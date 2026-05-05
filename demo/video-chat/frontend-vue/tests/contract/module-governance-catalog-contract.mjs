import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';
import { buildGovernanceCatalogRows } from '../../src/modules/governanceCatalog.js';
import { workspaceModuleRegistry } from '../../src/modules/index.js';

const root = path.resolve(new URL('../..', import.meta.url).pathname);

const moduleRows = buildGovernanceCatalogRows(workspaceModuleRegistry, 'admin-governance-modules');
assert.equal(moduleRows.length, workspaceModuleRegistry.list().length, 'governance modules must list all descriptors');
assert.ok(moduleRows.every((row) => row.entity_key === 'modules'), 'descriptor module rows must carry their entity key');
assert.ok(moduleRows.every((row) => row.readonly === true), 'descriptor module rows must be readonly system rows');
assert.ok(moduleRows.some((row) => row.key === 'governance'), 'governance module row missing');
assert.ok(moduleRows.every((row) => row.description_key === 'governance.catalog.module_description'), 'module rows must expose localized descriptions');
assert.ok(moduleRows.some((row) => row.description_params.permissions === 'governance.read'), 'module rows must expose permissions as localized params');
assert.ok(moduleRows.every((row) => typeof row.description_params.grant_targets === 'string'), 'module rows must expose grant target metadata');
assert.ok(moduleRows.every((row) => typeof row.description_params.time_limited_key === 'string'), 'module rows must expose time-limited grant metadata');

const permissionRows = buildGovernanceCatalogRows(workspaceModuleRegistry, 'admin-governance-permissions');
const permissionKeys = new Set(permissionRows.map((row) => row.key));
assert.ok(permissionRows.every((row) => row.entity_key === 'permissions'), 'descriptor permission rows must carry their entity key');
assert.ok(permissionKeys.has('users.read'), 'users permission row missing');
assert.ok(permissionKeys.has('governance.read'), 'governance permission row missing');
assert.ok(permissionKeys.has('theme_editor.admin'), 'theme editor permission row missing');

assert.deepEqual(
  buildGovernanceCatalogRows(workspaceModuleRegistry, 'admin-governance-groups'),
  [],
  'unimplemented governance scopes must not invent catalog rows',
);

const governanceSource = await readFile(path.join(root, 'src/modules/governance/pages/GovernanceCrudView.vue'), 'utf8');
assert.match(governanceSource, /buildGovernanceCatalogRows/, 'Governance CRUD must consume descriptor catalog rows');
assert.match(governanceSource, /row\?\.readonly/, 'Governance CRUD must treat descriptor catalog rows as readonly');

console.log('[module-governance-catalog-contract] PASS');
