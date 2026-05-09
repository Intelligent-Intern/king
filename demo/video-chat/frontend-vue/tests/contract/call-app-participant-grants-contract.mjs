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
  helperSource,
  templateSource,
  rightRosterSource,
  workspaceSource,
  runtimeConfigSource,
  signalingSource,
  routeSource,
  domainSource,
  migrationsSource,
  lifecycleTestSource,
  sprintSource,
] = await Promise.all([
  read('demo/video-chat/frontend-vue/src/domain/realtime/callApps/CallAppParticipantGrantButton.vue'),
  read('demo/video-chat/frontend-vue/src/domain/realtime/callApps/callAppParticipantGrantHelpers.js'),
  read('demo/video-chat/frontend-vue/src/domain/realtime/CallWorkspaceView.template.html'),
  read('demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/RightRosterPanel.vue'),
  read('demo/video-chat/frontend-vue/src/domain/realtime/CallWorkspaceView.vue'),
  read('demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/runtimeConfig.ts'),
  read('demo/video-chat/backend-king-php/domain/realtime/realtime_signaling.php'),
  read('demo/video-chat/backend-king-php/http/module_call_apps.php'),
  read('demo/video-chat/backend-king-php/domain/call_apps/call_app_sessions.php'),
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
  /permission-action="read"[\s\S]*permission-action="write"[\s\S]*permission-action="delete"/s,
  'right roster must render real read/write/delete Call App permission actions',
);

assert.match(
  rightRosterSource,
  /supportedCallAppPermissionActions[\s\S]*hasActiveCallAppSession[\s\S]*CALL_APP_PERMISSION_ACTIONS/s,
  'right roster must enable additive read/write/delete actions for active sessions even before backend persistence is upgraded',
);

assert.match(
  buttonSource,
  /\/api\/call-app-sessions\/\$\{encodeURIComponent\(sessionId\.value\)\}\/participant-grants/,
  'grant button must update the backend participant-grants endpoint',
);

assert.match(
  buttonSource,
  /permissionAction:[\s\S]*default: 'access'[\s\S]*validator: \(value\) => \['access', 'read', 'write', 'delete'\]\.includes\(value\)/,
  'grant button must support access/read/write/delete actions',
);

assert.match(
  buttonSource,
  /buildCallAppGrantPatch\(\{[\s\S]*permissionAction: normalizedPermissionAction\.value[\s\S]*enabled: nextEnabledState\.value[\s\S]*\}\)/,
  'grant button must build additive per-permission grant patches',
);

assert.match(
  buttonSource,
  /permissions: grantPatch\.permissions[\s\S]*permission_actions: grantPatch\.permission_actions/s,
  'grant button must send additive permission fields in PATCH and realtime payloads',
);

assert.match(
  buttonSource,
  /type:\s*['"]call-app\/grants-updated['"][\s\S]*requestRoomSnapshot\(\)/,
  'grant updates must emit a realtime signal and request snapshot backfill',
);

assert.match(
  buttonSource,
  /defineEmits\(\[['"]grant-updated['"]\]\)[\s\S]*emit\(['"]grant-updated['"][\s\S]*sessionId:\s*sessionId\.value[\s\S]*userId:\s*rowUserId\.value[\s\S]*grantState[\s\S]*permissions[\s\S]*permissionActions/s,
  'grant button must emit local grant updates with permission details for sidebar state labels',
);

assert.match(
  helperSource,
  /CALL_APP_PERMISSION_ACTIONS = Object\.freeze\(\['read', 'write', 'delete'\]\)/,
  'grant helper must define the read/write/delete permission action set',
);

assert.match(
  helperSource,
  /grant_state: grantState[\s\S]*permissions,[\s\S]*permission_actions: permissionActions/s,
  'grant helper must preserve compatible grant_state while adding permissions and permission_actions',
);

assert.doesNotMatch(
  buttonSource,
  /sessionToken|Authorization|localStorage/,
  'grant button must not expose primary auth material',
);

assert.match(runtimeConfigSource, /['"]call-app\/grants-updated['"]/, 'frontend call-state signal allowlist must include Call App grant updates');
assert.match(signalingSource, /['"]call-app\/grants-updated['"]/, 'backend signaling allowlist must route Call App grant update signals');

assert.match(
  routeSource,
  /\/api\/call-app-sessions\/\(\[A-Za-z0-9\._:-\]\+\)\/participant-grants[\s\S]*GET[\s\S]*PATCH/s,
  'backend must expose GET/PATCH participant-grants route',
);

assert.match(
  domainSource,
  /function videochat_call_app_update_participant_grants[\s\S]*call_app_participant_grants[\s\S]*videochat_call_app_write_grant_audit_event/s,
  'backend domain must persist explicit grants and audit events',
);

assert.match(
  migrationsSource,
  /CREATE TABLE IF NOT EXISTS call_app_audit_events/,
  'Call App grant audit events must have a persistent table',
);

assert.match(
  lifecycleTestSource,
  /participant-grants[\s\S]*non-owner participant must not update app grants[\s\S]*grant patch should create one audit event/s,
  'backend lifecycle contract must cover grant authorization and audit persistence',
);

assert.match(
  sprintSource,
  /## Sprint: Whiteboard Call App Hardening And Production Integration/,
  'SPRINT.md must keep Call App grant hardening under the active Whiteboard sprint',
);

console.log('[call-app-participant-grants-contract] PASS');
