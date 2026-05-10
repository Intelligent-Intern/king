import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';

const root = path.resolve(new URL('../..', import.meta.url).pathname);
const repoRoot = path.resolve(root, '../../..');

async function read(relativePath) {
  return readFile(path.join(repoRoot, relativePath), 'utf8');
}

function requireMatch(source, pattern, label) {
  assert.match(source, pattern, label);
}

function routeBlock(source, routePattern) {
  const match = source.match(routePattern);
  assert.ok(match, `route block must exist: ${routePattern}`);
  return match[0];
}

const [
  callSubjectSource,
  sessionsSource,
  launchTokenSource,
  crdtSource,
  routeSource,
  migrationSource,
  whiteboardSource,
] = await Promise.all([
  read('demo/video-chat/backend-king-php/domain/call_apps/call_app_call_subjects.php'),
  read('demo/video-chat/backend-king-php/domain/call_apps/call_app_sessions.php'),
  read('demo/video-chat/backend-king-php/domain/call_apps/call_app_launch_tokens.php'),
  read('demo/video-chat/backend-king-php/domain/call_apps/call_app_crdt.php'),
  read('demo/video-chat/backend-king-php/http/module_call_apps.php'),
  read('demo/video-chat/backend-king-php/support/database_migrations.php'),
  read('demo/call-app/whiteboard/public/whiteboard.js'),
]);

requireMatch(
  sessionsSource,
  /require_once __DIR__ \. '\/call_app_call_subjects\.php'[\s\S]*videochat_call_app_active_call_subjects\(\$pdo, \$callId\)/,
  'Call App sessions must seed grants through the shared IAM call-subject helper',
);

requireMatch(
  callSubjectSource,
  /function videochat_call_app_active_call_subjects[\s\S]*cp\.user_id IS NOT NULL AND cp\.source = 'internal'[\s\S]*OR cp\.user_id IS NULL/,
  'Call App default grant seeding must include only internal registered users and registered guest subjects',
);

requireMatch(
  callSubjectSource,
  /function videochat_call_app_joinable_call_sql[\s\S]*status IN \('scheduled', 'active'\)/,
  'Call App call-subject checks must reject terminal deleted, ended, disabled, or cancelled calls',
);

requireMatch(
  callSubjectSource,
  /function videochat_call_app_active_participant_sql[\s\S]*invite_state IN \('allowed', 'accepted'\)[\s\S]*left_at IS NULL[\s\S]*trim\([^)]*left_at\) = ''/,
  'Call App call-subject checks must count only admitted participants that have not left',
);

requireMatch(
  callSubjectSource,
  /function videochat_call_app_grant_subject_in_call[\s\S]*videochat_call_app_joinable_call_sql\('calls'\)[\s\S]*videochat_call_app_active_participant_sql\('cp'\)[\s\S]*cp\.source = 'internal'/,
  'registered user Call App admission must require an internal active participant row or current owner on a joinable call',
);

requireMatch(
  callSubjectSource,
  /function videochat_call_app_grant_subject_in_call[\s\S]*cp\.user_id IS NULL[\s\S]*videochat_call_app_session_guest_id[\s\S]*return true/s,
  'registered guest Call App admission must resolve guest ids only from active guest participant rows',
);

requireMatch(
  launchTokenSource,
  /function videochat_call_app_mint_launch_token[\s\S]*videochat_call_app_grant_subject_in_call[\s\S]*participant_not_in_call[\s\S]*videochat_call_app_launch_subject_grant/s,
  'launch token minting must check current IAM call admission before issuing a token',
);

requireMatch(
  launchTokenSource,
  /function videochat_call_app_validate_launch_token[\s\S]*videochat_call_app_grant_subject_in_call[\s\S]*participant_not_in_call[\s\S]*videochat_call_app_launch_subject_grant/s,
  'launch token validation must re-check IAM call admission so removed participants lose reconnect access',
);

requireMatch(
  launchTokenSource,
  /function videochat_call_app_launch_source_session_active[\s\S]*sessions\.revoked_at[\s\S]*users\.status AS user_status[\s\S]*auth_revoked/s,
  'Call App launch tokens must revalidate the bound browser session and disabled user state',
);

requireMatch(
  launchTokenSource,
  /function videochat_call_app_mint_launch_token[\s\S]*source_session_id[\s\S]*videochat_call_app_launch_source_session_active/s,
  'Call App launch token minting must bind tokens to the current authorized session when available',
);

requireMatch(
  launchTokenSource,
  /function videochat_call_app_validate_launch_token[\s\S]*source_session_id[\s\S]*videochat_call_app_retire_launch_token_row[\s\S]*auth_revoked/s,
  'Call App launch token validation must retire tokens after source session revocation or user disablement',
);

requireMatch(
  migrationSource,
  /0057_call_app_launch_token_session_binding[\s\S]*videochat_call_app_launch_token_session_binding_migration_statements/,
  'database migrations must persist Call App launch-token source session bindings',
);

requireMatch(
  routeSource,
  /videochat_call_app_mint_launch_token[\s\S]*\['session'\][\s\S]*\['expires_at'\][\s\S]*\['session'\][\s\S]*\['id'\]/,
  'Call App launch-token route must bind tokens only to authenticated session contexts from session validation',
);

requireMatch(
  crdtSource,
  /function videochat_call_app_crdt_session_for_actor[\s\S]*videochat_call_app_grant_subject_in_call[\s\S]*participant_not_in_call[\s\S]*videochat_call_app_fetch_session/s,
  'CRDT bootstrap, replay, append, and snapshot must resolve actor admission before private state lookup',
);

for (const [label, routePattern] of [
  ['availability', /\/api\/calls\/\(\[A-Za-z0-9\._-\]\{1,200\}\)\/call-apps\/available[\s\S]*?return \$jsonResponse\(200,/],
  ['session collection', /\/api\/calls\/\(\[A-Za-z0-9\._-\]\{1,200\}\)\/call-app-sessions[\s\S]*?return \$jsonResponse\(\(string\) \(\$result\['state'\]/],
  ['participant grants', /\/api\/call-app-sessions\/\(\[A-Za-z0-9\._:-\]\+\)\/participant-grants[\s\S]*?call_app_grants_changed/],
  ['launch token mint', /\/api\/call-app-sessions\/\(\[A-Za-z0-9\._:-\]\+\)\/launch-token\$[\s\S]*?return \$jsonResponse\(201,/],
]) {
  const block = routeBlock(routeSource, routePattern);
  assert.match(block, /videochat_get_call_for_user[\s\S]*videochat_call_app_module_call_response_error/, `${label} route must use IAM call resolution before Call App data`);
}

for (const [label, routePattern] of [
  ['CRDT bootstrap', /call_app_crdt_bootstrap_failed[\s\S]*?return \$jsonResponse\(200,/],
  ['CRDT ops', /call_app_crdt_ops_failed[\s\S]*?return \$jsonResponse\(\$method === 'POST'/],
  ['CRDT snapshot', /call_app_crdt_snapshot_failed[\s\S]*?return \$jsonResponse\(200,/],
  ['launch token validate', /call_app_launch_token_validation_failed[\s\S]*?return \$jsonResponse\(200,/],
  ['launch token mint', /call_app_launch_token_failed[\s\S]*?return \$jsonResponse\(201,/],
]) {
  const block = routeBlock(routeSource, routePattern);
  assert.match(block, /participant_not_in_call[\s\S]*\? 403/, `${label} route must translate IAM removal to HTTP 403`);
}

requireMatch(
  whiteboardSource,
  /function requestBootstrap\(afterClock = 0\)[\s\S]*if \(!canRead\(\)\) return/,
  'whiteboard runtime must not request private CRDT state without read access',
);

requireMatch(
  whiteboardSource,
  /function applyAccessState[\s\S]*clearInterval\(pollTimer\)[\s\S]*call_app\.crdt\.error[\s\S]*participant_grant_denied/,
  'whiteboard runtime must stop polling and editing after backend revocation',
);

console.log('[call-access-callapp-revocation-contract] PASS');
