import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';

const root = path.resolve(new URL('../..', import.meta.url).pathname);

async function source(relativePath) {
  return readFile(path.join(root, relativePath), 'utf8');
}

const appSource = await source('src/App.vue');
const baseSource = await source('src/styles/base.css');
const callSettingsSource = await source('src/styles/call-settings.css');
const themeSettingsSource = await source('src/layouts/settings/useWorkspaceThemeSettings.js');

const allowedPalette = new Set([
  '#000010',
  '#00052d',
  '#1582bf',
  '#59c7f2',
  '#efefe7',
  '#ffffff',
  '#03275a',
  '#00652f',
  '#f47221',
  '#ef4423',
]);

const expectedColorDefinitions = [
  ['--color-primary-navy', '#000010'],
  ['--color-surface-navy', '#00052d'],
  ['--color-cyan-primary', '#1582bf'],
  ['--color-cyan-hover', '#59c7f2'],
  ['--color-heading', '#efefe7'],
  ['--color-text-primary', '#ffffff'],
  ['--color-text-link', '#1582bf'],
  ['--color-text-link-hover', '#59c7f2'],
  ['--color-border', '#03275a'],
  ['--color-success', '#00652f'],
  ['--color-warning', '#f47221'],
  ['--color-error', '#ef4423'],
];

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

const colorDefinitions = [...baseSource.matchAll(/(--color-[\w-]+):\s*(#[0-9a-f]{6});/gi)]
  .map((match) => [match[1], match[2].toLowerCase()]);
assert.deepEqual(colorDefinitions, expectedColorDefinitions, 'base CSS must define only the 12 KingRT styleguide color slots');

for (const [_token, value] of colorDefinitions) {
  assert.equal(allowedPalette.has(value), true, `palette value ${value} must come from the KingRT styleguide`);
}

assert.doesNotMatch(baseSource, /--color-rgba-/, 'base CSS must not define rgba color tokens');
assert.doesNotMatch(baseSource, /--color-[0-9a-f]{3,}/, 'base CSS must not define arbitrary hex-named color tokens');
assert.match(baseSource, /--bg-shell:\s*var\(--color-primary-navy\);/, 'base shell must use primary navy');
assert.match(baseSource, /--brand-bg:\s*var\(--color-primary-navy\);/, 'base sidebar must use primary navy');
assert.match(baseSource, /--bg-surface:\s*var\(--color-surface-navy\);/, 'base surfaces must use surface navy');
assert.match(baseSource, /--bg-action:\s*var\(--color-cyan-primary\);/, 'base actions must use cyan primary');
assert.match(baseSource, /--border-subtle:\s*var\(--color-border\);/, 'shared borders must use the styleguide border color');
assert.match(callSettingsSource, /\.ii-select\s*\{[\s\S]*?border:\s*1px solid var\(--border-subtle\);[\s\S]*?background-color:\s*var\(--border-subtle\);/, 'AppSelect background must use the styleguide border color');
assert.match(callSettingsSource, /\.ii-select option,[\s\S]*?\.ii-select optgroup\s*\{[\s\S]*?background:\s*var\(--border-subtle\);/, 'native select dropdown options must use the styleguide border color');

console.log('[theme-palette-contract] PASS');
