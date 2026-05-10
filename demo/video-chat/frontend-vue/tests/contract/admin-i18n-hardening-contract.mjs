import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';
import { ENGLISH_MESSAGES } from '../../src/modules/localization/englishMessages.js';

const root = path.resolve(new URL('../..', import.meta.url).pathname);

async function source(relativePath) {
  return readFile(path.join(root, relativePath), 'utf8');
}

const governanceCatalog = await source('src/modules/governanceCatalog.js');
const governanceDescriptor = await source('src/modules/governance/descriptor.js');
const governanceCrudView = await source('src/modules/governance/pages/GovernanceCrudView.vue');
const moduleRegistry = await source('src/modules/moduleRegistry.js');
const navigationBuilder = await source('src/modules/navigationBuilder.js');
const workspaceNavigation = await source('src/layouts/WorkspaceNavigation.vue');
const routeActions = await source('src/modules/routeActions.js');

for (const key of [
  'governance.catalog.permission_description',
  'modules.governance',
  'modules.administration',
]) {
  assert.ok(ENGLISH_MESSAGES[key], `missing governance catalog i18n key: ${key}`);
}

assert.doesNotMatch(governanceCatalog, /description_key: 'governance\.catalog\.module_description'/, 'module catalog rows must not render descriptions');
assert.match(governanceCatalog, /preview_kind/, 'module catalog rows must expose screenshot preview metadata');
assert.match(governanceCatalog, /description_key: 'governance\.catalog\.permission_description'/, 'permission catalog rows must use description keys');
assert.match(governanceCatalog, /description_params: moduleName\.key !== '' \? \{ module_key: moduleName\.key \}/, 'permission catalog descriptions must pass localized module keys as params');
assert.doesNotMatch(governanceCatalog, /`\\$\\{descriptor\.routes\.length\\} routes`/, 'module descriptions must not concatenate English route text');
assert.doesNotMatch(governanceCatalog, /`Module: \\$\\{descriptor\.module_key\\}`/, 'permission descriptions must not concatenate English module text');
assert.doesNotMatch(governanceCatalog, /description_params:\s*\{\s*module: moduleName/, 'permission descriptions must not pass pre-rendered module names when a key exists');

assert.match(governanceCrudView, /rowDescription\(row\)/, 'governance table must render descriptions through a localizer');
assert.match(governanceCrudView, /localizedDescriptionParams/, 'description params with *_key values must be localized');
assert.doesNotMatch(governanceDescriptor, /pageTitle:\s*['"]/, 'governance routes must not keep literal page titles beside title keys');
assert.doesNotMatch(governanceDescriptor, /label:\s*['"]/, 'governance navigation must not keep literal labels beside label keys');
assert.doesNotMatch(governanceDescriptor, /['"]Nutzer['"]|['"]Gruppen['"]|['"]Audit Entry['"]/, 'governance descriptors must not carry pre-localized English/German entity labels');
assert.match(moduleRegistry, /name_key/, 'module catalog metadata must normalize localized name keys');
assert.match(moduleRegistry, /localized:[\s\S]*name/, 'module catalog metadata must expose structured localized name fields');
assert.match(navigationBuilder, /normalizeLocalizedField/, 'navigation builder must normalize structured localized fields centrally');
assert.match(navigationBuilder, /pageTitle_key/, 'route records must carry title keys');
assert.match(navigationBuilder, /label_key/, 'navigation records must carry label keys');
assert.match(navigationBuilder, /localized:[\s\S]*pageTitle/, 'route records must expose structured localized title metadata');
assert.match(workspaceNavigation, /localized\?\.label\?\.key/, 'workspace navigation must prefer structured localized label keys');
assert.match(routeActions, /localized\?\.label\?\.key/, 'route action labels must prefer structured localized label keys');

console.log('[admin-i18n-hardening-contract] PASS');
