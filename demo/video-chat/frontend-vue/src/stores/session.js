import { reactive } from 'vue';

const STORAGE_KEY = 'ii_videocall_v1_session';

function safeParse(raw) {
  if (!raw) return null;
  try {
    const parsed = JSON.parse(raw);
    if (!parsed || typeof parsed !== 'object') return null;
    if (parsed.role !== 'admin' && parsed.role !== 'user') return null;
    if (typeof parsed.displayName !== 'string' || parsed.displayName.trim() === '') return null;
    return {
      role: parsed.role,
      displayName: parsed.displayName.trim(),
      email: typeof parsed.email === 'string' ? parsed.email.trim() : '',
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
});

function persist() {
  if (typeof localStorage === 'undefined') return;
  if (!sessionState.role) {
    localStorage.removeItem(STORAGE_KEY);
    return;
  }
  localStorage.setItem(
    STORAGE_KEY,
    JSON.stringify({
      role: sessionState.role,
      displayName: sessionState.displayName,
      email: sessionState.email,
    }),
  );
}

export function isAuthenticated() {
  return sessionState.role === 'admin' || sessionState.role === 'user';
}

export function defaultRouteForRole(role) {
  return role === 'admin' ? '/admin/overview' : '/user/dashboard';
}

export function signInAs(role, displayName, email = '') {
  sessionState.role = role;
  sessionState.displayName = String(displayName || '').trim();
  sessionState.email = String(email || '').trim();
  persist();
}

export function signOut() {
  sessionState.role = null;
  sessionState.displayName = '';
  sessionState.email = '';
  persist();
}
