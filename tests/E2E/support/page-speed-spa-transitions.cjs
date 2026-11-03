'use strict';

const fs = require('node:fs');
const path = require('node:path');
const net = require('node:net');
const { spawn } = require('node:child_process');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '../../..');
const work = path.join(root, '.tmp/page-speed-audit');
const reportDir = path.join(root, 'docs/performance');
const runDir = path.join(reportDir, 'runs');
const resultsPath = path.join(reportDir, 'ERP_PAGE_SPEED_RESULTS.json');
const runId = fs.existsSync(resultsPath)
  ? JSON.parse(fs.readFileSync(resultsPath, 'utf8')).conditions.generated_run_id
  : JSON.parse(fs.readFileSync(path.join(work, 'progress.json'), 'utf8')).run_id;
const bootstrap = JSON.parse(fs.readFileSync(path.join(work, 'bootstrap.json'), 'utf8'));
const storageState = path.join(work, 'audit-storage-state.json');
const routes = [
  { name: 'customers.index', path: '/customers', group: 'العملاء والموردون' },
  { name: 'suppliers.index', path: '/suppliers', group: 'العملاء والموردون' },
  { name: 'products.index', path: '/products', group: 'المنتجات' },
  { name: 'organization.company', path: '/organization/company', group: 'التنظيم' },
  { name: 'organization.users', path: '/organization/users', group: 'التنظيم' },
  { name: 'sales-orders.index', path: '/sales-orders', group: 'المبيعات' },
];

function freePort() {
  return new Promise((resolve, reject) => {
    const server = net.createServer();
    server.once('error', reject);
    server.listen(0, '127.0.0.1', () => { const port = server.address().port; server.close(() => resolve(port)); });
  });
}

function waitServer(url) {
  return new Promise((resolve, reject) => {
    const deadline = Date.now() + 20_000;
    const poll = async () => {
      if (Date.now() > deadline) return reject(new Error('SPA transition server did not become ready.'));
      try { const response = await fetch(`${url}/login`); if (response.status < 500) return resolve(); } catch {}
      setTimeout(poll, 200);
    };
    poll();
  });
}

async function transition(browser, baseUrl, viewport, target, trial, statePath = storageState) {
  const context = await browser.newContext({ baseURL: baseUrl, storageState: statePath, locale: 'ar-EG', timezoneId: 'Africa/Cairo', viewport });
  const page = await context.newPage();
  const result = { route_name: target.name, route_variant: 'default', module: target.name.split('.')[0], uri: target.path.slice(1), url: target.path,
    viewport: viewport.name, viewport_width: viewport.width, viewport_height: viewport.height, temperature: 'transition', trial,
    navigation_mode: 'wire:navigate', measured: false, healthy: false, transition_status: 'unavailable', transition_ms: null,
    transition_error: null, http_status: null, js_errors: [], failed_requests: [], http_errors: [] };
  const jsErrors = [];
  const requestFailures = [];
  let targetStatus = null;
  page.on('pageerror', error => { if (jsErrors.length < 5) jsErrors.push(String(error.message || error).slice(0, 250)); });
  page.on('requestfailed', request => { if (requestFailures.length < 5) requestFailures.push(`${request.method()} ${request.url()} ${request.failure()?.errorText || ''}`.trim()); });
  page.on('response', response => { if (new URL(response.url()).pathname.replace(/\/$/, '') === target.path.replace(/\/$/, '')) targetStatus = response.status(); });
  try {
    await page.addInitScript(() => {
      document.addEventListener('livewire:navigating', () => performance.mark('audit:spa-start'));
      document.addEventListener('livewire:navigated', () => performance.mark('audit:spa-done'));
    });
    await page.goto(`${baseUrl}/dashboard`, { waitUntil: 'domcontentloaded', timeout: 20_000 });
    await page.locator('#main-content h1, #main-content [role="heading"]').first().waitFor({ state: 'visible', timeout: 6_000 });
    if (viewport.width <= 500) {
      const drawer = page.getByRole('button', { name: 'فتح القائمة' }).first();
      if (await drawer.isVisible().catch(() => false)) await drawer.click({ timeout: 2_000 });
    }
    const groupButtons = page.locator('.erp-sidebar button[aria-expanded]');
    const groupIndex = await groupButtons.evaluateAll((items, label) => items.findIndex(button => (button.innerText || button.getAttribute('aria-label') || '').includes(label)), target.group);
    if (groupIndex >= 0) {
      const group = groupButtons.nth(groupIndex);
      if (await group.getAttribute('aria-expanded') === 'false') await group.click({ timeout: 2_000 });
    }
    const links = page.locator('a[wire\\:navigate]');
    const linkIndex = await links.evaluateAll((items, targetPath) => items.findIndex(anchor => {
      try { return new URL(anchor.href, location.href).pathname.replace(/\/$/, '') === targetPath.replace(/\/$/, ''); } catch { return false; }
    }), target.path);
    if (linkIndex < 0) return { ...result, transition_error: 'The requested wire:navigate target is absent from the opened navigation group.' };
    const link = links.nth(linkIndex);
    if (!(await link.isVisible().catch(() => false))) return { ...result, transition_error: 'The requested wire:navigate link remains hidden after opening its navigation group.' };
    const origin = await page.evaluate(() => performance.timeOrigin);
    await page.evaluate(() => performance.mark('audit:spa-click'));
    await Promise.all([page.waitForURL(url => url.pathname.replace(/\/$/, '') === target.path.replace(/\/$/, ''), { timeout: 10_000 }), link.click({ timeout: 3_000 })]);
    await page.waitForFunction(() => performance.getEntriesByName('audit:spa-done').length > 0, null, { timeout: 8_000 });
    await page.locator('#main-content h1, #main-content [role="heading"]').first().waitFor({ state: 'visible', timeout: 5_000 });
    const details = await page.evaluate(origin => ({ same_document: performance.timeOrigin === origin,
      elapsed: performance.getEntriesByName('audit:spa-done').at(-1)?.startTime - performance.getEntriesByName('audit:spa-click').at(-1)?.startTime }), origin);
    const passed = details.same_document && targetStatus === 200 && !jsErrors.length && !requestFailures.length;
    return { ...result, measured: passed, healthy: passed, transition_status: passed ? 'passed' : 'lifecycle-or-request-failure',
      transition_ms: passed ? Number(details.elapsed.toFixed(2)) : null, http_status: targetStatus,
      transition_error: passed ? null : `Same-document=${details.same_document}, target HTTP=${targetStatus ?? 'unknown'}, JS errors=${jsErrors.length}, failed requests=${requestFailures.length}`,
      js_errors: jsErrors, failed_requests: requestFailures };
  } catch (error) {
    return { ...result, transition_error: String(error.message || error).slice(0, 350), http_status: targetStatus, js_errors: jsErrors, failed_requests: requestFailures };
  } finally { await context.close(); }
}

async function main() {
  if (!fs.existsSync(storageState) || bootstrap.dbName !== 'erp_elec_new_test' || bootstrap.dbPort !== 55461) throw new Error('Refusing SPA run without the dedicated performance test storage and database.');
  const port = await freePort();
  const baseUrl = `http://127.0.0.1:${port}`;
  const env = { ...bootstrap.env, APP_URL: baseUrl, AUDIT_QUERY_LOG: '' };
  const logPath = path.join(bootstrap.logs, `spa-transitions-${runId}.log`);
  const log = fs.openSync(logPath, 'w');
  const server = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', '.', path.join(root, 'tests/E2E/support/performance-router.php')], { cwd: path.join(root, 'public'), env, stdio: ['ignore', log, log], windowsHide: true });
  let browser;
  const progressPath = path.join(work, 'spa-progress.json');
  const prior = fs.existsSync(progressPath) ? JSON.parse(fs.readFileSync(progressPath, 'utf8')).results || [] : [];
  const results = [...prior];
  const done = new Set(results.map(item => `${item.route_name}|${item.viewport}|${item.trial}`));
  try {
    await waitServer(baseUrl);
    const edges = ['C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', 'C:/Program Files/Microsoft/Edge/Application/msedge.exe'];
    const executable = edges.find(file => fs.existsSync(file));
    if (!executable) throw new Error('Microsoft Edge executable is unavailable.');
    browser = await chromium.launch({ headless: true, executablePath: executable });
    for (const viewport of [{ name: 'desktop', width: 1440, height: 900 }, { name: 'mobile', width: 390, height: 844 }]) {
      for (const route of routes) {
        for (let trial = 1; trial <= 3; trial++) {
          if (done.has(`${route.name}|${viewport.name}|${trial}`)) continue;
          const record = await transition(browser, baseUrl, viewport, route, trial);
          results.push(record);
          done.add(`${route.name}|${viewport.name}|${trial}`);
          fs.writeFileSync(progressPath, JSON.stringify({ run_id: runId, updated_at: new Date().toISOString(), completed: results.length, results }, null, 2) + '\n');
          if (record.transition_status === 'unavailable') break;
        }
      }
    }
    const resultFile = path.join(runDir, runId, 'ERP_PAGE_SPEED_SPA_TRANSITIONS.json');
    fs.mkdirSync(path.dirname(resultFile), { recursive: true });
    fs.writeFileSync(resultFile, JSON.stringify(results, null, 2) + '\n');
    const reportPath = path.join(reportDir, 'ERP_PAGE_SPEED_RESULTS.json');
    if (!fs.existsSync(reportPath)) {
      process.stdout.write(JSON.stringify({ status: 'complete', run_id: runId, attempts: results.length,
        passed: results.filter(x => x.transition_status === 'passed').length,
        unavailable: results.filter(x => x.transition_status === 'unavailable').length, result_file: resultFile }) + '\n');
      return;
    }
    const report = JSON.parse(fs.readFileSync(reportPath, 'utf8'));
    const transitionKeys = new Set(results.map(item => `${item.route_name}|${item.route_variant || 'default'}|${item.viewport}`));
    report.raw_results = report.raw_results.filter(item => item.temperature !== 'transition'
      || !transitionKeys.has(`${item.route_name}|${item.route_variant || 'default'}|${item.viewport}`));
    report.raw_results.push(...results);
    report.discovery.spa_transition_attempts = report.raw_results.filter(item => item.temperature === 'transition').length;
    report.discovery.spa_transition_passed = report.raw_results.filter(item => item.temperature === 'transition' && item.transition_status === 'passed').length;
    fs.writeFileSync(reportPath, JSON.stringify(report, null, 2) + '\n');
    fs.writeFileSync(path.join(reportDir, 'ERP_PAGE_SPEED_RAW.json'), JSON.stringify(report.raw_results, null, 2) + '\n');
    fs.writeFileSync(path.join(runDir, runId, 'ERP_PAGE_SPEED_RAW.json'), JSON.stringify(report.raw_results, null, 2) + '\n');
    process.stdout.write(JSON.stringify({ status: 'complete', run_id: runId, attempts: results.length,
      passed: results.filter(x => x.transition_status === 'passed').length, unavailable: results.filter(x => x.transition_status === 'unavailable').length, result_file: resultFile }) + '\n');
  } finally {
    if (browser) await browser.close().catch(() => {});
    if (server.exitCode === null) server.kill();
    try { fs.closeSync(log); } catch {}
  }
}

if (require.main === module) main().catch(error => { process.stderr.write(`${error.stack || error}\n`); process.exitCode = 1; });

module.exports = { transition, routes };
