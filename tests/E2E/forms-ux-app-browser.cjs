'use strict';

// Authenticated, read-only owner page sweep plus one bounded synthetic Company form save on owned PG 55467.
const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const fs = require('node:fs');
const http = require('node:http');
const net = require('node:net');
const path = require('node:path');
const { spawn, spawnSync } = require('node:child_process');
const { chromium } = require('playwright');
const { browserExecutable } = require('./page-speed-audit.cjs');

const root = path.resolve(__dirname, '../..');
const work = path.join(root, '.tmp/forms-ux-20261102/private');
const port = 8017;
const baseUrl = `http://127.0.0.1:${port}`;
const pgPort = 55467;
const database = 'erp_elec_new_test';
const pgBin = path.join(root, '.tools/postgresql/pgsql/bin');
const bootstrap = JSON.parse(fs.readFileSync(path.join(work, 'bootstrap.json'), 'utf8'));
const marker = JSON.parse(fs.readFileSync(path.join(work, '.codex-forms-ux-owned.json'), 'utf8'));
const actor = JSON.parse(fs.readFileSync(path.join(work, 'actor.json'), 'utf8'));
const runId = new Date().toISOString().replace(/[:.]/g, '-');
const screenshotDir = path.join(work, 'screenshots', 'route-run-' + runId);
const evidencePath = path.join(work, process.argv.includes('--forms-only') ? 'browser-forms-only.json' : 'browser-acceptance.json');
const failures = [];
const pageErrors = [];
const visited = [];
const selectMismatches = [];
let branchModalDiscard = false;
let realSelectChoice = null;
const progressPath = path.join(work, 'browser-progress.log');
function mark(message) { fs.appendFileSync(progressPath, `${new Date().toISOString()} ${message}\n`); }

function fail(message) { throw new Error(message); }
function assertOwned() {
  assert.equal(bootstrap.status, 'READY');
  assert.equal(bootstrap.database, database);
  assert.equal(Number(bootstrap.db_port), pgPort);
  assert.equal(marker.kind, 'codex-forms-ux-private-pg');
  assert.equal(marker.repo, root);
  assert.equal(Number(marker.port), pgPort);
  assert.equal(marker.database, database);
  assert.equal(marker.nonce, bootstrap.env.E2E_CLUSTER_TOKEN);
  const identity = spawnSync(path.join(pgBin, 'psql.exe'), ['-h', '127.0.0.1', '-p', String(pgPort), '-U', 'postgres', '-d', database,
    '-Atqc', "SELECT current_database() || '|' || current_setting('port') || '|' || current_setting('data_directory') || '|' || (SELECT nonce FROM codex_test_guard.ownership_receipts WHERE database=current_database())"],
  { cwd: root, encoding: 'utf8', windowsHide: true });
  if (identity.error || identity.status !== 0) fail('Private browser database identity check failed.');
  const [actualDb, actualPort, dataDirectory, nonce] = identity.stdout.trim().split('|');
  assert.equal(actualDb, database);
  assert.equal(actualPort, String(pgPort));
  assert.equal(path.resolve(dataDirectory), path.resolve(path.join(root, bootstrap.postgres_data_directory)));
  assert.equal(nonce, marker.nonce);
}
function safeEnvironment() {
  const env = { ...bootstrap.env, APP_URL: baseUrl, E2E_BASE_URL: baseUrl, E2E_APP_STORAGE: path.join(work, 'storage'),
    LARAVEL_STORAGE_PATH: path.join(work, 'storage'), VIEW_COMPILED_PATH: path.join(work, 'compiled-views'),
    E2E_CLUSTER_MARKER: path.join(work, '.codex-forms-ux-owned.json') };
  return env;
}
function portFree() {
  return new Promise((resolve, reject) => {
    const server = net.createServer();
    server.once('error', error => reject(error.code === 'EADDRINUSE' ? new Error(`Port ${port} is occupied; no process was stopped.`) : error));
    server.listen(port, '127.0.0.1', () => server.close(resolve));
  });
}
async function waitForApp(server) {
  for (let attempt = 0; attempt < 80; attempt++) {
    if (server.exitCode !== null) fail('The isolated Laravel server stopped before it became ready.');
    try {
      const response = await fetch(`${baseUrl}/login`, { redirect: 'manual' });
      if (response.status === 200) return;
    } catch { /* Laravel is starting. */ }
    await new Promise(resolve => setTimeout(resolve, 300));
  }
  fail('The isolated Laravel server did not become ready.');
}
function diagnostic(page) {
  page.on('pageerror', error => pageErrors.push(String(error.message).slice(0, 250)));
  page.on('console', message => { if (message.type() === 'error') pageErrors.push(String(message.text()).slice(0, 250)); });
  page.on('response', response => { if (response.status() >= 500) pageErrors.push(`HTTP ${response.status()} ${new URL(response.url()).pathname}`); });
}
async function selectableSnapshot(page) {
  return page.evaluate(() => {
    if (!window.Livewire?.find) return { ready: false, fields: [] };
    const fields = [];
    for (const select of document.querySelectorAll('select:not([multiple])')) {
      const binding = [...select.attributes].find(attribute => attribute.name.startsWith('wire:model'));
      if (!binding) continue;
      const componentRoot = select.closest('[wire\\:id]');
      const component = componentRoot && window.Livewire.find(componentRoot.getAttribute('wire:id'));
      if (!component?.$wire?.$get) continue;
      const model = component.$wire.$get(binding.value);
      if (model === undefined) continue;
      const modelValue = model == null ? '' : String(model);
      const optionExists = [...select.options].some(option => option.value === modelValue);
      fields.push({ model: binding.value, nativeMatches: select.value === modelValue, hasMatchingOption: optionExists, nonemptyMissing: modelValue !== '' && !optionExists });
    }
    return { ready: true, fields };
  });
}
function routeFromLink(href) {
  try {
    const url = new URL(href, baseUrl);
    if (url.origin !== baseUrl || url.search || url.hash) return null;
    if (/\/(?:logout|export|download|print|pdf|logo|avatar|media)(?:\/|$)/i.test(url.pathname)) return null;
    return url.pathname;
  } catch { return null; }
}

async function main() {
  assertOwned();
  fs.writeFileSync(progressPath, '');
  const manifest = JSON.parse(fs.readFileSync(path.join(root, 'public/build/manifest.json'), 'utf8'));
  for (const key of ['resources/js/app.js', 'resources/css/app.css']) {
    const file = manifest[key]?.file;
    if (!file || !fs.existsSync(path.join(root, 'public/build', file)) || fs.statSync(path.join(root, 'public/build', file)).size === 0) fail(`Fresh Vite build is missing ${key}.`);
  }
  const assetFingerprint = ['resources/js/app.js', 'resources/css/app.css'].map(key => manifest[key].file).join('|');
  await portFree();
  fs.mkdirSync(screenshotDir, { recursive: true });
  const serverLog = fs.openSync(path.join(work, 'app-server.log'), 'w');
  const server = spawn('php', ['artisan', 'serve', '--host=127.0.0.1', `--port=${port}`], {
    cwd: root, env: safeEnvironment(), windowsHide: true, stdio: ['ignore', serverLog, serverLog],
  });
  let browser;
  try {
    await waitForApp(server);
    browser = await chromium.launch({ executablePath: browserExecutable(), headless: true });
    const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'ar-EG', timezoneId: 'Africa/Cairo' });
    const page = await context.newPage();
    diagnostic(page);
    await page.goto(`${baseUrl}/login`, { waitUntil: 'domcontentloaded' });
    const email = page.locator('input[type="email"]');
    const password = page.locator('input[type="password"]');
    await email.fill(actor.email);
    await password.fill(actor.password);
    await page.locator('button[type="submit"]').click();
    await page.waitForURL(url => !url.pathname.endsWith('/login'), { timeout: 15000 });
    await page.waitForTimeout(250);
    mark('login-complete');

    const links = await page.locator('a[href]').evaluateAll(anchors => anchors.map(anchor => anchor.href));
    const routes = [...new Set(links.map(routeFromLink).filter(Boolean))].filter(route => route !== '/logout' && route !== '/login');
    if (!routes.includes('/dashboard')) routes.unshift('/dashboard');
    if (!routes.includes('/organization/company')) routes.push('/organization/company');
    if (!routes.includes('/organization/branches')) routes.push('/organization/branches');
    const ownerPrefixes = ['/organization', '/products', '/customers', '/suppliers', '/sales-orders', '/quotations', '/purchasing', '/inventory', '/projects', '/maintenance', '/workshops', '/service-contracts', '/accounting', '/financial', '/treasury', '/attendance', '/data-operations'];
    const coveredOwners = new Set();

    for (const route of process.argv.includes('--forms-only') ? [] : routes) {
      const owner = ownerPrefixes.find(prefix => route === prefix || route.startsWith(`${prefix}/`)) || (route === '/dashboard' ? 'dashboard' : null);
      if (owner) coveredOwners.add(owner);
      pageErrors.length = 0;
      const response = await page.goto(`${baseUrl}${route}`, { waitUntil: 'domcontentloaded', timeout: 30000 });
      await page.waitForTimeout(250);
      const status = response?.status() ?? 0;
      if (status !== 200 || new URL(page.url()).pathname === '/login') failures.push({ route, viewport: 1440, status, kind: 'route' });
      const dimensions = await page.evaluate(() => ({ width: innerWidth, bodyWidth: document.body.scrollWidth, title: document.title }));
      const snapshot = await selectableSnapshot(page);
      if (snapshot.ready) {
        for (const field of snapshot.fields) {
          if (!field.nativeMatches || field.nonemptyMissing) selectMismatches.push({ route, field: field.model, hasMatchingOption: field.hasMatchingOption });
        }
      }
      if (dimensions.bodyWidth > dimensions.width + 1) failures.push({ route, viewport: 1440, kind: 'overflow' });
      if (pageErrors.length) failures.push({ route, viewport: 1440, kind: 'browser', count: pageErrors.length });
      await page.screenshot({ path: path.join(screenshotDir, `${String(visited.length + 1).padStart(2, '0')}-desktop.png`), fullPage: true });
      visited.push({ route, viewport: 1440, status, boundSingleSelects: snapshot.fields.length });

      await page.setViewportSize({ width: 390, height: 844 });
      await page.waitForTimeout(100);
      const mobile = await page.evaluate(() => ({ width: innerWidth, bodyWidth: document.body.scrollWidth }));
      if (mobile.bodyWidth > mobile.width + 1) failures.push({ route, viewport: 390, kind: 'overflow' });
      await page.screenshot({ path: path.join(screenshotDir, `${String(visited.length).padStart(2, '0')}-mobile.png`), fullPage: true });
      visited.push({ route, viewport: 390, status, boundSingleSelects: snapshot.fields.length });
      await page.setViewportSize({ width: 1440, height: 900 });
    }

    // Exercise real Livewire validation and a successful save only on the synthetic test company.
    mark(`route-sweep-complete-${visited.length / 2}`);
    const routeCheckpoint = { routes, visited, failures, select_mismatches: selectMismatches,
      owner_prefixes_covered: [...coveredOwners].sort(), asset_fingerprint: assetFingerprint };
    if (!process.argv.includes('--forms-only')) fs.writeFileSync(path.join(work, 'route-sweep.json'), JSON.stringify(routeCheckpoint, null, 2) + '\n');
    await page.goto(`${baseUrl}/organization/company`, { waitUntil: 'domcontentloaded' });
    mark('company-page-loaded');
    await page.getByRole('button', { name: 'تعديل البيانات' }).click();
    mark('company-edit-clicked');
    const form = page.locator('.erp-shell form[wire\\:submit="save"]').first();
    const code = page.locator('[wire\\:model="form.code"]');
    const name = page.locator('[wire\\:model="form.name"]');
    await code.waitFor({ state: 'visible' });
    mark('company-edit-form-visible');
    await name.fill(`شركة اختبار الواجهات محفوظة ${actor.tag}`);
    let livewirePosts = 0;
    page.on('request', request => { if (request.method() === 'POST' && request.url().includes('/update')) livewirePosts++; });
    await code.fill('');
    const requestsBeforeNativeRequired = livewirePosts;
    await form.locator('button[type="submit"]').click();
    assert.equal(await code.evaluate(input => input.validity.valueMissing), true, 'required field keeps native browser validation');
    assert.equal(livewirePosts, requestsBeforeNativeRequired, 'native required validation prevents a server request');
    mark('company-native-required-blocked');
    const invalidCode = 'X'.repeat(51);
    await code.fill(invalidCode);
    assert.equal(await form.getAttribute('data-erp-dirty') !== null, true, 'edited real form shows unsaved state');
    await form.locator('button[type="submit"]').click();
    mark('company-invalid-save-clicked');
    const codeError = page.locator('[data-erp-validation] [data-error-field="form.code"]');
    await codeError.waitFor({ state: 'visible', timeout: 10000 });
    mark('company-validation-error-visible');
    assert.equal(await code.inputValue(), invalidCode, 'server validation failure keeps user input');
    mark('company-validation-value-retained');
    assert.equal(await form.getAttribute('data-erp-dirty') !== null, true, 'validation failure keeps form dirty');
    mark('company-validation-dirty-retained');
    assert.equal(await code.getAttribute('aria-invalid'), 'true', 'server error marks the matching field invalid');
    mark('company-validation-aria-invalid');
    assert.match(await code.getAttribute('aria-describedby'), /erp-inline-error|erp-error|flux/, 'server error has a description relationship');
    mark('company-validation-described');
    await page.setViewportSize({ width: 390, height: 844 });
    await page.screenshot({ path: path.join(screenshotDir, 'company-validation-mobile.png'), fullPage: true });
    mark('company-validation-screenshot');

    const savedCode = `DEMO-EG-FORMS-${actor.tag}-SAVED`;
    await page.setViewportSize({ width: 1440, height: 900 });
    await code.fill(savedCode);
    await page.locator('[wire\\:model="form.address"]').fill('عنوان اختبار محدث — لا يمثل بيانات عميل');
    await form.locator('button[type="submit"]').click();
    mark('company-valid-save-clicked');
    mark('company-valid-save-clicked');
    await page.getByText(`شركة اختبار الواجهات محفوظة ${actor.tag}`, { exact: true }).waitFor({ state: 'visible', timeout: 15000 });
    mark('company-save-complete');
    assert.equal(await page.locator('.erp-shell form[wire\\:submit="save"]').getAttribute('data-erp-dirty'), null, 'successful Livewire save clears dirty state');
    mark('company-dirty-attribute-clear');
    mark('company-beforeunload-check-start');
    const wouldBlockNavigation = await page.evaluate(() => {
      const event = new Event('beforeunload', { cancelable: true });
      window.dispatchEvent(event);
      return event.defaultPrevented;
    });
    mark(`company-beforeunload-check-complete-${wouldBlockNavigation}`);
    assert.equal(wouldBlockNavigation, false, 'successful Livewire save clears the unload guard');
    mark('company-beforeunload-clear');
    let unexpectedDialogs = 0;
    const noSuccessPrompt = async dialog => { unexpectedDialogs++; await dialog.dismiss(); };
    page.on('dialog', noSuccessPrompt);
    mark('company-branches-navigation-start');
    await page.goto(`${baseUrl}/organization/branches`, { waitUntil: 'domcontentloaded', timeout: 15000 });
    mark('company-branches-navigation-complete');
    assert.equal(unexpectedDialogs, 0, 'successful save does not prompt again on navigation');
    await page.screenshot({ path: path.join(screenshotDir, 'company-saved-desktop.png'), fullPage: true });
    page.off('dialog', noSuccessPrompt);

    await page.getByRole('button', { name: 'إضافة فرع' }).click();
    mark('branch-modal-create-clicked');
    const branchDialog = page.locator('ui-modal dialog[open]').filter({ hasText: 'إضافة فرع' });
    await branchDialog.waitFor({ state: 'visible', timeout: 10000 });
    mark('branch-modal-open');
    await page.screenshot({ path: path.join(screenshotDir, 'branch-modal-open.png'), fullPage: true });
    const branchDraftCode = branchDialog.locator('[wire\\:model="form.code"]');
    const branchDraftName = branchDialog.locator('[wire\\:model="form.name"]');
    await branchDraftCode.fill(`E2E-${actor.tag}-DISCARD`);
    await branchDraftName.fill(`مسودة فرع غير محفوظة ${actor.tag}`);
    mark('branch-modal-input-filled');
    assert.equal(await branchDialog.locator('form[wire\\:submit]').getAttribute('data-erp-dirty') !== null, true, 'modal edits are tracked as dirty');
    mark('branch-modal-draft-dirty');
    const branchClose = branchDialog.locator('[data-flux-modal-close]').first();
    const capturePrompt = action => new Promise((resolve, reject) => {
      const timer = setTimeout(() => reject(new Error('Expected the dirty modal discard confirmation.')), 5000);
      page.once('dialog', async dialog => {
        try { const type = dialog.type(); await dialog[action](); clearTimeout(timer); resolve(type); }
        catch (error) { clearTimeout(timer); reject(error); }
      });
    });
    assert.equal(await branchClose.count(), 1, 'branch modal exposes its close control');
    const dismissedPrompt = capturePrompt('dismiss');
    await page.keyboard.press('Escape');
    mark('branch-modal-escape-pressed');
    assert.equal(await dismissedPrompt, 'confirm', 'dirty modal Escape asks before discarding');
    mark('branch-modal-escape-confirm-shown');
    await branchDialog.waitFor({ state: 'visible' });
    assert.equal(await branchDraftCode.inputValue(), `E2E-${actor.tag}-DISCARD`, 'dismissing discard prompt keeps modal draft');
    assert.equal(await branchDraftName.inputValue(), `مسودة فرع غير محفوظة ${actor.tag}`, 'dismissing discard prompt keeps all modal input');
    const acceptedPrompt = capturePrompt('accept');
    await branchClose.click();
    assert.equal(await acceptedPrompt, 'confirm', 'dirty modal X close remains guarded');
    mark('branch-modal-x-confirm-shown');
    mark('branch-modal-x-confirm-accepted');
    await branchDialog.waitFor({ state: 'hidden', timeout: 10000 });
    branchModalDiscard = true;
    mark('branch-modal-discard-keeps-on-cancel-closes-on-confirm');

    const productResponse = await page.goto(`${baseUrl}/products/create`, { waitUntil: 'domcontentloaded', timeout: 15000 });
    assert.equal(productResponse?.status(), 200, 'synthetic Administrator can open the product form');
    const unitSelect = page.locator('erp-select > select[wire\\:model="form.base_unit_id"]');
    await unitSelect.waitFor({ state: 'attached', timeout: 10000 });
    assert.equal(await unitSelect.locator('option').count() >= 10, true, 'synthetic test options exercise the enhanced select');
    const unitWrapper = unitSelect.locator('xpath=..');
    const unitTrigger = unitWrapper.locator('[data-erp-select-trigger]');
    assert.equal(await unitTrigger.isDisabled(), false, 'enhanced unit selector is enabled');
    await unitTrigger.click();
    const unitSearch = unitWrapper.locator('.erp-select-search');
    await unitSearch.fill(`واجهة ${actor.tag} 9`);
    const chosenOption = unitWrapper.getByRole('option', { name: `اختبار واجهة ${actor.tag} 9`, exact: true });
    await chosenOption.click();
    const chosenValue = await unitSelect.locator('option').filter({ hasText: `اختبار واجهة ${actor.tag} 9` }).getAttribute('value');
    await page.waitForFunction(({ value }) => {
      const select = document.querySelector('erp-select > select[wire\\:model="form.base_unit_id"]');
      const component = select?.closest('[wire\:id]');
      return select?.value === value && String(window.Livewire?.find(component?.getAttribute('wire:id'))?.$wire?.$get('form.base_unit_id') ?? '') === value;
    }, { value: chosenValue }, { timeout: 10000 });
    assert.equal(await unitSelect.inputValue(), chosenValue, 'enhanced selection updates the native form value');
    assert.equal(await unitSelect.evaluate(select => [...select.options].some(option => option.value === select.value)), true, 'selected unit still has a matching native option');
    realSelectChoice = { model: 'form.base_unit_id', selected_value_nonempty: chosenValue !== '', has_matching_option: true };
    await page.screenshot({ path: path.join(screenshotDir, 'product-select-selected-desktop.png'), fullPage: true });
    mark('live-product-enhanced-select-updates-livewire-model');

    const result = { status: failures.length || selectMismatches.length ? 'FAIL' : 'PASS', database: database, port: pgPort,
      visited_pages: visited.length / 2, viewport_checks: visited.length, owner_prefixes_covered: [...coveredOwners].sort(),
      routes: visited, select_mismatches: selectMismatches, failures, browser_errors: pageErrors,
      company_form: { validation_preserved_input: true, successful_save_ui: true, duplicate_prompt_after_success: unexpectedDialogs > 0 },
      branch_modal_discard: branchModalDiscard, real_enhanced_select: realSelectChoice,
      screenshots: fs.readdirSync(screenshotDir).filter(file => file.endsWith('.png')) };
    fs.writeFileSync(evidencePath, JSON.stringify(result, null, 2) + '\n');
    if (failures.length || selectMismatches.length) fail(`Browser acceptance found ${failures.length} route/layout/runtime failures and ${selectMismatches.length} select binding mismatches; see private JSON evidence.`);
    console.log(process.argv.includes('--forms-only') ? 'PASS synthetic Company/Branch/Product forms on owned PG 55467.' : `PASS authenticated owner page sweep: ${routes.length} routes × 390/1440; Company validation/save and real select verified on owned PG 55467.`);
  } finally {
    if (browser) await browser.close().catch(() => {});
    server.kill();
    if (process.platform === 'win32' && Number.isInteger(server.pid)) {
      spawnSync('taskkill', ['/PID', String(server.pid), '/T', '/F'], { cwd: root, windowsHide: true, stdio: 'ignore' });
      const stopOwnedServer = "$repo = '" + root + "'; Get-CimInstance Win32_Process | Where-Object { $_.Name -eq 'php.exe' -and $_.CommandLine -like '*-S 127.0.0.1:" + port + "*' -and $_.CommandLine -like '*Illuminate*server.php*' -and $_.CommandLine -like ('*' + $repo + '*') } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force }";
      spawnSync('powershell.exe', ['-NoProfile', '-NonInteractive', '-Command', stopOwnedServer], { cwd: root, windowsHide: true, stdio: 'ignore' });
    }
    fs.closeSync(serverLog);
    if (server.exitCode === null) await Promise.race([new Promise(resolve => server.once('exit', resolve)), new Promise(resolve => setTimeout(resolve, 3000))]);
    assertOwned();
  }
}

main().catch(error => { process.stderr.write(`${error.message}\n`); process.exitCode = 1; });
