'use strict';

// Real browser acceptance for persisted M11/M12/M13 evidence on the isolated test PostgreSQL.
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const crypto = require('node:crypto');
const net = require('node:net');
const { spawn, spawnSync } = require('node:child_process');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '../..');
const router = path.join(root, 'tests/E2E/support/laravel-static-router.php');
const fixturePath = path.join(root, 'tests/E2E/support/prepare-phase1-financial-browser.php');
const outputRoot = path.join(root, 'docs/qa/evidence/phase1-financial-browser');
const port = crypto.randomInt(8300, 8900);
const base = `http://127.0.0.1:${port}`;
const tag = crypto.randomBytes(5).toString('hex').toUpperCase();
const runDir = path.join(outputRoot, tag);
const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'erp-phase1-browser-'));
const edge = process.env.PLAYWRIGHT_EXECUTABLE_PATH || 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe';
const storage = path.join(root, '.tmp/phase1-financial-browser-storage');
fs.mkdirSync(runDir, { recursive: true });
fs.mkdirSync(path.join(temp, 'views'), { recursive: true });
const env = { ...process.env, APP_ENV: 'testing', APP_URL: base, APP_DEBUG: 'false', APP_CONFIG_CACHE: path.join(temp, 'config.php'),
  APP_ROUTES_CACHE: path.join(temp, 'routes.php'), DB_CONNECTION: 'pgsql', DB_HOST: '127.0.0.1', DB_PORT: '5443',
  DB_DATABASE: 'erp_elec_new_test', DB_URL: '', LARAVEL_STORAGE_PATH: storage, SESSION_DRIVER: 'file',
  CACHE_STORE: 'array', QUEUE_CONNECTION: 'sync', MAIL_MAILER: 'array', LOG_CHANNEL: 'stderr', BROADCAST_CONNECTION: 'null',
  VIEW_COMPILED_PATH: path.join(temp, 'views') };
const checks = [];
const browserErrors = [];
const livewireTraffic = [];
let browser;
let server;
let serverLog;
let page;
let fixture;

function phpFixture() {
  const result = spawnSync('php', ['-d', 'opcache.enable_cli=0', fixturePath], { cwd: root, env, encoding: 'utf8', windowsHide: true });
  if (result.status !== 0) throw new Error((result.stderr || result.stdout || 'guarded browser fixture failed').trim());
  try { return JSON.parse(result.stdout); } catch { throw new Error(`Guarded PHP fixture did not return JSON: ${(result.stderr || result.stdout || '').slice(0, 1000)}`); }
}
async function assertPortFree(value) {
  await new Promise((resolve, reject) => {
    const probe = net.createServer();
    probe.once('error', error => reject(new Error(error.code === 'EADDRINUSE' ? `Harness port ${value} is occupied.` : error.message)));
    probe.listen(value, '127.0.0.1', () => probe.close(resolve));
  });
}
async function login(actor, target = page) {
  await target.goto(base + '/login');
  await target.locator('input[name="email"]').fill(actor.email);
  await target.locator('input[name="password"]').fill('password');
  await target.locator('[data-test="login-button"]').click();
  await target.waitForURL(url => url.pathname === '/dashboard');
}
function observe(target) {
  target.on('pageerror', error => browserErrors.push(String(error.message).slice(0, 300)));
  target.on('console', message => { if (message.type() === 'error') browserErrors.push(message.text().slice(0, 300)); });
  target.on('response', response => {
    if (response.status() >= 500) browserErrors.push(`HTTP ${response.status()} ${new URL(response.url()).pathname}`.slice(0, 300));
    if (/\/livewire(?:-[^/]+)?\/update/.test(response.url())) livewireTraffic.push({ status: response.status(), method: 'POST' });
  });
  target.on('requestfailed', request => {
    if (/\/livewire(?:-[^/]+)?\/update/.test(request.url())) livewireTraffic.push({ failure: request.failure()?.errorText || 'failed' });
  });
}
async function visit(pathname, expectedText, viewport) {
  await page.setViewportSize(viewport);
  const response = await page.goto(base + pathname, { waitUntil: 'networkidle' });
  if (!response || response.status() !== 200) throw new Error(`${pathname} returned HTTP ${response?.status() ?? 'no response'}.`);
  if (await page.locator('html').getAttribute('dir') !== 'rtl') throw new Error(`${pathname} lost Arabic RTL direction.`);
  const body = await page.locator('body').innerText();
  if (expectedText && !body.includes(expectedText)) throw new Error(`${pathname} did not render expected persisted value ${expectedText}.`);
  const metrics = await page.evaluate(() => ({ viewport: innerWidth, document: document.documentElement.scrollWidth, body: document.body.scrollWidth }));
  if (metrics.document > metrics.viewport + 4) throw new Error(`${pathname} overflows horizontally at ${metrics.viewport}px (document ${metrics.document}px).`);
  const safe = pathname.replaceAll('/', '-').replace(/^-/, '') || 'home';
  await page.screenshot({ path: path.join(runDir, `${safe}-${viewport.width}.png`), animations: 'disabled' });
  checks.push({ page: pathname, viewport: viewport.width, persisted_value: expectedText || null, ...metrics });
  return body;
}
async function downloadAndVerify(pathname, expectedHash, name) {
  const response = await page.request.get(base + pathname);
  if (response.status() !== 200 || !(response.headers()['content-type'] || '').includes('application/pdf')
    || response.headers()['cache-control']?.includes('private') !== true) {
    throw new Error(`${name} PDF response status/content-type/private cache headers are invalid: ${response.status()}.`);
  }
  const bytes = await response.body();
  const hash = crypto.createHash('sha256').update(bytes).digest('hex');
  if (bytes.subarray(0, 5).toString('ascii') !== '%PDF-' || hash !== expectedHash) throw new Error(`${name} PDF bytes/hash do not match immutable official evidence.`);
  fs.writeFileSync(path.join(runDir, `${name}.pdf`), bytes);
  checks.push({ pdf: name, sha256: hash, bytes: bytes.length, authorization: 'authorized', integrity: 'verified' });
}
async function exportAndVerify(pathname, kind, name) {
  const response = await page.request.get(base + pathname);
  if (response.status() !== 200 || response.headers()['cache-control']?.includes('private') !== true) {
    throw new Error(`${name} export did not return a private authorized response: HTTP ${response.status()}.`);
  }
  const bytes = await response.body();
  const disposition = response.headers()['content-disposition'] || '';
  if (!disposition.toLowerCase().includes('attachment')) throw new Error(`${name} export omitted attachment disposition.`);
  if (kind === 'xlsx' && (bytes.subarray(0, 4).toString('hex') !== '504b0304'
    || !(response.headers()['content-type'] || '').includes('spreadsheetml.sheet'))) throw new Error(`${name} XLSX signature/content type is invalid.`);
  if (kind === 'pdf' && (bytes.subarray(0, 5).toString('ascii') !== '%PDF-'
    || !(response.headers()['content-type'] || '').includes('application/pdf'))) throw new Error(`${name} PDF signature/content type is invalid.`);
  fs.writeFileSync(path.join(runDir, `${name}.${kind}`), bytes);
  checks.push({ export: name, format: kind, bytes: bytes.length, disposition: 'attachment', authorization: 'authorized', integrity: 'signature verified' });
}

async function main() {
  await assertPortFree(port);
  fixture = phpFixture();
  if (fixture.database !== 'erp_elec_new_test' || fixture.port !== 5443 || fixture.host !== '127.0.0.1') throw new Error('Guarded fixture returned an unexpected database identity.');
  serverLog = fs.openSync(path.join(runDir, 'server.log.redacted.txt'), 'w');
  server = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', '.', router], { cwd: path.join(root, 'public'), env,
    stdio: ['ignore', serverLog, serverLog], windowsHide: true });
  let ready = false;
  for (let i = 0; i < 60; i++) {
    if (server.exitCode !== null) throw new Error('Harness-owned Laravel server exited during startup.');
    try { if ((await fetch(base + '/login')).status === 200) { ready = true; break; } } catch {}
    await new Promise(resolve => setTimeout(resolve, 400));
  }
  if (!ready) throw new Error('Laravel server did not become ready.');
  browser = await chromium.launch({ executablePath: edge, headless: true });
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'ar-EG', timezoneId: 'Africa/Cairo' });
  page = await context.newPage();
  page.setDefaultTimeout(15000);
  page.setDefaultNavigationTimeout(30000);
  observe(page);
  await login(fixture.actor);
  if (await page.locator('html').getAttribute('dir') !== 'rtl') throw new Error('Arabic login did not render RTL.');
  checks.push({ login: 'authorized synthetic accountant', user_id: fixture.actor.id, result: 'passed' });

  await visit('/financial/supplier-invoices', fixture.supplier_invoice.supplier_invoice_number, { width: 1440, height: 900 });
  await visit(`/financial/supplier-invoices/${fixture.supplier_invoice.id}`, fixture.supplier_invoice.official_number, { width: 1440, height: 900 });
  await visit('/financial/ap', fixture.supplier_invoice.official_number, { width: 1440, height: 900 });
  await visit('/treasury/payments', null, { width: 1440, height: 900 });
  await visit('/financial/customer-invoices', fixture.customer_invoice.official_number, { width: 1440, height: 900 });
  await visit(`/financial/customer-invoices/${fixture.customer_invoice.id}`, fixture.customer_invoice.official_number, { width: 1440, height: 900 });
  await visit('/financial/ar', fixture.customer_invoice.official_number, { width: 1440, height: 900 });
  await visit('/treasury/collections', null, { width: 1440, height: 900 });
  await visit('/financial/credit-notes', fixture.credit_note.reason, { width: 1440, height: 900 });
  await visit('/treasury/transfers', fixture.treasury_movement.kind, { width: 1440, height: 900 });
  for (const route of ['/accounting/dashboard', '/accounting/journals', '/accounting/ledger', '/accounting/trial-balance',
    '/accounting/income-statement', '/accounting/balance-sheet', '/accounting/grni', '/accounting/ppv', '/accounting/reconciliation']) {
    await visit(route, null, { width: 1440, height: 900 });
  }
  checks.push({ desktop_pages: 'M11/M12/M13 financial registers, invoice details/matching and reports', count: 19, result: 'passed' });

  for (const pathname of ['/financial/supplier-invoices', `/financial/supplier-invoices/${fixture.supplier_invoice.id}`, '/financial/ap',
    '/financial/customer-invoices', `/financial/customer-invoices/${fixture.customer_invoice.id}`, '/financial/ar',
    '/financial/credit-notes', '/treasury/payments', '/treasury/collections', '/treasury/transfers', '/accounting/trial-balance', '/accounting/reconciliation']) {
    await visit(pathname, null, { width: 390, height: 844 });
  }
  checks.push({ mobile_pages: 'M11/M12/M13 RTL including invoice details/matching', count: 12, result: 'passed' });

  await downloadAndVerify(`/financial/documents/supplier-invoice/${fixture.supplier_invoice.id}`, fixture.supplier_invoice.pdf_sha256, 'supplier-invoice');
  await downloadAndVerify(`/financial/documents/customer-invoice/${fixture.customer_invoice.id}`, fixture.customer_invoice.pdf_sha256, 'customer-invoice');
  await downloadAndVerify(`/treasury/movements/${fixture.treasury_movement.id}/pdf`, fixture.treasury_pdf_sha256, 'treasury-movement');
  const reportQuery = `?branch=${fixture.branch.id}&from=2026-01-01&to=2026-12-31`;
  await exportAndVerify(`/accounting/reports/trial-balance/xlsx${reportQuery}`, 'xlsx', 'trial-balance');
  await exportAndVerify(`/accounting/reports/trial-balance/pdf${reportQuery}`, 'pdf', 'trial-balance');

  const deniedContext = await browser.newContext({ viewport: { width: 390, height: 844 }, locale: 'ar-EG', timezoneId: 'Africa/Cairo' });
  const deniedPage = await deniedContext.newPage();
  observe(deniedPage);
  await login(fixture.denied_actor, deniedPage);
  const deniedResponse = await deniedPage.request.get(base + `/financial/documents/supplier-invoice/${fixture.supplier_invoice.id}`);
  const deniedCustomer = await deniedPage.request.get(base + `/financial/documents/customer-invoice/${fixture.customer_invoice.id}`);
  const deniedReportXlsx = await deniedPage.request.get(base + `/accounting/reports/trial-balance/xlsx${reportQuery}`);
  const deniedReportPdf = await deniedPage.request.get(base + `/accounting/reports/trial-balance/pdf${reportQuery}`);
  const deniedTreasuryPdf = await deniedPage.request.get(base + `/treasury/movements/${fixture.treasury_movement.id}/pdf`);
  if ([deniedResponse, deniedCustomer, deniedReportXlsx, deniedReportPdf, deniedTreasuryPdf].some(response => response.status() !== 403)) {
    throw new Error(`Unprivileged financial download access was not denied: ${[deniedResponse, deniedCustomer, deniedReportXlsx, deniedReportPdf, deniedTreasuryPdf].map(response => response.status()).join(',')}.`);
  }
  checks.push({ private_pdf_and_report_authorization: 'supplier/customer/treasury PDFs and XLSX/PDF reports denied to active unscoped actor',
    statuses: [deniedResponse.status(), deniedCustomer.status(), deniedTreasuryPdf.status(), deniedReportXlsx.status(), deniedReportPdf.status()], result: 'passed' });
  await deniedContext.close();

  if (browserErrors.length || livewireTraffic.some(row => row.status >= 500 || row.failure)) throw new Error(`Browser/Livewire errors: ${JSON.stringify({ browserErrors, livewireTraffic })}`);
  const result = { result: 'PASS', run_id: tag, database: `${fixture.database} on ${fixture.host}:${fixture.port}`,
    browser: 'Microsoft Edge via Playwright', viewport_sizes: [1440, 390], fixture, checks, browser_errors: browserErrors, livewire_traffic: livewireTraffic };
  fs.writeFileSync(path.join(runDir, 'result.json'), JSON.stringify(result, null, 2) + '\n');
  fs.writeFileSync(path.join(outputRoot, 'latest.json'), JSON.stringify(result, null, 2) + '\n');
  process.stdout.write(`Evidence: ${runDir}\n`);
}

main().catch(async error => {
  const result = { result: 'FAIL', run_id: tag, failure: String(error.message).slice(0, 3500), checks, browser_errors: browserErrors, livewire_traffic: livewireTraffic };
  if (page && !page.isClosed()) await page.screenshot({ path: path.join(runDir, 'failure-state.png'), animations: 'disabled' }).catch(() => {});
  fs.writeFileSync(path.join(runDir, 'result.json'), JSON.stringify(result, null, 2) + '\n');
  process.stderr.write(`${result.failure}\n`);
  process.exitCode = 1;
}).finally(async () => {
  if (browser) await browser.close().catch(() => {});
  if (server && server.exitCode === null) { server.kill(); await new Promise(resolve => setTimeout(resolve, 700)); }
  if (serverLog) fs.closeSync(serverLog);
  fs.rmSync(temp, { recursive: true, force: true });
});
