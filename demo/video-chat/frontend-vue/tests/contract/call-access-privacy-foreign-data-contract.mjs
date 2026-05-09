import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { iamCallAccessContractSuiteText } from './helpers/iamCallAccessSuiteCoverage.mjs';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const frontendRoot = path.resolve(__dirname, '..', '..');
const repoRoot = path.resolve(frontendRoot, '..', '..', '..');

function read(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

const e2eSpec = read('demo/video-chat/frontend-vue/tests/e2e/call-access-privacy-foreign-data.spec.js');
const backendPrivacy = read('demo/video-chat/backend-king-php/tests/call-access-privacy-contract.php');
const backendStrongMismatch = read('demo/video-chat/backend-king-php/tests/call-access-strong-mismatch-privacy-contract.php');
const auditPrivacy = read('demo/video-chat/backend-king-php/tests/audit-call-access-privacy-minimization-contract.php');
const ciGate = read('demo/video-chat/scripts/iam-call-access-ci-gate.sh');

for (const id of [
  'e2e_privacy_001_foreign_link_data_not_rendered',
  'e2e_privacy_002_foreign_link_data_not_in_api_response',
  'e2e_privacy_003_invalid_link_no_personal_data_leak',
  'e2e_privacy_004_wrong_host_name_no_personal_data_leak',
  'e2e_privacy_005_browser_network_response_no_foreign_data',
]) {
  assert.ok(e2eSpec.includes(id), `privacy E2E must include ${id}`);
}

assert.match(
  e2eSpec,
  /joinResponse\.text\(\)[\s\S]*expectNoForeignData\(joinBody, foreignNeedles, 'invalid-link API response'\)/,
  'invalid-link E2E must inspect the browser network response body for foreign data',
);
assert.match(
  e2eSpec,
  /expectNoForeignData\(await joinDialog\.innerText\(\), foreignNeedles, 'invalid-link dialog'\)[\s\S]*toHaveCount\(0\)/,
  'invalid-link E2E must prove foreign data is not rendered and no join action is shown',
);
assert.match(
  e2eSpec,
  /expectNoForeignData\(joinBody, foreignNeedles, 'strong-mismatch join API response'\)/,
  'strong-mismatch E2E must prove the join API response has no foreign link data',
);
assert.match(
  e2eSpec,
  /expectNoForeignData\(sessionResponseBody, foreignNeedles, 'strong-mismatch browser network response'\)/,
  'strong-mismatch E2E must prove the session network response has no foreign data',
);
assert.match(
  e2eSpec,
  /sessionBody\)\.toEqual\(\{\s*verified_user_id:\s*wrongAccount\.userId,\s*verified_session_id:\s*wrongAccount\.sessionId,\s*\}\)/,
  'strong-mismatch E2E must send only verified current-session identity to session issuance',
);
assert.match(
  e2eSpec,
  /storedSession\.sessionToken\)\.not\.toBe\('sess_foreign_denied_should_not_bind'\)/,
  'strong-mismatch E2E must prove denied foreign sessions are not adopted',
);
assert.match(
  e2eSpec,
  /expect\(page\.url\(\)\)\.not\.toContain\('\/workspace\/call'\)/,
  'privacy E2E must prove denied privacy paths do not navigate into the workspace',
);

assert.match(
  backendPrivacy,
  /guessedJoinResponse[\s\S]*videochat_call_access_privacy_assert_body_has_no_needles\(\$guessedJoinResponse, \$secretNeedles, 'guessed join response'\)/,
  'backend privacy contract must cover guessed link API responses',
);
assert.match(
  backendPrivacy,
  /wrongUserResponse[\s\S]*videochat_call_access_privacy_assert_body_has_no_needles\(\$wrongUserResponse, \$secretNeedles, 'wrong-user access response'\)/,
  'backend privacy contract must cover wrong-user API responses',
);
assert.match(
  backendStrongMismatch,
  /wrongHostSession[\s\S]*videochat_call_access_strong_mismatch_privacy_assert_no_needles\(\$wrongHostSession, \$secretNeedles, 'wrong-host session response'\)/,
  'backend strong-mismatch contract must cover wrong-host no-leak responses',
);
assert.match(
  backendStrongMismatch,
  /sess_strong_mismatch_wrong_host_should_not_issue[\s\S]*\$wrongHostRows === 0/s,
  'backend strong-mismatch contract must prove wrong-host denials do not persist sessions',
);

assert.ok(
  auditPrivacy.includes('e2e_privacy_006_audit_logs_minimize_sensitive_data'),
  'audit privacy minimization contract must map to the privacy audit checkbox',
);
assert.match(
  auditPrivacy,
  /videochat_audit_record_call_access_strong_mismatch[\s\S]*host_name_logged[\s\S]*foreign_account_data_logged[\s\S]*raw_link_identifier_logged[\s\S]*raw_credential_identifier_logged/s,
  'audit minimization contract must run the real strong-mismatch audit helper and assert omission flags',
);
assert.match(
  auditPrivacy,
  /videochat_audit_fingerprint\(\$accessId\)[\s\S]*videochat_audit_fingerprint\(\$sessionId\)/,
  'audit minimization contract must retain fingerprints instead of raw identifiers',
);

for (const contract of [
  'tests/contract/call-access-privacy-foreign-data-contract.mjs',
  'tests/audit-call-access-privacy-minimization-contract.sh',
]) {
  assert.ok(iamCallAccessContractSuiteText.includes(contract), `package IAM contract script must include ${contract}`);
  assert.ok(ciGate.includes(contract), `IAM CI gate must include ${contract}`);
}

process.stdout.write('[call-access-privacy-foreign-data-contract] PASS\n');
