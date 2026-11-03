'use strict';

const crypto = require('node:crypto');
const fs = require('node:fs');
const net = require('node:net');
const path = require('node:path');
const { spawn, spawnSync } = require('node:child_process');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '../..');
const privateRoot = path.join(root, '.tmp/e2e-automation');
const reportPath = path.join(privateRoot, 'latest.json');
const bootstrapPath = path.join(privateRoot, 'bootstrap.json');
const lockPath = path.join(privateRoot, 'runner.lock');
const mode = process.argv[2] || 'smoke';
const validModes = new Set(['smoke', 'critical', 'changed', 'full']);
const SMOKE_OWNERS = new Set(['Organization', 'Parties', 'Products', 'Sales', 'Purchasing', 'Inventory', 'Projects', 'Accounting', 'ReceivablesPayables', 'Treasury', 'FieldMaintenance', 'WorkshopRepairs']);
const CRITICAL_OWNERS = new Set(['Sales', 'Purchasing', 'Inventory', 'Accounting', 'ReceivablesPayables', 'FieldMaintenance', 'WorkshopRepairs']);

function writeReport(report) {
  fs.mkdirSync(privateRoot, { recursive: true });
  fs.writeFileSync(reportPath, JSON.stringify(report, null, 2) + '\n');
  if (report.mode) fs.writeFileSync(path.join(privateRoot, `latest-${report.mode}.json`), JSON.stringify(report, null, 2) + '\n');
  process.stdout.write(JSON.stringify(report) + '\n');
}

function reportOnly() {
  if (!fs.existsSync(reportPath)) {
    writeReport({ status: 'NOT_RUN', total: 0, passed: 0, failed: 0, skipped: 0, duration_ms: 0, gaps: ['No execution report exists yet.'] });
    return 0;
  }
  const report = JSON.parse(fs.readFileSync(reportPath, 'utf8'));
  process.stdout.write(JSON.stringify(report) + '\n');
  return ['failed', 'blocked', 'incomplete'].includes(report.status) || report.failed ? 1 : 0;
}

function run(command, args, options = {}) {
  const result = spawnSync(command, args, { cwd: root, encoding: 'utf8', windowsHide: true, ...options });
  if (result.error) throw new Error(`Could not run ${path.basename(command)}.`);
  return result;
}

function freePort() {
  return new Promise((resolve, reject) => {
    const server = net.createServer();
    server.once('error', reject);
    server.listen(0, '127.0.0.1', () => { const port = server.address().port; server.close(() => resolve(port)); });
  });
}

async function waitForServer(server, url) {
  const deadline = Date.now() + 45_000;
  while (Date.now() < deadline) {
    if (server.exitCode !== null) throw new Error('Owned Laravel browser server exited before readiness.');
    try {
      const response = await fetch(`${url}/login`, { redirect: 'manual' });
      if (response.status === 200) {
        const body = await response.text();
        if (body.includes('name="email"') || body.includes("name='email'")) return;
        if (response.headers.get('content-type')?.includes('text/html') && body.length > 500) throw new Error('Login page returned without the expected form.');
      }
    } catch (error) { if (String(error.message).includes('expected form')) throw error; }
    await new Promise(resolve => setTimeout(resolve, 250));
  }
  throw new Error('Owned Laravel browser server did not become ready.');
}

function prepareFixtures(env, runId, fixtureMode, selectionContext = {}) {
  const helper = path.join(__dirname, 'support/automation-critical-fixtures.php');
  if (!fs.existsSync(helper)) return { available: false, message: 'Authenticated synthetic fixture helper has not been added yet.' };
  const helperEnv = { ...env, E2E_FIXTURE_MODE: fixtureMode };
  if (selectionContext.suites?.length) helperEnv.E2E_SELECTED_SUITES = selectionContext.suites.join(',');
  if (selectionContext.modules?.length) helperEnv.E2E_SELECTION_MODULES = selectionContext.modules.join(',');
  if (selectionContext.groups?.length) helperEnv.E2E_FIXTURE_GROUPS = selectionContext.groups.join(',');
  const result = run('php', [helper], { env: helperEnv, input: '{}', maxBuffer: 4 * 1024 * 1024 });
  if (result.status !== 0) {
    const safeError = (result.stderr || '').replace(/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/gi, '<email>').replace(/password[^\r\n]*/gi, 'password <redacted>').slice(0, 1600);
    fs.writeFileSync(path.join(privateRoot, 'logs', `fixture-error-${runId}.log`), `exit=${result.status}\n${safeError}`);
    let diagnostic = null;
    try { diagnostic = JSON.parse(result.stderr || ''); } catch {}
    if (diagnostic?.fixture_error) {
      const message = String(diagnostic.message || 'owner action failed').replace(/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/gi, '<email>').slice(0, 240);
      throw new Error(`Fixture failed in ${String(diagnostic.stage || 'unknown stage')} (${String(diagnostic.fixture_error).split('\\').pop()}): ${message}`);
    }
    throw new Error(`Fixture preparation exited ${result.status}; see private failure artifact fixture-error-${runId}.log.`);
  }
  if (result.status !== 0) throw new Error('Guarded synthetic E2E fixture preparation failed.');
  try { return { available: true, value: JSON.parse(result.stdout) }; }
  catch {
    let diagnostic = null;
    try { diagnostic = JSON.parse(result.stderr || ''); } catch {}
    const safe = diagnostic?.fixture_error
      ? `Fixture failed in ${String(diagnostic.stage || 'unknown stage')} (${String(diagnostic.fixture_error).split('\\').pop()}): ${String(diagnostic.message || 'owner action failed').replace(/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/gi, '<email>').slice(0, 240)}`
      : 'Fixture helper returned invalid JSON; no fixture stdout was recorded.';
    if (diagnostic) fs.writeFileSync(path.join(privateRoot, 'logs', `fixture-error-${runId}.json`), JSON.stringify({ stage: diagnostic.stage, error: diagnostic.fixture_error, message: safe }, null, 2));
    throw new Error(safe);
  }
}

async function createAuthStates(env, fixture, runtime) {
  if (!Array.isArray(fixture?.actors) || fixture.actors.length === 0) throw new Error('Fixture helper returned no authenticated actors.');
  const actorRoot = path.join(runtime, 'actors');
  fs.mkdirSync(actorRoot, { recursive: true });
  const browser = await chromium.launch({ headless: true, executablePath: browserExecutable() });
  try {
    const states = {};
    for (const actor of fixture.actors) {
      const context = await browser.newContext({ locale: 'ar-EG', timezoneId: 'Africa/Cairo' });
      const page = await context.newPage();
      await page.goto(`${env.APP_URL}/login`, { waitUntil: 'domcontentloaded' });
      await page.locator('input[name="email"]').waitFor({ state: 'visible' });
      await page.locator('input[name="email"]').fill(actor.email);
      await page.locator('input[name="password"]').fill(actor.password);
      const dashboard = page.waitForURL(url => url.pathname === '/dashboard', { timeout: 15_000, waitUntil: 'domcontentloaded' });
      await page.locator('[data-test="login-button"]').click({ noWaitAfter: true });
      await dashboard;
      await page.locator('#main-content').waitFor({ state: 'visible' });
      const state = path.join(actorRoot, `${actor.key}.json`);
      await context.storageState({ path: state });
      states[actor.key] = state;
      await context.close();
    }
    return states;
  } finally { await browser.close(); }
}

function browserExecutable() {
  const configured = process.env.PLAYWRIGHT_EXECUTABLE_PATH;
  if (configured && fs.existsSync(configured)) return configured;
  const edge = 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe';
  if (fs.existsSync(edge)) return edge;
  throw new Error('No installed Chromium-compatible browser found; configure PLAYWRIGHT_EXECUTABLE_PATH.');
}

function safeFixtureRecord(fixture) {
  if (!fixture || typeof fixture !== 'object') return fixture;
  const clean = structuredClone(fixture);
  if (Array.isArray(clean.actors)) clean.actors = clean.actors.map(actor => ({ key: actor.key, id: actor.id }));
  return clean;
}

async function runAutomation() {
  const started = Date.now();
  const runToken = crypto.randomBytes(8).toString('hex');
  let server;
  let browserStarted = 0;
  const phaseTimes = {};
  const selection = mode === 'changed' ? discoverChangedModules() : { modules: [], cold: false, global: false };
  let runSmoke = mode === 'smoke' || mode === 'full';
  let runCritical = mode === 'critical' || mode === 'full';
  if (mode === 'changed') {
    if (selection.cold || selection.global) { runSmoke = true; runCritical = true; }
    if (selection.modules.some(owner => SMOKE_OWNERS.has(owner))) runSmoke = true;
    if (selection.modules.some(owner => CRITICAL_OWNERS.has(owner))) runCritical = true;
    if (!runSmoke && !runCritical) {
      writeReport({ status: 'no_changes', mode, total: 0, passed: 0, failed: 0, skipped: 0, duration_ms: 0, gaps: [], changed_modules: [], cold_baseline: false });
      return 0;
    }
  }
  const selector = selectorDetails();
  const cliSelectors = selectorArgs();
  const fixtureGroups = requestedFixtureGroups(runCritical, selection, selector.grep);
  const sourceAtStart = fingerprint();
  fs.mkdirSync(privateRoot, { recursive: true });
  try { fs.mkdirSync(lockPath); }
  catch { writeReport({ status: 'blocked', mode, total: 0, passed: 0, failed: 0, skipped: 0, duration_ms: 0, gaps: ['Another E2E run owns the isolated database/runtime lock.'] }); return 2; }
  fs.writeFileSync(path.join(lockPath, 'owner.json'), JSON.stringify({ pid: process.pid, runToken }));
  const bootstrapStarted = Date.now();
  const baseline = run(process.execPath, [path.join(__dirname, 'bootstrap.cjs')], { maxBuffer: 1024 * 1024 });
  if (baseline.status !== 0) {
    writeReport({ status: 'blocked', total: 0, passed: 0, failed: 0, skipped: 0, duration_ms: Date.now() - started, gaps: ['Owned disposable PostgreSQL bootstrap failed; no browser tests ran.'], error: (baseline.stderr || '').slice(0, 300) });
    fs.rmSync(lockPath, { recursive: true, force: true });
    return 2;
  }
  phaseTimes.database_bootstrap_ms = Date.now() - bootstrapStarted;
  const bootstrap = JSON.parse(fs.readFileSync(bootstrapPath, 'utf8'));
  const port = await freePort();
  const baseUrl = `http://127.0.0.1:${port}`;
  const env = { ...bootstrap.env, APP_URL: baseUrl };
  bootstrap.baseUrl = baseUrl;
  bootstrap.runToken = runToken;
  bootstrap.env = env;
  bootstrap.fixtures = null;
  const serverLogPath = path.join(bootstrap.logs, `laravel-${bootstrap.runId}.log`);
  const serverLog = fs.openSync(serverLogPath, 'w');
  server = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', '.', path.join(root, 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')], {
    cwd: path.join(root, 'public'), env, stdio: ['ignore', serverLog, serverLog], windowsHide: true,
  });
  try {
    const serverStarted = Date.now();
    await waitForServer(server, baseUrl);
    phaseTimes.server_readiness_ms = Date.now() - serverStarted;
    const fixtureMode = runCritical ? 'critical' : 'smoke';
    const fixtureStarted = Date.now();
    const fixtureResult = prepareFixtures(env, bootstrap.runId, fixtureMode, {
      suites: [runSmoke && 'smoke', runCritical && 'critical'].filter(Boolean),
      modules: selection.modules,
      groups: fixtureGroups,
    });
    phaseTimes.fixture_preparation_ms = Date.now() - fixtureStarted;
    bootstrap.fixture_helper = fixtureResult.available;
    const authStarted = Date.now();
    if (fixtureResult.available) {
    bootstrap.fixtures = safeFixtureRecord(fixtureResult.value);
    const neededActors = new Set();
    if (runSmoke) neededActors.add('smoke');
    if (runCritical && fixtureGroups.includes('maintenance')) ['reviewer', 'technician', 'smoke'].forEach(key => neededActors.add(key));
    if (runCritical && fixtureGroups.includes('workshop')) ['dispatcher', 'technician', 'reviewer'].forEach(key => neededActors.add(key));
    if (runCritical && fixtureGroups.includes('financial')) ['dispatcher', 'reviewer', 'outsider'].forEach(key => neededActors.add(key));
    const authFixture = { ...fixtureResult.value, actors: fixtureResult.value.actors.filter(actor => neededActors.has(actor.key)) };
    try {
      bootstrap.actor_states = await createAuthStates(env, authFixture, bootstrap.runtime);
      bootstrap.authenticated = true;
    }
      finally {
        if (fixtureResult.value?.actors) fixtureResult.value.actors.forEach(actor => { actor.password = ''; actor.email = ''; });
      }
    }
    phaseTimes.auth_state_creation_ms = Date.now() - authStarted;
    fs.writeFileSync(bootstrapPath, JSON.stringify(bootstrap, null, 2) + '\n');
    const playwrightCli = path.join(path.dirname(require.resolve('playwright/package.json')), 'cli.js');
    const args = [playwrightCli, 'test', '--config=playwright.config.cjs'];
    if (runSmoke) args.push('tests/E2E/specs/smoke.spec.cjs', 'tests/E2E/specs/m08-draft-smoke.spec.cjs');
    if (runCritical) args.push('tests/E2E/specs/critical.spec.cjs');
    if (mode === 'full') args.push('tests/E2E/specs/smoke-mobile.spec.cjs');
    const selectedArgs = [...cliSelectors];
    if (mode === 'changed' && !selection.cold && !selection.global && !selector.hasSelector && selection.modules.length) {
      const pattern = changedTestPattern(selection.modules);
      if (pattern) selectedArgs.push('--grep', pattern);
    }
    args.push(...selectedArgs);
    const envTest = {
      ...env,
      E2E_BASE_URL: baseUrl,
      E2E_DB_PORT: String(bootstrap.dbPort),
      E2E_DB_DATABASE: bootstrap.dbName,
      E2E_RUN_TOKEN: runToken,
      E2E_BOOTSTRAP_FILE: bootstrapPath,
      E2E_STORAGE_STATE: runSmoke
        ? (bootstrap.actor_states?.smoke || '')
        : (bootstrap.actor_states?.dispatcher || ''),
      E2E_MODE: mode,
      E2E_WORKERS: process.argv.slice(3).includes('--workers=1') ? '1' : mode === 'full' ? '2' : '1',
    };
    browserStarted = Date.now();
    const testRun = run(process.execPath, args, { env: envTest, maxBuffer: 8 * 1024 * 1024 });
    const playwrightReportPath = path.join(privateRoot, `playwright-result-${runToken}.json`);
    const raw = fs.existsSync(playwrightReportPath) ? JSON.parse(fs.readFileSync(playwrightReportPath, 'utf8')) : {};
    const gaps = [];
    if (!bootstrap.authenticated) gaps.push('Authenticated UI/create-save coverage unavailable: no guarded fixture actor was established.');
    if (mode === 'full') gaps.push('Full registry-wide desktop/mobile, RBAC and create-save coverage remains incomplete; only authored smoke and critical journeys execute.');
    if (mode === 'changed' && selection.cold) gaps.push('Cold baseline: all implemented modules and shared sources were selected for the first changed run.');
    const noMeaningfulPass = (raw.passed || 0) === 0;
    const status = testRun.status === 0 && !noMeaningfulPass ? (gaps.length ? 'incomplete' : 'passed') : testRun.status === 0 ? 'incomplete' : (raw.failed || raw.global_errors?.length) ? 'failed' : 'blocked';
    const report = {
      status, mode, total: raw.total || 0, passed: raw.passed || 0, failed: raw.failed || 0, skipped: raw.skipped || 0,
      setup_failures: raw.global_errors?.length || 0,
      flaky: raw.flaky || 0, duration_ms: Date.now() - started,
      setup_duration_ms: browserStarted - started, browser_duration_ms: Date.now() - browserStarted,
      phase_duration_ms: { ...phaseTimes },
      gaps, changed_modules: selection.modules, fixture_groups: fixtureGroups,
      database: { host: '127.0.0.1', port: bootstrap.dbPort, name: bootstrap.dbName },
      reusable_caches: { blade_compiled_path: true, mpdf_font_temp_path: bootstrap.mpdfCacheLinked === true },
      artifacts: raw.artifacts || [], failures: (raw.failures || []).map(failure => ({ ...failure, message: failure.message.replace(/\u001b\[[0-9;]*m/g, '') })), global_errors: raw.global_errors || [],
    };
    report.selected_suites = [runSmoke && 'smoke', runCritical && 'critical'].filter(Boolean);
    report.coverage_registry_version = 1;
    const current = fingerprint();
    const unchangedDuringRun = JSON.stringify(sourceAtStart.hashes) === JSON.stringify(current.hashes);
    if (testRun.status === 0 && report.failed === 0 && report.passed > 0 && unchangedDuringRun
      && (mode === 'full' || mode === 'changed')) {
      const baselineFile = path.join(privateRoot, 'successful-full-fingerprint.json');
      let hashes = current.hashes;
      if (mode === 'changed' && !selection.cold && !selection.global && fs.existsSync(baselineFile)) {
        const previous = JSON.parse(fs.readFileSync(baselineFile, 'utf8'));
        hashes = { ...previous.hashes };
        for (const file of Object.keys(hashes)) if (selection.modules.includes(moduleForSource(file))) delete hashes[file];
        for (const [file, digest] of Object.entries(current.hashes)) if (selection.modules.includes(moduleForSource(file))) hashes[file] = digest;
      }
      fs.writeFileSync(baselineFile, JSON.stringify({ version: 1, registry_version: 1,
        successful_run: runToken, selected_suites: report.selected_suites, hashes }, null, 2) + '\n');
    }
    if (!unchangedDuringRun) report.gaps.push('Source fingerprint changed during execution; this run did not update the changed-test baseline.');
    writeReport(report);
    return status === 'passed' ? 0 : 1;
  } catch (error) {
    writeReport({ status: 'blocked', mode, total: 0, passed: 0, failed: 0, skipped: 0, setup_failures: 1, duration_ms: Date.now() - started,
      setup_duration_ms: browserStarted ? browserStarted - started : Date.now() - started, browser_duration_ms: browserStarted ? Date.now() - browserStarted : 0,
      phase_duration_ms: { ...phaseTimes },
      gaps: [], error: String(error.message || error).replace(/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/gi, '<email>').slice(0, 500) });
    return 1;
  } finally {
    if (server && server.exitCode === null) {
      server.kill();
      await new Promise(resolve => { const timer = setTimeout(resolve, 1500); server.once('exit', () => { clearTimeout(timer); resolve(); }); });
    }
    try { fs.closeSync(serverLog); } catch {}
    fs.rmSync(lockPath, { recursive: true, force: true });
  }
}

function discoverChangedModules() {
  const baselinePath = path.join(privateRoot, 'successful-full-fingerprint.json');
  const current = fingerprint();
  const owners = new Set(current.files.map(file => file.match(/^app\/Modules\/([^/]+)/)?.[1]).filter(Boolean));
  if (!fs.existsSync(baselinePath)) return { modules: [...owners].sort(), cold: true, global: true };
  const baseline = JSON.parse(fs.readFileSync(baselinePath, 'utf8'));
  if (baseline.registry_version !== 1) return { modules: [...owners].sort(), cold: true, global: true };
  const changed = new Set([...Object.keys(current.hashes), ...Object.keys(baseline.hashes)].filter(file => current.hashes[file] !== baseline.hashes[file]));
  const modules = new Set();
  let global = false;
  for (const file of changed) {
    const owner = moduleForSource(file);
    if (owner) modules.add(owner);
    else global = true;
  }
  if ([...modules].some(owner => !SMOKE_OWNERS.has(owner) && !CRITICAL_OWNERS.has(owner))) global = true;
  return { modules: [...modules].sort(), cold: false, global };
}

function fingerprint() {
  const hashes = {};
  const files = [];
  const roots = ['app/Modules', 'app/Application', 'app/Concerns', 'app/Models', 'app/Http', 'app/Providers', 'bootstrap', 'routes', 'config',
    'resources/views', 'resources/css', 'resources/js', 'database/migrations', 'database/factories', 'database/seeders', 'tests/E2E'];
  for (const relative of roots) {
    const folder = path.join(root, relative);
    if (!fs.existsSync(folder)) continue;
    const pending = [folder];
    while (pending.length) {
      const parent = pending.pop();
      for (const entry of fs.readdirSync(parent, { withFileTypes: true })) {
        const current = path.join(parent, entry.name);
        if (entry.isDirectory()) pending.push(current);
        else if (!current.includes(`${path.sep}artifacts${path.sep}`) && /\.(php|blade\.php|cjs|js|json)$/.test(entry.name)) {
          const key = path.relative(root, current).replaceAll('\\', '/');
          files.push(key);
          hashes[key] = crypto.createHash('sha256').update(fs.readFileSync(path.join(root, key))).digest('hex');
        }
      }
    }
  }
  for (const file of ['composer.json', 'composer.lock', 'package.json', 'package-lock.json']) {
    if (!fs.existsSync(path.join(root, file))) continue;
    files.push(file);
    hashes[file] = crypto.createHash('sha256').update(fs.readFileSync(path.join(root, file))).digest('hex');
  }
  return { hashes, files };
}

function moduleForSource(file) {
  const owner = file.match(/^app\/Modules\/([^/]+)/)?.[1];
  if (owner) return owner;
  if (!file.startsWith('resources/views/')) return null;
  const view = file.slice('resources/views/'.length).split('/')[0];
  return ({ organization: 'Organization', parties: 'Parties', customers: 'Parties', suppliers: 'Parties', products: 'Products',
    sales: 'Sales', purchasing: 'Purchasing', inventory: 'Inventory', projects: 'Projects', accounting: 'Accounting',
    financial: 'ReceivablesPayables', treasury: 'Treasury', maintenance: 'FieldMaintenance', workshops: 'WorkshopRepairs',
    settings: 'Organization' })[view] || null;
}

function selectorArgs() {
  const input = process.argv.slice(3);
  const args = [];
  const workerOptions = [...new Set(input.filter(value => value.startsWith('--workers=')))];
  if (workerOptions.length > 1) throw new Error('Conflicting Playwright worker options were supplied.');
  for (let i = 0; i < input.length; i++) {
    const value = input[i];
    if (value === '--grep' && input[i + 1]) { args.push('--grep', input[++i]); continue; }
    if (value.startsWith('--grep=')) { args.push('--grep', value.slice(7)); continue; }
    if (value === '--failed') {
      const sameMode = path.join(privateRoot, `latest-${mode}.json`);
      const last = fs.existsSync(sameMode) ? JSON.parse(fs.readFileSync(sameMode, 'utf8')) : null;
      const titles = (last?.failures || []).map(item => item.title).filter(Boolean);
      if (!titles.length) throw new Error('--failed requested, but the previous report has no failed test titles.');
      args.push('--grep', titles.map(title => title.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).join('|'));
      continue;
    }
    if (value.startsWith('--workers=')) {
      if (!['--workers=1', '--workers=2'].includes(value) || (value === '--workers=2' && mode !== 'full')) {
        throw new Error('Two workers are permitted only for full mode with a separate smoke branch and read-only mobile suite.');
      }
      continue;
    }
    throw new Error(`Unsupported E2E selector: ${value}`);
  }
  return args;
}

function selectorDetails() {
  const input = process.argv.slice(3);
  for (let i = 0; i < input.length; i++) {
    if (input[i] === '--grep' && input[i + 1]) return { grep: input[i + 1], hasSelector: true };
    if (input[i].startsWith('--grep=')) return { grep: input[i].slice(7), hasSelector: true };
    if (input[i] === '--failed') {
      const sameMode = path.join(privateRoot, `latest-${mode}.json`);
      const last = fs.existsSync(sameMode) ? JSON.parse(fs.readFileSync(sameMode, 'utf8')) : null;
      return { grep: (last?.failures || []).map(item => item.title || '').join('|'), hasSelector: true };
    }
  }
  return { grep: '', hasSelector: false };
}

function changedTestPattern(modules) {
  const ids = new Set();
  const ownerIds = { Organization: ['M01'], Parties: ['M03'], Products: ['M02'], Sales: ['M04', 'M05', 'M12'],
    Purchasing: ['M05', 'M12'], Inventory: ['M06', 'M05'], Projects: ['M07'], Accounting: ['M11', 'M05'],
    ReceivablesPayables: ['M12', 'M05'], Treasury: ['M13'], FieldMaintenance: ['M08'], WorkshopRepairs: ['M09'] };
  for (const owner of modules) for (const id of ownerIds[owner] || []) ids.add(id);
  return [...ids].sort().join('|');
}

function requestedFixtureGroups(runCritical, selection, grep) {
  if (!runCritical) return [];
  const text = String(grep || '').toLowerCase();
  if (text) {
    const groups = [];
    if (/m08|maintenance/.test(text)) groups.push('maintenance');
    if (/m09|workshop/.test(text)) groups.push('workshop');
    if (/m04|m05|m06|m11|m12|fifo|invoice|financial|sales/.test(text)) groups.push('financial');
    if (groups.length) return [...new Set(groups)];
  }
  if (mode === 'changed' && selection.modules.length && !selection.cold && !selection.global) {
    const groups = [];
    if (selection.modules.includes('FieldMaintenance')) groups.push('maintenance');
    if (selection.modules.includes('WorkshopRepairs')) groups.push('workshop');
    if (selection.modules.some(owner => ['Sales', 'Purchasing', 'Inventory', 'Accounting', 'ReceivablesPayables'].includes(owner))) groups.push('financial');
    if (groups.length) return [...new Set(groups)];
  }
  return ['maintenance', 'workshop', 'financial'];
}

if (mode === 'report') process.exitCode = reportOnly();
else if (!validModes.has(mode)) { process.stderr.write('Usage: npm run e2e:<smoke|critical|changed|full|report>\n'); process.exitCode = 2; }
else runAutomation().then(code => { process.exitCode = code; }).catch(error => {
  writeReport({ status: 'failed', mode, total: 0, passed: 0, failed: 1, skipped: 0, duration_ms: 0, gaps: [], error: String(error.message || error).slice(0, 400) });
  try { fs.rmSync(lockPath, { recursive: true, force: true }); } catch {}
  process.exitCode = 1;
});
