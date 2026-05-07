import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';

function normalizePageCount(value) {
  return Number.isInteger(value) && value > 0 ? value : 1;
}

function normalizeTotal(value, fallback) {
  return Number.isInteger(value) && value >= 0 ? value : fallback;
}

export function useAdminListController(options) {
  const config = options && typeof options === 'object' ? options : {};
  const pageSize = Number.isInteger(config.pageSize) && config.pageSize > 0 ? config.pageSize : 10;
  const debounceMs = Number.isInteger(config.debounceMs) && config.debounceMs >= 0 ? config.debounceMs : 250;
  const queryDraft = ref('');
  const queryApplied = ref('');
  const page = ref(1);
  const rows = ref([]);
  const loading = ref(false);
  const error = ref('');
  const pagination = reactive({
    total: 0,
    pageCount: 1,
    hasPrev: false,
    hasNext: false,
  });
  let loadToken = 0;
  let searchTimer = 0;

  const pageCount = computed(() => Math.max(1, pagination.pageCount));

  function readRows(payload) {
    if (typeof config.rows === 'function') {
      const resolvedRows = config.rows(payload);
      return Array.isArray(resolvedRows) ? resolvedRows : [];
    }
    return Array.isArray(payload?.rows) ? payload.rows : [];
  }

  function readPagination(payload) {
    if (typeof config.pagination === 'function') {
      return config.pagination(payload) || {};
    }
    return payload?.pagination || {};
  }

  function fallbackLoadError(errorValue) {
    if (typeof config.loadErrorMessage === 'function') {
      return config.loadErrorMessage(errorValue);
    }
    return 'Could not load rows.';
  }

  function resetFailedPagination() {
    rows.value = [];
    pagination.total = 0;
    pagination.pageCount = 1;
    pagination.hasPrev = false;
    pagination.hasNext = false;
  }

  async function loadRows() {
    const token = ++loadToken;
    loading.value = true;
    error.value = '';

    try {
      if (typeof config.load !== 'function') {
        throw new Error('Admin list loader is not configured.');
      }
      const payload = await config.load({
        query: queryApplied.value,
        page: page.value,
        pageSize,
      });

      if (token !== loadToken) return;

      const nextRows = readRows(payload);
      const paging = readPagination(payload);
      const nextPageCount = normalizePageCount(paging.page_count);
      rows.value = nextRows;
      pagination.total = normalizeTotal(paging.total, nextRows.length);
      pagination.pageCount = nextPageCount;
      pagination.hasPrev = Boolean(paging.has_prev);
      pagination.hasNext = Boolean(paging.has_next);
      if (page.value > pagination.pageCount) {
        page.value = pagination.pageCount;
        if (token === loadToken) {
          await loadRows();
        }
      }
    } catch (caught) {
      if (token !== loadToken) return;
      resetFailedPagination();
      error.value = caught instanceof Error ? caught.message : fallbackLoadError(caught);
    } finally {
      if (token === loadToken) loading.value = false;
    }
  }

  function applySearchNow() {
    queryApplied.value = queryDraft.value.trim();
    page.value = 1;
    void loadRows();
  }

  function goToPage(nextPage) {
    if (!Number.isInteger(nextPage) || nextPage < 1 || nextPage === page.value) return;
    page.value = nextPage;
    void loadRows();
  }

  function clearSearchTimer() {
    if (!searchTimer) return;
    globalThis.clearTimeout(searchTimer);
    searchTimer = 0;
  }

  watch(queryDraft, () => {
    clearSearchTimer();
    searchTimer = globalThis.setTimeout(() => {
      queryApplied.value = queryDraft.value.trim();
      page.value = 1;
      void loadRows();
    }, debounceMs);
  });

  onBeforeUnmount(() => {
    clearSearchTimer();
  });

  return {
    pageSize,
    queryDraft,
    queryApplied,
    page,
    rows,
    loading,
    error,
    pagination,
    pageCount,
    loadRows,
    applySearchNow,
    goToPage,
  };
}
