<template>
  <section class="settings-theme-settings" :class="{ 'is-management-only': props.managementOnly }">
    <section v-if="!props.managementOnly && !editor.open" class="settings-section">
      <h4>{{ t('theme_settings.title') }}</h4>
      <p>{{ t('theme_settings.description') }}</p>
      <label class="settings-field">
        <span>{{ t('theme_settings.workspace_theme') }}</span>
        <AppSelect v-model="selectedTheme">
          <option v-for="theme in themeOptions" :key="theme.id" :value="theme.id">
            {{ theme.label }}
          </option>
        </AppSelect>
      </label>
    </section>

    <section v-if="!props.selectionOnly && canEditThemes" class="settings-section settings-theme-crud">
      <header v-if="!props.managementOnly && !editor.open" class="settings-subhead">
        <h5>{{ t('theme_settings.management_title') }}</h5>
        <button class="btn btn-cyan" type="button" :disabled="state.saving" @click="startCreateTheme">
          {{ t('theme_settings.new_theme') }}
        </button>
      </header>

      <section v-if="state.error" class="settings-upload-status error">{{ state.error }}</section>
      <section v-if="state.notice" class="settings-upload-status">{{ state.notice }}</section>

      <template v-if="editor.open">
        <section class="settings-theme-editor" :aria-label="t('theme_settings.edit_theme')">
          <aside class="settings-theme-editor-sidebar">
            <nav class="settings-theme-editor-tabs" :aria-label="t('theme_settings.editor_tabs')">
              <button
                class="settings-theme-editor-tab"
                type="button"
                :class="{ active: editor.panel === 'chat' }"
                @click="setEditorPanel('chat')"
              >
                {{ t('theme_settings.chat') }}
              </button>
              <button
                class="settings-theme-editor-tab"
                type="button"
                :class="{ active: editor.panel === 'colors' }"
                @click="setEditorPanel('colors')"
              >
                {{ t('theme_settings.colors') }}
              </button>
              <button
                class="settings-theme-editor-tab"
                type="button"
                :class="{ active: editor.panel === 'images' }"
                @click="setEditorPanel('images')"
              >
                {{ t('theme_settings.images') }}
              </button>
            </nav>

            <section v-if="editor.panel === 'chat'" class="settings-theme-editor-panel">
              <label class="settings-field">
                <span>{{ t('theme_settings.chat_prompt') }}</span>
                <textarea
                  v-model="themePrompt"
                  class="input settings-theme-chat-input"
                  rows="8"
                  :placeholder="t('theme_settings.chat_placeholder')"
                ></textarea>
              </label>
              <button class="btn btn-cyan full" type="button" @click="applyThemePrompt">
                {{ t('theme_settings.apply_prompt') }}
              </button>
            </section>

            <section v-else-if="editor.panel === 'images'" class="settings-theme-editor-panel">
              <template v-if="canManageBranding">
                <section class="settings-logo-grid">
                  <article class="settings-logo-card">
                    <span>{{ t('theme_settings.left_sidebar_logo') }}</span>
                    <img class="settings-logo-preview" :src="sidebarLogoPreview" alt="" />
                    <input
                      class="input"
                      type="file"
                      accept="image/png,image/jpeg,image/webp"
                      :aria-label="t('theme_settings.left_sidebar_logo')"
                      @change="selectLogo($event, 'sidebar')"
                    />
                    <div class="settings-logo-actions">
                      <button class="btn" type="button" @click="keepLogo('sidebar')">{{ t('theme_settings.keep') }}</button>
                      <button class="btn" type="button" @click="resetLogo('sidebar')">{{ t('theme_settings.default') }}</button>
                    </div>
                  </article>

                  <article class="settings-logo-card">
                    <span>{{ t('theme_settings.modal_logo') }}</span>
                    <img class="settings-logo-preview" :src="modalLogoPreview" alt="" />
                    <input
                      class="input"
                      type="file"
                      accept="image/png,image/jpeg,image/webp"
                      :aria-label="t('theme_settings.modal_logo')"
                      @change="selectLogo($event, 'modal')"
                    />
                    <div class="settings-logo-actions">
                      <button class="btn" type="button" @click="keepLogo('modal')">{{ t('theme_settings.keep') }}</button>
                      <button class="btn" type="button" @click="resetLogo('modal')">{{ t('theme_settings.default') }}</button>
                    </div>
                  </article>
                </section>
              </template>
              <section v-else class="settings-upload-status">
                {{ t('theme_settings.branding_superadmin_only') }}
              </section>
            </section>

            <section v-else class="settings-theme-editor-panel">
              <label class="settings-field">
                <span>{{ t('theme_settings.theme_name') }}</span>
                <input v-model.trim="editor.label" class="input" type="text" />
              </label>
              <label class="settings-field">
                <span>{{ t('theme_settings.base_palette') }}</span>
                <AppSelect v-model="editor.baseThemeId" @update:model-value="loadBasePalette">
                  <option v-for="theme in themeOptions" :key="theme.id" :value="theme.id">
                    {{ theme.label }}
                  </option>
                </AppSelect>
              </label>

              <section class="settings-theme-admin-actions">
                <button class="btn" type="button" @click="loadSystemDefault('dark')">{{ t('theme_settings.load_dark_default') }}</button>
                <button class="btn" type="button" @click="loadSystemDefault('light')">{{ t('theme_settings.load_light_default') }}</button>
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

            <footer class="settings-theme-editor-actions">
              <button class="btn" type="button" :disabled="state.saving" @click="cancelEditor">{{ t('theme_settings.close_editor') }}</button>
              <button class="btn btn-cyan" type="button" :disabled="state.saving" @click="saveTheme">
                {{ state.saving ? t('settings.saving') : t('theme_settings.save_theme') }}
              </button>
            </footer>
          </aside>

          <section class="settings-theme-editor-preview-pane">
            <WorkspaceThemePreview
              class="settings-theme-live-preview"
              :colors="editor.colors"
              :sidebar-logo-src="sidebarLogoPreview"
            />
          </section>
        </section>
      </template>

      <template v-else>
        <section class="settings-theme-card-grid" :aria-label="t('theme_settings.management_title')">
          <article v-for="theme in pagedThemes" :key="theme.id" class="settings-theme-card">
            <header class="settings-theme-card-head">
              <div>
                <strong>{{ theme.label }}</strong>
                <span class="code">{{ theme.id }}</span>
              </div>
              <span class="tag" :class="theme.isSystem ? 'ok' : 'warn'">
                {{ theme.isSystem ? t('theme_settings.system') : t('theme_settings.custom') }}
              </span>
            </header>

            <WorkspaceThemePreview
              class="settings-theme-card-preview"
              compact
              :colors="theme.colors"
              :sidebar-logo-src="sidebarLogoPreview"
            />

            <footer class="settings-theme-card-actions">
              <button class="btn btn-cyan" type="button" @click="startEditTheme(theme)">
                {{ t('theme_settings.edit_theme') }}
              </button>
              <button
                v-if="!theme.isSystem"
                class="btn settings-theme-delete"
                type="button"
                :disabled="state.deletingId === theme.id"
                @click="deleteTheme(theme.id)"
              >
                {{ t('theme_settings.delete_theme') }}
              </button>
            </footer>
          </article>
        </section>

        <footer class="footer settings-theme-pagination">
          <div class="pagination">
            <button
              class="pager-btn pager-icon-btn"
              type="button"
              :disabled="themePage <= 1"
              :aria-label="t('pagination.previous')"
              @click="themePage -= 1"
            >
              <img class="pager-icon-img" src="/assets/orgas/kingrt/icons/backward.png" :alt="t('pagination.previous')" />
            </button>
            <div class="page-info">{{ t('pagination.page_short', { page: themePage, pageCount: themePageCount }) }}</div>
            <button
              class="pager-btn pager-icon-btn"
              type="button"
              :disabled="themePage >= themePageCount"
              :aria-label="t('pagination.next')"
              @click="themePage += 1"
            >
              <img class="pager-icon-img" src="/assets/orgas/kingrt/icons/forward.png" :alt="t('pagination.next')" />
            </button>
          </div>
        </footer>
      </template>
    </section>
  </section>
</template>

<script setup>
import AppSelect from '../../components/AppSelect.vue';
import { t } from '../../modules/localization/i18nRuntime.js';
import WorkspaceThemePreview from './WorkspaceThemePreview.vue';
import { useWorkspaceThemeSettings } from './useWorkspaceThemeSettings.js';

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

const {
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
} = useWorkspaceThemeSettings({ props, emit, t });

defineExpose({
  startCreateTheme,
});
</script>

<style scoped>
.settings-theme-settings,
.settings-theme-crud,
.settings-theme-editor {
  min-height: 0;
}

.settings-theme-settings.is-management-only {
  height: 100%;
  display: flex;
  flex-direction: column;
}

.settings-theme-settings.is-management-only .settings-theme-crud {
  flex: 1 1 auto;
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
  min-height: 0;
  display: flex;
  flex-direction: column;
}

.settings-theme-pagination {
  margin-top: auto;
  padding-top: 10px;
}

.settings-theme-card-grid {
  flex: 1 1 auto;
  min-height: 0;
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(330px, 1fr));
  gap: 14px;
  align-content: start;
  overflow: auto;
  padding: 2px 2px 8px;
}

.settings-theme-card {
  min-width: 0;
  display: grid;
  gap: 10px;
  border: 1px solid var(--border-subtle);
  border-radius: 8px;
  background: var(--bg-surface);
  padding: 10px;
}

.settings-theme-card-head,
.settings-theme-card-actions {
  min-width: 0;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
}

.settings-theme-card-head div {
  min-width: 0;
  display: grid;
  gap: 2px;
}

.settings-theme-card-head strong,
.settings-theme-card-head .code {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.settings-theme-card-preview {
  min-width: 0;
}

.settings-theme-delete {
  background: var(--danger);
}

.settings-theme-delete:hover {
  background: var(--color-error);
}

.settings-logo-grid,
.settings-row {
  display: grid;
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

.settings-theme-editor {
  min-height: 0;
  display: grid;
  grid-template-columns: minmax(280px, 360px) minmax(0, 1fr);
  gap: 12px;
  flex: 1 1 auto;
}

.settings-theme-editor-sidebar,
.settings-theme-editor-preview-pane,
.settings-theme-editor-panel {
  min-width: 0;
  min-height: 0;
}

.settings-theme-editor-sidebar {
  display: grid;
  grid-template-rows: auto minmax(0, 1fr) auto;
  border: 1px solid var(--border-subtle);
  border-radius: 8px;
  background: var(--bg-surface);
  overflow: hidden;
}

.settings-theme-editor-tabs {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  border-bottom: 1px solid var(--border-subtle);
  background: var(--bg-tab);
}

.settings-theme-editor-tab {
  min-width: 0;
  height: 42px;
  border: 0;
  border-inline-end: 1px solid var(--border-subtle);
  background: transparent;
  color: var(--text-secondary);
  font-weight: 700;
  cursor: pointer;
}

.settings-theme-editor-tab:last-child {
  border-inline-end: 0;
}

.settings-theme-editor-tab.active,
.settings-theme-editor-tab:hover {
  background: var(--bg-tab-active);
  color: var(--text-primary);
}

.settings-theme-editor-panel {
  display: grid;
  align-content: start;
  gap: 12px;
  overflow: auto;
  padding: 12px;
}

.settings-theme-chat-input {
  min-height: 160px;
  resize: vertical;
  padding: 10px;
  line-height: 1.4;
}

.settings-theme-editor-preview-pane {
  display: grid;
}

.settings-theme-live-preview {
  min-width: 0;
  min-height: 100%;
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

.settings-theme-editor-actions {
  border-top: 1px solid var(--border-subtle);
  padding: 12px;
}

.settings-theme-editor-actions .btn:last-child {
  background: var(--brand-cyan);
}

.settings-theme-editor-actions .btn:last-child:hover {
  background: var(--brand-cyan-hover);
}

.settings-upload-status.error {
  color: var(--color-heading);
}

@media (max-width: 760px) {
  .settings-theme-card-grid,
  .settings-logo-grid,
  .settings-row,
  .settings-theme-color-row,
  .settings-theme-editor {
    grid-template-columns: 1fr;
  }

  .settings-theme-card-grid {
    overflow: visible;
  }
}
</style>
