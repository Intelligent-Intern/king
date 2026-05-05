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
  ['--bg-shell', '#000010'],
  ['--bg-main', '#000010'],
  ['--brand-bg', '#000010'],
  ['--bg-sidebar', '#000010'],
  ['--bg-surface', '#00052d'],
  ['--bg-action', '#1582bf'],
  ['--bg-action-hover', '#59c7f2'],
  ['--border-subtle', '#03275a'],
  ['--text-main', '#ffffff'],
  ['--ok', '#00652f'],
  ['--wait', '#f47221'],
  ['--danger', '#ef4423'],
]) {
  assert.match(appSource, new RegExp(`'${token}': '${value}'`), `dark preset must keep ${token} at ${value}`);
  assert.match(themeSettingsSource, new RegExp(`key: '${token}'[\\s\\S]*default: '${value}'`), `theme editor default must keep ${token} at ${value}`);
}

assert.match(baseSource, /--color-000010:\s*#000010;/, 'base tokens must expose primary navy');
assert.match(baseSource, /--color-00052d:\s*#00052d;/, 'base tokens must expose surface navy');
assert.match(baseSource, /--color-03275a:\s*#03275a;/, 'base tokens must expose border navy');
assert.match(baseSource, /--color-1582bf:\s*#1582bf;/, 'base tokens must expose primary cyan');
assert.match(baseSource, /--color-59c7f2:\s*#59c7f2;/, 'base tokens must expose hover cyan');
assert.match(baseSource, /--bg-shell:\s*var\(--color-000010\);/, 'base shell must use primary navy');
assert.match(baseSource, /--brand-bg:\s*var\(--color-000010\);/, 'base sidebar must use primary navy');
assert.match(baseSource, /--bg-surface:\s*var\(--color-00052d\);/, 'base surfaces must use surface navy');
assert.match(baseSource, /--bg-action:\s*var\(--color-1582bf\);/, 'base actions must use cyan primary');
assert.match(baseSource, /--border-subtle:\s*var\(--line\);/, 'shared borders must stay centralized through border-subtle');

console.log('[theme-palette-contract] PASS');
