import type { E2EConfig } from 'e2e';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { web } from '@e2e-dev/web';
import { chatgpt } from 'e2e/oauth/chatgpt';

const root = process.cwd();
const bootstrap = JSON.parse(readFileSync(resolve(root, '.tmp/e2e-automation/bootstrap.json'), 'utf8'));
const markerPath = resolve(root, '.tmp/e2e-automation/postgres/codex-owned.json');
const marker = JSON.parse(readFileSync(markerPath, 'utf8'));
const appUrl = process.env.APP_URL;

if (bootstrap.dbPort !== 55460 || bootstrap.dbName !== 'erp_elec_new_test'
  || resolve(bootstrap.clusterMarker) !== markerPath
  || bootstrap.env?.DB_PORT !== '55460' || bootstrap.env?.DB_DATABASE !== 'erp_elec_new_test'
  || marker.port !== 55460 || marker.token !== bootstrap.clusterToken || marker.repo !== root) {
  throw new Error('TesterArmy requires a valid marker owned by the isolated PostgreSQL 55460 test run.');
}
if (!appUrl || appUrl !== bootstrap.baseUrl) {
  throw new Error('Set APP_URL to the exact baseUrl in .tmp/e2e-automation/bootstrap.json; no fallback server is allowed.');
}

export default {
  targets: [{
    engine: web({ locale: 'ar-EG', timezoneId: 'Africa/Cairo' }),
    app: { url: appUrl },
  }],
  agents: {
    default: {
      model: chatgpt('gpt-6-luna'),
      system: 'You are a careful ERP QA agent. Use only the visible synthetic test data. Never invent business outcomes or claim a draft was submitted or posted unless the screen proves it.',
    },
  },
  credentials: {
    smoke: {
      username: process.env.E2E_USER_SMOKE_USERNAME ?? '',
      password: () => process.env.E2E_USER_SMOKE_PASSWORD ?? '',
    },
  },
  output: '.tmp/testerarmy-e2e',
  trace: 'retain-on-failure',
} satisfies E2EConfig;
