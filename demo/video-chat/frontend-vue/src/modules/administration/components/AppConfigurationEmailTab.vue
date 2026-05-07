<template>
  <form class="app-config-tab app-config-email" autocomplete="off" @submit.prevent="save">
    <section v-if="state.loading" class="settings-upload-status">{{ t('administration.loading_settings') }}</section>
    <section v-if="state.error" class="settings-upload-status error">{{ state.error }}</section>
    <section v-if="state.message" class="settings-upload-status">{{ state.message }}</section>

    <AppConfigurationEmailSettingsForm v-if="isPrimaryAdmin" :draft="draft" />

    <section v-else class="settings-upload-status">
      {{ t('administration.primary_admin_only_email') }}
    </section>

    <footer v-if="isPrimaryAdmin" class="app-config-actions">
      <button class="btn btn-cyan" type="submit" :disabled="state.saving">
        {{ state.saving ? t('settings.saving') : t('administration.save_configuration') }}
      </button>
    </footer>
  </form>
</template>

<script setup>
import { t } from '../../localization/i18nRuntime.js';
import AppConfigurationEmailSettingsForm from './AppConfigurationEmailSettingsForm.vue';
import { useAppConfigurationEmailSettings } from './useAppConfigurationEmailSettings.js';

const {
  state,
  draft,
  isPrimaryAdmin,
  save,
} = useAppConfigurationEmailSettings({ t });
</script>

<style scoped>
.app-config-tab {
  height: 100%;
  min-height: 0;
  overflow: auto;
  display: flex;
  flex-direction: column;
}

.settings-upload-status.error {
  color: var(--color-heading);
}

.app-config-actions {
  margin-top: auto;
  display: flex;
  justify-content: flex-end;
  padding: 20px 0 0;
}
</style>
