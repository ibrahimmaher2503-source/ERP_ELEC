'use strict';

// Runs each maintained E2E test in its own runner pass. Playwright can stall on Windows after a
// multi-test worker finishes, so one pass per test keeps a stall from hiding the other results.
const fs = require('node:fs');
const path = require('node:path');
const { spawnSync } = require('node:child_process');

const root = path.resolve(__dirname, '../..');
const limit = Number(process.env.E2E_EACH_TIMEOUT_MS || 120_000);
const only = process.argv[2] ? new RegExp(process.argv[2]) : null;
const suites = [
  ['smoke', 'tests/E2E/specs/smoke.spec.cjs'], ['smoke', 'tests/E2E/specs/m08-draft-smoke.spec.cjs'],
  ['critical', 'tests/E2E/specs/critical.spec.cjs'], ['smoke', 'tests/E2E/specs/returns.spec.cjs'],
];

function titles(file) {
  const full = path.join(root, file);
  if (!fs.existsSync(full)) return [];
  const source = fs.readFileSync(full, 'utf8');
  const found = [...source.matchAll(/^\s*test\('([^']+)'/gm)].map(match => match[1]);
  if (source.includes('authorized module page renders: ${label}')) {
    for (const match of source.matchAll(/\['(M\d\d [^']+)', '\//g)) found.push(`authorized module page renders: ${match[1]}`);
  }

  return found;
}

const escape = text => text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
const results = [];
for (const [mode, file] of suites) {
  for (const title of titles(file)) {
    if (only && !only.test(title)) continue;
    const started = Date.now();
    const run = spawnSync(process.execPath, ['tests/E2E/automation.cjs', mode, '--grep', `${escape(title)}$`],
      { cwd: root, encoding: 'utf8', timeout: limit, killSignal: 'SIGKILL', windowsHide: true, maxBuffer: 8 * 1024 * 1024 });
    fs.rmSync(path.join(root, '.tmp/e2e-automation/runner.lock'), { recursive: true, force: true });
    const line = (run.stdout || '').trim().split('\n').pop();
    let report = null;
    try { report = JSON.parse(line); } catch { report = null; }
    const row = { title, status: report?.status || (run.error ? 'stalled' : 'no_report'), passed: report?.passed ?? 0, failed: report?.failed ?? 0,
      seconds: Math.round((Date.now() - started) / 1000), failure: report?.failures?.[0]?.message?.slice(0, 400) };
    results.push(row);
    process.stdout.write(`${row.status} ${row.seconds}s ${title}\n`);
  }
}
const summary = { total: results.length, passed: results.filter(row => row.status === 'passed').length, results };
fs.writeFileSync(path.join(root, '.tmp/e2e-automation/latest-each.json'), JSON.stringify(summary, null, 2));
process.stdout.write(JSON.stringify({ total: summary.total, passed: summary.passed }) + '\n');
process.exit(summary.total > 0 && summary.total === summary.passed ? 0 : 1);
