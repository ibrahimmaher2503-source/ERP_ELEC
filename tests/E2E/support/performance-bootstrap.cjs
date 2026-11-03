'use strict';

const crypto = require('node:crypto');
const fs = require('node:fs');
const net = require('node:net');
const path = require('node:path');
const { spawnSync } = require('node:child_process');

const root = path.resolve(__dirname, '../../..');
const work = path.join(root, '.tmp/page-speed-audit');
const cluster = path.join(work, 'postgres-55461');
const marker = path.join(cluster, 'codex-owned.json');
const port = 55461;
const pgBin = path.join(root, '.tools/postgresql/pgsql/bin');
const db = 'erp_elec_new_test';
const templateDb = 'codex_page_speed_template';
const username = 'erp_e2e';
const clusterToken = crypto.randomBytes(24).toString('hex');
const password = crypto.randomBytes(40).toString('base64url');
const runId = crypto.randomBytes(6).toString('hex').toUpperCase();
const runtime = path.join(work, 'runs', runId);
const storage = path.join(runtime, 'storage');
const views = path.join(work, 'cache', `blade-${viewCacheKey()}`);
const mpdfCache = path.join(work, 'cache', `mpdf-${mpdfCacheKey()}`);
const logs = path.join(work, 'logs');

function run(executable, args, options = {}) {
  const result = spawnSync(executable, args, { cwd: root, encoding: 'utf8', windowsHide: true, ...options });
  if (result.error || result.status !== 0) throw new Error(`Isolated setup command failed (${path.basename(executable)} ${args.at(-1) || ''}).`);
  return (result.stdout || '').trim();
}

function ownedCluster() {
  if (!fs.existsSync(marker)) return false;
  try {
    const identity = JSON.parse(fs.readFileSync(marker, 'utf8'));
    return identity.kind === 'codex-e2e-postgres' && identity.repo === root && identity.port === port && identity.version === 1 && (identity.token === undefined || typeof identity.token === 'string');
  } catch { return false; }
}

function portIsFree() {
  return new Promise(resolve => {
    const server = net.createServer();
    server.once('error', () => resolve(false));
    server.listen(port, '127.0.0.1', () => server.close(() => resolve(true)));
  });
}

function pg(exe, args) { return run(path.join(pgBin, exe), args); }

function runtimeEnvironment() {
  const allowed = ['PATH', 'SystemRoot', 'WINDIR', 'TEMP', 'TMP', 'COMSPEC', 'PATHEXT', 'LOCALAPPDATA', 'APPDATA', 'USERPROFILE',
    'HOMEDRIVE', 'HOMEPATH', 'PROGRAMFILES', 'PROGRAMFILES(X86)', 'COMMONPROGRAMFILES', 'OS', 'PROCESSOR_ARCHITECTURE',
    'NUMBER_OF_PROCESSORS', 'PHPRC', 'PHP_INI_SCAN_DIR', 'LANG', 'LC_ALL'];
  const safe = {};
  for (const name of allowed) if (process.env[name]) safe[name] = process.env[name];
  return safe;
}

async function main() {
  if ((process.env.E2E_DB_PORT && Number(process.env.E2E_DB_PORT) !== port)
      || (process.env.E2E_DB_DATABASE && process.env.E2E_DB_DATABASE !== db)
      || process.env.E2E_BASE_URL
      || (process.env.DB_DATABASE && ![db, templateDb].includes(process.env.DB_DATABASE))) {
    throw new Error('Refusing external or development E2E target before any setup writes.');
  }
  fs.mkdirSync(work, { recursive: true });
  fs.mkdirSync(logs, { recursive: true });
  if (fs.existsSync(cluster) && !ownedCluster()) throw new Error('Refusing PostgreSQL data directory without this runner’s ownership marker.');
  if (!fs.existsSync(cluster)) {
    if (!(await portIsFree())) throw new Error('Refusing to start disposable PostgreSQL: port 55460 is already occupied.');
    fs.mkdirSync(cluster, { recursive: true });
    run(path.join(pgBin, 'initdb.exe'), ['-D', cluster, '-U', username, '--auth-local=trust', '--auth-host=trust', '--encoding=UTF8', '--locale=C']);
    fs.writeFileSync(marker, JSON.stringify({ kind: 'codex-e2e-postgres', version: 1, repo: root, port, token: clusterToken, owner: process.pid }) + '\n');
  }
  let readiness = spawnSync(path.join(pgBin, 'pg_isready.exe'), ['-h', '127.0.0.1', '-p', String(port)], { encoding: 'utf8', windowsHide: true });
  if (readiness.status !== 0 || !readiness.stdout.includes('accepting connections')) {
    if (!(await portIsFree())) throw new Error('Port 55460 belongs to an unrecognized process; refusing to connect.');
    const start = spawnSync(path.join(pgBin, 'pg_ctl.exe'), ['-D', cluster, '-l', path.join(logs, 'postgres.log'), '-o', `-h 127.0.0.1 -p ${port}`, 'start'], { cwd: root, stdio: 'ignore', windowsHide: true });
    if (start.error || start.status !== 0) throw new Error('Could not start the owned disposable PostgreSQL instance.');
    const deadline = Date.now() + 15_000;
    while (Date.now() < deadline) {
      readiness = spawnSync(path.join(pgBin, 'pg_isready.exe'), ['-h', '127.0.0.1', '-p', String(port)], { encoding: 'utf8', windowsHide: true });
      if (readiness.status === 0 && readiness.stdout.includes('accepting connections')) break;
      await new Promise(resolve => setTimeout(resolve, 200));
    }
  }

  const ready = run(path.join(pgBin, 'pg_isready.exe'), ['-h', '127.0.0.1', '-p', String(port)]);
  if (!ready.includes('accepting connections')) throw new Error('Owned PostgreSQL did not become ready.');
  const actualDataDirectory = run(path.join(pgBin, 'psql.exe'), ['-h', '127.0.0.1', '-p', String(port), '-U', username, '-d', 'postgres', '-Atqc', 'SELECT current_setting(\'data_directory\')']);
  if (path.resolve(actualDataDirectory) !== path.resolve(cluster)) throw new Error('The PostgreSQL listener data directory does not match the owned cluster; refusing database changes.');
  const ownedIdentity = JSON.parse(fs.readFileSync(marker, 'utf8'));
  if (!ownedIdentity.token) fs.writeFileSync(marker, JSON.stringify({ ...ownedIdentity, token: clusterToken }) + '\n');
  const existing = run(path.join(pgBin, 'psql.exe'), ['-h', '127.0.0.1', '-p', String(port), '-U', username, '-d', 'postgres', '-Atqc', `SELECT datname FROM pg_database WHERE datname IN ('${templateDb}','${db}') ORDER BY datname`]);
  const databaseNames = existing.split(/\r?\n/).filter(Boolean);
  const migrationHash = migrationChecksum();
  const templateMetaPath = path.join(work, 'template-meta.json');
  let templateValid = databaseNames.includes(templateDb);
  if (templateValid && (!fs.existsSync(templateMetaPath) || JSON.parse(fs.readFileSync(templateMetaPath, 'utf8')).migrationHash !== migrationHash)) {
    pg('dropdb.exe', ['-h', '127.0.0.1', '-p', String(port), '-U', username, templateDb]);
    templateValid = false;
  }
  if (templateValid) {
    if (databaseNames.includes(db)) pg('dropdb.exe', ['-h', '127.0.0.1', '-p', String(port), '-U', username, db]);
    pg('createdb.exe', ['-h', '127.0.0.1', '-p', String(port), '-U', username, '-T', templateDb, db]);
  } else {
    if (databaseNames.includes(db)) pg('dropdb.exe', ['-h', '127.0.0.1', '-p', String(port), '-U', username, db]);
    pg('createdb.exe', ['-h', '127.0.0.1', '-p', String(port), '-U', username, templateDb]);
  }

  for (const dir of [runtime, storage, views, path.join(storage, 'framework/cache'), path.join(storage, 'framework/sessions'), path.join(storage, 'framework/views'), path.join(storage, 'logs')]) fs.mkdirSync(dir, { recursive: true });
  fs.mkdirSync(mpdfCache, { recursive: true });
  const pdfPrivate = path.join(storage, 'app/private');
  fs.mkdirSync(pdfPrivate, { recursive: true });
  const pdfTemp = path.join(pdfPrivate, 'mpdf');
  let mpdfCacheLinked = false;
  try { fs.symlinkSync(mpdfCache, pdfTemp, 'junction'); mpdfCacheLinked = true; }
  catch (error) { if (error.code !== 'EEXIST') throw new Error('Could not prepare private mPDF cache junction.'); }
  const env = {
    ...runtimeEnvironment(),
    APP_ENV: 'testing', APP_DEBUG: 'false', DEBUGBAR_ENABLED: 'false', PULSE_ENABLED: 'false', APP_KEY: `base64:${crypto.randomBytes(32).toString('base64')}`,
    APP_CONFIG_CACHE: path.relative(root, path.join(runtime, 'config.php')).replaceAll('\\', '/'),
    APP_ROUTES_CACHE: path.relative(root, path.join(runtime, 'routes.php')).replaceAll('\\', '/'),
    APP_EVENTS_CACHE: path.relative(root, path.join(runtime, 'events.php')).replaceAll('\\', '/'),
    APP_SERVICES_CACHE: path.relative(root, path.join(runtime, 'services.php')).replaceAll('\\', '/'),
    APP_PACKAGES_CACHE: path.relative(root, path.join(runtime, 'packages.php')).replaceAll('\\', '/'),
    VIEW_COMPILED_PATH: views, LARAVEL_STORAGE_PATH: storage, E2E_APP_STORAGE: storage,
    DB_CONNECTION: 'pgsql', DB_HOST: '127.0.0.1', DB_PORT: String(port), DB_DATABASE: db, DB_USERNAME: username, DB_PASSWORD: '', DB_URL: '',
    CACHE_STORE: 'array', SESSION_DRIVER: 'file', SESSION_FILES: path.join(storage, 'framework/sessions'), SESSION_LIFETIME: '30', QUEUE_CONNECTION: 'sync', MAIL_MAILER: 'array', LOG_CHANNEL: 'stderr',
    BROADCAST_CONNECTION: 'null', FILESYSTEM_DISK: 'local', E2E_DB_PORT: String(port), E2E_DB_DATABASE: db,
    E2E_CLUSTER_MARKER: marker, E2E_CLUSTER_TOKEN: JSON.parse(fs.readFileSync(marker, 'utf8')).token,
  };
  if (!templateValid) {
    const logFd = fs.openSync(path.join(logs, `migrate-${runId}.log`), 'w');
    try { run('php', ['artisan', 'migrate', '--force'], { env: { ...env, DB_DATABASE: templateDb }, stdio: ['ignore', logFd, logFd] }); }
    finally { fs.closeSync(logFd); }
    fs.writeFileSync(templateMetaPath, JSON.stringify({ migrationHash, migratedAt: new Date().toISOString() }) + '\n');
    pg('createdb.exe', ['-h', '127.0.0.1', '-p', String(port), '-U', username, '-T', templateDb, db]);
  }
  const result = { runId, baseUrl: '', dbPort: port, dbName: db, templateDb, clusterMarker: marker, clusterToken: env.E2E_CLUSTER_TOKEN,
    runtime, storage, logs, env, fixtures: null, databaseCreated: true, mpdfCacheLinked };
  const output = path.join(work, 'bootstrap.json');
  fs.writeFileSync(output, JSON.stringify(result, null, 2) + '\n');
  process.stdout.write(JSON.stringify({ status: 'READY', port, database: db, runId }) + '\n');
}

function migrationChecksum() {
  const folder = path.join(root, 'database/migrations');
  const files = fs.readdirSync(folder).filter(file => file.endsWith('.php')).sort();
  const hash = crypto.createHash('sha256');
  for (const file of files) hash.update(file).update(fs.readFileSync(path.join(folder, file)));
  return hash.digest('hex');
}

function viewCacheKey() {
  const hash = crypto.createHash('sha256');
  const folder = path.join(root, 'resources/views');
  const pending = [folder];
  while (pending.length) {
    const parent = pending.pop();
    for (const entry of fs.readdirSync(parent, { withFileTypes: true })) {
      const file = path.join(parent, entry.name);
      if (entry.isDirectory()) pending.push(file);
      else if (entry.name.endsWith('.blade.php')) hash.update(path.relative(root, file)).update(fs.readFileSync(file));
    }
  }
  hash.update(fs.readFileSync(path.join(root, 'composer.lock')));
  return hash.digest('hex').slice(0, 20);
}

function mpdfCacheKey() {
  return crypto.createHash('sha256').update(fs.readFileSync(path.join(root, 'composer.lock'))).digest('hex').slice(0, 20);
}

if (require.main === module) main().catch(error => { process.stderr.write(JSON.stringify({ status: 'FAILED', message: error.message }) + '\n'); process.exitCode = 1; });

module.exports = { runtimeEnvironment };
