<template>
  <section class="view-card administration-view">
    <AppPageHeader class="section administration-head" title="App Configuration">
      <template #actions>
        <button class="btn btn-cyan" type="button" :disabled="saving" @click="saveConfiguration">
          {{ saving ? 'Saving...' : 'Save configuration' }}
        </button>
      </template>
    </AppPageHeader>

    <section class="section administration-panel">
      <WorkspaceAdministrationSettings ref="administrationSettingsRef" title="" />
    </section>

    <section v-if="message" class="section administration-status" :class="{ error: !savedOk }">
      {{ message }}
    </section>
  </section>
</template>

<script setup>
import { ref } from 'vue';
import AppPageHeader from '../../components/AppPageHeader.vue';
import WorkspaceAdministrationSettings from '../../layouts/settings/WorkspaceAdministrationSettings.vue';

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
    message.value = result?.message || (savedOk.value ? 'Configuration saved.' : 'Could not save configuration.');
  } catch (error) {
    savedOk.value = false;
    message.value = error instanceof Error ? error.message : 'Could not save configuration.';
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

.administration-view {
  min-height: 100%;
  display: flex;
  flex-direction: column;
  gap: 0;
  background: transparent;
}

.administration-view > :first-child {
  border-top-left-radius: 0;
  border-top-right-radius: 5px;
}

.administration-head,
.administration-status {
  background: var(--bg-ui-chrome);
}

.administration-panel {
  flex: 1 1 auto;
  overflow: auto;
}

.administration-status.error {
  color: var(--color-ffb5b5);
}
</style>
