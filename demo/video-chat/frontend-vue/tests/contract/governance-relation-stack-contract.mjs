import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';
import { useCrudRelationNavigator } from '../../src/modules/governance/useCrudRelationNavigator.js';

const root = path.resolve(new URL('../..', import.meta.url).pathname);

async function source(relativePath) {
  return readFile(path.join(root, relativePath), 'utf8');
}

const rowsByEntity = {
  groups: [
    { id: 'group:ops', name: 'Operations', key: 'ops', status: 'active' },
  ],
  permissions: [
    { id: 'permission:users.read', name: 'users.read', key: 'users.read', status: 'active' },
    { id: 'permission:users.update', name: 'users.update', key: 'users.update', status: 'active' },
  ],
  modules: [
    { id: 'module:governance', name: 'Governance', key: 'governance', status: 'active' },
  ],
};

const navigator = useCrudRelationNavigator({
  rowProvider: (entityKey) => rowsByEntity[entityKey] || [],
  pageSize: 1,
});

navigator.reset(
  { key: 'permissions', target_entity: 'permissions', selection_mode: 'multiple' },
  [rowsByEntity.permissions[0]],
);
assert.equal(navigator.currentFrame.value.key, 'permissions', 'navigator must start at the requested relation');
assert.equal(navigator.currentSelectionIds.value.length, 1, 'navigator must hydrate existing relation selections');
assert.equal(navigator.pagedRows.value.length, 1, 'navigator must paginate relation rows');

navigator.toggleRow(rowsByEntity.permissions[1]);
assert.deepEqual(
  navigator.currentSelectionIds.value.sort(),
  ['permission:users.read', 'permission:users.update'],
  'multiple relations must support mass selection',
);

navigator.push({ key: 'modules', target_entity: 'modules', selection_mode: 'single' });
assert.equal(navigator.stack.value.length, 2, 'navigator must support recursive relation stack pushes');
assert.equal(navigator.currentFrame.value.target_entity, 'modules', 'recursive push must switch target entity');
navigator.toggleRow(rowsByEntity.modules[0]);
assert.deepEqual(navigator.currentSelectionIds.value, ['module:governance'], 'single relations must select one row');
navigator.back();
assert.equal(navigator.currentFrame.value.key, 'permissions', 'back must return to the parent relation frame');

const nestedNavigator = useCrudRelationNavigator({
  rowProvider: (entityKey) => rowsByEntity[entityKey] || [],
});
nestedNavigator.reset({ key: 'groups', target_entity: 'groups', selection_mode: 'multiple' });
nestedNavigator.toggleRow(rowsByEntity.groups[0]);
nestedNavigator.push({ key: 'permissions', target_entity: 'permissions', selection_mode: 'multiple' });
nestedNavigator.toggleRow(rowsByEntity.permissions[0]);
assert.equal(nestedNavigator.applyCurrentSelectionToParent(), true, 'nested selections must return to the selected parent frame');
assert.equal(nestedNavigator.currentFrame.value.key, 'groups', 'nested apply must navigate back to the parent relation');
assert.deepEqual(
  nestedNavigator.currentSelectedRows.value[0].relationships.permissions.map((row) => row.id),
  ['permission:users.read'],
  'nested selections must attach to the selected parent row draft',
);

const modalSource = await source('src/modules/governance/pages/GovernanceCrudModal.vue');
assert.match(modalSource, /\+1/, 'governance modal must expose relation links as +1 controls');
assert.match(modalSource, /open-relation/, 'governance modal must emit relation navigation requests');
assert.doesNotMatch(modalSource, /<select[\s\S]*relationship/i, 'relation fields must not be rendered as raw selects');

const stackSource = await source('src/modules/governance/components/CrudRelationStack.vue');
assert.match(stackSource, /AppModalShell/, 'relation stack must use the shared modal shell');
assert.match(stackSource, /AppPagination/, 'relation stack must paginate selection rows');
assert.match(stackSource, /createDraft/, 'relation stack must support create-in-place through a draft creator');
assert.match(stackSource, /canCreateDraftForEntity/, 'relation stack must let callers restrict local draft creation');
assert.match(stackSource, /relationFilter/, 'relation stack must let callers restrict visible nested relation hops');
assert.match(stackSource, /await props\.createDraft/, 'relation stack create must support persisted async row creation');
assert.match(stackSource, /draftSaving/, 'relation stack create must expose pending state while persisted rows are created');
assert.match(stackSource, /pushNestedRelation\(nestedRelation\)/, 'relation stack must support recursive nested relation navigation');
assert.match(stackSource, /applyCurrentSelectionToParent/, 'relation stack must apply nested selections back into the selected parent row');
assert.match(stackSource, /selection_mode === 'multiple'/, 'relation stack must respect multi-select relation descriptors');

const viewSource = await source('src/modules/governance/pages/GovernanceCrudView.vue');
assert.match(viewSource, /CrudRelationStack/, 'governance CRUD view must mount the relation stack');
assert.match(viewSource, /relationRowsForEntity/, 'governance CRUD view must provide target rows by entity');
assert.match(viewSource, /relationSelections/, 'governance CRUD view must keep draft relation selections');
assert.match(viewSource, /applyRelationSelection/, 'governance CRUD view must return selected rows into the draft');
assert.match(viewSource, /createRelationDraft/, 'governance CRUD view must create local relation drafts from the stack');
assert.match(viewSource, /relationSelectionSnapshot as buildRelationSelectionSnapshot/, 'governance CRUD view must preserve nested relation payloads');
assert.doesNotMatch(viewSource, /createRelationDraft[\s\S]{0,400}users/, 'backend-backed users must not be locally drafted by the relation stack');

console.log('[governance-relation-stack-contract] PASS');
