import { t } from './i18nRuntime.js';

export const API_ERROR_MESSAGE_KEYS = Object.freeze({
  auth_failed: 'errors.api.auth_failed',
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
  rbac_forbidden: 'errors.api.rbac_forbidden',
});

export function apiErrorCode(payload) {
  const code = payload && typeof payload === 'object' ? payload?.error?.code : '';
  return typeof code === 'string' ? code.trim() : '';
}

export function localizedApiErrorMessage(payload, fallback = '') {
  const code = apiErrorCode(payload);
  const key = API_ERROR_MESSAGE_KEYS[code] || '';
  if (key !== '') {
    return t(key);
  }
  const fallbackText = typeof fallback === 'string' ? fallback.trim() : '';
  return fallbackText || t('errors.api.request_failed');
}
