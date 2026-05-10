import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');
const repoRoot = path.resolve(frontendRoot, '../../..');

function read(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

function exists(relativePath) {
  return fs.existsSync(path.join(repoRoot, relativePath));
}

function requireIncludes(source, needle, message) {
  assert.ok(source.includes(needle), message);
}

function requireMatch(source, pattern, message) {
  assert.match(source, pattern, message);
}

const evidence = read('documentation/iam-sprint-05-owner-absence-extraction.md');
const terminalStates = read('demo/video-chat/frontend-vue/tests/contract/call-access-terminal-states-contract.mjs');
const inviteInvalidationTerminal = read('demo/video-chat/frontend-vue/tests/contract/call-access-invite-invalidation-terminal-contract.mjs');
const terminalJoinBackend = read('demo/video-chat/backend-king-php/tests/call-access-terminal-join-contract.php');
const ownerTransferLifecycle = read('demo/video-chat/frontend-vue/tests/contract/owner-transfer-lifecycle-contract.mjs');
const ownerTransferMain = read('demo/video-chat/frontend-vue/tests/contract/call-access-owner-transfer-main-contract.mjs');
const activePermissionChange = read('demo/video-chat/frontend-vue/tests/contract/call-access-permission-change-active-call-contract.mjs');
const auditCompatibility = read('demo/video-chat/frontend-vue/tests/contract/call-access-audit-event-compatibility-contract.mjs');
const auditRedaction = read('demo/video-chat/frontend-vue/tests/contract/call-access-audit-redaction-contract.mjs');

for (const branch of [
  'local/iam-e2e-king-participants-owner-timeout',
  'local/iam-e2e-owner-absence-browser',
  'local/iam-e2e-owner-absence-countdown-proof',
  'local/iam-e2e-owner-absence-realtime-sync',
  'local/iam-e2e-owner-leave-explicit-end-proof',
  'local/iam-e2e-owner-timeout-open-link-proof',
  'local/iam-owner-timeout-anonymous-link-proof',
]) {
  requireIncludes(evidence, branch, `evidence must classify ${branch}`);
}

for (const section of [
  '## Extracted Owner Absence Value',
  '## Extracted Owner Timeout Value',
  '## Extracted Owner-Leave Value',
  '## Current Maintained Coverage',
  '## Non-Ports',
]) {
  requireIncludes(evidence, section, `evidence must include ${section}`);
}

for (const portedPath of [
  'demo/video-chat/backend-king-php/domain/realtime/realtime_owner_absence.php',
  'demo/video-chat/backend-king-php/tests/call-access-owner-absence-realtime-sync-contract.php',
]) {
  assert.equal(exists(portedPath), true, `IAM7-20 must keep owner-absence backend proof path ported: ${portedPath}`);
}

for (const missingPath of [
  'demo/video-chat/frontend-vue/src/domain/realtime/OwnerAbsenceCountdownBanner.vue',
  'demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/ownerAbsenceState.js',
]) {
  assert.equal(exists(missingPath), false, `current base must not claim unported owner-absence file exists: ${missingPath}`);
  requireIncludes(evidence, missingPath, `evidence must record missing owner-absence source ${missingPath}`);
}

for (const phrase of [
  '15 minutes total owner absence',
  'occupies the final five minutes',
  'owner return before the deadline cancels monitoring/countdown',
  'stale owner heartbeat expiry is materialized',
  'room snapshots include `call_lifecycle.owner_absence`',
  'ended_reason: owner_absent_timeout',
  'anonymous, and open call-access links',
  'ended_reason: owner_explicit_end',
  'No separate local branch matching `*owner*timeout*audit*` was found.',
  'IAM7-20 follow-up ports the backend owner-absence realtime runtime',
]) {
  requireIncludes(evidence, phrase, `evidence must preserve extracted proof value: ${phrase}`);
}

requireMatch(
  terminalStates,
  /direct_join_system_admin_alpha_ended_denied[\s\S]*direct_join_alpha_owner_alpha_disabled_denied[\s\S]*direct_join_alpha_owner_alpha_deleted_hidden/,
  'terminal-state proof must keep ended, disabled, and deleted call rows fail-closed',
);
requireMatch(
  terminalStates,
  /private_call_payload_forbidden[\s\S]*resolvePayload\.result\?\.call \?\? null[\s\S]*callFetchPayload\.call \?\? null/,
  'terminal-state proof must redact private call payloads',
);
requireMatch(
  inviteInvalidationTerminal,
  /ended or disabled calls must produce terminal conflict states with no call payload[\s\S]*public session route must map ended or disabled calls to a terminal conflict state/,
  'invite invalidation proof must keep ended public join and session routes terminal',
);
requireMatch(
  inviteInvalidationTerminal,
  /invalidated personalized link must not create a fresh session[\s\S]*must not leak invited email[\s\S]*must not leak call id/,
  'invite invalidation proof must deny invalidated links without leaking invite data',
);
requireMatch(
  terminalJoinBackend,
  /ended personal public resolve[\s\S]*ended open public resolve[\s\S]*ended authenticated personalized resolve[\s\S]*ended open session issue[\s\S]*ended open session must not include session/,
  'backend terminal join proof must cover ended personal/open joins and session denial',
);

requireMatch(
  ownerTransferLifecycle,
  /socket reconnect must not be treated as a room leave[\s\S]*explicit leave must remain distinct from reconnect lifecycle/,
  'owner lifecycle proof must distinguish reconnect from explicit room leave',
);
requireMatch(
  ownerTransferLifecycle,
  /old owner should rejoin as participant[\s\S]*old owner should not regain moderation on rejoin[\s\S]*new owner should rejoin as owner/,
  'owner lifecycle proof must recompute owner authority after leave and rejoin',
);
requireMatch(
  ownerTransferMain,
  /owner transfer must update the canonical calls\.owner_user_id field[\s\S]*old owner should lose call moderation controls[\s\S]*new owner should moderate after transfer/,
  'owner transfer main proof must keep canonical owner authority fresh',
);
requireMatch(
  activePermissionChange,
  /backend realtime role context must recompute active-call owner, moderator, admin, and org-admin authority from current DB rows[\s\S]*room snapshot permission refresh must not be implemented as browser reload or websocket reconnect/,
  'active-call permission proof must keep realtime authority server-driven',
);

requireMatch(
  auditCompatibility,
  /call_owner_transferred[\s\S]*call_access_role_changed[\s\S]*call_access_allowed[\s\S]*call_access_admitted/,
  'audit compatibility proof must preserve owner/call-access IAM aliases',
);
requireMatch(
  auditRedaction,
  /audit sanitizer must cover raw call-access ids, session ids, and tokens[\s\S]*audit events must not leak sensitive text/,
  'audit redaction proof must keep terminal/timeout audit payloads sanitized',
);
requireIncludes(
  evidence,
  'preserve a terminal `call_ended` audit event',
  'owner-timeout extraction must preserve source audit value without porting audit runtime',
);

process.stdout.write('[call-access-owner-absence-extract-contract] PASS\n');
