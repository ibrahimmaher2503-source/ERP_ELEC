// M09 browser-to-ledger acceptance on the private Gate 2 PG17 finance UI database only.
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const net = require('node:net');
const { spawn, spawnSync } = require('node:child_process');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '..', '..');
const privateRoot = path.join(root, '.tmp', 'master-remediation-20261101', 'ui-private');
const financeRoot = path.join(privateRoot, 'finance');
const bootstrapPath = path.join(financeRoot, 'gate2-finance-ui-bootstrap.json');
const basePath = path.join(financeRoot, 'gate2-finance-ui-fixture.json');
const markerPath = path.join(privateRoot, '.codex-ui-owned.txt');
const marker = 'master-remediation-20261101 Gate2 UI private PostgreSQL marker';
const runNonce = crypto.randomBytes(16).toString('hex');
const failures = [];
const assertions = [];
const screenshots = [];
let server = null;
let browser = null;
let serverLog = '';
let baseUrl = '';
let lastPhpDiagnostic = '';
let activePage = null;
let activeStage = 'startup';

function check(ok, description) {
  assertions.push({ ok: Boolean(ok), description });
  if (!ok) throw new Error(`M09 acceptance failed: ${description}`);
}

function readJson(file) { return JSON.parse(fs.readFileSync(file, 'utf8')); }

function privateFile(file) {
  const resolved = fs.realpathSync(file);
  const base = fs.realpathSync(financeRoot) + path.sep;
  if (!resolved.startsWith(base)) throw new Error('M09 private receipt or log path escapes the private finance runtime.');
  return resolved;
}

function safeEnv(receipt) {
  if (fs.readFileSync(markerPath, 'utf8').trim() !== marker) throw new Error('Private Gate 2 marker does not match.');
  const cluster = fs.realpathSync(path.join(root, '.tmp', 'master-remediation-20261101', 'ui-pgdata'));
  const dbNonce = process.env.E2E_FINANCE_DB_NONCE || receipt.ownership_nonce;
  if (!/^[a-f0-9]{64}$/.test(dbNonce) || receipt.ownership_nonce !== dbNonce
      || receipt.database !== `erp_elec_new_finance_ui_${dbNonce.slice(0, 32)}` || receipt.port !== 49686
      || fs.realpathSync(receipt.data_directory) !== cluster) throw new Error('Private database receipt does not bind the owned cluster, database and nonce.');
  privateFile(bootstrapPath);
  const pid = fs.readFileSync(path.join(cluster, 'postmaster.pid'), 'utf8').split(/\r?\n/);
  if (fs.realpathSync(pid[1]) !== cluster || Number(pid[3]) !== 49686) throw new Error('Live PostgreSQL PID file is not the expected PGDATA/port.');
  return {
    ...process.env, APP_ENV: 'testing', APP_KEY: receipt.app_key, DB_CONNECTION: 'pgsql', DB_URL: '', DB_HOST: '127.0.0.1', DB_PORT: '49686',
    DB_DATABASE: receipt.database, DB_USERNAME: 'postgres', DB_PASSWORD: '', E2E_DB_PORT: '49686', E2E_FINANCE_DB_NONCE: dbNonce,
    APP_CONFIG_CACHE: '.tmp/master-remediation-20261101/ui-private/finance/cache/config.php',
    APP_ROUTES_CACHE: '.tmp/master-remediation-20261101/ui-private/finance/cache/routes.php',
    APP_EVENTS_CACHE: '.tmp/master-remediation-20261101/ui-private/finance/cache/events.php',
    APP_SERVICES_CACHE: '.tmp/master-remediation-20261101/ui-private/finance/cache/services.php',
    APP_PACKAGES_CACHE: '.tmp/master-remediation-20261101/ui-private/finance/cache/packages.php',
    APP_VIEW_COMPILED_PATH: path.join(financeRoot, 'views'), VIEW_COMPILED_PATH: path.join(financeRoot, 'views'),
    LARAVEL_STORAGE_PATH: path.join(financeRoot, 'storage'), CACHE_STORE: 'array', SESSION_DRIVER: 'file', QUEUE_CONNECTION: 'sync',
    MAIL_MAILER: 'array', BROADCAST_CONNECTION: 'null', DEBUGBAR_ENABLED: 'false',
  };
}

function runPhp(file, args, env) {
  const laravelLog = path.join(financeRoot, 'storage', 'logs', 'laravel.log');
  const before = fs.existsSync(laravelLog) ? fs.statSync(laravelLog).size : 0;
  const result = spawnSync(process.env.PHP_BINARY || 'php', ['-d', 'opcache.enable_cli=0', file, ...args], { cwd: root, env, encoding: 'utf8', windowsHide: true });
  const phpLogTail = fs.existsSync(laravelLog) ? fs.readFileSync(laravelLog, 'utf8').slice(before) : '';
  const redacted = `${result.stdout || ''}\n--- STDERR ---\n${result.stderr || ''}\n--- LARAVEL APP LOG DELTA ---\n${phpLogTail}`
    .replaceAll(env.APP_KEY, '[private app key]').replaceAll(env.DB_DATABASE, '[private database]')
    .replaceAll(env.E2E_FINANCE_DB_NONCE, '[private database nonce]');
  const stepId = crypto.createHash('sha256').update(`${file}\0${args.join('\0')}`).digest('hex').slice(0, 12);
  const stepLogPath = path.join(financeRoot, `m09-php-${runNonce}-${path.basename(file, '.php')}-${stepId}.log`);
  fs.writeFileSync(stepLogPath, redacted, { mode: 0o600 });
  privateFile(stepLogPath);
  const causes = redacted.split(/\r?\n/).filter(line => /testing\.(?:ERROR|CRITICAL):|SQLSTATE\[|RuntimeException:|ValidationException:/.test(line));
  lastPhpDiagnostic = causes.at(-1)?.replace(/^.*?testing\.(?:ERROR|CRITICAL):\s*/, '').split(' {"exception"')[0].slice(0, 420)
    || (result.status === 0 ? 'helper completed without emitting its READY marker' : `PHP helper exit status ${result.status ?? 'unknown'}`);
  if (result.error || result.status !== 0) throw new Error(`${path.basename(file)} failed; private diagnostic ${path.basename(stepLogPath)}; cause: ${lastPhpDiagnostic}`);
  return String(result.stdout || '');
}

function requireReady(output, step) {
  if (!output.includes('READY')) throw new Error(`${step} returned no READY marker; cause: ${lastPhpDiagnostic}`);
}

function stage(name, page = activePage) {
  activeStage = name;
  activePage = page;
}

function localTime(hoursOffset = 0) {
  const date = new Date(Date.now() + hoursOffset * 3600000);
  const parts = new Intl.DateTimeFormat('en-CA', { timeZone: 'Africa/Cairo', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).formatToParts(date);
  const values = Object.fromEntries(parts.map(part => [part.type, part.value]));
  return `${values.year}-${values.month}-${values.day}T${values.hour}:${values.minute}`;
}

async function freePort() {
  return new Promise((resolve, reject) => {
    const probe = net.createServer();
    probe.once('error', reject);
    probe.listen(0, '127.0.0.1', () => { const port = probe.address().port; probe.close(error => error ? reject(error) : resolve(port)); });
  });
}

async function login(actor) {
  const context = await browser.newContext({ locale: 'ar-EG', timezoneId: 'Africa/Cairo', viewport: { width: 1440, height: 1000 }, acceptDownloads: true });
  const page = await context.newPage();
  stage(`login:${actor.key || actor.name}`, page);
  page.on('pageerror', error => failures.push(`pageerror: ${error.message}`));
  page.on('dialog', dialog => dialog.accept());
  await page.goto(`${baseUrl}/login`);
  await page.getByLabel(/البريد الإلكتروني|البريد/).fill(actor.email);
  await page.getByLabel(/كلمة المرور/).fill(actor.password);
  await Promise.all([page.waitForURL(url => !url.pathname.endsWith('/login')), page.locator('button[type="submit"]').click()]);
  check(!new URL(page.url()).pathname.endsWith('/login'), `private synthetic ${actor.name} actor authenticated through the real login form`);
  return { page, context };
}

async function clickAction(page, locator) {
  const pending = page.waitForResponse(response => /\/livewire-[^/]+\/update(?:\?|$)/.test(response.url()) && response.request().method() === 'POST', { timeout: 30000 });
  await locator.click();
  const response = await pending;
  if (!response.ok()) throw new Error(`Livewire action failed with HTTP ${response.status()} at ${new URL(response.url()).pathname}.`);
  await page.waitForTimeout(300);
}

async function selectByLabel(page, label, value) { await page.getByLabel(label, { exact: true }).selectOption(String(value)); }
async function fillByLabel(page, label, value) { await page.getByLabel(label, { exact: true }).fill(String(value)); }
async function selectFirstOption(page, label) {
  const select = page.getByLabel(label, { exact: true });
  await select.waitFor({ state: 'visible' });
  const option = select.locator('option').nth(1);
  await option.waitFor({ state: 'attached' });
  const value = await option.getAttribute('value');
  if (!value) throw new Error(`No selectable option is available for ${label}.`);
  await select.selectOption(value);
}
async function goJob(page, jobId, tab = 'details') { stage(`job:${tab}`, page); await page.goto(`${baseUrl}/workshops/jobs/${jobId}?tab=${tab}`); await page.getByRole('heading', { name: /أمر إصلاح/ }).waitFor(); }

async function captureStage(page, stage) {
  activeStage = stage;
  activePage = page;
  const original = page.viewportSize();
  for (const [size, viewport] of [['desktop', { width: 1440, height: 1000 }], ['mobile-390', { width: 390, height: 844 }]]) {
    await page.setViewportSize(viewport);
    await page.waitForTimeout(150);
    const width = await page.evaluate(() => ({ viewport: window.innerWidth, document: document.documentElement.scrollWidth }));
    check(width.document <= width.viewport + 1, `${stage} ${size} has no horizontal page overflow (${width.document}/${width.viewport})`);
    const file = path.join(financeRoot, `m09-${runNonce}-${stage}-${size}.png`);
    await page.screenshot({ path: file, fullPage: true });
    privateFile(file);
    screenshots.push({ stage, viewport: size, sha256: crypto.createHash('sha256').update(fs.readFileSync(file)).digest('hex') });
  }
  await page.setViewportSize(original || { width: 1440, height: 1000 });
}

async function main() {
  const bootstrap = readJson(bootstrapPath);
  const env = safeEnv(bootstrap);
  if (bootstrap.port !== 49686) throw new Error('Finance UI bootstrap receipt does not use the exact owned port.');
  const setupOutput = runPhp('tests/Support/gate2-finance-ui-fixtures.php', ['--setup'], env);
  requireReady(setupOutput, 'Guarded finance base fixture setup');
  privateFile(basePath);
  const base = readJson(basePath);
  if (base.database !== bootstrap.database || base.ownership_nonce !== bootstrap.ownership_nonce) throw new Error('Existing setup receipt is not bound to the exact finance target.');
  const financeRunOutput = runPhp('tests/Support/gate2-finance-ui-fixtures.php', [`--prepare-run=${runNonce}`], env);
  requireReady(financeRunOutput, 'Nonce-scoped finance fixture preparation');
  const workshopOutput = runPhp('tests/Support/gate2-workshop-ui-fixtures.php', [`--prepare=${runNonce}`], env);
  requireReady(workshopOutput, 'Nonce-scoped M09 actors/workshop preparation');
  const fixturePath = path.join(financeRoot, `gate2-workshop-ui-run-${runNonce}.json`);
  privateFile(fixturePath);
  const fixture = readJson(fixturePath);
  if (fixture.state !== 'PREPARED' || fixture.database !== bootstrap.database || fixture.ownership_nonce !== bootstrap.ownership_nonce
      || fixture.port !== 49686 || fixture.run_nonce !== runNonce || !fixture.actors?.technician?.password) throw new Error('M09 run receipt does not match this isolated target.');

  const port = await freePort();
  const router = path.join(root, 'tests', 'Support', 'gate2-finance-ui-router.php');
  const logPath = path.join(financeRoot, `m09-workshop-router-${runNonce}.log`); privateFile(fs.existsSync(logPath) ? logPath : (() => { fs.writeFileSync(logPath, ''); return logPath; })());
  serverLog = logPath;
  const log = fs.openSync(logPath, 'a');
  const publicRoot = path.join(root, 'public');
  const appEnv = { ...env, APP_URL: `http://127.0.0.1:${port}`, SESSION_COOKIE: `g2_m09_${runNonce.slice(0, 12)}` };
  server = spawn(process.env.PHP_BINARY || 'php', ['-d', 'opcache.enable_cli=0', '-S', `127.0.0.1:${port}`, '-t', publicRoot, router], { cwd: publicRoot, env: appEnv, stdio: ['ignore', log, log], windowsHide: true });
  baseUrl = `http://127.0.0.1:${port}`;
  let ready = false;
  for (let attempt = 0; attempt < 80; attempt++) {
    if (server.exitCode !== null) throw new Error('Private M09 router exited before readiness.');
    try {
      const response = await fetch(`${baseUrl}/login`);
      const body = await response.text();
      if (response.status >= 400) throw new Error(`Private M09 router readiness returned HTTP ${response.status}.`);
      if (response.status === 200 && /name=["']email["']/.test(body)) { ready = true; break; }
    } catch (error) { if (String(error.message).includes('readiness returned')) throw error; }
    await new Promise(resolve => setTimeout(resolve, 250));
  }
  if (!ready) throw new Error(`Private M09 router did not become ready; see private log ${path.basename(logPath)}.`);

  const executablePath = process.env.PLAYWRIGHT_EXECUTABLE_PATH || 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe';
  browser = await chromium.launch({ headless: true, executablePath });
  const date = fixture.today;
  const intakeActor = await login(fixture.actors.intake); let page = intakeActor.page;
  await page.goto(`${baseUrl}/workshops/intakes/create`);
  stage('intake draft form', page);
  await selectByLabel(page, 'الفرع', fixture.branch_id);
  await fillByLabel(page, 'بحث العميل', fixture.customer_name);
  const customer = page.getByLabel('العميل', { exact: true });
  await customer.locator('option').filter({ hasText: fixture.customer_name }).first().waitFor({ state: 'attached' });
  const customerValue = await customer.locator('option').filter({ hasText: fixture.customer_name }).first().getAttribute('value');
  await customer.selectOption(customerValue);
  await fillByLabel(page, 'وصف المعدة', `جهاز اختبار قبول الورشة ${fixture.run_tag}`);
  await fillByLabel(page, 'الموديل', 'M09-ACCEPTANCE');
  await fillByLabel(page, 'الرقم المسلسل', `M09-${fixture.run_tag}`);
  await fillByLabel(page, 'العطل المبلغ', 'لا يعمل مصدر التغذية بعد التشغيل');
  await fillByLabel(page, 'الحالة عند الوصول', 'وصل دون تشغيل مع تلف ظاهري محدود');
  await fillByLabel(page, 'الملحقات المستلمة', 'كابل التغذية الأصلي');
  await fillByLabel(page, 'موقع العهدة', `استلام ورشة M09 ${fixture.run_tag}`);
  await clickAction(page, page.getByRole('button', { name: /حفظ مسودة الاستلام والمتابعة/ }));
  await page.waitForURL(url => /\/workshops\/intakes\/[1-9][0-9]*$/.test(url.pathname));
  await page.getByRole('heading', { name: /استلام معدة/ }).waitFor();
  await captureStage(page, '01-intake-draft');
  const intakeId = Number(new URL(page.url()).pathname.split('/').pop());
  check(Number.isInteger(intakeId) && intakeId > 0, 'intake draft was created through the M09 intake form');
  await fillByLabel(page, 'وقت الاستلام بالقاهرة', localTime(-3));
  await fillByLabel(page, 'دليل إقرار العميل', `إقرار استلام اصطناعي داخل قاعدة الاختبار ${fixture.run_tag}`);
  stage('confirm equipment receipt', page);
  await clickAction(page, page.getByRole('button', { name: 'تأكيد استلام العهدة', exact: true }));
  await page.getByRole('heading', { name: 'إحالة المعدة للفحص والإصلاح' }).waitFor();
  await selectByLabel(page, 'الورشة', fixture.workshop_id);
  await selectByLabel(page, 'نوع الفوترة', 'billable');
  await clickAction(page, page.getByRole('button', { name: 'إنشاء أمر الإصلاح والمتابعة', exact: true }));
  await page.waitForURL(url => /\/workshops\/jobs\/[1-9][0-9]*$/.test(url.pathname));
  const jobId = Number(new URL(page.url()).pathname.split('/').pop());
  check(Number.isInteger(jobId) && jobId > 0, 'received intake created its paid repair job through the real M09 handoff');

  await selectByLabel(page, 'الفني المكلف', fixture.actors.technician.id);
  stage('assign technician', page);
  await fillByLabel(page, 'سبب الإسناد', `إسناد قبول اصطناعي ${fixture.run_tag}`);
  await clickAction(page, page.getByRole('button', { name: 'حفظ الإسناد', exact: true }));
  await captureStage(page, '02-received-job-assigned');
  const tech = await login(fixture.actors.technician); page = tech.page; await goJob(page, jobId, 'details');
  await fillByLabel(page, 'تصنيف العطل', 'تغذية كهربائية');
  await fillByLabel(page, 'التشخيص', 'انقطاع موصل التغذية داخل الجهاز');
  await fillByLabel(page, 'خطة الإصلاح', 'إعادة تثبيت الموصل ثم اختبار التشغيل والحمل');
  await fillByLabel(page, 'الدقائق المقدرة', '60');
  await fillByLabel(page, 'اسم الاختبار 1', 'اختبار التشغيل والحمل');
  await clickAction(page, page.getByRole('button', { name: 'حفظ التشخيص وخطة الفحص', exact: true }));
  await captureStage(page, '03-diagnosis-test-plan');

  const estimator = await login(fixture.actors.estimator); page = estimator.page; await goJob(page, jobId, 'estimate');
  await selectByLabel(page, 'خدمة الإصلاح من دليل الأصناف', fixture.service_product_id);
  await fillByLabel(page, 'تكلفة القطع المقدرة', '0.00'); await fillByLabel(page, 'تكلفة العمل المقدرة', '40.00'); await fillByLabel(page, 'تكاليف أخرى مقدرة', '0.00');
  await fillByLabel(page, 'سعر بيع القطع', '0.00'); await fillByLabel(page, 'أجر الإصلاح للعميل', '100.00'); await fillByLabel(page, 'رسوم أخرى', '0.00'); await fillByLabel(page, 'الخصم', '0.00');
  await fillByLabel(page, 'تاريخ العرض والضريبة', date); await fillByLabel(page, 'صالح حتى', date); await fillByLabel(page, 'مدة الضمان بالأيام، صفر بدون ضمان', '0');
  await fillByLabel(page, 'شروط الضمان', 'لا يوجد ضمان إضافي في اختبار القبول'); await fillByLabel(page, 'مرجع السعر أو سبب الاستثناء التجاري المطلوب اعتماده', `تسعير قبول محلي ${fixture.run_tag}`);
  await clickAction(page, page.getByRole('button', { name: 'حفظ مراجعة جديدة للاعتماد', exact: true }));
  await page.getByText('بانتظار اعتماد مستقل', { exact: true }).waitFor();
  const approver = await login(fixture.actors.price_approver); page = approver.page; await goJob(page, jobId, 'estimate');
  await fillByLabel(page, 'مرجع الاعتماد الداخلي', `اعتماد مستقل لقبول السعر ${fixture.run_tag}`);
  await clickAction(page, page.getByRole('button', { name: 'اعتماد السعر بشكل مستقل', exact: true }));
  await goJob(estimator.page, jobId, 'estimate');
  await clickAction(estimator.page, estimator.page.getByRole('button', { name: 'إصدار عرض الإصلاح', exact: true }));
  const pdfPath = path.join(financeRoot, `m09-quote-${runNonce}.pdf`);
  const [pdf, pdfDownload] = await Promise.all([
    estimator.page.waitForResponse(response => response.url().includes('/quote/') && response.url().endsWith('/pdf'), { timeout: 20000 }),
    estimator.page.waitForEvent('download', { timeout: 20000 }),
    estimator.page.getByRole('link', { name: 'عرض الإصلاح PDF', exact: true }).click(),
  ]).then(([response, download]) => [response, download]);
  await pdfDownload.saveAs(pdfPath); privateFile(pdfPath);
  const pdfBytes = fs.readFileSync(pdfPath);
  const pdfSha256 = crypto.createHash('sha256').update(pdfBytes).digest('hex');
  check(Boolean(pdf.status() === 200 && /application\/pdf/.test(pdf.headers()['content-type'] || '') && pdfBytes.subarray(0, 4).toString() === '%PDF'
      && /^[a-f0-9]{64}$/.test(pdfSha256)),
    'accepted price has an issued private PDF offer generated through the official document route');

  const acceptor = await login(fixture.actors.customer_acceptor); page = acceptor.page; await goJob(page, jobId, 'estimate');
  await fillByLabel(page, 'دليل قرار العميل على نفس العرض الصادر', `قرار قبول اصطناعي موثق ${fixture.run_tag}`);
  await clickAction(page, page.getByRole('button', { name: 'توثيق قرار العميل', exact: true }));
  await captureStage(page, '04-priced-quote-customer-accepted');
  const partsStorekeeper = await login(fixture.parts.actors.storekeeper); stage('parts:request-reserve-issue', partsStorekeeper.page);
  await goJob(partsStorekeeper.page, jobId, 'parts');
  await selectByLabel(partsStorekeeper.page, 'قطعة الغيار', fixture.parts.product_id);
  await selectByLabel(partsStorekeeper.page, 'مستودع الصرف', fixture.parts.warehouse_id);
  await fillByLabel(partsStorekeeper.page, 'الكمية المطلوبة', '2.00000000');
  await partsStorekeeper.page.getByText(/المخزون الفعلي/).waitFor();
  await clickAction(partsStorekeeper.page, partsStorekeeper.page.getByRole('button', { name: 'إضافة طلب قطعة', exact: true }));
  const partRow = partsStorekeeper.page.locator('tr[wire\\:key^="job-part-"]').filter({ hasText: fixture.parts.product_name });
  await partRow.waitFor({ state: 'visible' });
  await selectFirstOption(partsStorekeeper.page, 'طلب القطعة');
  await fillByLabel(partsStorekeeper.page, 'الكمية المراد حجزها', '2.00000000');
  await clickAction(partsStorekeeper.page, partsStorekeeper.page.getByRole('button', { name: 'حجز الكمية', exact: true }));
  await selectByLabel(partsStorekeeper.page, 'العملية المطلوبة', 'issue');
  await selectFirstOption(partsStorekeeper.page, 'الحجز المصدر للصرف');
  await fillByLabel(partsStorekeeper.page, 'الكمية المراد صرفها', '2.00000000');
  await clickAction(partsStorekeeper.page, partsStorekeeper.page.getByRole('button', { name: 'صرف فعلي من المخزون', exact: true }));
  await partRow.locator('td').nth(2).getByText('2.00000000', { exact: true }).waitFor();
  await captureStage(partsStorekeeper.page, '05-part-request-reserve-issue');
  await tech.page.goto(`${baseUrl}/workshops/jobs/${jobId}?tab=work`);
  await fillByLabel(tech.page, 'سبب انتقال حالة الإصلاح', `بدء تنفيذ قبول الورشة ${fixture.run_tag}`);
  await clickAction(tech.page, tech.page.getByRole('button', { name: 'بدء الإصلاح', exact: true }));
  await fillByLabel(tech.page, 'بداية العمل بالقاهرة', localTime(-1.5)); await fillByLabel(tech.page, 'نهاية العمل بالقاهرة', localTime(-1));
  await fillByLabel(tech.page, 'نوع العمل', 'إصلاح كهربائي'); await fillByLabel(tech.page, 'العمل المنفذ', 'إعادة تثبيت موصل التغذية');
  await fillByLabel(tech.page, 'الملاحظات', 'تم التحقق من ثبات التوصيل');
  await clickAction(tech.page, tech.page.getByRole('button', { name: 'تثبيت سجل العمل والوقت', exact: true }));
  await tech.page.goto(`${baseUrl}/workshops/jobs/${jobId}?tab=parts`);
  await selectFirstOption(tech.page, 'طلب القطعة');
  await selectByLabel(tech.page, 'العملية المطلوبة', 'use');
  await selectFirstOption(tech.page, 'سجل العمل الذي استخدمت فيه القطعة');
  await fillByLabel(tech.page, 'الكمية المستخدمة فعليًا', '1.00000000');
  await clickAction(tech.page, tech.page.getByRole('button', { name: 'تسجيل استخدام القطعة', exact: true }));
  const technicianPartRow = tech.page.locator('tr[wire\\:key^="job-part-"]').filter({ hasText: fixture.parts.product_name });
  await technicianPartRow.locator('td').nth(3).getByText('1.00000000', { exact: true }).waitFor();
  await captureStage(tech.page, '06-technician-records-part-use');
  await partsStorekeeper.page.goto(`${baseUrl}/workshops/jobs/${jobId}?tab=parts`);
  await selectFirstOption(partsStorekeeper.page, 'طلب القطعة');
  await selectByLabel(partsStorekeeper.page, 'العملية المطلوبة', 'return');
  await selectFirstOption(partsStorekeeper.page, 'حركة الصرف الأصلية للإرجاع');
  await fillByLabel(partsStorekeeper.page, 'الكمية غير المستخدمة المراد إرجاعها', '1.00000000');
  await fillByLabel(partsStorekeeper.page, 'سبب إرجاع القطعة', `إرجاع الكمية غير المستخدمة ${fixture.run_tag}`);
  await clickAction(partsStorekeeper.page, partsStorekeeper.page.getByRole('button', { name: 'إرجاع غير المستخدم', exact: true }));
  await partRow.locator('td').nth(4).getByText('1.00000000', { exact: true }).waitFor();
  await captureStage(partsStorekeeper.page, '07-storekeeper-returns-unused-part');
  await fillByLabel(tech.page, 'سبب انتقال حالة الإصلاح', `إحالة فعلية للاختبار ${fixture.run_tag}`);
  await clickAction(tech.page, tech.page.getByRole('button', { name: 'إحالة للاختبار', exact: true }));
  await tech.page.goto(`${baseUrl}/workshops/jobs/${jobId}?tab=testing`);
  await selectByLabel(tech.page, 'الاختبار المخطط', 'اختبار التشغيل والحمل'); await selectByLabel(tech.page, 'نتيجة الاختبار', 'passed');
  await fillByLabel(tech.page, 'القياسات والنتائج الفعلية', 'تشغيل ثابت تحت حمل الاختبار لمدة عشر دقائق');
  await clickAction(tech.page, tech.page.getByRole('button', { name: 'تثبيت نتيجة الاختبار', exact: true }));
  await captureStage(tech.page, '08-work-record-and-qc-test');
  const verifier = await login(fixture.actors.verifier); page = verifier.page; await goJob(page, jobId, 'testing');
  await fillByLabel(page, 'الحالة النهائية المتحقق منها', 'التشغيل والحمل طبيعيان'); await fillByLabel(page, 'الأعطال المتبقية المفصح عنها', 'لا توجد أعطال متبقية');
  await clickAction(page, page.getByRole('button', { name: 'تحقق فني وإصدار تقرير الإصلاح', exact: true }));
  const billing = await login(fixture.actors.billing); page = billing.page; await goJob(page, jobId, 'billing');
  await fillByLabel(page, 'تاريخ الفاتورة المطابق للضريبة المعتمدة', date); await fillByLabel(page, 'تاريخ الاستحقاق', date);
  await clickAction(page, page.getByRole('button', { name: 'إنشاء مسودة فاتورة الإصلاح', exact: true }));
  await goJob(verifier.page, jobId, 'delivery');
  await fillByLabel(verifier.page, 'اسم المستلم', fixture.customer_name); await fillByLabel(verifier.page, 'وقت التسليم بالقاهرة', localTime(0));
  await fillByLabel(verifier.page, 'الملحقات المسلمة', 'كابل التغذية الأصلي'); await fillByLabel(verifier.page, 'حالة المعدة عند التسليم', 'تم إصلاحها واختبارها');
  await fillByLabel(verifier.page, 'إقرار المستلم ودليل التسليم', `استلام نهائي اصطناعي ${fixture.run_tag}`);
  await clickAction(verifier.page, verifier.page.getByRole('button', { name: 'تأكيد التسليم وإصدار المحضر', exact: true }));
  await captureStage(verifier.page, '09-independently-verified-delivered');

  await clickAction(billing.page, billing.page.getByRole('button', { name: 'التحقق من فاتورة الإصلاح', exact: true }));
  await billing.page.getByText(/تم التحقق، جاهزة للإصدار/).waitFor();
  const poster = await login(fixture.actors.poster); page = poster.page; await goJob(page, jobId, 'billing');
  await clickAction(page, page.getByRole('button', { name: 'إصدار الفاتورة وترحيلها', exact: true }));
  await page.getByText(/مرحلة|posted/).waitFor();
  await captureStage(page, '10-invoice-validated-posted');
  const cashier = await login(fixture.actors.cashier); page = cashier.page; await goJob(page, jobId, 'billing');
  await selectByLabel(page, 'حساب استلام المبلغ', fixture.cash_account_id); await fillByLabel(page, 'المبلغ المحصل بالجنيه', fixture.collection_amount);
  await fillByLabel(page, 'تاريخ التحصيل', date); await fillByLabel(page, 'مرجع استلام المبلغ', fixture.collection_reference);
  await clickAction(page, page.getByRole('button', { name: 'تسجيل التحصيل وإصدار سند القبض', exact: true }));
  await page.getByText(/محصلة جزئيًا/).waitFor();
  await captureStage(page, '11-partial-invoice-collection');

  const verifyOutput = runPhp('tests/Support/gate2-workshop-ui-fixtures.php', [`--verify=${runNonce}-${jobId}`], env);
  check(verifyOutput.includes('PASS'), `terminal DB assertion verifies actual delivered job, posted invoice, real partial collection and unchanged pre-run company/other-branch snapshots (${lastPhpDiagnostic})`);
  check(failures.length === 0, 'browser completed without uncaught page errors');
  const report = { status: 'PASS', run_nonce: runNonce, database: bootstrap.database, port: 49686, branch_id: fixture.branch_id, intake_id: intakeId, job_id: jobId,
    amount: '100.00', collection_amount: fixture.collection_amount, quote_pdf_sha256: pdfSha256, screenshots, assertions, created_at: new Date().toISOString() };
  fs.writeFileSync(path.join(financeRoot, `m09-workshop-${runNonce}-receipt.json`), JSON.stringify(report, null, 2));
  privateFile(path.join(financeRoot, `m09-workshop-${runNonce}-receipt.json`));
  console.log(JSON.stringify(report, null, 2));
  await Promise.all([intakeActor.context.close(), estimator.context.close(), approver.context.close(), acceptor.context.close(), tech.context.close(), partsStorekeeper.context.close(), verifier.context.close(), billing.context.close(), poster.context.close(), cashier.context.close()]);
}

main().catch(async error => {
  const failure = { status: 'FAIL', run_nonce: runNonce, stage: activeStage, message: String(error.message || error).slice(0, 1200),
    current_path: activePage ? new URL(activePage.url()).pathname : null, last_php_diagnostic: lastPhpDiagnostic, assertions, screenshots,
    router_log: serverLog ? path.basename(serverLog) : null, created_at: new Date().toISOString() };
  const receiptPath = path.join(financeRoot, `m09-workshop-${runNonce}-failure.json`);
  try {
    if (activePage && !activePage.isClosed()) {
      const screenshotPath = path.join(financeRoot, `m09-workshop-${runNonce}-failure.png`);
      await activePage.screenshot({ path: screenshotPath, fullPage: true, timeout: 10000 }).then(() => privateFile(screenshotPath)).catch(() => {});
      failure.failure_screenshot = path.basename(screenshotPath);
    }
    fs.writeFileSync(receiptPath, JSON.stringify(failure, null, 2), { mode: 0o600 });
    privateFile(receiptPath);
  } catch {}
  console.error(`${failure.stage}: ${failure.message}`);
  if (lastPhpDiagnostic) console.error(`Last PHP fixture diagnostic: ${lastPhpDiagnostic}`);
  console.error(`Private failure receipt: ${path.basename(receiptPath)}`);
  if (serverLog && fs.existsSync(serverLog)) console.error(`Private router log: ${path.basename(serverLog)}`);
  process.exitCode = 1;
}).finally(async () => {
  if (browser) await browser.close().catch(() => {});
  if (server) { server.kill('SIGTERM'); }
});
