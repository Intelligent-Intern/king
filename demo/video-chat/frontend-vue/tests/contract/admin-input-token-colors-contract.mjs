import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';

const root = path.resolve(new URL('../..', import.meta.url).pathname);

async function source(relativePath) {
  return readFile(path.join(root, relativePath), 'utf8');
}

function blockAfter(fileSource, selectorPattern, label) {
  const match = selectorPattern.exec(fileSource);
  assert.ok(match, `${label} selector missing`);
  const openIndex = fileSource.indexOf('{', match.index);
  assert.ok(openIndex >= 0, `${label} rule body missing`);

  let depth = 0;
  for (let index = openIndex; index < fileSource.length; index += 1) {
    if (fileSource[index] === '{') depth += 1;
    if (fileSource[index] === '}') {
      depth -= 1;
      if (depth === 0) return fileSource.slice(openIndex + 1, index);
    }
  }

  throw new Error(`${label} rule body is not closed`);
}

function assertDirectStyleguideVars(block, label) {
  const vars = [...block.matchAll(/var\((--[^)]+)\)/g)].map((match) => match[1]);
  const nonStyleguide = vars.filter((token) => !token.startsWith('--color-'));
  assert.deepEqual(nonStyleguide, [], `${label} must consume direct --color-* styleguide tokens only`);
}

const baseSource = await source('src/styles/base.css');
const workspaceSharedSource = await source('src/styles/workspace-shared.css');
const callSettingsSource = await source('src/styles/call-settings.css');
const relationStackSource = await source('src/modules/governance/components/CrudRelationStack.vue');
const relationPickerTableSource = await source('src/modules/governance/components/CrudRelationPickerTable.vue');

assert.match(
  baseSource,
  /input\[type='text'\],[\s\S]*?input\[type='search'\]\s*\{[\s\S]*?background-color:\s*var\(--color-border\);/,
  'plain text/search inputs must use a direct King styleguide token background',
);
assert.match(
  baseSource,
  /input\[type='checkbox'\],[\s\S]*?input\[type='radio'\]\s*\{[\s\S]*?accent-color:\s*var\(--color-cyan-primary\);/,
  'checkbox/radio inputs must use a direct King styleguide accent token',
);

for (const [label, fileSource, selectorPattern] of [
  ['shared .input/.select', workspaceSharedSource, /\.input,\s*[\r\n]+\.select\s*\{/],
  ['shared .input placeholder', workspaceSharedSource, /\.input::placeholder\s*\{/],
  ['shared .input/.select focus', workspaceSharedSource, /\.input:focus,\s*[\r\n]+\.select:focus\s*\{/],
  ['shared date/time inputs', workspaceSharedSource, /\.input\[type='date'\],[\s\S]*?\.input\[type='datetime-local'\]\s*\{/],
  ['shared date/time focus', workspaceSharedSource, /\.input\[type='date'\]:focus,[\s\S]*?\.input\[type='datetime-local'\]:focus\s*\{/],
  ['AppSelect root', callSettingsSource, /\.ii-select\s*\{/],
  ['AppSelect focus', callSettingsSource, /\.ii-select:focus\s*\{/],
  ['AppSelect options', callSettingsSource, /\.ii-select option,[\s\S]*?\.ii-select optgroup\s*\{/],
  ['AppSelect selected options', callSettingsSource, /\.ii-select option:checked,[\s\S]*?\.ii-select option:hover\s*\{/],
  ['Governance relation search input', relationStackSource, /\.crud-relation-search-field \.input\s*\{/],
]) {
  assertDirectStyleguideVars(blockAfter(fileSource, selectorPattern, label), label);
}

assert.match(
  relationPickerTableSource,
  /\.crud-relation-check\s*\{[\s\S]*?accent-color:\s*var\(--color-cyan-primary\);/,
  'Governance relation checkbox inputs must use the King styleguide cyan accent token',
);

const migratedSurfaceFiles = [
  'src/components/admin/AdminSearchToolbar.vue',
  'src/modules/governance/components/GovernanceCrudToolbar.vue',
  'src/modules/governance/components/CrudRelationStack.vue',
  'src/modules/governance/components/CrudRelationPickerTable.vue',
  'src/modules/governance/pages/GovernanceCrudModal.vue',
  'src/modules/governance/pages/GovernanceOrganizationsView.vue',
  'src/modules/governance/pages/GovernanceCrudView.css',
  'src/modules/users/pages/admin/UsersView.css',
  'src/modules/users/pages/components/UserEditorModal.vue',
  'src/modules/marketplace/pages/AdminMarketplaceView.css',
  'src/modules/marketplace/pages/AdminMarketplaceView.vue',
  'src/modules/administration/components/AppConfigurationEmailTextsTab.vue',
  'src/modules/administration/components/AppConfigurationEmailTextEditor.vue',
  'src/modules/administration/components/AppConfigurationEmailSettingsForm.vue',
  'src/modules/localization/components/AdministrationLocalizationEditor.vue',
];

const rawColorPattern = /#[0-9a-fA-F]{3,8}\b|rgba?\([^)]*\)|hsla?\([^)]*\)/g;
const legacyColorAliasPattern = /var\(--(?:bg-soft|accent-cyan)\)/g;
const rawColorViolations = [];
const legacyAliasViolations = [];

for (const relativePath of migratedSurfaceFiles) {
  const fileSource = await source(relativePath);
  for (const match of fileSource.matchAll(rawColorPattern)) {
    rawColorViolations.push(`${relativePath}: ${match[0]}`);
  }
  for (const match of fileSource.matchAll(legacyColorAliasPattern)) {
    legacyAliasViolations.push(`${relativePath}: ${match[0]}`);
  }
}

assert.deepEqual(
  rawColorViolations,
  [],
  `migrated Admin/Governance input surfaces must not add hard-coded raw colors:\n${rawColorViolations.join('\n')}`,
);
assert.deepEqual(
  legacyAliasViolations,
  [],
  `migrated Admin/Governance color surfaces must not consume legacy non-token aliases:\n${legacyAliasViolations.join('\n')}`,
);

console.log('[admin-input-token-colors-contract] PASS');
