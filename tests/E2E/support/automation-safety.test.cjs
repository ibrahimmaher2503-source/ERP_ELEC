'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const { test } = require('node:test');
const { runtimeEnvironment } = require('../bootstrap.cjs');

const root = path.resolve(__dirname, '../../..');
const privateRoot = path.join(root, '.tmp/e2e-automation');

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
