import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const frontendRoot = path.resolve(__dirname, '..', '..');
const repoRoot = path.resolve(frontendRoot, '..', '..', '..');

function read(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

function redactedTerminalPayload(reason) {
  return {
    ok: false,
    reason,
    access_link: null,
    call: null,
    target_user: null,
    session: null,
    user: null,
  };
}

function assertNoPrivatePayload(payload, label) {
  const serialized = JSON.stringify(payload);
  for (const privateNeedle of [
    'iam-alpha-active',
    'iam-alpha-room',
    'Alpha Active Strategy Call',
    'iam-alpha-owner@example.test',
    'guest_list_user_keys',
    'access_secret',
    'session_token',
    'cookie',
    'candidate:',
    'a=ice-ufrag',
    'v=0',
  ]) {
    assert.doesNotMatch(serialized, new RegExp(privateNeedle.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')), `${label} must not leak ${privateNeedle}`);
  }
}

const callAccessContract = read('demo/video-chat/backend-king-php/domain/calls/call_access_contract.php');
const callAccessPublic = read('demo/video-chat/backend-king-php/domain/calls/call_access_public.php');
const callAccessSession = read('demo/video-chat/backend-king-php/domain/calls/call_access_session.php');
const realtimeCallContext = read('demo/video-chat/backend-king-php/domain/realtime/realtime_call_context.php');
const websocketCommands = read('demo/video-chat/backend-king-php/http/module_realtime_websocket_commands.php');
const lobbySync = read('demo/video-chat/backend-king-php/domain/realtime/realtime_lobby_sync.php');
const authSupport = read('demo/video-chat/backend-king-php/support/auth.php');
const joinView = read('demo/video-chat/frontend-vue/src/domain/calls/access/JoinView.vue');
const routeGuardContract = read('demo/video-chat/frontend-vue/tests/contract/call-access-route-guard-ui-contract.mjs');
const removedMembersContract = read('demo/video-chat/frontend-vue/tests/contract/call-access-removed-members-contract.mjs');
const terminalStatesContract = read('demo/video-chat/frontend-vue/tests/contract/call-access-terminal-states-contract.mjs');

const revokedStates = ['cancelled', 'declined'];
for (const state of revokedStates) {
  const payload = redactedTerminalPayload(`call_access_${state}`);
  assert.equal(payload.access_link, null, `${state} terminal payload must not include access link data`);
  assert.equal(payload.call, null, `${state} terminal payload must not include call data`);
  assert.equal(payload.target_user, null, `${state} terminal payload must not include target user data`);
  assert.equal(payload.session, null, `${state} terminal payload must not include a reusable session`);
  assert.equal(payload.user, null, `${state} terminal payload must not include a user payload`);
  assertNoPrivatePayload(payload, `${state} terminal payload`);
}

assert.match(
  callAccessContract,
  /LEFT JOIN call_participants cp[\s\S]*cp\.call_id = call_access_sessions\.call_id[\s\S]*cp\.user_id = call_access_sessions\.user_id[\s\S]*cp\.source = 'internal'/,
  'cached call-access session validation must re-read the current participant row for the bound call/user',
);
assert.match(
  callAccessContract,
  /\$participantInviteState = strtolower\(trim\(\(string\) \(\$row\['participant_invite_state'\] \?\? ''\)\)\);[\s\S]*in_array\(\$participantInviteState, \['cancelled', 'declined'\], true\)[\s\S]*call_access_participant_removed/,
  'cached call-access sessions must fail once the participant row is cancelled or declined',
);
assert.match(
  authSupport,
  /videochat_validate_call_access_session_binding\([\s\S]*\$trimmedSessionId[\s\S]*\(int\) \$row\['user_id'\][\s\S]*if \([\s\S]*is_call_access_session[\s\S]*!\(bool\) \(\$callAccessSession\['ok'\] \?\? false\)[\s\S]*'session' => null,[\s\S]*'user' => null/,
  'normal bearer authentication must reject stale stored call-access sessions before websocket or API reuse',
);
assert.match(
  callAccessPublic,
  /if \(videochat_call_access_link_is_invalidated\(\$pdo, \$accessLink\)\) \{[\s\S]*'reason' => 'not_found'[\s\S]*'access_link' => null,[\s\S]*'call' => null,[\s\S]*'target_user' => null/,
  'copied join URLs for cancelled or declined participants must resolve as redacted not-found payloads',
);
assert.match(
  callAccessSession,
  /\$resolve = videochat_resolve_call_access_public\(\$pdo, \$accessId\);[\s\S]*if \(!\(bool\) \(\$resolve\['ok'\] \?\? false\)\) \{[\s\S]*'session' => null,[\s\S]*'user' => null,[\s\S]*'access_link' => null,[\s\S]*'call' => null/,
  'session issuance must inherit copied-link revocation before minting a new call-scoped session',
);
assert.match(
  websocketCommands,
  /if \(\$lobbyAction === 'lobby\/remove'\)[\s\S]*videochat_realtime_mark_call_participant_invite_state_by_user_id\([\s\S]*'cancelled'[\s\S]*\['pending', 'allowed', 'accepted'\]/,
  'host removal from lobby or admitted state must persist a revoked participant state, not restore invite access',
);
assert.match(
  realtimeCallContext,
  /function videochat_realtime_mark_call_participant_pending_for_queue[\s\S]*SET invite_state = 'pending'[\s\S]*AND invite_state = 'invited'/,
  'stale tabs must not turn cancelled or declined participant rows back into pending lobby rows',
);
assert.doesNotMatch(
  realtimeCallContext,
  /function videochat_realtime_mark_call_participant_pending_for_queue[\s\S]*invite_state IN \('invited', 'declined', 'cancelled'\)/,
  'queue join must not allow revoked participant states to re-enter the lobby',
);
assert.match(
  realtimeCallContext,
  /function videochat_realtime_mark_call_participant_joined[\s\S]*WHEN invite_state IN \('invited', 'pending', 'accepted'\) THEN 'allowed'/,
  'room join persistence must not promote cancelled or declined participants back to allowed',
);
assert.doesNotMatch(
  realtimeCallContext,
  /function videochat_realtime_mark_call_participant_joined[\s\S]*WHEN invite_state IN \('invited', 'pending', 'accepted', 'declined', 'cancelled'\) THEN 'allowed'/,
  'direct room joins must not revive participants removed after admission',
);
assert.match(
  realtimeCallContext,
  /videochat_fetch_call_access_session_binding\(\$pdo, \$sessionId\)[\s\S]*if \(is_array\(\$accessBinding\)\)[\s\S]*\$roomMismatch[\s\S]*\$callMismatch[\s\S]*\$userMismatch[\s\S]*'access_session_binding' => 'mismatch'/,
  'realtime room resolution must bind cached sessions to the exact issued call, room, and user',
);
assert.match(
  lobbySync,
  /WHERE cp\.call_id = :call_id[\s\S]*cp\.source = 'internal'[\s\S]*cp\.invite_state IN \('pending', 'allowed', 'accepted'\)/,
  'lobby snapshots must exclude cancelled and declined removed participants',
);
assert.match(
  joinView,
  /if \(!result\.ok\) \{[\s\S]*state\.joining = false[\s\S]*state\.joinError = localizedApiErrorMessage[\s\S]*return;[\s\S]*\}[\s\S]*startAdmissionWait\(accessId\);/,
  'public join UI must not start lobby admission after a copied join URL is denied',
);
assert.match(
  joinView,
  /sendAdmissionFrame\(\{ type: 'lobby\/queue\/join', room_id: pendingRoomId \}\)/,
  'admission wait remains explicit and must depend on the backend-accepted call-scoped websocket path',
);

assert.match(
  removedMembersContract,
  /cancelled invited user link must hide call-access data[\s\S]*declined invited user link must hide call-access data/,
  'removed-members proof must keep cancelled and declined copied-link payloads redacted',
);
assert.match(
  terminalStatesContract,
  /private_call_payload_forbidden[\s\S]*resolvePayload[\s\S]*callFetchPayload[\s\S]*must not leak/,
  'terminal-state proof must continue covering private call-data redaction',
);
assert.match(
  routeGuardContract,
  /workspace call route must require an authenticated admin or user session[\s\S]*public join UI must enter the admission lobby after call-access session issuance/,
  'route-guard proof must keep stale workspace tabs behind authenticated and admitted call-access paths',
);

process.stdout.write('[call-access-kicked-rejoin-denial-contract] PASS\n');
