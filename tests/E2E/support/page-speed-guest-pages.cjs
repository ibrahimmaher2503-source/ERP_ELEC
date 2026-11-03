'use strict';

// Read-only public authentication page measurements. Never submits forms or
// requests reset mail. Requires the already-owned isolated performance cluster.
const fs = require('node:fs');
const path = require('node:path');
const net = require('node:net');
const { spawn } = require('node:child_process');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '../../..');
const work = path.join(root, '.tmp/page-speed-audit');
const reportDir = path.join(root, 'docs/performance');
const resultPath = path.join(reportDir, 'ERP_PAGE_SPEED_RESULTS.json');
const runId = fs.existsSync(resultPath) ? JSON.parse(fs.readFileSync(resultPath, 'utf8')).conditions.generated_run_id
  : JSON.parse(fs.readFileSync(path.join(work, 'progress.json'), 'utf8')).run_id;
const bootstrap = JSON.parse(fs.readFileSync(path.join(work, 'bootstrap.json'), 'utf8'));
const pages = [
  { name: 'login', path: '/login', module: 'M01 Organization', ready: 'input[name="email"]' },
  { name: 'password.request', path: '/forgot-password', module: 'M01 Organization', ready: 'input[name="email"]' },
];
const viewports = [{ name: 'desktop', width: 1440, height: 900 }, { name: 'mobile', width: 390, height: 844 }];

function freePort() {
  return new Promise((resolve, reject) => {
    const server = net.createServer(); server.once('error', reject);
    server.listen(0, '127.0.0.1', () => { const port = server.address().port; server.close(() => resolve(port)); });
  });
}

async function sample(browser, baseUrl, route, viewport, temperature, trial, context) {
  const page = await context.newPage();
  const failures = [], httpErrors = [], jsErrors = [];
  const responseSizes = [];
  let requests = 0, bytes = 0, response;
  page.on('request', () => requests++);
  page.on('requestfailed', request => { if (failures.length < 10) failures.push(`${request.method()} ${request.url()} ${request.failure()?.errorText || ''}`.trim()); });
  page.on('response', res => {
    if (res.status() >= 400 && httpErrors.length < 10) httpErrors.push({ status: res.status(), url: res.url() });
    responseSizes.push((async () => { try { bytes += Number((await res.body()).byteLength); } catch {} })());
  });
  page.on('pageerror', error => { if (jsErrors.length < 5) jsErrors.push(String(error.message || error).slice(0, 250)); });
  const started = Date.now();
  try {
    response = await page.goto(`${baseUrl}${route.path}`, { waitUntil: 'domcontentloaded', timeout: 20_000 });
    await page.locator(route.ready).waitFor({ state: 'visible', timeout: 5_000 });
    await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
    await Promise.allSettled(responseSizes);
    const timing = await page.evaluate(() => {
      const nav = performance.getEntriesByType('navigation')[0];
      return { ttfb: nav ? nav.responseStart - nav.requestStart : null,
        dcl: nav?.domContentLoadedEventEnd || null, load: nav?.loadEventEnd || null };
    });
    const current = new URL(page.url());
    const measured = response?.status() === 200 && current.pathname.replace(/\/$/, '') === route.path;
    return { route_name: route.name, route_variant: 'default', module: route.module, uri: route.path.slice(1), url: route.path,
      viewport: viewport.name, viewport_width: viewport.width, viewport_height: viewport.height, temperature, trial,
      navigation_mode: 'full-document-navigation', measured, healthy: measured && !httpErrors.length && !failures.length && !jsErrors.length,
      readiness: measured ? 'passed' : 'failed', not_measured_reason: measured ? null : `HTTP ${response?.status() ?? 'unknown'} or redirected to ${current.pathname}`,
      http_status: response?.status() ?? null, ttfb_ms: timing.ttfb, domcontentloaded_ms: timing.dcl,
      load_ms: timing.load > 0 ? timing.load : null, ui_ready_ms: Date.now() - started,
      livewire_stable_ms: null, total_requests: requests, transferred_bytes: bytes,
      http_errors: httpErrors, failed_requests: failures, js_errors: jsErrors, console_errors: [] };
  } catch (error) {
    return { route_name: route.name, route_variant: 'default', module: route.module, uri: route.path.slice(1), url: route.path,
      viewport: viewport.name, viewport_width: viewport.width, viewport_height: viewport.height, temperature, trial,
      navigation_mode: 'full-document-navigation', measured: false, healthy: false, readiness: 'failed',
      not_measured_reason: String(error.message || error).slice(0, 300), http_status: response?.status() ?? null,
      ttfb_ms: null, domcontentloaded_ms: null, load_ms: null, ui_ready_ms: null, livewire_stable_ms: null,
      total_requests: requests, transferred_bytes: bytes, http_errors: httpErrors, failed_requests: failures, js_errors: jsErrors, console_errors: [] };
  } finally { await page.close(); }
}

async function main() {
  if (bootstrap.dbName !== 'erp_elec_new_test' || bootstrap.dbPort !== 55461) throw new Error('Refusing guest measurement without dedicated performance database.');
  const port = await freePort(), baseUrl = `http://127.0.0.1:${port}`;
  const env = { ...bootstrap.env, APP_URL: baseUrl, AUDIT_QUERY_LOG: '' };
  const log = fs.openSync(path.join(bootstrap.logs, `guest-pages-${runId}.log`), 'w');
  const server = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', '.', path.join(root, 'tests/E2E/support/performance-router.php')], { cwd: path.join(root, 'public'), env, stdio: ['ignore', log, log], windowsHide: true });
  let browser;
  try {
    for (let i = 0; i < 80; i++) { try { const r = await fetch(`${baseUrl}/login`); if (r.status < 500) break; } catch {} await new Promise(resolve => setTimeout(resolve, 150)); }
    const edge = ['C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', 'C:/Program Files/Microsoft/Edge/Application/msedge.exe'].find(fs.existsSync);
    if (!edge) throw new Error('Microsoft Edge executable unavailable.');
    browser = await chromium.launch({ headless: true, executablePath: edge });
    const rows = [];
    for (const viewport of viewports) for (const route of pages) {
      const cold = await browser.newContext({ locale: 'ar-EG', timezoneId: 'Africa/Cairo', viewport });
      rows.push(await sample(browser, baseUrl, route, viewport, 'cold', 0, cold)); await cold.close();
      const warm = await browser.newContext({ locale: 'ar-EG', timezoneId: 'Africa/Cairo', viewport });
      await sample(browser, baseUrl, route, viewport, 'warmup', 0, warm);
      for (let trial = 1; trial <= 3; trial++) rows.push(await sample(browser, baseUrl, route, viewport, 'warm', trial, warm));
      await warm.close();
    }
    const file = path.join(reportDir, 'runs', runId, 'ERP_PAGE_SPEED_GUEST_PAGES.json');
    fs.mkdirSync(path.dirname(file), { recursive: true });
    fs.writeFileSync(file, JSON.stringify(rows, null, 2) + '\n');
    process.stdout.write(JSON.stringify({ status: 'complete', samples: rows.length, measured: rows.filter(row => row.measured).length, file }) + '\n');
  } finally { if (browser) await browser.close().catch(() => {}); if (server.exitCode === null) server.kill(); try { fs.closeSync(log); } catch {} }
}

if (require.main === module) main().catch(error => { process.stderr.write(`${error.stack || error}\n`); process.exitCode = 1; });

module.exports = { sample };
