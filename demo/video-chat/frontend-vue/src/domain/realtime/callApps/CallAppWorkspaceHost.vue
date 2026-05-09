<template>
  <section
    class="call-app-workspace-host"
    :class="{ fullscreen: isWorkspaceFullscreen, 'participants-hidden': areWorkspaceParticipantsHidden }"
    :data-call-app-session-id="sessionId"
  >
    <section
      v-if="isWorkspaceFullscreen && hasActiveSession"
      class="call-app-workspace-fullscreen-toolbar"
      aria-label="Call App fullscreen controls"
    >
      <button
        class="call-app-workspace-participants-toggle"
        type="button"
        :aria-expanded="areWorkspaceParticipantsHidden ? 'false' : 'true'"
        aria-controls="call-app-workspace-mini-strip"
        :aria-label="participantsToggleLabel"
        :title="participantsToggleLabel"
        @click.stop="toggleFullscreenParticipants"
      >
        <span
          class="call-app-workspace-participants-icon"
          :class="{ hidden: areWorkspaceParticipantsHidden }"
          aria-hidden="true"
        >
          <span class="participant-dot"></span>
          <span class="participant-dot"></span>
          <span class="participant-dot"></span>
          <span class="participants-slash"></span>
        </span>
      </button>
      <button
        class="call-app-workspace-fullscreen-toggle"
        type="button"
        :aria-pressed="isWorkspaceFullscreen ? 'true' : 'false'"
        :aria-label="fullscreenToggleLabel"
        :title="fullscreenToggleLabel"
        @click.stop="toggleWorkspaceFullscreen"
      >
        <span
          class="call-app-workspace-fullscreen-icon"
          :class="{ active: isWorkspaceFullscreen }"
          aria-hidden="true"
        >
          <span class="corner top-left"></span>
          <span class="corner top-right"></span>
          <span class="corner bottom-right"></span>
          <span class="corner bottom-left"></span>
        </span>
      </button>
    </section>
    <section
      id="call-app-workspace-mini-strip"
      v-show="!areWorkspaceParticipantsHidden"
      class="call-app-workspace-mini-strip"
      :aria-hidden="areWorkspaceParticipantsHidden ? 'true' : 'false'"
      aria-label="Call App participants"
    >
      <article
        v-for="participant in visibleMiniParticipants"
        :key="participant.userId"
        class="call-app-workspace-mini-tile"
        @dblclick.stop="toggleVideoFullscreenForEvent(participant.userId, $event)"
      >
        <div
          :id="miniVideoSlotId(participant.userId)"
          class="workspace-mini-video-slot call-app-workspace-mini-video-slot"
          :data-user-id="participant.userId"
        ></div>
        <div
          v-if="showParticipantMediaOverlay(participant.userId)"
          class="workspace-video-status-overlay call-app-workspace-video-placeholder"
          :class="`state-${participantMediaStatusState(participant.userId)}`"
          aria-hidden="true"
        >
          <span class="workspace-video-status-spinner" aria-hidden="true"></span>
          <span class="call-app-workspace-mini-initials">{{ participantInitials(participant.displayName) }}</span>
          <span class="call-app-workspace-mini-status">{{ participantMediaStatusLabel(participant.userId) }}</span>
        </div>
        <span class="call-app-workspace-mini-title">{{ participant.displayName }}</span>
      </article>
      <article v-if="visibleMiniParticipants.length === 0" class="call-app-workspace-mini-empty">
        {{ t('calls.workspace.no_users_in_room') }}
      </article>
    </section>

    <section class="call-app-workspace-frame-shell">
      <button
        v-if="hasActiveSession && !isWorkspaceFullscreen"
        class="call-app-workspace-fullscreen-toggle"
        type="button"
        :aria-pressed="isWorkspaceFullscreen ? 'true' : 'false'"
        :aria-label="fullscreenToggleLabel"
        :title="fullscreenToggleLabel"
        @click.stop="toggleWorkspaceFullscreen"
      >
        <span
          class="call-app-workspace-fullscreen-icon"
          :class="{ active: isWorkspaceFullscreen }"
          aria-hidden="true"
        >
          <span class="corner top-left"></span>
          <span class="corner top-right"></span>
          <span class="corner bottom-right"></span>
          <span class="corner bottom-left"></span>
        </span>
      </button>
      <iframe
        v-if="hasActiveSession"
        ref="iframeRef"
        class="call-app-workspace-frame"
        :src="iframeSrc"
        :title="iframeTitle"
        :data-call-app-key="appKey"
        :data-call-app-entrypoint="iframeEntrypoint"
        :data-call-app-launch-state="launchState.status"
        sandbox="allow-scripts allow-forms allow-pointer-lock allow-downloads"
        referrerpolicy="no-referrer"
        loading="eager"
        @load="handleIframeLoad"
      ></iframe>
      <section
        v-if="hasActiveSession && (launchState.status !== 'ready' || accessNoticeState !== '')"
        class="call-app-workspace-launch-status"
        :class="`state-${accessNoticeState || launchState.status}`"
        aria-live="polite"
      >
        <span>{{ accessNoticeLabel || launchStatusLabel }}</span>
      </section>
      <section v-if="!hasActiveSession" class="call-app-workspace-empty" aria-live="polite">
        <span class="call-app-workspace-empty-title">{{ t('calls.workspace.no_call_app_active') }}</span>
      </section>
    </section>
  </section>
</template>

<script setup>
import { computed, ref } from 'vue';
import { t } from '../../../modules/localization/i18nRuntime.js';
import { CALL_APP_WORKSPACE_MINI_LIMIT, callAppWorkspaceIframeUrl } from './callAppWorkspaceState.js';
import { createCallAppDiagnosticTailBridge } from './callAppDiagnosticTailBridge.js';
import { createCallAppCrdtBridge } from './useCallAppCrdtBridge.js';
import { createCallAppIframeBridge } from './useCallAppIframeBridge.js';

const props = defineProps({
  activeSession: {
    type: Object,
    default: null,
  },
  miniParticipants: {
    type: Array,
    default: () => [],
  },
  participants: {
    type: Array,
    default: () => [],
  },
  currentUserId: {
    type: Number,
    default: 0,
  },
  currentUserDisplayName: {
    type: String,
    default: '',
  },
  sendSocketFrame: {
    type: Function,
    default: null,
  },
  miniVideoSlotId: {
    type: Function,
    required: true,
  },
  participantInitials: {
    type: Function,
    required: true,
  },
  participantMediaStatusLabel: {
    type: Function,
    required: true,
  },
  participantMediaStatusState: {
    type: Function,
    required: true,
  },
  showParticipantMediaOverlay: {
    type: Function,
    required: true,
  },
  toggleVideoFullscreenForEvent: {
    type: Function,
    required: true,
  },
  apiRequest: {
    type: Function,
    required: true,
  },
});

const iframeRef = ref(null);
const isWorkspaceFullscreen = ref(false);
const fullscreenParticipantsHidden = ref(false);
const hasActiveSession = computed(() => props.activeSession !== null && String(props.activeSession?.id || '').trim() !== '');
const sessionId = computed(() => String(props.activeSession?.id || '').trim());
const appKey = computed(() => String(props.activeSession?.app_key || props.activeSession?.appKey || '').trim());
const iframeEntrypoint = computed(() => String(props.activeSession?.app?.iframe_entrypoint || '').trim());
const iframeSrc = computed(() => (hasActiveSession.value ? callAppWorkspaceIframeUrl(props.activeSession) : 'about:blank'));
const activeSessionRef = computed(() => props.activeSession);
const participantsRef = computed(() => props.participants);
const currentUserIdRef = computed(() => props.currentUserId);
const currentUserDisplayNameRef = computed(() => props.currentUserDisplayName);
const sendSocketFrameRef = computed(() => props.sendSocketFrame);
const iframeTitle = computed(() => {
  const name = String(props.activeSession?.app?.name || appKey.value || 'Call App').trim();
  return `${name} workspace`;
});
const visibleMiniParticipants = computed(() => props.miniParticipants.slice(0, CALL_APP_WORKSPACE_MINI_LIMIT));
const { launchState, handleIframeLoad } = createCallAppIframeBridge({
  activeSession: activeSessionRef,
  iframeRef,
  apiRequest: props.apiRequest,
  participantDisplayName: currentUserDisplayNameRef,
});
const callAppCrdtBridge = createCallAppCrdtBridge({
  activeSession: activeSessionRef,
  iframeRef,
  apiRequest: props.apiRequest,
  participants: participantsRef,
  currentUserId: currentUserIdRef,
  currentUserDisplayName: currentUserDisplayNameRef,
  sendSocketFrame: sendSocketFrameRef,
});
createCallAppDiagnosticTailBridge({
  activeSession: activeSessionRef,
  iframeRef,
  postToIframe: callAppCrdtBridge.postToIframe,
});
const launchStatusLabel = computed(() => {
  if (launchState.value.status === 'error') return launchState.value.error || 'Call App launch failed.';
  if (launchState.value.status === 'launch_sent') return 'Opening Call App...';
  if (launchState.value.status === 'token_ready') return 'Preparing Call App...';
  return 'Requesting Call App access...';
});
const accessNoticeState = computed(() => {
  if (!hasActiveSession.value || !['ready', 'launch_sent', 'token_ready'].includes(launchState.value.status)) return '';
  const grantState = String(launchState.value.grant_state || '').trim().toLowerCase();
  const capabilities = Array.isArray(launchState.value.capabilities) ? launchState.value.capabilities : [];
  if (grantState === 'denied') return 'no-access';
  if (grantState === 'allowed' && capabilities.includes('call_apps.crdt.read') && !capabilities.includes('call_apps.crdt.append')) {
    return 'read-only';
  }
  return '';
});
const accessNoticeLabel = computed(() => {
  if (accessNoticeState.value === 'no-access') return 'No Call App access. Ask the call owner or moderator to allow this app.';
  if (accessNoticeState.value === 'read-only') return 'Read-only Call App access. You can view this app but cannot edit it.';
  return '';
});
const fullscreenToggleLabel = computed(() => (isWorkspaceFullscreen.value ? 'Exit Call App fullscreen' : 'Open Call App fullscreen'));
const areWorkspaceParticipantsHidden = computed(() => isWorkspaceFullscreen.value && fullscreenParticipantsHidden.value);
const participantsToggleLabel = computed(() => (
  areWorkspaceParticipantsHidden.value ? 'Show Call App participants' : 'Hide Call App participants'
));

function toggleWorkspaceFullscreen() {
  isWorkspaceFullscreen.value = !isWorkspaceFullscreen.value;
  if (!isWorkspaceFullscreen.value) fullscreenParticipantsHidden.value = false;
}

function toggleFullscreenParticipants() {
  fullscreenParticipantsHidden.value = !fullscreenParticipantsHidden.value;
}
</script>

<style scoped>
.call-app-workspace-host {
  --call-app-workspace-mini-height: 112px;
  --call-app-workspace-mini-width: 160px;
  --call-app-workspace-toolbar-height: 48px;
  width: 100%;
  height: 100%;
  min-height: 0;
  display: grid;
  grid-template-rows: var(--call-app-workspace-mini-height) minmax(0, 1fr);
  background: var(--color-surface-navy);
  color: var(--color-text-primary);
  overflow: hidden;
}

.call-app-workspace-host.fullscreen {
  position: fixed;
  inset: 0;
  z-index: 9990;
  --call-app-workspace-mini-width: 168px;
  width: 100vw;
  height: 100dvh;
  max-width: none;
  max-height: none;
  grid-template-rows: var(--call-app-workspace-toolbar-height) var(--call-app-workspace-mini-height) minmax(0, 1fr);
  isolation: isolate;
}

.call-app-workspace-host.fullscreen.participants-hidden {
  grid-template-rows: var(--call-app-workspace-toolbar-height) minmax(0, 1fr);
}

.call-app-workspace-fullscreen-toolbar {
  position: relative;
  z-index: 3;
  height: var(--call-app-workspace-toolbar-height);
  min-height: 0;
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 8px;
  padding: 5px max(10px, env(safe-area-inset-right)) 5px max(10px, env(safe-area-inset-left));
  border-bottom: 1px solid var(--color-border);
  background: var(--color-surface-navy);
}

.call-app-workspace-mini-strip {
  position: relative;
  z-index: 2;
  min-width: 0;
  height: var(--call-app-workspace-mini-height);
  min-height: 0;
  display: grid;
  grid-auto-flow: column;
  grid-auto-columns: var(--call-app-workspace-mini-width);
  grid-template-columns: none;
  align-items: center;
  gap: 8px;
  padding: 8px;
  border-bottom: 1px solid var(--color-border);
  background: var(--color-surface-navy);
  overflow-x: auto;
  overflow-y: hidden;
  scrollbar-gutter: stable;
}

.call-app-workspace-mini-tile,
.call-app-workspace-mini-empty {
  position: relative;
  width: var(--call-app-workspace-mini-width);
  min-width: var(--call-app-workspace-mini-width);
  height: calc(var(--call-app-workspace-mini-height) - 18px);
  min-height: 0;
  overflow: hidden;
  border: 1px solid var(--color-border);
  background: var(--color-border);
}

.call-app-workspace-mini-tile {
  aspect-ratio: 16 / 9;
  cursor: zoom-in;
}

.call-app-workspace-mini-video-slot {
  position: absolute;
  inset: 0;
  background: var(--color-primary-navy);
  contain: strict;
}

.call-app-workspace-mini-video-slot :deep(video),
.call-app-workspace-mini-video-slot :deep(canvas),
.call-app-workspace-mini-video-slot :deep(img),
.call-app-workspace-mini-video-slot :deep([data-call-video-framing-mode="cover"]),
.call-app-workspace-mini-video-slot :deep(.workspace-static-avatar-media) {
  position: absolute;
  inset: 0;
  width: 100% !important;
  height: 100% !important;
  display: block !important;
  object-fit: contain !important;
  object-position: center center !important;
}

.call-app-workspace-video-placeholder {
  position: absolute;
  inset: 0;
  z-index: 1;
  display: grid;
  align-content: center;
  justify-items: center;
  gap: 5px;
  padding: 8px;
  background: var(--color-surface-navy);
  pointer-events: none;
}

.call-app-workspace-mini-initials {
  width: 34px;
  height: 34px;
  display: grid;
  place-items: center;
  border: 1px solid var(--color-border);
  background: var(--color-primary-navy);
  color: var(--color-text-primary);
  font-size: 12px;
  font-weight: 800;
}

.call-app-workspace-mini-status,
.call-app-workspace-mini-title {
  max-width: calc(100% - 12px);
  overflow: hidden;
  text-align: center;
  text-overflow: ellipsis;
  white-space: nowrap;
  color: var(--color-heading);
  font-size: 10px;
  font-weight: 700;
}

.call-app-workspace-mini-title {
  position: absolute;
  left: 6px;
  right: 6px;
  bottom: 5px;
  z-index: 2;
  color: var(--color-text-primary);
  text-shadow: 0 1px 3px var(--color-primary-navy);
}

.call-app-workspace-mini-empty {
  grid-column: 1 / -1;
  display: grid;
  place-items: center;
  color: var(--color-heading);
  font-size: 12px;
  font-weight: 700;
}

.call-app-workspace-frame-shell {
  position: relative;
  z-index: 1;
  min-width: 0;
  min-height: 0;
  display: grid;
  background: var(--color-primary-navy);
  overflow: hidden;
}

.call-app-workspace-participants-toggle,
.call-app-workspace-fullscreen-toggle {
  width: 38px;
  height: 38px;
  flex: 0 0 38px;
  display: grid;
  place-items: center;
  border: 1px solid var(--color-border);
  border-radius: 0;
  background: color-mix(in srgb, var(--color-surface-navy) 92%, transparent);
  color: var(--color-text-primary);
  line-height: 1;
  cursor: pointer;
}

.call-app-workspace-fullscreen-toggle {
  position: absolute;
  top: max(10px, env(safe-area-inset-top));
  right: max(10px, env(safe-area-inset-right));
  z-index: 3;
}

.call-app-workspace-fullscreen-toolbar .call-app-workspace-participants-toggle,
.call-app-workspace-fullscreen-toolbar .call-app-workspace-fullscreen-toggle {
  position: relative;
  top: auto;
  right: auto;
  z-index: auto;
}

.call-app-workspace-participants-toggle:hover,
.call-app-workspace-fullscreen-toggle:hover {
  background: var(--color-border);
}

.call-app-workspace-participants-icon {
  position: relative;
  width: 22px;
  height: 18px;
  display: block;
}

.call-app-workspace-participants-icon .participant-dot {
  position: absolute;
  width: 6px;
  height: 6px;
  border: 2px solid currentColor;
  border-radius: 50%;
}

.call-app-workspace-participants-icon .participant-dot:nth-child(1) {
  left: 8px;
  top: 0;
}

.call-app-workspace-participants-icon .participant-dot:nth-child(2) {
  left: 1px;
  bottom: 1px;
}

.call-app-workspace-participants-icon .participant-dot:nth-child(3) {
  right: 1px;
  bottom: 1px;
}

.call-app-workspace-participants-icon .participants-slash {
  position: absolute;
  left: 1px;
  top: 8px;
  width: 22px;
  height: 2px;
  display: none;
  background: currentColor;
  transform: rotate(-38deg);
  transform-origin: center;
}

.call-app-workspace-participants-icon.hidden .participants-slash {
  display: block;
}

.call-app-workspace-fullscreen-icon {
  position: relative;
  width: 18px;
  height: 18px;
  display: block;
}

.call-app-workspace-fullscreen-icon .corner {
  position: absolute;
  width: 7px;
  height: 7px;
  border-color: currentColor;
  border-style: solid;
}

.call-app-workspace-fullscreen-icon .top-left {
  top: 0;
  left: 0;
  border-width: 2px 0 0 2px;
}

.call-app-workspace-fullscreen-icon .top-right {
  top: 0;
  right: 0;
  border-width: 2px 2px 0 0;
}

.call-app-workspace-fullscreen-icon .bottom-right {
  right: 0;
  bottom: 0;
  border-width: 0 2px 2px 0;
}

.call-app-workspace-fullscreen-icon .bottom-left {
  bottom: 0;
  left: 0;
  border-width: 0 0 2px 2px;
}

.call-app-workspace-fullscreen-icon.active .top-left {
  top: 3px;
  left: 3px;
}

.call-app-workspace-fullscreen-icon.active .top-right {
  top: 3px;
  right: 3px;
}

.call-app-workspace-fullscreen-icon.active .bottom-right {
  right: 3px;
  bottom: 3px;
}

.call-app-workspace-fullscreen-icon.active .bottom-left {
  bottom: 3px;
  left: 3px;
}

.call-app-workspace-frame {
  width: 100%;
  height: 100%;
  min-width: 0;
  min-height: 0;
  border: 0;
  display: block;
  background: var(--color-text-primary);
}

.call-app-workspace-launch-status {
  position: absolute;
  inset: auto 16px 16px auto;
  z-index: 2;
  max-width: min(360px, calc(100% - 32px));
  padding: 8px 10px;
  border: 1px solid var(--color-border);
  background: var(--color-surface-navy);
  color: var(--color-heading);
  font-size: 12px;
  font-weight: 800;
}

.call-app-workspace-launch-status.state-error {
  border-color: var(--color-error);
}

.call-app-workspace-launch-status.state-no-access {
  border-color: var(--color-error);
  color: var(--color-error);
}

.call-app-workspace-launch-status.state-read-only {
  border-color: var(--color-warning);
  color: var(--color-warning);
}

.call-app-workspace-empty {
  display: grid;
  place-items: center;
  padding: 24px;
  background: var(--color-primary-navy);
  color: var(--color-heading);
}

.call-app-workspace-empty-title {
  font-size: 13px;
  font-weight: 800;
}

@media (max-width: 720px) {
  .call-app-workspace-host {
    --call-app-workspace-mini-height: 96px;
    --call-app-workspace-mini-width: 142px;
  }

  .call-app-workspace-mini-strip {
    padding: 6px;
  }
}
</style>
