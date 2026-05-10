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

const evidence = read('documentation/iam-sprint-05-lobby-admission-extraction.md');
const packageJson = JSON.parse(read('demo/video-chat/frontend-vue/package.json'));
const concurrencyStatic = read('demo/video-chat/frontend-vue/tests/contract/call-access-lobby-concurrency-contract.mjs');
const concurrencyBackend = read('demo/video-chat/backend-king-php/tests/realtime-lobby-concurrency-contract.php');
const admissionBoundaries = read('demo/video-chat/frontend-vue/tests/contract/call-access-admission-boundaries-contract.mjs');
const lobbySecurity = read('demo/video-chat/backend-king-php/tests/realtime-lobby-security-contract.php');
const lobbyContract = read('demo/video-chat/backend-king-php/tests/realtime-lobby-contract.php');
const lobbyDbSync = read('demo/video-chat/backend-king-php/tests/realtime-lobby-db-sync-contract.php');
const auditCompatibility = read('demo/video-chat/frontend-vue/tests/contract/call-access-audit-event-compatibility-contract.mjs');
const auditRedaction = read('demo/video-chat/frontend-vue/tests/contract/call-access-audit-redaction-contract.mjs');

for (const branch of [
  'local/iam-e2e-lobby-timeout-consistency-proof-2',
  'codex/iam-lobby-timeout-consistency-followup',
  'codex/iam-lobby-timeout-consistency-followup-20260509',
  'local/iam-e2e-lobby-concurrency-remaining',
  'local/iam-e2e-lobby-admission-main',
  'local/iam-e2e-lobby-audit-entry-admission-rejection',
  'local/iam-e2e-lobby-audit-events',
  'codex/iam-e2e-lobby-audit-ci-scan-20260509',
  'codex/iam-lobby-audit-cleanup-followup-20260509',
]) {
  requireIncludes(evidence, branch, `evidence must classify ${branch}`);
}

for (const section of [
  '## Timeout Consistency',
  '## Concurrency Races',
  '## Admission And Rejection Boundaries',
  '## Audit Entries',
  '## Extraction Decision',
]) {
  requireIncludes(evidence, section, `evidence must include ${section}`);
}

assert.equal(
  exists('demo/video-chat/backend-king-php/http/module_realtime_lobby_persistence.php'),
  false,
  'current base should not accidentally claim the unported timeout persistence module exists',
);
assert.equal(
  exists('demo/video-chat/backend-king-php/domain/audit/audit_lobby_events.php'),
  false,
  'current base should not accidentally claim the unported lobby audit domain exists',
);
requireIncludes(
  evidence,
  'The three timeout branches carry identical core timeout files.',
  'timeout extraction must record duplicate timeout branch proof shape',
);
requireIncludes(
  evidence,
  'Current base does not contain',
  'evidence must explicitly call out missing timeout/audit backend files',
);
requireIncludes(
  evidence,
  'not ported here',
  'evidence must state why backend-only timeout/audit value was not ported in this scope',
);

requireMatch(
  concurrencyBackend,
  /concurrent allow should create one admitted handoff[\s\S]*late duplicate allow should be idempotent[\s\S]*admit-then-reject should leave no admitted handoff[\s\S]*reject-then-stale-admit should leave no admitted handoff/s,
  'backend concurrency proof must pin idempotent admission and reject-wins races',
);
requireMatch(
  concurrencyStatic,
  /backendConcurrency[\s\S]*realtime-lobby-concurrency-contract\.php[\s\S]*concurrent allow should create one admitted handoff/s,
  'frontend static concurrency proof must bind the backend lobby concurrency contract',
);
requireIncludes(
  packageJson.scripts?.['test:contract:iam-call-access'] || '',
  'node tests/contract/call-access-lobby-concurrency-contract.mjs',
  'current IAM contract script must run the active lobby concurrency proof',
);

requireMatch(
  admissionBoundaries,
  /system admin must manage lobby admission[\s\S]*system admin must admit participants[\s\S]*system admin must reject participants/,
  'admission boundary contract must pin system-admin lobby authority',
);
requireMatch(
  admissionBoundaries,
  /lobby moderation must reload the server role from the database[\s\S]*lobby moderation authorization must bind to realtime call context and require can_moderate/,
  'admission boundary contract must pin DB-backed lobby moderation authority',
);
requireMatch(
  lobbySecurity,
  /forged role\/call_role must not authorize lobby moderation[\s\S]*owner of another call must not moderate this room lobby[\s\S]*forged call id must be rebound to target room context/s,
  'backend lobby security proof must reject forged roles and cross-room authority',
);
requireMatch(
  lobbyContract,
  /non-moderator lobby\/allow must fail[\s\S]*moderator lobby\/allow should succeed[\s\S]*moderator remove should succeed[\s\S]*invalid target_user_id should fail/s,
  'backend lobby contract must pin allow/remove rejection boundaries',
);
requireMatch(
  lobbyDbSync,
  /remove should reset DB participant to invited[\s\S]*queue join must not demote an already allowed participant back to pending/s,
  'backend DB sync contract must pin reject/remove and stale allowed boundaries',
);

requireMatch(
  auditCompatibility,
  /call_access_rejected: 'call_access_denied'[\s\S]*call_access_allowed: 'call_access_admitted'/,
  'current audit compatibility proof must keep call-access admission aliases stable',
);
requireMatch(
  auditRedaction,
  /audit sanitizer must cover raw call-access ids, session ids, and tokens[\s\S]*audit events must not leak sensitive text/s,
  'current audit redaction proof must keep sensitive identifiers out of audit payloads',
);
requireMatch(
  evidence,
  /Current base\s+does contain stable call-access audit compatibility and redaction contracts/,
  'evidence must distinguish current audit coverage from missing lobby-specific audit rows',
);
requireIncludes(
  evidence,
  'not lobby-specific admission/rejection event rows',
  'evidence must preserve the lobby audit gap as a backend follow-up',
);

process.stdout.write('[call-access-lobby-admission-extract-contract] PASS\n');
