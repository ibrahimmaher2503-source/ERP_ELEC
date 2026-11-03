const fs = require('fs');
const path = require('path');
const net = require('net');
const { spawn, spawnSync } = require('child_process');
const { chromium } = require('playwright');
const audit = require('./page-speed-audit.cjs');

const root = path.resolve(__dirname, '..', '..');
const bootstrapPath = path.join(root, '.tmp', 'page-speed-audit', 'bootstrap.json');
const manifestPath = path.join(root, '.tmp', 'page-speed-audit', 'after-route-manifest.json');
const storageState = path.join(root, '.tmp', 'page-speed-audit', 'audit-storage-state.json');
const outDir = path.join(root, '.tmp', 'visual-audit');
const publicDir = path.join(root, 'docs', 'qa', 'visual-audit');
const screenshotDir = path.join(outDir, 'screenshots');
const viewports = [
  { name: 'desktop-1440', width: 1440, height: 900 },
  { name: 'desktop-1366', width: 1366, height: 768 },
  { name: 'tablet-768', width: 768, height: 1024 },
  { name: 'mobile-390', width: 390, height: 844 },
  { name: 'mobile-360', width: 360, height: 800 },
];

function exe() { return audit.browserExecutable(); }
function safeName(s) { return String(s || 'route').replace(/[^a-z0-9._-]+/gi, '-').replace(/^-|-$/g, '').slice(0, 90); }
function freePort() {
  return new Promise((resolve, reject) => {
    const server = net.createServer();
    server.listen(0, '127.0.0.1', () => { const p = server.address().port; server.close(() => resolve(p)); });
    server.on('error', reject);
  });
}
function wait(ms) { return new Promise(resolve => setTimeout(resolve, ms)); }
async function waitForServer(url) {
  for (let i = 0; i < 80; i++) {
    try { const response = await fetch(`${url}/login`); if (response.status < 500) return; } catch {}
    await wait(250);
  }
  throw new Error('Visual audit PHP server did not become ready.');
}
function readOwnedBootstrap() {
  const b = JSON.parse(fs.readFileSync(bootstrapPath, 'utf8'));
  if (b.dbName !== 'erp_elec_new_test' || Number(b.dbPort) !== 55461) throw new Error('Refusing visual audit outside the marker-owned 55461 test database.');
  const marker = JSON.parse(fs.readFileSync(b.clusterMarker, 'utf8'));
  if (marker.kind !== 'codex-e2e-postgres' || marker.repo !== root || Number(marker.port) !== 55461 || marker.token !== b.clusterToken) throw new Error('PostgreSQL ownership marker validation failed.');
  return b;
}
function chooseCases(cases) {
  const unique = new Map();
  for (const c of cases) if (!unique.has(c.name)) unique.set(c.name, c);
  const all = [...unique.values()].filter(c => c.url && c.baseline_status === 200);
  const preferred = all.filter(c => /dashboard|\.index$|\.create$|\.show$|\.edit$|report|financial|settings|screen/.test(c.name));
  const selected = [];
  for (const c of preferred) { if (!selected.some(x => x.module === c.module)) selected.push(c); }
  for (const c of preferred) { if (selected.length >= 30) break; if (!selected.some(x => x.name === c.name)) selected.push(c); }
  for (const c of all) { if (selected.length >= 30) break; if (!selected.some(x => x.name === c.name)) selected.push(c); }
  return { all, selected: selected.slice(0, 30) };
}
function currentRouteNames(env) {
  const listed = spawnSync('php', ['artisan', 'route:list', '--json'], { cwd: root, env, encoding: 'utf8' });
  if (listed.status !== 0) throw new Error(`Current route registry discovery failed: ${String(listed.stderr || listed.stdout).slice(-800)}`);
  const routes = JSON.parse(listed.stdout);
  return new Set(routes.filter(audit.isPageRoute).flatMap(route => audit.routeVariants(route).map(() => route.name)));
}
function parseLastJson(text) { const lines = String(text || '').trim().split(/\r?\n/).filter(Boolean); for (let i = lines.length - 1; i >= 0; i--) { try { return JSON.parse(lines[i]); } catch {} } return null; }
async function ensureAuth(browser, baseUrl, env) {
  const probe = await browser.newContext({ baseURL: baseUrl, storageState, locale: 'ar-EG', timezoneId: 'Africa/Cairo', viewport: { width: 1440, height: 900 } });
  const page = await probe.newPage(); await page.goto(`${baseUrl}/dashboard`, { waitUntil: 'domcontentloaded', timeout: 20000 }).catch(() => {});
  const valid = /\/dashboard(?:$|\?)/.test(new URL(page.url()).pathname) && await page.locator('#main-content, main').count() > 0;
  await probe.close();
  if (valid) return;
  const helper = spawnSync('php', [path.join(root, 'tests', 'E2E', 'support', 'performance-auth-refresh.php')], { cwd: root, env, encoding: 'utf8' });
  if (helper.status !== 0) throw new Error(`Guarded auth refresh failed: ${String(helper.stderr || helper.stdout).slice(-800)}`);
  const actor = parseLastJson(helper.stdout); if (!actor?.email || !actor?.password) throw new Error('Guarded auth refresh returned no actor credentials.');
  const auth = await browser.newContext({ baseURL: baseUrl, locale: 'ar-EG', timezoneId: 'Africa/Cairo', viewport: { width: 1440, height: 900 } });
  const login = await auth.newPage(); await login.goto(`${baseUrl}/login`, { waitUntil: 'domcontentloaded' });
  await login.locator('input[name="email"]').fill(actor.email); await login.locator('input[name="password"]').fill(actor.password);
  await Promise.all([login.waitForURL(/\/dashboard(?:\?|$)/, { waitUntil: 'domcontentloaded', timeout: 20000 }), login.locator('[data-test="login-button"]').click()]);
  if (!/\/dashboard(?:\?|$)/.test(new URL(login.url()).pathname)) throw new Error('Guarded auth login did not reach dashboard.');
  await auth.storageState({ path: storageState }); await auth.close();
}
async function inspect(page) {
  return page.evaluate(() => {
    const visible = el => { const r = el.getBoundingClientRect(); const s = getComputedStyle(el); return s.display !== 'none' && s.visibility !== 'hidden' && r.width > 0 && r.height > 0; };
    const inputs = [...document.querySelectorAll('input,select,textarea')].filter(visible);
    const unlabeled = inputs.filter(el => !el.getAttribute('aria-label') && !el.getAttribute('aria-labelledby') && !el.labels?.length).slice(0, 20).map(el => ({ tag: el.tagName.toLowerCase(), type: el.type || null, name: el.name || null }));
    const images = [...document.images].filter(img => visible(img) && img.complete && img.naturalWidth === 0).slice(0, 20).map(img => img.currentSrc || img.src);
    const root = document.documentElement;
    const main = document.querySelector('#main-content, main');
    const sidebar = document.querySelector('.erp-sidebar, [data-sidebar], aside');
    const actions = [...document.querySelectorAll('#main-content button, #main-content a, main button, main a')].filter(visible).filter(el => (el.textContent || '').trim() || el.getAttribute('aria-label')).slice(0, 20);
    return {
      dir: root.getAttribute('dir'), lang: root.getAttribute('lang'),
      overflow: root.scrollWidth > window.innerWidth + 4,
      scroll_width: root.scrollWidth, viewport_width: window.innerWidth,
      broken_images: images, sidebar: !!sidebar, main: !!main,
      primary_actions: actions.length,
      unlabeled_fields: unlabeled,
      heading: !!document.querySelector('h1,[role="heading"]'),
    };
  });
}
async function run() {
  fs.rmSync(screenshotDir, { recursive: true, force: true }); fs.mkdirSync(screenshotDir, { recursive: true }); fs.mkdirSync(publicDir, { recursive: true });
  const bootstrap = readOwnedBootstrap();
  const routeManifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8'));
  const port = await freePort(); const baseUrl = `http://127.0.0.1:${port}`;
  const env = { ...bootstrap.env, APP_URL: baseUrl, APP_ENV: 'testing', APP_DEBUG: 'false', DEBUGBAR_ENABLED: 'false', PULSE_ENABLED: 'false' };
  const currentNames = currentRouteNames(env);
  const currentCases = (routeManifest.cases || []).filter(c => currentNames.has(c.name));
  const { all, selected } = chooseCases(currentCases);
  const logPath = path.join(outDir, `server-${Date.now()}.log`); const log = fs.openSync(logPath, 'w');
  const server = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', '.', path.join(root, 'tests', 'E2E', 'support', 'performance-router.php')], { cwd: path.join(root, 'public'), env, stdio: ['ignore', log, log], windowsHide: true });
  const evidence = { generated_at: new Date().toISOString(), database: { name: 'erp_elec_new_test', port: 55461 }, browser: 'Microsoft Edge via Playwright', route_registry_source: 'php artisan route:list --json', route_inventory_count: all.length, route_registry_eligible_names: currentNames.size, selected_screen_count: selected.length, screenshots: [], checks: [], gaps: ['permission-denied, empty, validation and destructive flows were not exercised; no safe existing fixture state was selected for them'] };
  let browser;
  try {
    await waitForServer(baseUrl);
    browser = await chromium.launch({ headless: true, executablePath: exe() });
    await ensureAuth(browser, baseUrl, env);
    const desktopNames = new Set(selected.map(c => c.name));
    for (const vp of viewports) {
      const cases = vp.name === 'desktop-1440' ? selected : selected.slice(0, 5);
      const context = await browser.newContext({ baseURL: baseUrl, storageState, locale: 'ar-EG', timezoneId: 'Africa/Cairo', viewport: { width: vp.width, height: vp.height } });
      for (const route of cases) {
        const page = await context.newPage(); const consoleErrors = []; const failedRequests = []; const httpErrors = [];
        page.on('console', m => { if (m.type() === 'error') consoleErrors.push(m.text().slice(0, 300)); });
        page.on('pageerror', e => consoleErrors.push(String(e.message || e).slice(0, 300)));
        page.on('requestfailed', r => failedRequests.push(`${r.method()} ${r.url()} ${r.failure()?.errorText || ''}`.slice(0, 350)));
        page.on('response', r => { if (r.status() >= 400) httpErrors.push(`${r.status()} ${r.url()}`.slice(0, 350)); });
        const started = Date.now(); let status = null; let navigationError = null;
        try { const response = await page.goto(`${baseUrl}${route.url}`, { waitUntil: 'domcontentloaded', timeout: 45000 }); status = response?.status() ?? null; await page.locator('#main-content h1, #main-content [role="heading"], main h1, main [role="heading"]').first().waitFor({ state: 'visible', timeout: 12000 }).catch(() => {}); } catch (e) { navigationError = String(e.message || e).slice(0, 350); }
        const checks = await inspect(page).catch(e => ({ inspect_error: String(e.message || e).slice(0, 300) }));
        const shot = path.join(screenshotDir, `${safeName(route.name)}-${vp.name}.png`); await page.screenshot({ path: shot, fullPage: false }).catch(() => {});
        const record = { route: route.name, module: route.module, url: route.url, viewport: vp, state: 'normal', http_status: status, duration_ms: Date.now() - started, screenshot: path.relative(root, shot).replace(/\\/g, '/'), checks, console_errors: consoleErrors.slice(0, 10), failed_requests: failedRequests.slice(0, 10), http_errors: httpErrors.slice(0, 10), navigation_error: navigationError };
        evidence.screenshots.push(record); evidence.checks.push(record); await page.close();
      }
      await context.close();
    }
  } finally { if (browser) await browser.close().catch(() => {}); if (server.exitCode === null) server.kill(); try { fs.closeSync(log); } catch {} }
  const sanitized = { ...evidence, screenshots: evidence.screenshots.map(({ screenshot, ...r }) => ({ ...r, screenshot })) };
  fs.writeFileSync(path.join(publicDir, 'evidence.json'), JSON.stringify(sanitized, null, 2) + '\n');
  fs.writeFileSync(path.join(outDir, 'summary.json'), JSON.stringify({ route_inventory_count: all.length, selected_screen_count: selected.length, captured: evidence.screenshots.length, evidence: path.join(publicDir, 'evidence.json') }, null, 2) + '\n');
  console.log(JSON.stringify({ route_inventory_count: all.length, selected_screen_count: selected.length, captured: evidence.screenshots.length, evidence: path.join(publicDir, 'evidence.json'), screenshots: screenshotDir }));
}
if (require.main === module) run().catch(e => { console.error(e.stack || e); process.exitCode = 1; });
module.exports = { chooseCases, inspect, browserExecutable: exe, readOwnedBootstrap, ensureAuth, safeName, root, bootstrapPath, manifestPath, storageState };
