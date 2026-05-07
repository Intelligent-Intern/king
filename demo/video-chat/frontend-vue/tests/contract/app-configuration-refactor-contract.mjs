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
const emailTextsTable = await source('src/modules/administration/components/AppConfigurationEmailTextsTable.vue');
const emailTextEditor = await source('src/modules/administration/components/AppConfigurationEmailTextEditor.vue');
const emailTextsLogic = await source('src/modules/administration/components/useAppConfigurationEmailTexts.js');
const backgroundImages = await source('src/modules/administration/components/AppConfigurationBackgroundImagesTab.vue');
const backgroundImageGrid = await source('src/modules/administration/components/AppConfigurationBackgroundImageGrid.vue');
const backgroundImagesLogic = await source('src/modules/administration/components/useAppConfigurationBackgroundImages.js');

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

assert.match(emailTexts, /useAppConfigurationEmailTexts\(\{ t \}\)/, 'email texts tab must delegate state and persistence to the CRUD composable');
assert.match(emailTexts, /<AppConfigurationEmailTextsTable[\s\S]*@edit-row="openEdit"[\s\S]*@delete-row="deleteRow"/, 'email texts tab must render the extracted table with edit/delete wiring');
assert.match(emailTexts, /<AppConfigurationEmailTextEditor[\s\S]*@close="closeEditor"[\s\S]*@save="saveEditor"/, 'email texts tab must render the extracted editor with close/save wiring');
assert.match(emailTexts, /AppIconButton[\s\S]*icons\/send\.png/, 'email texts CRUD search must keep the standard submit icon');
assert.doesNotMatch(emailTexts, /listWorkspaceEmailTexts|updateWorkspaceEmailText|deleteWorkspaceEmailText|createWorkspaceEmailText/i, 'email texts tab wrapper must not keep raw CRUD API calls');

assert.match(emailTextsTable, /AdminTableFrame/, 'email texts table must own the table frame');
assert.match(emailTextsTable, /formatLocalizedDateTimeDisplay/, 'email texts table must own localized updated-at formatting');
assert.match(emailTextsTable, /\$emit\('edit-row', row\)/, 'email texts table must emit edit requests');
assert.match(emailTextsTable, /\$emit\('delete-row', row\)/, 'email texts table must emit delete requests');
assert.match(emailTextsTable, /row\.is_system[\s\S]*email_text_system/, 'email texts table must show system templates and protect delete affordance');

assert.match(emailTextEditor, /AppSelect[\s\S]*active[\s\S]*disabled/, 'email text editor must keep active/disabled status options');
assert.match(emailTextEditor, /body_template[\s\S]*settings-textarea/, 'email text editor must keep the body template textarea');
assert.match(emailTextEditor, /icons\/cancel\.png/, 'email text editor must keep the standard close icon');
assert.match(emailTextEditor, /btn btn-cyan[\s\S]*common\.save/, 'email text editor must keep the bottom save action');

assert.match(emailTextsLogic, /listWorkspaceEmailTexts/, 'email texts composable must load through the workspace administration API');
assert.match(emailTextsLogic, /updateWorkspaceEmailText\(form\.id, payloadFromForm\(\)\)/, 'email texts composable must save through the workspace administration API');
assert.match(emailTextsLogic, /deleteWorkspaceEmailText\(row\.id\)/, 'email texts composable must delete through the workspace administration API');
assert.match(emailTextsLogic, /function payloadFromForm\(\)/, 'email texts composable must own save payload shaping');
assert.doesNotMatch(emailTextsLogic, /createWorkspaceEmailText/i, 'email texts composable must not expose create email text in the current path');
assert.match(backgroundImages, /useAppConfigurationBackgroundImages\(\{ t \}\)/, 'background images tab must delegate state and persistence to the background-image composable');
assert.match(backgroundImages, /<AppConfigurationBackgroundImageGrid[\s\S]*@open-picker="openFilePicker"[\s\S]*@drop-files="handleDrop"[\s\S]*@edit-image="editImage"[\s\S]*@delete-image="deleteImage"/, 'background images tab must render the extracted dropzone grid with upload/edit/delete wiring');
assert.match(backgroundImages, /<BackgroundImageUploadModal/, 'background images tab must crop uploads before sending them');
assert.doesNotMatch(backgroundImages, /listWorkspaceBackgroundImages|uploadWorkspaceBackgroundImages|updateWorkspaceBackgroundImage|deleteWorkspaceBackgroundImage/, 'background images tab wrapper must not keep raw background image API calls');
assert.doesNotMatch(backgroundImages, /type="search"|background_image_search/i, 'background images tab must stay metadata-free without search');

assert.match(backgroundImageGrid, /class="background-dropzone"/, 'background image grid must expose the dropzone upload surface');
assert.match(backgroundImageGrid, /\$emit\('drop-files', \$event\)/, 'background image grid must emit file drop events');
assert.match(backgroundImageGrid, /\$emit\('edit-image', image\)/, 'background image grid must emit edit requests');
assert.match(backgroundImageGrid, /\$emit\('delete-image', image\)/, 'background image grid must emit delete requests');
assert.match(backgroundImageGrid, /function fileSizeLabel\(bytes\)/, 'background image grid must own file-size display formatting');

assert.match(backgroundImagesLogic, /listWorkspaceBackgroundImages/, 'background image composable must load through the workspace administration API');
assert.match(backgroundImagesLogic, /uploadWorkspaceBackgroundImages\(files\)/, 'background image composable must upload cropped image batches through the workspace administration API');
assert.match(backgroundImagesLogic, /updateWorkspaceBackgroundImage\(cropModal\.editImage\.id/, 'background image composable must save edited crops through the workspace administration API');
assert.match(backgroundImagesLogic, /deleteWorkspaceBackgroundImage\(image\.id\)/, 'background image composable must delete through the workspace administration API');
assert.match(backgroundImagesLogic, /files\.length > 12/, 'background image composable must keep the twelve-image bulk upload limit');

console.log('[app-configuration-refactor-contract] PASS');
