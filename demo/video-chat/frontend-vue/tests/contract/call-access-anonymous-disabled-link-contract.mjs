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

function readJson(relativePath) {
  return JSON.parse(read(relativePath));
}

const packageJson = readJson('demo/video-chat/frontend-vue/package.json');
const uiParityMatrix = readJson('demo/video-chat/contracts/v1/ui-parity-acceptance.matrix.json');
const backendProof = read('demo/video-chat/backend-king-php/tests/call-access-anonymous-disabled-link-contract.php');
const backendShell = read('demo/video-chat/backend-king-php/tests/call-access-anonymous-disabled-link-contract.sh');
const sqliteAggregate = read('demo/video-chat/backend-king-php/tests/iam-call-access-sqlite-runtime-proof.sh');
const ciGate = read('demo/video-chat/scripts/iam-call-access-ci-gate.sh');
const accessContract = read('demo/video-chat/backend-king-php/domain/calls/call_access_contract.php');
const accessLinks = read('demo/video-chat/backend-king-php/domain/calls/call_access_links.php');
const accessPublic = read('demo/video-chat/backend-king-php/domain/calls/call_access_public.php');
const accessRoutes = read('demo/video-chat/backend-king-php/http/module_calls_access.php');

const iamScript = String(packageJson.scripts?.['test:contract:iam-call-access'] || '');
assert.ok(
  iamScript.includes('node tests/contract/call-access-anonymous-disabled-link-contract.mjs'),
  'IAM call-access package gate must run the disabled anonymous link Node contract',
);
assert.ok(
  iamScript.includes('../backend-king-php/tests/call-access-anonymous-disabled-link-contract.sh'),
  'IAM call-access package gate must run the disabled anonymous link backend proof',
);
assert.match(
  ciGate,
  /STATIC_CONTRACTS=\([\s\S]*node tests\/contract\/call-access-anonymous-disabled-link-contract\.mjs/,
  'IAM static CI gate must include the disabled anonymous link Node contract',
);

const iamPaths = new Set(uiParityMatrix.commands?.['frontend:contract:iam-call-access']?.paths || []);
assert.ok(
  iamPaths.has('frontend-vue/tests/contract/call-access-anonymous-disabled-link-contract.mjs'),
  'UI parity IAM contract command must list the disabled anonymous link Node contract',
);
assert.ok(
  iamPaths.has('backend-king-php/tests/call-access-anonymous-disabled-link-contract.sh'),
  'UI parity IAM contract command must list the disabled anonymous link backend proof',
);
assert.match(
  sqliteAggregate,
  /call-access-anonymous-disabled-link-contract\.sh/,
  'SQLite IAM runtime aggregate must include the disabled anonymous link proof',
);
assert.match(
  backendShell,
  /pdo_sqlite[\s\S]*call-access-anonymous-disabled-link-contract\.php/,
  'backend shell wrapper must run the disabled anonymous SQLite proof with host-safe skip behavior',
);

assert.match(
  accessContract,
  /call_access_links\.disabled_at AS link_disabled_at[\s\S]*videochat_call_access_link_is_disabled\(\[[\s\S]*link_disabled_at[\s\S]*return \$fail\('call_access_link_invalidated'\)/,
  'active call-access sessions must revalidate disabled anonymous link state',
);
assert.match(
  accessContract,
  /function videochat_call_access_link_is_invalidated\(PDO \$pdo,\s*array \$accessLink\): bool[\s\S]*videochat_call_access_link_is_disabled\(\$accessLink\)/,
  'public invalidation predicate must include disabled links',
);
assert.match(
  accessLinks,
  /function videochat_disable_anonymous_call_access_link\([\s\S]*videochat_count_call_access_sessions_for_link[\s\S]*UPDATE call_access_links[\s\S]*SET disabled_at = :disabled_at[\s\S]*videochat_audit_record_call_access_link_disabled/,
  'anonymous link disable must persist disabled_at, count bound sessions, and audit safely',
);
assert.match(
  accessPublic,
  /videochat_call_access_link_is_invalidated\(\$pdo,\s*\$accessLink\)[\s\S]*'reason' => 'not_found'[\s\S]*'access_link' => null[\s\S]*'call' => null[\s\S]*'target_user' => null/,
  'disabled anonymous public resolve must fail closed without exposing link, call, or user payloads',
);
assert.match(
  accessRoutes,
  /\/api\/call-access\/\(\[A-Fa-f0-9-\]\{36\}\)\/join[\s\S]*call_access_not_found[\s\S]*\/api\/call-access\/\(\[A-Fa-f0-9-\]\{36\}\)\/session[\s\S]*call_access_not_found/,
  'public join and session routes must return safe not-found errors for disabled links',
);

for (const marker of [
  'forged call_id body must keep session bound to original call',
  'forged call_id body must not create a foreign-call lobby participant',
  'manipulated logged-in anonymous link must not call session issuer',
  'disabled anonymous link should expose disabled state',
  'repeat anonymous link disable should be idempotent',
  'disabled anonymous link must not create a temporary guest',
  'disabled anonymous link must not persist a call-access session',
  'disabled anonymous link must not create a lobby participant',
  'disabled anonymous HTTP join should return 404',
  'disabled anonymous HTTP session should return 404',
]) {
  assert.ok(backendProof.includes(marker), `backend proof must assert: ${marker}`);
}

assert.match(
  backendProof,
  /videochat_disable_anonymous_call_access_link\(/,
  'backend proof must invoke the domain disable helper',
);
assert.match(
  backendProof,
  /videochat_call_access_link_is_invalidated\(/,
  'backend proof must assert the invalidation predicate',
);
assert.match(
  backendProof,
  /videochat_issue_session_for_call_access\(/,
  'backend proof must cover direct session issuance denial',
);
assert.match(
  backendProof,
  /videochat_handle_call_routes\(/,
  'backend proof must cover the public HTTP routes',
);
assert.match(
  backendProof,
  /videochat_disable_anonymous_call_access_link[\s\S]*videochat_issue_session_for_call_access/,
  'backend proof must cover domain disable, invalidation predicate, session issuance, and HTTP routes',
);
assert.match(
  backendProof,
  /videochat_iam_anonymous_disabled_assert_omits[\s\S]*Disabled Anonymous Link No Lobby Secret[\s\S]*iam-anon-disabled-owner-/,
  'backend proof must assert disabled-link HTTP responses do not leak private call or owner details',
);

process.stdout.write('[call-access-anonymous-disabled-link-contract] PASS\n');
