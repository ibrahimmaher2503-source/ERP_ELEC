'use strict';

// Owns only the forms UX PostgreSQL cluster on 127.0.0.1:55467. It never drops a database or edits .env files.
const crypto = require('node:crypto');
const fs = require('node:fs');
const net = require('node:net');
const path = require('node:path');
const { spawnSync } = require('node:child_process');

const root = path.resolve(__dirname, '../..');
const port = 55467;
const database = 'erp_elec_new_test';
const work = path.join(root, '.tmp/forms-ux-20261102');
const cluster = path.join(work, 'pgdata');
const privateDir = path.join(work, 'private');
const markerPath = path.join(privateDir, '.codex-forms-ux-owned.json');
const bootstrapPath = path.join(privateDir, 'bootstrap.json');
const pgBin = path.join(root, '.tools/postgresql/pgsql/bin');
const commandLogDir = path.join(privateDir, 'commands');
const requested = new Set(process.argv.slice(2));
const allowed = new Set(['--migrate', '--seed', '--test', '--foundation', '--actors', '--select-fixtures']);
const safeEnv = Object.fromEntries(['PATH', 'SystemRoot', 'WINDIR', 'TEMP', 'TMP', 'COMSPEC', 'PATHEXT', 'LOCALAPPDATA', 'APPDATA', 'USERPROFILE', 'HOMEDRIVE', 'HOMEPATH', 'PROGRAMFILES', 'OS', 'PROCESSOR_ARCHITECTURE', 'PHPRC', 'PHP_INI_SCAN_DIR']
  .filter(key => process.env[key]).map(key => [key, process.env[key]]));

function fail(message) { throw new Error(message); }
function run(executable, args, options = {}) {
  const result = spawnSync(executable, args, { cwd: root, encoding: 'utf8', windowsHide: true, ...options });
  if (result.error || result.status !== 0) fail(`${path.basename(executable)} failed; see the private forms UX bootstrap log.`);
  return String(result.stdout || '').trim();
}
function pg(executable, args, options = {}) { return run(path.join(pgBin, executable), args, options); }
function portFree() {
  return new Promise(resolve => {
    const server = net.createServer();
    server.once('error', () => resolve(false));
    server.listen(port, '127.0.0.1', () => server.close(() => resolve(true)));
  });
}
function readMarker() {
  try { return JSON.parse(fs.readFileSync(markerPath, 'utf8')); } catch { return null; }
}
function owned(marker) {
  return marker?.kind === 'codex-forms-ux-private-pg' && marker.repo === root && marker.port === port
    && marker.database === database && typeof marker.nonce === 'string' && /^[a-f0-9]{64}$/.test(marker.nonce)
    && path.resolve(marker.data_directory) === path.resolve(cluster);
}
function dbArgs(name) { return ['-h', '127.0.0.1', '-p', String(port), '-U', 'postgres', '-d', name]; }
function verifyServer(marker) {
  if (!owned(marker)) fail('The existing forms UX marker does not match this checkout, port, database, and PGDATA.');
  const identity = pg('psql.exe', [...dbArgs('postgres'), '-Atqc', "SELECT current_database() || '|' || current_user || '|' || current_setting('port') || '|' || current_setting('data_directory')"]);
  const [dbName, user, actualPort, dataDir] = identity.split('|');
  if (dbName !== 'postgres' || user !== 'postgres' || actualPort !== String(port) || path.resolve(dataDir) !== path.resolve(cluster)) {
    fail('Live PostgreSQL identity differs from the private marker.');
  }
}
function verifyDatabase(marker) {
  const receipt = pg('psql.exe', [...dbArgs(database), '-Atqc', 'SELECT nonce FROM codex_test_guard.ownership_receipts WHERE database = current_database()']);
  if (receipt !== marker.nonce) fail('The database ownership nonce does not match the private cluster marker.');
}
function artisan(command, label, env) {
  fs.mkdirSync(commandLogDir, { recursive: true });
  const logPath = path.join(commandLogDir, `${label}.log`);
  const fd = fs.openSync(logPath, 'w');
  try {
    const result = spawnSync('php', ['artisan', ...command], { cwd: root, env, windowsHide: true, stdio: ['ignore', fd, fd] });
    if (result.error || result.status !== 0) fail(`php artisan ${command.join(' ')} failed; see the private command log.`);
  } finally { fs.closeSync(fd); }
  return path.relative(root, logPath).replaceAll('\\', '/');
}
function phpScript(relativeScript, label, env, args = []) {
  fs.mkdirSync(commandLogDir, { recursive: true });
  const logPath = path.join(commandLogDir, `${label}.log`);
  const fd = fs.openSync(logPath, 'w');
  try {
    const result = spawnSync('php', [relativeScript, ...args], { cwd: root, env, windowsHide: true, stdio: ['ignore', fd, fd] });
    if (result.error || result.status !== 0) fail(`php ${relativeScript} failed; see the private command log.`);
  } finally { fs.closeSync(fd); }
}

async function main() {
  if ([...requested].some(arg => !allowed.has(arg)) || requested.size !== process.argv.length - 2) fail('Options: --migrate, --seed, --test.');
  fs.mkdirSync(privateDir, { recursive: true });
  if (fs.existsSync(cluster) && !owned(readMarker())) fail('Refusing existing PGDATA without the exact private forms UX marker.');
  if (!fs.existsSync(cluster)) {
    if (fs.existsSync(markerPath)) fail('Refusing to initialize PGDATA when an ownership marker already exists.');
    if (!(await portFree())) fail('Port 55467 is occupied; refusing to connect or initialize.');
    fs.mkdirSync(cluster, { recursive: false });
    run(path.join(pgBin, 'initdb.exe'), ['-D', cluster, '-U', 'postgres', '--auth-local=trust', '--auth-host=trust', '--encoding=UTF8', '--locale=C']);
    const marker = { kind: 'codex-forms-ux-private-pg', repo: root, port, database,
      data_directory: cluster, nonce: crypto.randomBytes(32).toString('hex'), created_at: new Date().toISOString() };
    fs.writeFileSync(markerPath, JSON.stringify(marker, null, 2) + '\n', { flag: 'wx', mode: 0o600 });
  }
  const marker = readMarker();
  if (!owned(marker)) fail('Private forms UX marker verification failed.');
  const ready = spawnSync(path.join(pgBin, 'pg_isready.exe'), ['-h', '127.0.0.1', '-p', String(port)], { cwd: root, encoding: 'utf8', windowsHide: true });
  if (ready.status !== 0) {
    if (!(await portFree())) fail('Port 55467 belongs to an unrecognized process; refusing to connect.');
    pg('pg_ctl.exe', ['-D', cluster, '-l', path.join(privateDir, 'postgres.log'), '-o', `-h 127.0.0.1 -p ${port}`, 'start'], { stdio: 'ignore' });
  }
  for (let attempt = 0; attempt < 40; attempt++) {
    const status = spawnSync(path.join(pgBin, 'pg_isready.exe'), ['-h', '127.0.0.1', '-p', String(port)], { cwd: root, encoding: 'utf8', windowsHide: true });
    if (status.status === 0) break;
    if (attempt === 39) fail('The private PostgreSQL cluster did not become ready.');
    await new Promise(resolve => setTimeout(resolve, 500));
  }
  verifyServer(marker);
  const present = pg('psql.exe', [...dbArgs('postgres'), '-Atqc', `SELECT count(*) FROM pg_database WHERE datname = '${database}'`]);
  if (present === '0') pg('psql.exe', [...dbArgs('postgres'), '-v', 'ON_ERROR_STOP=1', '-c', `CREATE DATABASE ${database}`]);
  else if (present !== '1') fail('Unexpected database catalog response.');
  const receipts = pg('psql.exe', [...dbArgs(database), '-Atqc', "SELECT count(*) FROM information_schema.tables WHERE table_schema = 'codex_test_guard' AND table_name = 'ownership_receipts'"]);
  if (receipts === '0') {
    const applicationTables = pg('psql.exe', [...dbArgs(database), '-Atqc', "SELECT count(*) FROM information_schema.tables WHERE table_schema NOT IN ('pg_catalog', 'information_schema')"]);
    if (applicationTables !== '0') fail('Refusing to adopt a database containing application tables without its ownership receipt.');
    pg('psql.exe', [...dbArgs(database), '-v', 'ON_ERROR_STOP=1', '-c', 'CREATE SCHEMA codex_test_guard; CREATE TABLE codex_test_guard.ownership_receipts (database text PRIMARY KEY, nonce char(64) NOT NULL)']);
    pg('psql.exe', [...dbArgs(database), '-v', 'ON_ERROR_STOP=1', '-c', `INSERT INTO codex_test_guard.ownership_receipts (database, nonce) VALUES ('${database}', '${marker.nonce}')`]);
  } else if (receipts !== '1') fail('Unexpected database ownership receipt catalog response.');
  verifyDatabase(marker);
  const bootstrap = {
    status: 'READY', db_port: port, database, postgres_data_directory: path.relative(root, cluster).replaceAll('\\', '/'),
    marker: path.relative(root, markerPath).replaceAll('\\', '/'),
    env: {
      ...safeEnv, APP_ENV: 'testing', APP_DEBUG: 'false', APP_KEY: `base64:${crypto.randomBytes(32).toString('base64')}`,
      DB_CONNECTION: 'pgsql', DB_HOST: '127.0.0.1', DB_PORT: String(port), DB_DATABASE: database,
      DB_USERNAME: 'postgres', DB_PASSWORD: '', DB_URL: '', E2E_DB_PORT: String(port), E2E_DB_DATABASE: database,
      E2E_CLUSTER_TOKEN: marker.nonce,
    },
  };
  fs.writeFileSync(bootstrapPath, JSON.stringify(bootstrap, null, 2) + '\n', { mode: 0o600 });
  const runtime = path.join(privateDir, 'runtime');
  const storage = path.join(privateDir, 'storage');
  const views = path.join(privateDir, 'compiled-views');
  const relative = target => path.relative(root, target).replaceAll('\\', '/');
  for (const dir of [runtime, storage, views, path.join(storage, 'framework/cache'), path.join(storage, 'framework/sessions'),
    path.join(storage, 'framework/views'), path.join(storage, 'app/private'), path.join(storage, 'logs')]) fs.mkdirSync(dir, { recursive: true });
  const env = {
    ...bootstrap.env, APP_URL: 'http://127.0.0.1:8017', CACHE_STORE: 'array', SESSION_DRIVER: 'file',
    QUEUE_CONNECTION: 'sync', MAIL_MAILER: 'array', BROADCAST_CONNECTION: 'null', FILESYSTEM_DISK: 'local',
    E2E_CLUSTER_MARKER: markerPath, E2E_BASE_URL: 'http://127.0.0.1:8017',
    LARAVEL_STORAGE_PATH: storage, E2E_APP_STORAGE: storage, VIEW_COMPILED_PATH: views,
    APP_CONFIG_CACHE: relative(path.join(runtime, 'config.php')), APP_ROUTES_CACHE: relative(path.join(runtime, 'routes.php')),
    APP_EVENTS_CACHE: relative(path.join(runtime, 'events.php')), APP_SERVICES_CACHE: relative(path.join(runtime, 'services.php')),
    APP_PACKAGES_CACHE: relative(path.join(runtime, 'packages.php')),
  };
  const logs = [];
  if (requested.has('--migrate')) { verifyDatabase(marker); logs.push(artisan(['migrate', '--force'], 'migrate', env)); verifyDatabase(marker); }
  if (requested.has('--seed')) { verifyDatabase(marker); logs.push(artisan(['db:seed', '--class=EgyptMarketDemoSeeder', '--force'], 'seed-demo', env)); verifyDatabase(marker); }
  if (requested.has('--test')) { verifyDatabase(marker); logs.push(artisan(['test'], 'full-suite', env)); verifyDatabase(marker); }
  if (requested.has('--foundation')) { verifyDatabase(marker); logs.push(artisan(['test', '--filter=FoundationTest'], 'foundation-test', env)); verifyDatabase(marker); }
  if (requested.has('--actors')) { verifyDatabase(marker); phpScript('tests/E2E/support/prepare-forms-ux-browser-actor.php', 'actors', env); verifyDatabase(marker); }
  if (requested.has('--select-fixtures')) { verifyDatabase(marker); phpScript('tests/E2E/support/prepare-forms-ux-select-fixtures.php', 'select-fixtures', env, [JSON.parse(fs.readFileSync(path.join(privateDir, 'actor.json'), 'utf8')).tag]); verifyDatabase(marker); }
  process.stdout.write(`READY database=${database} port=${port} private_marker=${path.relative(root, markerPath).replaceAll('\\', '/')} logs=${logs.join(',')}\n`);
}

main().catch(error => { process.stderr.write(`${error.message}\n`); process.exitCode = 1; });
