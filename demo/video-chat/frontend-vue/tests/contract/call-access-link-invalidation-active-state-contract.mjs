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

const backendContract = read('demo/video-chat/backend-king-php/tests/call-access-invalidation-contract.php');
const backendShell = read('demo/video-chat/backend-king-php/tests/call-access-invalidation-contract.sh');
const aggregateShell = read('demo/video-chat/backend-king-php/tests/iam-call-access-sqlite-runtime-proof.sh');
const accessContract = read('demo/video-chat/backend-king-php/domain/calls/call_access_contract.php');
const accessLinks = read('demo/video-chat/backend-king-php/domain/calls/call_access_links.php');
const auditEvents = read('demo/video-chat/backend-king-php/domain/audit/audit_events.php');
const migrations = read('demo/video-chat/backend-king-php/support/database_migrations.php');
const database = read('demo/video-chat/backend-king-php/support/database.php');
const packageJson = readJson('demo/video-chat/frontend-vue/package.json');
const matrix = readJson('demo/video-chat/contracts/v1/ui-parity-acceptance.matrix.json');

assert.match(
  accessContract,
  /call_access_links\.disabled_at AS link_disabled_at[\s\S]*SELECT[\s\S]*\{\$linkDisabledAtSelect\}[\s\S]*videochat_call_access_link_is_disabled\(\[[\s\S]*link_disabled_at[\s\S]*return \$fail\('call_access_link_invalidated'\)/,
  'existing call-access sessions must revalidate disabled link state and fail closed',
);
assert.match(
  accessContract,
  /function videochat_call_access_link_is_invalidated\(PDO \$pdo,\s*array \$accessLink\): bool[\s\S]*videochat_call_access_link_is_disabled\(\$accessLink\)[\s\S]*\['cancelled',\s*'declined'\]/,
  'public link invalidation must include disabled, cancelled, and declined states',
);
assert.match(
  accessLinks,
  /function videochat_disable_anonymous_call_access_link\([\s\S]*videochat_count_call_access_sessions_for_link[\s\S]*UPDATE call_access_links[\s\S]*SET disabled_at = :disabled_at[\s\S]*videochat_audit_record_call_access_link_disabled/,
  'anonymous link disable must persist disabled_at, count active sessions, and audit safely',
);
assert.match(
  auditEvents,
  /function videochat_audit_record_call_access_link_disabled[\s\S]*'raw_link_identifier_logged' => false[\s\S]*'raw_credential_identifier_logged' => false[\s\S]*'raw_guest_identity_logged' => false/,
  'disabled link audit payload must keep raw link, credential, and guest identifiers redacted',
);
assert.match(
  migrations,
  /CREATE TABLE IF NOT EXISTS call_access_links[\s\S]*disabled_at TEXT[\s\S]*0056_call_access_link_disabled_at/,
  'SQLite schema must expose disabled_at for fresh and migrated databases',
);
assert.match(
  database,
  /'call_access_links',\s*'disabled_at',\s*'ALTER TABLE call_access_links ADD COLUMN disabled_at TEXT'/,
  'SQLite bootstrap repair must add disabled_at to existing local databases',
);

for (const marker of [
  'personal lobby A',
  'personal active A',
  'anonymous lobby A',
  'anonymous active A',
  'videochat_disable_anonymous_call_access_link',
  'call_access_participant_removed',
  'call_access_link_invalidated',
  'disabled anonymous join should return safe not-found status',
  'disabled anonymous join leaked',
]) {
  assert.ok(backendContract.includes(marker), `backend active-state proof must include ${marker}`);
}

assert.match(
  backendContract,
  /anonymous lobby disable should count both browser sessions[\s\S]*anonymous active disable should count both browser sessions/,
  'disabled anonymous link proof must cover multiple active browser/device sessions',
);
assert.match(
  backendContract,
  /videochat_call_access_invalidation_guest_count\(\$pdo\) === \$anonymousLobbyGuestCount[\s\S]*disabled anonymous lobby link must not create a replacement guest/,
  'disabled anonymous stale state must not recreate temporary guest accounts',
);
assert.match(
  backendContract,
  /raw_link_identifier_logged'[\s\S]*raw_credential_identifier_logged'[\s\S]*raw_guest_identity_logged'/,
  'backend proof must assert disabled-link audit redaction flags',
);
assert.match(
  backendShell,
  /call-access-invalidation-contract\.php/,
  'backend shell wrapper must execute the active-state invalidation contract',
);
assert.match(
  aggregateShell,
  /call-access-invalidation-contract\.sh/,
  'SQLite IAM aggregate must include the IAM7-16 runtime proof',
);

const iamScript = String(packageJson.scripts?.['test:contract:iam-call-access'] || '');
assert.ok(
  iamScript.includes('node tests/contract/call-access-link-invalidation-active-state-contract.mjs'),
  'IAM contract gate must run the IAM7-16 static contract',
);
const paths = new Set(matrix?.commands?.['frontend:contract:iam-call-access']?.paths || []);
assert.ok(
  paths.has('frontend-vue/tests/contract/call-access-link-invalidation-active-state-contract.mjs'),
  'UI parity IAM gate must list the IAM7-16 static contract',
);
assert.ok(
  paths.has('backend-king-php/tests/call-access-invalidation-contract.sh'),
  'UI parity IAM gate must list the IAM7-16 backend runtime proof',
);

process.stdout.write('[call-access-link-invalidation-active-state-contract] PASS\n');
