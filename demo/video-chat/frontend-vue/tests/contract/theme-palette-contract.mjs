import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';

const root = path.resolve(new URL('../..', import.meta.url).pathname);

async function source(relativePath) {
  return readFile(path.join(root, relativePath), 'utf8');
}

const appSource = await source('src/App.vue');
const baseSource = await source('src/styles/base.css');
const themeSettingsSource = await source('src/layouts/settings/useWorkspaceThemeSettings.js');

for (const [token, value] of [
  ['--bg-shell', '#101b33'],
  ['--bg-main', '#101b33'],
  ['--brand-bg', '#1482be'],
  ['--bg-sidebar', '#1482be'],
  ['--border-subtle', '#1d315c'],
]) {
  assert.match(appSource, new RegExp(`'${token}': '${value}'`), `dark preset must keep ${token} at ${value}`);
  assert.match(themeSettingsSource, new RegExp(`key: '${token}'[\\s\\S]*default: '${value}'`), `theme editor default must keep ${token} at ${value}`);
}

assert.match(baseSource, /--color-101b33:\s*#101b33;/, 'base tokens must expose the shell color');
assert.match(baseSource, /--color-1d315c:\s*#1d315c;/, 'base tokens must expose the border color');
assert.match(baseSource, /--bg-shell:\s*var\(--color-101b33\);/, 'base shell must use the requested color');
assert.match(baseSource, /--brand-bg:\s*var\(--color-1482be\);/, 'base sidebar brand must use the requested color');
assert.match(baseSource, /--border-subtle:\s*var\(--line\);/, 'shared borders must stay centralized through border-subtle');

console.log('[theme-palette-contract] PASS');
