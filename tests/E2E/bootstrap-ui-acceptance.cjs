'use strict';

// Marker-owned, non-destructive PostgreSQL target for explicitly requested local acceptance.
// It never drops a database or touches .env / .env.testing.
const crypto = require('node:crypto');
const fs = require('node:fs');
const net = require('node:net');
const path = require('node:path');
const { spawnSync } = require('node:child_process');

const root = path.resolve(__dirname, '../..');
const port = 55462;
const dbName = 'erp_elec_new_test';
const username = 'erp_e2e';
const work = path.join(root, '.tmp/ui-acceptance');
const cluster = path.join(work, 'postgres-55462');
const markerPath = path.join(cluster, 'codex-owned.json');
const bootstrapPath = path.join(work, 'bootstrap.json');
const pgBin = path.join(root, '.tools/postgresql/pgsql/bin');
const args = process.argv.slice(2);
const allowed = new Set(['--prepare', '--migrate', '--test', '--safe-qa']);

function fail(message) { throw new Error(message); }
function run(exe, argv, options = {}) {
  const result = spawnSync(exe, argv, { cwd: root, encoding: 'utf8', windowsHide: true, ...options });
  if (result.error || result.status !== 0) {
    if (options.logPath) fail(`${path.basename(exe)} failed; see private log ${path.relative(root, options.logPath)}.`);
    fail(`${path.basename(exe)} failed with exit ${result.status ?? 'unknown'}.`);
  }
  return String(result.stdout || '').trim();
}
function pg(exe, argv) { return run(path.join(pgBin, exe), argv); }
function ownsCluster() {
  if (!fs.existsSync(markerPath)) return false;
  try {
    const m = JSON.parse(fs.readFileSync(markerPath, 'utf8'));
    return m.kind === 'codex-ui-acceptance' && m.version === 1 && m.repo === root && m.port === port
      && m.database === dbName && typeof m.token === 'string' && m.token.length >= 48;
  } catch { return false; }
}
function portFree() {
  return new Promise(resolve => {
    const s = net.createServer();
    s.once('error', () => resolve(false));
    s.listen(port, '127.0.0.1', () => s.close(() => resolve(true)));
  });
}
function safeEnv() {
  const names = ['PATH', 'SystemRoot', 'WINDIR', 'TEMP', 'TMP', 'COMSPEC', 'PATHEXT', 'LOCALAPPDATA', 'APPDATA',
    'USERPROFILE', 'HOMEDRIVE', 'HOMEPATH', 'PROGRAMFILES', 'OS', 'PROCESSOR_ARCHITECTURE', 'PHPRC', 'PHP_INI_SCAN_DIR'];
  return Object.fromEntries(names.filter(k => process.env[k]).map(k => [k, process.env[k]]));
}
function repoRelative(target) { return path.relative(root, target).replaceAll('\\', '/'); }
function envForRun() {
  const token = JSON.parse(fs.readFileSync(markerPath, 'utf8')).token;
  const runtime = path.join(work, 'runtime');
  const storage = path.join(work, 'storage');
  const views = path.join(work, 'compiled-views');
  for (const dir of [runtime, storage, views, path.join(storage, 'framework/cache'), path.join(storage, 'framework/sessions'),
    path.join(storage, 'framework/views'), path.join(storage, 'logs')]) fs.mkdirSync(dir, { recursive: true });
  return {
    ...safeEnv(), APP_ENV: 'testing', APP_DEBUG: 'false', APP_KEY: `base64:${crypto.randomBytes(32).toString('base64')}`,
    DEBUGBAR_ENABLED: 'false', PULSE_ENABLED: 'false', DB_CONNECTION: 'pgsql', DB_HOST: '127.0.0.1', DB_PORT: String(port),
    DB_DATABASE: dbName, DB_USERNAME: username, DB_PASSWORD: '', DB_URL: '',
    CACHE_STORE: 'array', SESSION_DRIVER: 'file', SESSION_FILES: path.join(storage, 'framework/sessions'),
    QUEUE_CONNECTION: 'sync', MAIL_MAILER: 'array', BROADCAST_CONNECTION: 'null', FILESYSTEM_DISK: 'local',
    APP_CONFIG_CACHE: repoRelative(path.join(runtime, 'config.php')), APP_ROUTES_CACHE: repoRelative(path.join(runtime, 'routes.php')),
    APP_EVENTS_CACHE: repoRelative(path.join(runtime, 'events.php')), APP_SERVICES_CACHE: repoRelative(path.join(runtime, 'services.php')),
    APP_PACKAGES_CACHE: repoRelative(path.join(runtime, 'packages.php')), VIEW_COMPILED_PATH: views, LARAVEL_STORAGE_PATH: storage,
    E2E_APP_STORAGE: storage, E2E_DB_PORT: String(port), E2E_DB_DATABASE: dbName,
    E2E_CLUSTER_MARKER: markerPath, E2E_CLUSTER_TOKEN: token,
  };
}
function identitySql() {
  return pg('psql.exe', ['-h', '127.0.0.1', '-p', String(port), '-U', username, '-d', dbName, '-Atqc',
    "SELECT current_database() || '|' || current_setting('port') || '|' || current_setting('data_directory')"]);
}
function verifyIdentity() {
  if (!ownsCluster()) fail('Ownership marker verification failed.');
  const result = identitySql().split('|');
  if (result[0] !== dbName || result[1] !== String(port) || path.resolve(result[2]) !== path.resolve(cluster)) {
    fail('Database name, listener port, or PostgreSQL data directory does not match the owned target.');
  }
}
function runArtisan(command, logName, env) {
  const logPath = path.join(work, `${logName}.log`);
  const fd = fs.openSync(logPath, 'w');
  try {
    const result = spawnSync('php', ['artisan', ...command], { cwd: root, env, windowsHide: true, stdio: ['ignore', fd, fd] });
    if (result.error || result.status !== 0) fail(`php artisan ${command.join(' ')} failed; see private log ${path.relative(root, logPath)}.`);
  } finally { fs.closeSync(fd); }
  return path.relative(root, logPath).replaceAll('\\', '/');
}

async function main() {
  if (args.some(a => !allowed.has(a)) || new Set(args).size !== args.length) fail('Options: --prepare [--migrate] [--test] [--safe-qa].');
  if (!args.length) args.push('--prepare');
  // Refuse inherited target overrides that could redirect commands to another environment.
  for (const key of ['DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_CONNECTION', 'DB_URL', 'E2E_DB_PORT', 'E2E_DB_DATABASE', 'E2E_BASE_URL']) {
    const value = process.env[key];
    if (value && !({ DB_HOST: '127.0.0.1', DB_PORT: String(port), DB_DATABASE: dbName, DB_CONNECTION: 'pgsql', DB_URL: '',
      E2E_DB_PORT: String(port), E2E_DB_DATABASE: dbName }[key] === value)) fail(`Refusing inherited ${key} override.`);
  }
  fs.mkdirSync(work, { recursive: true });
  const lockPath = path.join(work, 'bootstrap.lock');
  let lock;
  try { lock = fs.openSync(lockPath, 'wx'); } catch { fail('Another UI acceptance bootstrap holds the lock; inspect before retrying.'); }
  try {
    if (fs.existsSync(cluster) && !ownsCluster()) fail('Refusing existing cluster directory without this runner’s ownership marker.');
    if (!fs.existsSync(cluster)) {
      if (!(await portFree())) fail('Port 55462 is occupied; refusing to connect or initialize.');
      fs.mkdirSync(cluster, { recursive: true });
      run(path.join(pgBin, 'initdb.exe'), ['-D', cluster, '-U', username, '--auth-local=trust', '--auth-host=trust', '--encoding=UTF8', '--locale=C']);
      fs.writeFileSync(markerPath, JSON.stringify({ kind: 'codex-ui-acceptance', version: 1, repo: root, port,
        database: dbName, token: crypto.randomBytes(32).toString('hex'), created_at: new Date().toISOString() }, null, 2) + '\n', { flag: 'wx' });
    }
    if (!ownsCluster()) fail('Marker is not valid for this repository and database.');
    let ready = spawnSync(path.join(pgBin, 'pg_isready.exe'), ['-h', '127.0.0.1', '-p', String(port)], { encoding: 'utf8', windowsHide: true });
    if (ready.status !== 0) {
      if (!(await portFree())) fail('Port 55462 belongs to an unrecognized process; refusing to connect.');
      const logPath = path.join(work, 'postgres.log');
      run(path.join(pgBin, 'pg_ctl.exe'), ['-D', cluster, '-l', logPath, '-o', `-h 127.0.0.1 -p ${port}`, 'start'], { stdio: 'ignore' });
      const deadline = Date.now() + 20_000;
      do {
        ready = spawnSync(path.join(pgBin, 'pg_isready.exe'), ['-h', '127.0.0.1', '-p', String(port)], { encoding: 'utf8', windowsHide: true });
        if (ready.status === 0) break;
        await new Promise(resolve => setTimeout(resolve, 200));
      } while (Date.now() < deadline);
    }
    if (ready.status !== 0) fail('Owned PostgreSQL did not become ready.');
    const dataDir = pg('psql.exe', ['-h', '127.0.0.1', '-p', String(port), '-U', username, '-d', 'postgres', '-Atqc', "SELECT current_setting('data_directory')"]);
    if (path.resolve(dataDir) !== path.resolve(cluster)) fail('Listener data directory mismatch; no database writes were made.');
    const existing = pg('psql.exe', ['-h', '127.0.0.1', '-p', String(port), '-U', username, '-d', 'postgres', '-Atqc',
      `SELECT count(*) FROM pg_database WHERE datname='${dbName}'`]);
    if (existing !== '1') {
      pg('createdb.exe', ['-h', '127.0.0.1', '-p', String(port), '-U', username, '-E', 'UTF8', dbName]);
    }
    verifyIdentity();
    const env = envForRun();
    const logs = [];
    if (args.includes('--migrate')) { verifyIdentity(); logs.push(runArtisan(['migrate', '--force'], 'migrate', env)); verifyIdentity(); }
    if (args.includes('--test')) { verifyIdentity(); logs.push(runArtisan(['test'], 'phpunit', env)); verifyIdentity(); }
    if (args.includes('--safe-qa')) {
      verifyIdentity();
      const logPath = path.join(work, 'safe-qa.log');
      const fd = fs.openSync(logPath, 'w');
      try {
        const result = spawnSync('php', ['.tools/run-safe-qa-checks.php'], { cwd: root, env, windowsHide: true, stdio: ['ignore', fd, fd] });
        if (result.error || result.status !== 0) fail(`Safe QA runner failed; see private log ${path.relative(root, logPath)}.`);
      } finally { fs.closeSync(fd); }
      verifyIdentity(); logs.push(path.relative(root, logPath).replaceAll('\\', '/'));
    }
    const record = { status: 'READY', db_port: port, database: dbName, postgres_data_directory: path.relative(root, cluster).replaceAll('\\', '/'), marker: path.relative(root, markerPath).replaceAll('\\', '/'), log_paths: logs };
    fs.writeFileSync(bootstrapPath, JSON.stringify(record, null, 2) + '\n');
    process.stdout.write(JSON.stringify(record) + '\n');
  } finally {
    if (lock !== undefined) fs.closeSync(lock);
    try { fs.unlinkSync(lockPath); } catch {}
  }
}

main().catch(error => { process.stderr.write(JSON.stringify({ status: 'FAILED', message: error.message }) + '\n'); process.exitCode = 1; });
