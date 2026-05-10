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

const evidence = read('documentation/iam-sprint-05-email-confirmation-extraction.md');
const evidenceText = evidence.replace(/\s+/g, ' ');
const packageJson = JSON.parse(read('demo/video-chat/frontend-vue/package.json'));
const accountIsolation = read('demo/video-chat/frontend-vue/tests/contract/call-access-account-isolation-contract.mjs');
const logoutSwitch = read('demo/video-chat/frontend-vue/tests/contract/call-access-logout-login-switch-contract.mjs');
const strongMismatchPrivacy = read('demo/video-chat/frontend-vue/tests/contract/call-access-strong-mismatch-privacy-contract.mjs');
const linkPrivacy = read('demo/video-chat/frontend-vue/tests/contract/call-access-link-privacy-contract.mjs');
const auditCompatibility = read('demo/video-chat/frontend-vue/tests/contract/call-access-audit-event-compatibility-contract.mjs');
const auditRedaction = read('demo/video-chat/frontend-vue/tests/contract/call-access-audit-redaction-contract.mjs');
const strongMismatchAudit = read('demo/video-chat/frontend-vue/tests/contract/call-access-strong-mismatch-audit-redaction-contract.mjs');
const confirmationHelper = read('demo/video-chat/backend-king-php/domain/calls/call_access_account_confirmation.php');
const confirmationBackendContract = read('demo/video-chat/backend-king-php/tests/call-access-email-confirmation-contract.php');
const callAccessRoutes = read('demo/video-chat/backend-king-php/http/module_calls_access.php');

for (const branch of [
  'local/iam-e2e-account-reconciliation-email',
  'local/iam-e2e-email-confirmation-secure-expiry',
  'local/iam-e2e-email-multiple-pending-proof',
  'local/iam-e2e-email-safe-texts-and-dispatch-audit',
  'local/iam-e2e-email-confirmation-race-hardening',
  'local/iam-e2e-audit-confirmation-implicit',
]) {
  requireIncludes(evidence, branch, `evidence must classify ${branch}`);
}

for (const head of [
  '393bef4219d953aed145cf023c9d7c05f8157e66',
  'f2c702aa1aa96637faf044fba8d52bdf016b5feb',
  '72c2c2922141ec4220c54cbc5cc3c9cb387adeff',
  '29d94e72b63b6482c50094360d731d17e0a15932',
  'a87b0ba8144c4bddb2c34207c6d3a569217754a6',
  '6c8b0734f0f06ff59da25cb56de95f3a2c7f34c5',
  '9f654b8345dcf7719ab68c7f15f535019d8b41a0',
]) {
  requireIncludes(evidence, head, `evidence must record ${head}`);
}

for (const integratedPath of [
  'demo/video-chat/backend-king-php/domain/calls/call_access_account_confirmation.php',
  'demo/video-chat/backend-king-php/tests/call-access-email-confirmation-contract.php',
]) {
  assert.equal(exists(integratedPath), true, `focused account-confirmation backend path must be extracted: ${integratedPath}`);
  requireIncludes(evidence, integratedPath, `evidence must list extracted account-confirmation path ${integratedPath}`);
}

for (const sourceOnlyPath of [
  'demo/video-chat/backend-king-php/domain/calls/call_access_account_confirmation_audit.php',
  'demo/video-chat/frontend-vue/src/domain/calls/access/AccountUpdateConfirmationView.vue',
  'demo/video-chat/frontend-vue/tests/contract/call-access-email-safe-texts-dispatch-audit-contract.mjs',
]) {
  assert.equal(exists(sourceOnlyPath), false, `still-parked account-confirmation path must remain absent: ${sourceOnlyPath}`);
  requireIncludes(evidence, sourceOnlyPath, `evidence must list parked source-only path ${sourceOnlyPath}`);
}

for (const [pattern, message] of [
  [
    /The current base does not contain the account-confirmation implementation\s+surface used by the source branches/,
    'evidence must state that implementation files are absent',
  ],
  [
    /That is not the call-access account-update\s+confirmation flow from the IAM5-13 branches/,
    'evidence must not conflate workspace email changes with call-access confirmation',
  ],
  [
    /Current maintained contracts already support adjacent account, privacy, and\s+audit safety, but not the account-confirmation runtime itself/,
    'evidence must separate current extracted proof from deferred runtime behavior',
  ],
  [
    /Importing only static assertions or documentation as if the runtime were\s+present would falsely claim support for missing contracts/,
    'evidence must reject false static implementation claims',
  ],
  [
    /No backend runtime, frontend route\/view, package script, CI wiring, `SPRINT\.md`,\s+or `BACKLOG\.md` change was made/,
    'evidence must document write-scope compliance',
  ],
  [
    /IAM7-02 Current Extraction Update/,
    'evidence must record current IAM7-02 account-confirmation extraction',
  ],
  [
    /This extraction does not claim email dispatch acceptance or\s+frontend modal coverage/,
    'evidence must keep dispatch and frontend modal value parked',
  ],
]) {
  requireMatch(evidence, pattern, message);
}

for (const invariant of [
  'sent to the current logged-in account',
  'not sent to the personalized-link target account',
  'manual display-name re-entry',
  'account data must remain unchanged until a valid confirmation consumes a token',
  'cannot be confirmed by another account',
  'another browser session for the same account may confirm without rebinding',
  'high-entropy `cau_` tokens',
  'secure HTTPS or loopback frontend origin',
  'fail after expiry without consumption',
  'multiple pending confirmations use distinct tokens',
  'superseded_at',
  'superseded_by_id',
  'secure confirmation URL, and expiry metadata',
  'delete the pending confirmation row on dispatch failure',
  'leave account data unchanged',
  'fingerprints, not raw access ids',
  '`confirmation_identifier_logged=false`',
  '`raw_link_identifier_logged=false`',
  '`recipient_email_logged=false`',
]) {
  requireIncludes(evidenceText, invariant, `evidence must preserve source invariant: ${invariant}`);
}

for (const eventType of [
  'call_access_account_update_confirmation_requested',
  'call_access_account_update_confirmation_email_dispatched',
  'call_access_account_update_confirmation_email_dispatch_failed',
  'call_access_account_update_confirmed',
  'call_access_account_data_changed',
  'call_access_account_update_confirmation_failed',
  'call_access_account_update_confirmation_rate_limited',
  'call_access_account_update_confirmation_superseded',
]) {
  requireIncludes(evidence, eventType, `evidence must preserve deferred audit event ${eventType}`);
}

const iamGate = String(packageJson.scripts?.['test:contract:iam-call-access'] || '');
for (const sourceOnlyTarget of [
  'call-access-email-safe-texts-dispatch-audit-contract.mjs',
  'AccountUpdateConfirmationView.vue',
]) {
  assert.equal(iamGate.includes(sourceOnlyTarget), false, `IAM gate must not claim unsupported account-confirmation target ${sourceOnlyTarget}`);
}

requireMatch(
  confirmationHelper,
  /CREATE TABLE IF NOT EXISTS call_access_account_update_confirmations[\s\S]*token_fingerprint TEXT NOT NULL UNIQUE/s,
  'current account-confirmation helper must persist token fingerprints, not raw tokens',
);
requireMatch(
  confirmationHelper,
  /required_manual_reentry[\s\S]*sent_to_logged_in_account' => true[\s\S]*sent_to_link_account' => false/s,
  'current account-confirmation helper must target the current account and require manual re-entry',
);
requireMatch(
  confirmationHelper,
  /'token' => 'account_bound'[\s\S]*'token' => 'already_consumed'[\s\S]*'token' => 'expired'/s,
  'current account-confirmation helper must reject wrong-account, replayed, and expired tokens',
);
assert.doesNotMatch(
  confirmationHelper,
  /UPDATE sessions SET user_id/,
  'account confirmation must not rebind sessions',
);
requireMatch(
  callAccessRoutes,
  /account-update-confirmation[\s\S]*debug_confirmation_token[\s\S]*production[\s\S]*null/s,
  'route wiring must expose account-confirmation requests without production token disclosure',
);
requireMatch(
  confirmationBackendContract,
  /confirmation must be sent to current logged-in email[\s\S]*account data must not update before confirmation[\s\S]*confirmation must not rebind the current session[\s\S]*confirmation storage should keep token fingerprint/s,
  'backend confirmation contract must prove current-account targeting, no early update, token fingerprinting, and no session rebinding',
);

requireMatch(
  accountIsolation,
  /login switch must store the newly authenticated account token[\s\S]*post-switch storage must not retain previous account token or email[\s\S]*call-access session issuance must fail closed when verified login context disappears/s,
  'current account-isolation contract must cover storage replacement and fail-closed issuance',
);
requireMatch(
  logoutSwitch,
  /wrong logged-in account should be forbidden[\s\S]*storage replacement across viewer switch[\s\S]*same personalized link in parallel contexts keeps account sessions isolated/s,
  'current logout/login-switch contract must cover wrong-account denial and parallel account isolation',
);
requireMatch(
  strongMismatchPrivacy,
  /strong personalized-link mismatch wrong host denial gives no access[\s\S]*wrong-host denial grants no direct call access[\s\S]*denied responses do not bind a foreign session/s,
  'current strong-mismatch privacy contract must deny wrong-host access without rebinding',
);
requireMatch(
  linkPrivacy,
  /invalid call-access link renders safe state without foreign call data[\s\S]*foreign call title and email are not rendered/s,
  'current link privacy proof must hide foreign call and email data',
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
  'current strong-mismatch audit proof must keep canonical safe fields',
);

process.stdout.write('[call-access-email-confirmation-extract-contract] PASS\n');
