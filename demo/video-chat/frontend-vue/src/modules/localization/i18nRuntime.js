import { reactive } from 'vue';
import { sessionState } from '../../domain/auth/session';
import { fetchBackend } from '../../support/backendFetch';
import {
  localizationLanguageDirection,
  normalizeLocalizationLanguage,
} from '../../support/localizationOptions';

export const DEFAULT_I18N_NAMESPACES = Object.freeze([
  'common',
  'navigation',
  'settings',
  'localization',
]);

const BUILTIN_ENGLISH_MESSAGES = Object.freeze({
  'common.cancel': 'Cancel',
  'common.save': 'Save',
  'common.saved': 'Saved',
  'common.loading': 'Loading...',
  'settings.language': 'Language',
  'settings.language_saved': 'Language saved.',
  'localization.import_preview_ready': 'Preview ready for {count} rows.',
});

export const i18nState = reactive({
  locale: 'en',
  direction: 'ltr',
  ready: false,
  loading: false,
  namespaces: [...DEFAULT_I18N_NAMESPACES],
  localeMessages: {},
  fallbackMessages: { ...BUILTIN_ENGLISH_MESSAGES },
  messages: { ...BUILTIN_ENGLISH_MESSAGES },
  missingKeys: {},
  lastError: '',
});

let resourceLoadInFlight = null;
let resourceLoadKey = '';

function normalizeString(value) {
  return typeof value === 'string' ? value.trim() : '';
}

function shouldTrackMissingKeys() {
  const metaEnv = import.meta.env || {};
  if (metaEnv.PROD === true || metaEnv.MODE === 'production') return false;
  if (typeof process !== 'undefined' && process.env?.NODE_ENV === 'production') return false;
  return true;
}

export function normalizeI18nNamespaces(value = DEFAULT_I18N_NAMESPACES) {
  const source = Array.isArray(value) && value.length > 0 ? value : DEFAULT_I18N_NAMESPACES;
  const namespaces = [];
  for (const namespace of source) {
    const normalized = normalizeString(namespace);
    if (normalized === '' || !/^[a-z][a-z0-9_.-]{0,119}$/.test(normalized)) continue;
    namespaces.push(normalized);
  }
  return [...new Set(namespaces)].sort();
}

export function normalizeI18nResources(value) {
  if (!value || typeof value !== 'object' || Array.isArray(value)) return {};
  const resources = {};
  for (const [key, rawValue] of Object.entries(value)) {
    const normalizedKey = normalizeString(key);
    if (normalizedKey === '') continue;
    resources[normalizedKey] = String(rawValue ?? '');
  }
  return resources;
}

export function mergeI18nMessages(fallbackMessages, localeMessages) {
  return {
    ...normalizeI18nResources(fallbackMessages),
    ...normalizeI18nResources(localeMessages),
  };
}

export function escapeTranslationValue(value) {
  return String(value ?? '').replace(/[&<>"']/g, (character) => {
    switch (character) {
      case '&':
        return '&amp;';
      case '<':
        return '&lt;';
      case '>':
        return '&gt;';
      case '"':
        return '&quot;';
      case "'":
        return '&#39;';
      default:
        return character;
    }
  });
}

export function interpolateTranslation(template, params = {}) {
  const source = String(template ?? '');
  const values = params && typeof params === 'object' ? params : {};
  return source.replace(/\{([A-Za-z0-9_.-]+)\}/g, (_match, key) => escapeTranslationValue(values[key] ?? ''));
}

export function syncI18nDocumentState(locale = i18nState.locale, direction = i18nState.direction) {
  if (typeof document === 'undefined') return;
  const normalizedLocale = normalizeLocalizationLanguage(locale || sessionState.locale);
  const normalizedDirection = direction === 'rtl' ? 'rtl' : localizationLanguageDirection(normalizedLocale);
  document.documentElement.lang = normalizedLocale;
  document.documentElement.dir = normalizedDirection;
}

function replaceObject(target, source) {
  for (const key of Object.keys(target)) {
    delete target[key];
  }
  Object.assign(target, source);
}

function recordMissingKey(key) {
  if (!shouldTrackMissingKeys()) return;
  i18nState.missingKeys[key] = (i18nState.missingKeys[key] || 0) + 1;
}

export function applyI18nResourcePayload(payload = {}) {
  const locale = normalizeLocalizationLanguage(payload.locale || sessionState.locale);
  const direction = payload.direction === 'rtl' ? 'rtl' : localizationLanguageDirection(locale);
  const fallbackMessages = {
    ...BUILTIN_ENGLISH_MESSAGES,
    ...normalizeI18nResources(payload.fallback_resources),
  };
  const localeMessages = normalizeI18nResources(payload.resources);
  const messages = mergeI18nMessages(fallbackMessages, localeMessages);

  i18nState.locale = locale;
  i18nState.direction = direction;
  i18nState.namespaces = normalizeI18nNamespaces(payload.namespaces);
  replaceObject(i18nState.fallbackMessages, fallbackMessages);
  replaceObject(i18nState.localeMessages, localeMessages);
  replaceObject(i18nState.messages, messages);
  i18nState.ready = true;
  i18nState.lastError = '';
  syncI18nDocumentState(locale, direction);
  return i18nState;
}

function sessionHeaders() {
  const token = normalizeString(sessionState.sessionToken);
  const headers = { accept: 'application/json' };
  if (token !== '') {
    headers.authorization = `Bearer ${token}`;
  }
  return headers;
}

async function readJsonResponse(response) {
  try {
    return await response.json();
  } catch {
    return null;
  }
}

function hasLoadedNamespaces(namespaces) {
  return namespaces.every((namespace) => i18nState.namespaces.includes(namespace));
}

export async function loadI18nResources(options = {}) {
  const locale = normalizeLocalizationLanguage(options.locale || sessionState.locale);
  const namespaces = normalizeI18nNamespaces(options.namespaces);
  const force = options.force === true;
  const loadKey = `${locale}|${namespaces.join(',')}|${sessionState.tenantId || 0}`;

  syncI18nDocumentState(locale, sessionState.direction || localizationLanguageDirection(locale));
  if (!force && i18nState.ready && i18nState.locale === locale && hasLoadedNamespaces(namespaces)) {
    return i18nState;
  }

  if (resourceLoadInFlight && resourceLoadKey === loadKey && !force) {
    return resourceLoadInFlight;
  }

  if (!sessionState.sessionToken) {
    applyI18nResourcePayload({
      locale,
      direction: localizationLanguageDirection(locale),
      namespaces,
      resources: {},
      fallback_resources: BUILTIN_ENGLISH_MESSAGES,
    });
    return i18nState;
  }

  i18nState.loading = true;
  i18nState.lastError = '';
  resourceLoadKey = loadKey;
  resourceLoadInFlight = (async () => {
    try {
      const { response } = await fetchBackend('/api/localization/resources', {
        method: 'GET',
        headers: sessionHeaders(),
        query: {
          locale,
          namespaces: namespaces.join(','),
        },
        retryOnNetworkError: true,
      });
      const payload = await readJsonResponse(response);
      if (!response.ok || !payload || payload.status !== 'ok') {
        throw new Error(payload?.error?.message || 'Could not load translations.');
      }
      return applyI18nResourcePayload(payload);
    } catch (error) {
      i18nState.lastError = error instanceof Error ? error.message : 'Could not load translations.';
      applyI18nResourcePayload({
        locale,
        direction: localizationLanguageDirection(locale),
        namespaces,
        resources: {},
        fallback_resources: BUILTIN_ENGLISH_MESSAGES,
      });
      return i18nState;
    } finally {
      i18nState.loading = false;
      resourceLoadInFlight = null;
    }
  })();

  return resourceLoadInFlight;
}

export function ensureI18nResources(options = {}) {
  return loadI18nResources(options);
}

export function t(key, params = {}) {
  const normalizedKey = normalizeString(key);
  if (normalizedKey === '') return '';
  if (Object.prototype.hasOwnProperty.call(i18nState.localeMessages, normalizedKey)) {
    return interpolateTranslation(i18nState.localeMessages[normalizedKey], params);
  }
  if (Object.prototype.hasOwnProperty.call(i18nState.fallbackMessages, normalizedKey)) {
    if (i18nState.locale !== 'en') {
      recordMissingKey(normalizedKey);
    }
    return interpolateTranslation(i18nState.fallbackMessages[normalizedKey], params);
  }
  recordMissingKey(normalizedKey);
  return normalizedKey;
}
