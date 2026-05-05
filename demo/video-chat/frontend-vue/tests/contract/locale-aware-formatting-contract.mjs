import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';
import {
  formatLocalizedDateTimeDisplay,
  formatWeekdayShort,
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

function localizedInteger(value, locale, minimumIntegerDigits = 2) {
  return new Intl.NumberFormat(locale, {
    minimumIntegerDigits,
    useGrouping: false,
  }).format(value);
}

function expectedLocalizedDate(date, locale, dateFormat) {
  const year = localizedInteger(date.getFullYear(), locale, 4);
  const month = localizedInteger(date.getMonth() + 1, locale, 2);
  const day = localizedInteger(date.getDate(), locale, 2);
  if (dateFormat === 'ymd_dash') return `${year}-${month}-${day}`;
  if (dateFormat === 'ymd_compact') return `${year}${month}${day}`;
  if (dateFormat === 'mdy_slash') return `${month}/${day}/${year}`;
  return `${day}.${month}.${year}`;
}

function expectedLocalizedTime(date, locale, timeFormat) {
  return new Intl.DateTimeFormat(locale, {
    hour: '2-digit',
    minute: '2-digit',
    hour12: timeFormat === '12h',
  }).format(date);
}

function expectedLocalizedDateTime(date, { locale, dateFormat = 'dmy_dot', timeFormat = '24h' }) {
  return `${expectedLocalizedDate(date, locale, dateFormat)} ${expectedLocalizedTime(date, locale, timeFormat)}`;
}

assert.equal(normalizeDateTimeLocale('de'), 'de');
assert.equal(normalizeDateTimeLocale('definitely-unsupported'), 'en');

for (const locale of ['en', 'de', 'ar', 'fa', 'ps']) {
  assert.equal(normalizeDateTimeLocale(locale), locale);
  assert.equal(
    formatLocalizedDateTimeDisplay(sampleIso, { locale, dateFormat: 'ymd_dash', timeFormat: '24h' }),
    expectedLocalizedDateTime(sampleDate, { locale, dateFormat: 'ymd_dash', timeFormat: '24h' }),
    `${locale} date-time formatting must use the active locale and requested date format`,
  );
}

assert.equal(
  formatLocalizedDateTimeDisplay(sampleIso, { locale: 'en', dateFormat: 'mdy_slash', timeFormat: '12h' }),
  expectedLocalizedDateTime(sampleDate, { locale: 'en', dateFormat: 'mdy_slash', timeFormat: '12h' }),
);
assert.equal(
  formatLocalizedDateTimeDisplay(sampleIso, { locale: 'ar', dateFormat: 'ymd_compact', timeFormat: '12h' }),
  expectedLocalizedDateTime(sampleDate, { locale: 'ar', dateFormat: 'ymd_compact', timeFormat: '12h' }),
);
assert.equal(formatLocalizedDateTimeDisplay('', { fallback: 'n/a' }), 'n/a');
assert.equal(formatLocalizedDateTimeDisplay('not-a-date', { fallback: 'n/a' }), 'not-a-date');
assert.equal(
  formatWeekdayShort(sampleIso, { locale: 'de', fallback: '' }),
  new Intl.DateTimeFormat('de', { weekday: 'short' }).format(sampleDate),
);
assert.equal(
  formatWeekdayShort(sampleIso, { locale: 'ar', fallback: '' }),
  new Intl.DateTimeFormat('ar', { weekday: 'short' }).format(sampleDate),
);
assert.equal(formatWeekdayShort('', { locale: 'de', fallback: 'n/a' }), 'n/a');
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
  assert.match(source, /dateFormat:\s*sessionState\.dateFormat/, `${relativePath} must pass the active user date format`);
  assert.match(source, /timeFormat:\s*sessionState\.timeFormat/, `${relativePath} must pass the active user time format`);
  assert.doesNotMatch(source, /Intl\.DateTimeFormat\(['"](?:en-GB|de-DE)['"]/, `${relativePath} must not pin date-time formatting to en-GB/de-DE`);
}

const navigationBuilderSource = await readFile(path.join(root, 'src/modules/navigationBuilder.js'), 'utf8');
const routeAccessSource = await readFile(path.join(root, 'src/http/routeAccess.js'), 'utf8');
assert.match(navigationBuilderSource, /compareLocalizedStrings/, 'navigation builder must use centralized locale-aware collation');
assert.doesNotMatch(navigationBuilderSource, /\.localeCompare\(/, 'navigation builder must not call localeCompare directly');
assert.match(routeAccessSource, /locale: normalizeString\(session\.locale\)/, 'module access context must carry the active locale to navigation sorting');

console.log('[locale-aware-formatting-contract] PASS');
