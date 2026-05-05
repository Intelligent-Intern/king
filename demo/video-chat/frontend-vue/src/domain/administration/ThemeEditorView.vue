<template>
  <section class="view-card theme-editor-view">
    <AppPageHeader class="section theme-editor-head" title="Theme Editor" />

    <section class="section theme-editor-panel">
      <WorkspaceThemeSettings v-model="selectedTheme" management-only />
    </section>
  </section>
</template>

<script setup>
import { ref } from 'vue';
import AppPageHeader from '../../components/AppPageHeader.vue';
import { sessionState } from '../../domain/auth/session';
import WorkspaceThemeSettings from '../../layouts/settings/WorkspaceThemeSettings.vue';

const selectedTheme = ref(sessionState.theme || 'dark');
</script>

<style scoped>
.theme-editor-view,
.theme-editor-panel {
  min-height: 0;
}

.theme-editor-view {
  min-height: 100%;
  display: flex;
  flex-direction: column;
  gap: 0;
  background: transparent;
}

.theme-editor-view > :first-child {
  border-top-left-radius: 0;
  border-top-right-radius: 5px;
}

.theme-editor-head {
  background: var(--bg-ui-chrome);
}

.theme-editor-panel {
  flex: 1 1 auto;
  overflow: auto;
}
</style>
