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
                  title="Enter video call"
                  :aria-label="`Enter video call ${call.title || call.id}`"
                  :disabled="!isInvitable(call)"
                  @click="openEnterCallModal(call)"
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
                  title="Enter video call"
                  :disabled="!isInvitable(call)"
                  @click="openEnterCallModal(call)"
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

    <div class="calls-modal" :hidden="!enterCallState.open" role="dialog" aria-modal="true" aria-label="Enter video call">
      <div class="calls-modal-backdrop" @click="closeEnterCallModal"></div>
      <div class="calls-modal-dialog calls-modal-dialog-enter">
        <header class="calls-modal-header">
          <h4>Enter Video Call</h4>
          <button class="icon-mini-btn" type="button" aria-label="Close" @click="closeEnterCallModal">
            <img src="/assets/orgas/kingrt/icons/cancel.png" alt="" />
          </button>
        </header>

        <div class="calls-modal-body calls-enter-body">
          <div class="calls-enter-layout" :class="{ 'panel-open': enterCallState.panelOpen }">
            <section class="calls-enter-preview">
              <div class="calls-enter-preview-head">
                <span>Camera Preview</span>
                <span class="calls-enter-preview-meta">{{ enterCallState.callId }}</span>
              </div>
              <div class="calls-enter-preview-frame">
                <video ref="enterCallPreviewVideoRef" autoplay playsinline muted></video>
                <p v-if="enterCallState.previewError" class="calls-inline-error">{{ enterCallState.previewError }}</p>
                <p v-else-if="!enterCallState.previewReady" class="calls-inline-hint">Preparing preview...</p>
              </div>
            </section>

            <button
              class="calls-enter-panel-toggle"
              type="button"
              :aria-label="enterCallState.panelOpen ? 'Close settings panel' : 'Open settings panel'"
              @click="toggleEnterCallPanel"
            >
              <img
                :src="enterCallState.panelOpen
                  ? '/assets/orgas/kingrt/icons/forward.png'
                  : '/assets/orgas/kingrt/icons/backward.png'"
                alt=""
              />
            </button>

            <section class="calls-enter-right">
              <section class="calls-enter-config-grid">
                <label class="field">
                  <span>Camera</span>
                  <select
                    class="input"
                    :value="callMediaPrefs.selectedCameraId"
                    @change="setCallCameraDevice($event.target.value)"
                  >
                    <option value="">{{ callMediaPrefs.cameras.length === 0 ? 'No camera detected' : 'Select camera' }}</option>
                    <option v-for="camera in callMediaPrefs.cameras" :key="camera.id" :value="camera.id">
                      {{ camera.label }}
                    </option>
                  </select>
                </label>

                <label class="field">
                  <span>Mic</span>
                  <select
                    class="input"
                    :value="callMediaPrefs.selectedMicrophoneId"
                    @change="setCallMicrophoneDevice($event.target.value)"
                  >
                    <option value="">{{ callMediaPrefs.microphones.length === 0 ? 'No microphone detected' : 'Select mic' }}</option>
                    <option v-for="microphone in callMediaPrefs.microphones" :key="microphone.id" :value="microphone.id">
                      {{ microphone.label }}
                    </option>
                  </select>
                </label>

                <label class="field">
                  <span>Mic volume</span>
                  <input
                    class="input"
                    type="range"
                    min="0"
                    max="100"
                    step="1"
                    :value="callMediaPrefs.microphoneVolume"
                    @input="setCallMicrophoneVolume($event.target.value)"
                  />
                </label>

                <label class="field">
                  <span>Speaker</span>
                  <select
                    class="input"
                    :value="callMediaPrefs.selectedSpeakerId"
                    @change="setCallSpeakerDevice($event.target.value)"
                  >
                    <option value="">{{ callMediaPrefs.speakers.length === 0 ? 'No speaker detected' : 'Select speaker' }}</option>
                    <option v-for="speaker in callMediaPrefs.speakers" :key="speaker.id" :value="speaker.id">
                      {{ speaker.label }}
                    </option>
                  </select>
                </label>

                <label class="field">
                  <span>Speaker volume</span>
                  <input
                    class="input"
                    type="range"
                    min="0"
                    max="100"
                    step="1"
                    :value="callMediaPrefs.speakerVolume"
                    @input="setCallSpeakerVolume($event.target.value)"
                  />
                </label>
              </section>

              <section class="calls-enter-invite">
                <p class="invite-popover-label">
                  Invite for <strong>{{ enterCallState.callId }}</strong>
                </p>
                <p v-if="enterCallState.loading" class="invite-popover-label">Generating invite code...</p>
                <p v-else-if="enterCallState.error" class="invite-popover-label calls-error">{{ enterCallState.error }}</p>
                <template v-else>
                  <div class="invite-popover-row">
                    <code class="invite-code">{{ enterCallState.code }}</code>
                    <button class="icon-mini-btn" type="button" title="Copy invite" @click="copyInviteCode">
                      <span class="icon-copy" aria-hidden="true"></span>
                    </button>
                  </div>
                  <p class="invite-popover-label">
                    Expires: {{ formatDateTime(enterCallState.expiresAt) }}
                  </p>
                  <p v-if="enterCallState.copyNotice" class="invite-popover-label">{{ enterCallState.copyNotice }}</p>
                </template>
              </section>
            </section>
          </div>
        </div>

        <footer class="calls-modal-footer">
          <button class="btn" type="button" :disabled="enterCallState.loading" @click="closeEnterCallModal">Close</button>
          <button
            class="btn"
            type="button"
            :disabled="enterCallState.loading"
            @click="openCallWorkspace({ callId: enterCallState.callId, roomId: enterCallState.roomId })"
          >
            Open call
          </button>
        </footer>
      </div>
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
              <span>Access mode</span>
              <select v-model="composeState.accessMode" class="input" aria-label="Call access mode">
                <option value="invite_only">Invite only</option>
                <option value="free_for_all">Free for all</option>
              </select>
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
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { sessionState } from '../auth/session';
import { currentBackendOrigin, fetchBackend } from '../../support/backendFetch';
import {
  attachCallMediaDeviceWatcher,
  callMediaPrefs,
  refreshCallMediaDevices,
  setCallCameraDevice,
  setCallMicrophoneDevice,
  setCallMicrophoneVolume,
  setCallSpeakerDevice,
  setCallSpeakerVolume,
} from '../realtime/callMediaPreferences';

const router = useRouter();

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

async function apiRequest(path, { method = 'GET', query = null, body = null } = {}) {
  let response = null;
  try {
    const result = await fetchBackend(path, {
      method,
      query,
      headers: requestHeaders(body !== null),
      body: body === null ? undefined : JSON.stringify(body),
    });
    response = result.response;
  } catch (error) {
    const message = error instanceof Error ? error.message.trim() : '';
    if (message === '' || /failed to fetch|socket|connection/i.test(message)) {
      throw new Error(`Could not reach backend (${currentBackendOrigin()}).`);
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

const enterCallPreviewVideoRef = ref(null);
const enterCallPreviewStreamRef = ref(null);
let detachCallMediaWatcher = null;

const enterCallState = reactive({
  open: false,
  loading: false,
  error: '',
  code: '',
  expiresAt: '',
  callId: '',
  roomId: '',
  copyNotice: '',
  previewReady: false,
  previewError: '',
  panelOpen: false,
});

function resetEnterCallState() {
  enterCallState.loading = false;
  enterCallState.error = '';
  enterCallState.code = '';
  enterCallState.expiresAt = '';
  enterCallState.callId = '';
  enterCallState.roomId = '';
  enterCallState.copyNotice = '';
  enterCallState.previewReady = false;
  enterCallState.previewError = '';
  enterCallState.panelOpen = false;
}

function toggleEnterCallPanel() {
  enterCallState.panelOpen = !enterCallState.panelOpen;
}

function stopEnterCallPreview() {
  const previewNode = enterCallPreviewVideoRef.value;
  if (previewNode instanceof HTMLVideoElement) {
    try {
      previewNode.pause();
    } catch {
      // ignore
    }
    previewNode.srcObject = null;
  }

  const stream = enterCallPreviewStreamRef.value;
  if (stream instanceof MediaStream) {
    for (const track of stream.getTracks()) {
      track.stop();
    }
  }
  enterCallPreviewStreamRef.value = null;
  enterCallState.previewReady = false;
}

function buildPreviewConstraints() {
  const cameraDeviceId = String(callMediaPrefs.selectedCameraId || '').trim();
  const microphoneDeviceId = String(callMediaPrefs.selectedMicrophoneId || '').trim();

  const video = cameraDeviceId === '' ? true : { deviceId: { exact: cameraDeviceId } };
  const audio = microphoneDeviceId === '' ? true : { deviceId: { exact: microphoneDeviceId } };

  return { video, audio };
}

async function startEnterCallPreview() {
  stopEnterCallPreview();
  enterCallState.previewReady = false;
  enterCallState.previewError = '';

  if (
    typeof navigator === 'undefined'
    || !navigator.mediaDevices
    || typeof navigator.mediaDevices.getUserMedia !== 'function'
  ) {
    enterCallState.previewError = 'Camera preview is not supported in this browser.';
    return;
  }

  try {
    const stream = await navigator.mediaDevices.getUserMedia(buildPreviewConstraints());
    enterCallPreviewStreamRef.value = stream;
    const volume = Math.max(0, Math.min(100, Number(callMediaPrefs.microphoneVolume || 100))) / 100;
    for (const track of stream.getAudioTracks()) {
      if (typeof track.applyConstraints === 'function') {
        track.applyConstraints({ volume }).catch(() => {});
      }
    }

    await nextTick();
    const previewNode = enterCallPreviewVideoRef.value;
    if (!(previewNode instanceof HTMLVideoElement)) {
      return;
    }

    previewNode.muted = true;
    previewNode.srcObject = stream;
    await previewNode.play().catch(() => {});
    enterCallState.previewReady = true;
  } catch (error) {
    const message = error instanceof Error ? error.message : 'Could not start camera preview.';
    enterCallState.previewError = message || 'Could not start camera preview.';
  }
}

function closeEnterCallModal() {
  enterCallState.open = false;
  enterCallState.panelOpen = false;
  resetEnterCallState();
  stopEnterCallPreview();
}

async function openEnterCallModal(call) {
  if (!call || !call.id || !isInvitable(call)) {
    return;
  }

  clearNotice();
  enterCallState.open = true;
  enterCallState.loading = true;
  enterCallState.error = '';
  enterCallState.code = '';
  enterCallState.expiresAt = '';
  enterCallState.callId = String(call.id);
  enterCallState.roomId = String(call.room_id || 'lobby');
  enterCallState.copyNotice = '';
  enterCallState.panelOpen = false;

  await refreshCallMediaDevices({ requestPermissions: true });
  await startEnterCallPreview();

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

    enterCallState.code = inviteCode.code;
    enterCallState.expiresAt = typeof inviteCode.expires_at === 'string' ? inviteCode.expires_at : '';
  } catch (error) {
    enterCallState.error = error instanceof Error ? error.message : 'Could not create invite code.';
  } finally {
    enterCallState.loading = false;
  }
}

async function copyInviteCode() {
  const code = String(enterCallState.code || '').trim();
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

    enterCallState.copyNotice = 'Copied.';
  } catch {
    enterCallState.copyNotice = 'Copy failed.';
  }
}

function resolveJoinTarget(joinContext) {
  const callAccess = joinContext?.call_access;
  const accessId = String(callAccess?.id || '').trim();
  const callId = String(joinContext?.call?.id || '').trim();
  const roomId = String(joinContext?.room?.id || joinContext?.call?.room_id || '').trim();
  return {
    accessId,
    callId,
    roomId: roomId === '' ? 'lobby' : roomId,
  };
}

async function resolveWorkspaceRouteSegment(target = null) {
  const normalizedTarget = target && typeof target === 'object' ? target : {};
  const explicitAccessId = String(normalizedTarget.accessId || '').trim();
  if (explicitAccessId !== '') {
    return explicitAccessId;
  }

  const callId = String(normalizedTarget.callId || '').trim();
  if (callId !== '') {
    try {
      const payload = await apiRequest(`/api/calls/${encodeURIComponent(callId)}/access-link`, {
        method: 'POST',
      });
      const accessId = String(payload?.result?.access_link?.id || '').trim();
      if (accessId !== '') {
        return accessId;
      }
    } catch {
      // Fallback to direct call id route if access-link endpoint is unavailable.
    }

    return callId;
  }

  const roomId = String(normalizedTarget.roomId || '').trim();
  return roomId === '' ? 'lobby' : roomId;
}

async function openCallWorkspace(target = null) {
  const routeSegment = await resolveWorkspaceRouteSegment(target);
  closeEnterCallModal();
  router.push(`/workspace/call/${encodeURIComponent(routeSegment)}`);
}

watch(
  () => [callMediaPrefs.selectedCameraId, callMediaPrefs.selectedMicrophoneId],
  () => {
    if (!enterCallState.open) return;
    void startEnterCallPreview();
  },
);

watch(
  () => callMediaPrefs.microphoneVolume,
  () => {
    const stream = enterCallPreviewStreamRef.value;
    if (!(stream instanceof MediaStream)) return;
    const volume = Math.max(0, Math.min(100, Number(callMediaPrefs.microphoneVolume || 100))) / 100;
    for (const track of stream.getAudioTracks()) {
      if (typeof track.applyConstraints === 'function') {
        track.applyConstraints({ volume }).catch(() => {});
      }
    }
  },
);

const joinState = reactive({
  open: false,
  submitting: false,
  error: '',
  code: '',
});

function openJoinModal() {
  clearNotice();
  closeEnterCallModal();
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
    const joinTarget = resolveJoinTarget(joinContext);
    const scope = String(joinContext?.scope || '');

    closeJoinModal();
    setNotice('ok', `Invite redeemed for ${scope || 'invite'} context.`);
    void openCallWorkspace(joinTarget);
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
  accessMode: 'invite_only',
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
  composeState.accessMode = 'invite_only';
  composeState.roomId = 'lobby';
  composeState.submitting = false;
  composeState.error = '';
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
    composeState.accessMode = String(call.access_mode || 'invite_only').trim() || 'invite_only';
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
    access_mode: String(composeState.accessMode || 'invite_only').trim() || 'invite_only',
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

  if (enterCallState.open) {
    if (enterCallState.panelOpen) {
      enterCallState.panelOpen = false;
      return;
    }
    closeEnterCallModal();
  }
}

onMounted(() => {
  detachCallMediaWatcher = attachCallMediaDeviceWatcher({ requestPermissions: false });
  window.addEventListener('keydown', handleEscape);

  void Promise.all([loadCalls(), loadCalendar()]);
});

onBeforeUnmount(() => {
  window.removeEventListener('keydown', handleEscape);
  if (typeof detachCallMediaWatcher === 'function') {
    detachCallMediaWatcher();
    detachCallMediaWatcher = null;
  }
  stopEnterCallPreview();
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

.calls-modal-dialog-enter {
  width: min(1220px, calc(100vw - 24px));
  height: min(840px, calc(100vh - 24px));
  max-height: calc(100vh - 24px);
  overflow: hidden;
  grid-template-rows: auto minmax(0, 1fr) auto;
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

.calls-enter-body {
  grid-template-rows: minmax(0, 1fr);
  min-height: 0;
  overflow: hidden;
}

.calls-enter-layout {
  position: relative;
  min-height: 0;
  height: 100%;
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(360px, 44%);
  gap: 12px;
}

.calls-enter-preview {
  min-height: 0;
  display: grid;
  grid-template-rows: auto minmax(0, 1fr);
  gap: 8px;
}

.calls-enter-preview-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  font-size: 12px;
  color: var(--text-main);
}

.calls-enter-preview-meta {
  color: var(--text-muted);
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
}

.calls-enter-panel-toggle {
  display: none;
  border: 0;
  border-radius: 50%;
  background: #133262;
  color: #f7f7f7;
  cursor: pointer;
}

.calls-enter-panel-toggle img {
  width: 18px;
  height: 18px;
  object-fit: contain;
  filter: brightness(0) invert(1);
}

.calls-enter-preview-frame {
  position: relative;
  width: min(100%, 560px);
  aspect-ratio: 1 / 1;
  min-height: 0;
  border: 1px solid var(--border-subtle);
  border-radius: 6px;
  background: #0b1324;
  overflow: hidden;
}

.calls-enter-preview-frame video {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
  transform: scaleX(-1);
}

.calls-enter-preview-frame .calls-inline-hint,
.calls-enter-preview-frame .calls-inline-error {
  position: absolute;
  left: 10px;
  right: 10px;
  bottom: 10px;
  margin: 0;
}

.calls-enter-right {
  min-height: 0;
  display: grid;
  grid-template-rows: auto minmax(0, 1fr);
  align-content: start;
  gap: 10px;
  overflow: auto;
  padding-right: 2px;
}

.calls-enter-config-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px;
}

.calls-enter-invite {
  border: 1px solid var(--border-subtle);
  border-radius: 6px;
  background: #0f1f37;
  padding: 10px;
  display: grid;
  gap: 8px;
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

  .calls-modal-dialog-enter {
    width: min(1120px, calc(100vw - 20px));
    height: min(820px, calc(100vh - 20px));
    max-height: calc(100vh - 20px);
  }

  .calls-enter-layout {
    grid-template-columns: minmax(0, 1fr);
    gap: 10px;
  }

  .calls-enter-preview-frame {
    width: 100%;
    aspect-ratio: 4 / 3;
    max-height: 52vh;
  }

  .calls-enter-panel-toggle {
    position: absolute;
    top: 50%;
    right: 10px;
    transform: translateY(-50%);
    z-index: 4;
    width: 36px;
    height: 36px;
    display: grid;
    place-items: center;
  }

  .calls-enter-right {
    position: absolute;
    top: 0;
    right: 0;
    bottom: 0;
    width: min(430px, 88vw);
    transform: translateX(104%);
    transition: transform 220ms ease;
    z-index: 3;
    border-left: 1px solid var(--border-subtle);
    background: var(--bg-surface-strong);
    box-shadow: -10px 0 24px rgba(0, 0, 0, 0.3);
    padding: 10px;
  }

  .calls-enter-layout.panel-open .calls-enter-right {
    transform: translateX(0);
  }

  .calls-enter-config-grid {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 760px) {
  .calls-modal-dialog-enter {
    width: calc(100vw - 10px);
    height: calc(100vh - 10px);
    max-height: calc(100vh - 10px);
  }

  .calls-enter-preview-frame {
    aspect-ratio: 1 / 1;
    max-height: 44vh;
  }

  .calls-enter-right {
    width: 100%;
    border-left: 0;
  }

  .calls-enter-panel-toggle {
    top: 12px;
    right: 12px;
    transform: none;
  }
}
</style>
