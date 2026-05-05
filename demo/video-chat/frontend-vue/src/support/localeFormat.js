import { normalizeLocalizationLanguage } from './localizationOptions.js';

function normalizeString(value) {
  return String(value || '').trim();
}

function normalizedLocale(value) {
  return normalizeLocalizationLanguage(value);
}

function fallbackText(options = {}) {
  return typeof options.fallback === 'string' && options.fallback !== '' ? options.fallback : 'n/a';
}

export function formatLocalizedNumberDisplay(value, options = {}) {
  const fallback = fallbackText(options);
  const number = typeof value === 'number' ? value : Number.parseFloat(String(value || '').trim());
  if (!Number.isFinite(number)) return fallback;

  const formatOptions = {};
  if (Number.isInteger(options.minimumFractionDigits)) {
    formatOptions.minimumFractionDigits = Math.max(0, options.minimumFractionDigits);
  }
  if (Number.isInteger(options.maximumFractionDigits)) {
    formatOptions.maximumFractionDigits = Math.max(0, options.maximumFractionDigits);
  }
  if (options.style === 'percent') {
    formatOptions.style = 'percent';
  } else if (options.style === 'currency' && normalizeString(options.currency) !== '') {
    formatOptions.style = 'currency';
    formatOptions.currency = normalizeString(options.currency).toUpperCase();
  }

  try {
    return new Intl.NumberFormat(normalizedLocale(options.locale), formatOptions).format(number);
  } catch {
    return String(number);
  }
}

export function formatLocalizedListDisplay(values, options = {}) {
  const fallback = fallbackText(options);
  if (!Array.isArray(values)) return fallback;

  const items = values.map(normalizeString).filter((item) => item !== '');
  if (items.length === 0) return fallback;
  if (items.length === 1) return items[0];

  const style = ['long', 'short', 'narrow'].includes(options.style) ? options.style : 'long';
  const type = ['conjunction', 'disjunction', 'unit'].includes(options.type) ? options.type : 'conjunction';

  try {
    return new Intl.ListFormat(normalizedLocale(options.locale), { style, type }).format(items);
  } catch {
    return items.join(', ');
  }
}
