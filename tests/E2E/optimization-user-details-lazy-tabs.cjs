'use strict';

// Read-only authenticated browser acceptance for the lazy User Details tabs.
// The sole database write is the existing guarded test Administrator auth
// refresh; no business record or existing user is changed.
const fs = require('node:fs');
const path = require('node:path');
const net = require('node:net');
const { spawn, spawnSync } = require('node:child_process');
const { chromium } = require('playwright');
const { browserExecutable } = require('./page-speed-audit.cjs');

const root = path.resolve(__dirname, '../..');
const work = path.join(root, '.tmp/page-speed-audit');
const bootstrapPath = path.join(work, 'bootstrap.json');
const bootstrap = JSON.parse(fs.readFileSync(bootstrapPath, 'utf8'));
const markerPath = path.join(work, 'postgres-55461/codex-owned.json');
const marker = JSON.parse(fs.readFileSync(markerPath, 'utf8'));
const pgBin = path.join(root, '.tools/postgresql/pgsql/bin');
const outputDir = path.join(work, 'native-profile', `user-tabs-${Date.now()}`);
const storage = path.join(outputDir, 'storage');
const views = path.join(outputDir, 'views');
const evidencePath = path.join(root, '.tmp/optimization-regression/user-details-lazy-tabs.json');

function assert(condition, message) {
  if (!condition) throw new Error(message);
}

function privateDir(directory) {
  fs.mkdirSync(directory, { recursive: true, mode: 0o700 });
}

function verifyOwnedTarget() {
  const env = bootstrap.env || {};
  assert(bootstrap.dbName === 'erp_elec_new_test' && Number(bootstrap.dbPort) === 55461,
    'Refusing browser acceptance outside the isolated PostgreSQL test database.');
  assert(env.DB_CONNECTION === 'pgsql' && env.DB_HOST === '127.0.0.1' && Number(env.DB_PORT) === 55461
    && env.DB_DATABASE === 'erp_elec_new_test', 'Private bootstrap database target does not match the expected PostgreSQL identity.');
  assert(marker.kind === 'codex-e2e-postgres' && marker.repo === root && Number(marker.port) === 55461
    && Number(marker.version) === 1 && typeof env.E2E_CLUSTER_TOKEN === 'string'
    && marker.token === env.E2E_CLUSTER_TOKEN, 'Owned PostgreSQL marker and private bootstrap token do not match.');
  const actual = spawnSync(path.join(pgBin, 'psql.exe'), ['-h', '127.0.0.1', '-p', '55461', '-U', env.DB_USERNAME,
    '-d', 'postgres', '-Atqc', "SELECT current_setting('data_directory') || '|' || (SELECT datname FROM pg_database WHERE datname = current_database())"],
  { cwd: root, env, encoding: 'utf8', windowsHide: true });
  assert(!actual.error && actual.status === 0, 'Could not verify owned PostgreSQL runtime identity.');
  const [dataDirectory, connectedDatabase] = actual.stdout.trim().split('|');
  assert(path.resolve(dataDirectory) === path.resolve(path.join(work, 'postgres-55461'))
    && connectedDatabase === 'postgres', 'PostgreSQL data directory failed its ownership check.');
}

function freePort() {
  return new Promise((resolve, reject) => {
    const server = net.createServer();
    server.once('error', reject);
    server.listen(0, '127.0.0.1', () => {
      const port = server.address().port;
      server.close(() => resolve(port));
    });
  });
}

function prepareRuntime(baseUrl) {
  for (const directory of [outputDir, storage, views, path.join(storage, 'framework/cache'),
    path.join(storage, 'framework/sessions'), path.join(storage, 'framework/views'),
    path.join(storage, 'app/private'), path.join(storage, 'logs')]) privateDir(directory);
  const sharedPrivate = path.join(bootstrap.storage, 'app/private');
  const ownPrivate = path.join(storage, 'app/private');
  if (fs.existsSync(sharedPrivate) && fs.readdirSync(ownPrivate).length === 0) {
    fs.rmdirSync(ownPrivate);
    try { fs.symlinkSync(sharedPrivate, ownPrivate, 'junction'); } catch { privateDir(ownPrivate); }
  }
  return {
    ...bootstrap.env,
    APP_ENV: 'local', APP_URL: baseUrl, APP_DEBUG: 'false',
    LARAVEL_STORAGE_PATH: storage, E2E_APP_STORAGE: storage, VIEW_COMPILED_PATH: views,
    APP_CONFIG_CACHE: path.relative(root, path.join(outputDir, 'config.php')).replaceAll('\\', '/'),
    DEBUGBAR_ENABLED: 'false', PULSE_ENABLED: 'false',
  };
}

function cacheConfig(env) {
  const result = spawnSync('php', ['artisan', 'config:cache'], { cwd: root, env, encoding: 'utf8', windowsHide: true, maxBuffer: 2 * 1024 * 1024 });
  assert(!result.error && result.status === 0, 'Private isolated Laravel config could not be prepared.');
}

function startServer(baseUrl, env) {
  const logPath = path.join(outputDir, 'php-server.log');
  const fd = fs.openSync(logPath, 'w');
  const server = spawn('php', ['-S', new URL(baseUrl).host, '-t', '.', path.join(root, 'tests/E2E/support/performance-router.php')], {
    cwd: path.join(root, 'public'), env, stdio: ['ignore', fd, fd], windowsHide: true,
  });
  return { server, fd };
}

async function waitReady(baseUrl, server) {
  const deadline = Date.now() + 20_000;
  while (Date.now() < deadline) {
    if (server.exitCode !== null) throw new Error('Private Laravel acceptance server exited before readiness.');
    try {
      const response = await fetch(`${baseUrl}/login`, { redirect: 'manual' });
      if (response.status === 200) return;
    } catch {}
    await new Promise(resolve => setTimeout(resolve, 150));
  }
  throw new Error('Private loopback Laravel server did not become ready.');
}

function refreshActor(env) {
  const helperEnv = { ...env, E2E_APP_STORAGE: storage };
  const result = spawnSync('php', ['tests/E2E/support/performance-auth-refresh.php'], {
    cwd: root, env: helperEnv, encoding: 'utf8', windowsHide: true, maxBuffer: 1024 * 1024,
  });
  assert(!result.error && result.status === 0, 'Guarded synthetic Administrator auth refresh failed.');
  return JSON.parse(result.stdout.trim());
}

function findTargetUserId(actorId, env) {
  const result = spawnSync(path.join(pgBin, 'psql.exe'), ['-h', '127.0.0.1', '-p', '55461', '-U', env.DB_USERNAME,
    '-d', 'erp_elec_new_test', '-Atqc', `SELECT u.id FROM users u WHERE u.id <> ${Number(actorId)} AND u.active = true AND u.email_verified_at IS NOT NULL AND EXISTS (SELECT 1 FROM user_branch_scopes s WHERE s.user_id = u.id) ORDER BY u.id LIMIT 1`],
  { cwd: root, env, encoding: 'utf8', windowsHide: true });
  assert(!result.error && result.status === 0 && /^\d+$/.test(result.stdout.trim()), 'No existing active verified branch user is available as a read-only target.');
  return result.stdout.trim();
}

async function login(browser, baseUrl, credentials, statePath) {
  const context = await browser.newContext({ baseURL: baseUrl, locale: 'ar-EG', timezoneId: 'Africa/Cairo', viewport: { width: 1440, height: 900 } });
  try {
    const page = await context.newPage();
    await page.goto('/login', { waitUntil: 'domcontentloaded', timeout: 20_000 });
    await page.locator('input[name="email"]').fill(credentials.email);
    await page.locator('input[name="password"]').fill(credentials.password);
    await Promise.all([
      page.waitForURL(url => url.pathname === '/dashboard', { waitUntil: 'domcontentloaded', timeout: 20_000 }),
      page.locator('[data-test="login-button"]').click(),
    ]);
    await context.storageState({ path: statePath });
  } finally {
    await context.close();
  }
}

async function main() {
  verifyOwnedTarget();
  const port = await freePort();
  const baseUrl = `http://127.0.0.1:${port}`;
  const env = prepareRuntime(baseUrl);
  cacheConfig(env);
  let serverRecord = startServer(baseUrl, env);
  let browser;
  try {
    await waitReady(baseUrl, serverRecord.server);
    const credentials = refreshActor(env);
    const targetUserId = findTargetUserId(credentials.id, env);
    const edge = browserExecutable();
    browser = await chromium.launch({ headless: true, executablePath: edge });
    const statePath = path.join(outputDir, 'admin-storage-state.json');
    await login(browser, baseUrl, credentials, statePath);
    credentials.password = '';
    credentials.email = '';

    const context = await browser.newContext({ baseURL: baseUrl, storageState: statePath, locale: 'ar-EG',
      timezoneId: 'Africa/Cairo', viewport: { width: 1440, height: 900 } });
    const page = await context.newPage();
    const lazyRequests = [];
    page.on('request', request => {
      if (request.method() !== 'POST' || !/\/livewire(?:-[^/]+)?\/update(?:\?|$)/.test(request.url())) return;
      const body = request.postData() || '';
      if (/UserAccessSection|QuotationSelfApprovalSection|user-access|user-permission-history/.test(body)) lazyRequests.push(new URL(request.url()).pathname);
    });
    const checks = [];
    try {
      const response = await page.goto(`/organization/users/${targetUserId}`, { waitUntil: 'domcontentloaded', timeout: 20_000 });
      assert(response && response.status() === 200, `User details route failed with HTTP ${response?.status() ?? 'no response'}.`);
      await page.getByRole('tab', { name: /المعلومات الأساسية/ }).waitFor({ state: 'visible' });
      await page.waitForFunction(() => document.readyState === 'complete' && !!window.Livewire, { timeout: 10_000 });
      await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
      assert(!(await page.locator('#user-access').count()), 'Access child mounted before its tab was selected.');
      assert(!(await page.locator('#permissions-history').count()), 'History child mounted before its tab was selected.');
      assert(lazyRequests.length === 0, 'Hidden tabs sent lazy Livewire requests on the basic panel.');
      checks.push('basic tab leaves access and history children unmounted');

      await page.getByRole('tab', { name: /الأدوار ونطاقات الوصول/ }).click();
      await page.waitForURL(/#user-access$/);
      await page.locator('#user-access').waitFor({ state: 'visible', timeout: 15_000 });
      await page.getByRole('link', { name: /إدارة الأدوار/ }).waitFor({ state: 'visible' });
      assert(lazyRequests.length >= 1, 'Access tab did not lazy-mount its child.');
      checks.push('access tab hash navigation lazy-mounts its component and shows controls');

      const previous = lazyRequests.length;
      await page.getByRole('tab', { name: /سجل الصلاحيات/ }).click();
      await page.waitForURL(/#permissions-history$/);
      await page.locator('#permissions-history').waitFor({ state: 'visible', timeout: 15_000 });
      await page.locator('#permissions-history .erp-section-heading').getByText('سجل تغييرات الوصول', { exact: true }).waitFor({ state: 'visible' });
      await page.locator('#permissions-history').getByText('لا توجد أحداث تدقيق', { exact: true }).waitFor({ state: 'visible' });
      assert(lazyRequests.length > previous, 'History tab did not lazy-mount its child.');
      checks.push('history tab hash navigation lazy-mounts the section and shows the truthful empty state');
    } finally {
      await context.close();
    }

    privateDir(path.dirname(evidencePath));
    fs.writeFileSync(evidencePath, JSON.stringify({ status: 'passed', target: 'organization user details', viewport: '1440x900',
      checks, lazy_component_requests: lazyRequests.length, business_mutations: 0, database: 'erp_elec_new_test@55461' }, null, 2) + '\n');
    process.stdout.write(JSON.stringify({ status: 'passed', checks, lazy_component_requests: lazyRequests.length }) + '\n');
  } finally {
    if (browser) await browser.close();
    if (serverRecord?.server.exitCode === null) serverRecord.server.kill();
    if (serverRecord) { try { fs.closeSync(serverRecord.fd); } catch {} }
  }
}

main().catch(error => {
  process.stderr.write(`FAIL ${error.message}\n`);
  process.exitCode = 1;
});
