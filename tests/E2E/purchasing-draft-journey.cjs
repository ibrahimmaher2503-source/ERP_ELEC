'use strict';

const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { spawn, spawnSync } = require('node:child_process');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '../..');
const url = 'http://127.0.0.1:8023';
const edge = process.env.PLAYWRIGHT_EXECUTABLE_PATH || 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe';
const evidence = path.join(root, '.tmp/purchasing-draft-browser');
const finance = path.join(root, '.tmp/master-remediation-20261101/ui-private/finance');
const bootstrap = JSON.parse(fs.readFileSync(path.join(finance, 'gate2-finance-ui-bootstrap.json'), 'utf8'));
const nonce = bootstrap.ownership_nonce;
if (!/^[a-f0-9]{64}$/.test(nonce) || bootstrap.database !== `erp_elec_new_finance_ui_${nonce.slice(0, 32)}`
  || bootstrap.port !== 49686 || fs.realpathSync(bootstrap.data_directory) !== fs.realpathSync(path.join(root, '.tmp/master-remediation-20261101/ui-pgdata'))
  || fs.readFileSync(path.join(root, '.tmp/master-remediation-20261101/ui-private/.codex-ui-owned.txt'), 'utf8').trim() !== 'master-remediation-20261101 Gate2 UI private PostgreSQL marker') {
  throw new Error('Purchasing browser check requires the owned finance UI receipt');
}
const field = (page, label) => page.getByLabel(new RegExp(`^\\s*${label}\\s*\\*?\\s*$`));
async function chooseProduct(page, model, sku, id) {
  const select = page.locator(`select[wire\\:model="${model}"]`);
  if (await select.locator(`option[value="${id}"]`).count() === 0) {
    const wrapper = select.locator('xpath=ancestor::erp-select');
    await wrapper.locator('[data-erp-select-trigger]').click();
    await wrapper.locator('.erp-select-popover:visible .erp-select-search').fill(sku);
  }
  await select.locator(`option[value="${id}"]`).waitFor({ state: 'attached' });
  await select.selectOption(String(id));
}
const env = () => ({ ...process.env, APP_ENV: 'testing', APP_DEBUG: 'false', APP_KEY: bootstrap.app_key, APP_URL: url,
  DB_CONNECTION: 'pgsql', DB_URL: '', DB_HOST: '127.0.0.1', DB_PORT: '49686', DB_DATABASE: bootstrap.database,
  DB_USERNAME: 'postgres', DB_PASSWORD: '', E2E_DB_PORT: '49686', E2E_FINANCE_DB_NONCE: nonce,
  SESSION_DRIVER: 'file', CACHE_STORE: 'array', QUEUE_CONNECTION: 'sync', MAIL_MAILER: 'array',
  APP_CONFIG_CACHE: '.tmp/master-remediation-20261101/ui-private/finance/cache/config.php',
  APP_ROUTES_CACHE: '.tmp/master-remediation-20261101/ui-private/finance/cache/routes.php',
  APP_EVENTS_CACHE: '.tmp/master-remediation-20261101/ui-private/finance/cache/events.php',
  APP_SERVICES_CACHE: '.tmp/master-remediation-20261101/ui-private/finance/cache/services.php',
  APP_PACKAGES_CACHE: '.tmp/master-remediation-20261101/ui-private/finance/cache/packages.php',
  APP_VIEW_COMPILED_PATH: path.join(finance, 'views'), VIEW_COMPILED_PATH: path.join(finance, 'views'),
  LARAVEL_STORAGE_PATH: path.join(finance, 'storage') });
function fixture(script, input, temp) {
  const result = spawnSync('php', ['-d', 'opcache.enable_cli=0', path.join(__dirname, 'support', script)],
    { cwd: root, env: env(), input: JSON.stringify(input), encoding: 'utf8', windowsHide: true, timeout: 30000 });
  if (result.status !== 0) throw new Error(`Isolated fixture ${script} failed (exit ${result.status}); private output was withheld`);
  return JSON.parse(result.stdout);
}
async function save(page, label) {
  await page.getByRole('button', { name: label, exact: true }).click();
  await page.waitForURL(value => /\/\d+$/.test(value.pathname), { timeout: 20000 });
  const errors = (await page.locator('.error:visible, [role="alert"]:visible').allTextContents()).map(text => text.trim()).filter(Boolean);
  assert.deepEqual(errors, []);
  return Number(new URL(page.url()).pathname.split('/').at(-1));
}
async function main() {
  assert.ok(fs.existsSync(edge), 'Edge is required');
  assert.ok(fs.existsSync(path.join(root, 'public/build/manifest.json')), 'Build assets first');
  fs.mkdirSync(evidence, { recursive: true });
  const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'erp-purchasing-draft-'));
  fs.mkdirSync(path.join(temp, 'views'));
  const tag = crypto.randomBytes(6).toString('hex').toUpperCase();
  const password = crypto.randomBytes(32).toString('base64url');
  const result = { result: 'FAIL', tag, database: bootstrap.database, port: 49686, checks: [] };
  let server, browser, page, actor, actorPrepared = false, watchdog;
  const checkpoint = async (stage, page) => {
    result.stage = stage;
    fs.writeFileSync(path.join(evidence, `${tag}.json`), JSON.stringify(result, null, 2));
    if (page) {
      const dom = await page.evaluate(() => ({ path: location.pathname, title: document.title,
        headings: [...document.querySelectorAll('h1,h2')].map(node => node.textContent.trim()).slice(0, 12),
        alerts: [...document.querySelectorAll('[role="alert"]')].map(node => node.textContent.trim()).slice(0, 8) }));
      fs.writeFileSync(path.join(evidence, `${tag}-${stage}.dom.json`), JSON.stringify(dom, null, 2));
      await page.screenshot({ path: path.join(evidence, `${tag}-${stage}.png`), timeout: 10000 });
    }
  };
  try {
    await checkpoint('preflight');
    assert.equal(fixture('prepare-purchasing-draft-journey.php', { preflight: true, tag }, temp).owned, true);
    actorPrepared = true;
    await checkpoint('actor-preparing');
    const prepared = fixture('prepare-purchasing-draft-journey.php', { tag, password, prepare: true }, temp);
    actor = prepared.actor;
    const master = prepared.master;
    const actorState = fixture('prepare-purchasing-draft-journey.php', { tag, actor_id: actor.id, verify_actor: true }, temp);
    assert.deepEqual(actorState, { active: true, administrator: true });
    await checkpoint('fixtures-ready');
    const log = fs.openSync(path.join(temp, 'server.log'), 'w');
    server = spawn('php', ['-S', '127.0.0.1:8023', '-t', 'public', path.join(root, 'tests/Support/gate2-finance-ui-router.php')],
      { cwd: root, env: env(), stdio: ['ignore', log, log], windowsHide: true });
    let ready = false;
    for (let i = 0; i < 60; i++) {
      if (server.exitCode !== null) throw new Error('Isolated Laravel server exited during startup');
      try { if ((await fetch(url + '/login')).status === 200) { ready = true; break; } } catch {}
      await new Promise(resolve => setTimeout(resolve, 500));
    }
    assert.ok(ready, 'Isolated Laravel login did not become ready');
    await checkpoint('server-ready');
    browser = await chromium.launch({ executablePath: edge, headless: true });
    page = await browser.newPage({ viewport: { width: 1440, height: 900 }, locale: 'ar-EG', timezoneId: 'Africa/Cairo' });
    page.setDefaultTimeout(15000); page.setDefaultNavigationTimeout(25000);
    watchdog = setTimeout(() => {
      result.failure = `Browser watchdog at ${result.stage}`;
      if (actorPrepared) {
        try { result.cleanup = fixture('prepare-purchasing-draft-journey.php', { tag, cleanup: true }, temp); }
        catch (error) { result.cleanup_failure = String(error.message).slice(0, 200); }
      }
      fs.writeFileSync(path.join(evidence, `${tag}.json`), JSON.stringify(result, null, 2));
      browser?.close().catch(() => {});
      server?.kill();
      process.exit(124);
    }, 120000);
    const errors = []; page.on('pageerror', error => errors.push(error.message));
    result.login_responses = [];
    page.on('response', response => {
      const pathname = new URL(response.url()).pathname;
      if (pathname === '/login' || pathname === '/dashboard') result.login_responses.push({ path: pathname, status: response.status() });
    });
    await page.goto(url + '/login', { waitUntil: 'domcontentloaded' });
    await checkpoint('login-page', page);
    await page.locator('input[name="email"]').fill(actor.email);
    await page.locator('input[name="password"]').fill(password);
    const acceptedLogin = page.waitForResponse(response => new URL(response.url()).pathname === '/login'
      && response.request().method() === 'POST' && response.status() === 302, { timeout: 20000 });
    await page.locator('form button[type="submit"]').click({ noWaitAfter: true });
    await acceptedLogin;
    const dashboard = await page.goto(url + '/dashboard', { waitUntil: 'commit', timeout: 25000 });
    assert.equal(dashboard?.status(), 200, 'authenticated dashboard must return 200');
    assert.equal(new URL(page.url()).pathname, '/dashboard');
    await page.locator('#main-content').waitFor({ state: 'visible', timeout: 15000 });
    await checkpoint('logged-in', page);

    await page.goto(url + '/suppliers/create');
    await checkpoint('supplier-form', page);
    await field(page, 'الاسم القانوني بالعربية').fill(`مورد اختبار مشتريات ${tag}`);
    await checkpoint('supplier-name-filled');
    await field(page, 'كود المورد').fill(`R2-${tag}-S`);
    await checkpoint('supplier-code-filled');
    await field(page, 'نوع المورد').selectOption('distributor');
    await checkpoint('supplier-type-filled');
    const supplier = await save(page, 'حفظ');
    await checkpoint('supplier-saved', page);
    result.checks.push('Supplier created through UI');

    await page.goto(url + '/purchasing/requisitions/create');
    await checkpoint('requisition-form', page);
    await field(page, 'الفرع').selectOption(String(master.branch_id));
    await field(page, 'سبب الاحتياج').fill(`احتياج تجريبي ${tag}`);
    await page.getByRole('button', { name: 'إضافة صنف' }).click();
    await chooseProduct(page, 'form.lines.0.product_id', master.product_sku, master.product_id);
    await field(page, 'الكمية').fill('2.00000000');
    const requisition = await save(page, 'حفظ المسودة');
    await checkpoint('requisition-saved', page);
    result.checks.push('Requisition draft created through UI');

    await page.goto(url + '/purchasing/rfqs/create');
    await checkpoint('rfq-form', page);
    await field(page, 'مصدر طلب العرض').selectOption('STANDALONE');
    await field(page, 'الفرع').selectOption(String(master.branch_id));
    await field(page, 'مبرر طلب العرض المستقل').fill(`تجربة مستقلة ${tag}`);
    await field(page, 'الموضوع').fill(`طلب عرض تجريبي ${tag}`);
    await page.getByRole('button', { name: 'إضافة صنف' }).click();
    await chooseProduct(page, 'form.lines.0.product_id', master.product_sku, master.product_id);
    await field(page, 'كمية طلب العرض').fill('2.00000000');
    await page.getByText('اختيار الموردين').click();
    await page.getByLabel(`مورد اختبار مشتريات ${tag} — R2-${tag}-S`).check();
    const rfq = await save(page, 'حفظ المسودة');
    await checkpoint('rfq-saved', page);
    result.checks.push('Standalone RFQ draft created through UI; no issue or dispatch');

    await page.goto(url + '/purchasing/orders/create');
    await checkpoint('order-form', page);
    await field(page, 'الفرع').selectOption(String(master.branch_id));
    const supplierSelect = page.locator('select[wire\\:model="form.supplier_id"]');
    if (await supplierSelect.locator(`option[value="${supplier}"]`).count() === 0) {
      const wrapper = supplierSelect.locator('xpath=ancestor::erp-select');
      await wrapper.locator('[data-erp-select-trigger]').click();
      await wrapper.locator('.erp-select-popover:visible .erp-select-search').fill(`R2-${tag}-S`);
    }
    await supplierSelect.locator(`option[value="${supplier}"]`).waitFor({ state: 'attached' });
    await supplierSelect.selectOption(String(supplier));
    await page.getByRole('button', { name: 'إضافة صنف' }).click();
    await chooseProduct(page, 'form.lines.0.product_id', master.product_sku, master.product_id);
    await field(page, 'الكمية').fill('2.00000000');
    await field(page, 'سعر الوحدة').fill('10.00');
    const order = await save(page, 'حفظ أمر الشراء');
    await checkpoint('order-saved', page);
    result.checks.push('Direct purchase order draft created through UI; no approval or issue');

    await page.goto(url + '/purchasing/receipts/create');
    await checkpoint('receipt-form', page);
    assert.equal(await page.getByText('مسودة استلام مشتريات جديدة').count(), 1);
    result.checks.push('Receipt draft entry opens; saving requires a separately approved order');
    assert.deepEqual(errors, []);
    result.result = 'PASS'; result.records = { supplier, requisition, rfq, order };
  } catch (error) {
    result.failure = String(error.stack || error).slice(0, 1500);
    result.browser_path = page?.url() || null;
    if (page && !page.isClosed()) {
      try {
        const dom = await page.evaluate(() => ({ path: location.pathname, title: document.title,
          alerts: [...document.querySelectorAll('[role="alert"], [data-flux-error]')].map(node => node.textContent.trim()).filter(Boolean).slice(0, 12),
          validity: [...document.querySelectorAll('input:invalid')].map(node => node.name || node.type).slice(0, 8) }));
        fs.writeFileSync(path.join(evidence, `${tag}-failure.dom.json`), JSON.stringify(dom, null, 2));
        await page.locator('input[name="email"], input[name="password"]').evaluateAll(inputs => inputs.forEach(input => { input.value = ''; }));
        await page.screenshot({ path: path.join(evidence, `${tag}-failure.png`), timeout: 5000 });
      } catch { /* Browser may already be closing. */ }
    }
    process.exitCode = 1;
  }
  finally {
    try {
      const logPath = path.join(temp, 'server.log');
      if (fs.existsSync(logPath)) {
        const sanitized = fs.readFileSync(logPath, 'utf8').slice(-8000).replaceAll(password, '[REDACTED]').replaceAll(actor?.email || '\0', '[TEST_ACTOR]');
        fs.writeFileSync(path.join(evidence, `${tag}-server.redacted.log`), sanitized);
      }
    } catch { /* Keep the primary failure. */ }
    if (actorPrepared) {
      try { result.cleanup = fixture('prepare-purchasing-draft-journey.php', { tag, cleanup: true }, temp); }
      catch (error) { result.cleanup_failure = String(error.message).slice(0, 500); result.result = 'FAIL'; process.exitCode = 1; }
    }
    if (browser) await Promise.race([browser.close().catch(() => {}), new Promise(resolve => setTimeout(resolve, 5000))]);
    if (server && server.exitCode === null) server.kill();
    fs.writeFileSync(path.join(evidence, `${tag}.json`), JSON.stringify(result, null, 2));
    fs.rmSync(temp, { recursive: true, force: true });
    if (watchdog) clearTimeout(watchdog);
    console.log(`${result.result}: purchasing draft browser journey (${result.checks.length} checks)`);
  }
}
main().catch(error => { console.error(String(error)); process.exitCode = 1; });
