<template>
  <aside class="settings-theme-editor-sidebar">
    <nav class="settings-theme-editor-tabs" :aria-label="t('theme_settings.editor_tabs')">
      <button
        class="settings-theme-editor-tab"
        type="button"
        :class="{ active: editor.panel === 'chat' }"
        @click="$emit('set-editor-panel', 'chat')"
      >
        {{ t('theme_settings.chat') }}
      </button>
      <button
        class="settings-theme-editor-tab"
        type="button"
        :class="{ active: editor.panel === 'colors' }"
        @click="$emit('set-editor-panel', 'colors')"
      >
        {{ t('theme_settings.colors') }}
      </button>
      <button
        class="settings-theme-editor-tab"
        type="button"
        :class="{ active: editor.panel === 'images' }"
        @click="$emit('set-editor-panel', 'images')"
      >
        {{ t('theme_settings.images') }}
      </button>
    </nav>

    <section v-if="editor.panel === 'chat'" class="settings-theme-editor-panel">
      <label class="settings-field">
        <span>{{ t('theme_settings.chat_prompt') }}</span>
        <textarea
          v-model="themePromptModel"
          class="input settings-theme-chat-input"
          rows="8"
          :placeholder="t('theme_settings.chat_placeholder')"
        ></textarea>
      </label>
      <button class="btn btn-cyan full" type="button" @click="$emit('apply-theme-prompt')">
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
              @change="$emit('select-logo', $event, 'sidebar')"
            />
            <div class="settings-logo-actions">
              <button class="btn" type="button" @click="$emit('keep-logo', 'sidebar')">{{ t('theme_settings.keep') }}</button>
              <button class="btn" type="button" @click="$emit('reset-logo', 'sidebar')">{{ t('theme_settings.default') }}</button>
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
              @change="$emit('select-logo', $event, 'modal')"
            />
            <div class="settings-logo-actions">
              <button class="btn" type="button" @click="$emit('keep-logo', 'modal')">{{ t('theme_settings.keep') }}</button>
              <button class="btn" type="button" @click="$emit('reset-logo', 'modal')">{{ t('theme_settings.default') }}</button>
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
        <input
          class="input"
          type="text"
          :value="editor.label"
          @input="$emit('update-editor-label', $event.target.value)"
        />
      </label>
      <label class="settings-field">
        <span>{{ t('theme_settings.base_palette') }}</span>
        <AppSelect
          :model-value="editor.baseThemeId"
          @update:model-value="$emit('load-base-palette', $event)"
        >
          <option v-for="theme in themeOptions" :key="theme.id" :value="theme.id">
            {{ theme.label }}
          </option>
        </AppSelect>
      </label>

      <section class="settings-theme-admin-actions">
        <button class="btn" type="button" @click="$emit('load-system-default', 'dark')">{{ t('theme_settings.load_dark_default') }}</button>
        <button class="btn" type="button" @click="$emit('load-system-default', 'light')">{{ t('theme_settings.load_light_default') }}</button>
      </section>

      <section class="settings-theme-color-list">
        <article v-for="field in themeColorFields" :key="field.key" class="settings-theme-color-row">
          <span>{{ field.label }}</span>
          <input
            class="settings-theme-swatch"
            type="color"
            :value="editor.colors[field.key] || field.default"
            @input="$emit('update-theme-color', field.key, $event?.target?.value)"
          />
          <input
            class="input settings-theme-hex"
            type="text"
            maxlength="7"
            :value="editor.colors[field.key] || field.default"
            @input="$emit('update-theme-color', field.key, $event?.target?.value)"
          />
        </article>
      </section>
    </section>

    <footer class="settings-theme-editor-actions">
      <button class="btn" type="button" :disabled="saving" @click="$emit('close-editor')">{{ t('theme_settings.close_editor') }}</button>
      <button class="btn btn-cyan" type="button" :disabled="saving" @click="$emit('save-theme')">
        {{ saving ? t('settings.saving') : t('theme_settings.save_theme') }}
      </button>
    </footer>
  </aside>
</template>

<script setup>
import { computed } from 'vue';
import AppSelect from '../../components/AppSelect.vue';
import { t } from '../../modules/localization/i18nRuntime.js';

const props = defineProps({
  editor: {
    type: Object,
    required: true,
  },
  themePrompt: {
    type: String,
    default: '',
  },
  themeColorFields: {
    type: Array,
    default: () => [],
  },
  themeOptions: {
    type: Array,
    default: () => [],
  },
  canManageBranding: {
    type: Boolean,
    default: false,
  },
  sidebarLogoPreview: {
    type: String,
    default: '',
  },
  modalLogoPreview: {
    type: String,
    default: '',
  },
  saving: {
    type: Boolean,
    default: false,
  },
});

const emit = defineEmits([
  'update:themePrompt',
  'set-editor-panel',
  'apply-theme-prompt',
  'select-logo',
  'keep-logo',
  'reset-logo',
  'update-editor-label',
  'load-base-palette',
  'load-system-default',
  'update-theme-color',
  'close-editor',
  'save-theme',
]);

const themePromptModel = computed({
  get: () => props.themePrompt,
  set: (value) => emit('update:themePrompt', value),
});
</script>

<style scoped>
.settings-theme-editor-sidebar,
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

.settings-theme-admin-actions,
.settings-logo-actions,
.settings-theme-editor-actions {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
}

.settings-theme-admin-actions {
  flex-wrap: wrap;
  justify-content: flex-start;
  gap: 6px;
}

.settings-logo-grid {
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

@media (max-width: 760px) {
  .settings-logo-grid,
  .settings-theme-color-row {
    grid-template-columns: 1fr;
  }
}
</style>
