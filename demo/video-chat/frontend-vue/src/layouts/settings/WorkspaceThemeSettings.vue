<template>
  <section class="settings-theme-settings">
    <section v-if="!props.managementOnly && !editor.open" class="settings-section">
      <h4>Theme</h4>
      <p>Choose one of the saved workspace themes.</p>
      <label class="settings-field">
        <span>Workspace theme</span>
        <AppSelect v-model="selectedTheme">
          <option v-for="theme in themeOptions" :key="theme.id" :value="theme.id">
            {{ theme.label }}
          </option>
        </AppSelect>
      </label>
    </section>

    <section v-if="!props.selectionOnly && canEditThemes" class="settings-section settings-theme-crud">
      <header v-if="!editor.open" class="settings-subhead">
        <h5>Theme Management</h5>
        <button class="btn" type="button" :disabled="state.saving" @click="startCreateTheme">
          New theme
        </button>
      </header>

      <section v-if="state.error" class="settings-upload-status error">{{ state.error }}</section>
      <section v-if="state.notice" class="settings-upload-status">{{ state.notice }}</section>

      <template v-if="editor.open">
        <section class="settings-theme-editor">
          <header class="settings-theme-wizard-head">
            <div class="settings-wizard-steps" aria-label="Theme editor steps">
              <button
                class="settings-wizard-step"
                type="button"
                :class="{ active: editor.step === 'logos', done: editor.step === 'colors' }"
                @click="editor.step = 'logos'"
              >
                <span>1</span>
                <strong>Logos</strong>
              </button>
              <button
                class="settings-wizard-step"
                type="button"
                :class="{ active: editor.step === 'colors' }"
                @click="editor.step = 'colors'"
              >
                <span>2</span>
                <strong>Theme</strong>
              </button>
            </div>
          </header>

          <section v-if="editor.step === 'logos'" class="settings-theme-editor-step">
            <template v-if="canManageBranding">
              <section class="settings-logo-grid">
                <article class="settings-logo-card">
                  <span>Left sidebar logo</span>
                  <img class="settings-logo-preview" :src="sidebarLogoPreview" alt="" />
                  <input class="input" type="file" accept="image/png,image/jpeg,image/webp" @change="selectLogo($event, 'sidebar')" />
                  <div class="settings-logo-actions">
                    <button class="btn" type="button" @click="keepLogo('sidebar')">Keep</button>
                    <button class="btn" type="button" @click="resetLogo('sidebar')">Default</button>
                  </div>
                </article>

                <article class="settings-logo-card">
                  <span>Modal logo</span>
                  <img class="settings-logo-preview" :src="modalLogoPreview" alt="" />
                  <input class="input" type="file" accept="image/png,image/jpeg,image/webp" @change="selectLogo($event, 'modal')" />
                  <div class="settings-logo-actions">
                    <button class="btn" type="button" @click="keepLogo('modal')">Keep</button>
                    <button class="btn" type="button" @click="resetLogo('modal')">Default</button>
                  </div>
                </article>
              </section>
            </template>
            <section v-else class="settings-upload-status">
              Branding logos are managed by the primary admin account.
            </section>
            <footer class="settings-theme-editor-actions">
              <button class="btn" type="button" :disabled="state.saving" @click="cancelEditor">
                Cancel
              </button>
              <button class="btn" type="button" :disabled="state.saving" @click="editor.step = 'colors'">
                Next
              </button>
            </footer>
          </section>

          <section v-else class="settings-theme-editor-step">
            <section class="settings-theme-workbench">
              <section class="settings-theme-palette-panel">
                <section class="settings-row">
                  <label class="settings-field">
                    <span>Theme name</span>
                    <input v-model.trim="editor.label" class="input" type="text" />
                  </label>
                  <label class="settings-field">
                    <span>Base palette</span>
                    <AppSelect v-model="editor.baseThemeId" @update:model-value="loadBasePalette">
                      <option v-for="theme in themeOptions" :key="theme.id" :value="theme.id">
                        {{ theme.label }}
                      </option>
                    </AppSelect>
                  </label>
                </section>

                <section class="settings-theme-admin-actions">
                  <button class="btn" type="button" @click="loadSystemDefault('dark')">Load dark default</button>
                  <button class="btn" type="button" @click="loadSystemDefault('light')">Load light default</button>
                </section>

                <section class="settings-theme-color-list">
                  <article v-for="field in themeColorFields" :key="field.key" class="settings-theme-color-row">
                    <span>{{ field.label }}</span>
                    <input
                      class="settings-theme-swatch"
                      type="color"
                      :value="editor.colors[field.key] || field.default"
                      @input="updateThemeColor(field.key, $event?.target?.value)"
                    />
                    <input
                      class="input settings-theme-hex"
                      type="text"
                      maxlength="7"
                      :value="editor.colors[field.key] || field.default"
                      @input="updateThemeColor(field.key, $event?.target?.value)"
                    />
                  </article>
                </section>
              </section>

              <WorkspaceThemePreview
                class="settings-theme-live-preview"
                :colors="editor.colors"
                :sidebar-logo-src="sidebarLogoPreview"
              />
            </section>

            <footer class="settings-theme-editor-actions">
              <button class="btn" type="button" :disabled="state.saving" @click="editor.step = 'logos'">
                Back
              </button>
              <button class="btn" type="button" :disabled="state.saving" @click="saveTheme">
                {{ state.saving ? 'Saving...' : 'Save theme' }}
              </button>
            </footer>
          </section>
        </section>
      </template>

      <template v-else>
        <section class="settings-theme-list">
          <article v-for="theme in pagedThemes" :key="theme.id" class="settings-theme-row">
            <div class="settings-theme-row-main">
              <strong>{{ theme.label }}</strong>
              <span class="code">{{ theme.id }}</span>
            </div>
            <div class="settings-theme-row-swatches" aria-hidden="true">
              <span
                v-for="field in previewColorFields"
                :key="field.key"
                :style="{ backgroundColor: theme.colors?.[field.key] || field.default }"
              ></span>
            </div>
            <span class="tag" :class="theme.isSystem ? 'ok' : 'warn'">
              {{ theme.isSystem ? 'system' : 'custom' }}
            </span>
            <div class="actions-inline">
              <button class="icon-mini-btn" type="button" title="Edit theme" aria-label="Edit theme" @click="startEditTheme(theme)">
                <img src="/assets/orgas/kingrt/icons/gear.png" alt="" />
              </button>
              <button
                v-if="!theme.isSystem"
                class="icon-mini-btn danger"
                type="button"
                title="Delete theme"
                aria-label="Delete theme"
                :disabled="state.deletingId === theme.id"
                @click="deleteTheme(theme.id)"
              >
                <img src="/assets/orgas/kingrt/icons/remove_user.png" alt="" />
              </button>
            </div>
          </article>
        </section>

        <footer class="footer settings-theme-pagination">
          <div class="pagination">
            <button
              class="pager-btn pager-icon-btn"
              type="button"
              :disabled="themePage <= 1"
              @click="themePage -= 1"
            >
              <img class="pager-icon-img" src="/assets/orgas/kingrt/icons/backward.png" alt="Previous" />
            </button>
            <div class="page-info">Page {{ themePage }} / {{ themePageCount }}</div>
            <button
              class="pager-btn pager-icon-btn"
              type="button"
              :disabled="themePage >= themePageCount"
              @click="themePage += 1"
            >
              <img class="pager-icon-img" src="/assets/orgas/kingrt/icons/forward.png" alt="Next" />
            </button>
          </div>
        </footer>
      </template>
    </section>
  </section>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import AppSelect from '../../components/AppSelect.vue';
import { sessionState } from '../../domain/auth/session';
import { deleteWorkspaceTheme, saveWorkspaceAdministration } from '../../domain/workspace/administrationApi';
import { appearanceState, applyAppearancePayload, loadWorkspaceAppearance } from '../../domain/workspace/appearance';
import WorkspaceThemePreview from './WorkspaceThemePreview.vue';

const DEFAULT_LOGO = '/assets/orgas/kingrt/logo.svg';
const PAGE_SIZE = 5;
const themeColorFields = Object.freeze([
  { key: '--bg-shell', label: 'Shell background', default: '#09111e' },
  { key: '--bg-pane', label: 'Pane background', default: '#182c4d' },
  { key: '--brand-bg', label: 'Brand strip', default: '#09111e' },
  { key: '--bg-surface', label: 'Surface', default: '#003c93' },
  { key: '--bg-surface-strong', label: 'Surface strong', default: '#0c1c33' },
  { key: '--bg-input', label: 'Input background', default: '#d8dadd' },
  { key: '--bg-action', label: 'Action', default: '#0b1324' },
  { key: '--bg-action-hover', label: 'Action hover', default: '#5696ef' },
  { key: '--bg-row', label: 'Row', default: '#2a569f' },
  { key: '--bg-row-hover', label: 'Row hover', default: '#163260' },
  { key: '--line', label: 'Line', default: '#09111e' },
  { key: '--text-main', label: 'Text main', default: '#edf3ff' },
  { key: '--text-muted', label: 'Text muted', default: '#8490a1' },
  { key: '--ok', label: 'OK', default: '#177f22' },
  { key: '--wait', label: 'Wait', default: '#8d9500' },
  { key: '--danger', label: 'Danger', default: '#ff0000' },
  { key: '--bg-sidebar', label: 'Sidebar', default: '#09111e' },
  { key: '--bg-main', label: 'Main', default: '#182c4d' },
  { key: '--bg-tab', label: 'Tab', default: '#003c93' },
  { key: '--bg-tab-hover', label: 'Tab hover', default: '#5696ef' },
  { key: '--bg-tab-active', label: 'Tab active', default: '#2a569f' },
  { key: '--bg-ui-chrome', label: 'UI chrome', default: '#3d5f98' },
  { key: '--bg-ui-chrome-active', label: 'UI chrome active', default: '#2a569f' },
  { key: '--bg-icon', label: 'Icon background', default: '#162e51' },
  { key: '--bg-icon-active', label: 'Icon active', default: '#5696ef' },
  { key: '--border-subtle', label: 'Border subtle', default: '#09111e' },
  { key: '--text-primary', label: 'Text primary', default: '#edf3ff' },
  { key: '--text-secondary', label: 'Text secondary', default: '#c6d4eb' },
  { key: '--text-dim', label: 'Text dim', default: '#5e6d86' },
  { key: '--warn', label: 'Warn', default: '#4d5011' },
  { key: '--brand-cyan', label: 'Brand cyan', default: '#1482be' },
  { key: '--brand-cyan-hover', label: 'Brand cyan hover', default: '#1a96d8' },
  { key: '--brand-cyan-active', label: 'Brand cyan active', default: '#0f6ea8' },
]);
const previewColorFields = themeColorFields.slice(0, 6);

const props = defineProps({
  modelValue: {
    type: String,
    default: 'dark',
  },
  saving: {
    type: Boolean,
    default: false,
  },
  selectionOnly: {
    type: Boolean,
    default: false,
  },
  managementOnly: {
    type: Boolean,
    default: false,
  },
});
const emit = defineEmits(['update:modelValue']);

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

const selectedTheme = computed({
  get: () => props.modelValue || themeOptions.value[0]?.id || 'dark',
  set: (value) => emit('update:modelValue', normalizeThemeId(value)),
});
const canEditThemes = computed(() => sessionState.role === 'admin' || sessionState.canEditThemes === true);
const canManageBranding = computed(() => Number(sessionState.userId || 0) === 1);
const fallbackThemes = computed(() => ([
  { id: 'dark', label: 'Dark', colors: defaultColors(), isSystem: true },
  { id: 'light', label: 'Light', colors: defaultColors('light'), isSystem: true },
]));
const themeOptions = computed(() => (
  appearanceState.themes.length > 0 ? appearanceState.themes : fallbackThemes.value
));
const themePageCount = computed(() => Math.max(1, Math.ceil(themeOptions.value.length / PAGE_SIZE)));
const pagedThemes = computed(() => {
  const currentPage = Math.max(1, Math.min(themePage.value, themePageCount.value));
  const start = (currentPage - 1) * PAGE_SIZE;
  return themeOptions.value.slice(start, start + PAGE_SIZE);
});
const sidebarLogoPreview = computed(() => (
  editor.sidebarLogoReset ? DEFAULT_LOGO : (editor.sidebarLogoDataUrl || appearanceState.sidebarLogoPath || DEFAULT_LOGO)
));
const modalLogoPreview = computed(() => (
  editor.modalLogoReset ? DEFAULT_LOGO : (editor.modalLogoDataUrl || appearanceState.modalLogoPath || DEFAULT_LOGO)
));

watch(themePageCount, () => {
  themePage.value = Math.max(1, Math.min(themePage.value, themePageCount.value));
});

function defaultColors(themeId = 'dark') {
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
  for (const field of themeColorFields) {
    colors[field.key] = themeId === 'light' && lightOverrides[field.key] ? lightOverrides[field.key] : field.default;
  }
  return colors;
}

function normalizeThemeId(value) {
  const candidate = String(value || '').trim();
  return themeOptions.value.some((theme) => theme.id === candidate) ? candidate : themeOptions.value[0]?.id || 'dark';
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

function patchColors(source = {}) {
  for (const field of themeColorFields) {
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
  editor.label = 'Custom theme';
  editor.baseThemeId = selectedTheme.value || 'dark';
  editor.sidebarLogoDataUrl = '';
  editor.sidebarLogoReset = false;
  editor.modalLogoDataUrl = '';
  editor.modalLogoReset = false;
  const baseTheme = themeOptions.value.find((theme) => theme.id === editor.baseThemeId) || themeOptions.value[0];
  patchColors(baseTheme?.colors || defaultColors());
}

function startEditTheme(theme) {
  state.error = '';
  state.notice = '';
  editor.open = true;
  editor.step = 'logos';
  editor.createNew = false;
  editor.id = String(theme?.id || '');
  editor.label = String(theme?.label || editor.id || 'Theme');
  editor.baseThemeId = editor.id || 'dark';
  editor.sidebarLogoDataUrl = '';
  editor.sidebarLogoReset = false;
  editor.modalLogoDataUrl = '';
  editor.modalLogoReset = false;
  patchColors(theme?.colors || defaultColors(editor.baseThemeId));
}

function cancelEditor() {
  if (state.saving) return;
  editor.open = false;
  editor.step = 'logos';
  state.error = '';
}

function loadBasePalette(themeId) {
  const theme = themeOptions.value.find((entry) => entry.id === themeId);
  patchColors(theme?.colors || defaultColors(themeId));
}

function loadSystemDefault(themeId) {
  patchColors(defaultColors(themeId));
}

function updateThemeColor(key, value) {
  const field = themeColorFields.find((entry) => entry.key === key);
  if (!field) return;
  editor.colors[key] = normalizeHex(value, editor.colors[key] || field.default);
}

function readFileAsDataUrl(file) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(typeof reader.result === 'string' ? reader.result : '');
    reader.onerror = () => reject(new Error('Could not read image file.'));
    reader.readAsDataURL(file);
  });
}

async function selectLogo(event, kind) {
  const file = event?.target?.files?.[0] || null;
  if (!file) return;
  if (!['image/png', 'image/jpeg', 'image/webp'].includes(file.type)) {
    state.error = 'Logo must be PNG, JPEG, or WEBP.';
    return;
  }
  const dataUrl = await readFileAsDataUrl(file);
  if (kind === 'modal') {
    editor.modalLogoDataUrl = dataUrl;
    editor.modalLogoReset = false;
  } else {
    editor.sidebarLogoDataUrl = dataUrl;
    editor.sidebarLogoReset = false;
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
    state.error = 'Theme name is required.';
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

    const result = await saveWorkspaceAdministration(payload);
    if (result.appearance) {
      applyAppearancePayload(result.appearance);
    } else {
      await loadWorkspaceAppearance({ force: true });
    }
    const savedThemeId = String(result.saved_theme?.id || editor.id || '').trim();
    if (savedThemeId !== '') {
      emit('update:modelValue', savedThemeId);
    }
    editor.open = false;
    state.notice = 'Theme saved.';
  } catch (error) {
    state.error = error instanceof Error ? error.message : 'Could not save theme.';
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
    const result = await deleteWorkspaceTheme(id);
    if (result.appearance) {
      applyAppearancePayload(result.appearance);
    } else {
      await loadWorkspaceAppearance({ force: true });
    }
    if (selectedTheme.value === id) {
      emit('update:modelValue', 'dark');
    }
    state.notice = 'Theme deleted.';
  } catch (error) {
    state.error = error instanceof Error ? error.message : 'Could not delete theme.';
  } finally {
    state.deletingId = '';
  }
}

onMounted(() => {
  void loadWorkspaceAppearance({ force: true });
});
</script>

<style scoped>
.settings-theme-settings,
.settings-theme-crud,
.settings-theme-editor,
.settings-theme-editor-step {
  min-height: 0;
}

.settings-subhead,
.settings-theme-editor-actions,
.settings-logo-actions {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
}

.settings-subhead h5 {
  margin: 0;
  font-size: 13px;
  color: var(--text-main);
}

.settings-theme-crud {
  display: flex;
  flex-direction: column;
}

.settings-theme-editor,
.settings-theme-editor-step {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.settings-theme-list {
  display: grid;
  gap: 8px;
}

.settings-theme-row {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(96px, auto) auto auto;
  align-items: center;
  gap: 10px;
  border: 1px solid var(--border-subtle);
  border-radius: 6px;
  background: var(--bg-row);
  padding: 8px;
}

.settings-theme-row-main {
  min-width: 0;
  display: grid;
  gap: 2px;
}

.settings-theme-row-main strong,
.settings-theme-row-main .code {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.settings-theme-row-swatches {
  display: flex;
  gap: 4px;
}

.settings-theme-row-swatches span {
  width: 18px;
  height: 18px;
  border: 1px solid var(--border-subtle);
  border-radius: 4px;
}

.settings-theme-pagination {
  margin-top: auto;
  padding-top: 10px;
}

.settings-theme-wizard-head {
  display: flex;
  justify-content: center;
  padding: 2px 0 10px;
}

.settings-wizard-steps {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 16px;
}

.settings-wizard-step {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  border: 0;
  background: transparent;
  color: var(--text-muted);
  cursor: pointer;
  padding: 0;
}

.settings-wizard-step span {
  width: 34px;
  height: 34px;
  display: grid;
  place-items: center;
  border: 1px solid var(--border-subtle);
  border-radius: 50%;
  background: var(--bg-row);
  color: var(--text-main);
  font-weight: 800;
}

.settings-wizard-step strong {
  color: inherit;
  font-size: 12px;
  font-weight: 700;
}

.settings-wizard-step.active,
.settings-wizard-step.done {
  color: var(--text-primary);
}

.settings-wizard-step.active span,
.settings-wizard-step.done span {
  background: var(--bg-action-hover);
  color: var(--text-primary);
}

.settings-logo-grid,
.settings-row {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
}

.settings-logo-card {
  display: grid;
  gap: 8px;
  border: 1px solid var(--border-subtle);
  border-radius: 6px;
  padding: 10px;
}

.settings-logo-preview {
  width: 100%;
  height: 64px;
  object-fit: contain;
  border: 1px solid var(--border-subtle);
  border-radius: 6px;
  background: var(--brand-bg);
  padding: 8px;
}

.settings-theme-admin-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

.settings-theme-workbench {
  min-height: 0;
  display: grid;
  grid-template-columns: minmax(260px, 340px) minmax(0, 1fr);
  gap: 12px;
}

.settings-theme-palette-panel {
  min-width: 0;
  min-height: 0;
  display: grid;
  align-content: start;
  gap: 10px;
}

.settings-theme-live-preview {
  min-width: 0;
}

.settings-theme-color-list {
  display: grid;
  gap: 8px;
}

.settings-theme-color-row {
  display: grid;
  grid-template-columns: minmax(120px, 1fr) auto 92px;
  align-items: center;
  gap: 8px;
}

.settings-theme-swatch {
  width: 38px;
  height: 34px;
  border: 1px solid var(--border-subtle);
  border-radius: 6px;
  background: transparent;
}

.settings-upload-status.error {
  color: var(--color-ffb5b5);
}

@media (max-width: 760px) {
  .settings-theme-row,
  .settings-logo-grid,
  .settings-row,
  .settings-theme-color-row,
  .settings-theme-workbench {
    grid-template-columns: 1fr;
  }
}
</style>
