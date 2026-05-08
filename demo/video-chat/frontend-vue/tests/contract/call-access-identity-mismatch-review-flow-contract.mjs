import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const frontendRoot = path.resolve(__dirname, '../..');
const repoRoot = path.resolve(frontendRoot, '..', '..', '..');

function read(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

const joinView = read('demo/video-chat/frontend-vue/src/domain/calls/access/JoinView.vue');
const panel = read('demo/video-chat/frontend-vue/src/domain/calls/access/JoinStrongMismatchPanel.vue');
const flow = read('demo/video-chat/frontend-vue/src/domain/calls/access/joinStrongMismatchFlow.js');
const sessionClient = read('demo/video-chat/frontend-vue/src/domain/calls/access/callAccessSession.ts');
const identityHelper = read('demo/video-chat/backend-king-php/domain/calls/call_access_identity.php');
const sessionDomain = read('demo/video-chat/backend-king-php/domain/calls/call_access_session.php');
const reviewDomain = read('demo/video-chat/backend-king-php/domain/calls/call_access_review.php');
const backendContract = read('demo/video-chat/backend-king-php/tests/call-access-identity-mismatch-review-flow-contract.php');
const packageJson = JSON.parse(read('demo/video-chat/frontend-vue/package.json'));

assert.match(
  joinView,
  /headers:\s*callAccessJoinHeaders\(sessionState\)/,
  'JoinView must resolve personalized links with the active bearer so the backend can detect identity mismatch before showing private call data',
);
assert.match(
  joinView,
  /isStrongPersonalizedMismatchPayload\(errorPayload\)[\s\S]*strongMismatchFlow\.show\(\)/,
  'JoinView must turn safe strong-mismatch 403 responses into the host-name warning flow',
);
assert.match(
  joinView,
  /JoinStrongMismatchPanel[\s\S]*@verify-host="strongMismatchFlow\.verifyHost"[\s\S]*@continue-without-update="strongMismatchFlow\.continueWithoutUpdate"[\s\S]*@request-update="strongMismatchFlow\.requestUpdate"/,
  'JoinView must wire host verification, decline-update, and manual account-update actions',
);

assert.match(
  flow,
  /details\.mismatch === 'strong_personalized_link'[\s\S]*fields\.host_name === 'not_verified'/,
  'frontend flow must recognize only the explicit strong personalized-link mismatch contract',
);
assert.match(
  flow,
  /hostName[\s\S]*verifiedContext:\s*state\.verifiedAccessContext/,
  'host verification must submit the entered host name with the stable verified account context',
);
assert.match(
  flow,
  /requestCallAccessAccountUpdateConfirmation[\s\S]*display_name:\s*displayName/,
  'account update request must use manually re-entered display data only',
);
assert.doesNotMatch(
  panel,
  /target_user|participant_email|link target|foreign/i,
  'warning panel must not render link-target identity fields',
);

assert.match(
  sessionClient,
  /body\.host_name\s*=\s*hostName/,
  'call-access session client must send host_name only during explicit host verification',
);
assert.match(
  sessionClient,
  /requestCallAccessAccountUpdateConfirmation[\s\S]*account-update-confirmation/,
  'call-access client must expose authenticated account-update confirmation requests',
);

assert.match(
  identityHelper,
  /function videochat_call_access_identity_mismatch[\s\S]*first_name_differs[\s\S]*last_name_differs/s,
  'backend identity helper must classify first-name, last-name, and full-name strong mismatches',
);
assert.match(
  identityHelper,
  /state' => 'no_mismatch'[\s\S]*state' => \$strong \? 'strong_mismatch' : 'light_mismatch'/,
  'backend identity helper must distinguish no, light, and strong mismatch states',
);
assert.match(
  identityHelper,
  /videochat_call_access_public_join_identity_result[\s\S]*videochat_call_access_sanitize_authenticated_personalized_payload/,
  'public join resolution must sanitize non-strong authenticated mismatch payloads',
);
assert.match(
  sessionDomain,
  /videochat_call_access_host_name_matches_call_owner[\s\S]*correct_host_name[\s\S]*videochat_call_access_bind_authenticated_personalized_user/s,
  'session issuance must accept correct host-name verification by binding the current account, not the link-target account',
);
assert.match(
  reviewDomain,
  /call_access_host_name_verified[\s\S]*call_access_host_name_rejected/s,
  'host-name verification attempts must be audit logged for success and failure',
);

for (const needle of [
  'trimmed matching names should be no mismatch',
  'middle-name drift should be light mismatch',
  'first name mismatch should be strong',
  'last name mismatch should be strong',
  'strong mismatch join preview should require warning flow',
  'wrong host should not issue session',
  'correct host should issue session for current account',
  'successful host verification should be audit-logged',
]) {
  assert.ok(backendContract.includes(needle), `backend contract must prove: ${needle}`);
}

assert.match(
  packageJson.scripts['test:contract:iam-call-access'],
  /call-access-identity-mismatch-review-flow-contract\.mjs/,
  'IAM call-access contract script must include the identity mismatch review-flow contract',
);

console.log('[call-access-identity-mismatch-review-flow-contract] PASS');
