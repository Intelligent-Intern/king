import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';

const root = path.resolve(new URL('../..', import.meta.url).pathname);

async function source(relativePath) {
  return readFile(path.join(root, relativePath), 'utf8');
}

const view = await source('src/modules/localization/pages/AdministrationLocalizationView.vue');
const editor = await source('src/modules/localization/components/AdministrationLocalizationEditor.vue');

assert.match(view, /<AdministrationLocalizationEditor/, 'localization admin view must render the extracted two-column editor');
assert.match(view, /@update:left-locale="editorLeftLocale = \$event"/, 'view must keep left locale state authoritative');
assert.match(view, /@update:right-locale="editorRightLocale = \$event"/, 'view must keep right locale state authoritative');
assert.match(view, /@update-value="updateEditorValue\(\$event\.locale, \$event\.fullKey, \$event\.value\)"/, 'view must keep update-value wiring explicit');
assert.match(view, /@save="saveEditor"/, 'view must keep save wiring through the existing API path');
assert.match(view, /buildLocalizedApiError\(payload,\s*fallback,\s*response\.status\)/, 'view must keep localized backend error mapping');
assert.doesNotMatch(view, /localization-editor-grid|localization-resource-input|localization-editor-footer/, 'view must not keep the extracted editor matrix markup or styles');
assert.doesNotMatch(view, /Upload CSV|Import History|Bundles|No CSV selected|localization_csv_import/i, 'localization admin view must not reintroduce CSV/import/bundle UI');

assert.match(editor, /localization-editor-grid/, 'editor component must own the two-column matrix layout');
assert.match(editor, /localization\.admin\.left_language/, 'editor component must own the left language selector');
assert.match(editor, /localization\.admin\.right_language/, 'editor component must own the right language selector');
assert.match(editor, /translationRows[\s\S]*textarea[\s\S]*editorValue\(editorLeftLocale, row\.fullKey\)/, 'editor component must render left locale entry textareas');
assert.match(editor, /translationRows[\s\S]*textarea[\s\S]*editorValue\(editorRightLocale, row\.fullKey\)/, 'editor component must render right locale entry textareas');
assert.match(editor, /defineEmits\(\['update:left-locale', 'update:right-locale', 'update-value', 'save'\]\)/, 'editor component must expose explicit editor events');
assert.match(editor, /localization\.admin\.save_translations/, 'editor component must keep the bottom save action');

console.log('[localization-admin-editor-refactor-contract] PASS');
