// Targeted, read-only browser verification for the current UI improvement batch.
// Uses the existing marker-owned performance test database and auth/storage helpers.
const fs = require('fs');
const path = require('path');
const net = require('net');
const { spawn, spawnSync } = require('child_process');
const { chromium } = require('playwright');
const visual = require('./visual-audit.cjs');
const performance = require('./page-speed-audit.cjs');

const root = visual.root;
const viewports = [
  { name: 'desktop-1440', width: 1440, height: 900 },
  { name: 'desktop-1366', width: 1366, height: 768 },
  { name: 'tablet-768', width: 768, height: 1024 },
  { name: 'mobile-390', width: 390, height: 844 },
  { name: 'mobile-360', width: 360, height: 800 },
];

function freePort() {
  return new Promise((resolve, reject) => {
    const s = net.createServer(); s.listen(0, '127.0.0.1', () => { const p = s.address().port; s.close(() => resolve(p)); }); s.on('error', reject);
  });
}
function wait(ms) { return new Promise(resolve => setTimeout(resolve, ms)); }
async function waitForServer(base) {
  for (let n = 0; n < 80; n++) { try { const r = await fetch(`${base}/login`); if (r.status < 500) return; } catch {} await wait(250); }
  throw new Error('Targeted UI server did not start.');
}
function check(checks, name, ok, details = null) { checks.push({ name, result: ok ? 'pass' : 'fail', details }); }
function routeCases(env) {
  const listed = spawnSync('php', ['artisan', 'route:list', '--json'], { cwd: root, env, encoding: 'utf8' });
  if (listed.status !== 0) throw new Error(`Route discovery failed: ${String(listed.stderr || listed.stdout).slice(-600)}`);
  const current = new Set(JSON.parse(listed.stdout).filter(performance.isPageRoute).map(r => r.name));
  const manifest = JSON.parse(fs.readFileSync(visual.manifestPath, 'utf8'));
  const wants = [['dashboard','default'],['customers.show','default'],['suppliers.show','default'],['inventory.dashboard','default'],['inventory.document.create','opening'],['financial.aging','default']];
  return wants.map(([name, variant]) => {
    if (!current.has(name)) throw new Error(`Required route is not registered: ${name}`);
    const route = (manifest.cases || []).find(r => r.name === name && r.variant === variant && r.url && r.baseline_status === 200);
    if (!route) throw new Error(`No prior valid isolated fixture URL for ${name}|${variant}`);
    return { name, variant, module: route.module, url: route.url };
  });
}
async function pageChecks(page, record, route, screenshotDir) {
  const checks = record.checks;
  const layout = await visual.inspect(page);
  check(checks, 'no-horizontal-overflow', !layout.overflow, { scroll_width: layout.scroll_width, viewport_width: layout.viewport_width });
  check(checks, 'rtl-document', layout.dir === 'rtl', layout.dir);
  check(checks, 'main-content', layout.main, null);
  check(checks, 'sidebar-landmark', layout.sidebar, null);
  check(checks, 'all-visible-fields-labeled', layout.unlabeled_fields.length === 0, layout.unlabeled_fields);
  check(checks, 'no-broken-images', layout.broken_images.length === 0, layout.broken_images);
  const authorizedLinks = await page.locator('.erp-sidebar a[href]').evaluateAll(links => links.filter(a => {
    const r = a.getBoundingClientRect(); const s = getComputedStyle(a);
    return r.width > 0 && r.height > 0 && s.display !== 'none' && s.visibility !== 'hidden';
  }).map(a => ({ text: (a.innerText || a.getAttribute('aria-label') || '').trim(), href: a.getAttribute('href') })));
  check(checks, 'authorized-sidebar-links-have-destinations', authorizedLinks.length > 0 && authorizedLinks.every(a => a.href && a.href !== '#' && !a.href.startsWith('javascript:')), authorizedLinks.slice(0, 30));
  if (route.name === 'financial.aging') {
    const aging = await page.evaluate(() => {
      const tables = [...document.querySelectorAll('table')];
      const visible = el => { const r=el.getBoundingClientRect(),s=getComputedStyle(el); return r.width>0&&r.height>0&&s.display!=='none'&&s.visibility!=='hidden'; };
      const cards = [...document.querySelectorAll('[data-aging-card],.aging-card,[class*="aging-card"]')].filter(visible);
      const visibleValueCounts = cards.map(card => [...card.querySelectorAll('header span,header strong,dl>div,footer strong')].filter(visible).length);
      const rows = tables.flatMap(t => [...t.querySelectorAll('tbody tr')].filter(visible));
      return { table_count: tables.length, card_count: cards.length, visible_row_count: rows.length,
        header_count: Math.max(0,...tables.map(t=>t.querySelectorAll('thead th').length)), horizontal_overflow: tables.some(t=>t.scrollWidth>t.clientWidth+4),
        mobile_values: cards.map(c=>(c.innerText||'').trim()).filter(Boolean).slice(0,20), visible_value_counts: visibleValueCounts };
    });
    if (record.viewport.width <= 768) {
      check(checks, 'aging-mobile-cards-present', aging.card_count > 0, aging);
      check(checks, 'aging-nine-values-present', aging.visible_value_counts.length > 0 && aging.visible_value_counts.every(n => n === 9), aging.visible_value_counts);
    } else check(checks, 'aging-desktop-table-not-clipped', !aging.horizontal_overflow, aging);
    record.aging = aging;
  }
  if (route.name === 'customers.show' || route.name === 'suppliers.show') {
    const profile = await page.evaluate(() => {
      const el = document.querySelector('[data-party-financial],.party-ui');
      const header = document.querySelector('.party-profile-header');
      const icon = header?.querySelector('.party-monogram');
      const name = header?.querySelector('h2');
      const rect = node => { const r=node?.getBoundingClientRect(); return r ? {left:r.left,right:r.right,top:r.top,bottom:r.bottom,width:r.width,height:r.height} : null; };
      const ir=rect(icon), nr=rect(name), hr=rect(header);
      const overlap = !!(ir&&nr&&Math.min(ir.right,nr.right)-Math.max(ir.left,nr.left)>1&&Math.min(ir.bottom,nr.bottom)-Math.max(ir.top,nr.top)>1);
      const dates = [...document.querySelectorAll('.party-ui .erp-stat-card')].filter(card => /تاريخ التسجيل|آخر تحديث/.test(card.innerText));
      const dateStats = dates.map(card => {
        const label=[...card.querySelectorAll('p')].find(p=>/تاريخ التسجيل|آخر تحديث/.test(p.innerText));
        const value=label?.parentElement?.querySelector('p+p'); const iconBox=card.querySelector(':scope > div > div:last-child');
        const cr=rect(card), vr=rect(value);
        return {label:label?.innerText.trim(), value:value?.innerText.trim(), card:cr, value_rect:vr,
          value_clipped:!!value&&(value.scrollWidth>value.clientWidth+1||!vr||vr.left<cr.left-1||vr.right>cr.right+1), icon_display:iconBox?getComputedStyle(iconBox).display:null};
      });
      return el ? { scroll: el.scrollWidth, client: el.clientWidth, overflow: el.scrollWidth > el.clientWidth + 4,
        icon:ir,name:nr,header:hr,icon_name_overlap:overlap,name_outside_header:!!(nr&&hr&&(nr.left<hr.left-1||nr.right>hr.right+1)),date_stats:dateStats } : null;
    });
    check(checks, 'party-profile-no-inner-overflow', profile ? !profile.overflow : false, profile);
    check(checks, 'party-profile-icon-does-not-overlap-name', !!profile&&!profile.icon_name_overlap&&!profile.name_outside_header, profile&&{icon:profile.icon,name:profile.name,header:profile.header,overlap:profile.icon_name_overlap,name_outside_header:profile.name_outside_header});
    if (record.viewport.width <= 390) check(checks, 'party-date-cards-show-unclipped-values', profile?.date_stats?.length===2&&profile.date_stats.every(stat=>!!stat.value&&!stat.value_clipped&&stat.icon_display==='none'), profile?.date_stats);
  }
  if (route.name === 'inventory.dashboard' && record.viewport.width === 1440) {
    const modal = page.getByRole('button', { name: 'تعديل حد التنبيه' }).first();
    if (await modal.count()) {
      await modal.click();
      const dialog = page.locator('dialog[open],[role="dialog"]').first();
      await dialog.waitFor({ state: 'visible', timeout: 8000 }).catch(() => {});
      const opened = await dialog.isVisible().catch(() => false);
      check(checks, 'dialog-opens', opened, null);
      if (opened) {
        const modalShot = path.join(screenshotDir, `inventory-minimum-modal-${record.viewport.name}.png`);
        await page.screenshot({ path: modalShot, fullPage: false }); record.modal_screenshot = path.relative(root, modalShot).replace(/\\/g, '/');
        await page.keyboard.press('Escape'); await dialog.waitFor({ state: 'hidden', timeout: 5000 }).catch(() => {});
        check(checks, 'dialog-escape-closes-without-save', !(await dialog.isVisible().catch(() => false)), 'No save/submit action was invoked.');
      }
    } else check(checks, 'dialog-opens', null, 'No existing inventory row with edit action is available for a non-mutating modal check.');
  }
}
async function navInteractions(browser, base, state, viewport, evidence) {
  const context = await browser.newContext({ baseURL: base, storageState: state, locale: 'ar-EG', timezoneId: 'Africa/Cairo', viewport });
  const page = await context.newPage(); const result = { viewport, checks: [], console_errors: [] };
  page.on('console', m => { if (m.type() === 'error') result.console_errors.push(m.text().slice(0,300)); });
  page.on('pageerror', e => result.console_errors.push(String(e.message||e).slice(0,300)));
  await page.goto(`${base}/dashboard`, { waitUntil: 'domcontentloaded' });
  const nav = page.locator('.erp-sidebar');
  const filterButtons = page.locator('.workspace-filters button');
  if (await filterButtons.count()) {
    const initialTitles = (await page.locator('.workspace-module:visible h3').allTextContents()).map(t=>t.trim());
    const boxes = await filterButtons.evaluateAll(els => els.map(el => { const r=el.getBoundingClientRect(); return { text:(el.innerText||'').trim(), left:r.left, right:r.right, visible:r.width>0&&r.height>0 }; }));
    check(result.checks, 'workspace-five-filters-fit-viewport', boxes.length === 5 && boxes.every(b=>b.visible&&b.left>=0&&b.right<=viewport.width+1), boxes);
    const groups = {
      'تجاري': ['العملاء','الموردون','عروض الأسعار','أوامر البيع'],
      'تشغيلي': ['الأصناف والمنتجات','المشتريات','المخزون','المشروعات','المناقصات','الصيانة الميدانية','الورش والإصلاح'],
      'مالي': ['المحاسبة','الذمم والفواتير','الخزينة'],
      'إدارة': ['الشركة','الفروع','المستخدمون','الأدوار والصلاحيات'],
    };
    for (const [group, expected] of Object.entries(groups)) {
      const button = page.locator('.workspace-filters button').filter({ hasText: group }).first();
      await button.click();
      let waitError = null;
      await page.waitForFunction(({ label, expected }) => {
        const active = document.querySelector('.workspace-filters button[aria-pressed="true"]')?.innerText.trim();
        const titles = [...document.querySelectorAll('.workspace-module')].filter(el => getComputedStyle(el).display !== 'none').map(el => el.querySelector('h3')?.innerText.trim());
        return active === label && titles.length === expected.length && expected.every(title => titles.includes(title));
      }, { label: group, expected }, { timeout:8000 }).catch(error => { waitError = String(error.message || error).slice(0,300); });
      const active = await button.getAttribute('aria-pressed');
      const titles = (await page.locator('.workspace-module:visible h3').allTextContents()).map(t=>t.trim());
      const exact = titles.length === expected.length && expected.every(title => titles.includes(title));
      check(result.checks, `workspace-filter-${group}`, active === 'true' && exact, { active, expected, titles, wait_error: waitError });
      if (group === 'إدارة') {
        check(result.checks, 'workspace-management-routes-match-sidebar', exact, { expected, titles });
      }
    }
    const all = page.locator('.workspace-filters button').filter({ hasText: 'الكل' }).first(); await all.click();
    let resetError = null;
    await page.waitForFunction(expected => {
      const active = document.querySelector('.workspace-filters button[aria-pressed="true"]')?.innerText.trim();
      const titles = [...document.querySelectorAll('.workspace-module')].filter(el => getComputedStyle(el).display !== 'none').map(el => el.querySelector('h3')?.innerText.trim());
      return active === 'الكل' && titles.length === expected.length && expected.every(title => titles.includes(title));
    }, initialTitles, { timeout:8000 }).catch(error => { resetError = String(error.message || error).slice(0,300); });
    const restoredTitles = (await page.locator('.workspace-module:visible h3').allTextContents()).map(t=>t.trim());
    check(result.checks, 'workspace-filter-reset', restoredTitles.length === initialTitles.length && initialTitles.every(title=>restoredTitles.includes(title)), { expected_count: initialTitles.length, restored_count: restoredTitles.length, wait_error: resetError });
  } else check(result.checks, 'workspace-five-filters-fit-viewport', null, 'Dashboard module filter group not present.');
  if (viewport.width <= 768) {
    const open = page.locator('.erp-topbar button[aria-label="فتح القائمة"]');
    if (await open.count()) { await open.click(); await nav.waitFor({ state: 'visible', timeout: 5000 }).catch(() => {}); }
    check(result.checks, 'mobile-drawer-opens', await nav.isVisible().catch(() => false), null);
  }
  const search = page.locator('#erp-navigation-search');
  if (await search.count()) {
    await search.focus(); await page.keyboard.type('المخزون');
    const results = page.locator('#erp-navigation-results'); await results.waitFor({ state: 'visible', timeout: 5000 }).catch(() => {});
    const filtered = await results.locator('a:visible').evaluateAll(as => as.map(a => (a.innerText || '').trim()).filter(Boolean));
    check(result.checks, 'nav-search-keyboard-filter', filtered.length > 0 && filtered.some(t => /مخزون/.test(t)), filtered.slice(0, 30));
    await page.keyboard.press('Escape'); await page.waitForFunction(() => document.querySelector('#erp-navigation-search')?.value === '');
    const reset = await search.inputValue(), hidden = !(await results.isVisible().catch(() => false));
    check(result.checks, 'nav-search-escape-reset', reset === '' && hidden, { value: reset, results_hidden: hidden });
    await search.focus();
    const focus = await page.evaluate(() => { const el=document.querySelector('#erp-navigation-search'), box=el?.closest('.erp-nav-search'), s=box?getComputedStyle(box):null; return { focused:document.activeElement===el, outline:s?.outlineStyle, border:s?.borderColor }; });
    check(result.checks, 'keyboard-focus-visible', focus.focused && focus.outline !== 'none', focus);
  } else check(result.checks, 'nav-search-keyboard-filter', null, 'Search control is not present in sidebar.');
  if (viewport.width <= 768) {
    const backdrop = page.locator('[data-flux-sidebar-backdrop]');
    const bounds = await nav.boundingBox();
    const x = bounds && bounds.x > 20 ? 5 : viewport.width - 5;
    if (await backdrop.count()) await backdrop.click({ position: { x, y: Math.floor(viewport.height / 2) }, timeout: 5000 }); else await page.keyboard.press('Escape').catch(() => {});
    await page.waitForFunction(() => document.querySelector('.erp-sidebar')?.hasAttribute('data-flux-sidebar-collapsed-mobile') ?? false, null, { timeout: 5000 }).catch(() => {});
    const closed = await nav.evaluate(el => el.hasAttribute('data-flux-sidebar-collapsed-mobile')).catch(() => false);
    check(result.checks, 'mobile-drawer-closes', closed, { collapsed_mobile: closed, sidebar_bounds: bounds, backdrop_click_x: x });
  }
  check(result.checks, 'no-dashboard-js-errors', result.console_errors.length === 0, result.console_errors);
  evidence.interactions.push(result); await context.close();
}
async function run() {
  const bootstrap = visual.readOwnedBootstrap();
  const runId = new Date().toISOString().replace(/[:.]/g, '-');
  const evidenceDir = path.join(root, 'docs', 'qa', 'ui-improvements', runId);
  const screenshots = path.join(evidenceDir, 'screenshots'); fs.mkdirSync(screenshots, { recursive: true });
  const port = await freePort(), base = `http://127.0.0.1:${port}`;
  const env = { ...bootstrap.env, APP_URL: base, APP_ENV: 'testing', APP_DEBUG: 'false', DEBUGBAR_ENABLED: 'false', PULSE_ENABLED: 'false' };
  const privateRunDir = path.join(root, '.tmp', 'ui-improvements', runId); fs.mkdirSync(privateRunDir, { recursive: true });
  const selectedNames = process.argv.find(arg => arg.startsWith('--routes='))?.slice('--routes='.length).split(',').filter(Boolean);
  const routes = routeCases(env).filter(route => !selectedNames || selectedNames.includes(route.name)), logFd = fs.openSync(path.join(privateRunDir, 'server.log'), 'w');
  const interactionsOnly = process.argv.includes('--interactions-only');
  const screenshotsOnly = process.argv.includes('--screenshots-only');
  const selectedViewportNames = process.argv.find(arg => arg.startsWith('--viewports='))?.slice('--viewports='.length).split(',').filter(Boolean);
  const runViewports = viewports.filter(vp => !selectedViewportNames || selectedViewportNames.includes(vp.name));
  const server = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', '.', path.join(root, 'tests', 'E2E', 'support', 'performance-router.php')], { cwd: path.join(root, 'public'), env, stdio: ['ignore', logFd, logFd], windowsHide: true });
  const result = { run_id: runId, database: { name: 'erp_elec_new_test', port: 55461 }, route_cases: routes, viewports: runViewports, read_only_interactions: !screenshotsOnly, pages: [], interactions: [], gaps: [] };
  let browser;
  try {
    await waitForServer(base); browser = await chromium.launch({ headless: true, executablePath: visual.browserExecutable() }); await visual.ensureAuth(browser, base, env);
    for (const viewport of runViewports) {
      if (!interactionsOnly) for (const route of routes) {
        const context = await browser.newContext({ baseURL: base, storageState: visual.storageState, locale: 'ar-EG', timezoneId: 'Africa/Cairo', viewport: { width: viewport.width, height: viewport.height } });
        const page = await context.newPage(), rec = { route: route.name, variant: route.variant, module: route.module, url: route.url, viewport, state: 'normal', checks: [], http_status: null, console_errors: [], failed_requests: [] };
        page.on('console', m => { if (m.type() === 'error') rec.console_errors.push(m.text().slice(0,250)); }); page.on('pageerror', e => rec.console_errors.push(String(e.message||e).slice(0,250))); page.on('requestfailed', r => rec.failed_requests.push(`${r.method()} ${r.url()} ${r.failure()?.errorText||''}`.slice(0,300)));
        try {
          const response = await page.goto(`${base}${route.url}`, { waitUntil: 'domcontentloaded', timeout: 45000 }); rec.http_status = response?.status() ?? null;
          await page.locator('#main-content h1, #main-content [role="heading"],main h1,main [role="heading"]').first().waitFor({ state: 'visible', timeout: 10000 }).catch(() => {});
          const file = path.join(screenshots, `${visual.safeName(route.name)}-${route.variant}-${viewport.name}.png`);
          await page.screenshot({ path: file, fullPage: false }); rec.screenshot = path.relative(root,file).replace(/\\/g,'/');
          await pageChecks(page, rec, route, screenshots);
        }
        catch (e) { check(rec.checks, 'navigation-completes', false, String(e.message||e).slice(0,400)); }
        if (!rec.screenshot) { const file = path.join(screenshots, `${visual.safeName(route.name)}-${route.variant}-${viewport.name}.png`); await page.screenshot({ path: file, fullPage: false }).catch(() => {}); rec.screenshot = path.relative(root,file).replace(/\\/g,'/'); }
        result.pages.push(rec); await context.close();
      }
      if (!screenshotsOnly && (viewport.name === 'desktop-1440' || viewport.name === 'mobile-390' || viewport.name === 'mobile-360')) {
        try { await navInteractions(browser,base,visual.storageState,{width:viewport.width,height:viewport.height},result); }
        catch (e) { result.interactions.push({ viewport, checks: [{ name: 'interaction-runner-completes', result: 'fail', details: String(e.message||e).slice(0,500) }] }); }
      }
    }
  } catch (e) { result.run_error = String(e.stack || e).slice(0,1000); }
  finally { if (browser) await browser.close().catch(()=>{}); if (server.exitCode === null) server.kill(); try { fs.closeSync(logFd); } catch {} }
  fs.writeFileSync(path.join(evidenceDir,'evidence.json'),JSON.stringify(result,null,2)+'\n');
  const failed = result.pages.flatMap(p=>p.checks.filter(c=>c.result==='fail').map(c=>({route:p.route,viewport:p.viewport.name,...c}))).concat(result.interactions.flatMap(i=>i.checks.filter(c=>c.result==='fail').map(c=>({viewport:i.viewport.name,...c}))));
  fs.writeFileSync(path.join(evidenceDir,'summary.json'),JSON.stringify({run_id:runId,page_viewport_captures:result.pages.length,interaction_viewports:result.interactions.length,failed_checks:failed.length,failures:failed,evidence:path.join(evidenceDir,'evidence.json')},null,2)+'\n');
  console.log(JSON.stringify({run_id:runId,page_viewport_captures:result.pages.length,interaction_viewports:result.interactions.length,failed_checks:failed.length,evidence:path.join(evidenceDir,'evidence.json')}));
}
if (require.main === module) run().catch(e => { console.error(e.stack || e); process.exitCode = 1; });
module.exports = { routeCases, pageChecks };
