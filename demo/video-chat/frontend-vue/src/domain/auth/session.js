import { reactive } from 'vue';
import { fetchBackend } from '../../support/backendFetch';
import {
  inferAccountType,
  localizationLanguageDirection,
  normalizeDateFormat,
  normalizeMessengerContacts,
  normalizeOnboardingBadges,
  normalizeOnboardingCompletedTours,
  normalizePostLogoutLandingUrl,
  normalizeLocalizationLanguage,
  normalizeRole,
  normalizeString,
  normalizeSupportedLocales,
  normalizeTenantSnapshot,
  normalizeTheme,
  normalizeTimeFormat,
  SUPPORTED_LOCALIZATION_LANGUAGES,
} from './sessionNormalizers';
import { extractErrorMessage, normalizeNetworkErrorMessage } from './sessionErrors';
const STORAGE_KEY = 'ii_videocall_v1_session';
function errorCodeFromPayload(payload) {
  const code = payload && typeof payload === 'object' ? payload?.error?.code : '';
  return typeof code === 'string' ? code.trim() : '';
}
function safeParse(raw) {
  if (!raw) return null;
  try {
    const parsed = JSON.parse(raw);
    if (!parsed || typeof parsed !== 'object') return null;
    const sessionToken = typeof parsed.sessionToken === 'string' ? parsed.sessionToken.trim() : '';
    if (sessionToken === '') return null;
    return {
      sessionId: typeof parsed.sessionId === 'string' ? parsed.sessionId.trim() : sessionToken,
      sessionToken,
      expiresAt: typeof parsed.expiresAt === 'string' ? parsed.expiresAt.trim() : '',
    };
  } catch {
    return null;
  }
}
const loaded = safeParse(typeof localStorage === 'undefined' ? null : localStorage.getItem(STORAGE_KEY));
export const sessionState = reactive({
  role: null,
  displayName: '',
  email: '',
  userId: 0,
  accountType: '',
  isGuest: false,
  avatarPath: null,
  timeFormat: '24h',
  dateFormat: 'dmy_dot',
  theme: 'dark',
  locale: 'en',
  direction: 'ltr',
  supportedLocales: SUPPORTED_LOCALIZATION_LANGUAGES,
  canEditThemes: false,
  tenantId: 0,
  tenantUuid: '',
  tenantLabel: '',
  tenantRole: '',
  tenantPermissions: {},
  postLogoutLandingUrl: '',
  aboutMe: '',
  linkedinUrl: '',
  xUrl: '',
  youtubeUrl: '',
  messengerContacts: [],
  onboardingCompletedTours: [],
  onboardingBadges: [],
  status: '',
  sessionId: loaded?.sessionId || '',
  sessionToken: loaded?.sessionToken || '',
  expiresAt: loaded?.expiresAt || '',
  recoveryState: 'idle',
  recoveryReason: '',
  recoveryMessage: '',
  recovered: loaded?.sessionToken ? false : true,
});
function persist() {
  if (typeof localStorage === 'undefined') return;
  if (!sessionState.sessionToken) {
    localStorage.removeItem(STORAGE_KEY);
    return;
  }
  localStorage.setItem(
    STORAGE_KEY,
    JSON.stringify({
      sessionId: sessionState.sessionId,
      sessionToken: sessionState.sessionToken,
      expiresAt: sessionState.expiresAt,
    }),
  );
}
function resetUserFields() {
  sessionState.role = null;
  sessionState.displayName = '';
  sessionState.email = '';
  sessionState.userId = 0;
  sessionState.accountType = '';
  sessionState.isGuest = false;
  sessionState.avatarPath = null;
  sessionState.timeFormat = '24h';
  sessionState.dateFormat = 'dmy_dot';
  sessionState.theme = 'dark';
  sessionState.locale = 'en';
  sessionState.direction = 'ltr';
  sessionState.supportedLocales = SUPPORTED_LOCALIZATION_LANGUAGES;
  sessionState.canEditThemes = false;
  sessionState.tenantId = 0;
  sessionState.tenantUuid = '';
  sessionState.tenantLabel = '';
  sessionState.tenantRole = '';
  sessionState.tenantPermissions = {};
  sessionState.postLogoutLandingUrl = '';
  sessionState.aboutMe = '';
  sessionState.linkedinUrl = '';
  sessionState.xUrl = '';
  sessionState.youtubeUrl = '';
  sessionState.messengerContacts = [];
  sessionState.onboardingCompletedTours = [];
  sessionState.onboardingBadges = [];
  sessionState.status = '';
}
function setRecoveryState(state, reason = '', message = '') {
  sessionState.recoveryState = state;
  sessionState.recoveryReason = reason;
  sessionState.recoveryMessage = message;
}
function applyUserSnapshot(user, tenant = null) {
  if (!user || typeof user !== 'object') {
    throw new Error('Backend user snapshot is missing.');
  }
  const role = normalizeRole(user.role);
  if (!role) {
    throw new Error('Backend session role is invalid.');
  }
  sessionState.role = role;
  sessionState.displayName = normalizeString(user.display_name);
  sessionState.email = normalizeString(user.email);
  sessionState.userId = Number.isInteger(user.id) ? user.id : 0;
  sessionState.accountType = inferAccountType(user);
  sessionState.isGuest = sessionState.accountType === 'guest';
  sessionState.avatarPath = typeof user.avatar_path === 'string' && normalizeString(user.avatar_path) !== ''
    ? normalizeString(user.avatar_path)
    : null;
  sessionState.timeFormat = normalizeTimeFormat(user.time_format);
  sessionState.dateFormat = normalizeDateFormat(user.date_format);
  sessionState.theme = normalizeTheme(user.theme);
  sessionState.locale = normalizeLocalizationLanguage(user.locale);
  sessionState.direction = normalizeString(user.direction) === 'rtl'
    ? 'rtl'
    : localizationLanguageDirection(sessionState.locale);
  sessionState.supportedLocales = normalizeSupportedLocales(user.supported_locales);
  const tenantSnapshot = normalizeTenantSnapshot(tenant || user.tenant);
  sessionState.tenantId = tenantSnapshot.id;
  sessionState.tenantUuid = tenantSnapshot.uuid;
  sessionState.tenantLabel = tenantSnapshot.label;
  sessionState.tenantRole = tenantSnapshot.role;
  sessionState.tenantPermissions = tenantSnapshot.permissions;
  sessionState.canEditThemes = role === 'admin' || user.can_edit_themes === true || tenantSnapshot.permissions.edit_themes === true;
  sessionState.postLogoutLandingUrl = normalizePostLogoutLandingUrl(user.post_logout_landing_url);
  sessionState.aboutMe = normalizeString(user.about_me);
  sessionState.linkedinUrl = normalizeString(user.linkedin_url);
  sessionState.xUrl = normalizeString(user.x_url);
  sessionState.youtubeUrl = normalizeString(user.youtube_url);
  sessionState.messengerContacts = normalizeMessengerContacts(user.messenger_contacts);
  sessionState.onboardingCompletedTours = normalizeOnboardingCompletedTours(user.onboarding_completed_tours);
  sessionState.onboardingBadges = normalizeOnboardingBadges(user.onboarding_badges);
  sessionState.status = normalizeString(user.status);
}
function applySessionEnvelope(session, user, tenant = null) {
  if (!session || typeof session !== 'object' || !user || typeof user !== 'object') {
    throw new Error('Backend authentication response is missing session/user data.');
  }
  const sessionToken = normalizeString(session.token || session.id || '');
  if (sessionToken === '') {
    throw new Error('Backend session token is missing.');
  }
  applyUserSnapshot(user, tenant);
  sessionState.sessionId = normalizeString(session.id || sessionToken);
  sessionState.sessionToken = sessionToken;
  sessionState.expiresAt = normalizeString(session.expires_at);
  sessionState.recovered = true;
  setRecoveryState('ready');
  persist();
}
export function isAuthenticated() {
  return !!sessionState.sessionToken && !!normalizeRole(sessionState.role);
}
export function isGuestSession() {
  return isAuthenticated() && sessionState.accountType === 'guest';
}
export function defaultRouteForRole(role) {
  return role === 'admin' ? '/admin/overview' : '/user/dashboard';
}
export function callListRouteForRole(role) {
  return role === 'admin' ? '/admin/calls' : '/user/dashboard';
}
function sessionHeaders() {
  const token = normalizeString(sessionState.sessionToken);
  const headers = {
    accept: 'application/json',
    'content-type': 'application/json',
  };
  if (token === '') {
    return headers;
  }
  return {
    ...headers,
    authorization: `Bearer ${token}`,
  };
}
async function readJsonResponse(response) {
  try {
    return await response.json();
  } catch {
    return null;
  }
}
export async function loadAvailableTenants() {
  const { response } = await fetchBackend('/api/tenants', {
    method: 'GET',
    headers: sessionHeaders(),
  });
  const payload = await readJsonResponse(response);
  if (!response.ok || !payload || payload.status !== 'ok') {
    throw new Error(extractErrorMessage(payload, 'Could not load tenants.'));
  }
  return Array.isArray(payload.tenants) ? payload.tenants.map(normalizeTenantSnapshot).filter((tenant) => tenant.id > 0) : [];
}
export async function switchActiveTenant(tenantId) {
  const nextTenantId = Number.parseInt(String(tenantId || 0), 10);
  if (!Number.isInteger(nextTenantId) || nextTenantId <= 0) {
    throw new Error('Tenant selection is invalid.');
  }
  const { response } = await fetchBackend('/api/auth/tenant', {
    method: 'POST',
    headers: sessionHeaders(),
    body: JSON.stringify({ tenant_id: nextTenantId }),
  });
  const payload = await readJsonResponse(response);
  if (!response.ok || !payload || payload.status !== 'ok') {
    throw new Error(extractErrorMessage(payload, 'Could not switch tenant.'));
  }
  applySessionEnvelope(payload.session || { id: sessionState.sessionId, token: sessionState.sessionToken, expires_at: sessionState.expiresAt }, payload.user, payload.tenant);
  return normalizeTenantSnapshot(payload.tenant);
}
function normalizeAuthErrorState(reason, message, clearState = false) {
  if (clearState) {
    clearSessionState();
  }
  setRecoveryState('error', reason, message);
  sessionState.recovered = !sessionState.sessionToken;
}
export async function loginWithPassword(email, password) {
  try {
    const { response } = await fetchBackend('/api/auth/login', {
      method: 'POST',
      headers: {
        accept: 'application/json',
        'content-type': 'application/json',
      },
      body: JSON.stringify({
        email: normalizeString(email).toLowerCase(),
        password: String(password || ''),
      }),
    });
    const payload = await readJsonResponse(response);
    if (!response.ok) {
      return {
        ok: false,
        status: response.status,
        message: extractErrorMessage(payload, 'Invalid email or password.'),
      };
    }
    if (!payload || payload.status !== 'ok') {
      return {
        ok: false,
        status: response.status,
        message: 'Authentication response is invalid.',
      };
    }
    applySessionEnvelope(payload.session, payload.user, payload.tenant);
    return {
      ok: true,
      role: sessionState.role,
    };
  } catch (error) {
    return {
      ok: false,
      status: 0,
      message: normalizeNetworkErrorMessage(error, 'Login request failed.'),
    };
  }
}
export async function loginWithEmailChangeToken(token) {
  const normalizedToken = normalizeString(token);
  if (normalizedToken === '') {
    return {
      ok: false,
      status: 422,
      message: 'Email confirmation token is required.',
    };
  }
  try {
    const { response } = await fetchBackend('/api/auth/email-change/confirm', {
      method: 'GET',
      query: {
        token: normalizedToken,
      },
      headers: {
        accept: 'application/json',
      },
    });
    const payload = await readJsonResponse(response);
    if (!response.ok) {
      return {
        ok: false,
        status: response.status,
        message: extractErrorMessage(payload, 'Could not confirm email change.'),
      };
    }
    if (!payload || payload.status !== 'ok') {
      return {
        ok: false,
        status: response.status,
        message: 'Email confirmation response is invalid.',
      };
    }
    applySessionEnvelope(payload.session, payload.user, payload.tenant);
    const result = payload?.result && typeof payload.result === 'object' ? payload.result : {};
    return {
      ok: true,
      role: sessionState.role,
      redirectPath: typeof result.redirect_path === 'string' ? result.redirect_path : '',
      editUserId: Number.isInteger(result.edit_user_id) ? result.edit_user_id : 0,
    };
  } catch (error) {
    return {
      ok: false,
      status: 0,
      message: normalizeNetworkErrorMessage(error, 'Email confirmation request failed.'),
    };
  }
}
export async function loginWithCallAccess(accessId, options = {}) {
  const normalizedAccessId = String(accessId || '').trim().toLowerCase();
  if (!/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/.test(normalizedAccessId)) {
    return {
      ok: false,
      status: 422,
      errorCode: 'call_access_validation_failed',
      message: 'Call access id is invalid.',
    };
  }
  try {
    const guestName = typeof options?.guestName === 'string' ? options.guestName.trim() : '';
    const requestBody = guestName !== '' ? JSON.stringify({ guest_name: guestName }) : undefined;
    const { response } = await fetchBackend(`/api/call-access/${encodeURIComponent(normalizedAccessId)}/session`, {
      method: 'POST',
      headers: {
        accept: 'application/json',
        ...(requestBody ? { 'content-type': 'application/json' } : {}),
      },
      body: requestBody,
    });
    const payload = await readJsonResponse(response);
    if (!response.ok) {
      return {
        ok: false,
        status: response.status,
        errorCode: errorCodeFromPayload(payload),
        message: extractErrorMessage(payload, 'Could not start call access session.'),
      };
    }
    if (!payload || payload.status !== 'ok') {
      return {
        ok: false,
        status: response.status,
        message: 'Call access response is invalid.',
      };
    }
    const result = payload?.result && typeof payload.result === 'object' ? payload.result : {};
    applySessionEnvelope(result.session, result.user, result.tenant);
    return {
      ok: true,
      role: sessionState.role,
      accessLink: result.access_link || null,
      call: result.call || null,
    };
  } catch (error) {
    return {
      ok: false,
      status: 0,
      message: normalizeNetworkErrorMessage(error, 'Call access session request failed.'),
    };
  }
}
let recoveryInFlight = null;
let refreshInFlight = null;
export async function ensureSessionRecovery(force = false) {
  if (recoveryInFlight) {
    return recoveryInFlight;
  }
  if (!force && sessionState.recovered) {
    return { ok: isAuthenticated(), reason: isAuthenticated() ? 'ready' : 'missing_session' };
  }
  recoveryInFlight = (async () => {
    if (!sessionState.sessionToken) {
      sessionState.recovered = true;
      setRecoveryState('idle');
      return { ok: false, reason: 'missing_session' };
    }
    setRecoveryState('probing');
    try {
      const { response } = await fetchBackend('/api/auth/session-state', {
        method: 'GET',
        headers: sessionHeaders(),
      });
      const payload = await readJsonResponse(response);
      if (!response.ok || !payload || payload.status !== 'ok') {
        const message = extractErrorMessage(payload, 'Session validation failed.');
        normalizeAuthErrorState('invalid_session', message, true);
        return {
          ok: false,
          reason: 'invalid_session',
          status: response.status,
          message,
        };
      }
      const sessionStateResult = String(payload?.result?.state || '').trim().toLowerCase();
      if (sessionStateResult !== 'authenticated') {
        const reason = String(payload?.result?.reason || 'invalid_session').trim().toLowerCase() || 'invalid_session';
        const message = 'Session validation failed.';
        normalizeAuthErrorState(reason, message, true);
        return {
          ok: false,
          reason,
          status: response.status,
          message,
        };
      }
      applySessionEnvelope(payload.session, payload.user, payload.tenant);
      return {
        ok: true,
        reason: 'ready',
        role: sessionState.role,
      };
    } catch (error) {
      setRecoveryState('error', 'network_error', error instanceof Error ? error.message : 'Session recovery failed.');
      sessionState.recovered = false;
      return {
        ok: false,
        reason: 'network_error',
        status: 0,
        message: normalizeNetworkErrorMessage(error, 'Session recovery failed.'),
      };
    } finally {
      recoveryInFlight = null;
    }
  })();
  return recoveryInFlight;
}
export async function refreshSession() {
  if (!sessionState.sessionToken) {
    return { ok: false, reason: 'missing_session' };
  }
  if (refreshInFlight) {
    return refreshInFlight;
  }
  refreshInFlight = (async () => {
    setRecoveryState('refreshing');
    try {
      const { response } = await fetchBackend('/api/auth/refresh', {
        method: 'POST',
        headers: sessionHeaders(),
      });
      const payload = await readJsonResponse(response);
      if (!response.ok || !payload || payload.status !== 'ok') {
        const message = extractErrorMessage(payload, 'Session refresh failed.');
        if ([401, 403, 409].includes(response.status)) {
          normalizeAuthErrorState('invalid_session', message, true);
          return {
            ok: false,
            reason: 'invalid_session',
            status: response.status,
            message,
          };
        }
        setRecoveryState('error', 'refresh_failed', message);
        sessionState.recovered = false;
        return {
          ok: false,
          reason: 'refresh_failed',
          status: response.status,
          message,
        };
      }
      applySessionEnvelope(payload.session, payload.user, payload.tenant);
      return {
        ok: true,
        reason: 'ready',
        role: sessionState.role,
      };
    } catch (error) {
      setRecoveryState('error', 'network_error', error instanceof Error ? error.message : 'Session refresh failed.');
      sessionState.recovered = false;
      return {
        ok: false,
        reason: 'network_error',
        status: 0,
        message: normalizeNetworkErrorMessage(error, 'Session refresh failed.'),
      };
    } finally {
      refreshInFlight = null;
    }
  })();
  return refreshInFlight;
}
export async function fetchSessionSettings() {
  if (!sessionState.sessionToken) {
    return {
      ok: false,
      reason: 'missing_session',
      message: 'A valid session token is required.',
    };
  }
  try {
    const { response } = await fetchBackend('/api/user/settings', {
      method: 'GET',
      headers: sessionHeaders(),
    });
    const payload = await readJsonResponse(response);
    if (!response.ok || !payload || payload.status !== 'ok') {
      const message = extractErrorMessage(payload, 'Could not load user settings.');
      if ([401, 403].includes(response.status)) {
        normalizeAuthErrorState('invalid_session', message, true);
        return {
          ok: false,
          reason: 'invalid_session',
          status: response.status,
          message,
        };
      }
      return {
        ok: false,
        reason: 'request_failed',
        status: response.status,
        message,
      };
    }
    if (payload.user) {
      applyUserSnapshot(payload.user);
      sessionState.recoveryState = 'ready';
      sessionState.recoveryReason = '';
      sessionState.recoveryMessage = '';
      sessionState.recovered = true;
      persist();
    }
    return {
      ok: true,
      reason: 'ready',
      settings: payload.settings || payload.result?.settings || null,
      user: payload.user || payload.result?.user || null,
    };
  } catch (error) {
    return {
      ok: false,
      reason: 'network_error',
      status: 0,
      message: normalizeNetworkErrorMessage(error, 'Could not load user settings.'),
    };
  }
}
export async function saveSessionSettings(settingsPatch) {
  if (!sessionState.sessionToken) {
    return {
      ok: false,
      reason: 'missing_session',
      message: 'A valid session token is required.',
    };
  }
  const patch = {};
  if (settingsPatch && typeof settingsPatch === 'object') {
    for (const [field, value] of Object.entries(settingsPatch)) {
      if (value === undefined) continue;
      patch[field] = value;
    }
  }
  try {
    const { response } = await fetchBackend('/api/user/settings', {
      method: 'PATCH',
      headers: sessionHeaders(),
      body: JSON.stringify(patch),
    });
    const payload = await readJsonResponse(response);
    if (!response.ok || !payload || payload.status !== 'ok') {
      const message = extractErrorMessage(payload, 'Could not update user settings.');
      if ([401, 403].includes(response.status)) {
        normalizeAuthErrorState('invalid_session', message, true);
        return {
          ok: false,
          reason: 'invalid_session',
          status: response.status,
          message,
        };
      }
      return {
        ok: false,
        reason: 'request_failed',
        status: response.status,
        message,
        fields: payload?.error?.details?.fields || {},
      };
    }
    if (payload.result?.user) {
      applyUserSnapshot(payload.result.user);
      sessionState.recoveryState = 'ready';
      sessionState.recoveryReason = '';
      sessionState.recoveryMessage = '';
      sessionState.recovered = true;
      persist();
    }
    return {
      ok: true,
      reason: 'updated',
      settings: payload.result?.settings || payload.settings || null,
      user: payload.result?.user || payload.user || null,
    };
  } catch (error) {
    return {
      ok: false,
      reason: 'network_error',
      status: 0,
      message: normalizeNetworkErrorMessage(error, 'Could not update user settings.'),
    };
  }
}
export async function completeOnboardingTour(tourKey) {
  if (!sessionState.sessionToken) {
    return {
      ok: false,
      reason: 'missing_session',
      message: 'A valid session token is required.',
    };
  }

  const normalizedTourKey = normalizeString(tourKey).toLowerCase();
  if (normalizedTourKey === '') {
    return {
      ok: false,
      reason: 'invalid_tour_key',
      message: 'Tour key is required.',
    };
  }

  try {
    const { response } = await fetchBackend('/api/user/onboarding/tours/complete', {
      method: 'POST',
      headers: sessionHeaders(),
      body: JSON.stringify({ tour_key: normalizedTourKey }),
    });
    const payload = await readJsonResponse(response);
    if (!response.ok || !payload || payload.status !== 'ok') {
      const message = extractErrorMessage(payload, 'Could not update onboarding progress.');
      if ([401, 403].includes(response.status)) {
        normalizeAuthErrorState('invalid_session', message, true);
        return {
          ok: false,
          reason: 'invalid_session',
          status: response.status,
          message,
        };
      }
      return {
        ok: false,
        reason: 'request_failed',
        status: response.status,
        message,
        fields: payload?.error?.details?.fields || {},
      };
    }

    const onboarding = payload.result?.onboarding || {};
    sessionState.onboardingCompletedTours = normalizeOnboardingCompletedTours(onboarding.completed_tours);
    sessionState.onboardingBadges = normalizeOnboardingBadges(onboarding.badges);
    return {
      ok: true,
      reason: payload.result?.state || 'completed',
      onboarding,
    };
  } catch (error) {
    return {
      ok: false,
      reason: 'network_error',
      status: 0,
      message: normalizeNetworkErrorMessage(error, 'Could not update onboarding progress.'),
    };
  }
}
export async function uploadSessionAvatar(dataUrl) {
  if (!sessionState.sessionToken) {
    return {
      ok: false,
      reason: 'missing_session',
      message: 'A valid session token is required.',
    };
  }
  try {
    const { response } = await fetchBackend('/api/user/avatar', {
      method: 'POST',
      headers: sessionHeaders(),
      body: JSON.stringify({
        data_url: String(dataUrl || ''),
      }),
    });
    const payload = await readJsonResponse(response);
    if (!response.ok || !payload || payload.status !== 'ok') {
      const message = extractErrorMessage(payload, 'Could not upload avatar.');
      if ([401, 403].includes(response.status)) {
        normalizeAuthErrorState('invalid_session', message, true);
        return {
          ok: false,
          reason: 'invalid_session',
          status: response.status,
          message,
        };
      }
      return {
        ok: false,
        reason: 'request_failed',
        status: response.status,
        message,
        fields: payload?.error?.details?.fields || {},
      };
    }
    const avatarPath = normalizeString(payload.result?.avatar_path || payload.avatar_path);
    if (avatarPath !== '') {
      sessionState.avatarPath = avatarPath;
      persist();
    }
    return {
      ok: true,
      reason: 'uploaded',
      avatarPath: avatarPath || null,
      fileName: normalizeString(payload.result?.file_name),
      contentType: normalizeString(payload.result?.content_type),
      bytes: Number.isInteger(payload.result?.bytes) ? payload.result.bytes : 0,
    };
  } catch (error) {
    return {
      ok: false,
      reason: 'network_error',
      status: 0,
      message: normalizeNetworkErrorMessage(error, 'Could not upload avatar.'),
    };
  }
}
export async function logoutSession() {
  let postLogoutLandingUrl = normalizePostLogoutLandingUrl(sessionState.postLogoutLandingUrl);
  try {
    if (sessionState.sessionToken) {
      const { response } = await fetchBackend('/api/auth/logout', {
        method: 'POST',
        headers: sessionHeaders(),
      });
      const payload = await readJsonResponse(response);
      const backendLandingUrl = normalizePostLogoutLandingUrl(payload?.result?.post_logout_landing_url);
      if (backendLandingUrl !== '') {
        postLogoutLandingUrl = backendLandingUrl;
      }
    }
  } catch {
    // Best-effort logout: local session must still be dropped fail-closed.
  } finally {
    clearSessionState();
    setRecoveryState('idle');
  }
  return { ok: true, postLogoutLandingUrl };
}
export function postLogoutRedirectTarget(result = null, fallback = '/login') {
  const target = normalizePostLogoutLandingUrl(result?.postLogoutLandingUrl || sessionState.postLogoutLandingUrl);
  return target !== '' ? target : fallback;
}
export function clearSessionState() {
  resetUserFields();
  sessionState.sessionId = '';
  sessionState.sessionToken = '';
  sessionState.expiresAt = '';
  sessionState.recovered = true;
  setRecoveryState('idle');
  persist();
}
