'use strict';

// Correct only product-media pages whose first pass used the wrong local disk root.
const fs = require('node:fs');
const path = require('node:path');
const net = require('node:net');
const { spawn, spawnSync } = require('node:child_process');
const { chromium } = require('playwright');
const { measurePage, browserExecutable, waitForServer, paths } = require('../page-speed-audit.cjs');

const { root, workDir, reportDir, storageState } = paths;
const bootstrap = JSON.parse(fs.readFileSync(path.join(workDir, 'bootstrap.json'), 'utf8'));
const resultsPath = path.join(reportDir, 'ERP_PAGE_SPEED_RESULTS.json');
const outputPath = path.join(workDir, 'product-storage-recheck.json');
const selectedNames = new Set(['products.index', 'products.edit', 'products.show']);

function freePort() {
  return new Promise((resolve, reject) => {
    const server = net.createServer(); server.once('error', reject);
    server.listen(0, '127.0.0.1', () => { const port = server.address().port; server.close(() => resolve(port)); });
  });
}

async function main() {
  if (bootstrap.dbName !== 'erp_elec_new_test' || bootstrap.dbPort !== 55461) throw new Error('Refusing product-media recheck outside the owned performance database.');
  const report = JSON.parse(fs.readFileSync(resultsPath, 'utf8'));
  const routeMap = new Map();
  for (const sample of report.raw_results) if (selectedNames.has(sample.route_name) && ['cold','warm'].includes(sample.temperature)) {
    routeMap.set(sample.route_name, { name: sample.route_name, variant: sample.route_variant || 'default', module: sample.module, uri: sample.uri, url: sample.url });
  }
  const routes = [...routeMap.values()];
  if (routes.length !== selectedNames.size) throw new Error(`Expected exactly three already-measured product routes; found ${routes.length}.`);
  const port = await freePort(), baseUrl = `http://127.0.0.1:${port}`;
  const env = { ...bootstrap.env, APP_URL: baseUrl, LARAVEL_STORAGE_PATH: bootstrap.storage, AUDIT_QUERY_LOG: '' };
  const fixture = spawnSync('php', [path.join(root, 'tests/E2E/support/performance-auth-refresh.php')], {
    cwd: root, encoding: 'utf8', windowsHide: true, maxBuffer: 2 * 1024 * 1024, env,
  });
  if (fixture.status !== 0) throw new Error(`Could not create the targeted recheck actor: ${String(fixture.stderr || fixture.stdout).slice(-800)}`);
  const actor = JSON.parse(String(fixture.stdout || '').trim().split(/\r?\n/).filter(Boolean).at(-1));
  const log = fs.openSync(path.join(bootstrap.logs, `product-storage-recheck-${report.conditions.generated_run_id}.log`), 'w');
  const server = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', '.', path.join(root, 'tests/E2E/support/performance-router.php')], {
    cwd: path.join(root, 'public'), env, stdio: ['ignore', log, log], windowsHide: true,
  });
  let browser;
  const results = [];
  try {
    await waitForServer(baseUrl);
    browser = await chromium.launch({ headless: true, executablePath: browserExecutable() });
    const authContext = await browser.newContext({ baseURL: baseUrl, locale: 'ar-EG', timezoneId: 'Africa/Cairo', viewport: { width: 1440, height: 900 } });
    const authPage = await authContext.newPage();
    await authPage.goto('/login', { waitUntil: 'domcontentloaded' });
    await authPage.locator('input[name="email"]').fill(actor.email);
    await authPage.locator('input[name="password"]').fill(actor.password);
    await Promise.all([authPage.waitForURL(/\/dashboard(?:\?|$)/, { waitUntil: 'domcontentloaded', timeout: 20_000 }), authPage.locator('[data-test="login-button"]').click()]);
    await authPage.goto('/settings/security', { waitUntil: 'domcontentloaded' });
    if (/confirm-password/.test(new URL(authPage.url()).pathname)) {
      await authPage.locator('input[name="password"]').fill(actor.password);
      await Promise.all([authPage.waitForURL(/\/settings\/security(?:\?|$)/, { waitUntil: 'domcontentloaded', timeout: 20_000 }), authPage.locator('[data-test="confirm-password-button"]').click()]);
    }
    if (!/\/settings\/security(?:\?|$)/.test(new URL(authPage.url()).pathname + new URL(authPage.url()).search)) throw new Error('Targeted recheck actor did not complete normal password confirmation.');
    await authContext.storageState({ path: storageState });
    await authContext.close();
    for (const viewport of [{ name: 'desktop', width: 1440, height: 900 }, { name: 'mobile', width: 390, height: 844 }]) {
      for (const route of routes) {
        const context = await browser.newContext({ baseURL: baseUrl, storageState, locale: 'ar-EG', timezoneId: 'Africa/Cairo', viewport });
        const page = await context.newPage();
        const cold = await measurePage(page, baseUrl, route, viewport, 'cold', 0);
        results.push({ ...cold, targeted_recheck: 'isolated storage-root correction', original_run_id: report.conditions.generated_run_id });
        fs.writeFileSync(outputPath, JSON.stringify({ run_id: report.conditions.generated_run_id, updated_at: new Date().toISOString(), results }, null, 2) + '\n');
        for (let trial = 1; trial <= 3; trial++) {
          const warm = await measurePage(page, baseUrl, route, viewport, 'warm', trial);
          results.push({ ...warm, targeted_recheck: 'isolated storage-root correction', original_run_id: report.conditions.generated_run_id });
          if (!warm.redirected_to_login) await context.storageState({ path: storageState });
          fs.writeFileSync(outputPath, JSON.stringify({ run_id: report.conditions.generated_run_id, updated_at: new Date().toISOString(), results }, null, 2) + '\n');
        }
        await context.close();
      }
      process.stdout.write(JSON.stringify({ viewport: viewport.name, completed_samples: results.length,
        degraded: results.filter(row => row.http_errors?.length || row.failed_requests?.length || row.js_errors?.length).length }) + '\n');
    }
  } finally {
    if (browser) await browser.close().catch(() => {});
    if (server.exitCode === null) server.kill();
    try { fs.closeSync(log); } catch {}
  }
}

main().catch(error => { process.stderr.write(`${error.stack || error}\n`); process.exitCode = 1; });
