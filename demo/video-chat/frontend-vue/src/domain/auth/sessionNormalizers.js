import { normalizeDateFormat, normalizeTimeFormat } from '../../support/dateTimeFormat.js';
import {
  localizationLanguageDirection,
  normalizeLocalizationLanguage,
  SUPPORTED_LOCALIZATION_LANGUAGES,
} from '../../support/localizationOptions.js';

const AUTH_ROLES = new Set(['admin', 'user']);
const ACCOUNT_TYPES = new Set(['account', 'guest']);

export function normalizeString(value) {
  return typeof value === 'string' ? value.trim() : '';
}

export function normalizeRole(value) {
  const role = String(value || '').trim().toLowerCase();
  return AUTH_ROLES.has(role) ? role : null;
}

export function normalizeTheme(value) {
  const theme = String(value || '').trim();
  return theme !== '' ? theme : 'dark';
}

export function normalizeAccountType(value) {
  const accountType = normalizeString(value).toLowerCase();
  return ACCOUNT_TYPES.has(accountType) ? accountType : '';
}

export function normalizeTenantSnapshot(value) {
  const source = value && typeof value === 'object' ? value : {};
  const id = Number.isInteger(source.id) ? source.id : Number(source.tenant_id || 0);
  return {
    id: Number.isInteger(id) && id > 0 ? id : 0,
    uuid: normalizeString(source.uuid || source.public_id),
    label: normalizeString(source.label),
    role: normalizeString(source.role).toLowerCase(),
    permissions: source.permissions && typeof source.permissions === 'object' ? { ...source.permissions } : {},
  };
}

export function normalizeSupportedLocales(value) {
  const source = Array.isArray(value) && value.length > 0 ? value : SUPPORTED_LOCALIZATION_LANGUAGES;
  return source
    .map((locale) => {
      const code = normalizeLocalizationLanguage(locale?.code);
      return {
        code,
        label: normalizeString(locale?.label) || code.toUpperCase(),
        direction: normalizeString(locale?.direction) === 'rtl' ? 'rtl' : localizationLanguageDirection(code),
        is_default: locale?.is_default === true,
      };
    })
    .filter((locale, index, locales) => (
      locale.code !== ''
      && locales.findIndex((candidate) => candidate.code === locale.code) === index
    ));
}

export function inferAccountType(user) {
  const explicitType = normalizeAccountType(user?.account_type);
  if (explicitType !== '') return explicitType;
  if (user?.is_guest === true) return 'guest';

  const email = normalizeString(user?.email).toLowerCase();
  if (email.startsWith('guest+') && email.endsWith('@videochat.local')) {
    return 'guest';
  }
  return 'account';
}

export function normalizeMessengerContacts(value) {
  if (!Array.isArray(value)) return [];
  return value
    .map((contact) => {
      const source = contact && typeof contact === 'object' ? contact : {};
      return {
        channel: normalizeString(source.channel).toLowerCase(),
        handle: normalizeString(source.handle),
      };
    })
    .filter((contact) => contact.channel !== '' && contact.handle !== '');
}

export function normalizePostLogoutLandingUrl(value) {
  const url = normalizeString(value);
  if (url === '' || !url.startsWith('/') || url.startsWith('//') || url.includes('\\')) {
    return '';
  }
  return url;
}

export function normalizeOnboardingCompletedTours(value) {
  if (!Array.isArray(value)) return [];
  return [...new Set(value.map((tourKey) => normalizeString(tourKey).toLowerCase()).filter(Boolean))].sort();
}

export function normalizeOnboardingBadges(value) {
  if (!Array.isArray(value)) return [];
  return value
    .map((badge) => {
      const source = badge && typeof badge === 'object' ? badge : {};
      return {
        tour_key: normalizeString(source.tour_key).toLowerCase(),
        completed_at: normalizeString(source.completed_at),
      };
    })
    .filter((badge) => badge.tour_key !== '')
    .sort((a, b) => a.tour_key.localeCompare(b.tour_key));
}

export {
  localizationLanguageDirection,
  normalizeDateFormat,
  normalizeLocalizationLanguage,
  normalizeTimeFormat,
  SUPPORTED_LOCALIZATION_LANGUAGES,
};
