import { test } from '@e2e-dev/web';
import { credentials, expect } from 'e2e';
import { setLivewireModel, setModel } from './E2E/testerarmy-form';

test.setup('sign in as the synthetic smoke actor', { sessions: ['smoke'] }, async ({ app, browser, session }) => {
  const smoke = credentials.user('smoke');
  await app.open('/login');
  await browser.locator('input[name="email"]').fill(smoke.username);
  await browser.locator('input[name="password"]').fill(smoke.password);
  await browser.locator('[data-test="login-button"]').click();
  await expect(browser).toHaveURL(/\/dashboard$/);
  await session.save('smoke');
});

test('agent creates a synthetic maintenance request draft', { session: 'smoke', tags: ['smoke', 'maintenance', 'semantic'] }, async ({ app, agent, browser, screen }) => {
  const fixture = JSON.parse(process.env.E2E_FIXTURE_JSON ?? '{}') as { branch_id?: number; customer_id?: number; customer_code?: string; actor_id?: number };
  if (!fixture.branch_id || !fixture.customer_id || !fixture.customer_code || !fixture.actor_id) throw new Error('The guarded smoke fixture is incomplete.');

  await app.open('/maintenance/requests/create');
  await expect(screen.getByRole('heading', 'طلب صيانة جديد')).toBeVisible();
  await setModel(browser, 'form.branch_id', fixture.branch_id, true);
  await setLivewireModel(browser, 'input[name="customerSearch"]', 'customerSearch', fixture.customer_code);
  await expect(browser.locator(`[data-testid="maintenance-request-customer"] option[value="${fixture.customer_id}"]`)).toHaveCount(1);
  await setModel(browser, 'form.customer_id', fixture.customer_id, true);
  await expect(browser.locator(`[wire\\:model="form.responsible_id"] option[value="${fixture.actor_id}"]`)).toHaveCount(1);
  await setModel(browser, 'form.responsible_id', fixture.actor_id);

  await agent.act('In «وصف العطل», enter «بلاغ اختبار اصطناعي: فحص اتصال لوحة إنارة.» Then save using «حفظ الطلب». Do not submit for review, accept it, or create a work order.');
  await expect(browser).toHaveURL(/\/maintenance\/requests\/\d+$/);
  await expect(screen.getByText('مسودة')).toBeVisible();
  await expect(browser.locator('[wire\\:model="form.description"]')).toHaveValue('بلاغ اختبار اصطناعي: فحص اتصال لوحة إنارة.');
  await agent.assert('The request detail shows the synthetic maintenance request saved as a draft. It has not been submitted for review, accepted, or converted into a work order.');
});
