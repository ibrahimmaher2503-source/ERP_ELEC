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
const baseUrl = 'http://127.0.0.1:8012';
const outputDirectory = path.join(root, 'docs/qa/round2/evidence/quotation-reopen/afterfix');
const edgeExecutable = 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe';
const fixtureScript = path.join(__dirname, 'support/prepare-quotation-reopen.php');

function env(temp) {
    return { PATH: process.env.PATH || '', SystemRoot: process.env.SystemRoot || '', WINDIR: process.env.WINDIR || '', TEMP: temp, TMP: temp,
        APP_ENV: 'testing', APP_URL: baseUrl, APP_DEBUG: 'false', DB_CONNECTION: 'pgsql', DB_DATABASE: 'erp_elec_new_test', DB_URL: '',
        SESSION_DRIVER: 'database', SESSION_LIFETIME: '30', CACHE_STORE: 'array', QUEUE_CONNECTION: 'sync', MAIL_MAILER: 'array',
        LOG_CHANNEL: 'stderr', BROADCAST_CONNECTION: 'null', BCRYPT_ROUNDS: '4', VIEW_COMPILED_PATH: path.join(temp, 'views') };
}

function verifyQuotation(id, temp) {
    const checked = spawnSync('php', [fixtureScript, 'verify'], {
        cwd: root, env: env(temp), input: JSON.stringify({ id }), encoding: 'utf8', windowsHide: true,
    });
    if (checked.status !== 0) throw new Error('The PostgreSQL quotation verification fixture failed.');
    return JSON.parse(checked.stdout);
}

async function chooseLineProduct(page, index, productId, unitId) {
    const product = page.locator(`select[wire\\:model\\.live="lines.${index}.product_id"]`);
    const update = waitForPropertyUpdate(page, `lines.${index}.product_id`, String(productId));
    await product.selectOption(String(productId));
    const response = await update;
    await response.finished();
    assert.equal(response.status(), 200, 'product selection completes its Livewire update');
    const unit = page.locator(`select[wire\\:model\\.live="lines.${index}.unit_id"]`);
    await expectInputValue(product, String(productId));
    await expectInputValue(unit, String(unitId));
    return unit;
}

async function expectInputValue(locator, expected) {
    await locator.waitFor({ state: 'visible' });
    await locator.evaluate(async (element, value) => {
        const until = Date.now() + 5000;
        while (element.value !== value && Date.now() < until) await new Promise(resolve => setTimeout(resolve, 50));
    }, expected);
    assert.equal(await locator.inputValue(), expected);
}

async function saveQuote(page, quoteId = null) {
    await page.locator('.quotation-page form button[type="submit"]').click({ timeout: 10000 });
    const expectedId = quoteId || '\\d+';
    await page.waitForURL(url => new RegExp('/quotations/' + expectedId + '$').test(url.pathname), { timeout: 10000 });
    await page.waitForTimeout(200);
}

async function fillLive(page, selector, value) {
    const field = page.locator(selector);
    const update = waitForPropertyUpdate(page, propertyFromSelector(selector), String(value));
    await field.fill(value);
    const response = await update;
    await response.finished();
    assert.equal(response.status(), 200, 'field change completes its Livewire update: ' + selector);
    await expectInputValue(field, value);
}

async function selectLive(page, selector, value) {
    const field = page.locator(selector);
    const update = waitForPropertyUpdate(page, propertyFromSelector(selector), String(value));
    await field.selectOption(value);
    const response = await update;
    await response.finished();
    assert.equal(response.status(), 200, 'select change completes its Livewire update: ' + selector);
    await expectInputValue(field, value);
}

function propertyFromSelector(selector) {
    const match = selector.match(/wire\\:model(?:\\.[^=]+)?="([^"]+)"/);
    if (!match) throw new Error('Could not derive Livewire property from selector: ' + selector);
    return match[1];
}

function requestComponents(request) {
    try { return JSON.parse(request.postData() || '{}').components || []; } catch { return []; }
}

function unwrapLivewire(value) {
    if (Array.isArray(value) && value.length === 2 && value[1]?.s === 'arr') return unwrapLivewire(value[0]);
    if (Array.isArray(value)) return value.map(unwrapLivewire);
    if (value && typeof value === 'object') return Object.fromEntries(Object.entries(value).map(([key, item]) => [key, unwrapLivewire(item)]));
    return value;
}

function waitForPropertyUpdate(page, property, value) {
    return page.waitForResponse(response => {
        if (!response.url().endsWith('/update') || response.request().method() !== 'POST') return false;
        return requestComponents(response.request()).some(component => Object.entries(component.updates || {}).some(([path, updated]) => path === property && String(updated) === String(value)));
    }, { timeout: 15000 });
}

function waitForMethodCall(page, method) {
    return page.waitForResponse(response => {
        if (!response.url().endsWith('/update') || response.request().method() !== 'POST') return false;
        return requestComponents(response.request()).some(component => (component.calls || []).some(call => call.method === method));
    }, { timeout: 15000 });
}

async function main() {
    if (!fs.existsSync(edgeExecutable)) throw new Error('Microsoft Edge is unavailable.');
    const serverCheck = http.createServer();
    await new Promise((resolve, reject) => { serverCheck.once('error', reject); serverCheck.listen(8012, '127.0.0.1', () => serverCheck.close(resolve)); });
    fs.mkdirSync(outputDirectory, { recursive: true });
    const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'erp-round2-quotation-'));
    fs.mkdirSync(path.join(temp, 'views'), { recursive: true });
    const password = crypto.randomBytes(32).toString('base64url');
    const prepared = spawnSync('php', [fixtureScript], { cwd: root, env: env(temp), input: JSON.stringify({ password }), encoding: 'utf8', windowsHide: true });
    if (prepared.status !== 0) throw new Error('Guarded PostgreSQL quotation fixture setup failed.');
    const fixture = JSON.parse(prepared.stdout);
    const log = fs.openSync(path.join(temp, 'server.log'), 'w');
    let server;
    let browser;
    let currentPage = null;
    const browserErrors = [];
    const livewireResponses = [];
    const dialogs = [];
    const report = { result: 'FAIL', scenario: 'create two-line quotation, reopen, validate, amend, reload, and save again', test_database: 'erp_elec_new_test', browser: 'Microsoft Edge via Playwright', fixture_quotation_id: fixture.id };
    const checks = [];
    try {
        server = spawn('php', ['-S', '127.0.0.1:8012', '-t', 'public', path.join(__dirname, 'support/laravel-static-router.php')], { cwd: root, env: env(temp), stdio: ['ignore', log, log], windowsHide: true });
        let ready = false;
        for (let attempt = 0; attempt < 60; attempt++) {
            try { const response = await fetch(baseUrl + '/login'); if (response.status === 200) { ready = true; break; } } catch {}
            await new Promise(resolve => setTimeout(resolve, 500));
        }
        assert.ok(ready, 'isolated Laravel test server becomes ready');
        browser = await chromium.launch({ executablePath: edgeExecutable, headless: true });
        const page = await browser.newPage({ viewport: { width: 1440, height: 900 }, locale: 'ar-EG', timezoneId: 'Africa/Cairo' });
        currentPage = page;
        page.on('dialog', async dialog => { dialogs.push({ type: dialog.type(), message: dialog.message() }); await dialog.accept(); });
        page.on('response', async response => {
            if (response.url().includes('/update')) {
                const body = await response.text().catch(() => '');
                let parsed;
                try { parsed = JSON.parse(body); } catch {}
                let componentState;
                try {
                    const snapshot = JSON.parse(parsed.components?.[0]?.snapshot || '{}');
                    const data = unwrapLivewire(snapshot.data || {});
                    componentState = Array.isArray(data.lines) ? data.lines.map(line => ({ product_id: line.product_id, unit_id: line.unit_id, requested_price: line.requested_price })) : undefined;
                } catch {}
                const requestUpdates = requestComponents(response.request()).flatMap(component => Object.entries(component.updates || {}))
                    .filter(([key]) => /^lines\.\d+\.(product_id|unit_id|quantity|requested_price|discount_mode|discount_value)$/.test(key));
                livewireResponses.push({ status: response.status(), url: response.url(), redirect: parsed?.effects?.redirect || parsed?.effects?.returns?.redirect || null, request_updates: requestUpdates, component_state: componentState, body_start: response.status() >= 400 ? body.slice(0, 1000) : undefined });
            }
        });
        page.on('pageerror', e => browserErrors.push(String(e.message)));
        page.on('console', m => { if (m.type() === 'error') browserErrors.push(m.text()); });
        await page.goto(baseUrl + '/login');
        report.login_url = page.url();
        report.login_title = await page.title();
        report.login_inputs = await page.locator('input').evaluateAll(nodes => nodes.map(node => ({ name: node.name, type: node.type, placeholder: node.placeholder })));
        await page.screenshot({ path: path.join(outputDirectory, 'login.png'), fullPage: true });
        await page.locator('input[type="email"]').fill(fixture.email);
        await page.locator('input[type="password"]').fill(password);
        await page.locator('[data-test="login-button"]').click();
        await page.waitForURL(url => url.pathname === '/dashboard');
        report.browser_errors = browserErrors;
        report.dialogs = dialogs;

        const projectName = 'Round2 Playwright ' + fixture.run_tag;
        await page.goto(baseUrl + '/quotations/create');
        await page.locator('select[wire\\:model="form.customer_id"]').selectOption(String(fixture.customer_id));
        await page.locator('input[wire\\:model="form.project_name"]').fill(projectName);
        await page.locator('textarea[wire\\:model="form.notes"]').fill('Round2 first save');
        const firstUnit = await chooseLineProduct(page, 0, fixture.product_id, fixture.unit_id);
        await fillLive(page, 'input[wire\\:model\\.live\\.debounce\\.500ms="lines.0.quantity"]', '2.50000000');
        await fillLive(page, 'input[wire\\:model\\.live\\.debounce\\.500ms="lines.0.requested_price"]', '123.45000000');
        await page.getByRole('button', { name: 'إضافة بند جديد', exact: true }).click();
        const secondUnit = await chooseLineProduct(page, 1, fixture.product_two_id, fixture.unit_two_id);
        await fillLive(page, 'input[wire\\:model\\.live\\.debounce\\.500ms="lines.1.quantity"]', '3.00000000');
        await fillLive(page, 'input[wire\\:model\\.live\\.debounce\\.500ms="lines.1.requested_price"]', '18.25000000');
        await page.locator('textarea[wire\\:model="form.payment_terms"]').fill('Synthetic first draft payment proposal');
        await page.locator('textarea[wire\\:model="form.delivery_terms"]').fill('Synthetic first draft delivery proposal');
        await page.screenshot({ path: path.join(outputDirectory, 'create-two-lines.png'), fullPage: false });
        await saveQuote(page);
        const createdId = Number(new URL(page.url()).pathname.split('/').at(-1));
        const created = verifyQuotation(createdId, temp);
        report.create = created;
        assert.equal(created.version, 1);
        assert.equal(created.project_name, projectName);
        assert.equal(created.line_count, 2);
        assert.deepEqual(created.lines.map(line => [line.product_id, line.unit_id, line.quantity, line.requested_price]), [
            [fixture.product_id, fixture.unit_id, '2.50000000', '123.45000000'],
            [fixture.product_two_id, fixture.unit_two_id, '3.00000000', '18.25000000'],
        ]);
        assert.equal(created.payment_terms, 'Synthetic first draft payment proposal');
        assert.equal(created.delivery_terms, 'Synthetic first draft delivery proposal');
        checks.push('creator saves a two-product quotation draft with exact amounts, units, project identity, notes, and proposed terms');
        await page.screenshot({ path: path.join(outputDirectory, 'created-detail.png'), fullPage: false });

        await page.goto(baseUrl + '/quotations');
        const row = page.locator('tbody tr').filter({ hasText: projectName });
        await row.waitFor();
        await row.locator('a.quote-link').click();
        await page.waitForURL(url => url.pathname === '/quotations/' + createdId);
        await page.getByRole('link', { name: 'تعديل المسودة', exact: true }).click();
        await page.waitForURL(url => url.pathname === '/quotations/' + createdId + '/edit');
        assert.equal(await page.locator('input[wire\\:model\\.live\\.debounce\\.500ms="lines.0.quantity"]').inputValue(), '2.50000000');
        checks.push('saved draft appears in quotations list and reopens with exact persisted line quantity');

        await fillLive(page, 'input[wire\\:model\\.live\\.debounce\\.500ms="lines.0.quantity"]', '-1.00000000');
        const rejectedSaveResponse = waitForMethodCall(page, 'save');
        await page.locator('.quotation-page form button[type="submit"]').click();
        const invalidResponse = await rejectedSaveResponse;
        await invalidResponse.finished();
        assert.equal(invalidResponse.status(), 200, 'invalid save returns visible validation state through Livewire');
        assert.equal(new URL(page.url()).pathname, '/quotations/' + createdId + '/edit');
        report.invalid_quantity_feedback = await page.locator('.quotation-page').innerText();
        await page.screenshot({ path: path.join(outputDirectory, 'negative-quantity-rejected.png'), fullPage: false });
        assert.match(report.invalid_quantity_feedback, /أكبر من صفر/, 'negative quantity shows calculation validation feedback');
        const afterInvalid = verifyQuotation(createdId, temp);
        assert.equal(afterInvalid.version, 1);
        assert.equal(afterInvalid.lines[0].quantity, '2.50000000');
        checks.push('negative quantity is rejected visibly and leaves the persisted draft unchanged');

        await fillLive(page, 'input[wire\\:model\\.live\\.debounce\\.500ms="lines.0.quantity"]', '4.00000000');
        await chooseLineProduct(page, 1, fixture.product_id, fixture.unit_id);
        await fillLive(page, 'input[wire\\:model\\.live\\.debounce\\.500ms="lines.1.quantity"]', '3.50000000');
        await fillLive(page, 'input[wire\\:model\\.live\\.debounce\\.500ms="lines.1.requested_price"]', '31.25000000');
        await selectLive(page, 'select[wire\\:model\\.live="lines.1.discount_mode"]', 'fixed');
        await fillLive(page, 'input[wire\\:model\\.live\\.debounce\\.500ms="lines.1.discount_value"]', '1.25000000');
        await selectLive(page, 'select[wire\\:model\\.live="form.discount_mode"]', 'fixed');
        await fillLive(page, 'input[wire\\:model\\.live\\.debounce\\.400ms="form.discount_value"]', '4.25000000');
        await page.locator('textarea[wire\\:model="form.payment_terms"]').fill('Synthetic amended payment proposal');
        await page.locator('textarea[wire\\:model="form.delivery_terms"]').fill('Synthetic amended delivery proposal');
        await saveQuote(page, createdId);
        const amended = verifyQuotation(createdId, temp);
        report.amended = amended;
        assert.equal(amended.version, 2);
        assert.deepEqual(amended.lines.map(line => [line.product_id, line.unit_id, line.quantity, line.requested_price]), [
            [fixture.product_id, fixture.unit_id, '4.00000000', '123.45000000'],
            [fixture.product_id, fixture.unit_id, '3.50000000', '31.25000000'],
        ]);
        assert.equal(amended.discount_mode, 'fixed');
        assert.equal(amended.discount_value, '4.25000000');
        assert.equal(amended.lines[1].discount_mode, 'fixed');
        assert.equal(amended.lines[1].discount_value, '1.25000000');
        assert.equal(amended.payment_terms, 'Synthetic amended payment proposal');
        assert.equal(amended.delivery_terms, 'Synthetic amended delivery proposal');
        checks.push('reopened save persists quantity, product/unit changes, exact line and quotation discounts, and updated terms');

        await page.reload();
        await page.getByRole('link', { name: 'تعديل المسودة', exact: true }).click();
        await page.waitForURL(url => url.pathname === '/quotations/' + createdId + '/edit');
        await page.locator('textarea[wire\\:model="form.notes"]').fill('Round2 third edit after reload');
        await saveQuote(page, createdId);
        const finalSaved = verifyQuotation(createdId, temp);
        report.final_persisted = finalSaved;
        assert.equal(finalSaved.version, 3);
        assert.equal(finalSaved.notes, 'Round2 third edit after reload');
        assert.equal(finalSaved.lines.length, 2);
        assert.equal(finalSaved.lines[0].quantity, '4.00000000');
        assert.equal(finalSaved.lines[1].quantity, '3.50000000');
        assert.equal(dialogs.length, 0, 'saving and reloading drafts must not show unsaved-change confirmations');
        checks.push('detail reload then third edit saves without duplicate records or spurious unsaved-change confirmation');
        await page.screenshot({ path: path.join(outputDirectory, 'third-save-detail.png'), fullPage: false });

        report.created_quotation_id = createdId;
        report.browser_errors = browserErrors;
        report.dialogs = dialogs;
        report.livewire_responses = livewireResponses.map(response => ({ status: response.status, request_updates: response.request_updates, component_state: response.component_state }));
        report.checks = checks;
        report.result = browserErrors.length === 0 && dialogs.length === 0 && finalSaved.version === 3 ? 'PASS' : 'FAIL';
        if (report.result === 'FAIL') report.failure = 'A browser diagnostic or persisted lifecycle assertion failed.';
    } catch (error) {
        report.failure = String(error && error.message || error).slice(0, 1000);
        report.livewire_responses = livewireResponses.map(response => ({ status: response.status, request_updates: response.request_updates, redirect: response.redirect, component_state: response.component_state, body_start: response.body_start }));
        report.last_screen_text = currentPage && !currentPage.isClosed() ? await currentPage.locator('body').innerText().catch(() => '') : '';
        if (currentPage && !currentPage.isClosed()) {
            await currentPage.screenshot({ path: path.join(outputDirectory, 'failure-state.png'), fullPage: false }).catch(() => {});
            report.failure_url = currentPage.url();
        }
    } finally {
        if (browser) await browser.close();
        if (server && server.exitCode === null) {
            server.kill();
            await new Promise(resolve => { const timer = setTimeout(resolve, 1500); server.once('exit', () => { clearTimeout(timer); resolve(); }); });
        }
        try {
            const logText = fs.readFileSync(path.join(temp, 'server.log'), 'utf8');
            report.server_error_excerpt = logText.replace(/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/gi, '[email]')
                .replace(/Bearer\s+\S+/gi, 'Bearer [redacted]').slice(0, 5000);
        } catch {}
        fs.closeSync(log);
        fs.rmSync(temp, { recursive: true, force: true, maxRetries: 5, retryDelay: 200 });
        console.log(JSON.stringify(report, null, 2));
        fs.writeFileSync(path.join(outputDirectory, 'result.json'), JSON.stringify(report, null, 2) + '\n');
    }
    if (report.result !== 'PASS') process.exitCode = 1;
}

main().catch(error => { console.error(error.message || error); process.exitCode = 1; });
