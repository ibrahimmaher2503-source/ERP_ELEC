'use strict';

const { test: baseTest, expect } = require('playwright/test');

const test = baseTest.extend({
  sharedMobileContext: [async ({ browser, baseURL }, use) => {
    const context = await browser.newContext({ baseURL, storageState: process.env.E2E_STORAGE_STATE,
      locale: 'ar-EG', timezoneId: 'Africa/Cairo', viewport: { width: 390, height: 844 } });
    await use(context);
    await context.close();
  }, { scope: 'worker' }],
  page: async ({ sharedMobileContext }, use) => {
    const page = await sharedMobileContext.newPage();
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
  ['M13 treasury accounts', '/treasury/accounts'], ['M08 field maintenance', '/maintenance'], ['M09 workshop repairs', '/workshops/dashboard'],
];

for (const [label, url] of corePages) {
  test(`mobile authorized module page renders: ${label}`, async ({ page }) => {
    const errors = [];
    page.on('pageerror', error => errors.push(error.message.slice(0, 200)));
    page.on('response', response => { if (response.status() >= 500) errors.push(`HTTP ${response.status()}`); });
    const response = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 15_000 });
    expect(response?.status(), label).toBe(200);
    await expect(page.locator('#main-content'), label).toBeVisible();
    await page.waitForFunction(() => Boolean(window.Livewire), null, { timeout: 5_000 });
    const dimensions = await page.evaluate(() => ({ viewport: innerWidth, document: document.documentElement.scrollWidth }));
    expect(dimensions.document, label).toBeLessThanOrEqual(dimensions.viewport + 4);
    expect(errors, label).toEqual([]);
  });
}
