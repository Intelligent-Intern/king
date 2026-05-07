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
          <WorkspaceThemeEditorSidebar
            v-model:theme-prompt="themePrompt"
            :editor="editor"
            :theme-color-fields="themeColorFields"
            :theme-options="themeOptions"
            :can-manage-branding="canManageBranding"
            :sidebar-logo-preview="sidebarLogoPreview"
            :modal-logo-preview="modalLogoPreview"
            :saving="state.saving"
            @set-editor-panel="setEditorPanel"
            @apply-theme-prompt="applyThemePrompt"
            @select-logo="selectLogo"
            @keep-logo="keepLogo"
            @reset-logo="resetLogo"
            @update-editor-label="editor.label = String($event || '').trim()"
            @load-base-palette="loadBasePalette"
            @load-system-default="loadSystemDefault"
            @update-theme-color="updateThemeColor"
            @close-editor="cancelEditor"
            @save-theme="saveTheme"
          />

          <section class="settings-theme-editor-preview-pane">
            <WorkspaceThemePreview
              class="settings-theme-live-preview"
              :colors="editor.colors"
              :sidebar-logo-src="sidebarLogoPreview"
              interactive
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
import WorkspaceThemeEditorSidebar from './WorkspaceThemeEditorSidebar.vue';
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

.settings-subhead {
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

.settings-row {
  display: grid;
  gap: 12px;
}

.settings-theme-editor {
  min-height: 0;
  display: grid;
  grid-template-columns: minmax(280px, 360px) minmax(0, 1fr);
  gap: 12px;
  flex: 1 1 auto;
}

.settings-theme-editor-preview-pane {
  min-width: 0;
  min-height: 0;
}

.settings-theme-editor-preview-pane {
  display: grid;
}

.settings-theme-live-preview {
  min-width: 0;
  min-height: 100%;
}

.settings-upload-status.error {
  color: var(--color-heading);
}

@media (max-width: 760px) {
  .settings-theme-card-grid,
  .settings-row,
  .settings-theme-editor {
    grid-template-columns: 1fr;
  }

  .settings-theme-card-grid {
    overflow: visible;
  }
}
</style>
