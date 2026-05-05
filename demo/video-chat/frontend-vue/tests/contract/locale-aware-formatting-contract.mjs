import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';
import {
  formatLocalizedDateTimeDisplay,
  normalizeDateTimeLocale,
} from '../../src/support/dateTimeFormat.js';

const root = path.resolve(new URL('../..', import.meta.url).pathname);
const sampleIso = '2026-01-02T14:05:00Z';
const sampleDate = new Date(sampleIso);

assert.equal(normalizeDateTimeLocale('de'), 'de');
assert.equal(normalizeDateTimeLocale('definitely-unsupported'), 'en');
assert.equal(
  formatLocalizedDateTimeDisplay(sampleIso, { locale: 'de', timeFormat: '24h' }),
  new Intl.DateTimeFormat('de', {
    year: 'numeric',
    month: 'short',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
  }).format(sampleDate),
);
assert.equal(
  formatLocalizedDateTimeDisplay(sampleIso, { locale: 'en', timeFormat: '12h' }),
  new Intl.DateTimeFormat('en', {
    year: 'numeric',
    month: 'short',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    hour12: true,
  }).format(sampleDate),
);
assert.equal(formatLocalizedDateTimeDisplay('', { fallback: 'n/a' }), 'n/a');
assert.equal(formatLocalizedDateTimeDisplay('not-a-date', { fallback: 'n/a' }), 'not-a-date');

const localizedTableSources = await Promise.all([
  'src/modules/governance/pages/GovernanceCrudView.vue',
  'src/modules/marketplace/pages/AdminMarketplaceTable.vue',
  'src/modules/users/pages/components/UsersTable.vue',
].map(async (relativePath) => ({
  relativePath,
  source: await readFile(path.join(root, relativePath), 'utf8'),
})));

for (const { relativePath, source } of localizedTableSources) {
  assert.match(source, /formatLocalizedDateTimeDisplay/, `${relativePath} must use centralized localized date-time formatting`);
  assert.doesNotMatch(source, /Intl\.DateTimeFormat\(['"](?:en-GB|de-DE)['"]/, `${relativePath} must not pin date-time formatting to en-GB/de-DE`);
}

console.log('[locale-aware-formatting-contract] PASS');
