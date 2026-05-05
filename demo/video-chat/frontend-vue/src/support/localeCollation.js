import { normalizeLocalizationLanguage } from './localizationOptions.js';

function normalizeString(value) {
  return String(value || '').trim();
}

function activeDocumentLocale() {
  if (typeof document === 'undefined') {
    return '';
  }
  return normalizeString(document.documentElement?.lang);
}

export function compareLocalizedStrings(left, right, options = {}) {
  const explicitLocale = normalizeString(options.locale);
  const locale = normalizeLocalizationLanguage(explicitLocale || activeDocumentLocale());
  const collatorOptions = {
    sensitivity: options.sensitivity || 'base',
    numeric: options.numeric !== false,
  };
  return normalizeString(left).localeCompare(normalizeString(right), locale, collatorOptions);
}
