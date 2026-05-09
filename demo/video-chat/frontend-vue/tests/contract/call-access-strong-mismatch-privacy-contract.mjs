import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { callAccessE2eSuiteText } from './helpers/iamCallAccessSuiteCoverage.mjs';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(__dirname, '..', '..');

function read(relativePath) {
  return fs.readFileSync(path.join(root, relativePath), 'utf8');
}

const e2eSpec = [
  read('tests/e2e/call-access-join.spec.js'),
  read('tests/e2e/call-access-personalized-identity.spec.js'),
  read('tests/e2e/call-access-strong-mismatch-host-verification.spec.js'),
].join('\n');
const joinView = read('src/domain/calls/access/JoinView.vue');
const hostVerificationModal = read('src/domain/calls/access/StrongMismatchHostVerificationModal.vue');
const callAccessSession = read('src/domain/calls/access/callAccessSession.ts');
const backendSession = read('../backend-king-php/domain/calls/call_access_session.php');
const backendBinding = read('../backend-king-php/domain/calls/call_access_contract.php');
const backendAudit = read('../backend-king-php/domain/audit/audit_events.php');
const backendPrivacyContract = read('../backend-king-php/tests/call-access-strong-mismatch-privacy-contract.php');

assert.match(
  e2eSpec,
  /wrong-account strong personalized mismatch denies access without foreign data exposure/,
  'public join E2E must cover strong personalized-link mismatch wrong-host denial',
);
assert.match(
  e2eSpec,
  /foreignNeedles\s*=\s*\[[\s\S]*linkInviteeName[\s\S]*linkInviteeEmail[\s\S]*realHostName[\s\S]*realHostEmail[\s\S]*deniedSessionToken[\s\S]*\]/,
  'strong-mismatch E2E must define link invitee, host, and denied-session leak sentinels',
);
assert.match(
  e2eSpec,
  /expectTextDoesNotContain\((?:await joinResponse\.text\(\)|joinBody),\s*foreignNeedles,\s*'strong-mismatch join response'\)/,
  'strong-mismatch E2E must prove the join response has no foreign person data',
);
assert.match(
  e2eSpec,
  /status:\s*403[\s\S]*code:\s*'call_access_forbidden'[\s\S]*mismatch:\s*'strong_personalized_link'[\s\S]*host_name:\s*'wrong_host_name'/,
  'strong-mismatch E2E must model a server-side wrong-host-name denial',
);
assert.match(
  e2eSpec,
  /expectTextDoesNotContain\((?:sessionBodyText|sessionBody),\s*foreignNeedles,\s*'strong-mismatch wrong-host denial response'\)/,
  'strong-mismatch E2E must prove the denial response has no foreign person data',
);
assert.match(
  e2eSpec,
  /(?:sessionRequestAuthorization|sessionAuthorization)\)\.toBe\(`Bearer \$\{(?:wrongLoggedInSession|wrongAccount)\.sessionToken\}`\)/,
  'strong-mismatch E2E must prove the current logged-in session is authoritative',
);
assert.match(
  e2eSpec,
  /(?:sessionRequestBody|sessionBody)\)\.toEqual\(\{\s*verified_user_id:\s*(?:wrongLoggedInUserId|wrongAccount\.userId),\s*verified_session_id:\s*(?:wrongLoggedInSession|wrongAccount)\.sessionId,\s*\}\)/,
  'strong-mismatch E2E must prove verified logged-in context is sent to session issuance',
);
assert.match(
  e2eSpec,
  /not\.toContainText\(\/Call owner has been notified\|Waiting for host\/i\)[\s\S]*expect\(page\.url\(\)\)\.not\.toContain\('\/workspace\/call'\)/,
  'strong-mismatch E2E must prove wrong-host denial grants no direct call access',
);
assert.match(
  e2eSpec,
  /storedSession\.sessionId\)\.toBe\((?:wrongLoggedInSession|wrongAccount)\.sessionId\)[\s\S]*storedSession\.sessionToken\)\.toBe\((?:wrongLoggedInSession|wrongAccount)\.sessionToken\)[\s\S]*storedSession\.sessionToken\)\.not\.toBe\(deniedSessionToken\)/,
  'strong-mismatch E2E must prove denied responses do not bind a foreign session',
);
assert.match(
  e2eSpec,
  /expect\(sessionPostCount\)\.toBe\(1\)[\s\S]*expect\(joinGetCount\)\.toBe\(1\)|expect\(joinGetCount\)\.toBe\(1\)[\s\S]*expect\(sessionPostCount\)\.toBe\(1\)/,
  'strong-mismatch E2E must guard against reload or duplicate request loops',
);

assert.match(
  e2eSpec,
  /foreign personalized strong mismatch verifies host, declines update, and keeps logged-in account/,
  'host-verification E2E must cover correct-host decline-update flow',
);
assert.match(
  e2eSpec,
  /This link may have been issued for someone else\.[\s\S]*The link details differ from the account you are currently using\.[\s\S]*getByLabel\('Host name'\)/,
  'host-verification warning must explain foreign link/account difference and ask for host name',
);
assert.match(
  e2eSpec,
  /Definitely Wrong Host[\s\S]*Verify host[\s\S]*Access was not granted\. This attempt may be reviewed manually\.[\s\S]*not\.toContainText\(\/Call owner has been notified\|Waiting for host\/i\)/,
  'wrong host name must grant no direct access and surface manual-review wording',
);
assert.match(
  e2eSpec,
  /realHostName[\s\S]*Verify host[\s\S]*Host name confirmed[\s\S]*Do you want to update your account data before joining\?/,
  'correct host name must be accepted with success confirmation and update choice',
);
assert.match(
  e2eSpec,
  /correctPayload\?\.result\?\.user[\s\S]*display_name:\s*loggedInAccount\.displayName[\s\S]*Continue without updating[\s\S]*accountUpdateRequests\)\.toBe\(0\)/,
  'declining update must leave logged-in account data unchanged and skip account-update request',
);
assert.match(
  callAccessE2eSuiteText,
  /call-access-strong-mismatch-host-verification\.spec\.js/,
  'call-access E2E script must include the host-verification browser proof',
);
assert.match(
  joinView,
  /StrongMismatchHostVerificationModal[\s\S]*isStrongMismatchHostVerificationError[\s\S]*strong_personalized_link[\s\S]*host_name[\s\S]*not_verified/s,
  'JoinView must route strong-mismatch join denials into the host-verification modal',
);
assert.match(
  hostVerificationModal,
  /host_verification_foreign_link[\s\S]*host_verification_account_diff[\s\S]*host_name[\s\S]*loginWithCallAccess[\s\S]*hostName/s,
  'host-verification modal must explain the risk, ask host name, and send it to session issuance',
);
assert.match(
  callAccessSession,
  /errorDetailsFromPayload[\s\S]*body\.host_name\s*=\s*hostName/s,
  'call-access session client must send host_name and preserve safe field errors',
);
assert.match(
  backendSession,
  /videochat_call_access_host_name_verified[\s\S]*correct_host_name[\s\S]*\$targetUser = \$authenticatedUser[\s\S]*\$hostVerifiedAt = gmdate\('c'\)/s,
  'backend session issuance must accept a correct host name and continue as the authenticated account',
);
assert.match(
  backendSession,
  /wrong_host_name[\s\S]*videochat_audit_record_call_access_strong_mismatch[\s\S]*'host_name' => 'wrong_host_name'/s,
  'backend session issuance must deny wrong host names without issuing a session and audit the denial',
);
assert.match(
  backendBinding,
  /host_verified_at[\s\S]*\$personalLinkHostVerified[\s\S]*!\$personalLinkHostVerified/s,
  'call-access session binding must preserve a host-verified marker for accepted foreign personal links',
);
assert.match(
  backendAudit,
  /call_access_host_verification_succeeded[\s\S]*account_update_offered[\s\S]*host_name_logged' => false/s,
  'audit helper must log successful host-name verification without host-name or foreign-account data',
);
assert.match(
  backendPrivacyContract,
  /correct host name should issue a logged-in account session[\s\S]*host verification success audit should be recorded/s,
  'backend privacy contract must prove correct-host issuance and success audit',
);

console.log('[call-access-strong-mismatch-privacy-contract] PASS');
