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

for (const key of [
  'settings.about_me',
  'settings.linkedin_url',
  'settings.x_url',
  'settings.youtube_url',
  'settings.messenger_contacts',
  'settings.add_messenger_contact',
  'settings.remove_messenger_contact',
]) {
  assert.ok(ENGLISH_MESSAGES[key], `missing English settings profile key: ${key}`);
}

assert.match(sessionStore, /aboutMe: ''/, 'session state must carry about profile text');
assert.match(sessionStore, /linkedinUrl: ''/, 'session state must carry LinkedIn URL');
assert.match(sessionStore, /xUrl: ''/, 'session state must carry X.com URL');
assert.match(sessionStore, /youtubeUrl: ''/, 'session state must carry YouTube URL');
assert.match(sessionStore, /messengerContacts: \[\]/, 'session state must carry messenger contacts');
assert.match(sessionStore, /sessionState\.aboutMe = normalizeString\(user\.about_me\)/, 'session snapshot must apply about_me');
assert.match(sessionStore, /sessionState\.messengerContacts = normalizeMessengerContacts\(user\.messenger_contacts\)/, 'session snapshot must apply messenger contacts');

assert.match(workspaceShell, /import WorkspaceAboutSettings from '\.\/settings\/WorkspaceAboutSettings\.vue'/, 'workspace shell must use extracted about settings panel');
assert.match(workspaceShell, /<WorkspaceAboutSettings[\s\S]*activeSettingsTile === 'personal\.about'/, 'about tab must render extracted component');
assert.match(workspaceShell, /settingsDraft\.aboutMe = sessionState\.aboutMe \|\| ''/, 'settings draft must load about profile text');
assert.match(workspaceShell, /messenger_contacts: messengerContacts/, 'settings save must send messenger contacts');
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
assert.match(aboutPanel, /settings\.messenger_contacts/, 'about settings panel must expose messenger contacts');
assert.match(aboutPanel, /addMessengerContact/, 'about settings panel must support adding messenger rows');
assert.match(aboutPanel, /removeMessengerContact/, 'about settings panel must support removing messenger rows');

console.log('[settings-profile-contract] PASS');
