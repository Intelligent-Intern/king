import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';

const root = path.resolve(new URL('../..', import.meta.url).pathname);

async function source(relativePath) {
  return readFile(path.join(root, relativePath), 'utf8');
}

const sessionStore = await source('src/domain/auth/session.ts');
const workspaceShell = await source('src/layouts/WorkspaceShell.vue');
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
assert.match(workspaceShell, /activeSettingsTile === 'personal\.localization'[\s\S]*<h4>\{\{ t\('settings\.language'\) \}\}<\/h4>/, 'localization settings panel must focus on language');
assert.match(workspaceShell, /activeSettingsTile === 'personal\.regional'[\s\S]*<h4>\{\{ t\('settings\.regional_time'\) \}\}<\/h4>/, 'regional settings panel must be split from language');
assert.match(workspaceShell, /:dir="settingsDraftDirection"/, 'settings dialog must apply the selected language direction');
assert.match(workspaceShell, /settingsDraftDirection = computed\(\(\) => localizationLanguageDirection\(settingsDraft\.language\)\)/, 'settings dialog direction must derive from the selected language');
assert.match(workspaceSettingsDescriptor, /key: 'personal\.regional'/, 'regional settings panel must be descriptor registered');
const localizationPanelStart = workspaceShell.indexOf("activeSettingsTile === 'personal.localization'");
const regionalPanelStart = workspaceShell.indexOf("activeSettingsTile === 'personal.regional'");
assert.ok(localizationPanelStart > 0 && regionalPanelStart > localizationPanelStart, 'localization and regional panels must be ordered settings sections');
const localizationPanelSource = workspaceShell.slice(localizationPanelStart, regionalPanelStart);
assert.doesNotMatch(localizationPanelSource, /settingsDraft\.timeFormat|settingsDraft\.dateFormat/, 'localization panel must not contain regional time controls');

assert.match(localizationOptions, /new Set\(\['ar', 'fa', 'ps', 'sgd'\]\)/, 'frontend RTL list must match website runtime');
assert.doesNotMatch(localizationOptions, /'ur'/, 'frontend RTL list must not include unsupported ur');

console.log('[localization-settings-contract] PASS');
