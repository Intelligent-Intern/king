import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const frontendRoot = path.resolve(__dirname, '..', '..');
const repoRoot = path.resolve(frontendRoot, '..', '..', '..');

function readRepo(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

function requireIncludes(haystack, needle, label) {
  assert.ok(haystack.includes(needle), label);
}

function requireMatch(haystack, pattern, label) {
  assert.match(haystack, pattern, label);
}

const sourceBranch = 'local/iam-e2e-deleted-ended-disabled-followup-proof-3';
assert.equal(sourceBranch, 'local/iam-e2e-deleted-ended-disabled-followup-proof-3');

const packageJson = JSON.parse(readRepo('demo/video-chat/frontend-vue/package.json'));
const staticGate = readRepo('demo/video-chat/scripts/iam-call-access-ci-gate.sh');
const runtimeProof = readRepo('demo/video-chat/backend-king-php/tests/call-access-deleted-ended-disabled-join-contract.php');
const terminalBrowserContract = readRepo('demo/video-chat/frontend-vue/tests/contract/call-access-terminal-browser-flows-contract.mjs');
const terminalStatesContract = readRepo('demo/video-chat/frontend-vue/tests/contract/call-access-terminal-states-contract.mjs');
const seedMatrixSpec = readRepo('demo/video-chat/frontend-vue/tests/e2e/call-access-seed-matrix.spec.js');
const seedHelper = readRepo('demo/video-chat/frontend-vue/tests/e2e/helpers/callAccessSeedMatrix.js');
const foundationContract = readRepo('demo/video-chat/frontend-vue/tests/contract/iam-call-access-e2e-foundation-contract.mjs');

requireIncludes(
  packageJson.scripts['test:contract:iam9-10-terminal-followup-denials'],
  'node tests/contract/iam9-10-terminal-followup-denials-contract.mjs',
  'package script must expose the focused IAM9-10 proof',
);
requireIncludes(
  staticGate,
  'node tests/contract/iam9-10-terminal-followup-denials-contract.mjs',
  'static IAM gate must run the focused IAM9-10 proof',
);

for (const state of ['ended', 'cancelled', 'deleted']) {
  requireMatch(
    runtimeProof,
    new RegExp(`foreach \\(\\['ended', 'cancelled', 'deleted'\\] as \\$state\\)[\\s\\S]*videochat_iam7_11_assert_terminal_case\\(\\$pdo, \\$databasePath, \\$tenantId, \\$adminUserId, \\$standardUserId, \\$state\\)`),
    `${state} terminal state must be covered by the runtime follow-up loop`,
  );
}

requireMatch(
  runtimeProof,
  /videochat_iam7_11_route\([\s\S]*'\/api\/call-access\/' \. \$openAccessId \. '\/session'[\s\S]*\$expectedOpenStatus = \$state === 'deleted' \? 404 : 409[\s\S]*\$issuerCalls === 0[\s\S]*terminal route must not call session issuer/,
  'terminal follow-up session creation must deny before issuing a session id',
);
requireMatch(
  runtimeProof,
  /videochat_fetch_call_access_session_binding\(\$pdo, "sess_iam7_11_\{\$state\}_personal"\) === null[\s\S]*videochat_fetch_call_access_session_binding\(\$pdo, "sess_iam7_11_\{\$state\}_open"\) === null/,
  'terminal follow-up must quarantine stale personal and open call-access bindings',
);
requireMatch(
  runtimeProof,
  /videochat_validate_call_access_session_binding\(\$pdo, "sess_iam7_11_\{\$state\}_personal", \$participantUserId\)[\s\S]*videochat_validate_call_access_session_binding\(\$pdo, "sess_iam7_11_\{\$state\}_open", \$openUserId\)/,
  'terminal follow-up must reject stale binding validation for non-deleted terminal calls',
);
requireMatch(
  runtimeProof,
  /videochat_user_can_direct_join_call\(\$pdo, \(string\) \$personalCall\['id'\], \$participantUserId, 'user', \$tenantId\)[\s\S]*guest-list direct join should fail/,
  'terminal follow-up must deny adjacent guest-list direct joins',
);
requireMatch(
  runtimeProof,
  /videochat_realtime_resolve_connection_rooms\([\s\S]*stale call-access session must not reconnect directly to terminal room/,
  'terminal follow-up must prevent stale call-access room reconnects',
);
requireMatch(
  runtimeProof,
  /!videochat_realtime_connection_can_bypass_admission_for_room\([\s\S]*cached owner context must not bypass terminal admission[\s\S]*!videochat_realtime_connection_can_join_call_scoped_room\([\s\S]*cached owner connection must not rejoin terminal room/,
  'terminal follow-up must deny cached realtime owner-context room bypasses',
);
requireMatch(
  runtimeProof,
  /active public access should resolve[\s\S]*active session binding should validate[\s\S]*active guest-list user should direct join[\s\S]*active allowed participant should resolve to call room/,
  'IAM9-10 proof must preserve adjacent active joins while denying terminal follow-ups',
);
requireMatch(
  runtimeProof,
  /UPDATE users SET status = 'disabled'[\s\S]*disabled user stale binding should fail[\s\S]*call_access_user_inactive[\s\S]*disabled user binding fetch should be quarantined/,
  'disabled-user follow-up sessions must be denied and quarantined',
);

for (const scenarioKey of [
  'direct_join_system_admin_alpha_ended_denied',
  'direct_join_alpha_owner_alpha_disabled_denied',
  'direct_join_alpha_owner_alpha_deleted_hidden',
]) {
  requireIncludes(terminalBrowserContract, scenarioKey, `browser terminal contract must include ${scenarioKey}`);
  requireIncludes(terminalStatesContract, scenarioKey, `terminal state contract must include ${scenarioKey}`);
}

requireMatch(
  seedMatrixSpec,
  /private_call_payload_forbidden[\s\S]*responses\.resolve\.payload\?\.result\?\.call\s*\?\?\s*null[\s\S]*responses\.call\.payload\?\.call\s*\?\?\s*null/,
  'terminal browser proof must keep resolve and call fetch payloads redacted',
);
requireMatch(
  seedHelper,
  /function callDirectAccessFailure\(call\)[\s\S]*status === 'deleted'[\s\S]*hidden:\s*true[\s\S]*!\['scheduled',\s*'active'\]\.includes\(status\)[\s\S]*reason:\s*'call_not_joinable_from_status'/,
  'seed helper must classify deleted as hidden and ended/disabled as non-joinable before role checks',
);
requireMatch(
  foundationContract,
  /terminalDirectJoinScenarioKeys[\s\S]*expected_call_status_value[\s\S]*must not masquerade as a normal permission denial/,
  'foundation proof must keep terminal denials distinct from normal permission denials',
);

console.log('[iam9-10-terminal-followup-denials-contract] PASS');
