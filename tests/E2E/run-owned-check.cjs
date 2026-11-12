'use strict';

const fs = require('node:fs');
const path = require('node:path');
const { spawn } = require('node:child_process');
const crypto = require('node:crypto');

const root = path.resolve(__dirname, '../..');
const privateDir = path.join(root, '.tmp/forms-ux-20261102/private');
const bootstrapPath = path.join(privateDir, 'bootstrap.json');
const logDir = path.join(privateDir, 'owned-check-logs');
const checks = new Map([
  ['valued-inventory', 'tests/Support/check-phase0-valued-inventory.php'],
  ['trade-returns', 'tests/Support/check-trade-returns.php'],
  ['egypt-market-demo', '.tools/check-egypt-market-demo.php'],
  ['sales-order-factories', '.tools/check-sales-order-factories.php'],
  ['sales-orders', 'tests/Support/check-m04-sales-orders.php'],
  ['treasury-advance', 'tests/Support/check-treasury-advance-lifecycle.php'],
  ['treasury-statement', 'tests/Support/check-treasury-bank-statement-reconciliation.php'],
  ['treasury-credit-refunds', 'tests/Support/check-treasury-credit-refunds.php'],
  ['m15-attendance', 'tests/Support/check-m15-attendance.php'],
  ['projects', 'tests/Support/check-phase2-projects.php'],
  ['projects-financial', 'tests/Support/check-phase2-projects-financial.php'],
  ['safe-suite', '.tools/run-safe-qa-checks.php'],
]);
const completionMarkers = new Map([
  ['valued-inventory', '"all_pass": true'],
  ['trade-returns', '"all_pass": true'],
  ['egypt-market-demo', 'Egypt-market demo fixture transaction rolled back; generated private files cleaned up.'],
  ['sales-order-factories', 'Sales order demo seeder is repeat-safe, creates a draft-only order and null-priced line, and fabricates no review history'],
  ['sales-orders', 'Sales Order checks passed:'],
  ['treasury-advance', 'RESULT all_pass=1'],
  ['treasury-statement', 'RESULT all_pass=1'],
  ['treasury-credit-refunds', 'RESULT all_pass=1'],
  ['m15-attendance', 'RESULT gate=3 scope=m15-attendance result=PASS'],
  ['projects', 'RESULT all_pass=true'],
  ['projects-financial', '"all_pass": true'],
  ['safe-suite', '; 0 failed; 0 incomplete;'],
]);

function fail(message) {
  process.stderr.write(`${message}\n`);
  process.exitCode = 2;
}

function run(command, args, env, logPath) {
  return new Promise(resolve => {
    const log = fs.createWriteStream(logPath, { flags: fs.existsSync(logPath) ? 'a' : 'wx', mode: 0o600 });
    const child = spawn(command, args, { cwd: root, env, windowsHide: true, stdio: ['ignore', 'pipe', 'pipe'] });
    let stdout = '';
    let stderr = '';
    child.stdout.on('data', chunk => { stdout += chunk; log.write(chunk); process.stdout.write(chunk); });
    child.stderr.on('data', chunk => { stderr += chunk; log.write(chunk); process.stderr.write(chunk); });
    child.on('error', error => { log.end(); resolve({ code: 1, stdout, stderr: `${stderr}\n${error.message}` }); });
    child.on('close', code => { log.end(); resolve({ code: code ?? 1, stdout, stderr }); });
  });
}

function successful(result, marker) {
  const output = `${result.stdout}\n${result.stderr}`;
  const hasError = /^\s*(?:FAIL\b|ERROR\b|FATAL\b|PHP (?:Fatal|Parse) error\b|Uncaught\b|In .+ line \d+:|[A-Za-z_\\]*Exception:)/im.test(output);
  return result.code === 0 && !hasError && result.stdout.includes(marker);
}

async function main() {
  const names = process.argv.slice(2);
  if (names.length === 1 && names[0] === '--list') {
    process.stdout.write([...checks.keys()].join('\n') + '\n');
    return;
  }
  if (names.length === 0 || names.some(name => !checks.has(name))) {
    fail(`Choose one or more allowlisted checks: ${[...checks.keys()].join(', ')}`);
    return;
  }

  const freshCheckNames = new Set(['valued-inventory', 'trade-returns', 'projects', 'projects-financial']);
  if (names.some(name => freshCheckNames.has(name))) {
    if (names.some(name => !freshCheckNames.has(name))) {
      fail('Fresh 55460 checks cannot be mixed with the existing 55467 check suite.');
      return;
    }
    await runFreshChecks(names);
    return;
  }

  const bootstrap = JSON.parse(fs.readFileSync(bootstrapPath, 'utf8'));
  const sourceEnv = bootstrap.env;
  if (bootstrap.status !== 'READY' || bootstrap.db_port !== 55467 || bootstrap.database !== 'erp_elec_new_test'
      || !sourceEnv || sourceEnv.APP_ENV !== 'testing' || sourceEnv.DB_PORT !== '55467') {
    fail('The existing private forms UX bootstrap does not identify the owned 55467 test target.');
    return;
  }

  const storage = path.join(privateDir, 'storage');
  const runtime = path.join(privateDir, 'runtime');
  const views = path.join(privateDir, 'compiled-views');
  const relative = target => path.relative(root, target).replaceAll('\\', '/');
  for (const directory of [logDir, runtime, views, path.join(storage, 'framework/cache'), path.join(storage, 'framework/sessions'),
    path.join(storage, 'framework/views'), path.join(storage, 'app/private'), path.join(storage, 'logs')]) {
    fs.mkdirSync(directory, { recursive: true });
  }
  const env = {
    ...sourceEnv,
    APP_URL: 'http://127.0.0.1:8017',
    CACHE_STORE: 'array',
    SESSION_DRIVER: 'file',
    QUEUE_CONNECTION: 'sync',
    MAIL_MAILER: 'array',
    BROADCAST_CONNECTION: 'null',
    FILESYSTEM_DISK: 'local',
    E2E_CLUSTER_MARKER: path.join(privateDir, '.codex-forms-ux-owned.json'),
    LARAVEL_STORAGE_PATH: storage,
    E2E_APP_STORAGE: storage,
    VIEW_COMPILED_PATH: views,
    APP_CONFIG_CACHE: relative(path.join(runtime, 'config.php')),
    APP_ROUTES_CACHE: relative(path.join(runtime, 'routes.php')),
    APP_EVENTS_CACHE: relative(path.join(runtime, 'events.php')),
    APP_SERVICES_CACHE: relative(path.join(runtime, 'services.php')),
    APP_PACKAGES_CACHE: relative(path.join(runtime, 'packages.php')),
    APP_VIEW_COMPILED_PATH: views,
    VIEW_COMPILED_PATH: views,
  };

  for (const name of names) {
    const stamp = new Date().toISOString().replaceAll(':', '').replaceAll('.', '');
    const logPath = path.join(logDir, `${stamp}-${name}.log`);
    process.stdout.write(`Preflight ownership guard for ${name}\n`);
    const preflight = await run('php', ['tests/E2E/support/verify-owned-forms-check-target.php'], env, logPath);
    if (!successful(preflight, 'OWNED target verified: PostgreSQL 127.0.0.1:55467/erp_elec_new_test')) {
      process.stderr.write(`Preflight failed; see ${path.relative(root, logPath)}\n`);
      process.exitCode = 1;
      return;
    }
    const script = checks.get(name);
    process.stdout.write(`Running ${name}; log=${path.relative(root, logPath)}\n`);
    const result = await run('php', [script], env, logPath);
    if (!successful(result, completionMarkers.get(name))) {
      const reason = result.code !== 0 ? `exit ${result.code}` : 'reported an error or omitted its completion marker';
      process.stderr.write(`${name} failed (${reason}); log=${path.relative(root, logPath)}\n`);
      process.exitCode = result.code || 1;
      return;
    }
    process.stdout.write(`PASS ${name}\n`);
  }
}

async function runFreshChecks(names) {
  const work = path.join(root, '.tmp/e2e-automation');
  const lockPath = path.join(work, 'runner.lock');
  const logs = path.join(work, 'logs');
  fs.mkdirSync(logs, { recursive: true });
  const ownerToken = crypto.randomBytes(16).toString('hex');
  try { fs.mkdirSync(lockPath); }
  catch { throw new Error('Another E2E run owns the 55460 database/runtime lock.'); }
  fs.writeFileSync(path.join(lockPath, 'owner.json'), JSON.stringify({ pid: process.pid, ownerToken }));

  try {
    const stamp = new Date().toISOString().replaceAll(':', '').replaceAll('.', '');
    const bootstrapLog = path.join(logs, `${stamp}-owned-backend-bootstrap.log`);
    const bootstrapResult = await run(process.execPath, [path.join(root, 'tests/E2E/bootstrap.cjs')], process.env, bootstrapLog);
    const ready = bootstrapResult.stdout.split(/\r?\n/).some(line => {
      try {
        const value = JSON.parse(line);
        return value.status === 'READY' && value.port === 55460 && value.database === 'erp_elec_new_test';
      } catch { return false; }
    });
    if (bootstrapResult.code !== 0 || !ready) throw new Error(`Owned 55460 bootstrap failed; see ${path.relative(root, bootstrapLog)}.`);

    const bootstrapPath = path.join(work, 'bootstrap.json');
    const bootstrap = JSON.parse(fs.readFileSync(bootstrapPath, 'utf8'));
    const markerPath = path.join(work, 'postgres', 'codex-owned.json');
    const marker = JSON.parse(fs.readFileSync(markerPath, 'utf8'));
    const runsRoot = path.join(work, 'runs');
    const runtime = path.resolve(bootstrap.runtime || '');
    if (bootstrap.dbPort !== 55460 || bootstrap.dbName !== 'erp_elec_new_test'
        || path.resolve(bootstrap.clusterMarker || '') !== path.resolve(markerPath)
        || !bootstrap.clusterToken || marker.kind !== 'codex-e2e-postgres'
        || marker.repo !== root || Number(marker.port) !== 55460 || marker.token !== bootstrap.clusterToken
        || bootstrap.env?.APP_ENV !== 'testing' || bootstrap.env?.DB_CONNECTION !== 'pgsql'
        || bootstrap.env?.DB_HOST !== '127.0.0.1' || bootstrap.env?.DB_PORT !== '55460'
        || bootstrap.env?.DB_DATABASE !== 'erp_elec_new_test' || bootstrap.env?.DB_USERNAME !== 'erp_e2e'
        || bootstrap.env?.E2E_DB_PORT !== '55460' || bootstrap.env?.E2E_DB_DATABASE !== 'erp_elec_new_test'
        || path.resolve(bootstrap.env?.E2E_CLUSTER_MARKER || '') !== path.resolve(markerPath)
        || bootstrap.env?.E2E_CLUSTER_TOKEN !== bootstrap.clusterToken
        || !/^[A-F0-9]{12}$/.test(bootstrap.runId || '') || path.basename(runtime) !== bootstrap.runId
        || path.dirname(runtime) !== path.resolve(runsRoot)
        || fs.realpathSync(runsRoot) !== path.resolve(runsRoot) || fs.realpathSync(runtime) !== runtime) {
      throw new Error('Fresh-check bootstrap identity does not match the owned 55460 test runtime.');
    }

    for (const name of names) {
      const logPath = path.join(logs, `${stamp}-${name}.log`);
      const env = { ...bootstrap.env };
      delete env.PHASE1_COMMIT_RACE_FIXTURES;
      delete env.GATE1_HISTORY_CHECK;
      delete env.PHASE2_COMMIT_FIXTURES;
      if (name === 'valued-inventory') env.E2E_PHASE0_VALUED_EVIDENCE_PATH = path.join(runtime, 'phase0-valued-paths.json');
      if (name === 'projects-financial') {
        const evidenceDirectory = path.join(root, 'docs/qa/evidence/phase2-projects-browser');
        fs.mkdirSync(evidenceDirectory, { recursive: true });
        env.PHASE2_STORAGE_ROOT = path.join(runtime, 'phase2-project-financial-storage');
        env.PHASE2_EVIDENCE_PATH = path.join(evidenceDirectory, `.owned-${bootstrap.runId}-phase2-projects-financial.json`);
      }
      process.stdout.write(`Running ${name} against the fresh owned 55460 database; log=${path.relative(root, logPath)}\n`);
      try {
        const result = await run('php', [path.join(root, checks.get(name))], env, logPath);
        if (!successful(result, completionMarkers.get(name))) {
          const reason = result.code !== 0 ? `exit ${result.code}` : 'reported an error or omitted its completion marker';
          throw new Error(`${name} failed (${reason}); log=${path.relative(root, logPath)}.`);
        }
        process.stdout.write(`PASS ${name}\n`);
      } finally {
        if (env.PHASE2_EVIDENCE_PATH && fs.existsSync(env.PHASE2_EVIDENCE_PATH)) fs.rmSync(env.PHASE2_EVIDENCE_PATH);
      }
    }
  } finally {
    try {
      const owner = JSON.parse(fs.readFileSync(path.join(lockPath, 'owner.json'), 'utf8'));
      if (owner.pid === process.pid && owner.ownerToken === ownerToken) fs.rmSync(lockPath, { recursive: true, force: true });
    } catch {}
  }
}

if (require.main === module) main().catch(error => fail(error.message));

module.exports = { successful };
