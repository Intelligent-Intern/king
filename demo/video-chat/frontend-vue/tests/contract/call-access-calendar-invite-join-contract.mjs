import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');
const repoRoot = path.resolve(frontendRoot, '../../..');

function readText(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

function requireMatch(source, pattern, label) {
  assert.match(source, pattern, label);
}

function requireNoMatch(source, pattern, label) {
  assert.doesNotMatch(source, pattern, label);
}

function firstBlockAfter(source, marker, endMarker) {
  const start = source.indexOf(marker);
  assert.ok(start >= 0, `missing marker: ${marker}`);
  const end = source.indexOf(endMarker, start + marker.length);
  assert.ok(end > start, `missing end marker after ${marker}: ${endMarker}`);
  return source.slice(start, end);
}

function lastBlockAfter(source, marker, endMarker) {
  const start = source.lastIndexOf(marker);
  assert.ok(start >= 0, `missing marker: ${marker}`);
  const end = source.indexOf(endMarker, start + marker.length);
  assert.ok(end > start, `missing end marker after ${marker}: ${endMarker}`);
  return source.slice(start, end);
}

const appointmentBooking = readText('demo/video-chat/backend-king-php/domain/calls/appointment_calendar_booking.php');
const callAccessSession = readText('demo/video-chat/backend-king-php/domain/calls/call_access_session.php');
const callAccessLinks = readText('demo/video-chat/backend-king-php/domain/calls/call_access_links.php');
const callAccessPublic = readText('demo/video-chat/backend-king-php/domain/calls/call_access_public.php');
const callAccessRoutes = readText('demo/video-chat/backend-king-php/http/module_calls_access.php');
const tenantContext = readText('demo/video-chat/backend-king-php/support/tenant_context.php');
const joinView = readText('demo/video-chat/frontend-vue/src/domain/calls/access/JoinView.vue');
const callAccessFrontendSession = readText('demo/video-chat/frontend-vue/src/domain/calls/access/callAccessSession.ts');

requireMatch(
  appointmentBooking,
  /function videochat_book_public_appointment[\s\S]*\$callId = videochat_generate_call_id\(\);[\s\S]*\$accessId = videochat_generate_call_access_uuid\(\);/,
  'calendar booking must mint a fresh call id and access id instead of reusing calendar identifiers',
);

requireMatch(
  appointmentBooking,
  /INSERT INTO calls\([\s\S]*:id,\s*:room_id,\s*:title,\s*'invite_only'[\s\S]*:owner_user_id[\s\S]*\{\$callTenantValue\}/,
  'calendar booking must create an invite-only call scoped to the booking tenant',
);

requireMatch(
  appointmentBooking,
  /function videochat_create_calendar_invitation_guest_user[\s\S]*videochat_create_guest_user_for_call_access[\s\S]*DELETE FROM tenant_memberships[\s\S]*videochat_fetch_active_user_for_call_access\(\$pdo, \$userId, null, \$tenantId, false\)/,
  'calendar invitee must be isolated into a temporary guest account without tenant membership',
);

requireMatch(
  appointmentBooking,
  /INSERT INTO call_participants[\s\S]*':user_id' => \$temporaryUserId[\s\S]*':email' => \$bookingEmail[\s\S]*':source' => 'internal'[\s\S]*':invite_state' => 'invited'/,
  'calendar invitee must start as an internalized temporary invited participant, not as a tenant member',
);

requireMatch(
  appointmentBooking,
  /INSERT INTO call_access_links\([\s\S]*id, call_id, participant_user_id, participant_email[\s\S]*:id' => \$accessId[\s\S]*:call_id' => \$callId[\s\S]*:participant_user_id' => \$temporaryUserId[\s\S]*:participant_email' => \$bookingEmail/,
  'calendar invite join links must bind the generated access id to the generated call, temporary user, and invitee email metadata',
);

requireMatch(
  appointmentBooking,
  /INSERT INTO appointment_bookings\([\s\S]*block_id, call_id, access_id[\s\S]*:call_id' => \$callId[\s\S]*:access_id' => \$accessId/,
  'calendar booking records must reference the same call/access pair used by the join link',
);

const publicCallBlock = firstBlockAfter(appointmentBooking, '$publicCall = is_array($call) ? [', '] : null;');
for (const field of ['id', 'room_id', 'title', 'starts_at', 'ends_at', 'status']) {
  requireMatch(publicCallBlock, new RegExp(`'${field}'\\s*=>`), `calendar booking public call payload must include ${field}`);
}
for (const forbiddenField of [
  'owner',
  'owner_user_id',
  'email',
  'tenant_id',
  'calendar',
  'settings',
  'block_id',
  'access_id',
  'participant_email',
]) {
  requireNoMatch(publicCallBlock, new RegExp(`['"]${forbiddenField}['"]\\s*=>`), `calendar booking public call payload must not include ${forbiddenField}`);
}

const bookingReturnBlock = lastBlockAfter(appointmentBooking, 'return [', '];\n}');
requireMatch(
  bookingReturnBlock,
  /'booking' => \[[\s\S]*'call_id' => \$callId[\s\S]*'access_id' => \$accessId[\s\S]*'join_path' => '\/join\/' \. \$accessId/,
  'calendar booking response must expose only the call-access join path needed by the invitee',
);
requireNoMatch(
  bookingReturnBlock,
  /appointment_blocks|appointment_settings|tenant_permissions|owner_email|owner_user_id/,
  'calendar booking response must not expose private calendar internals or owner/tenant authority data',
);

requireMatch(
  callAccessSession,
  /function videochat_issue_session_for_call_access[\s\S]*\$resolve = videochat_resolve_call_access_public\(\$pdo, \$accessId\);[\s\S]*videochat_decide_call_access_for_user/,
  'call-access session issuance must resolve the access link publicly, then re-check IAM call admission',
);

requireMatch(
  callAccessSession,
  /INSERT INTO call_access_sessions\(session_id, access_id, call_id, room_id, user_id, link_kind, issued_at, expires_at\{\$bindTenantColumn\}\)[\s\S]*':access_id' => \(string\) \(\$accessLink\['id'\][\s\S]*':call_id' => \$callId[\s\S]*':room_id' => \$roomId[\s\S]*':user_id' => \$userId/,
  'issued sessions must persist an explicit call-scoped binding to the access id, call, room, and user',
);

requireMatch(
  callAccessSession,
  /videochat_get_call_for_user\([\s\S]*\(string\) \(\$call\['id'\][\s\S]*\$userId[\s\S]*\$userRole[\s\S]*\$tenantId[\s\S]*'call' => is_array\(\$freshCall\['call'\]/,
  'session response call data must be rebuilt through the admitted call-scoped user context',
);

for (const denialReason of ['forbidden', 'not_found', 'internal_error']) {
  requireMatch(
    callAccessSession,
    new RegExp(`'reason' => '${denialReason}'[\\s\\S]*'access_link' => null,[\\s\\S]*'call' => null`),
    `call-access session ${denialReason} denial must not return private access link or call data`,
  );
}

requireMatch(
  tenantContext,
  /0 AS membership_id[\s\S]*'member' AS membership_role[\s\S]*'\{\}' AS permissions_json[\s\S]*FROM call_access_sessions[\s\S]*INNER JOIN calls ON calls\.id = call_access_sessions\.call_id[\s\S]*INNER JOIN tenants ON tenants\.id = \{\$sessionTenantSelect\}/,
  'auth tenant fallback for join-link sessions must derive a least-privilege call-scoped tenant from call_access_sessions',
);

requireMatch(
  callAccessPublic,
  /function videochat_resolve_call_access_public[\s\S]*videochat_call_access_link_is_invalidated[\s\S]*'call' => null[\s\S]*videochat_fetch_active_user_for_call_access\([\s\S]*false[\s\S]*videochat_build_call_payload/,
  'public call-access resolution must fail closed on invalid links and allow explicit invite lookup without requiring tenant membership',
);

const publicSessionRoute = firstBlockAfter(
  callAccessRoutes,
  "if (preg_match('#^/api/call-access/([A-Fa-f0-9-]{36})/session$#'",
  "if (preg_match('#^/api/call-access/([A-Fa-f0-9-]{36})$#'",
);
requireMatch(
  publicSessionRoute,
  /videochat_issue_session_for_call_access[\s\S]*'state' => 'session_started'[\s\S]*'session' => \$issueResult\['session'\][\s\S]*'access_link' => \$issueResult\['access_link'\][\s\S]*'call' => \$issueResult\['call'\]/,
  'public session route must return the call-access session envelope, not calendar booking rows',
);
requireNoMatch(
  publicSessionRoute,
  /appointment|calendar|booking|block_id|owner_user_id|tenant_permissions/,
  'public session route must not expose calendar or tenant authority fields',
);

requireMatch(
  callAccessFrontendSession,
  /applySessionEnvelope\(result\.session, result\.user, result\.tenant\)[\s\S]*accessLink: result\.access_link \|\| null[\s\S]*call: result\.call \|\| null/,
  'frontend must adopt only the issued call-access session envelope and returned admitted call',
);

requireMatch(
  joinView,
  /if \(!response\.ok \|\| !payload \|\| payload\.status !== 'ok'\) \{[\s\S]*payload = payload && typeof payload === 'object'[\s\S]*\? payload[\s\S]*: \{ error: \{ code: 'call_access_validation_failed' \} \};[\s\S]*state\.contextError = localizedApiErrorMessage\(payload,\s*t\('public\.join\.resolve_failed'\)\)/,
  'public join view must preserve stable backend error codes while rendering safe localized copy',
);

requireNoMatch(
  joinView,
  /appointment|calendar|block_id|booking|owner_user_id/,
  'public join view must not render calendar internals from invite responses',
);

process.stdout.write('[call-access-calendar-invite-join-contract] PASS\n');
