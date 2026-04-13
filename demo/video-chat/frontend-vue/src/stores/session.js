import { reactive } from 'vue';

const STORAGE_KEY = 'ii_videocall_v1_session';
const AUTH_ROLES = new Set(['admin', 'moderator', 'user']);

function normalizeRole(value) {
  const role = String(value || '').trim().toLowerCase();
  return AUTH_ROLES.has(role) ? role : null;
}

function resolveBackendOrigin() {
  const envOrigin = String(import.meta.env.VITE_VIDEOCHAT_BACKEND_ORIGIN || '').trim();
  if (envOrigin !== '') {
    return envOrigin.replace(/\/+$/, '');
  }

  const port = String(import.meta.env.VITE_VIDEOCHAT_BACKEND_PORT || '18080').trim() || '18080';

  if (typeof window !== 'undefined') {
    const protocol = window.location.protocol === 'https:' ? 'https' : 'http';
    const host = window.location.hostname || '127.0.0.1';
    return `${protocol}://${host}:${port}`;
  }

  return `http://127.0.0.1:${port}`;
}

function extractErrorMessage(payload, fallback) {
  if (payload && typeof payload === 'object') {
    const message = payload?.error?.message;
    if (typeof message === 'string' && message.trim() !== '') {
      return message.trim();
    }
  }

  return fallback;
}

function safeParse(raw) {
  if (!raw) return null;
  try {
    const parsed = JSON.parse(raw);
    if (!parsed || typeof parsed !== 'object') return null;
    const role = normalizeRole(parsed.role);
    if (!role) return null;
    if (typeof parsed.displayName !== 'string' || parsed.displayName.trim() === '') return null;
    const sessionToken = typeof parsed.sessionToken === 'string' ? parsed.sessionToken.trim() : '';
    if (sessionToken === '') return null;
    return {
      role,
      displayName: parsed.displayName.trim(),
      email: typeof parsed.email === 'string' ? parsed.email.trim() : '',
      userId: Number.isInteger(parsed.userId) ? parsed.userId : 0,
      avatarPath: typeof parsed.avatarPath === 'string' && parsed.avatarPath.trim() !== '' ? parsed.avatarPath.trim() : null,
      timeFormat: typeof parsed.timeFormat === 'string' && parsed.timeFormat.trim() !== '' ? parsed.timeFormat.trim() : '24h',
      theme: typeof parsed.theme === 'string' && parsed.theme.trim() !== '' ? parsed.theme.trim() : 'dark',
      status: typeof parsed.status === 'string' ? parsed.status.trim() : '',
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
  role: loaded?.role || null,
  displayName: loaded?.displayName || '',
  email: loaded?.email || '',
  userId: loaded?.userId || 0,
  avatarPath: loaded?.avatarPath || null,
  timeFormat: loaded?.timeFormat || '24h',
  theme: loaded?.theme || 'dark',
  status: loaded?.status || '',
  sessionId: loaded?.sessionId || '',
  sessionToken: loaded?.sessionToken || '',
  expiresAt: loaded?.expiresAt || '',
  recoveryState: 'idle',
  recovered: loaded?.sessionToken ? false : true,
});

function persist() {
  if (typeof localStorage === 'undefined') return;
  if (!sessionState.role || !sessionState.sessionToken) {
    localStorage.removeItem(STORAGE_KEY);
    return;
  }
  localStorage.setItem(
    STORAGE_KEY,
    JSON.stringify({
      role: sessionState.role,
      displayName: sessionState.displayName,
      email: sessionState.email,
      userId: sessionState.userId,
      avatarPath: sessionState.avatarPath,
      timeFormat: sessionState.timeFormat,
      theme: sessionState.theme,
      status: sessionState.status,
      sessionId: sessionState.sessionId,
      sessionToken: sessionState.sessionToken,
      expiresAt: sessionState.expiresAt,
    }),
  );
}

function clearSessionState() {
  sessionState.role = null;
  sessionState.displayName = '';
  sessionState.email = '';
  sessionState.userId = 0;
  sessionState.avatarPath = null;
  sessionState.timeFormat = '24h';
  sessionState.theme = 'dark';
  sessionState.status = '';
  sessionState.sessionId = '';
  sessionState.sessionToken = '';
  sessionState.expiresAt = '';
  sessionState.recovered = true;
  persist();
}

function applySessionEnvelope(session, user) {
  if (!session || typeof session !== 'object' || !user || typeof user !== 'object') {
    throw new Error('Backend authentication response is missing session/user data.');
  }

  const role = normalizeRole(user.role);
  if (!role) {
    throw new Error('Backend session role is invalid.');
  }

  const sessionToken = String(session.token || session.id || '').trim();
  if (sessionToken === '') {
    throw new Error('Backend session token is missing.');
  }

  sessionState.role = role;
  sessionState.displayName = String(user.display_name || '').trim();
  sessionState.email = String(user.email || '').trim();
  sessionState.userId = Number.isInteger(user.id) ? user.id : 0;
  sessionState.avatarPath = typeof user.avatar_path === 'string' && user.avatar_path.trim() !== ''
    ? user.avatar_path.trim()
    : null;
  sessionState.timeFormat = typeof user.time_format === 'string' && user.time_format.trim() !== ''
    ? user.time_format.trim()
    : '24h';
  sessionState.theme = typeof user.theme === 'string' && user.theme.trim() !== ''
    ? user.theme.trim()
    : 'dark';
  sessionState.status = typeof user.status === 'string' ? user.status.trim() : '';
  sessionState.sessionId = String(session.id || sessionToken).trim();
  sessionState.sessionToken = sessionToken;
  sessionState.expiresAt = typeof session.expires_at === 'string' ? session.expires_at.trim() : '';
  sessionState.recovered = true;
  persist();
}

export function isAuthenticated() {
  return !!sessionState.sessionToken && !!normalizeRole(sessionState.role);
}

export function defaultRouteForRole(role) {
  return role === 'admin' ? '/admin/overview' : '/user/dashboard';
}

function sessionHeaders() {
  const token = String(sessionState.sessionToken || '').trim();
  if (token === '') {
    return {
      accept: 'application/json',
      'content-type': 'application/json',
    };
  }

  return {
    accept: 'application/json',
    'content-type': 'application/json',
    authorization: `Bearer ${token}`,
    'x-session-id': token,
  };
}

export async function loginWithPassword(email, password) {
  const endpoint = `${resolveBackendOrigin()}/api/auth/login`;

  try {
    const response = await fetch(endpoint, {
      method: 'POST',
      headers: {
        accept: 'application/json',
        'content-type': 'application/json',
      },
      body: JSON.stringify({
        email: String(email || '').trim(),
        password: String(password || ''),
      }),
    });

    let payload = null;
    try {
      payload = await response.json();
    } catch {
      payload = null;
    }

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

    applySessionEnvelope(payload.session, payload.user);
    return {
      ok: true,
      role: sessionState.role,
    };
  } catch (error) {
    return {
      ok: false,
      status: 0,
      message: error instanceof Error ? error.message : 'Login request failed.',
    };
  }
}

let recoveryInFlight = null;

export async function ensureSessionRecovery(force = false) {
  if (!force && sessionState.recovered) {
    return { ok: isAuthenticated(), reason: isAuthenticated() ? 'ready' : 'missing_session' };
  }

  if (!force && recoveryInFlight) {
    return recoveryInFlight;
  }

  recoveryInFlight = (async () => {
    if (!sessionState.sessionToken) {
      sessionState.recovered = true;
      sessionState.recoveryState = 'idle';
      return { ok: false, reason: 'missing_session' };
    }

    sessionState.recoveryState = 'probing';
    const endpoint = `${resolveBackendOrigin()}/api/auth/session`;
    try {
      const response = await fetch(endpoint, {
        method: 'GET',
        headers: sessionHeaders(),
      });

      let payload = null;
      try {
        payload = await response.json();
      } catch {
        payload = null;
      }

      if (!response.ok || !payload || payload.status !== 'ok') {
        clearSessionState();
        sessionState.recoveryState = 'error';
        return {
          ok: false,
          reason: 'invalid_session',
          status: response.status,
          message: extractErrorMessage(payload, 'Session validation failed.'),
        };
      }

      applySessionEnvelope(payload.session, payload.user);
      sessionState.recoveryState = 'ready';
      return {
        ok: true,
        reason: 'ready',
        role: sessionState.role,
      };
    } catch (error) {
      clearSessionState();
      sessionState.recoveryState = 'error';
      return {
        ok: false,
        reason: 'network_error',
        status: 0,
        message: error instanceof Error ? error.message : 'Session recovery failed.',
      };
    } finally {
      sessionState.recovered = true;
    }
  })().finally(() => {
    recoveryInFlight = null;
  });

  return recoveryInFlight;
}

export async function logoutSession() {
  const endpoint = `${resolveBackendOrigin()}/api/auth/logout`;

  try {
    if (sessionState.sessionToken) {
      await fetch(endpoint, {
        method: 'POST',
        headers: sessionHeaders(),
      });
    }
  } catch {
    // Best-effort logout: local session must still be dropped fail-closed.
  } finally {
    clearSessionState();
    sessionState.recoveryState = 'idle';
  }

  return { ok: true };
}
