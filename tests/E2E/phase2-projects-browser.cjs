'use strict';

// Real M07 acceptance on an isolated disposable PostgreSQL clone; app code is not changed here.
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const crypto = require('node:crypto');
const net = require('node:net');
const { spawn, spawnSync } = require('node:child_process');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '../..');
const router = path.join(root, 'tests/E2E/support/laravel-static-router.php');
const fixturePath = path.join(root, 'tests/E2E/support/prepare-phase2-projects-browser.php');
const postingFixturePath = path.join(root, 'tests/E2E/support/finalize-phase2-projects-browser-sources.php');
const outputRoot = path.join(root, 'docs/qa/evidence/phase2-projects-browser');
const dbPort = Number(process.env.PHASE2_BROWSER_DB_PORT || 55435);
const diagCloseOnly = process.env.PHASE2_E2E_DIAG_CLOSE === '1';
const port = crypto.randomInt(8901, 9500);
const base = `http://127.0.0.1:${port}`;
const tag = crypto.randomBytes(5).toString('hex').toUpperCase();
const runDir = path.join(outputRoot, tag);
const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'erp-phase2-projects-browser-'));
let storage = path.join(root, '.tmp/phase2-projects-browser', tag);
const edge = process.env.PLAYWRIGHT_EXECUTABLE_PATH || 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe';
fs.mkdirSync(runDir, { recursive: true });
fs.mkdirSync(path.join(temp, 'views'), { recursive: true });
const env = { ...process.env, APP_ENV: 'testing', APP_URL: base, APP_DEBUG: 'false',
  APP_CONFIG_CACHE: path.join(temp, 'config.php'), APP_ROUTES_CACHE: path.join(temp, 'routes.php'),
  DB_CONNECTION: 'pgsql', DB_HOST: '127.0.0.1', DB_PORT: String(dbPort), DB_DATABASE: 'erp_elec_new_test', DB_URL: '',
  LARAVEL_STORAGE_PATH: storage, PHASE2_STORAGE_ROOT: storage, PHASE2_BROWSER_DB_PORT: String(dbPort),
  SESSION_DRIVER: 'file', CACHE_STORE: 'array', QUEUE_CONNECTION: 'sync', MAIL_MAILER: 'array', LOG_CHANNEL: 'stderr',
  BROADCAST_CONNECTION: 'null', VIEW_COMPILED_PATH: path.join(temp, 'views') };
const checks = [];
const browserErrors = [];
const livewireTraffic = [];
let lastLivewireAction = null;
let browser;
let server;
let serverLog;
let page;
let fixture;
let postedSources;

function phpJson(scriptPath, extraEnv = {}) {
  const result = spawnSync('php', ['-d', 'opcache.enable_cli=0', '-d', 'memory_limit=512M', scriptPath], { cwd: root, env: { ...env, ...extraEnv }, encoding: 'utf8', windowsHide: true, maxBuffer: 4 * 1024 * 1024 });
  if (result.status !== 0) throw new Error(`${path.basename(scriptPath)} failed: ${(result.stderr || result.stdout || '').slice(-2500)}`);
  try { return JSON.parse(result.stdout); } catch { throw new Error(`${path.basename(scriptPath)} returned no JSON evidence: ${(result.stderr || result.stdout || '').slice(0, 1000)}`); }
}
async function assertPortFree(value) {
  await new Promise((resolve, reject) => {
    const probe = net.createServer();
    probe.once('error', error => reject(new Error(error.code === 'EADDRINUSE' ? `Harness port ${value} is occupied.` : error.message)));
    probe.listen(value, '127.0.0.1', () => probe.close(resolve));
  });
}
async function login(actor, password, target = page) {
  await target.goto(base + '/login');
  await target.locator('input[name="email"]').fill(actor.email);
  await target.locator('input[name="password"]').fill(password);
  await target.locator('[data-test="login-button"]').click();
  await target.waitForURL(url => url.pathname === '/dashboard');
}
function observe(target) {
  target.on('pageerror', error => browserErrors.push({ kind: 'pageerror', url: target.url(), last_livewire_action: lastLivewireAction,
    message: String(error.stack || error.message).slice(0, 1200) }));
  target.on('console', message => { if (message.type() === 'error') browserErrors.push({ kind: 'console', url: target.url(),
    last_livewire_action: lastLivewireAction, message: message.text().slice(0, 300) }); });
  target.on('response', response => {
    if (response.status() >= 500) browserErrors.push({ kind: 'http', url: target.url(), message: `HTTP ${response.status()} ${new URL(response.url()).pathname}`.slice(0, 300) });
    if (/\/livewire(?:-[^/]+)?\/update/.test(response.url())) livewireTraffic.push({ status: response.status(), method: 'POST',
      url: target.url(), action: lastLivewireAction });
  });
  target.on('request', request => {
    if (/\/livewire(?:-[^/]+)?\/update/.test(request.url())) {
      let methods = [];
      try {
        const payload = JSON.parse(request.postData() || '{}');
        methods = (payload.components || []).flatMap(component => component.calls || [])
          .map(call => ({ method: String(call.method || call.path || 'unknown'),
            state: call.method === 'changeLifecycle' && Array.isArray(call.params) ? String(call.params[0] ?? '').slice(0, 32) : undefined })).slice(0, 8);
      } catch {}
      lastLivewireAction = { url: new URL(request.url()).pathname, methods };
      livewireTraffic.push({ request: lastLivewireAction.url, method: request.method(), action: lastLivewireAction });
    } else if (/livewire/i.test(request.url())) livewireTraffic.push({ request: new URL(request.url()).pathname, method: request.method() });
  });
  target.on('requestfailed', request => {
    if (/\/livewire(?:-[^/]+)?\/update/.test(request.url())) livewireTraffic.push({ failure: request.failure()?.errorText || 'failed',
      url: target.url(), action: lastLivewireAction });
  });
}
const model = key => `[wire\\:model${key.startsWith('.') ? '' : ''}="${key}"]`;
async function setModel(target, key, value) {
  const locator = target.locator(model(key));
  await locator.first().fill(String(value));
}
async function setSelect(target, key, value) {
  const locator = target.locator(`[wire\\:model="${key}"], [wire\\:model\\.live="${key}"], [wire\\:model\\.live\\.debounce\\.300ms="${key}"]`).first();
  await locator.selectOption(String(value));
}
async function clickAction(target, startsWith) {
  const locator = target.locator(`button[wire\\:click^="${startsWith}"]`).first();
  await locator.waitFor({ state: 'visible' });
  return clickLivewireAndWait(target, locator, `Action ${startsWith}`);
}
async function clickLivewireAndWait(target, locator, label) {
  const responsePromise = target.waitForResponse(response => /\/livewire(?:-[^/]+)?\/update/.test(response.url()) && response.request().method() === 'POST', { timeout: 15000 });
  await locator.click();
  const response = await responsePromise;
  if (response.status() !== 200) throw new Error(`${label} Livewire action returned HTTP ${response.status()}.`);
  return response.status();
}
async function pageState(pathname, viewport, text, label) {
  await page.setViewportSize(viewport);
  const response = await page.goto(base + pathname, { waitUntil: 'networkidle' });
  if (!response || response.status() !== 200) throw new Error(`${pathname} returned HTTP ${response?.status() ?? 'no response'}.`);
  if (await page.locator('html').getAttribute('dir') !== 'rtl') throw new Error(`${pathname} lost RTL direction.`);
  const body = await page.locator('body').innerText();
  if (text && !body.includes(text)) throw new Error(`${pathname} did not render expected value: ${text}`);
  const metrics = await page.evaluate(() => ({ viewport: innerWidth, document: document.documentElement.scrollWidth, body: document.body.scrollWidth }));
  if (metrics.document > metrics.viewport + 4) throw new Error(`${pathname} overflows horizontally at ${metrics.viewport}px: ${metrics.document}px.`);
  const safe = label || pathname.replaceAll('/', '-').replace(/^-/, '') || 'home';
  await page.screenshot({ path: path.join(runDir, `${safe}-${viewport.width}.png`), animations: 'disabled' });
  checks.push({ page: pathname, viewport: viewport.width, expected_text: text || null, ...metrics });
  return body;
}

async function main() {
  const actorPassword = crypto.randomBytes(24).toString('base64url');
  await assertPortFree(port);
  if (process.env.PHASE2_CREATE_COMMITTED_FIXTURE === '1') {
    const financeEvidencePath = path.join(outputRoot, `finance-${dbPort}-${tag}.json`);
    const financeStorage = path.join(root, '.tmp/phase2-projects-browser', `finance-${dbPort}-${tag}`);
    fs.mkdirSync(financeStorage, { recursive: true });
    const committed = phpJson(path.join(root, 'tests/Support/check-phase2-projects-financial.php'), {
      PHASE2_COMMIT_FIXTURES: '1', PHASE2_BROWSER_PASSWORD: actorPassword,
      PHASE2_STORAGE_ROOT: financeStorage, PHASE2_EVIDENCE_PATH: financeEvidencePath });
    if (!committed.all_pass || !committed.project_fixture?.committed) {
      throw new Error(`Fresh PostgreSQL financial action fixture did not commit cleanly: ${JSON.stringify(committed)}`);
    }
  }
  fixture = phpJson(fixturePath, { PHASE2_E2E_SET_ACTOR_PASSWORD: '1', PHASE2_BROWSER_PASSWORD: actorPassword });
  if (fixture.existing_browser_project?.status === 'closed') {
    const documentRun = String(fixture.existing_browser_project.document_number || '').match(/^DOC-([A-F0-9]{10})$/)?.[1];
    if (!documentRun) throw new Error('Closed project document is not tied to a known isolated browser storage run.');
    storage = path.join(root, '.tmp/phase2-projects-browser', documentRun);
    if (!fs.existsSync(storage)) throw new Error('Closed project private document storage is missing from its isolated run.');
    env.LARAVEL_STORAGE_PATH = storage;
    env.PHASE2_STORAGE_ROOT = storage;
  }
  const financeEvidence = fixture.finance_evidence;
  if (fixture.database !== 'erp_elec_new_test' || fixture.port !== dbPort || fixture.host !== '127.0.0.1') throw new Error('Fixture returned unexpected database identity.');
  if (!financeEvidence?.source_fixture_readback || fixture.project.id !== financeEvidence.project_fixture.project_id || fixture.actor.id !== financeEvidence.project_fixture.manager_id) {
    throw new Error('Read-only source graph and finance projection readback did not match.');
  }
  if ((!fixture.existing_browser_project && !diagCloseOnly && fixture.linkable_sales_order?.status !== 'draft')
    || !fixture.linkable_purchase_order?.order_id || !fixture.linkable_purchase_order?.order_line_id || !fixture.linkable_purchase_order?.receipt_line_id) {
    throw new Error('Unlinked Sales draft / received PO fixture is missing or belongs to another isolated database.');
  }
  fixture.linkable_sales_order_id = fixture.linkable_sales_order.id;
  fixture.linkable_sales_order_line_id = fixture.linkable_sales_order.line_id;
  serverLog = fs.openSync(path.join(runDir, 'server.log.redacted.txt'), 'w');
  // Keep the disposable web process out of Laravel's unit-test upload fake so Livewire uses this run's private local disk.
  server = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', '.', router], { cwd: path.join(root, 'public'), env: { ...env, APP_ENV: 'local' },
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
  await login(fixture.actor, actorPassword);
  checks.push({ login: 'committed synthetic project manager', user_id: fixture.actor.id, result: 'passed' });
  if (fixture.existing_browser_project?.status === 'closed') {
    const projectId = fixture.existing_browser_project.id;
    const projectName = fixture.existing_browser_project.name;
    await pageState(`/projects/${projectId}/financial`, { width: 1440, height: 900 }, '1234.50', 'closed-project-financial-desktop');
    await pageState(`/projects/${projectId}/tasks`, { width: 390, height: 844 }, fixture.existing_browser_project.task_title, 'closed-project-tasks-mobile');
    await pageState(`/projects/${projectId}/closure`, { width: 1440, height: 900 }, projectName, 'closed-project-handover-desktop');
    const exportEvidence = {};
    for (const format of ['xlsx', 'pdf']) {
      const response = await page.context().request.get(`${base}/projects/${projectId}/financial/${format}`);
      const bytes = await response.body();
      const cacheControl = response.headers()['cache-control'] || '';
      const contentType = response.headers()['content-type'] || '';
      const valid = response.status() === 200 && cacheControl.includes('private') && cacheControl.includes('no-store')
        && (format === 'pdf' ? contentType.includes('application/pdf') && bytes.subarray(0, 5).toString() === '%PDF-'
          : contentType.includes('spreadsheet') && bytes.subarray(0, 2).toString() === 'PK');
      if (!valid) throw new Error(`Closed project ${format} export failed private/authenticated integrity check.`);
      exportEvidence[format] = { status: response.status(), content_type: contentType, cache_control: cacheControl, bytes: bytes.length };
    }
    await page.goto(base + `/projects/${projectId}/documents`, { waitUntil: 'networkidle' });
    const documentRow = page.locator('tr').filter({ hasText: fixture.existing_browser_project.document_number }).first();
    const documentHref = await documentRow.locator('a').getAttribute('href');
    if (!documentHref) throw new Error('Closed project private approved document has no authorized revision link.');
    const authorizedDocument = await page.context().request.get(new URL(documentHref, base).toString());
    const documentBytes = await authorizedDocument.body();
    if (authorizedDocument.status() !== 200 || documentBytes.subarray(0, 5).toString() !== '%PDF-'
      || !(authorizedDocument.headers()['cache-control'] || '').includes('private')) throw new Error(`Closed project authorized private PDF revision failed integrity/cache validation: HTTP ${authorizedDocument.status()} cache=${authorizedDocument.headers()['cache-control']} content-type=${authorizedDocument.headers()['content-type']} signature=${documentBytes.subarray(0, 8).toString('hex')}.`);
    const deniedContext = await browser.newContext({ locale: 'ar-EG' });
    const deniedPage = await deniedContext.newPage();
    await login(fixture.denied_actor, 'password', deniedPage);
    const deniedPdf = await deniedPage.request.get(new URL(documentHref, base).toString());
    const deniedExports = {};
    for (const format of ['xlsx', 'pdf']) deniedExports[format] = (await deniedPage.request.get(`${base}/projects/${projectId}/financial/${format}`)).status();
    await deniedContext.close();
    if (deniedPdf.status() !== 403 || deniedExports.xlsx !== 403 || deniedExports.pdf !== 403) throw new Error('Out-of-scope actor received a closed-project document/export.');
    const finalState = phpJson(fixturePath, { PHASE2_E2E_PROJECT_ID: String(projectId) }).browser_project_readback;
    if (finalState?.status !== 'closed' || !finalState.issue?.id || finalState.issue.fifo_allocations < 1
      || finalState.document?.status !== 'approved' || !finalState.task_ids?.includes(Number(finalState.document.task_id))
      || finalState.handovers < 1 || finalState.baseline?.approved_by !== fixture.reviewer.id) {
      throw new Error(`Closed project PostgreSQL evidence is incomplete: ${JSON.stringify(finalState)}`);
    }
    checks.push({ resumed_closed_project_evidence: true, project_id: projectId, exports: exportEvidence,
      denied_download: deniedPdf.status(), denied_exports: deniedExports, authorized_pdf_bytes: documentBytes.length,
      task_document_id: finalState.document.task_id, material_issue_id: finalState.issue.id,
      fifo_allocations: finalState.issue.fifo_allocations, status: finalState.status });
    if (browserErrors.length || livewireTraffic.some(row => row.status >= 500 || row.failure)) throw new Error(`Browser errors: ${JSON.stringify({ browserErrors, livewireTraffic })}`);
    const result = { result: 'PASS', run_id: tag, database: `${fixture.database} on ${fixture.host}:${fixture.port}`,
      browser: 'Microsoft Edge via Playwright', viewport_sizes: [1440, 390], project: { id: projectId, name: projectName, status: finalState.status },
      source_financial_projection: financeEvidence.project_financial_projection, final_postgresql_state: finalState,
      checks, browser_errors: browserErrors, livewire_traffic: livewireTraffic };
    fs.writeFileSync(path.join(runDir, 'result.json'), JSON.stringify(result, null, 2) + '\n');
    fs.writeFileSync(path.join(outputRoot, 'latest.json'), JSON.stringify(result, null, 2) + '\n');
    process.stdout.write(`Evidence: ${runDir}\n`);
    return;
  }

  let projectId;
  let code;
  let projectName;
  const resume = fixture.existing_browser_project || null;
  if (!resume) {
  // Create through the real Livewire form, then reopen and edit through the same route.
  if (diagCloseOnly) await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(base + '/projects/create', { waitUntil: 'networkidle' });
  code = `PB2-${tag}`;
  projectName = `اختبار مشروع المتصفح ${tag}`;
  await setModel(page, 'form.code', code);
  await setModel(page, 'form.name', projectName);
  await setModel(page, 'form.project_type', 'اختبار قبول M07');
  await setSelect(page, 'form.priority', 'high');
  await setSelect(page, 'form.branch_id', fixture.project.branch_id);
  const customerSearch = page.locator('[wire\\:model\\.live\\.debounce\\.300ms="customerSearch"]');
  await customerSearch.fill(fixture.project.customer_name);
  await page.locator(`[wire\\:model="form.customer_id"] option[value="${fixture.project.customer_id}"]`).waitFor({ state: 'attached' });
  await setSelect(page, 'form.customer_id', fixture.project.customer_id);
  await setSelect(page, 'form.manager_id', fixture.actor.id);
  await setModel(page, 'form.start_date', new Date().toISOString().slice(0, 10));
  await setModel(page, 'form.end_date', new Date(Date.now() + 86400000 * 45).toISOString().slice(0, 10));
  await setModel(page, 'form.description', 'مشروع اختبار متصفح مع روابط مصادر فعلية.');
  await setModel(page, 'form.notes', 'بيانات اصطناعية على نسخة PostgreSQL معزولة.');
  await page.locator('form[wire\\:submit="save"] button[type="submit"]').click();
  await page.waitForURL(/\/projects\/\d+$/);
  projectId = Number(new URL(page.url()).pathname.split('/').pop());
  await page.getByText(projectName, { exact: false }).first().waitFor();
  await page.goto(base + `/projects/${projectId}`, { waitUntil: 'networkidle' });
  await setModel(page, 'form.name', `${projectName} — تعديل محفوظ`);
  await setModel(page, 'editReason', 'تصحيح وصف ضمن اختبار المتصفح المعزول.');
  await clickAction(page, 'saveMetadata');
  await page.getByText(`${projectName} — تعديل محفوظ`, { exact: false }).waitFor({ timeout: 10000 });
  checks.push({ project_create_save_reopen_edit: 'passed', project_id: projectId, code, saved_name: `${projectName} — تعديل محفوظ` });

  // Persist phase/task/milestone via actual browser forms.
  await page.goto(base + `/projects/${projectId}/tasks`, { waitUntil: 'networkidle' });
  const start = new Date().toISOString().slice(0, 10);
  const end = new Date(Date.now() + 86400000 * 30).toISOString().slice(0, 10);
  await setModel(page, 'phase.title', `مرحلة تنفيذ ${tag}`);
  await setModel(page, 'phase.start_date', start);
  await setModel(page, 'phase.end_date', end);
  await page.locator('form[wire\\:submit="addPhase"] button[type="submit"]').click();
  await page.getByText(`مرحلة تنفيذ ${tag}`, { exact: false }).last().waitFor();
  const phaseOption = await page.locator('[wire\\:model="task.phase_id"] option').nth(1).getAttribute('value');
  await setModel(page, 'task.title', `مهمة اختبار ${tag}`);
  await setSelect(page, 'task.phase_id', phaseOption);
  await setSelect(page, 'task.assigned_to', fixture.actor.id);
  await setModel(page, 'task.start_date', start);
  await setModel(page, 'task.end_date', end);
  await setModel(page, 'task.notes', 'تنفيذ مهمة اختبار متصفح محفوظة.');
  await page.locator('form[wire\\:submit="addTask"] button[type="submit"]').click();
  await page.getByText(`مهمة اختبار ${tag}`, { exact: false }).first().waitFor();
  await setModel(page, 'milestone.title', `معلم قبول ${tag}`);
  await setModel(page, 'milestone.target_date', end);
  await setSelect(page, 'milestone.responsible_id', fixture.actor.id);
  await page.locator('form[wire\\:submit="addMilestone"] button[type="submit"]').click();
  await page.getByText(`معلم قبول ${tag}`, { exact: false }).waitFor();
  checks.push({ browser_created_phase_task_milestone: 'passed', project_id: projectId });

  // Propose the budget as manager and accept it in a separate authenticated reviewer context.
  await page.goto(base + `/projects/${projectId}/budget`, { waitUntil: 'networkidle' });
  await setModel(page, 'form.currency', 'EGP');
  await setModel(page, 'form.total', '1234.50');
  await setModel(page, 'form.scope', 'ميزانية قبول المتصفح المستقلة');
  await setModel(page, 'form.categories.0.name', 'مواد كهربائية');
  await setModel(page, 'form.categories.0.amount', '1234.50');
  await setModel(page, 'reason', 'طلب خط أساس ضمن اختبار المتصفح.');
  await page.locator('form[wire\\:submit="propose"] button[type="submit"]').click();
  await page.locator('p').filter({ hasText: '1234.50' }).first().waitFor();
  const reviewerContext = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'ar-EG', timezoneId: 'Africa/Cairo' });
  const reviewerPage = await reviewerContext.newPage();
  reviewerPage.setDefaultTimeout(15000);
  observe(reviewerPage);
  await login(fixture.reviewer, 'password', reviewerPage);
  await reviewerPage.goto(base + `/projects/${projectId}/budget`, { waitUntil: 'networkidle' });
  await setModel(reviewerPage, 'decisionReason', 'اعتماد مستقل لخط الأساس في اختبار القبول.');
  await clickAction(reviewerPage, 'decide(');
  await reviewerPage.locator('p').filter({ hasText: '1234.50' }).first().waitFor();
  const budgetDb = spawnSync('php', ['artisan', 'tinker', '--execute', `dump(DB::table('project_baselines')->where('project_id', ${projectId})->orderByDesc('revision')->value('total'));`],
    { cwd: root, env, encoding: 'utf8', windowsHide: true });
  // The independent browser approval is proven by the resulting baseline shown on the budget page.
  await reviewerContext.close();
  checks.push({ independent_budget_approval: 'passed', project_id: projectId, reviewer_id: fixture.reviewer.id,
    baseline_visible: true, database_probe_exit: budgetDb.status });
  await page.goto(base + `/projects/${projectId}`, { waitUntil: 'networkidle' });
  await setModel(page, 'lifecycleReason', 'بدء التنفيذ بعد اعتماد خط الأساس المستقل.');
  await clickAction(page, "changeLifecycle('planned')");
  await clickAction(page, "changeLifecycle('active')");
  checks.push({ independent_budget_gated_planned_and_active_lifecycle: 'passed', project_id: projectId });
  if (diagCloseOnly) {
    await page.setViewportSize({ width: 390, height: 844 });
    await pageState(`/projects/${projectId}/tasks`, { width: 390, height: 844 }, `مهمة اختبار ${tag}`, 'diag-mobile-tasks');
    await setModel(page, 'taskReason', 'Mobile closure diagnostic: task completed through the browser.');
    const taskStart = page.locator('button[wire\\:click*="taskStatus("][wire\\:click*="in_progress"]').first();
    await clickLivewireAndWait(page, taskStart, 'Diagnostic task start');
    const taskComplete = page.locator('button[wire\\:click*="taskStatus("][wire\\:click*="completed"]').first();
    await clickLivewireAndWait(page, taskComplete, 'Diagnostic task completion');
    let diagReadback = phpJson(fixturePath, { PHASE2_E2E_PROJECT_ID: String(projectId) }).browser_project_readback;
    if (diagReadback?.task_states?.[0]?.status !== 'completed') throw new Error(`Diagnostic mobile task DB state did not persist: ${JSON.stringify(diagReadback?.task_states)}`);
    await page.goto(base + `/projects/${projectId}/tasks`, { waitUntil: 'networkidle' });
    await setModel(page, 'evidence', 'Mobile closure diagnostic: required milestone completed.');
    await clickLivewireAndWait(page, page.locator('button[wire\\:click^="completeMilestone("]').first(), 'Diagnostic milestone completion');
    diagReadback = phpJson(fixturePath, { PHASE2_E2E_PROJECT_ID: String(projectId) }).browser_project_readback;
    if (diagReadback?.milestone_states?.[0]?.status !== 'completed') throw new Error(`Diagnostic mobile milestone DB state did not persist: ${JSON.stringify(diagReadback?.milestone_states)}`);
    await page.goto(base + `/projects/${projectId}/closure`, { waitUntil: 'networkidle' });
    await setModel(page, 'evidence', `Mobile closure diagnostic handover ${tag}`);
    const handoverWait = page.waitForResponse(response => /\/livewire(?:-[^/]+)?\/update/.test(response.url()), { timeout: 15000 });
    await page.locator('form[wire\\:submit="handover"] button[type="submit"]').click();
    const handoverResponse = await handoverWait;
    if (handoverResponse.status() !== 200) throw new Error(`Diagnostic mobile handover returned HTTP ${handoverResponse.status()}.`);
    await page.getByText(`Mobile closure diagnostic handover ${tag}`, { exact: false }).waitFor();
    await page.goto(base + `/projects/${projectId}`, { waitUntil: 'networkidle' });
    await setModel(page, 'lifecycleReason', 'Mobile closure diagnostic execution complete.');
    await clickAction(page, "changeLifecycle('completed')");
    await page.goto(base + `/projects/${projectId}/closure`, { waitUntil: 'networkidle' });
    await setModel(page, 'reason', 'Mobile closure diagnostic: all obligations are clear.');
    const closeWait = page.waitForResponse(response => /\/livewire(?:-[^/]+)?\/update/.test(response.url()), { timeout: 15000 });
    await page.locator('form[wire\\:submit="close"] button[type="submit"]').click();
    const closeResponse = await closeWait;
    if (closeResponse.status() !== 200) throw new Error(`Diagnostic mobile close returned HTTP ${closeResponse.status()}: ${(await closeResponse.text()).slice(0, 800)}`);
    await page.locator('form[wire\\:submit="close"]').waitFor({ state: 'detached' });
    const finalReadback = phpJson(fixturePath, { PHASE2_E2E_PROJECT_ID: String(projectId) }).browser_project_readback;
    if (finalReadback?.status !== 'closed' || finalReadback.handovers < 1) throw new Error(`Diagnostic close DB state did not persist: ${JSON.stringify(finalReadback)}`);
    checks.push({ mobile_create_reopen_edit_and_project_close: 'persisted', project_id: projectId,
      task_status: finalReadback.task_states?.[0]?.status, milestone_status: finalReadback.milestone_states?.[0]?.status,
      handovers: finalReadback.handovers, status: finalReadback.status, close_http_status: closeResponse.status() });
    if (browserErrors.length || livewireTraffic.some(row => row.status >= 500 || row.failure)) throw new Error(`Browser/Livewire errors: ${JSON.stringify({ browserErrors, livewireTraffic })}`);
    const result = { result: 'PASS', mode: 'isolated close-morph diagnostic', run_id: tag,
      database: `${fixture.database} on ${fixture.host}:${fixture.port}`, browser: 'Microsoft Edge via Playwright',
      viewport_sizes: [390], project: { id: projectId, code, name: projectName }, final_postgresql_state: finalReadback,
      checks, browser_errors: browserErrors, livewire_traffic: livewireTraffic };
    fs.writeFileSync(path.join(runDir, 'result.json'), JSON.stringify(result, null, 2) + '\n');
    process.stdout.write(`Evidence: ${runDir}\n`);
    return;
  }
  } else {
    projectId = resume.id;
    checks.push({ resumed_existing_authenticated_browser_project: 'passed', project_id: projectId,
      create_edit: true, phase_task_milestone: true, independent_budget_approval: true });
    await page.goto(base + `/projects/${projectId}/operations`, { waitUntil: 'networkidle' });
  }

  // Link a real Sales draft and received PO through the Projects UI.
  if (!resume) await page.goto(base + `/projects/${projectId}/operations`, { waitUntil: 'networkidle' });
  for (const [type, id] of (resume ? [] : [['sales_order', fixture.linkable_sales_order_id], ['purchase_order', fixture.linkable_purchase_order.order_id]])) {
    await setSelect(page, 'sourceType', type);
    await setModel(page, 'sourceId', id);
    await page.locator('form[wire\\:submit="link"] button[type="submit"]').click();
    await page.getByText(`${type} #${id}`, { exact: false }).waitFor();
  }
  // Create the material requirement and execute a real reservation and FIFO issue through the same page.
  if (!resume) {
  await setModel(page, 'material.product_id', fixture.product_id);
  await setModel(page, 'material.quantity', '1.00000000');
  await setModel(page, 'material.notes', 'احتياج مادة متصل بمنتج FIFO حقيقي.');
  await page.locator('form[wire\\:submit="addMaterial"] button[type="submit"]').click();
  await page.getByText('1.00000000', { exact: false }).waitFor();
  }
  const requirementId = resume ? resume.requirement_id : Number((await page.locator('table tbody tr').first().locator('td').first().innerText()).trim());
  if (!Number.isInteger(requirementId) || requirementId < 1) throw new Error('Browser material requirement did not persist a database identity.');
  let reservationId = resume?.posted_reservation_id || resume?.reservation_id || 0;
  if (!resume?.posted_issue_id) {
  await setSelect(page, 'reservation.requirement_id', requirementId);
  await setSelect(page, 'reservation.warehouse_id', fixture.warehouse_id);
  await setModel(page, 'reservation.quantity', '1.00000000');
  await page.locator('button[wire\\:click="checkAvailability"]').click();
  await page.waitForTimeout(1000);
  checks.push({ availability_debug: { requirement: await page.locator('[wire\\:model="reservation.requirement_id"]').inputValue(),
    warehouse: await page.locator('[wire\\:model="reservation.warehouse_id"]').inputValue(),
    button: await page.locator('button[wire\\:click="checkAvailability"]').evaluate(el => el.outerHTML),
    livewire: livewireTraffic.slice(-4) } });
  const expectedAvailable = resume?.reservation_id ? '1.00000000' : '2.00000000';
  await page.locator('p.mt-4').filter({ hasText: expectedAvailable }).first().waitFor();
  if (!reservationId) {
    const reserveResponsePromise = page.waitForResponse(response => /\/livewire(?:-[^/]+)?\/update/.test(response.url()), { timeout: 15000 });
    await page.locator('form[wire\\:submit="reserve"] button[type="submit"]').click();
    const reserveResponse = await reserveResponsePromise;
    if (reserveResponse.status() !== 200) throw new Error(`Project material reservation failed HTTP ${reserveResponse.status()}.`);
    const reservationReadback = phpJson(fixturePath, { PHASE2_E2E_PROJECT_ID: String(projectId) }).browser_project_readback;
    reservationId = Number(reservationReadback?.reservation_ids?.[0] || 0);
  }
  if (!reservationId) throw new Error('Browser material reservation did not expose a persisted issue identity.');
  }
  if (!resume?.posted_issue_id) {
    const issueContext = await browser.newContext({ viewport: { width: 1280, height: 800 }, locale: 'ar-EG', timezoneId: 'Africa/Cairo' });
    const issuePage = await issueContext.newPage();
    issuePage.setDefaultTimeout(15000);
    observe(issuePage);
    await login(fixture.reviewer, 'password', issuePage);
    await issuePage.goto(base + `/projects/${projectId}/operations`, { waitUntil: 'networkidle' });
    const issueButton = issuePage.locator(`button[wire\\:click="issue(${reservationId})"]`);
    await issueButton.waitFor({ state: 'visible' });
    await setModel(issuePage, `issueQuantities.${reservationId}`, '1.00000000');
    const issueResponseStart = livewireTraffic.length;
    const issueResponsePromise = issuePage.waitForResponse(response => /\/livewire(?:-[^/]+)?\/update/.test(response.url()), { timeout: 15000 });
    await issueButton.click();
    const response = await issueResponsePromise;
    const issueResponse = { status: response.status() };
    await issueContext.close();
    if (issueResponse?.status !== 200) throw new Error(`Independent inventory authorizer material issue failed: ${JSON.stringify(issueResponse || livewireTraffic.slice(issueResponseStart))}`);
  }
  const materialReadback = phpJson(fixturePath, { PHASE2_E2E_PROJECT_ID: String(projectId) }).browser_project_readback;
  if (!materialReadback?.issue?.id || materialReadback.issue.fifo_allocations < 1) throw new Error('Browser material issue did not persist a FIFO-backed project movement.');
  checks.push({ browser_linked_sales_and_purchase_order: 'passed', sales_order_id: fixture.linkable_sales_order_id,
    purchase_order_id: fixture.linkable_purchase_order.order_id, browser_material_reservation_and_fifo_issue: 'passed',
    project_id: projectId, requirement_id: requirementId, reservation_id: reservationId, issue_id: materialReadback.issue.id,
    fifo_allocations: materialReadback.issue.fifo_allocations, product_id: fixture.product_id });
  postedSources = phpJson(postingFixturePath, { PHASE2_E2E_PROJECT_ID: String(projectId),
    PHASE2_E2E_SALES_ORDER_ID: String(fixture.linkable_sales_order_id), PHASE2_E2E_SALES_LINE_ID: String(fixture.linkable_sales_order_line_id),
    PHASE2_E2E_WAREHOUSE_ID: String(fixture.warehouse_id) });
  if (postedSources.project_id !== projectId || postedSources.sales_order_id !== fixture.linkable_sales_order_id
    || postedSources.project_summary?.subledger?.unallocated_invoices !== 0 || postedSources.project_summary?.fifo_gl_difference !== '0.00') {
    throw new Error(`Owner source posting failed project attribution/FIFO proof: ${JSON.stringify(postedSources.project_summary)}`);
  }
  await page.goto(base + `/projects/${projectId}/financial`, { waitUntil: 'networkidle' });
  const newProjectFinancial = await page.locator('body').innerText();
  if (!newProjectFinancial.includes('1234.50') || !newProjectFinancial.includes('10.00') || !newProjectFinancial.includes('0.00')) {
    throw new Error('Browser financial page omitted approved budget, matched PO, or FIFO/GL reconciliation.');
  }
  checks.push({ browser_project_financial_summary: 'passed', project_id: projectId,
    recognized_cost: postedSources.project_summary.accounting.recognized_cost,
    recognized_revenue: postedSources.project_summary.accounting.recognized_revenue,
    grni_cleared: postedSources.project_summary.subledger.grni_cleared,
    fifo_gl_difference: postedSources.project_summary.fifo_gl_difference });
  const exportEvidence = {};
  for (const format of ['xlsx', 'pdf']) {
    const response = await page.context().request.get(`${base}/projects/${projectId}/financial/${format}`);
    const bytes = await response.body();
    const contentType = response.headers()['content-type'] || '';
    const cacheControl = response.headers()['cache-control'] || '';
    const valid = response.status() === 200 && cacheControl.includes('no-store')
      && (format === 'pdf' ? (contentType.includes('application/pdf') && bytes.subarray(0, 5).toString() === '%PDF-')
        : (contentType.includes('spreadsheet') && bytes.subarray(0, 2).toString() === 'PK'));
    if (!valid) throw new Error(`Authorized financial ${format} export did not return a private valid document: HTTP ${response.status()} ${contentType} cache=${cacheControl} bytes=${bytes.length} signature=${bytes.subarray(0, 5).toString('hex')}.`);
    exportEvidence[format] = { status: response.status(), content_type: contentType, cache_control: cacheControl,
      private: cacheControl.includes('private'), bytes: bytes.length };
  }
  const deniedExportContext = await browser.newContext({ locale: 'ar-EG' });
  const deniedExportPage = await deniedExportContext.newPage();
  await login(fixture.denied_actor, 'password', deniedExportPage);
  const deniedExportStatuses = {};
  for (const format of ['xlsx', 'pdf']) deniedExportStatuses[format] = (await deniedExportPage.request.get(`${base}/projects/${projectId}/financial/${format}`)).status();
  await deniedExportContext.close();
  if (deniedExportStatuses.xlsx !== 403 || deniedExportStatuses.pdf !== 403) throw new Error(`Out-of-scope actor financial export should be forbidden: ${JSON.stringify(deniedExportStatuses)}`);
  checks.push({ scoped_financial_exports: exportEvidence, denied_actor_statuses: deniedExportStatuses });
  if (Object.values(exportEvidence).some(item => !item.private)) throw new Error(`Financial exports must use private no-store caching: ${JSON.stringify(exportEvidence)}`);

  // The committed finance source project proves actual owner-attributed Sales, PO, issue and FIFO/GL projection.
  await pageState(`/projects/${fixture.project.id}/financial`, { width: 1440, height: 900 }, fixture.project.name, 'source-project-financial-desktop');
  const sourceFinancialText = await page.locator('body').innerText();
  if (!sourceFinancialText.includes('50.00') || !sourceFinancialText.includes('0.00')) throw new Error('Source project financial projection omitted fixture received/FIFO reconciliation amounts.');
  const operationsText = await pageState(`/projects/${fixture.project.id}/operations`, { width: 1440, height: 900 }, fixture.project.name, 'source-project-operations-desktop');
  for (const id of [fixture.sales_order_ids[0], ...fixture.purchase_order_ids.slice(0, 2)]) {
    if (!operationsText.includes(`#${id}`)) throw new Error(`Closed source project did not render its persisted Sales/PO link #${id}.`);
  }
  await pageState(`/projects/${fixture.project.id}/financial`, { width: 390, height: 844 }, fixture.project.name, 'source-project-financial-mobile');
  await pageState(`/projects/${projectId}/tasks`, { width: 390, height: 844 }, resume?.task_title || `مهمة اختبار ${tag}`, 'new-project-tasks-mobile');
  await pageState(`/projects/${projectId}/budget`, { width: 1440, height: 900 }, '1234.50', 'new-project-budget-desktop');

  // Save and independently approve a private project file, then prove its download is authorization protected.
  await page.goto(base + `/projects/${projectId}/documents`, { waitUntil: 'networkidle' });
  await setModel(page, 'form.number', `DOC-${tag}`);
  await setModel(page, 'form.title', `مستند قبول ${tag}`);
  await setModel(page, 'form.classification', 'handover');
  const documentTaskSelect = page.locator('[wire\\:model="form.task_id"]');
  if (await documentTaskSelect.count()) {
    const documentTaskId = await documentTaskSelect.locator('option').nth(1).getAttribute('value');
    if (documentTaskId) await setSelect(page, 'form.task_id', documentTaskId);
  }
  await page.locator('[wire\\:model="form.required"]').check();
  await page.locator('input[type="file"]').setInputFiles({ name: `phase2-${tag}.pdf`, mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n') });
  await page.locator('form[wire\\:submit="saveDocument"] button[type="submit"]').click();
  await page.getByText(`DOC-${tag}`, { exact: false }).waitFor();
  await setModel(page, 'reason', 'إرسال المستند لمراجعة مستقلة.');
  await clickAction(page, 'decide(');
  await page.getByText('قيد المراجعة', { exact: false }).waitFor();
  const reviewerDocs = await browser.newContext({ viewport: { width: 1280, height: 800 }, locale: 'ar-EG', timezoneId: 'Africa/Cairo' });
  const reviewerDocsPage = await reviewerDocs.newPage();
  reviewerDocsPage.setDefaultTimeout(15000);
  observe(reviewerDocsPage);
  await login(fixture.reviewer, 'password', reviewerDocsPage);
  await reviewerDocsPage.goto(base + `/projects/${projectId}/documents`, { waitUntil: 'networkidle' });
  await setModel(reviewerDocsPage, 'reason', 'تمت مراجعة ملف مستند التسليم.');
  await clickAction(reviewerDocsPage, 'decide(');
  await reviewerDocsPage.getByText('معتمد', { exact: false }).first().waitFor();
  const documentRow = page.locator('tr').filter({ hasText: `DOC-${tag}` }).first();
  const downloadPath = await documentRow.locator('a').getAttribute('href');
  if (!downloadPath) throw new Error('Uploaded project document did not expose its authorized revision link.');
  const authorizedDownload = await page.request.get(new URL(downloadPath, base).toString());
  const pdfBytes = await authorizedDownload.body();
  if (authorizedDownload.status() !== 200 || pdfBytes.subarray(0, 5).toString('ascii') !== '%PDF-' || authorizedDownload.headers()['cache-control']?.includes('private') !== true) {
    throw new Error(`Authorized private project document failed integrity/auth headers: HTTP ${authorizedDownload.status()}.`);
  }
  const deniedContext = await browser.newContext({ viewport: { width: 390, height: 844 }, locale: 'ar-EG', timezoneId: 'Africa/Cairo' });
  const deniedPage = await deniedContext.newPage();
  observe(deniedPage);
  await login(fixture.denied_actor, 'password', deniedPage);
  const deniedDownload = await deniedPage.request.get(new URL(downloadPath, base).toString());
  if (deniedDownload.status() !== 403) throw new Error(`Unprivileged project document download returned HTTP ${deniedDownload.status()}, expected 403.`);
  checks.push({ private_project_document: 'saved, independently approved, PDF signature verified', denied_download_status: deniedDownload.status(),
    bytes: pdfBytes.length, sha256: crypto.createHash('sha256').update(pdfBytes).digest('hex') });
  await deniedContext.close();
  await reviewerDocs.close();

  // Complete the required task and milestone, record handover, then prove completion and closure gates in the UI.
  await page.goto(base + `/projects/${projectId}/tasks`, { waitUntil: 'networkidle' });
  let currentTaskStatus = resume?.task_status || 'pending';
  if (currentTaskStatus !== 'completed') {
    await setModel(page, 'taskReason', 'Verified browser acceptance task completion.');
    if (currentTaskStatus === 'pending') {
      const startTask = page.locator('button[wire\\:click*="taskStatus("][wire\\:click*="in_progress"]').first();
      await clickLivewireAndWait(page, startTask, 'Task start');
      const taskStartReadback = phpJson(fixturePath, { PHASE2_E2E_PROJECT_ID: String(projectId) }).browser_project_readback;
      if (taskStartReadback?.task_states?.[0]?.status !== 'in_progress') throw new Error(`Task start PostgreSQL readback failed: ${JSON.stringify(taskStartReadback?.task_states)}`);
      currentTaskStatus = 'in_progress';
    }
    const completeTask = page.locator('button[wire\\:click*="taskStatus("][wire\\:click*="completed"]').first();
    await clickLivewireAndWait(page, completeTask, 'Task completion');
    const taskReadback = phpJson(fixturePath, { PHASE2_E2E_PROJECT_ID: String(projectId) }).browser_project_readback;
    if (taskReadback?.task_states?.[0]?.status !== 'completed') throw new Error(`Task completion PostgreSQL readback failed: ${JSON.stringify(taskReadback?.task_states)}`);
  }
  if ((resume?.milestone_status || 'pending') !== 'completed') {
    await setModel(page, 'evidence', 'Verified milestone completion in browser acceptance.');
    await clickLivewireAndWait(page, page.locator('button[wire\\:click^="completeMilestone("]').first(), 'Milestone completion');
    const milestoneReadback = phpJson(fixturePath, { PHASE2_E2E_PROJECT_ID: String(projectId) }).browser_project_readback;
    if (milestoneReadback?.milestone_states?.[0]?.status !== 'completed') throw new Error(`Milestone completion PostgreSQL readback failed: ${JSON.stringify(milestoneReadback?.milestone_states)}`);
  }
  await page.goto(base + `/projects/${projectId}/closure`, { waitUntil: 'networkidle' });
  await setModel(page, 'evidence', `Handover acceptance record ${tag}`);
  const closureSnapshot = JSON.parse(await page.locator('[wire\\:id]').first().getAttribute('wire:snapshot'));
  const handoverResponsePromise = page.waitForResponse(response => /\/livewire(?:-[^/]+)?\/update/.test(response.url()), { timeout: 15000 });
  await page.locator('form[wire\\:submit="handover"] button[type="submit"]').click();
  const handoverResponse = await handoverResponsePromise;
  if (handoverResponse.status() !== 200) throw new Error(`Handover action failed HTTP ${handoverResponse.status()}: ${(await handoverResponse.text()).slice(0, 1000)}; snapshot=${JSON.stringify(closureSnapshot.data?.version)}; database=${JSON.stringify(phpJson(fixturePath, { PHASE2_E2E_PROJECT_ID: String(projectId) }).browser_project_readback?.version)}`);
  await page.getByText(`Handover acceptance record ${tag}`, { exact: false }).waitFor();
  await page.goto(base + `/projects/${projectId}`, { waitUntil: 'networkidle' });
  await setModel(page, 'lifecycleReason', 'Required task, milestone, document, and handover complete.');
  await clickAction(page, "changeLifecycle('completed')");
  await page.goto(base + `/projects/${projectId}/closure`, { waitUntil: 'networkidle' });
  await setModel(page, 'reason', 'Acceptance project obligations reconciled and closed.');
  await page.locator('form[wire\\:submit="close"] button[type="submit"]').click();
  await page.locator('form[wire\\:submit="close"]').waitFor({ state: 'detached' });
  checks.push({ handover_completion_closure: 'passed', project_id: projectId, status: 'closed' });

  const finalReadback = spawnSync('php', ['-d', 'opcache.enable_cli=0', fixturePath], { cwd: root,
    env: { ...env, PHASE2_E2E_PROJECT_ID: String(projectId) }, encoding: 'utf8', windowsHide: true });
  if (finalReadback.status !== 0) throw new Error(`Final PostgreSQL readback failed: ${(finalReadback.stderr || finalReadback.stdout || '').slice(0, 1600)}`);
  const finalState = JSON.parse(finalReadback.stdout);
  if (finalState.browser_project_readback?.status !== 'closed' || !String(finalState.browser_project_readback?.baseline?.total).startsWith('1234.5')
    || finalState.browser_project_readback?.phases < 1 || finalState.browser_project_readback?.tasks < 1
    || finalState.browser_project_readback?.milestones < 1 || finalState.browser_project_readback?.handovers < 1
    || finalState.browser_project_readback?.document?.status !== 'approved'
    || !finalState.browser_project_readback?.task_ids?.includes(Number(finalState.browser_project_readback?.document?.task_id))) {
    throw new Error(`Final PostgreSQL state did not match browser acceptance: ${JSON.stringify(finalState.browser_project_readback)}`);
  }

  if (browserErrors.length || livewireTraffic.some(row => row.status >= 500 || row.failure)) throw new Error(`Browser/Livewire errors: ${JSON.stringify({ browserErrors, livewireTraffic })}`);
  const result = { result: 'PASS', run_id: tag, database: `${fixture.database} on ${fixture.host}:${fixture.port}`,
    browser: 'Microsoft Edge via Playwright', viewport_sizes: [1440, 390], fixture, new_project: { id: projectId, code, name: `${projectName} — تعديل محفوظ` },
    source_financial_projection: financeEvidence.project_financial_projection,
    source_documents: { sales_order_id: financeEvidence.customer?.sales_order_id, purchase_order_ids: financeEvidence.project_fixture?.purchase_order_ids,
      project_issue_id: financeEvidence.project_fixture?.project_issue_id, linked_sources: financeEvidence.project_fixture?.source_link_ids },
    browser_linked_source_postings: postedSources && { sales_order_id: postedSources.sales_order_id, sales_delivery_id: postedSources.sales_delivery_id,
      customer_invoice_id: postedSources.customer_invoice_id, supplier_invoice_id: postedSources.supplier_invoice_id,
      project_summary: postedSources.project_summary },
    final_postgresql_state: finalState.browser_project_readback, checks, browser_errors: browserErrors, livewire_traffic: livewireTraffic };
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
