<template>
  <AdminPageFrame class="administration-view" :title="t('administration.app_configuration')">
    <template #actions>
      <button class="btn btn-cyan" type="button" :disabled="saving" @click="saveConfiguration">
        {{ saving ? t('settings.saving') : t('administration.save_configuration') }}
      </button>
    </template>

    <section class="section administration-panel">
      <WorkspaceAdministrationSettings ref="administrationSettingsRef" title="" />
    </section>

    <section v-if="message" class="section administration-status" :class="{ error: !savedOk }">
      {{ message }}
    </section>
  </AdminPageFrame>
</template>

<script setup>
import { ref } from 'vue';
import AdminPageFrame from '../../../components/admin/AdminPageFrame.vue';
import WorkspaceAdministrationSettings from '../../../layouts/settings/WorkspaceAdministrationSettings.vue';
import { t } from '../../localization/i18nRuntime.js';

const administrationSettingsRef = ref(null);
const saving = ref(false);
const message = ref('');
const savedOk = ref(true);

async function saveConfiguration() {
  if (saving.value) return;
  saving.value = true;
  message.value = '';
  savedOk.value = true;
  try {
    const result = await administrationSettingsRef.value?.save?.();
    savedOk.value = result?.ok !== false;
    message.value = result?.message || (savedOk.value ? t('administration.configuration_saved') : t('administration.configuration_save_failed'));
  } catch (error) {
    savedOk.value = false;
    message.value = error instanceof Error ? error.message : t('administration.configuration_save_failed');
  } finally {
    saving.value = false;
  }
}
</script>

<style scoped>
.administration-view,
.administration-panel {
  min-height: 0;
}

.administration-status {
  background: var(--bg-ui-chrome);
}

.administration-panel {
  flex: 1 1 auto;
  overflow: auto;
}

.administration-status.error {
  color: var(--color-heading);
}
</style>
