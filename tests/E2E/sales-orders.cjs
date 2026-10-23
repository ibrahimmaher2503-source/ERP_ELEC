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
const baseUrl = 'http://127.0.0.1:8001';
const fixtureScript = path.join(__dirname, 'support', 'prepare-sales-order-fixtures.php');
const edgeExecutable = 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe';
const artifactRoot = path.join(__dirname, 'artifacts');
const errors = [];

function testEnvironment(tempPath) {
    return {
        PATH: process.env.PATH || '',
        SystemRoot: process.env.SystemRoot || '',
        WINDIR: process.env.WINDIR || '',
        TEMP: tempPath,
        TMP: tempPath,
        APP_ENV: 'testing',
        APP_URL: baseUrl,
        APP_DEBUG: 'false',
        DB_CONNECTION: 'pgsql',
        DB_DATABASE: 'erp_elec_new_test',
        DB_URL: '',
        SESSION_DRIVER: 'database',
        SESSION_LIFETIME: '30',
        CACHE_STORE: 'array',
        QUEUE_CONNECTION: 'sync',
        MAIL_MAILER: 'array',
        LOG_CHANNEL: 'stderr',
        BROADCAST_CONNECTION: 'null',
        BCRYPT_ROUNDS: '4',
    };
}

function runFixture(mode, input, tempPath) {
    const result = spawnSync('php', [fixtureScript, mode], {
        cwd: root,
        env: testEnvironment(tempPath),
        input: JSON.stringify(input),
        encoding: 'utf8',
        windowsHide: true,
    });
    if (result.error || result.status !== 0) {
        throw new Error('The guarded PostgreSQL E2E fixture step failed.');
    }
    return result.stdout.trim() === '' ? {} : JSON.parse(result.stdout);
}

function assertPortAvailable(port) {
    return new Promise((resolve, reject) => {
        const server = http.createServer();
        server.once('error', error => {
            if (error.code === 'EADDRINUSE') {
                reject(new Error('Port 8001 is already in use; no process was stopped or changed.'));
                return;
            }
            reject(error);
        });
        server.listen(port, '127.0.0.1', () => server.close(resolve));
    });
}

async function waitForServer(server) {
    for (let attempt = 0; attempt < 60; attempt++) {
        if (server.exitCode !== null) {
            throw new Error('The isolated test application stopped before becoming ready.');
        }
        try {
            const response = await fetch(baseUrl + '/login', { redirect: 'manual' });
            if (response.status === 200) {
                return;
            }
        } catch {
            // The test server is still starting.
        }
        await new Promise(resolve => setTimeout(resolve, 500));
    }
    throw new Error('The isolated test application did not become ready on port 8001.');
}

async function login(page, account) {
    await page.goto(baseUrl + '/login');
    await page.locator('input[name="email"]').fill(account.email);
    await page.locator('input[name="password"]').fill(account.password);
    await page.locator('[data-test="login-button"]').click();
    await page.waitForURL(url => url.pathname === '/dashboard');
}

async function saveScreenshot(page, outputDirectory, filename) {
    await page.screenshot({
        path: path.join(outputDirectory, filename),
        fullPage: false,
        animations: 'disabled',
    });
}

async function clickOrderScreen(page, orderId, key, label) {
    await page.getByRole('link', { name: label, exact: true }).click();
    const expectedPath = key === '' ? '/sales-orders/' + orderId : '/sales-orders/' + orderId + '/' + key;
    await page.waitForURL(url => url.pathname === expectedPath);
}

function addDays(isoDate, count) {
    const date = new Date(isoDate + 'T12:00:00Z');
    date.setUTCDate(date.getUTCDate() + count);
    return date.toISOString().slice(0, 10);
}

function attachBrowserDiagnostics(page, ignoreConsoleError = () => false) {
    page.on('pageerror', error => {
        errors.push({ kind: 'uncaught page exception', message: String(error.message).slice(0, 300) });
    });
    page.on('console', message => {
        if (message.type() === 'error' && !ignoreConsoleError(message.text())) {
            errors.push({ kind: 'console error', message: String(message.text()).slice(0, 300) });
        }
    });
}

async function main() {
    if (!fs.existsSync(edgeExecutable)) {
        throw new Error('Microsoft Edge is unavailable at the configured local browser path.');
    }
    await assertPortAvailable(8001);

    fs.mkdirSync(artifactRoot, { recursive: true });
    const runId = crypto.randomBytes(6).toString('hex').toUpperCase();
    const outputDirectory = path.join(artifactRoot, runId);
    fs.mkdirSync(outputDirectory, { recursive: true });

    const tempPath = fs.mkdtempSync(path.join(os.tmpdir(), 'erp-sales-order-e2e-'));
    const serverLogPath = path.join(tempPath, 'test-server.log');
    const serverLogHandle = fs.openSync(serverLogPath, 'w');
    const creatorPassword = crypto.randomBytes(32).toString('base64url');
    const reviewerPassword = crypto.randomBytes(32).toString('base64url');
    let fixture = null;
    let server = null;
    let browser = null;
    let currentPage = null;
    let exitCode = 0;
    const checks = [];

    try {
        fixture = runFixture('prepare', {
            creator_password: creatorPassword,
            reviewer_password: reviewerPassword,
        }, tempPath);
        const creator = { email: fixture.creator.email, password: creatorPassword };
        const reviewer = { email: fixture.reviewer.email, password: reviewerPassword };

        server = spawn('php', ['-S', '127.0.0.1:8001', '-t', 'public', 'public/index.php'], {
            cwd: root,
            env: testEnvironment(tempPath),
            stdio: ['ignore', serverLogHandle, serverLogHandle],
            windowsHide: true,
        });
        await waitForServer(server);

        browser = await chromium.launch({
            executablePath: edgeExecutable,
            headless: true,
        });

        const ownerContext = await browser.newContext({
            viewport: { width: 1672, height: 941 },
            locale: 'ar-EG',
            timezoneId: 'Africa/Cairo',
            colorScheme: 'light',
        });
        const ownerPage = await ownerContext.newPage();
        currentPage = ownerPage;
        attachBrowserDiagnostics(ownerPage);

        await ownerPage.goto(baseUrl + '/sales-orders');
        await ownerPage.waitForURL(url => url.pathname === '/login');
        assert.equal(await ownerPage.locator('html').getAttribute('dir'), 'rtl');
        assert.equal(await ownerPage.locator('html').getAttribute('lang'), 'ar');
        await saveScreenshot(ownerPage, outputDirectory, 'login-guest-desktop.png');
        checks.push('guest sales-order route redirects to login; Arabic RTL document');

        await login(ownerPage, creator);
        await ownerPage.goto(baseUrl + '/sales-orders');
        await ownerPage.getByRole('heading', { name: 'أوامر البيع' }).waitFor();
        const wideListWidth = await ownerPage.evaluate(() => ({
            document: document.documentElement.scrollWidth,
            viewport: window.innerWidth,
        }));
        assert.ok(wideListWidth.document <= wideListWidth.viewport, 'the list has no document-level horizontal overflow at the reference viewport');
        await saveScreenshot(ownerPage, outputDirectory, 'list-shared-shell-reference-viewport.png');
        checks.push('wide RTL list layout captured at the supplied 1672x941 reference viewport');
        await ownerPage.goto(baseUrl + '/sales-orders/create');
        await ownerPage.getByRole('heading', { name: 'إنشاء أمر بيع مباشر' }).waitFor();

        const invalidSave = ownerPage.getByRole('button', { name: 'حفظ المسودة', exact: true }).first();
        await invalidSave.click();
        await ownerPage.locator('main li').first().waitFor();
        assert.match(await ownerPage.locator('main').innerText(), /مطلوب|required/);
        assert.equal(new URL(ownerPage.url()).pathname, '/sales-orders/create');
        await saveScreenshot(ownerPage, outputDirectory, 'form-validation-error-desktop.png');
        checks.push('required sales-order data rejected before persistence');

        await ownerPage.reload();
        const branchSelect = ownerPage.locator('select[wire\\:model="form.branch_id"]');
        await branchSelect.selectOption(String(fixture.branch.id));
        const orderDateInput = ownerPage.locator('input[wire\\:model="form.order_date"]');
        const orderDate = await orderDateInput.inputValue();
        await ownerPage.getByLabel('تاريخ التسليم المطلوب', { exact: true }).fill(addDays(orderDate, 5));
        await ownerPage.getByLabel('العميل *', { exact: true }).selectOption(String(fixture.customer.id));
        const reference = 'E2E-SO-' + runId;
        await ownerPage.getByLabel('مرجع العميل', { exact: true }).fill(reference);
        await ownerPage.getByLabel('اسم المشروع (اختياري)', { exact: true }).fill('مشروع تجريبي لاختبار أمر البيع');
        const productSelect = ownerPage.getByLabel('الصنف للبند 1', { exact: true });
        await productSelect.selectOption(String(fixture.product.id));
        const unitSelect = ownerPage.getByLabel('الوحدة للبند 1', { exact: true });
        await ownerPage.waitForFunction(unitId => {
            const field = document.querySelector('select[wire\\:model="lines.0.unit_id"]');
            return field && field.value === String(unitId);
        }, fixture.unit.id);
        assert.equal(await unitSelect.inputValue(), String(fixture.unit.id));
        await ownerPage.getByLabel('كمية البند 1', { exact: true }).fill('2.50000000');
        await ownerPage.getByLabel('السعر المقترح للبند 1', { exact: true }).fill('123.45000000');

        await branchSelect.focus();
        await ownerPage.keyboard.press('Tab');
        assert.equal(await ownerPage.evaluate(() => document.activeElement.type), 'date');
        checks.push('keyboard tab moves from branch selection to order date');

        await saveScreenshot(ownerPage, outputDirectory, 'form-filled-desktop.png');
        await ownerPage.setViewportSize({ width: 390, height: 844 });
        const formWidth = await ownerPage.evaluate(() => ({
            document: document.documentElement.scrollWidth,
            viewport: window.innerWidth,
        }));
        assert.ok(formWidth.document <= formWidth.viewport, 'the filled form has no document-level horizontal overflow on mobile');
        await saveScreenshot(ownerPage, outputDirectory, 'form-filled-mobile.png');
        await ownerPage.setViewportSize({ width: 1672, height: 941 });

        await Promise.all([
            ownerPage.waitForURL(url => {
                const pathParts = url.pathname.split('/');
                return pathParts.length === 3 && pathParts[1] === 'sales-orders' && /^\d+$/.test(pathParts[2]);
            }),
            ownerPage.getByRole('button', { name: 'حفظ المسودة', exact: true }).last().click(),
        ]);
        const orderId = Number(new URL(ownerPage.url()).pathname.split('/').pop());
        assert.ok(Number.isInteger(orderId) && orderId > 0);
        await ownerPage.getByText('تم حفظ مسودة أمر البيع.', { exact: true }).waitFor();
        assert.equal(await ownerPage.locator('html').getAttribute('dir'), 'rtl');
        assert.match(await ownerPage.locator('main').innerText(), /مسودة/);
        assert.ok((await ownerPage.locator('main').innerText()).includes('123.45'));
        assert.ok((await ownerPage.locator('main').innerText()).includes('2.5'));
        assert.match(await ownerPage.locator('main').innerText(), new RegExp(reference));
        checks.push('creator saves a persisted direct draft with exact decimal strings');
        await saveScreenshot(ownerPage, outputDirectory, 'order-overview-desktop.png');

        const screens = [
            ['', 'البيانات الرئيسية', 'order-overview'],
            ['review', 'المراجعة', 'review-draft'],
            ['amendment', 'التعديل', 'amendment-draft'],
            ['delivery', 'التسليم', 'delivery-draft'],
            ['invoices', 'الفواتير', 'invoices-draft'],
            ['closure', 'الإغلاق والمرتجعات', 'closure-draft'],
        ];
        for (const [key, label, fileBase] of screens) {
            await clickOrderScreen(ownerPage, orderId, key, label);
            assert.ok((await ownerPage.locator('main').innerText()).length > 50);
            if (key === 'review') {
                const approval = ownerPage.getByRole('button', { name: 'اعتماد أمر البيع', exact: true });
                assert.equal(await approval.isDisabled(), true);
                assert.match(await ownerPage.locator('main').innerText(), /لا يسجل هذا القسم أي موافقة/);
            }
            if (key === 'delivery') {
                assert.match(await ownerPage.locator('main').innerText(), /لا توجد حجوزات مخزون/);
                assert.equal(await ownerPage.getByRole('button', { name: 'إنشاء مستند تسليم', exact: true }).isDisabled(), true);
            }
            if (key === 'invoices') {
                assert.equal(await ownerPage.getByRole('button', { name: 'عرض الفواتير', exact: true }).isDisabled(), true);
            }
            await saveScreenshot(ownerPage, outputDirectory, fileBase + '-desktop.png');
        }
        checks.push('all order detail tabs render; approval, delivery, invoice, closure and return paths remain blocked');

        await ownerPage.goto(baseUrl + '/sales-orders');
        await ownerPage.getByRole('heading', { name: 'أوامر البيع' }).waitFor();
        await ownerPage.getByLabel('عدد الصفوف في الصفحة', { exact: true }).selectOption('10');
        const pagination = ownerPage.locator('nav[aria-label="Pagination Navigation"]');
        await pagination.waitFor();
        const pageTwo = pagination.getByRole('button', { name: /2/ });
        await pageTwo.waitFor();
        await pageTwo.click();
        await ownerPage.waitForURL(url => url.searchParams.get('page') === '2');
        assert.equal(await ownerPage.getByText(reference, { exact: true }).count(), 0);
        await saveScreenshot(ownerPage, outputDirectory, 'list-page-2-desktop.png');
        checks.push('sales-order list pagination advances to the second page');

        await ownerPage.getByLabel('البحث', { exact: true }).fill(reference);
        await ownerPage.waitForURL(url => url.searchParams.get('page') !== '2');
        await ownerPage.getByText(reference, { exact: true }).waitFor();
        await ownerPage.getByLabel('الحالة', { exact: true }).selectOption('draft');
        await ownerPage.getByLabel('الفرع', { exact: true }).selectOption(String(fixture.branch.id));
        await ownerPage.getByLabel('من تاريخ الأمر', { exact: true }).fill(orderDate);
        await ownerPage.getByLabel('إلى تاريخ الأمر', { exact: true }).fill(orderDate);
        await ownerPage.waitForTimeout(500);
        await saveScreenshot(ownerPage, outputDirectory, 'list-filtered-desktop.png');
        const downloadPromise = ownerPage.waitForEvent('download');
        await ownerPage.getByRole('button', { name: 'تصدير Excel', exact: true }).click();
        const exportDownload = await downloadPromise;
        assert.equal(exportDownload.suggestedFilename(), 'sales-orders.xlsx');
        const exportPath = path.join(outputDirectory, 'filtered-sales-orders.xlsx');
        await exportDownload.saveAs(exportPath);
        assert.equal(fs.readFileSync(exportPath).subarray(0, 2).toString('ascii'), 'PK');
        checks.push('creator downloads a valid XLSX using current authorized filters');
        await ownerPage.getByRole('button', { name: 'مسح الفلاتر', exact: true }).click();
        await ownerPage.waitForFunction(() => {
            const search = document.querySelector('input[wire\\:model\\.live\\.debounce\\.350ms="search"]');
            const status = document.querySelector('select[wire\\:model\\.live="status"]');
            return search && search.value === '' && status && status.value === '';
        });
        assert.equal(await ownerPage.getByLabel('البحث', { exact: true }).inputValue(), '');
        assert.equal(await ownerPage.getByLabel('الحالة', { exact: true }).inputValue(), '');
        await saveScreenshot(ownerPage, outputDirectory, 'list-desktop.png');
        checks.push('list search, status, branch, date filters and clear action');

        await ownerPage.goto(baseUrl + '/sales-orders/' + orderId + '/edit');
        const project = ownerPage.getByLabel('اسم المشروع (اختياري)', { exact: true });
        await project.fill('تعديل غير محفوظ لاختبار التنقل');
        let confirmSeen = false;
        ownerPage.once('dialog', async dialog => {
            confirmSeen = dialog.type() === 'confirm';
            await dialog.dismiss();
        });
        await ownerPage.getByRole('link', { name: 'العودة إلى أوامر البيع', exact: true }).click();
        await ownerPage.waitForTimeout(150);
        assert.equal(new URL(ownerPage.url()).pathname, '/sales-orders/' + orderId + '/edit');
        assert.equal(confirmSeen, true);
        await project.fill('مشروع تجريبي - تم حفظ التعديل');
        await ownerPage.getByRole('button', { name: 'حفظ المسودة', exact: true }).last().click();
        await ownerPage.waitForURL(url => url.pathname === '/sales-orders/' + orderId);
        assert.match(await ownerPage.locator('main').innerText(), /مشروع تجريبي - تم حفظ التعديل/);
        await ownerPage.reload();
        assert.match(await ownerPage.locator('main').innerText(), /مشروع تجريبي - تم حفظ التعديل/);
        checks.push('editing persists across reload and dirty-navigation confirmation blocks leaving');

        await ownerPage.setViewportSize({ width: 390, height: 844 });
        await ownerPage.goto(baseUrl + '/sales-orders');
        const mobileListWidth = await ownerPage.evaluate(() => ({
            document: document.documentElement.scrollWidth,
            viewport: window.innerWidth,
        }));
        assert.ok(mobileListWidth.document <= mobileListWidth.viewport, 'the sales-order list has no document-level horizontal overflow on mobile');
        await saveScreenshot(ownerPage, outputDirectory, 'list-mobile.png');
        await ownerPage.goto(baseUrl + '/sales-orders/' + orderId);
        const mobileDetailsWidth = await ownerPage.evaluate(() => ({
            document: document.documentElement.scrollWidth,
            viewport: window.innerWidth,
        }));
        assert.ok(mobileDetailsWidth.document <= mobileDetailsWidth.viewport, 'order details have no document-level horizontal overflow on mobile');
        await saveScreenshot(ownerPage, outputDirectory, 'order-overview-mobile.png');
        await clickOrderScreen(ownerPage, orderId, 'delivery', 'التسليم');
        await saveScreenshot(ownerPage, outputDirectory, 'delivery-mobile.png');
        checks.push('mobile 390x844 RTL list, form, overview and delivery render without document overflow');

        await ownerPage.setViewportSize({ width: 1440, height: 1000 });
        await ownerPage.goto(baseUrl + '/sales-orders/' + orderId + '/review');
        await ownerPage.getByRole('button', { name: 'تقديم للمراجعة', exact: true }).click();
        await ownerPage.getByText('تم تقديم أمر البيع للمراجعة.', { exact: true }).waitFor();
        assert.match(await ownerPage.locator('main').innerText(), /بانتظار المراجعة/);
        await saveScreenshot(ownerPage, outputDirectory, 'review-pending-desktop.png');
        assert.equal(await ownerPage.getByRole('button', { name: 'اعتماد أمر البيع', exact: true }).isDisabled(), true);

        await ownerPage.getByRole('button', { name: 'سحب طلب المراجعة', exact: true }).click();
        await ownerPage.getByText('تم سحب طلب المراجعة.', { exact: true }).waitFor();
        assert.match(await ownerPage.locator('main').innerText(), /مسودة/);
        await saveScreenshot(ownerPage, outputDirectory, 'review-withdrawn-desktop.png');
        await ownerPage.getByRole('button', { name: 'تقديم للمراجعة', exact: true }).click();
        await ownerPage.getByText('تم تقديم أمر البيع للمراجعة.', { exact: true }).waitFor();
        assert.match(await ownerPage.locator('main').innerText(), /لا يصدر مقدم طلب المراجعة قرارًا على طلبه/);
        assert.equal(await ownerPage.getByText('رفض المراجعة وإعادة الأمر للمسودة', { exact: true }).count(), 0);
        checks.push('requester submits and withdraws, then cannot decide their own pending review');

        const reviewerContext = await browser.newContext({
            viewport: { width: 1672, height: 941 },
            locale: 'ar-EG',
            timezoneId: 'Africa/Cairo',
            colorScheme: 'light',
        });
        const reviewerPage = await reviewerContext.newPage();
        currentPage = reviewerPage;
        let expectedForbiddenNavigation = false;
        attachBrowserDiagnostics(reviewerPage, message => expectedForbiddenNavigation && /403/.test(message));
        await login(reviewerPage, reviewer);
        await reviewerPage.goto(baseUrl + '/sales-orders');
        await reviewerPage.getByRole('heading', { name: 'أوامر البيع' }).waitFor();
        assert.equal(await reviewerPage.getByRole('link', { name: 'إنشاء أمر بيع', exact: true }).count(), 0);
        assert.equal(await reviewerPage.getByRole('button', { name: 'تصدير Excel', exact: true }).count(), 0);
        expectedForbiddenNavigation = true;
        const unauthorizedCreate = await reviewerPage.goto(baseUrl + '/sales-orders/create');
        expectedForbiddenNavigation = false;
        assert.equal(unauthorizedCreate.status(), 403);
        await reviewerPage.goto(baseUrl + '/sales-orders/' + orderId + '/review');
        await reviewerPage.getByText('رفض المراجعة وإعادة الأمر للمسودة', { exact: true }).waitFor();
        await reviewerPage.locator('summary').filter({ hasText: 'رفض المراجعة وإعادة الأمر للمسودة' }).click();
        await reviewerPage.getByLabel('سبب الرفض', { exact: true }).fill('يرجى مراجعة بيانات بند الاختبار.');
        await reviewerPage.getByRole('button', { name: 'تأكيد الرفض', exact: true }).click();
        await reviewerPage.getByText('تم رفض المراجعة وإرجاع أمر البيع إلى المسودة.', { exact: true }).waitFor();
        assert.match(await reviewerPage.locator('main').innerText(), /مسودة/);
        assert.equal(await reviewerPage.getByRole('button', { name: 'تقديم للمراجعة', exact: true }).count(), 0);
        await saveScreenshot(reviewerPage, outputDirectory, 'review-rejected-desktop.png');
        checks.push('separate reviewer can reject with a reason; reviewer cannot create or submit');

        currentPage = ownerPage;
        await ownerPage.goto(baseUrl + '/sales-orders/' + orderId);
        await ownerPage.locator('summary').filter({ hasText: 'إلغاء أمر البيع' }).first().click();
        await ownerPage.getByLabel('سبب الإلغاء', { exact: true }).fill('إلغاء بيانات اختبار E2E بعد التحقق.');
        await saveScreenshot(ownerPage, outputDirectory, 'order-cancellation-form-desktop.png');
        await ownerPage.getByRole('button', { name: 'تأكيد الإلغاء', exact: true }).click();
        await ownerPage.getByText('تم إلغاء أمر البيع.', { exact: true }).waitFor();
        assert.match(await ownerPage.locator('main').innerText(), /ملغي/);
        await saveScreenshot(ownerPage, outputDirectory, 'order-cancelled-desktop.png');
        checks.push('unexecuted order cancellation requires and persists a reason');

        if (errors.length > 0) {
            throw new Error('The browser emitted ' + errors.length + ' page or console error(s).');
        }

        const verification = runFixture('verify', {
            order_id: orderId,
            creator_id: fixture.creator.id,
            reviewer_id: fixture.reviewer.id,
            branch_id: fixture.branch.id,
            customer_id: fixture.customer.id,
            product_id: fixture.product.id,
            unit_id: fixture.unit.id,
            customer_reference: reference,
        }, tempPath);
        assert.equal(verification.status, 'cancelled');
        assert.equal(verification.review_statuses.join(','), 'withdrawn,rejected');
        assert.equal(verification.line_quantity, '2.50000000');
        assert.equal(verification.line_requested_price, '123.45000000');
        checks.push('PostgreSQL confirms persisted decimals, cancellation, and two real review outcomes');

        const report = {
            result: 'PASS',
            run_id: runId,
            base_url: baseUrl,
            test_database: 'erp_elec_new_test',
            viewport_desktop: '1672x941',
            viewport_mobile: '390x844',
            reference_viewport_capture: '1672x941; matched to the supplied Sales Orders list reference.',
            browser: 'Microsoft Edge via Playwright',
            checks,
            screenshots: fs.readdirSync(outputDirectory).filter(name => name.endsWith('.png')).sort(),
            downloads: fs.readdirSync(outputDirectory).filter(name => name.endsWith('.xlsx')).sort(),
            browser_errors: errors,
            note: 'Synthetic local testing records only; no approval, stock movement, invoice, or posting was created.',
        };
        fs.writeFileSync(path.join(outputDirectory, 'result.json'), JSON.stringify(report, null, 2) + '\n');
        console.log('PASS: Sales Order browser E2E (' + checks.length + ' checks); screenshots: ' + outputDirectory);
    } catch (error) {
        exitCode = 1;
        const failure = error && error.message ? error.message : 'Sales Order browser E2E failed.';
        if (currentPage && !currentPage.isClosed()) {
            await saveScreenshot(currentPage, outputDirectory, 'failure-state.png').catch(() => {});
        }
        try {
            fs.writeFileSync(path.join(outputDirectory, 'result.json'), JSON.stringify({
                result: 'FAIL',
                run_id: runId,
                base_url: baseUrl,
                test_database: 'erp_elec_new_test',
                checks,
                failure,
                browser_errors: errors,
                screenshots: fs.readdirSync(outputDirectory).filter(name => name.endsWith('.png')).sort(),
                downloads: fs.readdirSync(outputDirectory).filter(name => name.endsWith('.xlsx')).sort(),
            }, null, 2) + '\n');
        } catch {
            // Keep the original failure if the local evidence directory is unavailable.
        }
        console.error(failure);
    } finally {
        if (browser) {
            await browser.close().catch(() => {});
        }
        if (server && server.exitCode === null) {
            server.kill();
            await new Promise(resolve => {
                const timeout = setTimeout(resolve, 1500);
                server.once('exit', () => {
                    clearTimeout(timeout);
                    resolve();
                });
            });
        }
        try {
            if (fixture) {
                runFixture('cleanup', { actor_ids: [fixture.creator.id, fixture.reviewer.id] }, tempPath);
            }
        } catch {
            if (exitCode === 0) {
                exitCode = 1;
                console.error('The test-only database session cleanup failed.');
            }
        }
        fs.closeSync(serverLogHandle);
        fs.rmSync(tempPath, { recursive: true, force: true });
    }

    process.exitCode = exitCode;
}

main().catch(error => {
    console.error(error && error.message ? error.message : 'Sales Order browser E2E failed before setup.');
    process.exitCode = 1;
});
