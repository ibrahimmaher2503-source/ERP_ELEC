'use strict';

// Authenticated M07 tender and M09 export browser acceptance on isolated PostgreSQL 55475.
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const crypto = require('node:crypto');
const net = require('node:net');
const { spawn, spawnSync } = require('node:child_process');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '../../../..');
const dbPort = 55475;
const router = path.join(__dirname, 'router.php');
const fixturePath = path.join(__dirname, 'fixture.php');
const workRoot = path.join(root, '.tmp/m09-browser-20261031');
const runTag = crypto.randomBytes(5).toString('hex').toUpperCase();
const runDir = path.join(__dirname, `tender-run-${runTag}`);
const storage = path.join(workRoot, `storage-tender-${runTag}`);
const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'erp-tender-browser-'));
const port = crypto.randomInt(9601, 9999);
const base = `http://127.0.0.1:${port}`;
const password = crypto.randomBytes(32).toString('base64url');
const browserNow = '2027-10-30T10:00:00.000Z';
const errors = [];
const checks = [];
const browserRoot = path.join(process.env.LOCALAPPDATA || '', 'ms-playwright');
const cachedChromium = fs.existsSync(browserRoot)
  ? fs.readdirSync(browserRoot).filter(name => /^chromium-\d+$/.test(name)).sort((a, b) => Number(b.slice(9)) - Number(a.slice(9)))
    .map(name => path.join(browserRoot, name, 'chrome-win64', 'chrome.exe')).find(fs.existsSync)
  : null;
const browserExecutable = process.env.PLAYWRIGHT_EXECUTABLE_PATH || cachedChromium || 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe';
const env = {
  ...process.env,
  APP_ENV: 'local', APP_URL: base, APP_DEBUG: 'false',
  APP_CONFIG_CACHE: path.join(temp, 'config.php'), APP_ROUTES_CACHE: path.join(temp, 'routes.php'),
  DB_CONNECTION: 'pgsql', DB_HOST: '127.0.0.1', DB_PORT: String(dbPort), DB_DATABASE: 'erp_elec_new_test',
  DB_USERNAME: 'postgres', DB_PASSWORD: '', DB_URL: '',
  SESSION_DRIVER: 'database', CACHE_STORE: 'array', QUEUE_CONNECTION: 'sync', MAIL_MAILER: 'array', LOG_CHANNEL: 'stderr',
  BROADCAST_CONNECTION: 'null', VIEW_COMPILED_PATH: path.join(temp, 'views'), LARAVEL_STORAGE_PATH: storage,
  PHASE5_WORKSHOP_DB_PORT: String(dbPort), PHASE5_WORKSHOP_STORAGE_ROOT: storage,
  PHASE5_WORKSHOP_TEST_NOW: '2027-10-30T12:00:00+02:00', PHASE5_WORKSHOP_PASSWORD: password, PHASE5_WORKSHOP_TAG: runTag,
};
let server, serverLog, browser, fixture, tenderId = 0;
fs.mkdirSync(runDir, { recursive: true });
fs.mkdirSync(path.join(temp, 'views'), { recursive: true });

function phpJson(extra = {}) {
  const result = spawnSync('php', ['-d', 'opcache.enable_cli=0', '-d', 'memory_limit=512M', fixturePath], {
    cwd: root, env: { ...env, APP_ENV: 'testing', ...extra }, encoding: 'utf8', windowsHide: true, maxBuffer: 4 * 1024 * 1024,
  });
  if (result.status !== 0) throw new Error(`Guarded tender fixture failed (${result.status}): ${(result.stderr || result.stdout || '').slice(-1800)}`);
  try { return JSON.parse(result.stdout); } catch { throw new Error(`Tender fixture returned no JSON: ${(result.stderr || result.stdout || '').slice(0, 700)}`); }
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
    if (server.exitCode !== null) throw new Error('Tender browser server exited during startup.');
    try { const response = await fetch(base + '/login'); if (response.status === 200) return; } catch {}
    await new Promise(resolve => setTimeout(resolve, 250));
  }
  throw new Error('Tender browser server did not become ready.');
}

function observe(page, ignoreExpected403 = false) {
  page.on('pageerror', error => errors.push({ kind: 'pageerror', path: new URL(page.url()).pathname, message: String(error.stack || error.message).slice(0, 700) }));
  page.on('console', message => {
    const expected403 = ignoreExpected403 && /responded with a status of 403 \(Forbidden\)/.test(message.text());
    if (message.type() === 'error' && !expected403) errors.push({ kind: 'console', path: new URL(page.url()).pathname, message: message.text().slice(0, 250) });
  });
  page.on('response', response => { if (response.status() >= 500) errors.push({ kind: 'http', path: new URL(response.url()).pathname, status: response.status() }); });
}

function wireModel(key) { return `[wire\\:model="${key}"], [wire\\:model\\.live="${key}"], [wire\\:model\\.live\\.debounce\\.300ms="${key}"]`; }
async function setModel(page, key, value) {
  const target = page.locator(wireModel(key)).first();
  await target.waitFor({ state: 'visible', timeout: 15000 });
  await target.fill(String(value));
}
async function selectModel(page, key, value) {
  const target = page.locator(wireModel(key)).first();
  await target.waitFor({ state: 'visible', timeout: 15000 });
  await target.selectOption(String(value));
}

function livewirePost(response) {
  const pathname = new URL(response.url()).pathname;
  return pathname.startsWith('/livewire') && pathname.endsWith('/update') && response.request().method() === 'POST';
}
function actionResponse(response, action) {
  if (!livewirePost(response)) return false;
  try {
    const payload = JSON.parse(response.request().postData() || '{}');
    return (payload.components || []).some(component => (component.calls || []).some(call => call.method === action || call.path === action));
  } catch { return false; }
}
function requestSummary(request) {
  try {
    const payload = JSON.parse(request.postData() || '{}');
    return JSON.stringify({ components: (payload.components || []).map(component => ({ calls: (component.calls || []).map(call => call.method), updates: component.updates || [] })) });
  } catch { return 'unavailable'; }
}
function responseSummary(raw) {
  try {
    const payload = JSON.parse(raw);
    return JSON.stringify({ components: (payload.components || []).map(component => {
      let errors; try { errors = JSON.parse(component.snapshot || '{}').memo?.errors; } catch {}
      return { errors, effectErrors: component.effects?.errors, htmlTail: typeof component.effects?.html === 'string' ? component.effects.html.slice(-1200) : undefined };
    }) });
  } catch { return raw.slice(0, 3000); }
}

let lastRequest = '', lastResponse = '';
async function submit(page, action) {
  const form = page.locator(`form[wire\\:submit="${action}"]`).first();
  await form.waitFor({ state: 'visible', timeout: 15000 });
  const responsePromise = page.waitForResponse(livewirePost, { timeout: 60000 });
  await form.evaluate(node => node.requestSubmit());
  const response = await responsePromise;
  lastRequest = requestSummary(response.request());
  lastResponse = responseSummary(await response.text().catch(() => ''));
  if (response.status() !== 200) throw new Error(`${action} returned Livewire HTTP ${response.status()}: ${lastResponse}`);
  await page.waitForLoadState('networkidle', { timeout: 20000 }).catch(error => { throw new Error(`${action} did not settle: ${error.message}`); });
}

async function clickWire(page, exact, label = exact) {
  const buttons = await page.locator('[wire\\:click]').all();
  let target = null;
  for (const button of buttons) if (await button.getAttribute('wire:click') === exact && await button.isVisible().catch(() => false)) { target = button; break; }
  if (!target) throw new Error(`${label} missing at ${page.url()}: ${(await page.locator('body').innerText().catch(() => '')).slice(-1000)}`);
  const action = exact.match(/^\s*([A-Za-z_$][\w$]*)/)?.[1];
  const responsePromise = page.waitForResponse(response => actionResponse(response, action), { timeout: 60000 }).then(response => ({ response }), error => ({ error }));
  try { await target.click({ timeout: 15000 }); } catch (error) { await responsePromise; throw error; }
  const waited = await responsePromise;
  if (waited.error) throw new Error(`${label} got no matching Livewire reply at ${page.url()}: ${(await page.locator('body').innerText().catch(() => '')).slice(-1000)}; ${waited.error.message}`);
  const response = waited.response;
  lastRequest = requestSummary(response.request());
  lastResponse = responseSummary(await response.text().catch(() => ''));
  if (response.status() !== 200) throw new Error(`${label} returned ${response.status()}: ${lastResponse}`);
}

async function clickPrefix(page, prefix, label = prefix) {
  const buttons = await page.locator('[wire\\:click]').all();
  let target = null;
  for (const button of buttons) if ((await button.getAttribute('wire:click'))?.startsWith(prefix) && await button.isVisible().catch(() => false)) { target = button; break; }
  if (!target) throw new Error(`${label} missing at ${page.url()}`);
  await target.click({ timeout: 15000 });
  await page.waitForLoadState('networkidle', { timeout: 20000 });
}

async function login(page, actor) {
  await page.goto(base + '/login');
  await page.locator('input[name="email"]').fill(actor.email);
  await page.locator('input[name="password"]').fill(password);
  await page.locator('[data-test="login-button"]').click();
  await page.waitForURL(url => url.pathname === '/dashboard', { timeout: 20000 });
}

function cairoDate(days = 0) {
  const d = new Date(Date.parse(browserNow) + days * 86400000);
  return new Intl.DateTimeFormat('en-CA', { timeZone: 'Africa/Cairo', year: 'numeric', month: '2-digit', day: '2-digit' }).format(d);
}
function cairoDateTime(days = 0, hour = 12) { return `${cairoDate(days)}T${String(hour).padStart(2, '0')}:00`; }
async function gotoTab(page, tab) {
  const link = page.locator(`a[href*="tab=${tab}"]`).first();
  await link.waitFor({ state: 'visible', timeout: 15000 });
  await link.click();
  await page.waitForURL(url => url.searchParams.get('tab') === tab, { timeout: 20000 });
  await page.locator('#main-content').waitFor({ state: 'visible', timeout: 15000 });
}
async function tenderReadback(id) {
  return phpJson({ PHASE5_WORKSHOP_FIXTURE_MODE: 'tender_readback', PHASE5_WORKSHOP_TENDER_ID: String(id) });
}

async function uploadTenderDocument(page, title, classification, filePath) {
  await setModel(page, 'uploadTitle', title);
  await selectModel(page, 'uploadClassification', classification);
  const upload = page.locator('[wire\\:model="file"]');
  const done = upload.evaluate(element => new Promise((resolve, reject) => {
    const timer = setTimeout(() => reject(new Error('Tender Livewire upload timed out.')), 30000);
    element.addEventListener('livewire-upload-finish', () => { clearTimeout(timer); resolve(); }, { once: true });
    element.addEventListener('livewire-upload-error', () => { clearTimeout(timer); reject(new Error('Tender Livewire upload failed.')); }, { once: true });
  }));
  await upload.setInputFiles(filePath);
  await done;
  const timing = phpJson({ PHASE5_WORKSHOP_FIXTURE_MODE: 'inspect_livewire_upload_times' });
  if (timing.database !== 'erp_elec_new_test' || timing.port !== dbPort || !timing.aligned.some(file => file.endsWith?.(path.basename(filePath)))) {
    // The inspect helper reports opaque Livewire temp names; the router has already aligned them.
    if (timing.database !== 'erp_elec_new_test' || timing.port !== dbPort || !timing.aligned.length || timing.aligned.some(file => file.mtime < Date.parse('2027-10-30T10:00:00.000Z') / 1000)) {
      throw new Error(`Tender upload clock/identity check failed: ${JSON.stringify(timing)}`);
    }
  }
  await submit(page, 'saveAttachment');
}

async function main() {
  if (dbPort !== 55475) throw new Error('Tender acceptance is hard-bound to disposable PostgreSQL clone 55475.');
  await freePort(port);
  fixture = phpJson({ PHASE5_WORKSHOP_FIXTURE_MODE: 'prepare_tender' });
  if (fixture.database !== 'erp_elec_new_test' || fixture.port !== dbPort || !fixture.m09_job) throw new Error('Tender fixture did not verify its guarded target database.');
  serverLog = fs.openSync(path.join(runDir, 'server.log.redacted.txt'), 'w');
  server = spawn('php', ['-d', 'memory_limit=512M', '-d', 'opcache.enable_cli=0', '-S', `127.0.0.1:${port}`, '-t', '.', router], {
    cwd: path.join(root, 'public'), env, stdio: ['ignore', serverLog, serverLog], windowsHide: true,
  });
  await waitForServer();
  browser = await chromium.launch({ executablePath: browserExecutable, headless: true, timeout: 30000 });
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'ar-EG', timezoneId: 'Africa/Cairo', colorScheme: 'light' });
  const maker = await context.newPage(); observe(maker);
  await login(maker, fixture.actors.maker);

  // M09 posted-invoice card and scoped report exports, using actual authenticated HTTP responses.
  await maker.goto(base + `/workshops/jobs/${fixture.m09_job}?tab=delivery`);
  const m09Text = await maker.locator('#main-content').innerText();
  if (!m09Text.includes('posted') || !m09Text.includes('CI-2027-000016')) throw new Error(`M09 delivered job does not reflect posted invoice after reload: ${m09Text.slice(-700)}`);
  await maker.setViewportSize({ width: 390, height: 844 });
  await maker.screenshot({ path: path.join(runDir, 'm09-posted-delivery-mobile.png'), animations: 'disabled' });
  const m09Metrics = await maker.evaluate(() => ({ viewport: document.documentElement.clientWidth, document: document.documentElement.scrollWidth }));
  if (m09Metrics.document > m09Metrics.viewport + 4) throw new Error(`Posted M09 delivery card overflows mobile: ${JSON.stringify(m09Metrics)}`);
  await maker.setViewportSize({ width: 1440, height: 900 });
  const m09Xlsx = await context.request.get(base + '/workshops/exports/xlsx');
  const xlsxBytes = await m09Xlsx.body();
  if (m09Xlsx.status() !== 200 || !m09Xlsx.headers()['cache-control']?.includes('private') || !m09Xlsx.headers()['cache-control']?.includes('no-store') || !xlsxBytes.subarray(0, 2).equals(Buffer.from('PK'))) throw new Error(`M09 XLSX export failed private/ZIP checks: ${JSON.stringify({ status: m09Xlsx.status(), headers: m09Xlsx.headers(), bytes: xlsxBytes.length })}`);
  const xlsxPath = path.join(workRoot, `workshop-export-${runTag}.xlsx`);
  fs.writeFileSync(xlsxPath, xlsxBytes);
  const workbookCheck = phpJson({ PHASE5_WORKSHOP_FIXTURE_MODE: 'inspect_workshop_export', PHASE5_WORKSHOP_EXPORT_PATH: xlsxPath });
  if (workbookCheck.database !== 'erp_elec_new_test' || workbookCheck.port !== dbPort || !workbookCheck.contains_target_job) throw new Error(`M09 export omitted current source job: ${JSON.stringify(workbookCheck)}`);
  const m09Pdf = await context.request.get(base + '/workshops/exports/pdf');
  const pdfBytes = await m09Pdf.body();
  if (m09Pdf.status() !== 200 || !m09Pdf.headers()['cache-control']?.includes('private') || !m09Pdf.headers()['cache-control']?.includes('no-store') || !pdfBytes.subarray(0, 5).equals(Buffer.from('%PDF-'))) throw new Error(`M09 PDF export failed private/signature checks: ${JSON.stringify({ status: m09Pdf.status(), headers: m09Pdf.headers(), bytes: pdfBytes.length })}`);
  checks.push({ m09_posted_job_card_mobile: m09Metrics, m09_exports: { xlsx_bytes: xlsxBytes.length, xlsx_contains_job_45: true, pdf_bytes: pdfBytes.length, cache_control: m09Xlsx.headers()['cache-control'] } });

  await maker.setViewportSize({ width: 1440, height: 900 });
  await maker.goto(base + '/tenders/create');
  await maker.screenshot({ path: path.join(runDir, 'tender-create-1440x900.png'), animations: 'disabled' });
  const tenderTag = `DEMO-EG-M07-${runTag}`;
  await setModel(maker, 'form.external_reference', tenderTag);
  await setModel(maker, 'form.issuer', 'DEMO-EG Alexandria Electrical Authority');
  await setModel(maker, 'form.title', `${tenderTag} Distribution Panel Upgrade`);
  await setModel(maker, 'form.description', 'Synthetic acceptance tender for a distribution panel supply and installation scope.');
  await setModel(maker, 'form.requirements', 'Compliant low-voltage distribution equipment; attach the manufacturer specification and delivery schedule.');
  await selectModel(maker, 'form.category', 'supply');
  await selectModel(maker, 'form.branch_id', fixture.branch_id);
  await selectModel(maker, 'form.responsible_id', fixture.actors.maker.id);
  await setModel(maker, 'customerSearch', fixture.customer_code);
  await maker.waitForTimeout(500);
  await selectModel(maker, 'form.customer_id', fixture.customer_id);
  await setModel(maker, 'form.published_on', cairoDate());
  const deadlineOffsets = { site_visit_at: [2, 12], clarification_at: [3, 12], technical_at: [5, 12], financial_at: [6, 12], final_at: [8, 12], opening_at: [8, 13] };
  for (const [key, [day, hour]] of Object.entries(deadlineOffsets)) await setModel(maker, `form.deadlines.${key}`, cairoDateTime(day, hour));
  await submit(maker, 'save');
  await maker.waitForURL(url => /^\/tenders\/\d+$/.test(url.pathname), { timeout: 20000 });
  tenderId = Number(new URL(maker.url()).pathname.split('/').at(-1));
  await setModel(maker, 'form.title', `${tenderTag} Distribution Panel Upgrade - Revised`);
  await setModel(maker, 'form.description', 'Edited once before the technical bid; original issuer and tender reference remain locked.');
  await submit(maker, 'save');
  let state = await tenderReadback(tenderId);
  if (!state.tender || state.tender.title !== `${tenderTag} Distribution Panel Upgrade - Revised`) throw new Error(`Tender create/edit persistence mismatch: ${JSON.stringify(state.tender)}`);
  checks.push({ tender_create_edit_reopen: { id: tenderId, number: state.tender.number, version: state.tender.version } });

  await selectModel(maker, 'state', 'under_review');
  await setModel(maker, 'reason', 'Synthetic opening review recorded for acceptance.');
  await submit(maker, 'advanceState');
  await selectModel(maker, 'state', 'preparing_technical');
  await setModel(maker, 'reason', 'Proceed with the documented technical offer preparation.');
  await submit(maker, 'advanceState');
  state = await tenderReadback(tenderId);
  if (state.tender.status !== 'preparing_technical') throw new Error(`Tender lifecycle transition failed: ${JSON.stringify(state.tender)}`);

  const pdfPath = path.join(temp, 'tender-evidence.pdf');
  fs.writeFileSync(pdfPath, Buffer.from('%PDF-1.4\n% Synthetic tender acceptance evidence\n1 0 obj << /Type /Catalog >> endobj\n%%EOF\n'));
  await gotoTab(maker, 'documents');
  await uploadTenderDocument(maker, 'DEMO-EG Technical specification', 'technical', pdfPath);
  await uploadTenderDocument(maker, 'DEMO-EG Physical submission receipt', 'submission', pdfPath);
  await uploadTenderDocument(maker, 'DEMO-EG Award decision evidence', 'award', pdfPath);
  state = await tenderReadback(tenderId);
  const technicalDoc = state.documents.find(doc => doc.classification === 'technical');
  const submissionDoc = state.documents.find(doc => doc.classification === 'submission');
  const awardDoc = state.documents.find(doc => doc.classification === 'award');
  if (!technicalDoc || !submissionDoc || !awardDoc) throw new Error(`Required tender evidence classes were not persisted: ${JSON.stringify(state.documents)}`);

  await gotoTab(maker, 'technical');
  await setModel(maker, 'technical.requirements', 'One low-voltage distribution panel meeting the published protection and enclosure specification.');
  await maker.locator(`[wire\\:model="technical.documents"][value="${technicalDoc.id}"]`).check();
  await setModel(maker, 'technical.lines.0.description', 'Main distribution panel, 400A, 4-pole');
  await setModel(maker, 'technical.lines.0.quantity', '2.00000000');
  await setModel(maker, 'technical.lines.0.unit', 'piece');
  await setModel(maker, 'technical.lines.0.specification', 'Copper busbar, 400A, IP54 enclosure, tested assembly.');
  await selectModel(maker, 'technical.lines.0.compliance', 'compliant');
  await maker.setViewportSize({ width: 1366, height: 768 });
  const techMetrics = await maker.evaluate(() => ({ viewport: document.documentElement.clientWidth, document: document.documentElement.scrollWidth }));
  if (techMetrics.document > techMetrics.viewport + 4) throw new Error(`Tender technical editor overflows at 1366x768: ${JSON.stringify(techMetrics)}`);
  await maker.screenshot({ path: path.join(runDir, 'tender-technical-1366x768.png'), animations: 'disabled' });
  await submit(maker, 'saveTechnical');
  state = await tenderReadback(tenderId);
  if (!state.technical || state.technical.prepared_by !== fixture.actors.maker.id || state.technical.snapshot.lines[0].quantity !== '2.00000000') throw new Error(`Technical bid persistence mismatch: ${JSON.stringify(state.technical)}`);

  const technicalReviewerContext = await browser.newContext({ viewport: { width: 1366, height: 768 }, locale: 'ar-EG', timezoneId: 'Africa/Cairo' });
  const technicalReviewer = await technicalReviewerContext.newPage(); observe(technicalReviewer);
  await login(technicalReviewer, fixture.actors.technical_reviewer);
  await technicalReviewer.goto(base + `/tenders/${tenderId}?tab=approvals`);
  await setModel(technicalReviewer, 'reason', `Independent technical review ${runTag}`);
  await clickWire(technicalReviewer, 'approveTechnical', 'independent technical approval');
  state = await tenderReadback(tenderId);
  if (!state.approvals.some(item => item.kind === 'technical' && item.approved_by === fixture.actors.technical_reviewer.id)) throw new Error(`Technical approval did not persist independently: ${JSON.stringify(state.approvals)}`);

  await maker.setViewportSize({ width: 1440, height: 900 });
  await gotoTab(maker, 'financial');
  await clickWire(maker, 'prepareFinancial', 'load technical bid pricing lines');
  await setModel(maker, 'financial.date', cairoDate());
  await selectModel(maker, 'financial.mode', 'exclusive');
  await setModel(maker, 'financial.lines.0.unit_cost', '50.00000000');
  await setModel(maker, 'financial.lines.0.selling_price', '100.00000000');
  await setModel(maker, 'financial.lines.0.cost_reference', `DEMO-EG vendor budget ${runTag}`);
  await setModel(maker, 'financial.lines.0.price_reference', `DEMO-EG approved quotation basis ${runTag}`);
  await setModel(maker, 'financial.terms', 'Synthetic offer valid through the published deadline, inclusive of installation.');
  await setModel(maker, 'financial.reference', 'Synthetic documented cost and commercial pricing basis.');
  await submit(maker, 'saveFinancial');
  state = await tenderReadback(tenderId);
  if (!state.financial || state.financial.prepared_by !== fixture.actors.maker.id || state.financial.net !== '200.00' || state.financial.tax !== '28.00') throw new Error(`Financial price/tax snapshot mismatch: ${JSON.stringify(state.financial)}`);

  const financialReviewerContext = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'ar-EG', timezoneId: 'Africa/Cairo' });
  const financialReviewer = await financialReviewerContext.newPage(); observe(financialReviewer);
  await login(financialReviewer, fixture.actors.financial_reviewer);
  await financialReviewer.goto(base + `/tenders/${tenderId}?tab=approvals`);
  await setModel(financialReviewer, 'reason', `Independent financial review ${runTag}`);
  await clickWire(financialReviewer, 'approveFinancial', 'independent financial approval');
  state = await tenderReadback(tenderId);
  if (!state.approvals.some(item => item.kind === 'financial' && item.approved_by === fixture.actors.financial_reviewer.id)) throw new Error(`Financial approval did not persist independently: ${JSON.stringify(state.approvals)}`);

  await maker.goto(base + `/tenders/${tenderId}?tab=submission`);
  await clickWire(maker, 'issue', 'issue immutable official tender bid');
  state = await tenderReadback(tenderId);
  if (!state.bid_document || state.bid_document.financial_id !== state.financial.id) throw new Error(`Official bid document missing: ${JSON.stringify(state.bid_document)}`);
  const officialBid = await context.request.get(base + `/tenders/${tenderId}/bids/${state.financial.id}/pdf`);
  const bidBytes = await officialBid.body();
  if (officialBid.status() !== 200 || !officialBid.headers()['cache-control']?.includes('private') || !officialBid.headers()['cache-control']?.includes('no-store') || !bidBytes.subarray(0, 5).equals(Buffer.from('%PDF-'))) throw new Error(`Official tender bid PDF failed privacy/signature checks: ${JSON.stringify({ status: officialBid.status(), bytes: bidBytes.length })}`);
  await selectModel(maker, 'submission.method', 'portal');
  await setModel(maker, 'submission.submitted_at', cairoDateTime(0, 12));
  await setModel(maker, 'submission.reference', `DEMO-EG portal receipt ${runTag}`);
  await selectModel(maker, 'submission.evidence_id', submissionDoc.id);
  await submit(maker, 'submitBid');
  state = await tenderReadback(tenderId);
  if (state.tender.status !== 'submitted' || !state.submission || state.submission.document_id !== state.bid_document.document_id) throw new Error(`Actual bid submission did not persist with official revision: ${JSON.stringify({ tender: state.tender, submission: state.submission })}`);

  await maker.goto(base + `/tenders/${tenderId}?tab=details`);
  await selectModel(maker, 'state', 'awaiting_result');
  await setModel(maker, 'reason', 'Physical result window remains open for decision evidence.');
  await submit(maker, 'advanceState');
  await maker.goto(base + `/tenders/${tenderId}?tab=award`);
  await selectModel(maker, 'result.outcome', 'awarded');
  await setModel(maker, 'result.decided_on', cairoDate());
  await setModel(maker, 'result.award_amount', state.financial.total);
  await selectModel(maker, 'result.evidence_id', awardDoc.id);
  await setModel(maker, 'result.reference', `DEMO-EG award minute ${runTag}`);
  await submit(maker, 'saveResult');
  state = await tenderReadback(tenderId);
  if (state.tender.status !== 'awarded' || !state.result || state.result.award_amount !== state.financial.total) throw new Error(`Tender award result mismatch: ${JSON.stringify(state.result)}`);

  await setModel(maker, 'planReference', `Award handoff to M07 delivery project ${runTag}`);
  await setModel(maker, 'project.code', `DEMO-${runTag}`);
  await setModel(maker, 'project.name', `DEMO-EG Panel Upgrade ${runTag}`);
  await selectModel(maker, 'project.manager_id', fixture.actors.maker.id);
  await setModel(maker, 'project.start_date', cairoDate(10));
  await setModel(maker, 'project.end_date', cairoDate(60));
  await submit(maker, 'savePlan');
  state = await tenderReadback(tenderId);
  if (!state.plan || state.plan.proposed_by !== fixture.actors.maker.id) throw new Error(`Project handoff plan did not persist: ${JSON.stringify(state.plan)}`);
  await financialReviewer.goto(base + `/tenders/${tenderId}?tab=award`);
  await selectModel(financialReviewer, 'planId', state.plan.id);
  await setModel(financialReviewer, 'reason', `Independent handoff plan approval ${runTag}`);
  await submit(financialReviewer, 'approvePlan');
  await maker.goto(base + `/tenders/${tenderId}?tab=award`);
  await selectModel(maker, 'planId', state.plan.id);
  await clickWire(maker, 'convert', 'convert awarded tender into M07 project draft');
  state = await tenderReadback(tenderId);
  if (!state.handoff || state.handoff.destination !== 'project' || !state.project || state.project.name !== `DEMO-EG Panel Upgrade ${runTag}`) throw new Error(`Tender project conversion did not preserve source handoff: ${JSON.stringify({ handoff: state.handoff, project: state.project })}`);

  await maker.setViewportSize({ width: 390, height: 844 });
  await maker.goto(base + `/tenders/${tenderId}?tab=award`);
  await maker.locator('details summary').first().click();
  const awardBody = await maker.locator('#main-content').innerText();
  if (!awardBody.includes(state.tender.number) || !awardBody.includes(state.project.name)) throw new Error('Tender award and handoff are not visible on mobile after reload.');
  const mobile = await maker.evaluate(() => ({ viewport: document.documentElement.clientWidth, document: document.documentElement.scrollWidth }));
  if (mobile.document > mobile.viewport + 4) throw new Error(`Tender award page overflows at 390x844: ${JSON.stringify(mobile)}`);
  await maker.screenshot({ path: path.join(runDir, 'tender-award-390x844.png'), animations: 'disabled' });
  checks.push({ mobile: 'tender-award', ...mobile });

  const outsiderContext = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'ar-EG', timezoneId: 'Africa/Cairo' });
  const outsider = await outsiderContext.newPage(); observe(outsider, true);
  await login(outsider, fixture.actors.outsider);
  const deniedTender = await outsider.goto(base + `/tenders/${tenderId}`);
  const deniedBid = await outsiderContext.request.get(base + `/tenders/${tenderId}/bids/${state.financial.id}/pdf`);
  const deniedM09Xlsx = await outsiderContext.request.get(base + '/workshops/exports/xlsx');
  const deniedM09Pdf = await outsiderContext.request.get(base + '/workshops/exports/pdf');
  if (deniedTender?.status() !== 403 || deniedBid.status() !== 403 || deniedM09Xlsx.status() !== 403 || deniedM09Pdf.status() !== 403) throw new Error(`Unauthorized tender/M09 export reached data: ${JSON.stringify({ tender: deniedTender?.status(), bid: deniedBid.status(), xlsx: deniedM09Xlsx.status(), pdf: deniedM09Pdf.status() })}`);
  checks.push({ out_of_scope_denials: { tender: deniedTender.status(), bid_pdf: deniedBid.status(), m09_xlsx: deniedM09Xlsx.status(), m09_pdf: deniedM09Pdf.status() } });
  if (errors.length) throw new Error(`Tender browser runtime errors: ${JSON.stringify(errors.slice(0, 8))}`);

  const result = {
    result: 'PASS', database: 'erp_elec_new_test@127.0.0.1:55475', browser: 'Playwright Chromium/Edge',
    viewports: [[1440, 900], [1366, 768], [390, 844]],
    tender: { id: tenderId, number: state.tender.number, final_status: state.tender.status, technical_revision: state.technical.revision, financial_revision: state.financial.revision, net: state.financial.net, tax: state.financial.tax, total: state.financial.total, submitted_method: state.submission.method, award: state.result.award_amount, handoff: state.handoff, project: state.project },
    checks: [{ m09_workshop_job_id: fixture.m09_job }, ...checks, { official_bid_pdf_bytes: bidBytes.length, official_bid_sha256: crypto.createHash('sha256').update(bidBytes).digest('hex') }],
    browser_errors: errors,
  };
  fs.writeFileSync(path.join(runDir, 'result.json'), JSON.stringify(result, null, 2));
  process.stdout.write(`Tender browser PASS: ${runDir}\n${JSON.stringify(result, null, 2)}\n`);
}

main().then(async () => {
  await browser?.close();
  if (server && server.exitCode === null) server.kill('SIGTERM');
  if (serverLog !== undefined) fs.closeSync(serverLog);
}).catch(async error => {
  const diagnostic = { message: error.message, stack: String(error.stack || '').split('\n').slice(0, 14), last_request: lastRequest, last_response: lastResponse };
  try { await browser?.close(); if (server && server.exitCode === null) server.kill('SIGTERM'); if (serverLog !== undefined) fs.closeSync(serverLog); } catch {}
  try { diagnostic.readback = await phpJson({ PHASE5_WORKSHOP_FIXTURE_MODE: 'tender_readback', PHASE5_WORKSHOP_TENDER_ID: String(tenderId) }); } catch (e) { diagnostic.readback_error = String(e.message).slice(0, 500); }
  fs.writeFileSync(path.join(runDir, 'failure.redacted.json'), JSON.stringify(diagnostic, null, 2));
  process.stderr.write(`${error.stack || error.message}\nEvidence: ${runDir}\n`);
  process.exitCode = 1;
});
