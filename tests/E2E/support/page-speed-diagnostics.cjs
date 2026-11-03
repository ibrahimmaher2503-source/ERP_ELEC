'use strict';

// Separate, untimed SQL diagnostic pass for the 20 slowest page cases.
const fs = require('node:fs');
const path = require('node:path');
const net = require('node:net');
const { spawn } = require('node:child_process');
const { chromium } = require('playwright');
const { measurePage, browserExecutable, waitForServer, paths } = require('../page-speed-audit.cjs');

const { root, workDir } = paths;
const bootstrap = JSON.parse(fs.readFileSync(path.join(workDir, 'bootstrap.json'), 'utf8'));
const progressPath = path.join(workDir, 'progress.json');
const outputPath = path.join(workDir, 'diagnostic-results.json');
const queryLog = path.join(bootstrap.logs, `queries-profile-final-${Date.now().toString(36)}.jsonl`);
const exclusions = /^(?:financial\.documents|tenders\.bid)$/;

function freePort() { return new Promise((resolve, reject) => { const s = net.createServer(); s.once('error', reject); s.listen(0, '127.0.0.1', () => { const p = s.address().port; s.close(() => resolve(p)); }); }); }
function median(a) { const v = a.filter(Number.isFinite).sort((x,y) => x-y); if (!v.length) return null; const m = v.length >> 1; return Number((v.length % 2 ? v[m] : (v[m-1]+v[m])/2).toFixed(2)); }

async function main() {
  if (bootstrap.dbName !== 'erp_elec_new_test' || bootstrap.dbPort !== 55461) throw new Error('Refusing diagnostics outside performance database.');
  const progress = JSON.parse(fs.readFileSync(progressPath, 'utf8'));
  const resumedPath = path.join(workDir, 'resumed-mobile-results.json');
  const resumed = fs.existsSync(resumedPath) ? JSON.parse(fs.readFileSync(resumedPath, 'utf8')) : null;
  const resumedSamples = resumed?.run_id === progress.run_id ? (resumed.results || []) : [];
  const samples = [...progress.results, ...resumedSamples];
  const groups = new Map();
  for (const row of samples) {
    if (!row.measured || row.temperature !== 'warm' || exclusions.test(row.route_name)) continue;
    const key = `${row.route_name}|${row.route_variant || 'default'}`;
    const group = groups.get(key) || { name: row.route_name, variant: row.route_variant || 'default', module: row.module, uri: row.uri, url: row.url, desktop: [], mobile: [] };
    group[row.viewport]?.push(row.ui_ready_ms); groups.set(key, group);
  }
  const top = [...groups.values()].map(group => ({ ...group, score: Math.max(median(group.desktop) || 0, median(group.mobile) || 0) }))
    .filter(group => group.desktop.length && group.mobile.length).sort((a,b) => b.score - a.score).slice(0, 20);
  const port = await freePort(), baseUrl = `http://127.0.0.1:${port}`;
  const env = { ...bootstrap.env, APP_URL: baseUrl, AUDIT_QUERY_LOG: queryLog };
  process.env.AUDIT_QUERY_LOG = queryLog;
  const log = fs.openSync(path.join(bootstrap.logs, `query-diagnostic-final-${progress.run_id}.log`), 'w');
  const server = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', '.', path.join(root, 'tests/E2E/support/performance-router.php')], { cwd: path.join(root, 'public'), env, stdio: ['ignore', log, log], windowsHide: true });
  let browser;
  const results = [];
  try {
    await waitForServer(baseUrl);
    browser = await chromium.launch({ headless: true, executablePath: browserExecutable() });
    const viewport = { name: 'desktop', width: 1440, height: 900 };
    for (const route of top) {
      const context = await browser.newContext({ baseURL: baseUrl, storageState: paths.storageState, locale: 'ar-EG', timezoneId: 'Africa/Cairo', viewport });
      const page = await context.newPage();
      const row = await measurePage(page, baseUrl, { name: route.name, variant: route.variant, route_variant: route.variant,
        module: route.module, uri: route.uri, url: route.url }, viewport, 'diagnostic', 1, true);
      results.push(row);
      if (!row.redirected_to_login) await context.storageState({ path: paths.storageState });
      await context.close();
      fs.writeFileSync(outputPath, JSON.stringify({ run_id: progress.run_id, updated_at: new Date().toISOString(), diagnostic_route_count: top.length, results }, null, 2) + '\n');
    }
    process.stdout.write(JSON.stringify({ status: 'complete', top_cases: top.length, profiled: results.length,
      profiles_captured: results.filter(row => row.query_profile).length, output: outputPath }) + '\n');
  } finally { if (browser) await browser.close().catch(() => {}); if (server.exitCode === null) server.kill(); try { fs.closeSync(log); } catch {} }
}
main().catch(error => { process.stderr.write(`${error.stack || error}\n`); process.exitCode = 1; });
