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

const evidence = read('documentation/iam-sprint-05-duplicate-review-extraction.md');
const packageJson = JSON.parse(read('demo/video-chat/frontend-vue/package.json'));
const duplicateDevice = read('demo/video-chat/frontend-vue/tests/contract/call-access-duplicate-device-browser-contract.mjs');
const duplicateAbuse = read('demo/video-chat/frontend-vue/tests/contract/call-access-duplicate-abuse-contract.mjs');
const duplicateReplay = read('demo/video-chat/frontend-vue/tests/contract/call-access-duplicate-invite-replay-contract.mjs');
const mismatchNoLeak = read('demo/video-chat/frontend-vue/tests/contract/call-access-mismatch-no-leak-states-contract.mjs');
const strongMismatch = read('demo/video-chat/frontend-vue/tests/contract/call-access-strong-mismatch-privacy-contract.mjs');
const linkPrivacy = read('demo/video-chat/frontend-vue/tests/contract/call-access-link-privacy-contract.mjs');
const auditCompatibility = read('demo/video-chat/frontend-vue/tests/contract/call-access-audit-event-compatibility-contract.mjs');
const auditRedaction = read('demo/video-chat/frontend-vue/tests/contract/call-access-audit-redaction-contract.mjs');
const strongMismatchAudit = read('demo/video-chat/frontend-vue/tests/contract/call-access-strong-mismatch-audit-redaction-contract.mjs');

for (const branch of [
  'codex/iam-e2e-duplicate-review-abuse-integration',
  'agent/iam-e2e-duplicate-review-email',
  'local/iam-e2e-review-abuse-cross-browser-proof-3',
  'local/iam-e2e-review-warning-modal-policy-proof-3',
  'local/iam-e2e-light-mismatch-logging-proof-2',
  'local/iam-e2e-duplicate-abuse-device-browser-proof-3',
  'local/iam-e2e-duplicate-link-abuse-device-browser',
  'local/iam-e2e-abuse-duplicate-race',
]) {
  requireIncludes(evidence, branch, `evidence must classify ${branch}`);
}

for (const head of [
  '4f8159fdc9a5a3b4de421ada3fae5a6398e05adc',
  'a89ffcff40faf421c0c1be9bb1d02c39eca12349',
  '0e02e60542734f5221b1bd85fee7154e002e5077',
  'bdd29ffd2bc2a7ba9cbec4711dfc043931639044',
  '33a7cdf9c4696207fc53ac48afad8762c8549a2e',
  '2cd67944d703767871327c64df89f0d4005fcddc',
  '6599d8f27eed9abd246cd8d2498f885fe8ab06ed',
  '111f4084052b9099f96a65aaa8e5477e7d8f9e62',
]) {
  requireIncludes(evidence, head, `evidence must record inspected head ${head}`);
}

for (const [pattern, message] of [
  [
    /The reusable proof value already supported by current contracts is the\s+duplicate-abuse and privacy boundary/,
    'evidence must scope current extraction to duplicate-abuse and privacy coverage',
  ],
  [/classified as deferred implementation evidence/, 'evidence must defer unsupported review/email implementation value'],
  [
    /Importing only the static assertions would falsely claim support\s+for missing contracts/,
    'evidence must avoid false static claims against missing implementation',
  ],
  [
    /No product code, package scripts, shared CI wiring, `SPRINT\.md`, or\s+`BACKLOG\.md` were edited/,
    'evidence must document write-scope compliance',
  ],
]) {
  requireMatch(evidence, pattern, message);
}

for (const sourceOnlyPath of [
  'demo/video-chat/backend-king-php/domain/calls/call_access_review.php',
  'demo/video-chat/backend-king-php/domain/calls/call_access_account_confirmation.php',
  'demo/video-chat/backend-king-php/domain/calls/call_access_account_confirmation_audit.php',
  'demo/video-chat/backend-king-php/domain/calls/call_access_identity.php',
  'demo/video-chat/frontend-vue/src/domain/calls/access/AccountUpdateConfirmationView.vue',
  'demo/video-chat/frontend-vue/src/domain/calls/access/JoinStrongMismatchPanel.vue',
  'demo/video-chat/frontend-vue/src/domain/calls/access/joinStrongMismatchFlow.js',
  'demo/video-chat/frontend-vue/tests/contract/call-access-duplicate-review-email-contract.mjs',
  'demo/video-chat/frontend-vue/tests/contract/call-access-identity-mismatch-review-flow-contract.mjs',
  'demo/video-chat/frontend-vue/tests/e2e/call-access-duplicate-review-email.spec.js',
  'demo/video-chat/frontend-vue/tests/e2e/call-access-duplicate-race.spec.js',
  'demo/video-chat/backend-king-php/tests/call-access-duplicate-review-contract.php',
  'demo/video-chat/backend-king-php/tests/call-access-email-confirmation-contract.php',
  'demo/video-chat/backend-king-php/tests/call-access-identity-mismatch-review-flow-contract.php',
]) {
  assert.equal(exists(sourceOnlyPath), false, `source-only manual review/email path must remain absent: ${sourceOnlyPath}`);
  requireIncludes(evidence, sourceOnlyPath, `evidence must list absent source-only path ${sourceOnlyPath}`);
}

const iamGate = String(packageJson.scripts?.['test:contract:iam-call-access'] || '');
for (const maintainedContract of [
  'node tests/contract/call-access-duplicate-device-browser-contract.mjs',
  'node tests/contract/call-access-duplicate-abuse-contract.mjs',
  'node tests/contract/call-access-duplicate-invite-replay-contract.mjs',
  'node tests/contract/call-access-mismatch-no-leak-states-contract.mjs',
  'node tests/contract/call-access-strong-mismatch-privacy-contract.mjs',
  'node tests/contract/call-access-strong-mismatch-audit-redaction-contract.mjs',
  'node tests/contract/call-access-link-privacy-contract.mjs',
  'node tests/contract/call-access-audit-event-compatibility-contract.mjs',
  'node tests/contract/call-access-audit-redaction-contract.mjs',
]) {
  requireIncludes(iamGate, maintainedContract, `IAM package gate must keep ${maintainedContract}`);
}

for (const sourceOnlyTarget of [
  'call-access-duplicate-review-email-contract.mjs',
  'call-access-identity-mismatch-review-flow-contract.mjs',
  'call-access-duplicate-review-email.spec.js',
  'call-access-duplicate-race.spec.js',
  'call-access-duplicate-review-contract.php',
  'call-access-email-confirmation-contract.php',
  'call-access-identity-mismatch-review-flow-contract.php',
]) {
  assert.equal(iamGate.includes(sourceOnlyTarget), false, `IAM gate must not claim source-only duplicate review/email target ${sourceOnlyTarget}`);
}

requireMatch(
  duplicateDevice,
  /separate browser contexts[\s\S]*same personalized link[\s\S]*one success and one 409 conflict[\s\S]*must not adopt either call-access token/s,
  'current duplicate-device proof must cover cross-browser duplicate abuse and token isolation',
);
requireMatch(
  duplicateAbuse,
  /stale verified-context duplicate-session test[\s\S]*parallel browser-context duplicate-abuse test[\s\S]*cross-device token bleed/s,
  'current duplicate-abuse proof must cover stale replay and parallel abuse without token bleed',
);
requireMatch(
  duplicateReplay,
  /stale verified-context replay coverage[\s\S]*atomic capped update[\s\S]*duplicate redemption races/s,
  'current replay proof must cover stale replay and duplicate invite redemption races',
);
requireMatch(
  mismatchNoLeak,
  /conflict UI denial state[\s\S]*forbidden UI denial state[\s\S]*render denied session states from error code only/s,
  'current no-leak proof must keep denied UI states code-driven and data-free',
);
requireMatch(
  strongMismatch,
  /strong personalized-link mismatch wrong host denial gives no access[\s\S]*wrong-host denial grants no direct call access[\s\S]*denied responses do not bind a foreign session/s,
  'current strong-mismatch proof must deny wrong-host access without rebinding',
);
requireMatch(
  linkPrivacy,
  /invalid call-access link renders safe state without foreign call data[\s\S]*foreign call title and email are not rendered/s,
  'current link privacy proof must hide foreign call/email data on invalid links',
);
requireMatch(
  auditCompatibility,
  /CALL_ACCESS_FORBIDDEN:\s*'call_access_denied'[\s\S]*call_access_rejected: 'call_access_denied'/,
  'current audit compatibility proof must canonicalize denied call-access aliases',
);
requireMatch(
  auditRedaction,
  /raw call-access ids, session ids, and tokens[\s\S]*private call data or foreign person data/s,
  'current audit redaction proof must keep raw identifiers and private data out of audit payloads',
);
requireMatch(
  strongMismatchAudit,
  /wrong-account public join denial must expose only canonical strong-mismatch fields[\s\S]*host_name field states[\s\S]*strong mismatch audit payload must preserve canonical mismatch/s,
  'current strong-mismatch audit proof must keep only canonical safe fields',
);

for (const deferredInvariant of [
  'duplicate_personalized_link',
  'manual_review_required',
  'access fingerprints',
  'currently logged-in account',
  'account-bound, expiring, one-time tokens',
  'does not rebind the current browser session',
  'same-account light mismatch reopen',
]) {
  requireIncludes(evidence, deferredInvariant, `evidence must preserve deferred invariant ${deferredInvariant}`);
}

process.stdout.write('[call-access-duplicate-review-extract-contract] PASS\n');
