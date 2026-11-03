'use strict';

// M09 WorkshopRepairs authenticated desktop/mobile acceptance on disposable PostgreSQL 55447.
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const crypto = require('node:crypto');
const net = require('node:net');
const { spawn, spawnSync } = require('node:child_process');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '../..');
const dbPort = 55447;
const router = path.join(root, 'tests/E2E/support/laravel-static-router.php');
const fixturePath = path.join(root, 'tests/E2E/support/prepare-phase5-workshop-browser.php');
const workRoot = path.join(root, '.tmp/phase5-workshop-browser');
const runTag = crypto.randomBytes(5).toString('hex').toUpperCase();
const runDir = path.join(root, 'docs/qa/evidence/phase5-workshop-browser', `run-${runTag}`);
const storage = path.join(workRoot, `storage-${runTag}`);
const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'erp-phase5-workshop-'));
const port = crypto.randomInt(9601, 9999);
const base = `http://127.0.0.1:${port}`;
const password = crypto.randomBytes(32).toString('base64url');
const browserTestAt = new Date().toISOString();
const browserEpoch = Date.parse(browserTestAt);
const browserErrors = [];
const checks = [];
const cachedBrowserRoot = path.join(process.env.LOCALAPPDATA || '', 'ms-playwright');
const cachedChromium = fs.existsSync(cachedBrowserRoot)
  ? fs.readdirSync(cachedBrowserRoot).filter(name => /^chromium-\d+$/.test(name)).sort((a, b) => Number(b.slice(9)) - Number(a.slice(9)))
    .map(name => path.join(cachedBrowserRoot, name, 'chrome-win64', 'chrome.exe')).find(fs.existsSync)
  : null;
const browserExecutable = process.env.PLAYWRIGHT_EXECUTABLE_PATH || cachedChromium || 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe';
const env = {
  ...process.env,
  APP_ENV: 'local', APP_URL: base, APP_DEBUG: 'false',
  APP_CONFIG_CACHE: path.join(temp, 'config.php'), APP_ROUTES_CACHE: path.join(temp, 'routes.php'),
  DB_CONNECTION: 'pgsql', DB_HOST: '127.0.0.1', DB_PORT: String(dbPort), DB_DATABASE: 'erp_elec_new_test',
  DB_USERNAME: 'postgres', DB_PASSWORD: '', DB_URL: '',
  SESSION_DRIVER: 'file', CACHE_STORE: 'array', QUEUE_CONNECTION: 'sync', MAIL_MAILER: 'array', LOG_CHANNEL: 'stderr',
  BROADCAST_CONNECTION: 'null', VIEW_COMPILED_PATH: path.join(temp, 'views'), LARAVEL_STORAGE_PATH: storage,
  PHASE5_WORKSHOP_DB_PORT: String(dbPort), PHASE5_WORKSHOP_STORAGE_ROOT: storage,
  PHASE5_WORKSHOP_PASSWORD: password, PHASE5_WORKSHOP_TAG: runTag,
};
let server;
let serverLog;
let browser;
let fixture;

fs.mkdirSync(runDir, { recursive: true });
fs.mkdirSync(path.join(temp, 'views'), { recursive: true });

function phpJson(extra = {}) {
  const result = spawnSync('php', ['-d', 'opcache.enable_cli=0', '-d', 'memory_limit=512M', fixturePath], {
    cwd: root, env: { ...env, APP_ENV: 'testing', ...extra }, encoding: 'utf8', windowsHide: true, maxBuffer: 4 * 1024 * 1024,
  });
  if (result.status !== 0) throw new Error(`Guarded M09 fixture failed (${result.status}): ${(result.stderr || result.stdout || '').slice(-1800)}`);
  try { return JSON.parse(result.stdout); } catch { throw new Error(`M09 fixture returned no JSON: ${(result.stderr || result.stdout || '').slice(0, 700)}`); }
}

async function freePort(value) {
  await new Promise((resolve, reject) => {
    const probe = net.createServer();
    probe.once('error', error => reject(new Error(error.code === 'EADDRINUSE' ? `Browser port ${value} is occupied.` : error.message)));
    probe.listen(value, '127.0.0.1', () => probe.close(resolve));
  });
}

async function waitForServer() {
  const deadline = Date.now() + 30000;
  while (Date.now() < deadline) {
    if (server.exitCode !== null) throw new Error('Workshop browser server exited during startup.');
    try { const response = await fetch(base + '/login'); if (response.status === 200) return; } catch {}
    await new Promise(resolve => setTimeout(resolve, 250));
  }
  throw new Error('Workshop browser server did not become ready.');
}

function observe(page) {
  page.on('pageerror', error => browserErrors.push({ kind: 'pageerror', url: new URL(page.url()).pathname, message: String(error.stack || error.message).slice(0, 700) }));
  page.on('console', message => { if (message.type() === 'error') browserErrors.push({ kind: 'console', url: new URL(page.url()).pathname, message: message.text().slice(0, 250) }); });
  page.on('response', response => { if (response.status() >= 500) browserErrors.push({ kind: 'http', url: new URL(response.url()).pathname, status: response.status() }); });
}

function wireModel(key) {
  return `[wire\\:model="${key}"], [wire\\:model\\.live="${key}"], [wire\\:model\\.live\\.debounce\\.300ms="${key}"]`;
}

async function setModel(page, key, value) {
  const target = page.locator(wireModel(key)).first();
  await target.waitFor({ state: 'visible', timeout: 12000 });
  await target.fill(String(value));
}

async function selectModel(page, key, value) {
  const target = page.locator(wireModel(key)).first();
  await target.waitFor({ state: 'visible', timeout: 12000 });
  await target.selectOption(String(value));
}

function actionResponse(response, action) {
  if (!/\/livewire(?:-[^/]+)?\/update/.test(response.url()) || response.request().method() !== 'POST') return false;
  try {
    const payload = JSON.parse(response.request().postData() || '{}');
    return (payload.components || []).some(component => (component.calls || []).some(call => call.method === action || call.path === action));
  } catch { return false; }
}

async function submit(page, action) {
  const form = page.locator(`form[wire\\:submit="${action}"]`).first();
  await form.waitFor({ state: 'visible', timeout: 12000 });
  const responsePromise = page.waitForResponse(response => actionResponse(response, action), { timeout: 60000 });
  await form.evaluate(node => node.requestSubmit());
  const response = await responsePromise;
  if (response.status() !== 200) throw new Error(`${action} form returned Livewire HTTP ${response.status()}.`);
}

async function clickWire(page, exact, label = exact) {
  const buttons = await page.locator('[wire\\:click]').all();
  let target = null;
  for (const button of buttons) {
    if (await button.getAttribute('wire:click') === exact && await button.isVisible().catch(() => false)) { target = button; break; }
  }
  if (!target) {
    const body = await page.locator('#main-content').innerText().catch(() => '');
    throw new Error(`${label} action missing on ${page.url()}: ${body.slice(0, 1200)}`);
  }
  const action = exact.match(/^\s*([A-Za-z_$][\w$]*)/)?.[1];
  const responsePromise = page.waitForResponse(response => actionResponse(response, action), { timeout: 60000 });
  await target.click({ timeout: 15000 });
  const response = await responsePromise;
  if (response.status() !== 200) throw new Error(`${label} returned Livewire HTTP ${response.status()}.`);
}

async function login(page, actor) {
  await page.goto(base + '/login');
  await page.locator('input[name="email"]').fill(actor.email);
  await page.locator('input[name="password"]').fill(password);
  await page.locator('[data-test="login-button"]').click();
  await page.waitForURL(url => url.pathname === '/dashboard', { timeout: 20000 });
}

function cairoDateTime(minutesFromNow = 0) {
  const date = new Date(browserEpoch + minutesFromNow * 60000);
  const parts = new Intl.DateTimeFormat('en-GB', { timeZone: 'Africa/Cairo', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' })
    .formatToParts(date).reduce((out, part) => (out[part.type] = part.value, out), {});
  return `${parts.year}-${parts.month}-${parts.day}T${parts.hour}:${parts.minute}`;
}

function cairoDate() { return cairoDateTime().slice(0, 10); }

async function gotoTab(page, tab) {
  const link = page.locator(`a[href*="tab=${tab}"]`).first();
  await link.waitFor({ state: 'visible', timeout: 12000 });
  await link.click();
  await page.waitForURL(url => url.searchParams.get('tab') === tab, { timeout: 20000 });
  await page.locator('#main-content').waitFor({ state: 'visible' });
}

async function checkMobile(page, label, expected) {
  await page.setViewportSize({ width: 390, height: 844 });
  const response = await page.reload({ waitUntil: 'domcontentloaded' });
  if (!response || response.status() !== 200) throw new Error(`${label} failed to reload at mobile viewport.`);
  const body = await page.locator('#main-content').innerText();
  if (!body.includes(expected)) throw new Error(`${label} lost persisted content: ${expected}`);
  const metrics = await page.evaluate(() => ({ viewport: document.documentElement.clientWidth, document: document.documentElement.scrollWidth }));
  if (metrics.document > metrics.viewport + 4) throw new Error(`${label} horizontally overflows on mobile: ${JSON.stringify(metrics)}`);
  await page.screenshot({ path: path.join(runDir, `${label}-mobile.png`), animations: 'disabled' });
  checks.push({ mobile: label, ...metrics });
}

async function readback(ids) {
  return phpJson({
    PHASE5_WORKSHOP_FIXTURE_MODE: 'readback',
    PHASE5_WORKSHOP_INTAKE_ID: String(ids.intakeId || 0),
    PHASE5_WORKSHOP_JOB_ID: String(ids.jobId || 0),
    PHASE5_WORKSHOP_CODE: fixture.workshop_code,
  });
}

async function main() {
  if (dbPort !== 55447) throw new Error('M09 browser acceptance is hard-bound to disposable PostgreSQL 55447.');
  await freePort(port);
  fixture = phpJson({ PHASE5_WORKSHOP_FIXTURE_MODE: 'prepare' });
  if (fixture.database !== 'erp_elec_new_test' || fixture.port !== dbPort || fixture.inventory_before.movements !== 0 || fixture.inventory_before.fifo_layers !== 0) {
    throw new Error(`Fixture helper returned unexpected database or nonempty stock: ${JSON.stringify(fixture.inventory_before)}`);
  }
  serverLog = fs.openSync(path.join(runDir, 'server.log.redacted.txt'), 'w');
  server = spawn('php', ['-d', 'memory_limit=512M', '-d', 'opcache.enable_cli=0', '-S', `127.0.0.1:${port}`, '-t', '.', router], {
    cwd: path.join(root, 'public'), env, stdio: ['ignore', serverLog, serverLog], windowsHide: true,
  });
  await waitForServer();
  browser = await chromium.launch({ executablePath: browserExecutable, headless: true, timeout: 30000 });
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'ar-EG', timezoneId: 'Africa/Cairo', colorScheme: 'light' });
  const dispatcher = await context.newPage(); observe(dispatcher);
  await login(dispatcher, fixture.actors.dispatcher);

  // The fixture workshop is already configured through the owner Action. Exercise
  // its actual settings form and membership editor before the intake journey.
  await dispatcher.goto(base + '/workshops/settings');
  const editAction = `editWorkshop(${fixture.workshop_id})`;
  await clickWire(dispatcher, editAction, 'open workshop settings');
  await setModel(dispatcher, 'form.name', `ورشة اختبار معدلة ${runTag}`);
  await submit(dispatcher, 'saveWorkshop');
  await selectModel(dispatcher, 'technicianId', fixture.actors.technician.id);
  await submit(dispatcher, 'saveMember');
  let state = await readback({});
  if (!state.workshop || !state.workshop.active || state.workshop.version !== 4) throw new Error(`Workshop edit/member forms did not persist expected versions: ${JSON.stringify(state.workshop)}`);
  checks.push({ browser_workshop_settings_and_member_version: state.workshop.version });
  await dispatcher.setViewportSize({ width: 1440, height: 900 });
  await dispatcher.screenshot({ path: path.join(runDir, 'workshop-settings-desktop.png'), animations: 'disabled' });

  await dispatcher.setViewportSize({ width: 390, height: 844 });
  await dispatcher.goto(base + '/workshops/intakes/create');
  await selectModel(dispatcher, 'form.branch_id', fixture.branch_id);
  await setModel(dispatcher, 'customerSearch', fixture.customer_code);
  await dispatcher.waitForTimeout(500);
  await selectModel(dispatcher, 'form.customer_id', fixture.customer_id);
  await setModel(dispatcher, 'form.equipment_description', `قاطع تجريبي ${runTag} وارد للفحص والإصلاح.`);
  await setModel(dispatcher, 'form.model', 'DEMO-M09-BREAKER');
  await setModel(dispatcher, 'form.serial_number', `DEMO-M09-${runTag}`);
  await setModel(dispatcher, 'form.accessories', 'مفتاح تشغيل وكتيب الجهاز');
  await setModel(dispatcher, 'form.reported_fault', 'فصل متكرر حسب البلاغ التجريبي للعميل.');
  await setModel(dispatcher, 'form.arrival_condition', 'الجسم الخارجي سليم ولا توجد آثار كسر ظاهرة.');
  await setModel(dispatcher, 'form.location', 'مكتب استلام الورشة');
  await submit(dispatcher, 'save');
  await dispatcher.waitForURL(url => /^\/workshops\/intakes\/\d+$/.test(url.pathname), { timeout: 15000 });
  const intakeId = Number(dispatcher.url().split('/').at(-1));
  await setModel(dispatcher, 'form.accessories', 'مفتاح تشغيل وكتيب الجهاز مع ملصق إضافي.');
  await submit(dispatcher, 'save');
  await dispatcher.waitForURL(url => url.pathname === `/workshops/intakes/${intakeId}`);
  state = await readback({ intakeId });
  if (!state.intake || state.intake.version !== 2 || state.intake.status !== 'draft' || !state.intake.accessories.includes('ملصق إضافي')) {
    throw new Error(`Intake draft resave readback mismatch: ${JSON.stringify(state.intake)}`);
  }
  checks.push({ browser_mobile_intake_create_resave: { id: intakeId, version: state.intake.version } });
  await checkMobile(dispatcher, 'intake-resaved', 'ملصق إضافي');

  const attachment = Buffer.from('%PDF-1.4\n% Synthetic private M09 acceptance attachment\n1 0 obj << /Type /Catalog >> endobj\n%%EOF\n');
  const attachmentPath = path.join(temp, 'workshop-evidence.pdf');
  fs.writeFileSync(attachmentPath, attachment);
  const upload = dispatcher.locator('[wire\\:model="attachment"]');
  const uploadDone = upload.evaluate(element => new Promise((resolve, reject) => {
    const timeout = setTimeout(() => reject(new Error('Livewire upload did not finish.')), 30000);
    element.addEventListener('livewire-upload-finish', () => { clearTimeout(timeout); resolve(); }, { once: true });
    element.addEventListener('livewire-upload-error', () => { clearTimeout(timeout); reject(new Error('Livewire emitted upload error.')); }, { once: true });
  }));
  await upload.setInputFiles(attachmentPath);
  await uploadDone;
  await submit(dispatcher, 'saveAttachment');
  state = await readback({ intakeId });
  const uploadedDocument = state.documents[0];
  const expectedHash = crypto.createHash('sha256').update(attachment).digest('hex');
  if (!uploadedDocument || uploadedDocument.sha256 !== expectedHash) throw new Error(`Private upload hash mismatch: ${JSON.stringify(state.documents)}`);
  const attachmentResponse = await context.request.get(base + `/workshops/documents/${uploadedDocument.id}`);
  const attachmentBytes = await attachmentResponse.body();
  const cacheHeaders = (attachmentResponse.headers()['cache-control'] || '').toLowerCase();
  if (attachmentResponse.status() !== 200 || !cacheHeaders.includes('private') || !cacheHeaders.includes('no-store')
    || !attachmentBytes.equals(attachment)) throw new Error(`Private attachment download failed: ${JSON.stringify({ status: attachmentResponse.status(), cacheHeaders, bytes: attachmentBytes.length })}`);
  checks.push({ private_attachment: { id: uploadedDocument.id, sha256: expectedHash, bytes: attachment.length } });

  await setModel(dispatcher, 'receivedAt', cairoDateTime(-60));
  await setModel(dispatcher, 'acknowledgment', 'أقر باستلام القاطع والملحقات للفحص دون وعد بإصلاح.');
  await submit(dispatcher, 'receive');
  state = await readback({ intakeId });
  if (state.intake.status !== 'received' || state.intake.version !== 3 || state.custody_events.filter(value => value === 'received').length !== 1) {
    throw new Error(`Workshop receipt readback mismatch: ${JSON.stringify({ intake: state.intake, custody: state.custody_events })}`);
  }
  await selectModel(dispatcher, 'workshopId', fixture.workshop_id);
  await selectModel(dispatcher, 'priority', 'high');
  await selectModel(dispatcher, 'billingMode', 'nonbillable');
  await submit(dispatcher, 'createJob');
  await dispatcher.waitForURL(url => /^\/workshops\/jobs\/\d+$/.test(url.pathname), { timeout: 15000 });
  const jobId = Number(dispatcher.url().split('/').at(-1));
  state = await readback({ intakeId, jobId });
  if (!state.job || state.job.status !== 'inspection' || state.job.version !== 1) throw new Error(`Workshop job conversion mismatch: ${JSON.stringify(state.job)}`);
  checks.push({ browser_receipt_and_job_conversion: { intakeId, jobId, state: state.job.status } });

  await gotoTab(dispatcher, 'details');
  await selectModel(dispatcher, 'technicianId', fixture.actors.technician.id);
  await setModel(dispatcher, 'reason', 'إسناد القاطع للفني المختص بالفحص الأولي.');
  await submit(dispatcher, 'assign');
  state = await readback({ intakeId, jobId });
  if (state.job.technician_id !== fixture.actors.technician.id || state.job.version !== 2) throw new Error(`Technician assignment readback mismatch: ${JSON.stringify(state.job)}`);

  const technicianContext = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'ar-EG', timezoneId: 'Africa/Cairo', colorScheme: 'light' });
  const technician = await technicianContext.newPage(); observe(technician);
  await login(technician, fixture.actors.technician);
  await technician.goto(base + `/workshops/jobs/${jobId}`);
  await gotoTab(technician, 'details');
  await setModel(technician, 'diagnosis.diagnosis', 'تآكل ظاهر على نقاط التلامس مع فصل متقطع.');
  await setModel(technician, 'diagnosis.planned_work', 'تنظيف نقاط التلامس وفحص التشغيل حسب إجراء المصنع المسجل.');
  await setModel(technician, 'diagnosis.estimated_minutes', '45');
  await setModel(technician, 'diagnosis.required_tests.0.name', 'فحص التشغيل حسب إجراء المصنع');
  await submit(technician, 'diagnose');
  state = await readback({ intakeId, jobId });
  if (!state.job.required_tests?.some(test => test.name === 'فحص التشغيل حسب إجراء المصنع' && test.required)) throw new Error(`Required test plan did not persist: ${JSON.stringify(state.job)}`);
  checks.push({ browser_assignment_and_diagnosis: { technician_id: state.job.technician_id, required_tests: state.job.required_tests.length } });
  await technician.setViewportSize({ width: 390, height: 844 });
  await technician.reload({ waitUntil: 'domcontentloaded' });
  const diagnosisMetrics = await technician.evaluate(() => ({ viewport: document.documentElement.clientWidth, document: document.documentElement.scrollWidth }));
  if (diagnosisMetrics.document > diagnosisMetrics.viewport + 4) throw new Error(`Mobile diagnosis overflows: ${JSON.stringify(diagnosisMetrics)}`);
  await technician.screenshot({ path: path.join(runDir, 'diagnosis-mobile.png'), animations: 'disabled' });
  checks.push({ mobile: 'diagnosis', ...diagnosisMetrics });

  // A nonbillable quote exercises estimate versioning and independent customer
  // acceptance while avoiding synthetic invoices or accounting postings.
  await dispatcher.goto(base + `/workshops/jobs/${jobId}`);
  await gotoTab(dispatcher, 'estimate');
  await selectModel(dispatcher, 'estimate.product_id', fixture.service_product_id);
  await setModel(dispatcher, 'estimate.parts_amount', '0.00');
  await setModel(dispatcher, 'estimate.labor_amount', '0.00');
  await setModel(dispatcher, 'estimate.other_amount', '0.00');
  await setModel(dispatcher, 'estimate.billable_amount', '0.00');
  await setModel(dispatcher, 'estimate.date', cairoDate());
  await setModel(dispatcher, 'estimate.pricing_reference', `DEMO-EG M09 internal acceptance estimate ${runTag}`);
  await submit(dispatcher, 'proposeEstimate');
  state = await readback({ intakeId, jobId });
  const estimate = state.estimates[0];
  if (!estimate || state.job.status !== 'waiting_approval' || estimate.billable_amount !== '0.00') throw new Error(`Nonbillable estimate readback mismatch: ${JSON.stringify({ job: state.job, estimates: state.estimates })}`);
  const supervisorContext = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'ar-EG', timezoneId: 'Africa/Cairo', colorScheme: 'light' });
  const supervisor = await supervisorContext.newPage(); observe(supervisor);
  await login(supervisor, fixture.actors.supervisor);
  await supervisor.goto(base + `/workshops/jobs/${jobId}`);
  await gotoTab(supervisor, 'estimate');
  await selectModel(supervisor, 'estimateId', estimate.id);
  await setModel(supervisor, 'approvalEvidence', 'موافقة عميل تجريبية موثقة لهذا السيناريو فقط.');
  await submit(supervisor, 'decideEstimate');
  state = await readback({ intakeId, jobId });
  if (state.job.status !== 'approved' || state.approvals.length !== 1
    || state.approvals[0].approved_by !== fixture.actors.supervisor.id
    || state.approvals[0].approved_by === estimate.proposed_by || !state.approvals[0].customer_accepted) {
    throw new Error(`Estimate was not accepted independently: ${JSON.stringify({ job: state.job, approvals: state.approvals })}`);
  }
  checks.push({ independent_estimate_approval: { estimate_id: estimate.id, accepted_by_different_supervisor: true, tax: estimate.tax_amount } });

  await technician.goto(base + `/workshops/jobs/${jobId}?tab=work`);
  await setModel(technician, 'reason', 'الفني يبدأ الإصلاح بعد اعتماد التقدير.');
  await clickWire(technician, "state('start')", 'start approved repair');
  state = await readback({ intakeId, jobId });
  if (state.job.status !== 'repair') throw new Error(`Approved job did not enter repair: ${JSON.stringify(state.job)}`);
  await setModel(technician, 'record.started_at', cairoDateTime(-25));
  await setModel(technician, 'record.ended_at', cairoDateTime(-15));
  await setModel(technician, 'record.work_type', 'إصلاح');
  await setModel(technician, 'record.work_performed', 'تم تنظيف نقاط التلامس وإعادة تثبيت الوصلة.');
  await setModel(technician, 'record.observations', 'لا توجد قطع شركة مستخدمة في هذا السيناريو.');
  await submit(technician, 'recordWork');
  state = await readback({ intakeId, jobId });
  if (state.work_records !== 1 || state.job.version !== 4) throw new Error(`Actual work record was not persisted: ${JSON.stringify(state)}`);
  await setModel(technician, 'reason', 'اكتمل العمل وتحال المعدة للاختبار.');
  await clickWire(technician, "state('testing')", 'send repair to testing');
  state = await readback({ intakeId, jobId });
  if (state.job.status !== 'testing') throw new Error(`Repair did not enter testing: ${JSON.stringify(state.job)}`);

  await gotoTab(technician, 'testing');
  await selectModel(technician, 'test.name', 'فحص التشغيل حسب إجراء المصنع');
  await selectModel(technician, 'test.outcome', 'failed');
  await setModel(technician, 'test.results', 'فشل اختبار التشغيل الأول؛ لا يسمح بالتسليم.');
  await submit(technician, 'recordTest');
  state = await readback({ intakeId, jobId });
  if (state.tests.length !== 1 || state.tests[0].outcome !== 'failed') throw new Error(`Failed test evidence did not persist: ${JSON.stringify(state.tests)}`);

  await supervisor.goto(base + `/workshops/jobs/${jobId}?tab=testing`);
  await setModel(supervisor, 'finalCondition', 'لا يعتمد الإصلاح قبل نجاح الاختبار المطلوب.');
  await setModel(supervisor, 'remainingFaults', 'الاختبار الإلزامي ما زال فاشلاً.');
  await submit(supervisor, 'verify');
  state = await readback({ intakeId, jobId });
  if (state.job.status !== 'testing' || state.verification !== null) throw new Error(`Failed required test did not block verification: ${JSON.stringify(state)}`);
  checks.push({ required_test_guard: 'failed result blocked verification without issuing a report' });

  await selectModel(technician, 'test.name', 'فحص التشغيل حسب إجراء المصنع');
  await selectModel(technician, 'test.outcome', 'passed');
  await setModel(technician, 'test.results', 'بعد إعادة الضبط نجح اختبار التشغيل وفق إجراء المصنع.');
  await submit(technician, 'recordTest');
  state = await readback({ intakeId, jobId });
  if (state.tests.length !== 2 || state.tests[1].outcome !== 'passed') throw new Error(`Passing retry of the required test did not persist: ${JSON.stringify(state.tests)}`);

  await supervisor.goto(base + `/workshops/jobs/${jobId}?tab=testing`);
  await setModel(supervisor, 'finalCondition', 'القاطع يعمل بعد الإصلاح واجتاز الاختبار المحدد.');
  await setModel(supervisor, 'remainingFaults', 'لا توجد أعطال متبقية حسب الفحص المسجل.');
  await submit(supervisor, 'verify');
  state = await readback({ intakeId, jobId });
  if (state.job.status !== 'verified' || !state.verification?.document_id) throw new Error(`Supervisor verification did not persist official evidence: ${JSON.stringify(state)}`);
  const reportResponse = await supervisorContext.request.get(base + `/workshops/jobs/${jobId}/report`);
  const reportBytes = await reportResponse.body();
  const reportCache = (reportResponse.headers()['cache-control'] || '').toLowerCase();
  if (reportResponse.status() !== 200 || !reportCache.includes('private') || !reportCache.includes('no-store') || !reportBytes.subarray(0, 5).equals(Buffer.from('%PDF-'))
    || !reportResponse.headers()['content-disposition']?.includes('attachment')) {
    throw new Error(`Official workshop report download failed integrity/private checks: ${JSON.stringify({ status: reportResponse.status(), reportCache, bytes: reportBytes.length })}`);
  }
  checks.push({ supervisor_verification_and_private_report: { document_id: state.verification.document_id, bytes: reportBytes.length, sha256: crypto.createHash('sha256').update(reportBytes).digest('hex') } });

  await dispatcher.goto(base + `/workshops/jobs/${jobId}?tab=delivery`);
  await setModel(dispatcher, 'delivery.recipient', 'مندوب العميل محمود حسن');
  await setModel(dispatcher, 'delivery.accessories', 'مفتاح التشغيل فقط؛ الكتيب لم يعد مع المعدة.');
  await setModel(dispatcher, 'delivery.final_condition', 'القاطع يعمل واجتاز الاختبار المحدد.');
  await setModel(dispatcher, 'delivery.acknowledgment', 'أقر المندوب باستلام المعدة بالحالة والملحقات المبينة.');
  await setModel(dispatcher, 'delivery.delivered_at', cairoDateTime(-1));
  const deliveryForm = dispatcher.locator('form[wire\\:submit="deliver"]').first();
  await deliveryForm.waitFor({ state: 'visible' });
  const unrepaired = deliveryForm.locator('[wire\\:model="delivery.unrepaired"]');
  if (await unrepaired.isChecked()) await unrepaired.uncheck();
  await submit(dispatcher, 'deliver');
  state = await readback({ intakeId, jobId });
  if (state.job.status !== 'delivered' || state.intake.status !== 'delivered' || !state.delivery
    || state.delivery.recipient !== 'مندوب العميل محمود حسن' || state.delivery.unrepaired
    || state.delivery.accessories !== 'مفتاح التشغيل فقط؛ الكتيب لم يعد مع المعدة.') {
    throw new Error(`Customer handover readback mismatch: ${JSON.stringify({ job: state.job, intake: state.intake, delivery: state.delivery })}`);
  }
  checks.push({ customer_handover: { intake_status: state.intake.status, job_status: state.job.status, unrepaired: state.delivery.unrepaired } });
  await dispatcher.setViewportSize({ width: 1440, height: 900 });
  await dispatcher.screenshot({ path: path.join(runDir, 'delivery-desktop.png'), animations: 'disabled' });
  await checkMobile(dispatcher, 'delivery', 'مندوب العميل محمود حسن');

  const outsiderContext = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'ar-EG', timezoneId: 'Africa/Cairo' });
  const outsider = await outsiderContext.newPage(); observe(outsider);
  await login(outsider, fixture.actors.outsider);
  const deniedJob = await outsider.goto(base + `/workshops/jobs/${jobId}`);
  const deniedAttachment = await outsiderContext.request.get(base + `/workshops/documents/${uploadedDocument.id}`);
  const deniedReport = await outsiderContext.request.get(base + `/workshops/jobs/${jobId}/report`);
  if (deniedJob?.status() !== 403 || deniedAttachment.status() !== 403 || deniedReport.status() !== 403) {
    throw new Error(`Out-of-scope user accessed M09 records or evidence: ${JSON.stringify({ job: deniedJob?.status(), attachment: deniedAttachment.status(), report: deniedReport.status() })}`);
  }
  checks.push({ branch_scope_denials: { job: deniedJob.status(), attachment: deniedAttachment.status(), report: deniedReport.status() } });

  state = await readback({ intakeId, jobId });
  if (state.inventory.movements !== fixture.inventory_before.movements || state.inventory.fifo_layers !== fixture.inventory_before.fifo_layers
    || state.inventory.balances.length !== fixture.inventory_before.balances) {
    throw new Error(`M09 no-stock browser flow changed Inventory: ${JSON.stringify({ before: fixture.inventory_before, after: state.inventory })}`);
  }
  if (browserErrors.length) throw new Error(`Browser emitted runtime errors: ${JSON.stringify(browserErrors.slice(0, 8))}`);

  const result = {
    result: 'PASS',
    database: 'erp_elec_new_test@127.0.0.1:55447',
    browser: 'Playwright Chromium/Edge',
    viewport_sizes: [1440, 390],
    workflow: {
      workshop_id: fixture.workshop_id,
      intake_id: intakeId,
      intake_number: state.intake.number,
      job_id: jobId,
      job_number: state.job.number,
      final_status: state.job.status,
      custody_events: state.custody_events,
      estimates: state.estimates.length,
      estimate_approval_count: state.approvals.length,
      work_record_count: state.work_records,
      test_outcomes: state.tests.map(test => test.outcome),
      verified_report_bytes: reportBytes.length,
      attachment_sha256: uploadedDocument.sha256,
      delivery_recipient: state.delivery.recipient,
      delivered_accessories: state.delivery.accessories,
    },
    inventory: { before: fixture.inventory_before, after: { movements: state.inventory.movements, fifo_layers: state.inventory.fifo_layers, balance_rows: state.inventory.balances.length } },
    checks,
    browser_errors: browserErrors,
  };
  fs.writeFileSync(path.join(runDir, 'result.json'), JSON.stringify(result, null, 2));
  process.stdout.write(`M09 browser PASS: ${runDir}\n${JSON.stringify(result, null, 2)}\n`);
}

main().then(async () => {
  await browser?.close();
  if (server && server.exitCode === null) server.kill('SIGTERM');
  if (serverLog !== undefined) fs.closeSync(serverLog);
}).catch(async error => {
  const diagnostic = { message: error.message, stack: String(error.stack || '').split('\n').slice(0, 12) };
  try {
    await browser?.close();
    if (server && server.exitCode === null) server.kill('SIGTERM');
    if (serverLog !== undefined) fs.closeSync(serverLog);
  } catch {}
  try {
    const state = await phpJson({ PHASE5_WORKSHOP_FIXTURE_MODE: 'readback' });
    diagnostic.readback = { database: state.database, port: state.port, inventory: state.inventory };
  } catch (readError) { diagnostic.readback_error = String(readError.message).slice(0, 500); }
  fs.writeFileSync(path.join(runDir, 'failure.redacted.json'), JSON.stringify(diagnostic, null, 2));
  process.stderr.write(`${error.stack || error.message}\nEvidence: ${runDir}\n`);
  process.exitCode = 1;
});
