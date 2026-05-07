<template>
  <section class="app-config-form-box">
    <section class="app-config-form-grid">
      <header class="settings-subhead">
        <h5>{{ t('administration.mail_server') }}</h5>
      </header>
      <section class="settings-row">
        <label class="settings-field">
          <span>{{ t('administration.sender_email') }}</span>
          <input v-model.trim="draft.mail_from_email" class="input" type="email" autocomplete="email" />
        </label>
        <label class="settings-field">
          <span>{{ t('administration.sender_name') }}</span>
          <input v-model.trim="draft.mail_from_name" class="input" type="text" autocomplete="organization" />
        </label>
      </section>
      <section class="settings-row">
        <label class="settings-field">
          <span>{{ t('administration.smtp_host') }}</span>
          <input v-model.trim="draft.mail_smtp_host" class="input" type="text" autocomplete="off" />
        </label>
        <label class="settings-field">
          <span>{{ t('administration.smtp_port') }}</span>
          <input v-model.number="draft.mail_smtp_port" class="input" type="number" min="1" max="65535" />
        </label>
      </section>
      <section class="settings-row">
        <label class="settings-field">
          <span>{{ t('administration.encryption') }}</span>
          <AppSelect v-model="draft.mail_smtp_encryption">
            <option value="starttls">{{ t('administration.encryption_starttls') }}</option>
            <option value="ssl">{{ t('administration.encryption_ssl_tls') }}</option>
            <option value="none">{{ t('administration.encryption_none') }}</option>
          </AppSelect>
        </label>
        <label class="settings-field">
          <span>{{ t('administration.smtp_username') }}</span>
          <input v-model.trim="draft.mail_smtp_username" class="input" type="text" autocomplete="username" />
        </label>
      </section>
      <section class="settings-row">
        <label class="settings-field">
          <span>{{ t('administration.smtp_password') }}</span>
          <input
            v-model="draft.mail_smtp_password"
            class="input"
            type="password"
            autocomplete="new-password"
            :placeholder="draft.mail_smtp_password_set ? t('administration.password_keep_placeholder') : ''"
          />
        </label>
        <label class="settings-field settings-password-clear">
          <span>{{ t('administration.password_state') }}</span>
          <label class="settings-checkbox-row">
            <input v-model="draft.mail_smtp_password_clear" type="checkbox" />
            <span>{{ t('administration.clear_saved_password') }}</span>
          </label>
        </label>
      </section>
    </section>
  </section>
</template>

<script setup>
import AppSelect from '../../../components/AppSelect.vue';
import { t } from '../../localization/i18nRuntime.js';

defineProps({
  draft: {
    type: Object,
    required: true,
  },
});
</script>

<style scoped>
.app-config-form-box {
  flex: 1 1 auto;
  min-height: 0;
  border: 1px solid var(--color-border);
  background: var(--color-surface-navy);
  padding: 20px;
  overflow: auto;
}

.app-config-form-grid {
  display: grid;
  gap: 20px;
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

.settings-checkbox-row {
  min-height: 38px;
  display: flex;
  align-items: center;
  gap: 8px;
  border: 1px solid var(--border-subtle);
  border-radius: 0;
  padding: 0 10px;
}

@media (max-width: 760px) {
  .settings-row {
    grid-template-columns: 1fr;
  }
}
</style>
