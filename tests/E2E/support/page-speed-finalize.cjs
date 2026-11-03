'use strict';

const fs = require('node:fs');
const path = require('node:path');
const audit = require('../page-speed-audit.cjs');
const { root, workDir, reportDir, runDir } = audit.paths;
const progress = JSON.parse(fs.readFileSync(path.join(workDir, 'progress.json'), 'utf8'));
const bootstrap = JSON.parse(fs.readFileSync(path.join(workDir, 'bootstrap.json'), 'utf8'));
const routes = JSON.parse(fs.readFileSync(path.join(workDir, 'audit-routes.json'), 'utf8'));
let results = [...progress.results];
const invalidContextSamples = results.filter(row => row.route_name === 'password.request' && !row.guest_route);
results = results.filter(row => !(row.route_name === 'password.request' && !row.guest_route));
const runPath = path.join(reportDir, 'runs', progress.run_id);
const spa = JSON.parse(fs.readFileSync(path.join(runPath, 'ERP_PAGE_SPEED_SPA_TRANSITIONS.json'), 'utf8'));
const guests = JSON.parse(fs.readFileSync(path.join(runPath, 'ERP_PAGE_SPEED_GUEST_PAGES.json'), 'utf8'));
const spaUnperformed = progress.results.filter(row => row.temperature === 'transition'
  && row.transition_error === 'No direct dashboard wire:navigate link is defined for this route case; SPA transition was not attempted.');
const diagnostics = JSON.parse(fs.readFileSync(path.join(runPath, 'ERP_PAGE_SPEED_DIAGNOSTICS.json'), 'utf8'));
const startFingerprint = JSON.parse(fs.readFileSync(path.join(workDir, 'source-fingerprint-start.json'), 'utf8')).sha256;
const seederStatus = JSON.parse(fs.readFileSync(path.join(workDir, 'seed-status.json'), 'utf8'));
const childProcess = require('node:child_process');
const phpVersion = childProcess.spawnSync('php', ['-r', 'echo PHP_VERSION;'], { encoding: 'utf8', windowsHide: true }).stdout.trim();
const edgeVersion = childProcess.spawnSync('powershell.exe', ['-NoProfile', '-Command', "(Get-Item -LiteralPath 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe').VersionInfo.ProductVersion"], { encoding: 'utf8', windowsHide: true }).stdout.trim();

// Restore unresolved route identities and case variants from the discovered catalog.
const unresolved = results.filter(row => row.temperature === 'unresolved' && !row.route_name);
const byUri = new Map();
for (const row of unresolved) { const list = byUri.get(row.uri) || []; list.push(row); byUri.set(row.uri, list); }
for (const [uri, rows] of byUri) {
  const route = routes.find(item => item.uri === uri && audit.isPageRoute(item));
  if (!route) continue;
  const variants = audit.routeVariants(route);
  const perCase = new Map();
  for (const row of rows) { const list = perCase.get(row.viewport) || []; list.push(row); perCase.set(row.viewport, list); }
  for (const [viewport, grouped] of perCase) grouped.forEach((row, index) => {
    const variant = variants[index] || {};
    row.route_name = route.name; row.module = audit.moduleName(route);
    row.route_variant = Object.values(variant).join(',') || (Object.keys(variant)[0] ? `unresolved-${Object.keys(variant)[0]}` : 'unresolved-parameter');
    row.uri = route.uri; row.not_measured_reason = 'No valid isolated fixture ID for required route parameter';
    row.ready_error = row.not_measured_reason;
  });
}

// Expired mobile-session rows were replaced by the authenticated resume runner.
// Keep only deliberate, real UI transitions from the six opened navigation groups.
const excludedUris = new Set(routes.filter(route => !audit.isPageRoute(route)).map(route => route.uri));
results = results.filter(row => row.temperature !== 'transition' && !audit.routeExclusions.test(row.route_name || '')
  && !excludedUris.has(row.uri));
const normalizedModules = new Map(routes.map(route => [route.name, audit.moduleName(route)]));
for (const row of results) if (normalizedModules.has(row.route_name)) row.module = normalizedModules.get(row.route_name);
for (const row of results.filter(item => item.route_name === 'security.edit' && (item.redirect_chain || []).some(url => /user\/confirm-password/.test(url)))) {
  row.not_measured_reason = 'Password-confirmation flow not completed by harness';
  row.ready_error = row.not_measured_reason; row.readiness = 'not-measured'; row.measured = false; row.healthy = false;
}
for (const row of guests) { row.guest_route = true; row.navigation_mode = 'full-document-navigation'; }
for (const row of spa) row.module = normalizedModules.get(row.route_name) || row.module;
results.push(...guests, ...spaUnperformed, ...spa);
// Subresource HTTP failures degrade the sample but do not invalidate a successfully rendered page.
for (const row of results.filter(item => ['cold','warm','unresolved'].includes(item.temperature))) {
  const documentRendered = row.readiness === 'passed' && row.http_status === 200
    && !row.redirected_to_login && !row.js_errors?.length && !row.failed_requests?.length;
  if (documentRendered) {
    row.measured = true;
    row.healthy = !(row.http_errors?.length || row.server_errors?.length);
    row.not_measured_reason = null;
  }
}

const candidatesByKey = new Map();
for (const row of results.filter(item => ['cold','unresolved'].includes(item.temperature))) {
  if (!row.route_name) continue;
  const key = `${row.route_name}|${row.route_variant || 'default'}`;
  if (!candidatesByKey.has(key)) candidatesByKey.set(key, { name: row.route_name, module: row.module, uri: row.uri,
    method: 'GET|HEAD', variant: row.route_variant || 'default', url: row.url ?? null });
}
for (const routeName of ['login','password.request']) {
  const record = guests.find(row => row.route_name === routeName);
  if (record) candidatesByKey.set(`${routeName}|default`, { name: routeName, module: record.module, uri: record.uri,
    method: 'GET|HEAD', variant: 'default', url: record.url });
}
const candidates = [...candidatesByKey.values()];

const explicitlyExcluded = new Map();
for (const route of routes.filter(item => item.name !== 'password.request' && !audit.isPageRoute(item) && audit.exclusionReason(item))) {
  explicitlyExcluded.set(route.name || route.uri, { name: route.name || null, uri: route.uri, method: route.method,
    action: route.action, reason: audit.exclusionReason(route) });
}
for (const name of ['financial.documents','tenders.bid']) {
  const route = routes.find(item => item.name === name);
  if (route) explicitlyExcluded.set(name, { name, uri: route.uri, method: route.method, action: route.action,
    reason: 'PDF/binary file response; explicitly excluded from page performance coverage' });
}

const endFingerprint = audit.sourceFingerprint();
const payload = {
  routes,
  candidates,
  results,
  seeders: seederStatus,
  diagnostic_results: diagnostics,
  excluded_routes: [...explicitlyExcluded.values()],
  conditions: {
    generated_run_id: progress.run_id,
    db_host: '127.0.0.1', db_port: 55461, db_name: 'erp_elec_new_test',
    browser: 'Microsoft Edge via Playwright', server: 'local PHP built-in server', network_throttling: 'none',
    runtime: { app_env: bootstrap.env.APP_ENV, app_debug: bootstrap.env.APP_DEBUG, cache_store: bootstrap.env.CACHE_STORE,
      session_driver: bootstrap.env.SESSION_DRIVER, queue_connection: bootstrap.env.QUEUE_CONNECTION,
      php_version: phpVersion || null, edge_version: edgeVersion || null },
    baseline: 'Current local checkout; isolated synthetic fixture dataset; server/runtime warmed between requests.',
    cache_method: 'one cold navigation in a fresh browser context/cache is followed by three measured warm navigations in the same context; PostgreSQL/PHP/OS were not cold-started.',
    warm_trials: 3, warmups: 1, viewports: { desktop: { width: 1440, height: 900 }, mobile: { width: 390, height: 844 } },
    source_fingerprint_start: startFingerprint, source_fingerprint_end: endFingerprint, source_unchanged: startFingerprint === endFingerprint,
    source_fingerprint_note: 'Start and end fingerprints cover app, routes, views, CSS/JS source, and composer.lock.',
    run_note: 'Final seeded baseline; authenticated Laravel cookie renewal was persisted after successful warm responses. No app optimization was applied.'
  },
};
const report = audit.writeReports(payload);

const pageRows = results.filter(row => ['cold','warm','unresolved'].includes(row.temperature));
const caseKeys = new Set(candidates.map(row => `${row.name}|${row.variant}`));
const completeCases = [...caseKeys].filter(key => ['desktop','mobile'].every(viewport =>
  pageRows.filter(row => `${row.route_name}|${row.route_variant || 'default'}` === key && row.viewport === viewport && row.temperature === 'warm' && row.measured).length === 3));
const caseReasons = new Map();
for (const row of pageRows.filter(item => !item.measured)) {
  const key = `${row.route_name || 'unknown'}|${row.route_variant || 'default'}`;
  const reason = row.not_measured_reason || row.ready_error || row.readiness || 'not measured';
  const bucket = caseReasons.get(key) || new Set(); bucket.add(reason); caseReasons.set(key, bucket);
}
report.discovery.eligible_page_routes = new Set(candidates.map(row => row.name)).size;
report.discovery.total_get_routes = routes.filter(route => String(route.method || '').includes('GET')).length;
report.discovery.named_get_routes = routes.filter(route => String(route.method || '').includes('GET') && route.name).length;
report.discovery.unnamed_get_routes = report.discovery.total_get_routes - report.discovery.named_get_routes;
report.discovery.expanded_page_cases = candidates.length;
report.discovery.fully_measured_cases_desktop_mobile_3warm = completeCases.length;
report.discovery.incomplete_page_cases = [...caseReasons.keys()].length;
report.discovery.page_sample_count = pageRows.length;
report.discovery.page_unmeasured_samples = pageRows.filter(row => !row.measured).length;
report.discovery.spa_transition_unperformed = spaUnperformed.length;
report.discovery.navigation_http_failures = pageRows.filter(row => row.http_status >= 400 || row.server_errors?.length).length;
report.discovery.javascript_failures = pageRows.filter(row => row.js_errors?.length).length;
report.discovery.transport_failures = pageRows.filter(row => row.failed_requests?.length).length;
report.discovery.asset_http_error_responses = pageRows.reduce((n,row) => n + (row.http_errors?.length || 0), 0);
report.discovery.document_http_error_responses = pageRows.filter(row => row.http_status >= 400).length;
report.discovery.asset_http_error_responses = Math.max(0, report.discovery.asset_http_error_responses - report.discovery.document_http_error_responses);
report.discovery.http_error_responses_recorded_lower_bound = report.discovery.asset_http_error_responses
  + report.discovery.document_http_error_responses;
const fullNavigationMeasured = pageRows.filter(row => row.measured);
report.discovery.full_navigation_healthy_samples = fullNavigationMeasured.filter(row => row.healthy !== false).length;
report.discovery.full_navigation_degraded_samples = fullNavigationMeasured.filter(row => row.healthy === false).length;
report.discovery.full_navigation_measured_samples = fullNavigationMeasured.length;
report.discovery.transition_passed_samples = spa.filter(row => row.transition_status === 'passed').length;
report.discovery.incomplete_case_reasons = [...caseReasons.entries()].map(([key,reasons]) => ({ route_case: key, reasons: [...reasons] }));
report.coverage = { eligible_route_names: report.discovery.eligible_page_routes, expanded_route_cases: candidates.length,
  fully_measured_cases_desktop_mobile_3warm: completeCases.length, incomplete_page_cases: report.discovery.incomplete_case_reasons };
report.source_summary = { end_fingerprint: endFingerprint, start_fingerprint: startFingerprint, unchanged: startFingerprint === endFingerprint, note: payload.conditions.source_fingerprint_note };
report.raw_results = results;
fs.writeFileSync(path.join(reportDir, 'ERP_PAGE_SPEED_RESULTS.json'), JSON.stringify(report, null, 2) + '\n');
fs.writeFileSync(path.join(reportDir, 'ERP_PAGE_SPEED_RAW.json'), JSON.stringify(results, null, 2) + '\n');
fs.writeFileSync(path.join(runDir, progress.run_id, 'ERP_PAGE_SPEED_RAW.json'), JSON.stringify(results, null, 2) + '\n');
fs.writeFileSync(path.join(reportDir, 'ERP_PAGE_SPEED_INVALID_CONTEXT.json'), JSON.stringify(invalidContextSamples.map(row => ({ ...row,
  context_classification: row.temperature === 'transition'
    ? 'unattempted SPA placeholder, not an authenticated navigation redirect.'
    : 'authenticated-context redirect for guest-only page; excluded from application page failures; anonymous guest samples replace this measurement.' })), null, 2) + '\n');
fs.writeFileSync(path.join(runDir, progress.run_id, 'ERP_PAGE_SPEED_INVALID_CONTEXT.json'), JSON.stringify(invalidContextSamples, null, 2) + '\n');
fs.writeFileSync(path.join(runDir, progress.run_id, 'ERP_PAGE_SPEED_GUEST_PAGES.json'), JSON.stringify(guests, null, 2) + '\n');
fs.writeFileSync(path.join(runDir, progress.run_id, 'ERP_PAGE_SPEED_SPA_TRANSITIONS.json'), JSON.stringify(spa, null, 2) + '\n');
fs.writeFileSync(path.join(runDir, progress.run_id, 'ERP_PAGE_SPEED_DIAGNOSTICS.json'), JSON.stringify(diagnostics, null, 2) + '\n');
const finalRawPath = path.join(runDir, progress.run_id, 'ERP_PAGE_SPEED_RAW.json');
const checkpointRaw = path.join(runDir, progress.run_id, 'ERP_PAGE_SPEED_RAW_CHECKPOINT.json');
fs.writeFileSync(checkpointRaw, JSON.stringify({ run_id: progress.run_id, previous_completed_samples: progress.completed_samples,
  completed_primary_timing_samples: pageRows.length, results }, null, 2) + '\n');
const reportPath = path.join(reportDir, 'ERP_PAGE_SPEED_REPORT.md');
const coverageText = [
  '', '## Final coverage and reliability notes', '',
  `- GET route inventory: ${report.discovery.total_get_routes} total (${report.discovery.named_get_routes} named, ${report.discovery.unnamed_get_routes} unnamed). Eligible page routes: ${report.discovery.eligible_page_routes} including the 2 measured guest pages; expanded route cases: ${candidates.length}.`,
  `- Cases with 3 measured warm samples on both desktop and mobile: ${completeCases.length}/${candidates.length}.`,
  `- Primary full-page timing samples not measured: ${report.discovery.page_unmeasured_samples}; incomplete cases and exact reasons are in JSON.`,
  `- Invalid auth-context records excluded: ${invalidContextSamples.filter(row => row.temperature !== 'transition').length} navigation attempts redirected from guest-only pages, plus ${invalidContextSamples.filter(row => row.temperature === 'transition').length} unattempted transition placeholder. Anonymous guest samples are used instead. Raw records are preserved separately.`,
  `- SPA transitions: ${report.discovery.spa_transition_passed}/${report.discovery.spa_transition_attempts} passed across reachable shell links; ${spaUnperformed.length} route/viewport cases had no direct dashboard link and were not attempted. These are not full-page failures.`,
  `- Full-document page samples: ${report.discovery.full_navigation_measured_samples} measured (${report.discovery.full_navigation_healthy_samples} healthy, ${report.discovery.full_navigation_degraded_samples} degraded); ${report.discovery.page_unmeasured_samples} full-navigation samples had no valid timing. SPA is separate: ${report.discovery.transition_passed_samples}/${report.discovery.spa_transition_attempts} transitions passed.`,
  `- HTTP error responses recorded: at least ${report.discovery.http_error_responses_recorded_lower_bound} (lower bound; per-sample HTTP error lists are capped), including at least ${report.discovery.asset_http_error_responses} asset and ${report.discovery.document_http_error_responses} document responses. The exact count of affected samples is separately reported.`,
  '- Product media error diagnosis: the initial PHP router did not expose `LARAVEL_STORAGE_PATH` through Laravel’s `$_ENV`/`$_SERVER`, so it served from the repository storage root and produced pre-fix media 404s. The test router now maps the isolated fixture storage root. A targeted fresh-auth probe then reached the files but synthetic demo filenames containing `/` triggered Symfony HeaderUtils’ “filename and fallback cannot contain the "/" and "\\" characters” error. This is data-dependent on those synthetic names; it does not establish that ordinary uploaded product media fails. Main page UI timings remain valid and product media failures are reported as degraded asset health; no app code or fixture names were changed.',
  `- Runtime: APP_ENV=${bootstrap.env.APP_ENV}; APP_DEBUG=${bootstrap.env.APP_DEBUG}; CACHE_STORE=${bootstrap.env.CACHE_STORE}; SESSION_DRIVER=${bootstrap.env.SESSION_DRIVER}; QUEUE_CONNECTION=${bootstrap.env.QUEUE_CONNECTION}; PHP ${phpVersion || 'unknown'}; Microsoft Edge ${edgeVersion || 'unknown'}.`,
  '- The `projects.dashboard` page returned HTTP 500: Flux component `icon.قيد التنفيذ` does not exist, traced to the `in_progress` stat-card icon mapping in `resources/views/projects/dashboard.blade.php`.',
  '- `security.edit` was entered after the harness completed Fortify’s ordinary password-confirmation form with its synthetic account; no 2FA or reset workflow was used.',
  `- Source fingerprint SHA-256: start ${startFingerprint}; end ${endFingerprint}; unchanged: ${startFingerprint === endFingerprint}.`,
  '- No application optimization was applied. Timings use a local PHP server, no network throttling, synthetic data, and warm PostgreSQL/PHP runtime; cold means browser cache/context only, not PHP/process/PostgreSQL/OS cold start. Transferred bytes sum Playwright responseBodySize (headers excluded).',
  '',
].join('\n');
fs.appendFileSync(reportPath, coverageText);
process.stdout.write(JSON.stringify({ status: 'reports-complete', run_id: progress.run_id,
  eligible_routes: report.discovery.eligible_page_routes, expanded_cases: candidates.length,
  complete_cases: completeCases.length, incomplete_cases: report.discovery.incomplete_page_cases.length,
  measured: report.discovery.healthy_samples + report.discovery.degraded_samples,
  healthy: report.discovery.healthy_samples, degraded: report.discovery.degraded_samples,
  spa: `${report.discovery.spa_transition_passed}/${report.discovery.spa_transition_attempts}`,
  diagnostic_profiles: diagnostics.filter(row => row.query_profile).length,
  report: reportPath, json: path.join(reportDir, 'ERP_PAGE_SPEED_RESULTS.json') }) + '\n');
