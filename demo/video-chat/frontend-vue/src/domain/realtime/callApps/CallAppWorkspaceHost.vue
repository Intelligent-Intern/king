<template>
  <section class="call-app-workspace-host" :data-call-app-session-id="sessionId">
    <section class="call-app-workspace-mini-strip" aria-label="Call App participants">
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
        csp="default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; connect-src 'self'; img-src 'self' data: blob:"
        referrerpolicy="no-referrer"
        loading="eager"
        @load="handleIframeLoad"
      ></iframe>
      <section
        v-if="hasActiveSession && launchState.status !== 'ready'"
        class="call-app-workspace-launch-status"
        :class="`state-${launchState.status}`"
        aria-live="polite"
      >
        <span>{{ launchStatusLabel }}</span>
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
const hasActiveSession = computed(() => props.activeSession !== null && String(props.activeSession?.id || '').trim() !== '');
const sessionId = computed(() => String(props.activeSession?.id || '').trim());
const appKey = computed(() => String(props.activeSession?.app_key || props.activeSession?.appKey || '').trim());
const iframeEntrypoint = computed(() => String(props.activeSession?.app?.iframe_entrypoint || '').trim());
const iframeSrc = computed(() => (hasActiveSession.value ? callAppWorkspaceIframeUrl(props.activeSession) : 'about:blank'));
const activeSessionRef = computed(() => props.activeSession);
const iframeTitle = computed(() => {
  const name = String(props.activeSession?.app?.name || appKey.value || 'Call App').trim();
  return `${name} workspace`;
});
const visibleMiniParticipants = computed(() => props.miniParticipants.slice(0, CALL_APP_WORKSPACE_MINI_LIMIT));
const { launchState, handleIframeLoad } = createCallAppIframeBridge({
  activeSession: activeSessionRef,
  iframeRef,
  apiRequest: props.apiRequest,
});
createCallAppCrdtBridge({
  activeSession: activeSessionRef,
  iframeRef,
  apiRequest: props.apiRequest,
});
const launchStatusLabel = computed(() => {
  if (launchState.value.status === 'error') return launchState.value.error || 'Call App launch failed.';
  if (launchState.value.status === 'launch_sent') return 'Opening Call App...';
  if (launchState.value.status === 'token_ready') return 'Preparing Call App...';
  return 'Requesting Call App access...';
});
</script>

<style scoped>
.call-app-workspace-host {
  width: 100%;
  height: 100%;
  min-height: 0;
  display: grid;
  grid-template-rows: minmax(104px, auto) minmax(0, 1fr);
  background: var(--color-surface-navy);
  color: var(--color-text-primary);
  overflow: hidden;
}

.call-app-workspace-mini-strip {
  min-width: 0;
  min-height: 104px;
  display: grid;
  grid-template-columns: repeat(5, minmax(92px, 1fr));
  gap: 8px;
  padding: 8px;
  border-bottom: 1px solid var(--color-border);
  background: var(--color-surface-navy);
  overflow-x: auto;
  overflow-y: hidden;
}

.call-app-workspace-mini-tile,
.call-app-workspace-mini-empty {
  position: relative;
  min-width: 92px;
  min-height: 86px;
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
.call-app-workspace-mini-video-slot :deep(canvas) {
  position: absolute;
  inset: 0;
  width: 100% !important;
  height: 100% !important;
  display: block !important;
  object-fit: cover !important;
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
  min-width: 0;
  min-height: 0;
  display: grid;
  background: var(--color-primary-navy);
  overflow: hidden;
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
    grid-template-rows: minmax(92px, auto) minmax(0, 1fr);
  }

  .call-app-workspace-mini-strip {
    grid-template-columns: repeat(5, minmax(120px, 120px));
    min-height: 92px;
    padding: 6px;
  }

  .call-app-workspace-mini-tile,
  .call-app-workspace-mini-empty {
    min-width: 120px;
    min-height: 74px;
  }
}
</style>
