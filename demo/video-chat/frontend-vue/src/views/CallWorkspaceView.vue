<template>
  <section class="view-card workspace-call-view">
    <header class="workspace-call-head">
      <div>
        <h3>Call Workspace</h3>
        <p>
          Active room <strong>{{ activeRoomId }}</strong>
          · Route room <strong>{{ desiredRoomId }}</strong>
          · {{ connectionLabel }}
        </p>
      </div>
      <div class="actions-inline workspace-call-head-actions">
        <button class="btn" type="button" :disabled="inviteState.creating || !isSocketOnline" @click="createRoomInvite">
          {{ inviteState.creating ? 'Creating invite…' : 'Create invite' }}
        </button>
        <button class="btn" type="button" :disabled="!inviteState.code" @click="copyInviteCode">Copy invite</button>
      </div>
    </header>

    <section v-if="workspaceError" class="workspace-call-banner error">
      {{ workspaceError }}
    </section>
    <section v-if="workspaceNotice" class="workspace-call-banner ok">
      {{ workspaceNotice }}
    </section>

    <section class="workspace-call-body">
      <section class="workspace-stage">
        <article class="workspace-main-video">
          <span class="workspace-main-video-room">{{ activeRoomId }}</span>
          <div class="workspace-main-video-status">
            <span>{{ participantUsers.length }} users</span>
            <span>{{ lobbyQueue.length }} waiting</span>
            <span>{{ lobbyAdmitted.length }} admitted</span>
          </div>

          <div class="workspace-reaction-flight">
            <span
              v-for="burst in activeReactions"
              :key="burst.id"
              class="workspace-reaction-particle"
              :style="{ '--rx': `${burst.x}%`, '--delay': `${burst.delay}ms` }"
            >
              {{ burst.emoji }}
            </span>
          </div>
        </article>

        <section class="workspace-mini-strip">
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
            @click="controlState.handRaised = !controlState.handRaised"
          >
            <img class="ctrl-icon-image" src="/assets/orgas/intelligent-intern/icons/hand.png" alt="" />
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
            @click="controlState.cameraEnabled = !controlState.cameraEnabled"
          >
            <img
              class="ctrl-icon-image"
              :src="controlState.cameraEnabled
                ? '/assets/orgas/intelligent-intern/icons/camon.png'
                : '/assets/orgas/intelligent-intern/icons/cameraoff.png'"
              alt=""
            />
          </button>

          <button
            class="call-control-btn"
            type="button"
            :class="{ active: controlState.micEnabled }"
            title="Toggle microphone"
            @click="controlState.micEnabled = !controlState.micEnabled"
          >
            <img
              class="ctrl-icon-image"
              :src="controlState.micEnabled
                ? '/assets/orgas/intelligent-intern/icons/micon.png'
                : '/assets/orgas/intelligent-intern/icons/micoff.png'"
              alt=""
            />
          </button>

          <button
            class="call-control-btn"
            type="button"
            :class="{ active: controlState.screenEnabled }"
            title="Share screen"
            @click="controlState.screenEnabled = !controlState.screenEnabled"
          >
            <img class="ctrl-icon-image" src="/assets/orgas/intelligent-intern/icons/share_screen.png" alt="" />
          </button>

          <button class="call-control-btn hangup" type="button" title="Hang up" @click="hangupCall">
            <img class="ctrl-icon-image" src="/assets/orgas/intelligent-intern/icons/end_call.png" alt="" />
          </button>
        </footer>
      </section>

      <aside class="workspace-context">
        <nav class="tabs tabs-right" role="tablist" aria-label="Call workspace context tabs">
          <button
            class="tab"
            :class="{ active: activeTab === 'users' }"
            type="button"
            role="tab"
            :aria-selected="activeTab === 'users'"
            @click="activeTab = 'users'"
          >
            <img class="tab-icon" src="/assets/orgas/intelligent-intern/icons/users.png" alt="" />
          </button>
          <button
            class="tab"
            :class="{ active: activeTab === 'lobby' }"
            type="button"
            role="tab"
            :aria-selected="activeTab === 'lobby'"
            @click="activeTab = 'lobby'"
          >
            <img class="tab-icon" src="/assets/orgas/intelligent-intern/icons/lobby.png" alt="" />
          </button>
          <button
            class="tab"
            :class="{ active: activeTab === 'chat' }"
            type="button"
            role="tab"
            :aria-selected="activeTab === 'chat'"
            @click="activeTab = 'chat'"
          >
            <img class="tab-icon" src="/assets/orgas/intelligent-intern/icons/chat.png" alt="" />
          </button>
          <button
            class="tab tab-toggle"
            type="button"
            title="Refresh room snapshot"
            :disabled="!isSocketOnline"
            @click="requestRoomSnapshot"
          >
            <img class="tab-icon" src="/assets/orgas/intelligent-intern/icons/forward.png" alt="" />
          </button>
        </nav>

        <section class="tab-panel panel-users" :class="{ active: activeTab === 'users' }">
          <div class="toolbar">
            <input
              v-model.trim="usersSearch"
              class="search"
              type="search"
              placeholder="Search users"
              @input="usersPage = 1"
            />
          </div>

          <ul class="user-list">
            <li
              v-for="row in usersPageRows"
              :key="row.userId"
              class="user-row"
              :class="{ self: row.userId === currentUserId, pinned: pinnedUsers[row.userId] === true }"
            >
              <div class="user-preview">{{ initials(row.displayName) }}</div>
              <div class="user-main">
                <strong class="user-name">{{ row.displayName }}</strong>
                <span class="user-role">{{ row.role }}</span>
              </div>
              <div class="actions-inline">
                <button
                  class="icon-mini-btn"
                  type="button"
                  :title="mutedUsers[row.userId] ? 'Unmute local' : 'Mute local'"
                  @click="toggleUserMuted(row.userId)"
                >
                  <img
                    :src="mutedUsers[row.userId]
                      ? '/assets/orgas/intelligent-intern/icons/micoff.png'
                      : '/assets/orgas/intelligent-intern/icons/micon.png'"
                    alt=""
                  />
                </button>
                <button
                  class="icon-mini-btn"
                  type="button"
                  :title="pinnedUsers[row.userId] ? 'Unpin user' : 'Pin user'"
                  @click="togglePinned(row.userId)"
                >
                  <img
                    :src="pinnedUsers[row.userId]
                      ? '/assets/orgas/intelligent-intern/icons/adminon.png'
                      : '/assets/orgas/intelligent-intern/icons/adminoff.png'"
                    alt=""
                  />
                </button>
                <button
                  class="icon-mini-btn danger"
                  type="button"
                  title="Remove from lobby"
                  :disabled="!canModerate || row.userId === currentUserId"
                  @click="removeLobbyUser(row.userId)"
                >
                  <img src="/assets/orgas/intelligent-intern/icons/remove_user.png" alt="" />
                </button>
              </div>
            </li>
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
                @click="usersPage = usersPage - 1"
              >
                <img class="pager-icon-img" src="/assets/orgas/intelligent-intern/icons/backward.png" alt="Previous users page" />
              </button>
              <div class="page-info">Page {{ usersPage }} / {{ usersPageCount }} · {{ filteredUsers.length }} users</div>
              <button
                class="pager-btn pager-icon-btn"
                type="button"
                :disabled="usersPage >= usersPageCount"
                @click="usersPage = usersPage + 1"
              >
                <img class="pager-icon-img" src="/assets/orgas/intelligent-intern/icons/forward.png" alt="Next users page" />
              </button>
            </div>
          </footer>
        </section>

        <section class="tab-panel panel-lobby" :class="{ active: activeTab === 'lobby' }">
          <div class="lobby-toolbar">
            <div class="actions-inline">
              <button class="btn" type="button" :disabled="!isSocketOnline" @click="requestLobbyJoin">
                Join queue
              </button>
              <button
                class="btn"
                type="button"
                :disabled="!isSocketOnline || !canModerate || lobbyQueue.length === 0"
                @click="allowAllLobbyUsers"
              >
                Allow all
              </button>
            </div>
          </div>

          <ul class="lobby-list">
            <li v-for="row in lobbyPageRows" :key="`${row.status}-${row.user_id}`" class="user-row">
              <div class="user-preview">{{ initials(row.display_name) }}</div>
              <div class="user-main">
                <strong class="user-name">{{ row.display_name }}</strong>
                <span class="user-role">{{ row.status }}</span>
              </div>
              <div class="actions-inline">
                <button
                  class="icon-mini-btn"
                  type="button"
                  title="Allow user"
                  :disabled="!canModerate || row.status !== 'queued'"
                  @click="allowLobbyUser(row.user_id)"
                >
                  <img src="/assets/orgas/intelligent-intern/icons/add_to_call.png" alt="" />
                </button>
                <button
                  class="icon-mini-btn danger"
                  type="button"
                  title="Remove user"
                  :disabled="!canModerate"
                  @click="removeLobbyUser(row.user_id)"
                >
                  <img src="/assets/orgas/intelligent-intern/icons/remove_user.png" alt="" />
                </button>
              </div>
            </li>
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
                @click="lobbyPage = lobbyPage - 1"
              >
                <img class="pager-icon-img" src="/assets/orgas/intelligent-intern/icons/backward.png" alt="Previous lobby page" />
              </button>
              <div class="page-info">Page {{ lobbyPage }} / {{ lobbyPageCount }} · {{ lobbyRows.length }} entries</div>
              <button
                class="pager-btn pager-icon-btn"
                type="button"
                :disabled="lobbyPage >= lobbyPageCount"
                @click="lobbyPage = lobbyPage + 1"
              >
                <img class="pager-icon-img" src="/assets/orgas/intelligent-intern/icons/forward.png" alt="Next lobby page" />
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
              <img src="/assets/orgas/intelligent-intern/icons/send.png" alt="Send" />
            </button>
          </form>
        </section>

        <section class="workspace-invite-join">
          <label class="workspace-invite-label" for="workspace-invite-code">Join with invite</label>
          <div class="workspace-invite-row">
            <input
              id="workspace-invite-code"
              v-model.trim="inviteJoinCode"
              class="search"
              type="text"
              maxlength="64"
              placeholder="Invite code"
            />
            <button class="btn" type="button" :disabled="inviteJoinBusy || inviteJoinCode === ''" @click="joinByInviteCode">
              {{ inviteJoinBusy ? 'Joining…' : 'Join' }}
            </button>
          </div>
          <p v-if="inviteState.code" class="workspace-invite-hint">
            Current invite: <code class="code">{{ inviteState.code }}</code>
          </p>
          <p v-if="inviteState.copyNotice" class="workspace-invite-hint">{{ inviteState.copyNotice }}</p>
        </section>
      </aside>
    </section>
  </section>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { sessionState } from '../stores/session';
import { resolveBackendOrigin } from '../lib/backendOrigin';

const route = useRoute();
const router = useRouter();

const USERS_PAGE_SIZE = 10;
const LOBBY_PAGE_SIZE = 10;
const TYPING_LOCAL_STOP_MS = 1200;
const TYPING_SWEEP_MS = 600;
const RECONNECT_DELAYS_MS = [750, 1250, 2000, 3200, 5000, 8000];

const reactionOptions = ['👍', '❤️', '🐘', '🥳', '😂', '😮', '😢', '🤔', '👏', '👎'];

const backendOrigin = resolveBackendOrigin();

function normalizeRoomId(value) {
  const candidate = String(value || '').trim().toLowerCase();
  if (candidate === '' || candidate.length > 120) return 'lobby';
  return /^[a-z0-9._-]+$/.test(candidate) ? candidate : 'lobby';
}

function normalizeRole(value) {
  const role = String(value || '').trim().toLowerCase();
  if (role === 'admin' || role === 'moderator') return role;
  return 'user';
}

function roleRank(role) {
  if (role === 'admin') return 0;
  if (role === 'moderator') return 1;
  return 2;
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

function requestHeaders(withBody = false) {
  const headers = { accept: 'application/json' };
  if (withBody) headers['content-type'] = 'application/json';

  const token = String(sessionState.sessionToken || '').trim();
  if (token !== '') {
    headers.authorization = `Bearer ${token}`;
    headers['x-session-id'] = token;
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
  const endpoint = `${backendOrigin}${path}${buildQueryString(query || {})}`;
  const response = await fetch(endpoint, {
    method,
    headers: requestHeaders(body !== null),
    body: body === null ? undefined : JSON.stringify(body),
  });

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

function socketUrlForRoom(roomId) {
  const httpUrl = new URL(backendOrigin);
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

const connectionState = ref('offline');
const reconnectAttempt = ref(0);
const socketRef = ref(null);
const serverRoomId = ref('lobby');

const participantsRaw = ref([]);
const lobbyQueue = ref([]);
const lobbyAdmitted = ref([]);

const chatByRoom = reactive({});
const typingByRoom = reactive({});

const mutedUsers = reactive({});
const pinnedUsers = reactive({});

const reactionTrayOpen = ref(false);
const activeReactions = ref([]);
let reactionId = 0;

const controlState = reactive({
  handRaised: false,
  cameraEnabled: true,
  micEnabled: true,
  screenEnabled: false,
});

const inviteState = reactive({
  creating: false,
  code: '',
  expiresAt: '',
  copyNotice: '',
});
const inviteJoinCode = ref('');
const inviteJoinBusy = ref(false);

const workspaceError = ref('');
const workspaceNotice = ref('');

const desiredRoomId = computed(() => normalizeRoomId(route.params.roomId));
const activeRoomId = computed(() => normalizeRoomId(serverRoomId.value || desiredRoomId.value));
const currentUserId = computed(() => (Number.isInteger(sessionState.userId) ? sessionState.userId : 0));
const canModerate = computed(() => ['admin', 'moderator'].includes(normalizeRole(sessionState.role)));
const isSocketOnline = computed(() => connectionState.value === 'online');

const connectionLabel = computed(() => {
  if (connectionState.value === 'online') return 'Signal online';
  if (connectionState.value === 'connecting') return 'Signal connecting';
  if (connectionState.value === 'reconnecting') return `Signal reconnecting (#${Math.max(1, reconnectAttempt.value)})`;
  return 'Signal offline';
});

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
  return {
    connectionId: String(raw?.connection_id || ''),
    roomId: normalizeRoomId(raw?.room_id || 'lobby'),
    userId: Number.isInteger(userId) && userId > 0 ? userId : 0,
    displayName: String(user.display_name || '').trim() || `User ${userId || 'unknown'}`,
    role: normalizeRole(user.role),
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
        connectedAt: normalized.connectedAt,
        connections: 1,
      });
      continue;
    }

    existing.connections += 1;
    if (roleRank(normalized.role) < roleRank(existing.role)) {
      existing.role = normalized.role;
    }
    if (normalized.displayName.length > existing.displayName.length) {
      existing.displayName = normalized.displayName;
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

const stripParticipants = computed(() => participantUsers.value.slice(0, 6));

const filteredUsers = computed(() => {
  const query = usersSearch.value.trim().toLowerCase();
  if (query === '') return participantUsers.value;

  return participantUsers.value.filter((row) => (
    row.displayName.toLowerCase().includes(query)
    || row.role.toLowerCase().includes(query)
    || String(row.userId).includes(query)
  ));
});

const usersPageCount = computed(() => Math.max(1, Math.ceil(filteredUsers.value.length / USERS_PAGE_SIZE)));
const usersPageRows = computed(() => {
  const offset = (usersPage.value - 1) * USERS_PAGE_SIZE;
  return filteredUsers.value.slice(offset, offset + USERS_PAGE_SIZE);
});

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
  return lobbyRows.value.slice(offset, offset + LOBBY_PAGE_SIZE);
});

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

function setNotice(message, kind = 'ok') {
  workspaceNotice.value = String(message || '').trim();
  if (kind === 'error') {
    workspaceError.value = workspaceNotice.value;
    workspaceNotice.value = '';
  } else {
    workspaceError.value = '';
  }
}

function clearErrors() {
  workspaceError.value = '';
}

function pushReaction(emoji) {
  reactionId += 1;
  const id = `rx_${reactionId}`;
  const entry = {
    id,
    emoji,
    x: 18 + Math.round(Math.random() * 64),
    delay: Math.round(Math.random() * 140),
  };
  activeReactions.value = [...activeReactions.value, entry];
  window.setTimeout(() => {
    activeReactions.value = activeReactions.value.filter((row) => row.id !== id);
  }, 1800);
}

function emitReaction(emoji) {
  if (typeof emoji !== 'string' || emoji.trim() === '') return;
  pushReaction(emoji);
}

function toggleUserMuted(userId) {
  const normalizedUserId = Number(userId);
  if (!Number.isInteger(normalizedUserId) || normalizedUserId <= 0) return;
  mutedUsers[normalizedUserId] = mutedUsers[normalizedUserId] !== true;
}

function togglePinned(userId) {
  const normalizedUserId = Number(userId);
  if (!Number.isInteger(normalizedUserId) || normalizedUserId <= 0) return;
  pinnedUsers[normalizedUserId] = pinnedUsers[normalizedUserId] !== true;
}

let reconnectTimer = null;
let pingTimer = null;
let typingStopTimer = null;
let typingSweepTimer = null;
let localTypingStarted = false;
let manualSocketClose = false;

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

function requestLobbyJoin() {
  if (!sendSocketFrame({ type: 'lobby/queue/join' })) {
    setNotice('Could not join lobby queue while websocket is offline.', 'error');
  }
}

function allowLobbyUser(userId) {
  const normalizedUserId = Number(userId);
  if (!canModerate.value || !Number.isInteger(normalizedUserId) || normalizedUserId <= 0) return;
  if (!sendSocketFrame({ type: 'lobby/allow', target_user_id: normalizedUserId })) {
    setNotice('Could not allow user while websocket is offline.', 'error');
  }
}

function removeLobbyUser(userId) {
  const normalizedUserId = Number(userId);
  if (!canModerate.value || !Number.isInteger(normalizedUserId) || normalizedUserId <= 0) return;
  if (!sendSocketFrame({ type: 'lobby/remove', target_user_id: normalizedUserId })) {
    setNotice('Could not remove user while websocket is offline.', 'error');
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

function applyLobbySnapshot(payload) {
  const roomId = normalizeRoomId(payload?.room_id || payload?.roomId || activeRoomId.value);
  if (roomId !== activeRoomId.value) return;

  lobbyQueue.value = Array.isArray(payload?.queue) ? payload.queue.map(normalizeLobbyEntry) : [];
  lobbyAdmitted.value = Array.isArray(payload?.admitted) ? payload.admitted.map(normalizeLobbyEntry) : [];
}

function applyRoomSnapshot(payload) {
  const roomId = normalizeRoomId(payload?.room_id || payload?.roomId || desiredRoomId.value);
  serverRoomId.value = roomId;
  ensureRoomBuckets(roomId);

  participantsRaw.value = Array.isArray(payload?.participants) ? payload.participants : [];
}

function handleSignalingEvent(payload) {
  const type = String(payload?.type || '').trim().toLowerCase();
  if (!['call/offer', 'call/answer', 'call/ice', 'call/hangup'].includes(type)) return;

  const sender = payload && typeof payload.sender === 'object' ? payload.sender : {};
  const senderName = String(sender.display_name || `User ${sender.user_id || 'unknown'}`).trim();
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
    const welcomeRoom = normalizeRoomId(payload.active_room_id || desiredRoomId.value);
    serverRoomId.value = welcomeRoom;
    ensureRoomBuckets(welcomeRoom);
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
    setNotice(`Sent ${signalType} to ${payload?.sent_count ?? 0} peer(s).`);
    return;
  }

  if (type === 'chat/ack') {
    return;
  }

  if (type === 'system/error') {
    const message = String(payload?.message || 'Realtime command failed.').trim();
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
  const socket = socketRef.value;
  socketRef.value = null;
  if (!(socket instanceof WebSocket)) return;
  try {
    socket.close(1000, 'client_close');
  } catch {
    // ignore
  }
}

function scheduleReconnect() {
  clearReconnectTimer();
  reconnectAttempt.value += 1;
  connectionState.value = 'reconnecting';

  const delay = RECONNECT_DELAYS_MS[Math.min(reconnectAttempt.value - 1, RECONNECT_DELAYS_MS.length - 1)];
  reconnectTimer = setTimeout(() => {
    connectSocket();
  }, delay);
}

function connectSocket() {
  const token = String(sessionState.sessionToken || '').trim();
  if (token === '') {
    connectionState.value = 'offline';
    return;
  }

  clearReconnectTimer();
  clearPingTimer();
  manualSocketClose = false;
  connectionState.value = reconnectAttempt.value > 0 ? 'reconnecting' : 'connecting';

  const socket = new WebSocket(socketUrlForRoom(desiredRoomId.value));
  socketRef.value = socket;

  socket.addEventListener('open', () => {
    reconnectAttempt.value = 0;
    connectionState.value = 'online';
    clearErrors();
    startPingLoop();
    requestRoomSnapshot();
  });

  socket.addEventListener('message', handleSocketMessage);
  socket.addEventListener('error', () => {
    if (!manualSocketClose) {
      connectionState.value = 'reconnecting';
    }
  });
  socket.addEventListener('close', () => {
    clearPingTimer();
    if (socketRef.value === socket) {
      socketRef.value = null;
    }

    if (manualSocketClose) {
      connectionState.value = 'offline';
      return;
    }

    scheduleReconnect();
  });
}

async function createRoomInvite() {
  clearErrors();
  inviteState.creating = true;
  inviteState.copyNotice = '';

  try {
    const payload = await apiRequest('/api/invite-codes', {
      method: 'POST',
      body: {
        scope: 'room',
        room_id: activeRoomId.value,
      },
    });

    const inviteCode = payload?.result?.invite_code || null;
    if (!inviteCode || typeof inviteCode.code !== 'string' || inviteCode.code.trim() === '') {
      throw new Error('Invite-code response is missing code.');
    }

    inviteState.code = inviteCode.code.trim();
    inviteState.expiresAt = typeof inviteCode.expires_at === 'string' ? inviteCode.expires_at : '';
    setNotice(`Invite code ready for room ${activeRoomId.value}.`);
  } catch (error) {
    setNotice(error instanceof Error ? error.message : 'Could not create invite code.', 'error');
  } finally {
    inviteState.creating = false;
  }
}

async function copyInviteCode() {
  const code = String(inviteState.code || '').trim();
  if (code === '') return;

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

    inviteState.copyNotice = 'Invite copied.';
  } catch {
    inviteState.copyNotice = 'Copy failed.';
  }
}

async function joinByInviteCode() {
  const code = inviteJoinCode.value.trim();
  if (code === '') return;

  clearErrors();
  inviteJoinBusy.value = true;

  try {
    const payload = await apiRequest('/api/invite-codes/redeem', {
      method: 'POST',
      body: { code },
    });

    const roomId = normalizeRoomId(payload?.result?.redemption?.join_context?.room?.id || 'lobby');
    inviteJoinCode.value = '';
    setNotice(`Joined invite context for room ${roomId}.`);

    if (route.path !== `/workspace/call/${roomId}`) {
      await router.push(`/workspace/call/${encodeURIComponent(roomId)}`);
    }

    if (isSocketOnline.value) {
      void sendRoomJoin(roomId);
      requestRoomSnapshot();
    }
  } catch (error) {
    setNotice(error instanceof Error ? error.message : 'Could not redeem invite code.', 'error');
  } finally {
    inviteJoinBusy.value = false;
  }
}

function hangupCall() {
  controlState.handRaised = false;
  controlState.screenEnabled = false;
  reactionTrayOpen.value = false;

  let sentCount = 0;
  for (const participant of participantUsers.value) {
    if (participant.userId === currentUserId.value) continue;
    const sent = sendSocketFrame({
      type: 'call/hangup',
      target_user_id: participant.userId,
      payload: {
        reason: 'local_hangup',
      },
    });
    if (sent) sentCount += 1;
  }

  if (sentCount > 0) {
    setNotice(`Hangup sent to ${sentCount} peer(s).`);
  } else {
    setNotice('Call controls reset.');
  }
}

watch(desiredRoomId, (nextRoomId, previousRoomId) => {
  ensureRoomBuckets(nextRoomId);
  usersPage.value = 1;
  lobbyPage.value = 1;
  if (nextRoomId === previousRoomId) return;
  if (isSocketOnline.value) {
    if (!sendRoomJoin(nextRoomId)) {
      setNotice(`Could not join room ${nextRoomId} while websocket is offline.`, 'error');
    } else {
      requestRoomSnapshot();
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
      closeSocket();
      return;
    }

    if (!isSocketOnline.value && connectionState.value !== 'connecting') {
      reconnectAttempt.value = 0;
      connectSocket();
    }
  }
);

onMounted(() => {
  ensureRoomBuckets(desiredRoomId.value);
  serverRoomId.value = desiredRoomId.value;
  connectSocket();

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

onBeforeUnmount(() => {
  manualSocketClose = true;
  stopLocalTyping();
  clearTypingStopTimer();
  clearReconnectTimer();
  clearPingTimer();
  if (typingSweepTimer !== null) {
    clearInterval(typingSweepTimer);
    typingSweepTimer = null;
  }
  closeSocket();
});
</script>

<style scoped>
.workspace-call-view {
  --bg-strip: #091a35;
  --bg-mini-video: #25569a;
  min-height: 0;
  display: grid;
  grid-template-rows: auto auto auto minmax(0, 1fr);
  gap: 1px;
  background: var(--border-subtle);
}

.workspace-call-head {
  background: var(--bg-surface);
  padding: 12px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  flex-wrap: wrap;
}

.workspace-call-head h3 {
  margin: 0;
  font-size: 18px;
}

.workspace-call-head p {
  margin: 4px 0 0;
  color: var(--text-muted);
  font-size: 12px;
}

.workspace-call-head p strong {
  color: var(--text-main);
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

.workspace-call-body {
  min-height: 0;
  display: grid;
  grid-template-columns: minmax(0, 1fr) 360px;
  gap: 1px;
  background: var(--border-subtle);
}

.workspace-stage {
  min-height: 0;
  background: var(--bg-main);
  padding: 10px;
  display: grid;
  grid-template-rows: minmax(0, 1fr) 92px auto;
  gap: 1px;
}

.workspace-main-video {
  position: relative;
  overflow: hidden;
  background: #133262;
  color: #ffffff;
  display: grid;
  place-items: center;
  border-radius: 4px;
  border: 1px solid var(--border-subtle);
}

.workspace-main-video-room {
  font-size: 18px;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}

.workspace-main-video-status {
  position: absolute;
  top: 10px;
  right: 10px;
  display: inline-flex;
  gap: 8px;
  align-items: center;
  font-size: 11px;
  color: #d4e3ff;
}

.workspace-reaction-flight {
  position: absolute;
  inset: 0;
  pointer-events: none;
  overflow: hidden;
}

.workspace-reaction-particle {
  position: absolute;
  left: var(--rx);
  bottom: 12px;
  font-size: 24px;
  line-height: 1;
  opacity: 0;
  animation: reactionRise 1.7s ease forwards;
  animation-delay: var(--delay);
}

@keyframes reactionRise {
  0% {
    transform: translate(0, 0) scale(0.92);
    opacity: 0;
  }

  16% {
    opacity: 1;
  }

  100% {
    transform: translate(-18px, -210px) scale(1.12);
    opacity: 0;
  }
}

.workspace-mini-strip {
  min-height: 0;
  background: var(--bg-strip);
  display: grid;
  grid-template-columns: repeat(6, minmax(0, 1fr));
  gap: 1px;
}

.workspace-mini-tile,
.workspace-mini-empty {
  background: var(--bg-mini-video);
  border-radius: 4px;
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

.workspace-controls {
  position: relative;
  display: inline-flex;
  justify-content: center;
  align-items: center;
  flex-wrap: wrap;
  gap: 10px;
  padding: 8px 0 2px;
}

.workspace-reactions-tray {
  position: absolute;
  bottom: calc(100% + 8px);
  left: 50%;
  transform: translate(-50%, 10px);
  background: #112b55;
  border: 1px solid var(--border-subtle);
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
  width: 30px;
  height: 30px;
  border: 0;
  border-radius: 8px;
  background: #21508f;
  color: #ffffff;
  cursor: pointer;
}

.workspace-reaction-btn:hover {
  background: #2f68ba;
}

.call-control-btn {
  width: 44px;
  height: 44px;
  border: 0;
  border-radius: 12px;
  background: #1b427a;
  color: #ffffff;
  display: grid;
  place-items: center;
  cursor: pointer;
}

.call-control-btn.active {
  background: #3f79d6;
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
  background: var(--bg-sidebar);
  display: grid;
  grid-template-rows: auto minmax(0, 1fr) auto;
}

.tab-panel {
  display: none;
  min-height: 0;
}

.tab-panel.active {
  display: grid;
}

.panel-users.active,
.panel-lobby.active {
  grid-template-rows: auto minmax(0, 1fr) auto;
}

.panel-chat.active {
  grid-template-rows: minmax(0, 1fr) auto auto;
}

.toolbar,
.lobby-toolbar {
  padding: 10px;
  border-top: 1px solid var(--border-subtle);
  border-bottom: 1px solid var(--border-subtle);
  background: #112b55;
}

.search {
  width: 100%;
  height: 38px;
  border: 0;
  border-radius: 6px;
  background: #0c1f41;
  color: var(--text-main);
  padding: 0 10px;
}

.search::placeholder {
  color: var(--text-dim);
}

.user-list,
.lobby-list,
.workspace-chat-list {
  min-height: 0;
  overflow: auto;
}

.user-row {
  list-style: none;
  display: grid;
  grid-template-columns: 48px minmax(0, 1fr) auto;
  gap: 8px;
  align-items: center;
  padding: 10px;
  border-bottom: 1px solid var(--border-subtle);
  background: #163260;
}

.user-row.self {
  background: #1e3e74;
}

.user-row.pinned {
  background: #2d63b3;
}

.user-preview {
  width: 40px;
  height: 40px;
  border-radius: 50%;
  background: #2b63ac;
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

.user-list-empty {
  list-style: none;
  padding: 12px;
  font-size: 12px;
  color: var(--text-muted);
  border-bottom: 1px solid var(--border-subtle);
  background: #112b55;
}

.workspace-tab-footer {
  background: #112b55;
  padding: 8px;
}

.workspace-chat-list {
  padding: 10px;
  display: grid;
  align-content: start;
  gap: 8px;
  background: #112b55;
}

.workspace-chat-message {
  border: 1px solid var(--border-subtle);
  border-radius: 6px;
  padding: 8px;
  background: #163260;
}

.workspace-chat-message.mine {
  background: #2a569f;
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
  background: #112b55;
  font-size: 12px;
  color: var(--text-muted);
}

.workspace-chat-compose {
  background: #112b55;
  padding: 8px 10px 10px;
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: 8px;
  align-items: center;
  border-top: 1px solid var(--border-subtle);
}

.workspace-invite-join {
  background: #112b55;
  border-top: 1px solid var(--border-subtle);
  padding: 10px;
  display: grid;
  gap: 8px;
}

.workspace-invite-label {
  font-size: 12px;
  color: var(--text-muted);
}

.workspace-invite-row {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: 8px;
}

.workspace-invite-hint {
  margin: 0;
  font-size: 12px;
  color: var(--text-muted);
}

.workspace-call-head-actions .btn {
  height: 38px;
}

@media (max-width: 1400px) {
  .workspace-call-body {
    grid-template-columns: minmax(0, 1fr) 330px;
  }

  .workspace-mini-strip {
    grid-template-columns: repeat(4, minmax(0, 1fr));
  }
}

@media (max-width: 980px) {
  .workspace-call-body {
    grid-template-columns: 1fr;
    grid-template-rows: minmax(0, 1fr) minmax(360px, 44vh);
  }

  .workspace-stage {
    grid-template-rows: minmax(0, 1fr) 82px auto;
  }

  .workspace-mini-strip {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }
}
</style>
