import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const frontendRoot = path.resolve(__dirname, '../..');
const videoChatRoot = path.resolve(frontendRoot, '..');
const repoRoot = path.resolve(videoChatRoot, '../..');

function read(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

const e2eSpec = read('demo/video-chat/frontend-vue/tests/e2e/call-access-duplicate-review-email.spec.js');
const raceE2eSpec = read('demo/video-chat/frontend-vue/tests/e2e/call-access-duplicate-race.spec.js');
const packageJson = JSON.parse(read('demo/video-chat/frontend-vue/package.json'));
const reviewHelper = read('demo/video-chat/backend-king-php/domain/calls/call_access_review.php');
const confirmationHelper = read('demo/video-chat/backend-king-php/domain/calls/call_access_account_confirmation.php');
const confirmationAuditHelper = read('demo/video-chat/backend-king-php/domain/calls/call_access_account_confirmation_audit.php');
const callAccessIdentity = read('demo/video-chat/backend-king-php/domain/calls/call_access_identity.php');
const callAccessSession = read('demo/video-chat/backend-king-php/domain/calls/call_access_session.php');
const callAccessRoutes = read('demo/video-chat/backend-king-php/http/module_calls_access.php');
const duplicateContract = read('demo/video-chat/backend-king-php/tests/call-access-duplicate-review-contract.php');
const emailContract = read('demo/video-chat/backend-king-php/tests/call-access-email-confirmation-contract.php');
const router = read('demo/video-chat/frontend-vue/src/http/router.ts');
const accountUpdateConfirmationView = read('demo/video-chat/frontend-vue/src/domain/calls/access/AccountUpdateConfirmationView.vue');

assert.match(
  e2eSpec,
  /duplicate personalized-link review flag stays private and keeps the current account session/,
  'frontend E2E must cover duplicate personalized-link review privacy',
);
assert.match(
  e2eSpec,
  /flag:\s*'duplicate_personalized_link'[\s\S]*state:\s*'manual_review_required'/,
  'duplicate E2E must require a manual-review flag',
);
assert.match(
  e2eSpec,
  /access_fingerprint:\s*'sha256:duplicate-access-fingerprint'/,
  'duplicate E2E must use a link fingerprint instead of exposing the raw access id',
);
assert.match(
  e2eSpec,
  /expectTextDoesNotContain\(joinBody,\s*foreignNeedles,\s*'duplicate review response'\)/,
  'duplicate E2E must prove the response omits foreign account and host data',
);
assert.match(
  e2eSpec,
  /storedSession\.sessionId\)\.toBe\(currentAccount\.sessionId\)[\s\S]*storedSession\.sessionToken\)\.toBe\(currentAccount\.sessionToken\)/,
  'duplicate E2E must prove the current logged-in session is preserved',
);
assert.match(
  e2eSpec,
  /email confirmation request is rate-limited and does not rebind before confirmation/,
  'frontend E2E must cover rate-limited confirmation requests',
);
assert.match(
  e2eSpec,
  /pending email confirmation confirms in another browser and rejects expired session safely/,
  'frontend E2E must cover pending confirmation refresh, other-browser confirmation, and expired-session denial',
);
assert.match(
  e2eSpec,
  /await browserA\.page\.reload\(\)[\s\S]*expect\(joinGetCount\)\.toBe\(2\)/,
  'pending confirmation E2E must keep the original join page safe after refresh',
);
assert.match(
  e2eSpec,
  /expiredResult\.payload\.error\.details\.reason\)\.toBe\('expired_session'\)/,
  'pending confirmation E2E must reject expired sessions without consuming the confirmation',
);
assert.match(
  e2eSpec,
  /wrongResult\.payload\.error\.details\.fields\.token\)\.toBe\('account_bound'\)/,
  'pending confirmation E2E must prove another account cannot consume the token',
);
assert.match(
  e2eSpec,
  /confirmResult\.payload\.result\.user\.id\)\.toBe\(currentAccountA\.userId\)[\s\S]*storedBrowserB\.sessionToken\)\.toBe\(currentAccountB\.sessionToken\)/,
  'pending confirmation E2E must update the correct account while preserving the other browser session',
);
assert.match(
  e2eSpec,
  /replayResult\.payload\.error\.details\.fields\.token\)\.toBe\('already_consumed'\)/,
  'pending confirmation E2E must prove replay is rejected after cross-browser confirmation',
);
assert.match(
  e2eSpec,
  /account-update-confirmation[\s\S]*confirmation:\s*'rate_limited'/,
  'confirmation E2E must cover the rate-limit field contract',
);
assert.match(
  e2eSpec,
  /sent_to_logged_in_account\)\.toBe\(true\)[\s\S]*sent_to_link_account\)\.toBe\(false\)/,
  'confirmation E2E must prove delivery targets the logged-in account, not the link account',
);
assert.match(
  e2eSpec,
  /confirmationRequests\[0\]\.body\)\.toEqual\(\{\s*display_name:\s*'Manually Re Entered Name'\s*\}\)/,
  'confirmation E2E must require manual re-entry of account data',
);
assert.match(
  e2eSpec,
  /storedSession\.sessionToken\)\.not\.toBe\('sess_confirmation_link_target_e2e'\)/,
  'confirmation E2E must prove the temporary/link target session is not adopted',
);
assert.match(
  packageJson.scripts['test:e2e:call-access'],
  /call-access-duplicate-race\.spec\.js/,
  'IAM call-access E2E script must include the duplicate-link race spec',
);
assert.match(
  raceE2eSpec,
  /security duplicate group detects concurrent personalized-link use by two accounts without inconsistent assignment/,
  'duplicate race E2E must cover concurrent personalized-link use by two accounts',
);
assert.match(
  raceE2eSpec,
  /joinRequests\.length >= 2[\s\S]*await bothJoinRequests/,
  'duplicate race E2E must hold parallel link-open responses until both accounts have requested the same link',
);
assert.match(
  raceE2eSpec,
  /duplicate_personalized_link[\s\S]*manual_review_required[\s\S]*access_fingerprint:\s*'sha256:duplicate-race-access'/,
  'duplicate race E2E must require a private duplicate review flag',
);
assert.match(
  raceE2eSpec,
  /sessionRequests\)\.toHaveLength\(1\)[\s\S]*authorization:\s*`Bearer \$\{linkedAccount\.sessionToken\}`[\s\S]*verified_user_id:\s*linkedAccount\.userId/,
  'duplicate race E2E must only issue a call session for the linked account during the race',
);
assert.match(
  raceE2eSpec,
  /security duplicate group marks later foreign use after the linked account is already in the call as suspicious/,
  'duplicate race E2E must cover later foreign use after the linked account has a call session',
);
assert.match(
  raceE2eSpec,
  /join_opened_after_active_call_session[\s\S]*active_call_session[\s\S]*foreignSessionPosts\)\.toBe\(0\)/,
  'after-call duplicate E2E must mark the later foreign use suspicious without session issuance',
);
assert.match(
  raceE2eSpec,
  /expectBodyOmitsSecrets\(foreignJoinBody,\s*\[accessId,\s*linkedAccount\.email,\s*linkedAccount\.displayName\]/,
  'duplicate race E2E must prove the review response omits raw link and linked-account data',
);

assert.match(
  reviewHelper,
  /CREATE TABLE IF NOT EXISTS call_access_review_flags/,
  'backend helper must persist duplicate review flags',
);
assert.match(
  reviewHelper,
  /CREATE TABLE IF NOT EXISTS call_access_host_verification_attempts/,
  'backend helper must persist host verification attempts for rate limiting',
);
assert.match(
  reviewHelper,
  /event_type' => 'call_access_duplicate_personalized_link_review'/,
  'backend helper must audit duplicate personalized-link review flags',
);
assert.match(
  reviewHelper,
  /raw_link_identifier_logged' => false[\s\S]*account_email_logged' => false[\s\S]*host_name_logged' => false/,
  'backend duplicate review payload must mark raw identifiers and sensitive names as omitted',
);
assert.match(
  reviewHelper,
  /videochat_call_access_host_verification_rate_limit/,
  'backend helper must expose host-name verification rate limiting',
);

assert.match(
  callAccessIdentity,
  /videochat_call_access_record_duplicate_personalized_link_review\([\s\S]*'join_opened'/,
  'public join must create the duplicate review flag when the foreign account reaches the warning modal',
);
assert.match(
  callAccessIdentity,
  /'mismatch' => 'strong_personalized_link'[\s\S]*'host_name' => 'not_verified'/,
  'public join must return only safe warning-modal fields for strong personalized-link mismatch',
);

assert.match(
  callAccessSession,
  /videochat_call_access_record_duplicate_personalized_link_review\([\s\S]*'session_verified_context'/,
  'session issuance must flag duplicate use from verified context mismatch',
);
assert.match(
  callAccessSession,
  /videochat_call_access_record_duplicate_personalized_link_review\([\s\S]*'session_host_verification'/,
  'session issuance must flag duplicate use during host verification',
);
assert.match(
  callAccessSession,
  /videochat_call_access_host_verification_rate_limit[\s\S]*'reason' => 'rate_limited'[\s\S]*'host_name' => 'rate_limited'/,
  'session issuance must fail host verification with a safe rate-limit field',
);
assert.match(
  callAccessSession,
  /'host_name' => \$hostName === '' \? 'not_verified' : 'wrong_host_name'/,
  'wrong host-name denial must be named host_name and avoid host data',
);

assert.match(
  confirmationHelper,
  /CREATE TABLE IF NOT EXISTS call_access_account_update_confirmations/,
  'backend helper must persist account-update confirmation tokens separately',
);
assert.match(
  confirmationHelper,
  /videochat_call_access_account_confirmation_rate_state/,
  'backend confirmation helper must expose rate-limit state',
);
assert.match(
  confirmationHelper,
  /videochat_build_call_access_account_confirmation_url[\s\S]*call_access_account_update_confirmation_token/,
  'backend confirmation helper must build a confirmation URL carrying only the account-update token',
);
assert.match(
  confirmationHelper,
  /videochat_call_access_account_confirmation_is_secure_origin[\s\S]*https/,
  'backend confirmation helper must restrict confirmation URLs to secure origins, except local loopback',
);
assert.match(
  confirmationHelper,
  /videochat_send_call_access_account_update_confirmation_mail[\s\S]*secure confirmation link[\s\S]*The link expires at/,
  'backend confirmation helper must send an email containing the secure expiring confirmation link',
);
assert.match(
  confirmationHelper,
  /'sent_to_logged_in_account' => true[\s\S]*'sent_to_link_account' => false/,
  'backend confirmation helper must target the logged-in account only',
);
assert.match(
  confirmationHelper,
  /videochat_call_access_confirm_account_update\([\s\S]*'token' => 'account_bound'/,
  'backend confirmation helper must keep tokens account-bound',
);
assert.match(
  confirmationHelper,
  /superseded_at TEXT[\s\S]*superseded_by_id TEXT/,
  'backend confirmation helper must persist superseded pending confirmation state',
);
assert.match(
  confirmationHelper,
  /VIDEOCHAT_CALL_ACCESS_ACCOUNT_CONFIRMATION_INVALIDATE_OLDER[\s\S]*VIDEOCHAT_CALL_ACCESS_ACCOUNT_UPDATE_CONFIRMATION_INVALIDATE_OLDER/,
  'backend confirmation helper must expose configured newer-invalidates-older behavior',
);
assert.match(
  confirmationAuditHelper,
  /call_access_account_update_confirmation_failed/,
  'backend confirmation helper must audit failed confirmation attempts',
);
assert.match(
  confirmationHelper,
  /call_access_account_update_confirmation_email_dispatched/,
  'backend confirmation helper must audit confirmation email dispatch',
);
assert.match(
  confirmationHelper,
  /confirmation_already_consumed[\s\S]*consume_race[\s\S]*'reason' => 'conflict'/,
  'backend confirmation helper must resolve consume races as deterministic conflicts',
);
assert.match(
  confirmationHelper,
  /UPDATE users SET display_name = :display_name/,
  'backend confirmation helper must update only confirmed manually re-entered fields',
);
assert.doesNotMatch(
  confirmationHelper,
  /UPDATE sessions SET user_id/,
  'backend confirmation helper must not rebind sessions during account update confirmation',
);

assert.match(
  callAccessRoutes,
  /account-update-confirmation/,
  'HTTP routes must expose account-update confirmation request handling',
);
assert.match(
  callAccessRoutes,
  /account-update-confirmations\/\(\[A-Za-z0-9\._-\]\{20,200\}\)\/confirm/,
  'HTTP routes must expose account-bound confirmation handling',
);
assert.match(
  callAccessRoutes,
  /VIDEOCHAT_KING_ENV[\s\S]*debug_confirmation_token[\s\S]*production[\s\S]*null/,
  'HTTP routes must not expose confirmation tokens in production responses',
);
assert.match(
  callAccessRoutes,
  /debug_confirmation_url[\s\S]*production[\s\S]*null/,
  'HTTP routes must not expose confirmation URLs in production responses',
);
assert.match(
  callAccessRoutes,
  /'expires_at' => \$requestResult\['expires_at'\]/,
  'HTTP routes must expose confirmation expiry metadata without exposing the production token',
);
assert.match(
  router,
  /path:\s*'\/account-update-confirmation'[\s\S]*AccountUpdateConfirmationView\.vue[\s\S]*requiresAuth:\s*true/,
  'router must expose a signed-in account update confirmation success/failure state route',
);
assert.match(
  accountUpdateConfirmationView,
  /call_access_account_update_confirmation_token[\s\S]*account-update-confirmations\/\$\{encodeURIComponent\(token\)\}\/confirm/,
  'confirmation view must consume the account-update confirmation token through the backend route',
);
assert.match(
  accountUpdateConfirmationView,
  /Account update confirmed[\s\S]*payload\?\.result\?\.state !== 'confirmed'/,
  'confirmation view must show a confirmed success state only after backend confirmation succeeds',
);

assert.match(
  duplicateContract,
  /same linked account must not create a duplicate review flag/,
  'backend duplicate contract must cover same-account reuse without a false flag',
);
assert.match(
  duplicateContract,
  /second account open should create exactly one review flag/,
  'backend duplicate contract must cover second-account flag creation',
);
assert.match(
  duplicateContract,
  /warning-modal review flag must be created at join-open reach[\s\S]*warning-modal review audit should record join_opened stage/,
  'backend duplicate contract must prove account B reaching the warning modal creates the review flag and audit event',
);
assert.match(
  duplicateContract,
  /host verification must reuse the warning-modal review flag[\s\S]*reused review flag must preserve the original warning-modal stage/,
  'backend duplicate contract must prove later host-name attempts reuse the warning-modal review flag',
);
assert.match(
  duplicateContract,
  /third host attempt should be rate-limited/,
  'backend duplicate contract must cover rate-limited host verification',
);
assert.match(
  duplicateContract,
  /duplicate denied and rate-limited attempts must not persist sessions/,
  'backend duplicate contract must prove denied duplicate attempts do not bind sessions',
);
assert.match(
  duplicateContract,
  /pcntl_fork[\s\S]*linked account parallel reopen should issue[\s\S]*foreign account parallel attempt must not issue/,
  'backend duplicate contract must run a real parallel linked-account versus foreign-account session issuance race when pcntl is available',
);
assert.match(
  duplicateContract,
  /parallel race must not reassign the personalized link[\s\S]*parallel foreign account should create a duplicate review flag/,
  'backend duplicate contract must prove the parallel race leaves the link assignment consistent and review-flagged',
);
assert.match(
  duplicateContract,
  /review flag should reference the first in-call linked account[\s\S]*review flag should keep first in-call session timestamp/,
  'backend duplicate contract must prove later foreign use records the already-used in-call reference',
);
assert.match(
  duplicateContract,
  /parallel duplicate review must be audit-logged/,
  'backend duplicate contract must audit-log the parallel duplicate review',
);
assert.match(
  duplicateContract,
  /review flag tenant must follow the call organization[\s\S]*review flag call id must follow the call/,
  'backend duplicate contract must prove review flags are scoped to the call organization and call',
);
assert.match(
  duplicateContract,
  /review audit tenant must follow the call organization[\s\S]*review audit call id must follow the call/,
  'backend duplicate contract must prove review audit events are scoped to the call organization and call',
);
assert.match(
  duplicateContract,
  /review flag subject must be the foreign account[\s\S]*review flag target must be the linked account[\s\S]*review flag should identify the affected linked account reference/,
  'backend duplicate contract must prove review flags identify foreign and linked accounts without rebinding the link',
);
assert.match(
  duplicateContract,
  /review audit actor must be the foreign account[\s\S]*review audit target must be the linked account[\s\S]*review audit should reference the affected linked account/,
  'backend duplicate contract must prove duplicate-link audit entries expose the actor and affected reference',
);
assert.match(
  duplicateContract,
  /review audit must not persist raw access id[\s\S]*review audit must fingerprint the foreign link[\s\S]*review audit must fingerprint the session id/,
  'backend duplicate contract must prove review audit uses fingerprints instead of raw link/session identifiers',
);
assert.match(
  duplicateContract,
  /crossOrgHostName[\s\S]*crossOrgToken[\s\S]*crossOrgSdp[\s\S]*crossOrgIce[\s\S]*cross-org review audit event/,
  'backend duplicate contract must prove review audit omits host names, tokens, SDP, and ICE data',
);

assert.match(
  emailContract,
  /confirmation must be sent to current logged-in email/,
  'backend email contract must prove delivery targets the logged-in account',
);
assert.match(
  emailContract,
  /account data must not update before confirmation/,
  'backend email contract must prove no update before confirmation',
);
assert.match(
  emailContract,
  /third confirmation request should be rate-limited/,
  'backend email contract must prove confirmation request rate limiting',
);
assert.match(
  emailContract,
  /confirmation email should contain a secure HTTPS confirmation link[\s\S]*confirmation email must describe link expiry/,
  'backend email contract must prove the dispatched email contains a secure time-limited confirmation link',
);
assert.match(
  emailContract,
  /expired pending-confirmation session should be rejected[\s\S]*expired_session/,
  'backend email contract must reject expired sessions while confirmation is pending',
);
assert.match(
  emailContract,
  /another browser session for same account should confirm[\s\S]*browser-b confirmation user mismatch/,
  'backend email contract must allow another active session for the same account to confirm',
);
assert.match(
  emailContract,
  /expired pending-confirmation session must not consume the token/,
  'backend email contract must prove expired sessions do not consume pending confirmations',
);
assert.match(
  emailContract,
  /confirmation must not rebind the current session/,
  'backend email contract must prove sessions are not rebound by confirmation',
);
assert.match(
  emailContract,
  /confirmation token replay should fail/,
  'backend email contract must prove one-time confirmation tokens',
);
assert.match(
  emailContract,
  /multiple pending confirmations must use distinct tokens[\s\S]*second pending confirmation should stay pending after first confirmation/,
  'backend email contract must prove multiple pending confirmations resolve independently by default',
);
assert.match(
  emailContract,
  /newer request should supersede exactly one older pending confirmation[\s\S]*superseded confirmation should return deterministic conflict/,
  'backend email contract must prove configured newer-invalidates-older behavior',
);
assert.match(
  emailContract,
  /newer invalidating replay should fail deterministically/,
  'backend email contract must prove confirmation replay/race conflicts resolve deterministically',
);
assert.match(
  emailContract,
  /expired confirmation should fail[\s\S]*expired confirmation must not update data[\s\S]*expired confirmation must not consume token/,
  'backend email contract must prove expired confirmation links update no data and remain unconsumed',
);

console.log('[call-access-duplicate-review-email-contract] PASS');
