import { computed } from 'vue';
import { defineStore } from 'pinia';
import { workspaceModuleRegistry } from '../modules/index.js';
import {
  buildModuleRouteRecords,
  buildSettingsPanels,
  buildWorkspaceNavigation,
} from '../modules/navigationBuilder.js';

export const useWorkspaceModuleStore = defineStore('workspaceModules', () => {
  const moduleKeys = computed(() => workspaceModuleRegistry.list().map((descriptor) => descriptor.module_key).sort());

  function routes() {
    return buildModuleRouteRecords(workspaceModuleRegistry);
  }

  function navigationFor(context = {}) {
    return buildWorkspaceNavigation(workspaceModuleRegistry, context);
  }

  function settingsPanelsFor(context = {}) {
    return buildSettingsPanels(workspaceModuleRegistry, context);
  }

  return {
    moduleKeys,
    routes,
    navigationFor,
    settingsPanelsFor,
  };
});
