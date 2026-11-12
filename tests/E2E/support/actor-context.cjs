'use strict';

const path = require('node:path');

async function actorContext(browser, run, key, { root = path.resolve(__dirname, '../../..'), env = process.env } = {}) {
  const state = run.actor_states?.[key];
  if (!state) throw new Error(`Runner did not provide ${key} authentication state.`);
  if (run.dbName !== 'erp_elec_new_test' || Number(run.dbPort) !== 55460
      || run.baseUrl !== env.E2E_BASE_URL || run.runToken !== env.E2E_RUN_TOKEN
      || !/^http:\/\/127\.0\.0\.1:\d+$/.test(env.E2E_BASE_URL || '')) {
    throw new Error('Refusing actor-session validation outside the owned isolated E2E runtime.');
  }
  const statePath = path.isAbsolute(state) ? state : path.resolve(root, state);
  const actorRoot = `${path.resolve(run.runtime, 'actors')}${path.sep}`;
  if (!path.resolve(statePath).startsWith(actorRoot)) throw new Error('Refusing actor state outside the owned run directory.');
  const probe = await browser.newContext({ baseURL: env.E2E_BASE_URL, storageState: statePath });
  let probeStatus;
  try { probeStatus = (await probe.request.get('/dashboard', { maxRedirects: 0, timeout: 15_000 })).status(); }
  finally { await probe.close(); }
  const valid = probeStatus === 200;
  process.stdout.write(`[e2e-auth] actor=${key} session=${valid ? 'valid' : 'renewal_required'} status=${probeStatus}\n`);
  if (!valid) {
    const credentials = JSON.parse(env.E2E_ACTOR_CREDENTIALS || '{}')[key];
    if (!credentials?.email || !credentials?.password) throw new Error(`No in-memory test credential is available to renew ${key}.`);
    const refresh = await browser.newContext({ baseURL: env.E2E_BASE_URL, locale: 'ar-EG', timezoneId: 'Africa/Cairo' });
    try {
      const page = await refresh.newPage();
      page.setDefaultTimeout(15_000);
      page.setDefaultNavigationTimeout(15_000);
      await page.goto('/login', { waitUntil: 'domcontentloaded' });
      await page.locator('input[name="email"]').fill(credentials.email);
      await page.locator('input[name="password"]').fill(credentials.password);
      await Promise.all([page.waitForURL(url => url.pathname === '/dashboard', { timeout: 15_000 }),
        page.locator('[data-test="login-button"]').click({ noWaitAfter: true })]);
      await page.locator('#main-content').waitFor({ state: 'visible' });
      await refresh.storageState({ path: statePath });
    } finally { await refresh.close(); }
  }
  return browser.newContext({ baseURL: env.E2E_BASE_URL, storageState: statePath, locale: 'ar-EG', timezoneId: 'Africa/Cairo' });
}

module.exports = { actorContext };
