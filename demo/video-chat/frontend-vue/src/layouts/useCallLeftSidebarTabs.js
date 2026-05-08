import { computed, onBeforeUnmount, ref, unref, watch } from 'vue';
import { useCallAppsCatalogStore } from '../stores/callAppsCatalogStore.js';

function asText(value) {
  return String(unref(value) || '').trim();
}

export function useCallLeftSidebarTabs({
  activeCallId,
  currentLayoutMode,
} = {}) {
  const callAppsCatalogStore = useCallAppsCatalogStore();
  const activePanel = ref('settings');
  let probeSeq = 0;

  const normalizedCallId = computed(() => asText(activeCallId));
  const showTabs = computed(() => normalizedCallId.value !== '');
  const showCallAppsPanel = computed(() => showTabs.value && activePanel.value === 'call_apps');
  const showSettingsPanel = computed(() => !showTabs.value || activePanel.value === 'settings');

  function resetToSettings() {
    activePanel.value = 'settings';
  }

  watch(normalizedCallId, (callId) => {
    probeSeq += 1;
    const sequence = probeSeq;
    if (callId === '') {
      callAppsCatalogStore.resetCallAppsCatalog();
      resetToSettings();
      return;
    }

    void callAppsCatalogStore.loadAvailableApps({
      callId,
      page: 1,
      pageSize: 1,
    }).then(() => {
      if (sequence !== probeSeq) return;
      if (!showTabs.value && activePanel.value === 'call_apps') {
        resetToSettings();
      }
    });
  }, { immediate: true });

  watch(showTabs, (visible) => {
    if (!visible && activePanel.value === 'call_apps') {
      resetToSettings();
    }
  });

  onBeforeUnmount(() => {
    probeSeq += 1;
    if (asText(callAppsCatalogStore.activeCallId) === normalizedCallId.value) {
      callAppsCatalogStore.resetCallAppsCatalog();
    }
  });

  return {
    activePanel,
    showTabs,
    showCallAppsPanel,
    showSettingsPanel,
  };
}
