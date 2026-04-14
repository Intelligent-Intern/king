<template>
  <section class="view-card calls-view">
    <section class="section calls-header">
      <div class="calls-header-left">
        <h3>My Video Calls</h3>
      </div>
      <div class="actions">
        <button class="btn" type="button" @click="openCompose('create')">New call</button>
      </div>
    </section>

    <section class="toolbar calls-toolbar">
      <input
        v-model="queryDraft"
        class="input"
        type="text"
        placeholder="Search call title"
        @keydown.enter.prevent="applyFilters"
      />
      <button class="btn" type="button" @click="applyFilters">Search</button>
    </section>

    <section v-if="noticeMessage" class="section calls-banner" :class="noticeKindClass">
      {{ noticeMessage }}
    </section>

    <section v-if="viewMode === 'calls'" class="table-wrap calls-table-wrap">
      <table>
        <thead>
          <tr>
            <th class="col-title">Call</th>
            <th>Status</th>
            <th>Window</th>
            <th>Participants</th>
            <th>Owner</th>
            <th class="col-actions">Actions</th>
          </tr>
        </thead>
        <tbody v-if="calls.length > 0">
          <tr v-for="call in calls" :key="call.id">
            <td>
              <div class="call-title">{{ call.title || call.id }}</div>
              <div class="call-subline code">{{ call.id }}</div>
            </td>
            <td>
              <span class="tag" :class="statusTagClass(call.status)">
                {{ call.status || 'unknown' }}
              </span>
            </td>
            <td>{{ formatRange(call.starts_at, call.ends_at) }}</td>
            <td>
              {{ call.participants?.total ?? 0 }}
              <span class="call-subline">
                in {{ call.participants?.internal ?? 0 }} / ex {{ call.participants?.external ?? 0 }}
              </span>
            </td>
            <td>
              {{ call.owner?.display_name || 'Unknown' }}
              <span class="call-subline">{{ call.owner?.email || 'n/a' }}</span>
            </td>
            <td>
              <div class="actions-inline">
                <button
                  class="icon-mini-btn"
                  type="button"
                  title="Edit call"
                  :aria-label="`Edit call ${call.title || call.id}`"
                  :disabled="!isEditable(call)"
                  @click="openCompose('edit', call)"
                >
                  <img src="/assets/orgas/kingrt/icons/gear.png" alt="" />
                </button>
                <button
                  class="icon-mini-btn"
                  type="button"
                  title="Create invite code"
                  :aria-label="`Create invite for ${call.title || call.id}`"
                  :disabled="!isInvitable(call)"
                  @click="toggleInvitePopover($event, call)"
                >
                  <img src="/assets/orgas/kingrt/icons/add_to_call.png" alt="" />
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>

      <section v-if="!loadingCalls && calls.length === 0" class="section calls-empty">
        No calls match the active filters.
      </section>
      <section v-if="loadingCalls" class="section calls-empty">
        Loading calls...
      </section>
      <section v-if="callsError" class="section calls-empty calls-error">
        {{ callsError }}
      </section>
    </section>

    <section v-else class="table-wrap calls-calendar-wrap">
      <section v-if="loadingCalendar" class="section calls-empty">
        Loading calendar view...
      </section>
      <section v-else-if="calendarError" class="section calls-empty calls-error">
        {{ calendarError }}
      </section>
      <section v-else-if="calendarBuckets.length === 0" class="section calls-empty">
        No calendar entries for the active filters.
      </section>
      <section v-else class="calls-calendar-grid">
        <article v-for="bucket in calendarBuckets" :key="bucket.key" class="calls-calendar-day">
          <header class="calls-calendar-day-head">
            <h4>{{ bucket.label }}</h4>
            <span>{{ bucket.rows.length }} calls</span>
          </header>
          <ul class="calls-calendar-list">
            <li v-for="call in bucket.rows" :key="call.id" class="calls-calendar-item">
              <div class="calls-calendar-item-meta">
                <strong>{{ call.title || call.id }}</strong>
                <span>{{ formatRange(call.starts_at, call.ends_at) }}</span>
                <span>{{ call.owner?.display_name || 'Unknown owner' }}</span>
              </div>
              <div class="actions-inline">
                <button
                  class="icon-mini-btn"
                  type="button"
                  title="Edit call"
                  :disabled="!isEditable(call)"
                  @click="openCompose('edit', call)"
                >
                  <img src="/assets/orgas/kingrt/icons/gear.png" alt="" />
                </button>
                <button
                  class="icon-mini-btn"
                  type="button"
                  title="Create invite code"
                  :disabled="!isInvitable(call)"
                  @click="toggleInvitePopover($event, call)"
                >
                  <img src="/assets/orgas/kingrt/icons/add_to_call.png" alt="" />
                </button>
              </div>
            </li>
          </ul>
        </article>
      </section>
    </section>

    <section class="footer calls-pagination-wrap">
      <div class="pagination">
        <button
          class="pager-btn pager-icon-btn"
          type="button"
          :disabled="!pagination.hasPrev || loadingCalls"
          @click="goToPage(pagination.page - 1)"
        >
          <img class="pager-icon-img" src="/assets/orgas/kingrt/icons/backward.png" alt="Previous" />
        </button>
        <div class="page-info">
          Page {{ pagination.page }} / {{ pagination.pageCount }} · {{ pagination.total }} total
        </div>
        <button
          class="pager-btn pager-icon-btn"
          type="button"
          :disabled="!pagination.hasNext || loadingCalls"
          @click="goToPage(pagination.page + 1)"
        >
          <img class="pager-icon-img" src="/assets/orgas/kingrt/icons/forward.png" alt="Next" />
        </button>
      </div>
    </section>

    <div
      ref="invitePopoverRef"
      class="invite-popover"
      :hidden="!invitePopover.open"
      :style="invitePopoverStyle"
      role="dialog"
      aria-label="Invite code"
    >
      <p class="invite-popover-label">
        Invite for <strong>{{ invitePopover.callId }}</strong>
      </p>
      <p v-if="invitePopover.loading" class="invite-popover-label">Generating invite code...</p>
      <p v-else-if="invitePopover.error" class="invite-popover-label calls-error">{{ invitePopover.error }}</p>
      <template v-else>
        <div class="invite-popover-row">
          <code class="invite-code">{{ invitePopover.code }}</code>
          <button class="icon-mini-btn" type="button" title="Copy invite" @click="copyInviteCode">
            <span class="icon-copy" aria-hidden="true"></span>
          </button>
        </div>
        <p class="invite-popover-label">
          Expires: {{ formatDateTime(invitePopover.expiresAt) }}
        </p>
        <p v-if="invitePopover.copyNotice" class="invite-popover-label">{{ invitePopover.copyNotice }}</p>
        <div class="actions-inline">
          <button class="btn" type="button" @click="openCallWorkspace(invitePopover.roomId)">Open call</button>
          <button class="invite-popover-close" type="button" @click="closeInvitePopover">Close</button>
        </div>
      </template>
    </div>

    <div class="calls-modal" :hidden="!joinState.open" role="dialog" aria-modal="true" aria-label="Join invite modal">
      <div class="calls-modal-backdrop" @click="closeJoinModal"></div>
      <div class="calls-modal-dialog calls-modal-dialog-small">
        <header class="calls-modal-header">
          <h4>Join with invite</h4>
          <button class="icon-mini-btn" type="button" aria-label="Close" @click="closeJoinModal">
            <img src="/assets/orgas/kingrt/icons/cancel.png" alt="" />
          </button>
        </header>

        <div class="calls-modal-body">
          <label class="field">
            <span>Invite code</span>
            <input
              v-model="joinState.code"
              class="input"
              type="text"
              placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"
              aria-label="Invite code"
              autocomplete="off"
              autocapitalize="off"
              spellcheck="false"
              @keydown.enter.prevent="submitJoinInvite"
            />
          </label>

          <section v-if="joinState.error" class="calls-inline-error">
            {{ joinState.error }}
          </section>
        </div>

        <footer class="calls-modal-footer">
          <button class="btn" type="button" :disabled="joinState.submitting" @click="closeJoinModal">Close</button>
          <button class="btn" type="button" :disabled="joinState.submitting" @click="submitJoinInvite">
            {{ joinState.submitting ? 'Joining...' : 'Join' }}
          </button>
        </footer>
      </div>
    </div>

    <div class="calls-modal" :hidden="!composeState.open" role="dialog" aria-modal="true" aria-label="Call compose modal">
      <div class="calls-modal-backdrop" @click="closeCompose"></div>
      <div class="calls-modal-dialog">
        <header class="calls-modal-header">
          <h4>{{ composeHeadline }}</h4>
          <button class="icon-mini-btn" type="button" aria-label="Close" @click="closeCompose">
            <img src="/assets/orgas/kingrt/icons/cancel.png" alt="" />
          </button>
        </header>

        <div class="calls-modal-body">
          <section class="calls-modal-grid">
            <label class="field">
              <span>Title</span>
              <input v-model="composeState.title" class="input" type="text" placeholder="Weekly Product Sync" />
            </label>
            <label class="field">
              <span>Room ID</span>
              <input v-model="composeState.roomId" class="input" type="text" placeholder="lobby" />
            </label>
            <label class="field">
              <span>Starts at</span>
              <input
                v-model="composeState.startsLocal"
                class="input"
                type="datetime-local"
                aria-label="Call starts at"
              />
            </label>
            <label class="field">
              <span>Ends at</span>
              <input
                v-model="composeState.endsLocal"
                class="input"
                type="datetime-local"
                aria-label="Call ends at"
              />
            </label>
          </section>

          <section v-if="composeState.error" class="calls-inline-error">
            {{ composeState.error }}
          </section>
        </div>

        <footer class="calls-modal-footer">
          <button class="btn" type="button" :disabled="composeState.submitting" @click="closeCompose">Close</button>
          <button class="btn" type="button" :disabled="composeState.submitting" @click="submitCompose">
            {{ composeState.submitting ? 'Saving...' : composeSubmitLabel }}
          </button>
        </footer>
      </div>
    </div>
  </section>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import { sessionState } from '../auth/session';
import { resolveBackendOrigin } from '../../support/backendOrigin';

const router = useRouter();
const backendOrigin = resolveBackendOrigin();

function requestHeaders(withBody = false) {
  const headers = { accept: 'application/json' };
  if (withBody) {
    headers['content-type'] = 'application/json';
  }

  const token = String(sessionState.sessionToken || '').trim();
  if (token !== '') {
    headers.authorization = `Bearer ${token}`;
    headers['x-session-id'] = token;
  }

  return headers;
}

function extractErrorMessage(payload, fallback) {
  if (payload && typeof payload === 'object') {
    const message = payload?.error?.message;
    if (typeof message === 'string' && message.trim() !== '') {
      return message.trim();
    }
  }

  return fallback;
}

function buildQueryString(params) {
  const query = new URLSearchParams();
  for (const [key, value] of Object.entries(params || {})) {
    if (value === undefined || value === null) continue;
    const text = String(value).trim();
    if (text === '') continue;
    query.set(key, text);
  }

  const encoded = query.toString();
  return encoded === '' ? '' : `?${encoded}`;
}

async function apiRequest(path, { method = 'GET', query = null, body = null } = {}) {
  const endpoint = `${backendOrigin}${path}${buildQueryString(query || {})}`;
  const response = await fetch(endpoint, {
    method,
    headers: requestHeaders(body !== null),
    body: body === null ? undefined : JSON.stringify(body),
  });

  let payload = null;
  try {
    payload = await response.json();
  } catch {
    payload = null;
  }

  if (!response.ok) {
    throw new Error(extractErrorMessage(payload, `Request failed (${response.status}).`));
  }

  if (!payload || payload.status !== 'ok') {
    throw new Error('Backend returned an invalid payload.');
  }

  return payload;
}

function isoToLocalInput(isoValue) {
  if (typeof isoValue !== 'string' || isoValue.trim() === '') return '';
  const date = new Date(isoValue);
  if (Number.isNaN(date.getTime())) return '';

  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');
  const hour = String(date.getHours()).padStart(2, '0');
  const minute = String(date.getMinutes()).padStart(2, '0');
  return `${year}-${month}-${day}T${hour}:${minute}`;
}

function localInputToIso(localValue) {
  if (typeof localValue !== 'string' || localValue.trim() === '') return '';
  const date = new Date(localValue);
  if (Number.isNaN(date.getTime())) return '';
  return date.toISOString();
}

function formatDateTime(isoValue) {
  if (typeof isoValue !== 'string' || isoValue.trim() === '') return 'n/a';
  const date = new Date(isoValue);
  if (Number.isNaN(date.getTime())) return isoValue;

  return new Intl.DateTimeFormat('en-GB', {
    year: 'numeric',
    month: 'short',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
  }).format(date);
}

function formatRange(startsAt, endsAt) {
  return `${formatDateTime(startsAt)} -> ${formatDateTime(endsAt)}`;
}

function statusTagClass(status) {
  const normalized = String(status || '').toLowerCase();
  if (normalized === 'scheduled' || normalized === 'active') return 'ok';
  if (normalized === 'ended') return 'warn';
  if (normalized === 'cancelled') return 'danger';
  return 'warn';
}

function isOwnerCall(call) {
  return Number(call?.owner?.user_id || 0) === Number(sessionState.userId || 0);
}

function isEditable(call) {
  const status = String(call?.status || '').toLowerCase();
  if (status === 'cancelled' || status === 'ended') {
    return false;
  }

  if (sessionState.role === 'admin') {
    return true;
  }

  return isOwnerCall(call);
}

function isInvitable(call) {
  return isEditable(call);
}

const canReadAllScope = computed(() => sessionState.role === 'admin');

const viewMode = ref('calls');
const queryDraft = ref('');
const queryApplied = ref('');
const statusFilter = ref('all');
const scopeFilter = ref('my');

const calls = ref([]);
const loadingCalls = ref(false);
const callsError = ref('');

const pagination = reactive({
  page: 1,
  pageSize: 10,
  total: 0,
  pageCount: 1,
  hasPrev: false,
  hasNext: false,
});

const calendarCalls = ref([]);
const loadingCalendar = ref(false);
const calendarError = ref('');

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

async function loadCalls() {
  loadingCalls.value = true;
  callsError.value = '';

  try {
    const payload = await apiRequest('/api/calls', {
      query: {
        scope: scopeFilter.value,
        status: statusFilter.value,
        query: queryApplied.value,
        page: pagination.page,
        page_size: pagination.pageSize,
      },
    });

    calls.value = Array.isArray(payload.calls) ? payload.calls : [];
    const paging = payload.pagination || {};
    pagination.page = Number.isInteger(paging.page) ? paging.page : pagination.page;
    pagination.pageSize = Number.isInteger(paging.page_size) ? paging.page_size : pagination.pageSize;
    pagination.total = Number.isInteger(paging.total) ? paging.total : calls.value.length;
    pagination.pageCount = Number.isInteger(paging.page_count) && paging.page_count > 0 ? paging.page_count : 1;
    pagination.hasPrev = Boolean(paging.has_prev);
    pagination.hasNext = Boolean(paging.has_next);
  } catch (error) {
    calls.value = [];
    callsError.value = error instanceof Error ? error.message : 'Could not load calls.';
    pagination.total = 0;
    pagination.pageCount = 1;
    pagination.hasPrev = false;
    pagination.hasNext = false;
  } finally {
    loadingCalls.value = false;
  }
}

async function loadCalendar() {
  loadingCalendar.value = true;
  calendarError.value = '';

  try {
    const payload = await apiRequest('/api/calls', {
      query: {
        scope: scopeFilter.value,
        status: statusFilter.value,
        query: queryApplied.value,
        page: 1,
        page_size: 100,
      },
    });

    calendarCalls.value = Array.isArray(payload.calls) ? payload.calls : [];
  } catch (error) {
    calendarCalls.value = [];
    calendarError.value = error instanceof Error ? error.message : 'Could not load calendar calls.';
  } finally {
    loadingCalendar.value = false;
  }
}

const calendarBuckets = computed(() => {
  const buckets = new Map();

  for (const call of calendarCalls.value) {
    const key = typeof call?.starts_at === 'string' && call.starts_at.length >= 10
      ? call.starts_at.slice(0, 10)
      : 'unscheduled';

    if (!buckets.has(key)) {
      buckets.set(key, []);
    }

    buckets.get(key).push(call);
  }

  const keys = Array.from(buckets.keys()).sort((a, b) => a.localeCompare(b));

  return keys.map((key) => {
    const rows = buckets.get(key).slice().sort((left, right) => {
      const leftStart = String(left?.starts_at || '');
      const rightStart = String(right?.starts_at || '');
      return leftStart.localeCompare(rightStart);
    });

    let label = 'Unscheduled';
    if (key !== 'unscheduled') {
      const keyDate = new Date(`${key}T00:00:00`);
      if (!Number.isNaN(keyDate.getTime())) {
        label = new Intl.DateTimeFormat('en-GB', {
          weekday: 'short',
          year: 'numeric',
          month: 'short',
          day: '2-digit',
        }).format(keyDate);
      }
    }

    return { key, label, rows };
  });
});

function setViewMode(nextMode) {
  if (nextMode !== 'calls' && nextMode !== 'calendar') {
    return;
  }

  viewMode.value = nextMode;
  if (nextMode === 'calendar' && calendarCalls.value.length === 0 && !loadingCalendar.value) {
    void loadCalendar();
  }
}

async function applyFilters() {
  clearNotice();
  queryApplied.value = queryDraft.value.trim();
  if (!canReadAllScope.value && scopeFilter.value !== 'my') {
    scopeFilter.value = 'my';
  }
  pagination.page = 1;
  await Promise.all([loadCalls(), loadCalendar()]);
}

async function goToPage(nextPage) {
  if (!Number.isInteger(nextPage) || nextPage < 1 || nextPage === pagination.page) {
    return;
  }

  pagination.page = nextPage;
  await loadCalls();
}

const invitePopoverRef = ref(null);
const invitePopover = reactive({
  open: false,
  loading: false,
  error: '',
  code: '',
  expiresAt: '',
  callId: '',
  roomId: '',
  x: 0,
  y: 0,
  copyNotice: '',
  triggerElement: null,
});

const invitePopoverStyle = computed(() => ({
  top: `${invitePopover.y}px`,
  left: `${invitePopover.x}px`,
}));

function closeInvitePopover() {
  invitePopover.open = false;
  invitePopover.loading = false;
  invitePopover.error = '';
  invitePopover.code = '';
  invitePopover.expiresAt = '';
  invitePopover.callId = '';
  invitePopover.roomId = '';
  invitePopover.copyNotice = '';
  invitePopover.triggerElement = null;
}

function placeInvitePopover(triggerElement) {
  if (!(triggerElement instanceof HTMLElement)) {
    return;
  }

  const rect = triggerElement.getBoundingClientRect();
  const popoverWidth = 320;
  const margin = 10;
  let nextX = rect.left;
  let nextY = rect.bottom + 8;

  if (nextX + popoverWidth > window.innerWidth - margin) {
    nextX = window.innerWidth - popoverWidth - margin;
  }
  if (nextX < margin) {
    nextX = margin;
  }

  if (nextY > window.innerHeight - 80) {
    nextY = Math.max(margin, rect.top - 180);
  }

  invitePopover.x = Math.round(nextX);
  invitePopover.y = Math.round(nextY);
}

async function toggleInvitePopover(event, call) {
  if (!call || !call.id) return;

  if (!isInvitable(call)) {
    return;
  }

  const trigger = event?.currentTarget instanceof HTMLElement ? event.currentTarget : null;
  if (invitePopover.open && invitePopover.callId === call.id) {
    closeInvitePopover();
    return;
  }

  invitePopover.open = true;
  invitePopover.loading = true;
  invitePopover.error = '';
  invitePopover.code = '';
  invitePopover.expiresAt = '';
  invitePopover.copyNotice = '';
  invitePopover.callId = String(call.id);
  invitePopover.roomId = String(call.room_id || 'lobby');
  invitePopover.triggerElement = trigger;
  if (trigger) {
    placeInvitePopover(trigger);
  }

  try {
    const payload = await apiRequest('/api/invite-codes', {
      method: 'POST',
      body: {
        scope: 'call',
        call_id: String(call.id),
      },
    });

    const inviteCode = payload?.result?.invite_code || null;
    if (!inviteCode || typeof inviteCode.code !== 'string' || inviteCode.code.trim() === '') {
      throw new Error('Invite code payload is invalid.');
    }

    invitePopover.code = inviteCode.code;
    invitePopover.expiresAt = typeof inviteCode.expires_at === 'string' ? inviteCode.expires_at : '';
  } catch (error) {
    invitePopover.error = error instanceof Error ? error.message : 'Could not create invite code.';
  } finally {
    invitePopover.loading = false;
  }
}

async function copyInviteCode() {
  const code = String(invitePopover.code || '').trim();
  if (code === '') {
    return;
  }

  try {
    if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
      await navigator.clipboard.writeText(code);
    } else {
      const textarea = document.createElement('textarea');
      textarea.value = code;
      textarea.setAttribute('readonly', 'readonly');
      textarea.style.position = 'fixed';
      textarea.style.top = '-1000px';
      document.body.appendChild(textarea);
      textarea.select();
      document.execCommand('copy');
      document.body.removeChild(textarea);
    }

    invitePopover.copyNotice = 'Copied.';
  } catch {
    invitePopover.copyNotice = 'Copy failed.';
  }
}

function openCallWorkspace(roomId) {
  const safeRoomId = String(roomId || 'lobby').trim() || 'lobby';
  closeInvitePopover();
  router.push(`/workspace/call/${encodeURIComponent(safeRoomId)}`);
}

const joinState = reactive({
  open: false,
  submitting: false,
  error: '',
  code: '',
});

function openJoinModal() {
  clearNotice();
  closeInvitePopover();
  joinState.open = true;
  joinState.submitting = false;
  joinState.error = '';
  joinState.code = '';
}

function closeJoinModal() {
  joinState.open = false;
  joinState.submitting = false;
  joinState.error = '';
}

function resolveJoinRoomId(joinContext) {
  const roomId = String(joinContext?.room?.id || joinContext?.call?.room_id || '').trim();
  return roomId === '' ? 'lobby' : roomId;
}

async function submitJoinInvite() {
  clearNotice();
  joinState.error = '';

  const code = String(joinState.code || '').trim();
  if (code === '') {
    joinState.error = 'Invite code is required.';
    return;
  }

  joinState.submitting = true;

  try {
    const payload = await apiRequest('/api/invite-codes/redeem', {
      method: 'POST',
      body: {
        code,
      },
    });

    const redemption = payload?.result?.redemption || {};
    const joinContext = redemption?.join_context || {};
    const roomId = resolveJoinRoomId(joinContext);
    const scope = String(joinContext?.scope || '');

    closeJoinModal();
    setNotice('ok', `Invite redeemed for ${scope || 'invite'} context.`);
    router.push(`/workspace/call/${encodeURIComponent(roomId)}`);
  } catch (error) {
    joinState.error = error instanceof Error ? error.message : 'Could not redeem invite code.';
  } finally {
    joinState.submitting = false;
  }
}

const composeState = reactive({
  open: false,
  mode: 'create',
  callId: '',
  title: '',
  roomId: 'lobby',
  startsLocal: '',
  endsLocal: '',
  submitting: false,
  error: '',
});

const composeHeadline = computed(() => {
  if (composeState.mode === 'edit') return 'Edit video call';
  if (composeState.mode === 'schedule') return 'Schedule video call';
  return 'New video call';
});

const composeSubmitLabel = computed(() => {
  if (composeState.mode === 'edit') return 'Save changes';
  if (composeState.mode === 'schedule') return 'Schedule call';
  return 'Create call';
});

function seedComposeWindow(mode) {
  const now = new Date();
  const start = new Date(now.getTime());
  if (mode === 'create') {
    start.setMinutes(start.getMinutes() + 5);
  } else {
    start.setMinutes(start.getMinutes() + 60);
  }

  const end = new Date(start.getTime());
  end.setMinutes(end.getMinutes() + 30);

  composeState.startsLocal = isoToLocalInput(start.toISOString());
  composeState.endsLocal = isoToLocalInput(end.toISOString());
}

function resetComposeModal() {
  composeState.callId = '';
  composeState.title = '';
  composeState.roomId = 'lobby';
  composeState.submitting = false;
  composeState.error = '';
}

function openCompose(mode, call = null) {
  clearNotice();
  closeInvitePopover();
  resetComposeModal();
  composeState.mode = mode;
  composeState.open = true;

  if (mode === 'edit' && call) {
    composeState.callId = String(call.id || '');
    composeState.title = String(call.title || '');
    composeState.roomId = String(call.room_id || 'lobby');
    composeState.startsLocal = isoToLocalInput(String(call.starts_at || ''));
    composeState.endsLocal = isoToLocalInput(String(call.ends_at || ''));
  } else {
    seedComposeWindow(mode);
  }
}

function closeCompose() {
  composeState.open = false;
  composeState.submitting = false;
  composeState.error = '';
}

async function submitCompose() {
  composeState.error = '';
  clearNotice();

  const title = composeState.title.trim();
  if (title === '') {
    composeState.error = 'Title is required.';
    return;
  }

  const startsAt = localInputToIso(composeState.startsLocal);
  const endsAt = localInputToIso(composeState.endsLocal);
  if (startsAt === '' || endsAt === '') {
    composeState.error = 'Start and end timestamps are required.';
    return;
  }

  if (new Date(endsAt).getTime() <= new Date(startsAt).getTime()) {
    composeState.error = 'End timestamp must be after start timestamp.';
    return;
  }

  const payload = {
    room_id: String(composeState.roomId || '').trim() || 'lobby',
    title,
    starts_at: startsAt,
    ends_at: endsAt,
  };

  composeState.submitting = true;

  try {
    if (composeState.mode === 'edit') {
      const callId = encodeURIComponent(composeState.callId);
      await apiRequest(`/api/calls/${callId}`, {
        method: 'PATCH',
        body: payload,
      });
      setNotice('ok', 'Call updated.');
    } else {
      await apiRequest('/api/calls', {
        method: 'POST',
        body: payload,
      });
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

function handleDocumentPointerDown(event) {
  if (!invitePopover.open) {
    return;
  }

  const target = event.target;
  const popoverNode = invitePopoverRef.value;
  if (popoverNode instanceof HTMLElement && popoverNode.contains(target)) {
    return;
  }

  const trigger = invitePopover.triggerElement;
  if (trigger instanceof HTMLElement && trigger.contains(target)) {
    return;
  }

  closeInvitePopover();
}

function handleWindowResize() {
  if (!invitePopover.open) return;

  const trigger = invitePopover.triggerElement;
  if (trigger instanceof HTMLElement) {
    placeInvitePopover(trigger);
  }
}

function handleEscape(event) {
  if (event.key !== 'Escape') return;

  if (joinState.open) {
    closeJoinModal();
    return;
  }

  if (composeState.open) {
    closeCompose();
    return;
  }

  if (invitePopover.open) {
    closeInvitePopover();
  }
}

onMounted(() => {
  document.addEventListener('pointerdown', handleDocumentPointerDown, true);
  window.addEventListener('resize', handleWindowResize);
  window.addEventListener('scroll', handleWindowResize, true);
  window.addEventListener('keydown', handleEscape);

  void Promise.all([loadCalls(), loadCalendar()]);
});

onBeforeUnmount(() => {
  document.removeEventListener('pointerdown', handleDocumentPointerDown, true);
  window.removeEventListener('resize', handleWindowResize);
  window.removeEventListener('scroll', handleWindowResize, true);
  window.removeEventListener('keydown', handleEscape);
});
</script>

<style scoped>
.calls-view {
  min-height: 0;
  display: grid;
  grid-template-rows: auto auto auto minmax(0, 1fr) auto;
  background: var(--border-subtle);
  gap: 1px;
}

.calls-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
}

.calls-header h3 {
  margin: 0;
  font-size: 18px;
}

.calls-header p {
  margin: 4px 0 0;
  color: var(--text-muted);
  font-size: 12px;
}

.calls-toolbar {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: 8px;
}

.calls-banner {
  font-size: 12px;
  color: #ffffff;
}

.calls-banner.ok {
  background: #1f4f31;
}

.calls-banner.error {
  background: #4f1f1f;
}

.calls-table-wrap {
  min-height: 0;
}

.col-title {
  width: 28%;
}

.col-actions {
  width: 150px;
}

.call-title {
  font-weight: 700;
}

.call-subline {
  display: block;
  margin-top: 2px;
  color: #c7d7f2;
  font-size: 11px;
}

.calls-empty {
  border-top: 1px solid var(--border-subtle);
  color: var(--text-muted);
  font-size: 12px;
}

.calls-error {
  color: #ff9f9f;
}

.calls-calendar-wrap {
  padding: 10px;
  background: var(--bg-surface);
}

.calls-calendar-grid {
  min-height: 0;
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
  gap: 10px;
  align-content: start;
}

.calls-calendar-day {
  border: 1px solid var(--border-subtle);
  border-radius: 6px;
  background: #122340;
  overflow: hidden;
}

.calls-calendar-day-head {
  padding: 10px;
  border-bottom: 1px solid var(--border-subtle);
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 8px;
}

.calls-calendar-day-head h4 {
  margin: 0;
  font-size: 13px;
}

.calls-calendar-day-head span {
  color: var(--text-muted);
  font-size: 12px;
}

.calls-calendar-list {
  margin: 0;
  padding: 0;
  list-style: none;
  display: grid;
}

.calls-calendar-item {
  border-bottom: 1px solid var(--border-subtle);
  padding: 10px;
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: 10px;
  align-items: center;
}

.calls-calendar-item:last-child {
  border-bottom: 0;
}

.calls-calendar-item-meta {
  min-width: 0;
  display: grid;
  gap: 2px;
}

.calls-calendar-item-meta strong {
  font-size: 13px;
}

.calls-calendar-item-meta span {
  color: var(--text-muted);
  font-size: 12px;
}

.calls-pagination-wrap {
  border-top: 1px solid var(--border-subtle);
}

.calls-modal {
  position: fixed;
  inset: 0;
  z-index: 70;
  display: grid;
  place-items: center;
}

.calls-modal[hidden] {
  display: none;
}

.calls-modal-backdrop {
  position: absolute;
  inset: 0;
  background: #09111e;
}

.calls-modal-dialog {
  position: relative;
  width: min(1020px, calc(100vw - 30px));
  max-height: calc(100vh - 30px);
  overflow: auto;
  border: 1px solid var(--border-subtle);
  border-radius: 8px;
  background: var(--bg-surface-strong);
  box-shadow: 0 16px 32px #000000;
  padding: 12px;
  display: grid;
  gap: 12px;
}

.calls-modal-dialog-small {
  width: min(620px, calc(100vw - 30px));
}

.calls-modal-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 10px;
}

.calls-modal-header h4 {
  margin: 0;
  font-size: 17px;
}

.calls-modal-body {
  display: grid;
  gap: 10px;
}

.calls-modal-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px;
}

.calls-inline-error {
  border: 1px solid #6b1f1f;
  border-radius: 6px;
  background: #331616;
  color: #ffb5b5;
  font-size: 12px;
  padding: 8px 10px;
}

.calls-inline-hint {
  margin: 0;
  border: 1px solid var(--border-subtle);
  border-radius: 6px;
  background: #132745;
  color: var(--text-muted);
  font-size: 12px;
  padding: 8px 10px;
}

.calls-modal-footer {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
}

.invite-popover {
  position: fixed;
  z-index: 75;
  width: 320px;
  padding: 10px;
  border: 1px solid var(--border-subtle);
  border-radius: 6px;
  background: #13213a;
  box-shadow: 0 16px 32px #000000;
  display: grid;
  gap: 8px;
}

.invite-popover[hidden] {
  display: none;
}

.invite-popover-label {
  margin: 0;
  font-size: 12px;
  color: var(--text-main);
}

.invite-popover-row {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: 8px;
  align-items: center;
}

.invite-code {
  display: block;
  width: 100%;
  border: 1px solid var(--border-subtle);
  border-radius: 6px;
  background: #08111f;
  color: #e6f0ff;
  padding: 8px 10px;
  font-size: 12px;
  overflow: auto;
}

.invite-popover-close {
  border: 1px solid var(--border-subtle);
  border-radius: 6px;
  background: transparent;
  color: var(--text-main);
  min-width: 74px;
  height: 32px;
}

.invite-popover-close:hover {
  background: rgba(255, 255, 255, 0.06);
}

@media (max-width: 1180px) {
  .calls-toolbar {
    grid-template-columns: 1fr;
  }

  .calls-modal-grid {
    grid-template-columns: 1fr;
  }

  .calls-calendar-grid {
    grid-template-columns: 1fr;
  }
}
</style>
