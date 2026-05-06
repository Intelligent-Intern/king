import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';

const frontendRoot = path.resolve(new URL('../..', import.meta.url).pathname);
const repoRoot = path.resolve(new URL('../../../../..', import.meta.url).pathname);

const inventory = await readFile(path.join(repoRoot, 'documentation/video-chat-localization-inventory.md'), 'utf8');
const localizationOptions = await readFile(path.join(frontendRoot, 'src/support/localizationOptions.js'), 'utf8');

const websiteLocales = [
  'am',
  'ar',
  'bn',
  'de',
  'en',
  'es',
  'fa',
  'fr',
  'ha',
  'hi',
  'it',
  'ja',
  'jv',
  'ko',
  'my',
  'pa',
  'ps',
  'pt',
  'ru',
  'sgd',
  'so',
  'th',
  'tl',
  'tr',
  'uk',
  'uz',
  'vi',
  'zh',
];

for (const locale of websiteLocales) {
  assert.match(inventory, new RegExp(`\\\`${locale}\\\``), `inventory missing website locale ${locale}`);
  assert.match(localizationOptions, new RegExp(`code: '${locale}'`), `localization options missing website locale ${locale}`);
}

for (const rtlLocale of ['ar', 'fa', 'ps', 'sgd']) {
  assert.match(inventory, new RegExp(`\\b${rtlLocale}\\b`), `inventory missing RTL locale ${rtlLocale}`);
}

assert.match(
  inventory,
  /same RTL metadata as the website runtime/,
  'inventory must record that app RTL metadata matches the website runtime',
);
assert.match(
  inventory,
  /Resolved non-call RTL pass/,
  'inventory must document the resolved non-call RTL pass',
);
assert.match(
  inventory,
  /Remaining documented physical-coordinate cases/,
  'inventory must document remaining physical-coordinate RTL cases',
);
assert.doesNotMatch(localizationOptions, /'ur'/, 'localization options must not list unsupported ur as RTL');
assert.match(localizationOptions, /'sgd'/, 'localization options must mark sgd as RTL');

for (const namespace of [
  'common',
  'auth',
  'settings',
  'calls',
  'call_workspace',
  'calendar',
  'users',
  'tenancy',
  'marketplace',
  'public_booking',
  'emails',
  'errors',
  'diagnostics',
]) {
  assert.match(inventory, new RegExp(`\\\`${namespace}\\\``), `inventory missing namespace ${namespace}`);
}

for (const heading of [
  'Frontend Translation Surfaces',
  'Backend And Email Translation Surfaces',
  'Locale Assumptions Found',
  'RTL Risk Map',
  'Public Booking And Website Copy',
]) {
  assert.match(inventory, new RegExp(`## ${heading}`), `inventory missing ${heading} section`);
}

for (const surface of [
  'WorkspaceShell.vue',
  'AdministrationLocalizationView.vue',
  'AppointmentConfigPanel.vue',
  'CallWorkspaceView.vue',
  'CallWorkspacePanels.css',
  'appointment_calendar_mail.php',
  'workspace_administration.php',
  'support/error_envelope.php',
]) {
  assert.match(inventory, new RegExp(surface.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')), `inventory missing ${surface}`);
}

assert.doesNotMatch(inventory, /\b(TODO|TBD)\b/, 'inventory must not leave placeholder TODO/TBD markers');

console.log('[localization-inventory-contract] PASS');
