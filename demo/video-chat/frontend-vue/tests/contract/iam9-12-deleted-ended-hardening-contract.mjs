import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');
const repoRoot = path.resolve(frontendRoot, '../../..');

function readRepo(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

const phpContract = readRepo('demo/video-chat/backend-king-php/tests/call-access-deleted-ended-hardening-contract.php');
const shellContract = readRepo('demo/video-chat/backend-king-php/tests/call-access-deleted-ended-hardening-contract.sh');
const sqliteGate = readRepo('demo/video-chat/backend-king-php/tests/iam-call-access-sqlite-runtime-proof.sh');
const packageJson = JSON.parse(readRepo('demo/video-chat/frontend-vue/package.json'));
const ciGate = readRepo('demo/video-chat/scripts/iam-call-access-ci-gate.sh');

assert.match(
  phpContract,
  /videochat_iam9_12_assert_org_admin_denied_after_terminal_state[\s\S]*'deleted'[\s\S]*'ended'/,
  'IAM9-12 proof must cover deleted and ended terminal transitions',
);
assert.match(
  phpContract,
  /organization_admin[\s\S]*must not bypass terminal call state/,
  'IAM9-12 proof must preserve the extracted organization-admin bypass hardening',
);
assert.match(
  phpContract,
  /videochat_decide_call_access_for_user[\s\S]*source[\s\S]*none[\s\S]*reason[\s\S]*not_found[\s\S]*conflict/,
  'IAM9-12 proof must pin deleted as not_found and ended as conflict at the direct decision layer',
);
assert.match(
  phpContract,
  /resolve must redact call payload[\s\S]*assert_body_omits/,
  'IAM9-12 proof must require redacted terminal route payloads',
);
assert.match(
  shellContract,
  /call-access-deleted-ended-hardening-contract\.php/,
  'IAM9-12 shell wrapper must invoke the backend PHP contract',
);
assert.match(
  sqliteGate,
  /call-access-deleted-ended-hardening-contract\.sh/,
  'IAM9-12 backend proof must be wired into the SQLite IAM runtime gate',
);
assert.match(
  packageJson.scripts['test:contract:iam-call-access'],
  /iam9-12-deleted-ended-hardening-contract\.mjs/,
  'IAM9-12 Node proof must be wired into the canonical IAM package gate',
);
assert.match(
  packageJson.scripts['test:contract:iam-call-access'],
  /call-access-deleted-ended-hardening-contract\.sh/,
  'IAM9-12 backend proof must be reachable from the canonical IAM package gate',
);
assert.match(
  ciGate,
  /iam9-12-deleted-ended-hardening-contract\.mjs/,
  'IAM9-12 static gate must include the focused Node proof',
);

process.stdout.write('[iam9-12-deleted-ended-hardening-contract] PASS\n');
