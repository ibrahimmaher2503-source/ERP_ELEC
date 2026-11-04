'use strict';

// Specs import this instead of playwright/test. Unattended pages get every dialog accepted, because an
// unanswered "unsaved changes" or beforeunload dialog freezes reloads and context teardown on Windows.
const base = require('playwright/test');

const test = base.test.extend({
  browser: [async ({ browser }, use) => {
    const original = browser.newContext.bind(browser);
    browser.newContext = async (...args) => {
      const context = await original(...args);
      const accept = page => page.on('dialog', dialog => { dialog.accept().catch(() => {}); });
      context.pages().forEach(accept);
      context.on('page', accept);

      return context;
    };
    await use(browser);
  }, { scope: 'worker' }],
});

module.exports = { ...base, test };
