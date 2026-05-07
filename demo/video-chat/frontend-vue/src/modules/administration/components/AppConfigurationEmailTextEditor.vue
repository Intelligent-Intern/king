<template>
  <aside v-if="open" class="app-config-editor">
    <header class="app-config-editor-head">
      <strong>{{ t('administration.email_text_edit') }}</strong>
      <AppIconButton
        icon="/assets/orgas/kingrt/icons/cancel.png"
        :title="t('common.close_panel')"
        :aria-label="t('common.close_panel')"
        @click="$emit('close')"
      />
    </header>
    <form class="app-config-editor-form" @submit.prevent="$emit('save')">
      <label class="settings-field">
        <span>{{ t('administration.email_text_label') }}</span>
        <input v-model.trim="form.label" class="input" type="text" />
      </label>
      <label class="settings-field">
        <span>{{ t('administration.email_text_key') }}</span>
        <input v-model.trim="form.template_key" class="input" type="text" :disabled="form.is_system" />
      </label>
      <label class="settings-field">
        <span>{{ t('administration.subject_template') }}</span>
        <input v-model.trim="form.subject_template" class="input" type="text" />
      </label>
      <label class="settings-field app-config-editor-body">
        <span>{{ t('administration.body_template') }}</span>
        <textarea v-model="form.body_template" class="settings-textarea" rows="12"></textarea>
      </label>
      <label class="settings-field">
        <span>{{ t('administration.email_text_status') }}</span>
        <AppSelect v-model="form.status">
          <option value="active">{{ t('administration.email_text_status_active') }}</option>
          <option value="disabled">{{ t('administration.email_text_status_disabled') }}</option>
        </AppSelect>
      </label>
      <section v-if="editor.error" class="settings-upload-status error">{{ editor.error }}</section>
      <footer class="app-config-editor-actions">
        <button class="btn btn-cyan" type="submit" :disabled="editor.saving">
          {{ editor.saving ? t('settings.saving') : t('common.save') }}
        </button>
      </footer>
    </form>
  </aside>
</template>

<script setup>
import AppIconButton from '../../../components/AppIconButton.vue';
import AppSelect from '../../../components/AppSelect.vue';
import { t } from '../../localization/i18nRuntime.js';

defineProps({
  open: {
    type: Boolean,
    default: false,
  },
  editor: {
    type: Object,
    required: true,
  },
  form: {
    type: Object,
    required: true,
  },
});

defineEmits(['close', 'save']);
</script>

<style scoped>
.app-config-editor {
  flex: 0 0 min(420px, 44vw);
  min-width: 320px;
  height: 100%;
  margin-inline-start: 20px;
  border-left: 1px solid var(--color-border);
  border-top: 0;
  border-bottom: 0;
  border-radius: 0;
  background: var(--color-surface-navy);
  display: flex;
  flex-direction: column;
  min-height: 0;
}

.app-config-editor-head {
  min-height: 54px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding: 0 14px;
  border-bottom: 1px solid var(--color-border);
}

.app-config-editor-form {
  min-height: 0;
  overflow: auto;
  display: grid;
  gap: 14px;
  padding: 14px;
}

.settings-textarea {
  width: 100%;
  min-height: 220px;
  border: 1px solid var(--border-subtle);
  border-radius: 0;
  background: var(--bg-input);
  color: var(--text-primary);
  padding: 8px 10px;
  resize: vertical;
}

.app-config-editor-actions {
  display: flex;
  justify-content: flex-end;
}

.settings-upload-status.error {
  color: var(--color-heading);
}

@media (max-width: 900px) {
  .app-config-editor {
    flex: 1 1 auto;
    width: 100%;
    min-width: 0;
    margin: 20px 0 0;
    border-left: 0;
    border-top: 1px solid var(--color-border);
  }
}
</style>
