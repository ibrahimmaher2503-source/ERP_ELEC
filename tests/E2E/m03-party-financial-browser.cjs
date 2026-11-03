'use strict';

// Authenticated desktop/mobile M03 profile acceptance against marker-owned PostgreSQL 55460.
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const crypto = require('node:crypto');
const net = require('node:net');
const { spawn, spawnSync } = require('node:child_process');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '../..');
const router = path.join(root, 'tests/E2E/support/laravel-static-router.php');
const fixturePath = path.join(root, 'tests/E2E/support/prepare-m03-financial-profile-browser.php');
const bootstrapPath = path.join(root, '.tmp/e2e-automation/bootstrap.json');
const outputRoot = path.join(root, 'docs/qa/evidence/m03-financial-profile');
const runId = crypto.randomBytes(5).toString('hex').toUpperCase();
const runDir = path.join(outputRoot, `run-${runId}`);
const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'erp-m03-financial-'));
const port = crypto.randomInt(9001, 9500);
const base = `http://127.0.0.1:${port}`;
const bootstrap = JSON.parse(fs.readFileSync(bootstrapPath, 'utf8'));
const env = { ...bootstrap.env, APP_ENV: 'testing', APP_URL: base, APP_DEBUG: 'false',
  APP_CONFIG_CACHE: path.join(temp, 'config.php'), APP_ROUTES_CACHE: path.join(temp, 'routes.php'),
  DB_CONNECTION: 'pgsql', DB_HOST: '127.0.0.1', DB_PORT: '55460', DB_DATABASE: 'erp_elec_new_test',
  DB_USERNAME: 'erp_e2e', DB_PASSWORD: '', DB_URL: '', SESSION_DRIVER: 'file', CACHE_STORE: 'array', QUEUE_CONNECTION: 'sync',
  MAIL_MAILER: 'array', LOG_CHANNEL: 'stderr', BROADCAST_CONNECTION: 'null', VIEW_COMPILED_PATH: path.join(temp, 'views'),
  LARAVEL_STORAGE_PATH: path.join(bootstrap.storage), E2E_CLUSTER_MARKER: bootstrap.clusterMarker, E2E_CLUSTER_TOKEN: bootstrap.clusterToken };
const edge = process.env.PLAYWRIGHT_EXECUTABLE_PATH || 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe';
const checks = [];
const errors = [];
let server;
let serverLog;
let browser;
let page;
let fixture;

fs.mkdirSync(runDir, { recursive: true });
fs.mkdirSync(path.join(temp, 'views'), { recursive: true });

function phpFixture() {
  const result = spawnSync('php', ['-d', 'opcache.enable_cli=0', fixturePath], { cwd: root, env, encoding: 'utf8', windowsHide: true });
  if (result.status !== 0) throw new Error(`Guarded M03 fixture failed: ${(result.stderr || result.stdout || '').slice(-1200)}`);
  return JSON.parse(result.stdout);
}
async function assertFreePort() {
  await new Promise((resolve, reject) => {
    const probe = net.createServer();
    probe.once('error', error => reject(new Error(error.code === 'EADDRINUSE' ? `Harness port ${port} is occupied.` : error.message)));
    probe.listen(port, '127.0.0.1', () => probe.close(resolve));
  });
}
function observe(target) {
  target.on('pageerror', error => errors.push(String(error.message).slice(0, 300)));
  target.on('console', message => { if (message.type() === 'error') errors.push(message.text().slice(0, 300)); });
  target.on('response', response => { if (response.status() >= 500) errors.push(`HTTP ${response.status()} ${new URL(response.url()).pathname}`); });
}
async function login(target, actor) {
  await target.goto(base + '/login');
  await target.locator('input[name="email"]').fill(actor.email);
  await target.locator('input[name="password"]').fill('password');
  await target.locator('[data-test="login-button"]').click();
  await target.waitForURL(url => url.pathname === '/dashboard');
}
async function visit(pathname, viewport, partyType) {
  await page.setViewportSize(viewport);
  const response = await page.goto(base + pathname, { waitUntil: 'domcontentloaded' });
  if (!response || response.status() !== 200) throw new Error(`${pathname} returned HTTP ${response?.status() ?? 'no response'}.`);
  if (await page.locator('html').getAttribute('dir') !== 'rtl') throw new Error(`${pathname} lost Arabic RTL direction.`);
  await page.locator('[data-party-financial]').waitFor();
  await page.locator('[data-financial-summary]').waitFor();
  await page.locator('[data-financial-invoices] tbody tr').first().waitFor();
  const expected = fixture.expected[partyType];
  for (const key of ['posted_invoices', 'outstanding', 'overdue', 'unapplied_credits']) {
    const rendered = await page.locator(`[data-kpi="${key}"]`).innerText();
    if (!rendered.includes(expected[key])) throw new Error(`${partyType} ${key} KPI differs from the authorized API projection.`);
  }
  const body = await page.locator('body').innerText();
  if (!body.includes(partyType === 'supplier' ? 'ملخص حساب المورد' : 'ملخص حساب العميل')) throw new Error(`${pathname} did not render the party financial profile.`);
  if (!(await page.locator('[data-financial-statement]').count())) throw new Error(`${pathname} omitted the M12 statement.`);
  if (!(await page.locator('[data-financial-movements]').count())) throw new Error(`${pathname} omitted the authorized Treasury projection.`);
  const metrics = await page.evaluate(() => ({ viewport: innerWidth, document: document.documentElement.scrollWidth, body: document.body.scrollWidth }));
  if (metrics.document > metrics.viewport + 4) throw new Error(`${pathname} overflows horizontally at ${metrics.viewport}px.`);
  await page.screenshot({ path: path.join(runDir, `${partyType}-${viewport.width}.png`), animations: 'disabled', fullPage: true });
  checks.push({ page: pathname, viewport: viewport.width, summary: 'loaded', statement: 'loaded', treasury_projection: 'authorized', ...metrics });
}

async function main() {
  await assertFreePort();
  fixture = phpFixture();
  if (fixture.database !== 'erp_elec_new_test' || fixture.port !== 55460 || fixture.host !== '127.0.0.1') throw new Error('M03 fixture returned unexpected PostgreSQL identity.');
  serverLog = fs.openSync(path.join(runDir, 'server.redacted.log'), 'w');
  server = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', '.', router], { cwd: path.join(root, 'public'), env,
    stdio: ['ignore', serverLog, serverLog], windowsHide: true });
  let ready = false;
  for (let index = 0; index < 60; index++) {
    if (server.exitCode !== null) throw new Error('Harness-owned Laravel server exited during startup.');
    try { if ((await fetch(base + '/login')).status === 200) { ready = true; break; } } catch {}
    await new Promise(resolve => setTimeout(resolve, 300));
  }
  if (!ready) throw new Error('Laravel server did not become ready.');
  browser = await chromium.launch({ executablePath: edge, headless: true });
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'ar-EG', timezoneId: 'Africa/Cairo' });
  page = await context.newPage();
  page.setDefaultTimeout(20000);
  observe(page);
  await login(page, fixture.actor);

  for (const [kind, id] of [['customer', fixture.customer_id], ['supplier', fixture.supplier_id]]) {
    const pathName = `/${kind === 'customer' ? 'customers' : 'suppliers'}/${id}?tab=financial`;
    await visit(pathName, { width: 1440, height: 900 }, kind);
    const paginator = page.getByLabel('صفحات invoices');
    if (!(await paginator.count()) || !(await paginator.getByRole('button', { name: 'السابق' }).isDisabled())) throw new Error(`${kind} invoice pagination did not expose the first-page state.`);
    const statementPaginator = page.getByLabel('صفحات statement');
    if (!(await statementPaginator.count()) || !(await statementPaginator.getByRole('button', { name: 'السابق' }).isDisabled())) throw new Error(`${kind} statement pagination did not expose the first-page state.`);
    const from = page.getByLabel('من تاريخ الإثبات');
    const to = page.getByLabel('إلى تاريخ الإثبات');
    const closingBefore = await page.locator('[data-statement-closing]').innerText();
    await from.fill('2026-10-31');
    await to.fill('2026-10-01');
    const invalidRequest = page.waitForResponse(response => /\/livewire(?:-[^/]+)?\/update/.test(response.url()), { timeout: 15000 });
    await page.getByRole('button', { name: 'تطبيق الفترة' }).click();
    const invalidResponse = await invalidRequest;
    if (invalidResponse.status() !== 200) throw new Error(`${kind} inverted period returned HTTP ${invalidResponse.status()}.`);
    const invalidPayload = await invalidResponse.text();
    if (!/after or equal|بعد أو مساو|greater than|يجب أن يكون/.test(invalidPayload)) throw new Error(`${kind} inverted dates produced no visible period validation error.`);
    if (!(await page.locator('[data-financial-statement] tbody tr').count())) throw new Error(`${kind} invalid-period handling erased statement rows.`);
    if ((await page.locator('[data-statement-closing]').innerText()) !== closingBefore) throw new Error(`${kind} invalid period changed the statement balance.`);
    await from.fill('2026-10-01');
    await to.fill('2026-10-31');
    const validRequest = page.waitForResponse(response => /\/livewire(?:-[^/]+)?\/update/.test(response.url()), { timeout: 15000 });
    await page.getByRole('button', { name: 'تطبيق الفترة' }).click();
    const validResponse = await validRequest;
    if (validResponse.status() !== 200 || await page.locator('[role="alert"]:visible').count()) throw new Error(`${kind} valid period was not applied cleanly.`);
    if ((await page.locator('[data-statement-closing]').innerText()) !== closingBefore) throw new Error(`${kind} full-period statement total does not match unfiltered total.`);
    checks.push({ page: `${kind} period filter`, result: 'inverted dates rejected without changing balance; valid period applied', pagination: 'visible; previous disabled on page 1' });
    if (kind === 'customer') {
      const movementLink = page.locator('[data-financial-movements] a[href*="/treasury/movements/"]').first();
      const movementPath = new URL(await movementLink.getAttribute('href'), base).pathname;
      fixture.customerMovementPath = movementPath;
      const movementResponse = await page.request.get(base + movementPath);
      if (movementResponse.status() !== 200) throw new Error(`Authorized treasury movement route returned HTTP ${movementResponse.status()}.`);
      checks.push({ movement_route: 'authorized HTTP 200' });
      const downloadEvent = page.waitForEvent('download', { timeout: 15000 });
      await page.getByRole('button', { name: 'تصدير كشف الفترة' }).click();
      const download = await downloadEvent;
      const filePath = path.join(runDir, 'party-statement.xlsx');
      await download.saveAs(filePath);
      const signature = fs.readFileSync(filePath).subarray(0, 4).toString('hex');
      if (signature !== '504b0304') throw new Error('Statement download is not a valid XLSX package.');
      checks.push({ export: 'customer full filtered period statement', format: 'xlsx', signature: 'valid' });
    }
    await visit(pathName, { width: 390, height: 844 }, kind);
  }

  const emptyPath = `${base}/customers/${fixture.customer_id}?tab=financial`;
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto(emptyPath, { waitUntil: 'domcontentloaded' });
  await page.locator('[data-party-financial]').waitFor();
  const branchSelect = page.getByLabel('نطاق الفرع');
  const emptyBranchRequest = page.waitForResponse(response => /\/livewire(?:-[^/]+)?\/update/.test(response.url()), { timeout: 15000 });
  await branchSelect.selectOption(String(fixture.empty_branch_id));
  const emptyBranchResponse = await emptyBranchRequest;
  if (emptyBranchResponse.status() !== 200) throw new Error('Valid scoped empty branch profile did not load.');
  await page.locator('[data-financial-summary]').waitFor();
  if (!(await page.locator('[data-kpi="outstanding"]').innerText()).includes('0.00')
    || !(await page.locator('[data-financial-invoices]').innerText()).includes('لا توجد فواتير لهذا الطرف')
    || !(await page.locator('[data-party-financial]').innerText()).includes('تفاصيل سندات الخزينة تتطلب صلاحية خزينة مستقلة')) {
    throw new Error('Authorized empty AR branch returned nonzero rows or exposed Treasury detail without M13 permission.');
  }
  if (await page.locator('[data-financial-movements]').count()) throw new Error('Treasury movement table appeared without M13 permission in the valid AR branch.');
  checks.push({ authorization: 'in-scope empty AR branch authorized with zero rows; M13 Treasury detail withheld' });

  const deniedContext = await browser.newContext({ viewport: { width: 390, height: 844 }, locale: 'ar-EG', timezoneId: 'Africa/Cairo' });
  const deniedPage = await deniedContext.newPage();
  await login(deniedPage, fixture.denied_actor);
  await deniedPage.goto(`${base}/customers/${fixture.customer_id}?tab=financial`, { waitUntil: 'domcontentloaded' });
  await deniedPage.locator('[data-party-financial]').waitFor();
  if (!(await deniedPage.getByText('عرض البيانات المالية غير مسموح').count())) throw new Error('M03-only actor saw financial profile data.');
  const deniedMovement = await deniedPage.request.get(base + fixture.customerMovementPath);
  if (deniedMovement.status() !== 403) throw new Error(`Actor without M13 movement scope received HTTP ${deniedMovement.status()}.`);
  await deniedContext.close();

  const revokedContext = await browser.newContext({ viewport: { width: 390, height: 844 }, locale: 'ar-EG', timezoneId: 'Africa/Cairo' });
  const revokedPage = await revokedContext.newPage();
  await login(revokedPage, fixture.revocable_actor);
  await revokedPage.goto(`${base}/customers/${fixture.customer_id}?tab=financial`, { waitUntil: 'domcontentloaded' });
  await revokedPage.locator('[data-financial-summary]').waitFor();
  const revokedEnv = { ...env, M03_REVOCABLE_ACTOR_ID: String(fixture.revocable_actor.id), M03_REVOCABLE_BRANCH_ID: String(fixture.branch_id) };
  const revoke = spawnSync('php', ['-d', 'opcache.enable_cli=0', path.join(root, 'tests/E2E/support/revoke-m03-financial-profile-scope.php')],
    { cwd: root, env: revokedEnv, encoding: 'utf8', windowsHide: true });
  if (revoke.status !== 0) throw new Error(`Could not revoke isolated actor scope: ${(revoke.stderr || '').slice(-500)}`);
  await revokedPage.getByRole('button', { name: 'تطبيق الفترة' }).click();
  await revokedPage.getByText('عرض البيانات المالية غير مسموح').waitFor();
  if (await revokedPage.locator('[data-financial-summary]').count()) throw new Error('Livewire retained financial data after scoped M12 permission was revoked.');
  checks.push({ authorization: 'M03-only denied; treasury route 403 without M13; Livewire reauthorized after M12 branch grant removal' });
  await revokedContext.close();
  if (errors.length) throw new Error(`Browser errors: ${JSON.stringify(errors.slice(0, 8))}`);
  const result = { status: 'passed', database: fixture.database, postgres_port: fixture.port, actor_id: fixture.actor.id,
    branch_id: fixture.branch_id, desktop: '1440x900', mobile: '390x844', checks, browser_errors: [] };
  fs.writeFileSync(path.join(runDir, 'result.json'), JSON.stringify(result, null, 2) + '\n');
  process.stdout.write(JSON.stringify(result) + '\n');
}

main().catch(error => {
  const result = { status: 'failed', database: fixture?.database || 'not-connected', postgres_port: 55460,
    error: String(error.message).slice(0, 1200), checks, browser_errors: errors.slice(0, 8) };
  fs.writeFileSync(path.join(runDir, 'result.json'), JSON.stringify(result, null, 2) + '\n');
  process.stderr.write(JSON.stringify(result) + '\n');
  process.exitCode = 1;
}).finally(async () => {
  if (browser) await browser.close().catch(() => {});
  if (server && server.exitCode === null) server.kill();
  if (serverLog !== undefined) fs.closeSync(serverLog);
});
