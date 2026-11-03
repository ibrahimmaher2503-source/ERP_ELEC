'use strict';

// One isolated, read-only native Debugbar profile of financial.aging.
const fs = require('node:fs');
const path = require('node:path');
const net = require('node:net');
const { spawn } = require('node:child_process');
const { chromium } = require('playwright');
const visual = require('../visual-audit.cjs');
const performance = require('../page-speed-audit.cjs');

const root = visual.root;
const work = path.join(root, '.tmp', 'page-speed-audit', 'native-debugbar-aging-profile');
const privateLog = path.join(work, 'php-server.log');
const rawOutput = path.join(work, 'debugbar-response.raw.json');
const summaryOutput = path.join(work, 'summary.json');

function freePort() {
  return new Promise((resolve, reject) => {
    const server = net.createServer();
    server.once('error', reject);
    server.listen(0, '127.0.0.1', () => { const port = server.address().port; server.close(() => resolve(port)); });
  });
}

function summarize(data) {
  const datasets = data?.__meta?.data || data?.data || data || {};
  const querySet = datasets.queries || datasets.db || {};
  const applicationMeasure = (datasets.time?.measures || []).find(item => item.label === 'Application');
  const routeMeasure = (datasets.time?.measures || []).find(item => item.label === 'Routing');
  const queries = Array.isArray(querySet.statements) ? querySet.statements : Array.isArray(querySet.data) ? querySet.data : [];
  const times = queries.map(row => Number(row.duration ?? row.time)).filter(Number.isFinite);
  const frequencies = new Map();
  for (const row of queries) {
    const fingerprint = String(row.sql || row.query || '').replace(/\s+/g, ' ').trim();
    if (fingerprint) frequencies.set(fingerprint, (frequencies.get(fingerprint) || 0) + 1);
  }
  return {
    dataset_names: Object.keys(datasets),
    application_time_ms: applicationMeasure ? Number((Number(applicationMeasure.duration || 0) * 1000).toFixed(2)) : null,
    routing_time_ms: routeMeasure ? Number((Number(routeMeasure.duration || 0) * 1000).toFixed(2)) : null,
    debugbar_request_duration_ms: datasets.time?.duration ? Number((Number(datasets.time.duration) * 1000).toFixed(2)) : null,
    blade_template_count: Number(datasets.views?.count ?? 0),
    exception_count: Number(datasets.exceptions?.count ?? 0),
    query_count: Number(querySet.count ?? querySet.nb_statements ?? queries.length),
    query_time_ms: Number((Number(querySet.accumulated_duration || 0) * 1000).toFixed(2)),
    slowest_query_ms: times.length ? Number((Math.max(...times) * 1000).toFixed(2)) : null,
    slow_query_count_over_100ms: times.filter(value => value >= 0.1).length,
    repeated_sql_fingerprints: [...frequencies.entries()].filter(([, count]) => count > 1).length,
    php_memory_bytes: Number(datasets.memory?.peak_usage || querySet.memory_usage || 0) || null,
    exceptions_dataset_present: Object.hasOwn(datasets, 'exceptions'),
  };
}

async function main() {
  const owned = visual.readOwnedBootstrap();
  if (owned.dbName !== 'erp_elec_new_test' || Number(owned.dbPort) !== 55461) throw new Error('Refusing Debugbar profile outside owned 55461 test DB.');
  const marker = JSON.parse(fs.readFileSync(owned.clusterMarker, 'utf8'));
  if (marker.kind !== 'codex-e2e-postgres' || marker.repo !== root || Number(marker.port) !== 55461 || marker.token !== owned.clusterToken) throw new Error('PostgreSQL ownership marker mismatch.');
  fs.mkdirSync(work, { recursive: true });
  const port = await freePort(), base = `http://127.0.0.1:${port}`;
  const runtime = path.join(work, 'runtime'); fs.mkdirSync(runtime, { recursive: true });
  const relative = file => path.relative(root, file).replace(/\\/g, '/');
  const env = {
    ...owned.env,
    APP_URL: base,
    APP_ENV: 'local',
    APP_DEBUG: 'true',
    DEBUGBAR_ENABLED: 'true',
    PULSE_ENABLED: 'false',
    DB_CONNECTION: 'pgsql', DB_HOST: '127.0.0.1', DB_PORT: '55461', DB_DATABASE: 'erp_elec_new_test', DB_URL: '',
    APP_CONFIG_CACHE: relative(path.join(runtime, 'config.php')),
    APP_ROUTES_CACHE: relative(path.join(runtime, 'routes.php')),
    APP_EVENTS_CACHE: relative(path.join(runtime, 'events.php')),
    APP_SERVICES_CACHE: relative(path.join(runtime, 'services.php')),
    APP_PACKAGES_CACHE: relative(path.join(runtime, 'packages.php')),
  };
  if (!String(env.E2E_APP_STORAGE || '').replaceAll('\\', '/').startsWith(`${root.replaceAll('\\', '/')}/.tmp/`)) throw new Error('Refusing non-private Debugbar storage.');
  const fd = fs.openSync(privateLog, 'w');
  const server = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', '.', path.join(root, 'tests', 'E2E', 'support', 'performance-router.php')], {
    cwd: path.join(root, 'public'), env, stdio: ['ignore', fd, fd], windowsHide: true,
  });
  let browser;
  try {
    await performance.waitForServer(base);
    browser = await chromium.launch({ headless: true, executablePath: performance.browserExecutable() });
    await visual.ensureAuth(browser, base, env);
    const context = await browser.newContext({ baseURL: base, storageState: visual.storageState, locale: 'ar-EG', timezoneId: 'Africa/Cairo', viewport: { width: 1440, height: 900 } });
    const page = await context.newPage();
    const response = await page.goto(`${base}/financial/aging`, { waitUntil: 'domcontentloaded', timeout: 45000 });
    const landedPath = new URL(page.url()).pathname;
    if (response?.status() !== 200 || landedPath !== '/financial/aging') throw new Error(`Aging profile route unavailable: HTTP ${response?.status() ?? 'unknown'}, landed on ${landedPath}.`);
    await page.locator('#main-content h1, #main-content [role="heading"], main h1, main [role="heading"]').first().waitFor({ state: 'visible', timeout: 12000 });
    await page.locator('table tbody tr:visible, .financial-mobile-items .aging-card:visible, .financial-mobile-items [role="status"]:visible').first().waitFor({ state: 'visible', timeout: 12000 });
    const requestId = response?.headers()['phpdebugbar-id'] || null;
    if (!requestId) throw new Error(`Debugbar did not return phpdebugbar-id (HTTP ${response?.status() ?? 'unknown'}); profile is unavailable.`);
    const profileResponse = await context.request.get(`${base}/_debugbar/open?op=get&id=${encodeURIComponent(requestId)}`, { headers: { accept: 'application/json' } });
    if (!profileResponse.ok()) throw new Error(`Debugbar OpenHandler returned HTTP ${profileResponse.status()}.`);
    const rawText = await profileResponse.text();
    const profile = JSON.parse(rawText);
    fs.writeFileSync(rawOutput, JSON.stringify(profile, null, 2) + '\n');
    const summary = {
      route: 'financial.aging', status: response?.status() ?? null, request_id: requestId,
      source_sha256: performance.sourceFingerprint(), css_asset: JSON.parse(fs.readFileSync(path.join(root, 'public', 'build', 'manifest.json'), 'utf8'))['resources/css/app.css']?.file || null,
      database: { name: 'erp_elec_new_test', port: 55461 }, storage_dir: path.relative(root, env.E2E_APP_STORAGE).replace(/\\/g, '/'),
      debugbar_enabled: true, debugbar_native_data: true, query_bindings: 'raw Debugbar data stays private in .tmp; do not publish',
      primary_heading_visible: true, financial_rows_or_empty_state_visible: true, ...summarize(profile),
      raw_profile: path.relative(root, rawOutput).replace(/\\/g, '/'), server_log: path.relative(root, privateLog).replace(/\\/g, '/'),
    };
    fs.writeFileSync(summaryOutput, JSON.stringify(summary, null, 2) + '\n');
    process.stdout.write(JSON.stringify({ ...summary, request_id: '[private]', raw_profile: summary.raw_profile }) + '\n');
    await context.close();
  } finally {
    if (browser) await browser.close().catch(() => {});
    if (server.exitCode === null) server.kill();
    try { fs.closeSync(fd); } catch {}
  }
}

main().catch(error => { process.stderr.write(`${String(error.message || error).slice(0, 500)}\n`); process.exitCode = 1; });
