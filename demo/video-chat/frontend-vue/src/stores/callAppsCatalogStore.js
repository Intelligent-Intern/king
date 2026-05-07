import { computed, ref } from 'vue';
import { defineStore } from 'pinia';
import { apiRequest } from '../domain/realtime/workspace/api';

function normalizePagination(value = {}) {
  const page = Number.parseInt(String(value.page || 1), 10) || 1;
  const pageSize = Number.parseInt(String(value.page_size || 12), 10) || 12;
  const total = Number.parseInt(String(value.total || 0), 10) || 0;
  const pageCount = Number.parseInt(String(value.page_count || 0), 10) || 0;

  return {
    page,
    page_size: pageSize,
    total,
    page_count: pageCount,
    returned: Number.parseInt(String(value.returned || 0), 10) || 0,
    has_prev: value.has_prev === true,
    has_next: value.has_next === true,
  };
}

function normalizeAvailableApp(value = {}) {
  const healthStatus = String(value.health_status || '').trim().toLowerCase();
  const installation = value.installation && typeof value.installation === 'object' ? value.installation : {};
  const availability = value.availability && typeof value.availability === 'object' ? value.availability : {};

  return {
    ...value,
    app_key: String(value.app_key || '').trim(),
    name: String(value.name || '').trim(),
    category: String(value.category || 'other').trim(),
    version: String(value.version || '').trim(),
    health_status: healthStatus,
    availability: {
      installed: availability.installed === true,
      healthy: availability.healthy === true || healthStatus === 'healthy',
      source: String(availability.source || 'organization_installation').trim(),
    },
    installation: {
      ...installation,
      id: String(installation.id || '').trim(),
      status: String(installation.status || '').trim().toLowerCase(),
      default_app_policy: String(installation.default_app_policy || 'blocked_by_default').trim(),
    },
  };
}

function isSidebarVisibleApp(app) {
  return Boolean(
    app
    && app.app_key
    && app.availability?.installed === true
    && app.availability?.healthy === true
    && app.installation?.status === 'enabled'
    && app.health_status === 'healthy',
  );
}

export const useCallAppsCatalogStore = defineStore('callAppsCatalog', () => {
  const activeCallId = ref('');
  const query = ref('');
  const category = ref('all');
  const apps = ref([]);
  const pagination = ref(normalizePagination());
  const loading = ref(false);
  const error = ref('');

  const availableApps = computed(() => apps.value.filter(isSidebarVisibleApp));
  const hasAvailableApps = computed(() => availableApps.value.length > 0);

  async function loadAvailableApps({
    callId,
    query: nextQuery = query.value,
    category: nextCategory = category.value,
    page = pagination.value.page || 1,
    pageSize = pagination.value.page_size || 12,
  } = {}) {
    const normalizedCallId = String(callId || activeCallId.value || '').trim();
    if (normalizedCallId === '') {
      apps.value = [];
      pagination.value = normalizePagination();
      error.value = 'Call id is required.';
      return [];
    }

    activeCallId.value = normalizedCallId;
    query.value = String(nextQuery || '').trim();
    category.value = String(nextCategory || 'all').trim() || 'all';
    loading.value = true;
    error.value = '';

    try {
      const payload = await apiRequest(
        `/api/calls/${encodeURIComponent(normalizedCallId)}/call-apps/available`,
        {
          query: {
            query: query.value,
            category: category.value,
            page,
            page_size: pageSize,
          },
        },
      );
      const result = payload?.result && typeof payload.result === 'object' ? payload.result : {};
      const rows = Array.isArray(result.apps) ? result.apps : [];
      apps.value = rows.map(normalizeAvailableApp).filter(isSidebarVisibleApp);
      pagination.value = normalizePagination(result.pagination || {});
      return apps.value;
    } catch (loadError) {
      const message = loadError instanceof Error ? loadError.message.trim() : '';
      error.value = message || 'Could not load Call Apps.';
      apps.value = [];
      pagination.value = normalizePagination();
      return [];
    } finally {
      loading.value = false;
    }
  }

  function resetCallAppsCatalog() {
    activeCallId.value = '';
    query.value = '';
    category.value = 'all';
    apps.value = [];
    pagination.value = normalizePagination();
    loading.value = false;
    error.value = '';
  }

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
    loadAvailableApps,
    resetCallAppsCatalog,
  };
});
