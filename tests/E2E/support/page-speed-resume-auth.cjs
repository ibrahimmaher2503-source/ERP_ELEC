'use strict';

// Replaces measurements invalidated by an expired test session. Uses the
// existing 55461 database and routes; it never resets or reseeds application data.
const fs = require('node:fs');
const path = require('node:path');
const net = require('node:net');
const { spawn, spawnSync } = require('node:child_process');
const { chromium } = require('playwright');
const { measurePage, browserExecutable, waitForServer, paths } = require('../page-speed-audit.cjs');

const { root, workDir, reportDir, storageState } = paths;
const bootstrap = JSON.parse(fs.readFileSync(path.join(workDir, 'bootstrap.json'), 'utf8'));
const progressPath = path.join(workDir, 'progress.json');
const resumedPath = path.join(workDir, 'resumed-mobile-results.json');

function freePort() {
  return new Promise((resolve, reject) => { const s = net.createServer(); s.once('error', reject); s.listen(0, '127.0.0.1', () => { const p = s.address().port; s.close(() => resolve(p)); }); });
}

function createActor() {
  if (bootstrap.dbName !== 'erp_elec_new_test' || bootstrap.dbPort !== 55461) throw new Error('Refusing nonperformance database.');
  const child = spawnSync('php', [path.join(root, 'tests/E2E/support/performance-auth-refresh.php')], {
    cwd: root, env: { ...process.env, ...bootstrap.env }, encoding: 'utf8', windowsHide: true,
  });
  if (child.status !== 0) throw new Error(`Could not create isolated refresh actor (exit ${child.status}).`);
  return JSON.parse(String(child.stdout || '').trim());
}

async function main() {
  const progress = JSON.parse(fs.readFileSync(progressPath, 'utf8'));
  const prior = progress.results;
  const desktopRoutes = [];
  const seen = new Set();
  for (const row of prior) {
    if (row.viewport !== 'desktop' || row.temperature !== 'cold' || !row.url || !row.route_name) continue;
    const key = `${row.route_name}|${row.route_variant || 'default'}`;
    if (!seen.has(key)) { seen.add(key); desktopRoutes.push({ name: row.route_name, route_name: row.route_name,
      route_variant: row.route_variant || 'default', variant: row.route_variant || 'default', module: row.module,
      uri: row.uri, url: row.url }); }
  }
  const startIndex = desktopRoutes.findIndex(row => row.route_name === 'security.edit');
  if (startIndex < 0) throw new Error('Could not locate route boundary after first expired session sample.');
  const routes = desktopRoutes.slice(startIndex + 1).filter(route => !/^(?:financial\.documents|tenders\.bid)$/.test(route.route_name));
  const oldBad = prior.filter(row => row.viewport === 'mobile' && row.redirected_to_login);
  const securityRows = prior.filter(row => row.route_name === 'security.edit' && row.viewport === 'mobile' && row.temperature !== 'transition');
  for (const row of securityRows) {
    row.measured = false; row.healthy = false; row.readiness = 'not-measured';
    row.not_measured_reason = 'Password-confirmation flow not completed by harness';
    row.ready_error = 'Password-confirmation flow not completed by harness'; row.redirected_to_login = false;
  }
  const replacedKeys = new Set(routes.map(row => `${row.route_name}|${row.route_variant || 'default'}`));
  const results = [];
  const actor = createActor();
  const port = await freePort(), baseUrl = `http://127.0.0.1:${port}`;
  const env = { ...bootstrap.env, APP_URL: baseUrl, AUDIT_QUERY_LOG: '' };
  const log = fs.openSync(path.join(bootstrap.logs, `resume-auth-${progress.run_id}.log`), 'w');
  const server = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', '.', path.join(root, 'tests/E2E/support/performance-router.php')], { cwd: path.join(root, 'public'), env, stdio: ['ignore', log, log], windowsHide: true });
  let browser;
  try {
    await waitForServer(baseUrl);
    browser = await chromium.launch({ headless: true, executablePath: browserExecutable() });
    const viewport = { name: 'mobile', width: 390, height: 844 };
    const auth = await browser.newContext({ baseURL: baseUrl, locale: 'ar-EG', timezoneId: 'Africa/Cairo', viewport });
    const login = await auth.newPage();
    await login.goto('/login', { waitUntil: 'domcontentloaded', timeout: 20_000 });
    await login.locator('input[name="email"]').fill(actor.email);
    await login.locator('input[name="password"]').fill(actor.password);
    await Promise.all([login.waitForURL(/\/dashboard(?:\?|$)/, { waitUntil: 'domcontentloaded', timeout: 20_000 }), login.locator('[data-test="login-button"]').click()]);
    await auth.storageState({ path: storageState });
    await auth.close();

    for (const route of routes) {
      const coldContext = await browser.newContext({ baseURL: baseUrl, storageState, locale: 'ar-EG', timezoneId: 'Africa/Cairo', viewport });
      const coldPage = await coldContext.newPage();
      const cold = await measurePage(coldPage, baseUrl, route, viewport, 'cold', 0);
      results.push(cold); await coldContext.close();
      if (cold.redirected_to_login) throw new Error(`Fresh session redirected on ${route.route_name}; stopping resume safely.`);

      const warmContext = await browser.newContext({ baseURL: baseUrl, storageState, locale: 'ar-EG', timezoneId: 'Africa/Cairo', viewport });
      const warmPage = await warmContext.newPage();
      await warmPage.goto(`${baseUrl}${route.url}`, { waitUntil: 'domcontentloaded', timeout: 45_000 });
      await warmContext.storageState({ path: storageState });
      for (let trial = 1; trial <= 3; trial++) {
        const sample = await measurePage(warmPage, baseUrl, route, viewport, 'warm', trial);
        if (sample.redirected_to_login) throw new Error(`Fresh session expired during resume at ${route.route_name}; stopping safely.`);
        results.push(sample);
        await warmContext.storageState({ path: storageState });
      }
      await warmContext.close();
      fs.writeFileSync(resumedPath, JSON.stringify({ run_id: progress.run_id, refreshed_actor: 'synthetic Administrator on isolated test DB', updated_at: new Date().toISOString(), results }, null, 2) + '\n');
    }

    const byKey = new Map(results.map(row => [`${row.route_name}|${row.route_variant || 'default'}|${row.viewport}|${row.temperature}|${row.trial}`, row]));
    const merged = prior.filter(row => !byKey.has(`${row.route_name}|${row.route_variant || 'default'}|${row.viewport}|${row.temperature}|${row.trial}`));
    merged.push(...results);
    const updated = { ...progress, updated_at: new Date().toISOString(), completed_samples: merged.length, results: merged };
    fs.writeFileSync(progressPath, JSON.stringify(updated, null, 2) + '\n');
    fs.writeFileSync(resumedPath, JSON.stringify({ run_id: progress.run_id, refreshed_actor: 'synthetic Administrator on isolated test DB',
      discarded_expired_samples: oldBad.length, updated_at: new Date().toISOString(), results }, null, 2) + '\n');
    process.stdout.write(JSON.stringify({ status: 'resumed', route_cases: routes.length, samples: results.length, measured: results.filter(row => row.measured).length, discarded_expired_samples: oldBad.length, output: resumedPath }) + '\n');
  } finally { if (browser) await browser.close().catch(() => {}); if (server.exitCode === null) server.kill(); try { fs.closeSync(log); } catch {} }
}

main().catch(error => { process.stderr.write(`${error.stack || error}\n`); process.exitCode = 1; });
