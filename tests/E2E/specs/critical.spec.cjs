'use strict';

const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const { spawnSync } = require('node:child_process');
const { test, expect } = require('playwright/test');

const root = path.resolve(__dirname, '../../..');
const bootstrapPath = process.env.E2E_BOOTSTRAP_FILE || path.join(root, '.tmp/e2e-automation/bootstrap.json');
const fixturePath = path.join(root, 'tests/E2E/support/automation-critical-fixtures.php');
const operationTimings = [];

function bootstrap() {
  if (!fs.existsSync(bootstrapPath)) throw new Error('Critical E2E bootstrap file is missing.');
  return JSON.parse(fs.readFileSync(bootstrapPath, 'utf8'));
}

function readback({ workOrderId, workshopJobId, financial = false } = {}) {
  const run = bootstrap();
  if (run.dbName !== 'erp_elec_new_test' || Number(run.dbPort) !== 55460) throw new Error('Refusing critical readback outside the isolated automation PostgreSQL database.');
  const result = spawnSync('php', ['-d', 'opcache.enable_cli=0', fixturePath], {
    cwd: root,
    env: { ...process.env, E2E_DB_PORT: '55460', E2E_DB_DATABASE: 'erp_elec_new_test',
      E2E_CLUSTER_MARKER: run.clusterMarker, E2E_CLUSTER_TOKEN: run.clusterToken,
      E2E_APP_STORAGE: run.env?.LARAVEL_STORAGE_PATH || run.storage, E2E_FIXTURE_MODE: 'readback',
      ...(workOrderId ? { E2E_M08_WORK_ORDER_ID: String(workOrderId) } : {}),
      ...(workshopJobId ? { E2E_M09_JOB_ID: String(workshopJobId) } : {}),
      ...(financial ? (() => {
        const f = run.fixtures?.financial;
        if (!f) throw new Error('Financial browser fixture is missing.');
        return { E2E_FINANCIAL_READBACK: '1', E2E_PURCHASE_ORDER_ID: String(f.purchase_order_id),
          E2E_RECEIPT_ID: String(f.receipt_id), E2E_SUPPLIER_INVOICE_ID: String(f.supplier_invoice_id),
          E2E_SALES_ORDER_ID: String(f.sales_order_id), E2E_SALES_LINE_ID: String(f.sale_line_id),
          E2E_CUSTOMER_INVOICE_ID: String(f.customer_invoice_id), E2E_WAREHOUSE_ID: String(f.warehouse_id),
          E2E_STOCK_PRODUCT_ID: String(f.stock_product_id) };
      })() : {}) },
    encoding: 'utf8', windowsHide: true,
  });
  if (result.status !== 0) throw new Error(`M08 PostgreSQL readback failed: ${(result.stderr || result.stdout).slice(-1200)}`);
  return JSON.parse(result.stdout);
}

test('M08 technician execution reaches independently verified closed work order', async ({ browser }) => {
  const run = bootstrap();
  expect(run.dbName).toBe('erp_elec_new_test');
  expect(Number(run.dbPort)).toBe(55460);
  const scenario = run.fixtures;
  expect(scenario?.work_order_id).toBeTruthy();
  expect(scenario?.expected?.status).toBe('work_completed');
  const state = run.actor_states?.reviewer;
  if (!state) throw new Error('Runner did not provide reviewer authentication state.');
  const statePath = path.isAbsolute(state) ? state : path.resolve(root, state);
  const context = await browser.newContext({ baseURL: process.env.E2E_BASE_URL, storageState: statePath, locale: 'ar-EG', timezoneId: 'Africa/Cairo' });
  const page = await context.newPage();
  page.setDefaultTimeout(12_000);
  const pageErrors = [];
  page.on('pageerror', error => pageErrors.push(error.message));
  try {
    const url = `/maintenance/work-orders/${scenario.work_order_id}?tab=completion`;
    const first = await gotoReady(page, url);
    expect(first?.status()).toBe(200);
    expect(await page.locator('html').getAttribute('dir')).toBe('rtl');
    await expect(page.getByRole('button', { name: 'اعتماد التحقق الفني' })).toBeVisible();
    const beforeReview = readback({ workOrderId: scenario.work_order_id });
    const technicianContext = await browser.newContext({ baseURL: process.env.E2E_BASE_URL,
      storageState: actorContextPath(run, 'technician'), locale: 'ar-EG', timezoneId: 'Africa/Cairo' });
    try {
      const technicianPage = await technicianContext.newPage();
      await gotoReady(technicianPage, url);
      await expect(technicianPage.getByRole('button', { name: 'اعتماد التحقق الفني' })).toHaveCount(0);
      await expect(technicianPage.getByRole('button', { name: 'إغلاق أمر العمل' })).toHaveCount(0);
    } finally {
      await technicianContext.close();
    }
    await setModel(page, 'reason', 'المراجع المستقل تحقق من اكتمال أدلة الصيانة.');
    await clickWire(page, "changeState('verify')");
    await expect(page.getByRole('button', { name: 'إغلاق أمر العمل' })).toBeVisible();
    await setModel(page, 'reason', 'أغلق العمل بعد التحقق المستقل وحفظ التقرير.');
    await clickWire(page, "changeState('close')");
    await expect(page.getByText('مغلق', { exact: true })).toBeVisible();

    await page.setViewportSize({ width: 390, height: 844 });
    await page.reload();
    await expect(page.getByText('مغلق', { exact: true })).toBeVisible();
    const dimensions = await page.evaluate(() => ({ viewport: innerWidth, document: document.documentElement.scrollWidth }));
    expect(dimensions.document).toBeLessThanOrEqual(dimensions.viewport + 4);

    const evidence = readback({ workOrderId: scenario.work_order_id });
    expect(evidence.database).toBe('erp_elec_new_test');
    expect(evidence.port).toBe(55460);
    expect(evidence.work_order.status).toBe('closed');
    expect(evidence.work_order.closed_at).toBeTruthy();
    expect(evidence.work_order.completion_count).toBe(1);
    expect(evidence.work_order.visit_count).toBe(1);
    expect(evidence.work_order.labor_count).toBe(1);
    const smokeContext = await browser.newContext({ baseURL: process.env.E2E_BASE_URL,
      storageState: actorContextPath(run, 'smoke'), locale: 'ar-EG', timezoneId: 'Africa/Cairo' });
    try {
      const deniedReport = await smokeContext.request.get(`/maintenance/work-orders/${scenario.work_order_id}/reports/${evidence.work_order.completion_id}`);
      expect(deniedReport.status()).toBe(403);
    } finally {
      await smokeContext.close();
    }
    const reportResponse = await page.request.get(`/maintenance/work-orders/${scenario.work_order_id}/reports/${evidence.work_order.completion_id}`);
    const reportBytes = await reportResponse.body();
    expect(reportResponse.status()).toBe(200);
    expect((reportResponse.headers()['content-type'] || '')).toContain('application/pdf');
    expect((reportResponse.headers()['cache-control'] || '').toLowerCase()).toContain('private');
    expect((reportResponse.headers()['cache-control'] || '').toLowerCase()).toContain('no-store');
    expect(reportBytes.subarray(0, 5).toString('ascii')).toBe('%PDF-');
    expect(crypto.createHash('sha256').update(reportBytes).digest('hex')).toBe(evidence.work_order.document_sha256);
    expect(pageErrors).toEqual([]);
  } finally {
    await context.close();
  }
});

function actorContextPath(run, key) {
  const state = run.actor_states?.[key];
  if (!state) throw new Error(`Runner did not provide ${key} authentication state.`);
  return path.isAbsolute(state) ? state : path.resolve(root, state);
}

async function gotoReady(page, url) {
  const response = await page.goto(url, { waitUntil: 'domcontentloaded' });
  await page.locator('main, [wire\\:id]').first().waitFor({ state: 'visible' });
  return response;
}

async function setModel(page, key, value) {
  const target = page.locator(`[wire\\:model="${key}"], [wire\\:model\\.live="${key}"], [wire\\:model\\.live\\.debounce\\.300ms="${key}"]`).first();
  await target.waitFor({ state: 'visible' });
  const tag = await target.evaluate(el => el.tagName.toLowerCase());
  if (tag === 'select') await target.selectOption(String(value));
  else await target.fill(String(value));
}

async function awaitLivewire(page, action) {
  const response = page.waitForResponse(response => /\/livewire(?:-[^/]+)?\/update/.test(response.url()), { timeout: 15_000 });
  await action();
  const result = await response;
  expect(result.status()).toBe(200);
  const errors = await page.locator('[role="alert"].bg-red-50, [role="alert"].text-red-700, [role="alert"].text-red-800')
    .evaluateAll(nodes => nodes.map(node => ({ text: node.innerText, className: node.className })));
  expect(errors, `Livewire action validation errors: ${JSON.stringify(errors)}`).toEqual([]);
  return result;
}

async function measureLivewire(page, operation, action, assertion, uiAssertion) {
  const start = { at: new Date().toISOString(), monotonic_ms: performance.now() };
  const response = await awaitLivewire(page, action);
  await assertion();
  const end = { at: new Date().toISOString(), monotonic_ms: performance.now() };
  operationTimings.push({ operation, started_at: start.at, ended_at: end.at,
    elapsed_ms: Math.round((end.monotonic_ms - start.monotonic_ms) * 100) / 100,
    livewire_http_status: response.status(), ui_assertion: uiAssertion });
}

function saveOperationTimings(run) {
  const directory = path.join(root, '.tmp/optimization-regression');
  fs.mkdirSync(directory, { recursive: true });
  fs.writeFileSync(path.join(directory, 'livewire-operation-latencies-55460.json'), JSON.stringify({
    status: operationTimings.length === 5 ? 'passed' : 'incomplete',
    database: 'erp_elec_new_test@55460', run_id: run.runToken || null,
    timings_include_ui_assertion: true, operations: operationTimings,
  }, null, 2) + '\n');
}

async function submit(page, method) {
  const form = page.locator(`form[wire\\:submit="${method}"]`).first();
  await form.waitFor({ state: 'visible' });
  await awaitLivewire(page, () => form.locator('button[type="submit"]').click());
}

async function clickWire(page, expression) {
  await awaitLivewire(page, () => page.locator(`[wire\\:click="${expression}"]`).first().click());
}

function cairoDateTime(minutesOffset = 0) {
  const parts = new Intl.DateTimeFormat('en-CA', { timeZone: 'Africa/Cairo', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' })
    .formatToParts(new Date(Date.now() + minutesOffset * 60 * 1000)).reduce((values, item) => ({ ...values, [item.type]: item.value }), {});
  return `${parts.year}-${parts.month}-${parts.day}T${parts.hour}:${parts.minute}`;
}

test('M09 customer-owned nonbillable repair requires independent goodwill authority and preserves custody through delivery', async ({ browser }) => {
  test.setTimeout(90_000);
  const run = bootstrap();
  expect(run.dbName).toBe('erp_elec_new_test');
  expect(Number(run.dbPort)).toBe(55460);
  const scenario = run.fixtures?.workshop;
  expect(scenario?.job_id).toBeTruthy();
  const contextOptions = { baseURL: process.env.E2E_BASE_URL, locale: 'ar-EG', timezoneId: 'Africa/Cairo' };
  const dispatchContext = await browser.newContext({ ...contextOptions, storageState: actorContextPath(run, 'dispatcher') });
  const techContext = await browser.newContext({ ...contextOptions, storageState: actorContextPath(run, 'technician') });
  const reviewContext = await browser.newContext({ ...contextOptions, storageState: actorContextPath(run, 'reviewer') });
  const dispatcher = await dispatchContext.newPage();
  const technician = await techContext.newPage();
  const reviewer = await reviewContext.newPage();
  for (const page of [dispatcher, technician, reviewer]) page.setDefaultTimeout(12_000);
  const jobUrl = `/workshops/jobs/${scenario.job_id}`;
  try {
    const opened = await gotoReady(dispatcher, `${jobUrl}?tab=estimate`);
    expect(opened?.status()).toBe(200);
    await expect(dispatcher.locator('form[wire\\:submit="authorizeNonbillable"]')).toHaveCount(0);
    await gotoReady(reviewer, `${jobUrl}?tab=estimate`);
    const authorizationForm = reviewer.locator('form[wire\\:submit="authorizeNonbillable"]');
    await expect(authorizationForm).toBeVisible();
    await setModel(reviewer, 'nonbillableKind', 'goodwill');
    await setModel(reviewer, 'approvalEvidence', 'اعتماد مجاملة تجريبية مستقل لهذا السيناريو فقط.');
    await submit(reviewer, 'authorizeNonbillable');
    let read = readback({ workshopJobId: scenario.job_id }).workshop_job;
    expect(read.status).toBe('approved');
    expect(read.nonbillable_authorization_count).toBe(1);
    expect(read.quote_count).toBe(0);
    expect(read.invoice_count).toBe(0);

    await gotoReady(technician, `${jobUrl}?tab=work`);
    await setModel(technician, 'reason', 'بدء الإصلاح بعد اعتماد العمل غير المفوتر.');
    await clickWire(technician, "state('start')");
    await setModel(technician, 'record.started_at', cairoDateTime(-4));
    await setModel(technician, 'record.ended_at', cairoDateTime(-3));
    await setModel(technician, 'record.work_type', 'فحص وإصلاح');
    await setModel(technician, 'record.work_performed', 'تم إحكام التوصيلة وفحص التشغيل.');
    await setModel(technician, 'record.observations', 'لا توجد قطع مخزون مستخدمة في هذا السيناريو.');
    await submit(technician, 'recordWork');
    read = readback({ workshopJobId: scenario.job_id }).workshop_job;
    expect(read.work_record_count).toBe(1);
    await setModel(technician, 'reason', 'اكتمل الإصلاح وجاهز للإحالة للاختبار.');
    await clickWire(technician, "state('testing')");
    await gotoReady(technician, `${jobUrl}?tab=testing`);
    await setModel(technician, 'test.name', 'اختبار التشغيل');
    await setModel(technician, 'test.outcome', 'passed');
    await setModel(technician, 'test.results', 'اجتاز القاطع اختبار التشغيل التجريبي.');
    await submit(technician, 'recordTest');
    read = readback({ workshopJobId: scenario.job_id }).workshop_job;
    expect(read.test_count).toBe(1);

    await gotoReady(reviewer, `${jobUrl}?tab=testing`);
    await setModel(reviewer, 'finalCondition', 'القاطع يعمل واجتاز الاختبار المسجل.');
    await setModel(reviewer, 'remainingFaults', 'لا توجد أعطال متبقية وفق الاختبار.');
    await submit(reviewer, 'verify');
    read = readback({ workshopJobId: scenario.job_id }).workshop_job;
    expect(read.status).toBe('verified');

    await gotoReady(dispatcher, `${jobUrl}?tab=delivery`);
    await setModel(dispatcher, 'delivery.recipient', 'مندوب العميل التجريبي');
    await setModel(dispatcher, 'delivery.accessories', 'مفتاح التشغيل فقط');
    await setModel(dispatcher, 'delivery.final_condition', 'القاطع يعمل واجتاز الاختبار.');
    await setModel(dispatcher, 'delivery.acknowledgment', 'أقر المندوب باستلام الجهاز وملحقاته.');
    await setModel(dispatcher, 'delivery.delivered_at', cairoDateTime(-2));
    const unrepaired = dispatcher.locator('[wire\\:model="delivery.unrepaired"]');
    if (await unrepaired.isChecked()) await unrepaired.uncheck();
    await submit(dispatcher, 'deliver');
    read = readback({ workshopJobId: scenario.job_id }).workshop_job;
    expect(read.status).toBe('delivered');
    expect(read.intake_status).toBe('delivered');
    expect(read.delivery_count).toBe(1);
    expect(read.nonbillable_authorization_count).toBe(1);
    expect(read.quote_count).toBe(0);
    expect(read.invoice_count).toBe(0);
    expect(read.job_inventory_movements).toBe(0);
    expect(read.custody_event_count).toBeGreaterThanOrEqual(3);
    await dispatcher.setViewportSize({ width: 390, height: 844 });
    await dispatcher.reload();
    const dimensions = await dispatcher.evaluate(() => ({ viewport: innerWidth, document: document.documentElement.scrollWidth }));
    expect(dimensions.document).toBeLessThanOrEqual(dimensions.viewport + 4);
  } finally {
    await dispatchContext.close();
    await techContext.close();
    await reviewContext.close();
  }
});

test('M05 receipt posts real FIFO/GRNI, Sales delivery consumes FIFO, and browser posts M12 AP and AR invoices', async ({ browser }) => {
  test.setTimeout(120_000);
  const run = bootstrap();
  expect(run.dbName).toBe('erp_elec_new_test');
  expect(Number(run.dbPort)).toBe(55460);
  const f = run.fixtures?.financial;
  expect(f?.supplier_invoice_id).toBeTruthy();
  expect(f?.customer_invoice_id).toBeTruthy();
  const context = await browser.newContext({ baseURL: process.env.E2E_BASE_URL,
    storageState: actorContextPath(run, 'dispatcher'), locale: 'ar-EG', timezoneId: 'Africa/Cairo' });
  const page = await context.newPage();
  page.setDefaultTimeout(12_000);
  page.on('dialog', dialog => dialog.accept());
  const browserErrors = [];
  page.on('pageerror', error => browserErrors.push(error.message));
  page.on('response', response => { if (response.status() >= 500) browserErrors.push(`HTTP ${response.status()}`); });
  try {
    const sourceEvidence = readback({ financial: true }).financial;
    expect(sourceEvidence.receipt.status).toBe('posted');
    expect(sourceEvidence.purchase_receipt_journal.status).toBe('posted');
    expect(sourceEvidence.inventory.receipt_fifo_layer.original_quantity).toBe('4.00000000');
    expect(sourceEvidence.supplier_invoice.status).toBe('draft');
    expect(sourceEvidence.customer_invoice.status).toBe('draft');

    for (const [url, expected] of [
      [`/purchasing/orders/${f.purchase_order_id}`, f.purchase_order_id],
      [`/purchasing/receipts/${f.receipt_id}`, f.receipt_id],
      ['/inventory/balances', f.stock_product_sku],
      ['/accounting/journals', String(f.purchase_journal_id)],
    ]) {
      const response = await gotoReady(page, url);
      expect(response?.status(), `${url} HTTP status`).toBe(200);
      await expect(page.locator('body')).toContainText(String(expected));
    }

    await gotoReady(page, '/purchasing/orders/create');
    await setModel(page, 'form.branch_id', String(f.branch_id));
    await setModel(page, 'form.supplier_id', String(f.supplier_id));
    await page.getByRole('button', { name: 'إضافة صنف' }).click();
    await setModel(page, 'form.lines.0.product_id', String(f.stock_product_id));
    await setModel(page, 'form.lines.0.quantity', '1.00000000');
    await setModel(page, 'form.lines.0.unit_price', '10.00000000');
    await setModel(page, 'form.lines.0.discount_amount', '0.00000000');
    await measureLivewire(page, 'save-draft',
      () => page.locator('form[wire\\:submit="save"] button[type="submit"]').click(),
      async () => {
        await expect(page).toHaveURL(/\/purchasing\/orders\/\d+$/);
        await expect(page.locator('body')).toContainText('الحالة: مسودة');
      }, 'saved PO route and visible draft status');
    const rejectedOrderUrl = new URL(page.url()).pathname;
    await awaitLivewire(page, () => page.getByRole('button', { name: 'إرسال للاعتماد' }).click());
    await expect(page.locator('body')).toContainText('الحالة: بانتظار الاعتماد');
    const reviewerContext = await browser.newContext({ baseURL: process.env.E2E_BASE_URL,
      storageState: actorContextPath(run, 'reviewer'), locale: 'ar-EG', timezoneId: 'Africa/Cairo' });
    const reviewerPage = await reviewerContext.newPage();
    reviewerPage.setDefaultTimeout(12_000);
    try {
      await gotoReady(reviewerPage, rejectedOrderUrl);
      await expect(reviewerPage.getByRole('button', { name: 'رفض', exact: true })).toBeVisible();
      await setModel(reviewerPage, 'reason', 'رفض مستقل ضمن رحلة القبول الاصطناعية.');
      await measureLivewire(reviewerPage, 'denial',
        () => reviewerPage.getByRole('button', { name: 'رفض', exact: true }).click(),
        async () => await expect(reviewerPage.locator('body')).toContainText('الحالة: مرفوض'),
        'independent reviewer rejection and visible rejected status');
    } finally {
      await reviewerContext.close();
    }

    const supplierList = await gotoReady(page, '/financial/supplier-invoices');
    expect(supplierList?.status()).toBe(200);
    const supplierRow = () => page.locator('tr').filter({ hasText: f.supplier_invoice_number }).first();
    await expect(supplierRow()).toBeVisible();
    await measureLivewire(page, 'validation',
      () => supplierRow().getByRole('button', { name: 'تحقق' }).click(),
      async () => await expect(supplierRow().getByRole('button', { name: 'مطابقة' })).toBeVisible(),
      'validated supplier invoice exposes the next matching action');
    expect(readback({ financial: true }).financial.supplier_invoice.status).toBe('validated');
    await awaitLivewire(page, () => supplierRow().getByRole('button', { name: 'مطابقة' }).click());
    expect(readback({ financial: true }).financial.supplier_invoice.status).toBe('matched');
    await measureLivewire(page, 'approval',
      () => supplierRow().getByRole('button', { name: 'اعتماد' }).click(),
      async () => await expect(supplierRow().getByRole('button', { name: 'ترحيل' })).toBeVisible(),
      'approved supplier invoice exposes the posting action');
    expect(readback({ financial: true }).financial.supplier_invoice.status).toBe('approved');
    await awaitLivewire(page, () => supplierRow().getByRole('button', { name: 'ترحيل' }).click());
    let evidence = readback({ financial: true }).financial;
    expect(evidence.supplier_invoice.status).toBe('posted');
    expect(evidence.supplier_invoice.net).toBe('40.00');
    expect(evidence.supplier_invoice.tax).toBe('5.60');
    expect(evidence.supplier_invoice.total).toBe('45.60');
    expect(evidence.ap).toEqual({ original: '45.60', remaining: '45.60' });
    expect(evidence.supplier_invoice.journal.status).toBe('posted');
    expect(evidence.supplier_invoice.journal.debit).toBe(evidence.supplier_invoice.journal.credit);

    await gotoReady(page, '/financial/customer-invoices');
    const customerRow = () => page.locator('tr').filter({ hasText: f.customer_name }).first();
    await expect(customerRow()).toBeVisible();
    await awaitLivewire(page, () => customerRow().getByRole('button', { name: 'تحقق' }).click());
    expect(readback({ financial: true }).financial.customer_invoice.status).toBe('validated');
    await awaitLivewire(page, () => customerRow().getByRole('button', { name: 'إصدار وترحيل' }).click());
    evidence = readback({ financial: true }).financial;
    expect(evidence.customer_invoice.status).toBe('posted');
    expect(evidence.customer_invoice.net).toBe('48.00');
    expect(evidence.customer_invoice.tax).toBe('6.72');
    expect(evidence.customer_invoice.total).toBe('54.72');
    expect(evidence.ar).toEqual({ original: '54.72', remaining: '54.72' });
    expect(evidence.customer_invoice.journal.status).toBe('posted');
    expect(evidence.customer_invoice.journal.debit).toBe(evidence.customer_invoice.journal.credit);
    expect(evidence.purchase_receipt_journal.debit).toBe(evidence.purchase_receipt_journal.credit);
    expect(evidence.sale_movement.journal.status).toBe('posted');
    expect(evidence.sale_movement.journal.debit).toBe(evidence.sale_movement.journal.credit);
    expect(evidence.sale_movement.quantity).toBe('-4.00000000');
    expect(evidence.inventory.on_hand).toBe('0.00000000');
    expect(evidence.inventory.receipt_fifo_layer.remaining_quantity).toBe('0.00000000');
    expect(evidence.inventory.receipt_fifo_layer.remaining_value).toBe('0.0000000000000000');
    expect(evidence.inventory.sale_fifo_allocation).toEqual({ quantity: '4.00000000', amount: '40.00' });

    await gotoReady(page, `/financial/customer-invoices/${f.customer_invoice_id}`);
    await expect(page.getByRole('link', { name: 'PDF الرسمي' })).toBeVisible();
    const exportStart = { at: new Date().toISOString(), monotonic_ms: performance.now() };
    const pdfDownloadPromise = page.waitForEvent('download');
    await page.getByRole('link', { name: 'PDF الرسمي' }).click();
    const pdfDownload = await pdfDownloadPromise;
    const pdfBytes = fs.readFileSync(await pdfDownload.path());
    const exportEnd = { at: new Date().toISOString(), monotonic_ms: performance.now() };
    expect(await pdfDownload.failure()).toBeNull();
    expect(pdfBytes.subarray(0, 5).toString('ascii')).toBe('%PDF-');
    expect(crypto.createHash('sha256').update(pdfBytes).digest('hex')).toBe(evidence.customer_invoice.document_sha256);
    operationTimings.push({ operation: 'export', started_at: exportStart.at, ended_at: exportEnd.at,
      elapsed_ms: Math.round((exportEnd.monotonic_ms - exportStart.monotonic_ms) * 100) / 100,
      download: 'passed', ui_assertion: 'PDF link yielded the matching saved private PDF' });

    await page.setViewportSize({ width: 390, height: 844 });
    await page.reload();
    const dimensions = await page.evaluate(() => ({ viewport: innerWidth, document: document.documentElement.scrollWidth }));
    expect(dimensions.document).toBeLessThanOrEqual(dimensions.viewport + 4);

    const outsiderContext = await browser.newContext({ baseURL: process.env.E2E_BASE_URL,
      storageState: actorContextPath(run, 'outsider'), locale: 'ar-EG', timezoneId: 'Africa/Cairo' });
    try {
      const deniedPdf = await outsiderContext.request.get(`/financial/documents/customer-invoice/${f.customer_invoice_id}`);
      const deniedM09 = run.fixtures.workshop?.job_id
        ? await outsiderContext.request.get(`/workshops/jobs/${run.fixtures.workshop.job_id}`) : null;
      expect(deniedPdf.status()).toBe(403);
      if (deniedM09) expect(deniedM09.status()).toBe(403);
    } finally {
      await outsiderContext.close();
    }
    expect(browserErrors).toEqual([]);
  } finally {
    saveOperationTimings(run);
    await context.close();
  }
});
