import { spawnSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { test } from '@e2e-dev/web';
import { expect } from 'e2e';
import { setLivewireModel, setModel } from './E2E/testerarmy-form';

const root = process.cwd();
const bootstrapPath = process.env.E2E_BOOTSTRAP_FILE ?? resolve(root, '.tmp/e2e-automation/bootstrap.json');
const helperPath = resolve(root, 'tests/E2E/support/automation-critical-fixtures.php');
const run = JSON.parse(readFileSync(bootstrapPath, 'utf8'));
const fixture = JSON.parse(process.env.E2E_FIXTURE_JSON ?? '{}') as { tag?: string; branch_id?: number; customer_id?: number; customer_code?: string; actor_id?: number };

function readback(requestId: number) {
  if (run.dbName !== 'erp_elec_new_test' || Number(run.dbPort) !== 55460 || !run.clusterMarker || !run.clusterToken) {
    throw new Error('Refusing maintenance readback outside the owned isolated PostgreSQL database.');
  }
  const result = spawnSync('php', ['-d', 'opcache.enable_cli=0', helperPath], {
    cwd: root,
    env: { ...process.env, E2E_DB_PORT: '55460', E2E_DB_DATABASE: 'erp_elec_new_test',
      E2E_CLUSTER_MARKER: run.clusterMarker, E2E_CLUSTER_TOKEN: run.clusterToken,
      E2E_APP_STORAGE: run.env?.LARAVEL_STORAGE_PATH || run.storage,
      E2E_FIXTURE_MODE: 'readback', E2E_M08_REQUEST_ID: String(requestId) },
    encoding: 'utf8', windowsHide: true,
  });
  if (result.status !== 0) throw new Error(`Maintenance PostgreSQL readback failed: ${(result.stderr || result.stdout).slice(-900)}`);
  return JSON.parse(result.stdout);
}

test('smoke actor creates, reopens, and persists an M08 draft deterministically', { session: 'smoke', tags: ['smoke', 'maintenance', 'deterministic'] }, async ({ app, browser, screen }) => {
  if (!fixture.tag || !fixture.branch_id || !fixture.customer_id || !fixture.customer_code || !fixture.actor_id) {
    throw new Error('The guarded smoke fixture is incomplete.');
  }
  await app.open('/maintenance/requests/create');
  await expect(screen.getByRole('heading', 'طلب صيانة جديد')).toBeVisible();
  await setModel(browser, 'form.branch_id', fixture.branch_id, true);
  await setLivewireModel(browser, 'input[name="customerSearch"]', 'customerSearch', fixture.customer_code);
  await expect(browser.locator(`[data-testid="maintenance-request-customer"] option[value="${fixture.customer_id}"]`)).toHaveCount(1);
  await setModel(browser, 'form.customer_id', fixture.customer_id, true);
  await expect(browser.locator(`[wire\\:model="form.responsible_id"] option[value="${fixture.actor_id}"]`)).toHaveCount(1);
  await setModel(browser, 'form.responsible_id', fixture.actor_id);
  await expect(browser.locator('[wire\\:model="form.reported_at"]')).toHaveValue(/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/);

  const description = `E2E draft ${fixture.tag}: synthetic fault intake for persistence verification.`;
  await browser.locator('[wire\\:model="form.description"]').fill(description);
  await browser.locator('form[wire\\:submit="save"] button[type="submit"]').click();
  await expect(browser).toHaveURL(/\/maintenance\/requests\/\d+$/);
  const requestId = Number(new URL(await browser.url()).pathname.split('/').at(-1));
  expect(requestId).toBeGreaterThan(0);
  await expect(screen.getByText('مسودة')).toBeVisible();
  await expect(browser.locator('[wire\\:model="form.description"]')).toHaveValue(description);

  await browser.reload();
  await expect(browser.locator('[wire\\:model="form.description"]')).toHaveValue(description);
  const persisted = readback(requestId).maintenance_request;
  expect(persisted.status).toBe('draft');
  expect(persisted.branch_id).toBe(fixture.branch_id);
  expect(persisted.customer_id).toBe(fixture.customer_id);
  expect(persisted.responsible_id).toBe(fixture.actor_id);
  expect(persisted.created_by).toBe(fixture.actor_id);
  expect(persisted.description).toBe(description);
  expect(persisted.version).toBe(1);
  expect(persisted.saved_audit_count).toBe(1);
  expect(persisted.work_order_count).toBe(0);
});
