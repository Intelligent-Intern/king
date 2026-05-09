import { spawnSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const frontendRoot = path.resolve(__dirname, '../..');

export const CALL_ACCESS_E2E_SPECS = Object.freeze([
  'tests/e2e/call-access-join.spec.js',
  'tests/e2e/call-access-strong-mismatch-host-verification.spec.js',
  'tests/e2e/call-access-privacy-foreign-data.spec.js',
  'tests/e2e/call-access-seed-matrix.spec.js',
  'tests/e2e/call-access-authorized-rejoin.spec.js',
  'tests/e2e/iam-lobby-admission-main.spec.js',
  'tests/e2e/call-access-temp-guest-list-direct-join.spec.js',
  'tests/e2e/call-access-core-org-session-journey.spec.js',
  'tests/e2e/call-access-duplicate-review-email.spec.js',
  'tests/e2e/call-access-duplicate-race.spec.js',
  'tests/e2e/call-access-duplicate-logout-login-switch.spec.js',
  'tests/e2e/call-access-parallel-account-tabs.spec.js',
  'tests/e2e/call-access-duplicate-link-device-browser.spec.js',
  'tests/e2e/call-access-cross-org-foreign-join.spec.js',
  'tests/e2e/call-access-owner-absence-browser.spec.js',
  'tests/e2e/call-access-main-journey-smoke.spec.js',
  'tests/e2e/call-access-owner-transfer-main-journeys.spec.js',
  'tests/e2e/call-access-invite-reschedule-delete-end-main-journeys.spec.js',
  'tests/e2e/call-access-security-manipulation.spec.js',
  'tests/e2e/call-access-anonymous-disabled-link.spec.js',
  'tests/e2e/call-access-invite-invalidation.spec.js',
  'tests/e2e/call-access-rejoin-kick-membership.spec.js',
]);

export const CALL_ACCESS_E2E_DEFAULT_ARGS = Object.freeze(['--workers=1']);

export function callAccessE2eCommandText(extraArgs = []) {
  return [
    'playwright',
    'test',
    ...CALL_ACCESS_E2E_SPECS,
    ...CALL_ACCESS_E2E_DEFAULT_ARGS,
    ...extraArgs,
  ].join(' ');
}

export function runCallAccessE2eSuite(extraArgs = process.argv.slice(2)) {
  const result = spawnSync(
    process.platform === 'win32' ? 'playwright.cmd' : 'playwright',
    ['test', ...CALL_ACCESS_E2E_SPECS, ...CALL_ACCESS_E2E_DEFAULT_ARGS, ...extraArgs],
    {
      cwd: frontendRoot,
      env: process.env,
      shell: process.platform === 'win32',
      stdio: 'inherit',
    },
  );

  if (result.error) {
    throw result.error;
  }
  if (result.signal) {
    process.stderr.write(`[call-access-e2e-suite] Playwright terminated by ${result.signal}\n`);
    process.exit(1);
  }
  process.exit(result.status ?? 1);
}

const invokedPath = process.argv[1] ? pathToFileURL(path.resolve(process.argv[1])).href : '';
if (import.meta.url === invokedPath) {
  runCallAccessE2eSuite();
}
