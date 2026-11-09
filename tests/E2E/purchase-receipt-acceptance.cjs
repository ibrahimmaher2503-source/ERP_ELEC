'use strict';

const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { spawn, spawnSync } = require('node:child_process');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '../..');
const privateRoot = path.join(root, '.tmp/master-remediation-20261101/ui-private');
const finance = path.join(privateRoot, 'finance');
const cluster = path.join(root, '.tmp/master-remediation-20261101/ui-pgdata');
const bootstrap = JSON.parse(fs.readFileSync(path.join(finance, 'gate2-finance-ui-bootstrap.json'), 'utf8'));
const nonce = bootstrap.ownership_nonce;
const pid = fs.readFileSync(path.join(cluster, 'postmaster.pid'), 'utf8').split(/\r?\n/);
if (!/^[a-f0-9]{64}$/.test(nonce) || bootstrap.database !== `erp_elec_new_finance_ui_${nonce.slice(0, 32)}`
  || bootstrap.port !== 49686 || fs.realpathSync(bootstrap.data_directory) !== fs.realpathSync(cluster)
  || fs.realpathSync(pid[1]) !== fs.realpathSync(cluster) || Number(pid[3]) !== 49686
  || fs.readFileSync(path.join(privateRoot, '.codex-ui-owned.txt'), 'utf8').trim() !== 'master-remediation-20261101 Gate2 UI private PostgreSQL marker') {
  throw new Error('ABORT: exact owned Gate2 finance PostgreSQL is required');
}
const port = 8024;
const base = `http://127.0.0.1:${port}`;
const tag = crypto.randomBytes(6).toString('hex').toUpperCase();
const password = crypto.randomBytes(32).toString('base64url');
const output = path.join(root, '.tmp/purchase-receipt-acceptance', tag + '.json');
const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'erp-receipt-'));
const env = { ...process.env, APP_ENV: 'testing', APP_DEBUG: 'false', APP_KEY: bootstrap.app_key, APP_URL: base,
  DB_CONNECTION: 'pgsql', DB_URL: '', DB_HOST: '127.0.0.1', DB_PORT: '49686', DB_DATABASE: bootstrap.database,
  DB_USERNAME: 'postgres', DB_PASSWORD: '', E2E_DB_PORT: '49686', E2E_FINANCE_DB_NONCE: nonce,
  SESSION_DRIVER: 'file', CACHE_STORE: 'array', QUEUE_CONNECTION: 'sync', MAIL_MAILER: 'array',
  APP_CONFIG_CACHE: '.tmp/master-remediation-20261101/ui-private/finance/cache/config.php',
  APP_ROUTES_CACHE: '.tmp/master-remediation-20261101/ui-private/finance/cache/routes.php',
  APP_EVENTS_CACHE: '.tmp/master-remediation-20261101/ui-private/finance/cache/events.php',
  APP_SERVICES_CACHE: '.tmp/master-remediation-20261101/ui-private/finance/cache/services.php',
  APP_PACKAGES_CACHE: '.tmp/master-remediation-20261101/ui-private/finance/cache/packages.php',
  APP_VIEW_COMPILED_PATH: path.join(temp, 'views'), VIEW_COMPILED_PATH: path.join(temp, 'views'),
  LARAVEL_STORAGE_PATH: path.join(finance, 'storage') };
function fixture(script, input) {
  const result = spawnSync('php', ['-d', 'opcache.enable_cli=0', path.join(__dirname, 'support', script)],
    { cwd: root, env, input: JSON.stringify(input), encoding: 'utf8', windowsHide: true, timeout: 30000 });
  if (result.status !== 0) throw new Error(`Guarded fixture ${script} failed (exit ${result.status}); private output withheld`);
  return JSON.parse(result.stdout);
}
const actorFixture = (input) => fixture('prepare-purchasing-draft-journey.php', input);
const receiptFixture = (input) => fixture('prepare-purchase-receipt-acceptance.php', input);
async function main() {
  fs.mkdirSync(path.dirname(output), { recursive: true });
  fs.mkdirSync(path.join(temp, 'views'));
  const result = { result: 'FAIL', tag, database: bootstrap.database, stages: [] };
  let server, browser, actorReady = false, receiptReady = false;
  try {
    assert.equal(actorFixture({ preflight: true, tag }).owned, true);
    const prepared = actorFixture({ tag, password, prepare: true }); actorReady = true;
    result.stages.push('isolated actor and master prepared');
    const source = receiptFixture({ mode: 'setup', tag, branch_id: prepared.master.branch_id, product_id: prepared.master.product_id });
    receiptReady = true;
    result.stages.push('independent reviewer approved PO through owning action');
    const log = fs.openSync(path.join(temp, 'server.log'), 'w');
    server = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', 'public', path.join(root, 'tests/Support/gate2-finance-ui-router.php')],
      { cwd: root, env, stdio: ['ignore', log, log], windowsHide: true });
    let ready = false;
    for (let i = 0; i < 40; i++) {
      if (server.exitCode !== null) throw new Error('Isolated PHP server exited');
      try { if ((await fetch(base + '/login')).status === 200) { ready = true; break; } } catch {}
      await new Promise(resolve => setTimeout(resolve, 500));
    }
    assert.ok(ready, 'Isolated login route did not become ready');
    browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_EXECUTABLE_PATH || 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', headless: true });
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 }, locale: 'ar-EG', timezoneId: 'Africa/Cairo' });
    page.setDefaultTimeout(15000);
    await page.goto(base + '/login');
    await page.locator('input[name="email"]').fill(prepared.actor.email);
    await page.locator('input[name="password"]').fill(password);
    await page.locator('form button[type="submit"]').click();
    await page.goto(base + `/purchasing/receipts/create?order=${source.order}`);
    assert.equal(await page.locator('select[wire\\:model="form.order_id"]').inputValue(), String(source.order));
    await page.getByLabel('المستودع الوجهة').selectOption(String(source.warehouse));
    await page.getByLabel('مرجع التوريد الفعلي').fill(`قبول استلام ${tag}`);
    await page.getByRole('button', { name: 'حفظ المسودة' }).click();
    await page.waitForURL(/\/purchasing\/receipts\/\d+$/, { timeout: 20000 });
    const receipt = Number(new URL(page.url()).pathname.split('/').at(-1));
    result.stages.push('receipt draft saved through browser');
    page.once('dialog', dialog => dialog.accept());
    await page.getByRole('button', { name: 'تثبيت الاستلام للمراجعة والترحيل' }).click();
    await page.getByText('الاستلام مثبت ولم يرحل بعد', { exact: false }).waitFor();
    result.stages.push('receipt submitted through browser without posting');
    result.persistence = receiptFixture({ mode: 'verify', tag, order: source.order, receipt });
    result.result = 'PASS'; result.records = { order: source.order, receipt };
  } catch (error) { result.failure = String(error.stack || error).slice(0, 1600); }
  finally {
    if (browser) await browser.close().catch(() => {});
    if (server) server.kill();
    if (receiptReady) try { result.reviewer_cleanup = receiptFixture({ mode: 'cleanup', tag }); } catch (error) { result.cleanup_error = String(error.message); }
    if (actorReady) try { result.actor_cleanup = actorFixture({ tag, cleanup: true }); } catch (error) { result.cleanup_error = String(error.message); }
    fs.writeFileSync(output, JSON.stringify(result, null, 2));
    fs.rmSync(temp, { recursive: true, force: true });
  }
  if (result.result !== 'PASS') throw new Error(`Receipt acceptance failed; private evidence: ${output}`);
  process.stdout.write(`PASS receipt draft/submit; private evidence: ${output}\n`);
}
main().catch(error => { process.stderr.write(String(error.message) + '\n'); process.exitCode = 1; });
