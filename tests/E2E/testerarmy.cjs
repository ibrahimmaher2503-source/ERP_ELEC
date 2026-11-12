'use strict';

const crypto = require('node:crypto');
const fs = require('node:fs');
const net = require('node:net');
const path = require('node:path');
const { spawn, spawnSync } = require('node:child_process');

const root = path.resolve(__dirname, '../..');
const privateRoot = path.join(root, '.tmp/e2e-automation');
const outputRoot = path.join(root, '.tmp/testerarmy-e2e');
const bootstrapPath = path.join(privateRoot, 'bootstrap.json');
const lockPath = path.join(privateRoot, 'runner.lock');
const markerPath = path.join(privateRoot, 'postgres/codex-owned.json');
const database = 'erp_elec_new_test';
const port = 55460;
const mode = process.argv[2] || 'deterministic';

function run(executable, args, options = {}) {
  const result = spawnSync(executable, args, { cwd: root, encoding: 'utf8', windowsHide: true, ...options });
  if (result.error || result.status !== 0) throw new Error(`${path.basename(executable)} failed (${result.status ?? result.error?.code ?? 'unknown'}).`);
  return (result.stdout || '').trim();
}

function freePort() {
  return new Promise((resolve, reject) => {
    const server = net.createServer();
    server.once('error', reject);
    server.listen(0, '127.0.0.1', () => {
      const value = server.address().port;
      server.close(error => error ? reject(error) : resolve(value));
    });
  });
}

async function waitForServer(server, url) {
  const deadline = Date.now() + 45_000;
  while (Date.now() < deadline) {
    if (server.exitCode !== null || server.spawnError) throw new Error('The owned Laravel server could not stay running for readiness.');
    try {
      const response = await fetch(`${url}/login`, { redirect: 'manual' });
      if (response.status === 200) {
        const body = await response.text();
        if (body.includes('name="email"') || body.includes("name='email'")) return;
        if (body.length > 500) throw new Error('Owned server returned a login page without the expected form.');
      }
    } catch (error) { if (String(error.message).includes('without the expected form')) throw error; }
    await new Promise(resolve => setTimeout(resolve, 250));
  }
  throw new Error('The owned Laravel server did not become ready.');
}

function verifyOwnedDatabase(bootstrap) {
  const marker = JSON.parse(fs.readFileSync(markerPath, 'utf8'));
  validateBootstrapIdentity(bootstrap, marker, root, markerPath);
  const pg = path.join(root, '.tools/postgresql/pgsql/bin');
  const ready = run(path.join(pg, 'pg_isready.exe'), ['-h', '127.0.0.1', '-p', String(port)]);
  if (!ready.includes('accepting connections')) throw new Error('The owned PostgreSQL listener is not ready.');
  const actual = run(path.join(pg, 'psql.exe'), ['-h', '127.0.0.1', '-p', String(port), '-U', 'erp_e2e', '-d', 'postgres', '-Atqc', "SELECT current_setting('data_directory')"]);
  if (path.resolve(actual).toLowerCase() !== path.resolve(path.join(privateRoot, 'postgres')).toLowerCase()) {
    throw new Error('PostgreSQL data_directory does not match the owned isolated cluster.');
  }
}

function validateBootstrapIdentity(bootstrap, marker, rootDirectory, markerFile) {
  const expectedMarker = path.resolve(bootstrap.clusterMarker || '');
  const samePath = value => path.resolve(value).toLowerCase() === path.resolve(markerFile).toLowerCase();
  if (bootstrap.dbName !== database || Number(bootstrap.dbPort) !== port || !samePath(expectedMarker)
    || bootstrap.env?.DB_PORT !== String(port) || bootstrap.env?.DB_DATABASE !== database
    || marker.kind !== 'codex-e2e-postgres' || marker.repo?.toLowerCase() !== rootDirectory.toLowerCase()
    || Number(marker.port) !== port || !bootstrap.clusterToken || marker.token !== bootstrap.clusterToken) {
    throw new Error('Refusing to continue without the exact owned PostgreSQL bootstrap marker and token.');
  }
}

function prepareSmokeFixture(bootstrap) {
  const env = { ...bootstrap.env, E2E_FIXTURE_MODE: 'smoke' };
  const helper = path.join(root, 'tests/E2E/support/automation-critical-fixtures.php');
  const result = spawnSync('php', ['-d', 'opcache.enable_cli=0', helper], { cwd: root, env, input: '{}', encoding: 'utf8', windowsHide: true, maxBuffer: 4 * 1024 * 1024 });
  if (result.error || result.status !== 0) throw new Error(`Guarded smoke fixture failed: ${(result.stderr || result.stdout).slice(-800)}`);
  const fixture = JSON.parse(result.stdout);
  const actor = fixture.actors?.find(item => item.key === 'smoke');
  if (!actor?.email || !actor.password || !fixture.branch_id || !fixture.customer_id || !fixture.customer_code) {
    throw new Error('Guarded fixture did not return the smoke actor and required maintenance data.');
  }
  return { actor, safe: { tag: fixture.tag, branch_id: fixture.branch_id, customer_id: fixture.customer_id,
    customer_code: fixture.customer_code, actor_id: actor.id } };
}

async function stopAndWait(child) {
  if (!child || child.exitCode !== null) return;
  child.kill();
  await Promise.race([new Promise(resolve => child.once('exit', resolve)), new Promise(resolve => setTimeout(resolve, 1_500))]);
}

async function main() {
  if (!['deterministic', 'semantic'].includes(mode)) throw new Error('Usage: node tests/E2E/testerarmy.cjs [deterministic|semantic].');
  if (process.env.APP_URL || process.env.E2E_BASE_URL || process.env.E2E_DB_PORT || process.env.E2E_DB_DATABASE
    || (process.env.DB_DATABASE && ![database, 'codex_e2e_template'].includes(process.env.DB_DATABASE))) {
    throw new Error('Refusing inherited app or database targets; TesterArmy owns its isolated runtime.');
  }
  fs.mkdirSync(privateRoot, { recursive: true });
  fs.mkdirSync(outputRoot, { recursive: true });
  try { fs.mkdirSync(lockPath); }
  catch { throw new Error('Another isolated E2E runner owns the shared PostgreSQL/bootstrap files.'); }
  const ownerToken = crypto.randomBytes(16).toString('hex');
  fs.writeFileSync(path.join(lockPath, 'owner.json'), JSON.stringify({ pid: process.pid, ownerToken }));
  let proxy;
  const workers = [];
  let logFd;
  try {
    run(process.execPath, [path.join(__dirname, 'bootstrap.cjs')], { maxBuffer: 1024 * 1024 });
    const bootstrap = JSON.parse(fs.readFileSync(bootstrapPath, 'utf8'));
    verifyOwnedDatabase(bootstrap);
    const { actor, safe } = prepareSmokeFixture(bootstrap);
    const portProxy = await freePort();
    const baseUrl = `http://127.0.0.1:${portProxy}`;
    const serverEnv = { ...bootstrap.env, APP_URL: baseUrl };
    const workerPorts = [await freePort(), await freePort(), await freePort()];
    logFd = fs.openSync(path.join(bootstrap.logs, `testerarmy-${bootstrap.runId}.log`), 'w');
    workers.push(...workerPorts.map(workerPort => watch(spawn('php', ['-S', `127.0.0.1:${workerPort}`, '-t', '.', path.join(root, 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')], {
      cwd: path.join(root, 'public'), env: serverEnv, stdio: ['ignore', logFd, logFd], windowsHide: true,
    }))));
    proxy = watch(spawn(process.execPath, [path.join(__dirname, 'support/balancing-proxy.cjs'), String(portProxy), ...workerPorts], {
      cwd: root, env: serverEnv, stdio: ['ignore', logFd, logFd], windowsHide: true,
    }));
    await waitForServer(proxy, baseUrl);

    bootstrap.baseUrl = baseUrl;
    bootstrap.env = serverEnv;
    bootstrap.fixtures = safe;
    fs.writeFileSync(bootstrapPath, JSON.stringify(bootstrap, null, 2) + '\n');
    const testEnv = { ...process.env, ...serverEnv,
      APP_URL: baseUrl, E2E_BASE_URL: baseUrl, E2E_BOOTSTRAP_FILE: bootstrapPath,
      E2E_FIXTURE_JSON: JSON.stringify(safe), E2E_USER_SMOKE_USERNAME: actor.email,
      E2E_USER_SMOKE_PASSWORD: actor.password, E2E_TELEMETRY_DISABLED: '1' };
    for (const name of Object.keys(testEnv)) if (name.startsWith('OPENAI_') || name.startsWith('AI_GATEWAY_') || name.endsWith('_API_KEY')) delete testEnv[name];
    const cli = path.join(root, 'node_modules/e2e/dist/cli/bin.js');
    const result = spawnSync(process.execPath, [cli, 'run', '--config', 'e2e.config.ts', '--workers', '1', '--tag', mode, 'tests/example.e2e.ts', 'tests/maintenance-deterministic.e2e.ts'], {
      cwd: root, env: testEnv, encoding: 'utf8', windowsHide: true, maxBuffer: 8 * 1024 * 1024,
    });
    testEnv.E2E_USER_SMOKE_PASSWORD = '';
    testEnv.E2E_USER_SMOKE_USERNAME = '';
    testEnv.E2E_FIXTURE_JSON = '';
    actor.password = '';
    actor.email = '';
    process.stdout.write(result.stdout || '');
    process.stderr.write(result.stderr || '');
    if (result.error) throw new Error(`TesterArmy CLI could not start: ${result.error.message}`);
    process.exitCode = result.status ?? 1;
  } finally {
    await stopAndWait(proxy);
    for (const worker of workers) await stopAndWait(worker);
    if (logFd !== undefined) try { fs.closeSync(logFd); } catch {}
    try {
      const owner = JSON.parse(fs.readFileSync(path.join(lockPath, 'owner.json'), 'utf8'));
      if (owner.pid === process.pid && owner.ownerToken === ownerToken) fs.rmSync(lockPath, { recursive: true, force: true });
    } catch {}
  }
}

function watch(child) {
  child.once('error', error => { child.spawnError = error; });
  return child;
}

if (require.main === module) main().catch(error => {
  process.stderr.write(`${JSON.stringify({ status: 'BLOCKED', reason: String(error.message || error).slice(0, 500) })}\n`);
  process.exitCode = 1;
});

module.exports = { validateBootstrapIdentity };
