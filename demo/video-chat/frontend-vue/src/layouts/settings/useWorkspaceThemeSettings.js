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
import {
  STYLEGUIDE_COLOR_FIELDS as THEME_COLOR_FIELDS,
  STYLEGUIDE_PALETTE,
  defaultWorkspaceThemeColors,
  normalizeStyleguideHex as normalizeHex,
  normalizeStyleguideThemeColors,
} from '../../domain/workspace/styleguidePalette.js';

const DEFAULT_LOGO = '/assets/orgas/kingrt/logo.svg';
const DEFAULT_PAGE_SIZE = 5;

function fallbackTranslate(key, params = {}) {
  return String(key || '').replace(/\{([A-Za-z0-9_.-]+)\}/g, (_match, name) => String(params?.[name] ?? ''));
}

function createTranslator(t) {
  return typeof t === 'function' ? t : fallbackTranslate;
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
    panel: 'colors',
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
  const themePrompt = ref('');
  const themePage = ref(1);

  const themeColorFields = computed(() => THEME_COLOR_FIELDS.map((field) => ({
    ...field,
    label: t(field.labelKey),
  })));
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
    const normalizedColors = normalizeStyleguideThemeColors(source, defaultWorkspaceThemeColors(editor.baseThemeId));
    for (const field of THEME_COLOR_FIELDS) {
      editor.colors[field.key] = normalizedColors[field.key];
    }
  }

  function setEditorPanel(panel) {
    if (['chat', 'colors', 'images'].includes(panel)) {
      editor.panel = panel;
    }
  }

  function startCreateTheme() {
    state.error = '';
    state.notice = '';
    editor.open = true;
    editor.panel = 'colors';
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
    editor.panel = 'colors';
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
    editor.panel = 'colors';
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

  function applyThemePrompt() {
    const prompt = String(themePrompt.value || '').trim().toLowerCase();
    if (prompt === '') {
      state.error = t('theme_settings.chat_prompt_required');
      return;
    }

    state.error = '';
    let changed = false;
    if (/(hell|light|bright)/.test(prompt)) {
      loadSystemDefault('light');
      changed = true;
    } else if (/(dunkel|dark|navy)/.test(prompt)) {
      loadSystemDefault('dark');
      changed = true;
    }

    if (/(cyan|blau|blue|king)/.test(prompt)) {
      updateThemeColor('--color-cyan-primary', STYLEGUIDE_PALETTE['--color-cyan-primary']);
      updateThemeColor('--color-cyan-hover', STYLEGUIDE_PALETTE['--color-cyan-hover']);
      updateThemeColor('--color-text-link', STYLEGUIDE_PALETTE['--color-text-link']);
      updateThemeColor('--color-text-link-hover', STYLEGUIDE_PALETTE['--color-text-link-hover']);
      changed = true;
    }

    if (/(kontrast|contrast|klar|lesbar)/.test(prompt)) {
      updateThemeColor('--color-heading', STYLEGUIDE_PALETTE['--color-heading']);
      updateThemeColor('--color-text-primary', STYLEGUIDE_PALETTE['--color-text-primary']);
      updateThemeColor('--color-border', STYLEGUIDE_PALETTE['--color-border']);
      changed = true;
    }

    if (!changed) {
      updateThemeColor('--color-primary-navy', STYLEGUIDE_PALETTE['--color-primary-navy']);
      updateThemeColor('--color-surface-navy', STYLEGUIDE_PALETTE['--color-surface-navy']);
      updateThemeColor('--color-cyan-primary', STYLEGUIDE_PALETTE['--color-cyan-primary']);
      updateThemeColor('--color-cyan-hover', STYLEGUIDE_PALETTE['--color-cyan-hover']);
    }

    state.notice = t('theme_settings.chat_prompt_applied');
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
          colors: normalizeStyleguideThemeColors(editor.colors, defaultWorkspaceThemeColors(editor.baseThemeId)),
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
    themePrompt,
    themePage,
    themeColorFields,
    selectedTheme,
    canEditThemes,
    canManageBranding,
    themeOptions,
    themePageCount,
    pagedThemes,
    sidebarLogoPreview,
    modalLogoPreview,
    setEditorPanel,
    startCreateTheme,
    startEditTheme,
    cancelEditor,
    loadBasePalette,
    loadSystemDefault,
    updateThemeColor,
    applyThemePrompt,
    selectLogo,
    keepLogo,
    resetLogo,
    saveTheme,
    deleteTheme,
  };
}
