import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';
import { ENGLISH_MESSAGES } from '../../src/modules/localization/englishMessages.js';

const root = path.resolve(new URL('../..', import.meta.url).pathname);
const sourceFiles = [
  'src/layouts/WorkspaceNavigation.vue',
  'src/layouts/WorkspaceShell.vue',
  'src/components/AppPageHeader.vue',
  'src/components/AppPagination.vue',
  'src/modules/administration/descriptor.js',
  'src/modules/administration/pages/AppConfigurationView.vue',
  'src/modules/governance/descriptor.js',
  'src/modules/governance/pages/GovernanceCrudModal.vue',
  'src/modules/governance/pages/GovernanceCrudView.vue',
  'src/modules/localization/descriptor.js',
  'src/modules/localization/pages/AdministrationLocalizationView.vue',
  'src/modules/marketplace/descriptor.js',
  'src/modules/theme_editor/descriptor.js',
  'src/modules/theme_editor/pages/ThemeEditorView.vue',
  'src/modules/users/descriptor.js',
  'src/modules/workspace_settings/descriptor.js',
];

const usedKeys = new Set();
for (const relativePath of sourceFiles) {
  const source = await readFile(path.join(root, relativePath), 'utf8');
  for (const match of source.matchAll(/\bt\(\s*['"]([a-z0-9_.-]+)['"]/g)) {
    usedKeys.add(match[1]);
  }
  for (const match of source.matchAll(/label_key:\s*['"]([a-z0-9_.-]+)['"]/g)) {
    usedKeys.add(match[1]);
  }
}

assert.ok(usedKeys.size > 0, 'translation key usage scan should find keys');
for (const key of [...usedKeys].sort()) {
  assert.ok(Object.prototype.hasOwnProperty.call(ENGLISH_MESSAGES, key), `missing English fallback for ${key}`);
}

console.log('[frontend-translation-key-coverage-contract] PASS');
