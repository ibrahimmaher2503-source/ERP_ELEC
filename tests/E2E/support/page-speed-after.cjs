'use strict';

// Canonical post-optimization pipeline and comparison formatter. This file is
// deliberately inert unless --execute and an exact source fingerprint are
// supplied after the application source has been frozen.
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const os = require('node:os');
const net = require('node:net');
const { spawn, spawnSync } = require('node:child_process');
const { chromium } = require('playwright');
const audit = require('../page-speed-audit.cjs');
const guestPages = require('./page-speed-guest-pages.cjs');
const spaTransitions = require('./page-speed-spa-transitions.cjs');

const root = audit.paths.root;
const perf = path.join(root, 'docs/performance');
const archive = path.join(perf, 'before-optimization-20261031');
const work = audit.paths.workDir;
const bootstrap = JSON.parse(fs.readFileSync(path.join(work, 'bootstrap.json'), 'utf8'));
const baseline = JSON.parse(fs.readFileSync(path.join(archive, 'ERP_PAGE_SPEED_RESULTS.json'), 'utf8'));
const baselineRaw = JSON.parse(fs.readFileSync(path.join(archive, 'ERP_PAGE_SPEED_RAW.json'), 'utf8'));
const moduleDirs = fs.readdirSync(path.join(root, 'app/Modules'), { withFileTypes: true }).filter(x => x.isDirectory()).map(x => x.name).sort();
const moduleNames = new Map(Object.entries({ Organization:'M01 Organization', Products:'M02 Products', Parties:'M03 Parties', Sales:'M04 Sales', Purchasing:'M05 Purchasing', Inventory:'M06 Inventory', Projects:'M07 Projects', FieldMaintenance:'M08 FieldMaintenance', WorkshopRepairs:'M09 WorkshopRepairs', Accounting:'M11 Accounting', ReceivablesPayables:'M12 ReceivablesPayables', Treasury:'M13 Treasury', System:'M17 System' }));
const knownGates = new Map([['purchasing.reports','Existing route returns 404 under current release gates; do not enable during performance measurement.'],['purchasing.returns','Existing route returns 404 under current release gates; do not enable during performance measurement.'],['sales-returns.index','Sales-return route is unavailable under current release gates; do not enable during performance measurement.']]);
const profilesPath = path.join(perf, 'ERP_NATIVE_PROFILING_RESULTS.json');

function shaFile(file) { return crypto.createHash('sha256').update(fs.readFileSync(file)).digest('hex').toUpperCase(); }
function configurationFingerprints(){return Object.fromEntries(['.env','config/app.php','config/database.php','public/build/manifest.json'].filter(file=>fs.existsSync(path.join(root,file))).map(file=>[file,shaFile(path.join(root,file))]));}
function writeStage(runDir,name,runId,sourceFingerprint,data){const file=path.join(runDir,`${name}.json`);fs.writeFileSync(file,JSON.stringify({run_id:runId,source_fingerprint:sourceFingerprint,configuration_fingerprints:configurationFingerprints(),data},null,2)+'\n',{mode:0o600});return data;}
function readStage(runDir,name,runId,sourceFingerprint){const file=path.join(runDir,`${name}.json`);if(!fs.existsSync(file))return null;const stage=JSON.parse(fs.readFileSync(file,'utf8'));
  if(stage.run_id!==runId||stage.source_fingerprint!==sourceFingerprint||JSON.stringify(stage.configuration_fingerprints)!==JSON.stringify(configurationFingerprints()))throw new Error(`Resume refused: ${name} checkpoint does not match the frozen source/configuration.`);return stage.data;}
function verifyArchive() {
  const manifest = JSON.parse(fs.readFileSync(path.join(archive, 'SHA256.json'), 'utf8'));
  for (const item of manifest) {
    const file = path.join(archive, item.file);
    if (!fs.existsSync(file) || shaFile(file) !== item.sha256) throw new Error(`Archived before-optimization artifact failed SHA-256 verification: ${item.file}`);
  }
}
function uniqueCases() {
  const byKey = new Map();
  for (const row of baselineRaw.filter(row => ['cold','warm','unresolved'].includes(row.temperature))) {
    const variant = row.route_variant || 'default';
    const key = `${row.route_name}|${variant}`;
    if (!byKey.has(key)) byKey.set(key, { key, name: row.route_name, variant, module: row.module, uri: row.uri, url: row.url,
      viewport_width: row.viewport_width, viewport_height: row.viewport_height, baseline_status: row.http_status,
      baseline_reason: row.not_measured_reason || null });
  }
  const rows=[...byKey.values()].sort((a,b) => a.key.localeCompare(b.key));
  if(rows.length!==166)throw new Error(`Baseline route inventory drift: expected exactly 166 unique route+variant keys; found ${rows.length}.`);
  return rows;
}
function measuredSamples(rows) { return rows.filter(row => row.measured && row.temperature === 'warm'); }
function percentile(values, p) {
  const sorted = values.filter(Number.isFinite).sort((a,b) => a-b);
  if (!sorted.length) return null;
  const index = Math.min(sorted.length - 1, Math.ceil(p * sorted.length) - 1);
  return Number(sorted[index].toFixed(2));
}
function median(values) {
  const sorted = values.filter(Number.isFinite).sort((a,b) => a-b);
  if (!sorted.length) return null;
  const middle = sorted.length >> 1;
  return Number((sorted.length % 2 ? sorted[middle] : (sorted[middle-1] + sorted[middle]) / 2).toFixed(2));
}
function baselineProfileMap() {
  const map = new Map();
  for (const row of baseline.diagnostic_results || []) {
    if (!row.query_profile) continue;
    map.set(`${row.route_name}|${row.route_variant || 'default'}`, {
      query_count: row.query_profile.query_count ?? null, database_time_ms: row.query_profile.database_time_ms ?? null,
      repeat_count: row.query_profile.repeated_query_count ?? null, source: 'baseline custom SQL profiler (separate diagnostic request)',
    });
  }
  if (fs.existsSync(profilesPath)) {
    const native = JSON.parse(fs.readFileSync(profilesPath, 'utf8'));
    const keyByShort = new Map([['dashboard','dashboard|default'],['organization.user-details','organization.users.show|default'],
      ['maintenance.work-order','maintenance.work-orders.show|default'],['maintenance.index','maintenance.index|default'],['customers.index','customers.index|default']]);
    for (const row of native.debugbar_native_profiles || []) {
      const key = keyByShort.get(row.route);
      if (key && !map.has(key)) map.set(key, { query_count: row.query_count ?? null,
        database_time_ms: row.database_duration_ms ?? null, duplicate_groups: row.duplicate_groups ?? null,
        n_plus_one_groups: row.n_plus_one_groups ?? null, source: 'baseline native Debugbar request (separate diagnostic request)' });
    }
  }
  return map;
}
function moduleBuckets(cases, raw, queryMap = new Map()) {
  const out = new Map(moduleDirs.map(name => [name, { owner:name, module:moduleNames.get(name) || 'No route owner mapping', cases:[], samples:[], sqlCases:0 }]));
  if (cases.some(c => c.module === 'M07 Projects (Tenders)')) out.set('Projects::Tenders', { owner:'Projects::Tenders', module:'M07 Projects · Tenders capability', cases:[], samples:[], sqlCases:0 });
  for (const c of cases) {
    const owner = c.module === 'M07 Projects (Tenders)' ? 'Projects::Tenders' : moduleDirs.find(name => moduleNames.get(name) === c.module);
    const bucket = out.get(owner || 'System');
    bucket.cases.push(c);
    const rows = raw.filter(row => row.route_name === c.name && (row.route_variant || 'default') === c.variant);
    bucket.samples.push(...measuredSamples(rows));
    if (queryMap.has(c.key)) bucket.sqlCases++;
  }
  return [...out.values()];
}
function writeInitialInventory() {
  verifyArchive();
  const cases = uniqueCases();
  const queryMap = baselineProfileMap();
  const buckets = moduleBuckets(cases, baselineRaw, queryMap);
  const lines = ['# ERP module performance matrix — baseline inventory', '',
    '**Status:** pre-optimization inventory only. No optimized result has been collected or compared yet.',
    `Baseline run: ${baseline.conditions.generated_run_id}. Archived source/runtime and raw samples are preserved under before-optimization-20261031/.`,
    `Cases: ${cases.length} unique route+variant cases; ${baseline.discovery.fully_measured_cases_desktop_mobile_3warm} completed with three warm samples on both viewports. Four cases were unavailable in the baseline; their exact statuses remain in the case inventory.`,
    'Global and module P95 values pool warm navigation samples within the stated module; they are not per-page P95 values. Before SQL fields remain blank where no matching before profile exists.', '',
    '| Module owner | Route cases | Warm samples | Median UI ms | P95 UI ms | Before SQL cases / available | Baseline notes |',
    '|---|---:|---:|---:|---:|---:|---|'];
  for (const b of buckets) {
    const vals = b.samples.map(x => x.ui_ready_ms);
    const blocked = b.cases.filter(c => c.baseline_status !== 200).map(c => `${c.name} HTTP ${c.baseline_status ?? 'unavailable'}`);
    lines.push(`| ${b.module} | ${b.cases.length} | ${vals.length} | ${median(vals) ?? '—'} | ${percentile(vals,.95) ?? '—'} | ${b.sqlCases} / ${b.cases.length} | ${blocked.length ? blocked.join('; ') : '—'} |`);
  }
  lines.push('', '## Existing baseline query evidence', '',
    `The custom diagnostic dataset contains ${baseline.diagnostic_results?.filter(x=>x.query_profile).length || 0} page profiles; the separate native Debugbar report contains ${(JSON.parse(fs.readFileSync(profilesPath,'utf8')).debugbar_native_profiles || []).length} page profiles and one Livewire customer-search profile. Unprofiled cases are intentionally blank.`,
    'The after pass will capture one separate Debugbar request per eligible route+variant case. Browser timing requests remain uninstrumented.', '',
    '## Known baseline exceptions', '',
    '- `projects.dashboard`: baseline HTTP 500; preserve its before timing as missing. If the optimized page renders, classify it as repaired, not as a fabricated before/after speedup.',
    '- `purchasing.reports`, `purchasing.returns`, `sales-returns.index`: baseline HTTP 404 under current release gates. Preserve as gated/deferred; do not auto-enable these routes to fill the matrix.',
    '- Product-media assets: baseline timings include degraded asset responses from synthetic demo names. Report the original errors separately from current file-storage and filename behavior.', '',
    'No application edits or after-run measurements are represented by this inventory.', '');
  fs.writeFileSync(path.join(perf, 'ERP_MODULE_PERFORMANCE_MATRIX.md'), lines.join('\n'));
  const manifest = { generated_at:new Date().toISOString(), status:'baseline-inventory-only; after optimization not yet measured',
    baseline_run_id:baseline.conditions.generated_run_id, baseline_archive:archive, baseline_sha256:JSON.parse(fs.readFileSync(path.join(archive,'SHA256.json'),'utf8')),
    database:{name:bootstrap.dbName,host:'127.0.0.1',port:bootstrap.dbPort}, route_case_count:cases.length, cases,
    module_counts:buckets.map(x=>({module:x.module,cases:x.cases.length,baseline_measured_warm_samples:x.samples.length,before_sql_profiles:x.sqlCases})),
    baseline_exceptions:cases.filter(x=>x.baseline_status!==200).map(x=>({route_case:x.key,status:x.baseline_status,reason:x.baseline_reason})),
    source_fingerprint_before:baseline.conditions.source_fingerprint_start };
  fs.writeFileSync(path.join(work,'after-route-manifest.json'),JSON.stringify(manifest,null,2)+'\n');
  process.stdout.write(JSON.stringify({status:'inventory-ready',run_id:manifest.baseline_run_id,cases:cases.length,
    eligibleRoutes:baseline.discovery.eligible_page_routes,modules:buckets.map(x=>({module:x.module,cases:x.cases.length})),
    initialReport:path.join(perf,'ERP_MODULE_PERFORMANCE_MATRIX.md'),manifest:path.join(work,'after-route-manifest.json')})+'\n');
}

function csvEscape(v) { const s = v === null || v === undefined ? '' : String(v); return /[",\r\n]/.test(s) ? `"${s.replaceAll('"','""')}"` : s; }
function safeStatus(caseDef, rows) {
  const http = rows.map(x=>x.http_status).filter(x=>x!==null);
  if (knownGates.has(caseDef.name)) return 'GATED/DEFERRED';
  const complete=['desktop','mobile'].every(viewport=>rows.filter(x=>x.viewport===viewport&&x.temperature==='warm'&&x.measured).length===3);
  if (caseDef.name === 'projects.dashboard' && caseDef.baseline_status !== 200 && complete) return 'REPAIRED';
  if (complete) return 'MEASURABLE';
  if (!rows.length || http.every(x=>x!==200)) return 'NOT MEASURABLE';
  return 'NOT MEASURABLE';
}
function comparisonFor(cases, beforeRaw, afterRaw, beforeSQL, afterSQL) {
  return cases.map(c=>{
    const b=beforeRaw.filter(x=>x.route_name===c.name&&(x.route_variant||'default')===c.variant&&x.temperature==='warm');
    const a=afterRaw.filter(x=>x.route_name===c.name&&(x.route_variant||'default')===c.variant&&x.temperature==='warm');
    const vals=(rows,v,metric)=>rows.filter(x=>x.viewport===v&&x.measured).map(x=>x[metric]);
    const oldD=median(vals(b,'desktop','ui_ready_ms')), oldM=median(vals(b,'mobile','ui_ready_ms'));
    const newD=median(vals(a,'desktop','ui_ready_ms')), newM=median(vals(a,'mobile','ui_ready_ms'));
    const bsql=beforeSQL.get(c.key)||null, asql=afterSQL.get(c.key)||null;
    const status=safeStatus(c,a);
    let classification=status;
    if (status==='MEASURABLE') {
      if (oldD===null||oldM===null) classification='NOT MEASURABLE';
      else {
        const dPct=100*(newD-oldD)/Math.max(oldD,1),mPct=100*(newM-oldM)/Math.max(oldM,1);
        const dReg=dPct>=5&&(newD-oldD)>=50,mReg=mPct>=5&&(newM-oldM)>=50;
        const dImprove=dPct<=-5&&(newD-oldD)<=-50,mImprove=mPct<=-5&&(newM-oldM)<=-50;
        classification=(dReg||mReg)?'REGRESSED':(dImprove||mImprove)?'IMPROVED':'UNCHANGED';
      }
    }
    if(status==='REPAIRED')classification='REPAIRED';
    const summarize=(rows,v,metric)=>median(rows.filter(x=>x.viewport===v&&x.temperature==='warm'&&x.measured).map(x=>x[metric]));
    const afterSummary={desktop_ttfb:summarize(a,'desktop','ttfb_ms'),mobile_ttfb:summarize(a,'mobile','ttfb_ms'),
      desktop_dcl:summarize(a,'desktop','domcontentloaded_ms'),mobile_dcl:summarize(a,'mobile','domcontentloaded_ms'),
      desktop_load:summarize(a,'desktop','load_ms'),mobile_load:summarize(a,'mobile','load_ms'),
      desktop_requests:summarize(a,'desktop','total_requests'),mobile_requests:summarize(a,'mobile','total_requests'),
      desktop_bytes:summarize(a,'desktop','transferred_bytes'),mobile_bytes:summarize(a,'mobile','transferred_bytes')};
    const beforeSummary={desktop_ttfb:summarize(b,'desktop','ttfb_ms'),mobile_ttfb:summarize(b,'mobile','ttfb_ms'),
      desktop_dcl:summarize(b,'desktop','domcontentloaded_ms'),mobile_dcl:summarize(b,'mobile','domcontentloaded_ms'),
      desktop_load:summarize(b,'desktop','load_ms'),mobile_load:summarize(b,'mobile','load_ms'),
      desktop_requests:summarize(b,'desktop','total_requests'),mobile_requests:summarize(b,'mobile','total_requests'),
      desktop_bytes:summarize(b,'desktop','transferred_bytes'),mobile_bytes:summarize(b,'mobile','transferred_bytes')};
    const opportunity=status==='GATED/DEFERRED'?'No performance ranking; route remains intentionally gated/deferred.'
      : status==='NOT MEASURABLE'?'Restore the route/readiness or provide a valid authorized page before ranking performance.'
      : asql?.query_count>=1000||asql?.database_time_ms>=500
      ? 'Review this route’s native SQL fingerprints; batch only confirmed repeated reads while preserving scope checks.'
      : (newD??0)>=1200||(newM??0)>=1200
        ? 'Inspect PHP component/render time and payload work; verify the owning source before changing it.'
        : a.some(x=>x.http_errors?.length||x.js_errors?.length||x.failed_requests?.length||x.http_status>=400)
          ? 'Resolve recorded route, asset, or browser failures before treating this case as accepted.'
          : 'No material outlier signal; retain as a control case.';
    return {route_case:c.key,module:c.module,uri:c.uri,url:c.url,classification,
      before_desktop_warm_median_ms:oldD,before_mobile_warm_median_ms:oldM,
      after_desktop_warm_median_ms:newD,after_mobile_warm_median_ms:newM,
      desktop_delta_ms:oldD===null||newD===null?null:Number((newD-oldD).toFixed(2)),
      mobile_delta_ms:oldM===null||newM===null?null:Number((newM-oldM).toFixed(2)),
      desktop_delta_percent:oldD===null||newD===null?null:Number((100*(newD-oldD)/Math.max(oldD,1)).toFixed(2)),
      mobile_delta_percent:oldM===null||newM===null?null:Number((100*(newM-oldM)/Math.max(oldM,1)).toFixed(2)),
      ...Object.fromEntries(Object.entries(beforeSummary).map(([key,value])=>[`before_${key}`,value])),
      ...Object.fromEntries(Object.entries(afterSummary).map(([key,value])=>[`after_${key}`,value])),
      before_query_count:bsql?.query_count??null,before_database_time_ms:bsql?.database_time_ms??null,
      before_duplicate_group_count:bsql?.duplicate_group_count??bsql?.duplicate_groups??null,
      before_n_plus_one_group_count:bsql?.n_plus_one_group_count??bsql?.n_plus_one_groups??null,
      after_query_count:asql?.query_count??null,after_database_time_ms:asql?.database_time_ms??null,
      after_duplicate_group_count:asql?.duplicate_group_count??null,after_n_plus_one_group_count:asql?.n_plus_one_group_count??null,
      after_failed_sql_statements:asql?.failed_statement_count??null,
      before_query_evidence:bsql?.source??'',after_query_evidence:asql?.source??'',
      before_http_statuses:[...new Set(b.map(x=>x.http_status).filter(Boolean))].join('|'),
      after_http_statuses:[...new Set(a.map(x=>x.http_status).filter(Boolean))].join('|'),
      before_failure_samples:b.filter(x=>!x.measured||x.healthy===false).length,
      after_failure_samples:a.filter(x=>!x.measured||x.healthy===false).length,
      before_js_failure_samples:b.filter(x=>x.js_errors?.length).length,after_js_failure_samples:a.filter(x=>x.js_errors?.length).length,
      before_transport_failure_samples:b.filter(x=>x.failed_requests?.length).length,after_transport_failure_samples:a.filter(x=>x.failed_requests?.length).length,
      before_error_responses_recorded_lower_bound:b.reduce((n,x)=>n+(x.http_errors?.length||0),0),
      after_error_responses_recorded_lower_bound:a.reduce((n,x)=>n+(x.http_errors?.length||0),0),
      before_p95_page_ms:null,after_p95_page_ms:null,
      optimization_opportunity:opportunity,
      acceptance:'3/3 warm UI-ready samples per viewport; document HTTP 200 and no JS/transport failures. Asset errors remain separately degraded; page P95 withheld at n=3.' };
  });
}

function writeComparison(after) {
  const cases=after.route_cases;
  const beforeRaw=JSON.parse(fs.readFileSync(path.join(archive,'ERP_PAGE_SPEED_RAW.json'),'utf8'));
  const beforeSQL=baselineProfileMap();
  const afterSQL=new Map((after.sql_profiles||[]).map(x=>[x.route_case,x]));
  const rows=comparisonFor(cases,beforeRaw,after.browser_samples,beforeSQL,afterSQL);
  const columns=Object.keys(rows[0]||{});
  fs.writeFileSync(path.join(perf,'ERP_PAGE_SPEED_COMPARISON.csv'),[columns.join(','),...rows.map(row=>columns.map(key=>csvEscape(row[key])).join(','))].join('\n')+'\n');
  const extra=(after.additional_eligible_cases||[]).map(c=>{
    const samples=after.browser_samples.filter(x=>x.route_name===c.name&&(x.route_variant||'default')===c.variant);
    const sql=afterSQL.get(c.key);
    return{route_case:c.key,module:c.module,uri:c.uri,url:c.url,status:c.url?'NEW ELIGIBLE':'NOT MEASURABLE',
      desktop_warm_median_ui_ms:median(samples.filter(x=>x.viewport==='desktop'&&x.temperature==='warm'&&x.measured).map(x=>x.ui_ready_ms)),
      mobile_warm_median_ui_ms:median(samples.filter(x=>x.viewport==='mobile'&&x.temperature==='warm'&&x.measured).map(x=>x.ui_ready_ms)),
      after_query_count:sql?.query_count??null,after_database_time_ms:sql?.database_time_ms??null,
      failures:samples.filter(x=>!x.measured||x.healthy===false).length,reason:c.baseline_reason||''};
  });
  const extraColumns=Object.keys(extra[0]||{route_case:'',module:'',uri:'',url:'',status:'',desktop_warm_median_ui_ms:'',mobile_warm_median_ui_ms:'',after_query_count:'',after_database_time_ms:'',failures:'',reason:''});
  fs.writeFileSync(path.join(perf,'ERP_PAGE_SPEED_NEW_ROUTES.csv'),[extraColumns.join(','),...extra.map(row=>extraColumns.map(key=>csvEscape(row[key])).join(','))].join('\n')+'\n');
  const comparableKeys=new Set(rows.filter(x=>['IMPROVED','UNCHANGED','REGRESSED'].includes(x.classification)).map(x=>x.route_case));
  const warmBefore=beforeRaw.filter(x=>x.measured&&x.temperature==='warm'&&comparableKeys.has(`${x.route_name}|${x.route_variant||'default'}`));
  const warmAfter=after.browser_samples.filter(x=>x.measured&&x.temperature==='warm'&&comparableKeys.has(`${x.route_name}|${x.route_variant||'default'}`));
  const vps=['desktop','mobile'];
  const lines=['# ERP performance comparison — final report','',`After run: ${after.run_id}. Baseline: ${baseline.conditions.generated_run_id}.`,'Source fingerprint and freeze marker are in the after JSON. Manual 14-family source review and explicit limits: [ERP_SOURCE_REVIEW.md](ERP_SOURCE_REVIEW.md). Actual read-only EXPLAIN plans are reported separately when captured SQL can be safely replayed with synthetic bindings.',
    'Only uninstrumented Playwright samples are used for timing comparisons. SQL profiles are separate Debugbar requests. Per-page P95 is withheld for n=3; aggregate P95 is shown only for pools with at least 20 samples. Aggregate before/after pools use only original route cases measured in both runs; repaired and additional cases are reported separately.','',
    '## Aggregate page timings','', '| Viewport | Before samples | Before median / P95 ms | After samples | After median / P95 ms |', '|---|---:|---:|---:|---:|'];
  for(const vp of vps){const b=warmBefore.filter(x=>x.viewport===vp).map(x=>x.ui_ready_ms),a=warmAfter.filter(x=>x.viewport===vp).map(x=>x.ui_ready_ms);lines.push(`| ${vp} | ${b.length} | ${median(b)??'—'} / ${b.length>=20?percentile(b,.95):'—'} | ${a.length} | ${median(a)??'—'} / ${a.length>=20?percentile(a,.95):'—'} |`);}
  const spa=after.spa_transitions||[];
  lines.push('', '## SPA navigation transitions','', 'Six original sidebar routes × desktop/mobile × three trials. These are same-document Livewire navigation timings from click to `livewire:navigated`; they are reported separately from full-page UI-ready timings.', '', '| Route | Viewport | Samples | Passed | Median transition ms | Failed/unavailable |','|---|---|---:|---:|---:|---:|');
  for(const route of spaTransitions.routes)for(const viewport of vps){const samples=spa.filter(x=>x.route_name===route.name&&x.viewport===viewport),passed=samples.filter(x=>x.transition_status==='passed');lines.push(`| ${route.name} | ${viewport} | ${samples.length} | ${passed.length} | ${median(passed.map(x=>x.transition_ms))??'—'} | ${samples.filter(x=>x.transition_status!=='passed').length} |`);}
  const plans=after.query_plan_summary?.module_plans||[];
  lines.push('', '## Actual SQL query plans','', 'Selected captured business SELECTs use actual synthetic bindings, a read-only transaction, `EXPLAIN (ANALYZE, BUFFERS)`, and a 3-second per-statement timeout. Raw SQL, bindings, and full plans remain private under `.tmp`.', '', '| Module | Status | Route | Execution ms | Actual rows | Shared hit/read blocks | Seq scans | Index scans | Sort keys |','|---|---|---|---:|---:|---:|---|---|---|');
  for(const plan of plans)lines.push(`| ${plan.module} | ${plan.status} | ${plan.route_case??'—'} | ${plan.execution_time_ms??'—'} | ${plan.actual_rows??'—'} | ${plan.shared_hit_blocks??'—'} / ${plan.shared_read_blocks??'—'} | ${(plan.seq_scan_relations||[]).join(', ')||'—'} | ${(plan.index_scans||[]).map(x=>`${x.relation}.${x.index}`).join(', ')||'—'} | ${(plan.sort_keys||[]).join(', ')||'—'} |`);
  lines.push('', 'Machine-readable public plan summary: [ERP_QUERY_PLAN_SUMMARY.json](ERP_QUERY_PLAN_SUMMARY.json).', 'Separate owner-action acceptance and latency evidence: [ERP_LIVEWIRE_INTERACTION_BENCHMARK.md](ERP_LIVEWIRE_INTERACTION_BENCHMARK.md).');
  const counts={}; for(const row of rows) counts[row.classification]=(counts[row.classification]||0)+1;
  lines.push('', '## Result counts','',...Object.entries(counts).map(([k,v])=>`- ${k}: ${v}`),'',
    'The comparison table preserves all 166 baseline route+variant keys. Baseline 404 routes stay gated/deferred. The dashboard HTTP500 has no before timing and is classified repaired only if the after page renders; its missing baseline is not treated as a speedup.',
    `New eligible route cases discovered after optimization are reported separately in ERP_PAGE_SPEED_NEW_ROUTES.csv (${extra.length}); they are not mixed into the 166-case baseline comparison.`,
    'SQL profile fields are null when no matching before profile exists. Duplicate-query flags are evidence for review, not by themselves proof of an N+1 defect.', '',
    '## Largest warm median regressions and improvements','', '| Route case | Class | Before desktop/mobile | After desktop/mobile | Query count before → after | DB ms before → after |', '|---|---|---:|---:|---:|---:|');
  const timed=rows.filter(x=>x.classification==='IMPROVED'||x.classification==='REGRESSED').sort((a,b)=>Math.max(Math.abs(b.desktop_delta_ms||0),Math.abs(b.mobile_delta_ms||0))-Math.max(Math.abs(a.desktop_delta_ms||0),Math.abs(a.mobile_delta_ms||0))).slice(0,20);
  for(const x of timed)lines.push(`| ${x.route_case} | ${x.classification} | ${x.before_desktop_warm_median_ms??'—'} / ${x.before_mobile_warm_median_ms??'—'} | ${x.after_desktop_warm_median_ms??'—'} / ${x.after_mobile_warm_median_ms??'—'} | ${x.before_query_count??'—'} → ${x.after_query_count??'—'} | ${x.before_database_time_ms??'—'} → ${x.after_database_time_ms??'—'} |`);
  for(const viewport of vps){
    const ranked=rows.map(row=>({...row,rank_ms:row[`after_${viewport}_warm_median_ms`]})).filter(row=>row.classification==='MEASURABLE'||['IMPROVED','UNCHANGED','REGRESSED'].includes(row.classification)).filter(row=>Number.isFinite(row.rank_ms)).sort((a,b)=>a.rank_ms-b.rank_ms);
    lines.push('',`## ${viewport} fastest 10 and slowest 20 after pages`,'','| Rank | Route case | Warm median UI ready ms |','|---:|---|---:|',...ranked.slice(0,10).map((row,i)=>`| ${i+1} | ${row.route_case} | ${row.rank_ms} |`),'| … | … | … |',...ranked.slice(-20).map((row,i)=>`| ${ranked.length-20+i+1} | ${row.route_case} | ${row.rank_ms} |`));
  }
  fs.writeFileSync(path.join(perf,'ERP_PERFORMANCE_FINAL_REPORT.md'),lines.join('\n')+'\n');
  const htmlEscape=value=>String(value??'').replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
  const rankingTable=(viewport,fastest)=>{
    const ranked=rows.map(row=>({...row,rank_ms:row[`after_${viewport}_warm_median_ms`]})).filter(row=>['MEASURABLE','IMPROVED','UNCHANGED','REGRESSED'].includes(row.classification)&&Number.isFinite(row.rank_ms)).sort((a,b)=>a.rank_ms-b.rank_ms);
    const selected=fastest?ranked.slice(0,10):ranked.slice(-20);
    return `<h2>${viewport} ${fastest?'fastest 10':'slowest 20'} after pages</h2><table><thead><tr><th>Rank</th><th>Route case</th><th>Module</th><th>Warm median UI ms</th><th>Class</th></tr></thead><tbody>${selected.map((row,i)=>`<tr><td>${fastest?i+1:ranked.length-selected.length+i+1}</td><td>${htmlEscape(row.route_case)}</td><td>${htmlEscape(row.module)}</td><td>${row.rank_ms}</td><td>${htmlEscape(row.classification)}</td></tr>`).join('')}</tbody></table>`;
  };
  const spaHtml=(after.spa_transitions||[]).map(row=>`<tr><td>${htmlEscape(row.route_name)}</td><td>${htmlEscape(row.viewport)}</td><td>${row.trial}</td><td>${htmlEscape(row.transition_status)}</td><td>${row.transition_ms??''}</td><td>${row.http_status??''}</td><td>${htmlEscape(row.transition_error)}</td></tr>`).join('');
  const planHtml=plans.map(row=>`<tr><td>${htmlEscape(row.module)}</td><td>${htmlEscape(row.status)}</td><td>${htmlEscape(row.route_case)}</td><td>${row.execution_time_ms??''}</td><td>${row.actual_rows??''}</td><td>${(row.seq_scan_relations||[]).map(htmlEscape).join(', ')}</td><td>${(row.index_scans||[]).map(x=>htmlEscape(`${x.relation}.${x.index}`)).join(', ')}</td></tr>`).join('');
  const dashboard=`<!doctype html><html lang="en"><meta charset="utf-8"><title>ERP Page Speed After</title><style>body{font:14px system-ui;margin:2rem;color:#172b4d}table{border-collapse:collapse;width:100%;margin-bottom:2rem}th,td{border-bottom:1px solid #dbe4f0;padding:8px;text-align:start}th{background:#edf4ff;position:sticky;top:0}tbody tr:nth-child(even){background:#f8fafc}</style><h1>ERP Page Speed After</h1><p>Run ${htmlEscape(after.run_id)}. Browser samples are uninstrumented. The table retains all ${cases.length} original baseline route keys. Aggregate comparison pools use only cases measurable in both runs; additional and repaired routes remain separate.</p>${vps.map(v=>rankingTable(v,true)+rankingTable(v,false)).join('')}<h2>SPA transitions</h2><p>Same-document Livewire navigation; click-to-livewire:navigated latency is separate from full-page timing.</p><table><thead><tr><th>Route</th><th>Viewport</th><th>Trial</th><th>Status</th><th>Transition ms</th><th>HTTP</th><th>Reason</th></tr></thead><tbody>${spaHtml}</tbody></table><h2>Actual SQL query plans</h2><p>EXPLAIN ANALYZE with BUFFERS; raw SQL and bindings remain private.</p><table><thead><tr><th>Module</th><th>Status</th><th>Route</th><th>Execution ms</th><th>Actual rows</th><th>Seq scans</th><th>Index scans</th></tr></thead><tbody>${planHtml}</tbody></table><h2>All ${rows.length} original route cases</h2><table><thead><tr><th>Route case</th><th>Module</th><th>Class</th><th>Before desktop ms</th><th>After desktop ms</th><th>Before mobile ms</th><th>After mobile ms</th><th>After desktop TTFB</th><th>After mobile TTFB</th></tr></thead><tbody>${rows.map(row=>`<tr><td>${htmlEscape(row.route_case)}</td><td>${htmlEscape(row.module)}</td><td>${htmlEscape(row.classification)}</td><td>${row.before_desktop_warm_median_ms??''}</td><td>${row.after_desktop_warm_median_ms??''}</td><td>${row.before_mobile_warm_median_ms??''}</td><td>${row.after_mobile_warm_median_ms??''}</td><td>${row.after_desktop_ttfb??''}</td><td>${row.after_mobile_ttfb??''}</td></tr>`).join('')}</tbody></table></html>`;
  fs.writeFileSync(path.join(perf,'ERP_PAGE_SPEED_AFTER_DASHBOARD.html'),dashboard);
  const moduleMatrix=buildModuleMatrix(cases,beforeRaw,after.browser_samples,rows);
  fs.writeFileSync(path.join(perf,'ERP_MODULE_PERFORMANCE_MATRIX.md'),moduleMatrix);
  return rows;
}

function buildModuleMatrix(cases,beforeRaw,afterRaw,comparison){
  const lines=['# ERP module performance matrix','',`Before: ${baseline.conditions.generated_run_id}; after: ${JSON.parse(fs.readFileSync(path.join(perf,'ERP_PAGE_SPEED_AFTER.json'),'utf8')).run_id}.`,
    'Browser timing is uninstrumented. SQL counts are from separate native Debugbar page requests. Module P95 pools samples within the module; page P95 is withheld at n=3.', '',
    '| Module | Route cases | Before warm median / P95 ms | After warm median / P95 ms | SQL profiles before / after | Improved / unchanged / regressed / gated / repaired / other |',
    '|---|---:|---:|---:|---:|---|'];
  const afterJson=JSON.parse(fs.readFileSync(path.join(perf,'ERP_PAGE_SPEED_AFTER.json'),'utf8'));
  for(const b of moduleBuckets(cases,beforeRaw,baselineProfileMap())){
    const moduleCases=b.cases;const keys=new Set(moduleCases.map(x=>x.key));
    const bv=beforeRaw.filter(x=>keys.has(`${x.route_name}|${x.route_variant||'default'}`)&&x.measured&&x.temperature==='warm').map(x=>x.ui_ready_ms);
    const av=afterRaw.filter(x=>keys.has(`${x.route_name}|${x.route_variant||'default'}`)&&x.measured&&x.temperature==='warm').map(x=>x.ui_ready_ms);
    const rs=comparison.filter(x=>keys.has(x.route_case)); const cls={};for(const x of rs)cls[x.classification]=(cls[x.classification]||0)+1;
    const beforeProfiles=moduleCases.filter(x=>baselineProfileMap().has(x.key)).length;
    const afterProfiles=moduleCases.filter(x=>afterJson.sql_profiles?.some(p=>p.route_case===x.key&&p.profile_captured)).length;
    lines.push(`| ${b.module} | ${moduleCases.length} | ${median(bv)??'—'} / ${bv.length>=20?percentile(bv,.95):'—'} | ${median(av)??'—'} / ${av.length>=20?percentile(av,.95):'—'} | ${beforeProfiles} / ${afterProfiles} | ${cls.IMPROVED||0} / ${cls.UNCHANGED||0} / ${cls.REGRESSED||0} / ${cls['GATED/DEFERRED']||0} / ${cls.REPAIRED||0} / ${(cls['NOT MEASURABLE']||0)+(cls.failed||0)} |`);
  }
  lines.push('', '## Review status','', 'This matrix summarizes browser before/after results. It does not modify business logic or gates. Query counts and duration are diagnostic evidence; compare both with page TTFB and visible-content timings.',
    'No eligible module pages existed for M10 ServiceContracts, M14 Payroll, M15 Attendance, or M16 Reporting in the original route inventory; these owners have no invented rows or measurements.', '');
  return lines.join('\n');
}

function inventoryOnly(){verifyArchive();writeInitialInventory();}

function discoverAdditionalCases(env,baselineCases,ids){
  const r=spawnSync('php',['artisan','route:list','--json'],{cwd:root,env,encoding:'utf8',windowsHide:true,maxBuffer:8*1024*1024});
  if(r.error||r.status!==0)throw new Error('Could not discover current after-run page routes.');
  const existing=new Set(baselineCases.map(c=>`${c.name}|${c.variant}`));
  const all=JSON.parse(r.stdout);
  return all.filter(audit.isPageRoute).flatMap(route=>audit.routeVariants(route).map(variant=>{
    const key=`${route.name}|${Object.values(variant).join(',')||'default'}`;
    if(existing.has(key))return null;
    return{key,name:route.name,variant:Object.values(variant).join(',')||'default',module:audit.moduleName(route),uri:route.uri,
      url:audit.routeUrl(route,ids,variant),baseline_status:null,baseline_reason:'No matching baseline route case.'};
  }).filter(Boolean));
}
function selfCheck(){
  const trial=(route_name,viewport,ms)=>({route_name,route_variant:'default',viewport,temperature:'warm',trial:1,measured:true,healthy:true,ui_ready_ms:ms,ttfb_ms:ms/2,domcontentloaded_ms:ms,load_ms:ms+10,total_requests:8,transferred_bytes:50000,http_status:200,http_errors:[]});
  const base=(name,status=200)=>['desktop','mobile'].flatMap(v=>[1,2,3].map(()=>trial(name,v,1000)));
  const after=(name,d=1000,m=1000)=>['desktop','mobile'].flatMap(v=>[1,2,3].map(()=>trial(name,v,v==='desktop'?d:m)));
  const tests=[
    {name:'unchanged',case:{key:'x|default',name:'x',variant:'default',baseline_status:200},before:base('x'),after:after('x'),expected:'UNCHANGED'},
    {name:'improved',case:{key:'x|default',name:'x',variant:'default',baseline_status:200},before:base('x'),after:after('x',900,900),expected:'IMPROVED'},
    {name:'regressed-one-viewport',case:{key:'x|default',name:'x',variant:'default',baseline_status:200},before:base('x'),after:after('x',1100,1000),expected:'REGRESSED'},
    {name:'dashboard-repaired',case:{key:'projects.dashboard|default',name:'projects.dashboard',variant:'default',baseline_status:500},before:[],after:after('projects.dashboard'),expected:'REPAIRED'},
    {name:'baseline-gate',case:{key:'purchasing.returns|default',name:'purchasing.returns',variant:'default',baseline_status:404},before:[],after:[],expected:'GATED/DEFERRED'},
    {name:'partial-after',case:{key:'x|default',name:'x',variant:'default',baseline_status:500},before:[],after:[trial('x','desktop',500)],expected:'NOT MEASURABLE'},
  ];
  for(const t of tests){const got=comparisonFor([t.case],t.before,t.after,new Map(),new Map())[0].classification;if(got!==t.expected)throw new Error(`Comparison self-check failed (${t.name}): ${got} != ${t.expected}`);}
  if(argValue('--freeze-sha256',['node','runner','--freeze-sha256=abc'])!=='abc'
      ||argValue('--freeze-sha256',['node','runner','--freeze-sha256','def'])!=='def')throw new Error('Freeze fingerprint argument parser self-check failed.');
  if(uniqueCases().length!==166)throw new Error('Baseline exact-case self-check expected 166 route+variant rows.');
  process.stdout.write(JSON.stringify({status:'self-check-passed',classification_cases:tests.length,baseline_cases:166,db:'not accessed',browser:'not launched'})+'\n');
}

function argValue(name,args=process.argv){
  const inline=args.find(value=>value.startsWith(`${name}=`));
  if(inline)return inline.slice(name.length+1)||null;
  const i=args.indexOf(name);return i<0?null:args[i+1]||null;
}
function privateDir(dir){fs.mkdirSync(dir,{recursive:true,mode:0o700});try{fs.chmodSync(dir,0o700);}catch{}}
function freePort(){return new Promise((resolve,reject)=>{const s=net.createServer();s.once('error',reject);s.listen(0,'127.0.0.1',()=>{const p=s.address().port;s.close(()=>resolve(p));});});}
function psql(query){const env={...process.env,...bootstrap.env};const exe=path.join(root,'.tools/postgresql/pgsql/bin/psql.exe');
  const r=spawnSync(exe,['-w','-h','127.0.0.1','-p','55461','-U',env.DB_USERNAME||'erp_e2e','-d','erp_elec_new_test','-Atqc',query],{cwd:root,env,encoding:'utf8',windowsHide:true,maxBuffer:1024*1024});
  if(r.error||r.status!==0)throw new Error('Refusing after run: owned PostgreSQL identity check failed.');return String(r.stdout||'').trim();}
function authMetadataCounts(){return JSON.parse(psql("SELECT json_build_object('users',(SELECT count(*) FROM users),'user_roles',(SELECT count(*) FROM user_roles),'user_branch_scopes',(SELECT count(*) FROM user_branch_scopes),'user_warehouse_scopes',(SELECT count(*) FROM user_warehouse_scopes))::text"));}
function createPerformancePreview(actorId){
  const env={...process.env,...bootstrap.env,E2E_APP_STORAGE:bootstrap.storage};
  const result=spawnSync('php',['tests/E2E/support/performance-operation-preview.php',String(actorId)],{cwd:root,env,encoding:'utf8',windowsHide:true,maxBuffer:1024*1024});
  if(result.error||result.status!==0)throw new Error(`Could not create the guarded preview-only data operation. ${String(result.stderr||'').slice(-500)}`);
  return JSON.parse(result.stdout.trim());
}
async function retryOwnedOperationShow(manifest,runDir,statePath,actor,runId){
  const target=manifest.cases.find(route=>route.name==='operations.show');if(!target)return null;
  const preview=createPerformancePreview(actor.id),url=String(target.url).replace(/data-operations\/[^/?]+/,`data-operations/${preview.run_id}`);
  if(url===target.url)throw new Error('Could not substitute the owner-created preview run id into operations.show.');
  target.url=url;
  const port=await freePort(),baseUrl=`http://127.0.0.1:${port}`,env=appRuntime(baseUrl,runDir),serverRecord=await startPhp(baseUrl,env,path.join(runDir,'operations-show-retry-server.log'));
  const edge=['C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe','C:/Program Files/Microsoft/Edge/Application/msedge.exe'].find(fs.existsSync);
  if(!edge){if(serverRecord.server.exitCode===null)serverRecord.server.kill();try{fs.closeSync(serverRecord.fd);}catch{}throw new Error('Microsoft Edge executable is unavailable for the owner-scoped operations.show retry.');}
  let browser;const rows=[];try{
    browser=await chromium.launch({headless:true,executablePath:edge});
    for(const viewport of [{name:'desktop',width:1440,height:900},{name:'mobile',width:390,height:844}]){
      const context=await browser.newContext({baseURL:baseUrl,storageState:statePath,locale:'ar-EG',timezoneId:'Africa/Cairo',viewport}),page=await context.newPage();
      try{for(const [temperature,trial]of[['cold',0],['warm',1],['warm',2],['warm',3]]){
        const sample=await audit.measurePage(page,baseUrl,{name:target.name,route_name:target.name,variant:target.variant,route_variant:target.variant,module:target.module,uri:target.uri,url:target.url},viewport,temperature,trial,false);
        rows.push({...sample,run_id:runId,baseline_route_case:target.key,fixture_context:'owner-created preview-only data operation; no execution',fixture_run_id:preview.run_id,fixture_actor_id:actor.id});
      }}finally{await context.close();}
    }
  }finally{if(browser)await browser.close();if(serverRecord.server.exitCode===null)serverRecord.server.kill();try{fs.closeSync(serverRecord.fd);}catch{}}
  const file=path.join(runDir,'operations-show-corrected-retry.json');fs.writeFileSync(file,JSON.stringify({run_id:runId,preview_fixture:{run_id:preview.run_id,item_count:preview.item_count,mode:'preview only; product update was not executed'},rows},null,2)+'\n',{mode:0o600});
  return{preview_run_id:preview.run_id,preview_item_count:preview.item_count,rows,file};
}
function assertOwnedTarget(){
  const markerFile=path.join(work,'postgres-55461/codex-owned.json');
  const marker=JSON.parse(fs.readFileSync(markerFile,'utf8'));
  const expected=bootstrap.env.E2E_CLUSTER_TOKEN||'';
  if(bootstrap.dbName!=='erp_elec_new_test'||bootstrap.dbPort!==55461||bootstrap.env.DB_CONNECTION!=='pgsql'||bootstrap.env.DB_HOST!=='127.0.0.1'||bootstrap.env.DB_PORT!=='55461'||bootstrap.env.DB_DATABASE!=='erp_elec_new_test'||bootstrap.env.E2E_DB_PORT!=='55461'||bootstrap.env.E2E_DB_DATABASE!=='erp_elec_new_test'||!String(bootstrap.storage||'').replaceAll('\\','/').includes('/.tmp/page-speed-audit/'))throw new Error('After run target or private storage marker is not the dedicated performance environment.');
  if(marker.kind!=='codex-e2e-postgres'||marker.repo!==root||marker.port!==55461||marker.version!==1||marker.token!==expected||expected.length<32)throw new Error('After run refused: PostgreSQL ownership marker mismatch.');
  const pgBin=path.join(root,'.tools/postgresql/pgsql/bin'),cluster=path.join(work,'postgres-55461');
  const status=spawnSync(path.join(pgBin,'pg_ctl.exe'),['-D',cluster,'status'],{cwd:root,encoding:'utf8',windowsHide:true});
  if(status.status!==0){const start=spawnSync(path.join(pgBin,'pg_ctl.exe'),['-D',cluster,'-l',path.join(work,'logs/postgres.log'),'-o','-h 127.0.0.1 -p 55461','start'],{cwd:root,encoding:'utf8',windowsHide:true});if(start.error||start.status!==0)throw new Error('After run refused: could not start the marker-owned PostgreSQL cluster without resetting it.');}
  const bits=psql("SELECT current_database() || '|' || current_setting('port') || '|' || current_setting('data_directory')").split('|');
  if(bits[0]!=='erp_elec_new_test'||bits[1]!=='55461'||path.resolve(bits[2].replaceAll('\\','/'))!==path.resolve(path.join(work,'postgres-55461')))throw new Error('After run refused: connected PostgreSQL data directory mismatch.');
}
async function loginState(browser,baseUrl,credentials,statePath){
  const context=await browser.newContext({baseURL:baseUrl,locale:'ar-EG',timezoneId:'Africa/Cairo',viewport:{width:1440,height:900}});const page=await context.newPage();
  await page.goto('/login',{waitUntil:'domcontentloaded',timeout:20000});await page.locator('input[name="email"]').fill(credentials.email);await page.locator('input[name="password"]').fill(credentials.password);
  await Promise.all([page.waitForURL(/\/dashboard(?:\?|$)/,{waitUntil:'domcontentloaded',timeout:20000}),page.locator('[data-test="login-button"]').click()]);
  await page.goto('/settings/security',{waitUntil:'domcontentloaded',timeout:20000});
  if(/confirm-password/.test(new URL(page.url()).pathname)){await page.locator('input[name="password"]').fill(credentials.password);await Promise.all([page.waitForURL(/\/settings\/security(?:\?|$)/,{waitUntil:'domcontentloaded',timeout:20000}),page.locator('[data-test="confirm-password-button"]').click()]);}
  if(/confirm-password/.test(new URL(page.url()).pathname))throw new Error('Ordinary Fortify password confirmation did not complete.');
  await context.storageState({path:statePath});try{fs.chmodSync(statePath,0o600);}catch{}await context.close();
}
function createAuditActor(){const env={...process.env,...bootstrap.env,E2E_APP_STORAGE:bootstrap.storage};
  const p=spawnSync('php',['tests/E2E/support/performance-auth-refresh.php'],{cwd:root,env,encoding:'utf8',windowsHide:true,maxBuffer:1024*1024});
  if(p.error||p.status!==0)throw new Error('Guarded isolated Administrator session refresh failed.');
  try{return JSON.parse(p.stdout.trim());}catch{throw new Error('Administrator helper returned invalid private session credentials.');}}
function appRuntime(baseUrl,runDir,prepareConfigCache=false){const storage=bootstrap.storage;const env={...bootstrap.env,APP_URL:baseUrl,APP_ENV:'testing',APP_DEBUG:'false',DEBUGBAR_ENABLED:'false',PULSE_ENABLED:'false',CACHE_STORE:'array',SESSION_DRIVER:'file',QUEUE_CONNECTION:'sync',MAIL_MAILER:'array',AUDIT_QUERY_LOG:'',LARAVEL_STORAGE_PATH:storage,E2E_APP_STORAGE:storage,
  APP_CONFIG_CACHE:path.relative(root,path.join(runDir,'app-config.php')).replaceAll('\\','/'),APP_ROUTES_CACHE:path.relative(root,path.join(runDir,'app-routes.php')).replaceAll('\\','/'),VIEW_COMPILED_PATH:path.join(runDir,'views').replaceAll('\\','/')};
  privateDir(path.dirname(env.APP_CONFIG_CACHE));privateDir(env.VIEW_COMPILED_PATH);
  if(prepareConfigCache){const c=spawnSync('php',['artisan','config:cache'],{cwd:root,env,encoding:'utf8',windowsHide:true,maxBuffer:2*1024*1024});if(c.error||c.status!==0)throw new Error('Could not prepare private Laravel config cache.');}return env;}
async function startPhp(baseUrl,env,logPath){const fd=fs.openSync(logPath,'w');const server=spawn('php',['-S',new URL(baseUrl).host,'-t','.',path.join(root,'tests/E2E/support/performance-router.php')],{cwd:path.join(root,'public'),env,stdio:['ignore',fd,fd],windowsHide:true});
  const deadline=Date.now()+30000;while(Date.now()<deadline){if(server.exitCode!==null)throw new Error('After-run PHP server exited before readiness.');try{const r=await fetch(`${baseUrl}/login`);if(r.status<500)return{server,fd};}catch{}await new Promise(r=>setTimeout(r,200));}throw new Error('After-run PHP server did not become ready.');}
function saveProgress(file,runId,samples,caseKey,sourceFingerprint){fs.writeFileSync(file,JSON.stringify({run_id:runId,source_fingerprint:sourceFingerprint,configuration_fingerprints:configurationFingerprints(),updated_at:new Date().toISOString(),completed_samples:samples.length,current_case:caseKey,samples},null,2)+'\n',{mode:0o600});}
async function collectBrowserSamples(manifest,runDir,statePath,progressPath){
  const port=await freePort(),baseUrl=`http://127.0.0.1:${port}`,env=appRuntime(baseUrl,runDir),serverRecord=await startPhp(baseUrl,env,path.join(runDir,'php-server.log'));
  const edge=['C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe','C:/Program Files/Microsoft/Edge/Application/msedge.exe'].find(x=>fs.existsSync(x));if(!edge)throw new Error('Microsoft Edge executable is missing.');
  const browser=await chromium.launch({headless:true,executablePath:edge}),cases=manifest.cases;
  let samples=[],resumePrimeCount=0;if(fs.existsSync(progressPath)){const prior=JSON.parse(fs.readFileSync(progressPath,'utf8'));
    if(prior.run_id!==manifest.run_id||prior.source_fingerprint!==manifest.source_fingerprint||JSON.stringify(prior.configuration_fingerprints)!==JSON.stringify(configurationFingerprints()))throw new Error('Browser checkpoint source/configuration mismatch; resume refused.');samples=prior.samples||[];}
  const sampleKey=(routeKey,viewport,temperature,trial)=>`${routeKey}|${viewport}|${temperature}|${trial}`;
  const completed=new Set(samples.map(row=>sampleKey(row.baseline_route_case||`${row.route_name}|${row.route_variant||'default'}`,row.viewport,row.temperature,row.trial)));
  try{
    const viewports=[{name:'desktop',width:1440,height:900},{name:'mobile',width:390,height:844}];
    for(const viewport of viewports){
      for(const route of cases){
        const routeSampleKey=(temperature,trial)=>`${route.key}|${viewport.name}|${temperature}|${trial}`;
        if([['cold',0],['warm',1],['warm',2],['warm',3]].every(([temperature,trial])=>completed.has(routeSampleKey(temperature,trial))))continue;
        if(knownGates.has(route.name)||!route.url){
          for(const [temperature,trial] of [['cold',0],['warm',1],['warm',2],['warm',3]]) {if(completed.has(sampleKey(route.key,viewport.name,temperature,trial)))continue;samples.push({run_id:manifest.run_id,baseline_route_case:route.key,route_name:route.name,route_variant:route.variant,module:route.module,uri:route.uri,url:route.url,
            viewport:viewport.name,viewport_width:viewport.width,viewport_height:viewport.height,temperature,trial,measured:false,healthy:false,readiness:'not-measured',http_status:null,
            not_measured_reason:knownGates.get(route.name)||'No valid after-run fixture URL was available.',total_requests:null,transferred_bytes:null,ttfb_ms:null,domcontentloaded_ms:null,load_ms:null,
            ui_ready_ms:null,livewire_stable_ms:null,js_errors:[],failed_requests:[],server_errors:[],http_errors:[],status:knownGates.has(route.name)?'GATED/DEFERRED':'NOT MEASURABLE'});completed.add(sampleKey(route.key,viewport.name,temperature,trial));
          saveProgress(progressPath,manifest.run_id,samples,route.key,manifest.source_fingerprint);}continue;
        }
        const guestRoute=['login','password.request'].includes(route.name);
        const context=await browser.newContext({baseURL:baseUrl,...(guestRoute?{}:{storageState:statePath}),locale:'ar-EG',timezoneId:'Africa/Cairo',viewport});const page=guestRoute?null:await context.newPage();
        try{
          if(completed.has(routeSampleKey('cold',0))&&[1,2,3].some(trial=>!completed.has(routeSampleKey('warm',trial)))){
            if(guestRoute){const guest={name:route.name,path:new URL(route.url,baseUrl).pathname,module:route.module,ready:'input[name="email"]'};await guestPages.sample(browser,baseUrl,guest,viewport,'resume-prime',0,context);}
            else await audit.measurePage(page,baseUrl,{name:route.name,route_name:route.name,variant:route.variant,route_variant:route.variant,module:route.module,uri:route.uri,url:route.url},viewport,'resume-prime',0,false);
            resumePrimeCount++;
          }
          for(const [temperature,trial] of [['cold',0],['warm',1],['warm',2],['warm',3]]){
            if(completed.has(sampleKey(route.key,viewport.name,temperature,trial)))continue;
            let sample;
            if(guestRoute){
              const guest={name:route.name,path:new URL(route.url,baseUrl).pathname,module:route.module,ready:'input[name="email"]'};
              sample=await guestPages.sample(browser,baseUrl,guest,viewport,temperature,trial,context);
            }else{
              sample=await audit.measurePage(page,baseUrl,{name:route.name,route_name:route.name,variant:route.variant,route_variant:route.variant,module:route.module,uri:route.uri,url:route.url},viewport,temperature,trial,false);
            }
            sample.run_id=manifest.run_id;sample.baseline_route_case=route.key;samples.push(sample);completed.add(sampleKey(route.key,viewport.name,temperature,trial));saveProgress(progressPath,manifest.run_id,samples,route.key,manifest.source_fingerprint);
            if(!guestRoute&&!sample.redirected_to_login&&sample.http_status===200)await context.storageState({path:statePath});
          }
        }finally{await context.close();}
        if((cases.indexOf(route)+1)%25===0||cases.indexOf(route)===cases.length-1)process.stdout.write(JSON.stringify({phase:'after-browser',viewport:viewport.name,completed_route_cases:cases.indexOf(route)+1,total_route_cases:cases.length,samples:samples.length})+'\n');
      }
    }
  }finally{await browser.close();if(serverRecord.server.exitCode===null)serverRecord.server.kill();try{fs.closeSync(serverRecord.fd);}catch{}}
  return{samples,resume_prime_count:resumePrimeCount};
}
async function collectAfterSpaTransitions(manifest,runDir,statePath){
  const progressPath=path.join(runDir,'spa-transition-progress.json');let rows=[];
  if(fs.existsSync(progressPath)){const prior=JSON.parse(fs.readFileSync(progressPath,'utf8'));if(prior.run_id!==manifest.run_id||prior.source_fingerprint!==manifest.source_fingerprint||JSON.stringify(prior.configuration_fingerprints)!==JSON.stringify(configurationFingerprints()))throw new Error('SPA transition checkpoint source/configuration mismatch; resume refused.');rows=prior.results||[];}
  const port=await freePort(),baseUrl=`http://127.0.0.1:${port}`,env=appRuntime(baseUrl,runDir),serverRecord=await startPhp(baseUrl,env,path.join(runDir,'spa-server.log'));
  const edge=['C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe','C:/Program Files/Microsoft/Edge/Application/msedge.exe'].find(x=>fs.existsSync(x));
  if(!edge){if(serverRecord.server.exitCode===null)serverRecord.server.kill();try{fs.closeSync(serverRecord.fd);}catch{}throw new Error('Microsoft Edge executable is unavailable for after-run SPA transitions.');}
  let browser;try{browser=await chromium.launch({headless:true,executablePath:edge});
  const done=new Set(rows.map(row=>`${row.route_name}|${row.viewport}|${row.trial}`));
    const viewports=[{name:'desktop',width:1440,height:900},{name:'mobile',width:390,height:844}];
    for(const target of spaTransitions.routes){
      if(!manifest.cases.some(route=>route.name===target.name))throw new Error(`After-run baseline inventory is missing SPA transition route ${target.name}.`);
      for(const viewport of viewports)for(let trial=1;trial<=3;trial++){
        const key=`${target.name}|${viewport.name}|${trial}`;if(done.has(key))continue;
        rows.push(await spaTransitions.transition(browser,baseUrl,viewport,target,trial,statePath));
        done.add(key);fs.writeFileSync(progressPath,JSON.stringify({run_id:manifest.run_id,source_fingerprint:manifest.source_fingerprint,configuration_fingerprints:configurationFingerprints(),completed:rows.length,updated_at:new Date().toISOString(),results:rows},null,2)+'\n',{mode:0o600});
      }
    }
  }finally{if(browser)await browser.close();if(serverRecord.server.exitCode===null)serverRecord.server.kill();try{fs.closeSync(serverRecord.fd);}catch{}}
  const output=path.join(runDir,'ERP_PAGE_SPEED_SPA_TRANSITIONS.json');fs.writeFileSync(output,JSON.stringify(rows,null,2)+'\n',{mode:0o600});
  if(rows.length!==36)throw new Error(`After-run SPA transition coverage mismatch: expected 36 samples, captured ${rows.length}.`);
  return{rows,output};
}
async function livewireAction(page, actionName, control, execute, timeout=12000){
  const urlPattern=/\/livewire(?:-[^/]+)?\/update/i;let requestCount=0;let requestStarted=null;let responseStatus=null;
  const onRequest=req=>{if(urlPattern.test(new URL(req.url()).pathname)){requestCount++;requestStarted=performance.now();}};
  const onResponse=res=>{if(urlPattern.test(new URL(res.url()).pathname))responseStatus=res.status();};
  page.on('request',onRequest);page.on('response',onResponse);
  const start=Date.now();
  try{
    const response=page.waitForResponse(res=>urlPattern.test(new URL(res.url()).pathname),{timeout}).catch(()=>null);
    await execute(control);const res=await response;
    if(!res)return{action:actionName,status:'unavailable',reason:'No Livewire update response was observed',request_count:requestCount,http_status:responseStatus,elapsed_ms:null};
    try{await page.waitForFunction(()=>{const root=document.querySelector('#main-content')||document.body;return ![...root.querySelectorAll('[wire\\:loading],[aria-busy="true"]')].some(e=>{const s=getComputedStyle(e);return s.display!=='none'&&s.visibility!=='hidden';});},null,{timeout});}
    catch(error){error.code='LIVEWIRE_READY_TIMEOUT';throw error;}
    return{action:actionName,status:res.status()===200?'measured':'failed',request_count:requestCount,http_status:res.status(),elapsed_ms:Date.now()-start,
      ready_stable_ms:requestStarted===null?null:Number((performance.now()-requestStarted).toFixed(2))};
  }catch(error){if(error.code==='LIVEWIRE_READY_TIMEOUT')throw error;return{action:actionName,status:'failed',request_count:requestCount,http_status:responseStatus,elapsed_ms:Date.now()-start,error:String(error.message||error).slice(0,250)};}
  finally{page.off('request',onRequest);page.off('response',onResponse);}
}
async function livewireProbe(page,actionName,control,execute){
  try{return await livewireAction(page,actionName,control,execute);}
  catch(error){return{action:actionName,status:'failed',reason:'Livewire update arrived but the pending UI readiness check timed out.',error:String(error.message||error).slice(0,250),readiness_timeout:error.code==='LIVEWIRE_READY_TIMEOUT'};}
}
async function clientAction(page,actionName,control,execute,waitReady,timeout=5000){
  const start=Date.now();let requests=0;const onRequest=req=>{if(/\/livewire(?:-[^/]+)?\/update/i.test(new URL(req.url()).pathname))requests++;};page.on('request',onRequest);
  try{await execute(control);await waitReady(timeout);await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));
    return{action:actionName,status:'measured',request_count:requests,http_status:null,elapsed_ms:Date.now()-start,completion:'visible DOM state'};}
  catch(error){return{action:actionName,status:'failed',request_count:requests,http_status:null,elapsed_ms:Date.now()-start,error:String(error.message||error).slice(0,250)};}
  finally{page.off('request',onRequest);}
}
async function runReadOnlyInteractions(manifest,runDir,statePath){
  const port=await freePort(),baseUrl=`http://127.0.0.1:${port}`,env=appRuntime(baseUrl,runDir),serverRecord=await startPhp(baseUrl,env,path.join(runDir,'interaction-server.log'));
  const edge=['C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe','C:/Program Files/Microsoft/Edge/Application/msedge.exe'].find(x=>fs.existsSync(x));
  if(!edge){if(serverRecord.server.exitCode===null)serverRecord.server.kill();throw new Error('Microsoft Edge executable is unavailable.');}
  const browser=await chromium.launch({headless:true,executablePath:edge});const rows=[];
  try{
    const owners=[...new Set(manifest.cases.map(x=>x.module))];
    for(const module of owners){
      const group=manifest.cases.filter(x=>x.module===module);
      const route=group.find(x=>/\.(?:index|dashboard|accounts|movements|invoices)$/.test(x.name))||group.find(x=>/\.create$/.test(x.name))||group[0];
      const context=await browser.newContext({baseURL:baseUrl,storageState:statePath,locale:'ar-EG',timezoneId:'Africa/Cairo',viewport:{width:1440,height:900}});const page=await context.newPage();
      try{
        const nav=await audit.measurePage(page,baseUrl,{name:route.name,variant:route.variant,module:route.module,uri:route.uri,url:route.url},{name:'desktop',width:1440,height:900},'interaction-prime',1,false);
        rows.push({module,route_case:route.key,action:'form/page-initialization',status:nav.measured?'measured':'unavailable',http_status:nav.http_status,elapsed_ms:nav.ui_ready_ms,livewire_update_requests:nav.livewire_update_requests,reason:nav.not_measured_reason||null});
        if(!nav.measured)continue;
        const root=page.locator('#main-content');
        const inputs=await root.locator('input').all();let search=null;
        for(const input of inputs){const meta=await input.evaluate(el=>({visible:!!(el.offsetWidth||el.offsetHeight||el.getClientRects().length),attrs:[...el.attributes].filter(a=>a.name.startsWith('wire:model.live')).map(a=>a.value),placeholder:el.getAttribute('placeholder'),aria:el.getAttribute('aria-label')}));
          if(meta.visible&&meta.attrs.length&&/search|بحث|query|filter/i.test(`${meta.placeholder||''} ${meta.aria||''} ${meta.attrs.join(' ')}`)){search=input;break;}}
        if(search)rows.push({module,route_case:route.key,...await livewireProbe(page,'search',search,loc=>loc.fill('DEMO-EG'))});
        const selects=await root.locator('select').all();let select=null;let filterValue=null;
        for(const candidate of selects){const meta=await candidate.evaluate(el=>({live:[...el.attributes].some(a=>a.name.startsWith('wire:model.live')),visible:!!(el.offsetWidth||el.offsetHeight||el.getClientRects().length),current:el.value,options:[...el.options].filter(o=>!o.disabled&&o.value).map(o=>o.value)}));
          const next=meta.options.find(value=>value!==meta.current);if(meta.live&&meta.visible&&next){select=candidate;filterValue=next;break;}}
        if(select){
          rows.push({module,route_case:route.key,...await livewireProbe(page,'filter',select,loc=>loc.selectOption(filterValue))});
        }
        const next=root.locator('[wire\\:click*="nextPage"],button[aria-label*="next" i],button[aria-label*="التالي"]').first();
        if(await next.count()&&!(await next.isDisabled().catch(()=>true)))rows.push({module,route_case:route.key,...await livewireProbe(page,'pagination',next,loc=>loc.click())});
        const tab=page.locator('[role="tab"][aria-selected="false"],a[href*="tab="]').first();
        if(await tab.count()){
          const handle=await tab.elementHandle(),beforeUrl=page.url();
          rows.push({module,route_case:route.key,...await clientAction(page,'tab',tab,loc=>loc.click(),timeout=>page.waitForFunction(({el,url})=>{
            const state=el.getAttribute('aria-selected')==='true'||el.getAttribute('aria-current')==='page'||el.getAttribute('data-state')==='active';
            return state||location.href!==url;
          },{el:handle,url:beforeUrl},{timeout}))});
        }
        const modal=page.locator('button[aria-haspopup="dialog"],button[data-modal-trigger]').first();
        if(await modal.count()){
          const action=await clientAction(page,'modal-open',modal,loc=>loc.click(),timeout=>page.getByRole('dialog').first().waitFor({state:'visible',timeout}));
          rows.push({module,route_case:route.key,...action});
        }
      }finally{await context.close();}
      fs.writeFileSync(path.join(runDir,'interaction-progress.json'),JSON.stringify(rows,null,2)+'\n');
    }
  }finally{await browser.close();if(serverRecord.server.exitCode===null)serverRecord.server.kill();try{fs.closeSync(serverRecord.fd);}catch{}}
  return rows;
}
async function runProductionLikeExperiment(baseManifest,runDir,actor){
  const wanted=['dashboard','organization.users.show','maintenance.work-orders.show','maintenance.index','customers.index'];
  const pages=wanted.map(name=>baseManifest.cases.find(c=>c.name===name)).filter(Boolean);
  if(pages.length!==5)throw new Error(`Production-like sample route inventory incomplete (${pages.length}/5); experiment will not substitute different pages.`);
  const edge=['C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe','C:/Program Files/Microsoft/Edge/Application/msedge.exe'].find(fs.existsSync);
  if(!edge)throw new Error('Microsoft Edge executable is missing.');
  const browser=await chromium.launch({headless:true,executablePath:edge});const rows=[];
  try{
    for(const mode of ['config-cache-off','config-cache-on']){
      const modeDir=path.join(runDir,'production-like',mode);privateDir(modeDir);const port=await freePort(),baseUrl=`http://127.0.0.1:${port}`;
      const env=appRuntime(baseUrl,modeDir,mode==='config-cache-on');
      const serverRecord=await startPhp(baseUrl,env,path.join(modeDir,'php-server.log'));
      const statePath=path.join(modeDir,'auth-storage-state.json');
      try{
        await loginState(browser,baseUrl,actor,statePath);
        for(const viewport of [{name:'desktop',width:1440,height:900}]){
          for(const route of pages){
            const context=await browser.newContext({baseURL:baseUrl,storageState:statePath,locale:'ar-EG',timezoneId:'Africa/Cairo',viewport});const page=await context.newPage();
            try{for(const [temperature,trial]of[['cold',0],['warm',1],['warm',2],['warm',3]]){
              const sample=await audit.measurePage(page,baseUrl,{name:route.name,variant:route.variant,module:route.module,uri:route.uri,url:route.url},viewport,temperature,trial,false);
              rows.push({...sample,experiment_mode:mode,route_case:route.key,debugbar:false,pulse:false,route_cache:false,app_env:'testing'});
              if(sample.http_status===200&&!sample.redirected_to_login)await context.storageState({path:statePath});
            }}finally{await context.close();}
          }
        }
      }finally{if(serverRecord.server.exitCode===null)serverRecord.server.kill();try{fs.closeSync(serverRecord.fd);}catch{}}
    }
  }finally{await browser.close();}
  const summary=[];
  for(const mode of ['config-cache-off','config-cache-on'])for(const route of pages)for(const viewport of ['desktop']){
    const samples=rows.filter(x=>x.experiment_mode===mode&&x.route_case===route.key&&x.viewport===viewport&&x.temperature==='warm'&&x.measured);
    summary.push({experiment_mode:mode,route_case:route.key,module:route.module,viewport,warm_samples:samples.length,
      median_ui_ready_ms:median(samples.map(x=>x.ui_ready_ms)),median_ttfb_ms:median(samples.map(x=>x.ttfb_ms)),
      median_domcontentloaded_ms:median(samples.map(x=>x.domcontentloaded_ms)),median_load_ms:median(samples.map(x=>x.load_ms)),
      failures:rows.filter(x=>x.experiment_mode===mode&&x.route_case===route.key&&x.viewport===viewport&&(!x.measured||x.http_errors?.length||x.js_errors?.length)).length});
  }
  const result={generated_at:new Date().toISOString(),status:'separate exploratory experiment; excluded from primary before/after classification',
    sample_design:'Five representative desktop pages × one cold+three warm, run independently for each mode (40 total navigations).',
    config_cache_off:{APP_ENV:'testing',config_cache:false,route_cache:false,Debugbar:false,Pulse:false},
    config_cache_on:{APP_ENV:'testing',config_cache:true,route_cache:false,Debugbar:false,Pulse:false},
    assets:'Existing Vite built asset manifest reused unchanged for both modes.',runtime_note:'This isolates config-cache state under testing APP_ENV; it is not a production deployment or full production configuration benchmark.',summary,samples:rows};
  const privateSamples=path.join(runDir,'production-like-samples.json');fs.writeFileSync(privateSamples,JSON.stringify(rows,null,2)+'\n',{mode:0o600});
  fs.writeFileSync(path.join(perf,'ERP_PRODUCTION_LIKE_EXPERIMENT.json'),JSON.stringify({...result,samples:undefined,sample_count:rows.length,raw_samples_retained_privately:true},null,2)+'\n');
  return result;
}
function writeInteractionReport(runId,rows){
  const receiptPath=path.join(root,'.tmp/optimization-regression/livewire-operation-latencies-55460.json');
  const receipt=fs.existsSync(receiptPath)?JSON.parse(fs.readFileSync(receiptPath,'utf8')):null;
  const ownerOperations=receipt?.database==='erp_elec_new_test@55460'&&receipt.status==='passed'&&Array.isArray(receipt.operations)?receipt.operations:[];
  const categories=['search','filter','pagination','tab','modal-open','form/page-initialization','validation','save-draft','approval','denial','export'];
  const implemented=[...new Set(uniqueCases().map(x=>x.module))];const lines=['# Livewire interaction benchmark','',`Run: ${runId}. Browser: Microsoft Edge, 1440×900. Livewire update time is measured from the triggering action to response and pending-UI completion; browser navigation is separately recorded as form/page initialization.`,
    'Read probes are non-persistent. Validation and business mutations are not replayed on canonical 55461; the independent owner-action evidence below uses isolated 55460 and is not comparable with navigation timings.', '',
    '| Module family | Route case | Interaction | Status | HTTP | Requests | Elapsed ms | Notes |','|---|---|---|---|---:|---:|---:|---|'];
  for(const module of implemented){const modRows=rows.filter(x=>x.module===module);if(!modRows.length){const c=uniqueCases().find(x=>x.module===module);lines.push(`| ${module} | ${c?.key||'—'} | read-only probes | not measurable | — | — | — | No safe supported control detected; not inferred from route timing. |`);continue;}
    for(const row of modRows)lines.push(`| ${module} | ${row.route_case} | ${row.action} | ${row.status} | ${row.http_status??'—'} | ${row.request_count??row.livewire_update_requests??'—'} | ${row.elapsed_ms??'—'} | ${row.reason||row.error||'—'} |`);
  }
  lines.push('', '## Lifecycle action evidence','', '| Action | Status | Measurement source | Safety boundary |','|---|---|---|---|');
  for(const type of ['validation','save-draft','approval','denial','export']){const op=ownerOperations.find(item=>item.operation===type);lines.push(`| ${type} | ${op?`passed (n=1, UI asserted; HTTP ${op.livewire_http_status??'download'})`:'not measured'} | ${op?`55460 receipt ${receipt.run_id}; ${op.started_at} → ${op.ended_at}; ${op.elapsed_ms} ms`:'No matching isolated owner-action receipt.'} | Separate fixture database; single observations only, no P95/statistical claim. |`);}
  if(ownerOperations.length)lines.push('',`Owner-action receipt: [livewire-operation-latencies-55460.json](../../.tmp/optimization-regression/livewire-operation-latencies-55460.json). The timestamps and UI assertions are retained; these timings are separate from the 55461 aggregate.`);
  lines.push('', 'Available interaction categories:', ...categories.map(x=>`- ${x}`), '', 'Missing selectors or workflows are reported as unavailable; the runner does not fabricate interaction latency or submit guessed business forms.', '');
  fs.writeFileSync(path.join(perf,'ERP_LIVEWIRE_INTERACTION_BENCHMARK.md'),lines.join('\n'));
  fs.writeFileSync(path.join(perf,'ERP_LIVEWIRE_INTERACTION_BENCHMARK.json'),JSON.stringify({run_id:runId,generated_at:new Date().toISOString(),status:rows.length?'measured-safe-probes':'pending',rows,separate_owner_action_evidence:ownerOperations.map(({operation,started_at,ended_at,elapsed_ms,livewire_http_status,download,ui_assertion})=>({operation,started_at,ended_at,elapsed_ms,livewire_http_status:livewire_http_status??null,download:download??null,ui_assertion,database:'erp_elec_new_test@55460',sample_count:1,statistical_claims:false})),lifecycle_actions_not_measured:['validation','save-draft','approval','denial','export'].filter(type=>!ownerOperations.some(op=>op.operation===type)),note:'55460 one-observation owner-action timings include UI assertions and remain separate from the 55461 route benchmark; no P95 claims.'},null,2)+'\n');
}
function currentProfileArguments(runDir,manifest){
  const eligible=manifest.cases.filter(c=>!knownGates.has(c.name)&&c.url);const file=path.join(runDir,'native-profile-route-manifest.json');
  fs.writeFileSync(file,JSON.stringify({run_id:path.basename(runDir),database:{name:'erp_elec_new_test',port:55461},routes:eligible.map(c=>({name:c.name,route_variant:c.variant,module:c.module,uri:c.uri,url:c.url,guest_route:['login','password.request'].includes(c.name)}))},null,2)+'\n');
  const output=path.join(work,'native-profile',`after-${path.basename(runDir)}`,'profiles.json');privateDir(path.dirname(output));
  const resumeArgs=fs.existsSync(output)?['--resume-profile',output]:[];
  const run=spawnSync(process.execPath,[path.join(root,'tests/E2E/native-performance-profile.cjs'),'--profile-route-manifest',file,'--profile-output',output,...resumeArgs],{cwd:root,env:{...process.env,NATIVE_PROFILE_CAPTURE_SQL:'1'},encoding:'utf8',windowsHide:true,maxBuffer:2*1024*1024});
  if(run.error||run.status!==0)throw new Error(`Native Debugbar manifest profile failed. ${String(run.stdout||'').slice(-800)} ${String(run.stderr||'').slice(-800)}`);
  const profiles=JSON.parse(fs.readFileSync(output,'utf8')).profiles,candidates=[],modules=[...new Set(profiles.map(row=>row.module||'Unknown'))];
  const authTables=/\b(?:users|roles|permissions|role_permissions|user_roles|access_grants|user_branch_scopes|user_warehouse_scopes|sessions|password_reset_tokens|personal_access_tokens|pg_catalog|information_schema)\b/i;
  const sqlRows=rows=>rows.flatMap(row=>(row.private_statements||[]).map((statement,index)=>({route_case:row.route_case,module:row.module||'Unknown',statement_index:index,
    duration_ms:Number.isFinite(Number(statement.duration))?Number((Number(statement.duration)*1000).toFixed(3)):null,sql:statement.sql,params:Array.isArray(statement.params)?statement.params:null,route_database_time_ms:row.database_time_ms??null})))
    .filter(row=>/^\s*(?:select|with)\b/i.test(row.sql||'')&&Array.isArray(row.params)&&(row.sql.match(/\?/g)||[]).length===row.params.length);
  for(const module of [...new Set(profiles.map(row=>row.module||'Unknown'))]){
    const rows=profiles.filter(row=>row.module===module).sort((a,b)=>(b.database_time_ms??-1)-(a.database_time_ms??-1));
    const businessRows=rows.map(source=>({source,statements:sqlRows([source]).filter(statement=>!authTables.test(statement.sql))})).find(item=>item.statements.length);
    if(businessRows){const selected=[...businessRows.statements].sort((a,b)=>(b.duration_ms??-1)-(a.duration_ms??-1))[0];
      candidates.push({...selected,selection_method:selected.duration_ms===null?'first captured business SELECT from highest aggregate-DB-time route; per-statement duration unavailable':'longest recorded business SELECT duration on highest aggregate-DB-time route'});}
  }
  const authRows=sqlRows(profiles).filter(row=>authTables.test(row.sql)).sort((a,b)=>(b.route_database_time_ms??-1)-(a.route_database_time_ms??-1)||(b.duration_ms??-1)-(a.duration_ms??-1));
  if(authRows.length)candidates.push({...authRows[0],module:'GLOBAL_AUTH',selection_method:'top captured authentication/infrastructure query by route aggregate-DB-time; reported separately'});
  fs.writeFileSync(path.join(runDir,'captured-query-candidates.json'),JSON.stringify({run_id:path.basename(runDir),source_fingerprint:audit.sourceFingerprint(),captured_at:new Date().toISOString(),modules,candidates},null,2)+'\n',{mode:0o600});
  return profiles.map(({private_statements,...profile})=>profile);
}
function actualExplainPlans(runDir,runId,sourceFingerprint){
  const candidatesPath=path.join(runDir,'captured-query-candidates.json'),rawPath=path.join(runDir,'actual-query-plans-private.json');
  if(!fs.existsSync(candidatesPath))throw new Error('Private captured SQL candidate file is missing; EXPLAIN will not guess SQL.');
  const input=JSON.parse(fs.readFileSync(candidatesPath,'utf8'));
  if(input.run_id!==runId||input.source_fingerprint!==sourceFingerprint)throw new Error('EXPLAIN candidate source does not match the frozen after run.');
  const candidates=input.candidates;
  const child=spawnSync('php',[path.join(root,'tests/E2E/support/explain-captured-queries.php'),candidatesPath,rawPath],{cwd:root,env:{...process.env,...bootstrap.env},encoding:'utf8',windowsHide:true,maxBuffer:1024*1024});
  if(child.error||child.status!==0)throw new Error(`Read-only captured-query EXPLAIN failed. ${String(child.stderr||'').slice(-600)}`);
  const raw=JSON.parse(fs.readFileSync(rawPath,'utf8'));
  const plans=raw.results.map(row=>{
    const plan=row.plan?.[0]?.Plan||{},nodes=[];const visit=node=>{if(!node||nodes.length>=100)return;nodes.push({node_type:node['Node Type']||null,relation:node['Relation Name']||null,index:node['Index Name']||null,sort_key:node['Sort Key']||null,plan_rows:node['Plan Rows']??null,actual_rows:node['Actual Rows']??null,actual_total_time_ms:node['Actual Total Time']??null,shared_hit_blocks:node['Shared Hit Blocks']??null,shared_read_blocks:node['Shared Read Blocks']??null,total_cost:node['Total Cost']??null});for(const child of node.Plans||[])visit(child);};visit(plan);
    return{module:row.module,route_case:row.route_case,statement_index:row.statement_index,status:row.status,query_duration_ms:row.duration_ms,
      planning_time_ms:row.plan?.[0]?.['Planning Time']??null,execution_time_ms:row.plan?.[0]?.['Execution Time']??null,root_node:plan['Node Type']||null,plan_rows:plan['Plan Rows']??null,actual_rows:plan['Actual Rows']??null,shared_hit_blocks:plan['Shared Hit Blocks']??null,shared_read_blocks:plan['Shared Read Blocks']??null,total_cost:plan['Total Cost']??null,
      seq_scan_relations:nodes.filter(x=>x.node_type==='Seq Scan').map(x=>x.relation),index_scans:nodes.filter(x=>x.index).map(x=>({relation:x.relation,index:x.index,node_type:x.node_type})),sort_keys:nodes.filter(x=>x.sort_key).flatMap(x=>x.sort_key),node_types:[...new Set(nodes.map(x=>x.node_type).filter(Boolean))],error_code:row.error_code||null};
  });
  const moduleSet=new Set(plans.map(row=>row.module));
  for(const module of input.modules)if(!moduleSet.has(module))plans.push({module,status:'no captured domain SELECT with replayable bindings'});
  if(!moduleSet.has('GLOBAL_AUTH'))plans.push({module:'GLOBAL_AUTH',status:'no captured authentication/infrastructure SELECT with replayable bindings'});
  const result={run_id:runId,source_fingerprint:sourceFingerprint,database:'erp_elec_new_test@127.0.0.1:55461',plan_type:'EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON), actual SELECT execution in a read-only transaction, 3 second statement timeout per statement',private_raw_evidence:'actual-query-plans-private.json',module_plans:plans};
  fs.writeFileSync(path.join(perf,'ERP_QUERY_PLAN_SUMMARY.json'),JSON.stringify(result,null,2)+'\n');
  return result;
}
function interactionCatalog(cases){
  const byModule=new Map();for(const c of cases){if(!byModule.has(c.module))byModule.set(c.module,[]);byModule.get(c.module).push(c);}
  const preferred=/\.(?:index|dashboard|accounts|movements|invoices|work-orders)$/;
  const entries=[];
  for(const [module,rows]of byModule){const route=rows.find(x=>preferred.test(x.name))||rows.find(x=>/\.create$/.test(x.name))||rows[0];
    for(const action of ['search','filter','pagination','tab','modal','form-initialization','validation','save-draft','approval','denial','export'])entries.push({module,route_case:route.key,route_name:route.name,variant:route.variant,url:route.url,action,
      safety: ['save-draft','approval','denial'].includes(action)?'write-requires-separate-owner-fixture':'read-only-eligible',status:'pending-after-run'});
  }
  return entries;
}
async function runAfter(options){
  verifyArchive();assertOwnedTarget();
  const expected=String(options.freeze||'').toLowerCase();if(!/^[a-f0-9]{64}$/.test(expected))throw new Error('Explicit --freeze-sha256=<64 hex chars> is required; no measurements will run without it.');
  const beforeFingerprint=audit.sourceFingerprint();if(beforeFingerprint!==expected)throw new Error('Application fingerprint differs from the supplied frozen-source fingerprint; after run aborted.');
  let runId=options.resume||`after-${new Date().toISOString().replace(/[-:.TZ]/g,'').slice(0,14)}`;
  if(!/^after-[A-Za-z0-9_-]{1,40}$/.test(runId))throw new Error('Resume run id must be an existing after-<id> checkpoint.');
  const runDir=path.join(work,'after',runId),privateManifestPath=path.join(runDir,'run-manifest.json'),configHashes=configurationFingerprints();
  let allManifest,manifest,initialAuthCounts;
  if(options.resume){
    if(!fs.existsSync(privateManifestPath))throw new Error('Resume checkpoint manifest is missing.');
    const saved=JSON.parse(fs.readFileSync(privateManifestPath,'utf8'));
    if(saved.run_id!==runId||saved.source_fingerprint!==beforeFingerprint||JSON.stringify(saved.configuration_fingerprints)!==JSON.stringify(configHashes))throw new Error('Resume refused: checkpoint source/configuration does not match the frozen candidate.');
    allManifest=saved.manifest;manifest={...allManifest,cases:uniqueCases()};
    initialAuthCounts=saved.initial_auth_metadata_counts||null;if(!initialAuthCounts)throw new Error('Resume checkpoint is missing its pre-auth metadata counts.');
    if(JSON.stringify(manifest.cases.map(c=>c.key))!==JSON.stringify(allManifest.cases.filter(c=>!allManifest.additional_cases?.some(extra=>extra.key===c.key)).map(c=>c.key)))throw new Error('Resume refused: original route inventory changed.');
  }else{
    initialAuthCounts=authMetadataCounts();manifest={run_id:runId,source_fingerprint:beforeFingerprint,database:{name:'erp_elec_new_test',host:'127.0.0.1',port:55461},cases:uniqueCases(),baseline_archive:archive};
  }
  privateDir(runDir);const publicRunDir=path.join(perf,'runs',runId);privateDir(publicRunDir);const progressPath=path.join(runDir,'ERP_PAGE_SPEED_AFTER_PROGRESS.json');
  if(!options.resume)fs.writeFileSync(path.join(publicRunDir,'manifest.json'),JSON.stringify(manifest,null,2)+'\n');
  const actor=createAuditActor();const statePath=path.join(runDir,'auth-storage-state.json');
  const authPort=await freePort(),authUrl=`http://127.0.0.1:${authPort}`,env=appRuntime(authUrl,runDir),authServer=await startPhp(authUrl,env,path.join(runDir,'auth-server.log'));
  let browser;try{const edge=['C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe','C:/Program Files/Microsoft/Edge/Application/msedge.exe'].find(x=>fs.existsSync(x));browser=await chromium.launch({headless:true,executablePath:edge});await loginState(browser,authUrl,actor,statePath);}finally{if(browser)await browser.close();if(authServer.server.exitCode===null)authServer.server.kill();try{fs.closeSync(authServer.fd);}catch{}}
  if(!allManifest){const readEnv=appRuntime(authUrl,runDir),ids=audit.fixtureIds(readEnv),additional=discoverAdditionalCases(readEnv,manifest.cases,ids);allManifest={...manifest,cases:[...manifest.cases,...additional],additional_cases:additional};
    fs.writeFileSync(path.join(publicRunDir,'manifest.json'),JSON.stringify(allManifest,null,2)+'\n');fs.writeFileSync(privateManifestPath,JSON.stringify({run_id:runId,source_fingerprint:beforeFingerprint,configuration_fingerprints:configHashes,initial_auth_metadata_counts:initialAuthCounts,manifest:allManifest},null,2)+'\n',{mode:0o600});}
  const cachedBrowser=readStage(runDir,'stage-browser',runId,beforeFingerprint),browserStage=cachedBrowser||writeStage(runDir,'stage-browser',runId,beforeFingerprint,await collectBrowserSamples(allManifest,runDir,statePath,progressPath)),browserSamples=browserStage.samples;
  const cachedSpa=readStage(runDir,'stage-spa',runId,beforeFingerprint),spaRun=cachedSpa||writeStage(runDir,'stage-spa',runId,beforeFingerprint,await collectAfterSpaTransitions(allManifest,runDir,statePath));
  let interactionRows=readStage(runDir,'stage-interactions',runId,beforeFingerprint);if(!interactionRows){interactionRows=writeStage(runDir,'stage-interactions',runId,beforeFingerprint,await runReadOnlyInteractions(allManifest,runDir,statePath));}writeInteractionReport(runId,interactionRows);
  const productionLike=readStage(runDir,'stage-production-like',runId,beforeFingerprint)||writeStage(runDir,'stage-production-like',runId,beforeFingerprint,await runProductionLikeExperiment(allManifest,runDir,actor));
  const cachedSql=readStage(runDir,'stage-sql',runId,beforeFingerprint),sqlProfiles=cachedSql||writeStage(runDir,'stage-sql',runId,beforeFingerprint,currentProfileArguments(runDir,allManifest));
  const queryPlans=readStage(runDir,'stage-explains',runId,beforeFingerprint)||writeStage(runDir,'stage-explains',runId,beforeFingerprint,actualExplainPlans(runDir,runId,beforeFingerprint));
  const expectedSqlCases=allManifest.cases.filter(c=>!knownGates.has(c.name)&&c.url).length;
  if(sqlProfiles.length!==expectedSqlCases)throw new Error(`Native SQL profile coverage mismatch: expected ${expectedSqlCases} eligible cases, captured ${sqlProfiles.length}.`);
  const endFingerprint=audit.sourceFingerprint();if(endFingerprint!==beforeFingerprint)throw new Error('Application source changed during after pipeline; output is retained as an interrupted run and will not be compared.');
  const finalAuthCounts=authMetadataCounts(),authCountDelta=Object.fromEntries(Object.keys(initialAuthCounts).map(key=>[key,Number(finalAuthCounts[key])-Number(initialAuthCounts[key])]));
  const seedStatusPath=path.join(work,'seed-status.json'),seedStatus=fs.existsSync(seedStatusPath)?JSON.parse(fs.readFileSync(seedStatusPath,'utf8')):[];
  const seededInvoices=[...seedStatus].reverse().find(item=>item.seeder==='PerformancePageDraftSupplement'&&item.summary);
  const eligibleNavigationCases=allManifest.cases.filter(c=>!knownGates.has(c.name)&&c.url).length,plannedNavigationSamples=eligibleNavigationCases*8;
  const after={run_id:manifest.run_id,started_at:new Date().toISOString(),source_fingerprint_start:beforeFingerprint,source_fingerprint_end:endFingerprint,source_unchanged:true,
    runtime:{php:spawnSync('php',['-r','echo PHP_VERSION;'],{encoding:'utf8'}).stdout.trim(),browser:'Microsoft Edge',viewport:{desktop:'1440x900',mobile:'390x844'},app_env:'testing',app_debug:false,cache_store:'array',session_driver:'file',queue:'sync',network_throttling:'none',config_cache:false,route_cache:false,opcache_enable_cli:spawnSync('php',['-r','echo ini_get("opcache.enable_cli");'],{encoding:'utf8'}).stdout.trim(),configuration_fingerprints:Object.fromEntries(['.env','config/app.php','config/database.php','public/build/manifest.json'].filter(file=>fs.existsSync(path.join(root,file))).map(file=>[file,shaFile(path.join(root,file))])),vite_manifest_sha256:fs.existsSync(path.join(root,'public/build/manifest.json'))?shaFile(path.join(root,'public/build/manifest.json')):null,timed_requests:'Debugbar/Pulse disabled'},
    database:manifest.database,database_preservation:{canonical_business_graph_reused:true,reset_or_reseed:false,performance_auth_refresh:'A fresh synthetic Administrator account, role membership, and active branch/warehouse scopes are created for each start or resume; normal login/authentication metadata may also be added.',pre_run_auth_metadata_counts:initialAuthCounts,final_auth_metadata_counts:finalAuthCounts,auth_metadata_count_delta:authCountDelta,baseline_seed_status:{file:'../../.tmp/page-speed-audit/seed-status.json',passed_seeders:seedStatus.filter(item=>item.status==='passed'||item.status==='passed-via-DatabaseSeeder').length,skipped_seeders:seedStatus.filter(item=>item.status==='skipped').length,customer_invoice_count:seededInvoices?.summary?.customer_invoice_count??null,supplier_invoice_count:seededInvoices?.summary?.supplier_invoice_count??null,full_business_row_count_fingerprint_available:false},business_row_count_comparison:'No baseline all-table row-count fingerprint exists in seed-status.json; no whole-database identity claim is made.'},baseline_run_id:baseline.conditions.generated_run_id,baseline_archive_sha256:JSON.parse(fs.readFileSync(path.join(archive,'SHA256.json'),'utf8')),
    route_case_count:manifest.cases.length,all_route_case_count:allManifest.cases.length,eligible_navigation_case_count:eligibleNavigationCases,planned_navigation_samples:plannedNavigationSamples,gated_or_unavailable_placeholder_samples:browserSamples.length-plannedNavigationSamples,resume_prime_count:browserStage.resume_prime_count||0,route_cases:manifest.cases,additional_eligible_cases:allManifest.additional_cases||[],browser_samples:browserSamples,spa_transitions:spaRun.rows,spa_transition_evidence_path:spaRun.output,sql_profiles:sqlProfiles,query_plan_summary:queryPlans,
    interaction_catalog:interactionCatalog(allManifest.cases),interaction_results:interactionRows,
    interaction_status:'Read-only probes ran on 55461. Separate 55460 owner-action receipts include one-observation UI-asserted latencies and are not comparable to navigation samples.',
    production_like_experiment:{file:'ERP_PRODUCTION_LIKE_EXPERIMENT.json',status:productionLike.status,representative_pages:5,navigations:productionLike.samples.length,cache_modes:['config-cache-off','config-cache-on'],excluded_from_primary_comparison:true}};
  fs.writeFileSync(path.join(perf,'ERP_PAGE_SPEED_AFTER.json'),JSON.stringify(after,null,2)+'\n');
  writeComparison(after);
  process.stdout.write(JSON.stringify({status:'after-run-complete',run_id:manifest.run_id,cases:manifest.cases.length,all_cases:allManifest.cases.length,eligible_navigation_cases:eligibleNavigationCases,planned_navigation_samples:plannedNavigationSamples,placeholder_samples:browserSamples.length-plannedNavigationSamples,resume_primes:browserStage.resume_prime_count||0,sql_profiles:sqlProfiles.length,source_unchanged:true})+'\n');
}

async function runPreflight(options){
  verifyArchive();assertOwnedTarget();
  const expected=String(options.freeze||'').toLowerCase();if(!/^[a-f0-9]{64}$/.test(expected))throw new Error('Preflight requires --freeze-sha256=<exact frozen source fingerprint>.');
  const start=audit.sourceFingerprint();if(start!==expected)throw new Error('Preflight fingerprint does not match the frozen application source.');
  const cases=uniqueCases();const selected=['dashboard|default','login|default','customers.show|financial'].map(key=>cases.find(c=>c.key===key));
  if(selected.some(c=>!c))throw new Error('Preflight inventory is missing one of its three representative route cases.');
  const runId=`preflight-${new Date().toISOString().replace(/[-:.TZ]/g,'').slice(0,14)}`,runDir=path.join(work,'after',runId);privateDir(runDir);
  const actor=createAuditActor(),port=await freePort(),baseUrl=`http://127.0.0.1:${port}`,env=appRuntime(baseUrl,runDir),serverRecord=await startPhp(baseUrl,env,path.join(runDir,'php-server.log'));
  const edge=['C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe','C:/Program Files/Microsoft/Edge/Application/msedge.exe'].find(x=>fs.existsSync(x));if(!edge)throw new Error('Microsoft Edge executable is missing.');
  const browser=await chromium.launch({headless:true,executablePath:edge}),statePath=path.join(runDir,'auth-storage-state.json'),rows=[];
  try{
    await loginState(browser,baseUrl,actor,statePath);
    const desktop={name:'desktop',width:1440,height:900};
    for(const route of selected){
      const guest=route.name==='login';const context=await browser.newContext({baseURL:baseUrl,...(guest?{}:{storageState:statePath}),locale:'ar-EG',timezoneId:'Africa/Cairo',viewport:desktop});
      try{
        if(guest){rows.push(await guestPages.sample(browser,baseUrl,{name:route.name,path:new URL(route.url,baseUrl).pathname,module:route.module,ready:'input[name="email"]'},desktop,'preflight',0,context));}
        else{const page=await context.newPage();rows.push(await audit.measurePage(page,baseUrl,route,desktop,'preflight',0,false));}
      }finally{await context.close();}
    }
  }finally{await browser.close();if(serverRecord.server.exitCode===null)serverRecord.server.kill();try{fs.closeSync(serverRecord.fd);}catch{}}
  const end=audit.sourceFingerprint();const compact=rows.map(row=>({route_name:row.route_name,route_variant:row.route_variant,viewport:row.viewport,http_status:row.http_status,measured:row.measured,healthy:row.healthy,ui_ready_ms:row.ui_ready_ms,not_measured_reason:row.not_measured_reason||row.ready_error||null}));
  fs.writeFileSync(path.join(runDir,'preflight.json'),JSON.stringify({run_id:runId,source_fingerprint_start:start,source_fingerprint_end:end,source_unchanged:start===end,samples:compact},null,2)+'\n',{mode:0o600});
  if(end!==start)throw new Error('Source changed during protocol preflight.');
  if(rows.length!==3||rows.some(row=>!row.measured||row.http_status!==200))throw new Error('Protocol preflight did not render all three representative pages; full timing run remains blocked.');
  process.stdout.write(JSON.stringify({status:'preflight-passed',samples:compact.length,all_http_200:true,all_primary_ready:true,source_unchanged:true,private_output:path.join(runDir,'preflight.json')})+'\n');
}

function profileOnly(){
  verifyArchive();assertOwnedTarget();const start=audit.sourceFingerprint();const runId=`sql-inventory-${new Date().toISOString().replace(/[-:.TZ]/g,'').slice(0,14)}`;
  const runDir=path.join(work,'after',runId);privateDir(runDir);const env=appRuntime('http://127.0.0.1:8000',runDir);
  const ids=audit.fixtureIds(env);const cases=uniqueCases();const additional=discoverAdditionalCases(env,cases,ids);
  const allCases=[...cases,...additional];const manifest={run_id:runId,source_fingerprint:start,database:{name:'erp_elec_new_test',host:'127.0.0.1',port:55461},cases:allCases,additional_cases:additional};
  const file=path.join(runDir,'profile-route-manifest.json');fs.writeFileSync(file,JSON.stringify({database:manifest.database,routes:allCases.filter(c=>c.url&&!knownGates.has(c.name)).map(c=>({name:c.name,route_variant:c.variant,module:c.module,uri:c.uri,url:c.url,guest_route:['login','password.request'].includes(c.name)}))},null,2)+'\n');
  const output=path.join(work,'native-profile',runId,'profiles.json');privateDir(path.dirname(output));
  const r=spawnSync(process.execPath,[path.join(root,'tests/E2E/native-performance-profile.cjs'),'--profile-route-manifest',file,'--profile-output',output],{cwd:root,env:process.env,encoding:'utf8',windowsHide:true,maxBuffer:2*1024*1024});
  if(r.error||r.status!==0)throw new Error(`Per-case native SQL profile failed. ${String(r.stdout||'').slice(-700)} ${String(r.stderr||'').slice(-700)}`);
  const profiles=JSON.parse(fs.readFileSync(output,'utf8')).profiles;const end=audit.sourceFingerprint();
  const result={run_id:runId,source_fingerprint_start:start,source_fingerprint_end:end,source_unchanged:start===end,
    evidence_classification:start===end?'interim current-source SQL inventory; repeat affected cases after final freeze':'interim mixed-source SQL inventory; not valid as final before/after evidence',database:manifest.database,
    expected_profiles:manifest.cases.filter(c=>c.url&&!knownGates.has(c.name)).length,profiles,additional_cases:additional,
    timing_mode:'Separate native Debugbar request per route case, APP_ENV=local; browser timings were not run.'};
  fs.writeFileSync(path.join(perf,'ERP_PAGE_SPEED_SQL_INVENTORY.json'),JSON.stringify(result,null,2)+'\n');
  process.stdout.write(JSON.stringify({status:'sql-inventory-complete',cases:manifest.cases.length,eligible_profiles:result.expected_profiles,captured:profiles.filter(x=>x.profile_captured).length,additional:additional.length,output:path.join(perf,'ERP_PAGE_SPEED_SQL_INVENTORY.json')})+'\n');
}
function profileGuestsOnly(){
  verifyArchive();assertOwnedTarget();const runId=`guest-sql-${new Date().toISOString().replace(/[-:.TZ]/g,'').slice(0,14)}`;
  const runDir=path.join(work,'after',runId);privateDir(runDir);const rows=uniqueCases().filter(c=>['login','password.request'].includes(c.name));
  if(rows.length!==2)throw new Error('Expected exactly two anonymous guest page cases.');
  const manifestPath=path.join(runDir,'guest-profile-manifest.json');fs.writeFileSync(manifestPath,JSON.stringify({database:{name:'erp_elec_new_test',port:55461},routes:rows.map(c=>({name:c.name,route_variant:c.variant,module:c.module,uri:c.uri,url:c.url,guest_route:true}))},null,2)+'\n');
  const output=path.join(work,'native-profile',runId,'profiles.json');privateDir(path.dirname(output));
  const r=spawnSync(process.execPath,[path.join(root,'tests/E2E/native-performance-profile.cjs'),'--profile-route-manifest',manifestPath,'--profile-output',output],{cwd:root,env:process.env,encoding:'utf8',windowsHide:true,maxBuffer:1024*1024});
  if(r.error||r.status!==0)throw new Error(`Anonymous guest SQL profiling failed. ${String(r.stdout||'').slice(-500)} ${String(r.stderr||'').slice(-500)}`);
  const profiles=JSON.parse(fs.readFileSync(output,'utf8')).profiles;
  process.stdout.write(JSON.stringify({status:'guest-profiles-complete',profiles:profiles.length,captured:profiles.filter(x=>x.profile_captured).length,output})+'\n');
}
function summarizeInterimSql(){
  const mainPath=path.join(work,'native-profile','sql-inventory-20261031184450','profiles.json');
  const guestPath=path.join(work,'native-profile','guest-sql-20261031185642','profiles.json');
  if(!fs.existsSync(mainPath)||!fs.existsSync(guestPath))throw new Error('Required interim private SQL profile checkpoints were not found.');
  const main=JSON.parse(fs.readFileSync(mainPath,'utf8')).profiles;const guests=JSON.parse(fs.readFileSync(guestPath,'utf8')).profiles;
  const guestKeys=new Set(guests.map(x=>x.route_case));const profiles=[...main.filter(x=>!guestKeys.has(x.route_case)),...guests];
  const byModule=new Map();for(const row of profiles){const name=row.module||'Unknown';const b=byModule.get(name)||{module:name,eligible_profiles:0,captured:0,query_counts:[],database_times_ms:[]};b.eligible_profiles++;if(row.profile_captured){b.captured++;if(Number.isFinite(row.query_count))b.query_counts.push(row.query_count);if(Number.isFinite(row.database_time_ms))b.database_times_ms.push(row.database_time_ms);}byModule.set(name,b);}
  const mean=a=>a.length?Number((a.reduce((s,v)=>s+v,0)/a.length).toFixed(2)):null;
  const moduleSummary=[...byModule.values()].map(x=>({...x,mean_query_count:mean(x.query_counts),mean_database_time_ms:mean(x.database_times_ms)})).sort((a,b)=>a.module.localeCompare(b.module));
  const result={generated_at:new Date().toISOString(),run_id:'sql-inventory-20261031184450',evidence_classification:'interim mixed-source diagnostics; source edits occurred during capture and this must not be used as final after evidence',
    baseline_case_count:166,baseline_non_gated_profile_target:163,additional_eligible_routes:['password.confirm'],profile_count:profiles.length,captured_count:profiles.filter(x=>x.profile_captured).length,
    source_fingerprint_start:'not-preserved-by-interrupted-run',source_fingerprint_end:audit.sourceFingerprint(),source_unchanged:false,
    database:{name:'erp_elec_new_test',host:'127.0.0.1',port:55461},profiler:'Laravel Debugbar CLI; APP_ENV=local; one separate instrumented GET per case; no browser baseline timing was run.',
    module_summary:moduleSummary,slowest_by_database_time:[...profiles].filter(x=>x.profile_captured).sort((a,b)=>(b.database_time_ms||0)-(a.database_time_ms||0)).slice(0,20),
    profiles};
  fs.writeFileSync(path.join(perf,'ERP_PAGE_SPEED_SQL_INVENTORY.json'),JSON.stringify(result,null,2)+'\n');
  process.stdout.write(JSON.stringify({status:'interim-sql-summary-written',profiles:profiles.length,captured:result.captured_count,output:path.join(perf,'ERP_PAGE_SPEED_SQL_INVENTORY.json'),classification:result.evidence_classification})+'\n');
}

if(require.main===module){
  if(process.argv.includes('--inventory-only')) inventoryOnly();
  else if(process.argv.includes('--profile-only'))profileOnly();
  else if(process.argv.includes('--profile-guests-only'))profileGuestsOnly();
  else if(process.argv.includes('--summarize-interim-sql'))summarizeInterimSql();
  else if(process.argv.includes('--self-check'))selfCheck();
  else if(process.argv.includes('--execute'))runAfter({freeze:argValue('--freeze-sha256'),resume:argValue('--resume-run')}).catch(e=>{process.stderr.write(`${e.stack||e}\n`);process.exitCode=1;});
  else if(process.argv.includes('--preflight'))runPreflight({freeze:argValue('--freeze-sha256')}).catch(e=>{process.stderr.write(`${e.stack||e}\n`);process.exitCode=1;});
  else { process.stderr.write('Use --inventory-only or, after the application source is frozen, --execute --freeze-sha256 <exact current fingerprint>; resume an interrupted run with --resume-run=after-<id>.\n');process.exitCode=2; }
}

module.exports={inventoryOnly,profileOnly,profileGuestsOnly,summarizeInterimSql,uniqueCases,comparisonFor,writeComparison,buildModuleMatrix,runAfter,runPreflight,interactionCatalog};
