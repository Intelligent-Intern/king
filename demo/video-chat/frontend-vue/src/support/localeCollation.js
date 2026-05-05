import { normalizeLocalizationLanguage } from './localizationOptions.js';

function normalizeString(value) {
  return String(value || '').trim();
}

export function compareLocalizedStrings(left, right, options = {}) {
  const locale = normalizeLocalizationLanguage(options.locale);
  const collatorOptions = {
    sensitivity: options.sensitivity || 'base',
    numeric: options.numeric !== false,
  };
  return normalizeString(left).localeCompare(normalizeString(right), locale, collatorOptions);
}
