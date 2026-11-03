'use strict';

// Incremental authenticated GET coverage only; no application actions or DB writes.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const { chromium } = require('playwright');
const { browserExecutable } = require('./page-speed-audit.cjs');

const root = path.resolve(__dirname, '../..');
const work = path.join(root, '.tmp/forms-ux-20261102/private');
const baseUrl = 'http://127.0.0.1:8017';
const dbPort = 55467;
const database = 'erp_elec_new_test';
const bootstrap = JSON.parse(fs.readFileSync(path.join(work, 'bootstrap.json'), 'utf8'));
const marker = JSON.parse(fs.readFileSync(path.join(work, '.codex-forms-ux-owned.json'), 'utf8'));
const actor = JSON.parse(fs.readFileSync(path.join(work, 'actor.json'), 'utf8'));
const expectedAssets = 'assets/app-D-lV2jMK.js|assets/app-CmNZNImA.css';
const manifest = JSON.parse(fs.readFileSync(path.join(root, 'public/build/manifest.json'), 'utf8'));
const currentAssets = ['resources/js/app.js', 'resources/css/app.css'].map(key => manifest[key]?.file).join('|');
const timestamp = new Date().toISOString().replace(/[:.]/g, '-');
const screenshotDir = path.join(work, 'screenshots', `route-subset-${timestamp}`);
const outputPath = path.join(work, `route-subset-readonly-${timestamp}.json`);

function fail(message) { throw new Error(message); }
function readOnlyQuery(sql) {
  const pgBin = path.join(root, '.tools/postgresql/pgsql/bin');
  const result = spawnSync(path.join(pgBin, 'psql.exe'), [
    '-h', '127.0.0.1', '-p', String(dbPort), '-U', 'postgres', '-d', database,
    '-Atqc', sql,
  ], { cwd: root, encoding: 'utf8', windowsHide: true, maxBuffer: 1024 * 1024 });
  if (result.error || result.status !== 0) fail('Read-only PostgreSQL ownership/fixture query failed.');
  return String(result.stdout || '').trim();
}

function assertOwned() {
  assert.equal(bootstrap.status, 'READY');
  assert.equal(bootstrap.database, database);
  assert.equal(Number(bootstrap.db_port), dbPort);
  assert.equal(marker.kind, 'codex-forms-ux-private-pg');
  assert.equal(path.resolve(marker.repo), root);
  assert.equal(Number(marker.port), dbPort);
  assert.equal(marker.database, database);
  assert.equal(marker.nonce, bootstrap.env.E2E_CLUSTER_TOKEN);
  const identity = readOnlyQuery("SELECT current_database() || '|' || current_setting('port') || '|' || current_setting('data_directory') || '|' || (SELECT nonce FROM codex_test_guard.ownership_receipts WHERE database=current_database())");
  const [actualDb, actualPort, actualDirectory, actualNonce] = identity.split('|');
  assert.equal(actualDb, database);
  assert.equal(actualPort, String(dbPort));
  assert.equal(path.resolve(actualDirectory), path.resolve(path.join(root, bootstrap.postgres_data_directory)));
  assert.equal(actualNonce, marker.nonce);
}

function safePath(raw) {
  try {
    const url = new URL(raw, baseUrl);
    if (url.origin !== baseUrl || url.search || url.hash) return null;
    return url.pathname;
  } catch { return null; }
}

async function main() {
  assertOwned();
  assert.equal(currentAssets, expectedAssets, 'The running browser build fingerprint must match the approved final bundle.');
  assert.equal(await fetch(`${baseUrl}/login`, { redirect: 'manual' }).then(response => response.status), 200,
    'The existing 8017 browser server must be available; this harness never starts or stops it.');
  fs.mkdirSync(screenshotDir, { recursive: true });

  const browser = await chromium.launch({ executablePath: browserExecutable(), headless: true });
  const context = await browser.newContext({
    viewport: { width: 1440, height: 900 }, locale: 'ar-EG', timezoneId: 'Africa/Cairo',
  });
  const page = await context.newPage();
  page.setDefaultTimeout(10000);
  page.on('dialog', dialog => dialog.dismiss());
  const pageErrors = [];
  const nonGetRequests = [];
  page.on('pageerror', error => pageErrors.push(String(error.message).slice(0, 200)));
  page.on('console', message => { if (message.type() === 'error') pageErrors.push(String(message.text()).slice(0, 200)); });
  page.on('request', request => {
    if (!['GET', 'HEAD'].includes(request.method()) && !request.url().includes('/login')) {
      nonGetRequests.push({ method: request.method(), path: new URL(request.url()).pathname });
    }
  });

  const results = [];
  const blocked = [];
  try {
    await page.goto(`${baseUrl}/login`, { waitUntil: 'domcontentloaded' });
    await page.locator('input[type="email"]').fill(actor.email);
    await page.locator('input[type="password"]').fill(actor.password);
    await page.locator('button[type="submit"]').click();
    await page.waitForURL(url => !url.pathname.endsWith('/login'), { timeout: 15000 });

    const candidates = [
      { pattern: '/customers/create', route: '/customers/create', family: 'Catalogs' },
      { pattern: '/customers/import', route: '/customers/import', family: 'Catalogs' },
      { pattern: '/products/create', route: '/products/create', family: 'Catalogs' },
      { pattern: '/products/import', route: '/products/import', family: 'Catalogs' },
      { pattern: '/suppliers/import', route: '/suppliers/import', family: 'Catalogs' },
      { pattern: '/organization/permissions', route: '/organization/permissions', family: 'Organization' },
      { pattern: '/settings/appearance', route: '/settings/appearance', family: 'Account settings' },
      { pattern: '/settings/security', route: '/settings/security', family: 'Account settings' },
      { pattern: '/data-operations/import/{entity}', route: '/data-operations/import/products', family: 'System' },
    ];

    // Dynamic record routes are resolved only from links rendered by the authenticated list page.
    const linkSpecs = [
      { list: '/customers', base: '/customers/', match: /^\/customers\/[^/]+$/, family: 'Catalogs', routes: ['show', 'edit'] },
      { list: '/products', base: '/products/', match: /^\/products\/[^/]+$/, family: 'Catalogs', routes: ['show', 'edit', 'prices'] },
      { list: '/suppliers', base: '/suppliers/', match: /^\/suppliers\/[^/]+$/, family: 'Catalogs', routes: ['show', 'edit'] },
      { list: '/organization/branches', base: '/organization/branches/', match: /^\/organization\/branches\/[^/]+$/, family: 'Organization', routes: ['show'] },
      { list: '/organization/roles', base: '/organization/roles/', match: /^\/organization\/roles\/[^/]+$/, family: 'Organization', routes: ['show'] },
      { list: '/organization/users', base: '/organization/users/', match: /^\/organization\/users\/[^/]+$/, family: 'Organization', routes: ['show'] },
    ];

    for (const spec of linkSpecs) {
      let pathFromLink = null;
      try {
        await page.goto(`${baseUrl}${spec.list}`, { waitUntil: 'domcontentloaded', timeout: 25000 });
        const links = await page.locator('a[href]').evaluateAll(nodes => nodes.map(node => node.href));
        pathFromLink = links.map(safePath).find(candidate => candidate && spec.match.test(candidate)) || null;
      } catch { /* Record the route as unavailable below; do not guess private IDs. */ }
      if (!pathFromLink) {
        for (const routeType of spec.routes) blocked.push({
          pattern: routeType === 'show' ? `${spec.base}{record}` : routeType === 'edit'
            ? `${spec.base}{record}/edit` : `${spec.base}{record}/prices`,
          family: spec.family,
          status: 'blocked_missing_scoped_record_link',
        });
        continue;
      }
      for (const routeType of spec.routes) {
        const base = `${spec.base}${pathFromLink.slice(spec.base.length)}`;
        const route = routeType === 'edit' ? `${base}/edit` : routeType === 'prices' ? `${base}/prices` : base;
        const pattern = routeType === 'edit' ? `${spec.base}{record}/edit`
          : routeType === 'prices' ? `${spec.base}{record}/prices` : `${spec.base}{record}`;
        candidates.push({ pattern, route, family: spec.family, resolvedFrom: spec.list });
      }
    }

    // A data-operation receipt is navigated only if one already belongs to this synthetic actor.
    const actorId = String(actor.user_id);
    if (!/^\d+$/.test(actorId)) fail('Private actor identifier is malformed.');
    const runLookup = readOnlyQuery(`BEGIN READ ONLY; SELECT id FROM data_operation_runs WHERE actor_id=${actorId} AND expires_at > NOW() ORDER BY created_at DESC LIMIT 1; COMMIT;`);
    const runId = runLookup.match(/[0-9a-f]{8}-[0-9a-f-]{27}/i)?.[0] || '';
    if (runId && /^[0-9a-f-]{36}$/i.test(runId)) {
      candidates.push({ pattern: '/data-operations/{runId}', route: `/data-operations/${runId}`, family: 'System', resolvedFrom: 'read-only actor-owned receipt lookup' });
    } else {
      blocked.push({ pattern: '/data-operations/{runId}', family: 'System', status: 'blocked_no_existing_actor_owned_run_fixture' });
    }

    for (const candidate of candidates) {
      for (const viewport of [{ label: 'desktop', width: 1440, height: 900 }, { label: 'mobile', width: 390, height: 844 }]) {
        pageErrors.length = 0;
        nonGetRequests.length = 0;
        await page.setViewportSize({ width: viewport.width, height: viewport.height });
        const response = await page.goto(`${baseUrl}${candidate.route}`, { waitUntil: 'domcontentloaded', timeout: 30000 });
        await page.waitForTimeout(250);
        const status = response?.status() ?? 0;
        const snapshot = await page.evaluate(() => {
          const controls = [...document.querySelectorAll('input:not([type="hidden"]),select,textarea,[role="combobox"]')];
          const labeled = controls.filter(control => control.labels?.length || control.getAttribute('aria-label') || control.getAttribute('aria-labelledby')).length;
          return {
            titlePresent: Boolean(document.title),
            direction: document.documentElement.dir || getComputedStyle(document.body).direction,
            width: innerWidth,
            bodyWidth: document.body.scrollWidth,
            forms: document.querySelectorAll('form').length,
            controls: controls.length,
            labeledControls: labeled,
            selects: document.querySelectorAll('select').length,
            enhancedSelects: document.querySelectorAll('[data-erp-select-ui]').length,
            validationSummary: document.querySelectorAll('[data-erp-validation]').length,
            visibleErrorText: [...document.querySelectorAll('[data-erp-validation]')].some(el => el.offsetParent !== null && el.innerText.trim().length > 0),
            pageHeadingCount: document.querySelectorAll('h1,[role="heading"][aria-level="1"]').length,
          };
        });
        const filename = `${String(results.length + 1).padStart(2, '0')}-${candidate.family.replace(/[^a-z]/gi, '-')}-${viewport.label}.png`;
        await page.screenshot({ path: path.join(screenshotDir, filename), fullPage: true });
        results.push({
          pattern: candidate.pattern,
          family: candidate.family,
          status,
          viewport: viewport.label,
          snapshot,
          browserErrorCount: pageErrors.length,
          nonGetRequestCount: nonGetRequests.length,
          outcome: status === 200 && new URL(page.url()).pathname !== '/login' ? 'rendered' : status === 403 ? 'forbidden' : 'not_rendered',
          screenshot: filename,
        });
        fs.writeFileSync(outputPath + '.checkpoint', JSON.stringify({ results, blocked, assetFingerprint: currentAssets }, null, 2));
      }
    }
  } finally {
    await page.close({ runBeforeUnload: false });
    await context.close();
    await browser.close();
  }

  const report = {
    generatedAt: new Date().toISOString(),
    scope: 'Incremental read-only GET coverage for Catalogs, Organization, account settings, and System routes. No business mutations were executed.',
    databaseIdentity: { database, port: dbPort, ownershipVerified: true },
    assetFingerprint: currentAssets,
    sourceSweepPreserved: path.join(work, 'route-sweep.json'),
    counts: {
      routePatternsAttempted: [...new Set(results.map(result => result.pattern))].length,
      viewportVisits: results.length,
      rendered200Visits: results.filter(result => result.outcome === 'rendered').length,
      forbiddenVisits: results.filter(result => result.outcome === 'forbidden').length,
      failedVisits: results.filter(result => !['rendered', 'forbidden'].includes(result.outcome)).length,
      blockedMissingFixturePatterns: blocked.length,
      browserErrors: results.reduce((sum, result) => sum + result.browserErrorCount, 0),
      unexpectedNonGetRequests: results.reduce((sum, result) => sum + result.nonGetRequestCount, 0),
    },
    results,
    blocked,
    privacy: 'Resolved record paths, actor email/password, UUIDs, and form values are intentionally omitted. Screenshots remain in the private ignored folder.',
  };
  fs.writeFileSync(outputPath, JSON.stringify(report, null, 2) + '\n');
  process.stdout.write(JSON.stringify({ outputPath, screenshotDir, counts: report.counts, assetFingerprint: currentAssets }) + '\n');
}

main().catch(error => { process.stderr.write(`${error.stack || error}\n`); process.exitCode = 1; });
