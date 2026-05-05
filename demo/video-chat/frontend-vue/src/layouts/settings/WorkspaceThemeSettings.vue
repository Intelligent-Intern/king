<template>
  <section class="settings-theme-settings">
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
      <header v-if="!editor.open" class="settings-subhead">
        <h5>{{ t('theme_settings.management_title') }}</h5>
        <button class="btn" type="button" :disabled="state.saving" @click="startCreateTheme">
          {{ t('theme_settings.new_theme') }}
        </button>
      </header>

      <section v-if="state.error" class="settings-upload-status error">{{ state.error }}</section>
      <section v-if="state.notice" class="settings-upload-status">{{ state.notice }}</section>

      <template v-if="editor.open">
        <section class="settings-theme-editor">
          <header class="settings-theme-wizard-head">
            <div class="settings-wizard-steps" :aria-label="t('theme_settings.wizard_steps')">
              <button
                class="settings-wizard-step"
                type="button"
                :class="{ active: editor.step === 'logos', done: editor.step === 'colors' }"
                @click="editor.step = 'logos'"
              >
                <span>1</span>
                <strong>{{ t('theme_settings.logos') }}</strong>
              </button>
              <button
                class="settings-wizard-step"
                type="button"
                :class="{ active: editor.step === 'colors' }"
                @click="editor.step = 'colors'"
              >
                <span>2</span>
                <strong>{{ t('theme_settings.theme') }}</strong>
              </button>
            </div>
          </header>

          <section v-if="editor.step === 'logos'" class="settings-theme-editor-step">
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
            <footer class="settings-theme-editor-actions">
              <button class="btn" type="button" :disabled="state.saving" @click="cancelEditor">
                {{ t('common.cancel') }}
              </button>
              <button class="btn" type="button" :disabled="state.saving" @click="editor.step = 'colors'">
                {{ t('theme_settings.next') }}
              </button>
            </footer>
          </section>

          <section v-else class="settings-theme-editor-step">
            <section class="settings-theme-workbench">
              <section class="settings-theme-palette-panel">
                <section class="settings-row">
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
                </section>

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

              <WorkspaceThemePreview
                class="settings-theme-live-preview"
                :colors="editor.colors"
                :sidebar-logo-src="sidebarLogoPreview"
              />
            </section>

            <footer class="settings-theme-editor-actions">
              <button class="btn" type="button" :disabled="state.saving" @click="editor.step = 'logos'">
                {{ t('theme_settings.back') }}
              </button>
              <button class="btn" type="button" :disabled="state.saving" @click="saveTheme">
                {{ state.saving ? t('settings.saving') : t('theme_settings.save_theme') }}
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
              {{ theme.isSystem ? t('theme_settings.system') : t('theme_settings.custom') }}
            </span>
            <div class="actions-inline">
              <button
                class="icon-mini-btn"
                type="button"
                :title="t('theme_settings.edit_theme')"
                :aria-label="t('theme_settings.edit_theme')"
                @click="startEditTheme(theme)"
              >
                <img src="/assets/orgas/kingrt/icons/gear.png" alt="" />
              </button>
              <button
                v-if="!theme.isSystem"
                class="icon-mini-btn danger"
                type="button"
                :title="t('theme_settings.delete_theme')"
                :aria-label="t('theme_settings.delete_theme')"
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
} = useWorkspaceThemeSettings({ props, emit, t });
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
