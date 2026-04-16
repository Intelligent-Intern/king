<template>
  <section class="view-card workspace-call-view">
    <section v-if="workspaceError" class="workspace-call-banner error">
      {{ workspaceError }}
    </section>
    <section v-if="workspaceNotice" class="workspace-call-banner ok">
      {{ workspaceNotice }}
    </section>
    <section v-if="aloneIdlePrompt.visible" class="workspace-idle-toast" role="alert">
      <span class="workspace-idle-toast-text">
        Are you still in the call? This call will close in {{ aloneIdleCountdownLabel }} unless you confirm.
      </span>
      <button class="workspace-idle-toast-btn" type="button" @click="confirmStillInCall">
        Yes, I am in
      </button>
    </section>

    <section class="workspace-call-body" :class="{ 'right-collapsed': rightSidebarCollapsed }">
      <section class="workspace-stage" :class="{ compact: isCompactHeaderVisible }">
        <header v-if="isCompactHeaderVisible" class="workspace-compact-header">
          <button
            class="workspace-compact-toggle workspace-compact-toggle-menu"
            type="button"
            title="Open left sidebar"
            aria-label="Open left sidebar"
            @click.stop="openLeftSidebarOverlay"
          >
            <img class="arrow-icon-image" src="/assets/orgas/kingrt/icons/forward.png" alt="" />
          </button>
          <img class="workspace-compact-logo" src="/assets/orgas/kingrt/king_logo-withslogan.svg" alt="KingRT" />
          <button
            class="workspace-compact-toggle"
            type="button"
            title="Open right sidebar"
            aria-label="Open right sidebar"
            @click="showRightSidebar"
          >
            <img
              class="arrow-icon-image workspace-compact-toggle-icon workspace-compact-toggle-icon-back"
              src="/assets/orgas/kingrt/icons/forward.png"
              alt=""
            />
          </button>
        </header>

        <article class="workspace-main-video">
          <div id="local-video-container" class="video-container local"></div>
          <div id="remote-video-container" class="video-container remote"></div>
          <div id="decoded-video-container" class="video-container decoded"></div>

          <div class="workspace-reaction-flight">
            <span
              v-for="burst in activeReactions"
              :key="burst.id"
              class="workspace-reaction-particle"
              :style="{
                '--start-x': `${burst.startXPx}px`,
                '--delay': `${burst.delay}ms`,
                '--duration': `${burst.duration}ms`,
                '--travel-y': `${burst.travelY}px`,
                '--wave': `${burst.wave}px`,
                '--phase': `${burst.phase}deg`,
                '--base-bottom': `${burst.baseBottom}px`,
                '--scale': burst.scale,
              }"
            >
              {{ burst.emoji }}
            </span>
          </div>
        </article>

        <button
          v-if="showLeftSidebarRestoreButton"
          class="show-sidebar-overlay show-left-sidebar-overlay workspace-show-left-btn"
          type="button"
          title="Show sidebar"
          aria-label="Show sidebar"
          @click.stop="openLeftSidebarOverlay"
        >
          <img class="arrow-icon-image" src="/assets/orgas/kingrt/icons/forward.png" alt="" />
        </button>

        <button
          v-if="rightSidebarCollapsed && !isCompactHeaderVisible"
          class="show-sidebar-overlay show-right-sidebar-overlay workspace-show-right-btn"
          type="button"
          title="Show sidebar"
          aria-label="Show sidebar"
          @click="showRightSidebar"
        >
          <img class="arrow-icon-image" src="/assets/orgas/kingrt/icons/backward.png" alt="" />
        </button>

        <button
          v-if="showLobbyJoinToast"
          class="workspace-lobby-toast"
          type="button"
          @click="openLobbyRequestsPanel"
        >
          <img class="workspace-lobby-toast-icon" src="/assets/orgas/kingrt/icons/lobby.png" alt="" />
          <span class="workspace-lobby-toast-text">{{ lobbyJoinToastMessage }}</span>
        </button>

        <section v-if="!isCompactViewport && showMiniParticipantStrip" class="workspace-mini-strip">
          <article
            v-for="participant in stripParticipants"
            :key="participant.userId"
            class="workspace-mini-tile"
          >
            <span class="workspace-mini-title">{{ participant.displayName }}</span>
            <span class="workspace-mini-meta">{{ participant.role }}</span>
          </article>
          <article v-if="stripParticipants.length === 0" class="workspace-mini-empty">
            No users in this room yet.
          </article>
        </section>

        <footer class="workspace-controls">
          <div class="workspace-reactions-tray" :class="{ open: reactionTrayOpen }">
            <button
              v-for="emoji in reactionOptions"
              :key="emoji"
              class="workspace-reaction-btn"
              type="button"
              @click="emitReaction(emoji)"
            >
              {{ emoji }}
            </button>
          </div>

          <button
            class="call-control-btn"
            type="button"
            :class="{ active: controlState.handRaised }"
            title="Raise hand"
            @click="toggleHandRaised"
          >
            <img class="ctrl-icon-image" src="/assets/orgas/kingrt/icons/hand.png" alt="" />
          </button>

          <button
            class="call-control-btn"
            type="button"
            :class="{ active: reactionTrayOpen }"
            title="Reactions"
            @click="reactionTrayOpen = !reactionTrayOpen"
          >
            😀
          </button>

          <button
            class="call-control-btn"
            type="button"
            :class="{ active: controlState.cameraEnabled }"
            title="Toggle camera"
            @click="toggleCamera"
          >
            <img
              class="ctrl-icon-image"
              :src="controlState.cameraEnabled
                ? '/assets/orgas/kingrt/icons/camon.png'
                : '/assets/orgas/kingrt/icons/cameraoff.png'"
              alt=""
            />
          </button>

          <button
            class="call-control-btn"
            type="button"
            :class="{ active: controlState.micEnabled }"
            title="Toggle microphone"
            @click="toggleMicrophone"
          >
            <img
              class="ctrl-icon-image"
              :src="controlState.micEnabled
                ? '/assets/orgas/kingrt/icons/micon.png'
                : '/assets/orgas/kingrt/icons/micoff.png'"
              alt=""
            />
          </button>

          <button
            class="call-control-btn"
            type="button"
            :class="{ active: controlState.screenEnabled }"
            title="Share screen"
            @click="toggleScreenShare"
          >
            <img class="ctrl-icon-image" src="/assets/orgas/kingrt/icons/share_screen.png" alt="" />
          </button>

          <button class="call-control-btn hangup" type="button" title="Hang up" @click="hangupCall">
            <img class="ctrl-icon-image" src="/assets/orgas/kingrt/icons/end_call.png" alt="" />
          </button>
        </footer>
      </section>

      <aside class="workspace-context" :class="{ collapsed: rightSidebarCollapsed }">
        <nav class="tabs tabs-right" role="tablist" aria-label="Call workspace context tabs">
          <button
            class="tab"
            :class="{ active: activeTab === 'users' }"
            type="button"
            role="tab"
            :aria-selected="activeTab === 'users'"
            @click="setActiveTab('users')"
          >
            <img class="tab-icon" src="/assets/orgas/kingrt/icons/users.png" alt="" />
          </button>
          <button
            class="tab tab-lobby"
            :class="{ active: activeTab === 'lobby' }"
            type="button"
            role="tab"
            :aria-selected="activeTab === 'lobby'"
            @click="setActiveTab('lobby')"
          >
            <span class="tab-icon-wrap">
              <img class="tab-icon" src="/assets/orgas/kingrt/icons/lobby.png" alt="" />
              <span v-if="showLobbyRequestBadge" class="tab-notice-badge">{{ lobbyRequestBadgeText }}</span>
            </span>
          </button>
          <button
            class="tab"
            :class="{ active: activeTab === 'chat' }"
            type="button"
            role="tab"
            :aria-selected="activeTab === 'chat'"
            @click="setActiveTab('chat')"
          >
            <img class="tab-icon" src="/assets/orgas/kingrt/icons/chat.png" alt="" />
          </button>
          <button
            class="tab tab-toggle"
            type="button"
            title="Hide sidebar"
            aria-label="Hide sidebar"
            @click="hideRightSidebar"
          >
            <img class="tab-icon" src="/assets/orgas/kingrt/icons/forward.png" alt="" />
          </button>
        </nav>

        <section class="tab-panel panel-users" :class="{ active: activeTab === 'users' }">
          <div class="toolbar">
            <input
              v-model="usersSearch"
              class="search"
              type="search"
              placeholder="Search users"
              @input="onUsersSearchInput"
            />
          </div>
          <p v-if="usersSourceMode === 'directory' && usersDirectoryLoading" class="workspace-tab-hint">
            Loading server-backed user directory…
          </p>
          <p v-if="usersSourceMode === 'directory' && usersDirectoryPagination.error" class="workspace-tab-hint error">
            {{ usersDirectoryPagination.error }}
          </p>

          <ul
            ref="usersListRef"
            class="user-list"
            @scroll.passive="onUsersListScroll"
          >
            <li
              v-if="usersPageRows.length > 0 && usersVirtualWindow.paddingTop > 0"
              class="user-list-spacer"
              :style="{ height: `${usersVirtualWindow.paddingTop}px` }"
            ></li>
            <li
              v-for="row in usersVisibleRows"
              :key="row.userId"
              class="user-row"
              :class="{ self: row.userId === currentUserId, pinned: pinnedUsers[row.userId] === true, pending: rowActionPending(row.userId) }"
            >
            <div class="user-preview">{{ initials(row.displayName) }}</div>
              <div class="user-main">
                <strong class="user-name">{{ row.displayName }}</strong>
                <span class="user-role">{{ row.callRole }}</span>
                <span v-if="row.controlBadge" class="user-feedback">{{ row.controlBadge }}</span>
                <span v-if="row.feedback" class="user-feedback">{{ row.feedback }}</span>
              </div>
            <div class="actions-inline">
              <button
                class="icon-mini-btn"
                type="button"
                :title="mutedUsers[row.userId] ? 'Unmute peer' : 'Mute peer'"
                :disabled="!canModerate || row.userId === currentUserId || rowActionPending(row.userId) || !row.isRoomMember"
                @click="toggleUserMuted(row.userId)"
              >
                <img
                  :src="mutedUsers[row.userId]
                      ? '/assets/orgas/kingrt/icons/micoff.png'
                      : '/assets/orgas/kingrt/icons/micon.png'"
                    alt=""
                  />
                </button>
              <button
                class="icon-mini-btn"
                type="button"
                :title="pinnedUsers[row.userId] ? 'Unpin user' : 'Pin user'"
                :disabled="!canModerate || row.userId === currentUserId || rowActionPending(row.userId) || !row.isRoomMember"
                @click="togglePinned(row.userId)"
              >
                  <img
                    :src="pinnedUsers[row.userId]
                      ? '/assets/orgas/kingrt/icons/adminon.png'
                      : '/assets/orgas/kingrt/icons/adminoff.png'"
                    alt=""
                  />
                </button>
              <button
                class="icon-mini-btn"
                type="button"
                :title="row.callRole === 'moderator' ? 'Set participant role' : 'Set moderator role'"
                :disabled="!canModerate || !activeCallId || row.userId === currentUserId || rowActionPending(row.userId) || !row.isRoomMember || row.callRole === 'owner'"
                @click="toggleModeratorRole(row)"
              >
                  <img
                    :src="row.callRole === 'moderator'
                      ? '/assets/orgas/kingrt/icons/adminon.png'
                      : '/assets/orgas/kingrt/icons/adminoff.png'"
                    alt=""
                  />
                </button>
              <button
                class="icon-mini-btn"
                type="button"
                title="Transfer owner role"
                :disabled="viewerCallRole !== 'owner' || !activeCallId || rowActionPending(row.userId) || !row.isRoomMember || row.callRole === 'owner'"
                @click="transferOwnerRole(row)"
              >
                <img src="/assets/orgas/kingrt/icons/forward.png" alt="" />
              </button>
              <button
                class="icon-mini-btn danger"
                type="button"
                title="Remove from lobby"
                :disabled="!canModerate || row.userId === currentUserId || rowActionPending(row.userId) || !row.canRemoveFromLobby"
                @click="removeLobbyUser(row.userId)"
              >
                  <img src="/assets/orgas/kingrt/icons/remove_user.png" alt="" />
                </button>
              </div>
            </li>
            <li
              v-if="usersPageRows.length > 0 && usersVirtualWindow.paddingBottom > 0"
              class="user-list-spacer"
              :style="{ height: `${usersVirtualWindow.paddingBottom}px` }"
            ></li>
            <li v-if="usersPageRows.length === 0" class="user-list-empty">
              No users match the current filter.
            </li>
          </ul>

          <footer class="footer workspace-tab-footer">
            <div class="pagination">
              <button
                class="pager-btn pager-icon-btn"
                type="button"
                :disabled="usersPage <= 1"
                @click="goToUsersPage(usersPage - 1)"
              >
                <img class="pager-icon-img" src="/assets/orgas/kingrt/icons/backward.png" alt="Previous users page" />
              </button>
              <div class="page-info">
                Page {{ usersPage }} / {{ usersPageCount }}
                · {{ usersSourceMode === 'directory' ? usersDirectoryPagination.total : filteredUsers.length }} users
              </div>
              <button
                class="pager-btn pager-icon-btn"
                type="button"
                :disabled="usersPage >= usersPageCount"
                @click="goToUsersPage(usersPage + 1)"
              >
                <img class="pager-icon-img" src="/assets/orgas/kingrt/icons/forward.png" alt="Next users page" />
              </button>
            </div>
          </footer>
        </section>

        <section class="tab-panel panel-lobby" :class="{ active: activeTab === 'lobby' }">
          <div class="lobby-toolbar">
            <div class="actions-inline lobby-toolbar-actions">
              <button
                class="icon-mini-btn"
                type="button"
                title="Allow all queued users"
                :disabled="!isSocketOnline || !canModerate || lobbyQueue.length === 0"
                @click="allowAllLobbyUsers"
              >
                <img src="/assets/orgas/kingrt/icons/add_to_call.png" alt="Allow all queued users" />
              </button>
            </div>
          </div>

          <ul
            ref="lobbyListRef"
            class="lobby-list"
            @scroll.passive="onLobbyListScroll"
          >
            <li
              v-if="lobbyPageRows.length > 0 && lobbyVirtualWindow.paddingTop > 0"
              class="user-list-spacer"
              :style="{ height: `${lobbyVirtualWindow.paddingTop}px` }"
            ></li>
            <li v-for="row in lobbyVisibleRows" :key="`${row.status}-${row.user_id}`" class="user-row">
              <div class="user-preview">{{ initials(row.display_name) }}</div>
              <div class="user-main">
                <strong class="user-name">{{ row.display_name }}</strong>
                <span class="user-role">{{ row.status }}</span>
                <span v-if="row.feedback" class="user-feedback">{{ row.feedback }}</span>
              </div>
              <div class="actions-inline">
                <button
                  class="icon-mini-btn"
                  type="button"
                  title="Allow user"
                  :disabled="!canModerate || row.status !== 'queued' || lobbyActionPending(row.user_id)"
                  @click="allowLobbyUser(row.user_id)"
                >
                  <img src="/assets/orgas/kingrt/icons/add_to_call.png" alt="" />
                </button>
                <button
                  class="icon-mini-btn danger"
                  type="button"
                  title="Remove user"
                  :disabled="!canModerate || lobbyActionPending(row.user_id)"
                  @click="removeLobbyUser(row.user_id)"
                >
                  <img src="/assets/orgas/kingrt/icons/remove_user.png" alt="" />
                </button>
              </div>
            </li>
            <li
              v-if="lobbyPageRows.length > 0 && lobbyVirtualWindow.paddingBottom > 0"
              class="user-list-spacer"
              :style="{ height: `${lobbyVirtualWindow.paddingBottom}px` }"
            ></li>
            <li v-if="lobbyPageRows.length === 0" class="user-list-empty">
              Lobby queue is currently empty.
            </li>
          </ul>

          <footer class="footer workspace-tab-footer">
            <div class="pagination">
              <button
                class="pager-btn pager-icon-btn"
                type="button"
                :disabled="lobbyPage <= 1"
                @click="goToLobbyPage(lobbyPage - 1)"
              >
                <img class="pager-icon-img" src="/assets/orgas/kingrt/icons/backward.png" alt="Previous lobby page" />
              </button>
              <div class="page-info">Page {{ lobbyPage }} / {{ lobbyPageCount }} · {{ lobbyRows.length }} entries</div>
              <button
                class="pager-btn pager-icon-btn"
                type="button"
                :disabled="lobbyPage >= lobbyPageCount"
                @click="goToLobbyPage(lobbyPage + 1)"
              >
                <img class="pager-icon-img" src="/assets/orgas/kingrt/icons/forward.png" alt="Next lobby page" />
              </button>
            </div>
          </footer>
        </section>

        <section class="tab-panel panel-chat" :class="{ active: activeTab === 'chat' }">
          <div ref="chatListRef" class="workspace-chat-list">
            <article
              v-for="message in activeMessages"
              :key="message.id"
              class="workspace-chat-message"
              :class="{ mine: message.sender.user_id === currentUserId }"
            >
              <header>
                <strong>{{ message.sender.display_name }}</strong>
                <time>{{ formatTimestamp(message.server_time) }}</time>
              </header>
              <p>{{ message.text }}</p>
            </article>
            <article v-if="activeMessages.length === 0" class="workspace-chat-empty">
              No chat messages yet.
            </article>
          </div>

          <p v-if="typingUsers.length > 0" class="workspace-typing">
            {{ typingUsers.join(', ') }} typing…
          </p>

          <form class="workspace-chat-compose" @submit.prevent="sendChatMessage">
            <input
              v-model="chatDraft"
              class="search"
              type="text"
              maxlength="2000"
              placeholder="Write a message"
              @input="handleChatInput"
            />
            <button class="icon-mini-btn" type="submit" :disabled="!isSocketOnline || chatDraft.trim() === ''">
              <img src="/assets/orgas/kingrt/icons/send.png" alt="Send" />
            </button>
          </form>
        </section>
      </aside>
    </section>
  </section>
</template>

<script setup>
import { computed, inject, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { sessionState } from '../auth/session';
import { currentBackendOrigin, fetchBackend } from '../../support/backendFetch';
import {
  resolveBackendWebSocketOriginCandidates,
  setBackendWebSocketOrigin,
} from '../../support/backendOrigin';
import {
  attachCallMediaDeviceWatcher,
  callMediaPrefs,
  refreshCallMediaDevices,
  resetCallBackgroundRuntimeState,
  setCallBackgroundFilterMode,
} from './callMediaPreferences';
import { BackgroundFilterController } from './backgroundFilterController';
import { BackgroundFilterBaselineCollector } from './backgroundFilterBaseline';
import { evaluateBackgroundFilterGates } from './backgroundFilterGates';
import { detectMediaRuntimeCapabilities } from './mediaRuntimeCapabilities';
import { appendMediaRuntimeTransitionEvent } from './mediaRuntimeTelemetry';
import { SFUClient } from '../../lib/sfu/sfuClient';
import { createWasmEncoder, createWasmDecoder } from '../../lib/wasm/wasm-codec';
import { debugLog } from '../../support/debugLogs';

const route = useRoute();
const router = useRouter();
const workspaceSidebarState = inject('workspaceSidebarState', null);

const USERS_PAGE_SIZE = 10;
const LOBBY_PAGE_SIZE = 10;
const ROSTER_VIRTUAL_ROW_HEIGHT = 72;
const ROSTER_VIRTUAL_OVERSCAN = 6;
const TYPING_LOCAL_STOP_MS = 1200;
const TYPING_SWEEP_MS = 600;
const RECONNECT_DELAYS_MS = [750, 1250, 2000, 3200, 5000, 8000];
const COMPACT_BREAKPOINT = 1180;
const REACTION_CLIENT_WINDOW_MS = 1000;
const REACTION_CLIENT_DIRECT_PER_WINDOW = 5;
const REACTION_CLIENT_BATCH_SIZE = 5;
const REACTION_CLIENT_FLUSH_INTERVAL_MS = 40;
const REACTION_CLIENT_MAX_QUEUE = 500;
const MODERATION_SYNC_FLUSH_INTERVAL_MS = 90;
const SFU_PUBLISH_RETRY_DELAY_MS = 500;
const SFU_PUBLISH_MAX_RETRIES = 24;
const SFU_CONNECT_RETRY_DELAY_MS = 1200;
const SFU_CONNECT_MAX_RETRIES = 8;
const LOCAL_REACTION_ECHO_TTL_MS = 6000;
const WLVC_ENCODE_FAILURE_THRESHOLD = 18;
const WLVC_ENCODE_FAILURE_WINDOW_MS = 4000;
const WLVC_ENCODE_WARMUP_MS = 2500;
const WLVC_ENCODE_ERROR_LOG_COOLDOWN_MS = 3000;
const LOCAL_TRACK_RECOVERY_BASE_DELAY_MS = 1200;
const LOCAL_TRACK_RECOVERY_MAX_DELAY_MS = 10_000;
const LOCAL_TRACK_RECOVERY_MAX_ATTEMPTS = 10;
const VISIBLE_PARTICIPANTS_LIMIT = 5;
const PARTICIPANT_ACTIVITY_WINDOW_MS = 15_000;
const ALONE_IDLE_PROMPT_AFTER_MS = 15 * 60 * 1000;
const ALONE_IDLE_COUNTDOWN_MS = 5 * 60 * 1000;
const ALONE_IDLE_TICK_MS = 1000;
const ALONE_IDLE_POLL_MS = 5000;
const ALONE_IDLE_ACTIVITY_EVENTS = ['pointerdown', 'keydown', 'wheel', 'touchstart'];
const DIRECTORY_USERS_ORDER_VALUES = ['role_then_name_asc', 'role_then_name_desc'];
const DIRECTORY_USERS_STATUS_VALUES = ['all', 'active', 'disabled'];

function normalizeIceServerEntry(value) {
  if (!value || typeof value !== 'object') {
    return null;
  }

  let urls = value.urls;
  if (Array.isArray(urls)) {
    urls = urls
      .map((entry) => String(entry || '').trim())
      .filter(Boolean);
    if (urls.length === 0) {
      return null;
    }
  } else {
    urls = String(urls || '').trim();
    if (urls === '') {
      return null;
    }
  }

  const normalized = { urls };
  const username = String(value.username || '').trim();
  const credential = String(value.credential || '').trim();

  if (username !== '') normalized.username = username;
  if (credential !== '') normalized.credential = credential;

  return normalized;
}

function parseIceServersFromEnv(rawValue) {
  const raw = String(rawValue || '').trim();
  if (raw === '') {
    return null;
  }

  try {
    const parsed = JSON.parse(raw);
    if (!Array.isArray(parsed)) {
      return null;
    }

    const normalized = parsed
      .map((entry) => normalizeIceServerEntry(entry))
      .filter(Boolean);
    return normalized.length > 0 ? normalized : null;
  } catch {
    const normalized = raw
      .split(',')
      .map((entry) => String(entry || '').trim())
      .filter(Boolean)
      .map((entry) => normalizeIceServerEntry({ urls: entry }))
      .filter(Boolean);
    return normalized.length > 0 ? normalized : null;
  }
}

function parseEnvFlag(value, fallback = false) {
  const normalized = String(value ?? '').trim().toLowerCase();
  if (normalized === '') return fallback;
  return ['1', 'true', 'yes', 'on'].includes(normalized);
}

const DEFAULT_NATIVE_ICE_SERVERS = parseIceServersFromEnv(import.meta.env.VITE_VIDEOCHAT_ICE_SERVERS) || [
  { urls: 'stun:stun.l.google.com:19302' },
  { urls: 'stun:stun1.l.google.com:19302' },
];
const SFU_RUNTIME_ENABLED = parseEnvFlag(import.meta.env.VITE_VIDEOCHAT_ENABLE_SFU, true);

function mediaDebugLog(...args) {
  debugLog(...args);
}

const reactionOptions = ['👍', '❤️', '🐘', '🥳', '😂', '😮', '😢', '🤔', '👏', '👎'];

function normalizeRoomId(value) {
  const candidate = String(value || '').trim().toLowerCase();
  if (candidate === '' || candidate.length > 120) return 'lobby';
  return /^[a-z0-9._-]+$/.test(candidate) ? candidate : 'lobby';
}

function normalizeRole(value) {
  const role = String(value || '').trim().toLowerCase();
  if (role === 'admin') return role;
  return 'user';
}

function normalizeUsersDirectoryOrder(value) {
  const normalized = String(value || '').trim().toLowerCase();
  if (DIRECTORY_USERS_ORDER_VALUES.includes(normalized)) return normalized;
  return 'role_then_name_asc';
}

function normalizeUsersDirectoryStatus(value) {
  const normalized = String(value || '').trim().toLowerCase();
  if (DIRECTORY_USERS_STATUS_VALUES.includes(normalized)) return normalized;
  return 'all';
}

function parseUsersDirectoryQuery(rawValue) {
  const input = String(rawValue || '').trim();
  if (input === '') {
    return {
      query: '',
      status: 'all',
      order: 'role_then_name_asc',
    };
  }

  const queryTerms = [];
  let status = 'all';
  let order = 'role_then_name_asc';
  for (const token of input.split(/\s+/).filter(Boolean)) {
    const normalized = token.trim().toLowerCase();
    if (normalized === 'status:active' || normalized === 'is:active') {
      status = 'active';
      continue;
    }
    if (normalized === 'status:disabled' || normalized === 'is:disabled' || normalized === 'is:inactive') {
      status = 'disabled';
      continue;
    }
    if (
      normalized === 'sort:desc'
      || normalized === 'sort:za'
      || normalized === 'order:desc'
      || normalized === 'order:za'
    ) {
      order = 'role_then_name_desc';
      continue;
    }
    if (
      normalized === 'sort:asc'
      || normalized === 'sort:az'
      || normalized === 'order:asc'
      || normalized === 'order:az'
    ) {
      order = 'role_then_name_asc';
      continue;
    }

    queryTerms.push(token);
  }

  return {
    query: queryTerms.join(' ').trim(),
    status: normalizeUsersDirectoryStatus(status),
    order: normalizeUsersDirectoryOrder(order),
  };
}

function roleRank(role) {
  if (role === 'admin') return 0;
  return 1;
}

function normalizeCallRole(value) {
  const role = String(value || '').trim().toLowerCase();
  if (role === 'owner' || role === 'moderator') return role;
  return 'participant';
}

function callRoleRank(role) {
  if (role === 'owner') return 0;
  if (role === 'moderator') return 1;
  return 2;
}

function requestHeaders(withBody = false) {
  const headers = { accept: 'application/json' };
  if (withBody) headers['content-type'] = 'application/json';

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

function buildApiRequestError(payload, fallbackMessage, responseStatus = 0) {
  const error = new Error(extractErrorMessage(payload, fallbackMessage));
  error.responseStatus = Number(responseStatus) || 0;
  error.responseCode = String(payload?.error?.code || '').trim().toLowerCase();
  return error;
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
    throw buildApiRequestError(payload, `Request failed (${response.status}).`, response.status);
  }

  if (!payload || payload.status !== 'ok') {
    throw new Error('Backend returned an invalid payload.');
  }

  return payload;
}

function socketUrlForRoom(roomId, socketOrigin) {
  const httpUrl = new URL(socketOrigin);
  httpUrl.protocol = httpUrl.protocol === 'https:' ? 'wss:' : 'ws:';
  httpUrl.pathname = '/ws';
  httpUrl.search = '';
  httpUrl.searchParams.set('room', normalizeRoomId(roomId));

  const token = String(sessionState.sessionToken || '').trim();
  if (token !== '') {
    httpUrl.searchParams.set('session', token);
  }

  return httpUrl.toString();
}

function formatTimestamp(value) {
  if (typeof value !== 'string' || value.trim() === '') return '--';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return value;

  return new Intl.DateTimeFormat('en-GB', {
    year: 'numeric',
    month: 'short',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
  }).format(date);
}

function initials(name) {
  const raw = String(name || '').trim();
  if (raw === '') return 'U';
  const parts = raw.split(/\s+/).filter(Boolean);
  if (parts.length === 1) {
    return parts[0].slice(0, 2).toUpperCase();
  }
  return `${parts[0][0] || ''}${parts[1][0] || ''}`.toUpperCase();
}

const activeTab = ref('users');
const usersSearch = ref('');
const usersPage = ref(1);
const lobbyPage = ref(1);
const chatDraft = ref('');
const chatListRef = ref(null);
const usersListRef = ref(null);
const lobbyListRef = ref(null);

const connectionState = ref('retrying');
const connectionReason = ref('');
const reconnectAttempt = ref(0);
const socketRef = ref(null);
const serverRoomId = ref('lobby');

const participantsRaw = ref([]);
const lobbyQueue = ref([]);
const lobbyAdmitted = ref([]);
const lobbyNotificationState = reactive({
  hasSnapshot: false,
  toastVisible: false,
  toastMessage: '',
});
const aloneIdlePrompt = reactive({
  visible: false,
  deadlineMs: 0,
  remainingMs: ALONE_IDLE_COUNTDOWN_MS,
});
const usersDirectoryRows = ref([]);
const usersDirectoryLoading = ref(false);
const usersDirectoryPagination = reactive({
  query: '',
  status: 'all',
  order: 'role_then_name_asc',
  page: 1,
  pageSize: USERS_PAGE_SIZE,
  total: 0,
  pageCount: 1,
  returned: 0,
  hasPrev: false,
  hasNext: false,
  error: '',
});

const chatByRoom = reactive({});
const typingByRoom = reactive({});

const mutedUsers = reactive({});
const pinnedUsers = reactive({});
const participantActivityByUserId = reactive({});
const moderationActionState = reactive({});
const peerControlStateByUserId = reactive({});
const lobbyActionState = reactive({});
const usersRefreshTimer = ref(null);
const usersListViewport = reactive({
  scrollTop: 0,
  viewportHeight: 0,
});
const lobbyListViewport = reactive({
  scrollTop: 0,
  viewportHeight: 0,
});

const reactionTrayOpen = ref(false);
const activeReactions = ref([]);
const localReactionEchoes = ref([]);
let reactionId = 0;
const queuedReactionEmojis = ref([]);
let reactionQueueTimer = null;
let reactionWindowStartedMs = 0;
let reactionSentInWindow = 0;
let reactionBatchCounter = 0;
let moderationSyncTimer = null;
const moderationSyncQueue = reactive({});

const controlState = reactive({
  handRaised: false,
  cameraEnabled: true,
  micEnabled: true,
  screenEnabled: false,
});
const rightSidebarCollapsed = ref(false);
const isCompactViewport = ref(false);

const workspaceError = ref('');
const workspaceNotice = ref('');
const viewerCallRole = ref('participant');
const activeCallId = ref('');
const loadedCallId = ref('');
const callParticipantRoles = reactive({});
const routeCallResolve = reactive({
  accessId: '',
  callId: '',
  roomId: 'lobby',
  pending: false,
  error: '',
});
let routeCallResolveSeq = 0;
const pendingAdmissionJoinRoomId = ref('');
const hasRealtimeRoomSync = ref(false);

const sfuClientRef = ref(null);
const mediaRuntimeCapabilities = ref({
  checkedAt: '',
  wlvcWasm: {
    webAssembly: false,
    encoder: false,
    decoder: false,
    reason: 'not_checked',
  },
  webRtcNative: false,
  stageA: false,
  stageB: false,
  preferredPath: 'unsupported',
  reasons: ['not_checked'],
});
const mediaRuntimePath = ref('pending');
const mediaRuntimeReason = ref('boot');
const nativePeerConnectionsRef = ref(new Map());
let runtimeSwitchInFlight = false;
let wlvcEncodeFailureCount = 0;
let wlvcEncodeWarmupUntilMs = 0;
let wlvcEncodeFirstFailureAtMs = 0;
let wlvcEncodeLastErrorLogAtMs = 0;
const localTracksRef = ref([]);
const remotePeersRef = ref(new Map());
const sfuConnected = ref(false);
let sfuConnectRetryCount = 0;
let detachMediaDeviceWatcher = null;
let localTrackReconfigureInFlight = false;
let localTrackReconfigureQueued = false;
let compactMediaQuery = null;

const routeCallRef = computed(() => String(route.params.callRef || '').trim());
const desiredRoomId = computed(() => normalizeRoomId(routeCallResolve.roomId || routeCallRef.value || 'lobby'));
const activeRoomId = computed(() => normalizeRoomId(serverRoomId.value || desiredRoomId.value));
const currentUserId = computed(() => (Number.isInteger(sessionState.userId) ? sessionState.userId : 0));
const canModerate = computed(() => (
  normalizeRole(sessionState.role) === 'admin'
  || viewerCallRole.value === 'owner'
  || viewerCallRole.value === 'moderator'
));
const usersSourceMode = computed(() => 'snapshot');
const isSocketOnline = computed(() => connectionState.value === 'online');
const shouldConnectSfu = computed(() => (
  isWlvcRuntimePath()
  && isSocketOnline.value
  && hasRealtimeRoomSync.value
  && activeRoomId.value === desiredRoomId.value
));
const isShellLeftSidebarCollapsed = computed(() => {
  const candidate = workspaceSidebarState?.leftSidebarCollapsed;
  if (candidate && typeof candidate === 'object' && 'value' in candidate) {
    return Boolean(candidate.value);
  }
  return Boolean(candidate);
});
const isShellTabletViewport = computed(() => {
  const candidate = workspaceSidebarState?.isTabletViewport;
  if (candidate && typeof candidate === 'object' && 'value' in candidate) {
    return Boolean(candidate.value);
  }
  return Boolean(candidate);
});
const isShellTabletSidebarOpen = computed(() => {
  const candidate = workspaceSidebarState?.isTabletSidebarOpen;
  if (candidate && typeof candidate === 'object' && 'value' in candidate) {
    return Boolean(candidate.value);
  }
  return Boolean(candidate);
});
const isShellMobileViewport = computed(() => {
  const candidate = workspaceSidebarState?.isMobileViewport;
  if (candidate && typeof candidate === 'object' && 'value' in candidate) {
    return Boolean(candidate.value);
  }
  return Boolean(candidate);
});
const isCompactLayoutViewport = computed(() => (
  isShellMobileViewport.value
  || isShellTabletViewport.value
));
const isCompactHeaderVisible = computed(() => (
  isCompactViewport.value
  && isCompactLayoutViewport.value
));
const showLeftSidebarRestoreButton = computed(() => {
  if (isCompactHeaderVisible.value || isShellMobileViewport.value) {
    return false;
  }
  if (isShellTabletViewport.value) {
    return !isShellTabletSidebarOpen.value;
  }
  return !isCompactViewport.value && isShellLeftSidebarCollapsed.value;
});

function isWlvcRuntimePath() {
  return mediaRuntimePath.value === 'wlvc_wasm';
}

function isNativeWebRtcRuntimePath() {
  return mediaRuntimePath.value === 'webrtc_native';
}

function setMediaRuntimePath(nextPath, reason) {
  const previousPath = mediaRuntimePath.value;
  const normalizedPath = String(nextPath || '').trim() || 'unsupported';
  const normalizedReason = String(reason || '').trim() || 'unspecified';

  mediaRuntimePath.value = normalizedPath;
  mediaRuntimeReason.value = normalizedReason;

  if (previousPath !== normalizedPath) {
    appendMediaRuntimeTransitionEvent({
      from_path: previousPath,
      to_path: normalizedPath,
      reason: normalizedReason,
      user_id: currentUserId.value,
      call_id: activeCallId.value,
      room_id: activeRoomId.value,
      capabilities: {
        stage_a: mediaRuntimeCapabilities.value.stageA,
        stage_b: mediaRuntimeCapabilities.value.stageB,
        preferred_path: mediaRuntimeCapabilities.value.preferredPath,
        reasons: mediaRuntimeCapabilities.value.reasons,
      },
    });
  }
}

function isUuidLike(value) {
  const normalized = String(value || '').trim().toLowerCase();
  return /^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/.test(normalized);
}

function applyRouteCallResolution({
  accessId = '',
  callId = '',
  roomId = 'lobby',
  error = '',
  pending = false,
} = {}) {
  routeCallResolve.accessId = String(accessId || '').trim().toLowerCase();
  routeCallResolve.callId = String(callId || '').trim();
  routeCallResolve.roomId = normalizeRoomId(String(roomId || '').trim() || 'lobby');
  routeCallResolve.error = String(error || '').trim();
  routeCallResolve.pending = Boolean(pending);

  if (routeCallResolve.callId !== '') {
    activeCallId.value = routeCallResolve.callId;
    loadedCallId.value = '';
  }
}

function callPayloadToRouteResolution(callPayload) {
  const call = callPayload && typeof callPayload === 'object' ? callPayload : {};
  return {
    callId: String(call.id || '').trim(),
    roomId: String(call.room_id || '').trim() || 'lobby',
  };
}

async function tryResolveRouteAsAccessId(callRef) {
  const payload = await apiRequest(`/api/call-access/${encodeURIComponent(callRef)}`);
  const result = payload?.result || {};
  const accessLink = result?.access_link || {};
  const call = result?.call || {};
  const resolution = callPayloadToRouteResolution(call);
  const normalizedAccessId = String(accessLink?.id || '').trim().toLowerCase();
  return {
    accessId: normalizedAccessId,
    callId: resolution.callId,
    roomId: resolution.roomId,
  };
}

async function tryResolveRouteAsCallId(callRef) {
  const payload = await apiRequest(`/api/calls/${encodeURIComponent(callRef)}`);
  const resolution = callPayloadToRouteResolution(payload?.call || {});
  return {
    accessId: '',
    callId: resolution.callId || String(callRef || '').trim(),
    roomId: resolution.roomId,
  };
}

async function resolveRouteCallRef(callRef) {
  const normalized = String(callRef || '').trim();
  const seq = routeCallResolveSeq + 1;
  routeCallResolveSeq = seq;
  const looksLikeUuid = isUuidLike(normalized);

  if (normalized === '') {
    if (seq !== routeCallResolveSeq) return;
    applyRouteCallResolution({
      accessId: '',
      callId: '',
      roomId: 'lobby',
      error: '',
      pending: false,
    });
    return;
  }

  if (seq === routeCallResolveSeq) {
    applyRouteCallResolution({
      accessId: '',
      callId: '',
      roomId: normalized,
      error: '',
      pending: true,
    });
  }

  if (looksLikeUuid) {
    try {
      const callResolution = await tryResolveRouteAsCallId(normalized);
      if (seq !== routeCallResolveSeq) return;
      applyRouteCallResolution({
        ...callResolution,
        error: '',
        pending: false,
      });
      return;
    } catch {
      // UUID-like route refs can be either call ids or access-link ids.
      // Try access-link resolution when direct call lookup fails.
      try {
        const accessResolution = await tryResolveRouteAsAccessId(normalized);
        if (seq !== routeCallResolveSeq) return;
        applyRouteCallResolution({
          ...accessResolution,
          error: '',
          pending: false,
        });
        return;
      } catch (accessError) {
        const accessResponseStatus = Number(accessError?.responseStatus || 0);
        if (accessResponseStatus === 410) {
          if (seq !== routeCallResolveSeq) return;
          applyRouteCallResolution({
            accessId: normalized.toLowerCase(),
            callId: '',
            roomId: 'lobby',
            error: 'route_call_access_expired',
            pending: false,
          });

          const fallbackRouteName = normalizeRole(sessionState.role) === 'admin' ? 'admin-calls' : 'user-dashboard';
          if (String(route.name || '') === 'call-workspace' && String(routeCallRef.value || '').trim() !== '') {
            void router.replace({ name: fallbackRouteName });
          }
          return;
        }

        if (seq !== routeCallResolveSeq) return;
        applyRouteCallResolution({
          accessId: '',
          callId: '',
          roomId: 'lobby',
          error: 'route_call_ref_not_found',
          pending: false,
        });

        const fallbackRouteName = normalizeRole(sessionState.role) === 'admin' ? 'admin-calls' : 'user-dashboard';
        if (String(route.name || '') === 'call-workspace' && String(routeCallRef.value || '').trim() !== '') {
          void router.replace({ name: fallbackRouteName });
        }
        return;
      }
    }
  }

  try {
    const callResolution = await tryResolveRouteAsCallId(normalized);
    if (seq !== routeCallResolveSeq) return;
    applyRouteCallResolution({
      ...callResolution,
      error: '',
      pending: false,
    });
    return;
  } catch {
    // Fall back to treating param as room id.
  }

  if (seq !== routeCallResolveSeq) return;
  applyRouteCallResolution({
    accessId: '',
    callId: '',
    roomId: normalized,
    error: '',
    pending: false,
  });
}

function ensureRoomBuckets(roomId) {
  const normalizedRoomId = normalizeRoomId(roomId);
  if (!Array.isArray(chatByRoom[normalizedRoomId])) {
    chatByRoom[normalizedRoomId] = [];
  }
  if (!typingByRoom[normalizedRoomId] || typeof typingByRoom[normalizedRoomId] !== 'object') {
    typingByRoom[normalizedRoomId] = {};
  }
}

function normalizeParticipantRow(raw) {
  const user = raw && typeof raw.user === 'object' ? raw.user : {};
  const userId = Number(user.id);
  const connectionId = String(raw?.connection_id || '').trim();
  return {
    connectionId,
    hasConnection: connectionId !== '',
    roomId: normalizeRoomId(raw?.room_id || 'lobby'),
    userId: Number.isInteger(userId) && userId > 0 ? userId : 0,
    displayName: String(user.display_name || '').trim() || `User ${userId || 'unknown'}`,
    role: normalizeRole(user.role),
    callRole: normalizeCallRole(user.call_role || raw?.call_role || 'participant'),
    connectedAt: String(raw?.connected_at || ''),
  };
}

const participantUsers = computed(() => {
  const aggregate = new Map();

  for (const row of participantsRaw.value) {
    const normalized = normalizeParticipantRow(row);
    if (normalized.userId <= 0) continue;

    const existing = aggregate.get(normalized.userId);
    if (!existing) {
      aggregate.set(normalized.userId, {
        userId: normalized.userId,
        displayName: normalized.displayName,
        role: normalized.role,
        callRole: normalized.callRole,
        connectedAt: normalized.connectedAt,
        connections: normalized.hasConnection ? 1 : 0,
      });
      continue;
    }

    if (normalized.hasConnection) {
      existing.connections += 1;
    }
    if (roleRank(normalized.role) < roleRank(existing.role)) {
      existing.role = normalized.role;
    }
    if (normalized.displayName.length > existing.displayName.length) {
      existing.displayName = normalized.displayName;
    }
    if (callRoleRank(normalized.callRole) < callRoleRank(existing.callRole)) {
      existing.callRole = normalized.callRole;
    }
  }

  return Array.from(aggregate.values()).sort((left, right) => {
    const roleCmp = roleRank(left.role) - roleRank(right.role);
    if (roleCmp !== 0) return roleCmp;
    const nameCmp = left.displayName.localeCompare(right.displayName, 'en', { sensitivity: 'base' });
    if (nameCmp !== 0) return nameCmp;
    return left.userId - right.userId;
  });
});

const connectedParticipantUsers = computed(() => (
  participantUsers.value.filter((row) => Number(row?.connections || 0) > 0)
));
const isAloneInCall = computed(() => {
  const participants = connectedParticipantUsers.value;
  if (participants.length !== 1) return false;
  return Number(participants[0]?.userId || 0) === currentUserId.value;
});

function participantActivityWeight(source) {
  const normalized = String(source || '').trim().toLowerCase();
  if (normalized === 'media_frame') return 1;
  if (normalized === 'media_track') return 0.85;
  if (normalized === 'reaction') return 0.72;
  if (normalized === 'chat') return 0.68;
  if (normalized === 'typing') return 0.6;
  if (normalized === 'control') return 0.45;
  return 0.5;
}

function markParticipantActivity(userId, source = 'control', atMs = Date.now()) {
  const normalizedUserId = Number(userId);
  if (!Number.isInteger(normalizedUserId) || normalizedUserId <= 0) return;
  const nowMs = Number.isFinite(Number(atMs)) ? Math.max(0, Number(atMs)) : Date.now();
  participantActivityByUserId[normalizedUserId] = {
    lastActiveMs: nowMs,
    source: String(source || '').trim().toLowerCase(),
    weight: participantActivityWeight(source),
  };
}

function pruneParticipantActivity(allowedUserIds = null) {
  const allowed = allowedUserIds instanceof Set ? allowedUserIds : null;
  const nowMs = Date.now();
  const staleAfterMs = PARTICIPANT_ACTIVITY_WINDOW_MS * 2;
  for (const key of Object.keys(participantActivityByUserId)) {
    const userId = Number(key);
    if (!Number.isInteger(userId) || userId <= 0) {
      delete participantActivityByUserId[key];
      continue;
    }
    if (allowed && !allowed.has(userId)) {
      delete participantActivityByUserId[key];
      continue;
    }
    const entry = participantActivityByUserId[key];
    const lastActiveMs = Number(entry?.lastActiveMs || 0);
    if (!Number.isFinite(lastActiveMs) || (nowMs - lastActiveMs) > staleAfterMs) {
      delete participantActivityByUserId[key];
    }
  }
}

function participantActivityScore(userId, nowMs = Date.now()) {
  const normalizedUserId = Number(userId);
  if (!Number.isInteger(normalizedUserId) || normalizedUserId <= 0) return 0;
  const entry = participantActivityByUserId[normalizedUserId];
  if (!entry || typeof entry !== 'object') return 0;
  const lastActiveMs = Number(entry.lastActiveMs || 0);
  if (!Number.isFinite(lastActiveMs) || lastActiveMs <= 0) return 0;
  const ageMs = Math.max(0, nowMs - lastActiveMs);
  if (ageMs >= PARTICIPANT_ACTIVITY_WINDOW_MS) return 0;
  const freshness = 1 - (ageMs / PARTICIPANT_ACTIVITY_WINDOW_MS);
  const weight = Number.isFinite(Number(entry.weight)) ? Number(entry.weight) : 0.5;
  return freshness * Math.max(0.25, Math.min(1, weight)) * 100;
}

function participantVisibilityScore(row, nowMs = Date.now()) {
  const userId = Number(row?.userId || 0);
  if (!Number.isInteger(userId) || userId <= 0) return 0;
  const pinnedBoost = pinnedUsers[userId] === true ? 1000 : 0;
  const localBoost = userId === currentUserId.value ? 30 : 0;
  const callRole = normalizeCallRole(row?.callRole || 'participant');
  const roleBoost = callRole === 'owner' ? 14 : (callRole === 'moderator' ? 8 : 0);
  const peerState = userId === currentUserId.value
    ? controlState
    : (peerControlStateByUserId[userId] && typeof peerControlStateByUserId[userId] === 'object'
      ? peerControlStateByUserId[userId]
      : null);
  const raisedHandBoost = Boolean(peerState?.handRaised) ? 10 : 0;
  return pinnedBoost + localBoost + roleBoost + raisedHandBoost + participantActivityScore(userId, nowMs);
}

const stripParticipants = computed(() => {
  const nowMs = Date.now();
  const rows = [...connectedParticipantUsers.value];
  rows.sort((left, right) => {
    const scoreCmp = participantVisibilityScore(right, nowMs) - participantVisibilityScore(left, nowMs);
    if (scoreCmp !== 0) return scoreCmp;
    const roleCmp = roleRank(left.role) - roleRank(right.role);
    if (roleCmp !== 0) return roleCmp;
    const nameCmp = left.displayName.localeCompare(right.displayName, 'en', { sensitivity: 'base' });
    if (nameCmp !== 0) return nameCmp;
    return left.userId - right.userId;
  });
  return rows.slice(0, VISIBLE_PARTICIPANTS_LIMIT);
});

const showMiniParticipantStrip = computed(() => connectedParticipantUsers.value.length > 1);
const showLobbyRequestBadge = computed(() => (
  canModerate.value
  && lobbyQueue.value.length > 0
  && activeTab.value !== 'lobby'
));
const lobbyRequestBadgeText = computed(() => (
  lobbyQueue.value.length > 99 ? '99+' : String(lobbyQueue.value.length)
));
const showLobbyJoinToast = computed(() => (
  canModerate.value
  && rightSidebarCollapsed.value
  && lobbyNotificationState.toastVisible
  && lobbyNotificationState.toastMessage !== ''
));
const lobbyJoinToastMessage = computed(() => lobbyNotificationState.toastMessage);
const aloneIdleCountdownLabel = computed(() => {
  const remainingMs = Math.max(0, Number(aloneIdlePrompt.remainingMs || 0));
  const totalSeconds = Math.ceil(remainingMs / 1000);
  const minutes = Math.floor(totalSeconds / 60);
  const seconds = totalSeconds % 60;
  return `${minutes}:${String(seconds).padStart(2, '0')}`;
});

const snapshotUsersRows = computed(() => participantUsers.value.map((row) => userRowSnapshot(row)));

const filteredUsers = computed(() => {
  if (usersSourceMode.value === 'directory') {
    return usersDirectoryRows.value;
  }

  const query = usersSearch.value.trim().toLowerCase();
  if (query === '') return snapshotUsersRows.value;

  return snapshotUsersRows.value.filter((row) => (
    String(row.displayName || '').toLowerCase().includes(query)
    || String(row.role || '').toLowerCase().includes(query)
    || String(row.userId || '').includes(query)
    || String(row.feedback || '').toLowerCase().includes(query)
  ));
});

const usersPageCount = computed(() => {
  if (usersSourceMode.value === 'directory') {
    return Math.max(1, usersDirectoryPagination.pageCount || 1);
  }

  return Math.max(1, Math.ceil(filteredUsers.value.length / USERS_PAGE_SIZE));
});
const usersPageRows = computed(() => {
  if (usersSourceMode.value === 'directory') {
    return usersDirectoryRows.value.map((row) => userRowSnapshot(row));
  }

  const offset = (usersPage.value - 1) * USERS_PAGE_SIZE;
  return filteredUsers.value.slice(offset, offset + USERS_PAGE_SIZE).map((row) => userRowSnapshot(row));
});

function updateListViewportMetrics(node, viewport) {
  if (!(node instanceof HTMLElement)) return;
  viewport.scrollTop = Math.max(0, Number(node.scrollTop || 0));
  viewport.viewportHeight = Math.max(0, Number(node.clientHeight || 0));
}

function computeVirtualWindow(rows, viewport) {
  const total = Array.isArray(rows) ? rows.length : 0;
  if (total <= 0) {
    return {
      start: 0,
      end: 0,
      paddingTop: 0,
      paddingBottom: 0,
      rows: [],
    };
  }

  const viewportHeight = Math.max(
    ROSTER_VIRTUAL_ROW_HEIGHT * 3,
    Number(viewport?.viewportHeight || 0)
  );
  const contentHeight = total * ROSTER_VIRTUAL_ROW_HEIGHT;
  const maxScrollTop = Math.max(0, contentHeight - viewportHeight);
  const scrollTop = Math.max(0, Math.min(Number(viewport?.scrollTop || 0), maxScrollTop));
  const start = Math.max(0, Math.floor(scrollTop / ROSTER_VIRTUAL_ROW_HEIGHT) - ROSTER_VIRTUAL_OVERSCAN);
  const visibleCount = Math.ceil(viewportHeight / ROSTER_VIRTUAL_ROW_HEIGHT) + (ROSTER_VIRTUAL_OVERSCAN * 2);
  const end = Math.min(total, start + visibleCount);
  const paddingTop = start * ROSTER_VIRTUAL_ROW_HEIGHT;
  const paddingBottom = Math.max(0, (total - end) * ROSTER_VIRTUAL_ROW_HEIGHT);

  return {
    start,
    end,
    paddingTop,
    paddingBottom,
    rows: rows.slice(start, end),
  };
}

const usersVirtualWindow = computed(() => computeVirtualWindow(usersPageRows.value, usersListViewport));
const usersVisibleRows = computed(() => usersVirtualWindow.value.rows);

const lobbyRows = computed(() => {
  const queued = lobbyQueue.value.map((row) => ({
    ...row,
    status: 'queued',
    sortTs: Number(row.requested_unix_ms || 0),
  }));
  const admitted = lobbyAdmitted.value.map((row) => ({
    ...row,
    status: 'admitted',
    sortTs: Number(row.admitted_unix_ms || 0),
  }));

  return [...queued, ...admitted].sort((left, right) => {
    if (left.status !== right.status) return left.status.localeCompare(right.status);
    if (left.sortTs !== right.sortTs) return left.sortTs - right.sortTs;
    return String(left.display_name || '').localeCompare(String(right.display_name || ''), 'en', { sensitivity: 'base' });
  });
});

const lobbyPageCount = computed(() => Math.max(1, Math.ceil(lobbyRows.value.length / LOBBY_PAGE_SIZE)));
const lobbyPageRows = computed(() => {
  const offset = (lobbyPage.value - 1) * LOBBY_PAGE_SIZE;
  return lobbyRows.value.slice(offset, offset + LOBBY_PAGE_SIZE).map((row) => lobbyRowSnapshot(row));
});

const lobbyVirtualWindow = computed(() => computeVirtualWindow(lobbyPageRows.value, lobbyListViewport));
const lobbyVisibleRows = computed(() => lobbyVirtualWindow.value.rows);

const activeMessages = computed(() => {
  const bucket = chatByRoom[activeRoomId.value];
  return Array.isArray(bucket) ? bucket : [];
});

const typingUsers = computed(() => {
  const rows = typingByRoom[activeRoomId.value];
  if (!rows || typeof rows !== 'object') return [];
  const nowMs = Date.now();
  return Object.values(rows)
    .filter((entry) => Number(entry.expiresAtMs || 0) > nowMs)
    .sort((left, right) => String(left.displayName || '').localeCompare(String(right.displayName || ''), 'en', { sensitivity: 'base' }))
    .map((entry) => String(entry.displayName || '').trim())
    .filter(Boolean);
});

const participantsByUserId = computed(() => {
  const rows = new Map();
  for (const row of connectedParticipantUsers.value) {
    rows.set(row.userId, row);
  }
  return rows;
});

const lobbyEntryByUserId = computed(() => {
  const rows = new Map();
  for (const row of lobbyQueue.value) {
    rows.set(row.user_id, { ...row, status: 'queued' });
  }
  for (const row of lobbyAdmitted.value) {
    rows.set(row.user_id, { ...row, status: 'admitted' });
  }
  return rows;
});

function rowActionKey(action, userId) {
  return `${action}:${Number(userId)}`;
}

function setRowAction(store, action, userId, text = '', pending = false) {
  store[rowActionKey(action, userId)] = {
    text: String(text || '').trim(),
    pending: Boolean(pending),
    updatedAt: Date.now(),
  };
}

function clearRowAction(store, action, userId) {
  delete store[rowActionKey(action, userId)];
}

function rowActionPending(userId) {
  const normalizedUserId = Number(userId);
  if (!Number.isInteger(normalizedUserId) || normalizedUserId <= 0) return false;
  for (const action of ['mute', 'pin', 'role', 'owner']) {
    const entry = moderationActionState[rowActionKey(action, normalizedUserId)];
    if (entry && entry.pending) return true;
  }
  for (const action of ['allow', 'remove']) {
    const entry = lobbyActionState[rowActionKey(action, normalizedUserId)];
    if (entry && entry.pending) return true;
  }
  return false;
}

function rowActionFeedback(userId) {
  const normalizedUserId = Number(userId);
  if (!Number.isInteger(normalizedUserId) || normalizedUserId <= 0) return '';
  const actions = [
    moderationActionState[rowActionKey('mute', normalizedUserId)],
    moderationActionState[rowActionKey('pin', normalizedUserId)],
    moderationActionState[rowActionKey('role', normalizedUserId)],
    moderationActionState[rowActionKey('owner', normalizedUserId)],
    lobbyActionState[rowActionKey('allow', normalizedUserId)],
    lobbyActionState[rowActionKey('remove', normalizedUserId)],
  ];
  const active = actions.find((entry) => entry && (entry.pending || String(entry.text || '').trim() !== ''));
  return active ? String(active.text || '').trim() : '';
}

function lobbyActionPending(userId) {
  const normalizedUserId = Number(userId);
  if (!Number.isInteger(normalizedUserId) || normalizedUserId <= 0) return false;
  for (const action of ['allow', 'remove']) {
    const entry = lobbyActionState[rowActionKey(action, normalizedUserId)];
    if (entry && entry.pending) return true;
  }
  return false;
}

function userRowSnapshot(row) {
  const participant = participantsByUserId.value.get(row.userId) || null;
  const lobbyEntry = lobbyEntryByUserId.value.get(row.userId) || null;
  const feedback = rowActionFeedback(row.userId);
  const isRoomMember = Boolean(participant);
  const mappedCallRole = normalizeCallRole(
    callParticipantRoles[row.userId]
      || participant?.callRole
      || (row.userId === currentUserId.value ? viewerCallRole.value : row.callRole || 'participant')
  );
  const peerState = peerControlStateByUserId[row.userId] || {};
  return {
    ...row,
    callRole: mappedCallRole,
    isRoomMember,
    roomConnectionCount: Number(participant?.connections || 0),
    inLobby: Boolean(lobbyEntry),
    lobbyStatus: lobbyEntry ? String(lobbyEntry.status || 'queued') : '',
    canRemoveFromLobby: Boolean(lobbyEntry) && canModerate.value,
    canAllowFromLobby: Boolean(lobbyEntry && lobbyEntry.status === 'queued' && canModerate.value),
    feedback,
    controlBadge: describePeerControlState(row.userId),
    peerState,
  };
}

function lobbyRowSnapshot(row) {
  const feedback = rowActionFeedback(row.user_id);
  return {
    ...row,
    feedback,
  };
}

function peerControlSnapshot(userId) {
  const normalizedUserId = Number(userId);
  if (!Number.isInteger(normalizedUserId) || normalizedUserId <= 0) {
    return {
      handRaised: false,
      cameraEnabled: true,
      micEnabled: true,
      screenEnabled: false,
    };
  }

  if (normalizedUserId === currentUserId.value) {
    return {
      handRaised: controlState.handRaised,
      cameraEnabled: controlState.cameraEnabled,
      micEnabled: controlState.micEnabled,
      screenEnabled: controlState.screenEnabled,
    };
  }

  if (!peerControlStateByUserId[normalizedUserId] || typeof peerControlStateByUserId[normalizedUserId] !== 'object') {
    peerControlStateByUserId[normalizedUserId] = {
      handRaised: false,
      cameraEnabled: true,
      micEnabled: true,
      screenEnabled: false,
    };
  }

  return peerControlStateByUserId[normalizedUserId];
}

function describePeerControlState(userId) {
  const state = peerControlSnapshot(userId);
  const badges = [];
  if (state.handRaised) badges.push('hand');
  if (!state.micEnabled) badges.push('mic off');
  if (!state.cameraEnabled) badges.push('cam off');
  if (state.screenEnabled) badges.push('screen');
  return badges.join(' · ');
}

function setNotice(message, kind = 'ok') {
  workspaceNotice.value = String(message || '').trim();
  if (kind === 'error') {
    workspaceError.value = workspaceNotice.value;
    workspaceNotice.value = '';
  } else {
    workspaceError.value = '';
  }
}

function normalizeSignalCommandType(type) {
  return String(type || '').trim().toLowerCase();
}

function isCallSignalType(type) {
  const normalized = normalizeSignalCommandType(type);
  return normalized === 'call/offer'
    || normalized === 'call/answer'
    || normalized === 'call/ice'
    || normalized === 'call/hangup';
}

function shouldSuppressCallAckNotice(signalType) {
  const normalized = normalizeSignalCommandType(signalType).replace('call/', '');
  return normalized === 'offer'
    || normalized === 'answer'
    || normalized === 'ice'
    || normalized === 'hangup';
}

function shouldSuppressExpectedSignalingError(payload) {
  const code = String(payload?.code || '').trim().toLowerCase();
  if (code !== 'signaling_publish_failed') return false;

  const details = payload && typeof payload.details === 'object' ? payload.details : {};
  const commandType = normalizeSignalCommandType(details?.type);
  if (!isCallSignalType(commandType)) return false;

  const signalingError = String(details?.error || '').trim().toLowerCase();
  return signalingError === 'target_not_in_room'
    || signalingError === 'target_delivery_failed'
    || signalingError === 'sender_not_in_room';
}

function clearErrors() {
  workspaceError.value = '';
}

function pruneLocalReactionEchoes(nowMs = Date.now()) {
  localReactionEchoes.value = localReactionEchoes.value.filter(
    (entry) => Number(entry?.expiresAtMs || 0) > nowMs
  );
}

function trackLocalReactionEcho(emoji) {
  const normalizedEmoji = String(emoji || '').trim();
  if (normalizedEmoji === '') return;
  const nowMs = Date.now();
  pruneLocalReactionEchoes(nowMs);
  localReactionEchoes.value = [
    ...localReactionEchoes.value,
    {
      emoji: normalizedEmoji,
      expiresAtMs: nowMs + LOCAL_REACTION_ECHO_TTL_MS,
    },
  ];
}

function consumeLocalReactionEcho(emoji, senderUserId) {
  if (senderUserId !== currentUserId.value) return false;
  const normalizedEmoji = String(emoji || '').trim();
  if (normalizedEmoji === '') return false;

  const nowMs = Date.now();
  pruneLocalReactionEchoes(nowMs);
  const index = localReactionEchoes.value.findIndex(
    (entry) => String(entry?.emoji || '') === normalizedEmoji
  );
  if (index < 0) return false;

  localReactionEchoes.value = localReactionEchoes.value.filter((_, rowIndex) => rowIndex !== index);
  return true;
}

function pushReaction(emoji) {
  const random = (min, max) => min + Math.random() * (max - min);
  const edgePaddingPx = 20;
  const reactionFontPx = 24;
  const reactionScaleMin = 0.85;
  const reactionScaleMax = 1.15;
  const reactionMaxWidthPx = Math.ceil(reactionFontPx * reactionScaleMax) + 8;
  const viewportHeight = typeof window !== 'undefined' ? window.innerHeight : 720;
  const mainVideoHeight = typeof document !== 'undefined'
    ? Number(document.querySelector('.workspace-main-video')?.clientHeight || 0)
    : 0;
  const reactionLayer = typeof document !== 'undefined'
    ? document.querySelector('.workspace-reaction-flight')
    : null;
  const reactionLayerWidth = Number(reactionLayer?.clientWidth || 0);
  const reactionLayerHeight = Number(reactionLayer?.clientHeight || 0);
  const layerWidth = Math.max(reactionLayerWidth, 320);
  const layerHeight = Math.max(reactionLayerHeight, 220);
  const travelBase = Math.max(viewportHeight * 0.75, mainVideoHeight * 0.75, 280);
  const baseBottom = Math.round(random(24, 40));
  const maxTravelByTopPadding = Math.max(80, layerHeight - edgePaddingPx - baseBottom);
  const maxWaveWanted = random(14, 30);
  const leftThirdMax = Math.max(edgePaddingPx, (layerWidth / 3) - edgePaddingPx - reactionMaxWidthPx);
  let startMin = edgePaddingPx + maxWaveWanted;
  let startMax = leftThirdMax - maxWaveWanted;
  if (startMax < startMin) {
    startMin = edgePaddingPx;
    startMax = Math.max(startMin, leftThirdMax);
  }

  const startXPx = random(startMin, startMax);
  const maxWaveByBounds = Math.max(0, Math.min(
    maxWaveWanted,
    startXPx - edgePaddingPx,
    leftThirdMax - startXPx
  ));

  reactionId += 1;
  const id = `rx_${reactionId}`;
  const entry = {
    id,
    emoji,
    startXPx: Math.round(startXPx),
    delay: Math.round(Math.random() * 140),
    duration: Math.round(random(2300, 2850)),
    travelY: Math.round(Math.min(random(travelBase * 0.94, travelBase * 1.06), maxTravelByTopPadding)),
    wave: Number(maxWaveByBounds.toFixed(3)),
    phase: Math.round(random(0, 360)),
    baseBottom,
    scale: random(reactionScaleMin, reactionScaleMax).toFixed(3),
  };
  activeReactions.value = [...activeReactions.value, entry];
  window.setTimeout(() => {
    activeReactions.value = activeReactions.value.filter((row) => row.id !== id);
  }, entry.duration + entry.delay + 120);
}

function clearReactionQueueTimer() {
  if (reactionQueueTimer === null) return;
  clearTimeout(reactionQueueTimer);
  reactionQueueTimer = null;
}

function scheduleReactionQueueFlush() {
  if (reactionQueueTimer !== null) return;
  reactionQueueTimer = setTimeout(() => {
    reactionQueueTimer = null;
    flushQueuedReactions();
  }, REACTION_CLIENT_FLUSH_INTERVAL_MS);
}

function resetReactionSendWindow(nowMs) {
  reactionWindowStartedMs = nowMs;
  reactionSentInWindow = 0;
}

function refreshReactionSendWindow(nowMs) {
  if (reactionWindowStartedMs <= 0) {
    resetReactionSendWindow(nowMs);
    return;
  }
  if ((nowMs - reactionWindowStartedMs) >= REACTION_CLIENT_WINDOW_MS) {
    resetReactionSendWindow(nowMs);
  }
}

function enqueueReactionEmoji(emoji) {
  const nextQueue = [...queuedReactionEmojis.value, emoji];
  if (nextQueue.length > REACTION_CLIENT_MAX_QUEUE) {
    nextQueue.splice(0, nextQueue.length - REACTION_CLIENT_MAX_QUEUE);
  }
  queuedReactionEmojis.value = nextQueue;
}

function sendQueuedReactionFrame(emoji) {
  const clientReactionId = `rx_${Date.now()}_${Math.random().toString(16).slice(2, 8)}`;
  return sendSocketFrame({
    type: 'reaction/send',
    emoji,
    client_reaction_id: clientReactionId,
  });
}

function sendQueuedReactionBatchFrame(emojis) {
  reactionBatchCounter += 1;
  const clientBatchId = `rxb_${Date.now()}_${reactionBatchCounter}`;
  return sendSocketFrame({
    type: 'reaction/send_batch',
    emojis,
    client_reaction_id: clientBatchId,
  });
}

function flushQueuedReactions() {
  clearReactionQueueTimer();
  if (!isSocketOnline.value) return;

  let safety = 0;
  while (queuedReactionEmojis.value.length > 0 && safety < 512) {
    safety += 1;
    const nowMs = Date.now();
    refreshReactionSendWindow(nowMs);

    if (reactionSentInWindow < REACTION_CLIENT_DIRECT_PER_WINDOW) {
      const emoji = String(queuedReactionEmojis.value[0] || '').trim();
      if (emoji === '') {
        queuedReactionEmojis.value = queuedReactionEmojis.value.slice(1);
        continue;
      }

      const sent = sendQueuedReactionFrame(emoji);
      if (!sent) {
        scheduleReactionQueueFlush();
        return;
      }

      queuedReactionEmojis.value = queuedReactionEmojis.value.slice(1);
      reactionSentInWindow += 1;
      continue;
    }

    const batchCount = Math.min(REACTION_CLIENT_BATCH_SIZE, queuedReactionEmojis.value.length);
    if (batchCount <= 0) break;
    const batch = queuedReactionEmojis.value.slice(0, batchCount);
    const sentBatch = sendQueuedReactionBatchFrame(batch);
    if (!sentBatch) {
      scheduleReactionQueueFlush();
      return;
    }

    queuedReactionEmojis.value = queuedReactionEmojis.value.slice(batchCount);
  }

  if (queuedReactionEmojis.value.length > 0) {
    scheduleReactionQueueFlush();
  }
}

function normalizeDirectoryUser(raw) {
  const userId = Number(raw?.id || 0);
  return {
    userId: Number.isInteger(userId) && userId > 0 ? userId : 0,
    displayName: String(raw?.display_name || '').trim() || `User ${userId || 'unknown'}`,
    role: normalizeRole(raw?.role),
    status: String(raw?.status || '').trim() || 'unknown',
    email: String(raw?.email || '').trim(),
    timeFormat: String(raw?.time_format || '24h').trim() || '24h',
    theme: String(raw?.theme || 'dark').trim() || 'dark',
    avatarPath: typeof raw?.avatar_path === 'string' && raw.avatar_path.trim() !== '' ? raw.avatar_path.trim() : null,
    createdAt: String(raw?.created_at || ''),
    updatedAt: String(raw?.updated_at || ''),
  };
}

function clearModerationSyncTimer() {
  if (moderationSyncTimer === null) return;
  clearTimeout(moderationSyncTimer);
  moderationSyncTimer = null;
}

function queueModerationSync(action, userId) {
  const normalizedAction = String(action || '').trim().toLowerCase();
  const normalizedUserId = Number(userId);
  if (!Number.isInteger(normalizedUserId) || normalizedUserId <= 0) return;
  if (normalizedAction !== 'mute' && normalizedAction !== 'pin') return;

  const nextState = normalizedAction === 'mute'
    ? (mutedUsers[normalizedUserId] === true)
    : (pinnedUsers[normalizedUserId] === true);

  const key = rowActionKey(normalizedAction, normalizedUserId);
  moderationSyncQueue[key] = {
    action: normalizedAction,
    userId: normalizedUserId,
    state: nextState,
    updatedAt: Date.now(),
  };

  if (moderationSyncTimer !== null) return;
  moderationSyncTimer = setTimeout(() => {
    moderationSyncTimer = null;
    flushQueuedModerationSync();
  }, MODERATION_SYNC_FLUSH_INTERVAL_MS);
}

function consumeQueuedModerationSyncEntries() {
  const queuedEntries = Object.values(moderationSyncQueue);
  for (const key of Object.keys(moderationSyncQueue)) {
    delete moderationSyncQueue[key];
  }
  return queuedEntries;
}

function buildModerationStatePayloadFromQueue() {
  const moderatedState = {};
  const queuedEntries = consumeQueuedModerationSyncEntries();
  for (const entry of queuedEntries) {
    const action = String(entry?.action || '').trim().toLowerCase();
    const userId = Number(entry?.userId || 0);
    if (!Number.isInteger(userId) || userId <= 0) continue;
    if (action !== 'mute' && action !== 'pin') continue;

    const key = rowActionKey(action, userId);
    moderatedState[key] = {
      updatedAt: Number(entry?.updatedAt || Date.now()),
      pending: false,
      muted: action === 'mute' ? Boolean(entry?.state) : undefined,
      pinned: action === 'pin' ? Boolean(entry?.state) : undefined,
    };
  }
  return moderatedState;
}

function buildFullModerationStatePayload() {
  const moderatedState = {};

  for (const [rawUserId, muted] of Object.entries(mutedUsers)) {
    const userId = Number(rawUserId);
    if (!Number.isInteger(userId) || userId <= 0) continue;
    if (muted !== true) continue;
    moderatedState[rowActionKey('mute', userId)] = {
      updatedAt: Date.now(),
      pending: false,
      muted: true,
    };
  }

  for (const [rawUserId, pinned] of Object.entries(pinnedUsers)) {
    const userId = Number(rawUserId);
    if (!Number.isInteger(userId) || userId <= 0) continue;
    if (pinned !== true) continue;
    moderatedState[rowActionKey('pin', userId)] = {
      updatedAt: Date.now(),
      pending: false,
      pinned: true,
    };
  }

  return moderatedState;
}

function markUserActionText(userId, action, text, pending = false) {
  setRowAction(moderationActionState, action, userId, text, pending);
}

function markLobbyActionText(userId, action, text, pending = false) {
  setRowAction(lobbyActionState, action, userId, text, pending);
}

function clearLobbyActionText(userId, action) {
  clearRowAction(lobbyActionState, action, userId);
}

function updatePeerControlState(userId, patch) {
  const normalizedUserId = Number(userId);
  if (!Number.isInteger(normalizedUserId) || normalizedUserId <= 0) return;
  if (normalizedUserId === currentUserId.value) return;

  if (!peerControlStateByUserId[normalizedUserId] || typeof peerControlStateByUserId[normalizedUserId] !== 'object') {
    peerControlStateByUserId[normalizedUserId] = {
      handRaised: false,
      cameraEnabled: true,
      micEnabled: true,
      screenEnabled: false,
    };
  }

  peerControlStateByUserId[normalizedUserId] = {
    ...peerControlStateByUserId[normalizedUserId],
    ...patch,
  };
}

function resetPeerControlState(userId) {
  const normalizedUserId = Number(userId);
  if (!Number.isInteger(normalizedUserId) || normalizedUserId <= 0 || normalizedUserId === currentUserId.value) return;
  peerControlStateByUserId[normalizedUserId] = {
    handRaised: false,
    cameraEnabled: true,
    micEnabled: true,
    screenEnabled: false,
  };
}

function applyReactionEvent(payload) {
  const roomId = normalizeRoomId(payload?.room_id || payload?.roomId || activeRoomId.value);
  if (roomId !== activeRoomId.value) return;
  const senderUserId = Number(payload?.sender?.user_id || 0);
  if (Number.isInteger(senderUserId) && senderUserId > 0) {
    markParticipantActivity(senderUserId, 'reaction');
  }

  const reaction = payload && typeof payload.reaction === 'object' ? payload.reaction : null;
  if (reaction && typeof reaction === 'object') {
    const emoji = String(reaction.emoji || '').trim();
    if (emoji !== '') {
      if (consumeLocalReactionEcho(emoji, senderUserId)) {
        return;
      }
      pushReaction(emoji);
    }
  }

  const reactions = Array.isArray(payload?.reactions) ? payload.reactions : [];
  for (const row of reactions) {
    const emoji = String(row?.emoji || '').trim();
    if (emoji === '') continue;
    if (consumeLocalReactionEcho(emoji, senderUserId)) continue;
    pushReaction(emoji);
  }
}

function applyRemoteControlState(payload, sender) {
  const senderUserId = Number(sender?.user_id || 0);
  if (!Number.isInteger(senderUserId) || senderUserId <= 0) return false;
  markParticipantActivity(senderUserId, 'control');

  const kind = String(payload?.kind || '').trim().toLowerCase();
  if (kind === 'workspace-control-state') {
    const state = payload && typeof payload.state === 'object' ? payload.state : {};
    updatePeerControlState(senderUserId, {
      handRaised: Boolean(state.handRaised),
      cameraEnabled: state.cameraEnabled !== false,
      micEnabled: state.micEnabled !== false,
      screenEnabled: Boolean(state.screenEnabled),
    });
    refreshUsersDirectoryPresentation();
    return true;
  }

  if (kind === 'workspace-moderation-state') {
    const moderatedUsers = payload && typeof payload.moderated_users === 'object' ? payload.moderated_users : {};
    for (const [key, value] of Object.entries(moderatedUsers)) {
      const match = /^([a-z]+):([0-9]+)$/.exec(key);
      if (!match) continue;
      const action = String(match[1] || '');
      const subjectUserId = Number(match[2] || 0);
      if (!Number.isInteger(subjectUserId) || subjectUserId <= 0) continue;

      if (action === 'pin') {
        const nextPinned = Boolean(value?.pinned);
        if (nextPinned) {
          pinnedUsers[subjectUserId] = true;
        } else {
          delete pinnedUsers[subjectUserId];
        }
        markUserActionText(subjectUserId, 'pin', pinnedUsers[subjectUserId] ? 'Pinned' : 'Unpinned', false);
      }
      if (action === 'mute') {
        const nextMuted = Boolean(value?.muted);
        if (nextMuted) {
          mutedUsers[subjectUserId] = true;
        } else {
          delete mutedUsers[subjectUserId];
        }
        markUserActionText(subjectUserId, 'mute', mutedUsers[subjectUserId] ? 'Muted' : 'Unmuted', false);
      }
    }
    refreshUsersDirectoryPresentation();
    return true;
  }

  return false;
}

function syncControlStateToPeers() {
  const peerIds = connectedParticipantUsers.value
    .map((row) => row.userId)
    .filter((userId) => Number.isInteger(userId) && userId > 0 && userId !== currentUserId.value);

  let sentCount = 0;
  for (const targetUserId of peerIds) {
    const sent = sendSocketFrame({
      type: 'call/ice',
      target_user_id: targetUserId,
      payload: {
        kind: 'workspace-control-state',
        actor_user_id: currentUserId.value,
        room_id: activeRoomId.value,
        state: {
          handRaised: controlState.handRaised,
          cameraEnabled: controlState.cameraEnabled,
          micEnabled: controlState.micEnabled,
          screenEnabled: controlState.screenEnabled,
        },
      },
    });
    if (sent) sentCount += 1;
  }

  return sentCount;
}

function syncModerationStateToPeers() {
  return syncModerationStateToPeersWithPayload(buildFullModerationStatePayload());
}

function syncModerationStateToPeersWithPayload(moderatedUsers) {
  const normalizedPayload = moderatedUsers && typeof moderatedUsers === 'object' ? moderatedUsers : {};
  const payloadKeys = Object.keys(normalizedPayload);
  if (payloadKeys.length === 0) return 0;

  const peerIds = connectedParticipantUsers.value
    .map((row) => row.userId)
    .filter((userId) => Number.isInteger(userId) && userId > 0 && userId !== currentUserId.value);
  if (peerIds.length <= 0) return 0;

  let sentCount = 0;
  for (const targetUserId of peerIds) {
    const sent = sendSocketFrame({
      type: 'call/ice',
      target_user_id: targetUserId,
      payload: {
        kind: 'workspace-moderation-state',
        actor_user_id: currentUserId.value,
        room_id: activeRoomId.value,
        moderated_users: normalizedPayload,
      },
    });
    if (sent) sentCount += 1;
  }

  return sentCount;
}

function flushQueuedModerationSync() {
  clearModerationSyncTimer();
  if (!isSocketOnline.value) {
    consumeQueuedModerationSyncEntries();
    return 0;
  }

  const moderatedUsers = buildModerationStatePayloadFromQueue();
  if (Object.keys(moderatedUsers).length === 0) return 0;
  return syncModerationStateToPeersWithPayload(moderatedUsers);
}

function emitReaction(emoji) {
  if (typeof emoji !== 'string') return;
  const normalizedEmoji = emoji.trim();
  if (normalizedEmoji === '') return;
  markParticipantActivity(currentUserId.value, 'reaction');
  pushReaction(normalizedEmoji);
  trackLocalReactionEcho(normalizedEmoji);
  enqueueReactionEmoji(normalizedEmoji);
  flushQueuedReactions();
}

function toggleUserMuted(userId) {
  const normalizedUserId = Number(userId);
  if (!Number.isInteger(normalizedUserId) || normalizedUserId <= 0 || normalizedUserId === currentUserId.value) return;
  const nextMuted = mutedUsers[normalizedUserId] !== true;
  if (nextMuted) {
    mutedUsers[normalizedUserId] = true;
  } else {
    delete mutedUsers[normalizedUserId];
  }
  markUserActionText(normalizedUserId, 'mute', nextMuted ? 'Muted' : 'Unmuted', false);
  refreshUsersDirectoryPresentation();
  queueModerationSync('mute', normalizedUserId);
}

function togglePinned(userId) {
  const normalizedUserId = Number(userId);
  if (!Number.isInteger(normalizedUserId) || normalizedUserId <= 0 || normalizedUserId === currentUserId.value) return;
  const nextPinned = pinnedUsers[normalizedUserId] !== true;
  if (nextPinned) {
    pinnedUsers[normalizedUserId] = true;
  } else {
    delete pinnedUsers[normalizedUserId];
  }
  markUserActionText(normalizedUserId, 'pin', nextPinned ? 'Pinned' : 'Unpinned', false);
  refreshUsersDirectoryPresentation();
  queueModerationSync('pin', normalizedUserId);
}

function toggleHandRaised() {
  controlState.handRaised = !controlState.handRaised;
  refreshUsersDirectoryPresentation();
  void syncControlStateToPeers();
}

function toggleCamera() {
  controlState.cameraEnabled = !controlState.cameraEnabled;
  for (const track of localTracksRef.value) {
    if (track.kind === 'video') {
      track.enabled = controlState.cameraEnabled;
    }
  }
  refreshUsersDirectoryPresentation();
  void syncControlStateToPeers();
}

function toggleMicrophone() {
  controlState.micEnabled = !controlState.micEnabled;
  for (const track of localTracksRef.value) {
    if (track.kind === 'audio') {
      track.enabled = controlState.micEnabled;
    }
  }
  refreshUsersDirectoryPresentation();
  void syncControlStateToPeers();
}

function toggleScreenShare() {
  controlState.screenEnabled = !controlState.screenEnabled;
  refreshUsersDirectoryPresentation();
  void syncControlStateToPeers();
}

async function refreshUsersDirectory() {
  if (usersSourceMode.value !== 'directory') return;
  if (usersDirectoryLoading.value) return;

  const directoryQuery = parseUsersDirectoryQuery(usersSearch.value);
  usersDirectoryLoading.value = true;
  try {
    const payload = await apiRequest('/api/admin/users', {
      query: {
        query: directoryQuery.query,
        status: directoryQuery.status,
        page: usersPage.value,
        page_size: USERS_PAGE_SIZE,
        order: directoryQuery.order,
      },
    });

    const rows = Array.isArray(payload?.users) ? payload.users : [];
    usersDirectoryRows.value = rows.map(normalizeDirectoryUser).map(userRowSnapshot);

    const paging = payload?.pagination || {};
    usersPage.value = Number.isInteger(paging.page) ? paging.page : usersPage.value;
    usersDirectoryPagination.page = usersPage.value;
    usersDirectoryPagination.pageSize = Number.isInteger(paging.page_size) ? paging.page_size : USERS_PAGE_SIZE;
    usersDirectoryPagination.total = Number.isInteger(paging.total) ? paging.total : rows.length;
    usersDirectoryPagination.pageCount = Number.isInteger(paging.page_count) && paging.page_count > 0 ? paging.page_count : 1;
    usersDirectoryPagination.hasPrev = Boolean(paging.has_prev);
    usersDirectoryPagination.hasNext = Boolean(paging.has_next);
    usersDirectoryPagination.returned = Number.isInteger(paging.returned) ? paging.returned : rows.length;
    usersDirectoryPagination.query = String(paging.query || directoryQuery.query || '').trim();
    usersDirectoryPagination.status = normalizeUsersDirectoryStatus(paging.status || directoryQuery.status);
    usersDirectoryPagination.order = normalizeUsersDirectoryOrder(paging.order || directoryQuery.order);
    usersDirectoryPagination.error = '';
  } catch (error) {
    usersDirectoryPagination.error = error instanceof Error ? error.message : 'Could not load user directory.';
    usersDirectoryRows.value = [];
  } finally {
    usersDirectoryLoading.value = false;
  }
}

function refreshUsersDirectoryPresentation() {
  if (usersSourceMode.value !== 'directory' || usersDirectoryRows.value.length === 0) return;
  usersDirectoryRows.value = usersDirectoryRows.value.map((row) => userRowSnapshot(row));
}

function scheduleUsersRefresh() {
  if (usersRefreshTimer.value !== null) {
    clearTimeout(usersRefreshTimer.value);
    usersRefreshTimer.value = null;
  }
  usersRefreshTimer.value = window.setTimeout(() => {
    usersRefreshTimer.value = null;
    void refreshUsersDirectory();
  }, 220);
}

function onUsersSearchInput() {
  usersPage.value = 1;
  if (usersSourceMode.value === 'directory') {
    scheduleUsersRefresh();
  }
}

function goToUsersPage(nextPage) {
  const normalizedPage = Number(nextPage);
  if (!Number.isInteger(normalizedPage) || normalizedPage < 1) return;
  if (normalizedPage === usersPage.value) return;
  usersPage.value = normalizedPage;
  if (usersSourceMode.value === 'directory') {
    void refreshUsersDirectory();
  }
}

function goToLobbyPage(nextPage) {
  const normalizedPage = Number(nextPage);
  if (!Number.isInteger(normalizedPage) || normalizedPage < 1) return;
  if (normalizedPage === lobbyPage.value) return;
  lobbyPage.value = normalizedPage;
}

function syncUsersListViewport() {
  updateListViewportMetrics(usersListRef.value, usersListViewport);
}

function syncLobbyListViewport() {
  updateListViewportMetrics(lobbyListRef.value, lobbyListViewport);
}

function resetUsersListScroll() {
  if (usersListRef.value instanceof HTMLElement) {
    usersListRef.value.scrollTop = 0;
  }
  usersListViewport.scrollTop = 0;
  syncUsersListViewport();
}

function resetLobbyListScroll() {
  if (lobbyListRef.value instanceof HTMLElement) {
    lobbyListRef.value.scrollTop = 0;
  }
  lobbyListViewport.scrollTop = 0;
  syncLobbyListViewport();
}

function onUsersListScroll(event) {
  updateListViewportMetrics(event?.target, usersListViewport);
}

function onLobbyListScroll(event) {
  updateListViewportMetrics(event?.target, lobbyListViewport);
}

function clearAloneIdleWatchTimer() {
  if (aloneIdleWatchTimer !== null) {
    clearInterval(aloneIdleWatchTimer);
    aloneIdleWatchTimer = null;
  }
}

function clearAloneIdleCountdownTimer() {
  if (aloneIdleCountdownTimer !== null) {
    clearInterval(aloneIdleCountdownTimer);
    aloneIdleCountdownTimer = null;
  }
}

function hideAloneIdlePrompt() {
  clearAloneIdleCountdownTimer();
  aloneIdlePrompt.visible = false;
  aloneIdlePrompt.deadlineMs = 0;
  aloneIdlePrompt.remainingMs = ALONE_IDLE_COUNTDOWN_MS;
}

function markAloneIdleActivity() {
  if (!isAloneInCall.value) return;
  if (aloneIdlePrompt.visible) return;
  aloneIdleLastActiveMs = Date.now();
}

function updateAloneIdleCountdown() {
  const deadlineMs = Number(aloneIdlePrompt.deadlineMs || 0);
  if (!aloneIdlePrompt.visible || deadlineMs <= 0) {
    hideAloneIdlePrompt();
    return;
  }

  const remainingMs = Math.max(0, deadlineMs - Date.now());
  aloneIdlePrompt.remainingMs = remainingMs;
  if (remainingMs > 0) return;

  hideAloneIdlePrompt();
  setNotice('Ending call due to inactivity while alone.');
  hangupCall();
}

function showAloneIdlePrompt() {
  if (!isAloneInCall.value || aloneIdlePrompt.visible) return;
  aloneIdlePrompt.visible = true;
  aloneIdlePrompt.deadlineMs = Date.now() + ALONE_IDLE_COUNTDOWN_MS;
  aloneIdlePrompt.remainingMs = ALONE_IDLE_COUNTDOWN_MS;
  clearAloneIdleCountdownTimer();
  aloneIdleCountdownTimer = setInterval(() => {
    updateAloneIdleCountdown();
  }, ALONE_IDLE_TICK_MS);
}

function evaluateAloneIdlePrompt() {
  if (!isAloneInCall.value) {
    hideAloneIdlePrompt();
    aloneIdleLastActiveMs = Date.now();
    return;
  }
  if (aloneIdlePrompt.visible) return;

  const idleMs = Math.max(0, Date.now() - aloneIdleLastActiveMs);
  if (idleMs >= ALONE_IDLE_PROMPT_AFTER_MS) {
    showAloneIdlePrompt();
  }
}

function ensureAloneIdleWatchTimer() {
  if (aloneIdleWatchTimer !== null) return;
  aloneIdleWatchTimer = setInterval(() => {
    evaluateAloneIdlePrompt();
  }, ALONE_IDLE_POLL_MS);
}

function confirmStillInCall() {
  aloneIdleLastActiveMs = Date.now();
  hideAloneIdlePrompt();
}

function handleAloneIdleActivityEvent() {
  markAloneIdleActivity();
}

function attachAloneIdleActivityListeners() {
  if (typeof window === 'undefined') return;
  for (const eventName of ALONE_IDLE_ACTIVITY_EVENTS) {
    window.addEventListener(eventName, handleAloneIdleActivityEvent, { passive: true });
  }
}

function detachAloneIdleActivityListeners() {
  if (typeof window === 'undefined') return;
  for (const eventName of ALONE_IDLE_ACTIVITY_EVENTS) {
    window.removeEventListener(eventName, handleAloneIdleActivityEvent);
  }
}

function clearLobbyToastTimer() {
  if (lobbyToastTimer !== null) {
    clearTimeout(lobbyToastTimer);
    lobbyToastTimer = null;
  }
}

function hideLobbyJoinToast() {
  clearLobbyToastTimer();
  lobbyNotificationState.toastVisible = false;
  lobbyNotificationState.toastMessage = '';
}

function buildLobbyJoinToastMessage(entries) {
  const list = Array.isArray(entries) ? entries : [];
  const labels = list
    .map((entry) => String(entry?.display_name || '').trim())
    .filter((value) => value !== '');
  if (labels.length <= 0) return 'A user requested to join.';
  if (labels.length === 1) return `${labels[0]} requested to join.`;
  if (labels.length === 2) return `${labels[0]} and ${labels[1]} requested to join.`;
  return `${labels[0]} and ${labels.length - 1} more requested to join.`;
}

function notifyLobbyJoinRequests(entries) {
  if (!canModerate.value) return;
  if (!rightSidebarCollapsed.value) return;
  const list = Array.isArray(entries) ? entries : [];
  if (list.length <= 0) return;
  lobbyNotificationState.toastMessage = buildLobbyJoinToastMessage(list);
  lobbyNotificationState.toastVisible = true;
  clearLobbyToastTimer();
  lobbyToastTimer = setTimeout(() => {
    lobbyToastTimer = null;
    lobbyNotificationState.toastVisible = false;
  }, 7500);
}

function openLobbyRequestsPanel() {
  showRightSidebar();
  setActiveTab('lobby');
  hideLobbyJoinToast();
}

function setActiveTab(tab) {
  const nextTab = ['users', 'lobby', 'chat'].includes(tab) ? tab : 'users';
  activeTab.value = nextTab;
  if (nextTab === 'users') {
    nextTick(() => syncUsersListViewport());
  } else if (nextTab === 'lobby') {
    hideLobbyJoinToast();
    nextTick(() => syncLobbyListViewport());
  }
  if (isSocketOnline.value && (nextTab === 'users' || nextTab === 'lobby')) {
    requestRoomSnapshot();
  }
  if (nextTab === 'users' && usersSourceMode.value === 'directory') {
    void refreshUsersDirectory();
  }
}

function hideRightSidebar() {
  rightSidebarCollapsed.value = true;
}

function showRightSidebar() {
  rightSidebarCollapsed.value = false;
  hideLobbyJoinToast();
}

function openLeftSidebarOverlay(event) {
  if (event && typeof event.stopPropagation === 'function') {
    event.stopPropagation();
  }
  const openFn = workspaceSidebarState && typeof workspaceSidebarState.showLeftSidebar === 'function'
    ? workspaceSidebarState.showLeftSidebar
    : null;
  if (!openFn) return;
  openFn();
}

function handleCompactViewportChange(event) {
  isCompactViewport.value = Boolean(event?.matches);
}

let reconnectTimer = null;
let pingTimer = null;
let typingStopTimer = null;
let typingSweepTimer = null;
let lobbyToastTimer = null;
let aloneIdleWatchTimer = null;
let aloneIdleCountdownTimer = null;
let aloneIdleLastActiveMs = Date.now();
let localTypingStarted = false;
let manualSocketClose = false;
let connectGeneration = 0;

function clearReconnectTimer() {
  if (reconnectTimer !== null) {
    clearTimeout(reconnectTimer);
    reconnectTimer = null;
  }
}

function clearPingTimer() {
  if (pingTimer !== null) {
    clearInterval(pingTimer);
    pingTimer = null;
  }
}

function clearTypingStopTimer() {
  if (typingStopTimer !== null) {
    clearTimeout(typingStopTimer);
    typingStopTimer = null;
  }
}

function sendSocketFrame(payload) {
  const socket = socketRef.value;
  if (!(socket instanceof WebSocket)) return false;
  if (socket.readyState !== WebSocket.OPEN) return false;

  try {
    socket.send(JSON.stringify(payload));
    return true;
  } catch {
    return false;
  }
}

function requestRoomSnapshot() {
  if (!sendSocketFrame({ type: 'room/snapshot/request' })) {
    setNotice('Could not request room snapshot while websocket is offline.', 'error');
  }
}

function sendRoomJoin(roomId) {
  const normalizedRoomId = normalizeRoomId(roomId);
  if (!sendSocketFrame({ type: 'room/join', room_id: normalizedRoomId })) {
    return false;
  }

  return true;
}

function requestLobbyJoin(roomId = '', options = {}) {
  const targetRoomId = normalizeRoomId(roomId || desiredRoomId.value || activeRoomId.value || 'lobby');
  const announce = options && typeof options === 'object' && options.announce === false ? false : true;
  if (!sendSocketFrame({ type: 'lobby/queue/join', room_id: targetRoomId })) {
    setNotice('Could not join lobby queue while websocket is offline.', 'error');
    return;
  }
  if (announce) {
    setNotice('The host has been notified. Waiting for approval.');
  }
}

function allowLobbyUser(userId) {
  const normalizedUserId = Number(userId);
  if (!canModerate.value || !Number.isInteger(normalizedUserId) || normalizedUserId <= 0) return;
  markLobbyActionText(normalizedUserId, 'allow', 'Allowing user…', true);
  if (!sendSocketFrame({ type: 'lobby/allow', target_user_id: normalizedUserId })) {
    clearLobbyActionText(normalizedUserId, 'allow');
    setNotice('Could not allow user while websocket is offline.', 'error');
    return;
  }
}

function removeLobbyUser(userId) {
  const normalizedUserId = Number(userId);
  if (!canModerate.value || !Number.isInteger(normalizedUserId) || normalizedUserId <= 0) return;
  markLobbyActionText(normalizedUserId, 'remove', 'Removing user…', true);
  if (!sendSocketFrame({ type: 'lobby/remove', target_user_id: normalizedUserId })) {
    clearLobbyActionText(normalizedUserId, 'remove');
    setNotice('Could not remove user while websocket is offline.', 'error');
    return;
  }
}

function allowAllLobbyUsers() {
  if (!canModerate.value) return;
  if (!sendSocketFrame({ type: 'lobby/allow_all' })) {
    setNotice('Could not allow all while websocket is offline.', 'error');
  }
}

function stopLocalTyping() {
  clearTypingStopTimer();
  if (!localTypingStarted) return;
  localTypingStarted = false;
  void sendSocketFrame({ type: 'typing/stop' });
}

function handleChatInput() {
  if (!isSocketOnline.value) return;
  if (chatDraft.value.trim() === '') {
    stopLocalTyping();
    return;
  }

  if (!localTypingStarted) {
    localTypingStarted = sendSocketFrame({ type: 'typing/start' });
  }

  clearTypingStopTimer();
  typingStopTimer = setTimeout(() => {
    stopLocalTyping();
  }, TYPING_LOCAL_STOP_MS);
}

function sendChatMessage() {
  const text = chatDraft.value.trim();
  if (text === '' || !isSocketOnline.value) return;
  markParticipantActivity(currentUserId.value, 'chat');

  const clientMessageId = `client_${Date.now()}_${Math.random().toString(16).slice(2, 8)}`;
  const sent = sendSocketFrame({
    type: 'chat/send',
    message: text,
    client_message_id: clientMessageId,
  });

  if (!sent) {
    setNotice('Could not send chat message while websocket is offline.', 'error');
    return;
  }

  chatDraft.value = '';
  stopLocalTyping();
}

function normalizeChatMessage(payload) {
  const roomId = normalizeRoomId(payload?.room_id || payload?.roomId || activeRoomId.value);
  const message = payload && typeof payload.message === 'object' ? payload.message : {};
  const sender = message && typeof message.sender === 'object' ? message.sender : {};

  const idRaw = String(message.id || '').trim();
  const id = idRaw !== '' ? idRaw : `chat_${Date.now()}_${Math.random().toString(16).slice(2, 8)}`;

  return {
    id,
    room_id: roomId,
    text: String(message.text || '').trim(),
    sender: {
      user_id: Number(sender.user_id || 0) || 0,
      display_name: String(sender.display_name || 'Unknown user').trim() || 'Unknown user',
      role: normalizeRole(sender.role),
    },
    server_time: String(message.server_time || payload?.time || new Date().toISOString()),
    client_message_id: message.client_message_id ?? null,
  };
}

function appendChatMessage(payload) {
  const message = normalizeChatMessage(payload);
  if (message.text === '') return;
  if (Number.isInteger(message.sender.user_id) && message.sender.user_id > 0) {
    markParticipantActivity(message.sender.user_id, 'chat');
  }

  ensureRoomBuckets(message.room_id);
  const bucket = chatByRoom[message.room_id];
  if (bucket.some((row) => row.id === message.id)) return;

  bucket.push(message);
  if (bucket.length > 240) {
    bucket.splice(0, bucket.length - 240);
  }
}

function applyTypingEvent(payload) {
  const roomId = normalizeRoomId(payload?.room_id || payload?.roomId || activeRoomId.value);
  ensureRoomBuckets(roomId);

  const participant = payload && typeof payload.participant === 'object' ? payload.participant : {};
  const userId = Number(participant.user_id || participant.userId || 0);
  if (!Number.isInteger(userId) || userId <= 0 || userId === currentUserId.value) {
    return;
  }

  const type = String(payload?.type || '').trim().toLowerCase();
  const roomMap = typingByRoom[roomId];
  if (type === 'typing/stop') {
    delete roomMap[userId];
    return;
  }
  markParticipantActivity(userId, 'typing');

  const expiresInMs = Number(payload?.expires_in_ms || 3000);
  roomMap[userId] = {
    userId,
    displayName: String(participant.display_name || `User ${userId}`).trim() || `User ${userId}`,
    expiresAtMs: Date.now() + (Number.isFinite(expiresInMs) && expiresInMs > 0 ? expiresInMs : 3000),
  };
}

function normalizeLobbyEntry(entry) {
  const userId = Number(entry?.user_id || 0);
  return {
    user_id: Number.isInteger(userId) && userId > 0 ? userId : 0,
    display_name: String(entry?.display_name || '').trim() || `User ${userId || 'unknown'}`,
    role: normalizeRole(entry?.role),
    requested_unix_ms: Number(entry?.requested_unix_ms || 0),
    admitted_unix_ms: Number(entry?.admitted_unix_ms || 0),
  };
}

function resetCallParticipantRoles() {
  for (const key of Object.keys(callParticipantRoles)) {
    delete callParticipantRoles[key];
  }
}

function applyViewerContext(viewerPayload) {
  const nextCallId = String(viewerPayload?.call_id || viewerPayload?.callId || '').trim();
  if (nextCallId !== activeCallId.value) {
    activeCallId.value = nextCallId;
    loadedCallId.value = '';
    resetCallParticipantRoles();
    if (nextCallId !== '') {
      void loadActiveCallDetails(true);
    }
  }

  viewerCallRole.value = normalizeCallRole(viewerPayload?.call_role || viewerPayload?.callRole || viewerCallRole.value);
}

function applyCallDetails(callPayload) {
  const call = callPayload && typeof callPayload === 'object' ? callPayload : {};
  const callId = String(call.id || '').trim();
  if (callId !== '') {
    activeCallId.value = callId;
    loadedCallId.value = callId;
  }

  resetCallParticipantRoles();
  const internal = Array.isArray(call?.participants?.internal) ? call.participants.internal : [];
  for (const participant of internal) {
    const userId = Number(participant?.user_id || 0);
    if (!Number.isInteger(userId) || userId <= 0) continue;
    callParticipantRoles[userId] = normalizeCallRole(participant?.call_role || participant?.callRole || 'participant');
  }

  if (callParticipantRoles[currentUserId.value]) {
    viewerCallRole.value = normalizeCallRole(callParticipantRoles[currentUserId.value]);
    return;
  }
  const ownerUserId = Number(call?.owner?.user_id || 0);
  if (Number.isInteger(ownerUserId) && ownerUserId > 0 && ownerUserId === currentUserId.value) {
    viewerCallRole.value = 'owner';
  }
}

async function loadActiveCallDetails(force = false) {
  const callId = String(activeCallId.value || '').trim();
  if (callId === '') {
    loadedCallId.value = '';
    resetCallParticipantRoles();
    viewerCallRole.value = 'participant';
    return;
  }
  if (!force && loadedCallId.value === callId) return;

  try {
    const payload = await apiRequest(`/api/calls/${encodeURIComponent(callId)}`);
    applyCallDetails(payload?.call || null);
  } catch {
    loadedCallId.value = '';
  }
}

async function updateCallRole(userId, targetRole, pendingText, doneText) {
  const normalizedUserId = Number(userId);
  const callId = String(activeCallId.value || '').trim();
  if (!Number.isInteger(normalizedUserId) || normalizedUserId <= 0 || callId === '') return;

  const normalizedRole = normalizeCallRole(targetRole);
  const actionName = normalizedRole === 'owner' ? 'owner' : 'role';
  markUserActionText(normalizedUserId, actionName, pendingText, true);

  try {
    const payload = await apiRequest(`/api/calls/${encodeURIComponent(callId)}/participants/${normalizedUserId}/role`, {
      method: 'PATCH',
      body: {
        role: normalizedRole,
      },
    });
    applyCallDetails(payload?.result?.call || null);
    markUserActionText(normalizedUserId, actionName, doneText, false);
    refreshUsersDirectoryPresentation();
    requestRoomSnapshot();
  } catch (error) {
    clearRowAction(moderationActionState, actionName, normalizedUserId);
    setNotice(error instanceof Error ? error.message : 'Could not update call role.', 'error');
  }
}

function toggleModeratorRole(row) {
  const userId = Number(row?.userId || 0);
  if (!Number.isInteger(userId) || userId <= 0 || userId === currentUserId.value) return;
  const currentRole = normalizeCallRole(row?.callRole || callParticipantRoles[userId] || 'participant');
  if (currentRole === 'owner') return;

  const targetRole = currentRole === 'moderator' ? 'participant' : 'moderator';
  const pendingText = targetRole === 'moderator' ? 'Promoting to moderator…' : 'Removing moderator role…';
  const doneText = targetRole === 'moderator' ? 'Moderator role granted' : 'Moderator role removed';
  void updateCallRole(userId, targetRole, pendingText, doneText);
}

function transferOwnerRole(row) {
  const userId = Number(row?.userId || 0);
  if (!Number.isInteger(userId) || userId <= 0) return;
  if (normalizeCallRole(row?.callRole || callParticipantRoles[userId] || 'participant') === 'owner') return;
  void updateCallRole(userId, 'owner', 'Transferring ownership…', 'Ownership transferred');
}

function applyLobbySnapshot(payload) {
  const roomId = normalizeRoomId(payload?.room_id || payload?.roomId || activeRoomId.value);
  const admittedRows = Array.isArray(payload?.admitted) ? payload.admitted.map(normalizeLobbyEntry) : [];
  const admittedCurrentUser = admittedRows.some((entry) => Number(entry?.user_id || 0) === currentUserId.value);

  if (roomId !== activeRoomId.value) {
    if (
      admittedCurrentUser
      && roomId === desiredRoomId.value
      && pendingAdmissionJoinRoomId.value !== roomId
    ) {
      pendingAdmissionJoinRoomId.value = roomId;
      if (!sendRoomJoin(roomId)) {
        pendingAdmissionJoinRoomId.value = '';
      }
    }
    return;
  }

  const previousQueuedUserIds = new Set(
    lobbyQueue.value
      .map((entry) => Number(entry?.user_id || 0))
      .filter((userId) => Number.isInteger(userId) && userId > 0)
  );
  const nextQueueRows = Array.isArray(payload?.queue) ? payload.queue.map(normalizeLobbyEntry) : [];
  lobbyQueue.value = nextQueueRows;
  lobbyAdmitted.value = admittedRows;

  const addedQueueRows = nextQueueRows.filter((entry) => {
    const userId = Number(entry?.user_id || 0);
    if (!Number.isInteger(userId) || userId <= 0) return false;
    if (userId === currentUserId.value) return false;
    return !previousQueuedUserIds.has(userId);
  });
  if (lobbyNotificationState.hasSnapshot) {
    notifyLobbyJoinRequests(addedQueueRows);
  }
  lobbyNotificationState.hasSnapshot = true;

  for (const key of Object.keys(lobbyActionState)) {
    if (key.startsWith('allow:') || key.startsWith('remove:')) {
      delete lobbyActionState[key];
    }
  }
  refreshUsersDirectoryPresentation();
}

function applyRoomSnapshot(payload) {
  hasRealtimeRoomSync.value = true;
  const roomId = normalizeRoomId(payload?.room_id || payload?.roomId || desiredRoomId.value);
  const previousRoomId = normalizeRoomId(serverRoomId.value || roomId);
  if (previousRoomId !== roomId) {
    lobbyNotificationState.hasSnapshot = false;
    hideLobbyJoinToast();
  }
  serverRoomId.value = roomId;
  if (pendingAdmissionJoinRoomId.value === roomId) {
    pendingAdmissionJoinRoomId.value = '';
  }
  ensureRoomBuckets(roomId);
  applyViewerContext(payload?.viewer || null);

  participantsRaw.value = Array.isArray(payload?.participants) ? payload.participants : [];

  const presentUserIds = new Set();
  for (const row of connectedParticipantUsers.value) {
    presentUserIds.add(row.userId);
  }
  pruneParticipantActivity(presentUserIds);
  for (const userId of Object.keys(peerControlStateByUserId)) {
    if (!presentUserIds.has(Number(userId))) {
      delete peerControlStateByUserId[userId];
    }
  }
  refreshUsersDirectoryPresentation();
  if (isSocketOnline.value) {
    void syncControlStateToPeers();
    void syncModerationStateToPeers();
  }
}

function handleSignalingEvent(payload) {
  const type = String(payload?.type || '').trim().toLowerCase();
  if (!['call/offer', 'call/answer', 'call/ice', 'call/hangup'].includes(type)) return;

  const sender = payload && typeof payload.sender === 'object' ? payload.sender : {};
  const senderUserId = Number(sender.user_id || 0);
  const payloadBody = payload && typeof payload.payload === 'object' ? payload.payload : null;
  const payloadKind = String(payloadBody?.kind || '').trim().toLowerCase();
  const hasSdpPayload = Boolean(payloadBody && typeof payloadBody.sdp === 'object');
  const hasCandidatePayload = Boolean(payloadBody && typeof payloadBody.candidate === 'object');
  const isNativeSignal = payloadKind.startsWith('webrtc_')
    || (type === 'call/offer' && hasSdpPayload)
    || (type === 'call/answer' && hasSdpPayload)
    || (type === 'call/ice' && hasCandidatePayload);

  if (type === 'call/hangup') {
    resetPeerControlState(senderUserId);
    closeNativePeerConnection(senderUserId);
    refreshUsersDirectoryPresentation();
    const senderName = String(sender.display_name || `User ${senderUserId || 'unknown'}`).trim();
    setNotice(`Received hangup from ${senderName}.`);
    return;
  }

  if (isNativeSignal && Number.isInteger(senderUserId) && senderUserId > 0) {
    if (!isNativeWebRtcRuntimePath() && !mediaRuntimeCapabilities.value.stageB) {
      return;
    }
    if (!isNativeWebRtcRuntimePath() && mediaRuntimeCapabilities.value.stageB) {
      void switchMediaRuntimePath('webrtc_native', 'inbound_native_signaling');
    }
    if (type === 'call/offer') {
      void handleNativeOfferSignal(senderUserId, payloadBody || {});
      return;
    }
    if (type === 'call/answer') {
      void handleNativeAnswerSignal(senderUserId, payloadBody || {});
      return;
    }
    if (type === 'call/ice') {
      void handleNativeIceSignal(senderUserId, payloadBody || {});
      return;
    }
  }

  if (applyRemoteControlState(payload?.payload, sender)) {
    return;
  }

  const senderName = String(sender.display_name || `User ${senderUserId || 'unknown'}`).trim();
  setNotice(`Received ${type.replace('call/', '')} from ${senderName}.`);
}

function handleSocketMessage(event) {
  let payload = null;
  try {
    payload = JSON.parse(String(event.data || ''));
  } catch {
    return;
  }

  if (!payload || typeof payload !== 'object') return;
  const type = String(payload.type || '').trim().toLowerCase();
  if (type === '') return;

  if (type === 'system/welcome') {
    hasRealtimeRoomSync.value = true;
    const welcomeRoom = normalizeRoomId(payload.active_room_id || desiredRoomId.value);
    serverRoomId.value = welcomeRoom;
    ensureRoomBuckets(welcomeRoom);
    applyViewerContext(payload?.call_context || null);
    const admission = payload && typeof payload.admission === 'object' ? payload.admission : null;
    const requiresAdmission = Boolean(admission?.requires_admission);
    const pendingRoomId = normalizeRoomId(admission?.pending_room_id || '');
    if (requiresAdmission && pendingRoomId !== '') {
      requestLobbyJoin(pendingRoomId);
    }
    requestRoomSnapshot();
    if (desiredRoomId.value !== welcomeRoom) {
      void sendRoomJoin(desiredRoomId.value);
    }
    return;
  }

  if (type === 'room/snapshot') {
    applyRoomSnapshot(payload);
    return;
  }

  if (type === 'room/joined' || type === 'room/left') {
    requestRoomSnapshot();
    return;
  }

  if (type === 'lobby/snapshot') {
    applyLobbySnapshot(payload);
    return;
  }

  if (type === 'reaction/event' || type === 'reaction/batch') {
    applyReactionEvent(payload);
    return;
  }

  if (type === 'chat/message') {
    appendChatMessage(payload);
    return;
  }

  if (type === 'typing/start' || type === 'typing/stop') {
    applyTypingEvent(payload);
    return;
  }

  if (type === 'call/ack') {
    const signalType = String(payload?.signal_type || '').replace('call/', '').trim() || 'signal';
    if (!shouldSuppressCallAckNotice(signalType)) {
      setNotice(`Sent ${signalType} to ${payload?.sent_count ?? 0} peer(s).`);
    }
    return;
  }

  if (type === 'chat/ack') {
    return;
  }

  if (type === 'system/error') {
    const message = String(payload?.message || 'Realtime command failed.').trim();
    const code = String(payload?.code || '').trim().toLowerCase();
    const closeReason = String(payload?.details?.close?.close_reason || payload?.details?.reason || '').trim().toLowerCase();
    const failedCommandType = String(payload?.details?.type || '').trim().toLowerCase();
    const failedTargetUserId = Number(payload?.details?.target_user_id || 0);
    if (code === 'lobby_command_failed' && Number.isInteger(failedTargetUserId) && failedTargetUserId > 0) {
      if (failedCommandType === 'lobby/allow') {
        clearLobbyActionText(failedTargetUserId, 'allow');
      }
      if (failedCommandType === 'lobby/remove') {
        clearLobbyActionText(failedTargetUserId, 'remove');
      }
    }
    if (code === 'websocket_session_invalidated' || closeReason === 'session_invalidated') {
      manualSocketClose = true;
      connectionReason.value = closeReason || 'session_invalidated';
      connectionState.value = 'expired';
      closeSocket();
    } else if (code === 'websocket_auth_failed' || code === 'websocket_forbidden' || closeReason === 'auth_backend_error' || closeReason === 'role_not_allowed') {
      manualSocketClose = true;
      connectionReason.value = closeReason || code || 'blocked';
      connectionState.value = 'blocked';
      closeSocket();
    }
    if (code === 'reaction_publish_failed') {
      return;
    }
    if (code === 'room_join_requires_admission' || code === 'room_join_not_allowed') {
      const pendingRoomId = normalizeRoomId(payload?.details?.pending_room_id || desiredRoomId.value);
      requestLobbyJoin(pendingRoomId);
      return;
    }
    if (shouldSuppressExpectedSignalingError(payload)) {
      return;
    }
    setNotice(message, 'error');
    return;
  }

  if (type === 'system/pong') {
    return;
  }

  handleSignalingEvent(payload);
}

function startPingLoop() {
  clearPingTimer();
  pingTimer = setInterval(() => {
    if (!isSocketOnline.value) return;
    void sendSocketFrame({ type: 'ping' });
  }, 12_000);
}

function closeSocket() {
  clearReconnectTimer();
  clearPingTimer();
  hasRealtimeRoomSync.value = false;
  hideLobbyJoinToast();
  const socket = socketRef.value;
  socketRef.value = null;
  if (!(socket instanceof WebSocket)) return;
  try {
    socket.close(1000, 'client_close');
  } catch {
    // ignore
  }
}

async function probeWorkspaceSession() {
  const token = String(sessionState.sessionToken || '').trim();
  if (token === '') {
    return {
      ok: false,
      state: 'expired',
      reason: 'missing_session',
      message: 'Session is missing.',
    };
  }

  try {
    const { response } = await fetchBackend('/api/auth/session', {
      method: 'GET',
      headers: requestHeaders(false),
    });

    let payload = null;
    try {
      payload = await response.json();
    } catch {
      payload = null;
    }

    if (response.ok && payload && payload.status === 'ok') {
      return {
        ok: true,
        state: 'online',
        reason: 'ready',
        message: '',
      };
    }

    const code = String(payload?.error?.code || '').trim().toLowerCase();
    const detailReason = String(payload?.error?.details?.reason || '').trim().toLowerCase();
    const failureReason = detailReason || code || 'invalid_session';
    if (response.status === 403 || failureReason === 'role_not_allowed') {
      return {
        ok: false,
        state: 'blocked',
        reason: failureReason,
        message: extractErrorMessage(payload, 'Session is blocked by policy.'),
      };
    }

    if (
      response.status === 401
      || response.status === 404
      || response.status === 410
      || ['missing_session', 'invalid_session', 'revoked_session', 'expired_session'].includes(failureReason)
    ) {
      return {
        ok: false,
        state: 'expired',
        reason: failureReason,
        message: extractErrorMessage(payload, 'Session is no longer valid.'),
      };
    }

    if (response.status >= 500) {
      return {
        ok: false,
        state: 'retrying',
        reason: failureReason,
        message: extractErrorMessage(payload, 'Session validation is temporarily unavailable.'),
      };
    }

    return {
      ok: false,
      state: 'blocked',
      reason: failureReason,
      message: extractErrorMessage(payload, 'Session is blocked.'),
    };
  } catch (error) {
    return {
      ok: false,
      state: 'retrying',
      reason: 'network_error',
      message: error instanceof Error ? error.message : 'Session validation failed.',
    };
  }
}

function scheduleReconnect() {
  clearReconnectTimer();
  if (manualSocketClose || connectionState.value === 'blocked' || connectionState.value === 'expired') {
    return;
  }
  reconnectAttempt.value += 1;
  connectionState.value = 'retrying';
  connectionReason.value = 'network_retry';

  const delay = RECONNECT_DELAYS_MS[Math.min(reconnectAttempt.value - 1, RECONNECT_DELAYS_MS.length - 1)];
  reconnectTimer = setTimeout(() => {
    void connectSocket();
  }, delay);
}

async function connectSocket() {
  const generation = ++connectGeneration;
  const token = String(sessionState.sessionToken || '').trim();
  if (token === '') {
    connectionReason.value = 'missing_session';
    connectionState.value = 'expired';
    return;
  }

  const previousSocket = socketRef.value;
  if (previousSocket) {
    try {
      previousSocket.close(1000, 'reconnect');
    } catch {
      // ignore
    }
    if (socketRef.value === previousSocket) {
      socketRef.value = null;
    }
  }

  clearReconnectTimer();
  clearPingTimer();
  manualSocketClose = false;
  hasRealtimeRoomSync.value = false;
  pendingAdmissionJoinRoomId.value = '';
  lobbyNotificationState.hasSnapshot = false;
  hideLobbyJoinToast();
  connectionState.value = 'retrying';
  connectionReason.value = reconnectAttempt.value > 0 ? 'network_retry' : 'probing_session';

  const sessionProbe = await probeWorkspaceSession();
  if (generation !== connectGeneration || manualSocketClose) {
    return;
  }
  if (!sessionProbe.ok) {
    connectionReason.value = sessionProbe.reason;
    connectionState.value = sessionProbe.state;
    if (sessionProbe.state === 'retrying') {
      workspaceNotice.value = '';
      workspaceError.value = '';
    } else {
      setNotice(sessionProbe.message, 'error');
    }
    if (sessionProbe.state === 'retrying' && !manualSocketClose) {
      scheduleReconnect();
    }
    return;
  }

  const discoveredOrigins = resolveBackendWebSocketOriginCandidates();
  const orderedSocketOrigins = discoveredOrigins.length > 0 ? discoveredOrigins : [currentBackendOrigin()];

  const connectWithOriginAt = (originIndex) => {
    if (generation !== connectGeneration || manualSocketClose) return;
    if (originIndex >= orderedSocketOrigins.length) {
      connectionState.value = 'retrying';
      connectionReason.value = 'socket_unreachable';
      scheduleReconnect();
      return;
    }

    const socketOrigin = orderedSocketOrigins[originIndex] || currentBackendOrigin();
    const socket = new WebSocket(socketUrlForRoom(desiredRoomId.value, socketOrigin));
    if (generation !== connectGeneration || manualSocketClose) {
      try {
        socket.close(1000, 'stale_connect');
      } catch {
        // ignore
      }
      return;
    }

    socketRef.value = socket;
    let opened = false;
    let failedOver = false;

    const failOverToNextOrigin = () => {
      if (failedOver) return;
      failedOver = true;
      if (socketRef.value === socket) {
        socketRef.value = null;
      }
      try {
        socket.close(1000, 'failover');
      } catch {
        // ignore
      }
      connectWithOriginAt(originIndex + 1);
    };

    socket.addEventListener('open', () => {
      if (generation !== connectGeneration || manualSocketClose) {
        try {
          socket.close(1000, 'stale_connect');
        } catch {
          // ignore
        }
        return;
      }

      opened = true;
      reconnectAttempt.value = 0;
      connectionState.value = 'online';
      connectionReason.value = 'ready';
      setBackendWebSocketOrigin(socketOrigin);
      clearErrors();
      startPingLoop();
      requestRoomSnapshot();
      if (usersSourceMode.value === 'directory' && activeTab.value === 'users') {
        void refreshUsersDirectory();
      }
      void syncControlStateToPeers();
      void syncModerationStateToPeers();
    });

    socket.addEventListener('message', handleSocketMessage);

    socket.addEventListener('error', () => {
      if (generation !== connectGeneration || manualSocketClose) return;
      if (!opened) {
        failOverToNextOrigin();
        return;
      }
      connectionState.value = 'retrying';
      connectionReason.value = 'socket_error';
    });

    socket.addEventListener('close', (event) => {
      if (generation !== connectGeneration) return;

      clearPingTimer();
      if (socketRef.value === socket) {
        socketRef.value = null;
      }
      hasRealtimeRoomSync.value = false;

      if (manualSocketClose) {
        return;
      }

      const closeReason = String(event?.reason || '').trim().toLowerCase();
      if (closeReason === 'session_invalidated') {
        connectionState.value = 'expired';
        connectionReason.value = closeReason;
        manualSocketClose = true;
        return;
      }
      if (closeReason === 'auth_backend_error' || (event?.code === 1008 && closeReason !== '')) {
        connectionState.value = 'blocked';
        connectionReason.value = closeReason || 'blocked';
        manualSocketClose = true;
        return;
      }

      if (!opened) {
        failOverToNextOrigin();
        return;
      }

      connectionState.value = 'retrying';
      connectionReason.value = closeReason || 'socket_closed';
      scheduleReconnect();
    });
  };

  connectWithOriginAt(0);
}

function hangupCall() {
  controlState.handRaised = false;
  controlState.cameraEnabled = true;
  controlState.micEnabled = true;
  controlState.screenEnabled = false;
  reactionTrayOpen.value = false;
  refreshUsersDirectoryPresentation();
  teardownLocalPublisher();
  teardownNativePeerConnections();
  teardownSfuRemotePeers();

  const peerIds = connectedParticipantUsers.value
    .map((participant) => participant.userId)
    .filter((userId) => Number.isInteger(userId) && userId > 0 && userId !== currentUserId.value);

  for (const targetUserId of peerIds) {
    sendSocketFrame({
      type: 'call/hangup',
      target_user_id: targetUserId,
      payload: {
        reason: 'local_hangup',
        room_id: activeRoomId.value,
        actor_user_id: currentUserId.value,
      },
    });
  }

  const callEntryMode = String(route.query.entry || '').trim().toLowerCase();
  if (callEntryMode === 'invite') {
    if (String(route.name || '') !== 'call-goodbye') {
      void router.push({ name: 'call-goodbye' });
    }
    return;
  }

  const overviewRouteName = normalizeRole(sessionState.role) === 'admin' ? 'admin-calls' : 'user-dashboard';
  if (String(route.name || '') !== overviewRouteName) {
    void router.push({ name: overviewRouteName });
  }
}

watch(desiredRoomId, (nextRoomId, previousRoomId) => {
  ensureRoomBuckets(nextRoomId);
  hasRealtimeRoomSync.value = false;
  usersPage.value = 1;
  lobbyPage.value = 1;
  if (nextRoomId === previousRoomId) return;
  teardownNativePeerConnections();
  teardownSfuRemotePeers();
  if (isSocketOnline.value) {
    if (!sendRoomJoin(nextRoomId)) {
      setNotice(`Could not join room ${nextRoomId} while websocket is offline.`, 'error');
    } else {
      requestRoomSnapshot();
      refreshUsersDirectoryPresentation();
    }
  }
});

watch(filteredUsers, () => {
  if (usersPage.value > usersPageCount.value) {
    usersPage.value = usersPageCount.value;
  }
  if (usersPage.value < 1) usersPage.value = 1;
});

watch(lobbyRows, () => {
  if (lobbyPage.value > lobbyPageCount.value) {
    lobbyPage.value = lobbyPageCount.value;
  }
  if (lobbyPage.value < 1) lobbyPage.value = 1;
});

watch(usersPage, () => {
  nextTick(() => resetUsersListScroll());
});

watch(lobbyPage, () => {
  nextTick(() => resetLobbyListScroll());
});

watch(
  () => usersPageRows.value.length,
  () => {
    nextTick(() => syncUsersListViewport());
  }
);

watch(
  () => lobbyPageRows.value.length,
  () => {
    nextTick(() => syncLobbyListViewport());
  }
);

watch(
  () => connectedParticipantUsers.value
    .map((row) => Number(row?.userId || 0))
    .filter((userId) => Number.isInteger(userId) && userId > 0)
    .sort((left, right) => left - right)
    .join(','),
  () => {
    if (!isNativeWebRtcRuntimePath()) return;
    syncNativePeerConnectionsWithRoster();
  }
);

watch(
  isAloneInCall,
  (alone) => {
    if (alone) {
      aloneIdleLastActiveMs = Date.now();
      hideAloneIdlePrompt();
      ensureAloneIdleWatchTimer();
      return;
    }

    hideAloneIdlePrompt();
    aloneIdleLastActiveMs = Date.now();
    clearAloneIdleWatchTimer();
  },
  { immediate: true }
);

watch(
  () => activeMessages.value.length,
  async () => {
    await nextTick();
    const node = chatListRef.value;
    if (node instanceof HTMLElement) {
      node.scrollTop = node.scrollHeight;
    }
  }
);

watch(
  () => sessionState.sessionToken,
  (token) => {
    if (String(token || '').trim() === '') {
      manualSocketClose = true;
      connectionState.value = 'expired';
      connectionReason.value = 'missing_session';
      closeSocket();
      return;
    }

    if (!isSocketOnline.value) {
      reconnectAttempt.value = 0;
      void connectSocket();
    }
  }
);

watch(
  () => callMediaPrefs.speakerVolume,
  () => {
    applyCallOutputPreferences();
  },
  { immediate: true }
);

watch(
  () => callMediaPrefs.selectedSpeakerId,
  () => {
    applyCallOutputPreferences();
  }
);

watch(
  () => callMediaPrefs.microphoneVolume,
  () => {
    applyCallInputPreferences();
  }
);

watch(
  () => [callMediaPrefs.selectedCameraId, callMediaPrefs.selectedMicrophoneId],
  ([nextCameraId, nextMicId], [prevCameraId, prevMicId]) => {
    if (nextCameraId === prevCameraId && nextMicId === prevMicId) return;
    void reconfigureLocalTracksFromSelectedDevices();
  }
);

watch(
  () => [
    callMediaPrefs.backgroundFilterMode,
    callMediaPrefs.backgroundBackdropMode,
    callMediaPrefs.backgroundQualityProfile,
    callMediaPrefs.backgroundBlurStrength,
    callMediaPrefs.backgroundMaskVariant,
    callMediaPrefs.backgroundBlurTransition,
    callMediaPrefs.backgroundApplyOutgoing,
    callMediaPrefs.backgroundMaxProcessWidth,
    callMediaPrefs.backgroundMaxProcessFps,
  ],
  (nextValue, previousValue = []) => {
    if (
      nextValue[0] === previousValue[0]
      && nextValue[1] === previousValue[1]
      && nextValue[2] === previousValue[2]
      && nextValue[3] === previousValue[3]
      && nextValue[4] === previousValue[4]
      && nextValue[5] === previousValue[5]
      && nextValue[6] === previousValue[6]
      && nextValue[7] === previousValue[7]
      && nextValue[8] === previousValue[8]
    ) {
      return;
    }
    void reconfigureLocalTracksFromSelectedDevices();
  }
);

watch(isCompactViewport, (nextValue) => {
  if (nextValue && isCompactLayoutViewport.value) {
    rightSidebarCollapsed.value = true;
  }
});

watch(isShellMobileViewport, (nextValue) => {
  if (nextValue && isCompactViewport.value) {
    rightSidebarCollapsed.value = true;
  }
});

watch(isShellTabletViewport, (nextValue) => {
  if (nextValue && isCompactViewport.value) {
    rightSidebarCollapsed.value = true;
  }
});

watch(rightSidebarCollapsed, (collapsed) => {
  if (!collapsed) {
    hideLobbyJoinToast();
  }
});

watch(canModerate, (enabled) => {
  if (!enabled) {
    hideLobbyJoinToast();
  }
});

watch(isSocketOnline, (online) => {
  if (!online) return;
  flushQueuedReactions();
});

watch(
  shouldConnectSfu,
  (enabled) => {
    if (!enabled) {
      if (sfuClientRef.value) {
        sfuClientRef.value.leave();
        sfuClientRef.value = null;
      }
      sfuConnected.value = false;
      localTracksPublishedToSfu = false;
      teardownSfuRemotePeers();
      return;
    }

    if (String(sessionState.sessionToken || '').trim() !== '' && Number.isInteger(sessionState.userId) && sessionState.userId > 0) {
      initSFU();
    }
  }
);

watch(routeCallRef, (nextValue, previousValue) => {
  if (nextValue === previousValue) return;
  void resolveRouteCallRef(nextValue);
});

onMounted(async () => {
  if (typeof window !== 'undefined' && typeof window.matchMedia === 'function') {
    compactMediaQuery = window.matchMedia(`(max-width: ${COMPACT_BREAKPOINT}px)`);
    isCompactViewport.value = compactMediaQuery.matches;
    if (typeof compactMediaQuery.addEventListener === 'function') {
      compactMediaQuery.addEventListener('change', handleCompactViewportChange);
    } else if (typeof compactMediaQuery.addListener === 'function') {
      compactMediaQuery.addListener(handleCompactViewportChange);
    }
  } else if (typeof window !== 'undefined') {
    isCompactViewport.value = window.innerWidth <= COMPACT_BREAKPOINT;
  }
  if (isCompactViewport.value && isCompactLayoutViewport.value) {
    rightSidebarCollapsed.value = true;
  }

  attachAloneIdleActivityListeners();
  aloneIdleLastActiveMs = Date.now();

  detachMediaDeviceWatcher = attachCallMediaDeviceWatcher({ requestPermissions: true });
  await resolveRouteCallRef(routeCallRef.value);
  ensureRoomBuckets(desiredRoomId.value);
  serverRoomId.value = desiredRoomId.value;
  await refreshCallMediaDevices({ requestPermissions: true });
  void connectSocket();

  try {
    mediaRuntimeCapabilities.value = await detectMediaRuntimeCapabilities();
    const shouldUseSfuRuntime = SFU_RUNTIME_ENABLED || !mediaRuntimeCapabilities.value.stageB;
    if (mediaRuntimeCapabilities.value.stageA && shouldUseSfuRuntime) {
      mediaDebugLog('[Codec] Runtime capability: WLVC WASM available');
      await switchMediaRuntimePath('wlvc_wasm', 'capability_probe_stage_a');
    } else if (mediaRuntimeCapabilities.value.stageB) {
      if (mediaRuntimeCapabilities.value.stageA && !SFU_RUNTIME_ENABLED) {
        mediaDebugLog('[Codec] Runtime capability: WLVC WASM available, but SFU runtime is disabled; using native WebRTC');
      } else {
        mediaDebugLog('[Codec] Runtime capability: WLVC WASM unavailable, native WebRTC available');
      }
      await switchMediaRuntimePath('webrtc_native', 'capability_probe_stage_b');
    } else {
      mediaDebugLog('[Codec] Runtime capability: neither WLVC WASM nor native WebRTC available');
      setMediaRuntimePath('unsupported', 'capability_probe_unsupported');
    }
  } catch (e) {
    mediaDebugLog('[Codec] Runtime capability probe failed:', e);
    mediaRuntimeCapabilities.value = {
      checkedAt: new Date().toISOString(),
      wlvcWasm: {
        webAssembly: typeof WebAssembly === 'object',
        encoder: false,
        decoder: false,
        reason: 'probe_error',
      },
      webRtcNative: false,
      stageA: false,
      stageB: false,
      preferredPath: 'unsupported',
      reasons: ['probe_error'],
    };
    setMediaRuntimePath('unsupported', 'capability_probe_error');
  }

  if (shouldConnectSfu.value && sessionState.sessionToken && sessionState.userId) {
    initSFU();
  }

  await nextTick();
  syncUsersListViewport();
  syncLobbyListViewport();

  typingSweepTimer = setInterval(() => {
    const nowMs = Date.now();
    for (const roomId of Object.keys(typingByRoom)) {
      const roomMap = typingByRoom[roomId];
      if (!roomMap || typeof roomMap !== 'object') continue;
      for (const [userId, entry] of Object.entries(roomMap)) {
        if (Number(entry?.expiresAtMs || 0) <= nowMs) {
          delete roomMap[userId];
        }
      }
    }
  }, TYPING_SWEEP_MS);
});

function scheduleLocalTrackPublish(attempt = 0) {
  if (!sfuClientRef.value || !sfuConnected.value) return;
  void publishLocalTracks();
  if (localStreamRef.value instanceof MediaStream && localTracksPublishedToSfu) return;
  if (attempt >= SFU_PUBLISH_MAX_RETRIES) return;
  setTimeout(() => {
    scheduleLocalTrackPublish(attempt + 1);
  }, SFU_PUBLISH_RETRY_DELAY_MS);
}

function initSFU() {
  if (sfuClientRef.value) return;

  const token = String(sessionState.sessionToken || '').trim();
  if (!token) return;

  sfuClientRef.value = new SFUClient({
    onTracks: (e) => handleSFUTracks(e),
    onUnpublished: (publisherId, trackId) => handleSFUUnpublished(publisherId, trackId),
    onPublisherLeft: (publisherId) => handleSFUPublisherLeft(publisherId),
    onConnected: () => {
      sfuConnected.value = true;
      sfuConnectRetryCount = 0;
      scheduleLocalTrackPublish();
    },
    onDisconnect: () => {
      const hadActiveConnection = sfuConnected.value;
      sfuConnected.value = false;
      localTracksPublishedToSfu = false;
      sfuClientRef.value = null;
      if (manualSocketClose) {
        sfuConnectRetryCount = 0;
        return;
      }
      if (!hadActiveConnection) {
        if (sfuConnectRetryCount < SFU_CONNECT_MAX_RETRIES) {
          sfuConnectRetryCount += 1;
          setTimeout(() => initSFU(), SFU_CONNECT_RETRY_DELAY_MS);
          return;
        }
        sfuConnectRetryCount = 0;
        void maybeFallbackToNativeRuntime('sfu_connect_failed');
        return;
      }
      sfuConnectRetryCount = 0;
      setTimeout(() => initSFU(), 2000);
    },
    onEncodedFrame: (frame) => handleSFUEncodedFrame(frame),
  });

  sfuClientRef.value.connect(
    { userId: String(sessionState.userId), token, name: sessionState.displayName || 'User' },
    activeRoomId.value
  );
  sfuConnected.value = false;
}

function teardownRemotePeer(peer) {
  if (!peer || typeof peer !== 'object') return;
  if (peer.pc) {
    try {
      peer.pc.close();
    } catch {
      // ignore
    }
  }
  if (peer.decoder) {
    try {
      peer.decoder.destroy();
    } catch {
      // ignore
    }
  }
  if (peer.video instanceof HTMLElement) {
    peer.video.remove();
  }
  if (peer.decodedCanvas instanceof HTMLElement) {
    peer.decodedCanvas.remove();
  }
}

function handleSFUTracks(e) {
  if (!isWlvcRuntimePath()) {
    return;
  }
  (async () => {
    let decoder = null;
    if (isWlvcRuntimePath() && mediaRuntimeCapabilities.value.stageA) {
      try {
        decoder = await createWasmDecoder({ width: 640, height: 480, quality: 75 });
      } catch (error) {
        mediaDebugLog('[SFU] WASM decoder init failed for publisher', e.publisherId, error);
      }
    }

    if (!decoder) {
      void maybeFallbackToNativeRuntime('wlvc_decoder_unavailable');
      return;
    }

    const canvas = document.createElement('canvas');
    canvas.width = 640;
    canvas.height = 480;
    canvas.className = 'remote-video';

    for (const [_, peer] of remotePeersRef.value) {
      teardownRemotePeer(peer);
    }
    remotePeersRef.value.clear();

    const container = document.getElementById('remote-video-container');
    if (container) {
      container.replaceChildren(canvas);
    }

    remotePeersRef.value.set(e.publisherId, {
      pc: null,
      video: null,
      tracks: e.tracks,
      stream: null,
      decoder: decoder,
      decodedCanvas: canvas,
    });

    mediaDebugLog('[SFU] Subscribed to publisher', e.publisherId, 'with', e.tracks.length, 'tracks');
  })();
}

function handleSFUUnpublished(publisherId, trackId) {
  const peer = remotePeersRef.value.get(publisherId);
  if (peer) {
    teardownRemotePeer(peer);
    remotePeersRef.value.delete(publisherId);
  }
}

function handleSFUPublisherLeft(publisherId) {
  const peer = remotePeersRef.value.get(publisherId);
  if (peer) {
    teardownRemotePeer(peer);
    remotePeersRef.value.delete(publisherId);
  }
}

let videoEncoderRef = ref(null);
let localVideoElement = ref(null);
let localRawStreamRef = ref(null);
let localFilteredStreamRef = ref(null);
let localStreamRef = ref(null);
let encodeIntervalRef = ref(null);
let localTracksPublishedToSfu = false;
let localPublisherTeardownInProgress = false;
let localTrackRecoveryTimer = null;
let localTrackRecoveryAttempts = 0;
const backgroundFilterController = new BackgroundFilterController();
const backgroundBaselineCollector = new BackgroundFilterBaselineCollector(10);
let backgroundBaselineCaptured = false;
let backgroundRuntimeToken = 0;

function clearRemoteVideoContainer() {
  if (typeof document === 'undefined') return;
  const container = document.getElementById('remote-video-container');
  if (container) {
    container.replaceChildren();
  }
}

function teardownSfuRemotePeers() {
  for (const [_, peer] of remotePeersRef.value) {
    teardownRemotePeer(peer);
  }
  remotePeersRef.value.clear();
  clearRemoteVideoContainer();
}

function createNativePeerVideoElement(userId) {
  const video = document.createElement('video');
  video.className = 'remote-video';
  video.autoplay = true;
  video.playsInline = true;
  video.dataset.userId = String(userId);
  return video;
}

function renderNativeRemoteVideos() {
  if (typeof document === 'undefined') return;
  if (!isNativeWebRtcRuntimePath()) return;
  const container = document.getElementById('remote-video-container');
  if (!container) return;

  const nodes = [];
  for (const peer of nativePeerConnectionsRef.value.values()) {
    if (!(peer?.video instanceof HTMLVideoElement)) continue;
    nodes.push(peer.video);
  }
  container.replaceChildren(...nodes.slice(0, 1));
  applyCallOutputPreferences();
}

function nativeWebRtcConfig() {
  return {
    iceServers: DEFAULT_NATIVE_ICE_SERVERS,
    iceCandidatePoolSize: 4,
  };
}

function localTracksByKind(stream) {
  const out = {};
  if (!(stream instanceof MediaStream)) return out;
  for (const track of stream.getTracks()) {
    if (track.kind === 'audio' || track.kind === 'video') {
      out[track.kind] = track;
    }
  }
  return out;
}

async function syncNativePeerLocalTracks(peer) {
  if (!peer?.pc || peer.pc.signalingState === 'closed') return;
  const stream = localStreamRef.value instanceof MediaStream ? localStreamRef.value : null;
  const byKind = localTracksByKind(stream);
  const senders = peer.pc.getSenders();

  for (const sender of senders) {
    const senderKind = String(sender?.track?.kind || '').toLowerCase();
    if (senderKind !== 'audio' && senderKind !== 'video') continue;
    const nextTrack = byKind[senderKind] || null;
    if (nextTrack && sender.track?.id === nextTrack.id) {
      delete byKind[senderKind];
      continue;
    }
    try {
      await sender.replaceTrack(nextTrack);
    } catch {
      // ignore replace failures for unstable peers
    }
    if (nextTrack) {
      delete byKind[senderKind];
    }
  }

  if (!(stream instanceof MediaStream)) return;
  for (const kind of ['audio', 'video']) {
    const track = byKind[kind] || null;
    if (!track) continue;
    try {
      peer.pc.addTrack(track, stream);
    } catch {
      // ignore duplicate addTrack attempts
    }
  }
}

async function flushNativePendingIce(peer) {
  if (!peer?.pc) return;
  if (!peer.pc.remoteDescription || !peer.pc.remoteDescription.type) return;
  const pending = Array.isArray(peer.pendingIce) ? [...peer.pendingIce] : [];
  peer.pendingIce = [];
  for (const candidate of pending) {
    try {
      await peer.pc.addIceCandidate(new RTCIceCandidate(candidate));
    } catch {
      // ignore stale candidates
    }
  }
}

function closeNativePeerConnection(targetUserId) {
  const normalizedTargetUserId = Number(targetUserId);
  if (!Number.isInteger(normalizedTargetUserId) || normalizedTargetUserId <= 0) return;
  const peer = nativePeerConnectionsRef.value.get(normalizedTargetUserId);
  if (!peer) return;

  nativePeerConnectionsRef.value.delete(normalizedTargetUserId);
  if (peer.pc) {
    try {
      peer.pc.close();
    } catch {
      // ignore close errors
    }
  }
  if (peer.video instanceof HTMLVideoElement) {
    peer.video.srcObject = null;
    peer.video.remove();
  }
  renderNativeRemoteVideos();
}

function teardownNativePeerConnections() {
  for (const [targetUserId] of nativePeerConnectionsRef.value) {
    closeNativePeerConnection(targetUserId);
  }
  nativePeerConnectionsRef.value.clear();
  if (isNativeWebRtcRuntimePath()) {
    clearRemoteVideoContainer();
  }
}

async function sendNativeOffer(peer) {
  if (!peer?.pc) return;
  if (!isNativeWebRtcRuntimePath()) return;
  if (peer.negotiating) {
    peer.needsRenegotiate = true;
    return;
  }
  if (peer.pc.signalingState === 'closed') return;

  peer.negotiating = true;
  try {
    await syncNativePeerLocalTracks(peer);
    const offer = await peer.pc.createOffer();
    await peer.pc.setLocalDescription(offer);
    const local = peer.pc.localDescription;
    if (!local || !local.sdp) return;

    sendSocketFrame({
      type: 'call/offer',
      target_user_id: peer.userId,
      payload: {
        kind: 'webrtc_offer',
        runtime_path: 'webrtc_native',
        room_id: activeRoomId.value,
        sdp: {
          type: local.type,
          sdp: local.sdp,
        },
      },
    });
  } catch (error) {
    mediaDebugLog('[WebRTC] Could not create/send offer for peer', peer.userId, error);
  } finally {
    peer.negotiating = false;
    if (peer.needsRenegotiate) {
      peer.needsRenegotiate = false;
      void sendNativeOffer(peer);
    }
  }
}

function ensureNativePeerConnection(targetUserId) {
  const normalizedTargetUserId = Number(targetUserId);
  if (!Number.isInteger(normalizedTargetUserId) || normalizedTargetUserId <= 0) return null;
  if (normalizedTargetUserId === currentUserId.value) return null;
  if (typeof RTCPeerConnection !== 'function') return null;

  const existing = nativePeerConnectionsRef.value.get(normalizedTargetUserId);
  if (existing) return existing;

  const pc = new RTCPeerConnection(nativeWebRtcConfig());
  const remoteStream = new MediaStream();
  const video = createNativePeerVideoElement(normalizedTargetUserId);
  video.srcObject = remoteStream;

  const peer = {
    userId: normalizedTargetUserId,
    initiator: currentUserId.value > 0 && currentUserId.value < normalizedTargetUserId,
    negotiating: false,
    needsRenegotiate: false,
    pendingIce: [],
    pc,
    video,
    remoteStream,
  };

  pc.addEventListener('icecandidate', (event) => {
    if (!isNativeWebRtcRuntimePath()) return;
    if (!event?.candidate) return;
    sendSocketFrame({
      type: 'call/ice',
      target_user_id: normalizedTargetUserId,
      payload: {
        kind: 'webrtc_ice',
        runtime_path: 'webrtc_native',
        room_id: activeRoomId.value,
        candidate: event.candidate.toJSON(),
      },
    });
  });

  pc.addEventListener('track', (event) => {
    markParticipantActivity(normalizedTargetUserId, 'media_track');
    const incoming = event?.streams?.[0];
    if (incoming instanceof MediaStream) {
      for (const track of incoming.getTracks()) {
        if (!remoteStream.getTracks().some((row) => row.id === track.id)) {
          remoteStream.addTrack(track);
        }
      }
    } else if (event?.track) {
      if (!remoteStream.getTracks().some((row) => row.id === event.track.id)) {
        remoteStream.addTrack(event.track);
      }
    }
    renderNativeRemoteVideos();
  });

  pc.addEventListener('connectionstatechange', () => {
    const state = String(pc.connectionState || '').toLowerCase();
    if (state === 'closed' || state === 'failed') {
      closeNativePeerConnection(normalizedTargetUserId);
    }
  });

  pc.addEventListener('negotiationneeded', () => {
    if (!peer.initiator) return;
    void sendNativeOffer(peer);
  });

  nativePeerConnectionsRef.value.set(normalizedTargetUserId, peer);
  void syncNativePeerLocalTracks(peer);
  renderNativeRemoteVideos();
  if (peer.initiator) {
    void sendNativeOffer(peer);
  }
  return peer;
}

function syncNativePeerConnectionsWithRoster() {
  if (!isNativeWebRtcRuntimePath()) return;

  const activePeerIds = new Set();
  for (const row of connectedParticipantUsers.value) {
    const userId = Number(row?.userId || 0);
    if (!Number.isInteger(userId) || userId <= 0 || userId === currentUserId.value) continue;
    activePeerIds.add(userId);
    ensureNativePeerConnection(userId);
  }

  for (const [userId] of nativePeerConnectionsRef.value) {
    if (!activePeerIds.has(userId)) {
      closeNativePeerConnection(userId);
    }
  }
}

async function handleNativeOfferSignal(senderUserId, payloadBody) {
  const peer = ensureNativePeerConnection(senderUserId);
  if (!peer?.pc) return;

  const sdpPayload = payloadBody && typeof payloadBody.sdp === 'object' ? payloadBody.sdp : null;
  const type = String(sdpPayload?.type || '').trim().toLowerCase();
  const sdp = String(sdpPayload?.sdp || '').trim();
  if (type !== 'offer' || sdp === '') return;

  try {
    await syncNativePeerLocalTracks(peer);
    await peer.pc.setRemoteDescription(new RTCSessionDescription({ type: 'offer', sdp }));
    await flushNativePendingIce(peer);
    const answer = await peer.pc.createAnswer();
    await peer.pc.setLocalDescription(answer);
    const local = peer.pc.localDescription;
    if (!local || !local.sdp) return;
    sendSocketFrame({
      type: 'call/answer',
      target_user_id: senderUserId,
      payload: {
        kind: 'webrtc_answer',
        runtime_path: 'webrtc_native',
        room_id: activeRoomId.value,
        sdp: {
          type: local.type,
          sdp: local.sdp,
        },
      },
    });
  } catch (error) {
    mediaDebugLog('[WebRTC] Failed to handle offer from peer', senderUserId, error);
  }
}

async function handleNativeAnswerSignal(senderUserId, payloadBody) {
  const peer = nativePeerConnectionsRef.value.get(senderUserId);
  if (!peer?.pc) return;
  const sdpPayload = payloadBody && typeof payloadBody.sdp === 'object' ? payloadBody.sdp : null;
  const type = String(sdpPayload?.type || '').trim().toLowerCase();
  const sdp = String(sdpPayload?.sdp || '').trim();
  if (type !== 'answer' || sdp === '') return;

  try {
    await peer.pc.setRemoteDescription(new RTCSessionDescription({ type: 'answer', sdp }));
    await flushNativePendingIce(peer);
  } catch (error) {
    mediaDebugLog('[WebRTC] Failed to handle answer from peer', senderUserId, error);
  }
}

async function handleNativeIceSignal(senderUserId, payloadBody) {
  const peer = ensureNativePeerConnection(senderUserId);
  if (!peer?.pc) return;
  const candidatePayload = payloadBody ? payloadBody.candidate : null;
  if (!candidatePayload || typeof candidatePayload !== 'object') return;

  if (peer.pc.remoteDescription && peer.pc.remoteDescription.type) {
    try {
      await peer.pc.addIceCandidate(new RTCIceCandidate(candidatePayload));
    } catch {
      // ignore stale candidate failures
    }
    return;
  }
  peer.pendingIce.push(candidatePayload);
}

async function switchMediaRuntimePath(nextPath, reason = 'unspecified') {
  const normalizedNextPath = String(nextPath || '').trim();
  if (!['wlvc_wasm', 'webrtc_native', 'unsupported'].includes(normalizedNextPath)) {
    return false;
  }
  if (runtimeSwitchInFlight) return false;
  if (normalizedNextPath === mediaRuntimePath.value) return true;

  if (normalizedNextPath === 'wlvc_wasm' && !mediaRuntimeCapabilities.value.stageA) {
    return false;
  }
  if (normalizedNextPath === 'webrtc_native' && !mediaRuntimeCapabilities.value.stageB) {
    return false;
  }

  runtimeSwitchInFlight = true;
  try {
    if (normalizedNextPath === 'webrtc_native') {
      stopLocalEncodingPipeline();
      teardownSfuRemotePeers();
      syncNativePeerConnectionsWithRoster();
      for (const peer of nativePeerConnectionsRef.value.values()) {
        void syncNativePeerLocalTracks(peer);
      }
      wlvcEncodeFailureCount = 0;
      wlvcEncodeWarmupUntilMs = 0;
      wlvcEncodeFirstFailureAtMs = 0;
      wlvcEncodeLastErrorLogAtMs = 0;
    } else if (normalizedNextPath === 'wlvc_wasm') {
      teardownNativePeerConnections();
      wlvcEncodeFailureCount = 0;
      wlvcEncodeWarmupUntilMs = 0;
      wlvcEncodeFirstFailureAtMs = 0;
      wlvcEncodeLastErrorLogAtMs = 0;
      const localStream = localStreamRef.value instanceof MediaStream ? localStreamRef.value : null;
      const videoTrack = localStream?.getVideoTracks?.()[0] || null;
      if (videoTrack) {
        await startEncodingPipeline(videoTrack);
      }
    } else {
      stopLocalEncodingPipeline();
      teardownNativePeerConnections();
      teardownSfuRemotePeers();
    }

    setMediaRuntimePath(normalizedNextPath, reason);
    mediaDebugLog('[MediaRuntime] switched to', normalizedNextPath, 'reason=', reason);
    return true;
  } finally {
    runtimeSwitchInFlight = false;
  }
}

async function maybeFallbackToNativeRuntime(reason) {
  if (!mediaRuntimeCapabilities.value.stageB) return false;
  return switchMediaRuntimePath('webrtc_native', reason);
}

function buildLocalMediaConstraints() {
  const cameraDeviceId = String(callMediaPrefs.selectedCameraId || '').trim();
  const microphoneDeviceId = String(callMediaPrefs.selectedMicrophoneId || '').trim();

  const video = cameraDeviceId !== ''
    ? { width: 640, height: 480, frameRate: 15, deviceId: { exact: cameraDeviceId } }
    : { width: 640, height: 480, frameRate: 15 };
  const audio = microphoneDeviceId !== ''
    ? { deviceId: { exact: microphoneDeviceId } }
    : true;

  return { video, audio };
}

function buildLooseLocalMediaConstraints() {
  return {
    video: { width: 640, height: 480, frameRate: 15 },
    audio: true,
  };
}

function shouldRetryWithLooseConstraints(error) {
  const name = String(error?.name || '').trim();
  return name === 'NotFoundError'
    || name === 'OverconstrainedError'
    || name === 'NotReadableError'
    || name === 'AbortError';
}

async function acquireLocalMediaStreamWithFallback() {
  const strictConstraints = buildLocalMediaConstraints();
  const looseConstraints = buildLooseLocalMediaConstraints();

  try {
    return await navigator.mediaDevices.getUserMedia(strictConstraints);
  } catch (error) {
    if (!shouldRetryWithLooseConstraints(error)) {
      throw error;
    }
  }

  try {
    return await navigator.mediaDevices.getUserMedia(looseConstraints);
  } catch {
    return navigator.mediaDevices.getUserMedia({ video: true, audio: true });
  }
}

function publishLocalTracksToSfuIfReady() {
  if (!sfuClientRef.value) return false;
  if (localTracksPublishedToSfu) return true;
  if (sfuClientRef.value.ws?.readyState !== WebSocket.OPEN) return false;
  const stream = localStreamRef.value instanceof MediaStream ? localStreamRef.value : null;
  if (!(stream instanceof MediaStream)) return false;

  const tracks = stream.getTracks().map((track) => ({
    id: track.id,
    kind: track.kind,
    label: track.label,
  }));

  if (tracks.length === 0) return false;
  sfuClientRef.value.publishTracks(tracks);
  localTracksPublishedToSfu = true;
  return true;
}

function applyCallOutputPreferences() {
  if (typeof document === 'undefined') return;
  const volume = Math.max(0, Math.min(100, Number(callMediaPrefs.speakerVolume || 100))) / 100;
  const speakerDeviceId = String(callMediaPrefs.selectedSpeakerId || '').trim();
  const mediaElements = document.querySelectorAll('.workspace-call-view video, .workspace-call-view audio');

  for (const node of mediaElements) {
    if (!(node instanceof HTMLMediaElement)) continue;
    if (node.closest('#local-video-container')) continue;
    if (!node.muted) {
      node.volume = volume;
    }
    if (speakerDeviceId !== '' && typeof node.setSinkId === 'function') {
      node.setSinkId(speakerDeviceId).catch(() => {});
    }
  }
}

function applyCallInputPreferences() {
  const stream = localRawStreamRef.value instanceof MediaStream
    ? localRawStreamRef.value
    : localStreamRef.value;
  if (!(stream instanceof MediaStream)) return;
  const volume = Math.max(0, Math.min(100, Number(callMediaPrefs.microphoneVolume || 100))) / 100;
  for (const track of stream.getAudioTracks()) {
    if (typeof track.applyConstraints !== 'function') continue;
    track.applyConstraints({ volume }).catch(() => {});
  }
}

function resetBackgroundRuntimeMetrics(reason = 'idle') {
  resetCallBackgroundRuntimeState();
  callMediaPrefs.backgroundFilterReason = reason;
}

function resolveBackgroundFilterOptions(runtimeToken) {
  const toFiniteNumber = (value, fallback) => {
    const numeric = Number(value);
    return Number.isFinite(numeric) ? numeric : fallback;
  };
  const mode = String(callMediaPrefs.backgroundFilterMode || 'off').trim().toLowerCase() === 'blur'
    ? 'blur'
    : 'off';
  const applyOutgoing = Boolean(callMediaPrefs.backgroundApplyOutgoing);
  if (!applyOutgoing || mode !== 'blur') {
    return {
      mode: 'off',
    };
  }

  const backdrop = String(callMediaPrefs.backgroundBackdropMode || 'blur7').trim().toLowerCase();
  const qualityProfile = String(callMediaPrefs.backgroundQualityProfile || 'balanced').trim().toLowerCase();
  const baseBlurLevel = Math.max(0, Math.min(4, Math.round(toFiniteNumber(callMediaPrefs.backgroundBlurStrength, 2))));
  const blurStepPx = [1, 2, 3, 4, 5];
  let blurPx = blurStepPx[baseBlurLevel] ?? 3;
  if (backdrop === 'blur7') {
    blurPx = Math.round(blurPx * 1.0);
  } else if (backdrop === 'blur9') {
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
  const maxProcessWidth = Math.max(320, Math.min(processWidthCap, requestedProcessWidth));
  const maxProcessFps = Math.max(8, Math.min(processFpsCap, requestedProcessFps));

  return {
    mode,
    blurPx,
    detectIntervalMs,
    temporalSmoothingAlpha,
    preferFastMatte: false,
    maskVariant,
    transitionGain,
    maxProcessWidth,
    maxProcessFps,
    autoDisableOnOverload: false,
    overloadFrameMs: 90,
    overloadConsecutiveFrames: 12,
    statsIntervalMs: 1000,
    onOverload: () => {
      if (runtimeToken !== backgroundRuntimeToken) return;
      resetBackgroundRuntimeMetrics('overload');
    },
    onStats: (stats) => {
      if (runtimeToken !== backgroundRuntimeToken) return;
      callMediaPrefs.backgroundFilterActive = true;
      callMediaPrefs.backgroundFilterFps = Number(stats?.fps || 0);
      callMediaPrefs.backgroundFilterDetectMs = Number(stats?.avgDetectMs || 0);
      callMediaPrefs.backgroundFilterDetectFps = Number(stats?.detectFps || 0);
      callMediaPrefs.backgroundFilterProcessMs = Number(stats?.avgProcessMs || 0);
      callMediaPrefs.backgroundFilterProcessLoad = Number(stats?.processLoad || 0);

      callMediaPrefs.backgroundBaselineSampleCount = backgroundBaselineCollector.sampleCount();
      const baseline = backgroundBaselineCollector.push(stats);
      callMediaPrefs.backgroundBaselineSampleCount = backgroundBaselineCollector.sampleCount();
      if (!baseline || backgroundBaselineCaptured) return;

      backgroundBaselineCaptured = true;
      callMediaPrefs.backgroundBaselineMedianFps = baseline.medianFps;
      callMediaPrefs.backgroundBaselineP95Fps = baseline.p95Fps;
      callMediaPrefs.backgroundBaselineMedianDetectMs = baseline.medianDetectMs;
      callMediaPrefs.backgroundBaselineP95DetectMs = baseline.p95DetectMs;
      callMediaPrefs.backgroundBaselineMedianDetectFps = baseline.medianDetectFps;
      callMediaPrefs.backgroundBaselineP95DetectFps = baseline.p95DetectFps;
      callMediaPrefs.backgroundBaselineMedianProcessMs = baseline.medianProcessMs;
      callMediaPrefs.backgroundBaselineP95ProcessMs = baseline.p95ProcessMs;
      callMediaPrefs.backgroundBaselineMedianProcessLoad = baseline.medianProcessLoad;
      callMediaPrefs.backgroundBaselineP95ProcessLoad = baseline.p95ProcessLoad;

      const gateResult = evaluateBackgroundFilterGates({
        medianFps: baseline.medianFps,
        medianDetectMs: baseline.medianDetectMs,
        medianProcessLoad: baseline.medianProcessLoad,
      });
      callMediaPrefs.backgroundBaselineGatePass = gateResult.pass;
      callMediaPrefs.backgroundBaselineGateFpsPass = gateResult.fpsPass;
      callMediaPrefs.backgroundBaselineGateDetectPass = gateResult.detectPass;
      callMediaPrefs.backgroundBaselineGateLoadPass = gateResult.loadPass;
    },
  };
}

async function applyLocalBackgroundFilter(rawStream) {
  const runtimeToken = ++backgroundRuntimeToken;
  backgroundBaselineCollector.reset();
  backgroundBaselineCaptured = false;

  const options = resolveBackgroundFilterOptions(runtimeToken);
  if (options.mode !== 'blur') {
    resetBackgroundRuntimeMetrics('off');
    return rawStream;
  }

  resetBackgroundRuntimeMetrics('starting');
  const result = await backgroundFilterController.apply(rawStream, options);
  if (runtimeToken !== backgroundRuntimeToken || result?.stale) return rawStream;

  if (result?.active) {
    callMediaPrefs.backgroundFilterActive = true;
    callMediaPrefs.backgroundFilterReason = result.reason === 'ok_fallback' ? 'ok_fallback' : 'ok';
    callMediaPrefs.backgroundFilterBackend = String(result.backend || 'none');
  } else {
    callMediaPrefs.backgroundFilterActive = false;
    callMediaPrefs.backgroundFilterReason = String(result?.reason || 'setup_failed');
    callMediaPrefs.backgroundFilterBackend = 'none';
  }

  if (result?.stream instanceof MediaStream) {
    return result.stream;
  }
  return rawStream;
}

function uniqueLocalStreams(values) {
  const out = [];
  const seen = new Set();
  for (const value of values) {
    if (!(value instanceof MediaStream)) continue;
    if (seen.has(value)) continue;
    seen.add(value);
    out.push(value);
  }
  return out;
}

function clearLocalTrackRecoveryTimer() {
  if (localTrackRecoveryTimer !== null) {
    clearTimeout(localTrackRecoveryTimer);
    localTrackRecoveryTimer = null;
  }
}

function hasLiveLocalTrack(kind) {
  const normalizedKind = String(kind || '').trim().toLowerCase();
  const stream = localStreamRef.value instanceof MediaStream ? localStreamRef.value : null;
  if (!(stream instanceof MediaStream)) return false;
  return stream.getTracks().some((track) => (
    String(track?.kind || '').trim().toLowerCase() === normalizedKind
    && String(track?.readyState || '').trim().toLowerCase() === 'live'
  ));
}

function hasLiveLocalMedia() {
  const stream = localStreamRef.value instanceof MediaStream ? localStreamRef.value : null;
  if (!(stream instanceof MediaStream)) return false;
  return stream.getTracks().some((track) => String(track?.readyState || '').trim().toLowerCase() === 'live');
}

async function canAcquireReplacementLocalMedia() {
  if (
    typeof navigator === 'undefined'
    || !navigator.mediaDevices
    || typeof navigator.mediaDevices.getUserMedia !== 'function'
  ) {
    return false;
  }

  let probeStream = null;
  try {
    probeStream = await acquireLocalMediaStreamWithFallback();
    return true;
  } catch {
    return false;
  } finally {
    if (probeStream instanceof MediaStream) {
      for (const track of probeStream.getTracks()) {
        try {
          track.stop();
        } catch {
          // ignore
        }
      }
    }
  }
}

function scheduleLocalTrackRecovery(reason = 'track_ended') {
  if (localPublisherTeardownInProgress) return;
  if (localTrackRecoveryTimer !== null) return;
  if (localTrackRecoveryAttempts >= LOCAL_TRACK_RECOVERY_MAX_ATTEMPTS) return;

  const attempt = localTrackRecoveryAttempts;
  const delayMs = Math.min(
    LOCAL_TRACK_RECOVERY_BASE_DELAY_MS * Math.max(1, 2 ** attempt),
    LOCAL_TRACK_RECOVERY_MAX_DELAY_MS,
  );

  localTrackRecoveryTimer = setTimeout(async () => {
    localTrackRecoveryTimer = null;
    localTrackRecoveryAttempts += 1;

    const recovered = await reconfigureLocalTracksFromSelectedDevices();
    if (recovered || hasLiveLocalMedia()) {
      localTrackRecoveryAttempts = 0;
      return;
    }

    scheduleLocalTrackRecovery(reason);
  }, delayMs);
}

function bindLocalTrackLifecycle(stream) {
  if (!(stream instanceof MediaStream)) return;
  for (const track of stream.getTracks()) {
    track.addEventListener('ended', () => {
      if (localPublisherTeardownInProgress) return;
      const currentStream = localStreamRef.value instanceof MediaStream ? localStreamRef.value : null;
      if (!(currentStream instanceof MediaStream)) return;
      const isCurrentTrack = currentStream.getTracks().some((row) => row.id === track.id);
      if (!isCurrentTrack) return;
      scheduleLocalTrackRecovery(`track_${String(track?.kind || 'media').toLowerCase()}_ended`);
    });
  }
}

function stopLocalEncodingPipeline() {
  if (encodeIntervalRef.value) {
    clearInterval(encodeIntervalRef.value);
    encodeIntervalRef.value = null;
  }
  if (videoEncoderRef.value) {
    videoEncoderRef.value.destroy();
    videoEncoderRef.value = null;
  }
  wlvcEncodeFailureCount = 0;
  wlvcEncodeWarmupUntilMs = 0;
  wlvcEncodeFirstFailureAtMs = 0;
  wlvcEncodeLastErrorLogAtMs = 0;
}

function clearLocalPreviewElement() {
  const node = localVideoElement.value;
  if (node instanceof HTMLVideoElement) {
    try {
      node.pause();
    } catch {
      // ignore
    }
    node.srcObject = null;
    node.remove();
  }
  localVideoElement.value = null;

  const container = document.getElementById('local-video-container');
  if (container) {
    container.innerHTML = '';
  }
}

function unpublishAndStopLocalTracks() {
  localPublisherTeardownInProgress = true;
  clearLocalTrackRecoveryTimer();
  backgroundRuntimeToken += 1;
  backgroundBaselineCollector.reset();
  backgroundBaselineCaptured = false;
  resetBackgroundRuntimeMetrics('idle');
  backgroundFilterController.dispose();

  try {
    const tracks = Array.isArray(localTracksRef.value) ? [...localTracksRef.value] : [];
    if (sfuClientRef.value) {
      for (const track of tracks) {
        if (track?.id) {
          sfuClientRef.value.unpublishTrack(track.id);
        }
      }
    }

    for (const track of tracks) {
      try {
        track.stop();
      } catch {
        // ignore
      }
    }
    localTracksRef.value = [];
    localTracksPublishedToSfu = false;

    const streamsToStop = uniqueLocalStreams([
      localStreamRef.value,
      localRawStreamRef.value,
      localFilteredStreamRef.value,
    ]);
    for (const stream of streamsToStop) {
      for (const track of stream.getTracks()) {
        try {
          track.stop();
        } catch {
          // ignore
        }
      }
    }

    localRawStreamRef.value = null;
    localFilteredStreamRef.value = null;
    localStreamRef.value = null;
  } finally {
    localPublisherTeardownInProgress = false;
  }
}

function teardownLocalPublisher() {
  clearLocalTrackRecoveryTimer();
  stopLocalEncodingPipeline();
  unpublishAndStopLocalTracks();
  clearLocalPreviewElement();
}

async function publishLocalTracks() {
  if (localStreamRef.value instanceof MediaStream) {
    publishLocalTracksToSfuIfReady();
    return true;
  }
  if (typeof navigator === 'undefined' || !navigator.mediaDevices || typeof navigator.mediaDevices.getUserMedia !== 'function') {
    return false;
  }

  try {
    const rawStream = await acquireLocalMediaStreamWithFallback();
    localRawStreamRef.value = rawStream;

    const stream = await applyLocalBackgroundFilter(rawStream);
    localFilteredStreamRef.value = stream;
    localStreamRef.value = stream;
    localTracksRef.value = stream.getTracks();
    localTracksPublishedToSfu = false;
    localTrackRecoveryAttempts = 0;
    bindLocalTrackLifecycle(stream);
    publishLocalTracksToSfuIfReady();
    applyCallInputPreferences();
    applyCallOutputPreferences();
    if (isNativeWebRtcRuntimePath()) {
      syncNativePeerConnectionsWithRoster();
      for (const peer of nativePeerConnectionsRef.value.values()) {
        void syncNativePeerLocalTracks(peer);
      }
    }

    const videoTrack = stream.getVideoTracks()[0];
    if (videoTrack) {
      await startEncodingPipeline(videoTrack);
    }
    return true;
  } catch (e) {
    mediaDebugLog('[SFU] Failed to get user media:', e);
    return false;
  }
}

async function startEncodingPipeline(videoTrack) {
  stopLocalEncodingPipeline();

  const video = document.createElement('video');
  video.srcObject = new MediaStream([videoTrack]);
  video.muted = true;
  video.playsInline = true;
  video.autoplay = true;
  localVideoElement.value = video;

  const container = document.getElementById('local-video-container');
  if (container) {
    container.replaceChildren(video);
  }
  try {
    await video.play();
  } catch {
    // keep preview node mounted even when autoplay policy blocks playback.
  }
  applyCallOutputPreferences();

  if (!isWlvcRuntimePath()) {
    return;
  }

  if (!mediaRuntimeCapabilities.value.stageA) {
    mediaDebugLog('[SFU] WLVC WASM unavailable; falling back to native WebRTC path');
    void maybeFallbackToNativeRuntime('wlvc_runtime_unavailable');
    return;
  }

  try {
    videoEncoderRef.value = await createWasmEncoder({
      width: 640, 
      height: 480, 
      quality: 75,
      keyFrameInterval: 30,
    });
    if (!videoEncoderRef.value) {
      mediaDebugLog('[SFU] WASM encoder unavailable; falling back to native WebRTC path');
      void maybeFallbackToNativeRuntime('wlvc_encoder_unavailable');
      return;
    }
    mediaDebugLog('[SFU] WASM encoder initialized');
    wlvcEncodeFailureCount = 0;
    wlvcEncodeWarmupUntilMs = Date.now() + WLVC_ENCODE_WARMUP_MS;
    wlvcEncodeFirstFailureAtMs = 0;
    wlvcEncodeLastErrorLogAtMs = 0;
  } catch (error) {
    mediaDebugLog('[SFU] WASM encoder init error; falling back to native WebRTC path:', error);
    void maybeFallbackToNativeRuntime('wlvc_encoder_init_error');
    return;
  }

  const canvas = document.createElement('canvas');
  canvas.width = 640;
  canvas.height = 480;
  const ctx = canvas.getContext('2d', { willReadFrequently: true });

  encodeIntervalRef.value = setInterval(async () => {
    if (!isWlvcRuntimePath()) return;
    if (!videoEncoderRef.value || !sfuClientRef.value || sfuClientRef.value.ws?.readyState !== WebSocket.OPEN) {
      return;
    }
    if (typeof document !== 'undefined' && document.hidden) return;

    if (video.readyState < 2) return;

    ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
    const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
    const timestamp = Date.now();

    try {
      const encoded = videoEncoderRef.value.encodeFrame(imageData, timestamp);
      
      sfuClientRef.value.sendEncodedFrame({
        publisherId: String(sessionState.userId),
        trackId: videoTrack.id,
        timestamp: encoded.timestamp,
        data: encoded.data,
        type: encoded.type,
      });
      wlvcEncodeFailureCount = 0;
      wlvcEncodeFirstFailureAtMs = 0;
    } catch (e) {
      const nowMs = Date.now();
      if (nowMs - wlvcEncodeLastErrorLogAtMs >= WLVC_ENCODE_ERROR_LOG_COOLDOWN_MS) {
        wlvcEncodeLastErrorLogAtMs = nowMs;
        mediaDebugLog('[SFU] WASM encode frame failed', e);
      }

      if (nowMs < wlvcEncodeWarmupUntilMs) {
        return;
      }

      if (
        wlvcEncodeFirstFailureAtMs === 0
        || nowMs - wlvcEncodeFirstFailureAtMs > WLVC_ENCODE_FAILURE_WINDOW_MS
      ) {
        wlvcEncodeFirstFailureAtMs = nowMs;
        wlvcEncodeFailureCount = 1;
        return;
      }

      wlvcEncodeFailureCount += 1;
      if (wlvcEncodeFailureCount >= WLVC_ENCODE_FAILURE_THRESHOLD) {
        wlvcEncodeFailureCount = 0;
        wlvcEncodeFirstFailureAtMs = 0;
        void maybeFallbackToNativeRuntime('wlvc_encode_runtime_error');
      }
    }
  }, 66);
}

async function reconfigureLocalTracksFromSelectedDevices() {
  if (!(localStreamRef.value instanceof MediaStream)) {
    return publishLocalTracks();
  }
  if (localTrackReconfigureInFlight) {
    localTrackReconfigureQueued = true;
    return false;
  }
  localTrackReconfigureInFlight = true;
  let reconfigured = false;
  try {
    if (hasLiveLocalMedia()) {
      const canAcquire = await canAcquireReplacementLocalMedia();
      if (!canAcquire) {
        scheduleLocalTrackRecovery('reconfigure_preflight_failed');
        return false;
      }
    }

    teardownLocalPublisher();
    reconfigured = await publishLocalTracks();
    if (reconfigured) {
      await refreshCallMediaDevices();
      localTrackRecoveryAttempts = 0;
    }
    return reconfigured;
  } finally {
    localTrackReconfigureInFlight = false;
    if (localTrackReconfigureQueued) {
      localTrackReconfigureQueued = false;
      void reconfigureLocalTracksFromSelectedDevices();
    }
  }
}

function handleSFUEncodedFrame(frame) {
  if (!isWlvcRuntimePath()) return;
  const publisherUserId = Number(frame?.publisherId || 0);
  if (Number.isInteger(publisherUserId) && publisherUserId > 0) {
    markParticipantActivity(publisherUserId, 'media_frame');
  }
  const peer = remotePeersRef.value.get(frame.publisherId);
  if (!peer || !peer.decoder) return;

  try {
    const decoded = peer.decoder.decodeFrame({
      data: frame.data,
      timestamp: frame.timestamp,
      width: 640,
      height: 480,
      type: frame.type,
    });

    if (decoded && decoded.data) {
      const canvas = peer.decodedCanvas;
      const ctx = canvas.getContext('2d');
      const imageData = new ImageData(decoded.data, decoded.width, decoded.height);
      ctx.putImageData(imageData, 0, 0);
    }
  } catch (e) {
    mediaDebugLog('[SFU] Decode error:', e);
  }
}

onBeforeUnmount(() => {
  clearReactionQueueTimer();
  clearModerationSyncTimer();
  consumeQueuedModerationSyncEntries();
  if (compactMediaQuery) {
    if (typeof compactMediaQuery.removeEventListener === 'function') {
      compactMediaQuery.removeEventListener('change', handleCompactViewportChange);
    } else if (typeof compactMediaQuery.removeListener === 'function') {
      compactMediaQuery.removeListener(handleCompactViewportChange);
    }
    compactMediaQuery = null;
  }

  if (detachMediaDeviceWatcher) {
    detachMediaDeviceWatcher();
    detachMediaDeviceWatcher = null;
  }
  detachAloneIdleActivityListeners();
  manualSocketClose = true;
  connectGeneration += 1;
  stopLocalTyping();
  clearTypingStopTimer();
  hideAloneIdlePrompt();
  clearAloneIdleWatchTimer();
  clearLobbyToastTimer();
  clearReconnectTimer();
  clearPingTimer();
  clearLocalTrackRecoveryTimer();
  if (usersRefreshTimer.value !== null) {
    clearTimeout(usersRefreshTimer.value);
    usersRefreshTimer.value = null;
  }
  if (typingSweepTimer !== null) {
    clearInterval(typingSweepTimer);
    typingSweepTimer = null;
  }
  closeSocket();
  teardownLocalPublisher();
  teardownNativePeerConnections();
  teardownSfuRemotePeers();
  if (sfuClientRef.value) {
    sfuClientRef.value.leave();
    sfuClientRef.value = null;
  }
  backgroundFilterController.dispose();
});
</script>

<style scoped>
.workspace-call-view {
  --bg-shell: #0B1324;
  --bg-sidebar: #0B1324;
  --bg-main: #0B1324;
  --bg-video: #133262;
  --bg-strip: #091a35;
  --bg-mini-video: #25569a;
  --bg-surface: #112b55;
  --bg-surface-strong: #153665;
  --bg-tab: #1c437a;
  --bg-tab-hover: #2a5aa0;
  --bg-tab-active: #3f79d6;
  --bg-control: #1b427a;
  --bg-control-active: #3f79d6;
  --bg-reaction-tray: #122d59;
  --bg-reaction-btn: #21508f;
  --bg-reaction-btn-hover: #2e65b3;
  --bg-input: #0c1f41;
  --bg-row-hover: #15305a;
  --bg-row-pinned: #2d63b3;
  --bg-preview: #2b63ac;
  --border-subtle: #24497f;
  --text-main: #edf3ff;
  --text-secondary: #c0d1ef;
  --text-muted: #94add3;
  --text-dim: #7f9cc8;
  position: relative;
  height: 100%;
  min-height: 0;
  display: flex;
  flex-direction: column;
  background: var(--bg-shell);
}

.workspace-call-banner {
  padding: 8px 12px;
  font-size: 12px;
  font-weight: 600;
}

.workspace-call-banner.ok {
  background: #14452b;
  color: #bdf6cf;
}

.workspace-call-banner.error {
  background: #4f1e2e;
  color: #ffd1dc;
}

.workspace-idle-toast {
  position: absolute;
  top: 12px;
  left: 50%;
  transform: translateX(-50%);
  z-index: 120;
  width: min(92vw, 520px);
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  align-items: center;
  gap: 10px;
  padding: 10px 12px;
  border-radius: 10px;
  background: rgba(8, 20, 43, 0.94);
  border: 1px solid rgba(255, 188, 90, 0.55);
  box-shadow: 0 10px 30px rgba(0, 0, 0, 0.35);
}

.workspace-idle-toast-text {
  font-size: 12px;
  color: #ffe9c2;
  line-height: 1.35;
}

.workspace-idle-toast-btn {
  border: 0;
  border-radius: 8px;
  height: 34px;
  padding: 0 12px;
  background: #ffba50;
  color: #1f1808;
  font-size: 12px;
  font-weight: 700;
  cursor: pointer;
}

.workspace-idle-toast-btn:hover {
  background: #ffc56a;
}

.workspace-call-body {
  flex: 1 1 auto;
  min-height: 0;
  display: grid;
  grid-template-columns: minmax(0, 1fr) 390px;
  gap: 1px;
  background: var(--bg-shell);
}

.workspace-call-body.right-collapsed {
  grid-template-columns: minmax(0, 1fr);
}

.workspace-stage {
  position: relative;
  height: 100%;
  min-height: 0;
  background: var(--bg-shell);
  padding: 0;
  display: grid;
  gap: 0;
  grid-template-rows: minmax(0, 1fr) 170px auto;
}

.workspace-stage.compact {
  grid-template-rows: 50px minmax(0, 1fr) 60px;
}

.workspace-compact-header {
  height: 50px;
  min-height: 50px;
  margin: 0 1px 1px;
  padding: 0 12px;
  border-bottom: 1px solid var(--border-subtle);
  background: #0B1324;
  display: grid;
  grid-template-columns: auto minmax(0, 1fr) auto;
  align-items: center;
  gap: 10px;
}

.workspace-compact-toggle {
  width: 32px;
  height: 32px;
  padding: 0;
  line-height: 0;
  border: 0;
  border-radius: 50%;
  background: #133262;
  color: #f7f7f7;
  display: grid;
  place-items: center;
  align-self: center;
  cursor: pointer;
}

.workspace-compact-toggle:hover {
  background: #3f79d6;
}

.workspace-compact-toggle-icon {
  width: 18px;
  height: 18px;
  display: block;
  object-fit: contain;
}

.workspace-compact-toggle-icon-back {
  transform: rotate(180deg);
}

.workspace-compact-logo {
  width: auto;
  max-width: 100%;
  height: 34px;
  object-fit: contain;
  justify-self: center;
}

.workspace-main-video {
  position: relative;
  width: 100%;
  height: 100%;
  min-height: 0;
  overflow: hidden;
  background: var(--bg-video);
  color: #ffffff;
  display: block;
  border-radius: 0;
  border: 0;
  margin: 0;
}

.video-container {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  overflow: hidden;
}

.video-container :deep(video),
.video-container :deep(canvas) {
  position: absolute;
  inset: 0;
  width: 100% !important;
  height: 100% !important;
  min-width: 100% !important;
  min-height: 100% !important;
  max-width: none !important;
  max-height: none !important;
  display: block !important;
  object-fit: cover !important;
}

.video-container.local {
  z-index: 10;
}

.video-container.local :deep(video) {
  transform: scaleX(-1);
}

.video-container.remote {
  z-index: 5;
}

.video-container.decoded {
  z-index: 1;
  opacity: 0.5;
}

.workspace-reaction-flight {
  position: absolute;
  top: 0;
  bottom: 0;
  left: 0;
  right: 0;
  z-index: 30;
  pointer-events: none;
  overflow: hidden;
}

.workspace-reaction-particle {
  position: absolute;
  left: var(--start-x);
  bottom: var(--base-bottom);
  font-size: 24px;
  line-height: 1;
  opacity: 0;
  will-change: transform, opacity;
  animation: reactionRiseSine var(--duration) linear forwards;
  animation-delay: var(--delay);
}

@keyframes reactionRiseSine {
  0% {
    transform: translate3d(calc(var(--wave) * sin(calc(var(--phase) + 0deg))), 0, 0) scale(var(--scale));
    opacity: 0;
  }

  10% {
    opacity: 1;
    transform: translate3d(
      calc(var(--wave) * sin(calc(var(--phase) + 54deg))),
      calc(var(--travel-y) * -0.1),
      0
    ) scale(var(--scale));
  }

  22% {
    transform: translate3d(
      calc(var(--wave) * sin(calc(var(--phase) + 108deg))),
      calc(var(--travel-y) * -0.22),
      0
    ) scale(var(--scale));
  }

  36% {
    transform: translate3d(
      calc(var(--wave) * sin(calc(var(--phase) + 162deg))),
      calc(var(--travel-y) * -0.36),
      0
    ) scale(var(--scale));
  }

  52% {
    transform: translate3d(
      calc(var(--wave) * sin(calc(var(--phase) + 216deg))),
      calc(var(--travel-y) * -0.52),
      0
    ) scale(var(--scale));
  }

  68% {
    transform: translate3d(
      calc(var(--wave) * sin(calc(var(--phase) + 270deg))),
      calc(var(--travel-y) * -0.68),
      0
    ) scale(var(--scale));
  }

  84% {
    transform: translate3d(
      calc(var(--wave) * sin(calc(var(--phase) + 324deg))),
      calc(var(--travel-y) * -0.84),
      0
    ) scale(var(--scale));
    opacity: 0.92;
  }

  100% {
    transform: translate3d(
      calc(var(--wave) * sin(calc(var(--phase) + 360deg))),
      calc(var(--travel-y) * -1),
      0
    ) scale(var(--scale));
    opacity: 0.04;
  }
}

.workspace-mini-strip {
  min-height: 0;
  background: var(--bg-strip);
  margin: 1px;
  display: grid;
  grid-template-columns: repeat(6, minmax(0, 1fr));
  gap: 1px;
}

.workspace-mini-tile,
.workspace-mini-empty {
  background: var(--bg-mini-video);
  border-radius: 0;
  padding: 8px;
  display: grid;
  align-content: center;
  justify-items: center;
  gap: 2px;
}

.workspace-mini-title {
  font-size: 12px;
  font-weight: 700;
  color: #f1f6ff;
}

.workspace-mini-meta {
  font-size: 11px;
  color: #c9d9f4;
}

.workspace-mini-empty {
  grid-column: 1 / -1;
  color: var(--text-secondary);
  font-size: 12px;
}

.workspace-show-right-btn {
  position: absolute;
  top: 16px;
  right: 16px;
  z-index: 60;
  visibility: visible;
  pointer-events: auto;
  transform: translateX(0);
}

.workspace-show-left-btn {
  position: absolute;
  top: 16px;
  left: 16px;
  z-index: 60;
  visibility: visible;
  pointer-events: auto;
  transform: translateX(0);
}

.workspace-lobby-toast {
  position: absolute;
  top: 16px;
  right: 58px;
  z-index: 65;
  display: inline-flex;
  align-items: center;
  gap: 8px;
  border: 0;
  border-radius: 999px;
  padding: 7px 12px;
  color: #ffffff;
  background: rgba(213, 40, 40, 0.92);
  box-shadow: 0 6px 18px rgba(0, 0, 0, 0.35);
  cursor: pointer;
}

.workspace-lobby-toast:hover {
  background: rgba(225, 58, 58, 0.96);
}

.workspace-lobby-toast-icon {
  width: 14px;
  height: 14px;
  object-fit: contain;
  filter: brightness(0) invert(1);
}

.workspace-lobby-toast-text {
  font-size: 12px;
  font-weight: 600;
  line-height: 1;
}

.workspace-controls {
  position: relative;
  z-index: 20;
  display: inline-flex;
  justify-content: center;
  align-items: center;
  flex-wrap: wrap;
  gap: 10px;
  margin-top: 0;
  min-height: 56px;
  padding: 0 14px 8px;
  background: var(--bg-shell);
}

.workspace-reactions-tray {
  position: absolute;
  bottom: calc(100% + 8px);
  left: 50%;
  transform: translate(-50%, 10px);
  background: var(--bg-reaction-tray);
  border-radius: 10px;
  padding: 6px;
  display: inline-flex;
  gap: 6px;
  opacity: 0;
  pointer-events: none;
  transition: transform 180ms ease, opacity 180ms ease;
}

.workspace-reactions-tray.open {
  opacity: 1;
  pointer-events: auto;
  transform: translate(-50%, 0);
}

.workspace-reaction-btn {
  width: 34px;
  height: 34px;
  border: 0;
  border-radius: 9px;
  background: var(--bg-reaction-btn);
  color: #ffffff;
  font-size: 18px;
  line-height: 1;
  cursor: pointer;
}

.workspace-reaction-btn:hover {
  background: var(--bg-reaction-btn-hover);
}

.call-control-btn {
  width: 44px;
  height: 44px;
  border: 0;
  border-radius: 12px;
  background: var(--bg-control);
  color: #ffffff;
  display: grid;
  place-items: center;
  cursor: pointer;
}

.call-control-btn.active {
  background: var(--bg-control-active);
}

.call-control-btn.hangup {
  width: 88px;
  background: #ff0000;
}

.ctrl-icon-image {
  width: 20px;
  height: 20px;
  object-fit: contain;
  filter: brightness(0) invert(1);
}

.workspace-context {
  min-height: 0;
  background: var(--bg-video);
  margin: 0 10px var(--call-workspace-sidebar-bottom, 64px) 10px;
  border-radius: 0 5px 5px 0;
  overflow: auto;
  display: grid;
  grid-template-rows: auto minmax(0, 1fr);
  transform: translateX(0);
  visibility: visible;
  transition: transform 240ms ease, visibility 0s linear 0s;
}

.workspace-context.collapsed {
  visibility: hidden;
  transform: translateX(24px);
  pointer-events: none;
  transition: transform 240ms ease, visibility 0s linear 240ms;
}

.workspace-call-body.right-collapsed .workspace-context {
  display: none;
}

.workspace-context .tabs.tabs-right {
  grid-template-columns: repeat(4, minmax(0, 1fr));
  background: var(--bg-video);
}

.tab-icon-wrap {
  position: relative;
  display: inline-grid;
  place-items: center;
}

.tab-notice-badge {
  position: absolute;
  top: -8px;
  right: -10px;
  min-width: 16px;
  height: 16px;
  border-radius: 999px;
  background: #e03232;
  color: #ffffff;
  display: inline-grid;
  place-items: center;
  padding: 0 4px;
  font-size: 10px;
  font-weight: 700;
  line-height: 1;
}

.tab-panel {
  display: none;
  min-height: 0;
  background: var(--bg-video);
}

.tab-panel.active {
  display: grid;
  height: 100%;
  min-height: 0;
}

.panel-users.active,
.panel-lobby.active {
  grid-template-rows: auto minmax(0, 1fr) auto;
  min-height: 0;
}

.panel-chat.active {
  grid-template-rows: minmax(0, 1fr) auto auto;
}

.toolbar,
.lobby-toolbar {
  padding: 10px;
  border-top: 0;
  border-bottom: 0;
  background: var(--bg-video);
}

.lobby-toolbar-actions {
  justify-content: flex-end;
}

.search {
  width: 100%;
  height: 38px;
  border: 0;
  border-radius: 6px;
  background: var(--bg-input);
  color: var(--text-main);
  padding: 0 10px;
}

.search::placeholder {
  color: var(--text-dim);
}

.workspace-tab-hint {
  margin: 0;
  padding: 8px 10px 0;
  font-size: 11px;
  color: #c2d4f2;
}

.workspace-tab-hint.error {
  color: #ffc6d4;
}

.user-list,
.lobby-list,
.workspace-chat-list {
  min-height: 0;
  overflow: auto;
}

.user-list,
.lobby-list {
  margin: 0;
  padding: 0;
  list-style: none;
}

.user-row {
  list-style: none;
  display: grid;
  grid-template-columns: 48px minmax(0, 1fr) auto;
  gap: 8px;
  align-items: center;
  padding: 10px;
  min-height: 72px;
  box-sizing: border-box;
  background: var(--bg-row-hover);
}

.user-list-spacer {
  list-style: none;
  margin: 0;
  padding: 0;
  border: 0;
  background: transparent;
}

.user-row.self {
  background: #1a3f74;
}

.user-row.pinned {
  background: var(--bg-row-pinned);
}

.user-row.pending {
  outline: 1px solid rgba(255, 255, 255, 0.15);
}

.user-preview {
  width: 40px;
  height: 40px;
  border-radius: 50%;
  background: var(--bg-preview);
  display: grid;
  place-items: center;
  font-size: 12px;
  font-weight: 700;
  color: #ffffff;
}

.user-main {
  min-width: 0;
  display: grid;
  gap: 2px;
}

.user-name {
  font-size: 13px;
  color: #edf3ff;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.user-role {
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  color: #9db2d8;
}

.user-feedback {
  font-size: 11px;
  color: #d5e3ff;
}

.user-list-empty {
  list-style: none;
  padding: 12px;
  font-size: 12px;
  color: var(--text-muted);
  background: var(--bg-video);
}

.workspace-tab-footer {
  background: var(--bg-video);
  padding: 8px;
}

.workspace-chat-list {
  padding: 10px;
  display: grid;
  align-content: start;
  gap: 8px;
  background: var(--bg-video);
}

.workspace-chat-message {
  border: 1px solid var(--border-subtle);
  border-radius: 6px;
  padding: 8px;
  background: var(--bg-row-hover);
}

.workspace-chat-message.mine {
  background: var(--bg-row-pinned);
}

.workspace-chat-message header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 8px;
  font-size: 11px;
  color: var(--text-muted);
}

.workspace-chat-message p {
  margin: 6px 0 0;
  font-size: 13px;
  color: #f1f6ff;
  white-space: pre-wrap;
  word-break: break-word;
}

.workspace-chat-empty {
  border: 1px dashed var(--border-subtle);
  border-radius: 6px;
  padding: 10px;
  color: var(--text-muted);
  font-size: 12px;
}

.workspace-typing {
  margin: 0;
  padding: 0 10px 8px;
  background: var(--bg-video);
  font-size: 12px;
  color: var(--text-muted);
}

.workspace-chat-compose {
  background: var(--bg-video);
  padding: 8px 10px 10px;
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: 8px;
  align-items: center;
  border-top: 1px solid var(--border-subtle);
}

@media (max-width: 1440px) {
  .workspace-call-body {
    grid-template-columns: minmax(0, 1fr) 360px;
  }

  .workspace-mini-strip {
    grid-template-columns: repeat(4, minmax(0, 1fr));
  }
}

@media (min-width: 1181px) {
  .workspace-call-body {
    gap: 0;
  }

  .workspace-context {
    margin-left: 10px;
    margin-right: 10px;
  }
}

@media (max-width: 1180px) {
  .workspace-call-view {
    overflow: hidden;
  }

  .workspace-call-body {
    position: relative;
    height: 100%;
    overflow: hidden;
    grid-template-columns: 1fr;
    grid-template-rows: minmax(0, 1fr);
  }

  .workspace-stage {
    grid-template-rows: minmax(0, 1fr) 120px 60px;
  }

  .workspace-stage.compact {
    grid-template-rows: 50px minmax(0, 1fr) 58px;
  }

  .workspace-mini-strip {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }

  .workspace-context {
    position: absolute;
    top: 0;
    right: 0;
    bottom: 0;
    width: min(360px, 92vw);
    margin: 0;
    border-radius: 0;
    z-index: 50;
    box-shadow: -6px 0 18px rgba(0, 0, 0, 0.28);
  }

  .workspace-context.collapsed {
    transform: translateX(100%);
  }

  .workspace-call-body.right-collapsed .workspace-context {
    display: grid;
  }

  .workspace-show-right-btn {
    top: 62px;
  }

  .workspace-lobby-toast {
    top: 62px;
    right: 66px;
    max-width: min(68vw, 290px);
  }

  .workspace-lobby-toast-text {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
}

@media (max-width: 760px) {
  .workspace-idle-toast {
    top: 8px;
    width: min(96vw, 520px);
    padding: 8px 10px;
  }

  .workspace-idle-toast {
    grid-template-columns: 1fr;
  }

  .workspace-idle-toast-btn {
    width: 100%;
  }

  .workspace-compact-header {
    padding: 0 10px;
  }

  .workspace-compact-logo {
    height: 32px;
  }

  .workspace-context {
    width: 100%;
  }
}

@media (max-width: 760px) and (orientation: landscape) {
  .workspace-stage.compact {
    grid-template-rows: 44px minmax(0, 1fr) 50px;
  }

  .workspace-compact-header {
    height: 44px;
    min-height: 44px;
  }

  .workspace-compact-logo {
    height: 28px;
  }

  .workspace-controls {
    gap: 8px;
    min-height: 50px;
    padding: 0 8px 6px;
  }

  .call-control-btn {
    width: 40px;
    height: 40px;
  }

  .call-control-btn.hangup {
    width: 76px;
  }
}
</style>
