'use strict';

const fs = require('node:fs');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '../..');
const privateRoot = path.join(root, '.tmp/master-remediation-20261101/ui-private');
const fixturePath = path.join(privateRoot, 'gate2-ui-actors.json');
const bootstrapPath = path.join(privateRoot, 'gate2-ui-bootstrap.json');
const artifactRoot = path.join(privateRoot, 'artifacts');
let baseUrl;
const fixedViewports = [[1440, 900], [1366, 768], [768, 1024], [390, 844], [360, 800]];

function requireOwnedBaseUrl() {
  if (!fs.existsSync(bootstrapPath)) throw new Error('Private Gate 2 UI bootstrap receipt is missing; bootstrap the isolated database first.');
}

function helper(mode) {
  const result = spawnSync('php', [path.join(root, 'tests/Support/gate2-ui-actors.php'), ...(mode ? [`--${mode}`] : [])], {
    cwd: root, encoding: 'utf8', windowsHide: true, env: { ...process.env, APP_ENV: 'testing', DB_CONNECTION: 'pgsql', DB_URL: '', DB_HOST: '127.0.0.1',
      DB_PORT: '49686', DB_DATABASE: 'erp_elec_new_test', DB_USERNAME: 'postgres', DB_PASSWORD: '', E2E_DB_PORT: '49686', E2E_DB_DATABASE: 'erp_elec_new_test' },
  });
  if (result.status !== 0) throw new Error(`Private actor transition failed: ${result.stderr || result.stdout || `exit ${result.status}`}`);
}

function freePort() {
  const net = require('node:net');
  return new Promise((resolve, reject) => {
    const server = net.createServer();
    server.once('error', reject);
    server.listen(0, '127.0.0.1', () => { const port = server.address().port; server.close(() => resolve(port)); });
  });
}

async function waitForServer(server, url) {
  const deadline = Date.now() + 45_000;
  while (Date.now() < deadline) {
    if (server.exitCode !== null) throw new Error('Owned Gate 2 Laravel server exited before readiness.');
    try {
      const response = await fetch(`${url}/login`, { redirect: 'manual' });
      const body = await response.text();
      if (response.status === 200 && /name=["']email["']/.test(body)) return;
    } catch { /* server is still starting */ }
    await new Promise(resolve => setTimeout(resolve, 250));
  }
  throw new Error('Owned Gate 2 Laravel server did not become ready.');
}

function check(ok, message, checks) {
  checks.push({ result: ok ? 'PASS' : 'FAIL', message });
  if (!ok) throw new Error(`FAIL ${message}`);
}

function isLivewireUpdate(url) {
  return /^\/livewire(?:-[a-z0-9]+)?\/update$/.test(new URL(url).pathname);
}

function livewirePayload(request) {
  try { return request.postDataJSON(); } catch { return null; }
}

function livewireCalls(payload) {
  const components = Array.isArray(payload?.components) ? payload.components : [];
  return components.flatMap(component => Array.isArray(component?.calls) ? component.calls : []);
}

function hasLivewireCall(request, method, parameter) {
  const payload = livewirePayload(request);
  return livewireCalls(payload).some(call => (call.method || call.name) === method
    && (parameter === undefined || (Array.isArray(call.params) && call.params[0] === parameter)));
}

function summarizeRows(value, fields) {
  if (!Array.isArray(value)) return { count: null, rows: [] };
  return { count: value.length, rows: value.map(row => Object.fromEntries(fields.map(field => [field, row?.[field] ?? null]))) };
}

function safeLivewireFormState(request) {
  const payload = livewirePayload(request);
  const components = Array.isArray(payload?.components) ? payload.components : [];
  const states = components.map(component => {
    let snapshot = component?.snapshot;
    if (typeof snapshot === 'string') {
      try { snapshot = JSON.parse(snapshot); } catch { snapshot = null; }
    }
    const form = snapshot?.data?.form;
    const updates = component?.updates && typeof component.updates === 'object' ? component.updates : {};
    const updateForm = updates.form && typeof updates.form === 'object' ? updates.form : {};
    const safeUpdates = Object.fromEntries(Object.entries(updates).filter(([key]) =>
      /^form\.(?:value|coverage(?:\.|$)|preventive_schedule(?:\.|$)|schedule(?:\.|$))/.test(key)));
    return {
      component: typeof snapshot?.memo?.name === 'string' ? snapshot.memo.name : null,
      formPresent: Boolean(form && typeof form === 'object'),
      coverage: summarizeRows(form?.coverage ?? updateForm.coverage, ['equipment_ref', 'site_id', 'preventive_visits', 'corrective_visits']),
      preventive_schedule: summarizeRows(form?.preventive_schedule ?? updateForm.preventive_schedule, ['code', 'equipment_ref', 'site_id', 'due_date', 'description']),
      schedule: summarizeRows(form?.schedule ?? updateForm.schedule, ['code', 'due_date', 'amount', 'description']),
      value: form?.value ?? updateForm.value ?? null,
      safeUpdates,
      calls: livewireCalls({ components: [component] }).map(call => ({
        method: call.method || call.name || null,
        params: (call.method || call.name) === 'addRow' && Array.isArray(call.params) ? call.params.slice(0, 1) : [],
      })),
    };
  });
  return { capturedAt: new Date().toISOString(), components: states };
}

async function clickLivewireCall(page, button, method, parameter) {
  const responsePromise = page.waitForResponse(response => isLivewireUpdate(response.url())
    && hasLivewireCall(response.request(), method, parameter), { timeout: 15_000 });
  await button.click();
  const response = await responsePromise;
  if (!response.ok()) throw new Error(`Livewire ${method} returned HTTP ${response.status()}.`);
  return response;
}

async function capturePrivateDomFailure(page, output, name, details) {
  await save(page, output, `${name}.png`);
  fs.writeFileSync(path.join(output, `${name}.html`), await page.content());
  if (details) fs.writeFileSync(path.join(output, `${name}.json`), JSON.stringify(details, null, 2));
}

async function recordObservedContractState(page, diagnostics, output, step) {
  const counts = {
    coverage: await page.getByLabel('رقم المعدة أو مرجعها', { exact: true }).count(),
    preventive_schedule: await page.getByLabel('كود الموعد', { exact: true }).count(),
    schedule: await page.getByLabel('كود القسط', { exact: true }).count(),
  };
  const observed = { stage: `dom-after-${step}`, capturedAt: new Date().toISOString(), counts };
  if (counts.coverage) observed.coverage_first = {
    equipment_ref: await page.getByLabel('رقم المعدة أو مرجعها', { exact: true }).first().inputValue(),
    preventive_visits: await page.getByLabel('زيارات وقائية', { exact: true }).first().inputValue(),
    corrective_visits: await page.getByLabel('زيارات إصلاح', { exact: true }).first().inputValue(),
  };
  if (counts.preventive_schedule) observed.preventive_schedule_first = {
    code: await page.getByLabel('كود الموعد', { exact: true }).first().inputValue(),
    equipment_ref: await page.getByLabel('مرجع المعدة المغطاة', { exact: true }).first().inputValue(),
    due_date: await page.getByLabel('موعد الخدمة', { exact: true }).first().inputValue(),
  };
  diagnostics.push(observed);
  fs.writeFileSync(output, JSON.stringify(diagnostics, null, 2));
  return observed;
}

async function checkContractState(condition, message, checks, page, output, name, diagnostics) {
  if (!condition) {
    await capturePrivateDomFailure(page, output, name, { message, livewireFormState: diagnostics });
  }
  check(condition, message, checks);
}

async function waitForContractRow(page, locator, output, name, diagnostics) {
  try {
    await locator.waitFor({ state: 'visible', timeout: 15_000 });
  } catch (error) {
    await capturePrivateDomFailure(page, output, name, { message: error.message, livewireFormState: diagnostics });
    throw error;
  }
}

function cairoDate(date = new Date()) {
  const parts = new Intl.DateTimeFormat('en-GB', { timeZone: 'Africa/Cairo', year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(date);
  const value = Object.fromEntries(parts.map(part => [part.type, part.value]));
  return `${value.year}-${value.month}-${value.day}`;
}

async function waitForOption(page, label, value) {
  await page.getByLabel(label, { exact: true }).locator(`option[value="${value}"]`).waitFor({ state: 'attached' });
  await page.getByLabel(label, { exact: true }).selectOption(String(value));
}

async function selectOptionStartingWith(page, label, prefix) {
  const select = page.getByLabel(label, { exact: true });
  await select.locator('option').filter({ hasText: prefix }).first().waitFor({ state: 'attached' });
  const value = await select.locator('option').evaluateAll((options, expectedPrefix) => {
    const match = options.find(option => option.textContent.trim().startsWith(expectedPrefix));
    return match?.value || '';
  }, prefix);
  if (!value) throw new Error(`No ${label} option starts with ${prefix}.`);
  await select.selectOption(value);
}

async function login(browser, actor, output, checks) {
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'ar-EG', timezoneId: 'Africa/Cairo' });
  const page = await context.newPage();
  await page.goto(new URL('/login', baseUrl).toString());
  await page.locator('input[name="email"]').fill(actor.email);
  await page.locator('input[name="password"]').fill(actor.password);
  await Promise.all([page.waitForURL(url => !url.pathname.endsWith('/login')), page.locator('button[type="submit"]').click()]);
  check(!new URL(page.url()).pathname.endsWith('/login'), 'synthetic actor authenticated through the login UI', checks);
  await context.storageState({ path: path.join(output, `${actor.key}.storage.json`) });
  return { context, page };
}

async function save(page, output, name) {
  await page.screenshot({ path: path.join(output, `${name}.png`), fullPage: true, animations: 'disabled' });
}

async function run() {
  requireOwnedBaseUrl();
  if (!fs.existsSync(fixturePath)) helper();
  helper('ensure-dashboard-actor');
  helper('prepare-denial-actors');
  fs.mkdirSync(artifactRoot, { recursive: true });
  const runId = `${new Date().toISOString().replace(/[:.]/g, '-')}-${process.pid}`;
  const output = path.join(artifactRoot, runId);
  fs.mkdirSync(output, { recursive: true });
  const fixture = JSON.parse(fs.readFileSync(fixturePath, 'utf8'));
  const dashboardFixture = JSON.parse(fs.readFileSync(path.join(privateRoot, 'gate2-ui-dashboard-actor.json'), 'utf8'));
  if (dashboardFixture.ownership_nonce !== fixture.ownership_nonce || dashboardFixture.branch_id !== fixture.branch_id
      || dashboardFixture.role_code !== 'SalesClerk' || !dashboardFixture.actor?.email || !dashboardFixture.actor?.password) {
    throw new Error('Private dashboard SalesClerk actor receipt does not match the owned UI fixture.');
  }
  fixture.actors.dashboard = dashboardFixture.actor;
  const bootstrap = JSON.parse(fs.readFileSync(bootstrapPath, 'utf8'));
  const cluster = fs.realpathSync(path.join(root, '.tmp/master-remediation-20261101/ui-pgdata'));
  const privateRootResolved = fs.realpathSync(privateRoot);
  const marker = fs.readFileSync(path.join(privateRootResolved, '.codex-ui-owned.txt'), 'utf8').trim();
  if (fixture.database !== 'erp_elec_new_test' || fixture.port !== 49686 || !/^[a-f0-9]{64}$/.test(fixture.ownership_nonce || '')) {
    throw new Error('Private UI fixture receipt identity is invalid.');
  }
  if (bootstrap.database !== fixture.database || bootstrap.port !== fixture.port || bootstrap.nonce !== fixture.ownership_nonce
      || fs.realpathSync(bootstrap.data_directory) !== cluster || fs.realpathSync(fixture.data_directory) !== cluster
      || marker !== 'master-remediation-20261101 Gate2 UI private PostgreSQL marker'
      || !/^base64:[A-Za-z0-9+/=]+$/.test(bootstrap.app_key || '')) throw new Error('Private bootstrap, fixture and live cluster ownership receipts do not match.');
  const port = await freePort();
  baseUrl = `http://127.0.0.1:${port}`;
  const serverLog = fs.openSync(path.join(output, 'laravel-server.log'), 'w');
  const appEnv = { ...process.env, APP_ENV: 'testing', APP_DEBUG: 'false', APP_KEY: bootstrap.app_key, APP_URL: baseUrl,
    DB_CONNECTION: 'pgsql', DB_URL: '', DB_HOST: '127.0.0.1', DB_PORT: '49686', DB_DATABASE: 'erp_elec_new_test', DB_USERNAME: 'postgres', DB_PASSWORD: '',
    LARAVEL_STORAGE_PATH: path.join(privateRoot, 'storage'), APP_CONFIG_CACHE: '.tmp/master-remediation-20261101/ui-private/cache/config.php',
    APP_ROUTES_CACHE: '.tmp/master-remediation-20261101/ui-private/cache/routes.php', APP_EVENTS_CACHE: '.tmp/master-remediation-20261101/ui-private/cache/events.php',
    APP_SERVICES_CACHE: '.tmp/master-remediation-20261101/ui-private/cache/services.php', APP_PACKAGES_CACHE: '.tmp/master-remediation-20261101/ui-private/cache/packages.php',
    APP_VIEW_COMPILED_PATH: path.join(privateRoot, 'views'), VIEW_COMPILED_PATH: path.join(privateRoot, 'views'),
    CACHE_STORE: 'array', SESSION_DRIVER: 'file', QUEUE_CONNECTION: 'sync', MAIL_MAILER: 'array', BROADCAST_CONNECTION: 'null', DEBUGBAR_ENABLED: 'false' };
  const server = require('node:child_process').spawn('php', ['-S', `127.0.0.1:${port}`, '-t', '.', path.join(root, 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')], {
    cwd: path.join(root, 'public'), env: appEnv, stdio: ['ignore', serverLog, serverLog], windowsHide: true,
  });
  const checks = [];
  let browser;
  try {
    await waitForServer(server, baseUrl);
    browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_EXECUTABLE_PATH || 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe' });
    const managerActor = { key: 'manager', ...fixture.actors.manager };
    const manager = await login(browser, managerActor, output, checks);
    const page = manager.page;
    const dashboardTitles = ['الأداء المالي · الأستاذ', 'الفواتير المرحلة', 'البنك والنقدية', 'أوامر البيع', 'المشروعات', 'المناقصات', 'الصيانة الميدانية', 'الورش'];
    const dashboardUnavailable = 'البيانات تتطلب صلاحية الوحدة ونطاق فرع مسموحًا.';
    await page.goto(new URL('/dashboard', baseUrl).toString());
    const managerDashboard = page.locator('section[aria-label="لوحة المؤشرات التنفيذية"]');
    for (const title of dashboardTitles) await managerDashboard.getByRole('heading', { name: title, exact: true }).waitFor();
    check(await managerDashboard.getByText(dashboardUnavailable, { exact: true }).count() === 8
      && await managerDashboard.locator('section.erp-panel dl').count() === 0,
    'M01/M10/M15 manager sees all eight dashboard cards while ungranted financial and business metrics stay unavailable', checks);
    await save(page, output, 'dashboard-unavailable-1440x900');

    const dashboardActor = { key: 'dashboard', ...fixture.actors.dashboard };
    const dashboardReader = await login(browser, dashboardActor, output, checks);
    const dashboardPage = dashboardReader.page;
    await dashboardPage.goto(new URL('/dashboard', baseUrl).toString());
    const dashboardArea = dashboardPage.locator('section[aria-label="لوحة المؤشرات التنفيذية"]');
    for (const title of dashboardTitles) await dashboardArea.getByRole('heading', { name: title, exact: true }).waitFor();
    const dashboardBranchResponse = dashboardPage.waitForResponse(response => isLivewireUpdate(response.url()));
    await dashboardPage.getByLabel('نطاق مؤشرات الفرع', { exact: true }).selectOption(String(fixture.branch_id));
    await dashboardBranchResponse;
    const dashboardPanels = dashboardArea.locator('section.erp-panel');
    const dashboardSectionLists = await Promise.all(Array.from({ length: 8 }, (_, index) => dashboardPanels.nth(index + 1).locator('dl').count()));
    check(await dashboardPanels.count() === 9 && dashboardSectionLists.every(count => count === 1),
      'ordinary SalesClerk with scoped read grants can load all eight authorized dashboard sections', checks);
    const dashboardValues = await dashboardArea.locator('section.erp-panel dd').allTextContents();
    check(dashboardValues.length > 0 && dashboardValues.every(value => ['0', '0.00'].includes(value.trim())),
      'new scoped branch shows measured zero counts and balances without seeded revenue, invoices, or cash movements', checks);
    const receivableCard = dashboardPanels.nth(2);
    check(await receivableCard.getByText('فواتير العملاء EGP', { exact: true }).count() === 1
      && await receivableCard.getByText('فواتير الموردين EGP', { exact: true }).count() === 0,
    'AR-only dashboard authority displays customer invoices and omits the unauthorized supplier amount', checks);
    check(await dashboardPanels.nth(1)
      .getByText('الإيراد EGP', { exact: true }).count() === 1
      && await dashboardPanels.nth(3)
        .getByText('النقدية EGP', { exact: true }).count() === 1,
    'dashboard keeps posted-ledger revenue and treasury cash as separate measures', checks);
    await save(dashboardPage, output, 'dashboard-scoped-1440x900');
    for (const [width, height] of fixedViewports) {
      await dashboardPage.setViewportSize({ width, height });
      await dashboardPage.goto(new URL('/dashboard', baseUrl).toString());
      for (const title of dashboardTitles) await dashboardArea.getByRole('heading', { name: title, exact: true }).waitFor();
      await save(dashboardPage, output, `dashboard-${width}x${height}`);
      check(await dashboardPage.locator('html').getAttribute('dir').then(dir => dir === 'rtl')
        && await dashboardPage.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth),
      `${width}x${height} dashboard keeps RTL and avoids horizontal page overflow`, checks);
    }
    await dashboardReader.context.close();

    const suffix = runId.replace(/[^a-zA-Z0-9]/g, '').slice(-12).toUpperCase();
    const employeeCode = `DEMO-EG-G2UI-${suffix}`;
    const employeeName = `موظف اختبار Gate 2 ${suffix}`;

    await page.goto(new URL('/organization/employees', baseUrl).toString());
    await page.getByRole('heading', { name: 'الموظفون' }).waitFor();
    check(await page.getByRole('heading', { name: 'الموظفون' }).isVisible(), 'M01 employee list rendered for a scoped manager', checks);
    await page.getByLabel('الفرع', { exact: true }).first().selectOption(String(fixture.branch_id));
    await page.getByLabel('البحث عن موظف', { exact: true }).fill(`NO-MATCH-${suffix}`);
    await page.getByText('لا يوجد موظفون مطابقون').waitFor();
    check(await page.getByText('لا يوجد موظفون مطابقون').isVisible(), 'unique scoped employee search shows the truthful empty state', checks);
    await save(page, output, 'employee-empty-1440x900');
    await page.getByLabel('البحث عن موظف', { exact: true }).fill('');
    await page.getByRole('button', { name: 'إضافة موظف' }).click();
    await page.getByLabel('الفرع', { exact: true }).last().selectOption(String(fixture.branch_id));
    await page.getByLabel('كود الموظف', { exact: true }).fill(employeeCode);
    await page.getByLabel('اسم الموظف', { exact: true }).fill(employeeName);
    await page.getByLabel('المسمى الوظيفي', { exact: true }).fill('فني اختبار واجهات');
    const today = cairoDate();
    const employmentStart = new Date(`${today}T12:00:00+02:00`);
    employmentStart.setDate(employmentStart.getDate() - 30);
    await page.getByLabel('بداية التوظيف', { exact: true }).fill(cairoDate(employmentStart));
    await page.getByRole('button', { name: 'حفظ', exact: true }).click();
    await page.getByText(employeeCode).waitFor();
    await page.reload();
    await page.getByText(employeeName, { exact: true }).waitFor();
    check(await page.getByText(employeeName).isVisible(), 'M01 employee create persisted after full page reload', checks);

    let releaseLivewire;
    let interceptedLivewire;
    const livewireGate = new Promise(resolve => { releaseLivewire = resolve; });
    const livewireIntercepted = new Promise(resolve => { interceptedLivewire = resolve; });
    const livewireResponse = page.waitForResponse(response => isLivewireUpdate(response.url()));
    await page.route('**/livewire*/update', async route => {
      if (!isLivewireUpdate(route.request().url())) return route.continue();
      interceptedLivewire();
      await livewireGate;
      await route.continue();
    });
    await page.getByLabel('البحث عن موظف', { exact: true }).fill(employeeCode);
    let loadingSeen;
    let interceptTimeout;
    try {
      await Promise.race([livewireIntercepted, new Promise((_, reject) => {
        interceptTimeout = setTimeout(() => reject(new Error('Timed out waiting for the Livewire update request.')), 15_000);
      })]);
      loadingSeen = await page.locator('body').evaluate(body => [...body.querySelectorAll('*')].some(element =>
        [...element.attributes].some(attribute => attribute.name === 'wire:loading.class') && element.classList.contains('opacity-60')));
    } finally {
      clearTimeout(interceptTimeout);
      releaseLivewire();
    }
    await livewireResponse;
    await page.getByText(employeeCode).waitFor();
    check(loadingSeen, 'employee search exposes its loading state while Livewire is working', checks);
    await page.unroute('**/livewire*/update');

    const attendancePath = new URL('/attendance', baseUrl).toString();
    await page.goto(attendancePath);
    await page.getByLabel('الفرع', { exact: true }).selectOption(String(fixture.branch_id));
    await page.getByLabel('البحث عن الموظف', { exact: true }).fill(employeeCode);
    await selectOptionStartingWith(page, 'الموظف', employeeCode);
    const scheduledDate = new Date(`${today}T12:00:00+02:00`);
    scheduledDate.setDate(scheduledDate.getDate() - 1);
    const workDate = cairoDate(scheduledDate);
    const month = workDate.slice(0, 7);
    await page.getByLabel('شهر بداية الوردية', { exact: true }).fill(month);
    await page.getByText('لا توجد ورديات لهذا الشهر').waitFor();
    check(await page.getByText('لا توجد ورديات لهذا الشهر').isVisible(), 'M15 empty schedule state is explicit for a scoped employee/month', checks);
    await save(page, output, 'attendance-empty-1440x900');
    await page.getByLabel('كود الوردية', { exact: true }).fill(`G2UI-${suffix}`);
    await page.getByLabel('اسم الوردية', { exact: true }).fill(`وردية اختبار ${suffix}`);
    await page.getByLabel('البداية', { exact: true }).fill('08:00');
    await page.getByLabel('النهاية', { exact: true }).fill('16:00');
    await page.getByLabel('الراحة المخططة بالدقائق', { exact: true }).fill('30');
    await page.getByLabel('السماح بالتأخير بالدقائق', { exact: true }).fill('5');
    await page.getByRole('button', { name: 'إضافة الوردية' }).click();
    await selectOptionStartingWith(page, 'الوردية', `وردية اختبار ${suffix}`);
    await page.getByLabel('تاريخ بداية الوردية', { exact: true }).fill(workDate);
    await page.getByRole('button', { name: 'حفظ الجدولة' }).click();
    await page.getByText('غياب').waitFor();
    await page.getByLabel('الوقت الفعلي بتوقيت القاهرة', { exact: true }).fill(`${workDate}T09:00`);
    await page.getByLabel('نوع الواقعة', { exact: true }).selectOption('in');
    await page.getByRole('button', { name: 'تسجيل الواقعة' }).click();
    await page.reload();
    await page.getByLabel('الفرع', { exact: true }).selectOption(String(fixture.branch_id));
    await page.getByLabel('البحث عن الموظف', { exact: true }).fill(employeeCode);
    await selectOptionStartingWith(page, 'الموظف', employeeCode);
    await page.getByLabel('شهر بداية الوردية', { exact: true }).fill(month);
    await page.locator('[data-flux-heading]').filter({ hasText: employeeName }).waitFor();
    await page.getByText(/09:00/).waitFor();
    check(await page.getByText(/09:00/).isVisible(), 'M15 scheduled shift and actual attendance punch persist after reload', checks);

    const contractRequestDiagnostics = [];
    const contractDiagnosticsPath = path.join(output, 'contract-livewire-form-state.json');
    await page.route('**/livewire*/update', async route => {
      if (!isLivewireUpdate(route.request().url())) return route.continue();
      const diagnostic = safeLivewireFormState(route.request());
      contractRequestDiagnostics.push(diagnostic);
      fs.writeFileSync(contractDiagnosticsPath, JSON.stringify(contractRequestDiagnostics, null, 2));
      await route.continue();
    });
    await page.goto(new URL('/service-contracts/create', baseUrl).toString());
    await page.getByRole('heading', { name: 'عقد خدمة جديد' }).waitFor();
    check(await page.getByRole('heading', { name: 'عقد خدمة جديد' }).isVisible(), 'M10 create form rendered with current branch authority', checks);
    await page.getByLabel('الفرع', { exact: true }).selectOption(String(fixture.branch_id));
    await page.getByLabel('البحث عن عميل', { exact: true }).fill(fixture.customer_code);
    await waitForOption(page, 'العميل', fixture.customer_id);
    await page.getByLabel('البحث عن المسؤول', { exact: true }).fill(employeeCode);
    await selectOptionStartingWith(page, 'الموظف المسؤول', employeeCode);
    const contractTitle = `عقد اختبار واجهات ${suffix}`;
    await page.getByLabel('عنوان العقد', { exact: true }).fill(contractTitle);
    await page.getByLabel('بداية التغطية', { exact: true }).fill(today);
    const endDate = new Date(`${today}T12:00:00+02:00`);
    endDate.setDate(endDate.getDate() + 60);
    const endsOn = cairoDate(endDate);
    const dueDate = new Date(`${today}T12:00:00+02:00`);
    dueDate.setDate(dueDate.getDate() + 30);
    const dueOn = cairoDate(dueDate);
    await page.getByLabel('نهاية التغطية', { exact: true }).fill(endsOn);
    await page.getByLabel('قيمة العقد EGP', { exact: true }).fill('100.00');
    await page.getByLabel('البحث عن أصناف الخدمة والقطع', { exact: true }).fill(fixture.service_product_sku);
    await waitForOption(page, 'صنف الخدمة', fixture.service_product_id);
    await page.getByLabel('طريقة الفوترة', { exact: true }).selectOption('installments');
    await page.getByLabel('أساس أسعار العقد للضريبة', { exact: true }).selectOption('exclusive');
    await page.getByLabel('الاستجابة خلال دقائق', { exact: true }).fill('30');
    await page.getByLabel('المعالجة خلال دقائق', { exact: true }).fill('60');
    await page.getByLabel('وقت العمل المغطى بالدقائق', { exact: true }).fill('0');
    await page.getByLabel('شروط الخدمة والتغطية', { exact: true }).fill('شروط تجريبية للقبول المحلي فقط.');
    await clickLivewireCall(page, page.getByRole('button', { name: 'إضافة معدة' }), 'addRow', 'coverage');
    await waitForContractRow(page, page.getByLabel('رقم المعدة أو مرجعها', { exact: true }), output, 'coverage-row-missing-after-add', contractRequestDiagnostics);
    const afterCoverageAdd = await recordObservedContractState(page, contractRequestDiagnostics, contractDiagnosticsPath, 'addRow-coverage');
    await checkContractState(afterCoverageAdd.counts.coverage === 1 && afterCoverageAdd.counts.preventive_schedule === 0 && afterCoverageAdd.counts.schedule === 0,
      'M10 coverage row is present after the server confirms addRow(coverage)', checks, page, output, 'coverage-row-count-after-add', contractRequestDiagnostics);
    const coveredEquipment = `DEMO-EG-EQUIP-${suffix}`;
    await page.getByLabel('رقم المعدة أو مرجعها', { exact: true }).fill(coveredEquipment);
    await page.getByLabel('زيارات وقائية', { exact: true }).fill('1');
    await page.getByLabel('زيارات إصلاح', { exact: true }).fill('1');
    await checkContractState(await page.getByLabel('رقم المعدة أو مرجعها', { exact: true }).inputValue() === coveredEquipment
      && await page.getByLabel('زيارات وقائية', { exact: true }).inputValue() === '1'
      && await page.getByLabel('زيارات إصلاح', { exact: true }).inputValue() === '1',
    'M10 coverage fields contain the synthetic equipment and visit limits before the next server action', checks, page, output, 'coverage-values-before-preventive-add', contractRequestDiagnostics);
    const preventiveCode = `PV-${suffix}`;
    await clickLivewireCall(page, page.getByRole('button', { name: 'إضافة موعد وقائي' }), 'addRow', 'preventive_schedule');
    await waitForContractRow(page, page.getByLabel('كود الموعد', { exact: true }), output, 'preventive-row-missing-after-add', contractRequestDiagnostics);
    const afterPreventiveAdd = await recordObservedContractState(page, contractRequestDiagnostics, contractDiagnosticsPath, 'addRow-preventive_schedule');
    await checkContractState(afterPreventiveAdd.counts.coverage === 1 && afterPreventiveAdd.counts.preventive_schedule === 1
      && afterPreventiveAdd.counts.schedule === 0
      && await page.getByLabel('رقم المعدة أو مرجعها', { exact: true }).inputValue() === coveredEquipment
      && await page.getByLabel('زيارات وقائية', { exact: true }).inputValue() === '1',
    'M10 preventive-row response preserves the previously filled coverage row', checks, page, output, 'coverage-lost-after-preventive-add', contractRequestDiagnostics);
    await checkContractState(await page.getByLabel('كود الموعد', { exact: true }).count() === 1,
      'M10 preventive row exists after the server confirms addRow(preventive_schedule)', checks, page, output, 'preventive-row-count-after-add', contractRequestDiagnostics);
    await page.getByLabel('كود الموعد', { exact: true }).fill(preventiveCode);
    await page.getByLabel('مرجع المعدة المغطاة', { exact: true }).fill(coveredEquipment);
    await page.getByLabel('موعد الخدمة', { exact: true }).fill(dueOn);
    await page.getByLabel('الأعمال المخططة', { exact: true }).fill('فحص وقائي للوحة التوزيع.');
    await clickLivewireCall(page, page.getByRole('button', { name: 'إضافة قسط' }), 'addRow', 'schedule');
    await waitForContractRow(page, page.getByLabel('كود القسط', { exact: true }), output, 'installment-row-missing-after-add', contractRequestDiagnostics);
    const afterInstallmentAdd = await recordObservedContractState(page, contractRequestDiagnostics, contractDiagnosticsPath, 'addRow-schedule');
    await checkContractState(afterInstallmentAdd.counts.coverage === 1 && afterInstallmentAdd.counts.preventive_schedule === 1
      && afterInstallmentAdd.counts.schedule === 1
      && await page.getByLabel('رقم المعدة أو مرجعها', { exact: true }).inputValue() === coveredEquipment
      && await page.getByLabel('كود الموعد', { exact: true }).inputValue() === preventiveCode
      && await page.getByLabel('مرجع المعدة المغطاة', { exact: true }).inputValue() === coveredEquipment,
    'M10 installment-row response preserves both coverage and preventive schedule values', checks, page, output, 'coverage-or-preventive-values-lost-after-installment-add', contractRequestDiagnostics);
    await checkContractState(await page.getByLabel('كود القسط', { exact: true }).count() === 1,
      'M10 installment row exists after the server confirms addRow(schedule)', checks, page, output, 'installment-row-count-after-add', contractRequestDiagnostics);
    await page.getByLabel('كود القسط', { exact: true }).fill(`DEMO-EG-INST-${suffix}`);
    await page.getByLabel('تاريخ الاستحقاق', { exact: true }).fill(dueOn);
    await page.getByLabel('القيمة EGP', { exact: true }).last().fill('99.00');
    await page.getByLabel('البيان', { exact: true }).fill('قسط تجريبي');
    await clickLivewireCall(page, page.getByRole('button', { name: 'حفظ المسودة' }), 'save');
    try {
      await page.getByRole('alert').waitFor({ timeout: 15_000 });
    } catch (error) {
      await capturePrivateDomFailure(page, output, 'contract-validation-alert-missing', {
        expected: 'role=alert with schedule/value mismatch', livewireFormState: contractRequestDiagnostics,
      });
      throw error;
    }
    const scheduleMismatch = 'يجب أن تطابق قيمة العقد مجموع الجدول المعتمد.';
    const invalidAmountAlert = await page.getByRole('alert').innerText();
    if (!invalidAmountAlert.includes(scheduleMismatch)) {
      await capturePrivateDomFailure(page, output, 'contract-validation-unexpected-error', {
        expected: scheduleMismatch, actual: invalidAmountAlert, livewireFormState: contractRequestDiagnostics,
      });
      throw new Error(`M10 invalid-total validation did not report the schedule/value mismatch. Actual alert: ${invalidAmountAlert}`);
    }
    check(invalidAmountAlert.includes(scheduleMismatch), 'M10 invalid installment total reports the specific contract-value/schedule mismatch', checks);
    await save(page, output, 'contract-validation-1440x900');
    await page.getByLabel('القيمة EGP', { exact: true }).last().fill('100.00');
    await clickLivewireCall(page, page.getByRole('button', { name: 'حفظ المسودة' }), 'save');
    try {
      await page.getByRole('heading', { name: contractTitle, exact: true }).waitFor({ timeout: 15_000 });
    } catch (error) {
      const actualAlert = await page.getByRole('alert').count() ? await page.getByRole('alert').innerText() : null;
      await capturePrivateDomFailure(page, output, 'contract-save-unexpected-state', {
        expectedHeading: contractTitle, actualAlert, livewireFormState: contractRequestDiagnostics,
      });
      throw new Error(`M10 corrected-total save did not render the contract heading. Alert: ${actualAlert || 'none'}. ${error.message}`);
    }
    await page.getByText('مسودة').first().waitFor();
    check(await page.getByText('مسودة').first().isVisible(), 'M10 actual business action created a draft contract', checks);
    await page.reload();
    check(await page.getByLabel('أساس أسعار العقد للضريبة', { exact: true }).inputValue() === 'exclusive'
      && await page.getByLabel('كود الموعد', { exact: true }).inputValue() === preventiveCode,
    'M10 draft reload preserves explicit pricing tax basis and preventive schedule inputs', checks);
    const draftContractUrl = page.url();
    check(!(await page.getByRole('button', { name: 'الفوترة والأعمال المقاسة والاعتراف بالإيراد' }).count()),
      'M10 draft does not expose the billing page link before activation', checks);
    const contractId = new URL(draftContractUrl).pathname.split('/').pop();
    const billingStatus = await page.evaluate(async (contract) => (await fetch(`/service-contracts/${contract}/billing`, {
      headers: { Accept: 'text/html' }, credentials: 'same-origin',
    })).status, contractId);
    check(billingStatus === 409, 'M10 billing page rejects a draft contract that has no active signed revision', checks);
    await page.getByRole('button', { name: 'تقديم للاعتماد' }).click();
    await page.getByText('قيد الاعتماد').waitFor();
    await page.reload();
    await page.getByText('قيد الاعتماد').waitFor();
    check(await page.getByText('قيد الاعتماد').isVisible(), 'M10 draft submission persisted after reload', checks);
    const contractUrl = page.url();

    for (const [width, height] of fixedViewports) {
      await page.setViewportSize({ width, height });
      await page.goto(new URL('/organization/employees', baseUrl).toString());
      await page.getByText(employeeCode).waitFor();
      await save(page, output, `employee-${width}x${height}`);
      check(await page.locator('html').getAttribute('dir').then(dir => dir === 'rtl')
        && await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth),
      `${width}x${height} employee list keeps RTL without horizontal page overflow`, checks);
      await page.goto(attendancePath);
      await page.getByLabel('الفرع', { exact: true }).selectOption(String(fixture.branch_id));
      await page.getByLabel('البحث عن الموظف', { exact: true }).fill(employeeCode);
      await selectOptionStartingWith(page, 'الموظف', employeeCode);
      await page.getByLabel('شهر بداية الوردية', { exact: true }).fill(month);
      await page.locator('[data-flux-heading]').filter({ hasText: employeeName }).waitFor();
      await save(page, output, `attendance-${width}x${height}`);
      check(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth),
        `${width}x${height} attendance report has no horizontal page overflow`, checks);
      await page.goto(contractUrl);
      await page.getByText('قيد الاعتماد').waitFor();
      await save(page, output, `contract-${width}x${height}`);
      check(await page.locator('html').getAttribute('dir').then(dir => dir === 'rtl')
        && await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth),
      `${width}x${height} contract page keeps RTL without horizontal page overflow`, checks);
    }

    const readerActor = { key: 'reader', ...fixture.actors.reader };
    const reader = await login(browser, readerActor, output, checks);
    await reader.page.goto(new URL('/organization/employees', baseUrl).toString());
    await reader.page.getByText(employeeCode).waitFor();
    check(!(await reader.page.getByRole('button', { name: 'إضافة موظف' }).count()), 'read-only M01 actor sees records without employee mutation controls', checks);
    await save(reader.page, output, 'employee-read-only');
    await reader.page.goto(attendancePath);
    check(!(await reader.page.getByRole('button', { name: 'إضافة الوردية' }).count()) && !(await reader.page.getByRole('button', { name: 'تسجيل الواقعة' }).count()), 'read-only M15 actor cannot see shift or punch controls', checks);
    await reader.page.goto(new URL('/service-contracts', baseUrl).toString());
    await reader.page.getByText(contractTitle).waitFor();
    await reader.page.goto(contractUrl);
    await reader.page.getByText('قيد الاعتماد').waitFor();
    check(!(await reader.page.getByRole('button', { name: 'تقديم للاعتماد' }).count()), 'read-only M10 actor can view a contract without workflow mutation controls', checks);

    const revoked = await login(browser, { key: 'revoked', ...fixture.actors.revoked }, output, checks);
    helper('revoke');
    const revokedResponse = await revoked.page.goto(new URL('/organization/employees', baseUrl).toString());
    check(revokedResponse?.status() === 403, 'revoked employee-view grant denies the already authenticated session', checks);
    await save(revoked.page, output, 'denied-revoked');

    const disabled = await login(browser, { key: 'disabled', ...fixture.actors.disabled }, output, checks);
    helper('disable');
    const disabledResponse = await disabled.page.goto(new URL('/organization/employees', baseUrl).toString());
    check(disabledResponse?.status() === 403 || new URL(disabled.page.url()).pathname.endsWith('/login'), 'disabled account loses access and its existing session is invalidated', checks);
    await save(disabled.page, output, 'denied-disabled');

    const report = { status: 'PASS', database: fixture.database, port: fixture.port, viewports: fixedViewports.map(([width, height]) => ({ width, height })),
      checks, screenshots: fs.readdirSync(output).filter(file => file.endsWith('.png')), completedAt: new Date().toISOString() };
    fs.writeFileSync(path.join(output, 'receipt.json'), JSON.stringify(report, null, 2));
    process.stdout.write(`PASS Gate2 UI acceptance (${checks.length} assertions); private evidence: ${output}\n`);
  } catch (error) {
    fs.writeFileSync(path.join(output, 'failure.txt'), error.stack || String(error));
    throw error;
  } finally {
    if (browser) await browser.close();
    if (server.exitCode === null) {
      server.kill();
      await new Promise(resolve => { const timer = setTimeout(resolve, 1500); server.once('exit', () => { clearTimeout(timer); resolve(); }); });
    }
    try { fs.closeSync(serverLog); } catch {}
  }
}

run().catch(error => { process.stderr.write(`${error.stack || error}\n`); process.exitCode = 1; });
