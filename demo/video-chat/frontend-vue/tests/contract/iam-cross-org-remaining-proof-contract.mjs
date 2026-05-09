import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import {
  directJoinDecisionForSeedUser,
} from '../e2e/helpers/callAccessSeedMatrix.js';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const frontendRoot = path.resolve(__dirname, '../..');
const videoChatRoot = path.resolve(frontendRoot, '..');
const repoRoot = path.resolve(videoChatRoot, '../..');

function read(relativePath) {
  return fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');
}

function readJson(relativePath) {
  return JSON.parse(read(relativePath));
}

const sprint = read('SPRINT.md');
const packageJson = readJson('demo/video-chat/frontend-vue/package.json');
const ciGate = read('demo/video-chat/scripts/iam-call-access-ci-gate.sh');
const suite = read('demo/video-chat/frontend-vue/tests/contract/iam-call-access-contract-suite.mjs');
const seedMatrix = readJson('demo/video-chat/contracts/v1/iam-call-access-seeding.matrix.json');
const seedMatrixSpec = read('demo/video-chat/frontend-vue/tests/e2e/call-access-seed-matrix.spec.js');
const crossOrgBackend = read('demo/video-chat/backend-king-php/tests/call-access-cross-org-contract.php');
const staleRoleBackend = read('demo/video-chat/backend-king-php/tests/call-access-stale-organization-role-contract.php');

function scenario(key) {
  const found = (seedMatrix.scenarios || []).find((row) => row?.key === key);
  assert.ok(found, `seed matrix must include scenario ${key}`);
  return found;
}

function accessLink(key) {
  const found = (seedMatrix.access_links || []).find((row) => row?.key === key);
  assert.ok(found, `seed matrix must include access link ${key}`);
  return found;
}

function assertSprintChecked(checkedId) {
  assert.match(
    sprint,
    new RegExp(`- \\[x\\] \`${checkedId}\``),
    `SPRINT must mark ${checkedId} checked only while this proof is present`,
  );
}

for (const checkedId of [
  'e2e_org_008_cross_org_rights_not_leaked',
  'e2e_org_009_stale_client_role_ignored',
  'e2e_org_010_stale_session_role_revalidated',
  'e2e_anon_logged_in_006_org_admin_cannot_join_foreign_org_call',
  'e2e_journey_013_org_admin_foreign_org_denied',
]) {
  assertSprintChecked(checkedId);
}

assert.match(
  sprint,
  /- \[x\] Logged-in organization admin cannot join foreign organization calls through anonymous link/,
  'SPRINT must close the anonymous-link foreign org-admin row with proof',
);

assert.equal(
  packageJson.scripts?.['test:contract:iam-call-access'],
  'node tests/contract/iam-call-access-contract-suite.mjs',
  'IAM call-access package script must keep the shared suite wrapper',
);
assert.match(
  suite,
  /node tests\/contract\/iam-cross-org-remaining-proof-contract\.mjs/,
  'IAM call-access contract suite must run the remaining cross-org proof contract',
);
assert.match(
  ciGate,
  /iam-cross-org-remaining-proof-contract\.mjs/,
  'IAM CI static gate must run the remaining cross-org proof contract',
);
assert.match(
  ciGate,
  /call-access-cross-org-contract\.sh/,
  'IAM CI SQLite gate must run the backend cross-org contract',
);
assert.match(
  ciGate,
  /call-access-stale-organization-role-contract\.sh/,
  'IAM CI SQLite gate must run the stale organization-role backend contract',
);

for (const deniedScenarioKey of [
  'direct_join_org_admin_foreign_organization_denied',
  'direct_join_active_org_switch_does_not_grant_foreign_call',
  'direct_join_owner_rights_not_cross_org',
  'direct_join_guest_list_not_cross_org',
]) {
  const item = scenario(deniedScenarioKey);
  const decision = directJoinDecisionForSeedUser(item.principal_user_key, item.call_key);
  assert.equal(item.expected?.state, 'forbidden', `${deniedScenarioKey} must stay denied`);
  assert.equal(item.expected?.decision_source, 'none', `${deniedScenarioKey} must not claim a rights source`);
  assert.equal(item.expected?.tenant_admin, false, `${deniedScenarioKey} must not grant tenant admin`);
  assert.equal(item.expected?.platform_admin, false, `${deniedScenarioKey} must not grant platform admin`);
  assert.equal(decision.allowed, false, `${deniedScenarioKey} seed decision must deny direct join`);
  assert.equal(decision.source, 'none', `${deniedScenarioKey} seed decision must not claim a rights source`);
  assert.equal(decision.can_manage_lobby, false, `${deniedScenarioKey} seed decision must not grant lobby management`);
}

assert.equal(
  scenario('direct_join_org_admin_foreign_organization_denied').journey_key,
  'e2e_journey_013_org_admin_foreign_org_denied',
  'foreign org-admin direct-join denial must be mapped to the named main journey',
);
assert.equal(
  scenario('anonymous_open_logged_in_org_admin_foreign_org_lobby').journey_key,
  'e2e_anon_logged_in_006_org_admin_cannot_join_foreign_org_call',
  'foreign anonymous org-admin denial must be mapped to the named anonymous-link journey',
);
assert.equal(accessLink('beta_open').link_kind, 'open', 'beta foreign anonymous link must be an open link');
assert.equal(accessLink('beta_open').call_key, 'beta_active', 'beta foreign anonymous link must target the foreign call');

assert.match(
  seedMatrixSpec,
  /mainJourneyScenarioBindings[\s\S]*e2e_journey_013_org_admin_foreign_org_denied[\s\S]*direct_join_org_admin_foreign_organization_denied/s,
  'Playwright seed matrix spec must bind e2e_journey_013 to the foreign org-admin direct-join denial',
);
assert.match(
  seedMatrixSpec,
  /deniedDirectJoinScenarios[\s\S]*direct_join_active_org_switch_does_not_grant_foreign_call[\s\S]*direct_join_owner_rights_not_cross_org[\s\S]*direct_join_guest_list_not_cross_org/s,
  'Playwright seed matrix spec must keep cross-org denied direct-join rows in the denied matrix',
);
assert.match(
  seedMatrixSpec,
  /e2e_anon_logged_in_006 org admin cannot direct-join a foreign organization call through anonymous link[\s\S]*anonymous_open_logged_in_org_admin_foreign_org_lobby[\s\S]*alpha_org_admin/s,
  'Playwright seed matrix spec must name the foreign anonymous org-admin denial row',
);

assert.match(
  crossOrgBackend,
  /organization A admin should not receive organization B call access[\s\S]*foreign organization admin denial must not claim a source/s,
  'backend cross-org contract must prove org-admin rights do not cross organizations',
);
assert.match(
  crossOrgBackend,
  /organization A guest-list entry must not direct-join organization B call[\s\S]*organization A owner must not direct-join organization B call through owner rights/s,
  'backend cross-org contract must prove guest-list and owner rights do not leak across organizations',
);
assert.match(
  crossOrgBackend,
  /active organization switch must not mint organization B membership[\s\S]*multi-tenant active switch must not grant organization B call permission/s,
  'backend cross-org contract must prove active organization switching cannot grant foreign call access',
);
assert.match(
  crossOrgBackend,
  /foreign anonymous link must not create organization B tenant membership[\s\S]*organization B anonymous link for organization A user/s,
  'backend cross-org contract must prove foreign anonymous links are call-scoped for logged-in foreign users',
);

assert.match(
  staleRoleBackend,
  /organization_role=admin&tenant_admin=1&role=admin[\s\S]*X-Organization-Role[\s\S]*stale client role cache must not resolve hidden invite-only call/s,
  'stale organization-role contract must prove forged stale client role data is ignored',
);
assert.match(
  staleRoleBackend,
  /same session must re-read downgraded tenant role[\s\S]*locally cached session fallback must re-read downgraded tenant role/s,
  'stale organization-role contract must prove live and cached sessions re-read downgraded backend roles',
);
assert.match(
  staleRoleBackend,
  /staleDecodedSessionContext[\s\S]*call access must revalidate stale decoded role context against backend state/s,
  'stale organization-role contract must prove stale decoded session role context is revalidated',
);

console.log('[iam-cross-org-remaining-proof-contract] PASS');
