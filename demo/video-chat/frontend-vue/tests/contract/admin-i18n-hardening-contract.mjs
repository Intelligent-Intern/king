import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';
import { ENGLISH_MESSAGES } from '../../src/modules/localization/englishMessages.js';

const root = path.resolve(new URL('../..', import.meta.url).pathname);

async function source(relativePath) {
  return readFile(path.join(root, relativePath), 'utf8');
}

const governanceCatalog = await source('src/modules/governanceCatalog.js');
const governanceCrudView = await source('src/modules/governance/pages/GovernanceCrudView.vue');
const navigationBuilder = await source('src/modules/navigationBuilder.js');

for (const key of [
  'governance.catalog.module_description',
  'governance.catalog.no_permissions',
  'governance.catalog.permission_description',
  'governance.catalog.time_limited_not_supported',
  'governance.catalog.time_limited_supported',
]) {
  assert.ok(ENGLISH_MESSAGES[key], `missing governance catalog i18n key: ${key}`);
}

assert.match(governanceCatalog, /description_key: 'governance\.catalog\.module_description'/, 'module catalog rows must use description keys');
assert.match(governanceCatalog, /description_key: 'governance\.catalog\.permission_description'/, 'permission catalog rows must use description keys');
assert.doesNotMatch(governanceCatalog, /`\\$\\{descriptor\.routes\.length\\} routes`/, 'module descriptions must not concatenate English route text');
assert.doesNotMatch(governanceCatalog, /`Module: \\$\\{descriptor\.module_key\\}`/, 'permission descriptions must not concatenate English module text');

assert.match(governanceCrudView, /rowDescription\(row\)/, 'governance table must render descriptions through a localizer');
assert.match(governanceCrudView, /localizedDescriptionParams/, 'description params with *_key values must be localized');
assert.match(navigationBuilder, /pageTitle_key/, 'route records must carry title keys');
assert.match(navigationBuilder, /label_key/, 'navigation records must carry label keys');

console.log('[admin-i18n-hardening-contract] PASS');
