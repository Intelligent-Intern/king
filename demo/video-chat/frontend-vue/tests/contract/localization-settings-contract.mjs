import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';

const root = path.resolve(new URL('../..', import.meta.url).pathname);

async function source(relativePath) {
  return readFile(path.join(root, relativePath), 'utf8');
}

const sessionStore = await source('src/domain/auth/session.ts');
const workspaceShell = await source('src/layouts/WorkspaceShell.vue');
const workspaceLocalizationSettings = await source('src/layouts/settings/WorkspaceLocalizationSettings.vue');
const localizationOptions = await source('src/support/localizationOptions.js');
const workspaceSettingsDescriptor = await source('src/modules/workspace_settings/descriptor.js');

assert.match(sessionStore, /locale: 'en'/, 'session state must default user locale to en');
assert.match(sessionStore, /direction: 'ltr'/, 'session state must default direction to ltr');
assert.match(sessionStore, /supportedLocales: SUPPORTED_LOCALIZATION_LANGUAGES/, 'session state must expose locale metadata');
assert.match(sessionStore, /sessionState\.locale = normalizeLocalizationLanguage\(user\.locale\)/, 'session snapshot must apply backend locale');
assert.match(sessionStore, /sessionState\.supportedLocales = normalizeSupportedLocales\(user\.supported_locales\)/, 'session snapshot must apply backend supported locale metadata');

assert.match(workspaceShell, /sessionState\.supportedLocales/, 'settings language select must use backend supported locale metadata');
assert.match(workspaceShell, /settingsDraft\.language = normalizeSettingsLanguage\(sessionState\.locale \|\| 'en'\)/, 'settings draft must prefer backend locale');
assert.match(workspaceShell, /locale: language/, 'settings save must send locale to backend');
assert.doesNotMatch(workspaceShell, /settingsDraft\.language = readStoredSettingsLanguage\(\)/, 'settings draft must not be localStorage-only');
assert.doesNotMatch(workspaceShell, /SETTINGS_LANGUAGE_STORAGE_KEY|storeSettingsLanguage|readStoredSettingsLanguage/, 'settings language must not use localStorage as source of truth');
assert.match(workspaceShell, /ensureI18nResources\(\{ locale: savedLanguage, force: true \}\)/, 'settings save must refresh i18n resources immediately');
assert.match(workspaceShell, /<WorkspaceLocalizationSettings[\s\S]*activeSettingsTile === 'personal\.localization'[\s\S]*:draft="settingsDraft"[\s\S]*:language-options="settingsLanguageOptions"[\s\S]*:date-format-options="dateFormatOptions"/, 'workspace shell must delegate the merged localization settings panel');
assert.match(workspaceLocalizationSettings, /v-model="draft\.language"[\s\S]*v-model="draft\.dateFormat"[\s\S]*v-model="draft\.timeFormat"/, 'localization settings panel must combine language, date, and time controls');
assert.doesNotMatch(workspaceShell, /activeSettingsTile === 'personal\.regional'/, 'regional settings panel must be merged into localization');
assert.match(workspaceShell, /:dir="settingsDraftDirection"/, 'settings dialog must apply the selected language direction');
assert.match(workspaceShell, /settingsDraftDirection = computed\(\(\) => localizationLanguageDirection\(settingsDraft\.language\)\)/, 'settings dialog direction must derive from the selected language');
assert.doesNotMatch(workspaceSettingsDescriptor, /key: 'personal\.regional'/, 'regional settings panel must not be descriptor registered');
const localizationPanelStart = workspaceShell.indexOf("activeSettingsTile === 'personal.localization'");
assert.ok(localizationPanelStart > 0, 'localization panel must exist');
const localizationPanelSource = workspaceShell.slice(localizationPanelStart, workspaceShell.indexOf('<section v-else', localizationPanelStart + 1));
assert.doesNotMatch(localizationPanelSource, /settings\.application_language|settings\.text_direction|<h4>/, 'localization panel must not show redundant headings or text direction fields');
assert.doesNotMatch(workspaceLocalizationSettings, /settings\.application_language|settings\.text_direction|<h4>/, 'extracted localization panel must not show redundant headings or text direction fields');

assert.match(localizationOptions, /new Set\(\['ar', 'fa', 'ps', 'sgd'\]\)/, 'frontend RTL list must match website runtime');
assert.doesNotMatch(localizationOptions, /'ur'/, 'frontend RTL list must not include unsupported ur');

console.log('[localization-settings-contract] PASS');
