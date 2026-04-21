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
      <section
        class="workspace-stage"
        :class="{
          compact: isCompactHeaderVisible,
          'has-mini-strip': showMiniParticipantStrip,
          'mini-strip-above': isMobileMiniStripAbove,
          'layout-grid': currentLayoutMode === 'grid',
          'layout-main-mini': currentLayoutMode === 'main_mini',
          'layout-main-only': currentLayoutMode === 'main_only',
        }"
      >
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
          <section v-if="currentLayoutMode === 'grid'" class="workspace-grid-video">
            <article
              v-for="participant in gridVideoParticipants"
              :key="participant.userId"
              class="workspace-grid-tile"
            >
              <div
                :id="gridVideoSlotId(participant.userId)"
                class="workspace-grid-video-slot"
                :data-user-id="participant.userId"
              ></div>
              <span class="workspace-grid-title">{{ participant.displayName }}</span>
            </article>
          </section>
          <template v-else>
            <div id="local-video-container" class="video-container local"></div>
            <div id="remote-video-container" class="video-container remote"></div>
            <div id="decoded-video-container" class="video-container decoded"></div>
          </template>

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
          v-if="showChatUnreadToast"
          class="workspace-chat-toast"
          type="button"
          title="Open chat"
          aria-label="Open chat"
          @click="openChatPanel"
        >
          <img class="workspace-chat-toast-icon" src="/assets/orgas/kingrt/icons/chat.png" alt="" />
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

        <section v-if="showMiniParticipantStrip" class="workspace-mini-strip">
          <button
            v-if="showMobileMiniStripToggle"
            class="workspace-mini-placement-toggle"
            type="button"
            :title="mobileMiniStripToggleLabel"
            :aria-label="mobileMiniStripToggleLabel"
            @click="toggleMobileMiniStripPlacement"
          >
            <img
              class="workspace-mini-placement-icon"
              :class="{ up: !isMobileMiniStripAbove, down: isMobileMiniStripAbove }"
              src="/assets/orgas/kingrt/icons/forward.png"
              alt=""
            />
          </button>
          <article
            v-for="participant in miniVideoParticipants"
            :key="participant.userId"
            class="workspace-mini-tile"
          >
            <div
              :id="miniVideoSlotId(participant.userId)"
              class="workspace-mini-video-slot"
              :data-user-id="participant.userId"
            ></div>
            <span class="workspace-mini-title">{{ participant.displayName }}</span>
            <span class="workspace-mini-meta">{{ participant.role }}</span>
          </article>
          <article v-if="miniVideoParticipants.length === 0" class="workspace-mini-empty">
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
            aria-label="Users"
            title="Users"
            :aria-selected="activeTab === 'users'"
            @click="setActiveTab('users')"
          >
            <img class="tab-icon" src="/assets/orgas/kingrt/icons/users.png" alt="" />
          </button>
          <button
            v-if="showLobbyTab"
            class="tab tab-lobby"
            :class="{ active: activeTab === 'lobby' }"
            type="button"
            role="tab"
            aria-label="Lobby"
            title="Lobby"
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
            aria-label="Chat"
            title="Chat"
            :aria-selected="activeTab === 'chat'"
            @click="setActiveTab('chat')"
          >
            <span class="tab-icon-wrap">
              <img class="tab-icon" src="/assets/orgas/kingrt/icons/chat.png" alt="" />
              <span v-if="showChatUnreadBadge" class="tab-chat-unread-badge" aria-label="Unread chat messages"></span>
            </span>
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
                <span class="user-status-line">
                  <span v-if="activityLabelForUser(row.userId)" class="user-activity-pill">
                    {{ activityLabelForUser(row.userId) }}
                  </span>
                  <span v-if="row.controlBadge" class="user-feedback">{{ row.controlBadge }}</span>
                  <span v-if="row.feedback" class="user-feedback">{{ row.feedback }}</span>
                </span>
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
                :disabled="!canManageOwnerRole || !activeCallId || rowActionPending(row.userId) || !row.isRoomMember || row.callRole === 'owner'"
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

        <section v-if="showLobbyTab" class="tab-panel panel-lobby" :class="{ active: activeTab === 'lobby' }">
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
              <p v-if="message.text !== ''">{{ message.text }}</p>
              <div v-if="message.attachments.length > 0" class="workspace-chat-attachments">
                <a
                  v-for="attachment in message.attachments"
                  :key="attachment.id"
                  class="workspace-chat-attachment"
                  :href="attachment.download_url"
                  target="_blank"
                  rel="noopener"
                  download
                >
                  <span class="workspace-chat-attachment-kind">{{ attachment.kind }}</span>
                  <span class="workspace-chat-attachment-name">{{ attachment.name }}</span>
                  <span class="workspace-chat-attachment-size">{{ formatBytes(attachment.size_bytes) }}</span>
                </a>
              </div>
            </article>
            <article v-if="activeMessages.length === 0" class="workspace-chat-empty">
              No chat messages yet.
            </article>
          </div>

          <p v-if="typingUsers.length > 0" class="workspace-typing">
            {{ typingUsers.join(', ') }} typing…
          </p>

          <form
            class="workspace-chat-compose"
            :class="{ dragging: chatAttachmentDragActive }"
            @submit.prevent="sendChatMessage"
            @dragenter.prevent="chatAttachmentDragActive = true"
            @dragover.prevent="chatAttachmentDragActive = true"
            @dragleave.prevent="chatAttachmentDragActive = false"
            @drop.prevent="handleChatAttachmentDrop"
          >
            <div v-if="chatAttachmentError !== ''" class="workspace-chat-attachment-error">
              {{ chatAttachmentError }}
            </div>
            <div v-if="chatAttachmentDrafts.length > 0" class="workspace-chat-drafts">
              <article
                v-for="draft in chatAttachmentDrafts"
                :key="draft.localId"
                class="workspace-chat-draft"
              >
                <div class="workspace-chat-draft-main">
                  <input
                    class="workspace-chat-draft-name"
                    type="text"
                    :value="draft.name"
                    aria-label="Attachment filename"
                    @input="updateChatAttachmentDraftName(draft.localId, $event.target.value)"
                  />
                  <span>{{ draft.kind }} · {{ formatBytes(draft.sizeBytes) }}</span>
                </div>
                <p v-if="draft.preview" class="workspace-chat-draft-preview">{{ draft.preview }}</p>
                <button
                  class="icon-mini-btn danger"
                  type="button"
                  title="Remove attachment"
                  aria-label="Remove attachment"
                  @click="removeChatAttachmentDraft(draft.localId)"
                >
                  ×
                </button>
              </article>
            </div>
            <div v-if="chatEmojiTrayOpen" class="workspace-chat-emoji-tray">
              <button
                v-for="emoji in chatEmojiOptions"
                :key="emoji"
                class="workspace-chat-emoji-btn"
                type="button"
                @click="insertChatEmoji(emoji)"
              >
                {{ emoji }}
              </button>
            </div>
            <button
              class="icon-mini-btn chat-emoji-toggle"
              type="button"
              :class="{ active: chatEmojiTrayOpen }"
              title="Add emoji"
              aria-label="Add emoji"
              @click="toggleChatEmojiTray"
            >
              🙂
            </button>
            <button
              class="icon-mini-btn"
              type="button"
              title="Add attachment"
              aria-label="Add attachment"
              @click="openChatAttachmentPicker"
            >
              +
            </button>
            <input
              ref="chatAttachmentInputRef"
              class="workspace-chat-file-input"
              type="file"
              multiple
              accept=".jpg,.jpeg,.png,.webp,.gif,.txt,.csv,.md,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.odt,.ods,.odp"
              @change="handleChatAttachmentPick"
            />
            <input
              ref="chatInputRef"
              v-model="chatDraft"
              class="search"
              type="text"
              maxlength="2000"
              placeholder="Write a message"
              @input="handleChatInput"
              @paste="handleChatPaste"
            />
            <button class="icon-mini-btn" type="submit" :disabled="!isSocketOnline || !hasChatPayload || chatSending">
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
import { isGuestSession, sessionState } from '../auth/session';
import {
  resolveBackendWebSocketOriginCandidates,
  setBackendWebSocketOrigin,
} from '../../support/backendOrigin';
import {
  attachCallMediaDeviceWatcher,
  callMediaPrefs,
  refreshCallMediaDevices,
  resetCallBackgroundRuntimeState,
} from './callMediaPreferences';
import { BackgroundFilterController } from './backgroundFilterController';
import { BackgroundFilterBaselineCollector } from './backgroundFilterBaseline';
import { evaluateBackgroundFilterGates } from './backgroundFilterGates';
import { detectMediaRuntimeCapabilities } from './mediaRuntimeCapabilities';
import { appendMediaRuntimeTransitionEvent } from './mediaRuntimeTelemetry';
import { SFUClient } from '../../lib/sfu/sfuClient';
import { createWasmEncoder, createWasmDecoder } from '../../lib/wasm/wasm-codec';
import {
  ALONE_IDLE_ACTIVITY_EVENTS,
  ALONE_IDLE_COUNTDOWN_MS,
  ALONE_IDLE_POLL_MS,
  ALONE_IDLE_PROMPT_AFTER_MS,
  ALONE_IDLE_TICK_MS,
  chatEmojiOptions,
  COMPACT_BREAKPOINT,
  DEFAULT_NATIVE_ICE_SERVERS,
  LOBBY_PAGE_SIZE,
  LOCAL_REACTION_ECHO_TTL_MS,
  LOCAL_TRACK_RECOVERY_BASE_DELAY_MS,
  LOCAL_TRACK_RECOVERY_MAX_ATTEMPTS,
  LOCAL_TRACK_RECOVERY_MAX_DELAY_MS,
  MODERATION_SYNC_FLUSH_INTERVAL_MS,
  PARTICIPANT_ACTIVITY_WINDOW_MS,
  REACTION_CLIENT_BATCH_SIZE,
  REACTION_CLIENT_DIRECT_PER_WINDOW,
  REACTION_CLIENT_FLUSH_INTERVAL_MS,
  REACTION_CLIENT_MAX_QUEUE,
  REACTION_CLIENT_WINDOW_MS,
  RECONNECT_DELAYS_MS,
  ROSTER_VIRTUAL_OVERSCAN,
  ROSTER_VIRTUAL_ROW_HEIGHT,
  SFU_CONNECT_MAX_RETRIES,
  SFU_CONNECT_RETRY_DELAY_MS,
  SFU_PUBLISH_MAX_RETRIES,
  SFU_PUBLISH_RETRY_DELAY_MS,
  SFU_RUNTIME_ENABLED,
  TYPING_LOCAL_STOP_MS,
  TYPING_SWEEP_MS,
  USERS_PAGE_SIZE,
  VISIBLE_PARTICIPANTS_LIMIT,
  WLVC_ENCODE_ERROR_LOG_COOLDOWN_MS,
  WLVC_ENCODE_FAILURE_THRESHOLD,
  WLVC_ENCODE_FAILURE_WINDOW_MS,
  WLVC_ENCODE_WARMUP_MS,
  mediaDebugLog,
  reactionOptions,
} from './callWorkspaceConfig';
import {
  CHAT_ATTACHMENT_MAX_COUNT,
  CHAT_INLINE_MAX_BYTES,
  CHAT_INLINE_MAX_CHARS,
  buildFileAttachmentDraft,
  buildTextAttachmentDraft,
  chatAttachmentDraftToBase64,
  chatUtf8ByteLength,
  isChatTextInlineAllowed,
  sanitizeChatAttachmentName,
  validateChatAttachmentDraft,
} from './chatAttachments';
import {
  callRoleRank,
  formatTimestamp,
  initials,
  miniVideoSlotId,
  normalizeCallRole,
  normalizeOptionalRoomId,
  normalizeRole,
  normalizeRoomId,
  normalizeSocketCallId,
  normalizeUsersDirectoryOrder,
  normalizeUsersDirectoryStatus,
  parseUsersDirectoryQuery,
  roleRank,
} from './callWorkspaceUtils';
import {
  CALL_LAYOUT_MODES,
  CALL_LAYOUT_STRATEGIES,
  normalizeCallLayoutMode,
  normalizeCallLayoutState,
  selectCallLayoutParticipants,
} from './callLayoutStrategies';
import {
  apiRequest,
  extractErrorMessage,
  requestHeaders,
  socketUrlForRoom,
} from './callWorkspaceApi';

const route = useRoute();
const router = useRouter();
const workspaceSidebarState = inject('workspaceSidebarState', null);

const ACTIVITY_PUBLISH_INTERVAL_MS = 800;
const ACTIVITY_MOTION_SAMPLE_MS = 500;
const REMOTE_FRAME_ACTIVITY_MARK_INTERVAL_MS = 1000;
const LAYOUT_MODE_OPTIONS = [
  { mode: 'grid', label: 'Grid', icon: 'G' },
  { mode: 'main_mini', label: 'Main + Mini', icon: 'M' },
  { mode: 'main_only', label: 'Main only', icon: '1' },
];
const LAYOUT_STRATEGY_LABELS = {
  manual_pinned: 'Manual / pinned',
  most_active_window: 'Most active window',
  active_speaker_main: 'Active speaker main',
  round_robin_active: 'Round robin active',
};

const activeTab = ref('users');
const usersSearch = ref('');
const usersPage = ref(1);
const lobbyPage = ref(1);
const chatDraft = ref('');
const chatEmojiTrayOpen = ref(false);
const chatAttachmentDrafts = ref([]);
const chatAttachmentError = ref('');
const chatAttachmentDragActive = ref(false);
const chatSending = ref(false);
const chatInputRef = ref(null);
const chatAttachmentInputRef = ref(null);
const chatListRef = ref(null);
const usersListRef = ref(null);
const lobbyListRef = ref(null);

const connectionState = ref('retrying');
const connectionReason = ref('');
const reconnectAttempt = ref(0);
const socketRef = ref(null);
const serverRoomId = ref('lobby');

const participantsRaw = ref([]);
let participantsRawSignature = '';
const currentUserConnectedAt = new Date().toISOString();
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
const chatUnreadByRoom = reactive({});

const mutedUsers = reactive({});
const pinnedUsers = reactive({});
const participantActivityByUserId = reactive({});
const callLayoutState = reactive({
  call_id: '',
  room_id: '',
  mode: 'main_mini',
  strategy: 'manual_pinned',
  automation_paused: false,
  pinned_user_ids: [],
  selected_user_ids: [],
  main_user_id: 0,
  selection: {
    main_user_id: 0,
    visible_user_ids: [],
    mini_user_ids: [],
    pinned_user_ids: [],
  },
  updated_at: '',
});
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
const mobileMiniStripPlacement = ref('below');

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
const admissionGateState = reactive({
  roomId: '',
  message: '',
});
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
const dynamicIceServers = ref([]);
let runtimeSwitchInFlight = false;
let wlvcEncodeFailureCount = 0;
let wlvcEncodeWarmupUntilMs = 0;
let wlvcEncodeFirstFailureAtMs = 0;
let wlvcEncodeLastErrorLogAtMs = 0;
const localTracksRef = ref([]);
const remotePeersRef = ref(new Map());
const pendingSfuRemotePeerInitializers = new Map();
const remoteFrameActivityLastByUserId = new Map();
const sfuConnected = ref(false);
let sfuConnectRetryCount = 0;
let detachMediaDeviceWatcher = null;
let localTrackReconfigureInFlight = false;
let localTrackReconfigureQueuedMode = null;
let compactMediaQuery = null;
let activityMonitorTimer = null;
let activityAudioContext = null;
let activityAudioAnalyser = null;
let activityAudioData = null;
let activityMotionCanvas = null;
let activityMotionContext = null;
let activityPreviousFrame = null;
let activityLastPublishMs = 0;
let activityLastMotionSampleMs = 0;
let activityLastMotionScore = 0;
let dynamicIceServersPromise = null;
let dynamicIceServersExpiresAtMs = 0;

const routeCallRef = computed(() => String(route.params.callRef || '').trim());
const desiredRoomId = computed(() => normalizeRoomId(routeCallResolve.roomId || routeCallRef.value || 'lobby'));
const activeRoomId = computed(() => normalizeRoomId(serverRoomId.value || desiredRoomId.value));
const activeSocketCallId = computed(() => normalizeSocketCallId(activeCallId.value || routeCallResolve.callId || ''));
const currentUserId = computed(() => (Number.isInteger(sessionState.userId) ? sessionState.userId : 0));
const showAdmissionGate = computed(() => {
  const gateRoomId = normalizeOptionalRoomId(admissionGateState.roomId);
  return gateRoomId !== '' && activeRoomId.value !== gateRoomId;
});
const canModerate = computed(() => (
  normalizeRole(sessionState.role) === 'admin'
  || viewerCallRole.value === 'owner'
  || viewerCallRole.value === 'moderator'
));
const canManageOwnerRole = computed(() => (
  normalizeRole(sessionState.role) === 'admin'
  || viewerCallRole.value === 'owner'
));
const showLobbyTab = computed(() => canModerate.value);
const usersSourceMode = computed(() => 'snapshot');
const isSocketOnline = computed(() => connectionState.value === 'online');
const shouldConnectSfu = computed(() => (
  isWlvcRuntimePath()
  && isSocketOnline.value
  && hasRealtimeRoomSync.value
  && !routeCallResolve.pending
  && routeCallResolve.error === ''
  && activeSocketCallId.value !== ''
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
const isMobileMiniStripAbove = computed(() => (
  isShellMobileViewport.value
  && mobileMiniStripPlacement.value === 'above'
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

async function resolveRouteRefSafely(callRef) {
  const payload = await apiRequest(`/api/calls/resolve/${encodeURIComponent(callRef)}`);
  const result = payload?.result && typeof payload.result === 'object' ? payload.result : {};
  const state = String(result.state || '').trim().toLowerCase();
  const accessLink = result?.access_link || {};
  const call = result?.call || {};
  const resolution = callPayloadToRouteResolution(call);
  const normalizedAccessId = String(accessLink?.id || '').trim().toLowerCase();
  return {
    state,
    reason: String(result.reason || '').trim().toLowerCase(),
    resolvedAs: String(result.resolved_as || '').trim().toLowerCase(),
    accessId: normalizedAccessId,
    callId: resolution.callId,
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

  try {
    const callResolution = await resolveRouteRefSafely(normalized);
    if (seq !== routeCallResolveSeq) return;
    if (callResolution.state === 'resolved') {
      applyRouteCallResolution({
        ...callResolution,
        error: '',
        pending: false,
      });
      return;
    }

    if (looksLikeUuid) {
      const isExpired = callResolution.state === 'expired';
      applyRouteCallResolution({
        accessId: isExpired ? normalized.toLowerCase() : '',
        callId: '',
        roomId: 'lobby',
        error: isExpired ? 'route_call_access_expired' : 'route_call_ref_not_found',
        pending: false,
      });

      const fallbackRouteName = normalizeRole(sessionState.role) === 'admin' ? 'admin-calls' : 'user-dashboard';
      if (String(route.name || '') === 'call-workspace' && String(routeCallRef.value || '').trim() !== '') {
        void router.replace({ name: fallbackRouteName });
      }
      return;
    }
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

function participantSnapshotSignature(rows) {
  const normalizedRows = Array.isArray(rows) ? rows : [];
  return normalizedRows
    .map((row) => normalizeParticipantRow(row))
    .filter((row) => row.userId > 0 || row.connectionId !== '')
    .map((row) => [
      row.roomId,
      row.connectionId,
      row.userId,
      row.displayName,
      row.role,
      row.callRole,
      row.hasConnection ? '1' : '0',
      row.connectedAt,
    ].join('\u001f'))
    .sort()
    .join('\u001e');
}

function applyParticipantsSnapshot(rows) {
  const nextRows = Array.isArray(rows) ? rows : [];
  const nextSignature = participantSnapshotSignature(nextRows);
  if (nextSignature === participantsRawSignature) {
    return false;
  }
  participantsRawSignature = nextSignature;
  participantsRaw.value = nextRows;
  return true;
}

function currentUserParticipantRow() {
  const userId = currentUserId.value;
  if (!Number.isInteger(userId) || userId <= 0) return null;

  const displayName = String(sessionState.displayName || sessionState.email || '').trim() || 'You';
  const callRole = normalizeCallRole(callParticipantRoles[userId] || viewerCallRole.value || 'participant');
  return {
    userId,
    displayName,
    role: normalizeRole(sessionState.role),
    callRole,
    connectedAt: currentUserConnectedAt,
    connections: 1,
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

  for (const peer of remotePeersRef.value.values()) {
    const peerUserId = Number(peer?.userId || 0);
    if (!Number.isInteger(peerUserId) || peerUserId <= 0 || peerUserId === currentUserId.value) continue;

    const existing = aggregate.get(peerUserId);
    const displayName = String(peer?.displayName || '').trim() || `User ${peerUserId}`;
    if (existing) {
      existing.connections = Math.max(1, Number(existing.connections || 0));
      if (displayName.length > existing.displayName.length) {
        existing.displayName = displayName;
      }
      existing.callRole = normalizeCallRole(callParticipantRoles[peerUserId] || existing.callRole || 'participant');
      continue;
    }

    aggregate.set(peerUserId, {
      userId: peerUserId,
      displayName,
      role: 'user',
      callRole: normalizeCallRole(callParticipantRoles[peerUserId] || 'participant'),
      connectedAt: '',
      connections: 1,
    });
  }

  const currentUser = currentUserParticipantRow();
  if (currentUser) {
    const existing = aggregate.get(currentUser.userId);
    if (existing) {
      existing.displayName = currentUser.displayName;
      existing.role = currentUser.role;
      existing.callRole = currentUser.callRole;
      existing.connections = Math.max(1, Number(existing.connections || 0));
    } else {
      aggregate.set(currentUser.userId, currentUser);
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
  if (normalized === 'speaking') return 1;
  if (normalized === 'motion') return 0.9;
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
  if (Number.isFinite(Number(entry.score2s))) return Number(entry.score2s);
  if (Number.isFinite(Number(entry.score_2s))) return Number(entry.score_2s);
  if (Number.isFinite(Number(entry.score))) return Number(entry.score);
  const lastActiveMs = Number(entry.lastActiveMs || 0);
  if (!Number.isFinite(lastActiveMs) || lastActiveMs <= 0) return 0;
  const ageMs = Math.max(0, nowMs - lastActiveMs);
  if (ageMs >= PARTICIPANT_ACTIVITY_WINDOW_MS) return 0;
  const freshness = 1 - (ageMs / PARTICIPANT_ACTIVITY_WINDOW_MS);
  const weight = Number.isFinite(Number(entry.weight)) ? Number(entry.weight) : 0.5;
  return freshness * Math.max(0.25, Math.min(1, weight)) * 100;
}

function activityLabelForUser(userId) {
  const normalizedUserId = Number(userId);
  if (!Number.isInteger(normalizedUserId) || normalizedUserId <= 0) return '';
  const entry = participantActivityByUserId[normalizedUserId];
  const score = participantActivityScore(normalizedUserId);
  if (Boolean(entry?.isSpeaking) || score >= 55) return 'Speaking';
  if (score >= 18) return 'Active';
  return '';
}

function applyParticipantActivityPayload(activity, participant = null) {
  const normalizedUserId = Number(activity?.user_id || activity?.userId || participant?.user_id || participant?.userId || 0);
  if (!Number.isInteger(normalizedUserId) || normalizedUserId <= 0) return;
  const updatedAtMs = Number(activity?.updated_at_ms || activity?.updatedAtMs || Date.now());
  participantActivityByUserId[normalizedUserId] = {
    lastActiveMs: Number.isFinite(updatedAtMs) && updatedAtMs > 0 ? updatedAtMs : Date.now(),
    source: String(activity?.source || 'server_activity').trim().toLowerCase(),
    weight: Number.isFinite(Number(activity?.score)) ? Math.max(0.25, Math.min(1, Number(activity.score) / 100)) : 0.75,
    score: Number(activity?.score || 0),
    score_2s: Number(activity?.score_2s ?? activity?.score2s ?? 0),
    score_5s: Number(activity?.score_5s ?? activity?.score5s ?? 0),
    score_15s: Number(activity?.score_15s ?? activity?.score15s ?? 0),
    isSpeaking: Boolean(activity?.is_speaking ?? activity?.isSpeaking ?? false),
  };
}

function applyActivitySnapshot(rows) {
  if (!Array.isArray(rows)) return;
  for (const row of rows) {
    applyParticipantActivityPayload(row);
  }
}

function replaceNumericArray(target, values) {
  target.splice(0, target.length, ...values
    .map((value) => Number(value))
    .filter((value) => Number.isInteger(value) && value > 0));
}

function applyCallLayoutPayload(payload) {
  const normalized = normalizeCallLayoutState(payload);
  callLayoutState.call_id = normalized.callId;
  callLayoutState.room_id = normalized.roomId;
  callLayoutState.mode = normalized.mode;
  callLayoutState.strategy = normalized.strategy;
  callLayoutState.automation_paused = normalized.automationPaused;
  callLayoutState.main_user_id = Number.isInteger(normalized.mainUserId) ? normalized.mainUserId : 0;
  callLayoutState.updated_at = normalized.updatedAt;
  replaceNumericArray(callLayoutState.pinned_user_ids, normalized.pinnedUserIds);
  replaceNumericArray(callLayoutState.selected_user_ids, normalized.selectedUserIds);
  callLayoutState.selection.main_user_id = Number.isInteger(normalized.selection.mainUserId) ? normalized.selection.mainUserId : 0;
  replaceNumericArray(callLayoutState.selection.visible_user_ids, normalized.selection.visibleUserIds);
  replaceNumericArray(callLayoutState.selection.mini_user_ids, normalized.selection.miniUserIds);
  replaceNumericArray(callLayoutState.selection.pinned_user_ids, normalized.selection.pinnedUserIds);

  for (const key of Object.keys(pinnedUsers)) {
    if (!normalized.pinnedUserIds.includes(Number(key))) {
      delete pinnedUsers[key];
    }
  }
  for (const pinnedUserId of normalized.pinnedUserIds) {
    pinnedUsers[pinnedUserId] = true;
  }

  nextTick(() => renderCallVideoLayout());
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

const normalizedCallLayout = computed(() => normalizeCallLayoutState(callLayoutState));
const currentLayoutMode = computed(() => normalizeCallLayoutMode(normalizedCallLayout.value.mode));
const layoutSelection = computed(() => selectCallLayoutParticipants({
  participants: connectedParticipantUsers.value,
  currentUserId: currentUserId.value,
  pinnedUsers,
  activityByUserId: participantActivityByUserId,
  layoutState: normalizedCallLayout.value,
  nowMs: Date.now(),
}));
const stripParticipants = computed(() => layoutSelection.value.visibleParticipants.slice(0, VISIBLE_PARTICIPANTS_LIMIT));
const primaryVideoUserId = computed(() => {
  const selectedId = Number(layoutSelection.value.mainUserId || 0);
  return Number.isInteger(selectedId) && selectedId > 0 ? selectedId : currentUserId.value;
});
const miniVideoParticipants = computed(() => {
  if (currentLayoutMode.value !== 'main_mini') return [];
  return layoutSelection.value.miniParticipants;
});
const gridVideoParticipants = computed(() => (
  currentLayoutMode.value === 'grid' ? layoutSelection.value.gridParticipants : []
));
const showMiniParticipantStrip = computed(() => (
  currentLayoutMode.value === 'main_mini'
  && connectedParticipantUsers.value.length > 1
));
const layoutModeOptions = computed(() => LAYOUT_MODE_OPTIONS.filter((option) => CALL_LAYOUT_MODES.includes(option.mode)));
const layoutStrategyOptions = computed(() => CALL_LAYOUT_STRATEGIES);
const showMobileMiniStripToggle = computed(() => (
  isShellMobileViewport.value
  && showMiniParticipantStrip.value
));
const mobileMiniStripToggleLabel = computed(() => (
  isMobileMiniStripAbove.value
    ? 'Move mini videos below main video'
    : 'Move mini videos above main video'
));

function toggleMobileMiniStripPlacement() {
  mobileMiniStripPlacement.value = isMobileMiniStripAbove.value ? 'below' : 'above';
  nextTick(() => renderCallVideoLayout());
}

function gridVideoSlotId(userId) {
  const normalizedUserId = Number(userId);
  return `workspace-grid-video-slot-${Number.isInteger(normalizedUserId) && normalizedUserId > 0 ? normalizedUserId : 'unknown'}`;
}

function layoutStrategyLabel(strategy) {
  const normalized = String(strategy || '').trim().toLowerCase();
  return LAYOUT_STRATEGY_LABELS[normalized] || normalized || 'Strategy';
}

function currentCallLayoutSidebarControls() {
  const controls = workspaceSidebarState?.callLayoutControls;
  return controls && typeof controls === 'object' ? controls : null;
}

function syncCallLayoutSidebarControls() {
  const controls = currentCallLayoutSidebarControls();
  if (!controls) return;

  controls.visible = true;
  controls.canModerate = canModerate.value;
  controls.currentMode = currentLayoutMode.value;
  controls.currentStrategy = normalizedCallLayout.value.strategy;
  controls.modeOptions = layoutModeOptions.value.map((option) => ({
    mode: option.mode,
    label: option.label,
    icon: option.icon,
  }));
  controls.strategyOptions = layoutStrategyOptions.value.map((strategy) => ({
    strategy,
    label: layoutStrategyLabel(strategy),
  }));
  controls.setMode = setCallLayoutMode;
  controls.setStrategy = setCallLayoutStrategy;
}

function clearCallLayoutSidebarControls() {
  const controls = currentCallLayoutSidebarControls();
  if (!controls) return;

  controls.visible = false;
  controls.canModerate = false;
  controls.currentMode = 'main_mini';
  controls.currentStrategy = 'manual_pinned';
  controls.modeOptions = [];
  controls.strategyOptions = [];
  controls.setMode = null;
  controls.setStrategy = null;
}

watch(
  () => [
    canModerate.value,
    currentLayoutMode.value,
    normalizedCallLayout.value.strategy,
    layoutModeOptions.value.map((option) => option.mode).join(','),
    layoutStrategyOptions.value.join(','),
  ],
  () => syncCallLayoutSidebarControls(),
  { immediate: true },
);

onBeforeUnmount(() => {
  clearCallLayoutSidebarControls();
});

const showLobbyRequestBadge = computed(() => (
  showLobbyTab.value
  && lobbyQueue.value.length > 0
  && activeTab.value !== 'lobby'
));
const lobbyRequestBadgeText = computed(() => (
  lobbyQueue.value.length > 99 ? '99+' : String(lobbyQueue.value.length)
));
const showLobbyJoinToast = computed(() => (
  showLobbyTab.value
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
  return lobbyQueue.value.map((row) => ({
    ...row,
    status: 'queued',
    sortTs: Number(row.requested_unix_ms || 0),
  })).sort((left, right) => {
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

const hasChatPayload = computed(() => chatDraft.value.trim() !== '' || chatAttachmentDrafts.value.length > 0);
const showChatUnreadBadge = computed(() => chatUnreadByRoom[activeRoomId.value] === true);
const showChatUnreadToast = computed(() => rightSidebarCollapsed.value && showChatUnreadBadge.value);

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

function setAdmissionGate(roomId, message = '') {
  const normalizedRoomId = normalizeOptionalRoomId(roomId);
  if (normalizedRoomId === '') return;
  admissionGateState.roomId = normalizedRoomId;
  admissionGateState.message = String(message || '').trim();
}

function clearAdmissionGate(roomId = '') {
  const normalizedRoomId = normalizeOptionalRoomId(roomId);
  if (normalizedRoomId !== '' && admissionGateState.roomId !== normalizedRoomId) {
    return;
  }
  admissionGateState.roomId = '';
  admissionGateState.message = '';
}

function shouldShowWorkspaceAdmissionNotice() {
  return false;
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
  publishLayoutSelectionState();
}

function currentPinnedUserIds() {
  return Object.entries(pinnedUsers)
    .filter(([, pinned]) => pinned === true)
    .map(([id]) => Number(id))
    .filter((id) => Number.isInteger(id) && id > 0);
}

function sendLayoutCommand(type, payload = {}) {
  if (!canModerate.value || !isSocketOnline.value) return false;
  return sendSocketFrame({
    type,
    ...payload,
  });
}

function setCallLayoutMode(mode) {
  const normalizedMode = normalizeCallLayoutMode(mode, currentLayoutMode.value);
  callLayoutState.mode = normalizedMode;
  if (!sendLayoutCommand('layout/mode', { mode: normalizedMode })) {
    setNotice('Could not update layout mode while websocket is offline.', 'error');
  }
  syncCallLayoutSidebarControls();
  nextTick(() => renderCallVideoLayout());
}

function setCallLayoutStrategy(strategy) {
  const normalizedStrategy = String(strategy || '').trim().toLowerCase();
  if (!CALL_LAYOUT_STRATEGIES.includes(normalizedStrategy)) return;
  callLayoutState.strategy = normalizedStrategy;
  callLayoutState.automation_paused = false;
  if (!sendLayoutCommand('layout/strategy', {
    strategy: normalizedStrategy,
    automation_paused: false,
  })) {
    setNotice('Could not update layout strategy while websocket is offline.', 'error');
  }
  syncCallLayoutSidebarControls();
  nextTick(() => renderCallVideoLayout());
}

function publishLayoutSelectionState() {
  if (!canModerate.value) return false;
  const pinnedIds = currentPinnedUserIds();
  replaceNumericArray(callLayoutState.pinned_user_ids, pinnedIds);
  callLayoutState.main_user_id = primaryVideoUserId.value;
  return sendLayoutCommand('layout/selection', {
    pinned_user_ids: pinnedIds,
    selected_user_ids: layoutSelection.value.visibleUserIds,
    main_user_id: primaryVideoUserId.value,
  });
}

function toggleHandRaised() {
  controlState.handRaised = !controlState.handRaised;
  refreshUsersDirectoryPresentation();
  void syncControlStateToPeers();
  publishLocalActivitySample(true);
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
  publishLocalActivitySample(true);
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
  publishLocalActivitySample(true);
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
  if (!showLobbyTab.value) return;
  showRightSidebar();
  setActiveTab('lobby');
  hideLobbyJoinToast();
}

function clearChatUnread(roomId = activeRoomId.value) {
  const normalizedRoomId = normalizeRoomId(roomId || activeRoomId.value);
  if (normalizedRoomId === '') return;
  delete chatUnreadByRoom[normalizedRoomId];
}

function markChatUnread(message) {
  const roomId = normalizeRoomId(message?.room_id || activeRoomId.value);
  if (roomId === '') return;
  const senderUserId = Number(message?.sender?.user_id || 0);
  if (Number.isInteger(senderUserId) && senderUserId > 0 && senderUserId === currentUserId.value) {
    return;
  }
  if (roomId === activeRoomId.value && activeTab.value === 'chat' && !rightSidebarCollapsed.value) {
    return;
  }
  chatUnreadByRoom[roomId] = true;
}

function openChatPanel() {
  showRightSidebar();
  setActiveTab('chat');
}

function setActiveTab(tab) {
  const requestedTab = ['users', 'lobby', 'chat'].includes(tab) ? tab : 'users';
  const nextTab = requestedTab === 'lobby' && !showLobbyTab.value ? 'users' : requestedTab;
  activeTab.value = nextTab;
  if (nextTab === 'users') {
    nextTick(() => syncUsersListViewport());
  } else if (nextTab === 'lobby') {
    hideLobbyJoinToast();
    nextTick(() => syncLobbyListViewport());
  } else if (nextTab === 'chat') {
    clearChatUnread();
  }
  if (nextTab !== 'chat') {
    chatEmojiTrayOpen.value = false;
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
  chatEmojiTrayOpen.value = false;
}

function showRightSidebar() {
  rightSidebarCollapsed.value = false;
  hideLobbyJoinToast();
  if (activeTab.value === 'chat') {
    clearChatUnread();
  }
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
    return false;
  }
  setAdmissionGate(targetRoomId);
  if (announce && shouldShowWorkspaceAdmissionNotice()) {
    setNotice('Admission request sent.');
  }
  return true;
}

function requestLobbyCancel(roomId = '') {
  const targetRoomId = normalizeRoomId(roomId || admissionGateState.roomId || desiredRoomId.value || activeRoomId.value || 'lobby');
  return sendSocketFrame({ type: 'lobby/queue/cancel', room_id: targetRoomId });
}

function cancelAdmissionWait() {
  const targetRoomId = normalizeOptionalRoomId(admissionGateState.roomId || desiredRoomId.value || activeRoomId.value);
  if (targetRoomId !== '') {
    requestLobbyCancel(targetRoomId);
  }
  clearAdmissionGate(targetRoomId);
  hangupCall();
}

function tryDirectJoinWithModeratorBypass(roomId = '') {
  const targetRoomId = normalizeRoomId(roomId || desiredRoomId.value || activeRoomId.value || 'lobby');
  if (!canModerate.value || targetRoomId === '') {
    return false;
  }
  return sendRoomJoin(targetRoomId);
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

function formatBytes(bytes) {
  const value = Number(bytes || 0);
  if (!Number.isFinite(value) || value <= 0) return '0 B';
  if (value < 1024) return `${Math.round(value)} B`;
  if (value < 1024 * 1024) return `${(value / 1024).toFixed(value < 10 * 1024 ? 1 : 0)} KiB`;
  return `${(value / (1024 * 1024)).toFixed(value < 10 * 1024 * 1024 ? 1 : 0)} MiB`;
}

function clampChatInlineText(value) {
  const source = String(value || '');
  if (source.length <= CHAT_INLINE_MAX_CHARS && chatUtf8ByteLength(source) <= CHAT_INLINE_MAX_BYTES) {
    return source;
  }

  let next = '';
  for (const char of source) {
    const candidate = `${next}${char}`;
    if (candidate.length > CHAT_INLINE_MAX_CHARS || chatUtf8ByteLength(candidate) > CHAT_INLINE_MAX_BYTES) {
      break;
    }
    next = candidate;
  }
  return next;
}

function setChatAttachmentError(message) {
  chatAttachmentError.value = String(message || '').trim();
}

function addChatAttachmentDraft(rawDraft) {
  setChatAttachmentError('');
  const currentDrafts = chatAttachmentDrafts.value;
  const validation = validateChatAttachmentDraft(rawDraft, currentDrafts);
  if (!validation.ok) {
    setChatAttachmentError(validation.message || 'Attachment is not allowed.');
    return false;
  }

  chatAttachmentDrafts.value = [
    ...currentDrafts,
    {
      ...rawDraft,
      name: validation.name,
      extension: validation.extension,
      kind: validation.kind,
      contentType: validation.contentType,
    },
  ];
  return true;
}

function updateChatAttachmentDraftName(localId, value) {
  const normalizedId = String(localId || '').trim();
  if (normalizedId === '') return;
  const index = chatAttachmentDrafts.value.findIndex((draft) => draft.localId === normalizedId);
  if (index < 0) return;
  const nextDrafts = [...chatAttachmentDrafts.value];
  nextDrafts[index] = {
    ...nextDrafts[index],
    name: String(value || '').slice(0, 180),
  };
  chatAttachmentDrafts.value = nextDrafts;
}

function removeChatAttachmentDraft(localId) {
  const normalizedId = String(localId || '').trim();
  chatAttachmentDrafts.value = chatAttachmentDrafts.value.filter((draft) => draft.localId !== normalizedId);
  if (chatAttachmentDrafts.value.length === 0) {
    setChatAttachmentError('');
  }
}

function openChatAttachmentPicker() {
  const input = chatAttachmentInputRef.value;
  if (input instanceof HTMLInputElement) {
    input.click();
  }
}

async function addChatAttachmentFiles(files) {
  const incoming = Array.from(files || []).filter(Boolean);
  if (incoming.length === 0) return;
  if (chatAttachmentDrafts.value.length + incoming.length > CHAT_ATTACHMENT_MAX_COUNT) {
    setChatAttachmentError('Only 10 attachments are allowed per chat message.');
    return;
  }

  for (const file of incoming) {
    addChatAttachmentDraft(buildFileAttachmentDraft(file));
  }
}

function handleChatAttachmentPick(event) {
  const input = event?.target;
  const files = input instanceof HTMLInputElement ? input.files : null;
  void addChatAttachmentFiles(files);
  if (input instanceof HTMLInputElement) {
    input.value = '';
  }
}

function handleChatAttachmentDrop(event) {
  chatAttachmentDragActive.value = false;
  const files = event?.dataTransfer?.files || null;
  void addChatAttachmentFiles(files);
}

function handleChatPaste(event) {
  const text = event?.clipboardData?.getData('text/plain') || '';
  if (text === '') return;

  const input = chatInputRef.value;
  const currentDraft = String(chatDraft.value || '');
  const selectionStart = input instanceof HTMLInputElement && Number.isInteger(input.selectionStart)
    ? Number(input.selectionStart)
    : currentDraft.length;
  const selectionEnd = input instanceof HTMLInputElement && Number.isInteger(input.selectionEnd)
    ? Number(input.selectionEnd)
    : selectionStart;
  const combined = `${currentDraft.slice(0, selectionStart)}${text}${currentDraft.slice(selectionEnd)}`;
  if (isChatTextInlineAllowed(text) && isChatTextInlineAllowed(combined)) {
    return;
  }

  event.preventDefault();
  if (addChatAttachmentDraft(buildTextAttachmentDraft(text))) {
    setNotice('Large pasted text was converted to a chat attachment.');
  }
}

async function uploadChatAttachmentDraft(draft) {
  const callId = String(activeCallId.value || '').trim();
  if (callId === '') {
    throw new Error('Cannot upload chat attachment before the call context is loaded.');
  }

  const validation = validateChatAttachmentDraft(draft, []);
  if (!validation.ok) {
    throw new Error(validation.message || 'Attachment is not allowed.');
  }

  const payload = await apiRequest(`/api/calls/${encodeURIComponent(callId)}/chat/attachments`, {
    method: 'POST',
    body: {
      file_name: sanitizeChatAttachmentName(draft.name, validation.extension || 'txt'),
      content_type: validation.contentType,
      content_base64: await chatAttachmentDraftToBase64({
        ...draft,
        name: sanitizeChatAttachmentName(draft.name, validation.extension || 'txt'),
      }),
    },
  });

  const attachment = payload?.result?.attachment;
  if (!attachment || typeof attachment !== 'object' || String(attachment.id || '').trim() === '') {
    throw new Error('Backend returned an invalid attachment payload.');
  }
  return attachment;
}

async function uploadChatAttachmentDrafts() {
  const uploaded = [];
  try {
    for (const draft of chatAttachmentDrafts.value) {
      uploaded.push(await uploadChatAttachmentDraft(draft));
    }
  } catch (error) {
    await cancelUploadedChatAttachments(uploaded);
    throw error;
  }
  return uploaded;
}

async function cancelUploadedChatAttachments(attachments) {
  const callId = String(activeCallId.value || '').trim();
  if (callId === '') return;
  const rows = Array.isArray(attachments) ? attachments : [];
  await Promise.all(rows.map(async (attachment) => {
    const attachmentId = String(attachment?.id || '').trim();
    if (attachmentId === '') return;
    try {
      await apiRequest(`/api/calls/${encodeURIComponent(callId)}/chat/attachments/${encodeURIComponent(attachmentId)}`, {
        method: 'DELETE',
      });
    } catch {
      // The draft is already invisible to chat peers; best-effort cleanup keeps retry UX intact.
    }
  }));
}

function focusChatInput() {
  nextTick(() => {
    const input = chatInputRef.value;
    if (input instanceof HTMLInputElement) {
      input.focus();
    }
  });
}

function toggleChatEmojiTray() {
  chatEmojiTrayOpen.value = !chatEmojiTrayOpen.value;
  if (chatEmojiTrayOpen.value) {
    focusChatInput();
  }
}

function insertChatEmoji(emoji) {
  const normalizedEmoji = String(emoji || '').trim();
  if (normalizedEmoji === '') return;

  const input = chatInputRef.value;
  const currentDraft = String(chatDraft.value || '');
  const selectionStart = input instanceof HTMLInputElement && Number.isInteger(input.selectionStart)
    ? Number(input.selectionStart)
    : currentDraft.length;
  const selectionEnd = input instanceof HTMLInputElement && Number.isInteger(input.selectionEnd)
    ? Number(input.selectionEnd)
    : selectionStart;
  const nextDraft = clampChatInlineText(`${currentDraft.slice(0, selectionStart)}${normalizedEmoji}${currentDraft.slice(selectionEnd)}`);
  const nextCursor = Math.min(selectionStart + normalizedEmoji.length, nextDraft.length);
  chatDraft.value = nextDraft;
  handleChatInput();

  nextTick(() => {
    const nextInput = chatInputRef.value;
    if (!(nextInput instanceof HTMLInputElement)) return;
    nextInput.focus();
    nextInput.setSelectionRange(nextCursor, nextCursor);
  });
}

function handleChatInput() {
  const clamped = clampChatInlineText(chatDraft.value);
  if (clamped !== chatDraft.value) {
    chatDraft.value = clamped;
  }

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

async function sendChatMessage() {
  const text = chatDraft.value.trim();
  const hasAttachments = chatAttachmentDrafts.value.length > 0;
  if ((text === '' && !hasAttachments) || !isSocketOnline.value || chatSending.value) return;
  markParticipantActivity(currentUserId.value, 'chat');

  const clientMessageId = `client_${Date.now()}_${Math.random().toString(16).slice(2, 8)}`;
  chatSending.value = true;
  let attachments = [];
  try {
    attachments = hasAttachments ? await uploadChatAttachmentDrafts() : [];
  } catch (error) {
    setChatAttachmentError(error instanceof Error ? error.message : 'Could not upload chat attachment.');
    chatSending.value = false;
    return;
  }

  const sent = sendSocketFrame({
    type: 'chat/send',
    message: text,
    attachments: attachments.map((attachment) => ({ id: attachment.id })),
    client_message_id: clientMessageId,
  });

  if (!sent) {
    await cancelUploadedChatAttachments(attachments);
    setNotice('Could not send chat message while websocket is offline.', 'error');
    chatSending.value = false;
    return;
  }

  appendChatMessage({
    type: 'chat/message',
    room_id: activeRoomId.value,
    message: {
      id: clientMessageId,
      client_message_id: clientMessageId,
      text,
      attachments,
      sender: {
        user_id: currentUserId.value,
        display_name: String(sessionState.displayName || sessionState.email || '').trim() || 'You',
        role: normalizeRole(sessionState.role),
      },
      server_time: new Date().toISOString(),
    },
    time: new Date().toISOString(),
  });

  chatDraft.value = '';
  chatAttachmentDrafts.value = [];
  chatAttachmentError.value = '';
  chatEmojiTrayOpen.value = false;
  chatSending.value = false;
  stopLocalTyping();
}

function normalizeChatMessage(payload) {
  const roomId = normalizeRoomId(payload?.room_id || payload?.roomId || activeRoomId.value);
  const message = payload && typeof payload.message === 'object' ? payload.message : {};
  const sender = message && typeof message.sender === 'object' ? message.sender : {};
  const attachments = Array.isArray(message.attachments)
    ? message.attachments.map((attachment) => {
      const row = attachment && typeof attachment === 'object' ? attachment : {};
      return {
        id: String(row.id || '').trim(),
        name: String(row.name || 'attachment').trim() || 'attachment',
        content_type: String(row.content_type || 'application/octet-stream').trim() || 'application/octet-stream',
        size_bytes: Number(row.size_bytes || 0) || 0,
        kind: String(row.kind || 'document').trim() || 'document',
        download_url: String(row.download_url || '').trim(),
      };
    }).filter((attachment) => attachment.id !== '' && attachment.download_url !== '')
    : [];

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
    attachments,
  };
}

function appendChatMessage(payload) {
  const message = normalizeChatMessage(payload);
  if (message.text === '' && message.attachments.length === 0) return;
  if (Number.isInteger(message.sender.user_id) && message.sender.user_id > 0) {
    markParticipantActivity(message.sender.user_id, 'chat');
  }

  ensureRoomBuckets(message.room_id);
  const bucket = chatByRoom[message.room_id];
  const clientMessageId = String(message.client_message_id || '').trim();
  const existingIndex = bucket.findIndex((row) => (
    row.id === message.id
    || (clientMessageId !== '' && String(row.client_message_id || '').trim() === clientMessageId)
  ));
  if (existingIndex >= 0) {
    bucket[existingIndex] = {
      ...bucket[existingIndex],
      ...message,
    };
    return;
  }

  bucket.push(message);
  if (bucket.length > 240) {
    bucket.splice(0, bucket.length - 240);
  }
  markChatUnread(message);
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
  if (!canManageOwnerRole.value) return;
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
    participantsRawSignature = '';
  }
  serverRoomId.value = roomId;
  if (pendingAdmissionJoinRoomId.value === roomId) {
    pendingAdmissionJoinRoomId.value = '';
  }
  if (roomId === desiredRoomId.value) {
    clearAdmissionGate(roomId);
  }
  ensureRoomBuckets(roomId);
  applyViewerContext(payload?.viewer || null);

  applyParticipantsSnapshot(payload?.participants);
  if (payload?.layout && typeof payload.layout === 'object') {
    applyCallLayoutPayload(payload.layout);
  }
  if (Array.isArray(payload?.activity)) {
    applyActivitySnapshot(payload.activity);
  }

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

  const sender = typeof payload.sender === 'object' ? payload.sender : {};
  const senderUserId = Number(sender.user_id || 0);
  const payloadBody = typeof payload.payload === 'object' ? payload.payload : null;
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
    const admission = typeof payload.admission === 'object' ? payload.admission : null;
    const requiresAdmission = Boolean(admission?.requires_admission);
    const pendingRoomId = normalizeRoomId(admission?.pending_room_id || '');
    if (requiresAdmission && pendingRoomId !== '') {
      if (!tryDirectJoinWithModeratorBypass(pendingRoomId)) {
        setAdmissionGate(pendingRoomId);
        requestLobbyJoin(pendingRoomId, { announce: false });
        requestRoomSnapshot();
        return;
      }
    }
    clearAdmissionGate();
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

  if (type === 'participant/activity') {
    applyParticipantActivityPayload(payload?.activity, payload?.participant);
    return;
  }

  if (type === 'layout/mode' || type === 'layout/strategy' || type === 'layout/selection' || type === 'layout/state') {
    if (payload?.layout && typeof payload.layout === 'object') {
      applyCallLayoutPayload(payload.layout);
    }
    return;
  }

  if (type === 'call/ack') {
    const signalType = String(payload?.signal_type || '').replace('call/', '').trim() || 'signal';
    if (signalType === 'offer' && Number(payload?.sent_count ?? 0) === 0) {
      scheduleNativeOfferRetryForUserId(payload?.target_user_id, 'brokered_offer_unanswered');
    }
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
    if (
      code === 'lobby_command_failed'
      && (failedCommandType === 'lobby/queue/join' || failedCommandType === 'lobby/queue/request' || failedCommandType === 'lobby/queue/cancel')
      && showAdmissionGate.value
    ) {
      return;
    }
    if (code === 'room_join_requires_admission' || code === 'room_join_not_allowed') {
      const pendingRoomId = normalizeRoomId(payload?.details?.pending_room_id || desiredRoomId.value);
      if (!tryDirectJoinWithModeratorBypass(pendingRoomId)) {
        setAdmissionGate(pendingRoomId);
        requestLobbyJoin(pendingRoomId, { announce: false });
      }
      return;
    }
    if (shouldSuppressExpectedSignalingError(payload)) {
      scheduleNativeOfferRetryForUserId(failedTargetUserId, 'signaling_publish_retry');
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
    const { response } = await fetchBackend('/api/auth/session-state', {
      method: 'GET',
      headers: requestHeaders(false),
    });

    let payload = null;
    try {
      payload = await response.json();
    } catch {
      payload = null;
    }

    const sessionProbeState = String(payload?.result?.state || '').trim().toLowerCase();
    if (response.ok && payload && payload.status === 'ok' && sessionProbeState === 'authenticated') {
      return {
        ok: true,
        state: 'online',
        reason: 'ready',
        message: '',
      };
    }

    if (response.ok && payload && payload.status === 'ok' && sessionProbeState === 'unauthenticated') {
      const failureReason = String(payload?.result?.reason || 'invalid_session').trim().toLowerCase();
      return {
        ok: false,
        state: 'expired',
        reason: failureReason,
        message: 'Session is no longer valid.',
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
  clearAdmissionGate();
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
    if (sessionProbe.state === 'retrying') {
      // A transient HTTP probe failure must not block realtime; the WS handshake
      // validates the same session token and will close explicitly if it is invalid.
      workspaceNotice.value = '';
      workspaceError.value = '';
    } else {
      connectionState.value = sessionProbe.state;
      setNotice(sessionProbe.message, 'error');
      return;
    }
  }

  const orderedSocketOrigins = resolveBackendWebSocketOriginCandidates();
  if (orderedSocketOrigins.length === 0) {
    connectionState.value = 'blocked';
    connectionReason.value = 'secure_transport_required';
    setNotice('Secure WebSocket transport is required. Configure HTTPS/WSS backend origins.', 'error');
    return;
  }

  const connectWithOriginAt = (originIndex) => {
    if (generation !== connectGeneration || manualSocketClose) return;
    if (originIndex >= orderedSocketOrigins.length) {
      connectionState.value = 'retrying';
      connectionReason.value = 'socket_unreachable';
      scheduleReconnect();
      return;
    }

    const socketOrigin = orderedSocketOrigins[originIndex] || '';
    const socketUrl = socketUrlForRoom(desiredRoomId.value, socketOrigin, activeSocketCallId.value);
    if (!socketUrl) {
      connectWithOriginAt(originIndex + 1);
      return;
    }
    const socket = new WebSocket(socketUrl);
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
  if (callEntryMode === 'invite' && isGuestSession()) {
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
  chatAttachmentDrafts.value = [];
  chatAttachmentError.value = '';
  chatAttachmentDragActive.value = false;
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
    nextTick(() => renderCallVideoLayout());
    if (!isNativeWebRtcRuntimePath()) return;
    syncNativePeerConnectionsWithRoster();
  }
);

watch(
  () => [
    currentLayoutMode.value,
    primaryVideoUserId.value,
    miniVideoParticipants.value
      .map((row) => Number(row?.userId || 0))
      .filter((userId) => Number.isInteger(userId) && userId > 0)
      .join(','),
    gridVideoParticipants.value
      .map((row) => Number(row?.userId || 0))
      .filter((userId) => Number.isInteger(userId) && userId > 0)
      .join(','),
  ].join('|'),
  () => {
    nextTick(() => renderCallVideoLayout());
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
    void reconfigureLocalBackgroundFilterOnly();
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
    if (activeTab.value === 'lobby') {
      setActiveTab('users');
    }
    hideLobbyJoinToast();
  }
});

watch(isSocketOnline, (online) => {
  if (!online) return;
  flushQueuedReactions();
  publishLocalActivitySample(true);
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
  await loadDynamicIceServers();
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
  if (!shouldConnectSfu.value) return;

  const socketCallId = activeSocketCallId.value;
  if (socketCallId === '') return;

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
      if (!shouldConnectSfu.value) {
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
    activeRoomId.value,
    socketCallId
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

function normalizeSfuPublisherId(publisherId) {
  return String(publisherId || '').trim();
}

function setSfuRemotePeer(publisherId, peer) {
  const normalizedPublisherId = normalizeSfuPublisherId(publisherId);
  if (normalizedPublisherId === '') return;
  const nextPeers = new Map(remotePeersRef.value);
  nextPeers.set(normalizedPublisherId, peer);
  remotePeersRef.value = nextPeers;
}

function deleteSfuRemotePeer(publisherId) {
  const normalizedPublisherId = normalizeSfuPublisherId(publisherId);
  if (normalizedPublisherId === '') return false;
  if (!remotePeersRef.value.has(normalizedPublisherId)) return false;
  const nextPeers = new Map(remotePeersRef.value);
  nextPeers.delete(normalizedPublisherId);
  remotePeersRef.value = nextPeers;
  pendingSfuRemotePeerInitializers.delete(normalizedPublisherId);
  return true;
}

function sfuTrackRows(tracks) {
  return Array.isArray(tracks) ? tracks : [];
}

async function createOrUpdateSfuRemotePeer(options = {}) {
  if (!isWlvcRuntimePath()) return null;
  const publisherId = normalizeSfuPublisherId(options.publisherId);
  const publisherUserId = Number(options.publisherUserId || 0);
  if (publisherId === '') return null;
  if (Number.isInteger(publisherUserId) && publisherUserId === currentUserId.value) {
    return null;
  }

  const tracks = sfuTrackRows(options.tracks);
  const existingPeer = remotePeersRef.value.get(publisherId);
  if (existingPeer?.decoder) {
    const updatedPeer = {
      ...existingPeer,
      userId: Number.isInteger(publisherUserId) && publisherUserId > 0
        ? publisherUserId
        : Number(existingPeer.userId || 0),
      displayName: String(options.publisherName || existingPeer.displayName || '').trim(),
      tracks,
    };
    setSfuRemotePeer(publisherId, updatedPeer);
    await nextTick();
    renderCallVideoLayout();
    return updatedPeer;
  }

  let decoder = null;
  if (isWlvcRuntimePath() && mediaRuntimeCapabilities.value.stageA) {
    try {
      decoder = await createWasmDecoder({ width: 640, height: 480, quality: 75 });
    } catch (error) {
      mediaDebugLog('[SFU] WASM decoder init failed for publisher', publisherId, error);
    }
  }

  if (!decoder) {
    void maybeFallbackToNativeRuntime('wlvc_decoder_unavailable');
    return null;
  }

  const canvas = document.createElement('canvas');
  canvas.width = 640;
  canvas.height = 480;
  canvas.className = 'remote-video';
  canvas.dataset.publisherId = publisherId;
  if (Number.isInteger(publisherUserId) && publisherUserId > 0) {
    canvas.dataset.userId = String(publisherUserId);
  }

  if (existingPeer) {
    teardownRemotePeer(existingPeer);
  }

  const peer = {
    userId: Number.isInteger(publisherUserId) && publisherUserId > 0 ? publisherUserId : 0,
    displayName: String(options.publisherName || '').trim(),
    pc: null,
    video: null,
    tracks,
    stream: null,
    decoder,
    decodedCanvas: canvas,
  };
  setSfuRemotePeer(publisherId, peer);

  await nextTick();
  renderCallVideoLayout();
  mediaDebugLog('[SFU] Subscribed to publisher', publisherId, 'with', tracks.length, 'tracks');
  return peer;
}

function ensureSfuRemotePeerForFrame(frame) {
  const publisherId = normalizeSfuPublisherId(frame?.publisherId);
  if (publisherId === '') return null;
  const existingPeer = remotePeersRef.value.get(publisherId);
  if (existingPeer?.decoder) return Promise.resolve(existingPeer);
  const pending = pendingSfuRemotePeerInitializers.get(publisherId);
  if (pending) return pending;

  const trackId = String(frame?.trackId || '').trim();
  const init = createOrUpdateSfuRemotePeer({
    publisherId,
    publisherUserId: frame?.publisherUserId,
    publisherName: '',
    tracks: trackId === '' ? [] : [{ id: trackId, kind: 'video', label: 'Remote video' }],
  })
    .catch((error) => {
      mediaDebugLog('[SFU] Could not create peer from frame', publisherId, error);
      return null;
    })
    .finally(() => {
      pendingSfuRemotePeerInitializers.delete(publisherId);
    });
  pendingSfuRemotePeerInitializers.set(publisherId, init);
  return init;
}

function updateSfuRemotePeerUserId(publisherId, peer, publisherUserId) {
  if (!peer || typeof peer !== 'object') return peer;
  const normalizedUserId = Number(publisherUserId || 0);
  if (!Number.isInteger(normalizedUserId) || normalizedUserId <= 0) return peer;
  if (Number(peer?.userId || 0) === normalizedUserId) return peer;
  const updatedPeer = {
    ...peer,
    userId: normalizedUserId,
  };
  if (updatedPeer.decodedCanvas instanceof HTMLElement) {
    updatedPeer.decodedCanvas.dataset.userId = String(normalizedUserId);
  }
  setSfuRemotePeer(publisherId, updatedPeer);
  return updatedPeer;
}

function handleSFUTracks(e) {
  if (!isWlvcRuntimePath()) {
    return;
  }
  const publisherId = normalizeSfuPublisherId(e?.publisherId);
  if (publisherId === '') return;
  const init = createOrUpdateSfuRemotePeer({
    publisherId,
    publisherUserId: e?.publisherUserId,
    publisherName: e?.publisherName,
    tracks: e?.tracks,
  }).catch((error) => {
    mediaDebugLog('[SFU] Could not subscribe to publisher', publisherId, error);
    return null;
  });
  pendingSfuRemotePeerInitializers.set(
    publisherId,
    init.finally(() => pendingSfuRemotePeerInitializers.delete(publisherId))
  );
}

function handleSFUUnpublished(publisherId, trackId) {
  const normalizedPublisherId = normalizeSfuPublisherId(publisherId);
  const peer = remotePeersRef.value.get(normalizedPublisherId);
  if (peer) {
    teardownRemotePeer(peer);
    deleteSfuRemotePeer(normalizedPublisherId);
    renderCallVideoLayout();
  }
}

function handleSFUPublisherLeft(publisherId) {
  const normalizedPublisherId = normalizeSfuPublisherId(publisherId);
  const peer = remotePeersRef.value.get(normalizedPublisherId);
  if (peer) {
    teardownRemotePeer(peer);
    deleteSfuRemotePeer(normalizedPublisherId);
    renderCallVideoLayout();
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
const NATIVE_OFFER_RETRY_DELAYS_MS = [800, 1_500, 2_500, 4_000, 6_000];

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
  remotePeersRef.value = new Map();
  pendingSfuRemotePeerInitializers.clear();
  remoteFrameActivityLastByUserId.clear();
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

function remotePeerMediaNode(peer) {
  if (!peer || typeof peer !== 'object') return null;
  if (peer.decodedCanvas instanceof HTMLCanvasElement) return peer.decodedCanvas;
  if (peer.video instanceof HTMLVideoElement) return peer.video;
  return null;
}

function mediaNodeForUserId(userId) {
  const normalizedUserId = Number(userId);
  if (!Number.isInteger(normalizedUserId) || normalizedUserId <= 0) return null;
  if (normalizedUserId === currentUserId.value) {
    return localVideoElement.value instanceof HTMLVideoElement ? localVideoElement.value : null;
  }
  for (const peer of remotePeersRef.value.values()) {
    if (Number(peer?.userId || 0) === normalizedUserId) {
      return remotePeerMediaNode(peer);
    }
  }
  for (const peer of nativePeerConnectionsRef.value.values()) {
    if (Number(peer?.userId || 0) === normalizedUserId) {
      return remotePeerMediaNode(peer);
    }
  }
  return null;
}

function mountVideoNode(target, node, assignedNodes) {
  if (!(target instanceof HTMLElement) || !(node instanceof HTMLElement)) return false;
  assignedNodes.add(node);
  if (node.parentElement !== target || target.children.length !== 1 || target.firstElementChild !== node) {
    target.replaceChildren(node);
  }
  return true;
}

function clearUnassignedChildren(target, assignedNodes) {
  if (!(target instanceof HTMLElement)) return;
  for (const child of Array.from(target.children)) {
    if (child instanceof HTMLElement && assignedNodes.has(child)) continue;
    child.remove();
  }
}

function renderCallVideoLayout() {
  if (typeof document === 'undefined') return;

  const assignedNodes = new Set();
  const localContainer = document.getElementById('local-video-container');
  const remoteContainer = document.getElementById('remote-video-container');
  if (currentLayoutMode.value === 'grid') {
    for (const participant of gridVideoParticipants.value) {
      const userId = Number(participant?.userId || 0);
      const slot = document.getElementById(gridVideoSlotId(userId));
      const node = mediaNodeForUserId(userId);
      if (!mountVideoNode(slot, node, assignedNodes)) {
        clearUnassignedChildren(slot, assignedNodes);
      }
    }
    if (localContainer) clearUnassignedChildren(localContainer, assignedNodes);
    if (remoteContainer) clearUnassignedChildren(remoteContainer, assignedNodes);
  } else {
    const primaryUserId = primaryVideoUserId.value;
    const primaryNode = mediaNodeForUserId(primaryUserId);

    if (primaryUserId === currentUserId.value) {
      mountVideoNode(localContainer, primaryNode, assignedNodes);
    } else {
      mountVideoNode(remoteContainer, primaryNode, assignedNodes);
    }

    for (const participant of miniVideoParticipants.value) {
      const userId = Number(participant?.userId || 0);
      const slot = document.getElementById(miniVideoSlotId(userId));
      const node = mediaNodeForUserId(userId);
      if (!mountVideoNode(slot, node, assignedNodes)) {
        clearUnassignedChildren(slot, assignedNodes);
      }
    }

    clearUnassignedChildren(localContainer, assignedNodes);
    clearUnassignedChildren(remoteContainer, assignedNodes);
  }

  const allRemotePeers = [
    ...remotePeersRef.value.values(),
    ...nativePeerConnectionsRef.value.values(),
  ];
  for (const peer of allRemotePeers) {
    const node = remotePeerMediaNode(peer);
    if (node instanceof HTMLElement && !assignedNodes.has(node)) {
      node.remove();
    }
  }

  applyCallOutputPreferences();
}

function renderNativeRemoteVideos() {
  if (typeof document === 'undefined') return;
  if (!isNativeWebRtcRuntimePath()) return;
  renderCallVideoLayout();
}

function nativeWebRtcConfig() {
  return {
    iceServers: currentNativeIceServers(),
    iceCandidatePoolSize: 4,
  };
}

function normalizeIceServerEntry(value) {
  if (!value || typeof value !== 'object') return null;
  const urlsValue = Array.isArray(value.urls)
    ? value.urls.map((entry) => String(entry || '').trim()).filter(Boolean)
    : String(value.urls || '').trim();
  if ((Array.isArray(urlsValue) && urlsValue.length === 0) || (!Array.isArray(urlsValue) && urlsValue === '')) {
    return null;
  }

  const server = { urls: urlsValue };
  const username = String(value.username || '').trim();
  const credential = String(value.credential || '').trim();
  if (username !== '') server.username = username;
  if (credential !== '') server.credential = credential;
  return server;
}

function currentNativeIceServers() {
  return dynamicIceServers.value.length > 0 ? dynamicIceServers.value : DEFAULT_NATIVE_ICE_SERVERS;
}

async function loadDynamicIceServers(force = false) {
  const token = String(sessionState.sessionToken || '').trim();
  if (token === '') return currentNativeIceServers();

  const nowMs = Date.now();
  if (!force && dynamicIceServers.value.length > 0 && dynamicIceServersExpiresAtMs > nowMs + 60_000) {
    return currentNativeIceServers();
  }
  if (dynamicIceServersPromise) return dynamicIceServersPromise;

  dynamicIceServersPromise = apiRequest('/api/user/media/ice-servers')
    .then((payload) => {
      const result = payload?.result && typeof payload.result === 'object' ? payload.result : {};
      const servers = Array.isArray(result.ice_servers)
        ? result.ice_servers.map(normalizeIceServerEntry).filter(Boolean)
        : [];
      if (servers.length > 0) {
        dynamicIceServers.value = servers;
      }

      const expiresAtMs = Date.parse(String(result.expires_at || ''));
      dynamicIceServersExpiresAtMs = Number.isFinite(expiresAtMs)
        ? expiresAtMs
        : (Date.now() + 30 * 60_000);
      return currentNativeIceServers();
    })
    .catch((error) => {
      mediaDebugLog('[WebRTC] ICE server discovery failed; using static ICE config', error);
      return currentNativeIceServers();
    })
    .finally(() => {
      dynamicIceServersPromise = null;
    });

  return dynamicIceServersPromise;
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

function clearNativeOfferRetry(peer) {
  if (!peer || typeof peer !== 'object') return;
  if (peer.offerRetryTimer !== null && peer.offerRetryTimer !== undefined) {
    clearTimeout(peer.offerRetryTimer);
  }
  peer.offerRetryTimer = null;
}

function resetNativeOfferRetry(peer) {
  if (!peer || typeof peer !== 'object') return;
  clearNativeOfferRetry(peer);
  peer.offerRetryCount = 0;
}

function nativePeerHasRemoteAnswer(peer) {
  return Boolean(peer?.pc?.remoteDescription?.type);
}

function nativePeerConnectionIsFinal(peer) {
  const state = String(peer?.pc?.connectionState || '').trim().toLowerCase();
  return state === 'connected' || state === 'completed' || state === 'closed';
}

function shouldRetryNativeOffer(peer) {
  if (!peer?.initiator || !peer?.pc) return false;
  if (!isNativeWebRtcRuntimePath()) return false;
  if (nativePeerHasRemoteAnswer(peer)) return false;
  if (nativePeerConnectionIsFinal(peer)) return false;
  const signalingState = String(peer.pc.signalingState || '').trim().toLowerCase();
  return signalingState === 'stable' || signalingState === 'have-local-offer';
}

function scheduleNativeOfferRetry(peer, reason = 'retry') {
  if (!shouldRetryNativeOffer(peer)) return;
  if (peer.offerRetryTimer !== null && peer.offerRetryTimer !== undefined) return;

  const retryCount = Number.isInteger(peer.offerRetryCount) ? peer.offerRetryCount : 0;
  if (retryCount >= NATIVE_OFFER_RETRY_DELAYS_MS.length) return;

  const delayMs = NATIVE_OFFER_RETRY_DELAYS_MS[retryCount] || 6_000;
  peer.offerRetryCount = retryCount + 1;
  peer.offerRetryTimer = setTimeout(() => {
    peer.offerRetryTimer = null;
    if (!shouldRetryNativeOffer(peer)) return;
    mediaDebugLog('[WebRTC] retrying native offer', { userId: peer.userId, reason, retry: peer.offerRetryCount });
    void sendNativeOffer(peer);
  }, delayMs);
}

function scheduleNativeOfferRetryForUserId(userId, reason = 'signaling') {
  const normalizedUserId = Number(userId);
  if (!Number.isInteger(normalizedUserId) || normalizedUserId <= 0) return;
  const peer = nativePeerConnectionsRef.value.get(normalizedUserId);
  if (!peer) return;
  scheduleNativeOfferRetry(peer, reason);
}

function closeNativePeerConnection(targetUserId) {
  const normalizedTargetUserId = Number(targetUserId);
  if (!Number.isInteger(normalizedTargetUserId) || normalizedTargetUserId <= 0) return;
  const peer = nativePeerConnectionsRef.value.get(normalizedTargetUserId);
  if (!peer) return;

  nativePeerConnectionsRef.value.delete(normalizedTargetUserId);
  clearNativeOfferRetry(peer);
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
    let local = peer.pc.localDescription;
    if (!(peer.pc.signalingState === 'have-local-offer' && local?.type === 'offer' && local?.sdp)) {
      await syncNativePeerLocalTracks(peer);
      const offer = await peer.pc.createOffer();
      await peer.pc.setLocalDescription(offer);
      local = peer.pc.localDescription;
    }
    if (!local || !local.sdp) return;

    const sent = sendSocketFrame({
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
    peer.lastOfferSentAtMs = Date.now();
    if (sent) {
      scheduleNativeOfferRetry(peer, 'offer_unanswered');
    } else {
      scheduleNativeOfferRetry(peer, 'socket_not_ready');
    }
  } catch (error) {
    mediaDebugLog('[WebRTC] Could not create/send offer for peer', peer.userId, error);
    scheduleNativeOfferRetry(peer, 'offer_failed');
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
  if (existing) {
    scheduleNativeOfferRetry(existing, 'peer_roster_sync');
    return existing;
  }

  const pc = new RTCPeerConnection(nativeWebRtcConfig());
  const remoteStream = new MediaStream();
  const video = createNativePeerVideoElement(normalizedTargetUserId);
  video.srcObject = remoteStream;

  const peer = {
    userId: normalizedTargetUserId,
    initiator: currentUserId.value > 0 && currentUserId.value < normalizedTargetUserId,
    negotiating: false,
    needsRenegotiate: false,
    offerRetryCount: 0,
    offerRetryTimer: null,
    lastOfferSentAtMs: 0,
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
    if (video instanceof HTMLVideoElement) {
      video.play().catch(() => {});
    }
  });

  pc.addEventListener('connectionstatechange', () => {
    const state = String(pc.connectionState || '').toLowerCase();
    if (state === 'connected' || state === 'completed') {
      resetNativeOfferRetry(peer);
      return;
    }
    if (state === 'closed') {
      closeNativePeerConnection(normalizedTargetUserId);
      return;
    }
    if (state === 'failed') {
      closeNativePeerConnection(normalizedTargetUserId);
      setTimeout(() => {
        if (!isNativeWebRtcRuntimePath()) return;
        syncNativePeerConnectionsWithRoster();
      }, 250);
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
  await loadDynamicIceServers();
  const peer = ensureNativePeerConnection(senderUserId);
  if (!peer?.pc) return;

  const sdpPayload = payloadBody && typeof payloadBody.sdp === 'object' ? payloadBody.sdp : null;
  const type = String(sdpPayload?.type || '').trim().toLowerCase();
  const sdp = String(sdpPayload?.sdp || '').trim();
  if (type !== 'offer' || sdp === '') return;

  try {
    resetNativeOfferRetry(peer);
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
    resetNativeOfferRetry(peer);
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
    setMediaRuntimePath(normalizedNextPath, reason);

    if (normalizedNextPath === 'webrtc_native') {
      stopLocalEncodingPipeline();
      teardownSfuRemotePeers();
      if (!(localStreamRef.value instanceof MediaStream)) {
        await publishLocalTracks();
      } else {
        const videoTrack = localStreamRef.value.getVideoTracks?.()[0] || null;
        if (videoTrack) {
          await startEncodingPipeline(videoTrack);
        }
      }
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

function stopActivityMonitor() {
  if (activityMonitorTimer !== null) {
    clearInterval(activityMonitorTimer);
    activityMonitorTimer = null;
  }
  if (activityAudioContext && typeof activityAudioContext.close === 'function') {
    activityAudioContext.close().catch(() => {});
  }
  activityAudioContext = null;
  activityAudioAnalyser = null;
  activityAudioData = null;
  activityMotionCanvas = null;
  activityMotionContext = null;
  activityPreviousFrame = null;
  activityLastPublishMs = 0;
  activityLastMotionSampleMs = 0;
  activityLastMotionScore = 0;
}

function startActivityMonitor(stream) {
  stopActivityMonitor();
  if (!(stream instanceof MediaStream) || typeof window === 'undefined') return;

  const audioTrack = stream.getAudioTracks?.()[0] || null;
  const AudioContextCtor = window.AudioContext || window.webkitAudioContext;
  if (audioTrack && AudioContextCtor) {
    try {
      activityAudioContext = new AudioContextCtor();
      const source = activityAudioContext.createMediaStreamSource(new MediaStream([audioTrack]));
      activityAudioAnalyser = activityAudioContext.createAnalyser();
      activityAudioAnalyser.fftSize = 512;
      activityAudioData = new Uint8Array(activityAudioAnalyser.fftSize);
      source.connect(activityAudioAnalyser);
    } catch {
      activityAudioContext = null;
      activityAudioAnalyser = null;
      activityAudioData = null;
    }
  }

  if (typeof document !== 'undefined') {
    activityMotionCanvas = document.createElement('canvas');
    activityMotionCanvas.width = 96;
    activityMotionCanvas.height = 72;
    activityMotionContext = activityMotionCanvas.getContext('2d', { willReadFrequently: true });
  }

  activityMonitorTimer = setInterval(() => {
    publishLocalActivitySample();
  }, 200);
}

function sampleLocalAudioLevel() {
  if (!activityAudioAnalyser || !(activityAudioData instanceof Uint8Array)) return 0;
  if (activityAudioContext?.state === 'suspended' && typeof activityAudioContext.resume === 'function') {
    activityAudioContext.resume().catch(() => {});
  }
  try {
    activityAudioAnalyser.getByteTimeDomainData(activityAudioData);
  } catch {
    return 0;
  }

  let sum = 0;
  for (const value of activityAudioData) {
    const centered = (value - 128) / 128;
    sum += centered * centered;
  }
  const rms = Math.sqrt(sum / Math.max(1, activityAudioData.length));
  return Math.max(0, Math.min(1, rms * 3.2));
}

function sampleLocalMotionScore(nowMs) {
  if ((nowMs - activityLastMotionSampleMs) < ACTIVITY_MOTION_SAMPLE_MS) {
    return activityLastMotionScore;
  }
  activityLastMotionSampleMs = nowMs;

  const video = localVideoElement.value;
  if (!(video instanceof HTMLVideoElement) || video.readyState < 2 || !activityMotionContext || !activityMotionCanvas) {
    return 0;
  }

  try {
    activityMotionContext.drawImage(video, 0, 0, activityMotionCanvas.width, activityMotionCanvas.height);
    const frame = activityMotionContext.getImageData(0, 0, activityMotionCanvas.width, activityMotionCanvas.height).data;
    if (!(activityPreviousFrame instanceof Uint8ClampedArray)) {
      activityPreviousFrame = new Uint8ClampedArray(frame);
      activityLastMotionScore = 0;
      return 0;
    }

    let diff = 0;
    for (let index = 0; index < frame.length; index += 16) {
      diff += Math.abs(frame[index] - activityPreviousFrame[index]);
      diff += Math.abs(frame[index + 1] - activityPreviousFrame[index + 1]);
      diff += Math.abs(frame[index + 2] - activityPreviousFrame[index + 2]);
    }
    activityPreviousFrame = new Uint8ClampedArray(frame);
    const samples = Math.max(1, frame.length / 16);
    activityLastMotionScore = Math.max(0, Math.min(1, diff / (samples * 255 * 3) * 5));
    return activityLastMotionScore;
  } catch {
    return activityLastMotionScore;
  }
}

function publishLocalActivitySample(force = false) {
  const nowMs = Date.now();
  if (!force && (nowMs - activityLastPublishMs) < ACTIVITY_PUBLISH_INTERVAL_MS) return;
  if (!isSocketOnline.value || currentUserId.value <= 0 || activeSocketCallId.value === '') return;

  const audioLevel = controlState.micEnabled ? sampleLocalAudioLevel() : 0;
  const motionScore = controlState.cameraEnabled ? sampleLocalMotionScore(nowMs) : 0;
  const speaking = audioLevel >= 0.08;
  const gesture = controlState.handRaised || motionScore >= 0.7 ? 'wave' : '';
  if (!force && audioLevel < 0.03 && motionScore < 0.04 && !gesture) return;

  activityLastPublishMs = nowMs;
  markParticipantActivity(currentUserId.value, speaking ? 'speaking' : (motionScore > 0.04 ? 'motion' : 'control'), nowMs);
  sendSocketFrame({
    type: 'participant/activity',
    user_id: currentUserId.value,
    audio_level: Number(audioLevel.toFixed(4)),
    speaking,
    motion_score: Number(motionScore.toFixed(4)),
    gesture,
    source: 'client_observed',
  });
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

function isBackgroundFilterEnabledForOutgoing() {
  const mode = String(callMediaPrefs.backgroundFilterMode || 'off').trim().toLowerCase();
  return mode === 'blur' && Boolean(callMediaPrefs.backgroundApplyOutgoing);
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
  const maxProcessWidth = Math.max(320, Math.min(processWidthCap, requestedProcessWidth));
  const maxProcessFps = Math.max(8, Math.min(processFpsCap, requestedProcessFps));

  return {
    mode,
    blurPx,
    detectIntervalMs,
    temporalSmoothingAlpha,
    preferFastMatte: qualityProfile !== 'quality',
    maskVariant,
    transitionGain,
    maxProcessWidth,
    maxProcessFps,
    autoDisableOnOverload: false,
    overloadFrameMs: 48,
    overloadConsecutiveFrames: 6,
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

function queueLocalTrackReconfigure(mode = 'devices') {
  const normalizedMode = mode === 'filter' ? 'filter' : 'devices';
  if (localTrackReconfigureQueuedMode === 'devices') return;
  localTrackReconfigureQueuedMode = normalizedMode;
}

function consumeQueuedLocalTrackReconfigureMode() {
  const queuedMode = localTrackReconfigureQueuedMode;
  localTrackReconfigureQueuedMode = null;
  return queuedMode;
}

function applyControlStateToLocalTracks(tracks) {
  if (!Array.isArray(tracks)) return;
  for (const track of tracks) {
    if (!track) continue;
    if (track.kind === 'video') {
      track.enabled = controlState.cameraEnabled;
      continue;
    }
    if (track.kind === 'audio') {
      track.enabled = controlState.micEnabled;
    }
  }
}

function unpublishSfuTracks(tracks) {
  if (!sfuClientRef.value || !Array.isArray(tracks)) return;
  for (const track of tracks) {
    if (!track?.id) continue;
    try {
      sfuClientRef.value.unpublishTrack(track.id);
    } catch {
      // best-effort cleanup for stale tracks
    }
  }
}

function stopRetiredLocalStreams(retiredStreams, preservedStreams = []) {
  const preserved = new Set();
  for (const stream of preservedStreams) {
    if (stream instanceof MediaStream) {
      preserved.add(stream);
    }
  }

  for (const stream of uniqueLocalStreams(retiredStreams)) {
    if (!(stream instanceof MediaStream)) continue;
    if (preserved.has(stream)) continue;
    for (const track of stream.getTracks()) {
      try {
        track.stop();
      } catch {
        // ignore stop failures during stream turnover
      }
    }
  }
}

function clearLocalTrackRecoveryTimer() {
  if (localTrackRecoveryTimer !== null) {
    clearTimeout(localTrackRecoveryTimer);
    localTrackRecoveryTimer = null;
  }
}

function hasLiveLocalMedia() {
  const stream = localStreamRef.value instanceof MediaStream ? localStreamRef.value : null;
  if (!(stream instanceof MediaStream)) return false;
  return stream.getTracks().some((track) => String(track?.readyState || '').trim().toLowerCase() === 'live');
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
  renderCallVideoLayout();
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
  stopActivityMonitor();
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
    applyControlStateToLocalTracks(localTracksRef.value);
    startActivityMonitor(stream);
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

  let video = localVideoElement.value;
  if (!(video instanceof HTMLVideoElement)) {
    video = document.createElement('video');
    video.muted = true;
    video.playsInline = true;
    video.autoplay = true;
    localVideoElement.value = video;
  }
  video.srcObject = new MediaStream([videoTrack]);
  const container = document.getElementById('local-video-container');
  if (container && video.parentElement !== container) {
    container.replaceChildren(video);
  }
  try {
    await video.play();
  } catch {
    // keep preview node mounted even when autoplay policy blocks playback.
  }
  renderCallVideoLayout();
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
        publisherUserId: String(sessionState.userId),
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

async function reconfigureLocalBackgroundFilterOnly() {
  const rawStream = localRawStreamRef.value instanceof MediaStream
    ? localRawStreamRef.value
    : null;
  if (!(rawStream instanceof MediaStream)) {
    return reconfigureLocalTracksFromSelectedDevices();
  }
  if (!(localStreamRef.value instanceof MediaStream)) {
    return publishLocalTracks();
  }
  if (localTrackReconfigureInFlight) {
    queueLocalTrackReconfigure('filter');
    return false;
  }

  localTrackReconfigureInFlight = true;
  try {
    const previousFilteredStream = localFilteredStreamRef.value instanceof MediaStream
      ? localFilteredStreamRef.value
      : null;
    const previousOutputStream = localStreamRef.value instanceof MediaStream
      ? localStreamRef.value
      : null;
    const previousTracks = Array.isArray(localTracksRef.value) ? [...localTracksRef.value] : [];

    const nextStream = await applyLocalBackgroundFilter(rawStream);
    if (!(nextStream instanceof MediaStream)) {
      return false;
    }

    const streamChanged = nextStream !== previousOutputStream;
    localFilteredStreamRef.value = nextStream;
    localStreamRef.value = nextStream;
    localTracksRef.value = nextStream.getTracks();
    applyControlStateToLocalTracks(localTracksRef.value);
    startActivityMonitor(nextStream);
    bindLocalTrackLifecycle(nextStream);

    if (streamChanged) {
      localTracksPublishedToSfu = false;
      unpublishSfuTracks(previousTracks);
      publishLocalTracksToSfuIfReady();

      if (isNativeWebRtcRuntimePath()) {
        syncNativePeerConnectionsWithRoster();
        for (const peer of nativePeerConnectionsRef.value.values()) {
          await syncNativePeerLocalTracks(peer);
        }
      }

      const videoTrack = nextStream.getVideoTracks()[0] || null;
      if (videoTrack) {
        await startEncodingPipeline(videoTrack);
      }
      stopRetiredLocalStreams(
        [previousOutputStream, previousFilteredStream],
        [nextStream, rawStream],
      );
    }

    if (!isBackgroundFilterEnabledForOutgoing()) {
      backgroundFilterController.dispose();
    }

    applyCallInputPreferences();
    applyCallOutputPreferences();
    localTrackRecoveryAttempts = 0;
    return true;
  } catch {
    return false;
  } finally {
    localTrackReconfigureInFlight = false;
    const queuedMode = consumeQueuedLocalTrackReconfigureMode();
    if (queuedMode === 'devices') {
      void reconfigureLocalTracksFromSelectedDevices();
    } else if (queuedMode === 'filter') {
      void reconfigureLocalBackgroundFilterOnly();
    }
  }
}

async function reconfigureLocalTracksFromSelectedDevices() {
  if (!(localStreamRef.value instanceof MediaStream)) {
    return publishLocalTracks();
  }
  if (localTrackReconfigureInFlight) {
    queueLocalTrackReconfigure('devices');
    return false;
  }

  localTrackReconfigureInFlight = true;
  let nextRawStream = null;
  let nextOutputStream = null;
  try {
    const previousRawStream = localRawStreamRef.value instanceof MediaStream
      ? localRawStreamRef.value
      : null;
    const previousFilteredStream = localFilteredStreamRef.value instanceof MediaStream
      ? localFilteredStreamRef.value
      : null;
    const previousOutputStream = localStreamRef.value instanceof MediaStream
      ? localStreamRef.value
      : null;
    const previousTracks = Array.isArray(localTracksRef.value) ? [...localTracksRef.value] : [];

    nextRawStream = await acquireLocalMediaStreamWithFallback();
    nextOutputStream = await applyLocalBackgroundFilter(nextRawStream);
    if (!(nextOutputStream instanceof MediaStream)) {
      stopRetiredLocalStreams([nextRawStream], []);
      return false;
    }

    localRawStreamRef.value = nextRawStream;
    localFilteredStreamRef.value = nextOutputStream;
    localStreamRef.value = nextOutputStream;
    localTracksRef.value = nextOutputStream.getTracks();
    applyControlStateToLocalTracks(localTracksRef.value);
    startActivityMonitor(nextOutputStream);
    bindLocalTrackLifecycle(nextOutputStream);
    localTracksPublishedToSfu = false;

    unpublishSfuTracks(previousTracks);
    publishLocalTracksToSfuIfReady();
    applyCallInputPreferences();
    applyCallOutputPreferences();

    if (isNativeWebRtcRuntimePath()) {
      syncNativePeerConnectionsWithRoster();
      for (const peer of nativePeerConnectionsRef.value.values()) {
        await syncNativePeerLocalTracks(peer);
      }
    }

    const videoTrack = nextOutputStream.getVideoTracks()[0] || null;
    if (videoTrack) {
      await startEncodingPipeline(videoTrack);
    }

    stopRetiredLocalStreams(
      [previousOutputStream, previousRawStream, previousFilteredStream],
      [nextOutputStream, nextRawStream],
    );

    if (!isBackgroundFilterEnabledForOutgoing()) {
      backgroundFilterController.dispose();
    }

    await refreshCallMediaDevices();
    localTrackRecoveryAttempts = 0;
    return true;
  } catch {
    stopRetiredLocalStreams(
      [nextOutputStream, nextRawStream],
      [localStreamRef.value, localRawStreamRef.value],
    );
    scheduleLocalTrackRecovery('reconfigure_failed');
    return false;
  } finally {
    localTrackReconfigureInFlight = false;
    const queuedMode = consumeQueuedLocalTrackReconfigureMode();
    if (queuedMode === 'devices') {
      void reconfigureLocalTracksFromSelectedDevices();
    } else if (queuedMode === 'filter') {
      void reconfigureLocalBackgroundFilterOnly();
    }
  }
}

function markRemoteFrameActivity(publisherUserId) {
  const normalizedUserId = Number(publisherUserId || 0);
  if (!Number.isInteger(normalizedUserId) || normalizedUserId <= 0) return;
  const nowMs = Date.now();
  const lastMarkedMs = Number(remoteFrameActivityLastByUserId.get(normalizedUserId) || 0);
  if ((nowMs - lastMarkedMs) < REMOTE_FRAME_ACTIVITY_MARK_INTERVAL_MS) return;
  remoteFrameActivityLastByUserId.set(normalizedUserId, nowMs);
  markParticipantActivity(normalizedUserId, 'media_frame', nowMs);
}

function decodeSfuFrameForPeer(publisherId, peer, frame) {
  if (!peer || !peer.decoder) return;
  const publisherUserId = Number(frame?.publisherUserId || peer?.userId || 0);
  if (Number.isInteger(publisherUserId) && publisherUserId > 0) {
    markRemoteFrameActivity(publisherUserId);
  }

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
      if (!(canvas.parentElement instanceof HTMLElement)) {
        renderCallVideoLayout();
      }
    }
  } catch (e) {
    mediaDebugLog('[SFU] Decode error:', e);
  }
}

function handleSFUEncodedFrame(frame) {
  if (!isWlvcRuntimePath()) return;
  const publisherId = normalizeSfuPublisherId(frame?.publisherId);
  if (publisherId === '') return;
  let peer = remotePeersRef.value.get(publisherId);
  peer = updateSfuRemotePeerUserId(publisherId, peer, frame?.publisherUserId);
  const publisherUserId = Number(frame?.publisherUserId || peer?.userId || 0);
  if (Number.isInteger(publisherUserId) && publisherUserId > 0) {
    markRemoteFrameActivity(publisherUserId);
  }
  if (!peer || !peer.decoder) {
    const init = ensureSfuRemotePeerForFrame(frame);
    if (init) {
      void init.then((createdPeer) => {
        const nextPeer = updateSfuRemotePeerUserId(
          publisherId,
          createdPeer || remotePeersRef.value.get(publisherId),
          frame?.publisherUserId
        );
        decodeSfuFrameForPeer(publisherId, nextPeer, frame);
      });
    }
    return;
  }

  decodeSfuFrameForPeer(publisherId, peer, frame);
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

<style scoped src="./CallWorkspaceStage.css"></style>
<style scoped src="./CallWorkspacePanels.css"></style>
