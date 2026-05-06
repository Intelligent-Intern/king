import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';

const root = path.resolve(new URL('../..', import.meta.url).pathname);

async function source(relativePath) {
  return readFile(path.join(root, relativePath), 'utf8');
}

const themeEditor = await source('src/modules/theme_editor/pages/ThemeEditorView.vue');
const themeSettings = await source('src/layouts/settings/WorkspaceThemeSettings.vue');
const themeSettingsLogic = await source('src/layouts/settings/useWorkspaceThemeSettings.js');
const themePreview = await source('src/layouts/settings/WorkspaceThemePreview.vue');
const themePreviewApp = await source('src/layouts/settings/WorkspaceThemePreviewApp.vue');
const themePreviewRoute = await source('src/layouts/settings/WorkspaceThemePreviewRoute.vue');
const router = await source('src/http/router.ts');

assert.match(themeEditor, /<template #actions>/, 'theme editor create action must live in the page header action slot');
assert.match(themeEditor, /themeSettingsRef\.value\?\.startCreateTheme/, 'header create action must open the in-page theme editor');
assert.match(themeEditor, /<WorkspaceThemeSettings ref="themeSettingsRef"[\s\S]*management-only/, 'theme editor page must render management in the main area');

assert.match(themeSettings, /settings-theme-card-grid/, 'theme management must render preview cards');
assert.match(themeSettings, /<WorkspaceThemePreview[\s\S]*compact[\s\S]*:colors="theme\.colors"/, 'each theme card must include a compact live preview');
assert.match(themeSettings, /settings-theme-card-actions[\s\S]*theme_settings\.edit_theme/, 'each preview card must expose an edit button below the preview');
assert.doesNotMatch(themeSettings, /settings-theme-list|settings-theme-row/, 'theme management must not regress to a row list');
assert.doesNotMatch(themeSettings, /settings-wizard|settings-theme-wizard|editor\.step/, 'theme editor must not use the removed wizard flow');

assert.match(themeSettings, /settings-theme-editor-sidebar/, 'theme editor must provide a second left-side editor sidebar');
assert.match(themeSettings, /setEditorPanel\('chat'\)/, 'theme editor must provide a chat tab');
assert.match(themeSettings, /setEditorPanel\('colors'\)/, 'theme editor must provide a colors tab');
assert.match(themeSettings, /setEditorPanel\('images'\)/, 'theme editor must provide an images tab');
assert.match(themeSettings, /settings-theme-editor-preview-pane[\s\S]*settings-theme-live-preview/, 'theme editor must keep the live preview beside the editor sidebar');
assert.match(themeSettings, /settings-theme-live-preview[\s\S]*interactive/, 'theme editor live preview must use the iframe sandbox mode');

assert.match(themeSettingsLogic, /const themePrompt = ref\(''\)/, 'theme chat prompt state must be part of the composable');
assert.match(themeSettingsLogic, /function applyThemePrompt\(\)/, 'theme chat prompt must apply deterministic preview changes');
assert.match(themeSettingsLogic, /panel: 'colors'/, 'theme editor must track its active panel explicitly');

assert.match(themePreview, /<iframe[\s\S]*src="\/theme-preview-sandbox"/, 'theme editor preview must embed the sandbox route in an iframe');
assert.match(themePreview, /postMessage\(\{[\s\S]*kingrt-theme-preview-state/, 'theme editor preview must stream theme state into the iframe sandbox');
assert.match(themePreview, /<WorkspaceThemePreviewApp[\s\S]*:interactive="false"/, 'theme card previews must use a non-interactive app snapshot');
assert.match(themePreviewRoute, /kingrt-theme-preview-ready/, 'theme preview route must announce readiness to the parent editor');
assert.match(themePreviewRoute, /kingrt-theme-preview-state/, 'theme preview route must receive live theme state');
assert.match(router, /path:\s*'\/theme-preview-sandbox'[\s\S]*meta:\s*\{\s*public:\s*true\s*\}/, 'theme preview sandbox route must be public and isolated from auth redirects');
assert.match(themePreviewApp, /const activeSection = ref\('calls'\)/, 'theme preview app must keep local navigation state');
assert.match(themePreviewApp, /function selectSection\(item\)/, 'theme preview app sidebar navigation must be clickable');
assert.match(themePreviewApp, /rowsBySection/, 'theme preview app must switch displayed rows when navigating');
assert.match(themePreviewApp, /sidePanel = reactive/, 'theme preview app must open right sidebars for form surfaces');
assert.match(themePreviewApp, /modal = reactive/, 'theme preview app must open modal surfaces');
assert.match(themePreviewApp, /theme-editor-disabled/, 'theme preview app must include but disable the theme editor link');
assert.match(themePreviewApp, /\.theme-preview-app\.compact/, 'theme preview app must have a compact card mode');

console.log('[theme-management-workspace-contract] PASS');
