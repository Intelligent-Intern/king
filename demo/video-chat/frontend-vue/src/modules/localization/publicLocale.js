import {
  SUPPORTED_LOCALIZATION_LANGUAGES,
  localizationLanguageDirection,
} from '../../support/localizationOptions.js';

export const PUBLIC_LOCALE_COOKIE = 'king_videocall_public_locale';

const SUPPORTED_PUBLIC_LOCALES = new Set(SUPPORTED_LOCALIZATION_LANGUAGES.map((language) => language.code));

function normalizeString(value) {
  return typeof value === 'string' ? value.trim() : '';
}

export function normalizePublicLocaleCandidate(value) {
  const normalized = normalizeString(value)
    .toLowerCase()
    .replace(/_/g, '-')
    .split(/[;,]/)[0]
    .trim();
  if (normalized === '') return '';
  const base = normalized.split('-')[0];
  return SUPPORTED_PUBLIC_LOCALES.has(base) ? base : '';
}

function firstQueryValue(route, keys) {
  const query = route && typeof route === 'object' ? route.query || {} : {};
  for (const key of keys) {
    const value = query[key];
    const candidate = Array.isArray(value) ? value[0] : value;
    const locale = normalizePublicLocaleCandidate(candidate);
    if (locale !== '') return locale;
  }
  return '';
}

export function readPublicLocaleCookie(documentRef = globalThis.document) {
  const cookieText = normalizeString(documentRef?.cookie || '');
  if (cookieText === '') return '';
  for (const part of cookieText.split(';')) {
    const [rawName, ...rawValueParts] = part.split('=');
    if (normalizeString(rawName) !== PUBLIC_LOCALE_COOKIE) continue;
    try {
      return normalizePublicLocaleCandidate(decodeURIComponent(rawValueParts.join('=')));
    } catch {
      return normalizePublicLocaleCandidate(rawValueParts.join('='));
    }
  }
  return '';
}

export function writePublicLocaleCookie(locale, documentRef = globalThis.document) {
  const normalized = normalizePublicLocaleCandidate(locale);
  if (normalized === '' || !documentRef) return;
  documentRef.cookie = `${PUBLIC_LOCALE_COOKIE}=${encodeURIComponent(normalized)}; Path=/; Max-Age=31536000; SameSite=Lax`;
}

function browserLocale(navigatorRef = globalThis.navigator) {
  const languages = Array.isArray(navigatorRef?.languages) ? navigatorRef.languages : [];
  for (const language of [...languages, navigatorRef?.language]) {
    const locale = normalizePublicLocaleCandidate(language);
    if (locale !== '') return locale;
  }
  return '';
}

export function resolvePublicRouteLocale(route, environment = {}) {
  const explicitLocale = firstQueryValue(route, ['locale', 'lang', 'language']);
  if (explicitLocale !== '') {
    return { locale: explicitLocale, source: 'query', direction: localizationLanguageDirection(explicitLocale) };
  }

  const cookieLocale = readPublicLocaleCookie(environment.documentRef);
  if (cookieLocale !== '') {
    return { locale: cookieLocale, source: 'cookie', direction: localizationLanguageDirection(cookieLocale) };
  }

  const detectedLocale = browserLocale(environment.navigatorRef);
  if (detectedLocale !== '') {
    return { locale: detectedLocale, source: 'browser', direction: localizationLanguageDirection(detectedLocale) };
  }

  return { locale: 'en', source: 'fallback', direction: 'ltr' };
}

export function applyPublicRouteLocale(route, environment = {}) {
  const result = resolvePublicRouteLocale(route, environment);
  if (result.source === 'query') {
    writePublicLocaleCookie(result.locale, environment.documentRef);
  }
  return result;
}
