import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';
import {
  formatLocalizedDateTimeDisplay,
  normalizeDateTimeLocale,
} from '../../src/support/dateTimeFormat.js';
import { compareLocalizedStrings } from '../../src/support/localeCollation.js';
import {
  formatLocalizedListDisplay,
  formatLocalizedNumberDisplay,
} from '../../src/support/localeFormat.js';

const root = path.resolve(new URL('../..', import.meta.url).pathname);
const sampleIso = '2026-01-02T14:05:00Z';
const sampleDate = new Date(sampleIso);

assert.equal(normalizeDateTimeLocale('de'), 'de');
assert.equal(normalizeDateTimeLocale('definitely-unsupported'), 'en');

for (const locale of ['en', 'de', 'ar', 'fa', 'ps']) {
  assert.equal(normalizeDateTimeLocale(locale), locale);
  assert.equal(
    formatLocalizedDateTimeDisplay(sampleIso, { locale, timeFormat: '24h' }),
    new Intl.DateTimeFormat(locale, {
      year: 'numeric',
      month: 'short',
      day: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
      hour12: false,
    }).format(sampleDate),
    `${locale} 24h date-time formatting must use the active locale`,
  );
}

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
assert.equal(
  formatLocalizedDateTimeDisplay(sampleIso, { locale: 'ar', timeFormat: '12h' }),
  new Intl.DateTimeFormat('ar', {
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
assert.equal(
  formatLocalizedNumberDisplay(1234.5, { locale: 'de', minimumFractionDigits: 1, maximumFractionDigits: 1 }),
  new Intl.NumberFormat('de', { minimumFractionDigits: 1, maximumFractionDigits: 1 }).format(1234.5),
);
assert.equal(
  formatLocalizedNumberDisplay(0.25, { locale: 'ar', style: 'percent', maximumFractionDigits: 0 }),
  new Intl.NumberFormat('ar', { style: 'percent', maximumFractionDigits: 0 }).format(0.25),
);
assert.equal(formatLocalizedNumberDisplay('not-a-number', { fallback: 'n/a' }), 'n/a');
assert.equal(
  formatLocalizedListDisplay(['Alpha', 'Beta', 'Gamma'], { locale: 'en' }),
  new Intl.ListFormat('en', { style: 'long', type: 'conjunction' }).format(['Alpha', 'Beta', 'Gamma']),
);
assert.equal(
  formatLocalizedListDisplay(['Alpha', 'Beta', 'Gamma'], { locale: 'ar', type: 'disjunction' }),
  new Intl.ListFormat('ar', { style: 'long', type: 'disjunction' }).format(['Alpha', 'Beta', 'Gamma']),
);
assert.equal(formatLocalizedListDisplay([], { fallback: 'n/a' }), 'n/a');
assert.equal(
  compareLocalizedStrings('ä', 'z', { locale: 'de' }),
  'ä'.localeCompare('z', 'de', { sensitivity: 'base', numeric: true }),
);
assert.equal(
  compareLocalizedStrings('item 2', 'item 10', { locale: 'en' }),
  'item 2'.localeCompare('item 10', 'en', { sensitivity: 'base', numeric: true }),
);

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

const navigationBuilderSource = await readFile(path.join(root, 'src/modules/navigationBuilder.js'), 'utf8');
const routeAccessSource = await readFile(path.join(root, 'src/http/routeAccess.js'), 'utf8');
assert.match(navigationBuilderSource, /compareLocalizedStrings/, 'navigation builder must use centralized locale-aware collation');
assert.doesNotMatch(navigationBuilderSource, /\.localeCompare\(/, 'navigation builder must not call localeCompare directly');
assert.match(routeAccessSource, /locale: normalizeString\(session\.locale\)/, 'module access context must carry the active locale to navigation sorting');

console.log('[locale-aware-formatting-contract] PASS');
