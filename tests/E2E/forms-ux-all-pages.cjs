'use strict';

// Read-only browser coverage of every registered HTML page pattern on the owned fixture DB.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const { chromium } = require('playwright');
const root = path.resolve(__dirname, '../..');
const work = path.join(root, '.tmp/forms-ux-20261102/private');
const base = 'http://127.0.0.1:8017';
const actor = JSON.parse(fs.readFileSync(path.join(work, 'actor.json')));
const marker = JSON.parse(fs.readFileSync(path.join(work, '.codex-forms-ux-owned.json')));
const fixture = JSON.parse(fs.readFileSync(path.join(work, 'page-fixtures.json')));
const inventory = JSON.parse(fs.readFileSync(path.join(work, 'route-coverage.json')));
const manifest = () => JSON.parse(fs.readFileSync(path.join(root, 'public/build/manifest.json')));
const fingerprint = JSON.stringify(manifest());
const retry = process.argv.includes('--retry');
const onlyPattern = process.argv.find(argument => argument.startsWith('--pattern='))?.slice(10);
const previous = retry ? JSON.parse(fs.readFileSync(path.join(work, 'all-pages.json'))) : null;
const reportPath = path.join(work, onlyPattern ? 'page-diagnostic.json' : retry ? 'all-pages-retry.json' : 'all-pages.json');
const screenshotDir = path.join(work, 'screenshots', `all-pages-${new Date().toISOString().replace(/[:.]/g, '-')}`);
const report = { status: 'RUNNING', database: fixture.database, port: fixture.port, results: [], blocked: [], excluded: inventory.exclusions || inventory.excluded, failures: [] };
const save = () => fs.writeFileSync(reportPath, JSON.stringify(report, null, 2));

async function main() {
    assert.equal(fixture.port, 55467);
    const identity = spawnSync(path.join(root, '.tools/postgresql/pgsql/bin/psql.exe'), ['-h', '127.0.0.1', '-p', '55467', '-U', 'postgres', '-d', 'erp_elec_new_test', '-Atqc', "select current_database() || '|' || current_setting('data_directory') || '|' || (select nonce from codex_test_guard.ownership_receipts)"], { encoding: 'utf8', windowsHide: true });
    assert.equal(identity.status, 0);
    const [database, directory, nonce] = identity.stdout.trim().split('|');
    assert.equal(database, 'erp_elec_new_test');
    assert.equal(path.resolve(directory), path.resolve(root, '.tmp/forms-ux-20261102/pgdata'));
    assert.equal(nonce, marker.nonce);
    fs.mkdirSync(screenshotDir, { recursive: true });
    const browser = await chromium.launch({ channel: 'msedge', headless: true });
    const context = await browser.newContext({ locale: 'ar-EG', timezoneId: 'Africa/Cairo' });
    const page = await context.newPage();
    page.setDefaultTimeout(10000);
    page.on('dialog', dialog => dialog.dismiss());
    let errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.goto(base + '/login', { waitUntil: 'domcontentloaded' });
    await page.locator('input[name="email"]').fill(actor.email);
    await page.locator('input[name="password"]').fill(actor.password);
    await page.locator('button[type="submit"]').click();
    await page.waitForURL(url => url.pathname !== '/login');
    // Once signed in this runner never sends mutations, uploads or Livewire actions.
    await page.route('**/*', route => ['GET', 'HEAD'].includes(route.request().method()) ? route.continue() : route.abort());
    try {
        for (const entry of inventory.coverage) {
            if (onlyPattern && entry.path !== onlyPattern) continue;
            if (retry && !previous.failures.some(failure => failure.pattern === entry.path)) continue;
            if (['/purchasing/reports', '/purchasing/returns', '/sales-returns'].includes(entry.path)) {
                report.blocked.push({ pattern: entry.path, reason: 'Explicit existing release.v2_enabled feature gate; unavailable by current release scope.' });
                save(); continue;
            }
            if (entry.path === '/service-contracts/{contract}/billing') {
                report.blocked.push({ pattern: entry.path, reason: 'The synthetic contract is an unsigned draft with no active_revision_id; billing correctly requires an active revision.' });
                save(); continue;
            }
            const variants = entry.path.includes('{kind}') ? ['count', 'adjustment', 'opening', 'transfer', 'receipt']
                : entry.path.includes('{type}') ? ['customer', 'supplier']
                : entry.path.includes('{screen}') ? ['review', 'amendment', 'delivery', 'invoices', 'closure'] : [null];
            for (const variant of variants) {
                if (entry.path.includes('{document}') && variant !== 'count') {
                    report.blocked.push({ pattern: entry.path, variant, reason: 'The existing document fixture is a count; other document kinds were not fabricated.' });
                    continue;
                }
                let url = entry.path;
                const ids = { ...fixture.ids, entity: 'products', kind: variant, type: variant, screen: variant };
                if (url.startsWith('/sales-orders/')) ids.order = ids.salesOrder;
                if (url.includes('{invoice}')) ids.invoice = ids[variant + 'Invoice'];
                const missing = [];
                url = url.replace(/\{([^}]+)\}/g, (_, key) => ids[key] === undefined ? (missing.push(key), '{' + key + '}') : String(ids[key]));
                if (missing.length) {
                    report.blocked.push({ pattern: entry.path, missing: missing.join(','), reason: 'No eligible source fixture; no approval, posting, bank statement or actor-owned operation was fabricated.' });
                    save();
                    continue;
                }
                for (const width of [1440, 390]) {
                    errors = [];
                    await page.setViewportSize({ width, height: width === 390 ? 844 : 900 });
                    try {
                        const response = await page.goto(base + url, { waitUntil: 'domcontentloaded', timeout: 25000 });
                        await page.waitForTimeout(120);
                        const snapshot = await page.evaluate(() => ({
                            rtl: document.documentElement.dir === 'rtl',
                            overflow: document.documentElement.scrollWidth > innerWidth + 2,
                            viewport: innerWidth,
                            documentWidth: document.documentElement.scrollWidth,
                            forms: document.querySelectorAll('form').length,
                            enhanced: document.querySelectorAll('erp-select[data-enhanced]').length,
                            wide: [...document.querySelectorAll('body *')].filter(element => {
                                const rect = element.getBoundingClientRect();
                                return rect.width > 0 && getComputedStyle(element).visibility !== 'hidden' && (rect.width > innerWidth || rect.left < -2 || rect.right > innerWidth + 2) && !element.closest('dialog, [data-flux-sidebar]');
                            }).slice(0, 18).map(element => ({ tag: element.tagName, class: element.className, width: Math.round(element.getBoundingClientRect().width), left: Math.round(element.getBoundingClientRect().left), right: Math.round(element.getBoundingClientRect().right) })),
                        }));
                        const status = response.status();
                        const result = { pattern: entry.path, variant, width, status, ...snapshot, errors: [...errors] };
                        report.results.push(result);
                        if (status !== 200 || snapshot.overflow || errors.length) report.failures.push(result);
                        const filename = `${String(report.results.length).padStart(3, '0')}.png`;
                        await page.screenshot({ path: path.join(screenshotDir, filename), fullPage: true });
                    } catch (error) {
                        report.failures.push({ pattern: entry.path, variant, width, error: error.message.split('\n')[0] });
                    }
                    save();
                }
            }
        }
        assert.equal(JSON.stringify(manifest()), fingerprint, 'Assets remain fixed during this run');
        report.status = report.failures.length ? 'FAIL' : 'PASS_WITH_SOURCE_FIXTURE_GAPS';
        report.counts = { htmlPatterns: inventory.coverage.length, visitedPatterns: new Set(report.results.map(result => result.pattern)).size, viewportVisits: report.results.length, blockedPatterns: new Set(report.blocked.map(result => result.pattern)).size, failures: report.failures.length };
        save();
        console.log(JSON.stringify(report.counts));
    } finally {
        await page.close({ runBeforeUnload: false });
        await context.close();
        await browser.close();
    }
}
main().catch(error => { report.status = 'INTERRUPTED'; report.error = error.message; save(); console.error(error.stack); process.exitCode = 1; });
