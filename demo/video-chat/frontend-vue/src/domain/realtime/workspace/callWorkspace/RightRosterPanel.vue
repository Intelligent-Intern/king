<template>
  <section class="tab-panel panel-users right-roster-panel" :class="{ active }">
    <div class="roster-toolbar">
      <button
        class="icon-mini-btn roster-options-toggle"
        type="button"
        :title="t('calls.workspace.roster_action_options')"
        :aria-label="t('calls.workspace.roster_action_options')"
        :aria-expanded="showActionOptions ? 'true' : 'false'"
        aria-controls="right-roster-action-options"
        @click="toggleActionOptions"
      >
        <img src="/assets/orgas/kingrt/icons/gear.png" alt="" />
      </button>
    </div>

    <div
      v-if="showActionOptions"
      id="right-roster-action-options"
      class="roster-action-options"
    >
      <label
        v-for="option in rosterActionOptions"
        :key="option.key"
        class="roster-action-option"
        :class="{ disabled: option.disabled }"
      >
        <input
          type="checkbox"
          :checked="visibleActionSet.has(option.key)"
          :disabled="option.disabled"
          @change="setActionVisible(option.key, $event.target.checked)"
        />
        <span>{{ option.label }}</span>
      </label>
    </div>

    <div class="right-roster-sections" :class="{ 'without-lobby': !showLobby }">
      <section v-if="showLobby" class="roster-section roster-section-lobby" aria-labelledby="right-roster-lobby-title">
        <header class="roster-section-header">
          <strong id="right-roster-lobby-title">{{ t('calls.workspace.lobby') }}</strong>
          <button
            class="icon-mini-btn roster-action-btn"
            type="button"
            :title="t('calls.workspace.allow_all_queued_users')"
            :disabled="!isSocketOnline || !canModerate || lobbyRows.length === 0"
            @click="$emit('allow-all-lobby-users')"
          >
            <img src="/assets/orgas/kingrt/icons/add_to_call.png" :alt="t('calls.workspace.allow_all_queued_users')" />
          </button>
        </header>
        <div v-if="showLobbySearch" class="toolbar roster-search-toolbar">
          <input
            :value="lobbySearch"
            class="search"
            type="search"
            :placeholder="t('calls.workspace.search_lobby')"
            @input="$emit('update:lobbySearch', $event.target.value)"
          />
        </div>

        <ul
          ref="lobbyListEl"
          class="lobby-list roster-list"
          @scroll.passive="$emit('lobby-list-scroll')"
        >
          <li
            v-if="lobbyPageRows.length > 0 && lobbyVirtualWindow.paddingTop > 0"
            class="user-list-spacer"
            :style="{ height: `${lobbyVirtualWindow.paddingTop}px` }"
          ></li>
          <li v-for="row in lobbyVisibleRows" :key="`${row.status}-${row.user_id}`" class="user-row lobby-user-row">
            <div class="user-preview">{{ initials(row.display_name) }}</div>
            <div class="user-main">
              <strong class="user-name">{{ row.display_name }}</strong>
              <span class="user-role">{{ row.status }}</span>
              <span v-if="row.feedback" class="user-feedback">{{ row.feedback }}</span>
            </div>
            <div class="actions-inline roster-row-actions">
              <button
                v-if="visibleActionSet.has('lobbyAllow')"
                class="icon-mini-btn roster-action-btn"
                type="button"
                :title="t('calls.workspace.allow_user')"
                :disabled="!canModerate || row.status !== 'queued' || lobbyActionPending(row.user_id)"
                @click="$emit('allow-lobby-user', row.user_id)"
              >
                <img src="/assets/orgas/kingrt/icons/add_to_call.png" alt="" />
              </button>
              <button
                v-if="visibleActionSet.has('kick')"
                class="icon-mini-btn danger roster-action-btn roster-kick-btn"
                type="button"
                :title="t('calls.workspace.remove_user')"
                :disabled="!canModerate || lobbyActionPending(row.user_id)"
                @click="$emit('remove-lobby-user', row.user_id)"
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
            {{ t('calls.workspace.lobby_empty') }}
          </li>
        </ul>

        <footer v-if="lobbyPageCount > 1" class="footer workspace-tab-footer roster-section-footer">
          <div class="pagination">
            <button
              class="pager-btn pager-icon-btn"
              type="button"
              :disabled="lobbyPage <= 1"
              @click="$emit('go-to-lobby-page', lobbyPage - 1)"
            >
              <img class="pager-icon-img" src="/assets/orgas/kingrt/icons/backward.png" :alt="t('calls.workspace.page_previous_lobby')" />
            </button>
            <div class="page-info">
              {{ t('calls.workspace.lobby_page_info', { page: lobbyPage, pageCount: lobbyPageCount, total: lobbyRows.length }) }}
            </div>
            <button
              class="pager-btn pager-icon-btn"
              type="button"
              :disabled="lobbyPage >= lobbyPageCount"
              @click="$emit('go-to-lobby-page', lobbyPage + 1)"
            >
              <img class="pager-icon-img" src="/assets/orgas/kingrt/icons/forward.png" :alt="t('calls.workspace.page_next_lobby')" />
            </button>
          </div>
        </footer>
      </section>

      <div v-if="showLobby" class="roster-section-divider" aria-hidden="true"></div>

      <section class="roster-section roster-section-users" aria-labelledby="right-roster-users-title">
        <header class="roster-section-header">
          <strong id="right-roster-users-title">{{ t('users.title') }}</strong>
        </header>
        <div v-if="showUsersSearch" class="toolbar roster-search-toolbar">
          <input
            :value="usersSearch"
            class="search"
            type="search"
            :placeholder="t('calls.workspace.search_users')"
            @input="$emit('update:usersSearch', $event.target.value)"
          />
        </div>
        <p v-if="usersSourceMode === 'directory' && usersDirectoryLoading" class="workspace-tab-hint">
          {{ t('calls.workspace.loading_user_directory') }}
        </p>
        <p v-if="usersSourceMode === 'directory' && usersDirectoryPagination.error" class="workspace-tab-hint error">
          {{ usersDirectoryPagination.error }}
        </p>

        <ul
          ref="usersListEl"
          class="user-list roster-list"
          @scroll.passive="$emit('users-list-scroll')"
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
            <div class="actions-inline roster-row-actions">
              <button
                v-if="visibleActionSet.has('mute')"
                class="icon-mini-btn roster-action-btn"
                type="button"
                :title="mutedUsers[row.userId] ? t('calls.workspace.unmute_peer') : t('calls.workspace.mute_peer')"
                :disabled="!canModerate || row.userId === currentUserId || rowActionPending(row.userId) || !row.isRoomMember"
                @click="$emit('toggle-user-muted', row.userId)"
              >
                <img
                  :src="mutedUsers[row.userId]
                    ? '/assets/orgas/kingrt/icons/micoff.png'
                    : '/assets/orgas/kingrt/icons/micon.png'"
                  alt=""
                />
              </button>
              <button
                v-if="visibleActionSet.has('pin')"
                class="icon-mini-btn roster-action-btn"
                type="button"
                :title="pinnedUsers[row.userId] ? t('calls.workspace.unpin_locally') : t('calls.workspace.pin_locally')"
                :disabled="!row.isRoomMember"
                @click="$emit('toggle-pinned', row.userId)"
              >
                <img
                  :src="pinnedUsers[row.userId]
                    ? '/assets/orgas/kingrt/icons/adminon.png'
                    : '/assets/orgas/kingrt/icons/adminoff.png'"
                  alt=""
                />
              </button>
              <button
                v-if="visibleActionSet.has('moderator')"
                class="icon-mini-btn roster-action-btn"
                type="button"
                :title="row.callRole === 'moderator' ? t('calls.workspace.set_participant_role') : t('calls.workspace.set_moderator_role')"
                :disabled="!canModerate || !activeCallId || row.userId === currentUserId || rowActionPending(row.userId) || !row.isRoomMember || row.callRole === 'owner'"
                @click="$emit('toggle-moderator-role', row)"
              >
                <img
                  :src="row.callRole === 'moderator'
                    ? '/assets/orgas/kingrt/icons/adminon.png'
                    : '/assets/orgas/kingrt/icons/adminoff.png'"
                  alt=""
                />
              </button>
              <CallAppParticipantGrantButton
                v-if="visibleActionSet.has('callAppAccess')"
                :session="activeCallAppSession"
                :row="row"
                :can-manage="canModerate"
                :api-request="apiRequest"
                :send-socket-frame="sendSocketFrame"
                :request-room-snapshot="requestRoomSnapshot"
              />
              <CallAppParticipantGrantButton
                v-if="visibleActionSet.has('callAppRead')"
                :session="activeCallAppSession"
                :row="row"
                :can-manage="canModerate"
                :api-request="apiRequest"
                :send-socket-frame="sendSocketFrame"
                :request-room-snapshot="requestRoomSnapshot"
                permission-action="read"
              />
              <CallAppParticipantGrantButton
                v-if="visibleActionSet.has('callAppWrite')"
                :session="activeCallAppSession"
                :row="row"
                :can-manage="canModerate"
                :api-request="apiRequest"
                :send-socket-frame="sendSocketFrame"
                :request-room-snapshot="requestRoomSnapshot"
                permission-action="write"
              />
              <CallAppParticipantGrantButton
                v-if="visibleActionSet.has('callAppDelete')"
                :session="activeCallAppSession"
                :row="row"
                :can-manage="canModerate"
                :api-request="apiRequest"
                :send-socket-frame="sendSocketFrame"
                :request-room-snapshot="requestRoomSnapshot"
                permission-action="delete"
              />
              <button
                v-if="visibleActionSet.has('owner')"
                class="icon-mini-btn roster-action-btn"
                type="button"
                :title="t('calls.workspace.transfer_owner_role')"
                :disabled="!canManageOwnerRole || !activeCallId || rowActionPending(row.userId) || !row.isRoomMember || row.callRole === 'owner'"
                @click="$emit('transfer-owner-role', row)"
              >
                <img src="/assets/orgas/kingrt/icons/forward.png" alt="" />
              </button>
              <button
                v-if="visibleActionSet.has('kick')"
                class="icon-mini-btn danger roster-action-btn roster-kick-btn"
                type="button"
                :title="t('calls.workspace.remove_from_lobby')"
                :disabled="!canModerate || row.userId === currentUserId || rowActionPending(row.userId) || !row.canRemoveFromLobby"
                @click="$emit('remove-lobby-user', row.userId)"
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
            {{ t('calls.workspace.no_users_match') }}
          </li>
        </ul>

        <footer v-if="usersPageCount > 1" class="footer workspace-tab-footer roster-section-footer">
          <div class="pagination">
            <button
              class="pager-btn pager-icon-btn"
              type="button"
              :disabled="usersPage <= 1"
              @click="$emit('go-to-users-page', usersPage - 1)"
            >
              <img class="pager-icon-img" src="/assets/orgas/kingrt/icons/backward.png" :alt="t('calls.workspace.page_previous_users')" />
            </button>
            <div class="page-info">
              {{ t('calls.workspace.users_page_info', {
                page: usersPage,
                pageCount: usersPageCount,
                total: usersTotal,
              }) }}
            </div>
            <button
              class="pager-btn pager-icon-btn"
              type="button"
              :disabled="usersPage >= usersPageCount"
              @click="$emit('go-to-users-page', usersPage + 1)"
            >
              <img class="pager-icon-img" src="/assets/orgas/kingrt/icons/forward.png" :alt="t('calls.workspace.page_next_users')" />
            </button>
          </div>
        </footer>
      </section>
    </div>
  </section>
</template>

<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue';

import CallAppParticipantGrantButton from '../../callApps/CallAppParticipantGrantButton.vue';
import {
  CALL_APP_PERMISSION_ACTIONS,
  supportedCallAppPermissionActions,
} from '../../callApps/callAppParticipantGrantHelpers.js';

const props = defineProps({
  active: { type: Boolean, default: false },
  activeCallAppSession: { type: Object, default: null },
  activeCallId: { type: [Number, String], default: 0 },
  activityLabelForUser: { type: Function, required: true },
  apiRequest: { type: Function, required: true },
  canManageOwnerRole: { type: Boolean, default: false },
  canModerate: { type: Boolean, default: false },
  currentUserId: { type: Number, default: 0 },
  isSocketOnline: { type: Boolean, default: false },
  lobbyActionPending: { type: Function, required: true },
  lobbyPage: { type: Number, default: 1 },
  lobbyPageCount: { type: Number, default: 1 },
  lobbyPageRows: { type: Array, default: () => [] },
  lobbyRows: { type: Array, default: () => [] },
  lobbySearch: { type: String, default: '' },
  lobbyVirtualWindow: { type: Object, required: true },
  lobbyVisibleRows: { type: Array, default: () => [] },
  mutedUsers: { type: Object, default: () => ({}) },
  pinnedUsers: { type: Object, default: () => ({}) },
  requestRoomSnapshot: { type: Function, required: true },
  rowActionPending: { type: Function, required: true },
  sendSocketFrame: { type: Function, required: true },
  setLobbyListElement: { type: Function, required: true },
  setUsersListElement: { type: Function, required: true },
  showLobby: { type: Boolean, default: false },
  showLobbySearch: { type: Boolean, default: false },
  showUsersSearch: { type: Boolean, default: false },
  t: { type: Function, required: true },
  usersDirectoryLoading: { type: Boolean, default: false },
  usersDirectoryPagination: { type: Object, default: () => ({}) },
  usersPage: { type: Number, default: 1 },
  usersPageCount: { type: Number, default: 1 },
  usersPageRows: { type: Array, default: () => [] },
  usersSearch: { type: String, default: '' },
  usersSourceMode: { type: String, default: 'snapshot' },
  usersTotal: { type: Number, default: 0 },
  usersVirtualWindow: { type: Object, required: true },
  usersVisibleRows: { type: Array, default: () => [] },
});

defineEmits([
  'allow-all-lobby-users',
  'allow-lobby-user',
  'go-to-lobby-page',
  'go-to-users-page',
  'lobby-list-scroll',
  'remove-lobby-user',
  'toggle-moderator-role',
  'toggle-pinned',
  'toggle-user-muted',
  'transfer-owner-role',
  'update:lobbySearch',
  'update:usersSearch',
  'users-list-scroll',
]);

const usersListEl = ref(null);
const lobbyListEl = ref(null);
const showActionOptions = ref(false);
const hasActiveCallAppSession = computed(() => (
  String(props.activeCallAppSession?.id || '').trim() !== ''
  && String(props.activeCallAppSession?.status || '').trim().toLowerCase() === 'active'
));
const supportedCallAppPermissions = computed(() => {
  const supported = supportedCallAppPermissionActions(props.activeCallAppSession || {});
  return supported.length > 0 ? supported : (hasActiveCallAppSession.value ? [...CALL_APP_PERMISSION_ACTIONS] : []);
});
const actionVisibility = ref({
  mute: true,
  pin: true,
  moderator: true,
  callAppAccess: true,
  owner: true,
  kick: true,
  lobbyAllow: true,
  callAppRead: true,
  callAppWrite: true,
  callAppDelete: true,
});
const rosterActionOptions = computed(() => {
  const supportedPermissions = new Set(supportedCallAppPermissions.value);
  return [
    { key: 'mute', label: props.t('calls.workspace.action_option_mute') },
    { key: 'pin', label: props.t('calls.workspace.action_option_pin') },
    { key: 'moderator', label: props.t('calls.workspace.action_option_moderator') },
    { key: 'owner', label: props.t('calls.workspace.action_option_owner') },
    { key: 'lobbyAllow', label: props.t('calls.workspace.action_option_lobby_allow') },
    { key: 'kick', label: props.t('calls.workspace.action_option_kick') },
    { key: 'callAppAccess', label: props.t('calls.workspace.action_option_call_app_access') },
    {
      key: 'callAppRead',
      label: props.t('calls.workspace.action_option_call_app_read'),
      disabled: !hasActiveCallAppSession.value || !supportedPermissions.has('read'),
    },
    {
      key: 'callAppWrite',
      label: props.t('calls.workspace.action_option_call_app_write'),
      disabled: !hasActiveCallAppSession.value || !supportedPermissions.has('write'),
    },
    {
      key: 'callAppDelete',
      label: props.t('calls.workspace.action_option_call_app_delete'),
      disabled: !hasActiveCallAppSession.value || !supportedPermissions.has('delete'),
    },
  ];
});
const visibleActionSet = computed(() => {
  const keys = new Set();
  for (const option of rosterActionOptions.value) {
    if (option.disabled) continue;
    if (actionVisibility.value[option.key] === true) keys.add(option.key);
  }
  return keys;
});

function initials(name) {
  return String(name || '?')
    .trim()
    .split(/\s+/)
    .slice(0, 2)
    .map((part) => part.slice(0, 1).toUpperCase())
    .join('') || '?';
}

function toggleActionOptions() {
  showActionOptions.value = !showActionOptions.value;
}

function setActionVisible(key, visible) {
  if (!Object.prototype.hasOwnProperty.call(actionVisibility.value, key)) return;
  actionVisibility.value = {
    ...actionVisibility.value,
    [key]: visible === true,
  };
}

function syncListElements() {
  props.setUsersListElement(usersListEl.value);
  props.setLobbyListElement(lobbyListEl.value);
}

onMounted(() => {
  nextTick(syncListElements);
});

watch(() => props.active, (active) => {
  if (active) nextTick(syncListElements);
});

watch([usersListEl, lobbyListEl], () => {
  nextTick(syncListElements);
});
</script>
