import { computed, reactive, ref } from 'vue';
import { createParticipantDirectoryStore } from '../dashboard/viewState';

export function createComposeController({
  apiRequest,
  clearNotice,
  setNotice,
  publishAdminSync,
  closeEnterCallModal,
  loadCalls,
  loadCalendar,
  openCallWorkspace,
  normalizeCallAccessMode,
  isoToLocalInput,
  localInputToIso,
  sessionState,
}) {
  const composeState = reactive({
    open: false,
    mode: 'create',
    callId: '',
    title: '',
    accessMode: 'invite_only',
    startsLocal: '',
    endsLocal: '',
    replaceParticipants: false,
    submitting: false,
    error: '',
  });

  const composeParticipantStore = createParticipantDirectoryStore();
  const composeParticipants = composeParticipantStore.state;
  const composeSelectedUserIds = ref([]);
  const composeExternalRows = ref([]);
  let composeExternalRowId = 0;

  function currentSessionUserId() {
    const id = Number(sessionState.userId || 0);
    return Number.isInteger(id) && id > 0 ? id : 0;
  }

  function normalizedInternalParticipantUserIds() {
    const ownUserId = currentSessionUserId();
    const seen = new Set();
    const ids = [];
    for (const rawId of composeSelectedUserIds.value) {
      const id = Number(rawId);
      if (!Number.isInteger(id) || id <= 0 || id === ownUserId || seen.has(id)) {
        continue;
      }
      seen.add(id);
      ids.push(id);
    }
    return ids;
  }

  function nextExternalRow() {
    composeExternalRowId += 1;
    return {
      id: composeExternalRowId,
      display_name: '',
      email: '',
    };
  }

  const composeHeadline = computed(() => {
    if (composeState.mode === 'edit') return 'Edit video call';
    if (composeState.mode === 'schedule') return 'Schedule video call';
    return 'Start video call';
  });

  const composeSubmitLabel = computed(() => {
    if (composeState.mode === 'edit') return 'Save changes';
    if (composeState.mode === 'schedule') return 'Schedule call';
    return 'Start now';
  });

  const shouldSendParticipants = computed(
    () => composeState.mode !== 'edit' || composeState.replaceParticipants,
  );

  function seedComposeWindow() {
    const now = new Date();
    const start = new Date(now.getTime());
    start.setMinutes(start.getMinutes() + 60);

    const end = new Date(start.getTime());
    end.setMinutes(end.getMinutes() + 30);

    composeState.startsLocal = isoToLocalInput(start.toISOString());
    composeState.endsLocal = isoToLocalInput(end.toISOString());
  }

  function resetComposeModal() {
    composeState.callId = '';
    composeState.title = '';
    composeState.accessMode = 'invite_only';
    composeState.replaceParticipants = false;
    composeState.submitting = false;
    composeState.error = '';
    composeParticipantStore.reset();
    composeSelectedUserIds.value = [];
    composeExternalRows.value = [];
  }

  function openCompose(mode, call = null) {
    clearNotice();
    closeEnterCallModal();
    resetComposeModal();
    composeState.mode = mode;
    composeState.open = true;

    if (mode === 'edit' && call) {
      composeState.callId = String(call.id || '');
      composeState.title = String(call.title || '');
      composeState.accessMode = normalizeCallAccessMode(call.access_mode);
      composeState.startsLocal = isoToLocalInput(String(call.starts_at || ''));
      composeState.endsLocal = isoToLocalInput(String(call.ends_at || ''));
      composeState.replaceParticipants = false;
    } else {
      if (mode !== 'create') {
        seedComposeWindow();
      } else {
        composeState.startsLocal = '';
        composeState.endsLocal = '';
      }
      composeState.replaceParticipants = true;
      composeExternalRows.value = [nextExternalRow()];
    }

    void loadComposeParticipants();
  }

  function closeCompose() {
    composeState.open = false;
    composeState.submitting = false;
    composeState.error = '';
  }

  async function loadComposeParticipants() {
    if (!composeState.open) return;

    composeParticipants.loading = true;
    composeParticipants.error = '';

    try {
      const payload = await apiRequest('/api/admin/users', {
        query: {
          query: composeParticipants.query,
          page: composeParticipants.page,
          page_size: composeParticipants.pageSize,
        },
      });

      const ownUserId = currentSessionUserId();
      const allRows = Array.isArray(payload.users) ? payload.users : [];
      const rows = allRows.filter((row) => {
        const candidateId = Number(row?.id ?? row?.user_id ?? 0);
        return !Number.isInteger(candidateId) || candidateId !== ownUserId;
      });
      composeParticipantStore.applyRows(rows, payload.pagination || {});
      if (ownUserId > 0) {
        composeSelectedUserIds.value = composeSelectedUserIds.value.filter((id) => Number(id) !== ownUserId);
      }
    } catch (error) {
      composeParticipantStore.fail(error instanceof Error ? error.message : 'Could not load users.');
    } finally {
      composeParticipants.loading = false;
    }
  }

  async function applyParticipantSearch() {
    composeParticipants.page = 1;
    await loadComposeParticipants();
  }

  async function goToParticipantPage(nextPage) {
    if (!Number.isInteger(nextPage) || nextPage < 1 || nextPage === composeParticipants.page) {
      return;
    }

    composeParticipants.page = nextPage;
    await loadComposeParticipants();
  }

  function isUserSelected(userId) {
    const id = Number(userId);
    return composeSelectedUserIds.value.includes(id);
  }

  function toggleUserSelection(userId) {
    const id = Number(userId);
    const ownUserId = currentSessionUserId();
    if (!Number.isInteger(id) || id <= 0) {
      return;
    }
    if (ownUserId > 0 && id === ownUserId) {
      return;
    }

    const next = composeSelectedUserIds.value.slice();
    const index = next.indexOf(id);
    if (index >= 0) {
      next.splice(index, 1);
    } else {
      next.push(id);
    }

    composeSelectedUserIds.value = next;
  }

  function addExternalRow() {
    composeExternalRows.value = [...composeExternalRows.value, nextExternalRow()];
  }

  function removeExternalRow(index) {
    if (!Number.isInteger(index) || index < 0 || index >= composeExternalRows.value.length) {
      return;
    }

    const next = composeExternalRows.value.slice();
    next.splice(index, 1);
    composeExternalRows.value = next;
  }

  function normalizeExternalRows() {
    const rows = [];

    for (let index = 0; index < composeExternalRows.value.length; index += 1) {
      const row = composeExternalRows.value[index];
      const displayName = String(row?.display_name || '').trim();
      const email = String(row?.email || '').trim().toLowerCase();

      if (displayName === '' && email === '') {
        continue;
      }

      if (displayName === '' || email === '') {
        return {
          ok: false,
          error: `External participant row ${index + 1} requires both display name and email.`,
          rows: [],
        };
      }

      if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) {
        return {
          ok: false,
          error: `External participant row ${index + 1} has an invalid email.`,
          rows: [],
        };
      }

      rows.push({
        display_name: displayName,
        email,
      });
    }

    return {
      ok: true,
      error: '',
      rows,
    };
  }

  async function submitCompose() {
    composeState.error = '';
    clearNotice();

    const title = composeState.title.trim();
    if (title === '') {
      composeState.error = 'Title is required.';
      return;
    }

    let startsAt = '';
    let endsAt = '';
    if (composeState.mode === 'create') {
      const now = Date.now();
      startsAt = new Date(now).toISOString();
      endsAt = new Date(now + (60 * 60 * 1000)).toISOString();
    } else {
      startsAt = localInputToIso(composeState.startsLocal);
      endsAt = localInputToIso(composeState.endsLocal);
      if (startsAt === '' || endsAt === '') {
        composeState.error = 'Start and end timestamps are required.';
        return;
      }

      if (new Date(endsAt).getTime() <= new Date(startsAt).getTime()) {
        composeState.error = 'End timestamp must be after start timestamp.';
        return;
      }
    }

    const payload = {
      title,
      access_mode: normalizeCallAccessMode(composeState.accessMode),
      starts_at: startsAt,
      ends_at: endsAt,
    };

    if (shouldSendParticipants.value) {
      const normalizedExternal = normalizeExternalRows();
      if (!normalizedExternal.ok) {
        composeState.error = normalizedExternal.error;
        return;
      }

      payload.internal_participant_user_ids = normalizedInternalParticipantUserIds();
      payload.external_participants = normalizedExternal.rows;
    }

    composeState.submitting = true;

    try {
      if (composeState.mode === 'edit') {
        const callId = encodeURIComponent(composeState.callId);
        await apiRequest(`/api/calls/${callId}`, {
          method: 'PATCH',
          body: payload,
        });
        setNotice('ok', 'Call updated.');
        publishAdminSync('calls', 'call_updated');
      } else {
        const createResult = await apiRequest('/api/calls', {
          method: 'POST',
          body: payload,
        });
        const createdCallId = String(createResult?.result?.call?.id || '').trim();
        const createdRoomId = String(createResult?.result?.call?.room_id || createdCallId || 'lobby').trim() || 'lobby';
        publishAdminSync('calls', 'call_created');
        if (composeState.mode === 'create') {
          closeCompose();
          void openCallWorkspace({ callId: createdCallId, roomId: createdRoomId });
          return;
        }
        setNotice('ok', 'Call created.');
      }

      closeCompose();
      await Promise.all([loadCalls(), loadCalendar()]);
    } catch (error) {
      composeState.error = error instanceof Error ? error.message : 'Could not save call.';
    } finally {
      composeState.submitting = false;
    }
  }

  return {
    composeState,
    composeParticipants,
    composeSelectedUserIds,
    composeExternalRows,
    composeHeadline,
    composeSubmitLabel,
    openCompose,
    closeCompose,
    loadComposeParticipants,
    applyParticipantSearch,
    goToParticipantPage,
    isUserSelected,
    toggleUserSelection,
    addExternalRow,
    removeExternalRow,
    submitCompose,
  };
}
