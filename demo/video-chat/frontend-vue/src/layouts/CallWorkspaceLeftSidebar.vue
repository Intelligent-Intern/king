<template>
  <div class="sidebar-content left left-call-content">
    <div class="brand-strip">
      <img data-brand-logo :src="sidebarLogoSrc" alt="KingRT" />
      <button
        class="sidebar-toggle-btn"
        type="button"
        :title="leftSidebarToggleLabel"
        :aria-label="leftSidebarToggleLabel"
        @click="$emit('toggle-sidebar')"
      >
        <span v-if="isMobileViewport" class="sidebar-close-mark" aria-hidden="true">x</span>
        <img v-else class="arrow-icon-image" :src="leftSidebarToggleIcon" alt="" />
      </button>
    </div>

    <div v-if="showTabs" class="call-left-panel-switch" role="tablist" :aria-label="t('calls.workspace.call_sidebar')">
      <button
        class="call-left-panel-tab"
        :class="{ active: activePanel === 'settings' }"
        type="button"
        role="tab"
        :aria-selected="activePanel === 'settings'"
        @click="activePanel = 'settings'"
      >
        {{ t('settings.dialog_title') }}
      </button>
      <button
        class="call-left-panel-tab"
        :class="{ active: activePanel === 'call_apps' }"
        type="button"
        role="tab"
        :aria-selected="activePanel === 'call_apps'"
        @click="activePanel = 'call_apps'"
      >
        {{ t('calls.workspace.call_apps') }}
      </button>
    </div>

    <CallAppsSidebarPanel
      v-if="showCallAppsPanel"
      :call-id="activeSidebarCallId"
      :can-manage="canManageSidebarCallApps"
      :api-request="apiRequest"
      @session-created="$emit('call-app-session-created', $event)"
    />

    <div v-if="showSettingsPanel" class="call-left-settings">
      <section class="call-left-settings-block" :aria-label="t('calls.enter.camera')">
        <div class="call-left-settings-title">{{ t('calls.enter.camera') }}</div>
        <div class="call-left-settings-field">
          <AppSelect
            id="call-left-camera-select"
            :aria-label="t('calls.enter.camera')"
            :model-value="callMediaPrefs.selectedCameraId"
            @update:model-value="$emit('set-camera-device', $event)"
          >
            <option value="">{{ callMediaPrefs.cameras.length === 0 ? t('calls.enter.no_camera_detected') : t('calls.enter.select_camera') }}</option>
            <option
              v-for="camera in callMediaPrefs.cameras"
              :key="camera.id"
              :value="camera.id"
            >
              {{ camera.label }}
            </option>
          </AppSelect>
        </div>
      </section>

      <section class="call-left-settings-block" :aria-label="t('calls.enter.mic')">
        <div class="call-left-settings-title">{{ t('calls.enter.mic') }}</div>
        <div class="call-left-settings-field">
          <AppSelect
            id="call-left-mic-select"
            :aria-label="t('calls.enter.mic')"
            :model-value="callMediaPrefs.selectedMicrophoneId"
            @update:model-value="$emit('set-microphone-device', $event)"
          >
            <option value="">{{ callMediaPrefs.microphones.length === 0 ? t('calls.enter.no_microphone_detected') : t('calls.enter.select_microphone') }}</option>
            <option
              v-for="microphone in callMediaPrefs.microphones"
              :key="microphone.id"
              :value="microphone.id"
            >
              {{ microphone.label }}
            </option>
          </AppSelect>
        </div>
        <div class="call-left-settings-field">
          <label for="call-left-mic-volume">{{ t('calls.enter.volume') }}</label>
          <div class="call-left-volume-row">
            <input
              id="call-left-mic-volume"
              class="call-left-range"
              type="range"
              min="0"
              max="100"
              step="1"
              :value="callMediaPrefs.microphoneVolume"
              @input="$emit('set-microphone-volume', $event.target.value)"
            />
            <span class="call-left-volume-value">{{ callMediaPrefs.microphoneVolume }}%</span>
          </div>
          <div
            class="call-left-meter"
            role="meter"
            :aria-label="t('calls.enter.microphone_level')"
            aria-valuemin="0"
            aria-valuemax="100"
            :aria-valuenow="micLevelPercent"
          >
            <span class="call-left-meter-bar" :style="{ width: `${micLevelPercent}%` }"></span>
          </div>
        </div>
      </section>

      <section class="call-left-settings-block" :aria-label="t('calls.enter.speaker')">
        <div class="call-left-settings-title">{{ t('calls.enter.speaker') }}</div>
        <div class="call-left-settings-field">
          <AppSelect
            id="call-left-speaker-select"
            :aria-label="t('calls.enter.speaker')"
            :model-value="callMediaPrefs.selectedSpeakerId"
            @update:model-value="$emit('set-speaker-device', $event)"
          >
            <option value="">{{ callMediaPrefs.speakers.length === 0 ? t('calls.enter.no_speaker_detected') : t('calls.enter.select_speaker') }}</option>
            <option
              v-for="speaker in callMediaPrefs.speakers"
              :key="speaker.id"
              :value="speaker.id"
            >
              {{ speaker.label }}
            </option>
          </AppSelect>
        </div>
        <div class="call-left-settings-field">
          <label for="call-left-speaker-volume">{{ t('calls.enter.volume') }}</label>
          <div class="call-left-volume-row">
            <input
              id="call-left-speaker-volume"
              class="call-left-range"
              type="range"
              min="0"
              max="100"
              step="1"
              :value="callMediaPrefs.speakerVolume"
              @input="$emit('set-speaker-volume', $event.target.value)"
            />
            <span class="call-left-volume-value">{{ callMediaPrefs.speakerVolume }}%</span>
          </div>
        </div>
        <div class="call-left-settings-field">
          <button class="btn full call-left-test-btn" type="button" @click="$emit('play-speaker-test')">
            {{ t('calls.enter.play_test_sound') }}
          </button>
        </div>
      </section>

      <CallBackgroundControls />

      <section
        v-if="showCallOwnerInviteLink"
        class="call-left-settings-block call-left-invite-link-block"
        :aria-label="t('calls.workspace.free_for_all_invite_link')"
      >
        <div class="call-left-settings-title">{{ t('calls.workspace.invite_link') }}</div>
        <div class="call-left-invite-link-row">
          <input
            class="input call-left-invite-link-input"
            type="text"
            readonly
            :value="callOwnerInviteLinkState.url"
            :placeholder="callOwnerInviteLinkState.loading ? t('calls.workspace.generating_invite_link') : t('calls.workspace.invite_link_unavailable')"
            @focus="$event.target.select()"
          />
          <button
            class="icon-mini-btn call-left-invite-link-copy"
            type="button"
            :title="t('calls.workspace.copy_invite_link')"
            :aria-label="t('calls.workspace.copy_invite_link')"
            :disabled="callOwnerInviteLinkState.loading || callOwnerInviteLinkState.url === ''"
            @click="$emit('copy-invite-link')"
          >
            <span aria-hidden="true">⧉</span>
          </button>
        </div>
        <p v-if="callOwnerInviteLinkState.copyNotice" class="call-left-settings-value">
          {{ callOwnerInviteLinkState.copyNotice }}
        </p>
        <p v-if="callOwnerInviteLinkState.error" class="call-left-settings-error">
          {{ callOwnerInviteLinkState.error }}
        </p>
      </section>

      <section
        v-if="showInCallOwnerEditCard"
        class="call-left-settings-block call-left-owner-edit-block"
        :aria-label="t('calls.workspace.call_settings')"
      >
        <div class="call-left-settings-title">{{ t('calls.workspace.call_settings') }}</div>
        <button
          class="btn btn-cyan full call-left-owner-edit-btn"
          type="button"
          :disabled="callOwnerEditState.loadingContext || callOwnerEditState.submitting"
          @click="$emit('open-edit-modal')"
        >
          {{ callOwnerEditState.loadingContext ? t('common.loading') : t('calls.workspace.edit_call') }}
        </button>
        <p v-if="callOwnerEditState.contextError" class="call-left-settings-error">
          {{ callOwnerEditState.contextError }}
        </p>

        <template v-if="callLayoutSidebarState.visible && callLayoutSidebarState.canModerate">
          <div class="call-left-settings-field">
            <label for="call-left-layout-mode">{{ t('calls.workspace.video_layout') }}</label>
            <AppSelect
              id="call-left-layout-mode"
              :aria-label="t('calls.workspace.video_layout_mode')"
              :model-value="callLayoutSidebarState.currentMode"
              @update:model-value="$emit('apply-layout-mode', $event)"
            >
              <option
                v-for="option in callLayoutSidebarState.modeOptions"
                :key="option.mode"
                :value="option.mode"
              >
                {{ option.label }}
              </option>
            </AppSelect>
          </div>
          <div class="call-left-settings-field">
            <label for="call-left-layout-strategy">{{ t('calls.workspace.activity_strategy') }}</label>
            <AppSelect
              id="call-left-layout-strategy"
              :aria-label="t('calls.workspace.activity_strategy')"
              :model-value="callLayoutSidebarState.currentStrategy"
              @update:model-value="$emit('apply-layout-strategy', $event)"
            >
              <option
                v-for="option in callLayoutSidebarState.strategyOptions"
                :key="option.strategy"
                :value="option.strategy"
              >
                {{ option.label }}
              </option>
            </AppSelect>
          </div>
        </template>
      </section>

      <div v-if="callMediaPrefs.error" class="call-left-settings-error">{{ callMediaPrefs.error }}</div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import AppSelect from '../components/AppSelect.vue';
import CallAppsSidebarPanel from '../domain/realtime/callApps/CallAppsSidebarPanel.vue';
import CallBackgroundControls from '../domain/realtime/background/CallBackgroundControls.vue';
import { t } from '../modules/localization/i18nRuntime.js';
import { useCallLeftSidebarTabs } from './useCallLeftSidebarTabs.js';

const props = defineProps({
  sidebarLogoSrc: {
    type: String,
    required: true,
  },
  leftSidebarToggleLabel: {
    type: String,
    required: true,
  },
  leftSidebarToggleIcon: {
    type: String,
    required: true,
  },
  isMobileViewport: {
    type: Boolean,
    default: false,
  },
  callMediaPrefs: {
    type: Object,
    required: true,
  },
  micLevelPercent: {
    type: Number,
    default: 0,
  },
  showCallOwnerInviteLink: {
    type: Boolean,
    default: false,
  },
  callOwnerInviteLinkState: {
    type: Object,
    required: true,
  },
  showInCallOwnerEditCard: {
    type: Boolean,
    default: false,
  },
  callOwnerEditState: {
    type: Object,
    required: true,
  },
  callLayoutSidebarState: {
    type: Object,
    required: true,
  },
  activeSidebarCallId: {
    type: String,
    default: '',
  },
  canManageSidebarCallApps: {
    type: Boolean,
    default: false,
  },
  apiRequest: {
    type: Function,
    required: true,
  },
});

defineEmits([
  'toggle-sidebar',
  'set-camera-device',
  'set-microphone-device',
  'set-microphone-volume',
  'set-speaker-device',
  'set-speaker-volume',
  'play-speaker-test',
  'copy-invite-link',
  'open-edit-modal',
  'apply-layout-mode',
  'apply-layout-strategy',
  'call-app-session-created',
]);

const activeSidebarCallId = computed(() => props.activeSidebarCallId);
const currentLayoutMode = computed(() => props.callLayoutSidebarState?.currentMode || '');

const {
  activePanel,
  showTabs,
  showCallAppsPanel,
  showSettingsPanel,
} = useCallLeftSidebarTabs({
  activeCallId: activeSidebarCallId,
  currentLayoutMode,
});
</script>
