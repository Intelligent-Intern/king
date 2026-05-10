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

function assertMatches(source, pattern, message) {
  assert.match(source, pattern, message);
}

const evidence = readRepo('documentation/iam-sprint-05-calendar-invite-extraction.md');
const calendarInviteContract = readRepo('demo/video-chat/frontend-vue/tests/contract/call-access-calendar-invite-join-contract.mjs');
const registeredLoggedInContract = readRepo('demo/video-chat/frontend-vue/tests/contract/call-access-registered-logged-in-invitee-contract.mjs');
const registeredLoggedOutContract = readRepo('demo/video-chat/frontend-vue/tests/contract/call-access-registered-logged-out-handoff-contract.mjs');
const registeredExtractContract = readRepo('demo/video-chat/frontend-vue/tests/contract/call-access-registered-invitee-extract-contract.mjs');
const inviteInvalidationContract = readRepo('demo/video-chat/frontend-vue/tests/contract/call-access-invite-invalidation-terminal-contract.mjs');
const terminalBrowserContract = readRepo('demo/video-chat/frontend-vue/tests/contract/call-access-terminal-browser-flows-contract.mjs');
const terminalStatesContract = readRepo('demo/video-chat/frontend-vue/tests/contract/call-access-terminal-states-contract.mjs');
const linkPrivacyContract = readRepo('demo/video-chat/frontend-vue/tests/contract/call-access-link-privacy-contract.mjs');
const unregisteredSpec = readRepo('demo/video-chat/frontend-vue/tests/e2e/call-access-calendar-unregistered-invite.spec.js');
const appointmentBooking = readRepo('demo/video-chat/backend-king-php/domain/calls/appointment_calendar_booking.php');
const callAccessPublic = readRepo('demo/video-chat/backend-king-php/domain/calls/call_access_public.php');
const callAccessSession = readRepo('demo/video-chat/backend-king-php/domain/calls/call_access_session.php');
const callAccessContract = readRepo('demo/video-chat/backend-king-php/domain/calls/call_access_contract.php');

const sourceBranches = [
  'local/iam-e2e-calendar-invitation-flow',
  'local/iam-e2e-calendar-unregistered-main-journey',
  'codex/iam-e2e-calendar-unregistered-followup-20260509',
  'local/iam-e2e-calendar-edge-safe-states',
  'local/iam-e2e-reschedule-stale-link-safety',
  'local/iam-e2e-invite-reschedule-delete-end-main-journeys',
  'local/iam-e2e-invite-invalidation',
  'local/iam-e2e-invite-invalidation-audit',
  'local/iam-e2e-invite-registered-flow-proof-2',
  'local/iam-e2e-invite-registered-logged-out-proof-3',
  'codex/iam-lane-60-calendar-invite-personalized-link-proof',
];

for (const branch of sourceBranches) {
  assert.ok(evidence.includes(branch), `calendar invite evidence must classify ${branch}`);
}

assertMatches(
  evidence,
  /Source-Only Reschedule Lifecycle Value[\s\S]*reschedule deletes old personal\/open links[\s\S]*current stale-link terminal proof/s,
  'evidence must distinguish source-only reschedule lifecycle value from current stale-link terminal proof',
);
assertMatches(
  evidence,
  /No product code, package scripts, shared CI wiring, `SPRINT\.md`, or\s+`BACKLOG\.md` were edited/s,
  'evidence must keep this extraction inside the allowed write scope',
);

assertMatches(
  appointmentBooking,
  /\$callId = videochat_generate_call_id\(\);[\s\S]*\$accessId = videochat_generate_call_access_uuid\(\);/,
  'calendar booking must mint fresh call and access identifiers',
);
assertMatches(
  appointmentBooking,
  /INSERT INTO calls\([\s\S]*'invite_only'[\s\S]*:owner_user_id[\s\S]*:status/,
  'calendar booking must create an invite-only appointment call',
);
assertMatches(
  appointmentBooking,
  /INSERT INTO call_participants[\s\S]*:source' => 'external'[\s\S]*:invite_state' => 'invited'/,
  'calendar booking must keep unregistered invitees as external invited participants before session issuance',
);
assertMatches(
  appointmentBooking,
  /INSERT INTO call_access_links\([\s\S]*:id' => \$accessId[\s\S]*:call_id' => \$callId[\s\S]*:participant_email' => \(string\) \$data\['email'\]/,
  'calendar booking must bind the generated access id to the generated call and invitee email',
);
assertMatches(
  calendarInviteContract,
  /calendar booking response must expose only the call-access join path needed by the invitee[\s\S]*public session route must not expose calendar or tenant authority fields/s,
  'calendar invite extraction must keep booking and session envelopes redacted',
);

assertMatches(
  callAccessContract,
  /function videochat_call_access_requires_guest_name[\s\S]*return \$linkedUserId <= 0 && \$participantEmail !== '' && !is_array\(\$targetUser\);/s,
  'personal calendar links without a target account must require an invitee display name',
);
assertMatches(
  callAccessSession,
  /if \(\$requiresGuestName\)[\s\S]*videochat_create_guest_user_for_call_access\(\$pdo, \$guestName, \$tenantId\)[\s\S]*\$createdPersonalGuest = \$linkKind === 'personal';/s,
  'unregistered invitee guest identity must be created only during call-access session issuance',
);
assertMatches(
  callAccessSession,
  /if \(\(\$linkKind === 'open' && !\$openInviteOnlyLink\) \|\| \$createdPersonalGuest\)[\s\S]*videochat_ensure_internal_call_participant[\s\S]*\$linkKind === 'open' \? 'allowed' : 'invited'/s,
  'unregistered personal invitee must be internalized only as an invited participant after session issuance',
);
assertMatches(
  unregisteredSpec,
  /requires_guest_name\)\.toBe\(true\)[\s\S]*getByPlaceholder\('Enter your display name'\)[\s\S]*Name is required for this link\.[\s\S]*expect\(counters\.session\)\.toBe\(0\)/s,
  'unregistered calendar browser proof must require a display name before session POST',
);
assertMatches(
  unregisteredSpec,
  /guest_name: guestName[\s\S]*account_type\)\.toBe\('guest'\)[\s\S]*tenant_admin[\s\S]*toBe\(false\)[\s\S]*admit_participants[\s\S]*toBe\(false\)/s,
  'unregistered calendar session must use a least-privilege guest identity',
);
assertMatches(
  unregisteredSpec,
  /toContainText\(\/Call owner has been notified\|Waiting for host\/i[\s\S]*not\.toContain\('\/workspace\/call'\)[\s\S]*expect\(counters\.directCallAccess\)\.toBe\(0\)/s,
  'unregistered calendar invitee must remain in lobby instead of direct workspace access before admission',
);

assertMatches(
  registeredLoggedInContract,
  /matching logged-in user should issue[\s\S]*wrong logged-in account should be forbidden[\s\S]*session issuance must re-check the registered invitee/s,
  'registered logged-in invitee proof must keep account binding and wrong-account denial',
);
assertMatches(
  registeredLoggedOutContract,
  /workspace call route must stay authenticated for logged-out registered invitees[\s\S]*login handoff must rebind to the backend-returned access link for the intended invite/s,
  'registered logged-out invitee proof must keep safe login handoff and backend invite rebinding',
);
assertMatches(
  registeredExtractContract,
  /local\/iam-e2e-invite-registered-logged-out-proof-3[\s\S]*registered invitee sessions must persist call-scoped access bindings/s,
  'registered invitee extraction must retain the logged-out source branch and current call-scoped binding proof',
);

assertMatches(
  callAccessPublic,
  /videochat_call_access_link_is_invalidated\(\$pdo, \$accessLink\)[\s\S]*'reason' => 'not_found'[\s\S]*'access_link' => null[\s\S]*'call' => null[\s\S]*'target_user' => null/s,
  'invalidated personal links must resolve as safe missing links',
);
assertMatches(
  callAccessPublic,
  /\$expiresAtUnix <= time\(\)[\s\S]*'reason' => 'expired'[\s\S]*'access_link' => null[\s\S]*'call' => null[\s\S]*'target_user' => null/s,
  'expired and stale invite links must return terminal redacted expired states',
);
assertMatches(
  callAccessContract,
  /if \(\$linkExpiresAt !== ''\)[\s\S]*return \$fail\('call_access_link_expired'\)/s,
  'existing call-access session bindings must fail after stale link expiry',
);
assertMatches(
  inviteInvalidationContract,
  /rescheduled stale links must expire safely without exposing old invite metadata[\s\S]*public join and session routes must keep rescheduled stale links as expired terminal states/s,
  'invite invalidation terminal proof must retain reschedule stale-link expiry coverage',
);
assertMatches(
  inviteInvalidationContract,
  /invalidated personalized link must not create a fresh session[\s\S]*must not leak invited email[\s\S]*must not leak call id/s,
  'invite invalidation proof must deny session issuance and private data leakage',
);
assertMatches(
  terminalBrowserContract,
  /deleted users must invalidate existing backend sessions[\s\S]*invite invalidation contract must keep terminal call-access links closed in browser join flows/s,
  'terminal browser proof must keep disabled/deleted session and invite-link closure',
);
assertMatches(
  terminalStatesContract,
  /direct_join_system_admin_alpha_ended_denied[\s\S]*direct_join_alpha_owner_alpha_disabled_denied[\s\S]*direct_join_alpha_owner_alpha_deleted_hidden/s,
  'terminal states proof must include ended, disabled, and deleted call cases',
);
assertMatches(
  linkPrivacyContract,
  /invalid link states must clear call-specific UI details before rendering[\s\S]*invalid link E2E must prove foreign call title and email are not rendered/s,
  'link privacy proof must keep invalid calendar/invite states free of foreign details',
);

process.stdout.write('[call-access-calendar-invite-extract-contract] PASS\n');
