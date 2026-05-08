<template>
  <main class="app">
    <div class="shell no-right-sidebar" :class="shellClasses">
      <aside class="sidebar sidebar-left" :class="leftSidebarClasses">
        <CallWorkspaceLeftSidebar
          v-if="isCallWorkspace"
          :sidebar-logo-src="sidebarLogoSrc"
          :left-sidebar-toggle-label="leftSidebarToggleLabel"
          :left-sidebar-toggle-icon="leftSidebarToggleIcon"
          :is-mobile-viewport="isMobileViewport"
          :call-media-prefs="callMediaPrefs"
          :mic-level-percent="micLevelPercent"
          :show-call-owner-invite-link="showCallOwnerInviteLink"
          :call-owner-invite-link-state="callOwnerInviteLinkState"
          :show-in-call-owner-edit-card="showInCallOwnerEditCard"
          :call-owner-edit-state="callOwnerEditState"
          :call-layout-sidebar-state="callLayoutSidebarState"
          :active-sidebar-call-id="activeSidebarCallId"
          :can-manage-sidebar-call-apps="canManageSidebarCallApps"
          :call-app-sidebar-state="callAppSidebarState"
          :api-request="apiRequest"
          @toggle-sidebar="handleLeftSidebarToggle"
          @set-camera-device="setCallCameraDevice"
          @set-microphone-device="setCallMicrophoneDevice"
          @set-microphone-volume="setCallMicrophoneVolume"
          @set-speaker-device="setCallSpeakerDevice"
          @set-speaker-volume="setCallSpeakerVolume"
          @play-speaker-test="playSpeakerTestSound"
          @copy-invite-link="copyCallOwnerInviteLink"
          @open-edit-modal="openInCallEditModal"
          @apply-layout-mode="applySidebarLayoutMode"
          @apply-layout-strategy="applySidebarLayoutStrategy"
          @call-app-session-created="handleCallAppSessionCreated"
        />

        <WorkspaceStandardSidebar
          v-else
          :sidebar-logo-src="sidebarLogoSrc"
          :left-sidebar-toggle-label="leftSidebarToggleLabel"
          :left-sidebar-toggle-icon="leftSidebarToggleIcon"
          :is-mobile-viewport="isMobileViewport"
          :role="sessionState.role || ''"
          :current-path="route.path"
          :profile-avatar-src="profileAvatarSrc"
          @toggle-sidebar="handleLeftSidebarToggle"
          @navigate="handleNavItemClick"
          @open-settings="openSettingsModal()"
          @sign-out="handleSignOut"
        />
      </aside>

      <section class="main" @click="handleMainClick">
        <div v-if="showMobileShellHeader" class="mobile-brand-strip">
          <img src="/assets/orgas/kingrt/king_logo-withslogan.svg" alt="KingRT" />
          <button class="mobile-menu-btn" type="button" :aria-label="t('common.toggle_menu')" @click.stop="handleLeftSidebarToggle">
            <span class="mobile-menu-btn-bars" aria-hidden="true"></span>
          </button>
        </div>
        <div
          class="workspace"
          :class="{
            'workspace-has-header': showWorkspaceHeader,
            'workspace-no-header': !showWorkspaceHeader,
          }"
        >
          <section v-if="showWorkspaceHeader" class="section">
            <div class="section-head">
              <div class="section-head-left">
                  <button
                    v-if="!isMobileViewport"
                    class="show-sidebar-overlay show-sidebar-inline show-left-sidebar-overlay"
                    type="button"
                    :title="t('common.show_sidebar')"
                    :aria-label="t('common.show_sidebar')"
                    @click="showLeftSidebar"
                  >
                    <img class="arrow-icon-image" src="/assets/orgas/kingrt/icons/forward.png" alt="" />
                  </button>
                <div class="section-head-title">
                  <h1 class="title">{{ pageTitle }}</h1>
                  <p v-if="pageSubtitle" class="subtitle">{{ pageSubtitle }}</p>
                </div>
              </div>
              <div class="actions">
                <button
                  v-if="route.name === 'user-dashboard'"
                  class="btn btn-cyan"
                  type="button"
                  @click="openUserCreateCall"
                >
                  {{ t('common.new_call') }}
                </button>
                <button v-else class="btn" type="button" @click="openSettingsModal()">{{ t('common.settings') }}</button>
              </div>
            </div>
          </section>

          <section class="panel-grid">
            <RouterView />
          </section>
        </div>
      </section>
    </div>
  </main>

  <div class="settings-modal" :hidden="!settingsState.open" role="dialog" aria-modal="true" :aria-label="t('settings.dialog_aria')">
    <div class="settings-backdrop" @click="closeSettingsModal"></div>
    <div
      class="settings-dialog"
      :class="{ 'is-maximized': settingsState.maximized, 'rtl-mode': settingsDraftDirection === 'rtl' }"
      :dir="settingsDraftDirection"
    >
      <header class="settings-header">
        <div class="settings-title-wrap">
          <img :src="modalLogoSrc" alt="" />
          <h3>{{ t('settings.dialog_title') }}</h3>
        </div>
        <div class="settings-header-actions">
          <button
            v-if="isDesktopLikeViewport"
            class="icon-mini-btn"
            type="button"
            :aria-label="settingsState.maximized ? t('settings.restore_size') : t('settings.maximize')"
            :title="settingsState.maximized ? t('settings.restore_size') : t('settings.maximize')"
            @click="toggleSettingsMaximized"
          >
            <img :src="settingsState.maximized ? '/assets/orgas/kingrt/icons/backward.png' : '/assets/orgas/kingrt/icons/forward.png'" alt="" />
          </button>
          <button class="icon-mini-btn" type="button" :aria-label="t('settings.close')" @click="closeSettingsModal">
            <img src="/assets/orgas/kingrt/icons/cancel.png" alt="" />
          </button>
        </div>
      </header>

      <div class="settings-grid" role="tablist" :aria-label="t('settings.category_tabs_aria')">
        <button
          v-for="tile in settingsTiles"
          :key="tile.id"
          class="settings-tile tab"
          :class="{ active: activeSettingsTile === tile.id }"
          type="button"
          :disabled="settingsState.loading"
          @click="activeSettingsTile = tile.id"
        >
          {{ tile.label }}
        </button>
      </div>

      <WorkspaceAboutSettings
        v-if="activeSettingsTile === 'personal.about'"
        :draft="settingsDraft"
        :state="settingsState"
        :avatar-preview-src="settingsAvatarPreviewSrc"
        @avatar-select="handleAvatarSelect"
        @avatar-drop="handleAvatarDrop"
      />

      <WorkspaceCredentialsSettings v-else-if="activeSettingsTile === 'personal.credentials'" />

      <WorkspaceNotificationSettings
        v-else-if="activeSettingsTile === 'personal.notifications'"
        :draft="settingsDraft"
      />

      <section v-else-if="activeSettingsTile === 'personal.theme'" class="settings-panel">
        <WorkspaceThemeSettings
          v-model="settingsDraft.theme"
          :saving="settingsState.saving || settingsState.loading"
          selection-only
        />
      </section>

      <WorkspaceLocalizationSettings
        v-else-if="activeSettingsTile === 'personal.localization'"
        :draft="settingsDraft"
        :language-options="settingsLanguageOptions"
        :date-format-options="dateFormatOptions"
      />

      <section v-else class="settings-panel">
        <div class="settings-upload-status">{{ t('settings.select_tab') }}</div>
      </section>

      <div v-if="activeSettingsTile !== 'personal.credentials'" class="settings-actions">
        <button class="btn" type="button" :disabled="settingsState.saving || settingsState.loading" @click="saveSettings">
          {{ settingsState.saving ? t('settings.saving') : t('settings.save_settings') }}
        </button>
      </div>

      <div v-if="activeSettingsTile !== 'personal.credentials'" class="settings-upload-status">{{ settingsState.message }}</div>
    </div>
  </div>

  <div
    class="call-owner-edit-modal"
    :hidden="!callOwnerEditState.open"
    role="dialog"
    aria-modal="true"
    aria-label="Edit call from workspace"
  >
    <div class="call-owner-edit-backdrop" @click="closeInCallEditModal"></div>
    <div class="call-owner-edit-dialog">
      <header class="call-owner-edit-header">
        <div class="call-owner-edit-title-wrap">
          <img class="call-owner-edit-logo" :src="modalLogoSrc" alt="" />
          <h4>Edit video call</h4>
        </div>
        <button class="icon-mini-btn" type="button" aria-label="Close edit call modal" @click="closeInCallEditModal">
          <img src="/assets/orgas/kingrt/icons/cancel.png" alt="" />
        </button>
      </header>

      <div class="call-owner-edit-body">
        <section class="call-owner-edit-grid">
          <label class="field">
            <span>Title</span>
            <input
              v-model.trim="callOwnerEditState.title"
              class="input"
              type="text"
              placeholder="Weekly Product Sync"
            />
          </label>
          <label class="field">
            <span>Access mode</span>
            <AppSelect v-model="callOwnerEditState.accessMode" aria-label="Call access mode">
              <option value="invite_only">Invite only</option>
              <option value="free_for_all">Free for all</option>
            </AppSelect>
          </label>
          <label class="field">
            <span>Starts at</span>
            <input
              v-model="callOwnerEditState.startsLocal"
              class="input"
              type="datetime-local"
              aria-label="Call starts at"
            />
          </label>
          <label class="field">
            <span>Ends at</span>
            <input
              v-model="callOwnerEditState.endsLocal"
              class="input"
              type="datetime-local"
              aria-label="Call ends at"
            />
          </label>
        </section>

        <section class="call-owner-edit-toggle">
          <label class="call-owner-edit-checkbox">
            <input v-model="callOwnerEditState.replaceParticipants" type="checkbox" />
            <span>Edit participant list</span>
          </label>
        </section>

        <section v-if="callOwnerEditState.replaceParticipants" class="call-owner-participants-grid">
          <article v-if="canLoadCallOwnerInternalDirectory" class="call-owner-participants-panel">
            <header class="call-owner-participants-head">
              <h5>Registered users</h5>
              <label class="call-owner-search" aria-label="Participant search">
                <input
                  v-model.trim="callOwnerParticipants.query"
                  class="input"
                  type="search"
                  placeholder="Search users"
                  @keydown.enter.prevent="applyCallOwnerParticipantSearch"
                />
                <button class="btn" type="button" :disabled="callOwnerParticipants.loading" @click="applyCallOwnerParticipantSearch">
                  Search
                </button>
              </label>
            </header>

            <section v-if="callOwnerParticipants.error" class="call-owner-inline-error">
              {{ callOwnerParticipants.error }}
            </section>

            <section class="call-owner-participants-list" :class="{ loading: callOwnerParticipants.loading }">
              <label
                v-for="user in callOwnerParticipants.rows"
                :key="user.id"
                class="call-owner-participant-row"
              >
                <input
                  type="checkbox"
                  :checked="isCallOwnerUserSelected(user.id)"
                  @change="toggleCallOwnerUserSelection(user.id)"
                />
                <span class="call-owner-participant-main">{{ user.display_name || user.email }}</span>
                <span class="call-owner-participant-meta">{{ user.email }} · {{ user.role }}</span>
              </label>
              <p v-if="!callOwnerParticipants.loading && callOwnerParticipants.rows.length === 0" class="call-owner-empty-inline">
                No users in this page.
              </p>
            </section>

            <div class="pagination">
              <button
                class="pager-btn pager-icon-btn"
                type="button"
                :disabled="!callOwnerParticipants.hasPrev || callOwnerParticipants.loading"
                @click="goToCallOwnerParticipantPage(callOwnerParticipants.page - 1)"
              >
                <img class="pager-icon-img" src="/assets/orgas/kingrt/icons/backward.png" alt="Previous" />
              </button>
              <div class="page-info">Page {{ callOwnerParticipants.page }} / {{ callOwnerParticipants.pageCount }}</div>
              <button
                class="pager-btn pager-icon-btn"
                type="button"
                :disabled="!callOwnerParticipants.hasNext || callOwnerParticipants.loading"
                @click="goToCallOwnerParticipantPage(callOwnerParticipants.page + 1)"
              >
                <img class="pager-icon-img" src="/assets/orgas/kingrt/icons/forward.png" alt="Next" />
              </button>
            </div>
          </article>

          <article v-else class="call-owner-participants-panel">
            <p class="call-owner-inline-hint">
              Internal participants stay unchanged in this editor for non-admin owners.
            </p>
          </article>

          <article class="call-owner-participants-panel">
            <header class="call-owner-participants-head">
              <h5>External participants</h5>
              <button class="btn" type="button" @click="addCallOwnerExternalRow">Add row</button>
            </header>

            <section class="call-owner-external-list">
              <div v-for="(row, index) in callOwnerExternalRows" :key="row.id" class="call-owner-external-row">
                <input
                  v-model.trim="row.display_name"
                  class="input"
                  type="text"
                  placeholder="Display name"
                  :aria-label="`External participant ${index + 1} display name`"
                />
                <input
                  v-model.trim="row.email"
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
                  @click="removeCallOwnerExternalRow(index)"
                >
                  <img src="/assets/orgas/kingrt/icons/remove_user.png" alt="" />
                </button>
              </div>
            </section>
          </article>
        </section>

        <section v-if="callOwnerEditState.error" class="call-owner-inline-error">
          {{ callOwnerEditState.error }}
        </section>
      </div>

      <footer class="call-owner-edit-footer">
        <button class="btn" type="button" :disabled="callOwnerEditState.submitting" @click="closeInCallEditModal">
          Close
        </button>
        <button class="btn" type="button" :disabled="callOwnerEditState.submitting" @click="submitInCallEditModal">
          {{ callOwnerEditState.submitting ? 'Saving…' : 'Save changes' }}
        </button>
      </footer>
    </div>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, provide, reactive, ref, watch } from 'vue';
import { RouterView, useRoute, useRouter } from 'vue-router';
import AppSelect from '../components/AppSelect.vue';
import WorkspaceStandardSidebar from './WorkspaceStandardSidebar.vue';
import WorkspaceAboutSettings from './settings/WorkspaceAboutSettings.vue';
import WorkspaceCredentialsSettings from './settings/WorkspaceCredentialsSettings.vue';
import WorkspaceLocalizationSettings from './settings/WorkspaceLocalizationSettings.vue';
import WorkspaceNotificationSettings from './settings/WorkspaceNotificationSettings.vue';
import WorkspaceThemeSettings from './settings/WorkspaceThemeSettings.vue';
import CallWorkspaceLeftSidebar from './CallWorkspaceLeftSidebar.vue';
import { useWorkspaceShellViewport } from './useWorkspaceShellViewport.js';
import { useWorkspaceModuleStore } from '../stores/workspaceModuleStore.js';
import {
  logoutSession,
  postLogoutRedirectTarget,
  saveSessionSettings,
  sessionState,
  uploadSessionAvatar,
} from '../domain/auth/session';
import { DATE_FORMAT_OPTIONS, normalizeDateFormat, normalizeTimeFormat } from '../support/dateTimeFormat';
import {
  SUPPORTED_LOCALIZATION_LANGUAGES,
  localizationLanguageDirection,
  normalizeLocalizationLanguage,
} from '../support/localizationOptions';
import {
  ensureI18nResources,
  syncI18nDocumentState,
  t,
} from '../modules/localization/i18nRuntime.js';
import { apiRequest } from '../domain/realtime/workspace/api';
import {
  appearanceState,
  loadWorkspaceAppearance,
} from '../domain/workspace/appearance';
import {
  attachCallMediaDeviceWatcher,
  callMediaPrefs,
  setCallCameraDevice,
  setCallMicrophoneDevice,
  setCallMicrophoneVolume,
  setCallSpeakerDevice,
  setCallSpeakerVolume,
} from '../domain/realtime/media/preferences';
import { playCallSpeakerTestSound } from '../domain/realtime/media/speakerOutputRouting';
import { useWorkspaceMicLevelMonitor } from './useWorkspaceMicLevelMonitor';

const router = useRouter();
const route = useRoute();
const moduleStore = useWorkspaceModuleStore();
let detachCallMediaWatcher = null;
const placeholderAvatar = '/assets/orgas/kingrt/avatar-placeholder.svg';
const USER_CALL_CREATE_EVENT = 'king:user-calls:create';
const DEFAULT_SETTINGS_TILE = 'personal.about';

const pageTitle = computed(() => {
  const routeTitleKey = typeof route.meta?.pageTitle_key === 'string' ? route.meta.pageTitle_key.trim() : '';
  if (routeTitleKey !== '') return t(routeTitleKey);

  const routeTitle = typeof route.meta?.pageTitle === 'string' ? route.meta.pageTitle.trim() : '';
  if (routeTitle !== '') return routeTitle;

  const mapping = {
    '/admin/overview': 'page.video_operations',
    '/admin/users': 'users.title',
    '/admin/governance': 'navigation.governance',
    '/admin/governance/users': 'users.title',
    '/admin/administration': 'navigation.administration',
    '/admin/administration/marketplace': 'navigation.administration.marketplace',
    '/admin/administration/localization': 'navigation.administration.localization',
    '/admin/administration/app-configuration': 'navigation.administration.app_configuration',
    '/admin/administration/theme-editor': 'navigation.administration.theme_editor',
    '/admin/calls': 'page.video_call_management',
    '/user/dashboard': 'page.my_video_calls',
  };

  if (route.path.startsWith('/workspace/call')) return t('page.video_call');
  return t(mapping[route.path] || 'page.workspace');
});
const isCallWorkspace = computed(() => route.path.startsWith('/workspace/call'));

const pageSubtitle = computed(() => {
  return '';
});
const showWorkspaceHeader = computed(() => (
  !route.path.startsWith('/admin/governance')
  && !route.path.startsWith('/admin/administration')
  && !['/admin/users', '/admin/calls'].includes(route.path)
  && !isCallWorkspace.value
));

const showMobileShellHeader = computed(() => isMobileViewport.value && !isCallWorkspace.value);

const profileAvatarSrc = computed(() => sessionState.avatarPath || placeholderAvatar);
const sidebarLogoSrc = computed(() => appearanceState.sidebarLogoPath || '/assets/orgas/kingrt/logo.svg');
const modalLogoSrc = computed(() => appearanceState.modalLogoPath || '/assets/orgas/kingrt/logo.svg');
const workspaceThemeOptions = computed(() => (
  appearanceState.themes.length > 0
    ? appearanceState.themes
    : [
        { id: 'dark', label: 'Dark' },
        { id: 'light', label: 'Light' },
      ]
));
const {
  leftSidebarCollapsed,
  isTabletSidebarOpen,
  isTabletViewport,
  isMobileViewport,
  isDesktopLikeViewport,
  leftSidebarToggleIcon,
  leftSidebarToggleLabel,
  shellClasses,
  leftSidebarClasses,
  handleLeftSidebarToggle,
  showLeftSidebar,
  handleMainClick,
  handleNavItemClick,
  closeMobileSidebarForRouteChange,
} = useWorkspaceShellViewport({ isCallWorkspace });
const {
  attachMicLevelStream,
  micLevelPercent,
  startMicLevelMonitor,
  stopMicLevelMonitor,
} = useWorkspaceMicLevelMonitor({ isCallWorkspace, isMobileViewport });

const settingsDraft = reactive({
  displayName: '',
  theme: 'dark',
  timeFormat: '24h',
  dateFormat: 'dmy_dot',
  language: 'en',
  postLogoutLandingUrl: '',
  avatarDataUrl: '',
  aboutMe: '',
  linkedinUrl: '',
  xUrl: '',
  youtubeUrl: '',
  webAppNotificationsEnabled: false,
  webAppNotificationSoundEnabled: true,
  webAppNotificationCallInvitesEnabled: true,
  webAppNotificationCallRemindersEnabled: true,
  webAppNotificationChatMentionsEnabled: true,
});

const settingsState = reactive({
  open: false,
  loading: false,
  saving: false,
  dragging: false,
  maximized: false,
  message: '',
  avatarStatus: '',
});
const activeSettingsTile = ref(DEFAULT_SETTINGS_TILE);
const settingsTiles = computed(() => moduleStore.settingsPanelsFor({ role: sessionState.role }).map((panel) => ({
  id: panel.key,
  label: panel.label_key ? t(panel.label_key) : panel.label,
})));
const dateFormatOptions = DATE_FORMAT_OPTIONS;
const settingsLanguageOptions = computed(() => {
  const backendLocales = Array.isArray(sessionState.supportedLocales) && sessionState.supportedLocales.length > 0
    ? sessionState.supportedLocales
    : SUPPORTED_LOCALIZATION_LANGUAGES;
  return backendLocales.map((language) => ({
    code: normalizeSettingsLanguage(language.code),
    label: String(language.label || language.code || '').trim() || normalizeSettingsLanguage(language.code).toUpperCase(),
    direction: language.direction === 'rtl' ? 'rtl' : localizationLanguageDirection(language.code),
  }));
});
const settingsDraftDirection = computed(() => localizationLanguageDirection(settingsDraft.language));

const settingsAvatarPreviewSrc = computed(() => settingsDraft.avatarDataUrl || profileAvatarSrc.value);

function normalizeSettingsLanguage(value) {
  return normalizeLocalizationLanguage(value);
}

function normalizePostLogoutLandingUrl(value) {
  const url = String(value || '').trim();
  if (url === '') return '';
  if (!url.startsWith('/') || url.startsWith('//') || url.includes('\\')) return null;
  return url;
}

function normalizeRole(value) {
  const role = String(value || '').trim().toLowerCase();
  if (role === 'admin') return 'admin';
  return 'user';
}

function normalizeCallAccessMode(value) {
  const normalized = String(value || '').trim().toLowerCase();
  return normalized === 'free_for_all' ? 'free_for_all' : 'invite_only';
}

function normalizeCallParticipantRole(value) {
  const normalized = String(value || '').trim().toLowerCase();
  return ['owner', 'moderator', 'participant'].includes(normalized) ? normalized : 'participant';
}

function callParticipantRoleForUser(call, userId) {
  const normalizedUserId = Number(userId || 0);
  if (!Number.isInteger(normalizedUserId) || normalizedUserId <= 0) return 'participant';

  const ownerUserId = Number(call?.owner?.user_id || 0);
  if (Number.isInteger(ownerUserId) && ownerUserId > 0 && ownerUserId === normalizedUserId) {
    return 'owner';
  }

  const internalParticipants = Array.isArray(call?.participants?.internal)
    ? call.participants.internal
    : [];
  const participant = internalParticipants.find((row) => Number(row?.user_id || 0) === normalizedUserId);
  return normalizeCallParticipantRole(participant?.call_role || participant?.callRole || 'participant');
}

function isoToLocalInput(isoValue) {
  if (typeof isoValue !== 'string' || isoValue.trim() === '') return '';
  const date = new Date(isoValue);
  if (Number.isNaN(date.getTime())) return '';

  const year = date.getFullYear();
  const month = `${date.getMonth() + 1}`.padStart(2, '0');
  const day = `${date.getDate()}`.padStart(2, '0');
  const hours = `${date.getHours()}`.padStart(2, '0');
  const minutes = `${date.getMinutes()}`.padStart(2, '0');
  return `${year}-${month}-${day}T${hours}:${minutes}`;
}

function localInputToIso(localValue) {
  const text = String(localValue || '').trim();
  if (text === '') return '';
  const parsed = new Date(text);
  if (Number.isNaN(parsed.getTime())) return '';
  return parsed.toISOString();
}

const callOwnerEditState = reactive({
  visible: false,
  loadingContext: false,
  contextError: '',
  open: false,
  submitting: false,
  error: '',
  callId: '',
  resolvedCallId: '',
  title: '',
  accessMode: 'invite_only',
  startsLocal: '',
  endsLocal: '',
  replaceParticipants: false,
});

const callOwnerInviteLinkState = reactive({
  loading: false,
  error: '',
  url: '',
  expiresAt: '',
  copyNotice: '',
  generatedForCallId: '',
});

const callOwnerParticipants = reactive({
  loading: false,
  error: '',
  query: '',
  page: 1,
  pageSize: 10,
  pageCount: 1,
  hasPrev: false,
  hasNext: false,
  rows: [],
});

const callOwnerSelectedUserIds = ref([]);
const callOwnerExistingInternalUserIds = ref([]);
const callOwnerExternalRows = ref([]);
let callOwnerExternalRowId = 0;
let callOwnerContextSeq = 0;
const callLayoutSidebarState = reactive({
  visible: false,
  canModerate: false,
  currentMode: 'main_mini',
  currentStrategy: 'manual_pinned',
  modeOptions: [],
  strategyOptions: [],
  setMode: null,
  setStrategy: null,
});
const callAppSidebarState = reactive({
  activeSession: null,
  participants: [],
  sendSocketFrame: null,
  requestRoomSnapshot: null,
});

const showInCallOwnerEditCard = computed(() => isCallWorkspace.value && callOwnerEditState.visible);
const showCallOwnerInviteLink = computed(() => (
  isCallWorkspace.value
  && callOwnerEditState.visible
  && normalizeCallAccessMode(callOwnerEditState.accessMode) === 'free_for_all'
));
const canLoadCallOwnerInternalDirectory = computed(() => normalizeRole(sessionState.role) === 'admin');
const activeSidebarCallId = computed(() => String(
  callOwnerEditState.callId
  || callOwnerEditState.resolvedCallId
  || (isCallWorkspace.value ? route.params.callRef : '')
  || ''
).trim());
const canManageSidebarCallApps = computed(() => Boolean(callOwnerEditState.visible || callLayoutSidebarState.canModerate));

function applySidebarLayoutMode(mode) {
  if (typeof callLayoutSidebarState.setMode !== 'function') return;
  callLayoutSidebarState.setMode(mode);
}

function applySidebarLayoutStrategy(strategy) {
  if (typeof callLayoutSidebarState.setStrategy !== 'function') return;
  callLayoutSidebarState.setStrategy(strategy);
}

function handleCallAppSessionCreated() {
  applySidebarLayoutMode('call_app_workspace');
}

function clearCallAppSidebarState() {
  callAppSidebarState.activeSession = null;
  callAppSidebarState.participants = [];
  callAppSidebarState.sendSocketFrame = null;
  callAppSidebarState.requestRoomSnapshot = null;
}

function extractCallFromPayload(payload) {
  if (!payload || typeof payload !== 'object') return null;
  if (payload.call && typeof payload.call === 'object') {
    return payload.call;
  }

  const result = payload.result;
  if (result && typeof result === 'object' && result.call && typeof result.call === 'object') {
    return result.call;
  }

  return null;
}

async function resolveEditableCallFromRouteRef(callRef) {
  const normalized = String(callRef || '').trim();
  if (normalized === '') {
    throw new Error('Missing call reference.');
  }

  const payload = await apiRequest(`/api/calls/resolve/${encodeURIComponent(normalized)}`);
  const result = payload?.result && typeof payload.result === 'object' ? payload.result : {};
  const state = String(result.state || '').trim().toLowerCase();
  if (state === 'resolved') {
    const resolvedCall = extractCallFromPayload({ result });
    if (resolvedCall && typeof resolvedCall === 'object') {
      return resolvedCall;
    }
    throw new Error('Call payload is invalid.');
  }

  const error = new Error('Call reference could not be resolved.');
  if (state === 'expired') {
    error.responseStatus = 410;
  } else if (state === 'forbidden') {
    error.responseStatus = 403;
  } else {
    error.responseStatus = 404;
  }
  throw error;
}

function nextCallOwnerExternalRow(seed = null) {
  callOwnerExternalRowId += 1;
  const source = seed && typeof seed === 'object' ? seed : {};
  return {
    id: callOwnerExternalRowId,
    display_name: String(source.display_name || '').trim(),
    email: String(source.email || '').trim().toLowerCase(),
  };
}

function resetCallOwnerParticipantsState() {
  callOwnerParticipants.loading = false;
  callOwnerParticipants.error = '';
  callOwnerParticipants.query = '';
  callOwnerParticipants.page = 1;
  callOwnerParticipants.pageCount = 1;
  callOwnerParticipants.hasPrev = false;
  callOwnerParticipants.hasNext = false;
  callOwnerParticipants.rows = [];
  callOwnerSelectedUserIds.value = [];
  callOwnerExistingInternalUserIds.value = [];
  callOwnerExternalRows.value = [];
}

function resetCallOwnerInviteLinkState() {
  callOwnerInviteLinkState.loading = false;
  callOwnerInviteLinkState.error = '';
  callOwnerInviteLinkState.url = '';
  callOwnerInviteLinkState.expiresAt = '';
  callOwnerInviteLinkState.copyNotice = '';
  callOwnerInviteLinkState.generatedForCallId = '';
}

function buildJoinUrlFromPath(joinPath) {
  const normalizedPath = String(joinPath || '').trim();
  if (normalizedPath === '') return '';
  const path = normalizedPath.startsWith('/') ? normalizedPath : `/${normalizedPath}`;
  const origin = typeof window !== 'undefined' ? String(window.location.origin || '').trim() : '';
  return origin !== '' ? `${origin}${path}` : path;
}

async function generateCallOwnerInviteLink({ force = false } = {}) {
  if (!showCallOwnerInviteLink.value) {
    resetCallOwnerInviteLinkState();
    return;
  }

  const callId = String(callOwnerEditState.callId || '').trim();
  if (callId === '') {
    resetCallOwnerInviteLinkState();
    return;
  }

  if (!force && callOwnerInviteLinkState.generatedForCallId === callId && callOwnerInviteLinkState.url !== '') {
    return;
  }

  callOwnerInviteLinkState.loading = true;
  callOwnerInviteLinkState.error = '';
  callOwnerInviteLinkState.copyNotice = '';

  try {
    const payload = await apiRequest(`/api/calls/${encodeURIComponent(callId)}/access-link`, {
      method: 'POST',
      body: { link_kind: 'open' },
    });
    const result = payload?.result && typeof payload.result === 'object' ? payload.result : {};
    const accessId = String(result?.access_link?.id || '').trim();
    const joinPath = String(result?.join_path || (accessId !== '' ? `/join/${accessId}` : '')).trim();
    const url = buildJoinUrlFromPath(joinPath);
    if (url === '') {
      throw new Error('Invite link payload is invalid.');
    }

    callOwnerInviteLinkState.url = url;
    callOwnerInviteLinkState.expiresAt = typeof result?.access_link?.expires_at === 'string'
      ? result.access_link.expires_at
      : '';
    callOwnerInviteLinkState.generatedForCallId = callId;
  } catch (error) {
    callOwnerInviteLinkState.url = '';
    callOwnerInviteLinkState.expiresAt = '';
    callOwnerInviteLinkState.generatedForCallId = '';
    callOwnerInviteLinkState.error = error instanceof Error ? error.message : 'Could not create invite link.';
  } finally {
    callOwnerInviteLinkState.loading = false;
  }
}

async function copyCallOwnerInviteLink() {
  const url = String(callOwnerInviteLinkState.url || '').trim();
  if (url === '') return;

  try {
    if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
      await navigator.clipboard.writeText(url);
    } else {
      const textarea = document.createElement('textarea');
      textarea.value = url;
      textarea.setAttribute('readonly', 'readonly');
      textarea.style.position = 'fixed';
      textarea.style.top = '-1000px';
      document.body.appendChild(textarea);
      textarea.select();
      document.execCommand('copy');
      document.body.removeChild(textarea);
    }
    callOwnerInviteLinkState.copyNotice = 'Invite link copied.';
  } catch {
    callOwnerInviteLinkState.copyNotice = '';
    callOwnerInviteLinkState.error = 'Could not copy invite link.';
  }
}

function hydrateCallOwnerDraftFromCall(call) {
  const normalizedCall = call && typeof call === 'object' ? call : {};

  callOwnerEditState.callId = String(normalizedCall.id || '').trim();
  callOwnerEditState.resolvedCallId = callOwnerEditState.callId;
  callOwnerEditState.title = String(normalizedCall.title || '').trim();
  callOwnerEditState.accessMode = normalizeCallAccessMode(normalizedCall.access_mode);
  callOwnerEditState.startsLocal = isoToLocalInput(String(normalizedCall.starts_at || ''));
  callOwnerEditState.endsLocal = isoToLocalInput(String(normalizedCall.ends_at || ''));
  callOwnerEditState.error = '';
  callOwnerEditState.replaceParticipants = false;

  const ownerUserId = Number(normalizedCall?.owner?.user_id || 0);
  const internalRows = Array.isArray(normalizedCall?.participants?.internal)
    ? normalizedCall.participants.internal
    : [];
  const externalRows = Array.isArray(normalizedCall?.participants?.external)
    ? normalizedCall.participants.external
    : [];

  const nextInternalIds = [];
  const seenInternalIds = new Set();
  for (const row of internalRows) {
    const userId = Number(row?.user_id || 0);
    if (!Number.isInteger(userId) || userId <= 0 || userId === ownerUserId || seenInternalIds.has(userId)) {
      continue;
    }
    seenInternalIds.add(userId);
    nextInternalIds.push(userId);
  }

  const nextExternalRows = [];
  for (const row of externalRows) {
    const email = String(row?.email || '').trim().toLowerCase();
    const displayName = String(row?.display_name || '').trim();
    if (email === '' && displayName === '') continue;
    nextExternalRows.push(nextCallOwnerExternalRow({
      email,
      display_name: displayName,
    }));
  }

  callOwnerExistingInternalUserIds.value = nextInternalIds;
  callOwnerSelectedUserIds.value = nextInternalIds.slice();
  callOwnerExternalRows.value = nextExternalRows.length > 0 ? nextExternalRows : [nextCallOwnerExternalRow()];
}

async function refreshCallOwnerContext() {
  const sequence = callOwnerContextSeq + 1;
  callOwnerContextSeq = sequence;

  if (!isCallWorkspace.value) {
    callOwnerEditState.visible = false;
    callOwnerEditState.loadingContext = false;
    callOwnerEditState.contextError = '';
    callOwnerEditState.callId = '';
    callOwnerEditState.resolvedCallId = '';
    closeInCallEditModal();
    resetCallOwnerParticipantsState();
    resetCallOwnerInviteLinkState();
    return;
  }

  const callRef = String(route.params.callRef || '').trim();
  if (callRef === '') {
    callOwnerEditState.visible = false;
    callOwnerEditState.loadingContext = false;
    callOwnerEditState.contextError = '';
    callOwnerEditState.callId = '';
    callOwnerEditState.resolvedCallId = '';
    resetCallOwnerInviteLinkState();
    return;
  }

  callOwnerEditState.loadingContext = true;
  callOwnerEditState.contextError = '';
  try {
    const call = await resolveEditableCallFromRouteRef(callRef);
    if (sequence !== callOwnerContextSeq) return;

    const currentUserId = Number(sessionState.userId || 0);
    const currentCallRole = callParticipantRoleForUser(call, currentUserId);
    const isGlobalAdmin = normalizeRole(sessionState.role) === 'admin';
    const canManageCall = isGlobalAdmin || currentCallRole === 'owner' || currentCallRole === 'moderator';
    callOwnerEditState.visible = canManageCall;
    callOwnerEditState.resolvedCallId = String(call?.id || '').trim();

    if (canManageCall) {
      hydrateCallOwnerDraftFromCall(call);
      void generateCallOwnerInviteLink();
    } else {
      callOwnerEditState.callId = '';
      closeInCallEditModal();
      resetCallOwnerParticipantsState();
      resetCallOwnerInviteLinkState();
    }
  } catch (error) {
    if (sequence !== callOwnerContextSeq) return;
    callOwnerEditState.visible = false;
    callOwnerEditState.callId = '';
    callOwnerEditState.resolvedCallId = '';
    closeInCallEditModal();
    resetCallOwnerParticipantsState();
    resetCallOwnerInviteLinkState();
    const status = Number(error?.responseStatus || 0);
    if (status !== 404 && status !== 403 && status !== 410) {
      callOwnerEditState.contextError = error instanceof Error ? error.message : 'Could not load call settings.';
    } else {
      callOwnerEditState.contextError = '';
    }
  } finally {
    if (sequence === callOwnerContextSeq) {
      callOwnerEditState.loadingContext = false;
    }
  }
}

function isCallOwnerUserSelected(userId) {
  const id = Number(userId);
  return callOwnerSelectedUserIds.value.includes(id);
}

function toggleCallOwnerUserSelection(userId) {
  const id = Number(userId);
  const ownUserId = Number(sessionState.userId || 0);
  if (!Number.isInteger(id) || id <= 0 || id === ownUserId) return;

  const next = callOwnerSelectedUserIds.value.slice();
  const index = next.indexOf(id);
  if (index >= 0) {
    next.splice(index, 1);
  } else {
    next.push(id);
  }
  callOwnerSelectedUserIds.value = next;
}

async function loadCallOwnerParticipantsDirectory() {
  if (!callOwnerEditState.open || !callOwnerEditState.replaceParticipants || !canLoadCallOwnerInternalDirectory.value) {
    return;
  }

  callOwnerParticipants.loading = true;
  callOwnerParticipants.error = '';
  try {
    const payload = await apiRequest('/api/admin/users', {
      query: {
        query: callOwnerParticipants.query,
        page: callOwnerParticipants.page,
        page_size: callOwnerParticipants.pageSize,
      },
    });
    const ownUserId = Number(sessionState.userId || 0);
    const rows = Array.isArray(payload?.users) ? payload.users : [];
    callOwnerParticipants.rows = rows.filter((row) => {
      const rowId = Number(row?.id || 0);
      return !Number.isInteger(rowId) || rowId !== ownUserId;
    });
    const pagination = payload?.pagination || {};
    callOwnerParticipants.pageCount = Number.isInteger(pagination.page_count) && pagination.page_count > 0
      ? pagination.page_count
      : 1;
    callOwnerParticipants.hasPrev = Boolean(pagination.has_prev);
    callOwnerParticipants.hasNext = Boolean(pagination.has_next);
  } catch (error) {
    callOwnerParticipants.rows = [];
    callOwnerParticipants.pageCount = 1;
    callOwnerParticipants.hasPrev = false;
    callOwnerParticipants.hasNext = false;
    callOwnerParticipants.error = error instanceof Error ? error.message : 'Could not load users.';
  } finally {
    callOwnerParticipants.loading = false;
  }
}

async function applyCallOwnerParticipantSearch() {
  callOwnerParticipants.page = 1;
  await loadCallOwnerParticipantsDirectory();
}

async function goToCallOwnerParticipantPage(nextPage) {
  const normalizedPage = Number(nextPage);
  if (!Number.isInteger(normalizedPage) || normalizedPage < 1 || normalizedPage === callOwnerParticipants.page) {
    return;
  }

  callOwnerParticipants.page = normalizedPage;
  await loadCallOwnerParticipantsDirectory();
}

function addCallOwnerExternalRow() {
  callOwnerExternalRows.value = [...callOwnerExternalRows.value, nextCallOwnerExternalRow()];
}

function removeCallOwnerExternalRow(index) {
  if (!Number.isInteger(index) || index < 0 || index >= callOwnerExternalRows.value.length) return;
  const next = callOwnerExternalRows.value.slice();
  next.splice(index, 1);
  callOwnerExternalRows.value = next.length > 0 ? next : [nextCallOwnerExternalRow()];
}

function normalizeCallOwnerExternalRows() {
  const rows = [];

  for (let index = 0; index < callOwnerExternalRows.value.length; index += 1) {
    const row = callOwnerExternalRows.value[index];
    const displayName = String(row?.display_name || '').trim();
    const email = String(row?.email || '').trim().toLowerCase();

    if (displayName === '' && email === '') continue;

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

function normalizedCallOwnerInternalParticipantUserIds() {
  const ownUserId = Number(sessionState.userId || 0);
  const source = canLoadCallOwnerInternalDirectory.value
    ? callOwnerSelectedUserIds.value
    : callOwnerExistingInternalUserIds.value;
  const seen = new Set();
  const result = [];
  for (const rawId of source) {
    const id = Number(rawId);
    if (!Number.isInteger(id) || id <= 0 || id === ownUserId || seen.has(id)) continue;
    seen.add(id);
    result.push(id);
  }
  return result;
}

async function openInCallEditModal() {
  callOwnerEditState.error = '';
  if (callOwnerEditState.loadingContext) return;

  if (!callOwnerEditState.visible || String(callOwnerEditState.callId || '').trim() === '') {
    await refreshCallOwnerContext();
  }
  if (!callOwnerEditState.visible || String(callOwnerEditState.callId || '').trim() === '') {
    return;
  }

  callOwnerEditState.open = true;
  callOwnerEditState.error = '';
  callOwnerEditState.replaceParticipants = false;
  if (callOwnerExternalRows.value.length === 0) {
    callOwnerExternalRows.value = [nextCallOwnerExternalRow()];
  }
}

function closeInCallEditModal() {
  callOwnerEditState.open = false;
  callOwnerEditState.submitting = false;
  callOwnerEditState.error = '';
  callOwnerEditState.replaceParticipants = false;
  callOwnerParticipants.error = '';
}

async function submitInCallEditModal() {
  callOwnerEditState.error = '';
  const callId = String(callOwnerEditState.callId || '').trim();
  if (callId === '') {
    callOwnerEditState.error = 'Missing call id.';
    return;
  }

  const title = String(callOwnerEditState.title || '').trim();
  if (title === '') {
    callOwnerEditState.error = 'Title is required.';
    return;
  }

  const startsAt = localInputToIso(callOwnerEditState.startsLocal);
  const endsAt = localInputToIso(callOwnerEditState.endsLocal);
  if (startsAt === '' || endsAt === '') {
    callOwnerEditState.error = 'Start and end timestamps are required.';
    return;
  }
  if (new Date(endsAt).getTime() <= new Date(startsAt).getTime()) {
    callOwnerEditState.error = 'End timestamp must be after start timestamp.';
    return;
  }

  const payload = {
    title,
    access_mode: normalizeCallAccessMode(callOwnerEditState.accessMode),
    starts_at: startsAt,
    ends_at: endsAt,
  };

  if (callOwnerEditState.replaceParticipants) {
    const normalizedExternal = normalizeCallOwnerExternalRows();
    if (!normalizedExternal.ok) {
      callOwnerEditState.error = normalizedExternal.error;
      return;
    }
    payload.internal_participant_user_ids = normalizedCallOwnerInternalParticipantUserIds();
    payload.external_participants = normalizedExternal.rows;
  }

  callOwnerEditState.submitting = true;
  try {
    const response = await apiRequest(`/api/calls/${encodeURIComponent(callId)}`, {
      method: 'PATCH',
      body: payload,
    });
    const updatedCall = extractCallFromPayload(response);
    if (updatedCall && typeof updatedCall === 'object') {
      hydrateCallOwnerDraftFromCall(updatedCall);
    }
    closeInCallEditModal();
    await refreshCallOwnerContext();
  } catch (error) {
    callOwnerEditState.error = error instanceof Error ? error.message : 'Could not update call.';
  } finally {
    callOwnerEditState.submitting = false;
  }
}

async function playSpeakerTestSound() {
  await playCallSpeakerTestSound(callMediaPrefs);
}

provide('workspaceSidebarState', {
  leftSidebarCollapsed,
  isTabletViewport,
  isMobileViewport,
  isTabletSidebarOpen,
  showLeftSidebar,
  setMicLevelMonitorStream: attachMicLevelStream,
  callLayoutControls: callLayoutSidebarState,
  callAppControls: callAppSidebarState,
});

watch(settingsTiles, () => {
  activeSettingsTile.value = normalizeSettingsTile(activeSettingsTile.value);
}, { immediate: true });

watch(() => route.fullPath, () => {
  closeMobileSidebarForRouteChange();
});

watch(isCallWorkspace, (nextValue) => {
  if (nextValue) {
    if (!detachCallMediaWatcher) {
      detachCallMediaWatcher = attachCallMediaDeviceWatcher({ requestPermissions: false });
    }
    return;
  }
  if (detachCallMediaWatcher) {
    detachCallMediaWatcher();
    detachCallMediaWatcher = null;
  }
  clearCallAppSidebarState();
}, { immediate: true });

watch(
  () => [isCallWorkspace.value, callMediaPrefs.selectedMicrophoneId, isMobileViewport.value],
  ([inCallWorkspace, , mobileViewport]) => {
    if (inCallWorkspace && !mobileViewport) {
      void startMicLevelMonitor();
      return;
    }
    stopMicLevelMonitor();
  },
  { immediate: true }
);

watch(
  () => [
    isCallWorkspace.value,
    String(route.params.callRef || '').trim(),
    Number(sessionState.userId || 0),
    normalizeRole(sessionState.role),
    Boolean(callLayoutSidebarState.canModerate),
  ],
  () => {
    void refreshCallOwnerContext();
  },
  { immediate: true }
);

watch(
  () => [
    showCallOwnerInviteLink.value,
    String(callOwnerEditState.callId || '').trim(),
    normalizeCallAccessMode(callOwnerEditState.accessMode),
  ],
  ([shouldShow]) => {
    if (!shouldShow) {
      resetCallOwnerInviteLinkState();
      return;
    }
    void generateCallOwnerInviteLink();
  }
);

watch(
  () => [
    callOwnerEditState.open,
    callOwnerEditState.replaceParticipants,
    canLoadCallOwnerInternalDirectory.value,
  ],
  ([isOpen, replaceParticipants, canLoadInternalDirectory]) => {
    if (!isOpen || !replaceParticipants || !canLoadInternalDirectory) return;
    void loadCallOwnerParticipantsDirectory();
  }
);

onMounted(() => {
  syncI18nDocumentState(sessionState.locale, sessionState.direction);
  void loadWorkspaceAppearance({ force: true }).then(() => {
    resetSettingsDraft();
  });
});

onBeforeUnmount(() => {
  stopMicLevelMonitor();
  if (detachCallMediaWatcher) {
    detachCallMediaWatcher();
    detachCallMediaWatcher = null;
  }
});

function openUserCreateCall() {
  if (typeof window === 'undefined') return;
  window.dispatchEvent(new CustomEvent(USER_CALL_CREATE_EVENT));
}

function normalizeWorkspaceThemeId(value) {
  const themeId = String(value || '').trim();
  if (themeId !== '' && workspaceThemeOptions.value.some((theme) => theme.id === themeId)) {
    return themeId;
  }
  return workspaceThemeOptions.value[0]?.id || 'dark';
}

function resetSettingsDraft() {
  settingsDraft.displayName = sessionState.displayName || '';
  settingsDraft.theme = normalizeWorkspaceThemeId(sessionState.theme || 'dark');
  settingsDraft.timeFormat = sessionState.timeFormat || '24h';
  settingsDraft.dateFormat = sessionState.dateFormat || 'dmy_dot';
  settingsDraft.language = normalizeSettingsLanguage(sessionState.locale || 'en');
  settingsDraft.postLogoutLandingUrl = sessionState.postLogoutLandingUrl || '';
  settingsDraft.avatarDataUrl = '';
  settingsDraft.aboutMe = sessionState.aboutMe || '';
  settingsDraft.linkedinUrl = sessionState.linkedinUrl || '';
  settingsDraft.xUrl = sessionState.xUrl || '';
  settingsDraft.youtubeUrl = sessionState.youtubeUrl || '';
  settingsDraft.webAppNotificationsEnabled = sessionState.webAppNotificationsEnabled === true;
  settingsDraft.webAppNotificationSoundEnabled = sessionState.webAppNotificationSoundEnabled !== false;
  settingsDraft.webAppNotificationCallInvitesEnabled = sessionState.webAppNotificationCallInvitesEnabled !== false;
  settingsDraft.webAppNotificationCallRemindersEnabled = sessionState.webAppNotificationCallRemindersEnabled !== false;
  settingsDraft.webAppNotificationChatMentionsEnabled = sessionState.webAppNotificationChatMentionsEnabled !== false;
}

function setAvatarStatus(message = '') {
  settingsState.avatarStatus = message;
}

function normalizeSettingsTile(tileId) {
  const normalized = String(tileId || '').trim();
  const fallback = settingsTiles.value.some((tile) => tile.id === DEFAULT_SETTINGS_TILE)
    ? DEFAULT_SETTINGS_TILE
    : settingsTiles.value[0]?.id || DEFAULT_SETTINGS_TILE;
  if (normalized === '') return fallback;
  return settingsTiles.value.some((tile) => tile.id === normalized) ? normalized : fallback;
}

function toggleSettingsMaximized() {
  if (!isDesktopLikeViewport.value) return;
  settingsState.maximized = !settingsState.maximized;
}

function closeSettingsModal() {
  if (settingsState.saving) return;
  settingsState.open = false;
  settingsState.dragging = false;
  settingsState.maximized = false;
  settingsState.loading = false;
  settingsState.message = '';
  settingsState.avatarStatus = '';
  activeSettingsTile.value = normalizeSettingsTile(DEFAULT_SETTINGS_TILE);
  resetSettingsDraft();
}

function openSettingsModal(tileId = DEFAULT_SETTINGS_TILE) {
  activeSettingsTile.value = normalizeSettingsTile(tileId);
  if (settingsState.open) return;
  settingsState.open = true;
  settingsState.loading = false;
  settingsState.message = '';
  settingsState.avatarStatus = '';
  settingsState.dragging = false;
  settingsState.maximized = false;
  resetSettingsDraft();
}

function readFileAsDataUrl(file) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(typeof reader.result === 'string' ? reader.result : '');
    reader.onerror = () => reject(new Error('Could not read avatar file.'));
    reader.readAsDataURL(file);
  });
}

async function setAvatarFromFile(file) {
  if (!file) return;
  if (!['image/png', 'image/jpeg', 'image/webp'].includes(file.type)) {
    setAvatarStatus(t('settings.avatar_type_invalid'));
    return;
  }

  try {
    const dataUrl = await readFileAsDataUrl(file);
    settingsDraft.avatarDataUrl = dataUrl;
    setAvatarStatus(t('settings.avatar_selected', { name: file.name }));
  } catch (error) {
    setAvatarStatus(error instanceof Error ? error.message : t('settings.avatar_prepare_failed'));
  }
}

async function handleAvatarSelect(event) {
  const file = event?.target?.files?.[0] || null;
  settingsState.dragging = false;
  await setAvatarFromFile(file);
}

async function handleAvatarDrop(event) {
  settingsState.dragging = false;
  const file = event?.dataTransfer?.files?.[0] || null;
  await setAvatarFromFile(file);
}

async function saveSettings() {
  if (settingsState.saving || settingsState.loading) return;
  settingsState.message = '';
  settingsState.avatarStatus = '';

  const displayName = settingsDraft.displayName.trim();
  const theme = normalizeWorkspaceThemeId(settingsDraft.theme);
  const rawTimeFormat = settingsDraft.timeFormat.trim();
  const rawDateFormat = settingsDraft.dateFormat.trim();
  const timeFormat = normalizeTimeFormat(rawTimeFormat);
  const dateFormat = normalizeDateFormat(rawDateFormat);
  const language = normalizeSettingsLanguage(settingsDraft.language);
  const postLogoutLandingUrl = normalizePostLogoutLandingUrl(settingsDraft.postLogoutLandingUrl);

  if (displayName === '') {
    settingsState.message = t('settings.display_name_required');
    return;
  }

  if (theme === '') {
    settingsState.message = t('settings.theme_required');
    return;
  }

  if (!['24h', '12h'].includes(rawTimeFormat)) {
    settingsState.message = t('settings.time_format_invalid');
    return;
  }

  if (rawDateFormat === '' || rawDateFormat !== dateFormat) {
    settingsState.message = t('settings.date_format_invalid');
    return;
  }

  if (!settingsLanguageOptions.value.some((option) => option.code === language)) {
    settingsState.message = t('settings.unsupported_language');
    return;
  }

  if (postLogoutLandingUrl === null) {
    settingsState.message = t('settings.logout_landing_invalid');
    return;
  }

  settingsState.saving = true;
  try {
    let avatarPath = sessionState.avatarPath || null;
    if (settingsDraft.avatarDataUrl) {
      const uploadResult = await uploadSessionAvatar(settingsDraft.avatarDataUrl);
      if (!uploadResult.ok) {
        settingsState.message = uploadResult.message || t('settings.avatar_upload_failed');
        if (uploadResult.reason === 'invalid_session') {
          settingsState.open = false;
          router.replace('/login');
        }
        return;
      }

      avatarPath = uploadResult.avatarPath || avatarPath;
      setAvatarStatus(t('settings.avatar_uploaded'));
    }

    const saveResult = await saveSessionSettings({
      display_name: displayName,
      theme,
      time_format: timeFormat,
      date_format: dateFormat,
      locale: language,
      avatar_path: avatarPath,
      post_logout_landing_url: postLogoutLandingUrl,
      about_me: settingsDraft.aboutMe,
      linkedin_url: settingsDraft.linkedinUrl,
      x_url: settingsDraft.xUrl,
      youtube_url: settingsDraft.youtubeUrl,
      web_app_notifications_enabled: settingsDraft.webAppNotificationsEnabled === true,
      web_app_notification_sound_enabled: settingsDraft.webAppNotificationSoundEnabled === true,
      web_app_notification_call_invites_enabled: settingsDraft.webAppNotificationCallInvitesEnabled === true,
      web_app_notification_call_reminders_enabled: settingsDraft.webAppNotificationCallRemindersEnabled === true,
      web_app_notification_chat_mentions_enabled: settingsDraft.webAppNotificationChatMentionsEnabled === true,
    });

    if (!saveResult.ok) {
      settingsState.message = saveResult.message || 'Could not save settings.';
      if (saveResult.reason === 'invalid_session') {
        settingsState.open = false;
        router.replace('/login');
      }
      return;
    }

    const savedLanguage = normalizeSettingsLanguage(saveResult.user?.locale || language);
    await ensureI18nResources({ locale: savedLanguage, force: true });
    syncI18nDocumentState(savedLanguage, sessionState.direction);
    settingsState.message = t('settings.settings_saved');
    settingsState.open = false;
    resetSettingsDraft();
  } finally {
    settingsState.saving = false;
  }
}

async function handleSignOut() {
  const logoutResult = await logoutSession();
  router.replace(postLogoutRedirectTarget(logoutResult, '/login'));
}
</script>
