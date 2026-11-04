'use strict';

const { test: baseTest, expect } = require('../support/test.cjs');

const test = baseTest.extend({
  sharedSmokeContext: [async ({ browser }, use) => {
    const context = await browser.newContext({ baseURL: process.env.E2E_BASE_URL, storageState: process.env.E2E_STORAGE_STATE,
      locale: 'ar-EG', timezoneId: 'Africa/Cairo', viewport: { width: 1440, height: 900 } });
    await use(context);
    await context.close();
  }, { scope: 'worker' }],
  page: async ({ sharedSmokeContext }, use) => {
    const page = await sharedSmokeContext.newPage();
    await use(page);
    await page.close();
  },
});

const corePages = [
  ['M01 company', '/organization/company'], ['M01 branches', '/organization/branches'],
  ['M01 users', '/organization/users'], ['M01 roles', '/organization/roles'],
  ['M03 customers', '/customers'], ['M03 suppliers', '/suppliers'], ['M02 products', '/products'],
  ['M04 sales orders', '/sales-orders'], ['M04 quotations', '/quotations'],
  ['M05 purchasing', '/purchasing'], ['M06 inventory dashboard', '/inventory'], ['M07 projects', '/projects'],
  ['M11 accounting dashboard', '/accounting/dashboard'], ['M12 payable invoices', '/financial/supplier-invoices'],
  ['M13 treasury accounts', '/treasury/accounts'], ['M04 sales returns', '/sales-returns'], ['M05 purchase returns', '/purchasing/returns'], ['M01 document numbering', '/organization/document-numbering'], ['M08 field maintenance', '/maintenance'], ['M09 workshop repairs', '/workshops/dashboard'],
];

test('guest is sent to the localized sign-in page', async ({ browser, baseURL }) => {
  const context = await browser.newContext({ baseURL, locale: 'ar-EG', timezoneId: 'Africa/Cairo', storageState: { cookies: [], origins: [] } });
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', error => errors.push(error.message.slice(0, 200)));
  const response = await page.goto('/dashboard');
  expect(response?.status()).toBe(200);
  expect(response?.request().redirectedFrom()?.url()).toContain('/dashboard');
  await expect(page).toHaveURL(/\/login(?:\?|$)/);
  await expect(page.locator('input[name="email"]')).toBeVisible();
  expect(errors).toEqual([]);
  await context.close();
});

test('authenticated dashboard renders', async ({ page }) => {
  const errors = [];
  page.on('pageerror', error => errors.push(error.message.slice(0, 200)));
  page.on('response', response => { if (response.status() >= 500) errors.push(`HTTP ${response.status()}`); });
  const response = await page.goto('/dashboard', { waitUntil: 'domcontentloaded' });
  expect(response?.status()).toBe(200);
  await expect(page).toHaveURL(/\/dashboard(?:\?|$)/);
  await expect(page.locator('#main-content')).toBeVisible();
  await page.waitForFunction(() => Boolean(window.Livewire), null, { timeout: 5_000 });
  expect(await page.locator('html').getAttribute('dir')).toBe('rtl');
  expect(await page.locator('nav a[href]').count()).toBeGreaterThan(2);
  expect(errors).toEqual([]);
});

test('authenticated application shell fits mobile viewport', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  const response = await page.goto('/dashboard', { waitUntil: 'domcontentloaded' });
  expect(response?.status()).toBe(200);
  await expect(page.locator('#main-content')).toBeVisible();
  const dimensions = await page.evaluate(() => ({ viewport: innerWidth, document: document.documentElement.scrollWidth }));
  expect(dimensions.document).toBeLessThanOrEqual(dimensions.viewport + 4);
});

for (const [label, url] of corePages) {
  test(`authorized module page renders: ${label}`, async ({ page }) => {
    const pageErrors = [];
    page.on('pageerror', error => pageErrors.push(error.message.slice(0, 200)));
    const response = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 15_000 });
    expect(response?.status(), label).toBe(200);
    await expect(page.locator('#main-content'), label).toBeVisible();
    await page.waitForFunction(() => Boolean(window.Livewire), null, { timeout: 5_000 });
    expect(pageErrors, label).toEqual([]);
  });
}

test('synthetic administrator profile saves and survives reload', async ({ page }) => {
  const response = await page.goto('/settings/profile', { waitUntil: 'domcontentloaded' });
  expect(response?.status()).toBe(200);
  const name = page.locator('input[wire\\:model="name"]');
  const updatedName = `${await name.inputValue()} browser smoke`;
  await name.fill(updatedName);
  const save = page.waitForResponse(result => /\/livewire(?:-[^/]+)?\/update/.test(result.url()) && result.status() === 200);
  await page.locator('[data-test="update-profile-button"]').click();
  await save;
  await expect(name).toHaveValue(updatedName);
  await page.reload();
  await expect(name).toHaveValue(updatedName);
});
