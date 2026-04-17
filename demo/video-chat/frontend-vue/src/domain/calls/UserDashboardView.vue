<template>
  <section class="view-card calls-view">
    <section class="toolbar calls-toolbar">
      <input
        v-model="queryDraft"
        class="input"
        type="text"
        placeholder="Search call title"
        @keydown.enter.prevent="applyFilters"
      />
      <button class="btn" type="button" @click="applyFilters">Search</button>
      <button class="btn" type="button" @click="openCompose('create')">New call</button>
    </section>

    <section v-if="noticeMessage" class="section calls-banner" :class="noticeKindClass">
      {{ noticeMessage }}
    </section>

    <section v-if="viewMode === 'calls'" class="table-wrap calls-table-wrap">
      <table class="calls-list-table">
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
            <td data-label="Call">
              <div class="call-title">{{ call.title || call.id }}</div>
              <div class="call-subline code">{{ call.id }}</div>
            </td>
            <td data-label="Status">
              <span class="tag" :class="statusTagClass(call.status)">
                {{ call.status || 'unknown' }}
              </span>
            </td>
            <td data-label="Window">{{ formatRange(call.starts_at, call.ends_at) }}</td>
            <td data-label="Participants">
              {{ call.participants?.total ?? 0 }}
              <span class="call-subline">
                in {{ call.participants?.internal ?? 0 }} / ex {{ call.participants?.external ?? 0 }}
              </span>
            </td>
            <td data-label="Owner">
              {{ call.owner?.display_name || 'Unknown' }}
              <span class="call-subline">{{ call.owner?.email || 'n/a' }}</span>
            </td>
            <td data-label="Actions">
              <div class="actions-inline">
                <button
                  v-if="isEditable(call)"
                  class="icon-mini-btn"
                  type="button"
                  title="Edit call"
                  :aria-label="`Edit call ${call.title || call.id}`"
                  @click="openCompose('edit', call)"
                >
                  <img src="/assets/orgas/kingrt/icons/gear.png" alt="" />
                </button>
                <button
                  v-if="isInvitable(call)"
                  class="icon-mini-btn"
                  type="button"
                  title="Enter video call"
                  :aria-label="`Enter video call ${call.title || call.id}`"
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
                  v-if="isEditable(call)"
                  class="icon-mini-btn"
                  type="button"
                  title="Edit call"
                  @click="openCompose('edit', call)"
                >
                  <img src="/assets/orgas/kingrt/icons/gear.png" alt="" />
                </button>
                <button
                  v-if="isInvitable(call)"
                  class="icon-mini-btn"
                  type="button"
                  title="Enter video call"
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
        <header class="calls-modal-header calls-modal-header-enter">
          <div class="calls-modal-header-enter-left">
            <img class="calls-modal-header-enter-logo" src="/assets/orgas/kingrt/logo.svg" alt="" />
            <h4 class="calls-enter-title">Enter Video Call</h4>
          </div>
          <button class="icon-mini-btn" type="button" aria-label="Close" @click="closeEnterCallModal">
            <img src="/assets/orgas/kingrt/icons/cancel.png" alt="" />
          </button>
        </header>

        <div class="calls-modal-body calls-enter-body">
          <div class="calls-enter-layout">
            <section class="calls-enter-preview">
              <div class="calls-enter-preview-frame">
                <video ref="enterCallPreviewVideoRef" autoplay playsinline muted></video>
                <p v-if="enterCallState.previewError" class="calls-inline-error">{{ enterCallState.previewError }}</p>
                <p v-else-if="!enterCallState.previewReady" class="calls-inline-hint">Preparing preview...</p>
              </div>
            </section>

            <section class="calls-enter-right calls-enter-right-settings">
              <div class="call-left-settings">
                <section class="call-left-settings-block" aria-label="Camera">
                  <div class="call-left-settings-title">Camera</div>
                  <div class="call-left-settings-field">
                    <AppSelect
                      id="user-enter-call-camera-select"
                      aria-label="Camera"
                      :model-value="callMediaPrefs.selectedCameraId"
                      @update:model-value="setCallCameraDevice"
                    >
                      <option value="">{{ callMediaPrefs.cameras.length === 0 ? 'No camera detected' : 'Select camera' }}</option>
                      <option v-for="camera in callMediaPrefs.cameras" :key="camera.id" :value="camera.id">
                        {{ camera.label }}
                      </option>
                    </AppSelect>
                  </div>
                </section>

                <section class="call-left-settings-block" aria-label="Mic">
                  <div class="call-left-settings-title">Mic</div>
                  <div class="call-left-settings-field">
                    <AppSelect
                      id="user-enter-call-mic-select"
                      aria-label="Mic"
                      :model-value="callMediaPrefs.selectedMicrophoneId"
                      @update:model-value="setCallMicrophoneDevice"
                    >
                      <option value="">{{ callMediaPrefs.microphones.length === 0 ? 'No microphone detected' : 'Select mic' }}</option>
                      <option v-for="microphone in callMediaPrefs.microphones" :key="microphone.id" :value="microphone.id">
                        {{ microphone.label }}
                      </option>
                    </AppSelect>
                  </div>
                  <div class="call-left-settings-field">
                    <label for="user-enter-call-mic-volume">Volume</label>
                    <div class="call-left-volume-row">
                      <input
                        id="user-enter-call-mic-volume"
                        class="call-left-range"
                        type="range"
                        min="0"
                        max="100"
                        step="1"
                        :value="callMediaPrefs.microphoneVolume"
                        @input="setCallMicrophoneVolume($event.target.value)"
                      />
                      <span class="call-left-volume-value">{{ callMediaPrefs.microphoneVolume }}%</span>
                    </div>
                  </div>
                </section>

                <section class="call-left-settings-block" aria-label="Speaker">
                  <div class="call-left-settings-title">Speaker</div>
                  <div class="call-left-settings-field">
                    <AppSelect
                      id="user-enter-call-speaker-select"
                      aria-label="Speaker"
                      :model-value="callMediaPrefs.selectedSpeakerId"
                      @update:model-value="setCallSpeakerDevice"
                    >
                      <option value="">{{ callMediaPrefs.speakers.length === 0 ? 'No speaker detected' : 'Select speaker' }}</option>
                      <option v-for="speaker in callMediaPrefs.speakers" :key="speaker.id" :value="speaker.id">
                        {{ speaker.label }}
                      </option>
                    </AppSelect>
                  </div>
                  <div class="call-left-settings-field">
                    <label for="user-enter-call-speaker-volume">Volume</label>
                    <div class="call-left-volume-row">
                      <input
                        id="user-enter-call-speaker-volume"
                        class="call-left-range"
                        type="range"
                        min="0"
                        max="100"
                        step="1"
                        :value="callMediaPrefs.speakerVolume"
                        @input="setCallSpeakerVolume($event.target.value)"
                      />
                      <span class="call-left-volume-value">{{ callMediaPrefs.speakerVolume }}%</span>
                    </div>
                  </div>
                  <div class="call-left-settings-field">
                    <button class="btn full call-left-test-btn" type="button" @click="playSpeakerTestSound">
                      Play test sound
                    </button>
                  </div>
                </section>

                <section class="call-left-settings-block" aria-label="Background blur">
                  <div class="call-left-settings-title">Background blur</div>
                  <div class="call-left-blur-controls" role="group" aria-label="Background blur controls">
                    <button
                      class="call-left-blur-btn"
                      :class="{ active: isBackgroundPresetActive('light') }"
                      type="button"
                      :aria-pressed="isBackgroundPresetActive('light')"
                      aria-label="Blur"
                      title="Blur"
                      @click="applyBackgroundPreset('light')"
                    >
                      <img class="call-left-blur-icon" src="/assets/orgas/kingrt/icons/desktop.png" alt="" />
                    </button>
                    <button
                      class="call-left-blur-btn"
                      :class="{ active: isBackgroundPresetActive('strong') }"
                      type="button"
                      :aria-pressed="isBackgroundPresetActive('strong')"
                      aria-label="Strong blur"
                      title="Strong blur"
                      @click="applyBackgroundPreset('strong')"
                    >
                      <img class="call-left-blur-icon" src="/assets/orgas/kingrt/icons/desktop.png" alt="" />
                      <span class="call-left-blur-strong-mark" aria-hidden="true">+</span>
                    </button>
                  </div>
                </section>

                <div v-if="callMediaPrefs.error" class="call-left-settings-error">{{ callMediaPrefs.error }}</div>
              </div>
            </section>
          </div>
        </div>

        <footer class="calls-modal-footer">
          <button
            class="btn btn-green"
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
          <button class="btn btn-cyan" type="button" :disabled="joinState.submitting" @click="submitJoinInvite">
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
              <AppSelect v-model="composeState.accessMode" aria-label="Call access mode">
                <option value="invite_only">Invite only</option>
                <option value="free_for_all">Free for all</option>
              </AppSelect>
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
          <button class="btn btn-cyan" type="button" :disabled="composeState.submitting" @click="submitCompose">
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
import AppSelect from '../../components/AppSelect.vue';
import { sessionState } from '../auth/session';
import { currentBackendOrigin, fetchBackend } from '../../support/backendFetch';
import {
  formatDateDisplay,
  formatDateRangeDisplay,
  formatDateTimeDisplay,
  formatWeekdayShort,
} from '../../support/dateTimeFormat';
import {
  attachCallMediaDeviceWatcher,
  callMediaPrefs,
  refreshCallMediaDevices,
  setCallBackgroundApplyOutgoing,
  setCallBackgroundBackdropMode,
  setCallBackgroundBlurStrength,
  setCallBackgroundFilterMode,
  setCallBackgroundQualityProfile,
  setCallCameraDevice,
  setCallMicrophoneDevice,
  setCallMicrophoneVolume,
  setCallSpeakerDevice,
  setCallSpeakerVolume,
} from '../realtime/callMediaPreferences';
import { BackgroundFilterController } from '../realtime/backgroundFilterController';

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
  return formatDateTimeDisplay(isoValue, {
    dateFormat: sessionState.dateFormat,
    timeFormat: sessionState.timeFormat,
    fallback: 'n/a',
  });
}

function formatRange(startsAt, endsAt) {
  return formatDateRangeDisplay(startsAt, endsAt, {
    dateFormat: sessionState.dateFormat,
    timeFormat: sessionState.timeFormat,
    separator: ' -> ',
    fallback: 'n/a',
  });
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

function isParticipantCall(call) {
  if (isOwnerCall(call)) return true;
  return Boolean(call?.my_participation);
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

function isCallWindowOpen(call) {
  const startsAt = Date.parse(String(call?.starts_at || ''));
  const endsAt = Date.parse(String(call?.ends_at || ''));
  if (!Number.isFinite(startsAt) || !Number.isFinite(endsAt)) {
    return false;
  }
  const now = Date.now();
  return startsAt <= now && now < endsAt;
}

function isInvitable(call) {
  const status = String(call?.status || '').toLowerCase();
  const isJoinableStatus = status === 'active' || (status === 'scheduled' && isCallWindowOpen(call));
  return isJoinableStatus && isParticipantCall(call);
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
        const weekday = formatWeekdayShort(keyDate, { fallback: '' });
        const dateLabel = formatDateDisplay(keyDate, {
          dateFormat: sessionState.dateFormat,
          fallback: key,
        });
        label = weekday !== '' ? `${weekday}, ${dateLabel}` : dateLabel;
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
const enterCallPreviewRawStreamRef = ref(null);
const enterCallPreviewStreamRef = ref(null);
const enterCallPreviewBackgroundController = new BackgroundFilterController();
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
}

function isBackgroundPresetActive(preset) {
  const mode = String(callMediaPrefs.backgroundFilterMode || 'off').trim().toLowerCase();
  const applyOutgoing = Boolean(callMediaPrefs.backgroundApplyOutgoing);
  const backdrop = String(callMediaPrefs.backgroundBackdropMode || 'blur7').trim().toLowerCase();

  if (preset === 'off') {
    return mode !== 'blur' || !applyOutgoing;
  }
  if (preset === 'light') {
    return mode === 'blur' && applyOutgoing && backdrop === 'blur7';
  }
  if (preset === 'strong') {
    return mode === 'blur' && applyOutgoing && backdrop === 'blur9';
  }
  return false;
}

function applyBackgroundPreset(preset) {
  if (preset !== 'light' && preset !== 'strong') {
    setCallBackgroundFilterMode('off');
    setCallBackgroundApplyOutgoing(false);
    return;
  }

  if (isBackgroundPresetActive(preset)) {
    setCallBackgroundFilterMode('off');
    setCallBackgroundApplyOutgoing(false);
    return;
  }

  setCallBackgroundFilterMode('blur');
  setCallBackgroundApplyOutgoing(true);

  if (preset === 'strong') {
    setCallBackgroundBackdropMode('blur9');
    setCallBackgroundQualityProfile('quality');
    setCallBackgroundBlurStrength(4);
    return;
  }

  setCallBackgroundBackdropMode('blur7');
  setCallBackgroundQualityProfile('balanced');
  setCallBackgroundBlurStrength(2);
}

function resolvePreviewBackgroundFilterOptions() {
  const toFiniteNumber = (value, fallback) => {
    const numeric = Number(value);
    return Number.isFinite(numeric) ? numeric : fallback;
  };
  const mode = String(callMediaPrefs.backgroundFilterMode || 'off').trim().toLowerCase() === 'blur'
    ? 'blur'
    : 'off';
  const applyOutgoing = Boolean(callMediaPrefs.backgroundApplyOutgoing);
  if (!applyOutgoing || mode !== 'blur') {
    return { mode: 'off' };
  }

  const backdrop = String(callMediaPrefs.backgroundBackdropMode || 'blur7').trim().toLowerCase();
  const qualityProfile = String(callMediaPrefs.backgroundQualityProfile || 'balanced').trim().toLowerCase();
  const baseBlurLevel = Math.max(0, Math.min(4, Math.round(toFiniteNumber(callMediaPrefs.backgroundBlurStrength, 2))));
  const blurStepPx = [1, 2, 3, 4, 5];
  let blurPx = blurStepPx[baseBlurLevel] ?? 3;
  if (backdrop === 'blur9') {
    blurPx = Math.round(blurPx * 1.35);
  }
  blurPx = Math.max(1, Math.min(12, blurPx));

  let detectIntervalMs = 110;
  if (qualityProfile === 'quality') {
    detectIntervalMs = 80;
  } else if (qualityProfile === 'realtime') {
    detectIntervalMs = 140;
  }

  let temporalSmoothingAlpha = 0.24;
  if (qualityProfile === 'quality') {
    temporalSmoothingAlpha = 0.18;
  } else if (qualityProfile === 'realtime') {
    temporalSmoothingAlpha = 0.32;
  }

  const maskVariant = Math.max(1, Math.min(10, Math.round(toFiniteNumber(callMediaPrefs.backgroundMaskVariant, 4))));
  const transitionGain = Math.max(1, Math.min(10, Math.round(toFiniteNumber(callMediaPrefs.backgroundBlurTransition, 10))));
  const requestedProcessWidth = Math.max(320, Math.min(1920, Math.round(toFiniteNumber(callMediaPrefs.backgroundMaxProcessWidth, 960))));
  const requestedProcessFps = Math.max(8, Math.min(30, Math.round(toFiniteNumber(callMediaPrefs.backgroundMaxProcessFps, 24))));
  let processWidthCap = 960;
  let processFpsCap = 24;
  if (qualityProfile === 'quality') {
    processWidthCap = 1280;
    processFpsCap = 30;
  } else if (qualityProfile === 'realtime') {
    processWidthCap = 960;
    processFpsCap = 15;
  }

  return {
    mode,
    blurPx,
    detectIntervalMs,
    temporalSmoothingAlpha,
    preferFastMatte: false,
    maskVariant,
    transitionGain,
    maxProcessWidth: Math.max(320, Math.min(processWidthCap, requestedProcessWidth)),
    maxProcessFps: Math.max(8, Math.min(processFpsCap, requestedProcessFps)),
    autoDisableOnOverload: false,
  };
}

function stopEnterCallPreview() {
  enterCallPreviewBackgroundController.dispose();

  const previewNode = enterCallPreviewVideoRef.value;
  if (previewNode instanceof HTMLVideoElement) {
    try {
      previewNode.pause();
    } catch {
      // ignore
    }
    previewNode.srcObject = null;
  }

  const rawStream = enterCallPreviewRawStreamRef.value;
  if (rawStream instanceof MediaStream) {
    for (const track of rawStream.getTracks()) {
      track.stop();
    }
  }
  enterCallPreviewRawStreamRef.value = null;

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
    const rawStream = await navigator.mediaDevices.getUserMedia(buildPreviewConstraints());
    enterCallPreviewRawStreamRef.value = rawStream;
    const volume = Math.max(0, Math.min(100, Number(callMediaPrefs.microphoneVolume || 100))) / 100;
    for (const track of rawStream.getAudioTracks()) {
      if (typeof track.applyConstraints === 'function') {
        track.applyConstraints({ volume }).catch(() => {});
      }
    }

    let previewStream = rawStream;
    const backgroundOptions = resolvePreviewBackgroundFilterOptions();
    if (backgroundOptions.mode === 'blur') {
      try {
        const result = await enterCallPreviewBackgroundController.apply(rawStream, backgroundOptions);
        if (result?.stream instanceof MediaStream) {
          previewStream = result.stream;
        }
      } catch {
        previewStream = rawStream;
      }
    }
    enterCallPreviewStreamRef.value = previewStream;

    await nextTick();
    const previewNode = enterCallPreviewVideoRef.value;
    if (!(previewNode instanceof HTMLVideoElement)) {
      return;
    }

    previewNode.muted = true;
    previewNode.srcObject = previewStream;
    await previewNode.play().catch(() => {});
    enterCallState.previewReady = true;
  } catch (error) {
    const message = error instanceof Error ? error.message : 'Could not start camera preview.';
    enterCallState.previewError = message || 'Could not start camera preview.';
  }
}

async function playSpeakerTestSound() {
  if (typeof window === 'undefined') return;
  const AudioContextCtor = window.AudioContext || window.webkitAudioContext;
  if (!AudioContextCtor) return;

  let context = null;
  const audio = new Audio();
  try {
    context = new AudioContextCtor();
    const destination = context.createMediaStreamDestination();
    const oscillator = context.createOscillator();
    const gainNode = context.createGain();
    const normalizedVolume = Math.max(0, Math.min(100, Number(callMediaPrefs.speakerVolume || 100))) / 100;

    oscillator.type = 'sine';
    oscillator.frequency.value = 880;
    gainNode.gain.value = Math.max(0.01, normalizedVolume * 0.45);
    oscillator.connect(gainNode);
    gainNode.connect(destination);

    audio.srcObject = destination.stream;
    audio.playsInline = true;
    audio.muted = false;
    audio.volume = 1;

    const speakerDeviceId = String(callMediaPrefs.selectedSpeakerId || '').trim();
    if (speakerDeviceId !== '' && typeof audio.setSinkId === 'function') {
      await audio.setSinkId(speakerDeviceId).catch(() => {});
    }

    await audio.play();
    oscillator.start();
    oscillator.stop(context.currentTime + 0.22);
    await new Promise((resolve) => setTimeout(resolve, 260));
  } catch {
    // ignore
  } finally {
    try {
      audio.pause();
    } catch {
      // ignore
    }
    audio.srcObject = null;
    if (context && typeof context.close === 'function') {
      await context.close().catch(() => {});
    }
  }
}

function closeEnterCallModal() {
  enterCallState.open = false;
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

  try {
    await refreshCallMediaDevices({ requestPermissions: true });
    await startEnterCallPreview();
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
  const callId = String(normalizedTarget.callId || '').trim();
  if (callId !== '') {
    return callId;
  }

  const explicitAccessId = String(normalizedTarget.accessId || '').trim();
  if (explicitAccessId !== '') {
    return explicitAccessId;
  }

  const roomId = String(normalizedTarget.roomId || '').trim();
  return roomId === '' ? 'lobby' : roomId;
}

async function openCallWorkspace(target = null) {
  const routeSegment = await resolveWorkspaceRouteSegment(target);
  closeEnterCallModal();
  await router.push(`/workspace/call/${encodeURIComponent(routeSegment)}`);
}

watch(
  () => [callMediaPrefs.selectedCameraId, callMediaPrefs.selectedMicrophoneId],
  () => {
    if (!enterCallState.open) return;
    void startEnterCallPreview();
  },
);

watch(
  () => [
    callMediaPrefs.backgroundFilterMode,
    callMediaPrefs.backgroundBackdropMode,
    callMediaPrefs.backgroundQualityProfile,
    callMediaPrefs.backgroundBlurStrength,
    callMediaPrefs.backgroundApplyOutgoing,
    callMediaPrefs.backgroundMaskVariant,
    callMediaPrefs.backgroundBlurTransition,
    callMediaPrefs.backgroundMaxProcessWidth,
    callMediaPrefs.backgroundMaxProcessFps,
  ],
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

.calls-toolbar {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto auto;
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
  border-top: 0;
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
  --calls-enter-dialog-padding: 12px;
  width: min(1220px, calc(100vw - 24px));
  height: min(840px, calc(100vh - 24px));
  max-height: calc(100vh - 24px);
  overflow: hidden;
  grid-template-rows: auto minmax(0, 1fr) auto;
  padding: var(--calls-enter-dialog-padding);
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

.calls-modal-header .calls-enter-title {
  margin: 3px 0 0;
  font-size: 14px;
  line-height: 1;
}

.calls-modal-header-enter {
  margin: calc(var(--calls-enter-dialog-padding) * -1) calc(var(--calls-enter-dialog-padding) * -1) 0;
  padding: 10px;
  background: var(--brand-bg);
  border: 0;
}

.calls-modal-header-enter-left {
  min-width: 0;
  display: inline-flex;
  align-items: center;
  gap: 10px;
}

.calls-modal-header-enter-logo {
  width: auto;
  height: 24px;
  display: block;
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
  grid-template-rows: minmax(0, 1fr);
  gap: 0;
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
  grid-template-rows: minmax(0, 1fr);
  align-content: start;
  overflow: hidden;
}

.calls-enter-right-settings {
  border: 0;
  border-radius: 0;
  background: transparent;
  box-shadow: none;
  min-height: 0;
  overflow: hidden;
}

.calls-enter-right-settings .call-left-settings {
  min-height: 0;
  max-height: 100%;
  padding: 12px;
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
    --calls-enter-dialog-padding: 10px;
    width: min(980px, calc(100vw - 14px));
    height: min(920px, calc(100dvh - 14px));
    max-height: calc(100dvh - 14px);
    padding: var(--calls-enter-dialog-padding);
    gap: 8px;
  }

  .calls-enter-layout {
    grid-template-columns: minmax(0, 1fr);
    grid-template-rows: minmax(0, 42%) minmax(0, 58%);
    gap: 8px;
    min-height: 0;
    height: 100%;
  }

  .calls-enter-preview-frame {
    width: 100%;
    height: 100%;
    aspect-ratio: auto;
    max-height: none;
  }

  .calls-enter-right {
    position: static;
    width: 100%;
    border-left: 0;
    box-shadow: none;
    background: transparent;
    padding: 0;
  }

  .calls-enter-right-settings .call-left-settings {
    padding: 8px;
    gap: 6px;
    overflow-y: hidden;
  }

  .calls-enter-right-settings .call-left-settings-block {
    padding: 8px;
    gap: 6px;
  }

  .calls-enter-right-settings .call-left-settings-title {
    font-size: 12px;
  }

  .calls-enter-right-settings .call-left-settings-field {
    gap: 4px;
    font-size: 11px;
  }

  .calls-enter-right-settings .ii-select,
  .calls-enter-right-settings .call-left-test-btn {
    height: 30px;
    padding: 0 8px;
    font-size: 12px;
  }

  .calls-enter-right-settings .call-left-blur-btn {
    height: 30px;
  }

  .calls-enter-right-settings .call-left-volume-value {
    min-width: 38px;
    font-size: 11px;
  }

  .calls-modal-footer .btn {
    height: 34px;
    padding: 0 14px;
  }
}

@media (max-width: 760px) {
  .calls-list-table {
    width: 100%;
    table-layout: auto;
    border-collapse: separate;
    border-spacing: 0 8px;
  }

  .calls-list-table thead {
    position: absolute;
    width: 1px;
    height: 1px;
    margin: -1px;
    padding: 0;
    border: 0;
    overflow: hidden;
    clip: rect(0 0 0 0);
    clip-path: inset(50%);
    white-space: nowrap;
  }

  .calls-list-table tbody,
  .calls-list-table tr,
  .calls-list-table td {
    display: block;
    width: 100%;
  }

  .calls-list-table tbody tr {
    border: 1px solid var(--border-subtle);
    border-radius: 8px;
    overflow: hidden;
    background: var(--bg-row);
  }

  .calls-list-table td {
    display: grid;
    grid-template-columns: minmax(90px, 34%) minmax(0, 1fr);
    gap: 8px;
    align-items: start;
    padding: 8px 10px;
    border-bottom: 1px solid var(--border-subtle);
  }

  .calls-list-table td::before {
    content: attr(data-label);
    color: var(--text-muted);
    font-size: 11px;
    font-weight: 600;
  }

  .calls-list-table td:last-child {
    border-bottom: 0;
  }

  .calls-list-table td:last-child .actions-inline {
    justify-content: flex-start;
    flex-wrap: wrap;
  }

  .calls-list-table .call-subline.code {
    word-break: break-all;
    overflow-wrap: anywhere;
  }

  .calls-modal-dialog-enter {
    --calls-enter-dialog-padding: 8px;
    width: calc(100vw - 6px);
    height: calc(100dvh - 6px);
    max-height: calc(100dvh - 6px);
    padding: var(--calls-enter-dialog-padding);
    gap: 6px;
  }

  .calls-modal-header {
    gap: 6px;
  }

  .calls-modal-header h4 {
    font-size: 14px;
  }

  .calls-modal-header-enter {
    padding: 10px;
  }

  .calls-modal-header-enter-logo {
    height: 20px;
  }

  .calls-enter-layout {
    grid-template-rows: minmax(0, 38%) minmax(0, 62%);
  }

  .calls-enter-preview-frame {
    height: 100%;
    max-height: none;
  }

  .calls-enter-right-settings .call-left-settings {
    padding: 6px;
    gap: 5px;
  }

  .calls-enter-right-settings .call-left-settings-block {
    padding: 6px;
    gap: 5px;
  }

  .calls-enter-right-settings .call-left-settings-title {
    font-size: 11px;
  }

  .calls-enter-right-settings .call-left-settings-field {
    font-size: 10px;
  }

  .calls-modal-footer .btn {
    height: 32px;
    padding: 0 12px;
    font-size: 12px;
  }
}
</style>
