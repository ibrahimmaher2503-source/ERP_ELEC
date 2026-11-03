'use strict';

// M08 desktop/mobile browser acceptance on a disposable PostgreSQL clone.
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const crypto = require('node:crypto');
const net = require('node:net');
const { spawn, spawnSync } = require('node:child_process');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '../..');
const router = path.join(root, 'tests/E2E/support/laravel-static-router.php');
const fixturePath = path.join(root, 'tests/E2E/support/prepare-phase3-maintenance-browser.php');
const workRoot = path.join(root, '.tmp/phase3-maintenance-browser');
const dbPort = Number(process.env.PHASE3_BROWSER_DB_PORT || 55440);
const port = crypto.randomInt(9501, 9999);
const base = `http://127.0.0.1:${port}`;
const browserTestAt = new Date().toISOString();
const browserTestEpoch = Date.parse(browserTestAt);
const tag = crypto.randomBytes(5).toString('hex').toUpperCase();
const runDir = path.join(workRoot, `run-${tag}`);
const storage = path.join(workRoot, `storage-${tag}`);
const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'erp-phase3-maintenance-'));
const cachedBrowserRoot = path.join(process.env.LOCALAPPDATA || '', 'ms-playwright');
const cachedChromium = fs.existsSync(cachedBrowserRoot)
  ? fs.readdirSync(cachedBrowserRoot).filter(name => /^chromium-\d+$/.test(name)).sort((a, b) => Number(b.slice(9)) - Number(a.slice(9)))
    .map(name => path.join(cachedBrowserRoot, name, 'chrome-win64', 'chrome.exe')).find(fs.existsSync)
  : null;
const browserExecutable = process.env.PLAYWRIGHT_EXECUTABLE_PATH || cachedChromium || 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe';
const password = crypto.randomBytes(32).toString('base64url');
fs.mkdirSync(runDir, { recursive: true });
fs.mkdirSync(path.join(temp, 'views'), { recursive: true });
// Run the disposable app with local filesystem semantics: Livewire's testing
// environment forces an implicit tmp-for-tests disk that this isolated app
// does not configure, while local keeps uploads inside this run's storage root.
const env = { ...process.env, APP_ENV: 'local', APP_URL: base, APP_DEBUG: 'false',
  APP_CONFIG_CACHE: path.join(temp, 'config.php'), APP_ROUTES_CACHE: path.join(temp, 'routes.php'),
  DB_CONNECTION: 'pgsql', DB_HOST: '127.0.0.1', DB_PORT: String(dbPort), DB_DATABASE: 'erp_elec_new_test', DB_URL: '',
  SESSION_DRIVER: 'file', CACHE_STORE: 'array', QUEUE_CONNECTION: 'sync', MAIL_MAILER: 'array', LOG_CHANNEL: 'stderr',
  BROADCAST_CONNECTION: 'null', VIEW_COMPILED_PATH: path.join(temp, 'views'), LARAVEL_STORAGE_PATH: storage,
  PHASE3_STORAGE_ROOT: storage, PHASE3_BROWSER_DB_PORT: String(dbPort), PHASE3_BROWSER_PASSWORD: password,
  PHASE3_BROWSER_TEST_AT: browserTestAt };
const checks = [];
const browserErrors = [];
const livewireTraffic = [];
let browser;
let server;
let serverLog;
let fixture;
let workOrderId;

function phpJson(extraEnv = {}) {
  const result = spawnSync('php', ['-d', 'opcache.enable_cli=0', '-d', 'memory_limit=512M', fixturePath], {
    cwd: root, env: { ...env, ...extraEnv }, encoding: 'utf8', windowsHide: true, maxBuffer: 4 * 1024 * 1024,
  });
  if (result.status !== 0) throw new Error(`Guarded fixture helper failed (${result.status}): ${(result.stderr || result.stdout || '').slice(-1800)}`);
  try { return JSON.parse(result.stdout); } catch { throw new Error(`Fixture helper returned no JSON: ${(result.stderr || result.stdout || '').slice(0, 500)}`); }
}
function observe(page) {
  page.on('pageerror', error => browserErrors.push({ kind: 'pageerror', url: page.url(), message: String(error.stack || error.message).slice(0, 900) }));
  page.on('console', message => { if (message.type() === 'error') browserErrors.push({ kind: 'console', url: page.url(), message: message.text().slice(0, 300) }); });
  page.on('response', response => {
    if (response.status() >= 500) browserErrors.push({ kind: 'http', url: response.url(), status: response.status() });
    if (/\/livewire(?:-[^/]+)?\/update/.test(response.url())) livewireTraffic.push({ status: response.status(), path: new URL(response.url()).pathname });
  });
  page.on('request', request => {
    if (!/\/livewire(?:-[^/]+)?\/update/.test(request.url())) return;
    let calls = [];
    try {
      const payload = JSON.parse(request.postData() || '{}');
      calls = (payload.components || []).flatMap(component => component.calls || []).map(call => call.method || call.path || 'update');
    } catch {}
    livewireTraffic.push({ request: new URL(request.url()).pathname, calls });
  });
  page.on('requestfailed', request => {
    if (/\/livewire(?:-[^/]+)?\/update/.test(request.url())) livewireTraffic.push({ failure: request.failure()?.errorText || 'failed', path: new URL(request.url()).pathname });
  });
}
async function freePort(value) {
  await new Promise((resolve, reject) => {
    const probe = net.createServer();
    probe.once('error', error => reject(new Error(error.code === 'EADDRINUSE' ? `Browser server port ${value} is occupied.` : error.message)));
    probe.listen(value, '127.0.0.1', () => probe.close(resolve));
  });
}
async function waitForServer() {
  const deadline = Date.now() + 30000;
  while (Date.now() < deadline) {
    if (server.exitCode !== null) throw new Error('Laravel browser server exited during startup.');
    try { const response = await fetch(base + '/login'); if (response.status === 200) return; } catch {}
    await new Promise(resolve => setTimeout(resolve, 250));
  }
  throw new Error('Laravel browser server did not become ready.');
}
function wireModel(key) {
  return `[wire\\:model="${key}"], [wire\\:model\\.live="${key}"], [wire\\:model\\.live\\.debounce\\.300ms="${key}"]`;
}
async function setModel(page, key, value) {
  const target = page.locator(wireModel(key)).first();
  await target.waitFor({ state: 'visible' });
  await target.fill(String(value));
}
async function selectModel(page, key, value) {
  const target = page.locator(wireModel(key)).first();
  try {
    await target.waitFor({ state: 'visible', timeout: 8000 });
  } catch (error) {
    const body = await page.locator('#main-content').innerText().catch(() => '');
    const evidence = `${key}-missing`;
    await page.screenshot({ path: path.join(runDir, `${evidence}.png`), fullPage: true, animations: 'disabled' }).catch(() => {});
    throw new Error(`Select ${key} was not visible: ${JSON.stringify({ url: page.url(), title: await page.title().catch(() => ''), body: body.slice(0, 5000) })}\n${error.message}`);
  }
  await target.selectOption(String(value));
}
function isLivewireActionResponse(response, action) {
  if (!/\/livewire(?:-[^/]+)?\/update/.test(response.url()) || response.request().method() !== 'POST') return false;
  try {
    const payload = JSON.parse(response.request().postData() || '{}');
    return (payload.components || []).some(component => (component.calls || []).some(call => call.method === action || call.path === action));
  } catch {
    return false;
  }
}
async function waitAction(page, locator, label) {
  const wireExpression = await locator.getAttribute('wire:click');
  const action = wireExpression?.match(/^\s*([A-Za-z_$][\w$]*)/)?.[1];
  if (!action) throw new Error(`${label} control has no parseable Livewire action: ${wireExpression}`);
  const responsePromise = page.waitForResponse(response => isLivewireActionResponse(response, action), { timeout: 60000 }).catch(error => ({ timeoutError: error }));
  try { await locator.click({ timeout: 10000 }); }
  catch (error) {
    const element = await locator.evaluate(node => { const r = node.getBoundingClientRect(), s = getComputedStyle(node); return { html: node.outerHTML.slice(0, 500), rect: { x: r.x, y: r.y, width: r.width, height: r.height }, disabled: node.disabled, display: s.display, visibility: s.visibility, opacity: s.opacity }; }).catch(e => ({ evaluateError: e.message }));
    let renderer = null;
    try {
      const cdp = await page.context().newCDPSession(page);
      await cdp.send('Debugger.enable');
      const paused = new Promise(resolve => { const timeout = setTimeout(() => resolve(null), 1500); cdp.once('Debugger.paused', event => { clearTimeout(timeout); resolve({ reason: event.reason, stack: event.callFrames.slice(0, 12).map(frame => ({ function: frame.functionName, url: frame.url, line: frame.location.lineNumber + 1 })) }); }); });
      await cdp.send('Debugger.pause').catch(() => {});
      renderer = await paused;
      await cdp.send('Debugger.resume').catch(() => {});
      await cdp.detach().catch(() => {});
    } catch (e) { renderer = { error: e.message }; }
    throw new Error(`${label} activation press failed: ${error.message}; element=${JSON.stringify(element)}; renderer=${JSON.stringify(renderer)}`);
  }
  const response = await responsePromise;
  if (response.timeoutError) throw new Error(`${label} did not produce a Livewire update: ${response.timeoutError.message}`);
  if (response.status() !== 200) throw new Error(`${label} returned Livewire HTTP ${response.status()}.`);
}
async function submit(page, wireName) {
  const form = page.locator(`form[wire\\:submit="${wireName}"]`).first();
  await form.waitFor({ state: 'visible' });
  const responsePromise = page.waitForResponse(response => isLivewireActionResponse(response, wireName), { timeout: 60000 }).catch(error => ({ timeoutError: error }));
  await form.evaluate(element => element.requestSubmit());
  const response = await responsePromise;
  if (response.timeoutError) throw new Error(`submit ${wireName} did not produce a Livewire update: ${response.timeoutError.message}`);
  if (response.status() !== 200) throw new Error(`submit ${wireName} returned Livewire HTTP ${response.status()}.`);
}
async function clickWire(page, exact, label = exact) {
  // Resolve the exact action attribute against the live DOM. Flux/Livewire may
  // replace controls between lookup and activation, so retain a live locator.
  const actions = page.locator('[wire\\:click]');
  const candidates = await actions.all();
  let index = -1;
  for (let i = 0; i < candidates.length; i++) {
    if (await candidates[i].getAttribute('wire:click') === exact && await candidates[i].isVisible()) {
      index = i;
      break;
    }
  }
  const locator = index >= 0 ? actions.nth(index) : page.locator('[wire\\:click="__missing_action__"]');
  if (index < 0) {
    const body = await page.locator('body').innerText().catch(() => '');
    const controlsFound = await page.locator('[wire\\:click]').evaluateAll(elements => elements.map(element => ({ action: element.getAttribute('wire:click'), visible: element.getClientRects().length > 0 }))).catch(() => []);
    throw new Error(`${label} control missing: ${JSON.stringify({ url: page.url(), exact, controls: controlsFound, body: body.slice(0, 800) })}`);
  }
  try { await locator.waitFor({ state: 'visible', timeout: 8000 }); }
  catch {
    const body = await page.locator('body').innerText().catch(() => '');
    await page.screenshot({ path: path.join(runDir, `${label.replace(/[^a-z0-9]+/gi, '-')}-diagnostic.png`), animations: 'disabled' }).catch(() => {});
    const matching = await page.locator('[wire\\:click]').evaluateAll((elements, wanted) => elements.filter(element => element.getAttribute('wire:click') === wanted).map(element => { const r = element.getBoundingClientRect(), s = getComputedStyle(element); return { action: element.getAttribute('wire:click'), rect: { x: r.x, y: r.y, width: r.width, height: r.height }, display: s.display, visibility: s.visibility, opacity: s.opacity, disabled: element.disabled, html: element.outerHTML.slice(0, 300) }; }), exact).catch(() => []);
    throw new Error(`${label} control missing: ${JSON.stringify({ url: page.url(), exact, matching, body: body.slice(0, 1000) })}`);
  }
  await waitAction(page, locator, label);
}
async function login(page, actor) {
  await page.goto(base + '/login');
  await page.locator('input[name="email"]').fill(actor.email);
  await page.locator('input[name="password"]').fill(password);
  await page.locator('[data-test="login-button"]').click();
  await page.waitForURL(url => url.pathname === '/dashboard');
}
function cairoLocal(minutesFromNow = 0) {
  const date = new Date(browserTestEpoch + minutesFromNow * 60000);
  const parts = new Intl.DateTimeFormat('en-GB', { timeZone: 'Africa/Cairo', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' })
    .formatToParts(date).reduce((result, item) => (result[item.type] = item.value, result), {});
  return `${parts.year}-${parts.month}-${parts.day}T${parts.hour}:${parts.minute}`;
}
function subtractDecimal8(value, quantity) {
  const units = input => {
    const [whole, fraction = ''] = String(input).split('.');
    return BigInt(whole + fraction.padEnd(8, '0').slice(0, 8));
  };
  const remaining = units(value) - units(quantity);
  return `${remaining / 100000000n}.${String(remaining % 100000000n).padStart(8, '0')}`;
}
async function waitForWireControls(page, expected, timeout = 10000) {
  const deadline = Date.now() + timeout;
  while (Date.now() < deadline) {
    for (const form of await page.locator('form').all()) {
      if (expected.includes(await form.getAttribute('wire:submit')) && await form.isVisible().catch(() => false)) return;
    }
    for (const button of await page.locator('[wire\\:click]').all()) {
      if (expected.includes(await button.getAttribute('wire:click')) && await button.isVisible().catch(() => false)) return;
    }
    await page.waitForTimeout(200);
  }
  const body = await page.locator('#main-content').innerText().catch(() => '');
  await page.screenshot({ path: path.join(runDir, `tab-controls-${Date.now()}.png`), fullPage: true, animations: 'disabled' }).catch(() => {});
  throw new Error(`Expected visible Livewire controls ${JSON.stringify(expected)} on ${page.url()}; main=${body.slice(0, 1800)}`);
}
async function tab(page, name, requiredActions = null) {
  const link = page.locator(`a[href*="tab=${name}"]:visible`).first();
  await link.waitFor({ state: 'visible', timeout: 10000 });
  const destination = new URL(await link.getAttribute('href'), page.url());
  if (destination.searchParams.get('tab') !== name) throw new Error(`Tab ${name} link has invalid destination ${destination.href}.`);
  const navigation = page.waitForURL(url => url.pathname === destination.pathname && url.searchParams.get('tab') === name, { timeout: 30000 });
  await link.click({ timeout: 10000 });
  await navigation;
  const sectionForms = { assignments: 'assign', visits: ['schedule', 'recordVisit'], labor: 'recordLabor', billing: 'proposeCommercial' };
  if (requiredActions) await waitForWireControls(page, Array.isArray(requiredActions) ? requiredActions : [requiredActions]);
  if (sectionForms[name]) {
    const forms = Array.isArray(sectionForms[name]) ? sectionForms[name] : [sectionForms[name]];
    await page.locator(forms.map(action => `form[wire\\:submit="${action}"]`).join(', ')).first().waitFor({ state: 'visible', timeout: 10000 });
  }
  checks.push({ browser_tab_navigation: name, href: page.url() });
}
async function finish(page, action, label) { await clickWire(page, `changeState('${action}')`, label); }
async function pageViewport(page, viewport, label, expected) {
  await page.setViewportSize(viewport);
  const response = await page.reload();
  if (!response || response.status() !== 200) {
    throw new Error(`${label} reload failed: ${JSON.stringify({ status: response?.status(), url: page.url(), body: (await page.locator('body').innerText().catch(() => '')).slice(0, 800) })}`);
  }
  await page.locator('#main-content').waitFor({ state: 'visible', timeout: 10000 });
  const content = await page.locator('#main-content').innerText();
  if (!content.includes(expected)) throw new Error(`${label} reload did not show persisted value: ${expected}`);
  const metrics = await page.evaluate(() => ({ viewport: document.documentElement.clientWidth, document: document.documentElement.scrollWidth }));
  if (metrics.document > metrics.viewport + 4) throw new Error(`${label} has horizontal overflow at ${metrics.viewport}px.`);
  await page.screenshot({ path: path.join(runDir, `${label}.png`), animations: 'disabled' });
  checks.push({ page: label, viewport: viewport.width, persisted_value: expected, ...metrics });
}

async function main() {
  if (dbPort !== 55440) throw new Error('M08 browser acceptance requires the dedicated disposable clone at 127.0.0.1:55440.');
  await freePort(port);
  fixture = phpJson({ PHASE3_FIXTURE_MODE: 'prepare' });
  if (fixture.database !== 'erp_elec_new_test' || fixture.port !== dbPort) throw new Error('Fixture helper reported an unexpected PostgreSQL identity.');
  serverLog = fs.openSync(path.join(runDir, 'server.log.redacted.txt'), 'w');
  server = spawn('php', ['-d', 'memory_limit=512M', '-d', 'opcache.enable_cli=0', '-S', `127.0.0.1:${port}`, '-t', '.', router], { cwd: path.join(root, 'public'), env,
    stdio: ['ignore', serverLog, serverLog], windowsHide: true });
  await waitForServer();
  browser = await chromium.launch({ executablePath: browserExecutable, headless: true, timeout: 30000 });
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'ar-EG', timezoneId: 'Africa/Cairo', colorScheme: 'light' });
  const dispatcherPage = await context.newPage();
  observe(dispatcherPage);
  await login(dispatcherPage, fixture.actors.dispatcher);

  await dispatcherPage.setViewportSize({ width: 390, height: 844 });
  await dispatcherPage.goto(base + '/maintenance/requests/create');
  await selectModel(dispatcherPage, 'form.branch_id', fixture.branch_id);
  await setModel(dispatcherPage, 'customerSearch', fixture.customer_code);
  await dispatcherPage.waitForTimeout(500);
  await selectModel(dispatcherPage, 'form.customer_id', fixture.customer_id);
  await selectModel(dispatcherPage, 'form.responsible_id', fixture.actors.technician.id);
  await selectModel(dispatcherPage, 'form.priority', 'high');
  await setModel(dispatcherPage, 'form.reported_at', cairoLocal(-10));
  await setModel(dispatcherPage, 'form.description', 'Browser acceptance: inspect the site lighting panel and repair a loose terminal.');
  await submit(dispatcherPage, 'save');
  try { await dispatcherPage.waitForURL(url => /\/maintenance\/requests\/\d+$/.test(url.pathname), { timeout: 6000 }); }
  catch {
    const body = await dispatcherPage.locator('#main-content').innerText().catch(() => '');
    await dispatcherPage.screenshot({ path: path.join(runDir, 'request-save-diagnostic.png'), animations: 'disabled' }).catch(() => {});
    throw new Error(`Request form did not navigate after save: ${JSON.stringify({ url: dispatcherPage.url(), body: body.slice(0, 1600) })}`);
  }
  const requestId = Number(dispatcherPage.url().split('/').at(-1));
  await setModel(dispatcherPage, 'form.description', 'Updated after reopening the same draft: inspect and repair the site lighting panel terminal.');
  await submit(dispatcherPage, 'save');
  await dispatcherPage.waitForURL(url => url.pathname === `/maintenance/requests/${requestId}`);
  const savedRequest = phpJson({ PHASE3_FIXTURE_MODE: 'request-readback', PHASE3_REQUEST_ID: String(requestId) }).request;
  if (!savedRequest || savedRequest.id !== requestId || savedRequest.version !== 2 || !savedRequest.description.startsWith('Updated after reopening')) {
    throw new Error(`Reopening and saving the existing request draft did not preserve a single incremented record: ${JSON.stringify(savedRequest)}`);
  }
  checks.push({ browser_reopened_and_resaved_request: { id: requestId, version: savedRequest.version } });
  const requestMobile = await dispatcherPage.evaluate(() => ({ viewport: document.documentElement.clientWidth, width: document.documentElement.scrollWidth }));
  if (requestMobile.width > requestMobile.viewport + 4) throw new Error('Mobile maintenance request form overflows horizontally.');
  await dispatcherPage.screenshot({ path: path.join(runDir, 'request-created-mobile.png'), animations: 'disabled' });
  checks.push({ functional_mobile_request_saved: requestId, ...requestMobile });
  await clickWire(dispatcherPage, "changeState('submit')", 'submit maintenance request');
  await clickWire(dispatcherPage, "changeState('accept')", 'accept maintenance request');
  await clickWire(dispatcherPage, 'createWorkOrder', 'convert accepted request into work order');
  await dispatcherPage.waitForURL(url => /\/maintenance\/work-orders\/\d+$/.test(url.pathname));
  workOrderId = Number(dispatcherPage.url().split('/').at(-1));
  await dispatcherPage.setViewportSize({ width: 1440, height: 900 });

  const templatePage = await context.newPage();
  observe(templatePage);
  await templatePage.goto(base + '/maintenance/checklists');
  const checklistCode = `M08-BR-${tag}`;
  const checklistTitle = `Browser service checklist ${tag}`;
  await selectModel(templatePage, 'template.branch_id', fixture.branch_id);
  await setModel(templatePage, 'template.code', checklistCode);
  await setModel(templatePage, 'template.title', checklistTitle);
  await setModel(templatePage, 'template.items.0.key', 'terminal_check');
  await setModel(templatePage, 'template.items.0.label', 'Terminal and isolation verified');
  await submit(templatePage, 'saveTemplate');
  await templatePage.getByText(checklistTitle, { exact: false }).waitFor({ state: 'visible' });
  checks.push({ browser_created_checklist: checklistCode });
  await templatePage.close();

  const workOrderResponse = await dispatcherPage.goto(base + `/maintenance/work-orders/${workOrderId}`, { waitUntil: 'domcontentloaded', timeout: 30000 });
  if (workOrderResponse.status() !== 200) throw new Error(`Dispatcher could not open the new work order: HTTP ${workOrderResponse.status()}.`);
  await tab(dispatcherPage, 'assignments');
  await selectModel(dispatcherPage, 'assignment.technician_id', fixture.actors.technician.id);
  await setModel(dispatcherPage, 'assignment.starts_at', cairoLocal(-120));
  await setModel(dispatcherPage, 'assignment.ends_at', cairoLocal(120));
  await submit(dispatcherPage, 'assign');
  let state = phpJson({ PHASE3_FIXTURE_MODE: 'readback', PHASE3_WORK_ORDER_ID: String(workOrderId) });
  const assignment = state.assignments.find(row => Number(row.technician_id) === fixture.actors.technician.id && row.active);
  if (!assignment) throw new Error('Dispatcher form did not persist an active assignment for the selected technician.');
  const onHandBeforeRequest = String(fixture.stock_source.on_hand);
  if (!/^\d+\.\d{8}$/.test(onHandBeforeRequest) || BigInt(onHandBeforeRequest.replace('.', '')) < 100000000n) {
    throw new Error(`Guarded FIFO stock source has insufficient quantity for browser issue: ${onHandBeforeRequest}`);
  }
  await tab(dispatcherPage, 'visits');
  await selectModel(dispatcherPage, 'visit.assignment_id', assignment.id);
  await setModel(dispatcherPage, 'visit.scheduled_at', cairoLocal(-60));
  const templateId = Number(await (async () => {
    const select = dispatcherPage.locator('[wire\\:model="visit.template_id"]');
    await select.waitFor({ state: 'visible' });
    return select.locator('option').filter({ hasText: checklistTitle }).getAttribute('value');
  })());
  await selectModel(dispatcherPage, 'visit.template_id', templateId);
  await submit(dispatcherPage, 'schedule');
  state = phpJson({ PHASE3_FIXTURE_MODE: 'readback', PHASE3_WORK_ORDER_ID: String(workOrderId) });
  const visit = state.visits.find(row => row.status === 'planned');
  if (!visit) throw new Error('Dispatcher form did not persist the planned visit.');

  await tab(dispatcherPage, 'parts', 'addPart');
  await selectModel(dispatcherPage, 'part.product_id', fixture.product_id);
  await selectModel(dispatcherPage, 'part.warehouse_id', fixture.warehouse_id);
  await setModel(dispatcherPage, 'part.quantity', '1.00000000');
  await submit(dispatcherPage, 'addPart');
  state = phpJson({ PHASE3_FIXTURE_MODE: 'readback', PHASE3_WORK_ORDER_ID: String(workOrderId) });
  const requirement = state.part_requirements[0];
  if (!requirement || state.inventory_on_hand !== onHandBeforeRequest) throw new Error(`Part demand did not persist independently of stock on hand: ${JSON.stringify({ requirements: state.part_requirements, inventoryOnHand: state.inventory_on_hand, expectedOnHand: onHandBeforeRequest })}`);
  await selectModel(dispatcherPage, 'requirementId', requirement.id);
  await setModel(dispatcherPage, 'quantity', '1.00000000');
  await clickWire(dispatcherPage, 'reserve', 'reserve maintenance part');
  state = phpJson({ PHASE3_FIXTURE_MODE: 'readback', PHASE3_WORK_ORDER_ID: String(workOrderId) });
  const reservation = state.part_reservations.find(row => row.requirement_id === requirement.id);
  if (!reservation || state.inventory_on_hand !== onHandBeforeRequest) throw new Error('Reservation did not persist or incorrectly changed physical stock.');
  checks.push({ browser_part_request_and_reservation: requirement.id, inventory_on_hand_unchanged: state.inventory_on_hand });

  await tab(dispatcherPage, 'documents');
  const attachmentBytes = Buffer.from('%PDF-1.4\n% Synthetic M08 browser attachment\n1 0 obj << /Type /Catalog >> endobj\n%%EOF\n');
  const attachmentPath = path.join(temp, 'maintenance-evidence.pdf');
  fs.writeFileSync(attachmentPath, attachmentBytes);
  const attachmentInput = dispatcherPage.locator('[wire\\:model="attachment"]');
  const uploadFinished = attachmentInput.evaluate(element => new Promise((resolve, reject) => {
    const timeout = setTimeout(() => reject(new Error('Livewire file upload did not finish within 30 seconds.')), 30000);
    element.addEventListener('livewire-upload-finish', () => { clearTimeout(timeout); resolve(); }, { once: true });
    element.addEventListener('livewire-upload-error', () => { clearTimeout(timeout); reject(new Error('Livewire file upload emitted livewire-upload-error.')); }, { once: true });
  }));
  await attachmentInput.setInputFiles(attachmentPath);
  await uploadFinished;
  await selectModel(dispatcherPage, 'attachmentVisit', visit.id);
  await submit(dispatcherPage, 'saveAttachment');
  state = phpJson({ PHASE3_FIXTURE_MODE: 'readback', PHASE3_WORK_ORDER_ID: String(workOrderId) });
  const attachment = state.documents[0];
  if (!attachment || attachment.visit_id !== visit.id || attachment.sha256 !== crypto.createHash('sha256').update(attachmentBytes).digest('hex')) {
    throw new Error(`Private attachment did not persist with the selected planned visit and expected hash: ${JSON.stringify(state.documents)}`);
  }
  const attachmentResponse = await context.request.get(base + `/maintenance/documents/${attachment.id}`);
  const attachmentResponseBytes = await attachmentResponse.body();
  const cacheControl = (attachmentResponse.headers()['cache-control'] || '').toLowerCase().split(',').map(value => value.trim());
  if (attachmentResponse.status() !== 200 || !cacheControl.includes('private') || !cacheControl.includes('no-store')
    || !attachmentResponse.headers()['content-disposition']?.includes('attachment') || !attachmentResponseBytes.equals(attachmentBytes)) {
    throw new Error(`Authorized private attachment download mismatch: ${JSON.stringify({ status: attachmentResponse.status(), cacheControl, disposition: attachmentResponse.headers()['content-disposition'] || null, contentType: attachmentResponse.headers()['content-type'] || null, expectedBytes: attachmentBytes.length, actualBytes: attachmentResponseBytes.length, actualSha256: crypto.createHash('sha256').update(attachmentResponseBytes).digest('hex'), expectedSha256: crypto.createHash('sha256').update(attachmentBytes).digest('hex'), bodyPreview: attachmentResponseBytes.subarray(0, 160).toString('utf8') })}`);
  }
  checks.push({ browser_private_attachment_upload_download: attachment.id, sha256: attachment.sha256, bytes: attachmentBytes.length });

  const techContext = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'ar-EG', timezoneId: 'Africa/Cairo', colorScheme: 'light' });
  const technicianPage = await techContext.newPage();
  observe(technicianPage);
  await login(technicianPage, fixture.actors.technician);
  await technicianPage.goto(base + `/maintenance/work-orders/${workOrderId}`);
  await tab(technicianPage, 'completion');
  await finish(technicianPage, 'start', 'reject start without required reason');
  state = phpJson({ PHASE3_FIXTURE_MODE: 'readback', PHASE3_WORK_ORDER_ID: String(workOrderId) });
  if (state.work_order.status !== 'assigned') throw new Error('Starting without a required reason changed the work-order state.');
  if (await technicianPage.locator('#livewire-error[open]').count()) throw new Error('Missing-reason validation opened the Livewire exception dialog.');
  checks.push({ browser_missing_reason_validation: 'work order remained assigned without a Livewire error dialog' });
  await setModel(technicianPage, 'reason', 'Technician is ready to begin the assigned maintenance work.');
  await finish(technicianPage, 'start', 'start assigned work');
  await tab(technicianPage, 'visits');
  await technicianPage.setViewportSize({ width: 390, height: 844 });
  const visitViewport = await technicianPage.evaluate(() => ({ viewport: document.documentElement.clientWidth, width: document.documentElement.scrollWidth }));
  if (visitViewport.width > visitViewport.viewport + 4) throw new Error('Mobile visit recording form overflows horizontally.');
  await technicianPage.screenshot({ path: path.join(runDir, 'visit-recording-mobile.png'), animations: 'disabled' });
  checks.push({ functional_mobile_visit_recording_form: visitViewport });
  await selectModel(technicianPage, 'visitId', visit.id);
  const arrival = cairoLocal(-30);
  const departure = cairoLocal(-15);
  await setModel(technicianPage, 'record.arrival_at', arrival);
  await setModel(technicianPage, 'record.departure_at', departure);
  await setModel(technicianPage, 'record.work_performed', 'Replaced loose terminal and verified the panel output.');
  await setModel(technicianPage, 'record.diagnosis', 'Loose conductor found at terminal block.');
  await setModel(technicianPage, 'record.acknowledgment', 'Customer confirms the service visit.');
  await selectModel(technicianPage, 'results.terminal_check.result', 'complete');
  await submit(technicianPage, 'recordVisit');
  state = phpJson({ PHASE3_FIXTURE_MODE: 'readback', PHASE3_WORK_ORDER_ID: String(workOrderId) });
  if (!state.visits.some(row => row.id === visit.id && row.status === 'recorded' && row.recorded_by === fixture.actors.technician.id)) {
    throw new Error('Technician browser did not persist the visit under the assigned technician.');
  }
  checks.push({ browser_recorded_visit: visit.id, technician_id: fixture.actors.technician.id });
  state = phpJson({ PHASE3_FIXTURE_MODE: 'readback', PHASE3_WORK_ORDER_ID: String(workOrderId) });
  const persistedReservation = state.part_reservations.find(row => row.requirement_id === requirement.id);
  await tab(technicianPage, 'parts', ['issue', 'usePart']);
  await selectModel(technicianPage, 'requirementId', requirement.id);
  await selectModel(technicianPage, 'reservationId', persistedReservation.reservation_id);
  await setModel(technicianPage, 'quantity', '1.00000000');
  await clickWire(technicianPage, 'issue', 'issue reserved maintenance part');
  state = phpJson({ PHASE3_FIXTURE_MODE: 'readback', PHASE3_WORK_ORDER_ID: String(workOrderId) });
  const issue = state.part_issues[0];
  if (!issue || !issue.movement_id || !issue.journal_id || issue.quantity !== '1.00000000') {
    throw new Error(`Browser part issue did not persist linked M06 movement and M11 journal: ${JSON.stringify(state.part_issues)}`);
  }
  await selectModel(technicianPage, 'usageVisit', visit.id);
  await setModel(technicianPage, 'quantity', '1.00000000');
  await clickWire(technicianPage, 'usePart', 'record issued part usage');
  state = phpJson({ PHASE3_FIXTURE_MODE: 'readback', PHASE3_WORK_ORDER_ID: String(workOrderId) });
  const expectedOnHand = subtractDecimal8(onHandBeforeRequest, '1.00000000');
  if (state.inventory_on_hand !== expectedOnHand) throw new Error(`M06 stock readback did not reflect the source-linked issue: expected ${expectedOnHand}, got ${state.inventory_on_hand}`);
  checks.push({ browser_part_issue: issue.id, movement_id: issue.movement_id, journal_id: issue.journal_id, inventory_on_hand: state.inventory_on_hand });
  await tab(technicianPage, 'labor');
  await selectModel(technicianPage, 'labor.visit_id', visit.id);
  await setModel(technicianPage, 'labor.starts_at', arrival);
  await setModel(technicianPage, 'labor.ends_at', departure);
  await setModel(technicianPage, 'labor.work_type', 'Electrical terminal repair');
  await submit(technicianPage, 'recordLabor');
  await tab(dispatcherPage, 'costs');
  const costText = await dispatcherPage.locator('dl').innerText();
  if (!costText.includes(issue.amount)) throw new Error(`Cost screen did not show the actual FIFO parts cost ${issue.amount}.`);
  checks.push({ browser_cost_screen_fifo_amount: issue.amount });
  await tab(technicianPage, 'completion');
  await setModel(technicianPage, 'reason', 'Required visit, checklist, labor and customer acknowledgement are complete.');
  await finish(technicianPage, 'complete', 'complete work order');

  const reviewerContext = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'ar-EG', timezoneId: 'Africa/Cairo' });
  const reviewerPage = await reviewerContext.newPage();
  observe(reviewerPage);
  await login(reviewerPage, fixture.actors.reviewer);
  await reviewerPage.goto(base + `/maintenance/work-orders/${workOrderId}`);
  await tab(reviewerPage, 'completion');
  await setModel(reviewerPage, 'reason', 'Independent reviewer confirms the completed service report.');
  await finish(reviewerPage, 'verify', 'verify completed service');
  state = phpJson({ PHASE3_FIXTURE_MODE: 'readback', PHASE3_WORK_ORDER_ID: String(workOrderId) });
  const completion = state.completions[0];
  if (!completion?.official_document_id) throw new Error('Verified service did not persist its official report document identity.');
  const reportResponse = await reviewerContext.request.get(base + `/maintenance/work-orders/${workOrderId}/reports/${completion.id}`);
  const reportBytes = await reportResponse.body();
  if (reportResponse.status() !== 200 || !reportResponse.headers()['content-type']?.includes('application/pdf')
    || !reportResponse.headers()['content-disposition']?.includes('attachment')
    || !reportResponse.headers()['cache-control']?.includes('private') || reportBytes.subarray(0, 4).toString() !== '%PDF') {
    throw new Error('Authorized service report download did not return a private PDF attachment.');
  }
  checks.push({ browser_private_report_download: completion.id, bytes: reportBytes.length });
  await setModel(reviewerPage, 'reason', 'Verified service report retained for the customer.');
  await finish(reviewerPage, 'close', 'close verified work order');

  const deniedContext = await browser.newContext({ viewport: { width: 390, height: 844 }, locale: 'ar-EG', timezoneId: 'Africa/Cairo' });
  const deniedPage = await deniedContext.newPage();
  await login(deniedPage, fixture.actors.denied);
  const deniedResponse = await deniedPage.goto(base + `/maintenance/work-orders/${workOrderId}`);
  if (deniedResponse.status() !== 403) throw new Error(`Branch member without M08 permission got HTTP ${deniedResponse.status()} instead of 403.`);
  checks.push({ browser_denied_actor_http: deniedResponse.status() });
  const deniedAttachment = await deniedContext.request.get(base + `/maintenance/documents/${attachment.id}`);
  const deniedReport = await deniedContext.request.get(base + `/maintenance/work-orders/${workOrderId}/reports/${completion.id}`);
  if (deniedAttachment.status() !== 403 || deniedReport.status() !== 403) {
    throw new Error(`Private downloads were not denied to an unprivileged branch member: attachment=${deniedAttachment.status()}, report=${deniedReport.status()}`);
  }
  checks.push({ browser_denied_private_downloads: { attachment: deniedAttachment.status(), report: deniedReport.status() } });

  state = phpJson({ PHASE3_FIXTURE_MODE: 'readback', PHASE3_WORK_ORDER_ID: String(workOrderId) });
  const final = state.work_order;
  if (final.status !== 'closed' || !final.closed_at || final.request_id !== requestId || state.visits.length !== 1
    || state.visits[0].status !== 'recorded' || state.visits[0].outcome !== 'completed'
    || state.visits[0].recorded_by !== fixture.actors.technician.id || state.checklist_results !== 1
    || state.labor.length !== 1 || state.labor[0].duration_minutes !== 15 || state.completion_count !== 1
    || state.part_issues.length !== 1 || state.documents.length !== 1 || state.documents[0].sha256 !== attachment.sha256) {
    throw new Error(`Final PostgreSQL readback did not match the browser workflow: ${JSON.stringify(state)}`);
  }
  const reviewerResponse = await reviewerPage.goto(base + `/maintenance/work-orders/${workOrderId}`);
  if (reviewerResponse.status() !== 200) throw new Error(`Reviewer could not reopen the closed work order: HTTP ${reviewerResponse.status()}.`);
  await tab(reviewerPage, 'history');
  await pageViewport(reviewerPage, { width: 1440, height: 900 }, 'closed-work-order-desktop', state.work_order.number);
  await pageViewport(reviewerPage, { width: 390, height: 844 }, 'closed-work-order-mobile', state.work_order.number);
  if (browserErrors.length || livewireTraffic.some(row => row.status >= 500 || row.failure)) {
    throw new Error(`Browser errors or Livewire failures: ${JSON.stringify({ browserErrors, livewireTraffic })}`);
  }
  const result = { result: 'PASS', database: `erp_elec_new_test@127.0.0.1:${dbPort}`, browser: 'Microsoft Edge via Playwright',
    viewport_sizes: [1440, 390], request_id: requestId, work_order_id: workOrderId, final_postgresql_state: state,
    checks, browser_errors: browserErrors, livewire_failures: livewireTraffic.filter(row => row.status >= 500 || row.failure), evidence_directory: runDir };
  fs.writeFileSync(path.join(runDir, 'result.json'), JSON.stringify(result, null, 2) + '\n');
  process.stdout.write(JSON.stringify(result, null, 2) + '\n');
}

main().catch(error => {
  const result = { result: 'FAIL', database: `erp_elec_new_test@127.0.0.1:${dbPort}`, work_order_id: workOrderId,
    failure: String(error.stack || error.message).slice(0, 3200), checks, browser_errors: browserErrors,
    livewire_traffic: livewireTraffic, evidence_directory: runDir };
  try { fs.writeFileSync(path.join(runDir, 'result.json'), JSON.stringify(result, null, 2) + '\n'); } catch {}
  process.stderr.write(JSON.stringify(result, null, 2) + '\n');
  process.exitCode = 1;
}).finally(async () => {
  if (browser) await browser.close().catch(() => {});
  if (server && server.exitCode === null) {
    server.kill();
    await new Promise(resolve => { const timer = setTimeout(resolve, 1500); server.once('exit', () => { clearTimeout(timer); resolve(); }); });
  }
  if (Number.isInteger(serverLog)) fs.closeSync(serverLog);
  try { fs.rmSync(temp, { recursive: true, force: true, maxRetries: 5, retryDelay: 200 }); } catch {}
});
