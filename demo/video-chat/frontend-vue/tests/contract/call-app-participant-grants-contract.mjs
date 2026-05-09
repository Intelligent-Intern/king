import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';

const root = path.resolve(new URL('../..', import.meta.url).pathname);
const repoRoot = path.resolve(root, '../../..');

async function read(relativePath) {
  return readFile(path.join(repoRoot, relativePath), 'utf8');
}

const [
  buttonSource,
  templateSource,
  rightRosterSource,
  sidebarSource,
  workspaceSource,
  runtimeConfigSource,
  signalingSource,
  routeSource,
  domainSource,
  launchTokenSource,
  crdtDomainSource,
  crdtBridgeSource,
  migrationsSource,
  lifecycleTestSource,
  sprintSource,
] = await Promise.all([
  read('demo/video-chat/frontend-vue/src/domain/realtime/callApps/CallAppParticipantGrantButton.vue'),
  read('demo/video-chat/frontend-vue/src/domain/realtime/CallWorkspaceView.template.html'),
  read('demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/RightRosterPanel.vue'),
  read('demo/video-chat/frontend-vue/src/domain/realtime/callApps/CallAppsSidebarPanel.vue'),
  read('demo/video-chat/frontend-vue/src/domain/realtime/CallWorkspaceView.vue'),
  read('demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/runtimeConfig.ts'),
  read('demo/video-chat/backend-king-php/domain/realtime/realtime_signaling.php'),
  read('demo/video-chat/backend-king-php/http/module_call_apps.php'),
  read('demo/video-chat/backend-king-php/domain/call_apps/call_app_sessions.php'),
  read('demo/video-chat/backend-king-php/domain/call_apps/call_app_launch_tokens.php'),
  read('demo/video-chat/backend-king-php/domain/call_apps/call_app_crdt.php'),
  read('demo/video-chat/frontend-vue/src/domain/realtime/callApps/useCallAppCrdtBridge.js'),
  read('demo/video-chat/backend-king-php/support/call_app_session_migrations.php'),
  read('demo/video-chat/backend-king-php/tests/call-app-session-lifecycle-contract.php'),
  read('SPRINT.md'),
]);

assert.match(
  templateSource,
  /<RightRosterPanel[\s\S]*:active-call-app-session="activeCallAppSession"[\s\S]*:send-socket-frame="sendSocketFrame"/,
  'right participant list must pass Call App session and realtime wiring into the focused roster component',
);

assert.match(
  workspaceSource,
  /import RightRosterPanel from ['"]\.\/workspace\/callWorkspace\/RightRosterPanel\.vue['"]/,
  'CallWorkspaceView must import the focused right roster component instead of owning grant-button markup',
);

assert.match(
  rightRosterSource,
  /import CallAppParticipantGrantButton from ['"]\.\.\/\.\.\/callApps\/CallAppParticipantGrantButton\.vue['"]/,
  'right roster component must import the focused Call App grant control',
);

assert.match(
  rightRosterSource,
  /<CallAppParticipantGrantButton[\s\S]*:session="activeCallAppSession"[\s\S]*:row="row"[\s\S]*:send-socket-frame="sendSocketFrame"/,
  'right roster component must expose the Call App permission control when a session is active',
);

assert.match(
  rightRosterSource,
  /callAppRead[\s\S]*calls\.workspace\.action_option_call_app_read[\s\S]*supportedPermissions\.has\('read'\)[\s\S]*callAppWrite[\s\S]*supportedPermissions\.has\('write'\)[\s\S]*callAppDelete[\s\S]*supportedPermissions\.has\('delete'\)/s,
  'right roster action options must name read/write/delete Call App permissions only when the session advertises support',
);

assert.match(
  rightRosterSource,
  /const visibleActionSet = computed\(\(\) => \{[\s\S]*if \(option\.disabled\) continue;[\s\S]*actionVisibility\.value\[option\.key\] === true/s,
  'read/write/delete Call App action options must not become visible when the active session does not advertise that permission',
);

assert.match(
  buttonSource,
  /\/api\/call-app-sessions\/\$\{encodeURIComponent\(sessionId\.value\)\}\/participant-grants/,
  'grant button must update the backend participant-grants endpoint',
);

assert.match(
  buttonSource,
  /body:\s*\{[\s\S]*grants:\s*\[\{[\s\S]*subject_type:\s*'user'[\s\S]*user_id:\s*rowUserId\.value[\s\S]*grant_state:\s*grantState/s,
  'grant button PATCH payload must carry explicit user subject and next grant state',
);

assert.match(
  buttonSource,
  /type:\s*['"]call-app\/grants-updated['"][\s\S]*target_user_id:\s*rowUserId\.value[\s\S]*subject_type:\s*'user'[\s\S]*grant_state:\s*grantState[\s\S]*requestRoomSnapshot\(\)/,
  'grant updates must emit a targeted realtime refresh signal and request snapshot backfill',
);

assert.match(
  buttonSource,
  /defineEmits\(\[['"]grant-updated['"]\]\)[\s\S]*emit\(['"]grant-updated['"][\s\S]*sessionId:\s*sessionId\.value[\s\S]*userId:\s*rowUserId\.value[\s\S]*grantState/s,
  'grant button must emit local grant updates for sidebar state labels',
);

assert.doesNotMatch(
  buttonSource,
  /sessionToken|Authorization|localStorage/,
  'grant button must not expose primary auth material',
);

assert.match(
  sidebarSource,
  /function grantStateForParticipant\(participant\)[\s\S]*localGrantOverrides\.value\[`\$\{sessionId\}:\$\{userId\}`\][\s\S]*activeSessionForAccess\.value\?\.grants/s,
  'Call Apps sidebar must refresh participant grant labels from local realtime overrides and session snapshot grants',
);

assert.match(
  sidebarSource,
  /function applyLocalGrantUpdate\(event\)[\s\S]*localGrantOverrides\.value = \{[\s\S]*\[`\$\{sessionId\}:\$\{userId\}`\]: grantState/s,
  'Call Apps sidebar must apply grant-updated events without waiting for a full navigation reload',
);

assert.match(runtimeConfigSource, /['"]call-app\/grants-updated['"]/, 'frontend call-state signal allowlist must include Call App grant updates');
assert.match(signalingSource, /['"]call-app\/grants-updated['"]/, 'backend signaling allowlist must route Call App grant update signals');

assert.match(
  routeSource,
  /\/api\/call-app-sessions\/\(\[A-Za-z0-9\._:-\]\+\)\/participant-grants[\s\S]*GET[\s\S]*PATCH/s,
  'backend must expose GET/PATCH participant-grants route',
);

assert.match(
  routeSource,
  /'result' => \[[\s\S]*'session_id' => \$sessionId[\s\S]*'call_id' => \$callId[\s\S]*'default_app_policy'[\s\S]*'grants' => videochat_call_app_fetch_session_grants[\s\S]*'audit_events' => videochat_call_app_fetch_audit_events/s,
  'GET participant-grants payload must include session id, call id, default policy, grant rows, and audit trail',
);

assert.match(
  routeSource,
  /call_app_grants_forbidden[\s\S]*videochat_call_app_update_participant_grants[\s\S]*call_app_grants_changed[\s\S]*changed_grant_count[\s\S]*audit_event_count[\s\S]*retired_launch_token_count/s,
  'PATCH participant-grants must be owner/admin-only and report change, audit, and token-retirement diagnostics',
);

assert.match(
  domainSource,
  /function videochat_call_app_update_participant_grants[\s\S]*call_app_participant_grants[\s\S]*videochat_call_app_write_grant_audit_event/s,
  'backend domain must persist explicit grants and audit events',
);

assert.match(
  domainSource,
  /function videochat_call_app_normalize_grant_patch[\s\S]*in_array\(\$subjectType, \['user', 'guest'\], true\)[\s\S]*in_array\(\$grantState, \['allowed', 'denied'\], true\)[\s\S]*\$grants\[\$key\]/s,
  'grant PATCH normalization must support user/guest subjects, allow/deny states, and de-duplicate rows by subject',
);

assert.match(
  domainSource,
  /function videochat_call_app_update_participant_grants[\s\S]*contains_unknown_call_participant[\s\S]*UPDATE call_app_participant_grants[\s\S]*source = 'explicit'[\s\S]*INSERT INTO call_app_participant_grants/s,
  'grant PATCH persistence must fail closed for unknown participants and upsert explicit grant rows',
);

assert.match(
  domainSource,
  /function videochat_call_app_retire_launch_tokens_for_grant[\s\S]*grant_state[\s\S]*!== 'denied'[\s\S]*UPDATE call_app_launch_tokens[\s\S]*revoked_at/s,
  'delete/revoke grant semantics must be represented by denied grants that retire active launch tokens',
);

assert.match(
  migrationsSource,
  /CREATE TABLE IF NOT EXISTS call_app_audit_events/,
  'Call App grant audit events must have a persistent table',
);

assert.match(
  launchTokenSource,
  /function videochat_call_app_launch_capabilities[\s\S]*if \(\$grantState !== 'allowed'\)[\s\S]*call_apps\.launch[\s\S]*call_apps\.crdt\.read[\s\S]*call_apps\.crdt\.append[\s\S]*call_apps\.crdt\.replay/s,
  'launch capabilities must collapse to status-only when grants are denied and include read/write CRDT capabilities when allowed',
);

assert.match(
  crdtDomainSource,
  /function videochat_call_app_crdt_bootstrap[\s\S]*videochat_call_app_crdt_requires_allowed_grant[\s\S]*function videochat_call_app_crdt_list_ops[\s\S]*videochat_call_app_crdt_requires_allowed_grant[\s\S]*function videochat_call_app_crdt_append_op[\s\S]*videochat_call_app_crdt_requires_allowed_grant/s,
  'CRDT read, write, and replay surfaces must all re-check current participant grant state',
);

assert.match(
  crdtBridgeSource,
  /participant_grant_denied[\s\S]*call_app\.crdt\.error[\s\S]*grant_state/s,
  'frontend CRDT bridge must surface grant revocation to the iframe as a grant-state capability change',
);

assert.match(
  lifecycleTestSource,
  /participant-grants[\s\S]*non-owner participant must not update app grants[\s\S]*grant list must expose exact GET payload session and call ids[\s\S]*grant patch should persist the denied state in call_app_participant_grants[\s\S]*denied participant must not bootstrap private CRDT state[\s\S]*second participant append should admit CRDT op/s,
  'backend lifecycle contract must cover grant authorization, GET payloads, persistence, revoked read, and re-allowed write',
);

assert.match(
  sprintSource,
  /## Sprint: Whiteboard Call App Hardening And Production Integration/,
  'SPRINT.md must keep Call App grant hardening under the active Whiteboard sprint',
);

console.log('[call-app-participant-grants-contract] PASS');
