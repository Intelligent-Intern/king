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
        <h1>Video Call Management</h1>
      </div>
      <div class="actions">
        <button class="btn btn-cyan" type="button" @click="openPrimaryCompose">{{ primaryActionLabel }}</button>
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
        <label class="calls-search calls-search-main" aria-label="Call search">
          <input
            v-model="queryDraft"
            class="input"
            type="search"
            placeholder="Search call title"
            @keydown.enter.prevent="applyFilters"
          />
        </label>

        <AppSelect v-model="statusFilter" @change="applyFilters">
          <option value="all">All status</option>
          <option value="scheduled">Scheduled</option>
          <option value="active">Active</option>
          <option value="ended">Ended</option>
          <option value="cancelled">Cancelled</option>
        </AppSelect>

        <AppSelect v-model="scopeFilter" @change="applyFilters">
          <option value="all">All scope</option>
          <option value="my">My scope</option>
        </AppSelect>

        <button
          class="icon-mini-btn calls-toolbar-search-btn"
          type="button"
          title="Search calls"
          aria-label="Search calls"
          @click="applyFilters"
        >
          <img src="/assets/orgas/kingrt/icons/send.png" alt="" />
        </button>
      </div>
    </section>

    <section v-if="noticeMessage" class="section calls-banner" :class="noticeKindClass">
      {{ noticeMessage }}
    </section>

    <section v-if="viewMode === 'calls'" class="table-wrap calls-table-wrap">
      <CallsListTable
        :calls="calls"
        :format-range="formatRange"
        :status-tag-class="statusTagClass"
        :is-editable="isEditable"
        :is-invitable="isInvitable"
        :is-cancellable="isCancellable"
        :is-deletable="isDeletable"
        admin-mode
        @edit-call="(call) => openCompose('edit', call)"
        @open-chat-archive="openChatArchive"
        @enter-call="openEnterCallModal"
        @cancel-call="openCancel"
        @delete-call="openDelete"
      />

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

    <section v-if="viewMode === 'calls'" class="footer calls-pagination-wrap">
      <AppPagination
        :page="pagination.page"
        :page-count="pagination.pageCount"
        :total="pagination.total"
        total-label="total"
        :has-prev="pagination.hasPrev"
        :has-next="pagination.hasNext"
        :disabled="loadingCalls"
        @page-change="goToPage"
      />
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
                      id="admin-enter-call-camera-select"
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
                      id="admin-enter-call-mic-select"
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
                    <label for="admin-enter-call-mic-volume">Volume</label>
                    <div class="call-left-volume-row">
                      <input
                        id="admin-enter-call-mic-volume"
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
                      id="admin-enter-call-speaker-select"
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
                    <label for="admin-enter-call-speaker-volume">Volume</label>
                    <div class="call-left-volume-row">
                      <input
                        id="admin-enter-call-speaker-volume"
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

        <footer class="calls-modal-footer">
          <button
            class="btn btn-cyan"
            type="button"
            :disabled="enterCallState.loading"
            @click="openCallWorkspace({ callId: enterCallState.callId, roomId: enterCallState.roomId })"
          >
            Join call
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
            <label v-if="composeState.mode === 'edit'" class="field">
              <span>Access mode</span>
              <AppSelect v-model="composeState.accessMode" aria-label="Call access mode">
                <option value="invite_only">Invite only</option>
                <option value="free_for_all">Free for all</option>
              </AppSelect>
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
                  <button class="btn btn-cyan" type="button" @click="applyParticipantSearch">Search</button>
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

          <section v-else class="calls-inline-hint">
            Existing participants remain unchanged for this edit.
          </section>

          <section v-if="composeState.error" class="calls-inline-error">
            {{ composeState.error }}
          </section>
        </div>

        <footer class="calls-modal-footer">
          <button class="btn btn-cyan" type="button" :disabled="composeState.submitting" @click="submitCompose">
            {{ composeState.submitting ? 'Saving…' : composeSubmitLabel }}
          </button>
        </footer>
      </div>
    </div>

    <div class="calls-modal" :hidden="!cancelState.open" role="dialog" aria-modal="true" aria-label="Cancel call modal">
      <div class="calls-modal-backdrop" @click="closeCancel"></div>
      <div class="calls-modal-dialog calls-modal-dialog-cancel">
        <header class="calls-modal-header calls-modal-header-enter">
          <div class="calls-modal-header-enter-left">
            <img class="calls-modal-header-enter-logo" src="/assets/orgas/kingrt/logo.svg" alt="" />
            <h4 class="calls-enter-title">Cancel call</h4>
          </div>
          <button class="icon-mini-btn" type="button" aria-label="Close" @click="closeCancel">
            <img src="/assets/orgas/kingrt/icons/cancel.png" alt="" />
          </button>
        </header>

        <div class="calls-modal-body calls-cancel-body">
          <p class="calls-inline-hint">
            Cancelling <strong>{{ cancelState.callTitle }}</strong> marks all participants as cancelled.
          </p>

          <label class="field">
            <span>Cancel reason</span>
            <AppSelect
              v-if="cancelState.overrideTemplate"
              v-model="cancelState.selectedTemplateId"
              aria-label="Cancel reason template"
              @change="applyCancelTemplate(cancelState.selectedTemplateId)"
            >
              <option v-for="template in cancelTemplates" :key="template.reason" :value="template.reason">
                {{ template.label }}
              </option>
            </AppSelect>
            <input
              v-else
              v-model.trim="cancelState.customReason"
              class="input"
              type="text"
              placeholder="Type custom reason"
              aria-label="Cancel reason"
            />
          </label>

          <label class="field">
            <span>Cancel message</span>
            <div class="calls-cancel-template-toolbar" role="toolbar" aria-label="Cancel message formatting">
              <button class="btn" type="button" @mousedown.prevent @click="execCancelEditorCommand('bold')">Bold</button>
              <button class="btn" type="button" @mousedown.prevent @click="execCancelEditorCommand('italic')">Italic</button>
              <button class="btn" type="button" @mousedown.prevent @click="execCancelEditorCommand('underline')">Underline</button>
              <button class="btn" type="button" @mousedown.prevent @click="execCancelEditorCommand('insertUnorderedList')">List</button>
              <button class="btn" type="button" @mousedown.prevent @click="execCancelEditorCommand('removeFormat')">Clear</button>
            </div>
            <div
              ref="cancelEditorRef"
              class="calls-rich-editor"
              contenteditable="true"
              role="textbox"
              aria-multiline="true"
              aria-label="Cancel message editor"
              @input="handleCancelEditorInput"
            ></div>
          </label>

          <section v-if="cancelState.error" class="calls-inline-error">
            {{ cancelState.error }}
          </section>
        </div>

        <footer class="calls-modal-footer calls-cancel-footer">
          <label class="calls-checkbox-row calls-cancel-override-row">
            <input
              :checked="cancelState.overrideTemplate"
              type="checkbox"
              @change="toggleCancelOverride($event.target.checked)"
            />
            <span>Override</span>
          </label>
          <button
            v-if="cancelState.overrideTemplate && cancelState.templateDirty"
            class="btn btn-cyan"
            type="button"
            :disabled="cancelState.submitting || cancelState.templateSaving"
            @click="saveCancelTemplate"
          >
            {{ cancelState.templateSaving ? 'Saving…' : 'Save template' }}
          </button>
          <button class="btn btn-danger" type="button" :disabled="cancelState.submitting" @click="submitCancel">
            {{ cancelState.submitting ? 'Cancelling…' : 'Cancel call' }}
          </button>
        </footer>
      </div>
    </div>

    <div class="calls-modal" :hidden="!deleteState.open" role="dialog" aria-modal="true" aria-label="Delete call modal">
      <div class="calls-modal-backdrop" @click="closeDelete"></div>
      <div class="calls-modal-dialog calls-modal-dialog-small">
        <header class="calls-modal-header calls-modal-header-enter">
          <div class="calls-modal-header-enter-left">
            <img class="calls-modal-header-enter-logo" src="/assets/orgas/kingrt/logo.svg" alt="" />
            <h4 class="calls-enter-title">Delete call</h4>
          </div>
          <button class="icon-mini-btn" type="button" aria-label="Close" @click="closeDelete">
            <img src="/assets/orgas/kingrt/icons/cancel.png" alt="" />
          </button>
        </header>

        <div class="calls-modal-body">
          <p class="calls-delete-warning">
            Delete <strong>{{ deleteState.callTitle }}</strong> permanently?
            <br />
            <br />
            This removes participants, access links and invite codes for this call.
          </p>

          <section v-if="deleteState.error" class="calls-inline-error">
            {{ deleteState.error }}
          </section>
        </div>

        <footer class="calls-modal-footer">
          <button class="btn" type="button" :disabled="deleteState.submitting" @click="submitDelete">
            {{ deleteState.submitting ? 'Deleting…' : 'Delete call' }}
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
import { computed, inject, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { Calendar } from '@fullcalendar/core';
import dayGridPlugin from '@fullcalendar/daygrid';
import timeGridPlugin from '@fullcalendar/timegrid';
import interactionPlugin from '@fullcalendar/interaction';
import AppPagination from '../../../components/AppPagination.vue';
import AppSelect from '../../../components/AppSelect.vue';
import ChatArchiveModal from '../components/ChatArchiveModal.vue';
import CallsListTable from '../components/ListTable.vue';
import {
  createCallListStore,
  createChatArchiveStore,
  createNoticeStore,
  createParticipantDirectoryStore,
} from '../dashboard/viewState';
import { sessionState } from '../../auth/session';
import { currentBackendOrigin, fetchBackend } from '../../../support/backendFetch';
import { formatDateRangeDisplay, formatDateTimeDisplay, fullCalendarEventTimeFormat } from '../../../support/dateTimeFormat';
import { createAdminSyncSocket } from '../../../support/adminSyncSocket';
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
} from '../../realtime/media/preferences';
import { BackgroundFilterController } from '../../realtime/background/controller';

const router = useRouter();
const applyBackgroundPreset = applyCallBackgroundPreset;
const isBackgroundPresetActive = isCallBackgroundPresetActive;
const workspaceSidebarState = inject('workspaceSidebarState', null);

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
    separator: ' → ',
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

function isDeletable(call) {
  return Boolean(call?.id);
}

function isInvitable(call) {
  const status = String(call?.status || '').toLowerCase();
  return status !== 'cancelled' && status !== 'ended';
}

const callListStore = createCallListStore({ defaultScope: 'all' });
const {
  viewMode,
  queryDraft,
  queryApplied,
  statusFilter,
  scopeFilter,
  calls,
  loadingCalls,
  callsError,
  pagination,
  calendarCalls,
  loadingCalendar,
  calendarError,
} = callListStore;
const primaryActionLabel = computed(() => (viewMode.value === 'calendar'
  ? 'Schedule video call'
  : 'New video call'));

function openPrimaryCompose() {
  openCompose(viewMode.value === 'calendar' ? 'schedule' : 'create');
}

const {
  noticeKind,
  noticeMessage,
  noticeKindClass,
  setNotice,
  clearNotice,
} = createNoticeStore();
const chatArchiveStore = createChatArchiveStore();
const chatArchiveState = chatArchiveStore.state;
const { openChatArchive, closeChatArchive } = chatArchiveStore;

let adminSyncReloadTimer = 0;
let adminSyncClient = null;

function clearAdminSyncReloadTimer() {
  if (adminSyncReloadTimer > 0) {
    window.clearTimeout(adminSyncReloadTimer);
    adminSyncReloadTimer = 0;
  }
}

async function reloadCallsFromAdminSync() {
  await Promise.all([
    loadCalls(),
    loadCalendar({ background: true }),
  ]);
}

function queueReloadCallsFromAdminSync() {
  if (adminSyncReloadTimer > 0) return;
  adminSyncReloadTimer = window.setTimeout(() => {
    adminSyncReloadTimer = 0;
    void reloadCallsFromAdminSync();
  }, 120);
}

function publishAdminSync(topic, reason) {
  if (!adminSyncClient) return;
  adminSyncClient.publish(topic, reason);
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
    callListStore.applyPagination(payload.pagination || {}, calls.value.length);
  } catch (error) {
    calls.value = [];
    callsError.value = error instanceof Error ? error.message : 'Could not load calls.';
    callListStore.resetPagination();
  } finally {
    loadingCalls.value = false;
  }
}

async function loadCalendar({ background = false } = {}) {
  const hasVisibleCalendarData = Array.isArray(calendarCalls.value) && calendarCalls.value.length > 0;
  const useBlockingLoadingState = !background || !hasVisibleCalendarData;

  if (useBlockingLoadingState) {
    loadingCalendar.value = true;
    calendarError.value = '';
  }

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
    calendarError.value = '';
  } catch (error) {
    if (useBlockingLoadingState) {
      calendarCalls.value = [];
      calendarError.value = error instanceof Error ? error.message : 'Could not load calendar calls.';
    }
  } finally {
    if (useBlockingLoadingState) {
      loadingCalendar.value = false;
    }
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
      editable: isEditable(call),
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

function resolveCalendarEventCall(eventApi) {
  const payloadCall = eventApi?.extendedProps?.callPayload;
  if (payloadCall && typeof payloadCall === 'object') {
    return payloadCall;
  }

  const callId = String(eventApi?.id || '').trim();
  if (callId === '') {
    return null;
  }

  for (const call of calendarCalls.value) {
    if (String(call?.id || '') === callId) {
      return call;
    }
  }

  return null;
}

async function persistCalendarEventWindow(eventApi, revert) {
  const call = resolveCalendarEventCall(eventApi);
  const callId = String(call?.id || eventApi?.id || '').trim();
  if (callId === '') {
    if (typeof revert === 'function') revert();
    setNotice('error', 'Could not update call schedule (missing call id).');
    return;
  }

  if (!isEditable(call)) {
    if (typeof revert === 'function') revert();
    setNotice('error', 'Only scheduled and active calls can be rescheduled.');
    return;
  }

  const startDate = eventApi?.start instanceof Date ? new Date(eventApi.start.getTime()) : null;
  let endDate = eventApi?.end instanceof Date ? new Date(eventApi.end.getTime()) : null;
  if (!(startDate instanceof Date) || Number.isNaN(startDate.getTime())) {
    if (typeof revert === 'function') revert();
    setNotice('error', 'Could not update call schedule (invalid start timestamp).');
    return;
  }

  if (!(endDate instanceof Date) || Number.isNaN(endDate.getTime())) {
    const fallbackStart = new Date(String(call?.starts_at || ''));
    const fallbackEnd = new Date(String(call?.ends_at || ''));
    if (!Number.isNaN(fallbackStart.getTime()) && !Number.isNaN(fallbackEnd.getTime()) && fallbackEnd.getTime() > fallbackStart.getTime()) {
      endDate = new Date(startDate.getTime() + (fallbackEnd.getTime() - fallbackStart.getTime()));
    }
  }

  if (!(endDate instanceof Date) || Number.isNaN(endDate.getTime()) || endDate.getTime() <= startDate.getTime()) {
    if (typeof revert === 'function') revert();
    setNotice('error', 'End timestamp must be after start timestamp.');
    return;
  }

  const startsAt = startDate.toISOString();
  const endsAt = endDate.toISOString();

  try {
    await apiRequest(`/api/calls/${encodeURIComponent(callId)}`, {
      method: 'PATCH',
      body: {
        starts_at: startsAt,
        ends_at: endsAt,
      },
    });

    if (call && typeof call === 'object') {
      call.starts_at = startsAt;
      call.ends_at = endsAt;
    }
    setNotice('ok', 'Call schedule updated.');
    publishAdminSync('calls', 'call_schedule_updated');
    await Promise.all([loadCalls(), loadCalendar({ background: true })]);
  } catch (error) {
    if (typeof revert === 'function') revert();
    setNotice('error', error instanceof Error ? error.message : 'Could not update call schedule.');
  }
}

function handleCalendarEventMoveOrResize(info) {
  if (!info || !info.event) return;
  const revert = typeof info.revert === 'function' ? info.revert : null;
  void persistCalendarEventWindow(info.event, revert);
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
      eventTimeFormat: fullCalendarEventTimeFormat(sessionState.timeFormat),
      selectable: true,
      editable: true,
      eventStartEditable: true,
      eventDurationEditable: true,
      eventResizableFromStart: true,
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
      eventDrop(info) {
        handleCalendarEventMoveOrResize(info);
      },
      eventResize(info) {
        handleCalendarEventMoveOrResize(info);
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

const enterCallPreviewVideoRef = ref(null);
const enterCallPreviewRawStreamRef = ref(null);
const enterCallPreviewStreamRef = ref(null);
const enterCallPreviewBackgroundController = new BackgroundFilterController();
let detachCallMediaWatcher = null;
let enterCallPreviewResizeHandler = null;
const callAccessLinkEndpointAvailable = ref(true);

const enterCallState = reactive({
  open: false,
  loading: false,
  error: '',
  linkUrl: '',
  expiresAt: '',
  callId: '',
  roomId: '',
  callAccessMode: 'invite_only',
  targetKey: '',
  targetOptions: [],
  copyNotice: '',
  previewReady: false,
  previewError: '',
  previewAspectRatio: '16 / 9',
});

function resetEnterCallState() {
  enterCallState.loading = false;
  enterCallState.error = '';
  enterCallState.linkUrl = '';
  enterCallState.expiresAt = '';
  enterCallState.callId = '';
  enterCallState.roomId = '';
  enterCallState.callAccessMode = 'invite_only';
  enterCallState.targetKey = '';
  enterCallState.targetOptions = [];
  enterCallState.copyNotice = '';
  enterCallState.previewReady = false;
  enterCallState.previewError = '';
  enterCallState.previewAspectRatio = '16 / 9';
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

function updateEnterCallPreviewAspectRatio() {
  if (typeof window === 'undefined') return;
  const width = Math.max(1, Number(window.innerWidth || 0));
  const height = Math.max(1, Number(window.innerHeight || 0));
  enterCallState.previewAspectRatio = `${width} / ${height}`;
}

function looksLikeNotFoundError(error) {
  const message = (error instanceof Error ? error.message : String(error || '')).toLowerCase();
  return message.includes('404') || message.includes('not found');
}

function fallbackWorkspaceLink(callId) {
  const normalizedCallId = String(callId || '').trim();
  const joinPath = `/workspace/call/${encodeURIComponent(normalizedCallId)}`;
  const origin = typeof window !== 'undefined' ? String(window.location.origin || '').trim() : '';
  return origin !== '' ? `${origin}${joinPath}` : joinPath;
}

function normalizeCallAccessMode(value) {
  const normalized = String(value || '').trim().toLowerCase();
  return normalized === 'free_for_all' ? 'free_for_all' : 'invite_only';
}

function normalizeTargetOptionsFromCall(call) {
  const options = [];
  const seen = new Set();
  const internalRows = Array.isArray(call?.participants?.internal) ? call.participants.internal : [];
  for (const row of internalRows) {
    const userId = Number(row?.user_id || 0);
    if (!Number.isInteger(userId) || userId <= 0) continue;
    const key = `user:${userId}`;
    if (seen.has(key)) continue;
    seen.add(key);
    const labelName = String(row?.display_name || row?.email || `User ${userId}`).trim();
    const labelEmail = String(row?.email || '').trim();
    options.push({
      key,
      label: labelEmail !== '' ? `${labelName} · ${labelEmail}` : labelName,
    });
  }
  const externalRows = Array.isArray(call?.participants?.external) ? call.participants.external : [];
  for (const row of externalRows) {
    const email = String(row?.email || '').trim().toLowerCase();
    if (email === '') continue;
    const key = `email:${email}`;
    if (seen.has(key)) continue;
    seen.add(key);
    const labelName = String(row?.display_name || email).trim();
    options.push({
      key,
      label: `${labelName} · ${email}`,
    });
  }

  if (options.length === 0 && Number.isInteger(sessionState.userId) && sessionState.userId > 0) {
    options.push({
      key: `user:${sessionState.userId}`,
      label: `${String(sessionState.displayName || sessionState.email || `User ${sessionState.userId}`).trim()} · ${String(sessionState.email || '').trim()}`,
    });
  }

  return options;
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
  enterCallState.linkUrl = '';
  enterCallState.expiresAt = '';
  enterCallState.callId = String(call.id);
  enterCallState.roomId = String(call.room_id || 'lobby');
  enterCallState.callAccessMode = normalizeCallAccessMode(call.access_mode);
  enterCallState.targetOptions = normalizeTargetOptionsFromCall(call);
  enterCallState.targetKey = enterCallState.targetOptions[0]?.key || '';
  enterCallState.copyNotice = '';
  updateEnterCallPreviewAspectRatio();

  try {
    await refreshCallMediaDevices({ requestPermissions: true });
    await startEnterCallPreview();
  } finally {
    enterCallState.loading = false;
  }
}

async function generateEnterCallLink() {
  const callId = String(enterCallState.callId || '').trim();
  if (callId === '') {
    enterCallState.loading = false;
    enterCallState.error = 'Missing call id.';
    return;
  }

  enterCallState.loading = true;
  enterCallState.error = '';
  enterCallState.linkUrl = '';
  enterCallState.expiresAt = '';

  if (!callAccessLinkEndpointAvailable.value) {
    enterCallState.linkUrl = fallbackWorkspaceLink(callId);
    enterCallState.loading = false;
    return;
  }

  const requestBody = {};
  const callAccessMode = normalizeCallAccessMode(enterCallState.callAccessMode);
  if (callAccessMode === 'free_for_all') {
    requestBody.link_kind = 'open';
  } else {
    requestBody.link_kind = 'personal';
    const targetKey = String(enterCallState.targetKey || '').trim();
    if (targetKey.startsWith('user:')) {
      const parsed = Number(targetKey.slice(5));
      if (Number.isInteger(parsed) && parsed > 0) {
        requestBody.participant_user_id = parsed;
      }
    } else if (targetKey.startsWith('email:')) {
      const email = targetKey.slice(6).trim().toLowerCase();
      if (email !== '') {
        requestBody.participant_email = email;
      }
    }
  }

  try {
    const payload = await apiRequest(`/api/calls/${encodeURIComponent(callId)}/access-link`, {
      method: 'POST',
      body: requestBody,
    });

    const result = payload?.result || {};
    const accessId = String(result?.access_link?.id || '').trim();
    const joinPathRaw = String(result?.join_path || '').trim();
    const joinPath = joinPathRaw !== '' ? joinPathRaw : (accessId !== '' ? `/join/${accessId}` : '');
    if (joinPath === '') {
      throw new Error('Invite link payload is invalid.');
    }
    const origin = typeof window !== 'undefined' ? String(window.location.origin || '').trim() : '';
    enterCallState.linkUrl = origin !== '' ? `${origin}${joinPath}` : joinPath;
    enterCallState.expiresAt = typeof result?.access_link?.expires_at === 'string' ? result.access_link.expires_at : '';
  } catch (error) {
    if (looksLikeNotFoundError(error)) {
      callAccessLinkEndpointAvailable.value = false;
      enterCallState.linkUrl = fallbackWorkspaceLink(callId);
      enterCallState.error = '';
      enterCallState.expiresAt = '';
      return;
    }
    enterCallState.error = error instanceof Error ? error.message : 'Could not create invite link.';
  } finally {
    enterCallState.loading = false;
  }
}

function handleEnterLinkSettingsChanged() {
  enterCallState.copyNotice = '';
  void generateEnterCallLink();
}

async function copyInviteCode() {
  const link = String(enterCallState.linkUrl || '').trim();
  if (link === '') {
    return;
  }

  try {
    if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
      await navigator.clipboard.writeText(link);
    } else {
      const textarea = document.createElement('textarea');
      textarea.value = link;
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

function sanitizeCancelMessageHtml(value) {
  const html = String(value || '');
  if (typeof window === 'undefined') {
    return html.trim();
  }

  const container = document.createElement('div');
  container.innerHTML = html;
  for (const node of container.querySelectorAll('script,style')) {
    node.remove();
  }
  for (const element of container.querySelectorAll('*')) {
    for (const attribute of Array.from(element.attributes)) {
      const attributeName = String(attribute.name || '').toLowerCase();
      if (attributeName.startsWith('on')) {
        element.removeAttribute(attribute.name);
      }
    }
  }

  return container.innerHTML.trim();
}

function normalizeCancelMessageHtml(value) {
  return sanitizeCancelMessageHtml(value).replace(/>\s+</g, '><').trim();
}

function cancelMessageHtmlToPlainText(value) {
  const html = String(value || '');
  if (typeof window === 'undefined') {
    return html.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
  }

  const container = document.createElement('div');
  container.innerHTML = html;
  return String(container.textContent || '').replace(/\s+/g, ' ').trim();
}

function prettifyCancelReason(reason) {
  const normalized = String(reason || '').trim().replace(/[_-]+/g, ' ');
  if (normalized === '') return 'Custom template';
  return normalized.charAt(0).toUpperCase() + normalized.slice(1);
}

const CANCEL_TEMPLATE_STORAGE_KEY = 'king.video.calls.cancel.templates.v1';
const DEFAULT_CANCEL_TEMPLATES = Object.freeze([
  {
    reason: 'scheduler_conflict',
    label: 'Scheduler conflict',
    messageHtml: '<p>Call cancelled due to scheduling conflict.</p>',
  },
  {
    reason: 'host_unavailable',
    label: 'Host unavailable',
    messageHtml: '<p>Call cancelled because the host is currently unavailable.</p>',
  },
  {
    reason: 'technical_issue',
    label: 'Technical issue',
    messageHtml: '<p>Call cancelled due to a technical issue. We will reschedule shortly.</p>',
  },
  {
    reason: 'emergency_stop',
    label: 'Emergency stop',
    messageHtml: '<p>Call cancelled due to an urgent operational reason.</p>',
  },
]);

function normalizeCancelTemplateItem(rawTemplate, index) {
  const rawReason = String(rawTemplate?.reason || '').trim().toLowerCase();
  const reason = rawReason.replace(/[^a-z0-9_]+/g, '_').replace(/^_+|_+$/g, '');
  if (reason === '') {
    return null;
  }

  const fallbackLabel = prettifyCancelReason(reason);
  const label = String(rawTemplate?.label || '').trim() || fallbackLabel;
  const rawMessage = String(rawTemplate?.messageHtml || rawTemplate?.message || '').trim();
  const messageHtml = normalizeCancelMessageHtml(rawMessage || `<p>${fallbackLabel}.</p>`);

  return {
    id: `${reason}-${index}`,
    reason,
    label,
    messageHtml,
  };
}

function cloneCancelTemplateList(list) {
  return list
    .map((entry, index) => normalizeCancelTemplateItem(entry, index))
    .filter((entry) => entry !== null);
}

function loadCancelTemplates() {
  const fallback = cloneCancelTemplateList(DEFAULT_CANCEL_TEMPLATES);
  if (typeof window === 'undefined') {
    return fallback;
  }

  try {
    const raw = window.localStorage.getItem(CANCEL_TEMPLATE_STORAGE_KEY);
    if (typeof raw !== 'string' || raw.trim() === '') {
      return fallback;
    }
    const parsed = JSON.parse(raw);
    if (!Array.isArray(parsed)) {
      return fallback;
    }
    const templates = cloneCancelTemplateList(parsed);
    return templates.length > 0 ? templates : fallback;
  } catch {
    return fallback;
  }
}

function persistCancelTemplates(templates) {
  if (typeof window === 'undefined') return;
  try {
    window.localStorage.setItem(
      CANCEL_TEMPLATE_STORAGE_KEY,
      JSON.stringify(
        templates.map((template) => ({
          reason: template.reason,
          label: template.label,
          messageHtml: template.messageHtml,
        })),
      ),
    );
  } catch {
    // ignore storage failures
  }
}

const cancelTemplates = ref(loadCancelTemplates());
const cancelEditorRef = ref(null);

const cancelState = reactive({
  open: false,
  submitting: false,
  templateSaving: false,
  error: '',
  callId: '',
  callTitle: '',
  overrideTemplate: true,
  selectedTemplateId: '',
  customReason: '',
  reason: '',
  messageHtml: '',
  templateDirty: false,
});

const deleteState = reactive({
  open: false,
  submitting: false,
  error: '',
  callId: '',
  callTitle: '',
});

function openCancel(call) {
  clearNotice();
  closeEnterCallModal();
  cancelState.open = true;
  cancelState.submitting = false;
  cancelState.templateSaving = false;
  cancelState.error = '';
  cancelState.callId = String(call?.id || '');
  cancelState.callTitle = String(call?.title || call?.id || '');
  cancelState.overrideTemplate = true;
  cancelState.customReason = '';
  const preferredTemplate = findCancelTemplate('scheduler_conflict');
  const defaultTemplate = preferredTemplate || cancelTemplates.value[0] || null;
  cancelState.selectedTemplateId = defaultTemplate ? defaultTemplate.reason : '';
  applyCancelTemplate(cancelState.selectedTemplateId);
}

function closeCancel() {
  cancelState.open = false;
  cancelState.submitting = false;
  cancelState.templateSaving = false;
  cancelState.error = '';
}

function findCancelTemplate(reason) {
  const normalizedReason = String(reason || '').trim().toLowerCase();
  if (normalizedReason === '') return null;
  return cancelTemplates.value.find((template) => template.reason === normalizedReason) || null;
}

function syncCancelEditorFromState() {
  const normalizedHtml = normalizeCancelMessageHtml(cancelState.messageHtml);
  cancelState.messageHtml = normalizedHtml;
  nextTick(() => {
    const editor = cancelEditorRef.value;
    if (!(editor instanceof HTMLElement)) return;
    if (editor.innerHTML !== normalizedHtml) {
      editor.innerHTML = normalizedHtml;
    }
  });
}

function refreshCancelTemplateDirty() {
  if (!cancelState.overrideTemplate) {
    cancelState.templateDirty = false;
    return;
  }

  const template = findCancelTemplate(cancelState.selectedTemplateId);
  if (!template) {
    cancelState.templateDirty = false;
    return;
  }

  const currentHtml = normalizeCancelMessageHtml(cancelState.messageHtml);
  const templateHtml = normalizeCancelMessageHtml(template.messageHtml);
  cancelState.templateDirty = currentHtml !== templateHtml;
}

function applyCancelTemplate(reason) {
  const template = findCancelTemplate(reason);
  if (!template) {
    cancelState.reason = String(reason || '').trim();
    cancelState.messageHtml = '';
    cancelState.templateDirty = false;
    syncCancelEditorFromState();
    return;
  }

  cancelState.selectedTemplateId = template.reason;
  cancelState.reason = template.reason;
  cancelState.messageHtml = template.messageHtml;
  cancelState.templateDirty = false;
  syncCancelEditorFromState();
}

function handleCancelEditorInput() {
  const editor = cancelEditorRef.value;
  if (!(editor instanceof HTMLElement)) return;
  cancelState.messageHtml = normalizeCancelMessageHtml(editor.innerHTML);
  refreshCancelTemplateDirty();
}

function execCancelEditorCommand(commandName, commandValue = null) {
  if (typeof document === 'undefined') return;
  const editor = cancelEditorRef.value;
  if (!(editor instanceof HTMLElement)) return;
  editor.focus();
  document.execCommand(commandName, false, commandValue);
  handleCancelEditorInput();
}

function toggleCancelOverride(nextValue) {
  cancelState.overrideTemplate = Boolean(nextValue);
  cancelState.error = '';

  if (!cancelState.overrideTemplate) {
    cancelState.customReason = cancelState.reason || cancelState.selectedTemplateId || '';
    cancelState.templateDirty = false;
    return;
  }

  const defaultReason = String(cancelState.selectedTemplateId || cancelTemplates.value[0]?.reason || '').trim();
  if (defaultReason !== '') {
    applyCancelTemplate(defaultReason);
  }
}

async function saveCancelTemplate() {
  cancelState.error = '';
  if (!cancelState.overrideTemplate) return;

  const template = findCancelTemplate(cancelState.selectedTemplateId);
  if (!template) {
    cancelState.error = 'Select a template first.';
    return;
  }

  const messageHtml = normalizeCancelMessageHtml(cancelState.messageHtml);
  const plainText = cancelMessageHtmlToPlainText(messageHtml);
  if (plainText === '') {
    cancelState.error = 'Cancel message is required.';
    return;
  }

  cancelState.templateSaving = true;
  try {
    const nextTemplates = cancelTemplates.value
      .map((entry, index) => {
        if (entry.reason !== template.reason) return entry;
        return normalizeCancelTemplateItem({
          reason: entry.reason,
          label: entry.label,
          messageHtml,
        }, index);
      })
      .filter((entry) => entry !== null);

    cancelTemplates.value = nextTemplates;
    persistCancelTemplates(nextTemplates);
    cancelState.templateDirty = false;
  } finally {
    cancelState.templateSaving = false;
  }
}

function openDelete(call) {
  if (!call || !call.id || !isDeletable(call)) {
    return;
  }

  clearNotice();
  closeEnterCallModal();
  deleteState.open = true;
  deleteState.submitting = false;
  deleteState.error = '';
  deleteState.callId = String(call.id || '');
  deleteState.callTitle = String(call.title || call.id || '');
}

function closeDelete() {
  deleteState.open = false;
  deleteState.submitting = false;
  deleteState.error = '';
}

async function submitCancel() {
  cancelState.error = '';
  clearNotice();

  const reason = cancelState.overrideTemplate
    ? cancelState.reason.trim()
    : cancelState.customReason.trim();
  const message = cancelMessageHtmlToPlainText(cancelState.messageHtml).trim();
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
    publishAdminSync('calls', 'call_cancelled');
    await Promise.all([loadCalls(), loadCalendar()]);
  } catch (error) {
    cancelState.error = error instanceof Error ? error.message : 'Could not cancel call.';
  } finally {
    cancelState.submitting = false;
  }
}

async function submitDelete() {
  deleteState.error = '';
  clearNotice();

  const callId = String(deleteState.callId || '').trim();
  if (callId === '') {
    deleteState.error = 'Missing call id.';
    return;
  }

  deleteState.submitting = true;
  try {
    await apiRequest(`/api/calls/${encodeURIComponent(callId)}`, {
      method: 'DELETE',
    });

    closeDelete();
    setNotice('ok', 'Call deleted.');
    publishAdminSync('calls', 'call_deleted');
    await Promise.all([loadCalls(), loadCalendar()]);
  } catch (error) {
    deleteState.error = error instanceof Error ? error.message : 'Could not delete call.';
  } finally {
    deleteState.submitting = false;
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

  if (deleteState.open) {
    closeDelete();
    return;
  }

  if (enterCallState.open) {
    closeEnterCallModal();
  }
}

onMounted(() => {
  detachCallMediaWatcher = attachCallMediaDeviceWatcher({ requestPermissions: false });
  startAdminSyncSocket();
  updateEnterCallPreviewAspectRatio();
  enterCallPreviewResizeHandler = () => updateEnterCallPreviewAspectRatio();
  window.addEventListener('resize', enterCallPreviewResizeHandler);
  window.addEventListener('orientationchange', enterCallPreviewResizeHandler);
  window.addEventListener('keydown', handleEscape);

  void Promise.all([loadCalls(), loadCalendar()]);
});

onBeforeUnmount(() => {
  clearAdminSyncReloadTimer();
  stopAdminSyncSocket();
  if (typeof enterCallPreviewResizeHandler === 'function') {
    window.removeEventListener('resize', enterCallPreviewResizeHandler);
    window.removeEventListener('orientationchange', enterCallPreviewResizeHandler);
    enterCallPreviewResizeHandler = null;
  }
  window.removeEventListener('keydown', handleEscape);
  if (typeof detachCallMediaWatcher === 'function') {
    detachCallMediaWatcher();
    detachCallMediaWatcher = null;
  }
  stopEnterCallPreview();
  if (calendarInstance) {
    calendarInstance.destroy();
    calendarInstance = null;
  }
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

watch(
  () => sessionState.timeFormat,
  () => {
    if (!calendarInstance) return;
    calendarInstance.setOption('eventTimeFormat', fullCalendarEventTimeFormat(sessionState.timeFormat));
  }
);
</script>

<style scoped src="./CallsView.css"></style>
<style scoped src="./CallsViewResponsive.css"></style>
