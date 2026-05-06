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
const paletteSource = await source('src/domain/workspace/styleguidePalette.js');
const workspaceSharedSource = await source('src/styles/workspace-shared.css');
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

const colorDefinitions = [...baseSource.matchAll(/(--color-[\w-]+):\s*(#[0-9a-f]{6});/gi)]
  .map((match) => [match[1], match[2].toLowerCase()]);
assert.deepEqual(colorDefinitions, expectedColorDefinitions, 'base CSS must define only the 12 KingRT styleguide color slots');

for (const [_token, value] of colorDefinitions) {
  assert.equal(allowedPalette.has(value), true, `palette value ${value} must come from the KingRT styleguide`);
}

assert.doesNotMatch(baseSource, /--color-rgba-/, 'base CSS must not define rgba color tokens');
assert.doesNotMatch(baseSource, /--color-[0-9a-f]{3,}/, 'base CSS must not define arbitrary hex-named color tokens');
assert.doesNotMatch(appSource, /const THEME_PRESETS/, 'App.vue must not carry legacy theme alias presets');
assert.match(appSource, /STYLEGUIDE_DERIVED_COLOR_KEYS/, 'App.vue must clear legacy derived theme aliases before applying a theme');
assert.match(themeSettingsSource, /STYLEGUIDE_COLOR_FIELDS as THEME_COLOR_FIELDS/, 'theme editor must use the styleguide field list');
assert.match(themeSettingsSource, /normalizeStyleguideThemeColors/, 'theme editor must normalize persisted themes to styleguide color slots');

const editableThemeKeys = [...paletteSource.matchAll(/\{\s*key:\s*'(--color-[^']+)'/g)].map((match) => match[1]);
assert.deepEqual(
  editableThemeKeys,
  expectedColorDefinitions.map(([token]) => token),
  'theme editor must expose exactly the 12 root styleguide color slots',
);

const legacyThemeKeys = [
  '--bg-shell',
  '--bg-pane',
  '--brand-bg',
  '--bg-surface',
  '--bg-surface-strong',
  '--bg-input',
  '--bg-action',
  '--bg-action-hover',
  '--bg-row',
  '--bg-row-hover',
  '--line',
  '--text-main',
  '--text-muted',
  '--ok',
  '--wait',
  '--danger',
  '--bg-sidebar',
  '--bg-main',
  '--bg-tab',
  '--bg-tab-hover',
  '--bg-tab-active',
  '--bg-ui-chrome',
  '--bg-ui-chrome-active',
  '--bg-icon',
  '--bg-icon-active',
  '--border-subtle',
  '--text-primary',
  '--text-secondary',
  '--text-dim',
  '--warn',
  '--brand-cyan',
  '--brand-cyan-hover',
  '--brand-cyan-active',
];

for (const token of legacyThemeKeys) {
  const escapedToken = token.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  assert.doesNotMatch(
    themeSettingsSource,
    new RegExp(`key:\\s*'${escapedToken}'`),
    `theme editor must not expose derived alias ${token}`,
  );
}

assert.match(baseSource, /--bg-shell:\s*var\(--color-primary-navy\);/, 'base shell must use primary navy');
assert.match(baseSource, /--brand-bg:\s*var\(--color-primary-navy\);/, 'base sidebar must use primary navy');
assert.match(baseSource, /--bg-surface:\s*var\(--color-surface-navy\);/, 'base surfaces must use surface navy');
assert.match(baseSource, /--bg-action:\s*var\(--color-cyan-primary\);/, 'base actions must use cyan primary');
assert.match(baseSource, /--border-subtle:\s*var\(--color-border\);/, 'shared borders must use the styleguide border color');
assert.match(baseSource, /--bg-input:\s*var\(--color-border\);/, 'text input background must be derived from the border color');
assert.match(
  baseSource,
  /input\[type='text'\],[\s\S]*?input\[type='search'\]\s*\{[\s\S]*?background-color:\s*var\(--bg-input\);/,
  'plain text/search inputs must use the border-derived input background',
);
assert.match(
  workspaceSharedSource,
  /\.input,[\s\S]*?\.select\s*\{[\s\S]*?border:\s*1px solid var\(--border-subtle\);[\s\S]*?background:\s*var\(--bg-input\);/,
  'shared text inputs must use the border-derived input background',
);
assert.match(callSettingsSource, /\.ii-select\s*\{[\s\S]*?border:\s*1px solid var\(--border-subtle\);[\s\S]*?background-color:\s*var\(--border-subtle\);/, 'AppSelect background must use the styleguide border color');
assert.match(callSettingsSource, /\.ii-select option,[\s\S]*?\.ii-select optgroup\s*\{[\s\S]*?background:\s*var\(--border-subtle\);/, 'native select dropdown options must use the styleguide border color');

console.log('[theme-palette-contract] PASS');
