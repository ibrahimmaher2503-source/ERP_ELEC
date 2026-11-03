'use strict';

// Focused native Debugbar/Pulse diagnosis against the already-owned 55461 DB.
// No migrations, seeding, resets, business writes, request payload logging, or
// custom SQL listeners are performed here. Debugbar's complete private profile
// stays under .tmp; this runner emits only timing/count summaries.
const fs = require('node:fs');
const path = require('node:path');
const net = require('node:net');
const crypto = require('node:crypto');
const { spawn, spawnSync } = require('node:child_process');
const { chromium } = require('playwright');
const { measurePage } = require('./page-speed-audit.cjs');

const root = path.resolve(__dirname, '../..');
const work = path.join(root, '.tmp/page-speed-audit');
const bootstrapPath = path.join(work, 'bootstrap.json');
const bootstrap = JSON.parse(fs.readFileSync(bootstrapPath, 'utf8'));
const markerPath = path.join(work, 'postgres-55461/codex-owned.json');
const marker = JSON.parse(fs.readFileSync(markerPath, 'utf8'));
const expectedToken = bootstrap.env?.E2E_CLUSTER_TOKEN || '';
const pgBin = path.join(root, '.tools/postgresql/pgsql/bin');
const psql = path.join(pgBin, 'psql.exe');
const routes = [
  { name: 'dashboard', url: '/dashboard', ready: '#main-content h1, #main-content [role="heading"]', module: 'core', uri: 'dashboard' },
  { name: 'organization.user-details', dynamic: 'actor', ready: '#main-content h1, #main-content [role="heading"]' },
  { name: 'maintenance.work-order', ready: '#main-content h1, #main-content [role="heading"]', dynamic: 'workOrder' },
  { name: 'maintenance.index', url: '/maintenance', ready: '#main-content h1, #main-content [role="heading"]', module: 'M08', uri: 'maintenance' },
  { name: 'customers.index', url: '/customers', ready: 'input[wire\\:model.live.debounce.300ms="search"]', module: 'M03', uri: 'customers' },
];
routes[1].module = 'M01'; routes[1].uri = 'organization/users/{actor}';
routes[2].module = 'M08'; routes[2].uri = 'maintenance/work-orders/{workOrder}';

function assertOwnedTarget() {
  if (bootstrap.dbName !== 'erp_elec_new_test' || bootstrap.dbPort !== 55461
      || bootstrap.env?.DB_CONNECTION !== 'pgsql' || bootstrap.env?.DB_HOST !== '127.0.0.1'
      || bootstrap.env?.DB_PORT !== '55461' || bootstrap.env?.DB_DATABASE !== 'erp_elec_new_test'
      || bootstrap.env?.E2E_DB_PORT !== '55461' || bootstrap.env?.E2E_DB_DATABASE !== 'erp_elec_new_test'
      || !String(bootstrap.storage || '').replaceAll('\\', '/').includes('/.tmp/page-speed-audit/')) {
    throw new Error('Refusing profiling outside the configured owned PostgreSQL target/private storage.');
  }
  if (marker.kind !== 'codex-e2e-postgres' || marker.repo !== root || marker.port !== 55461 || marker.version !== 1
      || bootstrap.clusterMarker !== markerPath || expectedToken.length < 32
      || marker.token !== expectedToken || !crypto.timingSafeEqual(Buffer.from(marker.token), Buffer.from(expectedToken))) {
    throw new Error('Refusing profiling because the owned PostgreSQL marker does not match this workspace.');
  }
  if (!fs.existsSync(psql)) throw new Error('Owned PostgreSQL psql client is unavailable.');
}

function sqlScalar(query) {
  const env = { ...process.env, ...bootstrap.env };
  const result = spawnSync(psql, ['-w', '-h', '127.0.0.1', '-p', '55461', '-U', env.DB_USERNAME || 'erp_e2e',
    '-d', 'erp_elec_new_test', '-Atqc', query], { cwd: root, env, encoding: 'utf8', windowsHide: true, maxBuffer: 1024 * 1024 });
  if (result.error || result.status !== 0) throw new Error('Owned PostgreSQL identity/preflight query failed.');
  return result.stdout.trim();
}

function loadResumeBaseline() {
  const index = process.argv.indexOf('--resume-baseline');
  if (index < 0) return null;
  const candidate = path.resolve(process.argv[index + 1] || '');
  const allowed = path.resolve(work, 'native-profile') + path.sep;
  if (!candidate.startsWith(allowed) || !fs.existsSync(candidate)) throw new Error('Resume baseline must be an existing private native-profile checkpoint.');
  const value = JSON.parse(fs.readFileSync(candidate, 'utf8'));
  if (value.target?.database !== 'erp_elec_new_test' || Number(value.target?.port) !== 55461
      || !Array.isArray(value.baseline) || value.baseline.length !== 20
      || !value.search || value.search.status !== 200) throw new Error('Private baseline checkpoint failed its database or sample-count validation.');
  return value;
}

function freePort() {
  return new Promise((resolve, reject) => {
    const server = net.createServer(); server.once('error', reject);
    server.listen(0, '127.0.0.1', () => { const port = server.address().port; server.close(() => resolve(port)); });
  });
}

function mkdirPrivate(dir) { fs.mkdirSync(dir, { recursive: true, mode: 0o700 }); }

function makeRuntime(mode, baseUrl) {
  const runtime = path.join(work, 'native-profile', process.env.NATIVE_PROFILE_RUN_ID || 'unassigned', mode);
  const storage = path.join(runtime, 'storage');
  const views = path.join(runtime, 'views');
  for (const dir of [runtime, storage, views, path.join(storage, 'framework/cache'),
    path.join(storage, 'framework/sessions'), path.join(storage, 'framework/views'),
    path.join(storage, 'app/private'), path.join(storage, 'logs'), path.join(storage, 'debugbar')]) mkdirPrivate(dir);
  // Session data and Debugbar files stay private to this server phase.
  const originalPrivate = path.join(bootstrap.storage, 'app/private');
  const isolatedPrivate = path.join(storage, 'app/private');
  if (fs.existsSync(originalPrivate) && fs.readdirSync(isolatedPrivate).length === 0) {
    fs.rmdirSync(isolatedPrivate);
    try { fs.symlinkSync(originalPrivate, isolatedPrivate, 'junction'); }
    catch { mkdirPrivate(isolatedPrivate); }
  }
  const env = {
    ...bootstrap.env,
    APP_ENV: mode === 'debugbar' ? 'local' : 'testing', APP_URL: baseUrl, APP_DEBUG: mode === 'debugbar' ? 'true' : 'false',
    LARAVEL_STORAGE_PATH: storage, E2E_APP_STORAGE: storage, VIEW_COMPILED_PATH: views,
    APP_CONFIG_CACHE: path.relative(root, path.join(runtime, 'config.php')).replaceAll('\\', '/'),
    DEBUGBAR_ENABLED: mode === 'debugbar' ? 'true' : 'false',
    PULSE_ENABLED: mode === 'pulse' ? 'true' : 'false',
    PULSE_PATH: 'pulse',
  };
  return { runtime, storage, env };
}

function cacheConfig(env) {
  const result = spawnSync('php', ['artisan', 'config:cache'], { cwd: root, env, encoding: 'utf8', windowsHide: true, maxBuffer: 4 * 1024 * 1024 });
  if (result.error || result.status !== 0) throw new Error('Private per-phase Laravel config cache could not be prepared.');
}

function startServer(baseUrl, env, logFile) {
  const fd = fs.openSync(logFile, 'w');
  const server = spawn('php', ['-S', new URL(baseUrl).host, '-t', '.', path.join(root, 'tests/E2E/support/performance-router.php')], {
    cwd: path.join(root, 'public'), env, stdio: ['ignore', fd, fd], windowsHide: true,
  });
  return { server, fd };
}

async function waitReady(baseUrl, server) {
  const deadline = Date.now() + 20_000;
  while (Date.now() < deadline) {
    if (server.exitCode !== null) throw new Error('Private profiling server exited before readiness.');
    try { const response = await fetch(`${baseUrl}/login`); if (response.status < 500) return; } catch {}
    await new Promise(resolve => setTimeout(resolve, 200));
  }
  throw new Error('Private loopback Laravel server did not become ready.');
}

function closeServer(record) {
  if (!record) return;
  if (record.server.exitCode === null) record.server.kill();
  try { fs.closeSync(record.fd); } catch {}
}

function median(values) {
  const sorted = values.filter(Number.isFinite).sort((a, b) => a - b);
  if (!sorted.length) return null;
  const mid = Math.floor(sorted.length / 2);
  return Number((sorted.length % 2 ? sorted[mid] : (sorted[mid - 1] + sorted[mid]) / 2).toFixed(2));
}

async function navigateSample(context, baseUrl, route, temperature, trial, debugbar = false, profileEnv = {}) {
  const page = await context.newPage();
  let debugbarId = null;
  try {
    page.on('response', response => {
      if (new URL(response.url()).pathname === new URL(route.url, baseUrl).pathname) {
        debugbarId = response.headers()['phpdebugbar-id'] || null;
      }
    });
    const metrics = await measurePage(page, baseUrl, route, { name: 'desktop', width: 1440, height: 900 }, temperature, trial, false);
    if (debugbar && !debugbarId) debugbarId = findDebugbarId(profileEnv, route.url);
    const profile = debugbar && debugbarId ? nativeDebugbarProfile(debugbarId, profileEnv) : null;
    return { route: route.name, temperature, trial, http_status: metrics.http_status,
      measured: metrics.measured, healthy: metrics.healthy, ui_ready_ms: metrics.ui_ready_ms,
      ttfb_ms: metrics.ttfb_ms, domcontentloaded_ms: metrics.domcontentloaded_ms,
      request_count: metrics.total_requests, transferred_bytes: metrics.transferred_bytes,
      debugbar_id_present: debugbar ? Boolean(debugbarId) : undefined,
      debugbar: profile };
  } finally { await page.close(); }
}

async function runRoutes(browser, baseUrl, state, reps, phase, profileEnv = {}, actorId) {
  const results = [];
  for (const route of routes) {
    if (route.dynamic === 'workOrder') {
      const id = sqlScalar('SELECT id FROM maintenance_work_orders ORDER BY id LIMIT 1');
      if (!/^\d+$/.test(id)) throw new Error('Owned database has no real maintenance work order for the required detail route.');
      route.url = `/maintenance/work-orders/${id}`;
    }
    if (route.dynamic === 'actor') {
      if (!/^\d+$/.test(String(actorId))) throw new Error('Auth refresh helper did not return a valid actor ID.');
      route.url = `/organization/users/${actorId}`;
    }
    const context = await browser.newContext({ storageState: state, locale: 'ar-EG', timezoneId: 'Africa/Cairo', viewport: { width: 1440, height: 900 } });
    try {
      for (let trial = 0; trial < reps; trial++) {
        const temperature = trial === 0 ? 'cold' : 'warm';
        results.push(await navigateSample(context, baseUrl, route, temperature, trial + 1, phase === 'debugbar', profileEnv));
      }
    } finally {
      // Reuse the same browser context's HTTP cache while isolating each page's
      // navigation timing; per-sample context changes would invalidate warm trials.
      await context.close();
    }
  }
  return results;
}

async function searchRequest(browser, baseUrl, state, debugbar = false, profileEnv = {}) {
  const context = await browser.newContext({ storageState: state, locale: 'ar-EG', timezoneId: 'Africa/Cairo',
    viewport: { width: 1440, height: 900 } });
  const page = await context.newPage();
  let requestCount = 0, status = null, debugbarId = null;
  page.on('request', request => { if (/\/livewire(?:-[^/]+)?\/update/i.test(new URL(request.url()).pathname)) requestCount++; });
  page.on('response', response => {
    if (/\/livewire(?:-[^/]+)?\/update/i.test(new URL(response.url()).pathname)) debugbarId = response.headers()['phpdebugbar-id'] || null;
  });
  try {
    await page.goto(`${baseUrl}/customers`, { waitUntil: 'domcontentloaded', timeout: 25_000 });
    const input = page.getByRole('textbox', { name: /البحث في/ }).first();
    await input.waitFor({ state: 'visible', timeout: 10_000 });
    const responsePromise = page.waitForResponse(response => /\/livewire(?:-[^/]+)?\/update/i.test(new URL(response.url()).pathname), { timeout: 10_000 });
    await input.fill('DEMO-EG');
    const response = await responsePromise;
    status = response.status();
    await page.locator('[wire\\:loading]').first().waitFor({ state: 'hidden', timeout: 10_000 }).catch(() => {});
    await page.getByText(/نتيجة/).first().waitFor({ state: 'visible', timeout: 5_000 });
    if (debugbar && !debugbarId) debugbarId = findDebugbarId(profileEnv, '/livewire/update', 'POST');
    return { status, livewire_update_requests: requestCount, results_summary_visible: true,
      debugbar_id_present: debugbar ? Boolean(debugbarId) : undefined,
      debugbar: debugbar && debugbarId ? nativeDebugbarProfile(debugbarId, profileEnv) : null };
  } finally { await context.close(); }
}

function commandJson(args, env) {
  const result = spawnSync('php', ['artisan', ...args], { cwd: root, env, encoding: 'utf8', windowsHide: true, maxBuffer: 16 * 1024 * 1024 });
  if (result.error || result.status !== 0) return { ok: false, command: args[0], exit_code: result.status ?? null };
  try { return { ok: true, value: JSON.parse(result.stdout.trim()) }; }
  catch { return { ok: true, value: null }; }
}

function nativeDebugbarProfile(id, env) {
  const get = commandJson(['debugbar:get', id, '--json'], env);
  const queries = commandJson(['debugbar:queries', id, '--json'], env);
  const queryValue=queries.value??get.value;
  return { get_ok: get.ok, queries_ok: queries.ok, query_summary: inspectQueryJson(queryValue),
    profile_meta: get.value?.__meta ? { id: get.value.__meta.id || id, uri: get.value.__meta.uri || null, method: get.value.__meta.method || null } : null,
    ...(env.NATIVE_PROFILE_CAPTURE_SQL==='1'?{private_statements:(get.value?.queries?.statements||[]).map(({sql,type,params,duration,connection})=>({sql,type,params,duration,connection}))}:{}) };
}

async function confirmPasswordIfNeeded(browser, baseUrl, statePath, credentials) {
  const context=await browser.newContext({storageState:statePath,locale:'ar-EG',timezoneId:'Africa/Cairo',viewport:{width:1440,height:900}});
  try {
    const page=await context.newPage();
    await page.goto(`${baseUrl}/settings/security`,{waitUntil:'domcontentloaded',timeout:20000});
    if(/confirm-password/.test(new URL(page.url()).pathname)){
      await page.locator('input[name="password"]').fill(credentials.password);
      await Promise.all([page.waitForURL(/\/settings\/security(?:\?|$)/,{waitUntil:'domcontentloaded',timeout:20000}),page.locator('[data-test="confirm-password-button"]').click()]);
      if(/confirm-password/.test(new URL(page.url()).pathname))throw new Error('Password confirmation did not complete for the native diagnostic actor.');
      await context.storageState({path:statePath});
    }
  } finally { await context.close(); }
}

function findDebugbarId(env, uri, method = 'GET') {
  const result = commandJson(['debugbar:find', `--uri=${uri}`, `--method=${method}`, '--max=1', '--json'], env);
  return result.ok && Array.isArray(result.value) && result.value.length ? String(result.value[0].id || '') || null : null;
}

function migratePulseOnly(env) {
  const relativeMigration = 'database/monitoring/2026_10_31_180448_create_pulse_tables.php';
  const migration = path.resolve(root, relativeMigration);
  if (!migration.startsWith(`${path.resolve(root)}${path.sep}`) || !fs.existsSync(migration)
      || env.DB_CONNECTION !== 'pgsql' || env.DB_HOST !== '127.0.0.1' || env.DB_PORT !== '55461'
      || env.DB_DATABASE !== 'erp_elec_new_test' || env.E2E_DB_PORT !== '55461'
      || env.E2E_DB_DATABASE !== 'erp_elec_new_test'
      || !String(env.E2E_APP_STORAGE || '').replaceAll('\\', '/').includes('/.tmp/page-speed-audit/')) {
    throw new Error('Refusing Pulse schema setup outside the owned isolated target.');
  }
  const run = spawnSync('php', ['artisan', 'migrate', `--path=${relativeMigration}`, '--force'], {
    cwd: root, env, encoding: 'utf8', windowsHide: true, maxBuffer: 4 * 1024 * 1024,
  });
  if (run.error || run.status !== 0) throw new Error('The guarded Pulse-only published migration failed.');
  return { migration: path.basename(relativeMigration), applied: true };
}

async function loginAndSave(browser, baseUrl, credentials, outputPath) {
  const context = await browser.newContext({ locale: 'ar-EG', timezoneId: 'Africa/Cairo', viewport: { width: 1440, height: 900 } });
  const page = await context.newPage();
  try {
    await page.goto(`${baseUrl}/login`, { waitUntil: 'domcontentloaded', timeout: 20_000 });
    await page.locator('input[name="email"]').fill(credentials.email);
    await page.locator('input[name="password"]').fill(credentials.password);
    await page.locator('[data-test="login-button"]').click();
    await page.waitForURL(url => url.pathname !== '/login', { timeout: 15_000 });
    await context.storageState({ path: outputPath });
    try { fs.chmodSync(outputPath, 0o600); } catch {}
    return outputPath;
  } finally { await context.close(); }
}

function provisionBasicActor(env) {
  const php = String.raw`
require 'vendor/autoload.php';
$root = getcwd();
$marker = json_decode(file_get_contents($root.'/.tmp/page-speed-audit/postgres-55461/codex-owned.json'), true, 512, JSON_THROW_ON_ERROR);
if (($marker['kind'] ?? null) !== 'codex-e2e-postgres' || ($marker['repo'] ?? null) !== $root || (int) ($marker['port'] ?? 0) !== 55461
    || getenv('E2E_DB_PORT') !== '55461' || getenv('E2E_DB_DATABASE') !== 'erp_elec_new_test'
    || getenv('DB_CONNECTION') !== 'pgsql' || getenv('DB_HOST') !== '127.0.0.1' || getenv('DB_PORT') !== '55461'
    || getenv('DB_DATABASE') !== 'erp_elec_new_test' || ! hash_equals((string) ($marker['token'] ?? ''), (string) getenv('E2E_CLUSTER_TOKEN'))) {
    throw new RuntimeException('owned fixture guard failed');
}
$app = require 'bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->useStoragePath(getenv('E2E_APP_STORAGE'));
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (\Illuminate\Support\Facades\DB::connection()->getDatabaseName() !== 'erp_elec_new_test'
    || (int) \Illuminate\Support\Facades\DB::selectOne('select current_setting($$port$$) as port')->port !== 55461
    || realpath((string) \Illuminate\Support\Facades\DB::scalar('show data_directory')) !== realpath($root.'/.tmp/page-speed-audit/postgres-55461')) {
    throw new RuntimeException('owned database identity failed');
}
$password = \Illuminate\Support\Str::random(48);
$user = \App\Models\User::factory()->create([
    'name' => 'DEMO-EG native Pulse denied '.\Illuminate\Support\Str::upper(\Illuminate\Support\Str::random(8)),
    'email' => 'native-pulse-basic-'.\Illuminate\Support\Str::uuid().'@example.test',
    'password' => \Illuminate\Support\Facades\Hash::make($password), 'active' => true, 'email_verified_at' => now(),
]);
echo json_encode(['id' => (int) $user->id, 'email' => $user->email, 'password' => $password], JSON_THROW_ON_ERROR);
`;
  const result = spawnSync('php', ['-r', php], { cwd: root, env, encoding: 'utf8', windowsHide: true, maxBuffer: 1024 * 1024 });
  if (result.error || result.status !== 0) throw new Error('Could not prepare the isolated no-grant Pulse denial actor.');
  try { return JSON.parse(result.stdout.trim()); } catch { throw new Error('No-grant Pulse denial actor setup returned an invalid private response.'); }
}

function inspectQueryJson(value) {
  if (!value || typeof value !== 'object') return { query_count: null, accumulated_duration_ms: null, duplicate_group_count: null, n_plus_one_group_count: null };
  const statements = Array.isArray(value.statements) ? value.statements : [];
  return {
    query_count: Number(value.nb_statements ?? statements.length),
    accumulated_duration_ms: value.accumulated_duration === null || value.accumulated_duration === undefined
      ? null : Number((Number(value.accumulated_duration) * 1000).toFixed(2)),
    duplicate_group_count: Array.isArray(value.duplicate_groups) ? value.duplicate_groups.length : null,
    n_plus_one_group_count: Array.isArray(value.n_plus_one_groups) ? value.n_plus_one_groups.length : null,
    failed_statement_count: Number(value.nb_failed_statements ?? 0),
    top_statement_durations_ms: statements.map(statement => Number(statement.duration ?? 0))
      .filter(Number.isFinite).sort((a, b) => b - a).slice(0, 5).map(value => Number((value * 1000).toFixed(2))),
  };
}

function summarizeExistingProfiles() {
  assertOwnedTarget();
  const profileArg = process.argv.indexOf('--summarize-existing');
  const summaryArg = process.argv.indexOf('--summary-file');
  const profileDir = path.resolve(process.argv[profileArg + 1] || '');
  const summaryPath = path.resolve(process.argv[summaryArg + 1] || '');
  const allowed = path.resolve(work, 'native-profile') + path.sep;
  if (profileArg < 0 || summaryArg < 0 || !profileDir.startsWith(allowed) || !summaryPath.startsWith(allowed)
      || !fs.existsSync(path.join(profileDir, 'config.php')) || !fs.existsSync(summaryPath)) {
    throw new Error('Readback requires private existing native profile and summary paths.');
  }
  const profileStorage = path.join(profileDir, 'storage');
  const env = { ...bootstrap.env, APP_ENV: 'local', APP_DEBUG: 'true', DEBUGBAR_ENABLED: 'true', PULSE_ENABLED: 'false',
    LARAVEL_STORAGE_PATH: profileStorage, E2E_APP_STORAGE: profileStorage,
    APP_CONFIG_CACHE: path.relative(root, path.join(profileDir, 'config.php')).replaceAll('\\', '/') };
  const summary = JSON.parse(fs.readFileSync(summaryPath, 'utf8'));
  const profileRoutes = summary.debugbar.map(row => ({ route: row.route, uri: row.route === 'dashboard' ? '/dashboard'
    : row.route === 'organization.user-details' ? '/organization/users/*'
      : row.route === 'maintenance.work-order' ? '/maintenance/work-orders/*'
        : row.route === 'maintenance.index' ? '/maintenance' : '/customers' }));
  const read = profileRoutes.map(route => {
    const id = findDebugbarId(env, route.uri);
    return id ? { route: route.route, ...nativeDebugbarProfile(id, env) } : { route: route.route, get_ok: false, queries_ok: false,
      query_summary: inspectQueryJson(null) };
  });
  let livewireId = findDebugbarId(env, '/livewire/update', 'POST');
  if (!livewireId) {
    const candidates = commandJson(['debugbar:find', '--max=50', '--json'], env);
    const row = Array.isArray(candidates.value) ? candidates.value.findLast(item => item.method === 'POST'
      && String(item.uri || '').toLowerCase().includes('livewire') && Number(item.queries || 0) > 0) : null;
    livewireId = row?.id ? String(row.id) : null;
  }
  const livewire = livewireId ? nativeDebugbarProfile(livewireId, env) : { get_ok: false, queries_ok: false,
    query_summary: inspectQueryJson(null) };
  if (read.some(row => !row.get_ok || !row.queries_ok || row.query_summary.query_count < 1)
      || !livewire.get_ok || !livewire.queries_ok || livewire.query_summary.query_count < 1) {
    const missing = read.filter(row => !row.get_ok || !row.queries_ok || row.query_summary.query_count < 1)
      .map(row => ({ route: row.route, get_ok: row.get_ok, queries_ok: row.queries_ok, query_count: row.query_summary.query_count }));
    if (!livewire.get_ok || !livewire.queries_ok || livewire.query_summary.query_count < 1) missing.push({ route: 'livewire.search',
      get_ok: livewire.get_ok, queries_ok: livewire.queries_ok, query_count: livewire.query_summary.query_count });
    throw new Error('Read-only native Debugbar CLI recovery incomplete: '+JSON.stringify(missing));
  }
  const profilesByRoute = new Map(read.map(row => [row.route, row]));
  for (const row of summary.debugbar) {
    const profile = profilesByRoute.get(row.route);
    row.debugbar = { get_ok: profile.get_ok, queries_ok: profile.queries_ok, query_summary: profile.query_summary };
    row.debugbar_id_present = true;
  }
  summary.debugbar_livewire_search.debugbar = livewire;
  summary.summary.instrumented_query_counts = Object.fromEntries(read.map(row => [row.route, row.query_summary.query_count]));
  summary.summary.instrumented_database_duration_ms = Object.fromEntries(read.map(row => [row.route, row.query_summary.accumulated_duration_ms]));
  summary.summary.livewire_query_count = livewire.query_summary.query_count;
  summary.summary.livewire_database_duration_ms = livewire.query_summary.accumulated_duration_ms;
  fs.writeFileSync(summaryPath, JSON.stringify(summary, null, 2) + '\n', { mode: 0o600 });
  process.stdout.write(JSON.stringify({ status: 'readback-complete', profiles: read.map(row => ({ route: row.route,
    query_count: row.query_summary.query_count, database_duration_ms: row.query_summary.accumulated_duration_ms,
    duplicate_group_count: row.query_summary.duplicate_group_count, n_plus_one_group_count: row.query_summary.n_plus_one_group_count })),
    livewire: { query_count: livewire.query_summary.query_count, database_duration_ms: livewire.query_summary.accumulated_duration_ms },
    summary: summaryPath })+'\n');
}

function inspectFindJson(value) {
  if (!Array.isArray(value)) return { flagged_request_count: null, query_total: null, failed_query_total: null };
  return { flagged_request_count: value.length,
    query_total: value.reduce((sum, row) => sum + Number(row.queries || 0), 0),
    failed_query_total: value.reduce((sum, row) => sum + Number(row.failed_queries || 0), 0),
    exception_total: value.reduce((sum, row) => sum + Number(row.exceptions || 0), 0) };
}

async function probePulse(browser, baseUrl, adminState, basicState = null) {
  const admin = await browser.newContext({ storageState: adminState });
  const guest = await browser.newContext();
  const basic = basicState ? await browser.newContext({ storageState: basicState }) : null;
  try {
    const adminResponse = await admin.request.get(`${baseUrl}/pulse`, { maxRedirects: 0 });
    const guestResponse = await guest.request.get(`${baseUrl}/pulse`, { maxRedirects: 0 });
    const basicResponse = basic ? await basic.request.get(`${baseUrl}/pulse`, { maxRedirects: 0 }) : null;
    return { admin_status: adminResponse.status(), guest_status: guestResponse.status(), basic_status: basicResponse?.status() ?? null };
  } finally { await admin.close(); await guest.close(); if (basic) await basic.close(); }
}

async function pulseSlowRequest(browser, baseUrl, adminState, actorId) {
  const context = await browser.newContext({ storageState: adminState, locale: 'ar-EG', timezoneId: 'Africa/Cairo',
    viewport: { width: 1440, height: 900 } });
  const page = await context.newPage();
  try {
    const route = { name: 'organization.user-details', module: 'M01', uri: `organization/users/${actorId}`,
      url: `/organization/users/${actorId}`, variant: 'default' };
    const measured = await measurePage(page, baseUrl, route, { name: 'desktop', width: 1440, height: 900 }, 'pulse-probe', 1, false);
    return { status: measured.http_status, measured: measured.measured, ui_ready_ms: measured.ui_ready_ms };
  } finally { await context.close(); }
}

function readPulseEventCounts() {
  const result = sqlScalar("SELECT json_build_object('event_types',coalesce((SELECT json_object_agg(type,n) FROM (SELECT type,count(*)::int AS n FROM pulse_entries GROUP BY type) e),'{}'::json),'slow_request_entries',(SELECT count(*)::int FROM pulse_entries WHERE type='slow_request'),'slow_request_aggregates',(SELECT count(*)::int FROM pulse_aggregates WHERE type='slow_request'))::text");
  return JSON.parse(result || '{}');
}

async function main() {
  assertOwnedTarget();
  const identity = sqlScalar("SELECT current_database() || '|' || current_setting('port') || '|' || current_setting('data_directory')");
  const [database, port, dataDirectory] = identity.split('|');
  if (database !== 'erp_elec_new_test' || port !== '55461'
      || path.resolve(dataDirectory.replaceAll('\\', '/')) !== path.resolve(path.join(work, 'postgres-55461'))) {
    throw new Error('ABORT before application requests: PostgreSQL identity/data directory mismatch.');
  }
  const routeCount = sqlScalar('SELECT count(*) FROM maintenance_work_orders');
  if (!/^\d+$/.test(routeCount) || Number(routeCount) < 1) throw new Error('Owned database lacks the required populated work-order detail fixture.');
  const runStamp = `${Date.now()}-${process.pid}`;
  process.env.NATIVE_PROFILE_RUN_ID = runStamp;
  const outputDir = path.join(work, 'native-profile', `evidence-${runStamp}`);
  mkdirPrivate(outputDir);
  const result = { target: { database, port: Number(port), owned_data_directory: true }, baseline: [],
    debugbar: [], pulse: null, search: null, disabled_pulse_status: null };
  // Test-only owner helpers generate credentials in-process; credentials and
  // authenticated storage states remain private and are never emitted.
  const fixtureEnv = { ...bootstrap.env, E2E_APP_STORAGE: bootstrap.storage };
  const adminCredentials = (() => {
    const refreshed = spawnSync('php', ['tests/E2E/support/performance-auth-refresh.php'], {
      cwd: root, env: fixtureEnv, encoding: 'utf8', windowsHide: true, maxBuffer: 1024 * 1024,
    });
    if (refreshed.error || refreshed.status !== 0) throw new Error('Guarded isolated Administrator auth refresh failed.');
    try { return JSON.parse(refreshed.stdout.trim()); } catch { throw new Error('Guarded Administrator helper returned an invalid private response.'); }
  })();
  const resumed = loadResumeBaseline();
  const skipPulseAuth = process.argv.includes('--skip-pulse-auth');
  const skipPulseMigration = process.argv.includes('--skip-pulse-migration');
  const basicCredentials = skipPulseAuth ? null : provisionBasicActor(fixtureEnv);
  let browser;
  const edge = ['C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', 'C:/Program Files/Microsoft/Edge/Application/msedge.exe']
    .find(candidate => fs.existsSync(candidate));
  if (!edge) throw new Error('Installed Edge executable required by Playwright is unavailable.');
  browser = await chromium.launch({ headless: true, executablePath: edge });
  let serverRecord;
  try {
    if (resumed) {
      result.baseline = resumed.baseline;
      result.search = resumed.search;
      result.baseline_checkpoint_reused = true;
      result.pulse = resumed.pulse ?? null;
      result.disabled_pulse_status = resumed.disabled_pulse_status ?? null;
    } else {
      const baselinePort = await freePort();
      const baseUrl = `http://127.0.0.1:${baselinePort}`;
      const baselineRuntime = makeRuntime('baseline', baseUrl);
      cacheConfig(baselineRuntime.env);
      serverRecord = startServer(baseUrl, baselineRuntime.env, path.join(outputDir, 'baseline-server.log'));
      await waitReady(baseUrl, serverRecord.server);
      const baselineState = await loginAndSave(browser, baseUrl, adminCredentials, path.join(outputDir, 'admin-baseline.json'));
      result.baseline = await runRoutes(browser, baseUrl, baselineState, 4, 'baseline', {}, adminCredentials.id);
      result.search = await searchRequest(browser, baseUrl, baselineState);
      const checkpointPath = path.join(outputDir, 'baseline-checkpoint.json');
      fs.writeFileSync(checkpointPath, JSON.stringify({ target: result.target, baseline: result.baseline, search: result.search }, null, 2) + '\n', { mode: 0o600 });
      result.baseline_checkpoint = checkpointPath;
      closeServer(serverRecord); serverRecord = null;
    }
    process.stdout.write(JSON.stringify({ phase: 'baseline.complete', sample_count: result.baseline.length,
      livewire_status: result.search.status, checkpoint_reused: Boolean(resumed) })+'\n');

    const profilePort = await freePort();
    const profileUrl = `http://127.0.0.1:${profilePort}`;
    const profileRuntime = makeRuntime('debugbar', profileUrl);
    cacheConfig(profileRuntime.env);
    serverRecord = startServer(profileUrl, profileRuntime.env, path.join(outputDir, 'debugbar-server.log'));
    await waitReady(profileUrl, serverRecord.server);
    const profileState = await loginAndSave(browser, profileUrl, adminCredentials, path.join(outputDir, 'admin-debugbar.json'));
    const profileEnv = { ...profileRuntime.env, APP_URL: profileUrl, DEBUGBAR_ENABLED: 'true', PULSE_ENABLED: 'false' };
    result.debugbar = await runRoutes(browser, profileUrl, profileState, 1, 'debugbar', profileEnv, adminCredentials.id);
    result.debugbar_livewire_search = await searchRequest(browser, profileUrl, profileState, true, profileEnv);
    if (result.debugbar.some(row => row.http_status !== 200 || !row.debugbar_id_present
        || !row.debugbar?.get_ok || !row.debugbar?.queries_ok || (row.debugbar?.query_summary?.query_count ?? 0) < 1)) {
      throw new Error('Native Debugbar GET profile failed to capture a response ID and nonzero query statements for every route.');
    }
    if (result.debugbar_livewire_search.status !== 200 || result.debugbar_livewire_search.livewire_update_requests !== 1
        || !result.debugbar_livewire_search.debugbar_id_present || !result.debugbar_livewire_search.debugbar?.queries_ok
        || (result.debugbar_livewire_search.debugbar?.query_summary?.query_count ?? 0) < 1) {
      throw new Error('Native Debugbar profile failed the one read-only Livewire search POST.');
    }
    const found = commandJson(['debugbar:find', '--issues', '--json'], profileEnv);
    result.debugbar_cli = { find_issues_command_ok: found.ok, issue_summary: inspectFindJson(found.value) };
    process.stdout.write(JSON.stringify({ phase: 'debugbar.complete', route_profiles: result.debugbar.length,
      captured_ids: result.debugbar.filter(row => row.debugbar_id_present).length,
      profile_query_counts: result.debugbar.map(row => row.debugbar?.query_summary?.query_count ?? null),
      livewire_query_count: result.debugbar_livewire_search.debugbar?.query_summary?.query_count ?? null })+'\n');
    closeServer(serverRecord); serverRecord = null;

    const pulsePort = await freePort();
    const pulseUrl = `http://127.0.0.1:${pulsePort}`;
    const pulseRuntime = makeRuntime('pulse', pulseUrl);
    cacheConfig(pulseRuntime.env);
    if (!skipPulseMigration) result.pulse_migration = migratePulseOnly(pulseRuntime.env);
    serverRecord = startServer(pulseUrl, pulseRuntime.env, path.join(outputDir, 'pulse-server.log'));
    await waitReady(pulseUrl, serverRecord.server);
    const pulseAdminState = await loginAndSave(browser, pulseUrl, adminCredentials, path.join(outputDir, 'admin-pulse.json'));
    if (!skipPulseAuth) {
      const pulseBasicState = await loginAndSave(browser, pulseUrl, basicCredentials, path.join(outputDir, 'basic-pulse.json'));
      result.pulse = await probePulse(browser, pulseUrl, pulseAdminState, pulseBasicState);
    }
    result.pulse_slow_request_probe = await pulseSlowRequest(browser, pulseUrl, pulseAdminState, adminCredentials.id);
    await new Promise(resolve => setTimeout(resolve, 1000));
    result.pulse_event_counts = readPulseEventCounts();
    result.pulse_event_types_outside_policy = Object.keys(result.pulse_event_counts.event_types || {})
      .filter(type => !['slow_request', 'slow_query', 'exception'].includes(type));
    if (Number(result.pulse_event_counts.slow_request_aggregates || 0) < 1) {
      throw new Error('Pulse did not persist a native slow_request aggregate for the actual slow GET probe.');
    }
    process.stdout.write(JSON.stringify({ phase: 'pulse.complete', auth_statuses: result.pulse,
      slow_request_probe: result.pulse_slow_request_probe, slow_request_entries: result.pulse_event_counts.slow_request_entries,
      slow_request_aggregates: result.pulse_event_counts.slow_request_aggregates })+'\n');
    closeServer(serverRecord); serverRecord = null;

    if (!skipPulseAuth) {
      const disabledPort = await freePort();
      const disabledUrl = `http://127.0.0.1:${disabledPort}`;
      const disabledRuntime = makeRuntime('baseline', disabledUrl);
      cacheConfig(disabledRuntime.env);
      serverRecord = startServer(disabledUrl, disabledRuntime.env, path.join(outputDir, 'disabled-server.log'));
      await waitReady(disabledUrl, serverRecord.server);
      const disabledAdminState = await loginAndSave(browser, disabledUrl, adminCredentials, path.join(outputDir, 'admin-disabled.json'));
      const disabled = await probePulse(browser, disabledUrl, disabledAdminState);
      result.disabled_pulse_status = disabled.admin_status;
      closeServer(serverRecord); serverRecord = null;
    }

    result.summary = {
      baseline_routes: result.baseline.length,
      baseline_median_ui_ready_ms: Object.fromEntries([...new Set(result.baseline.map(row => row.route))]
        .map(name => [name, median(result.baseline.filter(row => row.route === name && row.temperature === 'warm').map(row => row.ui_ready_ms))])),
      instrumented_route_count: result.debugbar.length,
      instrumented_query_counts: Object.fromEntries(result.debugbar.map(row => [row.route, row.debugbar?.query_summary?.query_count ?? null])),
      pulse_admin_guest: result.pulse,
      pulse_disabled_status: result.disabled_pulse_status,
      livewire_search: result.search,
    };
    const outputPath = path.join(outputDir, 'summary.json');
    fs.writeFileSync(outputPath, JSON.stringify(result, null, 2) + '\n', { mode: 0o600 });
    process.stdout.write(JSON.stringify({ status: 'complete', output: outputPath, summary: result.summary }) + '\n');
  } finally {
    closeServer(serverRecord);
    if (browser) await browser.close();
  }
}

async function profileRouteManifest() {
  assertOwnedTarget();
  const manifestArg=process.argv.indexOf('--profile-route-manifest');
  const outputArg=process.argv.indexOf('--profile-output');
  const manifestPath=path.resolve(process.argv[manifestArg+1]||'');
  const outputPath=path.resolve(process.argv[outputArg+1]||'');
  const privateRoot=path.resolve(work,'native-profile')+path.sep;
  if(manifestArg<0||outputArg<0||!fs.existsSync(manifestPath)||!outputPath.startsWith(privateRoot)) throw new Error('Manifest SQL profiling requires a route manifest and private output path.');
  const manifest=JSON.parse(fs.readFileSync(manifestPath,'utf8'));
  if(manifest.database?.name!=='erp_elec_new_test'||Number(manifest.database?.port)!==55461||!Array.isArray(manifest.routes)) throw new Error('Manifest SQL profiling target does not match the owned PostgreSQL database.');
  const identity=sqlScalar("SELECT current_database() || '|' || current_setting('port') || '|' || current_setting('data_directory')");
  const [database,port,dataDirectory]=identity.split('|');
  if(database!=='erp_elec_new_test'||port!=='55461'||path.resolve(dataDirectory.replaceAll('\\','/'))!==path.resolve(path.join(work,'postgres-55461'))) throw new Error('SQL profile abort: owned PostgreSQL identity mismatch.');
  const runStamp=`after-${Date.now()}-${process.pid}`;process.env.NATIVE_PROFILE_RUN_ID=runStamp;
  const outputDir=path.dirname(outputPath);mkdirPrivate(outputDir);
  const resumeArg=process.argv.indexOf('--resume-profile');
  const resumePath=resumeArg>=0?path.resolve(process.argv[resumeArg+1]||''):null;
  if(resumePath&&(!resumePath.startsWith(privateRoot)||!fs.existsSync(resumePath)))throw new Error('Native profile resume requires the existing private checkpoint.');
  const prior=resumePath?JSON.parse(fs.readFileSync(resumePath,'utf8')):null;
  const expectedRun=manifest.run_id;
  const expectedCases=new Set(manifest.routes.map(route=>`${route.name}|${route.route_variant||route.variant||'default'}`));
  if(prior&&(prior.target?.database!=='erp_elec_new_test'||Number(prior.target?.port)!==55461
      ||(prior.after_run_id&&prior.after_run_id!==expectedRun)||!Array.isArray(prior.profiles)
      ||prior.profiles.some(row=>!expectedCases.has(row.route_case)||row.http_status!==200)))throw new Error('Native profile checkpoint run/target/route keys do not match this after-run.');
  const result=prior||{run_id:runStamp,after_run_id:expectedRun,target:{database,port:Number(port),owned_data_directory:true},app_env:'local',instrumentation:'Laravel Debugbar CLI on a separate authorized HTTP GET using the same authenticated browser cookies; no UI readiness timing is claimed',profiles:[],invalid_attempts:[]};
  result.invalid_attempts=result.invalid_attempts||[];
  const fixtureEnv={...bootstrap.env,E2E_APP_STORAGE:bootstrap.storage};
  const needsAuth=manifest.routes.some(route=>!route.guest_route);
  let credentials=null;
  if(needsAuth){const refreshed=spawnSync('php',['tests/E2E/support/performance-auth-refresh.php'],{cwd:root,env:fixtureEnv,encoding:'utf8',windowsHide:true,maxBuffer:1024*1024});
    if(refreshed.error||refreshed.status!==0)throw new Error('Guarded isolated Administrator auth refresh failed for SQL diagnostics.');
    try{credentials=JSON.parse(refreshed.stdout.trim());}catch{throw new Error('Synthetic actor helper returned invalid private credentials.');}}
  let browser,serverRecord;
  try{
    const edge=['C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe','C:/Program Files/Microsoft/Edge/Application/msedge.exe'].find(fs.existsSync);
    if(!edge)throw new Error('Installed Edge executable is unavailable.');
    browser=await chromium.launch({headless:true,executablePath:edge});
    const portNumber=await freePort(),baseUrl=`http://127.0.0.1:${portNumber}`;
    const runtime=makeRuntime('debugbar',baseUrl);cacheConfig(runtime.env);
    serverRecord=startServer(baseUrl,runtime.env,path.join(outputDir,'debugbar-server.log'));
    await waitReady(baseUrl,serverRecord.server);
    const statePath=path.join(outputDir,'admin-storage-state.json');
    if(needsAuth)await loginAndSave(browser,baseUrl,credentials,statePath);
    if(needsAuth)await confirmPasswordIfNeeded(browser,baseUrl,statePath,credentials);
    const operationRoute=manifest.routes.find(route=>route.name==='operations.show');
    const operationCase=operationRoute?`${operationRoute.name}|${operationRoute.route_variant||operationRoute.variant||'default'}`:null;
    if(operationRoute&&credentials?.id&&!result.profiles.some(row=>row.route_case===operationCase&&row.profile_captured)){
      const previewEnv={...bootstrap.env,E2E_APP_STORAGE:bootstrap.storage};
      const preview=spawnSync('php',['tests/E2E/support/performance-operation-preview.php',String(credentials.id)],{cwd:root,env:previewEnv,encoding:'utf8',windowsHide:true,maxBuffer:1024*1024});
      if(preview.error||preview.status!==0)throw new Error('Guarded owner-created preview fixture for operations.show failed.');
      const fixture=JSON.parse(preview.stdout.trim());
      operationRoute.url=String(operationRoute.url).replace(/data-operations\/[^/?]+/,`data-operations/${fixture.run_id}`);
      result.operation_preview_fixture={run_id:fixture.run_id,item_count:fixture.item_count,mode:'preview only; operation was not executed'};
    }
    // Complete ordinary Fortify confirmation once so the protected security
    // settings page profiles as the real page instead of its confirmation redirect.
    const confirmContext=await browser.newContext({...(needsAuth?{storageState:statePath}:{}),locale:'ar-EG',timezoneId:'Africa/Cairo',viewport:{width:1440,height:900}});
    try{if(needsAuth){const confirmPage=await confirmContext.newPage();await confirmPage.goto(`${baseUrl}/settings/security`,{waitUntil:'domcontentloaded',timeout:20000});
      if(/confirm-password/.test(new URL(confirmPage.url()).pathname)){await confirmPage.locator('input[name="password"]').fill(credentials.password);
        await Promise.all([confirmPage.waitForURL(/\/settings\/security(?:\?|$)/,{waitUntil:'domcontentloaded',timeout:20000}),confirmPage.locator('[data-test="confirm-password-button"]').click()]);}
      if(/confirm-password/.test(new URL(confirmPage.url()).pathname))throw new Error('Password confirmation was not completed for SQL profiling.');
      await confirmContext.storageState({path:statePath});}
    }finally{await confirmContext.close();}
    const profileEnv={...runtime.env,APP_URL:baseUrl,DEBUGBAR_ENABLED:'true',PULSE_ENABLED:'false',NATIVE_PROFILE_CAPTURE_SQL:process.env.NATIVE_PROFILE_CAPTURE_SQL||''};
    for(const [index,route] of manifest.routes.entries()){
      const routeCase=`${route.name}|${route.route_variant||route.variant||'default'}`;
      if(result.profiles.some(row=>row.route_case===routeCase&&row.http_status===200&&row.profile_captured))continue;
      const context=await browser.newContext({...(route.guest_route?{}:{storageState:statePath}),locale:'ar-EG',timezoneId:'Africa/Cairo',viewport:{width:1440,height:900}});
      try{
        const expectedPath=new URL(route.url,baseUrl).pathname;
        let response=null;
        for(let authAttempt=0;authAttempt<2;authAttempt++){
          const cookies=route.guest_route?[]:await context.cookies(baseUrl);
          const cookieHeader=cookies.map(cookie=>`${cookie.name}=${cookie.value}`).join('; ');
          response=await context.request.get(`${baseUrl}${route.url}`,{timeout:25000,maxRedirects:0,headers:cookieHeader?{cookie:cookieHeader}:{}});
          if(route.guest_route||response.status()<300||response.status()>=400)break;
          const location=response.headers()['location']||'',locationPath=location?new URL(location,baseUrl).pathname:'';
          if(authAttempt===1||!['/login','/user/confirm-password'].includes(locationPath))break;
          result.invalid_attempts.push({route_case:routeCase,route_name:route.name,url:route.url,http_status:response.status(),redirect_location:location,
            reason:'expired or unconfirmed diagnostic session redirected before the target route; request retried after reauthentication',profile_source:'authenticated direct GET with redirects disabled'});
          if(locationPath==='/login')await loginAndSave(browser,baseUrl,credentials,statePath);
          await confirmPasswordIfNeeded(browser,baseUrl,statePath,credentials);
          const refreshed=JSON.parse(fs.readFileSync(statePath,'utf8'));
          if(Array.isArray(refreshed.cookies)&&refreshed.cookies.length)await context.addCookies(refreshed.cookies);
        }
        const status=response.status(),debugbarId=response.headers()['phpdebugbar-id']||null;
        const responsePath=new URL(response.url()).pathname;
        if(status>=300&&status<400){
          const location=response.headers()['location']||'';
          result.invalid_attempts.push({route_case:routeCase,route_name:route.name,url:route.url,http_status:status,redirect_location:location,
            reason:'direct GET was not a final route response; no SQL profile is attributed to this case',profile_source:'authenticated direct GET with redirects disabled'});
          fs.writeFileSync(outputPath,JSON.stringify(result,null,2)+'\n',{mode:0o600});
          continue;
        }
        const profile=debugbarId?nativeDebugbarProfile(debugbarId,profileEnv):null;
        const q=profile?.query_summary||{};
        const profilePath=profile?.profile_meta?.uri?new URL(profile.profile_meta.uri,baseUrl).pathname:null;
        const exactTarget=status===200&&responsePath===expectedPath&&profilePath===expectedPath&&profile?.profile_meta?.method==='GET';
        const entry={route_case:routeCase,route_name:route.name,route_variant:route.route_variant||route.variant||'default',module:route.module,uri:route.uri,url:route.url,http_status:status,
          profile_captured:Boolean(exactTarget&&debugbarId&&profile?.get_ok&&profile?.queries_ok),query_count:q.query_count??null,database_time_ms:q.accumulated_duration_ms??null,
          duplicate_group_count:q.duplicate_group_count??null,n_plus_one_group_count:q.n_plus_one_group_count??null,failed_statement_count:q.failed_statement_count??null,
          top_statement_durations_ms:q.top_statement_durations_ms||[],profile_meta_uri:profilePath,debugbar_id:debugbarId,debugbar_id_present:Boolean(debugbarId),
          ...(profile?.private_statements?{private_statements:profile.private_statements}:{}),profile_source:'authenticated direct HTTP GET (redirects disabled) + same-response Debugbar CLI profile; no UI timing'};
        if(status===200&&entry.profile_captured){
          result.profiles.push(entry);result.invalid_attempts=result.invalid_attempts.filter(row=>row.route_case!==routeCase);
          if(!route.guest_route)await context.storageState({path:statePath});
        } else result.invalid_attempts.push({...entry,reason:status!==200?'route returned non-200':!exactTarget?'response or Debugbar metadata URI did not match requested route':'Debugbar CLI did not return a parsed profile'});
      }finally{await context.close();}
      fs.writeFileSync(outputPath,JSON.stringify(result,null,2)+'\n',{mode:0o600});
    }
    result.completed_at=new Date().toISOString();
    fs.writeFileSync(outputPath,JSON.stringify(result,null,2)+'\n',{mode:0o600});
    process.stdout.write(JSON.stringify({status:'complete',profiles:result.profiles.length,captured:result.profiles.filter(x=>x.profile_captured).length,output:outputPath})+'\n');
  }finally{closeServer(serverRecord);if(browser)await browser.close();}
}

const execution = process.argv.includes('--summarize-existing') ? summarizeExistingProfiles
  : process.argv.includes('--profile-route-manifest') ? profileRouteManifest : main;
Promise.resolve().then(execution).catch(error => {
  process.stderr.write(JSON.stringify({ status: 'failed', message: String(error.message || error).slice(0, 400) }) + '\n');
  process.exitCode = 1;
});
