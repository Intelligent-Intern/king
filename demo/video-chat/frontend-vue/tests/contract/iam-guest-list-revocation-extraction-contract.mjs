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

function readJson(relativePath) {
  return JSON.parse(readText(relativePath));
}

const evidence = readText('documentation/iam-sprint-04-guest-list-revocation-extraction.md');
const packageJson = readJson('demo/video-chat/frontend-vue/package.json');
const ciWire = readText('demo/video-chat/frontend-vue/tests/contract/iam-call-access-ci-wire-contract.mjs');
const matrix = readJson('demo/video-chat/contracts/v1/ui-parity-acceptance.matrix.json');
const removedMembersContract = readText('demo/video-chat/frontend-vue/tests/contract/call-access-removed-members-contract.mjs');
const guestListContract = readText('demo/video-chat/backend-king-php/tests/call-guest-list-direct-join-contract.php');
const dockerProofContract = readText('demo/video-chat/frontend-vue/tests/contract/call-access-guest-list-membership-docker-proof-contract.mjs');

assert.match(
  evidence,
  /Recommendation: `superseded\/documentation-only`/,
  'guest-list revocation extraction must be classified as superseded documentation-only',
);
assert.match(
  evidence,
  /was not\s+deleted, reset, rebased, checked out, cleaned, or modified/,
  'evidence must document non-destructive handling of the source worktree',
);
assert.match(
  evidence,
  /276c8e9951947e8d96ba68beeb426614e3991e84/,
  'evidence must record the inspected source HEAD',
);

for (const invariant of [
  /direct guest-list call visibility/,
  /stale personalized call-access links/,
  /stale call-scoped sessions/,
  /lobby\/realtime rejoin paths/,
]) {
  assert.match(evidence, invariant, `evidence must name guest-list revocation invariant ${invariant}`);
}

assert.match(
  removedMembersContract,
  /removed invited user must have no active alpha membership[\s\S]*removed invited user must not be on the alpha direct guest list[\s\S]*removed invited user must not directly see the org call/,
  'removed-members proof must cover removed guest-list member losing direct call visibility',
);
assert.match(
  removedMembersContract,
  /cancelled invited user link must hide call-access data[\s\S]*declined invited user link must hide call-access data[\s\S]*cancelled invited user must lose direct guest-list call access[\s\S]*declined invited user must lose direct guest-list call access/,
  'removed-members proof must cover cancelled and declined invite revocation',
);
assert.match(
  removedMembersContract,
  /session issuance must inherit safe invalidated-link denial before minting a call-scoped session/,
  'removed-members proof must cover stale call-scoped session prevention',
);
assert.match(
  removedMembersContract,
  /frontend must not open lobby visibility unless call-access session issuance succeeds/,
  'removed-members proof must cover lobby visibility after denial',
);
assert.match(
  guestListContract,
  /declined guest-list entry must not direct join[\s\S]*guest_list_entry_inactive/,
  'backend direct-join proof must fail inactive guest-list entries closed',
);
assert.match(
  guestListContract,
  /guest list from one call must not grant direct join to another call[\s\S]*guest list must not cross tenant call lookup/,
  'backend direct-join proof must keep guest-list access call-scoped and tenant-scoped',
);
assert.match(
  dockerProofContract,
  /guest-list contract must prove direct-join scope is restricted to the target call guest list[\s\S]*membership-removal contract must prove stale membership data cannot keep access alive/,
  'Docker proof contract must pin guest-list and stale membership runtime coverage',
);

const iamContractScript = String(packageJson.scripts?.['test:contract:iam-call-access'] || '');
for (const requiredPath of [
  'node tests/contract/call-access-removed-members-contract.mjs',
  'node tests/contract/call-access-guest-list-membership-docker-proof-contract.mjs',
  '../backend-king-php/tests/call-guest-list-direct-join-contract.sh',
  '../backend-king-php/tests/iam-backend-docker-runtime-proof-wrapper.sh',
]) {
  assert.ok(iamContractScript.includes(requiredPath), `IAM package gate must include ${requiredPath}`);
}

for (const requiredPath of [
  'frontend-vue/tests/contract/call-access-removed-members-contract.mjs',
  'frontend-vue/tests/contract/call-access-guest-list-membership-docker-proof-contract.mjs',
  'backend-king-php/tests/call-guest-list-direct-join-contract.sh',
  'backend-king-php/tests/iam-backend-docker-runtime-proof-wrapper.sh',
]) {
  assert.ok(ciWire.includes(requiredPath), `CI-wire contract must require ${requiredPath}`);
  assert.ok(
    matrix.commands?.['frontend:contract:iam-call-access']?.paths?.includes(requiredPath),
    `release-gate metadata must list ${requiredPath}`,
  );
}

for (const broadSourceOnlyTarget of [
  'call-access-rejoin-kick-membership.spec.js',
  'call-access-rejoin-kick-contract.php',
  'iam-owner-transfer-temp-moderator.spec.js',
]) {
  assert.equal(
    iamContractScript.includes(broadSourceOnlyTarget),
    false,
    `stable IAM guest-list revocation extraction must not import broad source-only target ${broadSourceOnlyTarget}`,
  );
}

process.stdout.write('[iam-guest-list-revocation-extraction-contract] PASS\n');
