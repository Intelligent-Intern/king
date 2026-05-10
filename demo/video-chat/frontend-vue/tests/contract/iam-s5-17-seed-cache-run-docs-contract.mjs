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

function rows(value) {
  return Array.isArray(value) ? value : [];
}

function assertUniqueField(items, field, label) {
  const owners = new Map();
  for (const row of rows(items)) {
    const key = String(row?.key || '<missing key>');
    const value = row?.[field];
    if (value === undefined || value === null || String(value).trim() === '') {
      continue;
    }
    const normalized = String(value);
    assert.ok(
      !owners.has(normalized),
      `${label} ${field} must be unique: ${normalized} is used by ${owners.get(normalized)} and ${key}`,
    );
    owners.set(normalized, key);
  }
}

function keySet(items) {
  return new Set(rows(items).map((row) => String(row?.key || '').trim()).filter(Boolean));
}

function assertRef(refs, value, label) {
  if (value === undefined || value === null) return;
  const normalized = String(value).trim();
  if (normalized === '') return;
  assert.ok(refs.has(normalized), `${label} must reference an existing key: ${normalized}`);
}

const evidence = readText('documentation/iam-sprint-05-seed-cache-run-docs-extraction.md');
const packageJson = readJson('demo/video-chat/frontend-vue/package.json');
const seedMatrix = readJson('demo/video-chat/contracts/v1/iam-call-access-seeding.matrix.json');
const seedMatrixSpec = readText('demo/video-chat/frontend-vue/tests/e2e/call-access-seed-matrix.spec.js');
const foundationContract = readText('demo/video-chat/frontend-vue/tests/contract/iam-call-access-e2e-foundation-contract.mjs');
const assetCacheContract = readText('demo/video-chat/frontend-vue/tests/contract/asset-cache-busting-contract.mjs');
const prodDebugContract = readText('demo/video-chat/frontend-vue/tests/contract/prod-debug-observability-contract.mjs');

const scripts = packageJson.scripts || {};
const iamContractScript = String(scripts['test:contract:iam-call-access'] || '');
const callAccessE2eScript = String(scripts['test:e2e:call-access'] || '');
const assetCacheScript = String(scripts['test:contract:asset-cache-busting'] || '');

assert.match(evidence, /Worker: IAM5-17/, 'evidence must name the IAM5-17 worker');
assert.match(evidence, /Base branch: `prod-kingrt-do-not-push-to-github` at `17c851ac`/, 'evidence must pin the inspected integration base');
assert.match(evidence, /focused extraction, not a merge lane/, 'evidence must reject whole-branch import');
assert.match(
  evidence,
  /No Background, Gossip, SFU, MediaSecurity, BTGF, deploy scripts, `SPRINT\.md`,[\s\S]*package scripts, CI wiring, source runtime files, or broad runbooks were edited\./,
  'evidence must preserve the IAM5-17 write boundary',
);

for (const [branch, head] of [
  ['codex/iam-e2e-asset-cache-busting-contract-20260509', '5101367b'],
  ['local/iam-e2e-local-run-docs-proof-20260509', 'f956c91b'],
  ['local/iam-seed-data-hygiene-20260509', 'bb4331ef'],
  ['codex/iam-seed-data-hygiene-20260509', '595b2ebd'],
  ['codex/iam-e2e-live-proof-env-audit-20260509', '7ea9757a'],
  ['codex/iam-e2e-deploy-readiness-20260509', '5771a3b6'],
  ['iam-e2e-deploy-readiness-rescan-codex-20260509', '7743e21f'],
  ['codex/iam-sprint-proof-audit-20260509', 'bf1186a2'],
  ['codex/iam-sprint-arendt-proof-checkboxes-20260509', 'e9a55048'],
]) {
  assert.ok(evidence.includes(branch), `evidence must classify ${branch}`);
  assert.ok(evidence.includes(head), `evidence must record ${branch} head ${head}`);
}

assert.match(
  evidence,
  /test:ci:iam-call-access:\*/,
  'local run docs source must be classified against obsolete test:ci helper names',
);
assert.match(
  evidence,
  /those helpers are absent from the current Sprint 05 base/,
  'evidence must explain why the local-run-doc branch was not copied',
);
assert.match(
  evidence,
  /covered by `prod-debug-observability-contract\.mjs`/,
  'evidence must classify live-proof env audit as an existing adjacent contract',
);
assert.match(
  evidence,
  /No matrix data edit was needed/,
  'evidence must make seed data hygiene a proof extraction, not a data rewrite',
);

assert.ok(iamContractScript.includes('node tests/contract/iam-call-access-e2e-foundation-contract.mjs'), 'current IAM gate must keep foundation seed proof');
assert.ok(iamContractScript.includes('node tests/contract/call-access-ci-artifacts-contract.mjs'), 'current IAM gate must keep artifact/redaction proof');
assert.ok(iamContractScript.includes('node tests/contract/call-access-direct-join-rights-contract.mjs'), 'current IAM gate must keep direct-join proof');
assert.ok(iamContractScript.includes('node tests/contract/call-access-cross-org-contract.mjs'), 'current IAM gate must keep cross-org proof');
assert.ok(iamContractScript.includes('node tests/contract/call-access-terminal-states-contract.mjs'), 'current IAM gate must keep terminal-state proof');
assert.ok(iamContractScript.includes('../backend-king-php/tests/iam-call-access-sqlite-runtime-proof.sh'), 'current IAM gate must keep SQLite runtime proof wrapper');
assert.doesNotMatch(iamContractScript, /iam-call-access-contract-suite\.mjs|iam-local-run-docs-contract\.mjs/, 'IAM5-17 must not replace the current gate with old helper contracts');

assert.equal(
  callAccessE2eScript,
  'PLAYWRIGHT_IAM_CALL_ACCESS_ARTIFACTS=1 playwright test tests/e2e/call-access-join.spec.js tests/e2e/call-access-seed-matrix.spec.js tests/e2e/call-access-calendar-unregistered-invite.spec.js tests/e2e/call-access-admin-join-boundaries.spec.js --workers=1',
  'current focused Call Access E2E gate must remain serial and artifact-retaining',
);

assert.equal(
  assetCacheScript,
  'node tests/contract/asset-cache-busting-contract.mjs',
  'asset-cache busting must stay in its dedicated focused contract',
);
assert.match(assetCacheContract, /scheduleBuildVersionReload/, 'asset-cache contract must cover stale-build reload scheduling');
assert.match(assetCacheContract, /startsWith\('\/workspace\/call'\)/, 'asset-cache contract must defer reloads inside active call workspace routes');
assert.match(assetCacheContract, /handleAssetVersionConnectionFailure/, 'asset-cache contract must cover pre-open websocket asset-version probes');
assert.match(assetCacheContract, /videochat_realtime_websocket_disconnect_stale_asset_client/, 'asset-cache contract must cover the presence reconnect stale-client helper');

assert.match(prodDebugContract, /must not source or dot-load \.env\.local/, 'env audit contract must forbid sourcing local env files');
assert.match(prodDebugContract, /allowlist must stay scoped to deploy\/debug diagnostics names/, 'env audit contract must keep a scoped allowlist');
assert.match(prodDebugContract, /must not import deploy secrets or provider tokens/, 'env audit contract must exclude deploy secrets');
assert.match(prodDebugContract, /must remain read-only/, 'env audit contract must preserve read-only production diagnostics');
assert.match(prodDebugContract, /remote sanitized compose env must exclude secrets and provider tokens/, 'env audit contract must sanitize remote compose env use');

assert.equal(seedMatrix.matrix_name, 'king-video-chat-iam-call-access-seeding', 'contract must use the IAM call-access seed matrix');
assert.match(
  String(seedMatrix.release_policy?.notes || ''),
  /no SDP, media payloads, production tokens, or reusable credentials/,
  'seed matrix must stay non-production and credential-free',
);

for (const [collection, label] of [
  [seedMatrix.tenants, 'tenant seed row'],
  [seedMatrix.users, 'user seed row'],
  [seedMatrix.calls, 'call seed row'],
  [seedMatrix.access_links, 'access-link seed row'],
  [seedMatrix.scenarios, 'scenario seed row'],
]) {
  assertUniqueField(collection, 'key', label);
}
for (const [collection, label] of [
  [seedMatrix.tenants, 'tenant seed row'],
  [seedMatrix.users, 'user seed row'],
  [seedMatrix.calls, 'call seed row'],
  [seedMatrix.access_links, 'access-link seed row'],
]) {
  assertUniqueField(collection, 'id', label);
}
assertUniqueField(seedMatrix.users, 'email', 'user seed row');
assertUniqueField(seedMatrix.calls, 'room_id', 'call seed row');
assertUniqueField(seedMatrix.access_links, 'join_path', 'access-link seed row');

const tenantKeys = keySet(seedMatrix.tenants);
const userKeys = keySet(seedMatrix.users);
const callKeys = keySet(seedMatrix.calls);
const accessLinkKeys = keySet(seedMatrix.access_links);

for (const user of rows(seedMatrix.users)) {
  for (const membership of rows(user.memberships)) {
    assertRef(tenantKeys, membership?.tenant_key, `${user.key} membership`);
  }
  for (const membership of rows(user.removed_memberships)) {
    assertRef(tenantKeys, membership?.tenant_key, `${user.key} removed membership`);
  }
}
for (const call of rows(seedMatrix.calls)) {
  assertRef(tenantKeys, call.tenant_key, `${call.key} tenant_key`);
  assertRef(userKeys, call.owner_user_key, `${call.key} owner_user_key`);
  for (const guestKey of rows(call.guest_list_user_keys)) {
    assertRef(userKeys, guestKey, `${call.key} guest_list_user_keys`);
  }
}
for (const link of rows(seedMatrix.access_links)) {
  assertRef(callKeys, link.call_key, `${link.key} call_key`);
  assertRef(userKeys, link.target_user_key, `${link.key} target_user_key`);
  assertRef(userKeys, link.anonymous_user_key, `${link.key} anonymous_user_key`);
}
for (const scenario of rows(seedMatrix.scenarios)) {
  assertRef(userKeys, scenario.principal_user_key, `${scenario.key} principal_user_key`);
  assertRef(callKeys, scenario.call_key, `${scenario.key} call_key`);
  assertRef(accessLinkKeys, scenario.link_key, `${scenario.key} link_key`);
  for (const callKey of rows(scenario.call_keys)) {
    assertRef(callKeys, callKey, `${scenario.key} call_keys`);
  }
}

const directJoinScenarioKeys = rows(seedMatrix.scenarios)
  .filter((scenario) => typeof scenario?.call_key === 'string' && typeof scenario?.expected?.direct_join_allowed === 'boolean')
  .map((scenario) => String(scenario.key));
assert.ok(directJoinScenarioKeys.length >= 1, 'seed matrix must keep direct-join scenarios');
for (const scenarioKey of directJoinScenarioKeys) {
  assert.match(seedMatrixSpec, new RegExp(scenarioKey.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')), `seed matrix spec must exercise ${scenarioKey}`);
}
assert.match(
  foundationContract,
  /directJoinCasesInSpec[\s\S]*directJoinScenarioKeys[\s\S]*seed-matrix spec Direct Join Permissions cases must exactly match the seed matrix/,
  'foundation contract must keep the exact seed-matrix to browser-spec binding',
);

assert.match(evidence, /npm run test:contract:iam-call-access/, 'evidence must record the current IAM static gate command');
assert.match(evidence, /npm run test:e2e:call-access -- --reporter=list/, 'evidence must record the current focused browser command');
assert.match(evidence, /VIDEOCHAT_SMOKE_COMPOSE_ONLY=1[\s\S]*VIDEOCHAT_SMOKE_REQUIRE_COMPOSE=1[\s\S]*bash demo\/video-chat\/scripts\/smoke\.sh/, 'evidence must record the compose local proof command');
assert.match(evidence, /pdo_sqlite[\s\S]*environment[\s\S]*blocker, not as backend proof/, 'evidence must classify missing pdo_sqlite as a local blocker');

process.stdout.write('[iam-s5-17-seed-cache-run-docs-contract] PASS\n');
