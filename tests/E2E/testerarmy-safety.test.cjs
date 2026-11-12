'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');
const { validateBootstrapIdentity } = require('./testerarmy.cjs');

const root = path.resolve(__dirname, '../..');
const markerPath = path.join(root, '.tmp/e2e-automation/postgres/codex-owned.json');
const marker = { kind: 'codex-e2e-postgres', repo: root, port: 55460, token: 'owned-token' };
const bootstrap = { dbName: 'erp_elec_new_test', dbPort: 55460, clusterMarker: markerPath,
  clusterToken: 'owned-token', env: { DB_PORT: '55460', DB_DATABASE: 'erp_elec_new_test' } };

test('TesterArmy database guard accepts only its marker, token and isolated database', () => {
  assert.doesNotThrow(() => validateBootstrapIdentity(bootstrap, marker, root, markerPath));
  assert.throws(() => validateBootstrapIdentity({ ...bootstrap, dbPort: 5443 }, marker, root, markerPath));
  assert.throws(() => validateBootstrapIdentity(bootstrap, { ...marker, token: 'other-token' }, root, markerPath));
  assert.throws(() => validateBootstrapIdentity(bootstrap, marker, root, path.join(root, 'other-marker.json')));
});
