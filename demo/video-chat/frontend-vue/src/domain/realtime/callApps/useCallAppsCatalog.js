import { storeToRefs } from 'pinia';
import { useCallAppsCatalogStore } from '../../../stores/callAppsCatalogStore.js';

export function useCallAppsCatalog() {
  const store = useCallAppsCatalogStore();
  const {
    activeCallId,
    query,
    category,
    apps,
    availableApps,
    hasAvailableApps,
    pagination,
    loading,
    error,
  } = storeToRefs(store);

  return {
    activeCallId,
    query,
    category,
    apps,
    availableApps,
    hasAvailableApps,
    pagination,
    loading,
    error,
    loadAvailableApps: store.loadAvailableApps,
    resetCallAppsCatalog: store.resetCallAppsCatalog,
  };
}
