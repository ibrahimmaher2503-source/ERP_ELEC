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
const port = 8013;
const baseUrl = `http://127.0.0.1:${port}`;
const fixtureScript = path.join(__dirname, 'support', 'prepare-round2-master-fixtures.php');
const edgeExecutable = 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe';
const evidenceRoot = path.join(root, 'docs', 'qa', 'round2', 'evidence', 'masters');
const errors = [];

function testEnvironment(tempPath) {
    return {
        PATH: process.env.PATH || '', SystemRoot: process.env.SystemRoot || '', WINDIR: process.env.WINDIR || '',
        TEMP: tempPath, TMP: tempPath, APP_ENV: 'testing', APP_URL: baseUrl, APP_DEBUG: 'false',
        DB_CONNECTION: 'pgsql', DB_DATABASE: 'erp_elec_new_test', DB_URL: '',
        SESSION_DRIVER: 'database', SESSION_LIFETIME: '30', CACHE_STORE: 'array',
        QUEUE_CONNECTION: 'sync', MAIL_MAILER: 'array', LOG_CHANNEL: 'stderr',
        BROADCAST_CONNECTION: 'null', BCRYPT_ROUNDS: '4', VIEW_COMPILED_PATH: path.join(tempPath, 'views'),
    };
}

function fixture(mode, input, tempPath) {
    const result = spawnSync('php', [fixtureScript, mode], {
        cwd: root, env: testEnvironment(tempPath), input: JSON.stringify(input), encoding: 'utf8', windowsHide: true,
    });
    if (result.error || result.status !== 0) throw new Error('Guarded PostgreSQL master fixture failed.');
    return result.stdout.trim() ? JSON.parse(result.stdout) : {};
}

function assertPortAvailable() {
    return new Promise((resolve, reject) => {
        const server = http.createServer();
        server.once('error', error => reject(error.code === 'EADDRINUSE'
            ? new Error(`Port ${port} is already in use; no process was stopped or changed.`) : error));
        server.listen(port, '127.0.0.1', () => server.close(resolve));
    });
}

async function waitForServer(server) {
    for (let attempt = 0; attempt < 60; attempt++) {
        if (server.exitCode !== null) throw new Error('The isolated test application stopped before ready.');
        try {
            const response = await fetch(baseUrl + '/login', { redirect: 'manual' });
            if (response.status === 200) return;
        } catch { /* isolated server is starting */ }
        await new Promise(resolve => setTimeout(resolve, 500));
    }
    throw new Error(`Isolated application did not become ready on port ${port}.`);
}

function diagnostics(page) {
    page.on('pageerror', error => errors.push({ kind: 'pageerror', message: String(error.message).slice(0, 300) }));
    page.on('console', message => {
        if (message.type() === 'error') errors.push({ kind: 'console', message: String(message.text()).slice(0, 300) });
    });
    page.on('requestfailed', request => errors.push({ kind: 'requestfailed', message: `${request.method()} ${request.url()}: ${request.failure()?.errorText || 'failed'}`.slice(0, 300) }));
    page.on('response', response => {
        if (response.status() >= 400) errors.push({ kind: 'http', message: `${response.status()} ${response.request().method()} ${response.url()}`.slice(0, 300) });
    });
}

function writeJourneyResults(output, run, journeys) {
    const directory = path.join(output, 'journey-results');
    fs.mkdirSync(directory, { recursive: true });
    for (const [key, title, record, persisted] of journeys) {
        const relatedChecks = run.checks.filter(check => check.toLowerCase().includes(key));
        fs.writeFileSync(path.join(directory, `${key}.json`), JSON.stringify({
            result: 'PASS', run_id: run.run_id, journey: title, record,
            checks: relatedChecks, postgresql_verification: persisted,
        }, null, 2) + '\n');
    }
}

async function login(page, actor, password) {
    await page.goto(baseUrl + '/login');
    await page.locator('input[name="email"]').fill(actor.email);
    await page.locator('input[name="password"]').fill(password);
    await page.locator('[data-test="login-button"]').click();
    await page.waitForURL(url => url.pathname === '/dashboard');
}

const model = name => `[wire\\:model="${name}"], [wire\\:model\\.live="${name}"], [wire\\:model\\.live\\.debounce\\.300ms="${name}"]`;
const forms = {
    product: {
        path: '/products', editPath: id => `/products/${id}/edit`, detailPath: id => `/products/${id}`,
        sku: tag => `R2-${tag}-P`, name: tag => `Round2 product ${tag}`,
        async fill(page, values, unitId) {
            await page.locator(model('form.name')).fill(values.name);
            await page.locator(model('form.sku')).fill(values.code);
            await page.locator(model('form.kind')).selectOption('bulk');
            await page.locator(model('form.tracking')).selectOption('quantity');
            await page.locator(model('form.base_unit_id')).selectOption(String(unitId));
            await page.locator(model('form.name_en')).fill(values.en);
        },
        async invalid(page) {
            await page.locator('form[wire\\:submit="save"] button[type="submit"]').click();
            await page.locator('form[wire\\:submit="save"] :invalid').first().waitFor({ state: 'attached' });
        },
        async change(page, value) { await page.locator(model('form.name_en')).fill(value); },
    },
    customer: {
        path: '/customers', editPath: id => `/customers/${id}/edit`, detailPath: id => `/customers/${id}`,
        code: tag => `R2-${tag}-C`, name: tag => `Round2 customer ${tag}`,
        async fill(page, values) {
            await page.locator(model('form.code')).fill(values.code);
            await page.locator(model('form.name')).fill(values.name);
            await page.locator(model('form.entity_type')).selectOption('organization');
            await page.locator(model('form.name_en')).fill(values.en);
        },
        async invalid(page) {
            await page.locator('form[wire\\:submit="save"] button[type="submit"]').click();
            await page.locator('form[wire\\:submit="save"] :invalid').first().waitFor({ state: 'attached' });
        },
        async change(page, value) { await page.locator(model('form.name_en')).fill(value); },
    },
    supplier: {
        path: '/suppliers', editPath: id => `/suppliers/${id}/edit`, detailPath: id => `/suppliers/${id}`,
        code: tag => `R2-${tag}-S`, name: tag => `Round2 supplier ${tag}`,
        async fill(page, values) {
            await page.locator(model('form.code')).fill(values.code);
            await page.locator(model('form.name')).fill(values.name);
            await page.locator(model('form.entity_type')).selectOption('organization');
            await page.locator(model('form.supplier_type')).selectOption('manufacturer');
            await page.locator(model('form.name_en')).fill(values.en);
        },
        async invalid(page) {
            await page.locator('form[wire\\:submit="save"] button[type="submit"]').click();
            await page.locator('form[wire\\:submit="save"] :invalid').first().waitFor({ state: 'attached' });
        },
        async change(page, value) { await page.locator(model('form.name_en')).fill(value); },
    },
};

async function journey(page, output, key, definition, tag, unitId, checks) {
    const code = definition.sku ? definition.sku(tag) : definition.code(tag);
    const firstName = definition.name(tag);
    const firstEdit = `Revision one ${tag}`;
    const finalName = `Revision two ${tag}`;
    const values = { code, name: firstName, en: `Round2 English ${tag}` };
    await page.goto(baseUrl + definition.path + '/create');
    await definition.invalid(page);
    assert.match(new URL(page.url()).pathname, /create$/);
    await page.screenshot({ path: path.join(output, `${key}-invalid-desktop.png`), animations: 'disabled' });
    checks.push(`${key}: empty required form rejected with visible recovery state`);

    await definition.fill(page, values, unitId);
    await page.screenshot({ path: path.join(output, `${key}-create-desktop.png`), animations: 'disabled' });
    await page.setViewportSize({ width: 390, height: 844 });
    const mobileWidth = await page.evaluate(() => ({ document: document.documentElement.scrollWidth, viewport: innerWidth }));
    assert.ok(mobileWidth.document <= mobileWidth.viewport, `${key} mobile page has no document horizontal overflow`);
    await page.screenshot({ path: path.join(output, `${key}-create-mobile.png`), animations: 'disabled' });
    await page.setViewportSize({ width: 1672, height: 941 });

    await Promise.all([
        page.waitForURL(url => new RegExp(`^/${key}/\\d+$`).test(url.pathname)),
        page.locator('form[wire\\:submit="save"] button[type="submit"]').click(),
    ]);
    const id = Number(new URL(page.url()).pathname.split('/').pop());
    assert.ok(Number.isInteger(id) && id > 0);
    checks.push(`${key}: first real save navigated to persisted details ${id}`);

    await page.goto(baseUrl + definition.path);
    await page.goto(baseUrl + definition.detailPath(id));
    await page.waitForLoadState('networkidle');
    await page.goto(baseUrl + definition.editPath(id));
    const reopenedName = definition.sku ? await page.locator(model('form.name')).inputValue() : await page.locator(model('form.name')).inputValue();
    assert.equal(reopenedName, firstName, `${key} first save was present when edit form reopened`);
    await definition.change(page, firstEdit);
    await page.locator('form[wire\\:submit="save"] button[type="submit"]').click();
    await page.waitForURL(url => url.pathname === definition.detailPath(id));
    await page.reload();
    await page.goto(baseUrl + definition.editPath(id));
    assert.equal(await page.locator(model('form.name_en')).inputValue(), firstEdit, `${key} first edit survived full reload`);
    await definition.change(page, finalName);
    await page.locator('form[wire\\:submit="save"] button[type="submit"]').click();
    await page.waitForURL(url => url.pathname === definition.detailPath(id));
    await page.reload();
    await page.goto(baseUrl + definition.editPath(id));
    assert.equal(await page.locator(model('form.name_en')).inputValue(), finalName, `${key} third save survived full reload`);
    checks.push(`${key}: navigate away, reopen, edit, reload, reopen, edit again and verify final save`);
    return { id, code, firstName, finalName };
}

async function branchJourney(page, output, tag, checks) {
    const code = `R2-${tag}-B`;
    const initialName = `Round2 branch ${tag}`;
    const revisionOne = `Branch revision one ${tag}`;
    const revisionTwo = `Branch revision two ${tag}`;
    const listPath = '/organization/branches';
    await page.goto(baseUrl + listPath);
    await page.locator('button[wire\\:click="create"]').click();
    const form = page.locator('form[wire\\:submit="save"]');
    await form.locator(':invalid').first().waitFor({ state: 'attached' });
    checks.push('M01 branch: empty required modal is rejected in the browser');
    await page.locator(model('form.code')).fill(code);
    await page.locator(model('form.name')).fill(initialName);
    await page.locator(model('form.address')).fill(`Synthetic test address ${tag}`);
    await page.screenshot({ path: path.join(output, 'branch-create-desktop.png'), animations: 'disabled' });
    await page.setViewportSize({ width: 390, height: 844 });
    const width = await page.evaluate(() => ({ document: document.documentElement.scrollWidth, viewport: innerWidth }));
    assert.ok(width.document <= width.viewport, 'branch list has no document horizontal overflow on mobile');
    await page.screenshot({ path: path.join(output, 'branch-create-mobile.png'), animations: 'disabled' });
    await page.setViewportSize({ width: 1672, height: 941 });
    await form.locator('button[type="submit"]').click();
    await form.waitFor({ state: 'hidden' });
    const row = page.locator('tr').filter({ hasText: code });
    await row.waitFor();
    const href = await row.locator('a').first().getAttribute('href');
    const branchPath = href ? new URL(href, baseUrl).pathname : '';
    assert.ok(/^\/organization\/branches\/\d+$/.test(branchPath));
    const id = Number(branchPath.split('/').pop());
    checks.push(`M01 branch: first save created synthetic branch ${id}`);

    await page.goto(baseUrl + branchPath);
    await page.waitForLoadState('networkidle');
    await page.goto(baseUrl + listPath);
    const editRow = page.locator('tr').filter({ hasText: code });
    await editRow.locator('button[aria-label]').click();
    await page.getByRole('menuitem', { name: 'تعديل', exact: true }).click();
    await page.locator('form[wire\\:submit="save"]').waitFor({ state: 'visible' });
    assert.equal(await page.locator(model('form.name')).inputValue(), initialName);
    await page.locator(model('form.name')).fill(revisionOne);
    await page.locator('form[wire\\:submit="save"] button[type="submit"]').click();
    await page.locator('form[wire\\:submit="save"]').waitFor({ state: 'hidden' });

    await page.goto(baseUrl + `/organization/branches/${id}`);
    await page.reload();
    await page.goto(baseUrl + listPath);
    const secondRow = page.locator('tr').filter({ hasText: code });
    await secondRow.locator('button[aria-label]').click();
    await page.getByRole('menuitem', { name: 'تعديل', exact: true }).click();
    await page.locator('form[wire\\:submit="save"]').waitFor({ state: 'visible' });
    assert.equal(await page.locator(model('form.name')).inputValue(), revisionOne);
    await page.locator(model('form.name')).fill(revisionTwo);
    await page.locator('form[wire\\:submit="save"] button[type="submit"]').click();
    await page.locator('form[wire\\:submit="save"]').waitFor({ state: 'hidden' });
    checks.push('M01 branch: create, navigate away, reopen edit, save, reload, second edit and save persisted');
    return { id, code, initialName, revisionTwo };
}

async function companyJourney(page, output, tag, original, checks) {
    const pathName = '/organization/company';
    await page.goto(baseUrl + pathName);
    assert.equal(await page.locator('html').getAttribute('dir'), 'rtl');
    await page.locator('button[wire\\:click="edit"]').first().click();
    const legalName = model('form.legal_name');
    const wasExisting = Boolean(original);
    const code = wasExisting ? original.code : `R2-${tag}-CO`;
    const name = wasExisting ? original.name : `Round2 synthetic company ${tag}`;
    const originalLegalName = wasExisting ? (original.legal_name || '') : `Round2 synthetic legal name ${tag}`;
    if (wasExisting) {
        assert.equal(await page.locator(model('form.code')).inputValue(), original.code);
        assert.equal(await page.locator(model('form.name')).inputValue(), original.name);
        assert.equal(await page.locator(legalName).inputValue(), originalLegalName);
    } else {
        await page.locator(model('form.code')).fill(code);
        await page.locator(model('form.name')).fill(name);
    }
    const firstLegalName = wasExisting ? `Company lifecycle revision one ${tag}` : originalLegalName;
    const revisionOne = `Company lifecycle revision one ${tag}`;
    const revisionTwo = `Company lifecycle revision two ${tag}`;
    const revisionThree = `Company lifecycle revision three ${tag}`;
    await page.locator(legalName).fill(firstLegalName);
    await page.screenshot({ path: path.join(output, 'company-revision-one-desktop.png'), animations: 'disabled' });
    await page.locator('form[wire\\:submit="save"] button[type="submit"]').click();
    await page.locator('button[wire\\:click="edit"]').first().waitFor();
    checks.push(`M01 company: ${wasExisting ? 'updated existing' : 'created setup'} company through the real organization action`);

    await page.reload();
    await page.locator('button[wire\\:click="edit"]').first().click();
    assert.equal(await page.locator(legalName).inputValue(), firstLegalName, 'company first save survives page reload');
    await page.locator(legalName).fill(wasExisting ? revisionOne : revisionTwo);
    await page.locator('form[wire\\:submit="save"] button[type="submit"]').click();
    await page.locator('button[wire\\:click="edit"]').first().waitFor();
    await page.reload();
    await page.locator('button[wire\\:click="edit"]').first().click();
    assert.equal(await page.locator(legalName).inputValue(), wasExisting ? revisionOne : revisionTwo, 'company second save survives page reload');
    await page.locator(legalName).fill(wasExisting ? originalLegalName : revisionThree);
    await page.locator('form[wire\\:submit="save"] button[type="submit"]').click();
    await page.locator('button[wire\\:click="edit"]').first().waitFor();
    await page.reload();
    checks.push(`M01 company: navigate away, reopen, save, reload, third save; ${wasExisting ? 'original value restored' : 'synthetic setup remains identifiable'}`);
    return { name, code, finalLegalName: wasExisting ? (original.legal_name ?? null) : revisionThree };
}

async function userJourney(page, output, tag, checks) {
    const email = `round2-managed-${tag.toLowerCase()}@example.test`;
    const initialName = `Round2 managed user ${tag}`;
    const revisionOne = `Managed user revision one ${tag}`;
    const revisionTwo = `Managed user revision two ${tag}`;
    const password = crypto.randomBytes(32).toString('base64url') + 'A1';
    const listPath = '/organization/users';
    await page.goto(baseUrl + listPath);
    await page.locator('button[wire\\:click="create"]').click();
    const form = page.locator('form[wire\\:submit="save"]');
    await form.locator(':invalid').first().waitFor({ state: 'attached' });
    checks.push('M01 user: empty required account modal is rejected in the browser');
    await page.locator(model('form.name')).fill(initialName);
    await page.locator(model('form.email')).fill(email);
    await page.locator(model('form.gender')).selectOption('male');
    await page.locator(model('form.password')).fill(password);
    await page.locator(model('form.password_confirmation')).fill(password);
    await page.locator('form[wire\\:submit="save"] button[type="submit"]').click();
    await form.waitFor({ state: 'hidden' });
    await page.locator(model('search')).fill(email);
    const row = page.locator('tr').filter({ hasText: email });
    await row.waitFor();
    const detailPath = new URL(await row.locator('a').first().getAttribute('href'), baseUrl).pathname;
    assert.ok(/^\/organization\/users\/\d+$/.test(detailPath));
    const id = Number(detailPath.split('/').pop());
    checks.push(`M01 user: account provisioned without roles or automatic business grants (${id})`);

    await page.goto(baseUrl + detailPath);
    await page.waitForLoadState('networkidle');
    await page.screenshot({ path: path.join(output, 'user-details-desktop.png'), animations: 'disabled' });
    await page.goto(baseUrl + listPath);
    await page.locator(model('search')).fill(email);
    const editRow = page.locator('tr').filter({ hasText: email });
    await editRow.locator('button[aria-label]').click();
    await page.getByRole('menuitem', { name: 'تحرير البيانات', exact: true }).click();
    await page.locator('form[wire\\:submit="save"]').waitFor({ state: 'visible' });
    assert.equal(await page.locator(model('form.name')).inputValue(), initialName);
    await page.locator(model('form.name')).fill(revisionOne);
    await page.locator('form[wire\\:submit="save"] button[type="submit"]').click();
    await page.locator('form[wire\\:submit="save"]').waitFor({ state: 'hidden' });

    await page.goto(baseUrl + detailPath);
    await page.reload();
    await page.goto(baseUrl + listPath);
    await page.locator(model('search')).fill(email);
    const secondRow = page.locator('tr').filter({ hasText: email });
    await secondRow.locator('button[aria-label]').click();
    await page.getByRole('menuitem', { name: 'تحرير البيانات', exact: true }).click();
    await page.locator('form[wire\\:submit="save"]').waitFor({ state: 'visible' });
    assert.equal(await page.locator(model('form.name')).inputValue(), revisionOne);
    await page.locator(model('form.name')).fill(revisionTwo);
    await page.locator('form[wire\\:submit="save"] button[type="submit"]').click();
    await page.locator('form[wire\\:submit="save"]').waitFor({ state: 'hidden' });
    await page.goto(baseUrl + detailPath);
    await page.reload();
    checks.push('M01 user: create, navigate away, reopen edit, save, reload, second edit and final save');
    return { id, email, initialName, revisionTwo };
}

async function main() {
    if (!fs.existsSync(edgeExecutable)) throw new Error('Microsoft Edge is unavailable at configured local browser path.');
    await assertPortAvailable();
    fs.mkdirSync(evidenceRoot, { recursive: true });
    const tag = crypto.randomBytes(6).toString('hex').toUpperCase();
    const output = path.join(evidenceRoot, tag);
    fs.mkdirSync(output, { recursive: true });
    const tempPath = fs.mkdtempSync(path.join(os.tmpdir(), 'erp-round2-masters-'));
    fs.mkdirSync(path.join(tempPath, 'views'), { recursive: true });
    const logHandle = fs.openSync(path.join(tempPath, 'server.log'), 'w');
    const password = crypto.randomBytes(32).toString('base64url');
    const checks = [];
    const run = { result: 'FAIL', run_id: tag, base_url: baseUrl, test_database: 'erp_elec_new_test', browser: 'Microsoft Edge via Playwright', checks, browser_errors: errors };
    let server; let browser; let actor; let page;
    try {
        const prepared = fixture('prepare', { tag, password }, tempPath);
        actor = prepared.actor;
        server = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', '.', path.join(root, 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')], {
            cwd: path.join(root, 'public'), env: testEnvironment(tempPath), stdio: ['ignore', logHandle, logHandle], windowsHide: true,
        });
        await waitForServer(server);
        browser = await chromium.launch({ executablePath: edgeExecutable, headless: true });
        const context = await browser.newContext({ viewport: { width: 1672, height: 941 }, locale: 'ar-EG', timezoneId: 'Africa/Cairo', colorScheme: 'light' });
        await context.clearCookies();
        page = await context.newPage();
        diagnostics(page);
        await page.goto(baseUrl + '/login');
        assert.equal(await page.locator('html').getAttribute('dir'), 'rtl');
        await login(page, actor, password);
        checks.push('Arabic RTL login succeeds with this run’s synthetic, explicitly scoped test actor');

        if (process.argv.includes('--user-only')) {
            const managedUser = await userJourney(page, output, tag, checks);
            const persisted = fixture('verify-user', { email: managedUser.email, name: managedUser.revisionTwo }, tempPath);
            assert.equal(errors.length, 0, `browser diagnostics reported ${errors.length} errors`);
            run.result = 'PASS';
            run.master_records = { user: { id: managedUser.id, email: managedUser.email, final_revision: managedUser.revisionTwo } };
            run.postgresql_verification = persisted;
            writeJourneyResults(output, run, [['user', 'M01 user create and repeated edit lifecycle', run.master_records.user, persisted]]);
            run.screenshots = fs.readdirSync(output).filter(name => name.endsWith('.png')).sort();
            run.note = 'Synthetic test-database account created through the M01 UI. It has no roles or business grants; its random password stayed in process memory.';
            fs.writeFileSync(path.join(output, 'result.json'), JSON.stringify(run, null, 2) + '\n');
            console.log(`PASS: Round2 M01 user browser QA (${checks.length} checks); ${output}`);
            return;
        }

        const product = await journey(page, output, 'products', forms.product, tag, prepared.unit.id, checks);
        const customer = await journey(page, output, 'customers', forms.customer, tag, prepared.unit.id, checks);
        const supplier = await journey(page, output, 'suppliers', forms.supplier, tag, prepared.unit.id, checks);
        const company = await companyJourney(page, output, tag, prepared.company, checks);
        const branch = await branchJourney(page, output, tag, checks);
        const managedUser = await userJourney(page, output, tag, checks);
        assert.equal(errors.length, 0, `browser diagnostics reported ${errors.length} errors`);
        const persisted = fixture('verify', {
            sku: product.code, customer_code: customer.code, supplier_code: supplier.code,
            expected: {
                product: product.firstName, product_revision: product.finalName,
                customer: customer.firstName, customer_revision: customer.finalName,
                supplier: supplier.firstName, supplier_revision: supplier.finalName,
                branch: branch.revisionTwo,
                managed_user: managedUser.revisionTwo,
                company: { name: company.name, code: company.code, legal_name: company.finalLegalName },
            },
            branch_code: branch.code,
            managed_user_email: managedUser.email,
        }, tempPath);
        checks.push('direct PostgreSQL reads confirm original identities and final revisions persisted after the third save');
        run.result = 'PASS';
        run.master_records = { company: { code: company.code, name: company.name, legal_name: company.finalLegalName }, branch: { id: branch.id, code: branch.code, final_revision: branch.revisionTwo }, user: { id: managedUser.id, email: managedUser.email, final_revision: managedUser.revisionTwo }, product: { id: product.id, sku: product.code, final_revision: product.finalName }, customer: { id: customer.id, code: customer.code, final_revision: customer.finalName }, supplier: { id: supplier.id, code: supplier.code, final_revision: supplier.finalName } };
        run.postgresql_verification = persisted;
        writeJourneyResults(output, run, [
            ['company', 'M01 company update and reload lifecycle', run.master_records.company, persisted.persisted?.company],
            ['branch', 'M01 branch create and repeated edit lifecycle', run.master_records.branch, persisted.persisted?.branch],
            ['user', 'M01 user create and repeated edit lifecycle', run.master_records.user, persisted.persisted?.managed_user],
            ['products', 'M02 product create and repeated edit lifecycle', run.master_records.product, persisted.persisted?.product],
            ['customers', 'M03 customer create and repeated edit lifecycle', run.master_records.customer, persisted.persisted?.customer],
            ['suppliers', 'M03 supplier create and repeated edit lifecycle', run.master_records.supplier, persisted.persisted?.supplier],
        ]);
        run.screenshots = fs.readdirSync(output).filter(name => name.endsWith('.png')).sort();
        run.note = 'Synthetic test-database data only. The browser actor is a per-run test identity with explicit M02/M03 grants; no business postings or external services were created.';
        fs.writeFileSync(path.join(output, 'result.json'), JSON.stringify(run, null, 2) + '\n');
        console.log(`PASS: Round2 master browser QA (${checks.length} checks); ${output}`);
    } catch (error) {
        run.failure = String(error?.message || 'Round2 masters E2E failed').slice(0, 1000);
        if (page && !page.isClosed()) await page.screenshot({ path: path.join(output, 'failure-state.png'), animations: 'disabled' }).catch(() => {});
        run.screenshots = fs.readdirSync(output).filter(name => name.endsWith('.png')).sort();
        fs.writeFileSync(path.join(output, 'result.json'), JSON.stringify(run, null, 2) + '\n');
        console.error(run.failure);
        process.exitCode = 1;
    } finally {
        if (browser) await browser.close().catch(() => {});
        if (server && server.exitCode === null) {
            server.kill();
            await new Promise(resolve => { const timer = setTimeout(resolve, 1500); server.once('exit', () => { clearTimeout(timer); resolve(); }); });
        }
        const logPath = path.join(tempPath, 'server.log');
        if (fs.existsSync(logPath)) {
            const rawLog = fs.readFileSync(logPath, 'utf8');
            const safeLog = rawLog.replaceAll(password, '[REDACTED]').replaceAll(actor?.email || '\u0000', '[TEST_ACTOR]');
            fs.writeFileSync(path.join(output, 'server.log.redacted.txt'), safeLog);
        }
        fs.closeSync(logHandle);
        fs.rmSync(tempPath, { recursive: true, force: true });
    }
}

main().catch(error => { console.error(error?.message || 'Round2 masters E2E setup failed.'); process.exitCode = 1; });
