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
assert.ok(moduleRows.some((row) => row.key === 'calls'), 'calls module row missing');
assert.ok(moduleRows.some((row) => row.key === 'onboarding'), 'onboarding module row missing');
assert.ok(moduleRows.every((row) => !row.description_key), 'module rows must not render descriptions');
assert.ok(moduleRows.every((row) => typeof row.preview_kind === 'string' && row.preview_kind !== ''), 'module rows must expose screenshot preview metadata');
assert.ok(moduleRows.every((row) => Number.isInteger(row.route_count)), 'module rows must expose route counts for diagnostics');

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
