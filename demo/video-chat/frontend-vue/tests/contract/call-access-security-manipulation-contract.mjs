import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import {
  callAccessE2eSuiteText,
  iamCallAccessContractSuiteText,
} from './helpers/iamCallAccessSuiteCoverage.mjs';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const frontendRoot = path.resolve(__dirname, '../..');
const videoChatRoot = path.resolve(frontendRoot, '..');
const repoRoot = path.resolve(videoChatRoot, '../..');

function read(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

const ciGate = read('demo/video-chat/scripts/iam-call-access-ci-gate.sh');
const routeModule = read('demo/video-chat/backend-king-php/http/module_calls_access.php');
const callsModule = read('demo/video-chat/backend-king-php/http/module_calls.php');
const backendContract = read('demo/video-chat/backend-king-php/tests/call-access-security-manipulation-contract.php');
const e2eSpec = read('demo/video-chat/frontend-vue/tests/e2e/call-access-security-manipulation.spec.js');

assert.match(
  iamCallAccessContractSuiteText,
  /call-access-security-manipulation-contract\.mjs/,
  'IAM call-access contract script must include the security manipulation static contract',
);
assert.match(
  iamCallAccessContractSuiteText,
  /call-access-security-manipulation-contract\.sh/,
  'IAM call-access contract script must include the backend security manipulation contract',
);
assert.match(
  callAccessE2eSuiteText,
  /call-access-security-manipulation\.spec\.js/,
  'call-access Playwright suite must include the focused security manipulation E2E',
);
assert.match(
  ciGate,
  /call-access-security-manipulation-contract\.mjs/,
  'IAM CI static gate must run the security manipulation static contract',
);
assert.match(
  ciGate,
  /call-access-security-manipulation-contract\.sh/,
  'IAM CI SQLite gate must run the backend security manipulation contract',
);

assert.match(
  routeModule,
  /function videochat_call_access_client_authority_fields/,
  'call-access public routes must reject client-controlled call/user/org/role authority fields',
);
for (const field of ['call_id', 'room_id', 'user_id', 'organization_id', 'tenant_id', 'role']) {
  assert.match(
    routeModule,
    new RegExp(`'${field}'`),
    `call-access authority field blocklist must include ${field}`,
  );
}
assert.match(
  routeModule,
  /videochat_call_access_authority_field_response[\s\S]*client_authority_fields_rejected/,
  'call-access route guard must return an explicit manipulation rejection',
);
assert.match(
  routeModule,
  /function videochat_call_access_allowed_state_change_origins[\s\S]*VIDEOCHAT_FRONTEND_ORIGIN[\s\S]*function videochat_call_access_state_change_origin_check[\s\S]*cross_origin_state_change/,
  'account-data state changes must check browser Origin/Referer against the frontend origin',
);
assert.match(
  routeModule,
  /csrf_origin_forbidden/,
  'cross-origin account update attempts must fail with a stable CSRF diagnostic',
);
assert.match(
  routeModule,
  /videochat_call_route_access_session_binding[\s\S]*videochat_call_route_ref_matches_access_binding/,
  'call-access helpers must expose the session binding comparison used by workspace resolution',
);
assert.match(
  callsModule,
  /call_access_session_call_mismatch/,
  'workspace call resolution must bind call-access sessions to their original call/access id',
);

assert.match(
  backendContract,
  /personalized session body call_id[\s\S]*anonymous session body call_id[\s\S]*forged identity and organization fields/,
  'backend contract must cover modified call id and forged identity/org fields',
);
assert.match(
  backendContract,
  /csrf_origin_forbidden[\s\S]*cross-origin confirmation must not update user data/,
  'backend contract must cover CSRF-sensitive account update request and confirmation paths',
);
assert.match(
  backendContract,
  /call_access_session_call_mismatch[\s\S]*ended call-access session must not authenticate[\s\S]*deleted call-access session must not authenticate/,
  'backend contract must cover modified workspace call id plus ended/deleted entry denial',
);

assert.match(
  e2eSpec,
  /parallel tabs in different authenticated contexts keep call-access accounts isolated/,
  'Playwright E2E must cover parallel different-account call-access joins',
);
assert.match(
  e2eSpec,
  /releaseBothRequests[\s\S]*sessionRequests\.length >= 2[\s\S]*await bothSessionRequests/,
  'parallel-account E2E must submit session requests concurrently',
);
assert.match(
  e2eSpec,
  /authorization:\s*`Bearer \$\{accountA\.sessionToken\}`[\s\S]*verified_user_id:\s*accountA\.userId[\s\S]*verified_session_id:\s*accountA\.sessionId/,
  'parallel-account E2E must prove tab A sends only account A bearer and verified identity',
);
assert.match(
  e2eSpec,
  /authorization:\s*`Bearer \$\{accountB\.sessionToken\}`[\s\S]*verified_user_id:\s*accountB\.userId[\s\S]*verified_session_id:\s*accountB\.sessionId/,
  'parallel-account E2E must prove tab B sends only account B bearer and verified identity',
);
assert.match(
  e2eSpec,
  /storedA\.sessionToken\)\.toBe\(accountA\.issuedSessionToken\)[\s\S]*storedB\.sessionToken\)\.toBe\(accountB\.issuedSessionToken\)[\s\S]*not\.toContain\(accountB\.issuedSessionToken\)[\s\S]*not\.toContain\(accountA\.issuedSessionToken\)/,
  'parallel-account E2E must prove issued call-access sessions are not merged across tabs',
);

console.log('[call-access-security-manipulation-contract] PASS');
