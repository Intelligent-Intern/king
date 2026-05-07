import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';

const root = path.resolve(new URL('../..', import.meta.url).pathname);

async function source(relativePath) {
  return readFile(path.join(root, relativePath), 'utf8');
}

const appConfigurationView = await source('src/modules/administration/pages/AppConfigurationView.vue');
const emailTab = await source('src/modules/administration/components/AppConfigurationEmailTab.vue');
const emailForm = await source('src/modules/administration/components/AppConfigurationEmailSettingsForm.vue');
const emailLogic = await source('src/modules/administration/components/useAppConfigurationEmailSettings.js');
const emailTexts = await source('src/modules/administration/components/AppConfigurationEmailTextsTab.vue');
const backgroundImages = await source('src/modules/administration/components/AppConfigurationBackgroundImagesTab.vue');

assert.match(appConfigurationView, /AppConfigurationEmailTab/, 'app configuration must keep the email tab');
assert.match(appConfigurationView, /AppConfigurationEmailTextsTab/, 'app configuration must keep the email texts tab');
assert.match(appConfigurationView, /AppConfigurationBackgroundImagesTab/, 'app configuration must keep the background images tab');
assert.doesNotMatch(appConfigurationView, /lead_recipients|Lead recipients/i, 'app configuration must not show lead recipients in email settings');

assert.match(emailTab, /useAppConfigurationEmailSettings\(\{ t \}\)/, 'email tab must delegate state and persistence to the settings composable');
assert.match(emailTab, /<AppConfigurationEmailSettingsForm v-if="isPrimaryAdmin" :draft="draft" \/>/, 'email tab must render the extracted form only for the primary admin');
assert.match(emailTab, /primary_admin_only_email/, 'email tab must keep the primary-admin-only guard');
assert.doesNotMatch(emailTab, /loadWorkspaceAdministration|saveWorkspaceAdministration|mail_smtp_password:/, 'email tab wrapper must not keep raw API or form-field state');

assert.match(emailForm, /administration\.mail_server/, 'email form must keep the mail server section');
assert.match(emailForm, /v-model\.trim="draft\.mail_from_email"/, 'email form must expose sender email');
assert.match(emailForm, /v-model\.trim="draft\.mail_smtp_host"/, 'email form must expose SMTP host');
assert.match(emailForm, /type="password"[\s\S]*draft\.mail_smtp_password_set/, 'email form must keep password keep-placeholder behavior');
assert.match(emailForm, /mail_smtp_password_clear/, 'email form must keep clear saved password behavior');
assert.match(emailForm, /AppSelect[\s\S]*starttls[\s\S]*ssl[\s\S]*none/, 'email form must keep the supported encryption options');

assert.match(emailLogic, /loadWorkspaceAdministration/, 'email settings composable must load the workspace administration settings');
assert.match(emailLogic, /saveWorkspaceAdministration\(buildPayload\(\)\)/, 'email settings composable must save through the workspace administration API');
assert.match(emailLogic, /Number\(sessionState\.userId \|\| 0\) === 1/, 'email settings composable must enforce primary admin visibility');
assert.match(emailLogic, /mail_smtp_password_clear: draft\.mail_smtp_password_clear/, 'email settings payload must keep password-clear intent');
assert.match(emailLogic, /if \(String\(draft\.mail_smtp_password \|\| ''\)\.trim\(\) !== ''\)/, 'email settings payload must only send SMTP password when it is explicitly provided');
assert.match(emailLogic, /draft\.mail_smtp_password = '';/, 'email settings load must never rehydrate the saved SMTP password into cleartext');

assert.match(emailTexts, /AppIconButton[\s\S]*icons\/send\.png/, 'email texts CRUD search must keep the standard submit icon');
assert.doesNotMatch(emailTexts, /create email text|createWorkspaceEmailText/i, 'email texts tab must not expose create email text in the current path');
assert.match(backgroundImages, /class="background-dropzone"/, 'background images tab must expose the dropzone upload surface');
assert.match(backgroundImages, /<BackgroundImageUploadModal/, 'background images tab must crop uploads before sending them');
assert.doesNotMatch(backgroundImages, /type="search"|background_image_search/i, 'background images tab must stay metadata-free without search');

console.log('[app-configuration-refactor-contract] PASS');
