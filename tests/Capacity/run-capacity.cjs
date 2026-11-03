'use strict';

// Manifest-driven target harness. It makes HTTP requests only; fixtures, target
// provisioning, authorization and post-run business-integrity checks stay external.
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');

const COHORTS = [5, 20, 50, 100];
const REQUIRED_CATEGORIES = ['read', 'write', 'report', 'import', 'upload', 'queue'];

function fail(message) { throw new Error(message); }
function envValue(name) {
  const value = process.env[name];
  if (!name || !value) fail(`Required secret environment variable [${name || 'unset'}] is missing.`);
  return value;
}
function assertionsPass(json, assertions) {
  return assertions.every(({ pointer, equals }) => {
    const parts = pointer.replace(/^\//, '').split('/').map(part => part.replaceAll('~1', '/').replaceAll('~0', '~'));
    let value = json;
    for (const part of parts) value = value == null ? undefined : value[part];
    return value === equals;
  });
}
function percentile(values, p) {
  if (!values.length) return null;
  const sorted = [...values].sort((a, b) => a - b);
  return Number(sorted[Math.ceil(p * sorted.length) - 1].toFixed(2));
}
function originOf(url) { return new URL(url).origin; }
function isLoopback(host) {
  const name = host.toLowerCase();
  return name === 'localhost' || name.endsWith('.localhost') || name === '::1'
    || /^127\./.test(name) || name === '0.0.0.0' || name === '::';
}
function readManifest(file) {
  const manifest = JSON.parse(fs.readFileSync(file, 'utf8'));
  const base = new URL(manifest.target?.base_url || '');
  if (manifest.target?.environment !== 'staging' || base.protocol !== 'https:' || isLoopback(base.hostname)
      || manifest.target?.allowed_origin !== base.origin) {
    fail('Refusing target: capacity runs require an exact HTTPS staging origin; local and PHP built-in-server targets are refused.');
  }
  const db = manifest.target?.database;
  if (!db || !['database', 'username', 'port', 'data_directory', 'ownership_marker_sha256'].every(key => db[key] !== undefined && db[key] !== '')
      || !/^[a-f0-9]{64}$/i.test(db.ownership_marker_sha256)) fail('Target database identity and ownership-marker SHA-256 are required.');
  const actors = manifest.actors;
  if (!Array.isArray(actors) || actors.length < 100 || new Set(actors.map(actor => actor.id)).size !== actors.length
      || actors.some(actor => !actor.id || !actor.cookie_env)) fail('Manifest must identify 100 distinct synthetic actors and their cookie environment-variable names.');
  const operations = manifest.operations;
  if (!Array.isArray(operations) || operations.length < 6) fail('An explicit operation manifest is required.');
  for (const category of REQUIRED_CATEGORIES) {
    if (!operations.some(operation => operation.category === category)) fail(`Missing configured [${category}] workload operation.`);
  }
  const allow = new Set((manifest.target.allowlist || []).map(item => `${item.method} ${item.path}`));
  const ids = new Set();
  for (const operation of operations) {
    if (!operation.id || ids.has(operation.id)) fail('Operation IDs must be present and unique.');
    ids.add(operation.id);
    if (!REQUIRED_CATEGORIES.includes(operation.category) || !['GET', 'POST', 'PUT', 'PATCH', 'DELETE'].includes(operation.method)
        || !operation.path?.startsWith('/') || operation.path.startsWith('//') || !allow.has(`${operation.method} ${operation.path}`)) {
      fail(`Operation [${operation.id}] is outside the exact target method/path allowlist.`);
    }
    if (!Number.isInteger(operation.expected_status) || operation.expected_status < 200 || operation.expected_status > 399
        || !Array.isArray(operation.assertions) || operation.assertions.length === 0
        || operation.assertions.some(assertion => typeof assertion.pointer !== 'string' || !('equals' in assertion))) {
      fail(`Operation [${operation.id}] needs an expected status and at least one semantic JSON assertion; status alone cannot pass.`);
    }
    if (operation.method !== 'GET' && !operation.fixture_file) fail(`Write operation [${operation.id}] must use an explicit synthetic fixture_file.`);
  }
  const preflight = manifest.target.preflight;
  if (!preflight?.path?.startsWith('/') || !preflight.assertions?.length
      || !allow.has(`${preflight.method || 'GET'} ${preflight.path}`)) fail('A live identity preflight must be present in the explicit method/path allowlist.');
  for (const required of ['database', 'username', 'port', 'data_directory', 'ownership_marker_sha256']) {
    if (!preflight.assertions.some(assertion => assertion.pointer === `/${required}` && assertion.equals === db[required])) {
      fail(`Live preflight must match the exact database ownership receipt field [${required}].`);
    }
  }
  const stack = String(manifest.target.server_stack || '').toLowerCase();
  if (!stack || stack.includes('php -s') || stack.includes('built-in')) fail('Target must declare and verify a non-built-in web stack.');
  if (!preflight.assertions.some(assertion => assertion.pointer === '/server_stack' && assertion.equals === manifest.target.server_stack)) {
    fail('Live preflight must verify the exact non-built-in server stack.');
  }
  return { manifest, manifestPath: path.resolve(file), base };
}
function resolveFixture(manifestPath, relative) {
  const fixtureRoot = path.resolve(path.dirname(manifestPath), 'fixtures');
  const target = path.resolve(fixtureRoot, relative || '');
  if (!target.startsWith(fixtureRoot + path.sep) || !fs.existsSync(target) || !fs.statSync(target).isFile()) {
    fail('Fixture files must be existing files below the manifest fixtures directory.');
  }
  return target;
}
async function request(base, actor, operation, manifestPath, timeoutMs) {
  const headers = { Accept: 'application/json', Cookie: envValue(actor.cookie_env) };
  if (actor.csrf_env) headers['X-CSRF-TOKEN'] = envValue(actor.csrf_env);
  const options = { method: operation.method, headers, signal: AbortSignal.timeout(timeoutMs), redirect: 'manual' };
  if (operation.fixture_file) {
    const file = resolveFixture(manifestPath, operation.fixture_file);
    const bytes = fs.readFileSync(file);
    if (operation.upload_field) {
      const form = new FormData();
      form.append(operation.upload_field, new Blob([bytes], { type: operation.content_type || 'application/octet-stream' }), path.basename(file));
      options.body = form;
    } else {
      options.headers['Content-Type'] = operation.content_type || 'application/octet-stream';
      options.body = bytes;
    }
  }
  const started = performance.now();
  try {
    const response = await fetch(new URL(operation.path, base), options);
    const text = await response.text();
    let json = null;
    try { json = JSON.parse(text); } catch {}
    const assertionPass = json !== null && assertionsPass(json, operation.assertions);
    const ok = response.status === operation.expected_status && assertionPass;
    return { operation: operation.id, category: operation.category, elapsed_ms: performance.now() - started,
      status: response.status, ok, assertion_pass: assertionPass, ...(ok ? {} : { raw_failure_body: text }) };
  } catch (error) {
    return { operation: operation.id, category: operation.category, elapsed_ms: performance.now() - started,
      status: null, ok: false, timeout: error?.name === 'TimeoutError' || error?.name === 'AbortError',
      error: `${error?.name || 'Error'}: ${error?.message || 'request failed'}` };
  }
}
async function livePreflight(manifest, base) {
  const actor = manifest.actors[0];
  const result = await request(base, actor, { id: 'target-identity-preflight', method: manifest.target.preflight.method || 'GET',
    path: manifest.target.preflight.path, expected_status: manifest.target.preflight.expected_status || 200,
    assertions: manifest.target.preflight.assertions }, '', 10_000);
  if (!result.ok) fail(`Live database identity preflight failed (status ${result.status ?? 'network-error'}).`);
}
async function cohort(manifest, manifestPath, base, users, runId) {
  let next = 0;
  const results = [];
  const started = performance.now();
  const timeoutMs = manifest.timeout_ms || 30_000;
  const perUser = manifest.requests_per_user || 6;
  await Promise.all(Array.from({ length: users }, async (_, userIndex) => {
    const actor = manifest.actors[userIndex];
    for (let requestIndex = 0; requestIndex < perUser; requestIndex++) {
      const op = manifest.operations[next++ % manifest.operations.length];
      results.push(await request(base, actor, op, manifestPath, timeoutMs));
    }
  }));
  const elapsedMs = performance.now() - started;
  const durations = results.map(row => row.elapsed_ms);
  const errors = results.filter(row => !row.ok).length;
  const timeouts = results.filter(row => row.timeout).length;
  const output = { run_id: runId, virtual_users: users, request_count: results.length,
    p50_ms: percentile(durations, .5), p95_ms: percentile(durations, .95), p99_ms: percentile(durations, .99),
    error_count: errors, error_rate: results.length ? Number((errors / results.length).toFixed(4)) : 0,
    timeout_count: timeouts, timeout_rate: results.length ? Number((timeouts / results.length).toFixed(4)) : 0,
    elapsed_seconds: Number((elapsedMs / 1000).toFixed(2)), throughput_attempts_per_second: Number((results.length / (elapsedMs / 1000)).toFixed(2)),
    host_metrics: { cpu: 'NOT TESTED', memory: 'NOT TESTED', database_waits: 'NOT TESTED', queue_lag: 'NOT TESTED' },
    raw_results: results };
  return output;
}
function selfCheck() {
  if (percentile([1, 2, 3, 4, 5], .95) !== 5 || percentile([1, 2, 3, 4], .5) !== 2) fail('Percentile self-check failed.');
  if (!assertionsPass({ a: { b: 4 } }, [{ pointer: '/a/b', equals: 4 }]) || assertionsPass({ a: 3 }, [{ pointer: '/a', equals: 4 }])) fail('Semantic assertion self-check failed.');
  if (!isLoopback('127.0.0.1') || !isLoopback('work.localhost') || isLoopback('staging.example.invalid')) fail('Loopback host self-check failed.');
  process.stdout.write('PASS capacity harness self-check (percentiles, semantic assertions, loopback refusal)\n');
}
async function main() {
  if (process.argv.includes('--self-check')) return selfCheck();
  const file = process.argv[2];
  const users = Number(process.argv[3]);
  if (!file || !COHORTS.includes(users)) fail('Usage: node tests/Capacity/run-capacity.cjs <explicit-staging-manifest.json> <5|20|50|100>');
  const { manifest, manifestPath, base } = readManifest(file);
  const runId = crypto.randomUUID();
  await livePreflight(manifest, base);
  const result = await cohort(manifest, manifestPath, base, users, runId);
  const directory = path.resolve('.tmp/capacity', runId);
  fs.mkdirSync(directory, { recursive: true, mode: 0o700 });
  fs.writeFileSync(path.join(directory, `cohort-${users}.json`), JSON.stringify(result, null, 2) + '\n', { mode: 0o600, flag: 'wx' });
  process.stdout.write(JSON.stringify({ status: result.error_count ? 'FAIL' : 'PASS', run_id: runId, virtual_users: users,
    requests: result.request_count, p50_ms: result.p50_ms, p95_ms: result.p95_ms, p99_ms: result.p99_ms,
    errors: result.error_count, timeouts: result.timeout_count, throughput_attempts_per_second: result.throughput_attempts_per_second,
    raw_receipt: path.join(directory, `cohort-${users}.json`), host_metrics: result.host_metrics }) + '\n');
  if (result.error_count) process.exitCode = 1;
}
main().catch(error => { process.stderr.write(`FAIL ${error.message}\n`); process.exitCode = 1; });
