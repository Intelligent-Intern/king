import { reactive } from 'vue';
import { fetchBackend } from '../../support/backendFetch';

const DEFAULT_LOGO = '/assets/orgas/kingrt/logo.svg';
const THEME_STORAGE_KEY = 'ii_videocall_v1_workspace_appearance';

export const appearanceState = reactive({
  loaded: false,
  loading: false,
  version: 0,
  sidebarLogoPath: DEFAULT_LOGO,
  modalLogoPath: DEFAULT_LOGO,
  themes: [],
  defaults: {
    sidebar_logo_path: DEFAULT_LOGO,
    modal_logo_path: DEFAULT_LOGO,
  },
});

function normalizeLogoPath(value) {
  const path = String(value || '').trim();
  return path !== '' ? path : DEFAULT_LOGO;
}

function normalizeTheme(theme) {
  const payload = theme && typeof theme === 'object' ? theme : {};
  const id = String(payload.id || '').trim();
  if (id === '') return null;
  const colors = payload.colors && typeof payload.colors === 'object' ? payload.colors : {};
  return {
    id,
    label: String(payload.label || id).trim() || id,
    colors,
    isSystem: payload.is_system === true,
  };
}

function persistAppearance() {
  if (typeof localStorage === 'undefined') return;
  localStorage.setItem(THEME_STORAGE_KEY, JSON.stringify({
    sidebarLogoPath: appearanceState.sidebarLogoPath,
    modalLogoPath: appearanceState.modalLogoPath,
    themes: appearanceState.themes,
    defaults: appearanceState.defaults,
  }));
}

function readPersistedAppearance() {
  if (typeof localStorage === 'undefined') return;
  const raw = localStorage.getItem(THEME_STORAGE_KEY);
  if (!raw) return;
  try {
    const parsed = JSON.parse(raw);
    if (!parsed || typeof parsed !== 'object') return;
    appearanceState.sidebarLogoPath = normalizeLogoPath(parsed.sidebarLogoPath);
    appearanceState.modalLogoPath = normalizeLogoPath(parsed.modalLogoPath);
    appearanceState.themes = Array.isArray(parsed.themes)
      ? parsed.themes.map(normalizeTheme).filter(Boolean)
      : [];
    appearanceState.defaults = parsed.defaults && typeof parsed.defaults === 'object'
      ? parsed.defaults
      : appearanceState.defaults;
  } catch {
  }
}

export function applyAppearancePayload(payload) {
  const result = payload && typeof payload === 'object' ? payload : {};
  appearanceState.sidebarLogoPath = normalizeLogoPath(result.sidebar_logo_path);
  appearanceState.modalLogoPath = normalizeLogoPath(result.modal_logo_path);
  appearanceState.themes = Array.isArray(result.themes)
    ? result.themes.map(normalizeTheme).filter(Boolean)
    : [];
  appearanceState.defaults = result.defaults && typeof result.defaults === 'object'
    ? result.defaults
    : appearanceState.defaults;
  appearanceState.loaded = true;
  appearanceState.version += 1;
  persistAppearance();
  applyWorkspaceBrandingDom();
}

export async function loadWorkspaceAppearance({ force = false } = {}) {
  if (appearanceState.loading) return appearanceState;
  if (appearanceState.loaded && !force) return appearanceState;
  appearanceState.loading = true;
  try {
    const { response } = await fetchBackend('/api/workspace/appearance', {
      method: 'GET',
      headers: { accept: 'application/json' },
    });
    const payload = await response.json().catch(() => null);
    if (response.ok && payload?.status === 'ok') {
      applyAppearancePayload(payload.result || {});
    }
  } finally {
    appearanceState.loading = false;
  }
  return appearanceState;
}

export function themeColorsForId(themeId) {
  const id = String(themeId || '').trim();
  if (id === '') return null;
  const theme = appearanceState.themes.find((entry) => entry.id === id);
  return theme?.colors && typeof theme.colors === 'object' ? theme.colors : null;
}

export function applyWorkspaceBrandingDom(root = null) {
  if (typeof document === 'undefined') return;
  const targetRoot = root || document;
  if (!targetRoot?.querySelectorAll) return;
  const sidebarLogo = normalizeLogoPath(appearanceState.sidebarLogoPath);
  const modalLogo = normalizeLogoPath(appearanceState.modalLogoPath);
  targetRoot.querySelectorAll('img[data-brand-logo]').forEach((image) => {
    image.setAttribute('src', sidebarLogo);
  });
  targetRoot.querySelectorAll([
    '.settings-title-wrap img',
    '.calls-modal-header-enter-logo',
    '.call-owner-edit-logo',
    '.governance-side-panel-head-logo',
    '.marketplace-side-panel-head-logo',
    '.users-side-panel-head-logo',
  ].join(',')).forEach((image) => {
    image.setAttribute('src', modalLogo);
  });
}

readPersistedAppearance();
