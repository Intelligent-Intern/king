<template>
  <AdminPageFrame class="administration-view" :title="t('administration.app_configuration')">
    <section class="administration-tabs" role="tablist" :aria-label="t('administration.app_configuration_tabs')">
      <button
        v-for="tab in tabs"
        :key="tab.key"
        class="administration-tab"
        :class="{ active: activeTab === tab.key }"
        type="button"
        role="tab"
        :aria-selected="activeTab === tab.key"
        @click="activeTab = tab.key"
      >
        {{ t(tab.labelKey) }}
      </button>
    </section>

    <section class="administration-panel">
      <AppConfigurationEmailTab v-if="activeTab === 'email'" />
      <AppConfigurationEmailTextsTab v-else-if="activeTab === 'email_texts'" />
      <AppConfigurationBackgroundImagesTab v-else />
    </section>
  </AdminPageFrame>
</template>

<script setup>
import { ref } from 'vue';
import AdminPageFrame from '../../../components/admin/AdminPageFrame.vue';
import { t } from '../../localization/i18nRuntime.js';
import AppConfigurationBackgroundImagesTab from '../components/AppConfigurationBackgroundImagesTab.vue';
import AppConfigurationEmailTab from '../components/AppConfigurationEmailTab.vue';
import AppConfigurationEmailTextsTab from '../components/AppConfigurationEmailTextsTab.vue';

const tabs = Object.freeze([
  { key: 'email', labelKey: 'administration.tab_email' },
  { key: 'email_texts', labelKey: 'administration.tab_email_texts' },
  { key: 'background_images', labelKey: 'administration.tab_background_images' },
]);

const activeTab = ref('email');
</script>

<style scoped>
.administration-view,
.administration-panel {
  min-height: 0;
}

.administration-tabs {
  display: flex;
  flex: 0 0 auto;
  gap: 10px;
  padding: 0 10px 20px;
  overflow-x: auto;
}

.administration-tab {
  min-height: 40px;
  border: 1px solid var(--color-border);
  border-radius: 0;
  background: var(--color-surface-navy);
  color: var(--text-main);
  font: inherit;
  font-weight: 700;
  padding: 0 14px;
  white-space: nowrap;
}

.administration-tab.active {
  background: var(--brand-cyan);
  border-color: var(--brand-cyan);
  color: var(--text-primary);
}

.administration-panel {
  flex: 1 1 auto;
  overflow: hidden;
  padding: 0 10px 10px;
}
</style>
