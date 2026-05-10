import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');
const videoChatRoot = path.resolve(frontendRoot, '..');
const repoRoot = path.resolve(videoChatRoot, '../..');

function readText(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

const callAccessEntry = readText('demo/video-chat/backend-king-php/domain/calls/call_access.php');
const calendarGuards = readText('demo/video-chat/backend-king-php/domain/calls/call_access_calendar_guards.php');
const callAccessContract = readText('demo/video-chat/backend-king-php/domain/calls/call_access_contract.php');
const calendarFlowContract = readText('demo/video-chat/backend-king-php/tests/call-calendar-invitation-flow-contract.php');
const sqliteProof = readText('demo/video-chat/backend-king-php/tests/iam-call-access-sqlite-runtime-proof.sh');
const iamStaticGate = readText('demo/video-chat/scripts/iam-call-access-ci-gate.sh');

assert.match(
  callAccessEntry,
  /require_once __DIR__ \. '\/call_access_calendar_guards\.php';/,
  'call-access runtime must load the calendar invalidation guard',
);
assert.match(
  calendarGuards,
  /function videochat_call_access_calendar_link_is_invalidated\(PDO \$pdo, array \$accessLink\): bool/,
  'calendar guard must expose the focused invalidation predicate',
);
assert.match(
  calendarGuards,
  /videochat_tenant_table_has_column\(\$pdo, 'appointment_bookings', 'access_id'\)/,
  'calendar guard must be schema-safe for tenants without appointment access ids',
);
assert.match(
  calendarGuards,
  /WHERE access_id = :access_id/,
  'calendar guard must resolve the booking by access id',
);
assert.match(
  calendarGuards,
  /\$bookingStatus !== 'booked'[\s\S]*return true;/,
  'non-booked calendar invitations must invalidate their access link',
);
assert.match(
  calendarGuards,
  /return \$bookingCallId !== '' && \$linkCallId !== '' && !hash_equals\(\$bookingCallId, \$linkCallId\);/,
  'booking/call mismatches must invalidate their access link without loose comparison',
);
assert.match(
  callAccessContract,
  /function videochat_validate_call_access_session_binding[\s\S]*videochat_call_access_calendar_link_is_invalidated\(\$pdo,[\s\S]*return \$fail\('call_access_link_invalidated'\);/s,
  'existing call-access session bindings must close when the calendar booking invalidates the link',
);
assert.match(
  callAccessContract,
  /function videochat_call_access_link_is_invalidated\(PDO \$pdo, array \$accessLink\): bool[\s\S]*videochat_call_access_calendar_link_is_invalidated\(\$pdo, \$accessLink\)[\s\S]*return true;[\s\S]*videochat_call_access_participant_invite_state/s,
  'fresh public/session resolution must apply calendar invalidation before participant invite state fallback',
);
assert.match(
  calendarFlowContract,
  /function videochat_calendar_invitation_flow_assert_stale_link_closed/,
  'calendar invitation runtime contract must include stale-link safe-state assertions',
);
assert.match(
  calendarFlowContract,
  /videochat_resolve_call_access_public\(\$pdo, \$accessId\)[\s\S]*\(\$resolution\['reason'\] \?\? ''\) === 'not_found'/s,
  'stale calendar invitation public resolution must fail closed as not_found',
);
assert.match(
  calendarFlowContract,
  /videochat_issue_session_for_call_access\([\s\S]*\(\$session\['reason'\] \?\? ''\) === 'not_found'[\s\S]*must not persist session/s,
  'stale calendar invitation session issuance must fail closed and persist no session',
);
assert.match(
  calendarFlowContract,
  /json_encode\(\[\$resolution, \$session\][\s\S]*!str_contains\(\$encoded, \$text\)/s,
  'stale calendar invitation denial payloads must omit private invite details',
);
assert.match(
  calendarFlowContract,
  /registered logged-out booking must not bind the access link to the existing account/,
  'registered logged-out calendar bookings must remain guest scoped',
);
assert.match(
  calendarFlowContract,
  /reopening a calendar link must not create another temporary account/,
  'reopening a valid calendar invitation must reuse the bound temporary guest',
);
assert.match(
  calendarFlowContract,
  /UPDATE appointment_bookings SET status = 'cancelled'[\s\S]*cancelled calendar appointment link/s,
  'cancelled appointment bookings must be covered as stale calendar invitation links',
);
assert.match(
  calendarFlowContract,
  /UPDATE appointment_bookings SET call_id = :call_id[\s\S]*stale binding reason should be invalidated[\s\S]*personalized link bound to another appointment call/s,
  'booking/call mismatches must close existing bindings and fresh public/session opens',
);
assert.match(
  sqliteProof,
  /call-calendar-invitation-flow-contract\.sh/,
  'IAM SQLite runtime proof must keep the calendar invitation flow contract in scope',
);
assert.match(
  iamStaticGate,
  /iam9-02-calendar-edge-safe-states-contract\.mjs/,
  'IAM static gate must include the IAM9-02 calendar edge safe states proof',
);

process.stdout.write('[iam9-02-calendar-edge-safe-states-contract] PASS\n');
