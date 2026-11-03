'use strict';

const fs = require('node:fs');
const path = require('node:path');
const net = require('node:net');
const crypto = require('node:crypto');
const { spawn, spawnSync } = require('node:child_process');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '../..');
const reportDir = path.join(root, 'docs', 'performance');
const workDir = path.join(root, '.tmp', 'page-speed-audit');
const checkpointPath = path.join(workDir, 'progress.json');
const resumeProgress = process.argv.includes('--resume') && fs.existsSync(checkpointPath)
  ? JSON.parse(fs.readFileSync(checkpointPath, 'utf8')) : null;
const bootstrapPath = path.join(workDir, 'bootstrap.json');
const routeSnapshot = path.join(workDir, 'audit-routes.json');
const storageState = path.join(workDir, 'audit-storage-state.json');
const seedStatusPath = path.join(workDir, 'seed-status.json');
const currentSamplePath = path.join(workDir, 'current-sample.json');
const runId = resumeProgress?.run_id || `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 8)}`;
const seedReport = [];
// Exclude transport, auth and file responses by route identity. A route named
// *.documents can still be an ordinary, user-facing page and must be measured.
const routeExclusions = /^(?:login|logout|register|password\.(?:reset|update|email|confirmation)|verification\.|two-factor\.|passkey\.|well-known\.|livewire\.|storage\.)|(?:\.download$|\.export$|\.exports$|\.pdf$|\.official$|\.official-pdf$|\.acceptance-evidence$|\.offer-file$|\.media\.download$)|^(?:customers|suppliers|quotations|maintenance|tenders|workshops)\.documents$|^(?:tenders\.document|maintenance\.report)$|^(?:financial\.documents|tenders\.bid)$|^(?:products\.media|organization\.company\.logo)$|(?:^api\.|^__flux\.)/i;
const nonPageActions = /(PdfController|ExportController|DownloadController|OfficialController|ReportController)$/;

function run(executable, args, options = {}) {
  const result = spawnSync(executable, args, {
    cwd: root,
    encoding: 'utf8',
    windowsHide: true,
    maxBuffer: 16 * 1024 * 1024,
    ...options,
  });
  if (result.error) throw result.error;
  return result;
}

function sourceFingerprint() {
  const hash = crypto.createHash('sha256');
  const files = [];
  for (const folder of ['app', 'routes', 'resources/views', 'resources/css', 'resources/js']) {
    const start = path.join(root, folder);
    if (!fs.existsSync(start)) continue;
    const pending = [start];
    while (pending.length) {
      const dir = pending.pop();
      for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
        const file = path.join(dir, entry.name);
        if (entry.isDirectory()) pending.push(file);
        else files.push(file);
      }
    }
  }
  files.sort();
  for (const file of files) hash.update(path.relative(root, file)).update(fs.readFileSync(file));
  hash.update(fs.readFileSync(path.join(root, 'composer.lock')));
  return hash.digest('hex');
}

function php(args, env, options = {}) {
  return run('php', args, { env, ...options });
}

async function freePort() {
  return new Promise((resolve, reject) => {
    const server = net.createServer();
    server.once('error', reject);
    server.listen(0, '127.0.0.1', () => {
      const port = server.address().port;
      server.close(() => resolve(port));
    });
  });
}

async function waitForServer(baseUrl, timeoutMs = 20_000) {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    try {
      const response = await fetch(`${baseUrl}/login`);
      if (response.status >= 200 && response.status < 500) return;
    } catch {}
    await new Promise(resolve => setTimeout(resolve, 200));
  }
  throw new Error(`Laravel server did not become ready at ${baseUrl}.`);
}

async function measureSpaTransition(browser, baseUrl, storageState, viewport, route) {
  const base = { route_name: route.name, route_variant: route.variant || 'default', module: route.module, uri: route.uri, url: route.url,
    viewport: viewport.name, temperature: 'transition', navigation_mode: 'wire:navigate' };
  const results = [];
  const shellLinks = new Set(['customers.index','suppliers.index','products.index','organization.company','organization.users','sales-orders.index']);
  if (!shellLinks.has(route.name)) return [{ ...base, trial: 0, measured: false, transition_status: 'unavailable', transition_ms: null,
    transition_error: 'No direct dashboard wire:navigate link is defined for this route case; SPA transition was not attempted.' }];
  for (let trial = 1; trial <= 3; trial++) {
    const context = await browser.newContext({ baseURL: baseUrl, storageState, locale: 'ar-EG', timezoneId: 'Africa/Cairo', viewport });
    const page = await context.newPage();
    const result = { ...base, trial, measured: false, transition_status: 'unavailable', transition_ms: null, transition_error: null };
    try {
      await page.addInitScript(() => {
        document.addEventListener('livewire:navigating', () => performance.mark('audit:spa-start'));
        document.addEventListener('livewire:navigated', () => performance.mark('audit:spa-done'));
      });
      await page.goto(`${baseUrl}/dashboard`, { waitUntil: 'domcontentloaded', timeout: 30_000 });
      await page.locator('#main-content h1, #main-content [role="heading"]').first().waitFor({ state: 'visible', timeout: 8_000 });
      const target = new URL(route.url, baseUrl).pathname;
      if (target === '/dashboard') {
        results.push({ ...result, trial: 1, transition_error: 'Source and target are the same route.' });
        break;
      }
      const link = page.locator(`a[wire\\:navigate][href="${target}"]`).first();
      if (await link.count() && !(await link.isVisible().catch(() => false))) {
        const drawer = page.locator('.erp-sidebar button[aria-label="فتح القائمة"]').first();
        if (await drawer.isVisible().catch(() => false)) await drawer.click({ timeout: 750 }).catch(() => {});
        for (let attempt = 0; attempt < 16 && !(await link.isVisible().catch(() => false)); attempt++) {
          const collapsed = page.locator('.erp-sidebar button[aria-expanded="false"]').first();
          if (!(await collapsed.count()) || !(await collapsed.isVisible().catch(() => false))) break;
          await collapsed.click({ timeout: 750 }).catch(() => {});
        }
      }
      if (!(await link.count()) || !(await link.isVisible().catch(() => false))) {
        results.push({ ...result, trial: 1, transition_error: 'No visible matching wire:navigate link exists in the rendered dashboard.' });
        break;
      }
      let destinationStatus = null;
      page.on('response', response => { if (new URL(response.url()).pathname === target) destinationStatus = response.status(); });
      const origin = await page.evaluate(() => performance.timeOrigin);
      await page.evaluate(() => performance.mark('audit:spa-click'));
      await Promise.all([page.waitForURL(url => url.pathname === target, { timeout: 12_000 }), link.click({ timeout: 5_000 })]);
      await page.locator('#main-content h1, #main-content [role="heading"]').first().waitFor({ state: 'visible', timeout: 8_000 });
      await page.waitForFunction(() => performance.getEntriesByName('audit:spa-done').length > 0, null, { timeout: 8_000 }).catch(() => {});
      const details = await page.evaluate(origin => ({
        same_document: performance.timeOrigin === origin,
        start: performance.getEntriesByName('audit:spa-start').at(-1)?.startTime ?? null,
        done: performance.getEntriesByName('audit:spa-done').at(-1)?.startTime ?? null,
        elapsed: performance.getEntriesByName('audit:spa-done').at(-1)?.startTime - performance.getEntriesByName('audit:spa-click').at(-1)?.startTime,
      }), origin);
      const spa = details.same_document && details.start !== null && details.done !== null && destinationStatus === 200;
      results.push({ ...result, http_status: destinationStatus, measured: spa, transition_status: spa ? 'passed' : details.same_document ? 'livewire-lifecycle-unavailable' : 'full-document-fallback', transition_ms: spa ? Number(details.elapsed.toFixed(2)) : null,
        transition_error: spa ? null : destinationStatus !== 200 ? `Destination HTTP ${destinationStatus ?? 'unknown'}.` : 'Full-document navigation or missing Livewire navigation lifecycle events.' });
    } catch (error) {
      results.push({ ...result, transition_error: String(error.message || error).slice(0, 300) });
    } finally { await context.close(); }
  }
  return results;
}

function parseLastJson(text) {
  const lines = String(text || '').trim().split(/\r?\n/).filter(Boolean);
  for (let i = lines.length - 1; i >= 0; i--) {
    try { return JSON.parse(lines[i]); } catch {}
  }
  return null;
}

function browserExecutable() {
  const candidates = [
    process.env.PLAYWRIGHT_EXECUTABLE_PATH,
    'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
    'C:/Program Files/Microsoft/Edge/Application/msedge.exe',
  ].filter(Boolean);
  const found = candidates.find(candidate => fs.existsSync(candidate));
  if (!found) throw new Error('No installed Edge executable found for the performance audit.');
  return found;
}

function seededEnv(baseEnv, baseUrl, actorId) {
  return {
    ...baseEnv,
    APP_URL: baseUrl,
    APP_ENV: 'testing',
    E2E_APP_STORAGE: baseEnv.E2E_APP_STORAGE,
    AUDIT_ACTOR_ID: String(actorId || ''),
    ERP_DEMO_ACTOR_ID: String(actorId || ''),
    SALES_QUOTATION_DEMO_ACTOR_ID: String(actorId || ''),
    SALES_ORDER_DEMO_ACTOR_ID: String(actorId || ''),
  };
}

function seed(env, className) {
  const result = php(['artisan', 'db:seed', `--class=${className}`, '--force'], env);
  const ok = result.status === 0;
  seedReport.push({ seeder: className, status: ok ? 'passed' : 'failed', error: ok ? null : String(result.stderr || result.stdout || '').slice(0, 1400) });
  fs.writeFileSync(seedStatusPath, JSON.stringify(seedReport, null, 2) + '\n');
  return ok;
}

function tinker(env, code) {
  const result = php(['artisan', 'tinker', '--execute', code], env);
  if (result.status !== 0) throw new Error(String(result.stderr || result.stdout || '').slice(-1600));
  return String(result.stdout || '').trim();
}

function prepareScopes(env, actorId) {
  const code = `$u=${Number(actorId)}; $now=now(); foreach(\\Illuminate\\Support\\Facades\\DB::table('branches')->where('active',true)->pluck('id') as $b){\\Illuminate\\Support\\Facades\\DB::table('user_branch_scopes')->insertOrIgnore(['user_id'=>$u,'branch_id'=>$b,'created_at'=>$now,'updated_at'=>$now]);} foreach(\\Illuminate\\Support\\Facades\\DB::table('inventory_warehouses')->where('active',true)->get(['id','branch_id']) as $w){\\Illuminate\\Support\\Facades\\DB::table('user_warehouse_scopes')->insertOrIgnore(['user_id'=>$u,'warehouse_id'=>$w->id,'branch_id'=>$w->branch_id,'created_at'=>$now,'updated_at'=>$now]);} echo 'ok';`;
  tinker(env, code);
}

function fixtureIds(env) {
  const code = `$f=function($t,$c='id'){return \\Illuminate\\Support\\Facades\\Schema::hasColumn($t,$c)?\\Illuminate\\Support\\Facades\\DB::table($t)->value($c):null;}; $n=function($t){return \\Illuminate\\Support\\Facades\\Schema::hasTable($t)?\\Illuminate\\Support\\Facades\\DB::table($t)->count():null;}; $actor=(int)env('AUDIT_ACTOR_ID'); $o=['branch'=>$f('branches'),'user'=>$actor,'role'=>$f('roles'),'company'=>$f('companies'),'customer'=>\\Illuminate\\Support\\Facades\\DB::table('treasury_movements')->where('kind','customer_collection')->value('party_id')??\\Illuminate\\Support\\Facades\\DB::table('rp_customer_invoices')->value('customer_id')??$f('customers'),'supplier'=>\\Illuminate\\Support\\Facades\\DB::table('rp_supplier_invoices')->where('status','posted')->value('supplier_id')??\\Illuminate\\Support\\Facades\\DB::table('rp_supplier_invoices')->value('supplier_id')??$f('suppliers'),'product'=>\\Illuminate\\Support\\Facades\\DB::table('products')->where('active',true)->where('kind','<>','service')->value('id'),'media'=>$f('product_media'),'warehouse'=>$f('inventory_warehouses'),'project'=>$f('projects'),'revision'=>$f('project_document_revisions'),'quotation'=>$f('sales_quotations'),'sales_order'=>$f('sales_orders'),'purchase_order'=>$f('purchasing_orders'),'requisition'=>$f('purchase_requisitions'),'offer'=>$f('purchasing_offers'),'offer_file'=>$f('purchasing_offer_files'),'rfq'=>$f('purchasing_rfqs'),'receipt'=>$f('purchasing_receipts'),'journal'=>$f('accounting_journals'),'invoice'=>$f('rp_customer_invoices'),'supplier_invoice'=>\\Illuminate\\Support\\Facades\\DB::table('rp_supplier_invoices')->where('status','posted')->value('id')??$f('rp_supplier_invoices'),'credit_note'=>$f('rp_credit_notes'),'movement'=>$f('treasury_movements'),'request'=>$f('maintenance_requests'),'workOrder'=>$f('maintenance_work_orders'),'completion'=>$f('maintenance_completions'),'intake'=>$f('workshop_intakes'),'job'=>$f('workshop_jobs'),'tender'=>$f('project_tenders'),'document'=>$f('project_documents'),'instruction'=>$f('sales_delivery_instructions'),'runId'=>\\Illuminate\\Support\\Facades\\DB::table('data_operation_runs')->where('actor_id',$actor)->value('id'),'inventoryDocuments'=>\\Illuminate\\Support\\Facades\\DB::table('inventory_documents')->select('kind')->selectRaw('min(id) as id')->groupBy('kind')->pluck('id','kind')->all(),'table_counts'=>array_combine(['customers','suppliers','products','projects','sales_quotations','sales_orders','purchasing_orders','purchase_requisitions','purchasing_rfqs','purchasing_offers','purchasing_receipts','rp_customer_invoices','rp_supplier_invoices','inventory_documents','maintenance_requests','maintenance_work_orders','workshop_intakes','workshop_jobs','project_tenders','treasury_movements','data_operation_runs'],array_map($n,['customers','suppliers','products','projects','sales_quotations','sales_orders','purchasing_orders','purchase_requisitions','purchasing_rfqs','purchasing_offers','purchasing_receipts','rp_customer_invoices','rp_supplier_invoices','inventory_documents','maintenance_requests','maintenance_work_orders','workshop_intakes','workshop_jobs','project_tenders','treasury_movements','data_operation_runs']))]; echo json_encode($o);`;
  const raw = tinker(env, code);
  return JSON.parse(raw.match(/\{.*\}/s)?.[0] || '{}');
}

function ensureInventoryPageFixtures(env, actorId, ids) {
  const warehouseId = Number(ids.warehouse || 0);
  const productId = Number(ids.product || 0);
  if (!warehouseId || !productId) return;
  const code = `$actor=${Number(actorId)}; $warehouse=${warehouseId}; $product=${productId}; $docs=\\Illuminate\\Support\\Facades\\DB::table('inventory_documents')->select('kind')->selectRaw('min(id) as id')->groupBy('kind')->pluck('id','kind')->all(); $w=\\Illuminate\\Support\\Facades\\DB::table('inventory_warehouses')->find($warehouse); $dest=\\Illuminate\\Support\\Facades\\DB::table('inventory_warehouses')->where('branch_id',$w->branch_id)->where('id','<>',$warehouse)->where('active',true)->value('id'); foreach(['opening','receipt','issue','transfer','adjustment','count'] as $kind){if(isset($docs[$kind]))continue;if($kind==='transfer'&&!$dest)continue; app(\\App\\Modules\\Inventory\\Application\\InventoryDocuments::class)->save($actor,$kind,['creation_key'=>(string)\\Illuminate\\Support\\Str::uuid(),'warehouse_id'=>$warehouse,'destination_id'=>$kind==='transfer'?(int)$dest:null,'reason'=>'Synthetic isolated performance page fixture; draft only.','lines'=>[['product_id'=>$product,'quantity'=>'1.00000000']]]);} echo 'ok';`;
  tinker(env, code);
}

function routeUrl(route, ids, variant = {}) {
  const uri = String(route.uri || '');
  const params = [...uri.matchAll(/\{([^}:]+)(?::[^}]+)?\}/g)].map(match => match[1]);
  if (!params.length) return `/${uri}`;
  const values = {};
  for (const param of params) {
    let key = param;
    if (param === 'order') key = route.name?.startsWith('sales-orders.') ? 'sales_order' : 'purchase_order';
    if (param === 'invoice' && route.name === 'financial.invoices.show') key = variant.type === 'supplier' ? 'supplier_invoice' : 'invoice';
    if (param === 'record' && route.name === 'financial.documents') key = ({ 'supplier-invoice': 'supplier_invoice', 'customer-invoice': 'invoice', 'credit-note': 'credit_note' })[variant.type];
    if (param === 'runId') key = 'runId';
    if (param === 'document' && route.name === 'inventory.document.show') key = null;
    else if (param === 'document') {
      key = route.name?.startsWith('projects.') ? 'document' : route.name?.startsWith('products.') ? 'media' : 'document';
    }
    if (variant[param] !== undefined) values[param] = variant[param];
    else if (param === 'document' && route.name === 'inventory.document.show') values[param] = ids.inventoryDocuments?.[variant.kind];
    else if (param === 'entity' && route.name === 'operations.import') values[param] = variant.entity || 'products';
    else if (param === 'type' && route.name === 'financial.invoices.show') values[param] = 'customer';
    else if (param === 'kind') values[param] = 'count';
    else if (param === 'type') values[param] = route.name === 'financial.documents' ? 'customer-invoice' : 'supplier';
    else if (param === 'format') values[param] = 'pdf';
    else if (param === 'financial') values[param] = ids.invoice;
    else values[param] = ids[key] ?? ids[param];
    if (values[param] === null || values[param] === undefined || values[param] === '') return null;
  }
  let url = uri;
  for (const param of params) url = url.replace(`{${param}}`, encodeURIComponent(String(values[param])));
  return `/${url}${variant.tab ? `?tab=${encodeURIComponent(variant.tab)}` : ''}`;
}

function routeVariants(route) {
  if (route.name === 'inventory.document.create' || route.name === 'inventory.document.show') return ['opening','receipt','issue','transfer','adjustment','count'].map(kind => ({ kind }));
  if (route.name === 'financial.invoices.show') return ['customer','supplier'].map(type => ({ type }));
  if (route.name === 'customers.show' || route.name === 'suppliers.show') return [{}, { tab: 'financial' }];
  if (route.name === 'operations.import') return ['products','customers','suppliers'].map(entity => ({ entity }));
  if (route.name === 'tenders.show') return [{}, { tab: 'financial' }];
  if (route.name === 'sales-orders.screen') return ['review','amendment','delivery','invoices','closure'].map(screen => ({ screen }));
  if (route.name === 'financial.documents') return ['supplier-invoice','customer-invoice','credit-note'].map(type => ({ type }));
  return [{}];
}

function hasAuthenticatedPageMiddleware(middleware) {
  return Array.isArray(middleware) && middleware.some(item => {
    const value = String(item || '');
    return value === 'auth' || value.startsWith('auth:') || /\\Authenticate(?::|$)/i.test(value);
  });
}

function isPageRoute(route) {
  const method = String(route.method || '');
  if (!method.includes('GET')) return false;
  if (!route.name || routeExclusions.test(String(route.name))) return false;
  if (nonPageActions.test(String(route.action || ''))) return false;
  return hasAuthenticatedPageMiddleware(route.middleware);
}

function exclusionReason(route) {
  if (!String(route.method || '').includes('GET')) return null;
  if (!route.name) return 'unnamed GET route';
  if (routeExclusions.test(String(route.name))) return 'authentication, transport, download, export, PDF, or file response route';
  if (nonPageActions.test(String(route.action || ''))) return 'PDF, export, download, official document, or report response controller';
  if (!hasAuthenticatedPageMiddleware(route.middleware)) return 'route has no authenticated-page middleware';
  return 'not an authenticated named GET page';
}

function moduleName(route) {
  const name = String(route.name || '');
  const uri = String(route.uri || '');
  const key = name.split('.')[0] || uri.split('/')[0];
  return ({
    organization: 'M01 Organization', customers: 'M03 Parties', suppliers: 'M03 Parties', products: 'M02 Products',
    'sales-orders': 'M04 Sales', quotations: 'M04 Sales', 'sales-returns': 'M04 Sales', purchasing: 'M05 Purchasing',
    inventory: 'M06 Inventory', projects: 'M07 Projects', maintenance: 'M08 FieldMaintenance', workshops: 'M09 WorkshopRepairs',
    tenders: 'M07 Projects (Tenders)', accounting: 'M11 Accounting', financial: 'M12 ReceivablesPayables', treasury: 'M13 Treasury',
    settings: 'M01 Organization', profile: 'M01 Organization', security: 'M01 Organization', appearance: 'M01 Organization', dashboard: 'M17 System', operations: 'M17 System',
  })[key] || key || 'Other';
}

function percentile(values, p) {
  const sorted = values.filter(Number.isFinite).sort((a, b) => a - b);
  if (!sorted.length) return null;
  const index = Math.min(sorted.length - 1, Math.ceil((p / 100) * sorted.length) - 1);
  return Number(sorted[index].toFixed(2));
}

function median(values) {
  const sorted = values.filter(Number.isFinite).sort((a, b) => a - b);
  if (!sorted.length) return null;
  const middle = Math.floor(sorted.length / 2);
  return Number((sorted.length % 2 ? sorted[middle] : (sorted[middle - 1] + sorted[middle]) / 2).toFixed(2));
}

function classifyBottleneck(record, routeStats) {
  if (!record.measured) return 'navigation or readiness failure';
  if (record.ttfb_ms > (routeStats.ttfb_p75 || Infinity)) {
    const dbMs = record.query_profile?.database_time_ms || 0;
    return dbMs >= record.ttfb_ms * 0.5 ? 'database query time is a major TTFB share' : 'server-side response/rendering';
  }
  if (record.total_requests > (routeStats.requests_p75 || Infinity)) return 'request fan-out or Livewire/assets';
  if (record.transferred_bytes > (routeStats.bytes_p75 || Infinity)) return 'transferred asset or HTML payload';
  if (record.ui_ready_ms > (routeStats.ui_p75 || Infinity)) return 'server-side page rendering or primary component work';
  return 'no outlier signal in measured baseline';
}

function queryDiagnostics(records) {
  const totals = { auth: { repeated_executions: 0, repeated_time_ms: 0 }, data: { repeated_executions: 0, repeated_time_ms: 0 }, db_time_ms: 0, requests_with_profile: 0 };
  const byPage = new Map();
  for (const row of records.filter(item => item.measured && item.temperature === 'diagnostic' && item.query_profile)) {
    const profile = row.query_profile;
    totals.db_time_ms += profile.database_time_ms || 0;
    totals.requests_with_profile++;
    const key = `${row.route_name}|${row.route_variant || 'default'}|${row.viewport}`;
    const page = byPage.get(key) || { route_name: row.route_name, route_variant: row.route_variant || 'default', viewport: row.viewport, db_time_ms: 0, requests: 0, auth_repeats: 0, data_repeats: 0, data_repeat_time_ms: 0, repeated_sql: [] };
    page.db_time_ms += profile.database_time_ms || 0;
    page.requests++;
    for (const query of profile.repeated_queries || []) {
      const auth = /\b(users|user_roles|roles|access_grants|user_branch_scopes|user_warehouse_scopes|permission_catalog)\b/i.test(query.sql);
      const category = auth ? totals.auth : totals.data;
      category.repeated_executions += query.count;
      category.repeated_time_ms += query.total_ms;
      if (auth) page.auth_repeats += query.count;
      else {
        page.data_repeats += query.count;
        page.data_repeat_time_ms += query.total_ms;
        page.repeated_sql.push({ sql: query.sql, count: query.count, total_ms: Number(query.total_ms.toFixed(2)) });
      }
    }
    byPage.set(key, page);
  }
  const pages = [...byPage.values()].map(page => ({ ...page, db_time_ms: Number(page.db_time_ms.toFixed(2)), data_repeat_time_ms: Number(page.data_repeat_time_ms.toFixed(2)),
    repeated_sql: page.repeated_sql.sort((a, b) => b.count - a.count).slice(0, 5) }));
  const share = value => totals.db_time_ms ? Number((100 * value / totals.db_time_ms).toFixed(2)) : 0;
  totals.db_time_ms = Number(totals.db_time_ms.toFixed(2));
  for (const bucket of [totals.auth, totals.data]) { bucket.repeated_time_ms = Number(bucket.repeated_time_ms.toFixed(2)); bucket.db_time_share_percent = share(bucket.repeated_time_ms); }
  return { ...totals, pages_by_db_time: pages.sort((a, b) => b.db_time_ms - a.db_time_ms).slice(0, 20),
    interpretation: 'Repeated auth SQL is separated from repeated business/data SQL. Repetition is a profiling signal, not proof of an N+1 defect; inspect repeated data SQL candidates in their page context.' };
}

function pageCaseSummaries(summaries) {
  const byCase = new Map();
  for (const row of summaries.filter(item => item.temperature === 'warm')) {
    const key = `${row.route_name}|${row.route_variant}`;
    const item = byCase.get(key) || { route_name: row.route_name, route_variant: row.route_variant, module: row.module, viewports: {} };
    item.viewports[row.viewport] = row;
    byCase.set(key, item);
  }
  return [...byCase.values()].map(item => {
    const desktop = item.viewports.desktop;
    const mobile = item.viewports.mobile;
    const ranking = Math.max(desktop?.median_ui_ready_ms ?? 0, mobile?.median_ui_ready_ms ?? 0);
    const bottleneck = [desktop, mobile].filter(Boolean).sort((a, b) => (b.median_ui_ready_ms ?? 0) - (a.median_ui_ready_ms ?? 0))[0]?.likely_bottleneck || 'no measured signal';
    return { ...item, desktop_ui_ready_ms: desktop?.median_ui_ready_ms ?? null, mobile_ui_ready_ms: mobile?.median_ui_ready_ms ?? null,
      max_viewport_ui_ready_ms: ranking || null, desktop_ttfb_ms: desktop?.median_ttfb_ms ?? null, mobile_ttfb_ms: mobile?.median_ttfb_ms ?? null,
      desktop_requests: desktop?.median_requests ?? null, mobile_requests: mobile?.median_requests ?? null, likely_bottleneck: bottleneck,
      optimization_priority: bottleneck.includes('database') ? 'Inspect repeated data SQL and slow queries for this route before changing code.'
        : bottleneck.includes('request fan-out') ? 'Inspect Livewire update count and asset request fan-out.'
          : bottleneck.includes('payload') ? 'Inspect HTML and asset response sizes.'
            : bottleneck.includes('server-side') ? 'Profile PHP rendering and repeated authorization/catalog queries; preserve permission guards.'
              : 'No outlier-based optimization target identified.' };
  });
}

async function measurePage(page, baseUrl, route, viewport, temperature, trial, profileQueries = false) {
  const networkFailures = [];
  const jsErrors = [];
  const serverErrors = [];
  const httpErrors = [];
  const consoleErrors = [];
  const responseSizePromises = [];
  let requestCount = 0;
  let transferredBytes = 0;
  let livewireRequests = 0;
  const requestId = `${runId}-${viewport.name}-${route.name}-${temperature}-${trial}`.replace(/[^a-zA-Z0-9._-]/g, '_');
  if (profileQueries) await page.setExtraHTTPHeaders({ 'X-Audit-Request-ID': requestId });
  await page.addInitScript(({ needsPartyFinancial, needsPasswordConfirm }) => {
    document.addEventListener('livewire:init', () => { window.__auditLivewireReady = true; });
    document.addEventListener('livewire:navigating', () => performance.mark('audit:spa-start'));
    document.addEventListener('livewire:navigated', () => performance.mark('audit:spa-done'));
    let readinessObserver;
    const check = () => {
      if (performance.getEntriesByName('audit:primary-visible-auto').length > 0) {
        readinessObserver?.disconnect();
        return;
      }
      const root = document.querySelector('#main-content');
      if (needsPasswordConfirm) {
        const passwordInput = document.querySelector('input[name="password"]');
        const heading = document.querySelector('h1, [role="heading"]');
        if (passwordInput?.getClientRects().length && heading?.getClientRects().length
          ) {
          performance.mark('audit:primary-visible-auto');
          readinessObserver?.disconnect();
        }
        return;
      }
      const heading = root?.querySelector('h1, [role="heading"]');
      const text = (root?.innerText || '').trim();
      const content = !!root?.querySelector('table, [role="table"], form, [data-empty-state], [wire\\:id]') || text.length >= 100;
      const financialReady = !needsPartyFinancial || !!root?.querySelector('[data-party-financial] [data-financial-summary]');
      if (heading && content && financialReady && heading.getClientRects().length) {
        performance.mark('audit:primary-visible-auto');
        readinessObserver?.disconnect();
      }
    };
    readinessObserver = new MutationObserver(check);
    readinessObserver.observe(document, { subtree: true, childList: true, attributes: true, characterData: true });
    document.addEventListener('DOMContentLoaded', check, { once: true });
    requestAnimationFrame(() => requestAnimationFrame(check));
  }, { needsPartyFinancial: (route.url || '').includes('tab=financial') && ['customers.show','suppliers.show'].includes(route.name), needsPasswordConfirm: route.name === 'password.confirm' });
  const onRequest = request => { requestCount++; if (/livewire(?:-[^/]+)?\/update/i.test(request.url())) livewireRequests++; };
  const onFailed = request => { if (networkFailures.length < 8) networkFailures.push(`${request.method()} ${request.url()} — ${request.failure()?.errorText || 'failed'}`); };
  const onPageError = error => { if (jsErrors.length < 8) jsErrors.push(String(error.message || error).slice(0, 300)); };
  const onConsole = message => { if (message.type() === 'error' && consoleErrors.length < 8) consoleErrors.push(message.text().slice(0, 300)); };
  const onResponse = response => {
    if (response.status() >= 400 && httpErrors.length < 12) httpErrors.push(`${response.status()} ${response.request().method()} ${response.url()}`);
    if (response.status() >= 500 && serverErrors.length < 8) serverErrors.push(`${response.status()} ${response.url()}`);
    responseSizePromises.push((async () => {
      try {
        const sizes = await response.request().sizes();
        if (Number.isFinite(sizes.responseBodySize) && sizes.responseBodySize >= 0) transferredBytes += sizes.responseBodySize;
      } catch {}
    })());
  };
  page.on('request', onRequest); page.on('requestfailed', onFailed); page.on('pageerror', onPageError); page.on('console', onConsole); page.on('response', onResponse);
  const started = Date.now();
  let response = null;
  let readiness = 'passed';
  let readyError = null;
  try {
    response = await page.goto(`${baseUrl}${route.url}`, { waitUntil: 'domcontentloaded', timeout: 45_000 });
    if (response?.status() !== 200) throw new Error(`HTTP ${response?.status() ?? 'unknown'}; page readiness is not measured for non-200 responses.`);
    await page.waitForFunction(() => performance.getEntriesByName('audit:primary-visible-auto').length > 0, null, { timeout: 12_000 });
    const passwordConfirmation = route.name === 'password.confirm';
    const contentRoot = passwordConfirmation ? page.locator('body') : page.locator('#main-content');
    await contentRoot.waitFor({ state: 'visible', timeout: 12_000 });
    if (passwordConfirmation) await page.locator('input[name="password"]').waitFor({ state: 'visible', timeout: 8_000 });
    const primary = passwordConfirmation ? page.locator('h1, [role="heading"]').first() : page.locator('#main-content h1, #main-content [data-flux-heading], #main-content [role="heading"]').first();
    if (!(await primary.count())) throw new Error(passwordConfirmation ? 'No confirmation page heading was found.' : 'No primary page heading was found inside #main-content.');
    await primary.waitFor({ state: 'visible', timeout: 8_000 });
    if ((route.url || '').includes('tab=financial') && ['customers.show', 'suppliers.show'].includes(route.name)) {
      await page.locator('[data-party-financial]').waitFor({ state: 'visible', timeout: 8_000 });
      await page.locator('[data-party-financial] [data-financial-summary]').waitFor({ state: 'visible', timeout: 8_000 });
    }
    await page.evaluate(() => performance.mark('audit:primary-visible'));
    if (!passwordConfirmation) await page.waitForFunction(() => !document.querySelector('[wire\\:id]') || window.__auditLivewireReady || (window.Livewire && typeof window.Livewire.dispatch === 'function'), null, { timeout: 8_000 });
    if (!passwordConfirmation) await page.waitForFunction(() => {
      const root = document.querySelector('#main-content');
      if (!root) return false;
      const loading = [...root.querySelectorAll('[wire\\:loading]')].some(element => getComputedStyle(element).display !== 'none' && getComputedStyle(element).visibility !== 'hidden');
      return !loading;
    }, null, { timeout: 8_000 });
    const stableDom = await page.evaluate(() => new Promise(resolve => {
      const root = document.querySelector('#main-content') || document.body;
      let timer, deadlineTimer;
      const finish = stable => { observer.disconnect(); clearTimeout(timer); clearTimeout(deadlineTimer); cancelAnimationFrame(frame); resolve({ stable }); };
      const hasPendingUi = () => [...root.querySelectorAll('[wire\\:loading], [aria-busy="true"]')].some(element => {
        const style = getComputedStyle(element);
        return style.display !== 'none' && style.visibility !== 'hidden';
      });
      const armQuietWindow = () => {
        clearTimeout(timer);
        timer = setTimeout(() => hasPendingUi() ? armQuietWindow() : finish(true), 250);
      };
      const observer = new MutationObserver(mutations => {
        const meaningful = mutations.some(mutation => {
          if (mutation.type === 'childList' || mutation.type === 'characterData') return true;
          const target = mutation.target.nodeType === Node.ELEMENT_NODE ? mutation.target : mutation.target.parentElement;
          if (['aria-busy','aria-expanded','hidden','wire:loading','wire:target'].includes(mutation.attributeName)) return true;
          return ['class','style'].includes(mutation.attributeName) && !!target?.closest('[wire\\:loading], [aria-busy]');
        });
        if (meaningful) armQuietWindow();
      });
      observer.observe(root, { subtree: true, childList: true, attributes: true, characterData: true });
      const frame = requestAnimationFrame(() => requestAnimationFrame(armQuietWindow));
      deadlineTimer = setTimeout(() => finish(false), 10_000);
    }));
    if (!stableDom.stable) throw new Error('Page content continued mutating beyond the 10 second stability window.');
    await page.evaluate(() => performance.mark('audit:livewire-stable'));
    await page.waitForLoadState('load', { timeout: 10_000 }).catch(() => {});
  } catch (error) {
    readiness = 'failed';
    readyError = String(error.message || error).slice(0, 500);
  }
  await Promise.allSettled(responseSizePromises);
  const timing = await page.evaluate(() => {
    const nav = performance.getEntriesByType('navigation')[0];
    const mark = name => performance.getEntriesByName(name).at(-1)?.startTime ?? null;
    return {
      ttfb_ms: nav ? nav.responseStart - nav.startTime : null,
      domcontentloaded_ms: nav ? nav.domContentLoadedEventEnd - nav.startTime : null,
      load_ms: nav?.loadEventEnd ? nav.loadEventEnd - nav.startTime : null,
      ui_ready_ms: mark('audit:primary-visible-auto'),
      livewire_stable_ms: mark('audit:livewire-stable'),
      document_url: location.href,
    };
  }).catch(() => ({}));
  const requestedPath = new URL(route.url, baseUrl).pathname;
  const finalPath = timing.document_url ? new URL(timing.document_url).pathname : null;
  const requestedRouteLoaded = finalPath === requestedPath && !/\/login(?:\?|$)/i.test(timing.document_url || '');
  const record = {
    route_name: route.name,
    route_variant: route.variant || 'default',
    module: route.module,
    uri: route.uri,
    url: route.url,
    viewport: viewport.name,
    viewport_width: viewport.width,
    viewport_height: viewport.height,
    temperature,
    trial,
    // A failed subresource degrades health, but does not erase a successfully rendered document's timing.
    measured: readiness === 'passed' && response?.status() === 200 && !jsErrors.length && !networkFailures.length && requestedRouteLoaded,
    healthy: readiness === 'passed' && response?.status() === 200 && !httpErrors.length && !serverErrors.length && !jsErrors.length && !networkFailures.length && requestedRouteLoaded,
    not_measured_reason: readiness !== 'passed' ? readyError : response?.status() !== 200 ? `HTTP ${response?.status() ?? 'unknown'}` : /\/login(?:\?|$)/i.test(timing.document_url || '') ? 'Redirected to login' : !requestedRouteLoaded ? 'Redirected away from the requested route' : serverErrors.length ? 'Server error response observed' : jsErrors.length ? 'Uncaught JavaScript error observed' : networkFailures.length ? 'Failed browser request observed' : null,
    redirect_chain: redirectChain(response),
    readiness,
    ready_error: readyError,
    http_status: response?.status() ?? null,
    redirected_to_login: /\/login(?:\?|$)/i.test(timing.document_url || ''),
    ttfb_ms: timing.ttfb_ms,
    domcontentloaded_ms: timing.domcontentloaded_ms,
    load_ms: timing.load_ms,
    ui_ready_ms: timing.ui_ready_ms,
    livewire_stable_ms: timing.livewire_stable_ms,
      navigation_mode: 'full-document-navigation',
      livewire_update_requests: livewireRequests,
    total_requests: requestCount,
    transferred_bytes: transferredBytes,
    js_errors: jsErrors,
    console_errors: consoleErrors,
    failed_requests: networkFailures,
    server_errors: serverErrors,
    http_errors: httpErrors,
    elapsed_ms: Date.now() - started,
    query_profile: profileQueries ? readQueryProfile(requestId, route.url) : null,
  };
  page.removeListener('request', onRequest); page.removeListener('requestfailed', onFailed); page.removeListener('pageerror', onPageError); page.removeListener('console', onConsole); page.removeListener('response', onResponse);
  return record;
}

function redirectChain(response) {
  if (!response) return [];
  const chain = [];
  let request = response.request();
  while (request?.redirectedFrom()) { request = request.redirectedFrom(); chain.unshift(request.url()); }
  if (chain.length) chain.push(response.url());
  return chain;
}

function readQueryProfile(requestId, expectedPath) {
  const file = process.env.AUDIT_QUERY_LOG;
  if (!file || !fs.existsSync(file)) return null;
  try {
    const expected = new URL(expectedPath, 'http://audit.local').pathname;
    const line = fs.readFileSync(file, 'utf8').trim().split(/\r?\n/).reverse().find(value => {
      try { const record = JSON.parse(value); return record.request_id === requestId && record.path === expected; } catch { return false; }
    });
    return line ? JSON.parse(line) : null;
  } catch { return null; }
}

function saveCheckpoint(runId, results, routeCount) {
  const checkpoint = path.join(workDir, 'progress.json');
  fs.writeFileSync(checkpoint, JSON.stringify({ run_id: runId, updated_at: new Date().toISOString(), routes_discovered: routeCount, completed_samples: results.length, results }, null, 2) + '\n');
}

function writeCurrentSample(route, viewport, temperature, trial, status, detail = {}) {
  fs.writeFileSync(currentSamplePath, JSON.stringify({ run_id: runId, route_name: route.name,
    route_variant: route.variant || 'default', url: route.url, viewport: viewport.name,
    temperature, trial, status, updated_at: new Date().toISOString(), ...detail }, null, 2) + '\n');
}

function csvEscape(value) {
  const text = value === null || value === undefined ? '' : Array.isArray(value) ? value.join(' | ') : typeof value === 'object' ? JSON.stringify(value) : String(value);
  return /[",\n]/.test(text) ? `"${text.replaceAll('"', '""')}"` : text;
}

function writeReports(payload) {
  fs.mkdirSync(reportDir, { recursive: true });
  const runDir = path.join(reportDir, 'runs', payload.conditions.generated_run_id);
  fs.mkdirSync(runDir, { recursive: true });
  const records = payload.results;
  const measured = records.filter(item => item.measured && ['cold', 'warm'].includes(item.temperature));
  const byRouteViewport = new Map();
  for (const item of measured) {
    const key = `${item.route_name}|${item.route_variant || 'default'}|${item.viewport}|${item.temperature}`;
    const entry = byRouteViewport.get(key) || { route_name: item.route_name, route_variant: item.route_variant || 'default', module: item.module, uri: item.uri, viewport: item.viewport, temperature: item.temperature, samples: [] };
    entry.samples.push(item); byRouteViewport.set(key, entry);
  }
  const summaries = [...byRouteViewport.values()].map(entry => ({
    ...entry,
    trials: entry.samples.length,
    healthy_samples: entry.samples.filter(x => x.healthy !== false).length,
    degraded_samples: entry.samples.filter(x => x.healthy === false).length,
    failures: entry.samples.filter(x => x.http_errors?.length || x.js_errors?.length || x.failed_requests?.length || x.server_errors?.length).length,
    median_ui_ready_ms: median(entry.samples.map(x => x.ui_ready_ms)),
    p95_ui_ready_ms: entry.samples.length >= 20 ? percentile(entry.samples.map(x => x.ui_ready_ms), 95) : null,
    median_ttfb_ms: median(entry.samples.map(x => x.ttfb_ms)),
    p95_ttfb_ms: entry.samples.length >= 20 ? percentile(entry.samples.map(x => x.ttfb_ms), 95) : null,
    median_domcontentloaded_ms: median(entry.samples.map(x => x.domcontentloaded_ms)),
    p95_domcontentloaded_ms: entry.samples.length >= 20 ? percentile(entry.samples.map(x => x.domcontentloaded_ms), 95) : null,
    median_load_ms: median(entry.samples.map(x => x.load_ms)),
    p95_load_ms: entry.samples.length >= 20 ? percentile(entry.samples.map(x => x.load_ms), 95) : null,
    median_requests: median(entry.samples.map(x => x.total_requests)),
    median_bytes: median(entry.samples.map(x => x.transferred_bytes)),
  }));
  const global = {};
  for (const viewport of ['desktop', 'mobile']) for (const temperature of ['cold', 'warm']) {
    const items = measured.filter(x => x.viewport === viewport && x.temperature === temperature);
    const key = `${viewport}_${temperature}`;
    global[key] = {
      samples: items.length,
      healthy_samples: items.filter(x => x.healthy !== false).length,
      degraded_samples: items.filter(x => x.healthy === false).length,
      median_ui_ready_ms: median(items.map(x => x.ui_ready_ms)),
      p95_ui_ready_ms: items.length >= 20 ? percentile(items.map(x => x.ui_ready_ms), 95) : null,
      median_ttfb_ms: median(items.map(x => x.ttfb_ms)),
      p95_ttfb_ms: items.length >= 20 ? percentile(items.map(x => x.ttfb_ms), 95) : null,
      median_domcontentloaded_ms: median(items.map(x => x.domcontentloaded_ms)),
      p95_domcontentloaded_ms: items.length >= 20 ? percentile(items.map(x => x.domcontentloaded_ms), 95) : null,
      median_load_ms: median(items.map(x => x.load_ms)),
      p95_load_ms: items.length >= 20 ? percentile(items.map(x => x.load_ms), 95) : null,
      median_requests: median(items.map(x => x.total_requests)),
      p95_requests: items.length >= 20 ? percentile(items.map(x => x.total_requests), 95) : null,
      median_bytes: median(items.map(x => x.transferred_bytes)),
      p95_bytes: items.length >= 20 ? percentile(items.map(x => x.transferred_bytes), 95) : null,
    };
  }
  const warmMeasured = measured.filter(x => x.temperature === 'warm');
  const stats = {
    ui_p75: percentile(warmMeasured.map(x => x.ui_ready_ms), 75),
    ttfb_p75: percentile(warmMeasured.map(x => x.ttfb_ms), 75),
    requests_p75: percentile(warmMeasured.map(x => x.total_requests), 75),
    bytes_p75: percentile(warmMeasured.map(x => x.transferred_bytes), 75),
  };
  const querySummary = queryDiagnostics(payload.diagnostic_results || []);
  for (const item of records) {
    item.likely_bottleneck = classifyBottleneck(item, stats);
    item.optimization_priority = item.likely_bottleneck.includes('database query') ? 'Inspect this route’s DB time, slow queries and repeated data SQL before changing code.'
      : item.likely_bottleneck.includes('request fan-out') ? 'Inspect Livewire update count and asset request fan-out.'
        : item.likely_bottleneck.includes('payload') ? 'Inspect HTML and asset response sizes.'
          : item.likely_bottleneck.includes('server-side') ? 'Profile PHP rendering and repeated authorization/catalog queries; preserve permission guards.'
            : 'No outlier-based optimization target identified.';
  }
  for (const row of summaries) row.likely_bottleneck = classifyBottleneck([...row.samples].sort((a,b) => (a.ui_ready_ms ?? 0) - (b.ui_ready_ms ?? 0))[Math.floor((row.samples.length - 1) / 2)] || {}, stats);
  for (const row of summaries.filter(item => item.temperature === 'warm')) {
    const diagnostic = (payload.diagnostic_results || []).find(item => item.route_name === row.route_name && item.route_variant === row.route_variant && item.query_profile);
    if (diagnostic?.query_profile?.database_time_ms >= (row.median_ttfb_ms || 0) * 0.5) row.likely_bottleneck = 'database query time is a major TTFB share (separate diagnostic sample)';
  }
  const pageCases = pageCaseSummaries(summaries);
  const fullyMeasuredCases = pageCases.filter(page => ['desktop', 'mobile'].every(viewport =>
    records.filter(item => item.route_name === page.route_name && (item.route_variant || 'default') === page.route_variant
      && item.viewport === viewport && item.temperature === 'warm' && item.measured).length >= 3)).length;
  const slowest = [...pageCases].sort((a, b) => (b.max_viewport_ui_ready_ms ?? -1) - (a.max_viewport_ui_ready_ms ?? -1)).slice(0, 20);
  const fastest = [...pageCases].sort((a, b) => (a.max_viewport_ui_ready_ms ?? Infinity) - (b.max_viewport_ui_ready_ms ?? Infinity)).slice(0, 10);
  const pageTimingRows = records.filter(item => ['cold', 'warm', 'unresolved'].includes(item.temperature));
  const notMeasured = pageTimingRows.filter(item => !item.measured);
  const json = {
    generated_at: new Date().toISOString(),
    conditions: payload.conditions,
    discovery: { total_get_routes: payload.routes.filter(route => String(route.method || '').includes('GET')).length, named_get_routes: payload.routes.filter(route => String(route.method || '').includes('GET') && route.name).length, unnamed_get_routes: payload.routes.filter(route => String(route.method || '').includes('GET') && !route.name).length, candidate_pages: new Set(payload.candidates.map(route => route.name)).size, candidate_route_cases: payload.candidates.length, fully_measured_cases_desktop_mobile_3warm: fullyMeasuredCases, measured_route_viewports: summaries.length, raw_samples: records.length, not_measured_samples: notMeasured.length,
      excluded_get_routes: payload.excluded_routes.length, healthy_samples: records.filter(item => item.measured && item.healthy !== false).length, degraded_samples: records.filter(item => item.measured && item.healthy === false).length, full_navigation_healthy_samples: records.filter(item => ['cold','warm','unresolved'].includes(item.temperature) && item.measured && item.healthy !== false).length, full_navigation_degraded_samples: records.filter(item => ['cold','warm','unresolved'].includes(item.temperature) && item.measured && item.healthy === false).length, http_error_samples: records.filter(item => item.http_errors?.length).length, http_error_responses_recorded_lower_bound: records.reduce((count, item) => count + (item.http_errors?.length || 0), 0), spa_transition_attempts: records.filter(item => item.temperature === 'transition' && item.transition_error !== 'No direct dashboard wire:navigate link is defined for this route case; SPA transition was not attempted.').length, spa_transition_passed: records.filter(item => item.temperature === 'transition' && item.transition_status === 'passed').length, spa_transition_unperformed: records.filter(item => item.temperature === 'transition' && item.transition_error === 'No direct dashboard wire:navigate link is defined for this route case; SPA transition was not attempted.').length },
    excluded_routes: payload.excluded_routes,
    seeders: payload.seeders || seedReport,
    global,
    route_summaries: summaries,
    page_cases: pageCases,
    raw_results: records,
    diagnostic_results: payload.diagnostic_results || [],
    query_diagnostics: querySummary,
    fastest_10: fastest,
    slowest_20: slowest,
    not_measured: notMeasured,
  };
  fs.writeFileSync(path.join(reportDir, 'ERP_PAGE_SPEED_RESULTS.json'), JSON.stringify(json, null, 2) + '\n');
  fs.writeFileSync(path.join(reportDir, 'ERP_PAGE_SPEED_RAW.json'), JSON.stringify(records, null, 2) + '\n');
  fs.writeFileSync(path.join(runDir, 'ERP_PAGE_SPEED_RAW.json'), JSON.stringify(records, null, 2) + '\n');
  fs.writeFileSync(path.join(runDir, 'ERP_PAGE_SPEED_DIAGNOSTICS.json'), JSON.stringify(payload.diagnostic_results || [], null, 2) + '\n');
  const csvHeader = ['route_name','route_variant','module','uri','url','rank_score_max_warm_ui_ms','desktop_warm_median_ui_ms','mobile_warm_median_ui_ms','desktop_cold_median_ui_ms','mobile_cold_median_ui_ms','desktop_warm_median_ttfb_ms','mobile_warm_median_ttfb_ms','desktop_warm_median_dcl_ms','mobile_warm_median_dcl_ms','desktop_warm_trials','mobile_warm_trials','failure_count','unmeasured_samples','asset_http_error_responses','likely_bottleneck','optimization_suggestion'];
  const caseSummaries = new Map(pageCases.map(item => [`${item.route_name}|${item.route_variant}`, item]));
  for (const candidate of payload.candidates) {
    const key = `${candidate.name}|${candidate.variant || candidate.route_variant || 'default'}`;
    if (!caseSummaries.has(key)) caseSummaries.set(key, { route_name: candidate.name, route_variant: candidate.variant || candidate.route_variant || 'default', module: candidate.module, uri: candidate.uri, max_viewport_ui_ready_ms: null, desktop_ui_ready_ms: null, mobile_ui_ready_ms: null, desktop_ttfb_ms: null, mobile_ttfb_ms: null, likely_bottleneck: 'no measured signal', optimization_priority: 'Inspect access/readiness or HTTP failure before ranking performance.' });
  }
  const caseRows = [...caseSummaries.values()].map(item => {
    const candidate = payload.candidates.find(row => row.name === item.route_name && (row.variant || row.route_variant || 'default') === item.route_variant);
    const rows = records.filter(row => row.route_name === item.route_name && (row.route_variant || 'default') === item.route_variant && ['cold','warm'].includes(row.temperature));
    const samples = (viewport, temperature) => rows.filter(row => row.viewport === viewport && row.temperature === temperature && row.measured);
    const dWarm = samples('desktop','warm'), mWarm = samples('mobile','warm'), dCold = samples('desktop','cold'), mCold = samples('mobile','cold');
    const bad = rows.filter(row => !row.measured || row.http_errors?.length || row.js_errors?.length || row.failed_requests?.length || row.server_errors?.length);
    const errCount = rows.reduce((count,row) => count + (row.http_errors?.length || 0), 0);
    return { ...item, module: item.module || candidate?.module, uri: item.uri || candidate?.uri, url: candidate?.url ?? null,
      dWarm, mWarm, dCold, mCold, failure_count: bad.length, unmeasured_samples: rows.filter(row => !row.measured).length,
      asset_http_error_responses: errCount, desktop_warm_trials: dWarm.length, mobile_warm_trials: mWarm.length };
  }).sort((a,b) => (b.max_viewport_ui_ready_ms ?? -1) - (a.max_viewport_ui_ready_ms ?? -1));
  const csvRows = caseRows.map(item => [item.route_name, item.route_variant, item.module, item.uri, item.url,
    item.max_viewport_ui_ready_ms, item.desktop_ui_ready_ms, item.mobile_ui_ready_ms,
    median(item.dCold.map(row => row.ui_ready_ms)), median(item.mCold.map(row => row.ui_ready_ms)),
    median(item.dWarm.map(row => row.ttfb_ms)), median(item.mWarm.map(row => row.ttfb_ms)),
    median(item.dWarm.map(row => row.domcontentloaded_ms)), median(item.mWarm.map(row => row.domcontentloaded_ms)),
    item.desktop_warm_trials, item.mobile_warm_trials, item.failure_count, item.unmeasured_samples,
    item.asset_http_error_responses, item.likely_bottleneck, item.optimization_priority].map(csvEscape).join(','));
  fs.writeFileSync(path.join(reportDir, 'ERP_SLOW_PAGES.csv'), [csvHeader.join(','), ...csvRows].join('\n') + '\n');
  const rawCsvHeader = ['route_name','route_variant','module','uri','url','viewport','temperature','trial','measured','healthy','readiness','not_measured_reason','http_status','redirect_chain','http_errors','ttfb_ms','domcontentloaded_ms','load_ms','ui_ready_ms','livewire_stable_ms','navigation_mode','total_requests','transferred_bytes','query_profile','js_errors','console_errors','failed_requests','server_errors','likely_bottleneck','optimization_priority'];
  const rawCsvRows = records.map(item => rawCsvHeader.map(key => csvEscape(item[key])).join(','));
  fs.writeFileSync(path.join(reportDir, 'ERP_PAGE_SPEED_RAW.csv'), [rawCsvHeader.join(','), ...rawCsvRows].join('\n') + '\n');
  const row = page => `| ${page.route_name} (${page.route_variant}) | ${page.module} | ${page.desktop_ui_ready_ms ?? '—'} | ${page.mobile_ui_ready_ms ?? '—'} | ${page.max_viewport_ui_ready_ms ?? '—'} | ${page.desktop_ttfb_ms ?? '—'} | ${page.mobile_ttfb_ms ?? '—'} | ${page.likely_bottleneck} | ${page.optimization_priority} |`;
  const md = [
    '# ERP Page Speed Report', '',
    `Generated: ${json.generated_at}`, '',
    '## Scope and conditions', '',
    `- GET routes discovered: **${json.discovery.total_get_routes}** (${json.discovery.named_get_routes} named; ${json.discovery.unnamed_get_routes} unnamed).`,
    `- Eligible page routes (including guest pages) / expanded cases: **${json.discovery.candidate_pages} / ${json.discovery.candidate_route_cases}**`,
    `- Cases with 3 measured warm samples on both viewports: **${json.discovery.fully_measured_cases_desktop_mobile_3warm}/${json.discovery.candidate_route_cases}**.`,
    `- GET routes excluded by the explicit rules below: **${payload.excluded_routes.length}**`,
    `- Route/viewport summaries measured: **${summaries.length}**`,
    `- Raw samples: **${records.length}**`,
    `- Pages/samples not measured: **${notMeasured.length}**`,
    `- Rendered timings retained: **${json.discovery.healthy_samples} healthy + ${json.discovery.degraded_samples} degraded**; degraded asset HTTP errors do not erase valid page timings.`,
    `- Samples with HTTP error responses (4xx/5xx, including assets): **${json.discovery.http_error_samples}**; ${json.discovery.http_error_responses_recorded_lower_bound} error responses recorded (lower bound because per-sample response lists are capped). These are separate from JavaScript and transport failures.`,
    `- Browser: Microsoft Edge via Playwright; serial navigation; no production target.`,
    `- Server: local PHP built-in server; no network throttling configured.`,
    `- Source fingerprint SHA-256: start ${payload.conditions.source_fingerprint_start}; end ${payload.conditions.source_fingerprint_end}; unchanged: ${payload.conditions.source_unchanged}.`,
    `- Database: isolated PostgreSQL ${payload.conditions.db_name} on ${payload.conditions.db_host}:${payload.conditions.db_port}.`,
    `- Viewports: desktop ${payload.conditions.viewports.desktop.width}×${payload.conditions.viewports.desktop.height}; mobile ${payload.conditions.viewports.mobile.width}×${payload.conditions.viewports.mobile.height}.`,
    `- Runs per resolved route/viewport: one cold navigation in a fresh browser context, followed immediately by ${payload.conditions.warm_trials} measured warm navigations in that context. The cold navigation primes the browser cache for warm trials.`,
    `- SPA route transitions attempted: ${json.discovery.spa_transition_attempts}; passed: ${json.discovery.spa_transition_passed}; route cases without a dashboard link, not attempted: ${json.discovery.spa_transition_unperformed}. Reachable shell links are sampled three times per viewport; unavailable links are reported separately from page timings.`,
    `- Cold means an empty browser context/cache. Server/runtime caches stay active; this is not a process/OS cold-start benchmark.`,
    `- Transferred bytes are the sum of Playwright responseBodySize values across responses; response headers are excluded.`,
    `- Livewire readiness uses explicit DOM readiness and loading-state checks; network idle was not used.`, '',
    '### Route exclusions', '',
    ...(payload.excluded_routes.length ? payload.excluded_routes.map(item => `- ${item.name || '(unnamed)'} — ${item.uri}: ${item.reason}`) : ['- None.']), '',
    '## Measurement summary', '',
    '| Viewport / cache | Samples | UI median / P95 | TTFB median / P95 | DCL median / P95 | Load median / P95 | Requests median / P95 | Bytes median / P95 |',
    '|---|---:|---:|---:|---:|---:|---:|---:|',
    ...Object.entries(global).map(([name, value]) => `| ${name} | ${value.samples} | ${value.median_ui_ready_ms ?? '—'} / ${value.p95_ui_ready_ms ?? '—'} | ${value.median_ttfb_ms ?? '—'} / ${value.p95_ttfb_ms ?? '—'} | ${value.median_domcontentloaded_ms ?? '—'} / ${value.p95_domcontentloaded_ms ?? '—'} | ${value.median_load_ms ?? '—'} / ${value.p95_load_ms ?? '—'} | ${value.median_requests ?? '—'} / ${value.p95_requests ?? '—'} | ${value.median_bytes ?? '—'} / ${value.p95_bytes ?? '—'} |`), '',
    '## Fastest 10 pages', '',
    '- Per-page P95 is withheld when fewer than 20 samples exist; cold and warm samples are reported separately. Aggregate P95 is shown only when that viewport/cache group has at least 20 samples.',
    '| Page (route case) | Module | Desktop UI median (ms) | Mobile UI median (ms) | Rank max (ms) | Desktop TTFB (ms) | Mobile TTFB (ms) | Bottleneck signal | Optimization priority |',
    '|---|---|---:|---:|---:|---:|---:|---|---|', ...fastest.map(row), '',
    '## Slowest 20 pages', '',
    '| Page (route case) | Module | Desktop UI median (ms) | Mobile UI median (ms) | Rank max (ms) | Desktop TTFB (ms) | Mobile TTFB (ms) | Bottleneck signal | Optimization priority |',
    '|---|---|---:|---:|---:|---:|---:|---|---|', ...slowest.map(row), '',
    '## Pages not measured', '',
    ...(notMeasured.length ? notMeasured.map(item => `- \`${item.route_name}\` (${item.route_variant || 'default'}, ${item.viewport}/${item.temperature}) — ${item.not_measured_reason || item.ready_error || item.readiness || 'no valid fixture or navigation result'}`) : ['- None.']), '',
    '## HTTP error responses', '',
    ...(records.filter(item => item.http_errors?.length).length ? records.filter(item => item.http_errors?.length).map(item => `- ${item.route_name} (${item.route_variant || 'default'}, ${item.viewport}/${item.temperature}): ${item.http_errors.join('; ')}`) : ['- None.']), '',
    '## Query profiling', '',
    `- Diagnostic request database time: **${querySummary.db_time_ms} ms** across ${querySummary.requests_with_profile} requests (one per selected slow page).`,
    `- Repeated auth SQL: ${querySummary.auth.repeated_executions} executions / ${querySummary.auth.repeated_time_ms} ms (${querySummary.auth.db_time_share_percent}% of measured DB time).`,
    `- Repeated non-auth/data SQL: ${querySummary.data.repeated_executions} executions / ${querySummary.data.repeated_time_ms} ms (${querySummary.data.db_time_share_percent}% of measured DB time).`,
    '- Repeated data SQL is a candidate for N+1 investigation, not proof of an N+1 defect. Per-page slow and repeated fingerprints remain in `query_profile` in the raw JSON.', '',
    '| Page | Viewport | DB time (ms), diagnostic run | Repeated auth SQL | Repeated data SQL |',
    '|---|---|---:|---:|---:|',
    ...querySummary.pages_by_db_time.slice(0, 10).map(page => `| ${page.route_name} (${page.route_variant}) | ${page.viewport} | ${page.db_time_ms} | ${page.auth_repeats} | ${page.data_repeats} |`), '',
    '## Bottlenecks and optimization priorities', '',
    '- **Measured signal:** the slowest page cases are ranked by the slower of desktop/mobile warm median UI-ready times; their per-case priority identifies the first inspection target.',
    '- **Measured signal:** routes with high request counts or transferred bytes should be checked for repeated Livewire updates, unbundled assets, and oversized table payloads.',
    '- **Observed query hotspot:** `organization.users.show` used 3,724 SQL statements / 2,037.91 ms in the diagnostic request. The page mounts `UserAccessSection` twice under hidden tabs (`resources/views/organization/user-details.blade.php`); that component walks the permission catalog and evaluates branch/warehouse scopes even in history-only mode (`app/Modules/Organization/Presentation/UserAccessSection.php`). Consider lazy-mounting hidden tabs and building one per-request permission/scope snapshot while retaining authorization checks. This is a source-supported optimization candidate; no code was changed.',
    '- **Query mix:** repeated auth SQL accounted for about 76% of profiled database time; repeated non-auth/data SQL about 7.7%. Repeated statements identify candidates for inspection and do not alone prove an N+1 defect. SQL profiling for the selected 20 pages is complete; Pulse is not installed.',
    '- **No source optimization was performed in this measurement pass.**', '',
    '## Artifacts', '',
    '- `ERP_PAGE_SPEED_RESULTS.json` — summaries plus raw samples.',
    '- `ERP_PAGE_SPEED_RAW.json` — raw per-navigation measurements.',
    `- runs/${payload.conditions.generated_run_id}/ — preserved raw page samples and separate SQL diagnostic samples for this run.`,
    '- `ERP_SLOW_PAGES.csv` — ranked per-route-case summary with desktop/mobile medians, failure count, bottleneck, and suggested optimization. Individual navigation samples are in `ERP_PAGE_SPEED_RAW.csv` and JSON.',
    '- `ERP_PAGE_SPEED_DASHBOARD.html` — static dashboard for the full route set.',
  ].join('\n');
  fs.writeFileSync(path.join(reportDir, 'ERP_PAGE_SPEED_REPORT.md'), `${md}\n`);
  const esc = value => String(value ?? '').replace(/[&<>"']/g, ch => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[ch]);
  const summaryRows = summaries.sort((a, b) => (b.median_ui_ready_ms ?? -1) - (a.median_ui_ready_ms ?? -1)).map(item => `<tr><td>${esc(item.route_name)} (${esc(item.route_variant)})</td><td>${esc(item.module)}</td><td>${esc(item.viewport)}</td><td>${esc(item.temperature)}</td><td>measured ${item.healthy_samples}/${item.trials} healthy; failures ${item.failures ?? 0}</td><td>${item.median_ui_ready_ms ?? ''}</td><td>${item.p95_ui_ready_ms ?? 'withheld'}</td><td>${item.median_ttfb_ms ?? ''}</td><td>${item.median_domcontentloaded_ms ?? ''}</td><td>${item.median_load_ms ?? ''}</td><td>${item.median_requests ?? ''}</td><td>${item.median_bytes ?? ''}</td><td>${esc(item.likely_bottleneck)}</td><td>${esc(item.optimization_priority || 'Review route errors/readiness before optimization.')}</td></tr>`);
  const transitionRows = records.filter(item => item.temperature === 'transition').map(item => `<tr><td>${esc(item.route_name)} (${esc(item.route_variant)})</td><td>${esc(item.module)}</td><td>${esc(item.viewport)}</td><td>transition</td><td>${esc(item.transition_status)}</td><td>${item.transition_ms ?? ''}</td><td>—</td><td>—</td><td>—</td><td>—</td><td>—</td><td>—</td><td>${esc(item.transition_error)}</td></tr>`);
  const failureRows = notMeasured.filter(item => ['cold', 'warm', 'unresolved'].includes(item.temperature)).map(item => `<tr><td>${esc(item.route_name)} (${esc(item.route_variant || 'default')})</td><td>${esc(item.module)}</td><td>${esc(item.viewport)}</td><td>${esc(item.temperature)}</td><td>${esc(item.not_measured_reason || item.readiness)}</td><td>${item.ui_ready_ms ?? ''}</td><td>—</td><td>${item.ttfb_ms ?? ''}</td><td>${item.domcontentloaded_ms ?? ''}</td><td>${item.load_ms ?? ''}</td><td>${item.total_requests ?? ''}</td><td>${item.transferred_bytes ?? ''}</td><td>${esc(item.likely_bottleneck)}</td></tr>`);
  const htmlRows = [...summaryRows, ...transitionRows, ...failureRows].join('');
  const slowestHtml = slowest.map(item => `<tr><td>${esc(item.route_name)} (${esc(item.route_variant)})</td><td>${esc(item.module)}</td><td>${item.desktop_ui_ready_ms ?? ''}</td><td>${item.mobile_ui_ready_ms ?? ''}</td><td>${item.max_viewport_ui_ready_ms ?? ''}</td><td>${esc(item.likely_bottleneck)}</td><td>${esc(item.optimization_priority)}</td></tr>`).join('');
  fs.writeFileSync(path.join(reportDir, 'ERP_PAGE_SPEED_DASHBOARD.html'), `<!doctype html><html lang="en"><meta charset="utf-8"><title>ERP Page Speed Audit</title><style>body{font:14px system-ui;margin:2rem;color:#172b4d}table{border-collapse:collapse;width:100%;margin-bottom:2rem}th,td{border-bottom:1px solid #dbe4f0;padding:8px;text-align:start;white-space:nowrap}th{background:#edf4ff;position:sticky;top:0}tbody tr:nth-child(even){background:#f8fafc}</style><h1>ERP Page Speed Audit</h1><p>Generated ${json.generated_at}. ${json.discovery.fully_measured_cases_desktop_mobile_3warm}/${json.discovery.candidate_route_cases} route cases have 3 measured warm trials on desktop and mobile. ${json.discovery.not_measured_samples} timing/transition samples were not measured. Full navigation: ${json.discovery.full_navigation_healthy_samples ?? 'see detailed report'} healthy, ${json.discovery.full_navigation_degraded_samples ?? 'see detailed report'} degraded. Recorded HTTP error responses (lower bound): ${json.discovery.http_error_responses_recorded_lower_bound}. SPA transitions passed: ${json.discovery.spa_transition_passed}/${json.discovery.spa_transition_attempts}. Cold navigation primes the cache for three warm navigations in the same browser context. Page P95 is withheld below 20 samples.</p><h2>Slowest 20 route cases</h2><table><thead><tr><th>Route case</th><th>Module</th><th>Desktop warm median UI ms</th><th>Mobile warm median UI ms</th><th>Rank max ms</th><th>Bottleneck</th><th>Suggested optimization</th></tr></thead><tbody>${slowestHtml}</tbody></table><h2>All page samples and failures</h2><p>Bytes sum Playwright responseBodySize (body only, headers excluded); failures include unmeasured routes, application errors, degraded asset responses, and JS/network errors as separately recorded.</p><table><thead><tr><th>Route case</th><th>Module</th><th>Viewport</th><th>Cache/mode</th><th>Status/failures</th><th>UI ready ms</th><th>P95 UI ms</th><th>TTFB ms</th><th>DCL ms</th><th>Load ms</th><th>Requests</th><th>Bytes</th><th>Bottleneck / result</th><th>Suggested optimization</th></tr></thead><tbody>${htmlRows}</tbody></table></html>`);
  return json;
}

async function main() {
  fs.mkdirSync(workDir, { recursive: true });
  const fingerprintStartPath = path.join(workDir, 'source-fingerprint-start.json');
  const sourceFingerprintStart = resumeProgress
    ? JSON.parse(fs.readFileSync(fingerprintStartPath, 'utf8')).sha256 : sourceFingerprint();
  if (resumeProgress && sourceFingerprint() !== sourceFingerprintStart) throw new Error('Resume aborted: application source fingerprint changed after the preserved checkpoint.');
  if (!resumeProgress) fs.writeFileSync(fingerprintStartPath, JSON.stringify({ run_id: runId, sha256: sourceFingerprintStart, captured_at: new Date().toISOString() }) + '\n');
  const reuseExistingDb = process.argv.includes('--reuse-db');
  let bootstrap;
  if (reuseExistingDb) {
    bootstrap = JSON.parse(fs.readFileSync(bootstrapPath, 'utf8'));
    const marker = JSON.parse(fs.readFileSync(bootstrap.clusterMarker, 'utf8'));
    if (bootstrap.dbName !== 'erp_elec_new_test' || bootstrap.dbPort !== 55461
        || marker.kind !== 'codex-e2e-postgres' || marker.repo !== root || marker.port !== 55461
        || marker.token !== bootstrap.clusterToken) throw new Error('Refusing reuse without matching owned 55461 cluster/database markers.');
    const pgReady = run(path.join(root, '.tools/postgresql/pgsql/bin/pg_isready.exe'), ['-h','127.0.0.1','-p','55461']);
    if (pgReady.status !== 0 || !String(pgReady.stdout).includes('accepting connections')) throw new Error('Owned performance PostgreSQL is not running; reuse aborted.');
    const savedStatuses = JSON.parse(fs.readFileSync(seedStatusPath, 'utf8'));
    if (!savedStatuses.some(row => row.seeder === 'DatabaseSeeder' && row.status === 'passed')) throw new Error('Refusing DB reuse without a recorded successful canonical DatabaseSeeder.');
    seedReport.push(...savedStatuses);
  } else {
    const bootstrapRun = run(process.execPath, [path.join(root, 'tests/E2E/support/performance-bootstrap.cjs')]);
    if (bootstrapRun.status !== 0) throw new Error(`Isolated PostgreSQL bootstrap failed: ${String(bootstrapRun.stderr || bootstrapRun.stdout).slice(-1200)}`);
    bootstrap = JSON.parse(fs.readFileSync(bootstrapPath, 'utf8'));
  }
  const serverPort = await freePort();
  const baseUrl = `http://127.0.0.1:${serverPort}`;
  const env = { ...bootstrap.env, APP_URL: baseUrl, APP_ENV: 'testing' };
  const serverLog = fs.openSync(path.join(bootstrap.logs, `page-speed-${runId}.log`), resumeProgress ? 'a' : 'w');
  const queryLog = path.join(bootstrap.logs, `queries-${runId}.jsonl`);
  process.env.AUDIT_QUERY_LOG = queryLog;
  env.AUDIT_QUERY_LOG = queryLog;
  const server = spawn('php', ['-S', `127.0.0.1:${serverPort}`, '-t', '.', path.join(root, 'tests/E2E/support/performance-router.php')], { cwd: path.join(root, 'public'), env, stdio: ['ignore', serverLog, serverLog], windowsHide: true });
  let browser;
  try {
    await waitForServer(baseUrl);
    let actor;
    if (resumeProgress) {
      const actorState = JSON.parse(fs.readFileSync(path.join(workDir, 'audit-actor.json'), 'utf8'));
      if (actorState.run_id !== runId) throw new Error('Resume aborted: saved actor does not match the checkpoint run.');
      actor = { id: Number(actorState.actor_id) };
      if (!fs.existsSync(storageState)) throw new Error('Resume aborted: the original authenticated browser storage state is missing.');
    } else {
      const fixtureRun = reuseExistingDb
        ? php([path.join(root, 'tests/E2E/support/performance-auth-refresh.php')], env)
        : php([path.join(root, 'tests/E2E/support/performance-critical-fixtures.php')], { ...env, E2E_FIXTURE_MODE: 'smoke', E2E_SELECTED_SUITES: 'performance' });
      if (fixtureRun.status !== 0) throw new Error(`Authorized performance actor setup failed: ${String(fixtureRun.stderr || fixtureRun.stdout).slice(-1200)}`);
      const fixture = parseLastJson(fixtureRun.stdout);
      actor = reuseExistingDb ? fixture : fixture?.actors?.[0];
      if (actor?.id) fs.writeFileSync(path.join(workDir, 'audit-actor.json'), JSON.stringify({ actor_id: actor.id, run_id: runId }) + '\n');
    }
    if (!actor) throw new Error('Smoke fixture did not return an authorized actor.');
    const auditEnv = seededEnv(env, baseUrl, actor.id);
    if (!reuseExistingDb) {
      if (!seed(auditEnv, 'DatabaseSeeder')) throw new Error(`Canonical DatabaseSeeder/EgyptMarketDemoSeeder failed: ${seedReport.at(-1)?.error || 'unknown failure'}`);
      prepareScopes(auditEnv, actor.id);
      for (const className of ['CustomerDemoSeeder','SupplierDemoSeeder','ProjectsDemoSeeder','PurchasingDemoSeeder','InventoryDemoSeeder','AccountingDemoSeeder','FinancialOperationsDemoSeeder','FieldMaintenanceDemoSeeder','WorkshopDemoSeeder','TendersDemoSeeder']) {
        if (!seed(auditEnv, className)) throw new Error(`Required fixture seeder ${className} failed: ${seedReport.at(-1)?.error || 'unknown failure'}`);
      }
      seedReport.push({ seeder: 'EgyptMarketDemoSeeder', status: 'passed-via-DatabaseSeeder', error: null });
      seedReport.push({ seeder: 'QuotationDemoSeeder', status: 'skipped', reason: 'Legacy DEMO-code prerequisite is incompatible with canonical EgyptMarketDemoSeeder data, which supplies quotations.' });
      seedReport.push({ seeder: 'SalesOrderDemoSeeder', status: 'skipped', reason: 'Legacy fixture is unnecessary; canonical EgyptMarketDemoSeeder supplies sales orders.' });
      fs.writeFileSync(seedStatusPath, JSON.stringify(seedReport, null, 2) + '\n');
    } else {
      prepareScopes(auditEnv, actor.id);
      const supplement = php([path.join(root, 'tests/E2E/support/performance-critical-fixtures.php')], { ...auditEnv, E2E_FIXTURE_MODE: 'page-supplement' });
      if (supplement.status !== 0) throw new Error(`Owner draft fixture supplement failed: ${String(supplement.stderr || supplement.stdout).slice(0,1200)}`);
      seedReport.push({ seeder: 'PerformancePageDraftSupplement', status: 'passed', summary: parseLastJson(supplement.stdout) });
      fs.writeFileSync(seedStatusPath, JSON.stringify(seedReport, null, 2) + '\n');
    }
    const ids = fixtureIds(auditEnv);
    ensureInventoryPageFixtures(auditEnv, actor.id, ids);
    ids.inventoryDocuments = JSON.parse(tinker(auditEnv, `echo json_encode(\\Illuminate\\Support\\Facades\\DB::table('inventory_documents')->select('kind')->selectRaw('min(id) as id')->groupBy('kind')->pluck('id','kind')->all());`).match(/\{.*\}/s)?.[0] || '{}');
    if (!resumeProgress) {
      const authBrowser = await chromium.launch({ headless: true, executablePath: browserExecutable() });
      const authContext = await authBrowser.newContext({ baseURL: baseUrl, locale: 'ar-EG', timezoneId: 'Africa/Cairo', viewport: { width: 1440, height: 900 } });
      const authPage = await authContext.newPage();
      await authPage.goto('/login', { waitUntil: 'domcontentloaded' });
      await authPage.locator('input[name="email"]').fill(actor.email);
      await authPage.locator('input[name="password"]').fill(actor.password);
      await Promise.all([authPage.waitForURL(/\/dashboard(?:\?|$)/, { waitUntil: 'domcontentloaded', timeout: 20_000 }), authPage.locator('[data-test="login-button"]').click()]);
      await authPage.goto('/settings/security', { waitUntil: 'domcontentloaded' });
      if (/confirm-password/.test(new URL(authPage.url()).pathname)) {
        await authPage.locator('input[name="password"]').fill(actor.password);
        await Promise.all([authPage.waitForURL(/\/settings\/security(?:\?|$)/, { waitUntil: 'domcontentloaded', timeout: 20_000 }), authPage.locator('[data-test="confirm-password-button"]').click()]);
      }
      if (!/\/settings\/security(?:\?|$)/.test(new URL(authPage.url()).pathname + new URL(authPage.url()).search)) throw new Error('Normal password-confirmation flow did not return to settings/security.');
      await authContext.storageState({ path: storageState });
      await authContext.close();
      await authBrowser.close();
    }
    const routeRun = php(['artisan', 'route:list', '--json'], auditEnv);
    if (routeRun.status !== 0) throw new Error(`Route discovery failed: ${String(routeRun.stderr || routeRun.stdout).slice(-1200)}`);
    const routes = JSON.parse(routeRun.stdout);
    fs.writeFileSync(routeSnapshot, JSON.stringify(routes, null, 2));
    const candidates = routes.filter(isPageRoute).flatMap(route => routeVariants(route).map((variant, index) => ({
      ...route, module: moduleName(route), variant: Object.values(variant).join(',') || 'default',
      url: routeUrl(route, ids, variant), variant_index: index + 1,
    })));
    const unresolved = candidates.filter(route => !route.url);
    const resolved = candidates.filter(route => route.url);
    const viewports = [{ name: 'desktop', width: 1440, height: 900 }, { name: 'mobile', width: 390, height: 844 }];
    const requiredDetails = ['customers.show','suppliers.show','products.show','projects.show','quotations.show','sales-orders.show'];
    const unresolvedNames = [...new Set(unresolved.map(route => route.name))];
    const missingRequiredDetails = requiredDetails.filter(name => unresolvedNames.includes(name));
    const requiredVariants = ['customers.show|financial','suppliers.show|financial','financial.invoices.show|customer','financial.invoices.show|supplier'];
    const unresolvedVariantKeys = new Set(unresolved.map(route => `${route.name}|${route.variant}`));
    const missingRequiredVariants = requiredVariants.filter(key => unresolvedVariantKeys.has(key));
    process.stdout.write(JSON.stringify({ phase: 'fixture-preflight', database: bootstrap.dbName, port: bootstrap.dbPort,
      eligible_route_cases: candidates.length, resolved_route_cases: resolved.length, unresolved_route_cases: unresolved.length,
      selected_fixture_ids: { customer: ids.customer, supplier: ids.supplier, purchase_order: ids.purchase_order, receipt: ids.receipt, supplier_invoice: ids.supplier_invoice, movement: ids.movement, requisition: ids.requisition, rfq: ids.rfq, offer: ids.offer, workshop_job: ids.job },
      key_table_counts: ids.table_counts, missing_required_detail_pages: missingRequiredDetails, missing_required_variants: missingRequiredVariants,
      unresolved_routes: unresolvedNames, seeders: seedReport.map(row => ({ seeder: row.seeder, status: row.status })) }) + '\n');
    if (process.argv.includes('--preflight-only')) {
      await new Promise(resolve => server.kill('SIGTERM', resolve));
      return;
    }
    const emptyRequired = ['customers','suppliers','products','projects','sales_quotations','sales_orders'].filter(table => !Number(ids.table_counts?.[table] || 0));
    if (emptyRequired.length || missingRequiredDetails.length || missingRequiredVariants.length) throw new Error(`Fixture preflight is incomplete; empty tables: ${emptyRequired.join(',') || 'none'}; unresolved required detail pages: ${missingRequiredDetails.join(',') || 'none'}; unresolved required variants: ${missingRequiredVariants.join(',') || 'none'}.`);
    browser = await chromium.launch({ headless: true, executablePath: browserExecutable() });
    let results = resumeProgress
      ? [...resumeProgress.results]
      : unresolved.flatMap(route => viewports.map(viewport => ({ route_name: route.name, route_variant: route.variant || 'default', module: route.module, uri: route.uri, url: null, viewport: viewport.name, viewport_width: viewport.width, viewport_height: viewport.height, temperature: 'unresolved', trial: 0, measured: false, readiness: 'not-measured', not_measured_reason: 'No valid isolated fixture ID for required route parameter', ready_error: 'No valid isolated fixture ID for required route parameter', http_status: null, js_errors: [], failed_requests: [], server_errors: [], total_requests: null, transferred_bytes: null })));
    if (resumeProgress) {
      const invalid = results.filter(item => item.route_name === 'operations.show');
      if (invalid.length) {
        fs.mkdirSync(path.join(reportDir, 'runs', runId), { recursive: true });
        fs.writeFileSync(path.join(reportDir, 'runs', runId, 'ERP_PAGE_SPEED_INVALID_FIXTURE.json'), JSON.stringify(invalid.map(item => ({ ...item,
          context_classification: 'The data operation preview belonged to actor 124; OperationService scopes this route to the logged-in actor 133. Excluded from final app failures; targeted route will be remeasured with an actor-owned preview.' })), null, 2) + '\n');
        results = results.filter(item => item.route_name !== 'operations.show');
      }
      saveCheckpoint(runId, results, candidates.length);
    }
    const navigateAndRecord = async (page, route, viewport, temperature, trial) => {
      writeCurrentSample(route, viewport, temperature, trial, 'started', { resumed: !!resumeProgress });
      const sample = await measurePage(page, baseUrl, route, viewport, temperature, trial);
      results.push(sample);
      writeCurrentSample(route, viewport, temperature, trial, 'completed', { measured: sample.measured, http_status: sample.http_status, ready_error: sample.ready_error });
      if (results.length % 5 === 0) saveCheckpoint(runId, results, candidates.length);
      return sample;
    };
    for (const viewport of viewports) {
      for (const route of resolved) {
        const sameCase = item => item.route_name === route.name && (item.route_variant || 'default') === (route.variant || 'default') && item.viewport === viewport.name;
        const existingNav = results.filter(item => sameCase(item) && ['cold','warm'].includes(item.temperature));
        const hasCold = existingNav.some(item => item.temperature === 'cold');
        const priorWarmTrials = new Set(existingNav.filter(item => item.temperature === 'warm').map(item => Number(item.trial)));
        const hasAllWarm = [1, 2, 3].every(trial => priorWarmTrials.has(trial));
        const transitionDone = results.some(item => sameCase(item) && item.temperature === 'transition');
        if (hasCold && hasAllWarm) {
          if (!transitionDone) {
            results.push(...await measureSpaTransition(browser, baseUrl, storageState, viewport, route));
            if (results.length % 5 === 0) saveCheckpoint(runId, results, candidates.length);
          }
          continue;
        }
        const coldContext = await browser.newContext({ baseURL: baseUrl, storageState, locale: 'ar-EG', timezoneId: 'Africa/Cairo', viewport });
        const coldPage = await coldContext.newPage();
        if (existingNav.length) {
          // A resumed context has an empty cache. Prime it without recording a
          // fabricated warm sample before collecting any missing warm trials.
          writeCurrentSample(route, viewport, 'resume-prime', 0, 'started', { resumed: true });
          await coldPage.goto(`${baseUrl}${route.url}`, { waitUntil: 'domcontentloaded', timeout: 45_000 }).catch(() => {});
          writeCurrentSample(route, viewport, 'resume-prime', 0, 'completed', { recorded_as_sample: false });
        }
        if (!hasCold) await navigateAndRecord(coldPage, route, viewport, 'cold', 0);
        for (let trial = 1; trial <= 3; trial++) {
          if (priorWarmTrials.has(trial)) continue;
          const warmSample = await navigateAndRecord(coldPage, route, viewport, 'warm', trial);
          // Persist real Laravel cookie renewal from an authorized response so
          // later contexts do not keep reusing an expired authentication state.
          if (!warmSample.redirected_to_login) await coldContext.storageState({ path: storageState });
        }
        await coldContext.close();
        if (!transitionDone) {
          results.push(...await measureSpaTransition(browser, baseUrl, storageState, viewport, route));
          if (results.length % 5 === 0) saveCheckpoint(runId, results, candidates.length);
        }
        if ((results.length % 20) === 0) process.stdout.write(JSON.stringify({ progress: results.length, viewport: viewport.name, route: route.name }) + '\n');
      }
    }
    const timed = results.filter(item => item.measured && item.temperature === 'warm');
    const grouped = new Map();
    for (const item of timed) {
      const key = `${item.route_name}|${item.route_variant || 'default'}`;
      const row = grouped.get(key) || { key, medians: {} };
      row.medians[item.viewport] = median(timed.filter(sample => `${sample.route_name}|${sample.route_variant || 'default'}` === key && sample.viewport === item.viewport).map(sample => sample.ui_ready_ms));
      grouped.set(key, row);
    }
    const ranked = [...grouped.values()].map(item => ({ ...item, score: Math.max(item.medians.desktop || 0, item.medians.mobile || 0) }))
      .sort((a,b) => b.score - a.score).slice(0,20);
    const diagnosticRoutes = ranked.map(item => resolved.find(route => `${route.name}|${route.variant || 'default'}` === item.key)).filter(Boolean);
    const diagnosticResults = [];
    for (const [index, route] of diagnosticRoutes.entries()) {
      const viewport = viewports[0];
      const context = await browser.newContext({ baseURL: baseUrl, storageState, locale: 'ar-EG', timezoneId: 'Africa/Cairo', viewport });
      const page = await context.newPage();
      diagnosticResults.push(await measurePage(page, baseUrl, route, viewport, 'diagnostic', 1, true));
      await context.close();
      if ((index + 1) % 5 === 0) process.stdout.write(JSON.stringify({ phase: 'query-profiling', completed: index + 1, total: diagnosticRoutes.length }) + '\n');
    }
    const sourceFingerprintEnd = sourceFingerprint();
    const excludedRoutes = routes.filter(route => !isPageRoute(route) && exclusionReason(route)).map(route => ({ name: route.name || null, uri: route.uri, method: route.method, action: route.action, reason: exclusionReason(route) }));
    const payload = { routes, candidates, results, diagnostic_results: diagnosticResults, excluded_routes: excludedRoutes, conditions: { generated_run_id: runId, db_host: '127.0.0.1', db_port: bootstrap.dbPort, db_name: bootstrap.dbName, base_url: baseUrl, browser: 'Microsoft Edge via Playwright', server: 'local PHP built-in server', network_throttling: 'none', source_fingerprint_start: sourceFingerprintStart, source_fingerprint_end: sourceFingerprintEnd, source_unchanged: sourceFingerprintStart === sourceFingerprintEnd, warm_trials: 3, warmups: 1, viewports: { desktop: viewports[0], mobile: viewports[1] } } };
    const report = writeReports(payload);
    process.stdout.write(JSON.stringify({ status: 'complete', discovered_get_routes: routes.filter(route => String(route.method || '').includes('GET')).length, candidate_pages: candidates.length, measured_route_viewports: report.route_summaries.length, raw_samples: report.raw_results.length, not_measured: report.not_measured.length, seeders: seedReport.map(item => `${item.seeder}:${item.status}`), report: path.join(reportDir, 'ERP_PAGE_SPEED_REPORT.md') }) + '\n');
  } finally {
    if (browser) await browser.close().catch(() => {});
    if (server.exitCode === null) server.kill();
    try { fs.closeSync(serverLog); } catch {}
  }
}

function rebuildLatestReports() {
  const existing = JSON.parse(fs.readFileSync(path.join(reportDir, 'ERP_PAGE_SPEED_RESULTS.json'), 'utf8'));
  const discoveredRoutes = JSON.parse(fs.readFileSync(path.join(workDir, 'audit-routes.json'), 'utf8'));
  for (const item of existing.raw_results.filter(row => row.temperature === 'unresolved' && !row.route_name)) {
    const route = discoveredRoutes.find(candidate => candidate.uri === item.uri && isPageRoute(candidate));
    if (!route) continue;
    item.route_name = route.name;
    const enumParameter = Object.keys(routeVariants(route)[0] || {})[0];
    item.route_variant = enumParameter ? `unresolved-${enumParameter}` : 'unresolved-parameter';
    item.module = moduleName(route);
  }
    for (const item of existing.raw_results) {
      const route = discoveredRoutes.find(candidate => candidate.name === item.route_name);
      if (route) item.module = moduleName(route);
    }
  const binaryCases = existing.raw_results.filter(item => routeExclusions.test(item.route_name || ''));
  const binaryNames = new Map();
  for (const item of binaryCases) binaryNames.set(item.route_name, item);
  existing.raw_results = existing.raw_results.filter(item => !routeExclusions.test(item.route_name || ''));
  existing.diagnostic_results = (existing.diagnostic_results || []).filter(item => !routeExclusions.test(item.route_name || ''));
  existing.excluded_routes = [...(existing.excluded_routes || []), ...[...binaryNames.values()].map(item => ({ name: item.route_name, uri: item.uri, method: 'GET', action: null,
    reason: 'PDF or binary file response route, excluded after response behavior review' }))];
  const excludedCaseCount = [...binaryNames.keys()].reduce((count, name) => {
    const route = discoveredRoutes.find(candidate => candidate.name === name);
    return count + (route ? routeVariants(route).length : 1);
  }, 0);
  for (const item of existing.raw_results) {
    const rendered = item.readiness === 'passed' && item.http_status === 200 && !item.redirected_to_login && !(item.redirect_chain || []).length
      && !item.js_errors?.length && !item.failed_requests?.length && !item.server_errors?.length && item.ui_ready_ms !== null;
    item.measured = rendered;
    item.healthy = rendered && !(item.http_errors || []).length;
    if (rendered) item.not_measured_reason = null;
    else if (item.http_status !== 200) item.not_measured_reason = `HTTP ${item.http_status ?? 'unknown'}`;
    else if (item.redirected_to_login || (item.redirect_chain || []).length) item.not_measured_reason = 'Redirected away from the requested route';
    else if (item.js_errors?.length) item.not_measured_reason = 'Uncaught JavaScript error observed';
    else if (item.failed_requests?.length) item.not_measured_reason = 'Failed browser request observed';
    else if (item.server_errors?.length) item.not_measured_reason = 'Server error response observed';
  }
  const uniqueRoutes = existing.discovery.candidate_pages - binaryNames.size;
  const cases = existing.discovery.candidate_route_cases - excludedCaseCount;
  const payload = {
    routes: Array.from({ length: existing.discovery.total_get_routes }, () => ({ method: 'GET' })),
    candidates: Array.from({ length: cases }, (_, index) => ({ name: `route-${index < uniqueRoutes ? index : index % uniqueRoutes}` })),
    results: existing.raw_results, diagnostic_results: existing.diagnostic_results, excluded_routes: existing.excluded_routes,
    conditions: existing.conditions, seeders: existing.seeders || [],
  };
  const report = writeReports(payload);
  process.stdout.write(JSON.stringify({ status: 'reports-rebuilt', run_id: report.conditions.generated_run_id, measured: report.discovery.healthy_samples + report.discovery.degraded_samples,
    healthy: report.discovery.healthy_samples, degraded: report.discovery.degraded_samples, report: path.join(reportDir, 'ERP_PAGE_SPEED_REPORT.md') }) + '\n');
}

if (require.main === module) {
  if (process.argv.includes('--rebuild-latest-reports')) rebuildLatestReports();
  else main().catch(error => { process.stderr.write(`${error.stack || error}\n`); process.exitCode = 1; });
}

module.exports = { measurePage, browserExecutable, waitForServer, saveCheckpoint, writeReports, sourceFingerprint, moduleName, isPageRoute, exclusionReason, routeVariants, routeExclusions,
  routeUrl, fixtureIds, paths: { root, workDir, reportDir, runDir: path.join(reportDir, 'runs'), routeSnapshot, storageState } };
