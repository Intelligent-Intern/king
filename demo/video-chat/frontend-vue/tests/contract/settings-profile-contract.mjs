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
]) {
  assert.ok(ENGLISH_MESSAGES[key], `missing English settings profile key: ${key}`);
}

assert.match(sessionStore, /aboutMe: ''/, 'session state must carry about profile text');
assert.match(sessionStore, /linkedinUrl: ''/, 'session state must carry LinkedIn URL');
assert.match(sessionStore, /xUrl: ''/, 'session state must carry X.com URL');
assert.match(sessionStore, /youtubeUrl: ''/, 'session state must carry YouTube URL');
assert.match(sessionStore, /sessionState\.aboutMe = normalizeString\(user\.about_me\)/, 'session snapshot must apply about_me');
assert.doesNotMatch(sessionStore, /messengerContacts/, 'session state must not carry removed messenger contacts');
assert.match(sessionStore, /fetchSessionEmailAddresses/, 'session store must expose email address loading');
assert.match(sessionStore, /changeSessionPassword/, 'session store must expose password change');

assert.match(workspaceShell, /import WorkspaceAboutSettings from '\.\/settings\/WorkspaceAboutSettings\.vue'/, 'workspace shell must use extracted about settings panel');
assert.match(workspaceShell, /import WorkspaceCredentialsSettings from '\.\/settings\/WorkspaceCredentialsSettings\.vue'/, 'workspace shell must use extracted credentials settings panel');
assert.match(workspaceShell, /<WorkspaceAboutSettings[\s\S]*activeSettingsTile === 'personal\.about'/, 'about tab must render extracted component');
assert.match(workspaceShell, /<WorkspaceCredentialsSettings v-else-if="activeSettingsTile === 'personal\.credentials'"/, 'credentials tab must render extracted component');
assert.match(workspaceShell, /settingsDraft\.aboutMe = sessionState\.aboutMe \|\| ''/, 'settings draft must load about profile text');
assert.doesNotMatch(workspaceShell, /messenger_contacts/, 'settings save must not send removed messenger contacts');
assert.match(workspaceShell, /about_me: settingsDraft\.aboutMe/, 'settings save must send about_me');
assert.match(workspaceShell, /linkedin_url: settingsDraft\.linkedinUrl/, 'settings save must send linkedin_url');
assert.match(workspaceShell, /x_url: settingsDraft\.xUrl/, 'settings save must send x_url');
assert.match(workspaceShell, /youtube_url: settingsDraft\.youtubeUrl/, 'settings save must send youtube_url');
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
assert.match(credentialsPanel, /settings\.confirmed_emails/, 'credentials panel must expose confirmed emails');
assert.match(credentialsPanel, /settings\.unconfirmed_emails/, 'credentials panel must expose unconfirmed emails');
assert.match(credentialsPanel, /type="password"/, 'credentials panel must use password fields inside forms');

console.log('[settings-profile-contract] PASS');
