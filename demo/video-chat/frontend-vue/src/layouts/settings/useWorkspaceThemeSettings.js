import { computed, onMounted, reactive, ref, watch } from 'vue';
import { sessionState as defaultSessionState } from '../../domain/auth/session';
import {
  deleteWorkspaceTheme,
  saveWorkspaceAdministration,
} from '../../domain/workspace/administrationApi';
import {
  appearanceState as defaultAppearanceState,
  applyAppearancePayload,
  loadWorkspaceAppearance,
} from '../../domain/workspace/appearance';

const DEFAULT_LOGO = '/assets/orgas/kingrt/logo.svg';
const DEFAULT_PAGE_SIZE = 5;

const THEME_COLOR_FIELDS = Object.freeze([
  { key: '--bg-shell', labelKey: 'theme_settings.color.shell_background', default: '#000010' },
  { key: '--bg-pane', labelKey: 'theme_settings.color.pane_background', default: '#000010' },
  { key: '--brand-bg', labelKey: 'theme_settings.color.brand_strip', default: '#000010' },
  { key: '--bg-surface', labelKey: 'theme_settings.color.surface', default: '#00052d' },
  { key: '--bg-surface-strong', labelKey: 'theme_settings.color.surface_strong', default: '#00052d' },
  { key: '--bg-input', labelKey: 'theme_settings.color.input_background', default: '#00052d' },
  { key: '--bg-action', labelKey: 'theme_settings.color.action', default: '#1582bf' },
  { key: '--bg-action-hover', labelKey: 'theme_settings.color.action_hover', default: '#59c7f2' },
  { key: '--bg-row', labelKey: 'theme_settings.color.row', default: '#00052d' },
  { key: '--bg-row-hover', labelKey: 'theme_settings.color.row_hover', default: '#03275a' },
  { key: '--line', labelKey: 'theme_settings.color.line', default: '#03275a' },
  { key: '--text-main', labelKey: 'theme_settings.color.text_main', default: '#ffffff' },
  { key: '--text-muted', labelKey: 'theme_settings.color.text_muted', default: '#efefe7' },
  { key: '--ok', labelKey: 'theme_settings.color.ok', default: '#00652f' },
  { key: '--wait', labelKey: 'theme_settings.color.wait', default: '#f47221' },
  { key: '--danger', labelKey: 'theme_settings.color.danger', default: '#ef4423' },
  { key: '--bg-sidebar', labelKey: 'theme_settings.color.sidebar', default: '#000010' },
  { key: '--bg-main', labelKey: 'theme_settings.color.main', default: '#000010' },
  { key: '--bg-tab', labelKey: 'theme_settings.color.tab', default: '#00052d' },
  { key: '--bg-tab-hover', labelKey: 'theme_settings.color.tab_hover', default: '#03275a' },
  { key: '--bg-tab-active', labelKey: 'theme_settings.color.tab_active', default: '#1582bf' },
  { key: '--bg-ui-chrome', labelKey: 'theme_settings.color.ui_chrome', default: '#00052d' },
  { key: '--bg-ui-chrome-active', labelKey: 'theme_settings.color.ui_chrome_active', default: '#03275a' },
  { key: '--bg-icon', labelKey: 'theme_settings.color.icon_background', default: '#00052d' },
  { key: '--bg-icon-active', labelKey: 'theme_settings.color.icon_active', default: '#1582bf' },
  { key: '--border-subtle', labelKey: 'theme_settings.color.border_subtle', default: '#03275a' },
  { key: '--text-primary', labelKey: 'theme_settings.color.text_primary', default: '#ffffff' },
  { key: '--text-secondary', labelKey: 'theme_settings.color.text_secondary', default: '#efefe7' },
  { key: '--text-dim', labelKey: 'theme_settings.color.text_dim', default: '#efefe7' },
  { key: '--warn', labelKey: 'theme_settings.color.warn', default: '#f47221' },
  { key: '--brand-cyan', labelKey: 'theme_settings.color.brand_cyan', default: '#1582bf' },
  { key: '--brand-cyan-hover', labelKey: 'theme_settings.color.brand_cyan_hover', default: '#59c7f2' },
  { key: '--brand-cyan-active', labelKey: 'theme_settings.color.brand_cyan_active', default: '#1582bf' },
]);

function fallbackTranslate(key, params = {}) {
  return String(key || '').replace(/\{([A-Za-z0-9_.-]+)\}/g, (_match, name) => String(params?.[name] ?? ''));
}

function createTranslator(t) {
  return typeof t === 'function' ? t : fallbackTranslate;
}

export function defaultWorkspaceThemeColors(themeId = 'dark') {
  const lightOverrides = {
    '--bg-shell': '#eff4fb',
    '--bg-pane': '#dce8f6',
    '--brand-bg': '#e8eff8',
    '--bg-surface': '#f4f8fd',
    '--bg-surface-strong': '#ffffff',
    '--bg-input': '#ffffff',
    '--text-main': '#122035',
    '--text-muted': '#5a6780',
    '--bg-sidebar': '#e8eff8',
    '--bg-main': '#dce8f6',
    '--text-primary': '#122035',
    '--text-secondary': '#33425d',
    '--text-dim': '#6d7d96',
  };
  const colors = {};
  for (const field of THEME_COLOR_FIELDS) {
    colors[field.key] = themeId === 'light' && lightOverrides[field.key] ? lightOverrides[field.key] : field.default;
  }
  return colors;
}

function normalizeHex(value, fallback = '#000000') {
  const normalized = String(value || '').trim().toLowerCase();
  if (/^#[a-f0-9]{6}$/.test(normalized)) return normalized;
  if (/^[a-f0-9]{6}$/.test(normalized)) return `#${normalized}`;
  if (/^#[a-f0-9]{3}$/.test(normalized)) {
    return `#${normalized[1]}${normalized[1]}${normalized[2]}${normalized[2]}${normalized[3]}${normalized[3]}`;
  }
  return fallback;
}

function readFileAsDataUrl(file, errorMessage) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(typeof reader.result === 'string' ? reader.result : '');
    reader.onerror = () => reject(new Error(errorMessage));
    reader.readAsDataURL(file);
  });
}

export function useWorkspaceThemeSettings(options = {}) {
  const props = options.props || {};
  const emit = typeof options.emit === 'function' ? options.emit : () => {};
  const t = createTranslator(options.t);
  const sessionState = options.sessionState || defaultSessionState;
  const appearanceState = options.appearanceState || defaultAppearanceState;
  const saveAdministration = options.saveAdministration || saveWorkspaceAdministration;
  const deleteThemeRequest = options.deleteTheme || deleteWorkspaceTheme;
  const loadAppearance = options.loadAppearance || loadWorkspaceAppearance;
  const applyAppearance = options.applyAppearance || applyAppearancePayload;
  const pageSize = Number(options.pageSize || DEFAULT_PAGE_SIZE) || DEFAULT_PAGE_SIZE;

  const state = reactive({
    saving: false,
    deletingId: '',
    error: '',
    notice: '',
  });
  const editor = reactive({
    open: false,
    step: 'logos',
    createNew: false,
    id: '',
    label: '',
    baseThemeId: 'dark',
    colors: {},
    sidebarLogoDataUrl: '',
    sidebarLogoReset: false,
    modalLogoDataUrl: '',
    modalLogoReset: false,
  });
  const themePage = ref(1);

  const themeColorFields = computed(() => THEME_COLOR_FIELDS.map((field) => ({
    ...field,
    label: t(field.labelKey),
  })));
  const previewColorFields = computed(() => themeColorFields.value.slice(0, 6));
  const fallbackThemes = computed(() => ([
    { id: 'dark', label: t('theme_settings.system_dark'), colors: defaultWorkspaceThemeColors(), isSystem: true },
    { id: 'light', label: t('theme_settings.system_light'), colors: defaultWorkspaceThemeColors('light'), isSystem: true },
  ]));
  const themeOptions = computed(() => (
    appearanceState.themes.length > 0 ? appearanceState.themes : fallbackThemes.value
  ));
  const themePageCount = computed(() => Math.max(1, Math.ceil(themeOptions.value.length / pageSize)));
  const selectedTheme = computed({
    get: () => props.modelValue || themeOptions.value[0]?.id || 'dark',
    set: (value) => emit('update:modelValue', normalizeThemeId(value)),
  });
  const pagedThemes = computed(() => {
    const currentPage = Math.max(1, Math.min(themePage.value, themePageCount.value));
    const start = (currentPage - 1) * pageSize;
    return themeOptions.value.slice(start, start + pageSize);
  });
  const canEditThemes = computed(() => sessionState.role === 'admin' || sessionState.canEditThemes === true);
  const canManageBranding = computed(() => Number(sessionState.userId || 0) === 1);
  const sidebarLogoPreview = computed(() => (
    editor.sidebarLogoReset ? DEFAULT_LOGO : (editor.sidebarLogoDataUrl || appearanceState.sidebarLogoPath || DEFAULT_LOGO)
  ));
  const modalLogoPreview = computed(() => (
    editor.modalLogoReset ? DEFAULT_LOGO : (editor.modalLogoDataUrl || appearanceState.modalLogoPath || DEFAULT_LOGO)
  ));

  watch(themePageCount, () => {
    themePage.value = Math.max(1, Math.min(themePage.value, themePageCount.value));
  });

  function normalizeThemeId(value) {
    const candidate = String(value || '').trim();
    return themeOptions.value.some((theme) => theme.id === candidate) ? candidate : themeOptions.value[0]?.id || 'dark';
  }

  function patchColors(source = {}) {
    for (const field of THEME_COLOR_FIELDS) {
      editor.colors[field.key] = normalizeHex(source[field.key], field.default);
    }
  }

  function startCreateTheme() {
    state.error = '';
    state.notice = '';
    editor.open = true;
    editor.step = 'logos';
    editor.createNew = true;
    editor.id = '';
    editor.label = t('theme_settings.custom_theme_default_name');
    editor.baseThemeId = selectedTheme.value || 'dark';
    editor.sidebarLogoDataUrl = '';
    editor.sidebarLogoReset = false;
    editor.modalLogoDataUrl = '';
    editor.modalLogoReset = false;
    const baseTheme = themeOptions.value.find((theme) => theme.id === editor.baseThemeId) || themeOptions.value[0];
    patchColors(baseTheme?.colors || defaultWorkspaceThemeColors());
  }

  function startEditTheme(theme) {
    state.error = '';
    state.notice = '';
    editor.open = true;
    editor.step = 'logos';
    editor.createNew = false;
    editor.id = String(theme?.id || '');
    editor.label = String(theme?.label || editor.id || t('theme_settings.theme_fallback_name'));
    editor.baseThemeId = editor.id || 'dark';
    editor.sidebarLogoDataUrl = '';
    editor.sidebarLogoReset = false;
    editor.modalLogoDataUrl = '';
    editor.modalLogoReset = false;
    patchColors(theme?.colors || defaultWorkspaceThemeColors(editor.baseThemeId));
  }

  function cancelEditor() {
    if (state.saving) return;
    editor.open = false;
    editor.step = 'logos';
    state.error = '';
  }

  function loadBasePalette(themeId) {
    const theme = themeOptions.value.find((entry) => entry.id === themeId);
    patchColors(theme?.colors || defaultWorkspaceThemeColors(themeId));
  }

  function loadSystemDefault(themeId) {
    patchColors(defaultWorkspaceThemeColors(themeId));
  }

  function updateThemeColor(key, value) {
    const field = THEME_COLOR_FIELDS.find((entry) => entry.key === key);
    if (!field) return;
    editor.colors[key] = normalizeHex(value, editor.colors[key] || field.default);
  }

  async function selectLogo(event, kind) {
    const file = event?.target?.files?.[0] || null;
    if (!file) return;
    if (!['image/png', 'image/jpeg', 'image/webp'].includes(file.type)) {
      state.error = t('theme_settings.logo_type_invalid');
      return;
    }
    try {
      const dataUrl = await readFileAsDataUrl(file, t('theme_settings.image_read_failed'));
      if (kind === 'modal') {
        editor.modalLogoDataUrl = dataUrl;
        editor.modalLogoReset = false;
      } else {
        editor.sidebarLogoDataUrl = dataUrl;
        editor.sidebarLogoReset = false;
      }
    } catch (error) {
      state.error = error instanceof Error ? error.message : t('theme_settings.image_read_failed');
    }
  }

  function keepLogo(kind) {
    if (kind === 'modal') {
      editor.modalLogoDataUrl = '';
      editor.modalLogoReset = false;
    } else {
      editor.sidebarLogoDataUrl = '';
      editor.sidebarLogoReset = false;
    }
  }

  function resetLogo(kind) {
    if (kind === 'modal') {
      editor.modalLogoDataUrl = '';
      editor.modalLogoReset = true;
    } else {
      editor.sidebarLogoDataUrl = '';
      editor.sidebarLogoReset = true;
    }
  }

  async function saveTheme() {
    if (state.saving) return;
    state.error = '';
    state.notice = '';
    if (String(editor.label || '').trim() === '') {
      state.error = t('theme_settings.theme_name_required');
      return;
    }
    state.saving = true;
    try {
      const payload = {
        theme: {
          id: editor.id,
          label: editor.label,
          colors: editor.colors,
          create_new: editor.createNew,
          base_theme: editor.baseThemeId || 'dark',
        },
      };
      if (canManageBranding.value) {
        if (editor.sidebarLogoDataUrl) payload.sidebar_logo_data_url = editor.sidebarLogoDataUrl;
        if (editor.modalLogoDataUrl) payload.modal_logo_data_url = editor.modalLogoDataUrl;
        if (editor.sidebarLogoReset) payload.sidebar_logo_reset = true;
        if (editor.modalLogoReset) payload.modal_logo_reset = true;
      }

      const result = await saveAdministration(payload);
      if (result.appearance) {
        applyAppearance(result.appearance);
      } else {
        await loadAppearance({ force: true });
      }
      const savedThemeId = String(result.saved_theme?.id || editor.id || '').trim();
      if (savedThemeId !== '') {
        emit('update:modelValue', savedThemeId);
      }
      editor.open = false;
      state.notice = t('theme_settings.theme_saved');
    } catch (error) {
      state.error = error instanceof Error ? error.message : t('theme_settings.theme_save_failed');
    } finally {
      state.saving = false;
    }
  }

  async function deleteTheme(themeId) {
    if (state.deletingId) return;
    const id = String(themeId || '').trim();
    if (id === '') return;
    state.error = '';
    state.notice = '';
    state.deletingId = id;
    try {
      const result = await deleteThemeRequest(id);
      if (result.appearance) {
        applyAppearance(result.appearance);
      } else {
        await loadAppearance({ force: true });
      }
      if (selectedTheme.value === id) {
        emit('update:modelValue', 'dark');
      }
      state.notice = t('theme_settings.theme_deleted');
    } catch (error) {
      state.error = error instanceof Error ? error.message : t('theme_settings.theme_delete_failed');
    } finally {
      state.deletingId = '';
    }
  }

  onMounted(() => {
    void loadAppearance({ force: true });
  });

  return {
    state,
    editor,
    themePage,
    themeColorFields,
    previewColorFields,
    selectedTheme,
    canEditThemes,
    canManageBranding,
    themeOptions,
    themePageCount,
    pagedThemes,
    sidebarLogoPreview,
    modalLogoPreview,
    startCreateTheme,
    startEditTheme,
    cancelEditor,
    loadBasePalette,
    loadSystemDefault,
    updateThemeColor,
    selectLogo,
    keepLogo,
    resetLogo,
    saveTheme,
    deleteTheme,
  };
}
