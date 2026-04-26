import { computed, reactive, ref } from 'vue';

function createPaginationState(pageSize = 10) {
  return reactive({
    page: 1,
    pageSize,
    total: 0,
    pageCount: 1,
    hasPrev: false,
    hasNext: false,
  });
}

function applyPaginationState(pagination, paging, fallbackTotal) {
  pagination.page = Number.isInteger(paging?.page) ? paging.page : pagination.page;
  pagination.pageSize = Number.isInteger(paging?.page_size) ? paging.page_size : pagination.pageSize;
  pagination.total = Number.isInteger(paging?.total) ? paging.total : fallbackTotal;
  pagination.pageCount = Number.isInteger(paging?.page_count) && paging.page_count > 0 ? paging.page_count : 1;
  pagination.hasPrev = Boolean(paging?.has_prev);
  pagination.hasNext = Boolean(paging?.has_next);
}

function resetPaginationState(pagination, { resetPage = false } = {}) {
  if (resetPage) {
    pagination.page = 1;
  }
  pagination.total = 0;
  pagination.pageCount = 1;
  pagination.hasPrev = false;
  pagination.hasNext = false;
}

export function createCallListStore({ defaultScope = 'my', pageSize = 10 } = {}) {
  const pagination = createPaginationState(pageSize);

  return {
    viewMode: ref('calls'),
    queryDraft: ref(''),
    queryApplied: ref(''),
    statusFilter: ref('all'),
    scopeFilter: ref(defaultScope),
    calls: ref([]),
    loadingCalls: ref(false),
    callsError: ref(''),
    pagination,
    calendarCalls: ref([]),
    loadingCalendar: ref(false),
    calendarError: ref(''),
    applyPagination: (paging, fallbackTotal) => applyPaginationState(pagination, paging, fallbackTotal),
    resetPagination: (options) => resetPaginationState(pagination, options),
  };
}

export function createNoticeStore() {
  const noticeKind = ref('');
  const noticeMessage = ref('');
  const noticeKindClass = computed(() => ({
    ok: noticeKind.value === 'ok',
    error: noticeKind.value === 'error',
  }));

  function setNotice(kind, message) {
    noticeKind.value = kind;
    noticeMessage.value = String(message || '').trim();
  }

  function clearNotice() {
    noticeKind.value = '';
    noticeMessage.value = '';
  }

  return {
    noticeKind,
    noticeMessage,
    noticeKindClass,
    setNotice,
    clearNotice,
  };
}

export function createChatArchiveStore() {
  const state = reactive({
    open: false,
    callId: '',
    callTitle: '',
  });

  function openChatArchive(call) {
    state.callId = String(call?.id || '').trim();
    state.callTitle = String(call?.title || call?.id || '').trim();
    state.open = state.callId !== '';
  }

  function closeChatArchive() {
    state.open = false;
    state.callId = '';
    state.callTitle = '';
  }

  return {
    state,
    openChatArchive,
    closeChatArchive,
  };
}

export function createParticipantDirectoryStore({ pageSize = 10 } = {}) {
  const state = reactive({
    loading: false,
    error: '',
    query: '',
    page: 1,
    pageSize,
    total: 0,
    pageCount: 1,
    hasPrev: false,
    hasNext: false,
    rows: [],
  });

  function reset() {
    state.loading = false;
    state.error = '';
    state.query = '';
    state.page = 1;
    state.rows = [];
    applyPaginationState(state, {}, 0);
  }

  function applyRows(rows, paging) {
    state.rows = Array.isArray(rows) ? rows : [];
    applyPaginationState(state, paging, state.rows.length);
  }

  function fail(message) {
    state.rows = [];
    state.error = String(message || 'Could not load users.');
    applyPaginationState(state, {}, 0);
  }

  return {
    state,
    reset,
    applyRows,
    fail,
  };
}
