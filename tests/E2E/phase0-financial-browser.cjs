'use strict';

// Isolated, real-browser Phase 0 financial acceptance entry point. Never points at
// the shared 5443 database; the accompanying fixture is pinned to port 55434.
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const crypto = require('node:crypto');
const net = require('node:net');
const { spawn, spawnSync } = require('node:child_process');
const { chromium } = require('playwright');
const root = path.resolve(__dirname, '../..');
const output = path.join(root, 'docs/qa/evidence/phase0-financial-browser');
const router = path.join(root, 'tests/E2E/support/laravel-static-router.php');
const fixturePath = path.join(root, 'tests/E2E/support/prepare-phase0-financial-fixtures.php');
const port = crypto.randomInt(8200, 8900);
const base = `http://127.0.0.1:${port}`;
const edge = process.env.PLAYWRIGHT_EXECUTABLE_PATH || 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe';
const tag = crypto.randomBytes(6).toString('hex').toUpperCase();
const runDir = path.join(output, tag);
const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'erp-phase0-financial-'));
fs.mkdirSync(runDir, { recursive: true }); fs.mkdirSync(path.join(temp, 'views'), { recursive: true });
const password = crypto.randomBytes(32).toString('base64url');
const isolatedStorage = path.join(root, '.tmp/phase0-financial-browser-luna-utf8-20261027/storage');
const env = { ...process.env, APP_ENV: 'testing', APP_URL: base, APP_DEBUG: 'false', APP_CONFIG_CACHE: path.join(temp, 'config.php'), APP_ROUTES_CACHE: path.join(temp, 'routes.php'), DB_CONNECTION: 'pgsql', DB_HOST: '127.0.0.1', DB_PORT: '55434', DB_DATABASE: 'erp_elec_new_test', DB_USERNAME: 'postgres', DB_PASSWORD: '', DB_URL: '', LARAVEL_STORAGE_PATH: isolatedStorage, PHASE0_STORAGE_ROOT: isolatedStorage, SESSION_DRIVER: 'file', CACHE_STORE: 'array', QUEUE_CONNECTION: 'sync', MAIL_MAILER: 'array', LOG_CHANNEL: 'stderr', BROADCAST_CONNECTION: 'null', VIEW_COMPILED_PATH: path.join(temp, 'views') };
const serverEnv = { ...env, APP_ENV: 'local' };
const checks = [];
let browser; let server; let page;
let serverLog;
const browserErrors = [];
const livewireTraffic = [];
function fixture(mode, data) {
  const result = spawnSync('php', [fixturePath, mode], { cwd: root, env, input: JSON.stringify(data), encoding: 'utf8', windowsHide: true });
  if (result.status !== 0) throw new Error((result.stderr || result.stdout || 'fixture failed').trim());
  try { return JSON.parse(result.stdout); } catch { throw new Error((result.stderr || result.stdout || 'fixture output was not JSON').trim().slice(0, 1200)); }
}
function safeUrl(raw) {
  const url = new URL(raw);
  return `${url.origin}${url.pathname}`;
}
async function assertPortFree(port) {
  await new Promise((resolve, reject) => {
    const probe = net.createServer();
    probe.once('error', error => reject(new Error(error.code === 'EADDRINUSE' ? `Port ${port} is occupied; refusing to use an unknown server.` : `Cannot preflight port ${port}: ${error.message}`)));
    probe.listen(port, '127.0.0.1', () => probe.close(resolve));
  });
}
async function login(actor) {
  await page.goto(base + '/login');
  await page.locator('input[name="email"]').fill(actor.email);
  await page.locator('input[name="password"]').fill(password);
  await page.locator('[data-test="login-button"]').click();
  await page.waitForURL(url => url.pathname === '/dashboard');
}
function livewireCalls(request) {
  try {
    const payload = request.postDataJSON();
    return (payload.components || []).flatMap(component => component.calls || []).map(call => ({
      method: call.method,
      params: call.params || [],
    }));
  } catch {
    return [];
  }
}
function safeLivewireResponse(body) {
  try {
    const payload = JSON.parse(body);
    return (payload.components || []).map(component => ({
      calls: component.calls || [],
      effects: {
        errors: component.effects?.errors || {},
        html_length: typeof component.effects?.html === 'string' ? component.effects.html.length : null,
        redirect: component.effects?.redirect || null,
      },
    }));
  } catch {
    return { invalid_json: body.slice(0, 1200) };
  }
}
async function livewireClick(locator, expectedAction = null) {
  const actionPage = locator.page();
  await locator.waitFor({ state: 'visible', timeout: 15000 });
  if (!expectedAction) {
    const expression = await locator.getAttribute('wire:click').catch(() => null)
      || await locator.locator('xpath=ancestor::form[1]').getAttribute('wire:submit').catch(() => null);
    const match = expression?.match(/(?:applyAction|operate|operation)\(['"]([^'"]+)/)
      || expression?.match(/^([A-Za-z_$][\w$]*)$/);
    expectedAction = match?.[1] || null;
  }
  const responsePromise = actionPage.waitForResponse(r => {
    if (!/\/livewire(?:-[^/]+)?\/update(?:\?|$)/.test(r.url()) || r.request().method() !== 'POST') return false;
    if (!expectedAction) return true;
    return livewireCalls(r.request()).some(call => call.method === expectedAction
      || (call.method === 'applyAction' && call.params.includes(expectedAction))
      || (call.method === 'operate' && call.params.includes(expectedAction))
      || (call.method === 'operation' && call.params.includes(expectedAction)));
  }, { timeout: 30000 });
  await locator.click();
  const response = await responsePromise;
  const responseBody = await response.text().catch(() => '');
  const calls = livewireCalls(response.request());
  const responseDetails = safeLivewireResponse(responseBody);
  livewireTraffic.push({
    event: 'action-result', action: expectedAction, status: response.status(),
    request_calls: calls, response: responseDetails,
  });
  if (expectedAction && !calls.some(call => call.method === expectedAction
    || (call.method === 'applyAction' && call.params.includes(expectedAction))
    || (call.method === 'operate' && call.params.includes(expectedAction))
    || (call.method === 'operation' && call.params.includes(expectedAction)))) {
    throw new Error(`Livewire response did not correspond to requested action ${expectedAction}; observed calls=${JSON.stringify(calls)}.`);
  }
  if (expectedAction && Array.isArray(responseDetails)
    && responseDetails.some(component => Object.keys(component.effects?.errors || {}).length > 0)) {
    throw new Error(`Livewire action ${expectedAction} returned validation errors: ${JSON.stringify(responseDetails)}`);
  }
  if (!response.ok()) {
    const pageBody = (await actionPage.locator('body').innerText({ timeout: 15000 }).catch(() => '')).slice(-1800);
    await actionPage.screenshot({ path: path.join(runDir, `livewire-${response.status()}-failure.png`), animations: 'disabled' }).catch(() => {});
    throw new Error(`Livewire action ${expectedAction || '(unspecified)'} returned HTTP ${response.status()} at ${response.url()}; calls=${JSON.stringify(calls)}; response=${JSON.stringify(responseDetails)}; page=${pageBody}`.slice(0, 4500));
  }
  return response;
}
async function livewireChange(locator, value) {
  const responsePromise = page.waitForResponse(r => /\/livewire(?:-[^/]+)?\/update(?:\?|$)/.test(r.url()), { timeout: 30000 });
  await locator.selectOption(String(value));
  const response = await responsePromise;
  if (!response.ok()) throw new Error(`Livewire field update returned HTTP ${response.status()}.`);
  return response;
}
async function livewireFill(locator, value) {
  const responsePromise = page.waitForResponse(r => /\/livewire(?:-[^/]+)?\/update(?:\?|$)/.test(r.url()), { timeout: 30000 });
  await locator.fill(String(value));
  const response = await responsePromise;
  if (!response.ok()) throw new Error(`Livewire field update returned HTTP ${response.status()}.`);
  return response;
}
async function submitAndWait(formSelector, successPath) {
  const buttonSelector = `${formSelector} button[type="submit"]`;
  await page.locator(buttonSelector).click();
  await page.waitForURL(url => successPath(url.pathname), { timeout: 30000 });
}
async function main() {
  await assertPortFree(port);
  const fixtures = fixture('prepare', { tag, password });
  if (path.resolve(fixtures.laravel_storage_root) !== path.resolve(isolatedStorage)) {
    throw new Error('The guarded PHP fixture did not resolve the isolated Laravel storage path.');
  }
  serverLog = fs.openSync(path.join(runDir, 'server.log.redacted.txt'), 'w');
  server = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', '.', router], { cwd: path.join(root, 'public'), env: serverEnv, stdio: ['ignore', serverLog, serverLog], windowsHide: true });
  let serverReady = false;
  for (let i = 0; i < 60; i++) {
    if (server.exitCode !== null) throw new Error('The harness-owned PHP server exited during startup.');
    try { if ((await fetch(base + '/login')).status === 200) { serverReady = true; break; } } catch {}
    await new Promise(r => setTimeout(r, 500));
  }
  if (!serverReady) throw new Error('The harness-owned PHP server failed its HTTP readiness check.');
  browser = await chromium.launch({ executablePath: edge, headless: true });
  const context = await browser.newContext({ viewport: { width: 1672, height: 941 }, locale: 'ar-EG', timezoneId: 'Africa/Cairo' });
  page = await context.newPage();
  page.setDefaultTimeout(15000);
  page.setDefaultNavigationTimeout(30000);
  page.on('pageerror', e => browserErrors.push(String(e.message).slice(0, 300)));
  page.on('console', m => { if (m.type() === 'error') browserErrors.push(m.text().slice(0, 300)); });
  page.on('response', r => { if (r.status() >= 500) browserErrors.push(`HTTP ${r.status()} ${r.url()}`.slice(0, 300)); });
  page.on('dialog', dialog => dialog.accept());
  page.on('request', r => { if (/\/livewire(?:-[^/]+)?\/(?:update|upload-file)/.test(r.url())) livewireTraffic.push({ event: 'request', method: r.method(), url: safeUrl(r.url()) }); });
  page.on('response', r => { if (/\/livewire(?:-[^/]+)?\/(?:update|upload-file)/.test(r.url())) livewireTraffic.push({ event: 'response', status: r.status(), url: safeUrl(r.url()) }); });
  page.on('requestfailed', r => { if (/\/livewire(?:-[^/]+)?\/(?:update|upload-file)/.test(r.url())) livewireTraffic.push({ event: 'failed', error: r.failure()?.errorText, url: safeUrl(r.url()) }); });
  await login(fixtures.actors.operator);
  if (await page.locator('html').getAttribute('dir') !== 'rtl') throw new Error('Arabic application did not render RTL.');
  checks.push('Arabic login completed with explicitly assigned Administrator and branch/warehouse scopes');
  await page.goto(base + '/quotations/create');
  await page.locator('[wire\\:model="form.customer_id"]').selectOption(String(fixtures.customer.id));
  if (fixtures.payment_term_id) await livewireChange(page.locator('[wire\\:model\\.live="form.payment_term_id"]'), fixtures.payment_term_id);
  if (fixtures.delivery_term_id) await livewireChange(page.locator('[wire\\:model\\.live="form.delivery_term_id"]'), fixtures.delivery_term_id);
  await page.locator('[wire\\:model="form.project_name"]').fill(`PHASE0-${tag} electrical supply`);
  for (let i = 0; i < 2; i++) {
    if (i) await livewireClick(page.locator('[wire\\:click="addLine"]'));
    await livewireChange(page.locator(`[wire\\:model\\.live="lines.${i}.product_id"]`), fixtures.products[i].id);
    await livewireFill(page.locator(`[wire\\:model\\.live\\.debounce\\.500ms="lines.${i}.quantity"]`), '2');
  }
  await page.waitForTimeout(1500);
  await page.screenshot({ path: path.join(runDir, 'quotation-create-desktop.png'), animations: 'disabled' });
  await page.setViewportSize({ width: 390, height: 844 });
  const dimensions = await page.evaluate(() => ({ viewport: innerWidth, document: document.documentElement.scrollWidth }));
  await page.screenshot({ path: path.join(runDir, 'quotation-create-mobile.png'), animations: 'disabled' });
  checks.push(`Quotation form mobile viewport captured (${dimensions.viewport}px; document width ${dimensions.document}px)`);
  await page.setViewportSize({ width: 1672, height: 941 });
  await submitAndWait('form[wire\\:submit="save"]', pathname => /^\/quotations\/\d+$/.test(pathname));
  if (!/^\/quotations\/\d+$/.test(new URL(page.url()).pathname)) {
    const errors = (await page.locator('[data-flux-error], [role="alert"], .text-red-700').allTextContents()).join(' | ');
    const body = (await page.locator('body').innerText({ timeout: 15000 })).slice(-1800);
    fs.writeFileSync(path.join(runDir, 'quotation-save-failure.html'), await page.content());
    await page.screenshot({ path: path.join(runDir, 'quotation-save-failure.png'), animations: 'disabled' });
    throw new Error(`Quotation draft did not save (current path ${new URL(page.url()).pathname}; visible errors: ${errors.slice(0, 500)}; body: ${body}; browser responses: ${browserErrors.join('; ')}).`);
  }
  const quotationId = Number(new URL(page.url()).pathname.split('/').pop());
  checks.push(`Quotation ${quotationId} created through the actual Livewire form with two restored, approved-price catalog products`);
  await page.goto(base + `/quotations/${quotationId}/edit`);
  const reopen = await page.locator('[wire\\:model="form.project_name"]').inputValue();
  if (reopen !== `PHASE0-${tag} electrical supply`) throw new Error('Quotation did not reopen with saved project identity.');
  await page.locator('[wire\\:model="form.project_name"]').fill(`PHASE0-${tag} edited`);
  await submitAndWait('form[wire\\:submit="save"]', pathname => pathname === `/quotations/${quotationId}`);
  await page.waitForURL(url => url.pathname === `/quotations/${quotationId}`);
  await page.screenshot({ path: path.join(runDir, 'quotation-details-desktop.png'), animations: 'disabled' });
  const quotePersisted = fixture('verify-quotation', { id: quotationId, project_name: `PHASE0-${tag} edited`, product_ids: fixtures.products.map(product => product.id) });
  checks.push('Guarded isolated PostgreSQL read confirms saved draft and both real product lines after reopening and editing');
  await page.goto(base + `/quotations/${quotationId}/pdf`);
  await page.screenshot({ path: path.join(runDir, 'quotation-issuance-readiness.png'), animations: 'disabled' });
  const issueButton = page.locator(`[wire\\:click="operation('issue')"]`);
  let quoteIssued = false;
  let issueCapture = null;
  if (await issueButton.count()) {
    const previousTraffic = livewireTraffic.length;
    await livewireClick(issueButton);
    await page.screenshot({ path: path.join(runDir, 'quotation-after-issue-click.png'), animations: 'disabled' });
    const bodyText = (await page.locator('body').innerText({ timeout: 15000 })).slice(0, 2500);
    issueCapture = { url: safeUrl(page.url()), livewire: livewireTraffic.slice(previousTraffic), body_text: bodyText };
    quoteIssued = await page.locator('a[href*="official-pdf"]').count() > 0;
    checks.push(quoteIssued ? 'Quotation issue action completed through its actual Livewire button' : 'Quotation issue control was clicked; official-issued state did not appear');
  }
  const issuanceText = (await page.locator('body').innerText({ timeout: 15000 })).slice(0, 1800);
  let officialPdf = null;
  let acceptanceSaved = false;
  let orderId = null;
  let purchaseOrderId = null;
  let purchaseReceiptId = null;
  let transferId = null;
  let countId = null;
  let adjustmentId = null;
  let finalDeliveryId = null;
  if (quoteIssued) {
    const pdfDownload = page.waitForEvent('download', { timeout: 30000 });
    await page.locator('a[href*="official-pdf"]').click();
    const download = await pdfDownload;
    const pdfPath = path.join(runDir, 'official-quotation.pdf');
    await download.saveAs(pdfPath);
    const pdfBytes = fs.readFileSync(pdfPath);
    officialPdf = { suggested_filename: download.suggestedFilename(), bytes: pdfBytes.length, pdf_signature: pdfBytes.subarray(0, 5).toString('ascii') };
    if (officialPdf.pdf_signature !== '%PDF-') throw new Error('Official quotation download did not contain PDF bytes.');
    checks.push(`Official quotation PDF downloaded through its authorized UI link (${officialPdf.bytes} bytes)`);

    await page.goto(base + `/quotations/${quotationId}/acceptance`);
    await page.locator('[wire\\:model="acceptance.evidence_type"]').selectOption('signed_acceptance');
    const cairoDate = new Intl.DateTimeFormat('en-CA', { timeZone: 'Africa/Cairo', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
    await page.locator('[wire\\:model="acceptance.accepted_on"]').fill(cairoDate);
    await page.locator('[wire\\:model="acceptance.reference"]').fill(`DEMO-EG written acceptance ${tag}`);
    const acceptancePath = path.join(isolatedStorage, 'app/private', fixtures.company_logo_path);
    if (!fs.existsSync(acceptancePath)) throw new Error('The isolated private company-logo and acceptance fixture is missing.');
    await page.evaluate(() => {
      window.__phase0UploadState = 'pending';
      const input = document.querySelector('input[type="file"]');
      input.addEventListener('livewire-upload-finish', () => { window.__phase0UploadState = 'finish'; }, { once: true });
      input.addEventListener('livewire-upload-error', () => { window.__phase0UploadState = 'error'; }, { once: true });
    });
    const uploadComplete = page.waitForResponse(response => /\/livewire(?:-[^/]+)?\/upload-file(?:\?|$)/.test(response.url()), { timeout: 30000 });
    await page.locator('input[type="file"]').setInputFiles(acceptancePath);
    const uploadResponse = await uploadComplete;
    if (!uploadResponse.ok()) throw new Error(`Private acceptance image upload failed with HTTP ${uploadResponse.status()}.`);
    await page.waitForFunction(() => window.__phase0UploadState !== 'pending', { timeout: 30000 });
    if (await page.evaluate(() => window.__phase0UploadState) !== 'finish') throw new Error('Livewire did not finish hydrating the uploaded acceptance image.');
    await livewireClick(page.locator('form[wire\\:submit="accept"] button[type="submit"]'));
    await page.locator('a[href*="acceptance-evidence"]').waitFor({ state: 'visible', timeout: 15000 }).catch(() => {});
    acceptanceSaved = await page.locator('a[href*="acceptance-evidence"]').count() > 0;
    if (!acceptanceSaved) {
      const errors = (await page.locator('[data-flux-error], [role="alert"], .text-red-700').allTextContents()).join(' | ');
      throw new Error(`Written acceptance did not save through the UI. Visible errors: ${errors.slice(0, 500)}`);
    }
    const acceptancePdf = page.waitForEvent('download', { timeout: 30000 });
    await page.locator('a[href*="acceptance-evidence"]').click();
    const acceptedDownload = await acceptancePdf;
    const acceptanceEvidencePath = path.join(runDir, 'written-acceptance.png');
    await acceptedDownload.saveAs(acceptanceEvidencePath);
    checks.push('Written acceptance recorded with actual uploaded private image evidence and downloaded from its authorized UI link');

    await page.goto(base + `/quotations/${quotationId}/convert`);
    await page.locator('[wire\\:model="conversionBranch"]').selectOption(String(fixtures.branch_id));
    await livewireClick(page.locator('form[wire\\:submit="convert"] button[type="submit"]'));
    await page.waitForURL(url => /^\/sales-orders\/\d+$/.test(url.pathname), { timeout: 30000 });
    orderId = Number(new URL(page.url()).pathname.split('/').pop());
    checks.push(`Accepted quotation converted once through the actual UI to Sales Order ${orderId}`);
    await page.goto(base + `/sales-orders/${orderId}/review`);
    await livewireClick(page.locator(`[wire\\:click="operate('submit')"]`));
    await page.screenshot({ path: path.join(runDir, 'sales-order-pending-review.png'), animations: 'disabled' });
    checks.push(`Converted order ${orderId} submitted for its independent review through the actual UI`);

    const operatorPage = page;
    const reviewerContext = await browser.newContext({ viewport: { width: 1672, height: 941 }, locale: 'ar-EG', timezoneId: 'Africa/Cairo' });
    page = await reviewerContext.newPage();
    page.setDefaultTimeout(15000);
    page.setDefaultNavigationTimeout(30000);
    page.on('pageerror', e => browserErrors.push(String(e.message).slice(0, 300)));
    page.on('console', m => { if (m.type() === 'error') browserErrors.push(m.text().slice(0, 300)); });
    page.on('response', r => { if (r.status() >= 500) browserErrors.push(`HTTP ${r.status()} ${r.url()}`.slice(0, 300)); });
    page.on('dialog', dialog => dialog.accept());
    page.on('request', r => { if (/\/livewire(?:-[^/]+)?\/(?:update|upload-file)/.test(r.url())) livewireTraffic.push({ event: 'request', method: r.method(), url: safeUrl(r.url()) }); });
    page.on('response', r => { if (/\/livewire(?:-[^/]+)?\/(?:update|upload-file)/.test(r.url())) livewireTraffic.push({ event: 'response', status: r.status(), url: safeUrl(r.url()) }); });
    page.on('requestfailed', r => { if (/\/livewire(?:-[^/]+)?\/(?:update|upload-file)/.test(r.url())) livewireTraffic.push({ event: 'failed', error: r.failure()?.errorText, url: safeUrl(r.url()) }); });
    await login(fixtures.actors.reviewer);
    const reviewerPage = page;
    await page.goto(base + `/sales-orders/${orderId}/review`);
    const orderFacts = fixture('order-lines', { order_id: orderId });
    for (const line of orderFacts.lines) {
      await page.locator(`[wire\\:model="reservationWarehouses.${line.id}"]`).selectOption(String(fixtures.warehouse_id));
    }
    await page.screenshot({ path: path.join(runDir, 'sales-order-independent-approval.png'), animations: 'disabled' });
    await livewireClick(page.locator(`[wire\\:click="operate('approve')"]`));
    checks.push(`Different scoped reviewer ${fixtures.actors.reviewer.id} approved Sales Order ${orderId} and requested actual warehouse reservations`);

    await page.goto(base + `/sales-orders/${orderId}/delivery`);
    const immediatelyAvailableLine = orderFacts.lines.find(line => Number(line.product_id) === Number(fixtures.products[0].id));
    if (!immediatelyAvailableLine) throw new Error('Converted sales order did not include the expected approved-price product line.');
    await page.setViewportSize({ width: 390, height: 844 });
    const orderMobile = await page.evaluate(() => ({ viewport: innerWidth, document: document.documentElement.scrollWidth }));
    await page.screenshot({ path: path.join(runDir, 'sales-order-backorder-mobile.png'), animations: 'disabled' });
    await page.setViewportSize({ width: 1672, height: 941 });
    checks.push(`Authorized sales-order delivery/backorder screen captured mobile RTL (${orderMobile.viewport}px/document ${orderMobile.document}px)`);

    // A real PO supplies the accepted Sales backorder with a fully evidenced FIFO/GRNI receipt.
    page = operatorPage;
    await page.goto(base + '/purchasing/orders/create');
    await page.locator('[wire\\:model="form.branch_id"]').selectOption(String(fixtures.branch_id));
    await livewireFill(page.locator('[wire\\:model\\.live\\.debounce\\.300ms="supplierSearch"]'), fixtures.supplier.name);
    await page.locator('[wire\\:model="form.supplier_id"]').selectOption(String(fixtures.supplier.id));
    await livewireClick(page.locator('[wire\\:click="addLine"]'));
    const replenishmentProduct = fixtures.products[1];
    await page.locator('[wire\\:model="form.lines.0.product_id"]').selectOption(String(replenishmentProduct.id));
    await page.locator('[wire\\:model="form.lines.0.quantity"]').fill('5');
    await page.locator('[wire\\:model="form.lines.0.unit_price"]').fill('1.00');
    await livewireClick(page.locator('[wire\\:click="addLine"]'));
    await page.locator('[wire\\:model="form.lines.1.product_id"]').selectOption(String(fixtures.products[0].id));
    await page.locator('[wire\\:model="form.lines.1.quantity"]').fill('4');
    await page.locator('[wire\\:model="form.lines.1.unit_price"]').fill('1.00');
    await page.locator('[wire\\:model="form.notes"]').fill(`DEMO-EG synthetic browser procurement fixture ${tag}; EGP 1.00/unit, explicitly synthetic test price.`);
    await page.locator('form[wire\\:submit="save"] button[type="submit"]').scrollIntoViewIfNeeded();
    await page.screenshot({ path: path.join(runDir, 'purchase-order-draft.png'), animations: 'disabled' });
    await submitAndWait('form[wire\\:submit="save"]', pathname => /^\/purchasing\/orders\/\d+$/.test(pathname));
    purchaseOrderId = Number(new URL(page.url()).pathname.split('/').pop());
    await livewireClick(page.locator(`[wire\\:click="applyAction('submit')"]`));
    await page.screenshot({ path: path.join(runDir, 'purchase-order-pending-review.png'), animations: 'disabled' });
    checks.push(`Real synthetic procurement order ${purchaseOrderId} created and submitted through Purchasing UI to supply the accepted backorder`);

    page = reviewerPage;
    await page.goto(base + `/purchasing/orders/${purchaseOrderId}`);
    await livewireClick(page.locator(`[wire\\:click="applyAction('approve')"]`));
    await page.screenshot({ path: path.join(runDir, 'purchase-order-approved.png'), animations: 'disabled' });
    await livewireClick(page.locator('[wire\\:click="issue"]'));
    const poPdfPromise = page.waitForEvent('download', { timeout: 30000 });
    await page.locator('a[href*="purchasing/orders"][href$="/pdf"]').click();
    const poDownload = await poPdfPromise;
    const poPdfPath = path.join(runDir, 'purchase-order.pdf');
    await poDownload.saveAs(poPdfPath);
    if (fs.readFileSync(poPdfPath).subarray(0, 5).toString('ascii') !== '%PDF-') throw new Error('Official purchase-order download was not PDF bytes.');
    checks.push('Different authorized reviewer approved and issued the Purchase Order; official PDF downloaded from its UI link');

    page = operatorPage;
    await page.goto(base + `/purchasing/receipts/create?order=${purchaseOrderId}`);
    await livewireClick(page.locator('[wire\\:click="loadOrder"]'));
    await page.locator('[wire\\:model="form.warehouse_id"]').selectOption(String(fixtures.warehouse_id));
    await page.locator('[wire\\:model="form.delivery_reference"]').fill(`DEMO-EG actual receipt ${tag}`);
    await page.locator('[wire\\:model="form.notes"]').fill('Synthetic fixture receipt: physical acceptance in isolated browser QA.');
    await page.locator('[wire\\:model="form.lines.0.quantity"]').fill('5');
    await page.locator('[wire\\:model="form.lines.1.quantity"]').fill('4');
    await submitAndWait('form[wire\\:submit="save"]', pathname => /^\/purchasing\/receipts\/\d+$/.test(pathname));
    purchaseReceiptId = Number(new URL(page.url()).pathname.split('/').pop());
    await livewireClick(page.locator('[wire\\:click="submit"]'));
    page = reviewerPage;
    await page.goto(base + `/purchasing/receipts/${purchaseReceiptId}`);
    await livewireClick(page.locator('[wire\\:click="post"]'));
    await page.screenshot({ path: path.join(runDir, 'purchase-receipt-posted.png'), animations: 'disabled' });
    checks.push(`Operator ${fixtures.actors.operator.id} saved/submitted receipt ${purchaseReceiptId}; independent poster ${fixtures.actors.reviewer.id} posted it through UI with FIFO and GRNI`);

    // Exercise Inventory through the same scoped, independent-approval browser actors.
    const saveInventoryDraft = async (kind, product, quantity, reason, destination = null, warehouse = fixtures.warehouse_id) => {
      await operatorPage.goto(base + `/inventory/documents/${kind}/create`);
      await operatorPage.locator('[wire\\:model="form.warehouse_id"]').selectOption(String(warehouse));
      if (destination) await operatorPage.locator('[wire\\:model="form.destination_id"]').selectOption(String(destination));
      await operatorPage.locator('[wire\\:model="form.reason"]').fill(reason);
      await operatorPage.locator('[wire\\:model="form.lines.0.product_id"]').selectOption(String(product.id));
      await operatorPage.locator('[wire\\:model="form.lines.0.quantity"]').fill(String(quantity));
      await operatorPage.locator('form[wire\\:submit="save"] button[type="submit"]').scrollIntoViewIfNeeded();
      await operatorPage.screenshot({ path: path.join(runDir, `inventory-${kind}-draft.png`), animations: 'disabled' });
      await livewireClick(operatorPage.locator('form[wire\\:submit="save"] button[type="submit"]'));
      const body = await operatorPage.locator('body').innerText({ timeout: 15000 });
      const recordId = Number((body.match(/مرجع داخلي\s*#(\d+)/) || [])[1]);
      if (!recordId) throw new Error(`Saved ${kind} draft did not expose its persisted reference.`);
      return recordId;
    };

    const inventoryProduct = fixtures.products[1];
    transferId = await saveInventoryDraft('transfer', inventoryProduct, 2, `DEMO-EG ${tag}: synthetic QA transfer`, fixtures.destination_warehouse_id);
    await livewireClick(operatorPage.locator('[wire\\:click="applyAction(\'submit\')"]'));

    await reviewerPage.goto(base + `/inventory/documents/transfer/${transferId}`);
    await livewireClick(reviewerPage.locator('[wire\\:click="applyAction(\'approve\')"]'));
    await operatorPage.goto(base + `/inventory/documents/transfer/${transferId}`);
    await livewireClick(operatorPage.locator('[wire\\:click="applyAction(\'dispatch\')"]'));
    await reviewerPage.goto(base + `/inventory/documents/transfer/${transferId}`);
    await reviewerPage.locator('[wire\\:model="receiptLines.0.accepted"]').fill('1');
    await reviewerPage.locator('[wire\\:model="receiptReason"]').fill(`DEMO-EG ${tag}: full synthetic QA receipt`);
    await livewireClick(reviewerPage.locator('form[wire\\:submit="receivePartial"] button[type="submit"]'));
    await reviewerPage.screenshot({ path: path.join(runDir, 'inventory-transfer-received.png'), animations: 'disabled' });
    const partialTransfer = fixture('inventory-transfer-state', { transfer_id: transferId });
    if (partialTransfer.status !== 'dispatched' || partialTransfer.receipt_count !== 1
      || partialTransfer.receipts.length !== 1 || partialTransfer.receipts[0].accepted !== '1.00000000') {
      throw new Error(`Partial transfer receipt did not persist the expected state: ${JSON.stringify(partialTransfer)}`);
    }
    checks.push(`PostgreSQL confirms partial receipt 1/2 for transfer ${transferId}; transfer remains dispatched with one immutable receipt`);
    await reviewerPage.goto(base + `/inventory/documents/transfer/${transferId}`);
    await reviewerPage.locator('[wire\\:model="receiptLines.0.accepted"]').fill('1');
    await reviewerPage.locator('[wire\\:model="receiptReason"]').fill(`DEMO-EG ${tag}: final synthetic QA receipt`);
    await livewireClick(reviewerPage.locator('form[wire\\:submit="receivePartial"] button[type="submit"]'), 'receivePartial');
    const finalTransfer = fixture('inventory-transfer-state', { transfer_id: transferId });
    if (finalTransfer.status !== 'received' || finalTransfer.receipt_count !== 2
      || finalTransfer.receipts.length !== 2 || finalTransfer.receipts.some(line => line.accepted !== '1.00000000')) {
      throw new Error(`Final transfer receipt did not persist the expected state: ${JSON.stringify(finalTransfer)}`);
    }
    checks.push(`PostgreSQL confirms final receipt 2/2 for transfer ${transferId}; transfer is received`);
    checks.push(`Inventory transfer ${transferId}: operator submitted and dispatched; independent scoped reviewer approved and recorded the actual received quantity through UI`);

    const countBalance = fixture('inventory-balance', { warehouse_id: fixtures.destination_warehouse_id, product_id: inventoryProduct.id });
    countId = await saveInventoryDraft('count', inventoryProduct, countBalance.on_hand, `DEMO-EG ${tag}: synthetic QA count after transfer`, null, fixtures.destination_warehouse_id);
    await livewireClick(operatorPage.locator('[wire\\:click="applyAction(\'submit\')"]'));
    await reviewerPage.goto(base + `/inventory/documents/count/${countId}`);
    await livewireClick(reviewerPage.locator('[wire\\:click="applyAction(\'approve\')"]'));
    await reviewerPage.screenshot({ path: path.join(runDir, 'inventory-count-approved.png'), animations: 'disabled' });
    checks.push(`Inventory count ${countId} independently approved at PostgreSQL's actual post-transfer on-hand quantity (${countBalance.on_hand}); no count variance was fabricated`);

    adjustmentId = await saveInventoryDraft('adjustment', inventoryProduct, -1, `DEMO-EG ${tag}: synthetic QA negative count adjustment`, null, fixtures.destination_warehouse_id);
    await livewireClick(operatorPage.locator('[wire\\:click="applyAction(\'submit\')"]'));
    await reviewerPage.goto(base + `/inventory/documents/adjustment/${adjustmentId}`);
    await livewireClick(reviewerPage.locator('[wire\\:click="applyAction(\'approve\')"]'));
    await operatorPage.goto(base + `/inventory/documents/adjustment/${adjustmentId}`);
    await livewireClick(operatorPage.locator('[wire\\:click="applyAction(\'post\')"]'));
    await operatorPage.screenshot({ path: path.join(runDir, 'inventory-adjustment-posted.png'), animations: 'disabled' });
    checks.push(`Synthetic QA negative inventory adjustment ${adjustmentId} independently approved then posted through the Inventory UI`);

    page = operatorPage;
    await page.goto(base + `/sales-orders/${orderId}/delivery`);
    const backorderedLine = orderFacts.lines.find(line => Number(line.product_id) === Number(replenishmentProduct.id));
    const backorderInput = page.locator(`[wire\\:model="backorderQuantities.${backorderedLine.id}"]`);
    if (await backorderInput.count()) {
      await backorderInput.fill('2');
      await livewireClick(page.locator(`[wire\\:click="allocateBackorder(${backorderedLine.id})"]`));
      checks.push(`Actual backorder for order line ${backorderedLine.id} was explicitly allocated after the authorized PO receipt`);
    } else {
      checks.push(`Order line ${backorderedLine.id} had no remaining backorder after current scoped reservations; no synthetic allocation was submitted`);
    }
    await page.locator(`[wire\\:model="deliveryQuantities.${immediatelyAvailableLine.id}"]`).fill('1');
    await page.locator('[wire\\:model="deliveryReference"]').fill(`DEMO-EG partial delivery ${tag}`);
    await livewireClick(page.locator('[wire\\:click="createDelivery"]'));
    const instructionElement = page.locator('[wire\\:key^="delivery-instruction-"]').first();
    const instructionKey = await instructionElement.getAttribute('wire:key');
    const instructionId = Number(instructionKey.split('-').pop());
    await page.screenshot({ path: path.join(runDir, 'sales-delivery-draft.png'), animations: 'disabled' });
    await livewireClick(page.locator(`[wire\\:click="confirmDelivery(${instructionId})"]`));
    await page.screenshot({ path: path.join(runDir, 'sales-order-partial-delivery.png'), animations: 'disabled' });
    checks.push(`Partial delivery ${instructionId} confirmed after actual PO receipt and backorder allocation; Inventory/FIFO/Accounting posted through Sales UI`);
    const deliveryPdfPromise = page.waitForEvent('download', { timeout: 30000 });
    await page.locator(`a[href*="/deliveries/${instructionId}/pdf"]`).click();
    const deliveryDownload = await deliveryPdfPromise;
    const deliveryPdfPath = path.join(runDir, 'partial-delivery.pdf');
    await deliveryDownload.saveAs(deliveryPdfPath);
    if (fs.readFileSync(deliveryPdfPath).subarray(0, 5).toString('ascii') !== '%PDF-') throw new Error('Delivery document download was not PDF bytes.');

    await page.locator(`[wire\\:model="deliveryQuantities.${backorderedLine.id}"]`).fill('2');
    await page.locator(`[wire\\:model="deliveryQuantities.${immediatelyAvailableLine.id}"]`).fill('1');
    await page.locator('[wire\\:model="deliveryReference"]').fill(`DEMO-EG final delivery ${tag}`);
    await livewireClick(page.locator('[wire\\:click="createDelivery"]'));
    const finalInstructionElement = page.locator('[wire\\:key^="delivery-instruction-"]').first();
    const finalInstructionKey = await finalInstructionElement.getAttribute('wire:key');
    finalDeliveryId = Number(finalInstructionKey.split('-').pop());
    await livewireClick(page.locator(`[wire\\:click="confirmDelivery(${finalDeliveryId})"]`));
    await page.setViewportSize({ width: 390, height: 844 });
    const mobileDelivery = await page.evaluate(() => ({ viewport: innerWidth, document: document.documentElement.scrollWidth }));
    await page.screenshot({ path: path.join(runDir, 'sales-order-final-delivery-mobile.png'), animations: 'disabled' });
    await page.setViewportSize({ width: 1672, height: 941 });
    await page.screenshot({ path: path.join(runDir, 'sales-order-final-delivery.png'), animations: 'disabled' });
    checks.push(`Final delivery ${finalDeliveryId} completed after real receipt posting and actual Inventory backorder allocation; mobile viewport ${mobileDelivery.viewport}px/document ${mobileDelivery.document}px`);

    const deniedContext = await browser.newContext({ viewport: { width: 1672, height: 941 }, locale: 'ar-EG', timezoneId: 'Africa/Cairo' });
    page = await deniedContext.newPage();
    page.setDefaultTimeout(15000);
    page.setDefaultNavigationTimeout(30000);
    await login(fixtures.actors.denied);
    const deniedResponse = await page.goto(base + `/sales-orders/${orderId}`);
    await page.screenshot({ path: path.join(runDir, 'sales-order-denied-lookalike.png'), animations: 'disabled' });
    if (deniedResponse?.status() !== 403) throw new Error(`Administrator display-name lookalike without a role received HTTP ${deniedResponse?.status()}. Expected deny-by-default 403.`);
    checks.push('Branch-scoped user named Administrator without role or grants was denied Sales Order view (HTTP 403)');
    await deniedContext.close();

    page = operatorPage;
    await reviewerContext.close();
  }
  const inventoryPersisted = quoteIssued && acceptanceSaved && orderId && purchaseOrderId && purchaseReceiptId && transferId && countId && adjustmentId && finalDeliveryId
    ? fixture('verify-inventory', { transfer_id: transferId, count_id: countId, adjustment_id: adjustmentId,
      product_id: fixtures.products[1].id, source_warehouse_id: fixtures.warehouse_id,
      destination_warehouse_id: fixtures.destination_warehouse_id }) : null;
  const inventoryPassed = inventoryPersisted?.result === 'PASS';
  if (inventoryPassed) checks.push('Final PostgreSQL reconciliation passed: both warehouse balances equal their complete movement ledgers; transfer FIFO lots, partial/final receipt layers, count, negative adjustment, and source-linked balanced GL journals reconcile.');
  if (browserErrors.length) throw new Error(`Browser console/runtime errors: ${browserErrors.join('; ')}`);
  const result = { result: quoteIssued && acceptanceSaved && orderId && purchaseOrderId && purchaseReceiptId && transferId && countId && adjustmentId && finalDeliveryId && inventoryPassed ? 'PASS' : 'INCOMPLETE', run_id: tag, database: 'erp_elec_new_test on private port 55434', browser: 'Microsoft Edge via Playwright', checks, browser_errors: browserErrors, livewire_traffic: livewireTraffic, quote_id: quotationId, quote_issued: quoteIssued, official_pdf: officialPdf, acceptance_saved: acceptanceSaved, converted_sales_order_id: orderId, purchase_order_id: purchaseOrderId, purchase_receipt_id: purchaseReceiptId, inventory_transfer_id: transferId, inventory_count_id: countId, inventory_adjustment_id: adjustmentId, final_delivery_id: finalDeliveryId, issue_capture: issueCapture, issuance_page_text: issuanceText, postgresql_verification: { quotation: quotePersisted, inventory: inventoryPersisted }, screenshot_files: fs.readdirSync(runDir).filter(f => f.endsWith('.png')), limits: [] };
  fs.writeFileSync(path.join(runDir, 'result.json'), JSON.stringify(result, null, 2) + '\n');
  process.stdout.write(`Evidence: ${runDir}\n`);
}
main().catch(async error => {
  const result = { result: 'FAIL', run_id: tag, database: 'erp_elec_new_test on private port 55434', checks, failure: String(error.message).slice(0, 4500), browser_errors: browserErrors, livewire_traffic: livewireTraffic };
  if (page && !page.isClosed()) await page.screenshot({ path: path.join(runDir, 'failure-state.png'), animations: 'disabled' }).catch(() => {});
  fs.writeFileSync(path.join(runDir, 'result.json'), JSON.stringify(result, null, 2) + '\n');
  process.stderr.write(`${result.failure}\n`); process.exitCode = 1;
}).finally(async () => {
  if (browser) await browser.close().catch(() => {});
  if (server && server.exitCode === null) { server.kill(); await new Promise(r => setTimeout(r, 800)); }
  if (serverLog) {
    fs.closeSync(serverLog);
    const logPath = path.join(runDir, 'server.log.redacted.txt');
    if (fs.existsSync(logPath)) fs.writeFileSync(logPath, fs.readFileSync(logPath, 'utf8').replaceAll(password, '[REDACTED]').replaceAll(`phase0-operator-${tag}@example.test`, '[TEST_OPERATOR]').replaceAll(`phase0-reviewer-${tag}@example.test`, '[TEST_REVIEWER]').replaceAll(`phase0-bootstrap-${tag}@example.test`, '[TEST_BOOTSTRAP]'));
  }
  fs.rmSync(temp, { recursive: true, force: true });
});
