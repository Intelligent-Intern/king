import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';
import { ENGLISH_MESSAGES } from '../../src/modules/localization/englishMessages.js';

const root = path.resolve(new URL('../..', import.meta.url).pathname);

async function source(relativePath) {
  return readFile(path.join(root, relativePath), 'utf8');
}

const sessionStore = await source('src/domain/auth/session.ts');
const workspaceShell = await source('src/layouts/WorkspaceShell.vue');
const aboutPanel = await source('src/layouts/settings/WorkspaceAboutSettings.vue');
const credentialsPanel = await source('src/layouts/settings/WorkspaceCredentialsSettings.vue');
const credentialsLogic = await source('src/layouts/settings/useWorkspaceCredentialsSettings.js');
const emailSettingsPanel = await source('src/layouts/settings/WorkspaceEmailAddressSettings.vue');
const passwordSettingsForm = await source('src/layouts/settings/WorkspacePasswordSettingsForm.vue');
const localizationSettingsPanel = await source('src/layouts/settings/WorkspaceLocalizationSettings.vue');
const notificationPanel = await source('src/layouts/settings/WorkspaceNotificationSettings.vue');

for (const key of [
  'settings.about_me',
  'settings.linkedin_url',
  'settings.x_url',
  'settings.youtube_url',
  'settings.confirmed_emails',
  'settings.unconfirmed_emails',
  'settings.add_email_address',
  'settings.current_password',
  'settings.new_password',
  'settings.repeat_new_password',
  'settings.change_password',
  'settings.notifications',
  'settings.web_app_notifications',
  'settings.enable_web_app_notifications',
  'settings.notification_call_invites',
  'settings.notification_call_reminders',
  'settings.notification_chat_mentions',
  'settings.notification_sound',
  'settings.request_browser_notifications',
]) {
  assert.ok(ENGLISH_MESSAGES[key], `missing English settings profile key: ${key}`);
}

assert.match(sessionStore, /aboutMe: ''/, 'session state must carry about profile text');
assert.match(sessionStore, /linkedinUrl: ''/, 'session state must carry LinkedIn URL');
assert.match(sessionStore, /xUrl: ''/, 'session state must carry X.com URL');
assert.match(sessionStore, /youtubeUrl: ''/, 'session state must carry YouTube URL');
assert.match(sessionStore, /webAppNotificationsEnabled: false/, 'session state must carry web app notification master switch');
assert.match(sessionStore, /webAppNotificationSoundEnabled: true/, 'session state must carry web app notification sound switch');
assert.match(sessionStore, /sessionState\.aboutMe = normalizeString\(user\.about_me\)/, 'session snapshot must apply about_me');
assert.match(sessionStore, /sessionState\.webAppNotificationsEnabled = normalizeBooleanPreference\(user\.web_app_notifications_enabled, false\)/, 'session snapshot must apply web app notification master switch');
assert.doesNotMatch(sessionStore, /messengerContacts/, 'session state must not carry removed messenger contacts');
assert.match(sessionStore, /fetchSessionEmailAddresses/, 'session store must expose email address loading');
assert.match(sessionStore, /changeSessionPassword/, 'session store must expose password change');

assert.match(workspaceShell, /import WorkspaceAboutSettings from '\.\/settings\/WorkspaceAboutSettings\.vue'/, 'workspace shell must use extracted about settings panel');
assert.match(workspaceShell, /import WorkspaceCredentialsSettings from '\.\/settings\/WorkspaceCredentialsSettings\.vue'/, 'workspace shell must use extracted credentials settings panel');
assert.match(workspaceShell, /import WorkspaceLocalizationSettings from '\.\/settings\/WorkspaceLocalizationSettings\.vue'/, 'workspace shell must use extracted localization settings panel');
assert.match(workspaceShell, /import WorkspaceNotificationSettings from '\.\/settings\/WorkspaceNotificationSettings\.vue'/, 'workspace shell must use extracted notification settings panel');
assert.match(workspaceShell, /<WorkspaceAboutSettings[\s\S]*activeSettingsTile === 'personal\.about'/, 'about tab must render extracted component');
assert.match(workspaceShell, /<WorkspaceCredentialsSettings v-else-if="activeSettingsTile === 'personal\.credentials'"/, 'credentials tab must render extracted component');
assert.match(workspaceShell, /<WorkspaceLocalizationSettings[\s\S]*activeSettingsTile === 'personal\.localization'/, 'localization tab must render extracted component');
assert.match(workspaceShell, /<WorkspaceNotificationSettings[\s\S]*activeSettingsTile === 'personal\.notifications'/, 'notifications tab must render extracted component');
assert.match(workspaceShell, /settingsDraft\.aboutMe = sessionState\.aboutMe \|\| ''/, 'settings draft must load about profile text');
assert.match(workspaceShell, /settingsDraft\.webAppNotificationsEnabled = sessionState\.webAppNotificationsEnabled === true/, 'settings draft must load web app notification switch');
assert.doesNotMatch(workspaceShell, /messenger_contacts/, 'settings save must not send removed messenger contacts');
assert.match(workspaceShell, /about_me: settingsDraft\.aboutMe/, 'settings save must send about_me');
assert.match(workspaceShell, /linkedin_url: settingsDraft\.linkedinUrl/, 'settings save must send linkedin_url');
assert.match(workspaceShell, /x_url: settingsDraft\.xUrl/, 'settings save must send x_url');
assert.match(workspaceShell, /youtube_url: settingsDraft\.youtubeUrl/, 'settings save must send youtube_url');
assert.match(workspaceShell, /web_app_notifications_enabled: settingsDraft\.webAppNotificationsEnabled === true/, 'settings save must send web app notification switch');
assert.doesNotMatch(
  workspaceShell,
  /<section v-if="activeSettingsTile === 'personal\.about'" class="settings-panel">/,
  'workspace shell must not own the large about settings markup',
);

assert.match(aboutPanel, /settings\.about_me/, 'about settings panel must expose about text');
assert.match(aboutPanel, /settings\.linkedin_url/, 'about settings panel must expose LinkedIn URL');
assert.match(aboutPanel, /settings\.x_url/, 'about settings panel must expose X.com URL');
assert.match(aboutPanel, /settings\.youtube_url/, 'about settings panel must expose YouTube URL');
assert.doesNotMatch(aboutPanel, /settings\.email/, 'about settings panel must not expose email address');
assert.doesNotMatch(aboutPanel, /messenger/i, 'about settings panel must not expose messenger contacts');
assert.doesNotMatch(aboutPanel, /onboarding/i, 'about settings panel must not expose onboarding badges');
assert.match(credentialsPanel, /WorkspaceEmailAddressSettings/, 'credentials panel must delegate email address lists to the extracted email settings section');
assert.match(credentialsPanel, /WorkspacePasswordSettingsForm/, 'credentials panel must delegate password changes to the extracted password form');
assert.match(credentialsPanel, /useWorkspaceCredentialsSettings\(\{ t \}\)/, 'credentials panel must use the shared credentials composable');
assert.match(credentialsLogic, /fetchSessionEmailAddresses/, 'credentials composable must load verified and pending email addresses');
assert.match(credentialsLogic, /changeSessionPassword/, 'credentials composable must submit password changes');
assert.match(credentialsLogic, /confirmedEmails = computed/, 'credentials composable must expose confirmed emails');
assert.match(credentialsLogic, /unconfirmedEmails = computed/, 'credentials composable must expose unconfirmed emails');
assert.match(emailSettingsPanel, /settings\.confirmed_emails/, 'email settings section must expose confirmed emails');
assert.match(emailSettingsPanel, /settings\.unconfirmed_emails/, 'email settings section must expose unconfirmed emails');
assert.match(emailSettingsPanel, /settings\.add_email_address/, 'email settings section must expose add email action');
assert.match(passwordSettingsForm, /type="password"/, 'password settings form must use password fields inside forms');
assert.match(passwordSettingsForm, /settings\.current_password/, 'password settings form must expose current password');
assert.match(passwordSettingsForm, /settings\.change_password/, 'password settings form must expose the change password submit action');
assert.match(localizationSettingsPanel, /settings\.language/, 'localization settings panel must expose language');
assert.match(localizationSettingsPanel, /settings\.date_format/, 'localization settings panel must expose date format');
assert.match(localizationSettingsPanel, /settings\.time_format/, 'localization settings panel must expose time format');
assert.match(notificationPanel, /Notification\.requestPermission/, 'notification panel must request browser notification permission');
assert.match(notificationPanel, /draft\.webAppNotificationsEnabled/, 'notification panel must expose web app notification master switch');
assert.match(notificationPanel, /draft\.webAppNotificationCallInvitesEnabled/, 'notification panel must expose call invite notification switch');
assert.match(notificationPanel, /draft\.webAppNotificationCallRemindersEnabled/, 'notification panel must expose call reminder notification switch');
assert.match(notificationPanel, /draft\.webAppNotificationChatMentionsEnabled/, 'notification panel must expose chat mention notification switch');

console.log('[settings-profile-contract] PASS');
