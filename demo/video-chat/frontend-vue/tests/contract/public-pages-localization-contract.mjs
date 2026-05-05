import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';
import {
  PUBLIC_LOCALE_COOKIE,
  normalizePublicLocaleCandidate,
  resolvePublicRouteLocale,
} from '../../src/modules/localization/publicLocale.js';

const root = path.resolve(new URL('../..', import.meta.url).pathname);

assert.equal(normalizePublicLocaleCandidate('de-DE'), 'de');
assert.equal(normalizePublicLocaleCandidate('ar_EG'), 'ar');
assert.equal(normalizePublicLocaleCandidate('zz-ZZ'), '');

const cookieDocument = { cookie: `${PUBLIC_LOCALE_COOKIE}=fa` };
assert.deepEqual(
  resolvePublicRouteLocale(
    { query: { locale: 'ar' } },
    {
      documentRef: cookieDocument,
      navigatorRef: { languages: ['de-DE'], language: 'de-DE' },
    },
  ),
  { locale: 'ar', source: 'query', direction: 'rtl' },
);
assert.deepEqual(
  resolvePublicRouteLocale(
    { query: {} },
    {
      documentRef: cookieDocument,
      navigatorRef: { languages: ['de-DE'], language: 'de-DE' },
    },
  ),
  { locale: 'fa', source: 'cookie', direction: 'rtl' },
);
assert.deepEqual(
  resolvePublicRouteLocale(
    { query: {} },
    {
      documentRef: { cookie: '' },
      navigatorRef: { languages: ['de-DE'], language: 'en-US' },
    },
  ),
  { locale: 'de', source: 'browser', direction: 'ltr' },
);
assert.deepEqual(
  resolvePublicRouteLocale({ query: {} }, { documentRef: { cookie: '' }, navigatorRef: {} }),
  { locale: 'en', source: 'fallback', direction: 'ltr' },
);

const routerSource = await readFile(path.join(root, 'src/http/router.js'), 'utf8');
const i18nRuntimeSource = await readFile(path.join(root, 'src/modules/localization/i18nRuntime.js'), 'utf8');
const appointmentApiSource = await readFile(path.join(root, 'src/domain/calls/appointment/appointmentCalendarApi.js'), 'utf8');
const appointmentBookingModalSource = await readFile(path.join(root, 'src/domain/calls/appointment/AppointmentBookingModal.vue'), 'utf8');
const joinViewSource = await readFile(path.join(root, 'src/domain/calls/access/JoinView.vue'), 'utf8');
const englishMessagesSource = await readFile(path.join(root, 'src/modules/localization/englishMessages.js'), 'utf8');
assert.match(routerSource, /applyPublicRouteLocale\(to\)/, 'public routes must resolve locale before rendering');
assert.match(routerSource, /public:\s*true,\s*i18nNamespaces:\s*\['public'\]/, 'public call routes must declare public i18n namespace');
assert.match(routerSource, /public:\s*true[\s\S]*ensureI18nResources/, 'public route guard must load translations without requiring auth');
assert.match(i18nRuntimeSource, /options\.public === true/, 'i18n runtime must support explicit public resource loading');
assert.match(i18nRuntimeSource, /!sessionState\.sessionToken && !publicLoad/, 'public resource loading must not use local-only fallback');
assert.match(i18nRuntimeSource, /resourceLoadKey/, 'i18n cache must distinguish public resources from authenticated tenant resources');
assert.match(appointmentApiSource, /new Intl\.DateTimeFormat\(locale,/, 'public slot labels must use the active locale explicitly');
assert.doesNotMatch(appointmentApiSource, /Intl\.DateTimeFormat\(undefined/, 'public slot labels must not fall back to browser-default formatting');
assert.match(appointmentBookingModalSource, /toLocalSlotLabel\(selectedSlot\.value,\s*\{\s*locale:\s*activeLocale\.value\s*\}/, 'selected public booking slot label must use active locale');
assert.match(appointmentBookingModalSource, /locale:\s*activeLocale\.value/, 'public booking submit and calendar options must carry active locale');
assert.match(appointmentBookingModalSource, /calendarInstance\?\.setOption\('locale',\s*locale\)/, 'FullCalendar must react to public locale changes');
assert.match(appointmentBookingModalSource, /direction:\s*activeDirection\.value/, 'FullCalendar must initialize with active public text direction');
assert.match(appointmentBookingModalSource, /calendarInstance\?\.setOption\('direction',\s*direction\)/, 'FullCalendar must react to public direction changes');
assert.match(appointmentApiSource, /localizedApiErrorMessage\(payload,\s*fallback\)/, 'public appointment API errors must resolve through stable codes');
assert.doesNotMatch(appointmentApiSource, /payload\?\.error\?\.message/, 'public appointment API must not display backend English error messages directly');
assert.match(joinViewSource, /localizedApiErrorMessage\(payload,\s*'Could not resolve call access\.'\)/, 'public join access errors must resolve through stable codes');
assert.doesNotMatch(joinViewSource, /payload\?\.error\?\.message/, 'public join view must not display backend English error messages directly');
for (const key of [
  'errors.api.call_access_expired',
  'errors.api.call_access_not_found',
  'errors.api.call_access_validation_failed',
  'errors.api.appointment_slot_unavailable',
]) {
  assert.match(englishMessagesSource, new RegExp(`'${key}'`), `${key} must have an English fallback`);
}

console.log('[public-pages-localization-contract] PASS');
