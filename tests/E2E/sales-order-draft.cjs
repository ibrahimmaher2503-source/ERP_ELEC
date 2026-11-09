'use strict';

const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const fs = require('node:fs');
const http = require('node:http');
const os = require('node:os');
const path = require('node:path');
const { spawn, spawnSync } = require('node:child_process');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '../..');
const privateRoot = path.join(root, '.tmp', 'master-remediation-20261101', 'ui-private');
const finance = path.join(privateRoot, 'finance');
const cluster = path.join(root, '.tmp', 'master-remediation-20261101', 'ui-pgdata');
const bootstrap = JSON.parse(fs.readFileSync(path.join(finance, 'gate2-finance-ui-bootstrap.json'), 'utf8'));
const nonce = process.env.E2E_FINANCE_DB_NONCE || bootstrap.ownership_nonce;
if (fs.readFileSync(path.join(privateRoot, '.codex-ui-owned.txt'), 'utf8').trim() !== 'master-remediation-20261101 Gate2 UI private PostgreSQL marker'
    || !/^[a-f0-9]{64}$/.test(nonce) || bootstrap.ownership_nonce !== nonce
    || bootstrap.database !== `erp_elec_new_finance_ui_${nonce.slice(0, 32)}` || bootstrap.port !== 49686
    || fs.realpathSync(bootstrap.data_directory) !== fs.realpathSync(cluster)
    || fs.realpathSync(fs.readFileSync(path.join(cluster, 'postmaster.pid'), 'utf8').split(/\r?\n/)[1]) !== fs.realpathSync(cluster)
    || Number(fs.readFileSync(path.join(cluster, 'postmaster.pid'), 'utf8').split(/\r?\n/)[3]) !== 49686) {
    throw new Error('ABORT: owned Gate2 finance UI PostgreSQL receipt is required');
}
const port = 8002;
const baseUrl = `http://127.0.0.1:${port}`;
const fixtureScript = path.join(__dirname, 'support', 'prepare-sales-order-fixtures.php');
const edgeExecutable = process.env.PLAYWRIGHT_EXECUTABLE_PATH || 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe';
const output = path.join(__dirname, 'artifacts', 'draft-' + crypto.randomBytes(6).toString('hex').toUpperCase());
const temporary = fs.mkdtempSync(path.join(os.tmpdir(), 'erp-sales-draft-'));
const environment = {
    PATH: process.env.PATH || '', SystemRoot: process.env.SystemRoot || '', WINDIR: process.env.WINDIR || '',
    TEMP: temporary, TMP: temporary, APP_ENV: 'testing', APP_URL: baseUrl, APP_DEBUG: 'false', APP_KEY: bootstrap.app_key,
    DB_CONNECTION: 'pgsql', DB_HOST: '127.0.0.1', DB_PORT: '49686', DB_DATABASE: bootstrap.database,
    DB_USERNAME: 'postgres', DB_PASSWORD: '', DB_URL: '', E2E_DB_PORT: '49686', E2E_FINANCE_DB_NONCE: nonce,
    APP_CONFIG_CACHE: '.tmp/master-remediation-20261101/ui-private/finance/cache/config.php',
    APP_ROUTES_CACHE: '.tmp/master-remediation-20261101/ui-private/finance/cache/routes.php',
    APP_EVENTS_CACHE: '.tmp/master-remediation-20261101/ui-private/finance/cache/events.php',
    APP_SERVICES_CACHE: '.tmp/master-remediation-20261101/ui-private/finance/cache/services.php',
    APP_PACKAGES_CACHE: '.tmp/master-remediation-20261101/ui-private/finance/cache/packages.php',
    APP_VIEW_COMPILED_PATH: path.join(finance, 'views'), VIEW_COMPILED_PATH: path.join(finance, 'views'),
    LARAVEL_STORAGE_PATH: path.join(finance, 'storage'), SESSION_DRIVER: 'file',
    CACHE_STORE: 'array', QUEUE_CONNECTION: 'sync', MAIL_MAILER: 'array', LOG_CHANNEL: 'stderr',
    BROADCAST_CONNECTION: 'null', BCRYPT_ROUNDS: '4',
};

function fixture(mode, input) {
    const result = spawnSync('php', ['-d', 'opcache.enable_cli=0', fixtureScript, mode], {
        cwd: root, env: environment, input: JSON.stringify(input), encoding: 'utf8', windowsHide: true,
    });
    if (result.error || result.status !== 0) throw new Error(`Guarded fixture ${mode} failed; inspect private test logs`);
    return JSON.parse(result.stdout);
}

function portAvailable() {
    return new Promise((resolve, reject) => {
        const probe = http.createServer();
        probe.once('error', reject);
        probe.listen(port, '127.0.0.1', () => probe.close(resolve));
    });
}

async function main() {
    let server;
    let browser;
    let actors;
    let page;
    let success;
    fs.mkdirSync(output, { recursive: true });
    const logPath = path.join(temporary, 'server.log');
    const logHandle = fs.openSync(logPath, 'w');
    try {
        assert.ok(fs.existsSync(edgeExecutable), 'Microsoft Edge executable is unavailable');
        await portAvailable();
        const password = crypto.randomBytes(32).toString('base64url');
        const reviewerPassword = crypto.randomBytes(32).toString('base64url');
        actors = fixture('prepare', { creator_password: password, reviewer_password: reviewerPassword });
        server = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', path.join(root, 'public'), path.join(root, 'tests/Support/gate2-finance-ui-router.php')], {
            cwd: path.join(root, 'public'), env: environment, stdio: ['ignore', logHandle, logHandle], windowsHide: true,
        });
        for (let attempt = 0; attempt < 60; attempt++) {
            if (server.exitCode !== null) throw new Error('Test server exited before readiness');
            try {
                if ((await fetch(baseUrl + '/login')).status === 200) break;
            } catch {}
            if (attempt === 59) throw new Error('Test server did not become ready');
            await new Promise(resolve => setTimeout(resolve, 500));
        }

        browser = await chromium.launch({ executablePath: edgeExecutable, headless: true });
        page = await browser.newPage({ viewport: { width: 1440, height: 900 }, locale: 'ar-EG', timezoneId: 'Africa/Cairo' });
        await page.goto(baseUrl + '/login', { waitUntil: 'domcontentloaded' });
        await page.locator('input[name="email"]').fill(actors.creator.email);
        await page.locator('input[name="password"]').fill(password);
        await page.locator('[data-test="login-button"]').click();
        await page.waitForURL(url => url.pathname === '/dashboard');
        if (process.argv.includes('--invoice-tab')) {
            const invoiceFixture = fixture('prepare-invoice-tab', {});
            await page.goto(baseUrl + `/sales-orders/${invoiceFixture.order_id}/invoices`, { waitUntil: 'domcontentloaded' });
            await page.getByText(`فاتورة قيد الإعداد #${invoiceFixture.invoice_id}`, { exact: true }).waitFor();
            await page.locator('#main-content').getByText('مسودة', { exact: true }).first().waitFor();
            const invoiceLink = page.getByRole('link', { name: 'عرض الفاتورة', exact: true });
            assert.equal(new URL(await invoiceLink.getAttribute('href'), baseUrl).pathname, `/financial/customer-invoices/${invoiceFixture.invoice_id}`);

            const reviewer = await browser.newPage({ viewport: { width: 1440, height: 900 }, locale: 'ar-EG', timezoneId: 'Africa/Cairo' });
            await reviewer.goto(baseUrl + '/login', { waitUntil: 'domcontentloaded' });
            await reviewer.locator('input[name="email"]').fill(actors.reviewer.email);
            await reviewer.locator('input[name="password"]').fill(reviewerPassword);
            await reviewer.locator('[data-test="login-button"]').click();
            await reviewer.waitForURL(url => url.pathname === '/dashboard');
            await reviewer.goto(baseUrl + `/sales-orders/${invoiceFixture.order_id}/invoices`, { waitUntil: 'domcontentloaded' });
            await reviewer.getByText('عرض فواتير هذا الأمر يتطلب صلاحية عرض فواتير العملاء.', { exact: true }).waitFor();
            assert.equal(await reviewer.getByText(`فاتورة قيد الإعداد #${invoiceFixture.invoice_id}`, { exact: true }).count(), 0);
            assert.equal(await reviewer.getByRole('link', { name: 'عرض الفاتورة', exact: true }).count(), 0);
            const cleanup = fixture('cleanup', { actor_ids: [actors.creator.id, actors.reviewer.id] });
            assert.equal(cleanup.actors_disabled, true);
            actors = null;
            success = { result: 'PASS', scope: 'sales-order-invoice-tab', order_id: invoiceFixture.order_id, invoice_id: invoiceFixture.invoice_id };
        } else {
        await page.goto(baseUrl + '/sales-orders/create', { waitUntil: 'domcontentloaded' });
        await page.getByRole('heading', { name: 'إنشاء أمر بيع مباشر' }).waitFor();

        await page.locator('select[wire\\:model="form.branch_id"]').selectOption(String(actors.branch.id));
        await page.locator('select[wire\\:model="form.customer_id"]').selectOption(String(actors.customer.id));
        const reference = 'E2E-DRAFT-' + path.basename(output);
        await page.locator('input[wire\\:model="form.customer_reference"]').fill(reference);
        await page.locator('input[wire\\:model="form.project_name"]').fill('مشروع اختبار المسودة');
        await page.locator('select[wire\\:model\\.live="lines.0.product_id"]').selectOption(String(actors.product.id));
        await page.locator('select[wire\\:model\\.live="lines.0.unit_id"]').waitFor();
        await page.waitForFunction(id => document.querySelector('select[wire\\:model\\.live="lines.0.unit_id"]')?.value === String(id), actors.unit.id);
        await page.locator('input[wire\\:model\\.live\\.debounce\\.500ms="lines.0.quantity"]').fill('2.50000000');
        await page.locator('input[wire\\:model\\.live\\.debounce\\.500ms="lines.0.requested_price"]').fill('123.45000000');
        await Promise.all([
            page.waitForURL(url => /^\/sales-orders\/\d+$/.test(url.pathname)),
            page.locator('form[wire\\:submit="save"] button[type="submit"]').click(),
        ]);
        const orderId = Number(new URL(page.url()).pathname.split('/').pop());
        assert.ok(orderId > 0);
        await page.getByText('تم حفظ مسودة أمر البيع.', { exact: true }).waitFor();
        await page.goto(baseUrl + `/sales-orders/${orderId}/edit`, { waitUntil: 'domcontentloaded' });
        const project = page.locator('input[wire\\:model="form.project_name"]');
        assert.equal(await project.inputValue(), 'مشروع اختبار المسودة');
        await project.fill('مشروع اختبار المسودة المعدّل');
        await Promise.all([
            page.waitForURL(url => url.pathname === `/sales-orders/${orderId}`),
            page.locator('form[wire\\:submit="save"] button[type="submit"]').click(),
        ]);
        const persisted = fixture('verify-draft', {
            order_id: orderId, creator_id: actors.creator.id, branch_id: actors.branch.id,
            customer_id: actors.customer.id, customer_reference: reference,
            project_name: 'مشروع اختبار المسودة المعدّل', product_id: actors.product.id, unit_id: actors.unit.id,
        });
        assert.equal(persisted.status, 'draft');
        assert.ok(persisted.version >= 2);
        const cleanup = fixture('cleanup', { actor_ids: [actors.creator.id, actors.reviewer.id] });
        assert.equal(cleanup.actors_disabled, true);
        actors = null;
        success = { result: 'PASS', order_id: orderId, status: persisted.status, version: persisted.version };
        }
    } catch (error) {
        fs.writeFileSync(path.join(output, 'result.json'), JSON.stringify({ result: 'FAIL', error: error.message, path: page?.url()?.startsWith(baseUrl) ? new URL(page.url()).pathname : null }, null, 2));
        if (page && !page.isClosed()) await page.screenshot({ path: path.join(output, 'failure.png') }).catch(() => {});
        throw error;
    } finally {
        if (browser) {
            const closed = await Promise.race([browser.close().then(() => true, () => false), new Promise(resolve => setTimeout(() => resolve(false), 5000))]);
            if (!closed) browser.process?.()?.kill();
        }
        if (server && server.exitCode === null) {
            if (process.platform === 'win32' && server.pid) spawnSync('taskkill', ['/PID', String(server.pid), '/T', '/F'], { windowsHide: true, timeout: 3000 });
            else server.kill();
            await new Promise(resolve => { const timer = setTimeout(resolve, 1500); server.once('exit', () => { clearTimeout(timer); resolve(); }); });
        }
        if (actors) fixture('cleanup', { actor_ids: [actors.creator.id, actors.reviewer.id] });
        fs.closeSync(logHandle);
        fs.rmSync(temporary, { recursive: true, force: true });
    }
    fs.writeFileSync(path.join(output, 'result.json'), JSON.stringify(success, null, 2));
    console.log(`PASS: ${success.scope ?? 'direct sales order draft'} ${success.order_id} verified in PostgreSQL and browser`);
}

main().then(() => process.exit(0), error => { console.error(error.message); process.exit(1); });
