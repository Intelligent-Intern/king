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
const governanceRelationshipPayloadSource = await source('src/modules/governance/governanceRelationshipPayload.js');
assert.match(governanceRolesSource, /\/api\/governance\/roles/, 'user management must load governance role options from the backend');
assert.match(governanceRolesSource, /\/api\/governance\/groups/, 'user management must load governance group options from the backend');
assert.match(governanceRolesSource, /governanceRelationshipPayload/, 'user management must delegate relationship payload shaping to the shared Governance normalizer');
assert.match(governanceRelationshipPayloadSource, /export function governanceRelationshipPayload\(rows, entityKey\)/, 'shared Governance normalizer must expose relationship payload shaping');
assert.match(governanceRelationshipPayloadSource, /payload\.relationships = relationships/, 'governance group payloads must preserve nested relation selections');
assert.match(governanceRelationshipPayloadSource, /entity_key: String\(row\?\.entity_key/, 'governance relation payloads must preserve nested entity keys');

const backendAdminUsersSource = await source('../backend-king-php/http/module_users_admin_accounts.php');
assert.match(
  backendAdminUsersSource,
  /function videochat_admin_user_core_update_payload\(array \$payload\): array[\s\S]*unset\(\$corePayload\['relationships'\], \$corePayload\['roles'\], \$corePayload\['groups'\]\);/,
  'admin user updates must strip governance relationship payload before core user validation',
);
assert.match(
  backendAdminUsersSource,
  /\$coreUpdatePayload = videochat_admin_user_core_update_payload\(\$payload\);[\s\S]*videochat_admin_update_user\(\$pdo, \$userId, \$coreUpdatePayload, \$tenantId\)/,
  'admin user updates must pass only core fields to the user field updater',
);
assert.match(
  backendAdminUsersSource,
  /videochat_tenancy_governance_sync_user_roles\(\$pdo, \$tenantId, \$userId, \$actorUserId, \$payload\)/,
  'admin user updates must keep using the original payload for governance role sync',
);
assert.match(
  backendAdminUsersSource,
  /videochat_tenancy_governance_sync_user_groups\(\$pdo, \$tenantId, \$userId, \$payload, \$actorUserId\)/,
  'admin user updates must keep using the original payload for governance group sync',
);

console.log('[user-editor-relation-controls-contract] PASS');
