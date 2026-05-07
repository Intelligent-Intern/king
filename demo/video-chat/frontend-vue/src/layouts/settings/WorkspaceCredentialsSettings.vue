<template>
  <section class="settings-panel settings-credentials-panel">
    <WorkspaceEmailAddressSettings
      :confirmed-emails="confirmedEmails"
      :unconfirmed-emails="unconfirmedEmails"
      :draft="draft"
      :state="state"
      @add-email="addEmail"
      @delete-email="deleteEmail"
    />

    <WorkspacePasswordSettingsForm
      :draft="draft"
      :state="state"
      @change-password="changePassword"
    />

    <div class="settings-upload-status">{{ state.message }}</div>
  </section>
</template>

<script setup>
import { t } from '../../modules/localization/i18nRuntime.js';
import WorkspaceEmailAddressSettings from './WorkspaceEmailAddressSettings.vue';
import WorkspacePasswordSettingsForm from './WorkspacePasswordSettingsForm.vue';
import { useWorkspaceCredentialsSettings } from './useWorkspaceCredentialsSettings.js';

const {
  draft,
  state,
  confirmedEmails,
  unconfirmedEmails,
  addEmail,
  deleteEmail,
  changePassword,
} = useWorkspaceCredentialsSettings({ t });
</script>

<style scoped>
.settings-credentials-panel {
  gap: 18px;
}
</style>
