export const SUPPORTED_LOCALIZATION_LANGUAGES = Object.freeze([
  { code: 'en', label: 'English' },
  { code: 'de', label: 'Deutsch' },
  { code: 'fr', label: 'Francais' },
  { code: 'es', label: 'Espanol' },
  { code: 'ar', label: 'Arabic' },
  { code: 'pt', label: 'Portuguese' },
  { code: 'jv', label: 'Javanese' },
  { code: 'tr', label: 'Turkish' },
  { code: 'pa', label: 'Punjabi' },
  { code: 'uk', label: 'Ukrainian' },
  { code: 'so', label: 'Somali' },
  { code: 'bn', label: 'Bengali' },
  { code: 'ps', label: 'Pashto' },
  { code: 'vi', label: 'Vietnamese' },
  { code: 'th', label: 'Thai' },
  { code: 'fa', label: 'Persian' },
  { code: 'it', label: 'Italian' },
  { code: 'sgd', label: 'Surigaonon' },
  { code: 'ja', label: 'Japanese' },
  { code: 'am', label: 'Amharic' },
  { code: 'uz', label: 'Uzbek' },
  { code: 'ru', label: 'Russian' },
  { code: 'hi', label: 'Hindi' },
  { code: 'tl', label: 'Tagalog' },
  { code: 'ha', label: 'Hausa' },
  { code: 'zh', label: 'Chinese' },
  { code: 'my', label: 'Burmese' },
  { code: 'ko', label: 'Korean' },
]);

const RTL_LANGUAGE_CODES = new Set(['ar', 'fa', 'ps', 'sgd']);

export function normalizeLocalizationLanguage(value) {
  const normalized = String(value || '').trim().toLowerCase();
  return SUPPORTED_LOCALIZATION_LANGUAGES.some((language) => language.code === normalized) ? normalized : 'en';
}

export function localizationLanguageDirection(value) {
  return RTL_LANGUAGE_CODES.has(normalizeLocalizationLanguage(value)) ? 'rtl' : 'ltr';
}
