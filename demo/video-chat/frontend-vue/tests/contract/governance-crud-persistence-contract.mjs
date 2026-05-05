import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';
import {
  GOVERNANCE_CRUD_DESCRIPTORS,
} from '../../src/modules/governance/crudDescriptors.js';
import {
  governanceCrudRowsFromPayload,
  isPersistedGovernanceEntity,
  normalizeGovernanceCrudRow,
} from '../../src/modules/governance/governanceCrudPersistenceHelpers.js';

const root = path.resolve(new URL('../..', import.meta.url).pathname);

async function source(relativePath) {
  return readFile(path.join(root, relativePath), 'utf8');
}

assert.equal(isPersistedGovernanceEntity('groups'), true, 'groups must be treated as backend-backed');
assert.equal(isPersistedGovernanceEntity('organizations'), true, 'organizations must be treated as backend-backed');
assert.equal(isPersistedGovernanceEntity('roles'), true, 'roles must be treated as backend-backed');
assert.equal(isPersistedGovernanceEntity('grants'), true, 'grants must be treated as backend-backed');
assert.equal(isPersistedGovernanceEntity('policies'), true, 'policies must be treated as backend-backed');
assert.equal(isPersistedGovernanceEntity('data-portability'), true, 'data portability jobs must be treated as backend-backed');
assert.equal(isPersistedGovernanceEntity('compliance'), false, 'compliance must remain local until its backend API exists');
assert.equal(GOVERNANCE_CRUD_DESCRIPTORS.groups.endpoint, '/api/governance/groups', 'groups must target the governance backend endpoint');
assert.equal(GOVERNANCE_CRUD_DESCRIPTORS.organizations.endpoint, '/api/governance/organizations', 'organizations must target the governance backend endpoint');
assert.equal(GOVERNANCE_CRUD_DESCRIPTORS.roles.endpoint, '/api/governance/roles', 'roles must target the governance backend endpoint');
assert.equal(GOVERNANCE_CRUD_DESCRIPTORS.policies.endpoint, '/api/governance/policies', 'policies must target the governance backend endpoint');
assert.equal(GOVERNANCE_CRUD_DESCRIPTORS['data-portability'].endpoint, '/api/governance/data-portability-jobs', 'data portability must target the governance backend endpoint');

const normalized = normalizeGovernanceCrudRow({
  id: '00000000-0000-4000-8000-000000000301',
  public_id: '00000000-0000-4000-8000-000000000301',
  database_id: 123,
  organization_database_id: 456,
  name: 'Governance Group',
  status: 'active',
  updated_at: '2026-05-05T12:00:00Z',
});
assert.equal(normalized.id, '00000000-0000-4000-8000-000000000301', 'persistent rows must preserve UUID ids');
assert.equal(normalized.key, '00000000-0000-4000-8000-000000000301', 'persistent rows must use public ids as stable keys');
assert.equal(normalized.updatedAt, '2026-05-05T12:00:00Z', 'persistent rows must normalize updated_at');
assert.equal(Object.hasOwn(normalized, 'database_id'), false, 'persistent rows must not expose internal database ids');
assert.equal(Object.hasOwn(normalized, 'organization_database_id'), false, 'persistent rows must not expose relation database ids');

const normalizedUser = normalizeGovernanceCrudRow({
  id: 7,
  email: 'member@example.test',
  display_name: 'Member Example',
  status: 'active',
});
assert.equal(normalizedUser.id, '7', 'user summaries must normalize numeric ids for relation payloads');
assert.equal(normalizedUser.key, 'member@example.test', 'user summaries must prefer email as relation key');
assert.equal(normalizedUser.name, 'Member Example', 'user summaries must use display_name as the visible name');

const rows = governanceCrudRowsFromPayload({
  status: 'ok',
  result: {
    rows: [
      { id: 'group-a', name: 'Group A', status: 'active' },
      { id: '', name: 'Invalid Group', status: 'active' },
    ],
    included: {
      groups: [{ id: 'group-b', name: 'Group B' }],
    },
  },
}, 'groups');
assert.deepEqual(rows.map((row) => row.id), ['group-a'], 'list payloads must prefer result.rows and ignore invalid ids');

const apiSource = await source('src/modules/governance/useGovernanceCrudPersistence.js');
assert.match(apiSource, /fetchBackend/, 'governance persistence must use the shared backend fetch helper');
assert.match(apiSource, /refreshSession/, 'governance persistence must preserve session refresh retry behavior');
assert.match(apiSource, /descriptorEndpoint/, 'governance persistence must use descriptor-owned endpoints');
assert.match(apiSource, /listUserSummaries/, 'governance persistence must expose backend user summaries for relation pickers');
assert.match(apiSource, /\/api\/governance\/users/, 'relation user summaries must use the governance-scoped endpoint');

const viewSource = await source('src/modules/governance/pages/GovernanceCrudView.vue');
assert.match(viewSource, /createGovernanceCrudPersistence/, 'governance CRUD view must use backend persistence');
assert.match(viewSource, /loadPersistedRowsForEntity/, 'governance CRUD view must load backend-backed entities');
assert.match(viewSource, /loadGovernanceUserRows/, 'governance CRUD view must hydrate user relation rows');
assert.match(viewSource, /loadRowsForRelationTarget/, 'governance CRUD view must hydrate aggregate relation targets');
assert.match(viewSource, /subjects'.*users.*groups.*organizations/s, 'subject pickers must hydrate users, groups, and organizations');
assert.match(viewSource, /resources'.*groups.*organizations/s, 'resource pickers must hydrate persisted group and organization resources');
assert.match(viewSource, /submitPersistedRow/, 'governance CRUD view must persist backend-backed create and update actions');
assert.match(viewSource, /isPersistedGovernanceEntity\(key\)\)\s*return false/, 'relation drafts must not fake-create backend-backed entities locally');

console.log('[governance-crud-persistence-contract] PASS');
