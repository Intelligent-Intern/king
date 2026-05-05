import { t } from './i18nRuntime.js';

export const API_ERROR_MESSAGE_KEYS = Object.freeze({
  auth_failed: 'errors.api.auth_failed',
  appointment_slot_unavailable: 'errors.api.appointment_slot_unavailable',
  call_access_conflict: 'errors.api.call_access_conflict',
  call_access_expired: 'errors.api.call_access_expired',
  call_access_forbidden: 'errors.api.call_access_forbidden',
  call_access_not_found: 'errors.api.call_access_not_found',
  call_access_validation_failed: 'errors.api.call_access_validation_failed',
  localization_bundle_fetch_failed: 'errors.api.localization_bundle_fetch_failed',
  localization_bundle_list_failed: 'errors.api.localization_bundle_list_failed',
  localization_bundle_not_found: 'errors.api.localization_bundle_not_found',
  localization_import_failed: 'errors.api.localization_import_failed',
  localization_import_fetch_failed: 'errors.api.localization_import_fetch_failed',
  localization_import_invalid_request_body: 'errors.api.localization_import_invalid_request_body',
  localization_import_list_failed: 'errors.api.localization_import_list_failed',
  localization_import_not_found: 'errors.api.localization_import_not_found',
  localization_import_validation_failed: 'errors.api.localization_import_validation_failed',
  localization_locale_list_failed: 'errors.api.localization_locale_list_failed',
  localization_resources_failed: 'errors.api.localization_resources_failed',
  localization_superadmin_required: 'errors.api.localization_superadmin_required',
  method_not_allowed: 'errors.api.method_not_allowed',
  primary_admin_required: 'errors.api.primary_admin_required',
  rbac_forbidden: 'errors.api.rbac_forbidden',
  theme_editor_access_required: 'errors.api.theme_editor_access_required',
  workspace_theme_locked: 'errors.api.workspace_theme_locked',
});

const API_ERROR_SUFFIX_MESSAGE_KEYS = Object.freeze([
  ['_invalid_request_body', 'errors.api.invalid_request_body'],
  ['_validation_failed', 'errors.api.validation_failed'],
  ['_not_found', 'errors.api.not_found'],
  ['_conflict', 'errors.api.conflict'],
  ['_locked', 'errors.api.conflict'],
  ['_forbidden', 'errors.api.rbac_forbidden'],
  ['_failed', 'errors.api.request_failed'],
]);

export function apiErrorCode(payload) {
  const code = payload && typeof payload === 'object' ? payload?.error?.code : '';
  return typeof code === 'string' ? code.trim() : '';
}

export function apiErrorMessageKey(code) {
  const normalizedCode = typeof code === 'string' ? code.trim() : '';
  if (normalizedCode === '') return '';
  if (API_ERROR_MESSAGE_KEYS[normalizedCode]) {
    return API_ERROR_MESSAGE_KEYS[normalizedCode];
  }
  for (const [suffix, key] of API_ERROR_SUFFIX_MESSAGE_KEYS) {
    if (normalizedCode.endsWith(suffix)) {
      return key;
    }
  }
  return '';
}

export function localizedApiErrorMessage(payload, fallback = '') {
  const code = apiErrorCode(payload);
  const key = apiErrorMessageKey(code);
  if (key !== '') {
    return t(key);
  }
  const fallbackText = typeof fallback === 'string' ? fallback.trim() : '';
  return fallbackText || t('errors.api.request_failed');
}

export function buildLocalizedApiError(payload, fallback = '', responseStatus = 0) {
  const error = new Error(localizedApiErrorMessage(payload, fallback));
  const code = apiErrorCode(payload);
  error.apiErrorCode = code;
  error.responseCode = code;
  error.responseStatus = Number(responseStatus) || 0;
  error.payload = payload;
  return error;
}
