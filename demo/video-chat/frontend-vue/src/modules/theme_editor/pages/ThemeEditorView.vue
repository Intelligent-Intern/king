<template>
  <AdminPageFrame class="theme-editor-view" :title="t('theme_editor.title')">
    <section class="section theme-editor-panel">
      <WorkspaceThemeSettings v-model="selectedTheme" management-only />
    </section>
  </AdminPageFrame>
</template>

<script setup>
import { ref } from 'vue';
import AdminPageFrame from '../../../components/admin/AdminPageFrame.vue';
import { sessionState } from '../../../domain/auth/session';
import WorkspaceThemeSettings from '../../../layouts/settings/WorkspaceThemeSettings.vue';
import { t } from '../../localization/i18nRuntime.js';

const selectedTheme = ref(sessionState.theme || 'dark');
</script>

<style scoped>
.theme-editor-view,
.theme-editor-panel {
  min-height: 0;
}

.theme-editor-panel {
  flex: 1 1 auto;
  overflow: auto;
}
</style>
