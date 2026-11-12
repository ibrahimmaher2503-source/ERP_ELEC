'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const { test } = require('node:test');
const { chromium } = require('playwright');
const { runtimeEnvironment } = require('../bootstrap.cjs');
const { actorContext } = require('./actor-context.cjs');
const { closeOwnedBrowserServer } = require('./owned-browser-cleanup.cjs');

const root = path.resolve(__dirname, '../../..');
const privateRoot = path.join(root, '.tmp/e2e-automation');

test('fresh actor session is reused without a login', async () => {
  const probe = { request: { get: async () => ({ status: () => 200 }) }, close: async () => {} };
  let contexts = 0;
  const browser = { newContext: async () => { contexts++; return probe; } };
  const run = ownedActorRun();
  const keys = ['E2E_BASE_URL', 'E2E_RUN_TOKEN'];
  const previous = Object.fromEntries(keys.map(key => [key, process.env[key]]));
  process.env.E2E_BASE_URL = run.baseUrl;
  process.env.E2E_RUN_TOKEN = run.runToken;
  try {
    const context = await actorContext(browser, run, 'reviewer');
    assert.equal(context, probe);
    assert.equal(contexts, 2);
  } finally {
    keys.forEach(key => previous[key] === undefined ? delete process.env[key] : process.env[key] = previous[key]);
    fs.rmSync(run.runtime, { recursive: true, force: true });
  }
});

test('expired actor session renews once with in-memory credentials', async () => {
  const statePath = path.join(privateRoot, 'safety-check-actor', 'actors', 'reviewer.json');
  fs.mkdirSync(path.dirname(statePath), { recursive: true });
  let contexts = 0;
  let savedState;
  const browser = { newContext: async options => {
    contexts++;
    if (contexts === 1) return { request: { get: async () => ({ status: () => 302 }) }, close: async () => {} };
    if (contexts === 2) return {
      newPage: async () => ({ setDefaultTimeout() {}, setDefaultNavigationTimeout() {}, goto: async () => {},
        locator: selector => ({ fill: async value => { if (selector.includes('email')) assert.equal(value, 'actor@example.test'); else assert.equal(value, 'test-password'); }, click: async () => {}, waitFor: async () => {} }),
        waitForURL: async () => {}, click: async () => {}, waitFor: async () => {} }),
      storageState: async ({ path: target }) => { savedState = target; }, close: async () => {},
    };
    return { renewed: true, options };
  } };
  const run = ownedActorRun(statePath);
  try {
    const context = await actorContext(browser, run, 'reviewer', { root, env: ownedActorEnv(run, { reviewer: { email: 'actor@example.test', password: 'test-password' } }) });
    assert.deepEqual(context, { renewed: true, options: { baseURL: 'http://127.0.0.1:9001', storageState: statePath, locale: 'ar-EG', timezoneId: 'Africa/Cairo' } });
    assert.equal(savedState, statePath);
    assert.equal(contexts, 3);
  } finally {
    fs.rmSync(path.join(privateRoot, 'safety-check-actor'), { recursive: true, force: true });
  }
});

test('timed out browser-server cleanup kills only that server process', async () => {
  const killed = [];
  const ownedProcess = { exitCode: null, killed: false, kill(signal) { killed.push({ pid: 43210, signal }); this.killed = true; } };
  await assert.rejects(closeOwnedBrowserServer({ process: () => ownedProcess, close: () => new Promise(() => {}) }, 5), /cleanup exceeded 5ms/);
  assert.deepEqual(killed, [{ pid: 43210, signal: 'SIGKILL' }]);
});

test('closing an owned Chromium server disconnects its client without a leak', async () => {
  const executablePath = process.env.PLAYWRIGHT_EXECUTABLE_PATH || 'C:/Users/N/AppData/Local/ms-playwright/chromium-1234/chrome-win64/chrome.exe';
  if (!fs.existsSync(executablePath)) return;
  const server = await chromium.launchServer({ headless: true, executablePath, timeout: 15_000 });
  const child = server.process();
  try {
    const browser = await chromium.connect(server.wsEndpoint(), { timeout: 10_000 });
    await browser.close();
    await closeOwnedBrowserServer(server, 2_000);
    assert.notEqual(child.exitCode, null);
  } finally {
    if (child.exitCode === null && !child.killed) child.kill('SIGKILL');
  }
});

function ownedActorRun(statePath = path.join(privateRoot, 'safety-check-actor', 'actors', 'reviewer.json')) {
  fs.mkdirSync(path.dirname(statePath), { recursive: true });
  fs.writeFileSync(statePath, '{}');
  return { dbName: 'erp_elec_new_test', dbPort: 55460, baseUrl: 'http://127.0.0.1:9001', runToken: 'owned-token',
    runtime: path.join(privateRoot, 'safety-check-actor'), actor_states: { reviewer: statePath } };
}

function ownedActorEnv(run, credentials = {}) {
  return { E2E_BASE_URL: run.baseUrl, E2E_RUN_TOKEN: run.runToken, E2E_ACTOR_CREDENTIALS: JSON.stringify(credentials) };
}

test('bootstrap environment is an allowlist and excludes unrelated secrets', () => {
  const key = 'E2E_AUTOMATION_SENTINEL_SECRET';
  const previous = process.env[key];
  process.env[key] = 'secret-value-must-not-leave-parent';
  try {
    const result = runtimeEnvironment();
    assert.equal(Object.hasOwn(result, key), false);
    assert.equal(Object.values(result).includes('secret-value-must-not-leave-parent'), false);
    assert.ok(result.PATH);
  } finally {
    if (previous === undefined) delete process.env[key];
    else process.env[key] = previous;
  }
});

test('bootstrap rejects the shared 5443/development target before setup writes', () => {
  const marker = path.join(privateRoot, 'postgres', 'codex-owned.json');
  const before = fs.existsSync(marker) ? fs.statSync(marker).mtimeMs : null;
  const env = { ...process.env, E2E_DB_PORT: '5443', E2E_DB_DATABASE: 'erp_elec_new', E2E_BASE_URL: 'http://127.0.0.1:8000',
    E2E_AUTOMATION_SENTINEL_SECRET: 'secret-value-must-not-leave-parent' };
  const result = spawnSync(process.execPath, [path.join(root, 'tests/E2E/bootstrap.cjs')], { cwd: root, env, encoding: 'utf8', windowsHide: true });
  assert.notEqual(result.status, 0);
  assert.match(result.stderr, /before any setup writes/);
  assert.equal(`${result.stdout}\n${result.stderr}`.includes('secret-value-must-not-leave-parent'), false);
  const after = fs.existsSync(marker) ? fs.statSync(marker).mtimeMs : null;
  assert.equal(after, before);
});

test('Playwright config rejects a loopback development URL without the owned bootstrap identity', () => {
  fs.mkdirSync(privateRoot, { recursive: true });
  const probeDir = fs.mkdtempSync(path.join(privateRoot, 'safety-check-'));
  try {
    const bootstrapFile = path.join(probeDir, 'bootstrap.json');
    fs.writeFileSync(bootstrapFile, JSON.stringify({ runToken: 'selfcheck-token', baseUrl: 'http://127.0.0.1:9001', dbPort: 55460, dbName: 'erp_elec_new_test' }));
    const playwrightCli = path.join(path.dirname(require.resolve('playwright/package.json')), 'cli.js');
    const result = spawnSync(process.execPath, [playwrightCli, 'test', '--config=playwright.config.cjs', '--list'], {
      cwd: root, encoding: 'utf8', windowsHide: true,
      env: { ...process.env, E2E_BASE_URL: 'http://127.0.0.1:8000', E2E_BOOTSTRAP_FILE: bootstrapFile, E2E_RUN_TOKEN: 'selfcheck-token' },
    });
    assert.notEqual(result.status, 0);
    assert.match(`${result.stdout}\n${result.stderr}`, /does not match the owned bootstrap identity/);
  } finally {
    const resolvedProbe = path.resolve(probeDir);
    if (!resolvedProbe.startsWith(path.resolve(privateRoot) + path.sep)) throw new Error('Refusing safety self-check cleanup outside private E2E storage.');
    fs.unlinkSync(path.join(resolvedProbe, 'bootstrap.json'));
    fs.rmdirSync(resolvedProbe);
  }
});
