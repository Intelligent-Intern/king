import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';

const root = path.resolve(new URL('../..', import.meta.url).pathname);

async function source(relativePath) {
  return readFile(path.join(root, relativePath), 'utf8');
}

const modalSource = await source('src/modules/users/pages/components/UserEditorModal.vue');
assert.match(modalSource, /AppSidePanelShell/, 'user editor must render in the shared CRUD side panel shell');
assert.doesNotMatch(modalSource, /AppModalShell/, 'user editor must not open as a centered modal');
assert.match(modalSource, /CrudRelationStack/, 'user editor must reuse the shared relation stack');
assert.match(modalSource, /v-if="relationStackOpen"/, 'user editor relation stack must replace content inside the existing side panel');
assert.doesNotMatch(modalSource, /relationStackMaximized/, 'user editor relation stack must not open a second maximizable modal');
assert.match(modalSource, /target_entity: 'user_roles'/, 'user role must be exposed as a relation target');
assert.match(modalSource, /target_entity: 'governance_roles'/, 'governance roles must be exposed as a relation target');
assert.match(modalSource, /target_entity: 'groups'/, 'governance groups must use the shared Governance group descriptor target');
assert.match(modalSource, /target_entity: 'user_themes'/, 'user theme must be exposed as a relation target');
assert.match(modalSource, /relationStackShowsNestedRelations/, 'governance group selection must enable nested relation hops without exposing them on legacy user fields');
assert.match(modalSource, /relationStackRelationFilter/, 'user group relation picker must hide unsupported nested relation hops');
assert.match(modalSource, /\['modules', 'permissions'\]/, 'user group relation picker must only expose module and permission nested hops');
assert.match(modalSource, /buildGovernanceCatalogRows/, 'nested group permission/module pickers must use Governance catalog rows');
assert.match(modalSource, /createGovernanceRelationRow/, 'user group relation picker must persist missing groups from the nested stack');
assert.match(modalSource, /GOVERNANCE_CRUD_DESCRIPTORS\[key\]/, 'user group inline creation must use the shared Governance descriptor endpoint');
assert.match(modalSource, /canCreateGovernanceRelationRow/, 'user group inline creation must be permission-gated by the caller');
assert.match(modalSource, /governance\.groups\.create/, 'user group inline creation must require group create permission');
assert.match(modalSource, /props\.form\.role = value/, 'role relation selection must update the existing backend payload field');
assert.match(modalSource, /props\.form\.governance_roles = /, 'governance role relation selection must update the backend relationship payload field');
assert.match(modalSource, /props\.form\.governance_groups = /, 'governance group relation selection must update the backend relationship payload field');
assert.match(modalSource, /props\.form\.theme = value/, 'theme relation selection must update the existing backend payload field');
assert.doesNotMatch(
  modalSource,
  /<AppSelect\s+v-model="form\.role"/,
  'user role must not be rendered as a raw select',
);
assert.doesNotMatch(
  modalSource,
  /<AppSelect\s+v-model="form\.theme"/,
  'user theme must not be rendered as a raw select',
);

const cssSource = await source('src/modules/users/pages/admin/UsersView.css');
assert.match(cssSource, /users-relation-link/, 'user relation controls must have stable styling');

const viewSource = await source('src/modules/users/pages/admin/UsersView.vue');
assert.match(viewSource, /loadGovernanceRoleOptions/, 'user management must load governance role options through the helper');
assert.match(viewSource, /loadGovernanceGroupOptions/, 'user management must load governance group options through the helper');
assert.match(viewSource, /relationships:\s*\{[\s\S]*roles: governanceRoleRelationshipPayload\(form\.governance_roles\)/, 'user save payload must submit governance role relationships');
assert.match(viewSource, /groups: governanceGroupRelationshipPayload\(form\.governance_groups\)/, 'user save payload must submit governance group relationships');

const governanceRolesSource = await source('src/modules/users/pages/admin/governanceRoles.js');
assert.match(governanceRolesSource, /\/api\/governance\/roles/, 'user management must load governance role options from the backend');
assert.match(governanceRolesSource, /\/api\/governance\/groups/, 'user management must load governance group options from the backend');
assert.match(governanceRolesSource, /payload\.relationships = relationships/, 'governance group payloads must preserve nested relation selections');
assert.match(governanceRolesSource, /entity_key: String\(row\?\.entity_key/, 'governance relation payloads must preserve nested entity keys');

console.log('[user-editor-relation-controls-contract] PASS');
