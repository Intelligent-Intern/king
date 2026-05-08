import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';
import { SUPPORTED_LOCALIZATION_LANGUAGES } from '../../src/support/localizationOptions.js';
import { ENGLISH_MESSAGES } from '../../src/modules/localization/englishMessages.js';
import {
  LOCALIZATION_FALLBACK_GAP_MARKER,
  LOCALIZATION_FALLBACK_GAP_POLICY,
} from '../../src/modules/localization/fallbackGapPolicy.js';

const root = path.resolve(new URL('../..', import.meta.url).pathname);
const repoRoot = path.resolve(root, '../../..');

function sortedUnique(values) {
  return [...new Set(values.map((value) => String(value || '').trim()).filter(Boolean))].sort();
}

const supportedLocales = sortedUnique(SUPPORTED_LOCALIZATION_LANGUAGES.map((locale) => locale.code));
const nonEnglishLocales = supportedLocales.filter((locale) => locale !== 'en');
const englishNamespaces = sortedUnique(Object.keys(ENGLISH_MESSAGES).map((key) => key.split('.')[0]));

assert.equal(
  LOCALIZATION_FALLBACK_GAP_POLICY.marker,
  LOCALIZATION_FALLBACK_GAP_MARKER,
  'fallback gap policy must use the canonical marker',
);
assert.equal(
  LOCALIZATION_FALLBACK_GAP_MARKER,
  'fallback_allowed_until_resource_editor',
  'fallback gap marker must describe the resource-editor boundary',
);
assert.equal(
  LOCALIZATION_FALLBACK_GAP_POLICY.owner,
  'admin_localization_resource_editor',
  'fallback gap ownership must remain with the admin localization resource editor',
);
assert.equal(
  LOCALIZATION_FALLBACK_GAP_POLICY.source,
  'admin_localization_resources_editor',
  'fallback gap source must point at the administration localization resource editor',
);
assert.deepEqual(
  sortedUnique(LOCALIZATION_FALLBACK_GAP_POLICY.locales),
  nonEnglishLocales,
  'every supported non-English locale must be explicitly allowed or translated',
);
assert.deepEqual(
  sortedUnique(LOCALIZATION_FALLBACK_GAP_POLICY.namespaces),
  englishNamespaces,
  'every English message namespace must be explicitly allowed or translated',
);

const importUiSource = await readFile(path.join(root, 'src/modules/localization/pages/AdministrationLocalizationView.vue'), 'utf8');
const backendModuleSource = await readFile(path.join(repoRoot, 'demo/video-chat/backend-king-php/http/module_localization.php'), 'utf8');
const backendImportContractSource = await readFile(path.join(repoRoot, 'demo/video-chat/backend-king-php/tests/localization-import-contract.php'), 'utf8');
const runtimeSource = await readFile(path.join(root, 'src/modules/localization/i18nRuntime.js'), 'utf8');

assert.match(importUiSource, /\/api\/admin\/localization\/resources/, 'resource editor save endpoint must remain wired');
assert.match(importUiSource, /\/api\/localization\/resources\?locale=/, 'resource editor must load locale resources through the runtime endpoint');
assert.doesNotMatch(importUiSource, /\/api\/admin\/localization\/imports\/commit/, 'CSV import commit endpoint must stay out of the frontend editor');
assert.match(backendModuleSource, /localization_csv_import_disabled/, 'backend must explicitly reject retired CSV import routes');
assert.match(backendModuleSource, /\/api\/admin\/localization\/imports\/preview/, 'backend must keep the CSV preview route as an explicit disabled compatibility response');
assert.match(backendModuleSource, /\/api\/admin\/localization\/imports\/commit/, 'backend must keep the CSV commit route as an explicit disabled compatibility response');
assert.match(backendImportContractSource, /localization_csv_import_disabled/, 'backend import contract must prove disabled CSV imports do not mutate resources');
assert.match(runtimeSource, /fallback_resources/, 'runtime must continue to receive English fallback resources');
assert.match(runtimeSource, /recordMissingKey\(normalizedKey\)/, 'runtime must continue tracking locale fallback misses');

console.log('[localization-fallback-gap-contract] PASS');
