<template>
  <AdminPageFrame class="theme-editor-view" :title="t('theme_editor.title')">
    <template #actions>
      <button v-if="canCreateThemes" class="btn btn-cyan theme-editor-create-btn" type="button" @click="startCreateTheme">
        {{ t('theme_settings.new_theme') }}
      </button>
    </template>

    <section class="section theme-editor-panel">
      <WorkspaceThemeSettings ref="themeSettingsRef" v-model="selectedTheme" management-only />
    </section>
  </AdminPageFrame>
</template>

<script setup>
import { computed, ref } from 'vue';
import AdminPageFrame from '../../../components/admin/AdminPageFrame.vue';
import { sessionState } from '../../../domain/auth/session';
import WorkspaceThemeSettings from '../../../layouts/settings/WorkspaceThemeSettings.vue';
import { t } from '../../localization/i18nRuntime.js';

const selectedTheme = ref(sessionState.theme || 'dark');
const themeSettingsRef = ref(null);
const canCreateThemes = computed(() => sessionState.role === 'admin' || sessionState.canEditThemes === true);

function startCreateTheme() {
  themeSettingsRef.value?.startCreateTheme?.();
}
</script>

<style scoped>
.theme-editor-view,
.theme-editor-panel {
  min-height: 0;
}

.theme-editor-panel {
  flex: 1 1 auto;
  display: flex;
  flex-direction: column;
  overflow: auto;
}

.theme-editor-create-btn {
  min-width: 120px;
}
</style>
