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

const dockerProof = readText('demo/video-chat/backend-king-php/tests/call-access-guest-list-membership-docker-proof.sh');
const guestListWrapper = readText('demo/video-chat/backend-king-php/tests/call-guest-list-direct-join-contract.sh');
const membershipWrapper = readText('demo/video-chat/backend-king-php/tests/call-access-membership-removal-contract.sh');
const guestListContract = readText('demo/video-chat/backend-king-php/tests/call-guest-list-direct-join-contract.php');
const membershipContract = readText('demo/video-chat/backend-king-php/tests/call-access-membership-removal-contract.php');
const iamSqliteRuntime = readText('demo/video-chat/backend-king-php/tests/iam-call-access-sqlite-runtime-proof.sh');

assert.match(
  guestListWrapper,
  /SKIP: pdo_sqlite is not available for \$\{PHP_BIN\}/,
  'baseline guest-list wrapper still documents the host-PHP pdo_sqlite skip this proof replaces',
);
assert.match(
  membershipWrapper,
  /SKIP: pdo_sqlite is not available for \$\{PHP_BIN\}/,
  'baseline membership-removal wrapper still documents the host-PHP pdo_sqlite skip this proof replaces',
);

assert.match(
  dockerProof,
  /CONTRACTS=\([\s\S]*"call-guest-list-direct-join-contract\.sh"[\s\S]*"call-access-membership-removal-contract\.sh"[\s\S]*\)/,
  'IAM3-14 Docker proof must run exactly the guest-list direct-join and membership-removal wrappers',
);
assert.match(
  dockerProof,
  /php_has_pdo_sqlite\(\)[\s\S]*grep -qi '\^pdo_sqlite\$'/,
  'IAM3-14 Docker proof must detect whether host PHP can run sqlite proofs directly',
);
assert.match(
  dockerProof,
  /Host PHP lacks pdo_sqlite; using container fallback[\s\S]*docker-php-ext-install pdo_sqlite[\s\S]*php -m \| grep -i "\^pdo_sqlite\$"/,
  'IAM3-14 Docker proof must install and verify pdo_sqlite in the container fallback',
);
assert.match(
  dockerProof,
  /tests\/call-guest-list-direct-join-contract\.sh[\s\S]*tests\/call-access-membership-removal-contract\.sh/,
  'container fallback must execute both existing contract wrappers inside the backend test directory',
);
assert.match(
  dockerProof,
  /FAIL: \$\{PHP_BIN\} lacks pdo_sqlite and \$\{DOCKER_BIN\} is unavailable/,
  'IAM3-14 proof must fail when neither host PHP nor Docker can provide pdo_sqlite',
);
assert.match(
  dockerProof,
  /echo "\$\{LOG_PREFIX\} PASS"/,
  'IAM3-14 proof must emit a stable PASS marker after both contracts run',
);

assert.match(
  guestListContract,
  /user on guest list should be allowed to direct join[\s\S]*user not on guest list should not direct join[\s\S]*guest list from one call must not grant direct join to another call/s,
  'guest-list contract must prove direct-join scope is restricted to the target call guest list',
);
assert.match(
  membershipContract,
  /removed invited user must not authenticate through stale locally cached session role data[\s\S]*removed invited user must not retain organization resource grant through stale organization membership/s,
  'membership-removal contract must prove stale membership data cannot keep access alive',
);
assert.match(
  membershipContract,
  /tenant_membership_inactive/,
  'membership-removal contract must reconcile removed membership as tenant_membership_inactive',
);

assert.match(
  iamSqliteRuntime,
  /DEFAULT_CONTRACTS=\([\s\S]*"call-access-membership-removal-contract\.sh"[\s\S]*"call-guest-list-direct-join-contract\.sh"[\s\S]*\)/,
  'broader IAM sqlite runtime proof must continue to include both IAM3-14 contracts',
);

process.stdout.write('[call-access-guest-list-membership-docker-proof-contract] PASS\n');
