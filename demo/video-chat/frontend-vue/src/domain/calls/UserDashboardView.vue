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
                  class="icon-mini-btn"
                  type="button"
                  title="Open chat archive"
                  :aria-label="`Open chat archive for ${call.title || call.id}`"
                  @click="openChatArchive(call)"
                >
                  <img src="/assets/orgas/kingrt/icons/chat.png" alt="" />
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
                  class="icon-mini-btn"
                  type="button"
                  title="Open chat archive"
                  @click="openChatArchive(call)"
                >
                  <img src="/assets/orgas/kingrt/icons/chat.png" alt="" />
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

    <section v-if="viewMode === 'calls'" class="footer calls-pagination-wrap">
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
                      <img class="call-left-blur-icon" src="/assets/orgas/kingrt/icons/blur.png" alt="" />
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
                      <img class="call-left-blur-icon" src="/assets/orgas/kingrt/icons/blurmore.png" alt="" />
                    </button>
                  </div>
                </section>

                <div v-if="callMediaPrefs.error" class="call-left-settings-error">{{ callMediaPrefs.error }}</div>
              </div>
            </section>
          </div>
        </div>

        <footer class="calls-modal-footer calls-modal-footer-enter">
          <p
            v-if="enterCallState.admissionMessage"
            class="calls-enter-admission-status"
            role="status"
            aria-live="polite"
          >
            {{ enterCallState.admissionMessage }}
          </p>
          <p v-if="enterCallState.error" class="calls-inline-error calls-enter-footer-error">
            {{ enterCallState.error }}
          </p>
          <button
            class="btn btn-cyan"
            type="button"
            :disabled="enterCallState.loading || enterCallState.waitingForAdmission"
            @click="openCallWorkspace({ callId: enterCallState.callId, roomId: enterCallState.roomId })"
          >
            {{ enterCallState.loading ? 'Joining...' : 'Join call' }}
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
        <header class="calls-modal-header calls-modal-header-enter">
          <div class="calls-modal-header-enter-left">
            <img class="calls-modal-header-enter-logo" src="/assets/orgas/kingrt/logo.svg" alt="" />
            <h4 class="calls-enter-title">{{ composeHeadline }}</h4>
          </div>
          <button class="icon-mini-btn" type="button" aria-label="Close" @click="closeCompose">
            <img src="/assets/orgas/kingrt/icons/cancel.png" alt="" />
          </button>
        </header>

        <div class="calls-modal-body">
          <section class="calls-modal-grid">
            <label class="field calls-field-wide">
              <span>Title</span>
              <input v-model="composeState.title" class="input" type="text" placeholder="Weekly Product Sync" />
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
            <label class="field calls-field-wide">
              <span>Access mode</span>
              <AppSelect v-model="composeState.accessMode" aria-label="Call access mode">
                <option value="invite_only">Invite only</option>
                <option value="free_for_all">Free for all</option>
              </AppSelect>
            </label>
          </section>

          <section v-if="shouldSendParticipants" class="calls-participants-grid">
            <article class="calls-participants-panel">
              <header class="calls-participants-head">
                <h5>Registered users</h5>
                <label class="calls-search small" aria-label="Participant search">
                  <input
                    v-model.trim="composeParticipants.query"
                    class="input"
                    type="search"
                    placeholder="Search users"
                    @keydown.enter.prevent="applyParticipantSearch"
                  />
                  <button class="btn btn-cyan" type="button" :disabled="composeParticipants.loading" @click="applyParticipantSearch">
                    Search
                  </button>
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
                <button class="btn btn-cyan" type="button" @click="addExternalRow">Add row</button>
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

          <section v-else-if="composeState.mode === 'edit'" class="calls-inline-hint">
            Existing participants remain unchanged for this edit.
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

    <ChatArchiveModal
      :open="chatArchiveState.open"
      :call-id="chatArchiveState.callId"
      :call-title="chatArchiveState.callTitle"
      @close="closeChatArchive"
    />
  </section>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import AppSelect from '../../components/AppSelect.vue';
import ChatArchiveModal from './ChatArchiveModal.vue';
import { sessionState } from '../auth/session';
import { currentBackendOrigin, fetchBackend } from '../../support/backendFetch';
import {
  buildWebSocketUrl,
  resolveBackendWebSocketOriginCandidates,
  setBackendWebSocketOrigin,
} from '../../support/backendOrigin';
import { createAdminSyncSocket } from '../../support/adminSyncSocket';
import {
  formatDateDisplay,
  formatDateRangeDisplay,
  formatDateTimeDisplay,
  formatWeekdayShort,
} from '../../support/dateTimeFormat';
import {
  applyCallBackgroundPreset,
  attachCallMediaDeviceWatcher,
  callMediaPrefs,
  isCallBackgroundPresetActive,
  refreshCallMediaDevices,
  setCallCameraDevice,
  setCallMicrophoneDevice,
  setCallMicrophoneVolume,
  setCallSpeakerDevice,
  setCallSpeakerVolume,
} from '../realtime/callMediaPreferences';
import { BackgroundFilterController } from '../realtime/backgroundFilterController';

const router = useRouter();
const USER_CALL_CREATE_EVENT = 'king:user-calls:create';
const applyBackgroundPreset = applyCallBackgroundPreset;
const isBackgroundPresetActive = isCallBackgroundPresetActive;

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
const chatArchiveState = reactive({
  open: false,
  callId: '',
  callTitle: '',
});

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

function openChatArchive(call) {
  chatArchiveState.callId = String(call?.id || '').trim();
  chatArchiveState.callTitle = String(call?.title || call?.id || '').trim();
  chatArchiveState.open = chatArchiveState.callId !== '';
}

function closeChatArchive() {
  chatArchiveState.open = false;
  chatArchiveState.callId = '';
  chatArchiveState.callTitle = '';
}

let adminSyncReloadTimer = 0;
let adminSyncClient = null;
let fallbackRefreshTimer = 0;

function clearAdminSyncReloadTimer() {
  if (adminSyncReloadTimer > 0) {
    window.clearTimeout(adminSyncReloadTimer);
    adminSyncReloadTimer = 0;
  }
}

async function reloadCallsFromAdminSync() {
  await Promise.all([
    loadCalls(),
    loadCalendar(),
  ]);
}

function queueReloadCallsFromAdminSync() {
  if (adminSyncReloadTimer > 0) return;
  adminSyncReloadTimer = window.setTimeout(() => {
    adminSyncReloadTimer = 0;
    void reloadCallsFromAdminSync();
  }, 120);
}

function handleAdminSyncEvent(payload) {
  const sourceSessionId = String(payload?.source_session_id || '').trim();
  const ownSessionId = String(sessionState.sessionId || sessionState.sessionToken || '').trim();
  if (sourceSessionId !== '' && sourceSessionId === ownSessionId) {
    return;
  }

  const topic = String(payload?.topic || '').trim().toLowerCase();
  if (!['all', 'calls', 'overview'].includes(topic)) {
    return;
  }

  queueReloadCallsFromAdminSync();
}

function startAdminSyncSocket() {
  if (adminSyncClient) {
    adminSyncClient.disconnect();
    adminSyncClient = null;
  }

  adminSyncClient = createAdminSyncSocket({
    getSessionToken: () => String(sessionState.sessionToken || '').trim(),
    onSync: handleAdminSyncEvent,
  });
  adminSyncClient.connect();
}

function stopAdminSyncSocket() {
  if (!adminSyncClient) return;
  adminSyncClient.disconnect();
  adminSyncClient = null;
}

function startFallbackRefreshLoop() {
  if (fallbackRefreshTimer > 0) {
    window.clearInterval(fallbackRefreshTimer);
    fallbackRefreshTimer = 0;
  }

  fallbackRefreshTimer = window.setInterval(() => {
    if (document.visibilityState === 'hidden') return;
    if (loadingCalls.value || loadingCalendar.value) return;
    void Promise.all([loadCalls(), loadCalendar()]);
  }, 5000);
}

function stopFallbackRefreshLoop() {
  if (fallbackRefreshTimer > 0) {
    window.clearInterval(fallbackRefreshTimer);
    fallbackRefreshTimer = 0;
  }
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
let enterAdmissionSocket = null;
let enterAdmissionSocketGeneration = 0;
let enterAdmissionAccepted = false;
let enterAdmissionManuallyClosed = false;
let enterAdmissionReconnectTimer = 0;
let enterAdmissionReconnectAttempt = 0;

const ENTER_ADMISSION_WAIT_MESSAGE = 'Call owner has been notified.';
const ENTER_ADMISSION_RECONNECT_DELAYS_MS = [500, 1000, 2000, 3000, 5000];

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
  waitingForAdmission: false,
  admissionMessage: '',
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
  enterCallState.waitingForAdmission = false;
  enterCallState.admissionMessage = '';
}

function normalizeRoomId(value) {
  const candidate = String(value || '').trim().toLowerCase();
  if (candidate === '' || candidate.length > 120) return 'lobby';
  return /^[a-z0-9._-]+$/.test(candidate) ? candidate : 'lobby';
}

function normalizeCallId(value) {
  const candidate = String(value || '').trim();
  if (candidate === '') return '';
  return /^[A-Za-z0-9._-]{1,200}$/.test(candidate) ? candidate : '';
}

function enterAdmissionSocketUrlForOrigin(origin) {
  const query = new URLSearchParams();
  query.set('room', normalizeRoomId(enterCallState.roomId || 'lobby'));

  const callId = normalizeCallId(enterCallState.callId);
  if (callId !== '') {
    query.set('call_id', callId);
  }

  const token = String(sessionState.sessionToken || '').trim();
  if (token !== '') {
    query.set('session', token);
  }

  return buildWebSocketUrl(origin, '/ws', query);
}

function clearEnterAdmissionReconnectTimer() {
  if (enterAdmissionReconnectTimer > 0 && typeof window !== 'undefined') {
    window.clearTimeout(enterAdmissionReconnectTimer);
  }
  enterAdmissionReconnectTimer = 0;
}

function enterAdmissionSocketIsOpen(socket = enterAdmissionSocket) {
  if (typeof WebSocket === 'undefined') return false;
  return socket instanceof WebSocket && socket.readyState === WebSocket.OPEN;
}

function sendEnterAdmissionFrame(payload) {
  if (!enterAdmissionSocketIsOpen()) return false;
  try {
    enterAdmissionSocket.send(JSON.stringify(payload));
    return true;
  } catch {
    return false;
  }
}

function closeEnterAdmissionSocket({ cancel = false } = {}) {
  enterAdmissionManuallyClosed = true;
  clearEnterAdmissionReconnectTimer();

  if (cancel && enterCallState.waitingForAdmission && !enterAdmissionAccepted) {
    sendEnterAdmissionFrame({
      type: 'lobby/queue/cancel',
      room_id: normalizeRoomId(enterCallState.roomId || 'lobby'),
    });
  }

  const socket = enterAdmissionSocket;
  enterAdmissionSocket = null;
  if (typeof WebSocket !== 'undefined' && socket instanceof WebSocket) {
    try {
      socket.close(1000, cancel ? 'admission_cancelled' : 'admission_closed');
    } catch {
      // ignore
    }
  }
}

function scheduleEnterAdmissionReconnect() {
  clearEnterAdmissionReconnectTimer();
  if (enterAdmissionManuallyClosed || enterAdmissionAccepted || !enterCallState.waitingForAdmission) return;
  if (typeof window === 'undefined') return;

  enterAdmissionReconnectAttempt += 1;
  const delay = ENTER_ADMISSION_RECONNECT_DELAYS_MS[
    Math.min(enterAdmissionReconnectAttempt - 1, ENTER_ADMISSION_RECONNECT_DELAYS_MS.length - 1)
  ];
  enterCallState.admissionMessage = 'Reconnecting lobby connection...';
  enterAdmissionReconnectTimer = window.setTimeout(() => {
    enterAdmissionReconnectTimer = 0;
    connectEnterAdmissionSocket();
  }, delay);
}

async function enterAdmittedCall() {
  enterAdmissionAccepted = true;
  closeEnterAdmissionSocket({ cancel: false });

  const callRef = normalizeCallId(enterCallState.callId) || normalizeRoomId(enterCallState.roomId || 'lobby');
  enterCallState.open = false;
  enterCallState.loading = false;
  enterCallState.waitingForAdmission = false;
  enterCallState.admissionMessage = '';
  stopEnterCallPreview();
  resetEnterCallState();

  await router.push({
    name: 'call-workspace',
    params: { callRef },
    query: { entry: 'invite' },
  });
}

function handleEnterAdmissionLobbySnapshot(payload) {
  const admittedRows = Array.isArray(payload?.admitted) ? payload.admitted : [];
  const currentUserId = Number(sessionState.userId || 0);
  const isAdmitted = admittedRows.some((entry) => Number(entry?.user_id || 0) === currentUserId);
  if (!isAdmitted) return;

  void enterAdmittedCall();
}

function handleEnterAdmissionWelcome(payload) {
  const admission = payload && typeof payload === 'object' ? payload.admission : null;
  const requiresAdmission = Boolean(admission?.requires_admission);
  const pendingRoomId = normalizeRoomId(admission?.pending_room_id || enterCallState.roomId || 'lobby');
  enterCallState.roomId = pendingRoomId;

  if (!requiresAdmission) {
    void enterAdmittedCall();
    return;
  }

  if (!sendEnterAdmissionFrame({ type: 'lobby/queue/join', room_id: pendingRoomId })) {
    enterCallState.loading = false;
    enterCallState.waitingForAdmission = false;
    enterCallState.admissionMessage = '';
    enterCallState.error = 'Could not notify call owner because the lobby connection is offline.';
    return;
  }

  enterCallState.loading = false;
  enterCallState.waitingForAdmission = true;
  enterCallState.error = '';
  enterCallState.admissionMessage = ENTER_ADMISSION_WAIT_MESSAGE;
}

function handleEnterAdmissionSocketMessage(event) {
  let payload = null;
  try {
    payload = JSON.parse(String(event.data || ''));
  } catch {
    return;
  }

  if (!payload || typeof payload !== 'object') return;
  const type = String(payload.type || '').trim().toLowerCase();
  if (type === 'system/welcome') {
    handleEnterAdmissionWelcome(payload);
    return;
  }

  if (type === 'lobby/snapshot') {
    handleEnterAdmissionLobbySnapshot(payload);
    return;
  }

  if (type === 'system/error') {
    const code = String(payload.code || '').trim().toLowerCase();
    if (code === 'lobby_command_failed') {
      enterCallState.error = 'Could not notify call owner.';
      enterCallState.admissionMessage = '';
      enterCallState.waitingForAdmission = false;
      enterCallState.loading = false;
    }
  }
}

function connectEnterAdmissionSocketWithOriginAt(candidates, originIndex, generation) {
  if (generation !== enterAdmissionSocketGeneration || enterAdmissionManuallyClosed || enterAdmissionAccepted) return;

  if (originIndex >= candidates.length) {
    if (enterCallState.waitingForAdmission) {
      scheduleEnterAdmissionReconnect();
    } else {
      enterCallState.loading = false;
      enterCallState.waitingForAdmission = false;
      enterCallState.admissionMessage = '';
      enterCallState.error = 'Could not connect to call lobby.';
    }
    return;
  }

  const socketOrigin = candidates[originIndex];
  const wsUrl = enterAdmissionSocketUrlForOrigin(socketOrigin);
  if (!wsUrl) {
    connectEnterAdmissionSocketWithOriginAt(candidates, originIndex + 1, generation);
    return;
  }

  const socket = new WebSocket(wsUrl);
  enterAdmissionSocket = socket;
  let opened = false;
  let failedOver = false;

  const failOverToNextOrigin = () => {
    if (failedOver) return;
    failedOver = true;
    if (enterAdmissionSocket === socket) {
      enterAdmissionSocket = null;
    }
    try {
      socket.close(1000, 'admission_failover');
    } catch {
      // ignore
    }
    connectEnterAdmissionSocketWithOriginAt(candidates, originIndex + 1, generation);
  };

  socket.addEventListener('open', () => {
    if (generation !== enterAdmissionSocketGeneration || enterAdmissionManuallyClosed || enterAdmissionAccepted) {
      try {
        socket.close(1000, 'stale_admission_socket');
      } catch {
        // ignore
      }
      return;
    }

    opened = true;
    enterAdmissionReconnectAttempt = 0;
    setBackendWebSocketOrigin(socketOrigin);
  });

  socket.addEventListener('message', (event) => {
    if (generation !== enterAdmissionSocketGeneration || enterAdmissionManuallyClosed || enterAdmissionAccepted) return;
    handleEnterAdmissionSocketMessage(event);
  });

  socket.addEventListener('error', () => {
    if (generation !== enterAdmissionSocketGeneration || enterAdmissionManuallyClosed || enterAdmissionAccepted) return;
    if (!opened) {
      failOverToNextOrigin();
      return;
    }
    enterCallState.admissionMessage = 'Reconnecting lobby connection...';
  });

  socket.addEventListener('close', () => {
    if (generation !== enterAdmissionSocketGeneration) return;
    if (enterAdmissionSocket === socket) {
      enterAdmissionSocket = null;
    }
    if (enterAdmissionManuallyClosed || enterAdmissionAccepted) return;

    if (!opened) {
      failOverToNextOrigin();
      return;
    }

    scheduleEnterAdmissionReconnect();
  });
}

function connectEnterAdmissionSocket() {
  const candidates = resolveBackendWebSocketOriginCandidates();
  connectEnterAdmissionSocketWithOriginAt(candidates, 0, enterAdmissionSocketGeneration);
}

function startEnterAdmissionWait(target = null) {
  if (typeof WebSocket === 'undefined') {
    enterCallState.loading = false;
    enterCallState.error = 'Realtime lobby is not supported in this browser.';
    return false;
  }

  const normalizedTarget = target && typeof target === 'object' ? target : {};
  enterCallState.callId = normalizeCallId(normalizedTarget.callId || enterCallState.callId);
  enterCallState.roomId = normalizeRoomId(normalizedTarget.roomId || enterCallState.roomId || 'lobby');
  enterCallState.error = '';
  enterCallState.loading = true;
  enterCallState.waitingForAdmission = true;
  enterCallState.admissionMessage = 'Connecting lobby connection...';

  closeEnterAdmissionSocket({ cancel: false });
  enterAdmissionAccepted = false;
  enterAdmissionManuallyClosed = false;
  enterAdmissionReconnectAttempt = 0;
  enterAdmissionSocketGeneration += 1;
  connectEnterAdmissionSocket();
  return true;
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

  let detectIntervalMs = 150;
  if (qualityProfile === 'quality') {
    detectIntervalMs = 110;
  } else if (qualityProfile === 'realtime') {
    detectIntervalMs = 190;
  }

  let temporalSmoothingAlpha = 0.28;
  if (qualityProfile === 'quality') {
    temporalSmoothingAlpha = 0.22;
  } else if (qualityProfile === 'realtime') {
    temporalSmoothingAlpha = 0.38;
  }

  const maskVariant = Math.max(1, Math.min(10, Math.round(toFiniteNumber(callMediaPrefs.backgroundMaskVariant, 4))));
  const transitionGain = Math.max(1, Math.min(10, Math.round(toFiniteNumber(callMediaPrefs.backgroundBlurTransition, 10))));
  const requestedProcessWidth = Math.max(320, Math.min(1920, Math.round(toFiniteNumber(callMediaPrefs.backgroundMaxProcessWidth, 960))));
  const requestedProcessFps = Math.max(8, Math.min(30, Math.round(toFiniteNumber(callMediaPrefs.backgroundMaxProcessFps, 24))));
  let processWidthCap = 720;
  let processFpsCap = 15;
  if (qualityProfile === 'quality') {
    processWidthCap = 960;
    processFpsCap = 24;
  } else if (qualityProfile === 'realtime') {
    processWidthCap = 640;
    processFpsCap = 12;
  }

  return {
    mode,
    blurPx,
    detectIntervalMs,
    temporalSmoothingAlpha,
    preferFastMatte: qualityProfile !== 'quality',
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
  closeEnterAdmissionSocket({
    cancel: enterCallState.waitingForAdmission && !enterAdmissionAccepted,
  });
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
  enterCallState.waitingForAdmission = false;
  enterCallState.admissionMessage = '';
  closeEnterAdmissionSocket({ cancel: false });

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

async function openCallWorkspace(target = null) {
  const normalizedTarget = target && typeof target === 'object' ? target : {};
  enterCallState.callId = normalizeCallId(normalizedTarget.callId || enterCallState.callId);
  enterCallState.roomId = normalizeRoomId(normalizedTarget.roomId || enterCallState.roomId || 'lobby');
  startEnterAdmissionWait({
    callId: enterCallState.callId,
    roomId: enterCallState.roomId,
  });
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
    enterCallState.open = true;
    enterCallState.loading = true;
    enterCallState.error = '';
    enterCallState.callId = normalizeCallId(joinTarget.callId || '');
    enterCallState.roomId = normalizeRoomId(joinTarget.roomId || 'lobby');
    enterCallState.copyNotice = '';
    enterCallState.waitingForAdmission = false;
    enterCallState.admissionMessage = '';
    closeEnterAdmissionSocket({ cancel: false });
    try {
      await refreshCallMediaDevices({ requestPermissions: true });
      await startEnterCallPreview();
    } finally {
      enterCallState.loading = false;
    }
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

const shouldSendParticipants = computed(() => composeState.mode !== 'edit');

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
  composeParticipants.loading = false;
  composeParticipants.error = '';
  composeParticipants.query = '';
  composeParticipants.page = 1;
  composeParticipants.total = 0;
  composeParticipants.pageCount = 1;
  composeParticipants.hasPrev = false;
  composeParticipants.hasNext = false;
  composeParticipants.rows = [];
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
    composeState.accessMode = String(call.access_mode || 'invite_only').trim() || 'invite_only';
    composeState.roomId = String(call.room_id || 'lobby');
    composeState.startsLocal = isoToLocalInput(String(call.starts_at || ''));
    composeState.endsLocal = isoToLocalInput(String(call.ends_at || ''));
  } else {
    seedComposeWindow(mode);
    composeExternalRows.value = [nextExternalRow()];
  }

  if (shouldSendParticipants.value) {
    void loadComposeParticipants();
  }
}

function closeCompose() {
  composeState.open = false;
  composeState.submitting = false;
  composeState.error = '';
}

function handleShellCreateCall() {
  openCompose('create');
}

async function loadComposeParticipants() {
  if (!composeState.open) return;

  composeParticipants.loading = true;
  composeParticipants.error = '';

  try {
    const payload = await apiRequest('/api/user/directory', {
      query: {
        query: composeParticipants.query,
        page: composeParticipants.page,
        page_size: composeParticipants.pageSize,
      },
    });

    const ownUserId = currentSessionUserId();
    const allRows = Array.isArray(payload.users) ? payload.users : [];
    composeParticipants.rows = allRows.filter((row) => {
      const candidateId = Number(row?.id ?? row?.user_id ?? 0);
      return !Number.isInteger(candidateId) || candidateId !== ownUserId;
    });
    const paging = payload.pagination || {};
    composeParticipants.total = Number.isInteger(paging.total) ? paging.total : composeParticipants.rows.length;
    composeParticipants.pageCount = Number.isInteger(paging.page_count) && paging.page_count > 0
      ? paging.page_count
      : 1;
    composeParticipants.hasPrev = Boolean(paging.has_prev);
    composeParticipants.hasNext = Boolean(paging.has_next);
    if (ownUserId > 0) {
      composeSelectedUserIds.value = composeSelectedUserIds.value.filter((id) => Number(id) !== ownUserId);
    }
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
  const ownUserId = currentSessionUserId();
  if (!Number.isInteger(id) || id <= 0 || id === ownUserId) {
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
  startAdminSyncSocket();
  startFallbackRefreshLoop();
  window.addEventListener('keydown', handleEscape);
  window.addEventListener(USER_CALL_CREATE_EVENT, handleShellCreateCall);

  void Promise.all([loadCalls(), loadCalendar()]);
});

onBeforeUnmount(() => {
  clearAdminSyncReloadTimer();
  stopAdminSyncSocket();
  stopFallbackRefreshLoop();
  window.removeEventListener('keydown', handleEscape);
  window.removeEventListener(USER_CALL_CREATE_EVENT, handleShellCreateCall);
  if (typeof detachCallMediaWatcher === 'function') {
    detachCallMediaWatcher();
    detachCallMediaWatcher = null;
  }
  closeEnterAdmissionSocket({
    cancel: enterCallState.waitingForAdmission && !enterAdmissionAccepted,
  });
  stopEnterCallPreview();
});

watch(
  () => sessionState.sessionToken,
  (nextValue, previousValue) => {
    const nextToken = String(nextValue || '').trim();
    const previousToken = String(previousValue || '').trim();
    if (nextToken === previousToken) return;
    if (!adminSyncClient) return;

    if (nextToken === '') {
      adminSyncClient.disconnect();
      return;
    }

    adminSyncClient.reconnect();
  }
);
</script>

<style scoped src="./UserDashboardView.css"></style>
