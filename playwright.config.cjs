const path = require('node:path');

const root = __dirname;
const privateRoot = path.join(root, '.tmp/e2e-automation');
const authState = process.env.E2E_STORAGE_STATE;
const target = process.env.E2E_BASE_URL;
const workers = Number(process.env.E2E_WORKERS || '1');
if (![1, 2].includes(workers) || (workers === 2 && process.env.E2E_MODE !== 'full')) {
  throw new Error('Two Playwright workers are allowed only for full mode with isolated fixture groups.');
}
if (!target || !process.env.E2E_BOOTSTRAP_FILE || !process.env.E2E_RUN_TOKEN) {
  throw new Error('E2E must run through tests/E2E/automation.cjs with a validated owned bootstrap.');
}
const parsedTarget = new URL(target);
if (!['127.0.0.1', 'localhost', '::1'].includes(parsedTarget.hostname)) throw new Error('E2E browser target must be loopback.');
const bootstrap = JSON.parse(require('node:fs').readFileSync(process.env.E2E_BOOTSTRAP_FILE, 'utf8'));
if (bootstrap.runToken !== process.env.E2E_RUN_TOKEN || bootstrap.baseUrl !== target || bootstrap.dbPort !== 55460 || bootstrap.dbName !== 'erp_elec_new_test') {
  throw new Error('E2E target does not match the owned bootstrap identity.');
}

module.exports = {
  testDir: './tests/E2E/specs',
  testMatch: '**/*.spec.cjs',
  outputDir: `./.tmp/e2e-automation/artifacts/${process.env.E2E_RUN_TOKEN}`,
  timeout: 30_000,
  expect: { timeout: 5_000 },
  fullyParallel: false,
  workers,
  retries: 0,
  reporter: [['./tests/E2E/support/failure-only-reporter.cjs']],
  use: {
    baseURL: target,
    browserName: 'chromium',
    launchOptions: { executablePath: process.env.PLAYWRIGHT_EXECUTABLE_PATH || 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe' },
    headless: true,
    viewport: { width: 1440, height: 900 },
    locale: 'ar-EG',
    timezoneId: 'Africa/Cairo',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'off',
  },
  projects: [
    { name: 'desktop', testMatch: '**/*.spec.cjs', testIgnore: '**/smoke-mobile.spec.cjs', use: authState ? { storageState: authState } : {} },
    { name: 'mobile', testMatch: '**/smoke-mobile.spec.cjs', use: { ...(authState ? { storageState: authState } : {}), viewport: { width: 390, height: 844 } } },
  ],
};
