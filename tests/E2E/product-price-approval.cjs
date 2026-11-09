// Browser smoke on the existing nonce-owned, disposable Gate2 PostgreSQL cluster only.
const fs = require('node:fs');
const path = require('node:path');
const net = require('node:net');
const { spawn, spawnSync } = require('node:child_process');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '..', '..');
const edge = 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe';
if (!fs.existsSync(edge)) throw new Error('Installed Microsoft Edge is required for the product price browser check.');
const privateRoot = path.join(root, '.tmp', 'master-remediation-20261101', 'ui-private');
const finance = path.join(privateRoot, 'finance');
const cluster = path.join(root, '.tmp', 'master-remediation-20261101', 'ui-pgdata');
const markerPath = path.join(privateRoot, '.codex-ui-owned.txt');
const bootstrap = JSON.parse(fs.readFileSync(path.join(finance, 'gate2-finance-ui-bootstrap.json'), 'utf8'));
const nonce = process.env.E2E_FINANCE_DB_NONCE || bootstrap.ownership_nonce;
if (fs.readFileSync(markerPath, 'utf8').trim() !== 'master-remediation-20261101 Gate2 UI private PostgreSQL marker'
    || !/^[a-f0-9]{64}$/.test(nonce) || bootstrap.ownership_nonce !== nonce
    || bootstrap.database !== `erp_elec_new_finance_ui_${nonce.slice(0, 32)}` || bootstrap.port !== 49686
    || fs.realpathSync(bootstrap.data_directory) !== fs.realpathSync(cluster)) {
  throw new Error('ABORT: product price E2E requires the owned Gate2 PostgreSQL receipt.');
}
const pid = fs.readFileSync(path.join(cluster, 'postmaster.pid'), 'utf8').split(/\r?\n/);
if (fs.realpathSync(pid[1]) !== fs.realpathSync(cluster) || Number(pid[3]) !== 49686) throw new Error('ABORT: Gate2 PostgreSQL is not running at its owned port.');
const env = { ...process.env, APP_ENV: 'testing', APP_KEY: bootstrap.app_key, DB_CONNECTION: 'pgsql', DB_URL: '', DB_HOST: '127.0.0.1', DB_PORT: '49686',
  DB_DATABASE: bootstrap.database, DB_USERNAME: 'postgres', DB_PASSWORD: '', E2E_DB_PORT: '49686', E2E_FINANCE_DB_NONCE: nonce,
  APP_CONFIG_CACHE: '.tmp/master-remediation-20261101/ui-private/finance/cache/config.php',
  APP_ROUTES_CACHE: '.tmp/master-remediation-20261101/ui-private/finance/cache/routes.php',
  APP_EVENTS_CACHE: '.tmp/master-remediation-20261101/ui-private/finance/cache/events.php',
  APP_SERVICES_CACHE: '.tmp/master-remediation-20261101/ui-private/finance/cache/services.php',
  APP_PACKAGES_CACHE: '.tmp/master-remediation-20261101/ui-private/finance/cache/packages.php',
  APP_VIEW_COMPILED_PATH: path.join(finance, 'views'), VIEW_COMPILED_PATH: path.join(finance, 'views'),
  LARAVEL_STORAGE_PATH: path.join(finance, 'storage'), APP_URL: '', SESSION_DRIVER: 'file', CACHE_STORE: 'array', QUEUE_CONNECTION: 'sync' };
const fixtureFile = path.join(root, 'tests', 'E2E', 'support', 'product-price-actors.php');
function fixture(mode, input) {
  const result = spawnSync('php', [fixtureFile, mode], { cwd: root, env, input: input ? JSON.stringify(input) : '', encoding: 'utf8', windowsHide: true });
  if (result.status !== 0) throw new Error(`Product price ${mode} fixture failed; inspect private Laravel log.`);
  return JSON.parse(result.stdout);
}
async function freePort() {
  return new Promise((resolve, reject) => { const server = net.createServer(); server.once('error', reject);
    server.listen(0, '127.0.0.1', () => { const port = server.address().port; server.close(error => error ? reject(error) : resolve(port)); }); });
}
async function waitReady(base, server) {
  for (let attempt = 0; attempt < 60; attempt++) {
    if (server.exitCode !== null) throw new Error('Private PHP server exited before readiness.');
    try { const response = await fetch(`${base}/login`); if (response.status === 200) return; } catch (_) {}
    await new Promise(resolve => setTimeout(resolve, 250));
  }
  throw new Error('Private PHP server did not become ready.');
}
async function login(browser, base, actor) {
  const context = await browser.newContext({ locale: 'ar-EG', timezoneId: 'Africa/Cairo', viewport: { width: 1440, height: 1000 } });
  const page = await context.newPage();
  await page.goto(`${base}/login`);
  await page.getByLabel(/البريد الإلكتروني|البريد/).fill(actor.email);
  await page.getByLabel(/كلمة المرور/).fill(actor.password);
  await Promise.all([page.waitForURL(url => !url.pathname.endsWith('/login')), page.locator('button[type="submit"]').click()]);
  return { context, page };
}
async function capturePriceUx(page, productId) {
  const evidence = path.join(finance, 'product-price-browser');
  fs.mkdirSync(evidence, { recursive: true });
  const screenshots = [];
  for (const [name, width, height] of [['desktop', 1440, 1000], ['mobile', 390, 844]]) {
    await page.setViewportSize({ width, height });
    await page.getByRole('heading', { name: 'أسعار البيع المعتمدة' }).waitFor();
    const layout = await page.evaluate(() => ({ direction: getComputedStyle(document.documentElement).direction,
      viewport: window.innerWidth, document: document.documentElement.scrollWidth }));
    if (layout.direction !== 'rtl' || layout.document > layout.viewport + 1) throw new Error(`Product price ${name} RTL or viewport overflow failed.`);
    const file = path.join(evidence, `product-${productId}-${name}.png`);
    await page.screenshot({ path: file, fullPage: true });
    screenshots.push(path.relative(root, file).replaceAll('\\', '/'));
  }
  return screenshots;
}
(async () => {
  const fixtureData = fixture('prepare');
  const port = await freePort();
  const base = `http://127.0.0.1:${port}`;
  const server = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', path.join(root, 'public'), path.join(root, 'tests', 'Support', 'gate2-finance-ui-router.php')],
    { cwd: path.join(root, 'public'), env: { ...env, APP_URL: base }, stdio: 'ignore', windowsHide: true });
  let browser;
  try {
    await waitReady(base, server);
    browser = await chromium.launch({ executablePath: edge, headless: true });
    const maker = await login(browser, base, fixtureData.maker);
    const page = maker.page;
    await page.goto(`${base}/products/${fixtureData.product_id}/prices`);
    await page.getByLabel('السعر (ج.م)').fill('123.45');
    await page.getByLabel('الوحدة', { exact: true }).selectOption({ index: 1 });
    await page.getByLabel('مرجع اعتماد السعر').fill('BROWSER-PRICE-APPROVAL');
    await page.getByLabel('يسري من').fill('2026-11-06');
    await page.getByRole('button', { name: 'حفظ للاعتماد' }).click();
    await page.getByText('تم حفظ مسودة السعر للاعتماد المستقل.').waitFor();
    const draft = page.getByRole('row').filter({ hasText: 'BROWSER-PRICE-APPROVAL' });
    await draft.getByText('مسودة').waitFor();
    if (await draft.getByRole('button', { name: 'اعتماد' }).count()) throw new Error('Maker can approve own price.');

    const reviewer = await login(browser, base, fixtureData.reviewer);
    await reviewer.page.goto(`${base}/products/${fixtureData.product_id}/prices`);
    const reviewerRow = reviewer.page.getByRole('row').filter({ hasText: 'BROWSER-PRICE-APPROVAL' });
    await reviewerRow.getByRole('button', { name: 'اعتماد' }).click();
    await reviewerRow.getByText('معتمد').waitFor();
    const screenshots = await capturePriceUx(reviewer.page, fixtureData.product_id);
    const verified = fixture('verify', { product_id: fixtureData.product_id, maker_id: fixtureData.maker.id, reviewer_id: fixtureData.reviewer.id });
    process.stdout.write(JSON.stringify({ status: 'PASS', database: bootstrap.database, port: 49686, screenshots, ...verified }) + '\n');
    await maker.context.close(); await reviewer.context.close();
  } finally {
    try {
      if (browser) await browser.close();
    } finally {
      server.kill();
      fixture('cleanup', { maker_id: fixtureData.maker.id, reviewer_id: fixtureData.reviewer.id });
    }
  }
})().catch(error => { process.stderr.write(`${error.message}\n`); process.exitCode = 1; });
