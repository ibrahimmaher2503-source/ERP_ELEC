'use strict';

const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const fs = require('node:fs');
const os = require('node:os');
const net = require('node:net');
const path = require('node:path');
const { spawn, spawnSync } = require('node:child_process');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '../..');
const edge = 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe';
const privateRoot = path.join(root, '.tmp/master-remediation-20261101/ui-private');
const finance = path.join(privateRoot, 'finance');
const cluster = path.join(root, '.tmp/master-remediation-20261101/ui-pgdata');
const bootstrap = JSON.parse(fs.readFileSync(path.join(finance, 'gate2-finance-ui-bootstrap.json'), 'utf8'));
const nonce = process.env.E2E_FINANCE_DB_NONCE || bootstrap.ownership_nonce;
if (fs.readFileSync(path.join(privateRoot, '.codex-ui-owned.txt'), 'utf8').trim() !== 'master-remediation-20261101 Gate2 UI private PostgreSQL marker'
    || !/^[a-f0-9]{64}$/.test(nonce) || bootstrap.ownership_nonce !== nonce
    || bootstrap.database !== `erp_elec_new_finance_ui_${nonce.slice(0, 32)}` || bootstrap.port !== 49686
    || fs.realpathSync(bootstrap.data_directory) !== fs.realpathSync(cluster)) throw new Error('ABORT: unowned Gate2 database receipt.');
const pid = fs.readFileSync(path.join(cluster, 'postmaster.pid'), 'utf8').split(/\r?\n/);
if (fs.realpathSync(pid[1]) !== fs.realpathSync(cluster) || Number(pid[3]) !== 49686) throw new Error('ABORT: unowned Gate2 PostgreSQL process.');
const fixturePath = path.join(__dirname, 'support/prepare-m06-warehouse-browser.php');
const diagnosticPath = path.join(finance, 'm06-warehouse-before-create');
const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'erp-m06-warehouse-'));
fs.mkdirSync(path.join(temp, 'views'));
const env = { ...process.env, APP_ENV: 'testing', APP_KEY: bootstrap.app_key, DB_CONNECTION: 'pgsql', DB_URL: '', DB_HOST: '127.0.0.1', DB_PORT: '49686',
  DB_DATABASE: bootstrap.database, DB_USERNAME: 'postgres', DB_PASSWORD: '', E2E_DB_PORT: '49686', E2E_FINANCE_DB_NONCE: nonce,
  APP_CONFIG_CACHE: '.tmp/master-remediation-20261101/ui-private/finance/cache/config.php',
  APP_ROUTES_CACHE: '.tmp/master-remediation-20261101/ui-private/finance/cache/routes.php',
  APP_EVENTS_CACHE: '.tmp/master-remediation-20261101/ui-private/finance/cache/events.php',
  APP_SERVICES_CACHE: '.tmp/master-remediation-20261101/ui-private/finance/cache/services.php',
  APP_PACKAGES_CACHE: '.tmp/master-remediation-20261101/ui-private/finance/cache/packages.php',
  APP_VIEW_COMPILED_PATH: path.join(finance, 'views'), VIEW_COMPILED_PATH: path.join(finance, 'views'),
  LARAVEL_STORAGE_PATH: path.join(finance, 'storage'), SESSION_DRIVER: 'file', CACHE_STORE: 'array', QUEUE_CONNECTION: 'sync', MAIL_MAILER: 'array', LOG_CHANNEL: 'stderr' };
async function freePort() {
  return new Promise((resolve, reject) => { const server = net.createServer(); server.once('error', reject);
    server.listen(0, '127.0.0.1', () => { const port = server.address().port; server.close(error => error ? reject(error) : resolve(port)); }); });
}
function fixture(mode, input) {
  const result = spawnSync('php', ['-d', 'opcache.enable_cli=0', fixturePath, mode], { cwd: root, env, input: JSON.stringify(input), encoding: 'utf8', windowsHide: true, timeout: 20000 });
  if (result.error || result.status !== 0) throw new Error(`Warehouse fixture ${mode} failed: ${(result.stderr || result.error || '').toString().slice(-1000)}`);
  return JSON.parse(result.stdout);
}
const isLivewireUpdate = response => /^\/livewire(?:-[a-z0-9]+)?\/update$/.test(new URL(response.url()).pathname);
async function main() {
  assert.ok(fs.existsSync(edge), 'Microsoft Edge is installed');
  const password = crypto.randomBytes(36).toString('base64url');
  let actor, url, timer, result;
  let cleaned = false;
  const cleanupActor = () => { if (actor && !cleaned) { fixture('cleanup', actor); cleaned = true; } };
  const log = fs.openSync(path.join(temp, 'server.log'), 'w');
  let server, browser;
  const stage = label => process.stderr.write(`M06 warehouse E2E: ${label}\n`);
  try {
    stage('prepare fixture');
    actor = fixture('prepare', { password });
    const port = await freePort();
    url = `http://127.0.0.1:${port}`;
    server = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', path.join(root, 'public'), path.join(root, 'tests/Support/gate2-finance-ui-router.php')], { cwd: path.join(root, 'public'), env: { ...env, APP_URL: url }, stdio: ['ignore', log, log], windowsHide: true });
    timer = setTimeout(() => {
      stage('global timeout after 90 seconds');
      try { cleanupActor(); stage('actor cleaned after timeout'); } catch (error) { stage(`cleanup failed: ${error.message}`); }
      if (server) server.kill();
      process.exit(124);
    }, 90000);
    let ready = false;
    for (let i = 0; i < 60; i++) {
      if (server.exitCode !== null) throw new Error('Test server exited');
      try { if ((await fetch(url + '/login')).status === 200) { ready = true; break; } } catch {}
      await new Promise(resolve => setTimeout(resolve, 500));
    }
    assert.ok(ready, 'isolated app server started');
    stage('server ready');
    browser = await chromium.launch({ executablePath: edge, headless: true });
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 }, locale: 'ar-EG', timezoneId: 'Africa/Cairo' });
    page.setDefaultTimeout(15000);
    page.setDefaultNavigationTimeout(20000);
    const errors = [];
    const updateStatuses = [];
    page.on('pageerror', error => errors.push(error.message));
    page.on('response', response => { if (isLivewireUpdate(response)) updateStatuses.push(response.status()); });
    await page.goto(url + '/login');
    stage('login page loaded');
    await page.locator('input[type="email"]').fill(actor.email);
    await page.locator('input[type="password"]').fill(password);
    await page.locator('form button[type="submit"]').click();
    await page.waitForURL(location => !location.pathname.includes('/login'));
    stage('authenticated');
    await page.goto(url + '/inventory/warehouses');
    stage('warehouse page loaded');
    fs.writeFileSync(diagnosticPath + '.html', await page.content());
    await page.screenshot({ path: diagnosticPath + '.png', fullPage: true });
    stage('private DOM and screenshot captured before create click');
    if (process.argv.includes('--diagnose-only')) return;
    await page.getByRole('button', { name: 'إضافة مستودع' }).click();
    stage('create modal opened');
    fs.writeFileSync(diagnosticPath + '-modal.html', await page.content());
    await page.screenshot({ path: diagnosticPath + '-modal.png', fullPage: true });
    stage('private modal DOM and screenshot captured');
    await page.getByLabel('كود المستودع', { exact: true }).fill(actor.code);
    stage('warehouse code filled');
    await page.getByLabel('اسم المستودع', { exact: true }).fill('مستودع اختبار المتصفح');
    stage('warehouse name filled');
    await page.getByLabel('الفرع', { exact: true }).selectOption(String(actor.branch_id));
    stage('branch selected');
    const saveResponse = page.waitForResponse(response => isLivewireUpdate(response) && response.request().method() === 'POST', { timeout: 10000 });
    await page.getByRole('button', { name: 'حفظ المستودع' }).click();
    stage('create save clicked');
    await saveResponse;
    stage('create Livewire response received');
    fs.writeFileSync(diagnosticPath + '-after-save.html', await page.content());
    await page.screenshot({ path: diagnosticPath + '-after-save.png', fullPage: true });
    const validation = await page.locator('[role="alert"], [data-flux-error]').allTextContents();
    fs.writeFileSync(diagnosticPath + '-after-save.json', JSON.stringify({ updateStatuses, validation, pageErrors: errors }, null, 2));
    stage('private save response and validation captured');
    let stored = fixture('verify', actor);
    assert.ok(stored.id, `Warehouse save did not persist; Livewire HTTP statuses: ${updateStatuses.join(',')}; validation: ${validation.join(' | ')}`);
    assert.equal(stored.name, 'مستودع اختبار المتصفح');
    assert.equal(stored.version, 1);
    stage('warehouse persisted');
    await page.getByLabel('البحث', { exact: true }).fill(actor.code);
    await page.locator('tr').filter({ hasText: actor.code }).waitFor({ timeout: 7000 });
    stage('warehouse found by search');
    const row = page.locator('tr').filter({ hasText: actor.code });
    await row.getByRole('button', { name: 'تفاصيل وتعديل' }).click();
    stage('edit modal opened');
    await page.getByLabel('اسم المستودع', { exact: true }).fill('مستودع اختبار المتصفح المعدّل');
    stage('updated name filled');
    await page.getByLabel('العنوان', { exact: true }).fill('عنوان اختباري، القاهرة');
    stage('address filled');
    const editResponse = page.waitForResponse(response => isLivewireUpdate(response) && response.request().method() === 'POST', { timeout: 10000 });
    await page.getByRole('button', { name: 'حفظ المستودع' }).click();
    stage('edit save clicked');
    await editResponse;
    stored = fixture('verify', actor);
    stage('warehouse verified');
    assert.equal(stored.name, 'مستودع اختبار المتصفح المعدّل');
    assert.equal(stored.address, 'عنوان اختباري، القاهرة');
    assert.equal(stored.version, 2);
    assert.deepEqual(errors, []);
    result = { result: 'PASS', route: '/inventory/warehouses', database: bootstrap.database, db_port: 49686, warehouse_id: stored.id };
  } finally {
    try {
      cleanupActor();
      stage('guarded actor cleanup complete');
    } finally {
      if (server && server.exitCode === null) server.kill();
      if (browser) {
        const closed = await Promise.race([browser.close().then(() => true, () => false), new Promise(resolve => setTimeout(() => resolve(false), 5000))]);
        if (!closed) browser.process?.()?.kill();
      }
      if (timer) clearTimeout(timer);
      fs.closeSync(log);
      fs.rmSync(temp, { recursive: true, force: true });
    }
  }
  if (result) console.log(JSON.stringify(result));
}
main().then(() => process.exit(0), error => { console.error(error.stack || error); process.exit(1); });
