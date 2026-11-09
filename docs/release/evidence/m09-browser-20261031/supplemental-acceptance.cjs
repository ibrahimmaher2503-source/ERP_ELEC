'use strict';

// Supplemental authenticated M07 browser coverage on the isolated 55475 clone.
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
const runDir = path.join(__dirname, `supplemental-run-${runTag}`);
const storage = path.join(workRoot, `storage-supplemental-${runTag}`);
const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'erp-tender-supplemental-'));
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
let server, serverLog, browser, fixture, tenderId = 1, beforeGuarantee;
let lastRequest = '', lastResponse = '';
fs.mkdirSync(runDir, { recursive: true });
fs.mkdirSync(path.join(temp, 'views'), { recursive: true });

function phpJson(extra = {}) {
  const result = spawnSync('php', ['-d', 'opcache.enable_cli=0', '-d', 'memory_limit=512M', fixturePath], {
    cwd: root, env: { ...env, APP_ENV: 'testing', ...extra }, encoding: 'utf8', windowsHide: true, maxBuffer: 4 * 1024 * 1024,
  });
  if (result.status !== 0) throw new Error(`Guarded supplemental fixture failed (${result.status}): ${(result.stderr || result.stdout || '').slice(-1800)}`);
  try { return JSON.parse(result.stdout); } catch { throw new Error(`Supplemental fixture returned no JSON: ${(result.stderr || result.stdout || '').slice(0, 700)}`); }
}

async function waitForServer() {
  const deadline = Date.now() + 30000;
  while (Date.now() < deadline) {
    if (server.exitCode !== null) throw new Error('Supplemental browser server exited during startup.');
    try { if ((await fetch(base + '/login')).status === 200) return; } catch {}
    await new Promise(resolve => setTimeout(resolve, 250));
  }
  throw new Error('Supplemental browser server did not become ready.');
}

function observe(page, ignoreExpected403 = false) {
  page.on('pageerror', error => errors.push({ kind: 'pageerror', path: new URL(page.url()).pathname, message: String(error.stack || error.message).slice(0, 700) }));
  page.on('console', message => {
    if (message.type() === 'error' && !(ignoreExpected403 && /responded with a status of 403 \(Forbidden\)/.test(message.text()))) errors.push({ kind: 'console', path: new URL(page.url()).pathname, message: message.text().slice(0, 250) });
  });
  page.on('response', response => { if (response.status() >= 500) errors.push({ kind: 'http', path: new URL(response.url()).pathname, status: response.status() }); });
}

function wireModel(key) { return `[wire\\:model="${key}"], [wire\\:model\\.live="${key}"], [wire\\:model\\.live\\.debounce\\.300ms="${key}"]`; }
async function setModel(page, key, value) { await page.locator(wireModel(key)).first().fill(String(value)); }
async function selectModel(page, key, value) { await page.locator(wireModel(key)).first().selectOption(String(value)); }
function livewirePost(response) { return new URL(response.url()).pathname.startsWith('/livewire') && new URL(response.url()).pathname.endsWith('/update') && response.request().method() === 'POST'; }
function actionResponse(response, action) {
  if (!livewirePost(response)) return false;
  try { return (JSON.parse(response.request().postData() || '{}').components || []).some(c => (c.calls || []).some(call => call.method === action)); } catch { return false; }
}
function requestSummary(request) {
  try { const p = JSON.parse(request.postData() || '{}'); return JSON.stringify({ components: (p.components || []).map(c => ({ calls: (c.calls || []).map(x => x.method), updates: c.updates || {} })) }); } catch { return 'unavailable'; }
}
function responseSummary(raw) {
  try { const p = JSON.parse(raw); return JSON.stringify({ components: (p.components || []).map(c => ({ errors: (() => { try { return JSON.parse(c.snapshot || '{}').memo?.errors; } catch { return undefined; } })(), effectErrors: c.effects?.errors, htmlTail: typeof c.effects?.html === 'string' ? c.effects.html.slice(-1000) : undefined })) }); } catch { return raw.slice(0, 2500); }
}
async function submit(page, action) {
  const form = page.locator(`form[wire\\:submit="${action}"]`).first();
  await form.waitFor({ state: 'visible', timeout: 15000 });
  const responsePromise = page.waitForResponse(livewirePost, { timeout: 60000 });
  await form.evaluate(node => node.requestSubmit());
  const response = await responsePromise;
  lastRequest = requestSummary(response.request()); lastResponse = responseSummary(await response.text().catch(() => ''));
  if (response.status() !== 200) throw new Error(`${action} returned Livewire HTTP ${response.status()}: ${lastResponse}`);
  await page.waitForLoadState('networkidle', { timeout: 20000 });
}
async function clickWire(page, action) {
  const target = page.locator(`[wire\\:click="${action}"]`).first();
  await target.waitFor({ state: 'visible', timeout: 15000 });
  const responsePromise = page.waitForResponse(response => actionResponse(response, action), { timeout: 60000 });
  await target.click();
  const response = await responsePromise;
  lastRequest = requestSummary(response.request()); lastResponse = responseSummary(await response.text().catch(() => ''));
  if (response.status() !== 200) throw new Error(`${action} returned HTTP ${response.status()}: ${lastResponse}`);
}
async function login(page, actor) {
  await page.goto(base + '/login');
  await page.locator('input[name="email"]').fill(actor.email);
  await page.locator('input[name="password"]').fill(password);
  await page.locator('[data-test="login-button"]').click();
  await page.waitForURL(url => url.pathname === '/dashboard', { timeout: 20000 });
}
async function readback(id) { return phpJson({ PHASE5_WORKSHOP_FIXTURE_MODE: 'supplemental_readback', PHASE5_WORKSHOP_TENDER_ID: String(id) }); }
async function upload(page, title, classification, file) {
  await setModel(page, 'uploadTitle', title);
  await selectModel(page, 'uploadClassification', classification);
  const input = page.locator('[wire\\:model="file"]');
  const finished = input.evaluate(element => new Promise((resolve, reject) => {
    const timer = setTimeout(() => reject(new Error('Tender evidence upload timed out.')), 30000);
    element.addEventListener('livewire-upload-finish', () => { clearTimeout(timer); resolve(); }, { once: true });
    element.addEventListener('livewire-upload-error', () => { clearTimeout(timer); reject(new Error('Tender evidence upload failed.')); }, { once: true });
  }));
  await input.setInputFiles(file);
  await finished;
  await submit(page, 'saveAttachment');
}
async function reportDownload(page, format, target) {
  const link = page.locator(`a[href*="/tenders/exports/${format}"]`).first();
  await link.waitFor({ state: 'visible', timeout: 15000 });
  const href = await link.getAttribute('href');
  const responsePromise = page.waitForResponse(response => new URL(response.url()).pathname === `/tenders/exports/${format}` && response.request().method() === 'GET', { timeout: 30000 });
  const downloadPromise = page.waitForEvent('download', { timeout: 30000 });
  await link.click();
  const [download, response] = await Promise.all([downloadPromise, responsePromise]);
  await download.saveAs(target);
  return { bytes: fs.statSync(target).size, status: response.status(), headers: response.headers(), path: target };
}

async function main() {
  await new Promise((resolve, reject) => net.createServer().once('error', reject).listen(port, '127.0.0.1', function () { this.close(resolve); }));
  fixture = phpJson({ PHASE5_WORKSHOP_FIXTURE_MODE: 'prepare_tender' });
  if (fixture.database !== 'erp_elec_new_test' || fixture.port !== dbPort) throw new Error('Tender fixture guard response mismatch.');
  const original = await readback(1);
  if (!original.tender || original.tender.id !== 1 || original.handoff_count !== 0 || !['identified', 'under_review', 'preparing_technical'].includes(original.tender.status)) throw new Error(`Expected failed, open, unconverted tender #1 for reopen proof: ${JSON.stringify(original.tender)}`);

  serverLog = fs.openSync(path.join(runDir, 'server.log.redacted.txt'), 'w');
  server = spawn('php', ['-d', 'opcache.enable_cli=0', '-d', 'memory_limit=512M', '-S', `127.0.0.1:${port}`, '-t', '.', router], { cwd: path.join(root, 'public'), env, stdio: ['ignore', serverLog, serverLog], windowsHide: true });
  await waitForServer();
  browser = await chromium.launch({ headless: true, executablePath: browserExecutable, args: ['--no-sandbox'] });
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'ar-EG', timezoneId: 'Africa/Cairo', acceptDownloads: true });
  const maker = await context.newPage(); observe(maker);
  await login(maker, fixture.actors.maker);

  // Scoped tender report view and actual private spreadsheet/PDF downloads.
  await maker.goto(base + '/tenders/reports');
  await maker.locator('#main-content').waitFor({ state: 'visible', timeout: 15000 });
  const reportsBody = await maker.locator('#main-content').innerText();
  if (!reportsBody.includes('TN-2027-000006') || !reportsBody.includes('التقارير')) throw new Error(`Tender report screen did not show the accepted scope: ${reportsBody.slice(0, 500)}`);
  await maker.screenshot({ path: path.join(runDir, 'tender-reports-1440x900.png'), animations: 'disabled' });
  const xlsxPath = path.join(workRoot, `tender-report-${runTag}.xlsx`);
  const pdfPath = path.join(workRoot, `tender-report-${runTag}.pdf`);
  const xlsx = await reportDownload(maker, 'xlsx', xlsxPath);
  const pdf = await reportDownload(maker, 'pdf', pdfPath);
  if (xlsx.status !== 200 || !xlsx.headers['cache-control']?.includes('private') || !xlsx.headers['cache-control']?.includes('no-store') || !fs.readFileSync(xlsxPath).subarray(0, 2).equals(Buffer.from('PK'))) throw new Error(`Tender XLSX report failed signature/privacy checks: ${JSON.stringify({ status: xlsx.status, headers: xlsx.headers, bytes: xlsx.bytes })}`);
  if (pdf.status !== 200 || !pdf.headers['cache-control']?.includes('private') || !pdf.headers['cache-control']?.includes('no-store') || !fs.readFileSync(pdfPath).subarray(0, 5).equals(Buffer.from('%PDF-'))) throw new Error(`Tender PDF report failed signature/privacy checks: ${JSON.stringify({ status: pdf.status, headers: pdf.headers, bytes: pdf.bytes })}`);
  const reportInspection = phpJson({ PHASE5_WORKSHOP_FIXTURE_MODE: 'inspect_tender_export', PHASE5_WORKSHOP_EXPORT_PATH: xlsxPath });
  if (!reportInspection.contains_awarded_tender) throw new Error(`Tender XLSX omitted accepted tender: ${JSON.stringify(reportInspection)}`);
  checks.push({ tender_report_exports: { xlsx_bytes: xlsx.bytes, xlsx_contains_awarded_tender: true, pdf_bytes: pdf.bytes, cache_control: xlsx.headers['cache-control'] } });

  // Existing frozen BOQ reopens correctly; an imported System-template workbook previews without a DB write.
  await maker.goto(base + '/tenders/6?tab=technical');
  await maker.locator('#main-content').waitFor({ state: 'visible', timeout: 15000 });
  const stored = await readback(6);
  const versionBeforePreview = stored.tender.version;
  const downloadPromise = maker.waitForEvent('download', { timeout: 30000 });
  await clickWire(maker, 'template');
  const templateDownload = await downloadPromise;
  const templatePath = path.join(workRoot, `tender-template-${runTag}.xlsx`);
  await templateDownload.saveAs(templatePath);
  if (!fs.readFileSync(templatePath).subarray(0, 2).equals(Buffer.from('PK'))) throw new Error('Tender BOQ template download is not an XLSX archive.');
  const populated = phpJson({ PHASE5_WORKSHOP_FIXTURE_MODE: 'populate_tender_boq', PHASE5_WORKSHOP_WORKBOOK_PATH: templatePath });
  if (!populated.row || populated.bytes < 1000) throw new Error(`Unable to populate real System BOQ template: ${JSON.stringify(populated)}`);
  await maker.setViewportSize({ width: 1366, height: 768 });
  await maker.screenshot({ path: path.join(runDir, 'tender-boq-stored-1366x768.png'), animations: 'disabled' });
  await clickWire(maker, 'loadTechnical');
  const savedDescription = maker.locator('[wire\\:model="technical.lines.0.description"]').first();
  if (await savedDescription.inputValue() !== stored.technical.snapshot.lines[0].description || await maker.locator('[wire\\:model="technical.lines.0.quantity"]').first().inputValue() !== stored.technical.snapshot.lines[0].quantity) throw new Error('Reloaded tender BOQ editor did not match the immutable saved revision.');
  const boqUpload = maker.locator('[wire\\:model="boq"]');
  const uploaded = boqUpload.evaluate(element => new Promise((resolve, reject) => {
    const timer = setTimeout(() => reject(new Error('BOQ Livewire upload timed out.')), 30000);
    element.addEventListener('livewire-upload-finish', () => { clearTimeout(timer); resolve(); }, { once: true });
    element.addEventListener('livewire-upload-error', () => { clearTimeout(timer); reject(new Error('BOQ Livewire upload failed.')); }, { once: true });
  }));
  await boqUpload.setInputFiles(templatePath); await uploaded; await submit(maker, 'importBoq');
  const previewDescription = maker.locator('[wire\\:model="technical.lines.0.description"]').first();
  const previewValues = { description: await previewDescription.inputValue(), quantity: await maker.locator('[wire\\:model="technical.lines.0.quantity"]').first().inputValue(), visibleAlert: await maker.locator('[role="alert"]').allInnerTexts().catch(() => []) };
  if (previewValues.description !== 'Supplementary imported enclosure assembly' || previewValues.quantity !== '3.00000000') throw new Error(`Imported System workbook did not populate the editable BOQ preview: ${JSON.stringify(previewValues)}`);
  const afterPreview = await readback(6);
  if (afterPreview.tender.technical_id !== stored.tender.technical_id || afterPreview.tender.version !== versionBeforePreview) throw new Error('BOQ import preview unexpectedly changed persisted tender revision/version.');
  checks.push({ boq_template_and_preview: { template_bytes: fs.statSync(templatePath).size, imported_description: 'Supplementary imported enclosure assembly', persisted_revision_unchanged: true, reloaded_saved_description: stored.technical.snapshot.lines[0].description } });

  // An operational guarantee follows evidence and a recorded return while all financial/stock ledgers stay unchanged.
  tenderId = 1;
  beforeGuarantee = await readback(tenderId);
  const evidence = path.join(temp, 'guarantee-evidence.pdf');
  fs.writeFileSync(evidence, Buffer.from('%PDF-1.4\n% DEMO-EG supplemental operational guarantee evidence\n1 0 obj << /Type /Catalog >> endobj\n%%EOF\n'));
  await maker.setViewportSize({ width: 390, height: 844 });
  await maker.goto(base + `/tenders/${tenderId}?tab=documents`);
  await upload(maker, `DEMO-EG guarantee evidence ${runTag}`, 'guarantee', evidence);
  let proof = await readback(tenderId);
  const guaranteeDoc = proof.documents.find(row => row.classification === 'guarantee' && row.title === `DEMO-EG guarantee evidence ${runTag}`);
  if (!guaranteeDoc) throw new Error(`Guarantee evidence was not persisted: ${JSON.stringify(proof.documents)}`);
  await maker.goto(base + `/tenders/${tenderId}?tab=guarantees`);
  await setModel(maker, 'guarantee.reference', `DEMO-EG-BOND-${runTag}`);
  await selectModel(maker, 'guarantee.kind', 'bid_bond');
  await setModel(maker, 'guarantee.guarantee_type', 'Bid bond');
  await setModel(maker, 'guarantee.bank', 'Synthetic acceptance bank');
  await setModel(maker, 'guarantee.amount', '1500.00');
  await setModel(maker, 'guarantee.issued_on', '2027-10-30');
  await setModel(maker, 'guarantee.expires_on', '2028-10-30');
  await selectModel(maker, 'guarantee.evidence_id', guaranteeDoc.id);
  await maker.setViewportSize({ width: 390, height: 844 });
  const guaranteeMetrics = await maker.evaluate(() => ({ viewport: document.documentElement.clientWidth, document: document.documentElement.scrollWidth }));
  if (guaranteeMetrics.document > guaranteeMetrics.viewport + 4) throw new Error(`Tender guarantee view overflows mobile: ${JSON.stringify(guaranteeMetrics)}`);
  await maker.screenshot({ path: path.join(runDir, 'tender-guarantee-mobile-before.png'), animations: 'disabled' });
  await submit(maker, 'saveGuarantee');
  proof = await readback(tenderId);
  const guarantee = proof.guarantees.find(row => row.reference === `DEMO-EG-BOND-${runTag}`);
  if (!guarantee || guarantee.recorded_by !== fixture.actors.maker.id || guarantee.amount !== '1500.00') throw new Error(`Operational guarantee did not persist: ${JSON.stringify(proof.guarantees)}`);
  await selectModel(maker, 'guaranteeId', guarantee.id);
  await selectModel(maker, 'guaranteeStatus', 'returned');
  await selectModel(maker, 'eventDocument', guaranteeDoc.id);
  await setModel(maker, 'reason', `DEMO-EG physical return receipt ${runTag}`);
  await submit(maker, 'settleGuarantee');
  proof = await readback(tenderId);
  const settled = proof.guarantees.find(row => row.id === guarantee.id);
  if (!settled || settled.settled_status !== 'returned' || settled.settled_by !== fixture.actors.maker.id) throw new Error(`Guarantee return was not recorded: ${JSON.stringify(proof.guarantees)}`);
  if (JSON.stringify(proof.ledger_counts) !== JSON.stringify(beforeGuarantee.ledger_counts)) throw new Error(`Operational guarantee unexpectedly changed financial/stock ledgers: before=${JSON.stringify(beforeGuarantee.ledger_counts)} after=${JSON.stringify(proof.ledger_counts)}`);
  await maker.goto(base + `/tenders/${tenderId}?tab=guarantees`);
  const guaranteeBody = await maker.locator('#main-content').innerText();
  if (!guaranteeBody.includes(`DEMO-EG-BOND-${runTag}`) || !guaranteeBody.includes('returned') && !guaranteeBody.includes('مسترد')) throw new Error('Reloaded guarantee register did not show returned operational record.');
  const settledMobile = await maker.evaluate(() => ({ viewport: document.documentElement.clientWidth, document: document.documentElement.scrollWidth }));
  if (settledMobile.document > settledMobile.viewport + 4) throw new Error(`Tender guarantee register overflows mobile after reload: ${JSON.stringify(settledMobile)}`);
  await maker.screenshot({ path: path.join(runDir, 'tender-guarantee-mobile-returned.png'), animations: 'disabled' });
  checks.push({ operational_guarantee: { id: guarantee.id, amount: guarantee.amount, evidence_id: guaranteeDoc.id, final_status: settled.settled_status, ledger_counts_unchanged: proof.ledger_counts, mobile: settledMobile } });

  // Record a genuine reopen event on failed open Tender #1 without touching accepted Tender #6 or any handoff.
  proof = await readback(tenderId);
  const versionBeforeReopen = proof.tender.version;
  await maker.goto(base + `/tenders/${tenderId}?tab=details`);
  await selectModel(maker, 'eventKind', 'reopen');
  await selectModel(maker, 'eventDocument', guaranteeDoc.id);
  await setModel(maker, 'reason', `DEMO-EG documented reassessment ${runTag}`);
  await submit(maker, 'saveEvent');
  proof = await readback(tenderId);
  const reopenEvent = proof.events.find(row => row.action === 'tender.reopen');
  if (proof.tender.status !== 'preparing_technical' || proof.tender.version !== versionBeforeReopen + 1 || !reopenEvent || reopenEvent.actor_id !== fixture.actors.maker.id || proof.handoff_count !== 0) throw new Error(`Tender reopen event/version failed: ${JSON.stringify({ tender: proof.tender, reopenEvent, handoffs: proof.handoff_count })}`);
  await maker.setViewportSize({ width: 1440, height: 900 });
  await maker.goto(base + `/tenders/${tenderId}?tab=history`);
  const historyText = await maker.locator('#main-content').innerText();
  if (!historyText.includes('tender.reopen') || !historyText.includes(`DEMO-EG documented reassessment ${runTag}`)) throw new Error('Tender reopen history did not survive page reload.');
  await maker.screenshot({ path: path.join(runDir, 'tender-reopen-history-1440x900.png'), animations: 'disabled' });
  checks.push({ failed_tender_reopen: { id: tenderId, status: proof.tender.status, version_before: versionBeforeReopen, version_after: proof.tender.version, audit_actor: reopenEvent.actor_id, handoff_count: proof.handoff_count } });

  // Default-deny proof for private attachment, report page and both report formats.
  const outsiderContext = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'ar-EG', timezoneId: 'Africa/Cairo' });
  const outsider = await outsiderContext.newPage(); observe(outsider, true); await login(outsider, fixture.actors.outsider);
  const deniedReport = await outsider.goto(base + '/tenders/reports');
  const deniedXlsx = await outsiderContext.request.get(base + '/tenders/exports/xlsx');
  const deniedPdf = await outsiderContext.request.get(base + '/tenders/exports/pdf');
  const deniedDocument = await outsiderContext.request.get(base + `/tenders/documents/${guaranteeDoc.id}`);
  if (deniedReport?.status() !== 403 || deniedXlsx.status() !== 403 || deniedPdf.status() !== 403 || deniedDocument.status() !== 403) throw new Error(`Unauthorized supplemental tender route was not denied: ${JSON.stringify({ report: deniedReport?.status(), xlsx: deniedXlsx.status(), pdf: deniedPdf.status(), document: deniedDocument.status() })}`);
  checks.push({ unauthorized_denials: { reports: deniedReport.status(), xlsx: deniedXlsx.status(), pdf: deniedPdf.status(), attachment: deniedDocument.status() } });
  if (errors.length) throw new Error(`Supplemental browser runtime errors: ${JSON.stringify(errors.slice(0, 8))}`);

  const result = { result: 'PASS', database: 'erp_elec_new_test@127.0.0.1:55475', browser: 'Playwright Chromium/Edge', viewports: [[1440, 900], [1366, 768], [390, 844]], fixture: { tag: runTag, storage_root: storage, actors: fixture.actors }, checks, browser_errors: errors };
  fs.writeFileSync(path.join(runDir, 'result.json'), JSON.stringify(result, null, 2));
  process.stdout.write(`Supplemental Tender browser PASS: ${runDir}\n${JSON.stringify(result, null, 2)}\n`);
}

main().then(async () => {
  await browser?.close();
  if (server && server.exitCode === null) server.kill('SIGTERM');
  if (serverLog !== undefined) fs.closeSync(serverLog);
}).catch(async error => {
  const diagnostic = { message: error.message, stack: String(error.stack || '').split('\n').slice(0, 14), last_request: lastRequest, last_response: lastResponse, before_guarantee: beforeGuarantee };
  try { await browser?.close(); if (server && server.exitCode === null) server.kill('SIGTERM'); if (serverLog !== undefined) fs.closeSync(serverLog); } catch {}
  try { diagnostic.readback = await readback(tenderId); } catch (e) { diagnostic.readback_error = String(e.message).slice(0, 500); }
  fs.writeFileSync(path.join(runDir, 'failure.redacted.json'), JSON.stringify(diagnostic, null, 2));
  process.stderr.write(`${error.stack || error.message}\nEvidence: ${runDir}\n`);
  process.exitCode = 1;
});
