'use strict';

// Browser-only acceptance for real Treasury and AR actions in a nonce-named private PostgreSQL UI database.
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const { spawnSync, spawn } = require('node:child_process');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '../..');
const privateRoot = path.join(root, '.tmp/master-remediation-20261101/ui-private/finance');
const bootstrapPath = path.join(privateRoot, 'gate2-finance-ui-bootstrap.json');
const setupPath = path.join(privateRoot, 'gate2-finance-ui-fixture.json');
const fixedViewports = [[1440, 900], [1366, 768], [768, 1024], [390, 844], [360, 800]];
const marker = 'master-remediation-20261101 Gate2 UI private PostgreSQL marker';
let baseUrl;
let output;
let server;
let activePage;
const checks = [];

function check(condition, message) {
  checks.push({ result: condition ? 'PASS' : 'FAIL', message });
  if (!condition) throw new Error(`FAIL ${message}`);
}

function readJson(file) {
  return JSON.parse(fs.readFileSync(file, 'utf8'));
}

function redactPreflightText(value, env) {
  let text = String(value || '');
  for (const [key, secret] of Object.entries(env)) {
    if (/(?:password|secret|token|nonce|app[_-]?key|credential)/i.test(key) && typeof secret === 'string' && secret.length > 0) {
      text = text.split(secret).join('[redacted]');
    }
  }
  return text
    .replace(/((?:password|passwd|secret|token|nonce|app[_-]?key|credential)[\w.-]*\s*[=:]\s*)(["']?)[^\s,"'&}]+/gi, '$1[redacted]')
    .replace(/base64:[A-Za-z0-9+/=]{32,}/gi, '[redacted-key]');
}

function runPhp(file, args, env) {
  const result = spawnSync(process.env.PHP_BINARY || 'php', ['-d', 'opcache.enable_cli=0', path.join(root, file), ...args], {
    cwd: root, encoding: 'utf8', windowsHide: true, env,
  });
  const stdout = redactPreflightText(result.stdout, env);
  const stderr = redactPreflightText(result.stderr, env);
  const terminal = stdout.trim().split(/\r?\n/).filter(Boolean).pop() || '';
  const attemptLogDirectory = path.join(privateRoot, 'artifacts');
  const attemptLog = path.join(attemptLogDirectory,
    `preflight-${path.basename(file, '.php')}-${new Date().toISOString().replace(/[:.]/g, '-')}-${crypto.randomBytes(4).toString('hex')}.log`);
  fs.mkdirSync(attemptLogDirectory, { recursive: true, mode: 0o700 });
  fs.writeFileSync(attemptLog, [
    `process: ${path.basename(file)}`,
    `exit_status: ${result.status === null ? 'null' : result.status}`,
    `terminal_ready: ${/^READY\b/.test(terminal) ? 'yes' : 'no'}`,
    `spawn_error: ${redactPreflightText(result.error?.message, env)}`,
    '--- stderr ---', stderr,
    '--- stdout ---', stdout,
  ].join('\n'), { encoding: 'utf8', mode: 0o600 });
  if (result.status !== 0 || !/^READY\b/.test(terminal)) {
    const reason = result.error?.message || terminal || 'process produced no terminal READY line';
    throw new Error(`${path.basename(file)} preflight failed (exit=${result.status === null ? 'null' : result.status}): ${redactPreflightText(reason, env).slice(0, 320)}; private redacted log: ${attemptLog}`);
  }
  return result.stdout;
}

function verifyPrivateBootstrap() {
  const markerPath = path.join(root, '.tmp/master-remediation-20261101/ui-private/.codex-ui-owned.txt');
  const cluster = fs.realpathSync(path.join(root, '.tmp/master-remediation-20261101/ui-pgdata'));
  const privatePath = fs.realpathSync(path.dirname(markerPath));
  const financePath = fs.realpathSync(privateRoot);
  if (fs.readFileSync(markerPath, 'utf8').trim() !== marker
      || privatePath !== path.join(root, '.tmp/master-remediation-20261101/ui-private')
      || financePath !== path.join(root, '.tmp/master-remediation-20261101/ui-private/finance')
      || !cluster.startsWith(`${root}${path.sep}.tmp${path.sep}master-remediation-20261101${path.sep}`)) {
    throw new Error('Finance UI requires the exact private Gate 2 marker and in-checkout PGDATA.');
  }
  if (!fs.realpathSync(bootstrapPath).startsWith(`${financePath}${path.sep}`)) {
    throw new Error('Finance bootstrap receipt resolves outside its private runtime directory.');
  }
  const bootstrap = readJson(bootstrapPath);
  if (bootstrap.port !== 49686 || bootstrap.database !== `erp_elec_new_finance_ui_${bootstrap.ownership_nonce?.slice(0, 32)}`
      || !/^[a-f0-9]{64}$/.test(bootstrap.ownership_nonce || '') || bootstrap.data_directory !== cluster
      || !/^base64:[A-Za-z0-9+/=]+$/.test(bootstrap.app_key || '')) {
    throw new Error('Finance bootstrap receipt does not bind the private database name, key, nonce, port, and PGDATA.');
  }
  return bootstrap;
}

function dbEnv(bootstrap) {
  return { ...process.env, APP_ENV: 'testing', APP_DEBUG: 'false', APP_KEY: bootstrap.app_key,
    DB_CONNECTION: 'pgsql', DB_URL: '', DB_HOST: '127.0.0.1', DB_PORT: '49686', DB_DATABASE: bootstrap.database,
    DB_USERNAME: 'postgres', DB_PASSWORD: '', E2E_DB_PORT: '49686', E2E_FINANCE_DB_NONCE: bootstrap.ownership_nonce,
    APP_CONFIG_CACHE: '.tmp/master-remediation-20261101/ui-private/finance/cache/config.php',
    APP_ROUTES_CACHE: '.tmp/master-remediation-20261101/ui-private/finance/cache/routes.php',
    APP_EVENTS_CACHE: '.tmp/master-remediation-20261101/ui-private/finance/cache/events.php',
    APP_SERVICES_CACHE: '.tmp/master-remediation-20261101/ui-private/finance/cache/services.php',
    APP_PACKAGES_CACHE: '.tmp/master-remediation-20261101/ui-private/finance/cache/packages.php',
    APP_VIEW_COMPILED_PATH: path.join(privateRoot, 'views'), VIEW_COMPILED_PATH: path.join(privateRoot, 'views'),
    LARAVEL_STORAGE_PATH: path.join(privateRoot, 'storage'), CACHE_STORE: 'array', SESSION_DRIVER: 'file',
    QUEUE_CONNECTION: 'sync', MAIL_MAILER: 'array', BROADCAST_CONNECTION: 'null', DEBUGBAR_ENABLED: 'false', PULSE_ENABLED: 'false' };
}

function isLivewireUpdate(url) {
  return /^\/livewire(?:-[a-z0-9]+)?\/update$/.test(new URL(url).pathname);
}

function requestCalls(request) {
  try {
    const payload = request.postDataJSON();
    return (payload?.components || []).flatMap(component => component?.calls || []);
  } catch { return []; }
}

function hasCall(request, method, firstParameter) {
  return requestCalls(request).some(call => (call.method || call.name) === method
    && (firstParameter === undefined || call.params?.[0] === firstParameter));
}

async function action(page, locator, method, firstParameter) {
  const response = page.waitForResponse(response => isLivewireUpdate(response.url())
    && hasCall(response.request(), method, firstParameter), { timeout: 20_000 });
  await locator.click();
  const result = await response;
  if (!result.ok()) throw new Error(`Livewire ${method} returned HTTP ${result.status()}.`);
  return result;
}

async function actionWithLoadingEvidence(page, locator, method, firstParameter) {
  let seen;
  let release;
  const seenRequest = new Promise(resolve => { seen = resolve; });
  const held = new Promise(resolve => { release = resolve; });
  const response = page.waitForResponse(candidate => isLivewireUpdate(candidate.url())
    && hasCall(candidate.request(), method, firstParameter), { timeout: 20_000 });
  const routeHandler = async route => {
    if (hasCall(route.request(), method, firstParameter)) {
      seen();
      await held;
    }
    await route.continue();
  };
  await page.route('**/livewire*/update', routeHandler);
  const click = locator.click();
  try {
    await Promise.race([seenRequest, new Promise((_, reject) => setTimeout(() => reject(new Error('Expected Livewire action request was not observed.')), 20_000))]);
    await page.waitForFunction(button => button.disabled, await locator.elementHandle(), { timeout: 5_000 });
    check(await locator.isDisabled(), `${method} exposes its actual disabled loading state while its server request is pending`);
  } finally {
    release();
  }
  await click;
  const result = await response;
  await page.unroute('**/livewire*/update', routeHandler);
  if (!result.ok()) throw new Error(`Livewire ${method} returned HTTP ${result.status()}.`);
  return result;
}

async function changeSelect(page, label, value) {
  const update = page.waitForResponse(response => isLivewireUpdate(response.url()), { timeout: 20_000 });
  await page.getByLabel(label, { exact: true }).selectOption(String(value));
  const response = await update;
  if (!response.ok()) throw new Error(`Changing ${label} returned HTTP ${response.status()}.`);
}

async function selectOptionPrefix(page, label, prefix) {
  const select = page.getByLabel(label, { exact: true });
  const option = select.locator('option').filter({ hasText: prefix }).first();
  await option.waitFor({ state: 'attached', timeout: 15_000 });
  const value = await option.getAttribute('value');
  if (!value) throw new Error(`No usable ${label} option starts with ${prefix}.`);
  await changeSelect(page, label, value);
}

async function selectStaticOptionPrefix(page, label, prefix) {
  const select = page.getByLabel(label, { exact: true });
  const option = select.locator('option').filter({ hasText: prefix }).first();
  await option.waitFor({ state: 'attached', timeout: 15_000 });
  const value = await option.getAttribute('value');
  if (!value) throw new Error(`No usable ${label} option starts with ${prefix}.`);
  await select.selectOption(value);
}

async function waitForStatementLine(page, externalId) {
  const row = page.getByRole('row').filter({ hasText: externalId });
  await row.first().waitFor({ state: 'visible', timeout: 15_000 });
  if (await row.count() !== 1) throw new Error(`Expected one statement table row for ${externalId}.`);
  return row;
}

function cairoDate() {
  const parts = new Intl.DateTimeFormat('en-GB', { timeZone: 'Africa/Cairo', year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(new Date());
  const values = Object.fromEntries(parts.map(part => [part.type, part.value]));
  return `${values.year}-${values.month}-${values.day}`;
}

async function login(browser, actor) {
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'ar-EG', timezoneId: 'Africa/Cairo' });
  const page = await context.newPage();
  activePage = page;
  await page.goto(new URL('/login', baseUrl).toString());
  await page.locator('input[name="email"]').fill(actor.email);
  await page.locator('input[name="password"]').fill(actor.password);
  await Promise.all([page.waitForURL(url => !url.pathname.endsWith('/login')), page.locator('button[type="submit"]').click()]);
  check(!new URL(page.url()).pathname.endsWith('/login'), `synthetic ${actor.key} actor authenticated through the real login form`);
  return { context, page };
}

async function screenshot(page, name) {
  await page.screenshot({ path: path.join(output, `${name}.png`), fullPage: true, animations: 'disabled' });
}

async function viewports(browser, actor, fixture, statementId, correctedMovementId) {
  const reader = await login(browser, actor);
  const page = reader.page;
  const routes = [
    ['treasury-statements', '/treasury/statements'],
    ['treasury-statement-detail', `/treasury/statements/${statementId}`],
    ['customer-advances', '/financial/advances'],
    ['party-credit-refunds', '/financial/party-credits'],
    ['treasury-movement-detail', `/treasury/movements/${correctedMovementId}`],
  ];
  for (const [width, height] of fixedViewports) {
    await page.setViewportSize({ width, height });
    for (const [label, route] of routes) {
      await page.goto(new URL(route, baseUrl).toString());
      if (label === 'treasury-statement-detail') await page.getByText(fixture.statement_reference, { exact: false }).waitFor();
      if (label === 'treasury-movement-detail') await page.getByText(String(correctedMovementId), { exact: true }).waitFor();
      if (label === 'customer-advances') {
        await changeSelect(page, 'الفرع', fixture.branch_id);
        await selectOptionPrefix(page, 'المقدم المحصل', `#${fixture.advance_id} ·`);
        await page.getByText('تاريخ التطبيقات والردود', { exact: true }).waitFor();
      }
      if (label === 'party-credit-refunds') {
        await changeSelect(page, 'الفرع', fixture.branch_id);
        await selectOptionPrefix(page, 'الرصيد الدائن الفعلي', `#${fixture.party_credit_id} ·`);
        await page.getByRole('heading', { name: `الرصيد #${fixture.party_credit_id}`, exact: true }).waitFor();
      }
      check(await page.locator('html').getAttribute('dir') === 'rtl'
        && await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth),
      `${label} at ${width}x${height} remains RTL without horizontal page overflow`);
      await screenshot(page, `${label}-${width}x${height}`);
    }
  }
  await reader.context.close();
}

async function main() {
  runPhp('tests/Support/bootstrap-gate2-finance-ui-database.php', [], { ...process.env, APP_ENV: 'testing' });
  const bootstrap = verifyPrivateBootstrap();
  const env = dbEnv(bootstrap);
  const setupOutput = runPhp('tests/Support/gate2-finance-ui-fixtures.php', ['--setup'], env);
  if (!setupOutput.includes('READY')) throw new Error('Finance owner-action setup did not report READY.');
  const runNonce = crypto.randomBytes(16).toString('hex');
  const runOutput = runPhp('tests/Support/gate2-finance-ui-fixtures.php', [`--prepare-run=${runNonce}`], env);
  if (!runOutput.includes('READY')) throw new Error('Finance owner-action acceptance fixture did not report READY.');
  const fixture = readJson(path.join(privateRoot, `gate2-finance-ui-run-${runNonce}.json`));
  if (fixture.state !== 'READY' || fixture.database !== bootstrap.database || fixture.ownership_nonce !== bootstrap.ownership_nonce
      || fixture.run_nonce !== runNonce || fixture.port !== 49686 || !fixture.actors?.maker?.password) {
    throw new Error('Finance acceptance fixture receipt does not match the exact nonce-owned target.');
  }
  const setup = readJson(setupPath);
  if (setup.ownership_nonce !== bootstrap.ownership_nonce || setup.branch_id !== fixture.base_branch_id
      || fixture.branch_id === setup.branch_id
      || setup.bank_account_id !== fixture.bank_account_id || setup.cash_account_id !== fixture.cash_account_id) {
    throw new Error('Financial base fixture receipt does not match the acceptance run.');
  }
  const timestamp = new Date().toISOString().replace(/[:.]/g, '-');
  output = path.join(privateRoot, 'artifacts', `finance-ui-${timestamp}-${runNonce}`);
  fs.mkdirSync(output, { recursive: true });
  const port = await new Promise((resolve, reject) => {
    const net = require('node:net');
    const probe = net.createServer();
    probe.once('error', reject);
    probe.listen(0, '127.0.0.1', () => { const selected = probe.address().port; probe.close(() => resolve(selected)); });
  });
  baseUrl = `http://127.0.0.1:${port}`;
  const appEnv = { ...env, APP_URL: baseUrl, SESSION_DRIVER: 'file', SESSION_COOKIE: `g2_finance_ui_${runNonce.slice(0, 12)}` };
  const serverLogPath = path.join(output, 'server.log');
  server = spawn(process.env.PHP_BINARY || 'php', ['-d', 'opcache.enable_cli=0', '-S', `127.0.0.1:${port}`, '-t', '.', path.join(root, 'tests/Support/gate2-finance-ui-router.php')], {
    cwd: path.join(root, 'public'), env: appEnv, windowsHide: true, stdio: ['ignore', fs.openSync(serverLogPath, 'a'), fs.openSync(serverLogPath, 'a')],
  });
  let browser;
  try {
    const executablePath = process.env.PLAYWRIGHT_EXECUTABLE_PATH || 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe';
    browser = await chromium.launch({ headless: true, executablePath });
    const deadline = Date.now() + 45_000;
    let ready = false;
    while (Date.now() < deadline) {
      if (server.exitCode !== null) throw new Error('Owned finance UI Laravel server exited before readiness.');
      let response;
      let body;
      try {
        response = await fetch(new URL('/login', baseUrl), { redirect: 'manual' });
        body = await response.text();
      } catch { /* private local server is still starting */ }
      if (response?.status >= 500) {
        throw new Error(`Finance UI readiness returned HTTP ${response.status}; inspect private logs ${serverLogPath} and ${path.join(privateRoot, 'storage/logs/laravel.log')}.`);
      }
      if (response?.status >= 400) {
        throw new Error(`Finance UI readiness returned HTTP ${response.status} for /login; inspect private logs ${serverLogPath} and ${path.join(privateRoot, 'storage/logs/laravel.log')}.`);
      }
      if (response?.status === 200 && /name=["']email["']/.test(body || '')) { ready = true; break; }
      await new Promise(resolve => setTimeout(resolve, 250));
    }
    if (!ready) throw new Error('Nonce-owned finance UI Laravel server did not become ready.');

    const statementManager = await login(browser, fixture.actors.manager);
    const page = statementManager.page;
    const statementRoute = '/treasury/statements';
    await page.goto(new URL(statementRoute, baseUrl).toString());
    await page.getByRole('heading', { name: 'كشوف البنك والمطابقة' }).waitFor();
    check(await page.getByRole('button', { name: 'استيراد الملف الخاص للمطابقة', exact: true }).isVisible(),
      'authorized bank statement manager sees the import workflow');
    const date = cairoDate();
    await page.getByLabel('فرع المطابقة', { exact: true }).selectOption(String(fixture.branch_id));
    await page.getByLabel('حساب البنك', { exact: true }).selectOption(String(fixture.bank_account_id));
    await page.getByLabel('مرجع كشف البنك الخارجي', { exact: true }).fill(fixture.statement_reference);
    await page.getByLabel('بداية فترة الكشف', { exact: true }).fill(date);
    await page.getByLabel('نهاية فترة الكشف', { exact: true }).fill(date);
    await page.getByLabel('الرصيد الافتتاحي الخارجي EGP', { exact: true }).fill('not-money');
    await page.getByLabel('الرصيد الختامي الخارجي EGP', { exact: true }).fill('5.00');
    const csv = `external_id,date,amount,reference\r\n${fixture.statement_external_id},${date},2.00,تحصيل قبول جزئي أول ${fixture.run_nonce}\r\n${fixture.statement_external_id_second},${date},3.00,تحصيل قبول جزئي ثان ${fixture.run_nonce}\r\n`;
    const fileInput = page.getByLabel('ملف البنك CSV', { exact: true });
    await page.evaluate(() => {
      window.__financeCsvUploadState = 'pending';
      const input = document.querySelector('input[type="file"]');
      if (!input) throw new Error('Finance CSV file input was not rendered.');
      input.addEventListener('livewire-upload-finish', () => { window.__financeCsvUploadState = 'finish'; }, { once: true });
      input.addEventListener('livewire-upload-error', () => { window.__financeCsvUploadState = 'error'; }, { once: true });
    });
    const uploadResponsePromise = page.waitForResponse(response => /\/livewire(?:-[^/]+)?\/upload-file(?:\?|$)/.test(response.url())
      && response.request().method() === 'POST', { timeout: 30_000 });
    await fileInput.setInputFiles({ name: 'bank-acceptance.csv', mimeType: 'text/csv', buffer: Buffer.from(csv) });
    const uploadResponse = await uploadResponsePromise;
    if (!uploadResponse.ok()) throw new Error(`Private statement CSV upload failed with HTTP ${uploadResponse.status()}.`);
    await page.waitForFunction(() => window.__financeCsvUploadState !== 'pending', null, { timeout: 30_000 });
    if (await page.evaluate(() => window.__financeCsvUploadState) !== 'finish') {
      throw new Error('Livewire did not finish hydrating the statement CSV upload.');
    }
    const invalidImportResponse = page.waitForResponse(response => isLivewireUpdate(response.url()) && hasCall(response.request(), 'import'), { timeout: 20_000 });
    await page.getByRole('button', { name: 'استيراد الملف الخاص للمطابقة', exact: true }).click();
    await invalidImportResponse;
    const importAlerts = await page.getByRole('alert').allTextContents();
    check(importAlerts.some(alert => alert.includes('أدخل مبلغًا عشريًا نصيًا صالحًا')),
      'invalid statement balance displays its specific real decimal validation error');
    await screenshot(page, 'statement-import-error');

    await page.getByLabel('الرصيد الافتتاحي الخارجي EGP', { exact: true }).fill('0.00');
    const importButton = page.getByRole('button', { name: 'استيراد الملف الخاص للمطابقة', exact: true });
    const importAction = actionWithLoadingEvidence(page, importButton, 'import');
    await page.waitForURL(url => /\/treasury\/statements\/\d+$/.test(url.pathname), { timeout: 20_000 });
    await importAction;
    const statementId = Number(new URL(page.url()).pathname.split('/').pop());
    check(Number.isInteger(statementId) && statementId > 0, 'valid CSV import created and opened a real private bank statement');
    const matchedLine = await waitForStatementLine(page, fixture.statement_external_id);
    await page.getByLabel('سبب الإجراء ومرجعه', { exact: true }).fill('مطابقة كشف اصطناعي مع حركة التحصيل الفعلية.');
    const lineSelect = page.getByLabel('سطر البنك', { exact: true });
    await selectStaticOptionPrefix(page, 'سطر البنك', fixture.statement_external_id);
    const movementSelect = page.getByLabel('حركة الخزينة الفعلية', { exact: true });
    await selectStaticOptionPrefix(page, 'حركة الخزينة الفعلية', `#${fixture.statement_movement_id} ·`);
    await page.getByLabel('قيمة المطابقة الجزئية EGP', { exact: true }).fill('2.00');
    await page.getByLabel('سبب الإجراء ومرجعه', { exact: true }).fill('تخصيص جزئي أول لحركة البنك الفعلية ذات القيمة والاتجاه المطابقين.');
    const managerMatchButton = page.getByRole('button', { name: 'ربط الحركة الفعلية بسطر البنك', exact: true });
    check(await lineSelect.inputValue() !== '' && await movementSelect.inputValue() === String(fixture.statement_movement_id),
      'match form selects the actual statement line and same-account movement');
    const matchResponse = page.waitForResponse(response => isLivewireUpdate(response.url()) && hasCall(response.request(), 'matchLine'), { timeout: 20_000 });
    await managerMatchButton.click();
    await matchResponse;
    await matchedLine.getByText('متبقٍ 0.00 EGP', { exact: false }).waitFor();
    const remainingMovement = movementSelect.locator('option').filter({ hasText: `#${fixture.statement_movement_id} ·` });
    check((await remainingMovement.innerText()).includes('متبقٍ 3.00'),
      'partial allocation visibly leaves 3.00 EGP on the actual Treasury movement');
    await page.getByLabel('قيمة المطابقة الجزئية EGP', { exact: true }).fill('3.00');
    await page.getByLabel('سبب الإجراء ومرجعه', { exact: true }).fill('استكمال الرصيد المتبقي من الحركة الفعلية نفسها.');
    await selectStaticOptionPrefix(page, 'سطر البنك', fixture.statement_external_id_second);
    await selectStaticOptionPrefix(page, 'حركة الخزينة الفعلية', `#${fixture.statement_movement_id} ·`);
    const finalMatchResponse = page.waitForResponse(response => isLivewireUpdate(response.url()) && hasCall(response.request(), 'matchLine'), { timeout: 20_000 });
    await managerMatchButton.click();
    await finalMatchResponse;
    const secondMatchedLine = await waitForStatementLine(page, fixture.statement_external_id_second);
    await secondMatchedLine.getByText('متبقٍ 0.00 EGP', { exact: false }).waitFor();
    await page.getByText(`حركة #${fixture.statement_movement_id}`, { exact: false }).waitFor();
    check(await page.getByText('تم ربط سطر البنك بالحركة الفعلية.', { exact: true }).isVisible(),
      'statement matching allocates 2.00 and then 3.00 from the real collection and renders the remaining balance after partial allocation');
    await screenshot(page, 'statement-matched-before-review');
    await statementManager.context.close();

    const reviewer = await login(browser, fixture.actors.reviewer);
    await reviewer.page.goto(new URL(`/treasury/statements/${statementId}`, baseUrl).toString());
    await waitForStatementLine(reviewer.page, fixture.statement_external_id);
    await reviewer.page.getByLabel('سبب الإجراء ومرجعه', { exact: true }).fill('مراجعة مستقلة لكشف المطابقات الاصطناعي.');
    const reviewButton = reviewer.page.getByRole('button', { name: 'حفظ المراجعة المستقلة', exact: true });
    check(await reviewButton.isVisible(), 'a separate authorized reviewer can see the review action');
    await action(reviewer.page, reviewButton, 'review');
    await reviewer.page.getByText('حركات الفرع مطابقة', { exact: false }).waitFor();
    await reviewer.page.getByText('SHA-256:', { exact: false }).waitFor();
    check(await reviewer.page.getByText('5.00', { exact: true }).count() >= 2,
      'reviewed statement shows matching external and Treasury amounts without an invented balancing figure');
    const fileResponsePromise = reviewer.page.waitForResponse(response => response.url().includes(`/treasury/statements/${statementId}/file`), { timeout: 20_000 });
    const downloadPromise = reviewer.page.waitForEvent('download');
    await reviewer.page.getByRole('link', { name: 'تحميل ملف البنك الأصلي', exact: true }).click();
    const [fileResponse, download] = await Promise.all([fileResponsePromise, downloadPromise]);
    const headers = await fileResponse.allHeaders();
    const downloadPath = path.join(output, 'original-bank-statement.csv');
    await download.saveAs(downloadPath);
    check(fileResponse.ok() && /no-store/i.test(headers['cache-control'] || '') && (headers['x-content-type-options'] || '').toLowerCase() === 'nosniff'
      && await fs.promises.readFile(downloadPath, 'utf8') === csv,
    'original statement download is authenticated, private/no-store, nosniff, and byte-identical to the uploaded CSV');
    await screenshot(reviewer.page, 'statement-independent-review');
    await reviewer.context.close();

    const correctionActor = await login(browser, fixture.actors.corrector);
    const originalMovementUrl = new URL(`/treasury/movements/${fixture.correction_movement_id}`, baseUrl).toString();
    await correctionActor.page.goto(originalMovementUrl);
    await correctionActor.page.getByRole('heading', { name: 'تفاصيل حركة الخزينة', exact: true }).waitFor();
    check(await correctionActor.page.getByRole('button', { name: 'ترحيل التصحيح المرتبط', exact: true }).isVisible(),
      'independent scoped corrector sees correction control for another actor’s original collection');
    await correctionActor.page.getByLabel('تاريخ التصحيح الفعلي', { exact: true }).fill(date);
    await correctionActor.page.getByLabel('سبب التصحيح', { exact: true }).fill('تصحيح قبول مستقل يحفظ حركة التحصيل الأصلية.');
    correctionActor.page.once('dialog', dialog => dialog.accept());
    const correctionResponse = correctionActor.page.waitForResponse(response => isLivewireUpdate(response.url()) && hasCall(response.request(), 'correct'), { timeout: 20_000 });
    await correctionActor.page.getByRole('button', { name: 'ترحيل التصحيح المرتبط', exact: true }).click();
    await correctionResponse;
    await correctionActor.page.waitForURL(url => /\/treasury\/movements\/\d+$/.test(url.pathname));
    const correctionUrl = new URL(correctionActor.page.url());
    const correctionId = Number(correctionUrl.pathname.split('/').pop());
    check(correctionId > 0 && correctionId !== Number(fixture.correction_movement_id),
      'correction creates a separate Treasury movement instead of editing the original');
    await correctionActor.page.getByText(`#${fixture.correction_movement_id}`, { exact: false }).waitFor();
    check(await correctionActor.page.getByText('تصحيح مرتبط بالحركة الأصلية', { exact: false }).isVisible(),
      'new correction record links back to the preserved original movement');
    await screenshot(correctionActor.page, 'cash-correction-linked-record');
    await correctionActor.page.goto(originalMovementUrl);
    await correctionActor.page.getByText(`صُححت الحركة بسجل مستقل #${correctionId}`, { exact: false }).waitFor();
    check(!(await correctionActor.page.getByRole('button', { name: 'ترحيل التصحيح المرتبط', exact: true }).count()),
      'original source remains visible and cannot be corrected a second time');
    await screenshot(correctionActor.page, 'cash-correction-original-preserved');

    const cashier2 = await login(browser, fixture.actors.cashier);
    const advancesPage = cashier2.page;
    await advancesPage.goto(new URL('/financial/advances', baseUrl).toString());
    await advancesPage.getByRole('heading', { name: 'مقدمات العملاء', exact: true }).waitFor();
    await changeSelect(advancesPage, 'الفرع', fixture.branch_id);
    await selectOptionPrefix(advancesPage, 'المقدم المحصل', `#${fixture.advance_id} ·`);
    await advancesPage.getByLabel('الذمة الفعلية للعميل نفسه', { exact: true }).waitFor();
    await advancesPage.getByLabel('الذمة الفعلية للعميل نفسه', { exact: true }).selectOption(String(fixture.open_item_id));
    await advancesPage.getByLabel('القيمة EGP', { exact: true }).fill('20.00');
    const applyButton = advancesPage.getByRole('button', { name: 'تطبيق المقدم', exact: true });
    advancesPage.once('dialog', dialog => dialog.accept());
    await action(advancesPage, applyButton, 'execute', 'apply');
    await advancesPage.getByText('تم حفظ الحركة المرتبطة بالمقدم وترحيلها.', { exact: true }).waitFor();
    const applicationHistory = advancesPage.getByText(/تطبيق #\d+.*20\.00 EGP.*قيد #\d+/);
    await applicationHistory.waitFor();
    check(await applicationHistory.isVisible(), 'advance application history shows its actual amount and posted journal reference');
    await screenshot(advancesPage, 'customer-advance-applied');

    await advancesPage.getByLabel('حساب البنك أو النقدية للرد', { exact: true }).selectOption(String(fixture.cash_account_id));
    await advancesPage.getByLabel('القيمة EGP', { exact: true }).fill('15.00');
    await advancesPage.getByLabel('سبب ومرجع رد المقدم', { exact: true }).fill(`رد قبول اصطناعي من المصدر ${fixture.run_nonce}`);
    const advanceRefund = advancesPage.getByRole('button', { name: 'رد المقدم من الخزينة', exact: true });
    advancesPage.once('dialog', dialog => dialog.accept());
    await action(advancesPage, advanceRefund, 'execute', 'refund');
    await advancesPage.getByText('تم حفظ الحركة المرتبطة بالمقدم وترحيلها.', { exact: true }).waitFor();
    const refundHistory = advancesPage.getByText(/رد #\d+.*15\.00 EGP.*حركة خزينة #\d+/);
    await refundHistory.waitFor();
    check(await refundHistory.isVisible(), 'advance refund history shows its actual Treasury movement');
    check(await advancesPage.getByText('المتبقي 25.00 EGP', { exact: false }).count() === 1,
      'after applying 20.00 and refunding 15.00 the real 60.00 advance retains its correct 25.00 balance');
    await screenshot(advancesPage, 'customer-advance-refunded');
    await cashier2.context.close();

    const creditActor = await login(browser, fixture.actors.cashier);
    const creditsPage = creditActor.page;
    await creditsPage.goto(new URL('/financial/party-credits', baseUrl).toString());
    await creditsPage.getByRole('heading', { name: 'رد الأرصدة الدائنة', exact: true }).waitFor();
    await changeSelect(creditsPage, 'الفرع', fixture.branch_id);
    await selectOptionPrefix(creditsPage, 'الرصيد الدائن الفعلي', `#${fixture.party_credit_id} ·`);
    await creditsPage.getByRole('heading', { name: `الرصيد #${fixture.party_credit_id}`, exact: true }).waitFor();
    await creditsPage.getByLabel('حساب البنك أو النقدية', { exact: true }).selectOption(String(fixture.cash_account_id));
    await creditsPage.getByLabel('المبلغ EGP', { exact: true }).fill(fixture.expected_party_credit);
    await creditsPage.getByLabel('سبب ومرجع الرد', { exact: true }).fill(`رد رصيد إشعار اصطناعي ${fixture.run_nonce}`);
    const creditRefund = creditsPage.getByRole('button', { name: 'صرف للعميل', exact: true });
    creditsPage.once('dialog', dialog => dialog.accept());
    await action(creditsPage, creditRefund, 'refund');
    await creditsPage.getByText('تم ترحيل رد الرصيد الدائن وحفظ سند الخزينة المرتبط.', { exact: true }).waitFor();
    const creditRefundHistory = creditsPage.getByText(new RegExp(`${fixture.expected_party_credit} EGP · حركة #\\d+`));
    await creditRefundHistory.waitFor();
    check(await creditRefundHistory.isVisible(), 'party-credit refund history shows its actual amount and linked Treasury movement');
    await screenshot(creditsPage, 'posted-party-credit-refund');
    await creditActor.context.close();

    const readonly = await login(browser, fixture.actors.readonly);
    await readonly.page.goto(new URL(`/treasury/statements/${statementId}`, baseUrl).toString());
    await waitForStatementLine(readonly.page, fixture.statement_external_id);
    check(!(await readonly.page.getByRole('button', { name: 'ربط الحركة الفعلية بسطر البنك', exact: true }).count())
      && !(await readonly.page.getByRole('button', { name: 'حفظ المراجعة المستقلة', exact: true }).count()),
    'read-only statement viewer sees real evidence without match or review mutation controls');
    await screenshot(readonly.page, 'read-only-statement-detail');
    await readonly.context.close();

    const denied = await login(browser, fixture.actors.denied);
    const deniedResponse = await denied.page.goto(new URL(`/treasury/statements/${statementId}`, baseUrl).toString());
    check(deniedResponse?.status() === 403, 'actor without statement-view authority is denied the real statement route');
    await screenshot(denied.page, 'statement-view-denial');
    await denied.context.close();
    await correctionActor.context.close();

    await viewports(browser, fixture.actors.corrector, fixture, statementId, correctionId);
    const report = { status: 'PASS', database: fixture.database, port: fixture.port, run_nonce: fixture.run_nonce,
      viewports: fixedViewports.map(([width, height]) => ({ width, height })), checks,
      screenshots: fs.readdirSync(output).filter(file => file.endsWith('.png')), completedAt: new Date().toISOString() };
    fs.writeFileSync(path.join(output, 'report.json'), JSON.stringify(report, null, 2));
    process.stdout.write(`PASS finance browser acceptance checks=${checks.length} artifacts=${output}\n`);
  } catch (error) {
    try { fs.writeFileSync(path.join(output, 'failure.json'), JSON.stringify({ message: String(error?.message || error), checks }, null, 2)); } catch { /* preserve primary error */ }
    if (activePage && !activePage.isClosed()) {
      try {
        await activePage.screenshot({ path: path.join(output, 'failure.png'), fullPage: true, animations: 'disabled' });
        fs.writeFileSync(path.join(output, 'failure.html'), await activePage.content());
      } catch { /* preserve primary error and any earlier artifacts */ }
    }
    throw error;
  } finally {
    try {
      if (browser) await browser.close().catch(() => {});
    } finally {
      if (server && server.exitCode === null) server.kill();
    }
  }
}

main().catch(error => { process.stderr.write(`${error.stack || error}\n`); process.exitCode = 1; });
