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
                <button
                  class="icon-mini-btn danger"
                  type="button"
                  title="Delete call"
                  :aria-label="`Delete call ${call.title || call.id}`"
                  :disabled="!isDeletable(call)"
                  @click="openDelete(call)"
                >
                  <img src="/assets/orgas/kingrt/icons/remove_user.png" alt="" />
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

    <div class="calls-modal" :hidden="!composeState.open" role="dialog" aria-modal="true" aria-label="Call compose modal">
      <div class="calls-modal-backdrop" @click="closeCompose"></div>
      <div class="calls-modal-dialog">
        <header class="calls-modal-header calls-modal-header-enter">
          <div class="calls-modal-header-enter-left">
            <img class="calls-modal-header-enter-logo" src="/assets/orgas/kingrt/logo.svg" alt="" />
            <h4>{{ composeHeadline }}</h4>
          </div>
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
  </section>
</template>

<script setup>
import { computed, inject, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { Calendar } from '@fullcalendar/core';
import dayGridPlugin from '@fullcalendar/daygrid';
import timeGridPlugin from '@fullcalendar/timegrid';
import interactionPlugin from '@fullcalendar/interaction';
import AppSelect from '../../components/AppSelect.vue';
import { sessionState } from '../auth/session';
import { currentBackendOrigin, fetchBackend } from '../../support/backendFetch';
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

function isDeletable(call) {
  return Boolean(call?.id);
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
    await Promise.all([loadCalls(), loadCalendar()]);
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
      eventTimeFormat: { hour: '2-digit', minute: '2-digit', hour12: false },
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
  closeEnterCallModal();
  resetComposeModal();
  composeState.mode = mode;
  composeState.open = true;

  if (mode === 'edit' && call) {
    composeState.callId = String(call.id || '');
    composeState.title = String(call.title || '');
    composeState.accessMode = normalizeCallAccessMode(call.access_mode);
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
    room_id: String(composeState.roomId || '').trim() || 'lobby',
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
    } else {
      const createResult = await apiRequest('/api/calls', {
        method: 'POST',
        body: payload,
      });
      const createdCallId = String(createResult?.result?.call?.id || '').trim();
      const createdRoomId = String(createResult?.result?.call?.room_id || payload.room_id || 'lobby').trim() || 'lobby';
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
  updateEnterCallPreviewAspectRatio();
  enterCallPreviewResizeHandler = () => updateEnterCallPreviewAspectRatio();
  window.addEventListener('resize', enterCallPreviewResizeHandler);
  window.addEventListener('orientationchange', enterCallPreviewResizeHandler);
  window.addEventListener('keydown', handleEscape);

  void Promise.all([loadCalls(), loadCalendar()]);
});

onBeforeUnmount(() => {
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
</script>

<style scoped>
.calls-view {
  min-height: 100%;
  display: flex;
  flex-direction: column;
  background: var(--bg-ui-chrome);
  gap: 0;
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

.calls-header h1 {
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
  margin-bottom: 15px;
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

.calls-toolbar-search-btn {
  width: 40px;
  height: 40px;
}

.calls-toolbar-search-btn img {
  width: 18px;
  height: 18px;
}

.calls-search {
  display: inline-grid;
  grid-template-columns: minmax(220px, 1fr) auto;
  gap: 8px;
  align-items: center;
}

.calls-search-main {
  grid-template-columns: minmax(220px, 1fr);
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
  flex: 1 1 auto;
  min-height: 0;
  padding-left: 10px;
  padding-right: 10px;
}

.col-title {
  width: 28%;
}

.col-actions {
  width: 190px;
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
  flex: 1 1 auto;
  min-height: 0;
  padding: 10px 10px 10px;
  background: var(--bg-surface);
  display: grid;
  grid-template-rows: minmax(0, 1fr);
}

.calls-calendar-full {
  min-height: 0;
  height: 100%;
  border: 1px solid var(--border-subtle);
  background: var(--bg-surface-strong);
  padding: 10px;
}

.calls-pagination-wrap {
  display: flex;
  justify-content: center;
  margin-top: auto;
  border-top: 1px solid var(--border-subtle);
  padding-left: 10px;
  padding-right: 10px;
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
  background: rgba(5, 12, 23, 0.72);
}

.calls-modal-dialog {
  --calls-enter-dialog-padding: 12px;
  position: relative;
  width: min(1020px, calc(100vw - 30px));
  max-height: calc(100vh - 30px);
  overflow: auto;
  border: 1px solid var(--border-subtle);
  border-radius: 8px;
  background: var(--bg-surface-strong);
  box-shadow: 0 6px 14px rgba(0, 0, 0, 0.28);
  padding: 12px;
  display: grid;
  gap: 12px;
}

.calls-modal-dialog-small {
  width: min(620px, calc(100vw - 30px));
}

.calls-modal-dialog-cancel {
  --calls-enter-dialog-padding: 12px;
  width: min(980px, calc(100vw - 24px));
  height: min(760px, calc(100dvh - 24px));
  max-height: calc(100dvh - 24px);
  overflow: hidden;
  grid-template-rows: auto minmax(0, 1fr) auto;
  padding: var(--calls-enter-dialog-padding);
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

.calls-delete-warning {
  margin: 0;
  border: 0;
  border-radius: 0;
  background: #ff0000;
  color: #f7f7f7;
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

.calls-cancel-footer {
  align-items: center;
  flex-wrap: wrap;
}

.calls-cancel-override-row {
  margin-right: auto;
}

.calls-cancel-body {
  min-height: 0;
  overflow: auto;
  align-content: start;
}

.calls-cancel-template-toolbar {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

.calls-cancel-template-toolbar .btn {
  height: 30px;
  padding: 0 10px;
  font-size: 12px;
}

.calls-rich-editor {
  min-height: 200px;
  max-height: 360px;
  overflow: auto;
  border-radius: 6px;
  border: 1px solid var(--border-subtle);
  background: #d8dadd;
  color: #0b1323;
  padding: 10px;
  line-height: 1.45;
}

.calls-rich-editor:empty::before {
  content: 'Write cancellation message...';
  color: #617082;
}

.btn.btn-danger {
  background: #a81a1a;
}

.btn.btn-danger:hover {
  background: #c91f1f;
}

.btn.btn-danger:active {
  background: #7c1010;
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

  .calls-search-main {
    grid-template-columns: minmax(0, 1fr);
    width: 100%;
  }

  .calls-modal-grid,
  .calls-participants-grid {
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

  .calls-modal-dialog-cancel {
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

  .calls-calendar-full {
    min-height: 0;
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

  .calls-modal-dialog-cancel {
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

  .calls-rich-editor {
    min-height: 150px;
    max-height: 260px;
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
