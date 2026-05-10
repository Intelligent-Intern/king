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

function exists(relativePath) {
  return fs.existsSync(path.join(repoRoot, relativePath));
}

const evidence = readText('documentation/iam-sprint-04-review-abuse-extraction.md');
const packageJson = readJson('demo/video-chat/frontend-vue/package.json');
const ciWire = readText('demo/video-chat/frontend-vue/tests/contract/iam-call-access-ci-wire-contract.mjs');
const matrix = readJson('demo/video-chat/contracts/v1/ui-parity-acceptance.matrix.json');
const duplicateAbuse = readText('demo/video-chat/frontend-vue/tests/contract/call-access-duplicate-abuse-contract.mjs');
const mismatchNoLeak = readText('demo/video-chat/frontend-vue/tests/contract/call-access-mismatch-no-leak-states-contract.mjs');
const strongMismatch = readText('demo/video-chat/frontend-vue/tests/contract/call-access-strong-mismatch-privacy-contract.mjs');
const strongMismatchAudit = readText('demo/video-chat/frontend-vue/tests/contract/call-access-strong-mismatch-audit-redaction-contract.mjs');

assert.match(
  evidence,
  /Recommendation: `manual\/deferred extraction`/,
  'review-abuse extraction must be classified as manual/deferred extraction',
);
assert.match(
  evidence,
  /source worktrees were\s+not deleted, reset, rebased, checked out, cleaned, or modified/,
  'evidence must document non-destructive handling of both source worktrees',
);
assert.match(
  evidence,
  /0e02e60542734f5221b1bd85fee7154e002e5077/,
  'evidence must record the inspected review-abuse source HEAD',
);
assert.match(
  evidence,
  /bdd29ffd2bc2a7ba9cbec4711dfc043931639044/,
  'evidence must record the inspected warning-modal source HEAD',
);
assert.match(
  evidence,
  /210 files changed, 41117 insertions, 3084\s+deletions/,
  'evidence must record the broad review-abuse diff size',
);
assert.match(
  evidence,
  /210 files changed, 41174 insertions, 3083 deletions/,
  'evidence must record the broad warning-modal diff size',
);

for (const invariant of [
  /duplicate personalized-link abuse must keep browser\/account sessions\s+isolated/,
  /manual-review\s+warning policy/,
  /duplicate_personalized_link/,
  /manual_review_required/,
  /mismatch=strong_personalized_link/,
  /auth=not_bound_to_current_user/,
  /host_name=not_verified\|wrong_host_name/,
  /account-update email confirmation\s+journey/,
  /rate-limited/,
  /account-bound tokens/,
  /reject expired, replayed, or wrong-account tokens/,
]) {
  assert.match(evidence, invariant, `evidence must preserve review-abuse invariant ${invariant}`);
}

for (const sourceOnlyPath of [
  'demo/video-chat/backend-king-php/domain/calls/call_access_review.php',
  'demo/video-chat/backend-king-php/domain/calls/call_access_account_confirmation.php',
  'demo/video-chat/backend-king-php/domain/calls/call_access_account_confirmation_audit.php',
  'demo/video-chat/backend-king-php/domain/calls/call_access_identity.php',
  'demo/video-chat/frontend-vue/src/domain/calls/access/AccountUpdateConfirmationView.vue',
  'demo/video-chat/frontend-vue/tests/e2e/call-access-duplicate-review-email.spec.js',
  'demo/video-chat/frontend-vue/tests/e2e/call-access-duplicate-race.spec.js',
  'demo/video-chat/backend-king-php/tests/call-access-duplicate-review-contract.php',
  'demo/video-chat/backend-king-php/tests/call-access-identity-mismatch-review-flow-contract.php',
]) {
  assert.equal(
    exists(sourceOnlyPath),
    false,
    `manual review/account-confirmation source-only path must not be treated as extracted: ${sourceOnlyPath}`,
  );
}

assert.match(
  duplicateAbuse,
  /same personalized link in parallel contexts keeps account sessions isolated/,
  'current stable duplicate-abuse proof must cover parallel browser-context abuse',
);
assert.match(
  duplicateAbuse,
  /one accepted session and one 409 conflict/,
  'current stable duplicate-abuse proof must reconcile one accepted session and one conflict',
);
assert.match(
  duplicateAbuse,
  /cross-device token bleed/,
  'current stable duplicate-abuse proof must prevent rejected-browser token bleed',
);
assert.match(
  mismatchNoLeak,
  /conflict UI denial state[\s\S]*forbidden UI denial state/,
  'current no-leak proof must cover both conflict and forbidden denied UI states',
);
assert.match(
  mismatchNoLeak,
  /public join UI must render denied session states from error code only, not backend result\/message payloads/,
  'current no-leak proof must keep denied UI copy independent from backend private payloads',
);
assert.match(
  strongMismatch,
  /strong personalized-link mismatch wrong host denial gives no access and leaks no foreign person data/,
  'current strong-mismatch privacy proof must cover wrong-host personalized-link denial',
);
assert.match(
  strongMismatchAudit,
  /wrong-account public join denial must expose only canonical strong-mismatch fields[\s\S]*session issuance denial must collapse wrong-host mismatch to safe host_name field states/,
  'current audit proof must keep strong mismatch audit fields canonical and redacted',
);

const iamContractScript = String(packageJson.scripts?.['test:contract:iam-call-access'] || '');
for (const requiredPath of [
  'node tests/contract/call-access-duplicate-abuse-contract.mjs',
  'node tests/contract/call-access-mismatch-no-leak-states-contract.mjs',
  'node tests/contract/call-access-strong-mismatch-privacy-contract.mjs',
  'node tests/contract/call-access-strong-mismatch-audit-redaction-contract.mjs',
]) {
  assert.ok(iamContractScript.includes(requiredPath), `IAM package gate must include ${requiredPath}`);
}

for (const requiredPath of [
  'frontend-vue/tests/contract/call-access-duplicate-abuse-contract.mjs',
  'frontend-vue/tests/contract/call-access-mismatch-no-leak-states-contract.mjs',
  'frontend-vue/tests/contract/call-access-strong-mismatch-privacy-contract.mjs',
  'frontend-vue/tests/contract/call-access-strong-mismatch-audit-redaction-contract.mjs',
]) {
  assert.ok(ciWire.includes(requiredPath), `CI-wire contract must require ${requiredPath}`);
  assert.ok(
    matrix.commands?.['frontend:contract:iam-call-access']?.paths?.includes(requiredPath),
    `release-gate metadata must list ${requiredPath}`,
  );
}

for (const broadSourceOnlyTarget of [
  'call-access-duplicate-review-email.spec.js',
  'call-access-duplicate-race.spec.js',
  'call-access-duplicate-review-contract.php',
  'call-access-identity-mismatch-review-flow-contract.php',
  'call-access-duplicate-review-email-contract.mjs',
  'call-access-identity-mismatch-review-flow-contract.mjs',
]) {
  assert.equal(
    iamContractScript.includes(broadSourceOnlyTarget),
    false,
    `stable IAM review-abuse extraction must not import broad source-only target ${broadSourceOnlyTarget}`,
  );
}

process.stdout.write('[iam-review-abuse-extraction-contract] PASS\n');
