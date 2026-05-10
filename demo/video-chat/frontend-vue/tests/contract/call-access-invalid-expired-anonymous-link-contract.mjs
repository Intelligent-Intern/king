import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const frontendRoot = path.resolve(__dirname, '../..');
const videoChatRoot = path.resolve(frontendRoot, '..');
const repoRoot = path.resolve(videoChatRoot, '..', '..');

function read(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

function readJson(relativePath) {
  return JSON.parse(read(relativePath));
}

const backendContract = read('demo/video-chat/backend-king-php/tests/call-access-invalid-expired-anonymous-link-contract.php');
const backendShell = read('demo/video-chat/backend-king-php/tests/call-access-invalid-expired-anonymous-link-contract.sh');
const sqliteAggregate = read('demo/video-chat/backend-king-php/tests/iam-call-access-sqlite-runtime-proof.sh');
const packageJson = readJson('demo/video-chat/frontend-vue/package.json');
const matrix = readJson('demo/video-chat/contracts/v1/ui-parity-acceptance.matrix.json');
const publicAccess = read('demo/video-chat/backend-king-php/domain/calls/call_access_public.php');
const sessionAccess = read('demo/video-chat/backend-king-php/domain/calls/call_access_session.php');
const sprint = read('SPRINT.md');
const readiness = read('READYNESS_TRACKER.md');
const evidence = read('documentation/iam7-15-invalid-expired-anonymous-link.md');

const iamScript = String(packageJson.scripts?.['test:contract:iam-call-access'] || '');
const matrixPaths = new Set(matrix.commands?.['frontend:contract:iam-call-access']?.paths || []);

assert.match(
  backendContract,
  /videochat_call_access_link_kind\(\$accessLink\) === 'open'/,
  'backend proof must force the fixture to be an anonymous/open link',
);
assert.match(
  backendContract,
  /videochat_resolve_call_access_public\(\$pdo, \$invalidAccessId\)[\s\S]*validation_failed/s,
  'backend proof must reject malformed anonymous link ids before side effects',
);
assert.match(
  backendContract,
  /videochat_resolve_call_access_public\(\$pdo, \$missingAccessId\)[\s\S]*not_found/s,
  'backend proof must reject unknown anonymous link ids as safe not-found',
);
assert.match(
  backendContract,
  /UPDATE call_access_links SET expires_at[\s\S]*videochat_resolve_call_access_public\(\$pdo, \$accessId\)[\s\S]*expired/s,
  'backend proof must reject expired anonymous links through the real resolver',
);
assert.match(
  backendContract,
  /\$expiredJoin[\s\S]*410[\s\S]*call_access_expired[\s\S]*\$expiredHttpSession[\s\S]*410[\s\S]*call_access_expired/s,
  'backend proof must cover expired anonymous HTTP join and session routes',
);
assert.match(
  backendContract,
  /\$missingJoin[\s\S]*404[\s\S]*call_access_not_found[\s\S]*\$missingHttpSession[\s\S]*404[\s\S]*call_access_not_found/s,
  'backend proof must cover missing anonymous HTTP join and session routes',
);
assert.match(
  backendContract,
  /invalid\/expired anonymous links must not create guests[\s\S]*must not create lobby or participant rows[\s\S]*must not persist call-access sessions[\s\S]*must not persist auth sessions/s,
  'backend proof must prove no guest, lobby, call-access session, or auth-session side effects',
);
assert.match(
  backendContract,
  /last_used_at[\s\S]*must not touch last_used_at[\s\S]*must not add audit events with raw reasoning/s,
  'backend proof must prove failed anonymous attempts do not touch link usage or add audit events',
);
assert.match(
  backendContract,
  /assert_no_needles[\s\S]*callTitle[\s\S]*accessId[\s\S]*expiredGuestName[\s\S]*admin@intelligent-intern\.com/s,
  'backend proof must include private call, link, guest, and owner needles for redaction checks',
);

assert.match(
  publicAccess,
  /if \(\$normalizedAccessId === ''\)[\s\S]*'access_link' => null[\s\S]*'call' => null[\s\S]*'target_user' => null[\s\S]*'participant_email' => null/s,
  'public resolver must redact malformed anonymous link ids',
);
assert.match(
  publicAccess,
  /if \(!is_array\(\$accessLink\)\)[\s\S]*'reason' => 'not_found'[\s\S]*'access_link' => null[\s\S]*'call' => null[\s\S]*'target_user' => null[\s\S]*'participant_email' => null/s,
  'public resolver must redact unknown anonymous link ids',
);
assert.match(
  publicAccess,
  /\$expiresAtUnix <= time\(\)[\s\S]*'reason' => 'expired'[\s\S]*'access_link' => null[\s\S]*'call' => null[\s\S]*'target_user' => null[\s\S]*'participant_email' => null/s,
  'public resolver must redact expired anonymous link data before call payload construction',
);
assert.match(
  sessionAccess,
  /if \(!\(bool\) \(\$resolve\['ok'\] \?\? false\)\) \{[\s\S]*'session' => null[\s\S]*'user' => null[\s\S]*'access_link' => null[\s\S]*'call' => null[\s\S]*\}/,
  'session issuer must fail closed with redacted payloads when anonymous link resolution fails',
);

assert.match(backendShell, /call-access-invalid-expired-anonymous-link-contract\.php/, 'shell wrapper must execute the backend contract');
assert.match(sqliteAggregate, /call-access-invalid-expired-anonymous-link-contract\.sh/, 'SQLite aggregate must include IAM7-15 runtime proof');
assert.match(iamScript, /node tests\/contract\/call-access-invalid-expired-anonymous-link-contract\.mjs/, 'IAM npm gate must run IAM7-15 static contract');
assert.ok(
  matrixPaths.has('frontend-vue/tests/contract/call-access-invalid-expired-anonymous-link-contract.mjs'),
  'release matrix must list IAM7-15 static contract',
);
assert.ok(
  matrixPaths.has('backend-king-php/tests/call-access-invalid-expired-anonymous-link-contract.sh'),
  'release matrix must list IAM7-15 backend wrapper',
);
assert.match(
  sprint,
  /\[x\] IAM7-15 Extract or prove invalid\/expired anonymous-link handling/,
  'SPRINT.md must mark IAM7-15 complete after focused proof verification',
);
assert.match(
  readiness,
  /IAM7-15 invalid\/expired anonymous-link extraction[\s\S]*malformed ids, unknown UUIDs, and expired anonymous links fail closed[\s\S]*No push, deploy, Background, Gossip, SFU, MediaSecurity, or BTGF/,
  'readiness log must record IAM7-15 proof and scope boundaries',
);
assert.match(
  evidence,
  /historical branch was not merged wholesale[\s\S]*focused runtime proof for open anonymous[\s\S]*no temporary guest[\s\S]*no additional audit event/s,
  'documentation must classify the historical branch and extracted IAM7-15 value',
);

process.stdout.write('[call-access-invalid-expired-anonymous-link-contract] PASS\n');
