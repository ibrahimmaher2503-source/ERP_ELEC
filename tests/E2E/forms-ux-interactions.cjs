'use strict';

// Bounded real-app browser checks against the existing private 55467 app server.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const { chromium } = require('playwright');
const { browserExecutable } = require('./page-speed-audit.cjs');
const root = path.resolve(__dirname, '../..');
const privateDir = path.join(root, '.tmp/forms-ux-20261102/private');
const baseUrl = 'http://127.0.0.1:8017';
const bootstrap = JSON.parse(fs.readFileSync(path.join(privateDir, 'bootstrap.json'), 'utf8'));
const marker = JSON.parse(fs.readFileSync(path.join(privateDir, '.codex-forms-ux-owned.json'), 'utf8'));
const actor = JSON.parse(fs.readFileSync(path.join(privateDir, 'actor.json'), 'utf8'));
const selectFixture = JSON.parse(fs.readFileSync(path.join(privateDir, 'select-fixtures.json'), 'utf8'));
const runId = new Date().toISOString().replace(/[:.]/g, '-');
const screenshotDir = path.join(privateDir, 'screenshots', `interaction-run-${runId}`);
const reportPath = path.join(privateDir, 'browser-interactions.json');

function verifyPrivateTarget() {
  assert.equal(bootstrap.status, 'READY');
  assert.equal(bootstrap.database, 'erp_elec_new_test');
  assert.equal(Number(bootstrap.db_port), 55467);
  assert.equal(marker.database, bootstrap.database);
  assert.equal(marker.nonce, bootstrap.env.E2E_CLUSTER_TOKEN);
  const psql = spawnSync(path.join(root, '.tools/postgresql/pgsql/bin/psql.exe'), ['-h', '127.0.0.1', '-p', '55467', '-U', 'postgres', '-d', 'erp_elec_new_test', '-Atqc', "SELECT current_database() || '|' || current_setting('port') || '|' || current_setting('data_directory') || '|' || (SELECT nonce FROM codex_test_guard.ownership_receipts WHERE database=current_database())"], { cwd: root, encoding: 'utf8', windowsHide: true });
  assert.equal(psql.status, 0, 'private PostgreSQL receipt is queryable');
  const [database, port, dataDir, nonce] = psql.stdout.trim().split('|');
  assert.equal(database, 'erp_elec_new_test');
  assert.equal(port, '55467');
  assert.equal(path.resolve(dataDir), path.resolve(path.join(root, bootstrap.postgres_data_directory)));
  assert.equal(nonce, marker.nonce);
  assert.equal(selectFixture.status, 'READY');
  assert.equal(selectFixture.database, database);
  assert.equal(selectFixture.port, Number(port));
}

async function main() {
  verifyPrivateTarget();
  fs.mkdirSync(screenshotDir, { recursive: true });
  const response = await fetch(`${baseUrl}/login`, { redirect: 'manual' });
  assert.equal(response.status, 200, 'existing app server is ready on 8017');
  const browser = await chromium.launch({ executablePath: browserExecutable(), headless: true });
  let page;
  try {
    const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'ar-EG', timezoneId: 'Africa/Cairo' });
    page = await context.newPage();
    page.setDefaultTimeout(10000);
    const errors = [];
    let expectedPrompt = null;
    page.on('dialog', async dialog => {
      const expectation = expectedPrompt;
      expectedPrompt = null;
      if (!expectation) errors.push(`Unexpected browser ${dialog.type()} dialog`);
      try {
        const type = dialog.type();
        await dialog[expectation?.action || 'dismiss']();
        expectation?.resolve(type);
      } catch (error) { expectation?.reject(error); }
    });
    page.on('pageerror', error => errors.push(String(error.message).slice(0, 250)));
    page.on('console', message => { if (message.type() === 'error') errors.push(String(message.text()).slice(0, 250)); });
    await page.goto(`${baseUrl}/login`, { waitUntil: 'domcontentloaded' });
    await page.locator('input[type="email"]').fill(actor.email);
    await page.locator('input[type="password"]').fill(actor.password);
    await page.locator('button[type="submit"]').click();
    await page.waitForURL(url => !url.pathname.endsWith('/login'), { timeout: 15000 });

    await page.goto(`${baseUrl}/organization/branches`, { waitUntil: 'domcontentloaded' });
    await page.getByRole('button', { name: 'إضافة فرع' }).click();
    const branchDialog = page.locator('ui-modal dialog[open]').filter({ hasText: 'إضافة فرع' });
    await branchDialog.waitFor({ state: 'visible', timeout: 10000 });
    await page.screenshot({ path: path.join(screenshotDir, 'branch-modal.png'), fullPage: true });
    const code = branchDialog.locator('[wire\\:model="form.code"]');
    const name = branchDialog.locator('[wire\\:model="form.name"]');
    const codeValue = `E2E-${actor.tag}-DISCARD`;
    const nameValue = `مسودة فرع غير محفوظة ${actor.tag}`;
    await code.fill(codeValue);
    await name.fill(nameValue);
    const branchForm = branchDialog.locator('form[wire\\:submit]');
    assert.notEqual(await branchForm.getAttribute('data-erp-dirty'), null, 'modal field edits mark the Livewire form dirty');
    const close = branchDialog.locator('[data-flux-modal-close]').first();
    assert.equal(await close.count(), 1);
    const capturePrompt = action => new Promise((resolve, reject) => {
      const timer = setTimeout(() => reject(new Error('Dirty modal did not show a discard confirm.')), 5000);
      expectedPrompt = { action, resolve: type => { clearTimeout(timer); resolve(type); }, reject: error => { clearTimeout(timer); reject(error); } };
    });
    const dismissPrompt = capturePrompt('dismiss');
    await page.keyboard.press('Escape');
    assert.equal(await dismissPrompt, 'confirm', 'Escape prompts before discarding modal edits');
    await branchDialog.waitFor({ state: 'visible', timeout: 5000 });
    assert.equal(await code.inputValue(), codeValue, 'dismissing the prompt keeps the modal open and draft');
    assert.equal(await name.inputValue(), nameValue, 'dismissing the prompt preserves every modal field');
    const acceptPrompt = capturePrompt('accept');
    await close.click();
    assert.equal(await acceptPrompt, 'confirm', 'X close prompts before discarding modal edits');
    await branchDialog.waitFor({ state: 'hidden', timeout: 10000 });
    const beforeUnload = await page.evaluate(() => { const event = new Event('beforeunload', { cancelable: true }); window.dispatchEvent(event); return event.defaultPrevented; });
    assert.equal(beforeUnload, false, 'accepting discard clears the modal dirty state');

    const product = await page.goto(`${baseUrl}/products/create`, { waitUntil: 'domcontentloaded', timeout: 15000 });
    assert.equal(product.status(), 200, 'product form route responds for the test Administrator');
    assert.ok(await page.locator('erp-select').count(), 'actual Flux selects use the shared ERP wrapper');
    const unit = page.locator('erp-select select[name="form.base_unit_id"]');
    await unit.waitFor({ state: 'attached', timeout: 10000 });
    assert.ok(await unit.locator('option').count() > 7, 'unit option list triggers enhanced mode');
    const wrapper = unit.locator('xpath=..');
    assert.equal(await wrapper.locator('[data-erp-select-trigger]').isDisabled(), false);
    await wrapper.locator('[data-erp-select-trigger]').click();
    const search = wrapper.locator('.erp-select-search');
    await search.fill(`واجهة ${actor.tag} 9`);
    await wrapper.getByRole('option').filter({ hasText: `اختبار واجهة ${actor.tag} 9` }).click();
    const selectedValue = await unit.inputValue();
    assert.ok(selectedValue, 'enhanced choice updates the native select value');
    await page.waitForFunction(value => {
      const select = document.querySelector('erp-select select[name="form.base_unit_id"]');
      const id = select?.closest('[wire\\:id]')?.getAttribute('wire:id');
      return select?.value === value && String(window.Livewire?.find(id)?.$get('form.base_unit_id') ?? '') === value;
    }, selectedValue, { timeout: 10000 });
    assert.equal(await unit.evaluate(select => [...select.options].some(option => option.value === select.value)), true);
    await page.screenshot({ path: path.join(screenshotDir, 'product-enhanced-unit-selected.png'), fullPage: true });
    assert.deepEqual(errors, [], 'interaction pages have no JavaScript errors');

    const result = { status: 'PASS', private_database: 'erp_elec_new_test', port: 55467,
      branch_modal: { dirty: true, escape_prompt: true, dismiss_retained_inputs: true, x_prompt: true, accept_cleared_dirty: true },
      product_select: { enhanced: true, model: 'form.base_unit_id', value_nonempty: selectedValue !== '', has_matching_option: true, livewire_model_updated: true },
      browser_errors: errors, screenshots: fs.readdirSync(screenshotDir) };
    fs.writeFileSync(reportPath, JSON.stringify(result, null, 2) + '\n');
    console.log('PASS Branch modal discard guard and real Livewire enhanced-select update on private 55467.');
  } finally {
    if (page && !page.isClosed()) await page.close({ runBeforeUnload: false });
    await browser.close();
  }
}

main().catch(error => { process.stderr.write(`${error.message}\n`); process.exitCode = 1; });
