'use strict';

const fs = require('node:fs');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const { test, expect } = require('playwright/test');

const root = path.resolve(__dirname, '../../..');
const fixturePath = path.join(root, 'tests/E2E/support/automation-critical-fixtures.php');
const bootstrapPath = process.env.E2E_BOOTSTRAP_FILE || path.join(root, '.tmp/e2e-automation/bootstrap.json');

function readback(requestId) {
  const run = JSON.parse(fs.readFileSync(bootstrapPath, 'utf8'));
  if (run.dbName !== 'erp_elec_new_test' || Number(run.dbPort) !== 55460) throw new Error('Refusing M08 request readback outside the isolated automation PostgreSQL database.');
  const result = spawnSync('php', ['-d', 'opcache.enable_cli=0', fixturePath], {
    cwd: root,
    env: { ...process.env, E2E_DB_PORT: '55460', E2E_DB_DATABASE: 'erp_elec_new_test',
      E2E_CLUSTER_MARKER: run.clusterMarker, E2E_CLUSTER_TOKEN: run.clusterToken,
      E2E_APP_STORAGE: run.env?.LARAVEL_STORAGE_PATH || run.storage,
      E2E_FIXTURE_MODE: 'readback', E2E_M08_REQUEST_ID: String(requestId) },
    encoding: 'utf8', windowsHide: true,
  });
  if (result.status !== 0) throw new Error(`M08 request PostgreSQL readback failed: ${(result.stderr || result.stdout).slice(-900)}`);
  return JSON.parse(result.stdout);
}

async function liveUpdate(page, action) {
  const response = page.waitForResponse(r => /\/livewire(?:-[^/]+)?\/update/.test(r.url()) && r.status() === 200, { timeout: 15_000 });
  await action();
  await response;
}

async function gotoReady(page, url) {
  const response = await page.goto(url, { waitUntil: 'domcontentloaded' });
  await page.locator('main, [wire\\:id]').first().waitFor({ state: 'visible' });
  return response;
}

async function setModel(page, key, value, live = false) {
  const target = page.locator(`[wire\\:model="${key}"], [wire\\:model\\.live="${key}"], [wire\\:model\\.live\\.debounce\\.300ms="${key}"]`).first();
  await target.waitFor({ state: 'attached' });
  // Optional fields sit in a collapsed section; open it as a user would.
  await target.evaluate(el => { const box = el.closest('details'); if (box) box.open = true; });
  // Enhanced controls hide the native field, so set the same Livewire model directly.
  if (!await target.isVisible()) {
    const assign = () => target.evaluate((el, [name, next]) => window.Livewire.find(el.closest('[wire\\:id]').getAttribute('wire:id')).set(name, next, false), [key, String(value)]);
    if (live) await liveUpdate(page, assign);
    else await assign();
    return;
  }
  const tag = await target.evaluate(el => el.tagName.toLowerCase());
  const update = () => tag === 'select' ? target.selectOption(String(value)) : target.fill(String(value));
  if (live) await liveUpdate(page, update);
  else await update();
}

function cairoDateTime(minutesOffset = 0) {
  const parts = new Intl.DateTimeFormat('en-CA', { timeZone: 'Africa/Cairo', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' })
    .formatToParts(new Date(Date.now() + minutesOffset * 60_000)).reduce((value, item) => ({ ...value, [item.type]: item.value }), {});
  return `${parts.year}-${parts.month}-${parts.day}T${parts.hour}:${parts.minute}`;
}

test('M08 request draft is created and edited in the browser with owner persistence readback', async ({ browser }) => {
  const run = JSON.parse(fs.readFileSync(bootstrapPath, 'utf8'));
  expect(run.dbName).toBe('erp_elec_new_test');
  expect(Number(run.dbPort)).toBe(55460);
  const fixture = run.fixtures;
  const smokeFixture = fixture?.smoke || fixture;
  expect(smokeFixture?.branch_id).toBeTruthy();
  expect(smokeFixture?.customer_id).toBeTruthy();
  const state = run.actor_states?.smoke;
  if (!state) throw new Error('Runner did not provide the scoped Administrator browser state.');
  const statePath = path.isAbsolute(state) ? state : path.resolve(root, state);
  const context = await browser.newContext({ baseURL: process.env.E2E_BASE_URL,
    storageState: statePath, locale: 'ar-EG', timezoneId: 'Africa/Cairo' });
  const page = await context.newPage();
  page.setDefaultTimeout(12_000);
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  try {
    const response = await gotoReady(page, '/maintenance/requests/create');
    expect(response?.status()).toBe(200);
    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
    await setModel(page, 'form.branch_id', smokeFixture.branch_id, true);
    // The enhanced select hides the server search input and owns its own search box.
    await liveUpdate(page, () => page.evaluate(code => {
      const input = document.querySelector('input[name="customerSearch"]');
      window.Livewire.find(input.closest('[wire\\:id]').getAttribute('wire:id')).set('customerSearch', code);
    }, smokeFixture.customer_code));
    await expect(page.getByTestId('maintenance-request-customer').locator(`option[value="${smokeFixture.customer_id}"]`)).toHaveCount(1);
    await setModel(page, 'form.customer_id', smokeFixture.customer_id, true);
    const responsible = page.locator('[wire\\:model="form.responsible_id"]');
    const smokeActor = (fixture.actors || []).find(actor => actor.key === 'smoke');
    const runActor = smokeActor?.id;
    const options = await responsible.locator('option').evaluateAll(nodes => nodes.map(node => node.value));
    const targetResponsible = String(runActor || options.find(value => value && value !== ''));
    expect(options).toContain(targetResponsible);
    await setModel(page, 'form.responsible_id', targetResponsible);
    await setModel(page, 'form.description', `E2E draft ${fixture.tag}: investigate customer equipment fault and record findings.`);
    await setModel(page, 'form.priority', 'normal');
    await setModel(page, 'form.reported_at', cairoDateTime(-3));
    const dateParts = new Intl.DateTimeFormat('en-CA', { timeZone: 'Africa/Cairo', year: 'numeric', month: '2-digit', day: '2-digit' })
      .formatToParts(new Date()).reduce((value, item) => ({ ...value, [item.type]: item.value }), {});
    await setModel(page, 'form.preferred_date', `${dateParts.year}-${dateParts.month}-${dateParts.day}`);
    const form = page.locator('form[wire\\:submit="save"]');
    const saved = page.waitForResponse(r => /\/livewire(?:-[^/]+)?\/update/.test(r.url()) && r.status() === 200, { timeout: 15_000 });
    await form.locator('button[type="submit"]').click();
    await saved;
    await page.waitForURL(/\/maintenance\/requests\/\d+(?:\?.*)?$/);
    const requestId = Number(new URL(page.url()).pathname.split('/').at(-1));
    expect(requestId).toBeGreaterThan(0);
    await expect(page.locator('body')).toContainText(`E2E draft ${fixture.tag}`);
    let evidence = readback(requestId);
    expect(evidence.maintenance_request.status).toBe('draft');
    expect(evidence.maintenance_request.branch_id).toBe(smokeFixture.branch_id);
    expect(evidence.maintenance_request.customer_id).toBe(smokeFixture.customer_id);
    expect(evidence.maintenance_request.responsible_id).toBe(Number(targetResponsible));
    expect(evidence.maintenance_request.created_by).toBe(smokeActor?.id);
    expect(evidence.maintenance_request.version).toBe(1);
    expect(evidence.maintenance_request.saved_audit_count).toBe(1);

    const revisedDescription = `E2E draft ${fixture.tag}: confirm the fault and document the inspection plan.`;
    await setModel(page, 'form.description', revisedDescription);
    const revised = page.waitForResponse(r => /\/livewire(?:-[^/]+)?\/update/.test(r.url()) && r.status() === 200, { timeout: 15_000 });
    await form.locator('button[type="submit"]').click();
    await revised;
    await page.reload();
    await expect(page.locator('body')).toContainText(revisedDescription);
    evidence = readback(requestId);
    expect(evidence.maintenance_request.version).toBe(2);
    expect(evidence.maintenance_request.description).toBe(revisedDescription);
    expect(evidence.maintenance_request.saved_audit_count).toBe(2);
    expect(evidence.maintenance_request.work_order_count).toBe(0);
    await page.setViewportSize({ width: 390, height: 844 });
    await page.reload();
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(394);
    expect(errors).toEqual([]);
  } finally {
    await context.close();
  }
});
