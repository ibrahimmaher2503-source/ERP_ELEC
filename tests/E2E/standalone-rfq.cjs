'use strict';

const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const fs = require('node:fs');
const http = require('node:http');
const os = require('node:os');
const path = require('node:path');
const { spawn, spawnSync } = require('node:child_process');
const { chromium } = require('C:/Users/N/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');

const root = path.resolve(__dirname, '../..');
const port = 8018;
const baseUrl = `http://127.0.0.1:${port}`;
const edge = 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe';
const fixtureScript = path.join(__dirname, 'support/prepare-standalone-rfq.php');
const evidenceRoot = path.join(root, 'docs/qa/evidence/m05-standalone-browser');

function env(temp) {
    return {
        PATH: process.env.PATH || '', SystemRoot: process.env.SystemRoot || '', WINDIR: process.env.WINDIR || '', TEMP: temp, TMP: temp,
        APP_ENV: 'testing', APP_URL: baseUrl, APP_DEBUG: 'false', DB_CONNECTION: 'pgsql', DB_HOST: '127.0.0.1', DB_PORT: '5443', DB_DATABASE: 'erp_elec_new_test', DB_URL: '',
        SESSION_DRIVER: 'database', SESSION_LIFETIME: '30', CACHE_STORE: 'array', QUEUE_CONNECTION: 'sync', MAIL_MAILER: 'array', LOG_CHANNEL: 'stderr', BROADCAST_CONNECTION: 'null',
        BCRYPT_ROUNDS: '4', VIEW_COMPILED_PATH: path.join(temp, 'views'),
    };
}

function fixture(mode, input, temp) {
    const result = spawnSync('php', [fixtureScript, mode], { cwd: root, env: env(temp), input: JSON.stringify(input), encoding: 'utf8', windowsHide: true });
    if (result.error || result.status !== 0) throw new Error(`Guarded standalone RFQ fixture failed (${mode}): ${(result.stderr || result.stdout || '').slice(-1200)}`);
    return result.stdout.trim() ? JSON.parse(result.stdout) : {};
}

async function assertPortAvailable() {
    await new Promise((resolve, reject) => {
        const server = http.createServer();
        server.once('error', error => reject(error.code === 'EADDRINUSE' ? new Error(`Port ${port} is already in use; no process was stopped or changed.`) : error));
        server.listen(port, '127.0.0.1', () => server.close(resolve));
    });
}

async function waitForServer(server) {
    for (let i = 0; i < 60; i++) {
        if (server.exitCode !== null) throw new Error('Standalone RFQ test server exited during startup.');
        try { const response = await fetch(baseUrl + '/login'); if (response.status === 200) return; } catch {}
        await new Promise(resolve => setTimeout(resolve, 500));
    }
    throw new Error('Standalone RFQ test server did not become ready.');
}

async function login(page, actor) {
    await page.goto(baseUrl + '/login');
    await page.locator('input[name="email"]').fill(actor.email);
    await page.locator('input[name="password"]').fill(actor.password);
    await page.locator('[data-test="login-button"]').click();
    await page.waitForURL(url => url.pathname === '/dashboard', { timeout: 15000 });
}

async function save(page, expectedPath) {
    await page.locator('form button[type="submit"]').first().click();
    await page.waitForURL(url => expectedPath(url), { timeout: 20000 });
    await page.waitForTimeout(200);
}

function verifyDraft(input, temp, data) {
    return fixture('verify-draft', { ...input, product_id: data.product_id, base_unit_id: data.base_unit_id, sku: data.sku, product_name: data.product_name, unit_name: data.unit_name }, temp);
}

async function main() {
    if (!fs.existsSync(edge)) throw new Error('Microsoft Edge executable is unavailable at the configured local path.');
    await assertPortAvailable();
    fs.mkdirSync(evidenceRoot, { recursive: true });
    const run = crypto.randomBytes(6).toString('hex').toUpperCase();
    const output = path.join(evidenceRoot, run);
    fs.mkdirSync(output, { recursive: true });
    const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'erp-standalone-rfq-'));
    fs.mkdirSync(path.join(temp, 'views'), { recursive: true });
    const passwords = { creator: crypto.randomBytes(36).toString('base64url'), reviewer: crypto.randomBytes(36).toString('base64url') };
    const data = fixture('prepare', { run, creator_password: passwords.creator, reviewer_password: passwords.reviewer }, temp);
    const actors = { creator: { ...data.creator, password: passwords.creator }, reviewer: { ...data.reviewer, password: passwords.reviewer } };
    const serverLogPath = path.join(temp, 'server.log');
    const serverLog = fs.openSync(serverLogPath, 'w');
    const result = { result: 'FAIL', run_id: run, database: 'erp_elec_new_test@127.0.0.1:5443', browser: 'Microsoft Edge / Playwright', checks: [], errors: [], screenshots: [] };
    let server = null, browser = null;
    try {
        server = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', 'public', path.join(__dirname, 'support/laravel-static-router.php')], { cwd: root, env: env(temp), stdio: ['ignore', serverLog, serverLog], windowsHide: true });
        await waitForServer(server);
        browser = await chromium.launch({ executablePath: edge, headless: true });
        const context = await browser.newContext({ viewport: { width: 1440, height: 960 }, locale: 'ar-EG', timezoneId: 'Africa/Cairo', colorScheme: 'light' });
        const page = await context.newPage();
        page.on('pageerror', error => result.errors.push({ type: 'pageerror', message: String(error.message).slice(0, 300) }));
        page.on('console', message => { if (message.type() === 'error') result.errors.push({ type: 'console', message: message.text().slice(0, 300) }); });
        page.on('response', async response => {
            if (response.status() >= 500) {
                const body = await response.text().catch(() => '');
                result.errors.push({ type: 'http', status: response.status(), path: new URL(response.url()).pathname, body: body.slice(0, 900) });
            }
        });
        await login(page, actors.creator);
        assert.equal(data.creator_has_warehouse_scope, false);
        assert.equal(data.creator_has_inventory_grants, false);
        result.checks.push('standalone sourcing actor has M05/M02/M03 authority but no warehouse scope or M06 Inventory grants');
        await page.goto(baseUrl + '/purchasing/rfqs/create');
        const sourceType = page.locator('select[wire\\:model\\.live="form.source_type"]');
        await sourceType.waitFor();
        assert.equal(await page.locator('html').getAttribute('dir'), 'rtl');
        assert.equal(await page.locator('html').getAttribute('lang'), 'ar');
        await sourceType.selectOption('STANDALONE');
        await page.locator('select[wire\\:model="form.branch_id"]').selectOption(String(data.branch_id));
        await page.locator('textarea[wire\\:model="form.justification"]').fill('DEMO-EG independent sourcing requirement ' + run);
        await page.locator('input[wire\\:model="form.subject"]').fill('DEMO-EG standalone RFQ ' + run);
        await page.locator('textarea[wire\\:model="form.requirements"]').fill('Exact decimal quantities; browser E2E fixture.');
        await page.locator('input[wire\\:model\.live\.debounce\.300ms="productSearch"]').fill(data.sku);
        const productSelect = page.locator('select[wire\\:model="form.lines.0.product_id"]');
        await page.locator('button[wire\\:click="addStandaloneLine"]').click();
        await page.waitForFunction(({ id }) => {
            const select = document.querySelector('select[wire\\:model="form.lines.0.product_id"]');
            return select && [...select.options].some(option => option.value === String(id));
        }, { id: data.product_id });
        await productSelect.selectOption(String(data.product_id));
        await page.locator('input[wire\\:model="form.lines.0.quantity"]').fill('1.00000000');
        const supplierCheckbox = page.locator(`input[type="checkbox"][wire\\:model="form.supplier_ids"][value="${data.supplier_id}"]`);
        await supplierCheckbox.check();

        const branchSelect = page.locator('select[wire\\:model="form.branch_id"]');
        await branchSelect.focus();
        await page.keyboard.press('Tab');
        assert.equal(await page.evaluate(() => document.activeElement.getAttribute('wire:model')), 'form.justification', 'keyboard tab reaches the required standalone justification');
        result.checks.push('RTL Arabic standalone RFQ form supports keyboard focus from branch to required justification');
        await page.screenshot({ path: path.join(output, 'standalone-draft-desktop-rtl.png'), fullPage: true, animations: 'disabled' });
        result.screenshots.push('standalone-draft-desktop-rtl.png');
        await page.setViewportSize({ width: 390, height: 844 });
        const mobileWidth = await page.evaluate(() => ({ document: document.documentElement.scrollWidth, viewport: innerWidth }));
        assert.ok(mobileWidth.document <= mobileWidth.viewport, `mobile RFQ form horizontal overflow: ${JSON.stringify(mobileWidth)}`);
        await page.screenshot({ path: path.join(output, 'standalone-draft-mobile-rtl.png'), fullPage: true, animations: 'disabled' });
        result.screenshots.push('standalone-draft-mobile-rtl.png');
        result.checks.push('standalone RFQ form fits a 390x844 RTL viewport without document-level horizontal overflow');

        await save(page, url => /^\/purchasing\/rfqs\/\d+$/.test(url.pathname));
        const rfqId = Number(new URL(page.url()).pathname.split('/').pop());
        assert.ok(rfqId > 0);
        result.rfq_id = rfqId;
        let persisted = verifyDraft({ rfq_id: rfqId, status: 'draft', subject: 'DEMO-EG standalone RFQ ' + run, version: 1, quantity: '1.00000000' }, temp, data);
        result.checks.push(`browser created a persisted standalone RFQ draft with product/unit snapshot (RFQ ${rfqId})`);

        await page.goto(baseUrl + '/purchasing/rfqs');
        await page.goto(baseUrl + `/purchasing/rfqs/${rfqId}`);
        const subjectInput = page.locator('input[wire\\:model="form.subject"]');
        await subjectInput.waitFor();
        await subjectInput.fill('DEMO-EG reopened RFQ ' + run);
        await page.locator('textarea[wire\\:model="form.justification"]').fill('DEMO-EG updated independent sourcing justification ' + run);
        await page.locator('input[wire\\:model="form.lines.0.quantity"]').fill('3.12500000');
        await save(page, url => url.pathname === `/purchasing/rfqs/${rfqId}`);
        result.checks.push('browser left and reopened draft, edited header and exact decimal quantity, and saved');

        await page.reload();
        persisted = verifyDraft({ rfq_id: rfqId, status: 'draft', subject: 'DEMO-EG reopened RFQ ' + run, version: 2, quantity: '3.12500000' }, temp, data);
        assert.equal(await subjectInput.inputValue(), 'DEMO-EG reopened RFQ ' + run);
        assert.equal(await page.locator('input[wire\\:model="form.lines.0.quantity"]').inputValue(), '3.12500000');
        result.checks.push('saved RFQ edit survives a full browser reload with the exact decimal string');

        await page.locator('textarea[wire\\:model="form.requirements"]').fill('Third save remains an explicit user action.');
        await save(page, url => url.pathname === `/purchasing/rfqs/${rfqId}`);
        verifyDraft({ rfq_id: rfqId, status: 'draft', subject: 'DEMO-EG reopened RFQ ' + run, version: 3, quantity: '3.12500000' }, temp, data);
        await page.reload();
        result.checks.push('third explicit draft save increments version and remains persisted after reload');

        page.once('dialog', dialog => dialog.accept());
        const issueResponse = page.waitForResponse(response => response.url().endsWith('/update') && response.request().method() === 'POST', { timeout: 20000 });
        await page.locator('button[wire\\:click="issue"]').click();
        assert.equal((await issueResponse).status(), 200);
        const issued = verifyDraft({ rfq_id: rfqId, status: 'issued', subject: 'DEMO-EG reopened RFQ ' + run, version: 4, quantity: '3.12500000' }, temp, data);
        assert.equal(issued.status, 'issued');
        result.checks.push('creator issued the immutable standalone RFQ after three independently persisted draft saves');
        await page.screenshot({ path: path.join(output, 'standalone-issued-desktop-rtl.png'), fullPage: true, animations: 'disabled' });
        result.screenshots.push('standalone-issued-desktop-rtl.png');

        await page.locator(`a[href*="purchasing/offers/create?rfq=${rfqId}"]`).click();
        await page.waitForURL(url => url.pathname === '/purchasing/offers/create');
        await page.locator('button[wire\\:click="loadRfq"]').click();
        await page.waitForFunction(({ id }) => {
            const select = document.querySelector('select[wire\\:model="form.supplier_id"]');
            return select && [...select.options].some(option => option.value === String(id));
        }, { id: data.supplier_id });
        await page.locator('select[wire\\:model="form.supplier_id"]').selectOption(String(data.supplier_id));
        await page.locator('input[wire\\:model="form.supplier_reference"]').fill('DEMO-EG-E2E-' + run);
        await page.locator('input[wire\\:model="form.currency"]').fill('EGP');
        await page.locator('input[wire\\:model="form.lines.0.quantity"]').fill('3.12500000');
        await page.locator('input[wire\\:model="form.lines.0.unit_price"]').fill('8.00000000');
        await page.locator('input[wire\\:model="form.lines.0.discount_amount"]').fill('0.00000000');
        await page.locator('input[wire\\:model="form.lines.0.specification"]').fill('DEMO-EG full-quantity comparison row');
        await save(page, url => /^\/purchasing\/offers\/\d+$/.test(url.pathname));
        const offerId = Number(new URL(page.url()).pathname.split('/').pop());
        result.offer_id = offerId;
        page.once('dialog', dialog => dialog.accept());
        const freezeResponse = page.waitForResponse(response => response.url().endsWith('/update') && response.request().method() === 'POST', { timeout: 20000 });
        await page.locator('button[wire\\:click="freeze"]').click();
        assert.equal((await freezeResponse).status(), 200);
        const offerRow = fixture('verify-offer', { offer_id: offerId, rfq_id: rfqId, supplier_id: data.supplier_id, product_id: data.product_id, base_unit_id: data.base_unit_id, sku: data.sku, product_name: data.product_name, unit_name: data.unit_name, quantity: '3.12500000' }, temp);
        assert.equal(offerRow.status, 'recorded');
        result.checks.push('issued standalone RFQ is reachable through the offer link; full-quantity offer is recorded from its actual product line');
        await page.screenshot({ path: path.join(output, 'standalone-offer-recorded-desktop-rtl.png'), fullPage: true, animations: 'disabled' });
        result.screenshots.push('standalone-offer-recorded-desktop-rtl.png');

        await page.locator(`a[href*="purchasing/comparison?rfq=${rfqId}"]`).click();
        await page.waitForURL(url => url.pathname === '/purchasing/comparison');
        const comparisonText = await page.locator('main').innerText();
        assert.ok(comparisonText.includes(data.sku), 'comparison uses the standalone product SKU snapshot');
        assert.ok(comparisonText.includes(data.product_name), 'comparison uses the standalone product-name snapshot');
        assert.ok(comparisonText.includes('3.12500000') && comparisonText.includes('8.00000000') && comparisonText.includes('EGP'));
        await page.setViewportSize({ width: 390, height: 844 });
        const compareWidth = await page.evaluate(() => ({ document: document.documentElement.scrollWidth, viewport: innerWidth }));
        assert.ok(compareWidth.document <= compareWidth.viewport, `mobile comparison horizontal overflow: ${JSON.stringify(compareWidth)}`);
        await page.screenshot({ path: path.join(output, 'standalone-comparison-mobile-rtl.png'), fullPage: true, animations: 'disabled' });
        result.screenshots.push('standalone-comparison-mobile-rtl.png');
        result.checks.push('comparison displays the actual standalone SKU, name, requested quantity, offer quantity, unit price and EGP without mobile page overflow');

        const downloadPromise = page.waitForEvent('download', { timeout: 20000 });
        await page.locator(`a[href*="purchasing/export/comparison?rfq=${rfqId}"]`).click();
        const download = await downloadPromise;
        const xlsxPath = path.join(temp, 'standalone-comparison.xlsx');
        await download.saveAs(xlsxPath);
        const workbook = fixture('verify-export', { path: xlsxPath, offer_id: offerId, supplier_reference: 'DEMO-EG-E2E-' + run, sku: data.sku, product_name: data.product_name }, temp);
        result.checks.push(`comparison XLSX contains source snapshot and exact offer values in ${workbook.rows} row`);

        const conversion = fixture('complete-award', { run, rfq_id: rfqId, offer_id: offerId }, temp);
        assert.equal(conversion.award_status, 'approved');
        assert.equal(conversion.order_status, 'approved');
        assert.equal(conversion.product_id, data.product_id);
        assert.equal(conversion.unit_id, data.base_unit_id);
        assert.equal(conversion.sku, data.sku);
        assert.equal(conversion.quantity, '3.12500000');
        assert.equal(conversion.unit_price, '8.00000000');
        assert.equal(conversion.posting_rows, 0);
        result.conversion = conversion;
        result.checks.push('separate creator/reviewer actions approve full award and PO with preserved standalone product mapping; no stock posting occurs');
        assert.deepEqual(result.errors, [], 'Edge reported no uncaught page or console errors');
        result.result = 'PASS';
    } catch (error) {
        result.failure = String(error && error.stack || error).slice(0, 2500);
    } finally {
        if (browser) await browser.close();
        if (server && server.exitCode === null) {
            server.kill();
            await new Promise(resolve => { const timer = setTimeout(resolve, 1500); server.once('exit', () => { clearTimeout(timer); resolve(); }); });
        }
        try { result.server_log = fs.readFileSync(serverLogPath, 'utf8').slice(-2500); } catch {}
        if (result.result !== 'PASS') { try { fs.copyFileSync(serverLogPath, path.join(output, 'server-failure.log')); } catch {} }
        try { result.cleanup = fixture('cleanup', { run }, temp); result.persistent_fixture_policy = 'Only tagged synthetic business records remain in the dedicated test DB; both temporary users are disabled and their sessions revoked.'; }
        catch (error) { result.cleanup_failure = String(error.message || error).slice(0, 500); result.result = 'FAIL'; }
        fs.closeSync(serverLog);
        if (path.resolve(temp).startsWith(path.resolve(os.tmpdir()) + path.sep)) fs.rmSync(temp, { recursive: true, force: true, maxRetries: 5, retryDelay: 200 });
        fs.writeFileSync(path.join(output, 'result.json'), JSON.stringify(result, null, 2) + '\n');
        fs.appendFileSync(path.join(evidenceRoot, 'runs.jsonl'), JSON.stringify(result) + '\n');
        console.log(JSON.stringify(result, null, 2));
    }
    if (result.result !== 'PASS') process.exitCode = 1;
}

main().catch(error => { console.error(String(error && error.stack || error)); process.exitCode = 1; });
