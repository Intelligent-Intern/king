import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';

const root = path.resolve(new URL('../..', import.meta.url).pathname);

async function source(relativePath) {
  return readFile(path.join(root, relativePath), 'utf8');
}

const modalSource = await source('src/modules/users/pages/components/UserEditorModal.vue');
assert.match(modalSource, /CrudRelationStack/, 'user editor must reuse the shared relation stack');
assert.match(modalSource, /target_entity: 'user_roles'/, 'user role must be exposed as a relation target');
assert.match(modalSource, /target_entity: 'user_themes'/, 'user theme must be exposed as a relation target');
assert.match(modalSource, /show-nested-relations="false"/, 'legacy user fields must not expose unrelated nested relation hops');
assert.match(modalSource, /props\.form\.role = value/, 'role relation selection must update the existing backend payload field');
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

console.log('[user-editor-relation-controls-contract] PASS');
