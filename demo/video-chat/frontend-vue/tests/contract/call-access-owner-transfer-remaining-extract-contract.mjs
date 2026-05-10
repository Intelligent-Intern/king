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

const evidence = readText('documentation/iam-sprint-05-owner-transfer-extraction.md');
const packageJson = JSON.parse(readText('demo/video-chat/frontend-vue/package.json'));
const ownerTransferMain = readText('demo/video-chat/frontend-vue/tests/contract/call-access-owner-transfer-main-contract.mjs');
const ownerTransferLifecycle = readText('demo/video-chat/frontend-vue/tests/contract/owner-transfer-lifecycle-contract.mjs');
const permissionChange = readText('demo/video-chat/frontend-vue/tests/contract/call-access-permission-change-active-call-contract.mjs');
const authorizedRejoin = readText('demo/video-chat/frontend-vue/tests/contract/call-access-authorized-rejoin-extract-contract.mjs');
const tempModerator = readText('demo/video-chat/frontend-vue/tests/contract/call-access-owner-transfer-temp-moderator-extract-contract.mjs');
const kickedRejoin = readText('demo/video-chat/frontend-vue/tests/contract/call-access-kicked-rejoin-denial-contract.mjs');
const auditCompatibility = readText('demo/video-chat/frontend-vue/tests/contract/call-access-audit-event-compatibility-contract.mjs');
const auditEvents = readText('demo/video-chat/backend-king-php/domain/audit/audit_events.php');
const callAccessDecision = readText('demo/video-chat/backend-king-php/domain/calls/call_access_decision.php');
const callManagement = readText('demo/video-chat/backend-king-php/domain/calls/call_management_query.php');
const orgAdminRights = readText('demo/video-chat/backend-king-php/tests/org-admin-call-rights-contract.php');
const admissionBoundaries = readText('demo/video-chat/frontend-vue/tests/contract/call-access-admission-boundaries-contract.mjs');
const socketLifecycle = readText('demo/video-chat/frontend-vue/src/domain/realtime/workspace/callWorkspace/socketLifecycle.ts');

for (const required of [
  'local/iam-e2e-owner-transfer-main-journey-proof-2',
  'codex/iam-owner-transfer-main-journey-followup',
  'local/iam-e2e-owner-transfer-rejoin-main',
  'local/iam-e2e-owner-transfer-permission-audit',
  'local/iam-e2e-org-admin-owner-transfer-policy',
  'local/iam-e2e-guest-owner-transfer-revocation',
  'local/iam-e2e-owner-transfer-lifecycle-proof-3',
  'c4781b5c2c44fad16edb185ad5cfc200b133a847',
  'ff00ed3459b4abaae99f9cf9c023399541bcf2d3',
  '9656b4e7d8a660739a01d0122851394795db5efa',
  '08c313ce8099484cddc2267a8f2a9d278b8ce0d1',
  '3ff99f1c890c015dc2d9bed98959882d5ff2e4c7',
  '276c8e9951947e8d96ba68beeb426614e3991e84',
  '32a4df25a8d9b479e4fb49888f14f10a17308951',
]) {
  assert.match(evidence, new RegExp(required.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')), `evidence must include ${required}`);
}

for (const forbiddenClaim of [
  /No product code, package scripts, shared CI wiring, `SPRINT\.md`, or\s+`BACKLOG\.md` were edited/,
  /Source\s+worktrees were inspected read-only/,
  /The broader org-admin policy branch was therefore not\s+ported/,
  /source mutation audit write was not ported here/,
]) {
  assert.match(evidence, forbiddenClaim, `evidence must preserve scope guard: ${forbiddenClaim}`);
}

const iamGate = String(packageJson.scripts?.['test:contract:iam-call-access'] || '');
for (const contractPath of [
  'call-access-owner-transfer-main-contract.mjs',
  'call-access-owner-transfer-temp-moderator-extract-contract.mjs',
  'owner-transfer-lifecycle-contract.mjs',
  'call-access-permission-change-active-call-contract.mjs',
  'call-access-audit-event-compatibility-contract.mjs',
]) {
  assert.match(iamGate, new RegExp(contractPath.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')), `IAM gate must keep ${contractPath}`);
}

assert.match(
  ownerTransferMain,
  /owner transfer must require the current owner or a system admin[\s\S]*owner transfer must update the canonical calls\.owner_user_id field[\s\S]*owner transfer must demote the previous owner participant row to participant[\s\S]*frontend owner transfer must request a room snapshot/s,
  'current owner-transfer main contract must pin owner/system-admin authority, canonical owner update, old-owner demotion, and snapshot refresh',
);
assert.match(
  ownerTransferMain,
  /lobby moderation must authorize through fresh DB-backed call context instead of stale connection roles/,
  'current owner-transfer main proof must keep lobby authority DB-backed after transfer',
);
assert.match(
  ownerTransferLifecycle,
  /old owner should rejoin as participant[\s\S]*old owner should not regain moderation on rejoin[\s\S]*new owner should rejoin as owner[\s\S]*moderator must not gain owner-transfer rights on rejoin/s,
  'current lifecycle proof must cover old-owner, new-owner, and moderator rejoin rights',
);
assert.match(
  socketLifecycle,
  /previousSocket\.close\(1000, 'reconnect'\);/,
  'current lifecycle proof must keep reconnect close semantics explicit',
);
assert.match(
  socketLifecycle,
  /socket\.send\(JSON\.stringify\(\{ type: 'room\/leave' \}\)\);[\s\S]*socket\.close\(1000, leaveRoom \? 'client_leave' : 'client_close'\);/s,
  'current lifecycle proof must keep explicit leave distinct from reconnect',
);
assert.match(
  permissionChange,
  /room snapshot permission refresh must not be implemented as browser reload or websocket reconnect[\s\S]*owner transfer button must disable immediately when owner-management authority is revoked[\s\S]*permission downgrade handling must not depend on reconnect, media, SFU, or background flows/s,
  'active permission proof must refresh owner-transfer rights without reload/media/background fallbacks',
);
assert.match(
  authorizedRejoin,
  /old owner should not regain moderation on rejoin[\s\S]*revoked temporary moderator must lose moderation on fresh context[\s\S]*revoked temporary moderator must fail the next lobby command/s,
  'authorized rejoin extraction must keep owner-transfer and temp-moderator stale-role paths fail-closed',
);
assert.match(
  tempModerator,
  /temporary moderator must not transfer ownership/,
  'temporary moderator proof must deny owner transfer for moderators',
);
assert.match(
  tempModerator,
  /owner-transfer UI must remain disabled unless the viewer has owner-management rights/,
  'temporary moderator proof must keep owner-transfer UI behind owner-management authority',
);
assert.match(
  kickedRejoin,
  /cached call-access sessions must fail once the participant row is cancelled or declined[\s\S]*direct room joins must not revive participants removed after admission/s,
  'kicked rejoin proof must keep revoked participant rows fail-closed',
);

assert.match(
  auditEvents,
  /'call_owner_transferred'\s*=>\s*'call_access_role_changed'/,
  'current audit floor must canonicalize legacy owner-transfer event aliases',
);
assert.match(
  auditCompatibility,
  /call_owner_transferred[\s\S]*call_access_role_changed[\s\S]*audit writes must canonicalize legacy IAM event aliases before sanitizing and persisting payloads/s,
  'audit compatibility proof must preserve owner-transfer alias canonicalization and sanitization',
);
assert.doesNotMatch(
  callManagement,
  /videochat_audit_record_call_owner_transferred/,
  'current mutation path must not be documented as already carrying the source owner-transfer audit write',
);

assert.match(
  callAccessDecision,
  /\$canManageOwner = \$allowed && \(\$source === 'system_admin' \|\| \$normalizedEffectiveRole === 'owner'\);/,
  'current owner-transfer main proof must keep owner-management stricter than general moderation',
);
assert.match(
  orgAdminRights,
  /org admin should not receive owner-transfer rights/,
  'current org-admin policy must not grant owner-transfer authority',
);
assert.match(
  admissionBoundaries,
  /org admin moderation must not imply owner-transfer rights/,
  'current admission boundary proof must keep org-admin moderation separate from owner transfer',
);
assert.match(
  evidence,
  /current owner and system-admin paths may transfer owner; same-org\s+organization admins may moderate their organization call but must not receive\s+owner-management rights/,
  'evidence must document why the broader org-admin owner-transfer policy was not ported',
);

process.stdout.write('[call-access-owner-transfer-remaining-extract-contract] PASS\n');
