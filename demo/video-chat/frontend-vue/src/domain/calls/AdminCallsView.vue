<template>
  <section class="view-card calls-view">
    <section class="section calls-header">
      <div class="calls-header-left">
        <button
          v-if="showInlineSidebarButton"
          class="show-sidebar-overlay show-sidebar-inline show-left-sidebar-overlay calls-show-sidebar-btn"
          type="button"
          title="Show sidebar"
          aria-label="Show sidebar"
          @click="showLeftSidebarFromHeader"
        >
          <img class="arrow-icon-image" src="/assets/orgas/kingrt/icons/forward.png" alt="" />
        </button>
        <h3>Video Call Management</h3>
      </div>
      <div class="actions">
        <button class="btn" type="button" @click="openPrimaryCompose">{{ primaryActionLabel }}</button>
      </div>
    </section>

    <section class="toolbar calls-toolbar">
      <div class="calls-toolbar-left">
        <div class="calls-view-tabs" role="tablist" aria-label="Calls view mode">
          <button
            class="tab"
            :class="{ active: viewMode === 'calls' }"
            type="button"
            role="tab"
            :aria-selected="viewMode === 'calls'"
            @click="setViewMode('calls')"
          >
            Calls
          </button>
          <button
            class="tab"
            :class="{ active: viewMode === 'calendar' }"
            type="button"
            role="tab"
            :aria-selected="viewMode === 'calendar'"
            @click="setViewMode('calendar')"
          >
            Calendar
          </button>
        </div>
      </div>

      <div class="calls-toolbar-right">
        <label class="calls-search" aria-label="Call search">
          <input
            v-model="queryDraft"
            class="input"
            type="search"
            placeholder="Search call title"
            @keydown.enter.prevent="applyFilters"
          />
          <button class="btn" type="button" @click="applyFilters">Search</button>
        </label>

        <select v-model="statusFilter" class="select" @change="applyFilters">
          <option value="all">All status</option>
          <option value="scheduled">Scheduled</option>
          <option value="active">Active</option>
          <option value="ended">Ended</option>
          <option value="cancelled">Cancelled</option>
        </select>

        <select v-model="scopeFilter" class="select" @change="applyFilters">
          <option value="all">All scope</option>
          <option value="my">My scope</option>
        </select>
      </div>
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
                <button
                  class="icon-mini-btn danger"
                  type="button"
                  title="Cancel call"
                  :aria-label="`Cancel call ${call.title || call.id}`"
                  :disabled="!isCancellable(call)"
                  @click="openCancel(call)"
                >
                  <img src="/assets/orgas/kingrt/icons/end_call.png" alt="" />
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
        Loading calls…
      </section>
      <section v-if="callsError" class="section calls-empty calls-error">
        {{ callsError }}
      </section>
    </section>

    <section v-else class="table-wrap calls-calendar-wrap">
      <section v-if="loadingCalendar" class="section calls-empty">
        Loading calendar view…
      </section>
      <section v-else-if="calendarError" class="section calls-empty calls-error">
        {{ calendarError }}
      </section>
      <section v-else ref="callsCalendarEl" class="calls-calendar-full"></section>
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
      <p v-if="invitePopover.loading" class="invite-popover-label">Generating invite code…</p>
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
            <label v-if="composeState.mode !== 'create'" class="field">
              <span>Room ID</span>
              <input v-model="composeState.roomId" class="input" type="text" placeholder="lobby" />
            </label>
            <label v-if="composeState.mode !== 'create'" class="field">
              <span>Starts at</span>
              <input
                v-model="composeState.startsLocal"
                class="input"
                type="datetime-local"
                aria-label="Call starts at"
              />
            </label>
            <label v-if="composeState.mode !== 'create'" class="field">
              <span>Ends at</span>
              <input
                v-model="composeState.endsLocal"
                class="input"
                type="datetime-local"
                aria-label="Call ends at"
              />
            </label>
          </section>

          <section v-if="composeState.mode === 'edit'" class="calls-toggle-row">
            <label class="calls-checkbox-row">
              <input v-model="composeState.replaceParticipants" type="checkbox" />
              <span>Replace participant list during edit</span>
            </label>
          </section>

          <section v-if="shouldSendParticipants" class="calls-participants-grid">
            <article class="calls-participants-panel">
              <header class="calls-participants-head">
                <h5>Registered users</h5>
                <label class="calls-search small" aria-label="Participant search">
                  <input
                    v-model="composeParticipants.query"
                    class="input"
                    type="search"
                    placeholder="Search users"
                    @keydown.enter.prevent="applyParticipantSearch"
                  />
                  <button class="btn" type="button" @click="applyParticipantSearch">Search</button>
                </label>
              </header>

              <section v-if="composeParticipants.error" class="calls-inline-error">
                {{ composeParticipants.error }}
              </section>

              <section class="calls-participants-list" :class="{ loading: composeParticipants.loading }">
                <label
                  v-for="user in composeParticipants.rows"
                  :key="user.id"
                  class="calls-participant-row"
                >
                  <input
                    type="checkbox"
                    :checked="isUserSelected(user.id)"
                    @change="toggleUserSelection(user.id)"
                  />
                  <span class="calls-participant-main">{{ user.display_name || user.email }}</span>
                  <span class="calls-participant-meta">{{ user.email }} · {{ user.role }}</span>
                </label>
                <p v-if="!composeParticipants.loading && composeParticipants.rows.length === 0" class="calls-empty-inline">
                  No users in this page.
                </p>
              </section>

              <div class="pagination">
                <button
                  class="pager-btn pager-icon-btn"
                  type="button"
                  :disabled="!composeParticipants.hasPrev || composeParticipants.loading"
                  @click="goToParticipantPage(composeParticipants.page - 1)"
                >
                  <img class="pager-icon-img" src="/assets/orgas/kingrt/icons/backward.png" alt="Previous" />
                </button>
                <div class="page-info">
                  Page {{ composeParticipants.page }} / {{ composeParticipants.pageCount }}
                </div>
                <button
                  class="pager-btn pager-icon-btn"
                  type="button"
                  :disabled="!composeParticipants.hasNext || composeParticipants.loading"
                  @click="goToParticipantPage(composeParticipants.page + 1)"
                >
                  <img class="pager-icon-img" src="/assets/orgas/kingrt/icons/forward.png" alt="Next" />
                </button>
              </div>
            </article>

            <article class="calls-participants-panel">
              <header class="calls-participants-head">
                <h5>External participants</h5>
                <button class="btn" type="button" @click="addExternalRow">Add row</button>
              </header>

              <section class="calls-external-list">
                <div v-for="(row, index) in composeExternalRows" :key="row.id" class="calls-external-row">
                  <input
                    v-model="row.display_name"
                    class="input"
                    type="text"
                    placeholder="Display name"
                    :aria-label="`External participant ${index + 1} display name`"
                  />
                  <input
                    v-model="row.email"
                    class="input"
                    type="email"
                    placeholder="guest@example.com"
                    :aria-label="`External participant ${index + 1} email`"
                  />
                  <button
                    class="icon-mini-btn danger"
                    type="button"
                    title="Remove external participant"
                    :aria-label="`Remove external participant row ${index + 1}`"
                    @click="removeExternalRow(index)"
                  >
                    <img src="/assets/orgas/kingrt/icons/remove_user.png" alt="" />
                  </button>
                </div>
                <p v-if="composeExternalRows.length === 0" class="calls-empty-inline">No external participants configured.</p>
              </section>
            </article>
          </section>

          <section v-else class="calls-inline-hint">
            Existing participants remain unchanged for this edit.
          </section>

          <section v-if="composeState.error" class="calls-inline-error">
            {{ composeState.error }}
          </section>
        </div>

        <footer class="calls-modal-footer">
          <button class="btn" type="button" :disabled="composeState.submitting" @click="closeCompose">Close</button>
          <button class="btn" type="button" :disabled="composeState.submitting" @click="submitCompose">
            {{ composeState.submitting ? 'Saving…' : composeSubmitLabel }}
          </button>
        </footer>
      </div>
    </div>

    <div class="calls-modal" :hidden="!cancelState.open" role="dialog" aria-modal="true" aria-label="Cancel call modal">
      <div class="calls-modal-backdrop" @click="closeCancel"></div>
      <div class="calls-modal-dialog calls-modal-dialog-small">
        <header class="calls-modal-header">
          <h4>Cancel call</h4>
          <button class="icon-mini-btn" type="button" aria-label="Close" @click="closeCancel">
            <img src="/assets/orgas/kingrt/icons/cancel.png" alt="" />
          </button>
        </header>

        <div class="calls-modal-body">
          <p class="calls-inline-hint">
            Cancelling <strong>{{ cancelState.callTitle }}</strong> marks all participants as cancelled.
          </p>

          <label class="field">
            <span>Cancel reason</span>
            <input
              v-model="cancelState.reason"
              class="input"
              type="text"
              placeholder="scheduler_conflict"
              aria-label="Cancel reason"
            />
          </label>

          <label class="field">
            <span>Cancel message</span>
            <textarea
              v-model="cancelState.message"
              class="calls-textarea"
              rows="4"
              placeholder="Call cancelled due to scheduling conflict."
              aria-label="Cancel message"
            ></textarea>
          </label>

          <section v-if="cancelState.error" class="calls-inline-error">
            {{ cancelState.error }}
          </section>
        </div>

        <footer class="calls-modal-footer">
          <button class="btn" type="button" :disabled="cancelState.submitting" @click="closeCancel">Close</button>
          <button class="btn" type="button" :disabled="cancelState.submitting" @click="submitCancel">
            {{ cancelState.submitting ? 'Cancelling…' : 'Cancel call' }}
          </button>
        </footer>
      </div>
    </div>
  </section>
</template>

<script setup>
import { computed, inject, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { Calendar } from '@fullcalendar/core';
import dayGridPlugin from '@fullcalendar/daygrid';
import timeGridPlugin from '@fullcalendar/timegrid';
import interactionPlugin from '@fullcalendar/interaction';
import { sessionState } from '../auth/session';
import { resolveBackendOrigin } from '../../support/backendOrigin';

const router = useRouter();
const workspaceSidebarState = inject('workspaceSidebarState', null);

const backendOrigin = resolveBackendOrigin();
const callsCalendarEl = ref(null);
let calendarInstance = null;
let lastCalendarDateClickAt = 0;
let lastCalendarDateKey = '';

function requestHeaders(withBody = false) {
  const headers = { accept: 'application/json' };
  if (withBody) {
    headers['content-type'] = 'application/json';
  }

  const token = String(sessionState.sessionToken || '').trim();
  if (token !== '') {
    headers.authorization = `Bearer ${token}`;
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
  let response = null;
  try {
    response = await fetch(endpoint, {
      method,
      headers: requestHeaders(body !== null),
      body: body === null ? undefined : JSON.stringify(body),
    });
  } catch (error) {
    const message = error instanceof Error ? error.message.trim() : '';
    if (message === '' || /failed to fetch/i.test(message)) {
      throw new Error(`Could not reach backend (${backendOrigin}).`);
    }
    throw new Error(message);
  }

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
  return `${formatDateTime(startsAt)} → ${formatDateTime(endsAt)}`;
}

function statusTagClass(status) {
  const normalized = String(status || '').toLowerCase();
  if (normalized === 'scheduled' || normalized === 'active') return 'ok';
  if (normalized === 'ended') return 'warn';
  if (normalized === 'cancelled') return 'danger';
  return 'warn';
}

const showInlineSidebarButton = computed(() => {
  const collapsed = Boolean(workspaceSidebarState?.leftSidebarCollapsed?.value);
  const isTablet = Boolean(workspaceSidebarState?.isTabletViewport?.value);
  const isMobile = Boolean(workspaceSidebarState?.isMobileViewport?.value);
  return collapsed && !isTablet && !isMobile;
});

function showLeftSidebarFromHeader() {
  if (typeof workspaceSidebarState?.showLeftSidebar === 'function') {
    workspaceSidebarState.showLeftSidebar();
  }
}

function isEditable(call) {
  const status = String(call?.status || '').toLowerCase();
  return status !== 'cancelled' && status !== 'ended';
}

function isCancellable(call) {
  const status = String(call?.status || '').toLowerCase();
  return status !== 'cancelled' && status !== 'ended';
}

function isInvitable(call) {
  const status = String(call?.status || '').toLowerCase();
  return status !== 'cancelled' && status !== 'ended';
}

const viewMode = ref('calls');
const queryDraft = ref('');
const queryApplied = ref('');
const statusFilter = ref('all');
const scopeFilter = ref('all');
const primaryActionLabel = computed(() => (viewMode.value === 'calendar'
  ? 'Schedule video call'
  : 'New video call'));

function openPrimaryCompose() {
  openCompose(viewMode.value === 'calendar' ? 'schedule' : 'create');
}

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

function toCalendarEvents() {
  const events = [];
  for (const call of calendarCalls.value) {
    const startsAt = new Date(String(call?.starts_at || ''));
    const endsAt = new Date(String(call?.ends_at || ''));
    if (Number.isNaN(startsAt.getTime()) || Number.isNaN(endsAt.getTime())) {
      continue;
    }

    events.push({
      id: String(call.id || ''),
      title: String(call.title || call.id || 'Video call'),
      start: startsAt,
      end: endsAt,
      allDay: false,
      extendedProps: {
        callPayload: call,
      },
    });
  }
  return events;
}

function syncCalendarEvents() {
  if (!calendarInstance) return;
  calendarInstance.removeAllEvents();
  for (const event of toCalendarEvents()) {
    calendarInstance.addEvent(event);
  }
}

function openComposeForCalendarDoubleClick(dateValue) {
  const start = dateValue instanceof Date ? new Date(dateValue.getTime()) : new Date();
  const end = new Date(start.getTime() + (45 * 60 * 1000));
  openCompose('schedule');
  composeState.startsLocal = isoToLocalInput(start.toISOString());
  composeState.endsLocal = isoToLocalInput(end.toISOString());
}

function openComposeForCalendarSelection(startValue, endValue) {
  const start = startValue instanceof Date ? startValue : new Date(startValue);
  const end = endValue instanceof Date ? endValue : new Date(endValue);
  if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime())) return;
  openCompose('schedule');
  composeState.startsLocal = isoToLocalInput(start.toISOString());
  composeState.endsLocal = isoToLocalInput(end.toISOString());
}

function openComposeForCalendarEvent(eventApi) {
  const call = eventApi?.extendedProps?.callPayload;
  if (call && typeof call === 'object') {
    openCompose('edit', call);
  }
}

async function initCallsCalendar() {
  if (!(callsCalendarEl.value instanceof HTMLElement) || calendarInstance) return;
  try {
    calendarInstance = new Calendar(callsCalendarEl.value, {
      plugins: [dayGridPlugin, timeGridPlugin, interactionPlugin],
      initialView: 'dayGridMonth',
      headerToolbar: {
        left: 'prev,next today',
        center: 'title',
        right: 'dayGridMonth,timeGridWeek,timeGridDay',
      },
      height: 'auto',
      contentHeight: 'auto',
      eventTimeFormat: { hour: '2-digit', minute: '2-digit', hour12: false },
      selectable: true,
      editable: false,
      events: [],
      dateClick(info) {
        const now = Date.now();
        const dateKey = `${String(info.view?.type || '')}:${info.dateStr}`;
        const isDoubleClick = dateKey === lastCalendarDateKey && now - lastCalendarDateClickAt < 360;
        lastCalendarDateKey = dateKey;
        lastCalendarDateClickAt = now;
        if (!isDoubleClick) return;
        openComposeForCalendarDoubleClick(info.date instanceof Date ? info.date : new Date(info.dateStr));
      },
      select(info) {
        if (String(info.view?.type || '') !== 'timeGridDay') return;
        openComposeForCalendarSelection(info.start, info.end);
        calendarInstance?.unselect();
      },
      eventClick(info) {
        openComposeForCalendarEvent(info.event);
      },
    });
    calendarInstance.render();
    syncCalendarEvents();
  } catch {
    calendarInstance = null;
    if (!calendarError.value) {
      calendarError.value = 'Could not load FullCalendar.';
    }
  }
}

function setViewMode(nextMode) {
  if (nextMode !== 'calls' && nextMode !== 'calendar') {
    return;
  }

  viewMode.value = nextMode;
  if (nextMode === 'calendar' && calendarCalls.value.length === 0 && !loadingCalendar.value) {
    void loadCalendar();
  }
}

async function ensureCalendarUiReady() {
  if (viewMode.value !== 'calendar') return;
  if (loadingCalendar.value || calendarError.value) return;
  await nextTick();
  await initCallsCalendar();
  if (!calendarInstance) return;
  await nextTick();
  calendarInstance.updateSize();
  syncCalendarEvents();
}

watch(viewMode, () => {
  void ensureCalendarUiReady();
});

watch(loadingCalendar, () => {
  void ensureCalendarUiReady();
});

watch(calendarError, () => {
  void ensureCalendarUiReady();
});

watch(calendarCalls, () => {
  syncCalendarEvents();
  void ensureCalendarUiReady();
});

async function applyFilters() {
  clearNotice();
  queryApplied.value = queryDraft.value.trim();
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

const composeState = reactive({
  open: false,
  mode: 'create',
  callId: '',
  title: '',
  roomId: 'lobby',
  startsLocal: '',
  endsLocal: '',
  replaceParticipants: false,
  submitting: false,
  error: '',
});

const composeParticipants = reactive({
  loading: false,
  error: '',
  query: '',
  page: 1,
  pageSize: 10,
  total: 0,
  pageCount: 1,
  hasPrev: false,
  hasNext: false,
  rows: [],
});

const composeSelectedUserIds = ref([]);
const composeExternalRows = ref([]);
let composeExternalRowId = 0;

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

function seedComposeWindow(mode) {
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
  composeState.roomId = 'lobby';
  composeState.replaceParticipants = false;
  composeState.submitting = false;
  composeState.error = '';
  composeParticipants.query = '';
  composeParticipants.page = 1;
  composeParticipants.error = '';
  composeParticipants.rows = [];
  composeParticipants.total = 0;
  composeParticipants.pageCount = 1;
  composeParticipants.hasPrev = false;
  composeParticipants.hasNext = false;
  composeSelectedUserIds.value = [];
  composeExternalRows.value = [];
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
    composeState.replaceParticipants = false;
  } else {
    if (mode !== 'create') {
      seedComposeWindow(mode);
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

    composeParticipants.rows = Array.isArray(payload.users) ? payload.users : [];
    const paging = payload.pagination || {};
    composeParticipants.total = Number.isInteger(paging.total) ? paging.total : composeParticipants.rows.length;
    composeParticipants.pageCount = Number.isInteger(paging.page_count) && paging.page_count > 0
      ? paging.page_count
      : 1;
    composeParticipants.hasPrev = Boolean(paging.has_prev);
    composeParticipants.hasNext = Boolean(paging.has_next);
  } catch (error) {
    composeParticipants.rows = [];
    composeParticipants.total = 0;
    composeParticipants.pageCount = 1;
    composeParticipants.hasPrev = false;
    composeParticipants.hasNext = false;
    composeParticipants.error = error instanceof Error ? error.message : 'Could not load users.';
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
  if (!Number.isInteger(id) || id <= 0) {
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
    room_id: String(composeState.roomId || '').trim() || 'lobby',
    title,
    starts_at: startsAt,
    ends_at: endsAt,
  };

  if (shouldSendParticipants.value) {
    const normalizedExternal = normalizeExternalRows();
    if (!normalizedExternal.ok) {
      composeState.error = normalizedExternal.error;
      return;
    }

    payload.internal_participant_user_ids = composeSelectedUserIds.value.slice();
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
    } else {
      const createResult = await apiRequest('/api/calls', {
        method: 'POST',
        body: payload,
      });
      const createdRoomId = String(createResult?.result?.call?.room_id || payload.room_id || 'lobby').trim() || 'lobby';
      if (composeState.mode === 'create') {
        closeCompose();
        router.push(`/workspace/call/${encodeURIComponent(createdRoomId)}`);
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

const cancelState = reactive({
  open: false,
  submitting: false,
  error: '',
  callId: '',
  callTitle: '',
  reason: '',
  message: '',
});

function openCancel(call) {
  clearNotice();
  closeInvitePopover();
  cancelState.open = true;
  cancelState.submitting = false;
  cancelState.error = '';
  cancelState.callId = String(call?.id || '');
  cancelState.callTitle = String(call?.title || call?.id || '');
  cancelState.reason = 'scheduler_conflict';
  cancelState.message = 'Call cancelled due to scheduling conflict.';
}

function closeCancel() {
  cancelState.open = false;
  cancelState.submitting = false;
  cancelState.error = '';
}

async function submitCancel() {
  cancelState.error = '';
  clearNotice();

  const reason = cancelState.reason.trim();
  const message = cancelState.message.trim();
  if (reason === '' || message === '') {
    cancelState.error = 'Cancel reason and message are required.';
    return;
  }

  cancelState.submitting = true;

  try {
    await apiRequest(`/api/calls/${encodeURIComponent(cancelState.callId)}/cancel`, {
      method: 'POST',
      body: {
        cancel_reason: reason,
        cancel_message: message,
      },
    });

    closeCancel();
    setNotice('ok', 'Call cancelled.');
    await Promise.all([loadCalls(), loadCalendar()]);
  } catch (error) {
    cancelState.error = error instanceof Error ? error.message : 'Could not cancel call.';
  } finally {
    cancelState.submitting = false;
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

  if (composeState.open) {
    closeCompose();
    return;
  }

  if (cancelState.open) {
    closeCancel();
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
  if (calendarInstance) {
    calendarInstance.destroy();
    calendarInstance = null;
  }
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
  border: 0;
  border-top-right-radius: 5px;
}

.calls-header-left {
  min-width: 0;
  display: inline-flex;
  align-items: center;
  gap: 10px;
}

.calls-show-sidebar-btn {
  display: grid;
  margin-top: 2px;
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
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  flex-wrap: wrap;
}

.calls-toolbar-left {
  min-width: 0;
}

.calls-view-tabs {
  display: inline-grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 1px;
  background: var(--border-subtle);
}

.calls-view-tabs .tab {
  min-width: 120px;
  height: 40px;
}

.calls-toolbar-right {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.calls-search {
  display: inline-grid;
  grid-template-columns: minmax(220px, 1fr) auto;
  gap: 8px;
  align-items: center;
}

.calls-search.small {
  grid-template-columns: minmax(0, 1fr) auto;
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

.calls-calendar-full {
  min-height: 620px;
  border: 1px solid var(--border-subtle);
  background: var(--bg-surface-strong);
  padding: 10px;
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

.calls-toggle-row {
  display: flex;
  align-items: center;
}

.calls-checkbox-row {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  color: var(--text-main);
  font-size: 12px;
}

.calls-participants-grid {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
  gap: 10px;
}

.calls-participants-panel {
  border: 1px solid var(--border-subtle);
  border-radius: 6px;
  background: #122340;
  padding: 10px;
  min-height: 0;
  display: grid;
  gap: 10px;
  align-content: start;
}

.calls-participants-head {
  display: grid;
  gap: 8px;
}

.calls-participants-head h5 {
  margin: 0;
  font-size: 13px;
}

.calls-participants-list {
  border: 1px solid var(--border-subtle);
  border-radius: 6px;
  background: #0f1f37;
  max-height: 280px;
  overflow: auto;
  display: grid;
  align-content: start;
}

.calls-participants-list.loading {
  opacity: 0.7;
}

.calls-participant-row {
  padding: 8px 10px;
  border-bottom: 1px solid var(--border-subtle);
  display: grid;
  grid-template-columns: auto minmax(0, 1fr);
  column-gap: 8px;
  align-items: start;
}

.calls-participant-row:last-child {
  border-bottom: 0;
}

.calls-participant-main {
  font-size: 12px;
  color: #ffffff;
}

.calls-participant-meta {
  grid-column: 2;
  font-size: 11px;
  color: var(--text-muted);
}

.calls-external-list {
  border: 1px solid var(--border-subtle);
  border-radius: 6px;
  background: #0f1f37;
  max-height: 280px;
  overflow: auto;
  padding: 8px;
  display: grid;
  gap: 8px;
  align-content: start;
}

.calls-external-row {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) auto;
  gap: 8px;
  align-items: center;
}

.calls-empty-inline {
  margin: 0;
  padding: 8px 10px;
  color: var(--text-muted);
  font-size: 12px;
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

.calls-textarea {
  width: 100%;
  border: 1px solid var(--border-subtle);
  border-radius: 6px;
  background: var(--bg-input);
  color: #0a1322;
  padding: 8px 10px;
  resize: vertical;
}

@media (max-width: 1180px) {
  .calls-toolbar {
    align-items: stretch;
  }

  .calls-toolbar-right {
    width: 100%;
  }

  .calls-search {
    grid-template-columns: minmax(0, 1fr) auto;
    width: 100%;
  }

  .calls-modal-grid,
  .calls-participants-grid {
    grid-template-columns: 1fr;
  }

  .calls-calendar-full {
    min-height: 520px;
  }
}
</style>
