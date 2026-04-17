<template>
  <main class="app">
    <div class="shell no-right-sidebar" :class="shellClasses">
      <aside class="sidebar sidebar-left" :class="leftSidebarClasses">
        <div v-if="isCallWorkspace" class="sidebar-content left left-call-content">
          <div class="brand-strip">
            <img data-brand-logo src="/assets/orgas/kingrt/logo.svg" alt="KingRT" />
            <button
              class="sidebar-toggle-btn"
              type="button"
              :title="leftSidebarToggleLabel"
              :aria-label="leftSidebarToggleLabel"
              @click="handleLeftSidebarToggle"
            >
              <span v-if="isMobileViewport" class="sidebar-close-mark" aria-hidden="true">x</span>
              <img v-else class="arrow-icon-image" :src="leftSidebarToggleIcon" alt="" />
            </button>
          </div>

          <div class="call-left-settings">
            <section class="call-left-settings-block" aria-label="Camera">
              <div class="call-left-settings-title">Camera</div>
              <div class="call-left-settings-field">
                <AppSelect
                  id="call-left-camera-select"
                  aria-label="Camera"
                  :model-value="callMediaPrefs.selectedCameraId"
                  @update:model-value="setCallCameraDevice"
                >
                  <option value="">{{ callMediaPrefs.cameras.length === 0 ? 'No camera detected' : 'Select camera' }}</option>
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

            <section class="call-left-settings-block" aria-label="Mic">
              <div class="call-left-settings-title">Mic</div>
              <div class="call-left-settings-field">
                <AppSelect
                  id="call-left-mic-select"
                  aria-label="Mic"
                  :model-value="callMediaPrefs.selectedMicrophoneId"
                  @update:model-value="setCallMicrophoneDevice"
                >
                  <option value="">{{ callMediaPrefs.microphones.length === 0 ? 'No microphone detected' : 'Select mic' }}</option>
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
                <label for="call-left-mic-volume">Volume</label>
                <div class="call-left-volume-row">
                  <input
                    id="call-left-mic-volume"
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
                <div
                  class="call-left-meter"
                  role="meter"
                  aria-label="Microphone level"
                  aria-valuemin="0"
                  aria-valuemax="100"
                  :aria-valuenow="micLevelPercent"
                >
                  <span class="call-left-meter-bar" :style="{ width: `${micLevelPercent}%` }"></span>
                </div>
              </div>
            </section>

            <section class="call-left-settings-block" aria-label="Speaker">
              <div class="call-left-settings-title">Speaker</div>
              <div class="call-left-settings-field">
                <AppSelect
                  id="call-left-speaker-select"
                  aria-label="Speaker"
                  :model-value="callMediaPrefs.selectedSpeakerId"
                  @update:model-value="setCallSpeakerDevice"
                >
                  <option value="">{{ callMediaPrefs.speakers.length === 0 ? 'No speaker detected' : 'Select speaker' }}</option>
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
                <label for="call-left-speaker-volume">Volume</label>
                <div class="call-left-volume-row">
                  <input
                    id="call-left-speaker-volume"
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

            <section
              v-if="showInCallOwnerEditCard"
              class="call-left-owner-edit-block"
              aria-label="Call settings"
            >
              <div class="call-left-settings-title">Call settings</div>
              <button
                class="btn btn-cyan full call-left-owner-edit-btn"
                type="button"
                :disabled="callOwnerEditState.loadingContext || callOwnerEditState.submitting"
                @click="openInCallEditModal"
              >
                {{ callOwnerEditState.loadingContext ? 'Loading…' : 'Edit call' }}
              </button>
              <p v-if="callOwnerEditState.contextError" class="call-left-settings-error">
                {{ callOwnerEditState.contextError }}
              </p>
            </section>

            <div v-if="callMediaPrefs.error" class="call-left-settings-error">{{ callMediaPrefs.error }}</div>
          </div>
        </div>

        <div v-else class="sidebar-content left">
          <div class="brand-strip">
            <img data-brand-logo src="/assets/orgas/kingrt/logo.svg" alt="KingRT" />
            <button
              class="sidebar-toggle-btn"
              type="button"
              :title="leftSidebarToggleLabel"
              :aria-label="leftSidebarToggleLabel"
              @click="handleLeftSidebarToggle"
            >
              <span v-if="isMobileViewport" class="sidebar-close-mark" aria-hidden="true">x</span>
              <img v-else class="arrow-icon-image" :src="leftSidebarToggleIcon" alt="" />
            </button>
          </div>

          <nav class="nav" aria-label="Main navigation">
            <RouterLink
              v-for="item in navItems"
              :key="item.to"
              :to="item.to"
              class="nav-link"
              :class="{ active: isNavItemActive(item) }"
              @click="handleNavItemClick"
            >
              <img :src="item.icon" alt="" />
              <span>{{ item.label }}</span>
            </RouterLink>
          </nav>

          <section class="sidebar-profile avatar-only">
            <button class="sidebar-avatar-trigger" type="button" aria-label="Open settings" @click="openSettingsModal('about-me')">
              <img
                class="sidebar-avatar-image"
                :src="profileAvatarSrc"
                alt="Profile avatar"
              />
            </button>
          </section>

          <div class="logout-wrap">
            <button class="btn full" type="button" @click="handleSignOut">Log out</button>
          </div>
        </div>
      </aside>

      <section class="main" @click="handleMainClick">
        <div v-if="showMobileShellHeader" class="mobile-brand-strip">
          <img src="/assets/orgas/kingrt/king_logo-withslogan.svg" alt="KingRT" />
          <button class="mobile-menu-btn" type="button" aria-label="Toggle menu" @click.stop="handleLeftSidebarToggle">
            <span class="mobile-menu-btn-bars" aria-hidden="true"></span>
          </button>
        </div>
        <div class="workspace">
          <section v-if="showWorkspaceHeader" class="section">
            <div class="section-head">
              <div class="section-head-left">
                  <button
                    v-if="!isMobileViewport"
                    class="show-sidebar-overlay show-sidebar-inline show-left-sidebar-overlay"
                    type="button"
                    title="Show sidebar"
                    aria-label="Show sidebar"
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
                <template v-if="route.path === '/admin/overview'">
                  <button class="btn btn-cyan" type="button" @click="openCallsRegistry">Open Calls</button>
                  <button class="btn btn-cyan" type="button" @click="openGrafana">Open Grafana</button>
                </template>
                <button v-else class="btn" type="button" @click="openSettingsModal('about-me')">Settings</button>
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

  <div class="settings-modal" :hidden="!settingsState.open" role="dialog" aria-modal="true" aria-label="Workspace settings">
    <div class="settings-backdrop" @click="closeSettingsModal"></div>
    <div class="settings-dialog">
      <header class="settings-header">
        <div class="settings-title-wrap">
          <img src="/assets/orgas/kingrt/logo.svg" alt="" />
          <h3>Settings</h3>
        </div>
        <button class="icon-mini-btn" type="button" aria-label="Close settings" @click="closeSettingsModal">
          <img src="/assets/orgas/kingrt/icons/cancel.png" alt="" />
        </button>
      </header>

      <div class="settings-grid" role="tablist" aria-label="Settings categories">
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

      <section v-if="activeSettingsTile === 'about-me'" class="settings-panel">
        <div class="settings-row">
          <label class="settings-field">
            <span>Display name</span>
            <input v-model.trim="settingsDraft.displayName" class="input" type="text" autocomplete="name" />
          </label>
          <div class="settings-field">
            <span>Email</span>
            <div class="settings-readonly-value">{{ sessionState.email || '—' }}</div>
          </div>
        </div>

        <div class="settings-row">
          <div class="settings-field">
            <span>Avatar preview</span>
            <img class="settings-avatar-preview-lg" :src="settingsAvatarPreviewSrc" alt="Avatar preview" />
          </div>
          <div class="settings-field">
            <label
              class="settings-dropzone"
              :class="{ 'is-over': settingsState.dragging }"
              for="settings-avatar-input"
              @dragenter.prevent="settingsState.dragging = true"
              @dragover.prevent="settingsState.dragging = true"
              @dragleave.prevent="settingsState.dragging = false"
              @drop.prevent="handleAvatarDrop"
            >
              <input
                id="settings-avatar-input"
                class="settings-hidden-input"
                type="file"
                accept="image/png,image/jpeg,image/webp"
                @change="handleAvatarSelect"
              />
              <span class="settings-dropzone-title">Drop or choose an avatar</span>
              <span class="settings-dropzone-subtitle">PNG, JPEG, or WEBP. Backend upload is used directly.</span>
            </label>
            <div class="settings-upload-status">{{ settingsState.avatarStatus }}</div>
          </div>
        </div>
      </section>

      <section v-else-if="activeSettingsTile === 'theme'" class="settings-panel">
        <div class="settings-row">
          <label class="settings-field">
            <span>Theme</span>
            <input
              v-model.trim="settingsDraft.theme"
              class="input"
              type="text"
              autocomplete="off"
              placeholder="dark"
            />
          </label>
        </div>
      </section>

      <section v-else-if="activeSettingsTile === 'credentials-email'" class="settings-panel">
        <div class="settings-row">
          <label class="settings-field">
            <span>Primary email</span>
            <div class="settings-readonly-value">{{ sessionState.email || '—' }}</div>
          </label>
          <label class="settings-field">
            <span>Password</span>
            <input class="input" type="password" value="********" disabled autocomplete="off" />
          </label>
        </div>
      </section>

      <section v-else-if="activeSettingsTile === 'regional-time'" class="settings-panel">
        <div class="settings-row">
          <label class="settings-field">
            <span>Time format</span>
            <AppSelect v-model="settingsDraft.timeFormat">
              <option value="24h">24h</option>
              <option value="12h">12h</option>
            </AppSelect>
          </label>
          <label class="settings-field">
            <span>Language</span>
            <AppSelect v-model="settingsDraft.language">
              <option value="en">English</option>
              <option value="de">Deutsch</option>
              <option value="fr">Français</option>
              <option value="es">Español</option>
            </AppSelect>
          </label>
        </div>
      </section>

      <section v-else-if="activeSettingsTile === 'notifications'" class="settings-panel">
        <div class="settings-upload-status">Notifications settings coming soon.</div>
      </section>

      <section v-else-if="activeSettingsTile === 'apps'" class="settings-panel">
        <div class="settings-upload-status">Apps settings coming soon.</div>
      </section>

      <section v-else class="settings-panel">
        <div class="settings-upload-status">Select a settings tab.</div>
      </section>

      <div class="settings-actions">
        <button class="btn" type="button" :disabled="settingsState.saving || settingsState.loading" @click="saveSettings">
          {{ settingsState.saving ? 'Saving…' : 'Save settings' }}
        </button>
      </div>

      <div class="settings-upload-status">{{ settingsState.message }}</div>
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
          <img class="call-owner-edit-logo" src="/assets/orgas/kingrt/logo.svg" alt="" />
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
            <span>Room ID</span>
            <input v-model.trim="callOwnerEditState.roomId" class="input" type="text" placeholder="lobby" />
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
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router';
import AppSelect from '../components/AppSelect.vue';
import {
  logoutSession,
  saveSessionSettings,
  sessionState,
  uploadSessionAvatar,
} from '../domain/auth/session';
import { currentBackendOrigin, fetchBackend } from '../support/backendFetch';
import {
  attachCallMediaDeviceWatcher,
  callMediaPrefs,
  setCallBackgroundBackdropMode,
  setCallBackgroundBlurStrength,
  setCallBackgroundFilterMode,
  setCallBackgroundQualityProfile,
  setCallCameraDevice,
  setCallMicrophoneDevice,
  setCallMicrophoneVolume,
  setCallBackgroundApplyOutgoing,
  setCallSpeakerDevice,
  setCallSpeakerVolume,
} from '../domain/realtime/callMediaPreferences';

const router = useRouter();
const route = useRoute();
const leftSidebarCollapsed = ref(false);
const isTabletSidebarOpen = ref(false);
const isMobileSidebarOpen = ref(false);
const viewportMode = ref('desktop');
let laptopMedia = null;
let tabletMedia = null;
let mobileMedia = null;
let detachCallMediaWatcher = null;
const placeholderAvatar = '/assets/orgas/kingrt/avatar-placeholder.svg';
const LAPTOP_BREAKPOINT = 1440;
const TABLET_BREAKPOINT = 1180;
const MOBILE_BREAKPOINT = 760;
const SETTINGS_LANGUAGE_STORAGE_KEY = 'ii_videocall_v1_workspace_language';
const SUPPORTED_SETTINGS_LANGUAGES = ['en', 'de', 'fr', 'es'];

const navItems = computed(() => {
  const role = sessionState.role;
  const items = [
    { to: '/admin/overview', label: 'Overview', icon: '/assets/orgas/kingrt/icons/users.png', roles: ['admin'] },
    { to: '/admin/users', label: 'User Management', icon: '/assets/orgas/kingrt/icons/user.png', roles: ['admin'] },
    { to: '/admin/calls', label: 'Video Calls', icon: '/assets/orgas/kingrt/icons/lobby.png', roles: ['admin'] },
    { to: '/user/dashboard', label: 'My Calls', icon: '/assets/orgas/kingrt/icons/lobby.png', roles: ['user'] },
  ];

  return items.filter((item) => role && item.roles.includes(role));
});

const pageTitle = computed(() => {
  const mapping = {
    '/admin/overview': 'Video Operations',
    '/admin/users': 'User Management',
    '/admin/calls': 'Video Call Management',
    '/user/dashboard': 'My Video Calls',
  };

  if (route.path.startsWith('/workspace/call')) return 'Video Call';
  return mapping[route.path] || 'Workspace';
});
const isCallWorkspace = computed(() => route.path.startsWith('/workspace/call'));

const pageSubtitle = computed(() => {
  return '';
});
const showWorkspaceHeader = computed(() => (
  !['/admin/users', '/admin/calls'].includes(route.path)
  && !isCallWorkspace.value
));

const isTabletViewport = computed(() => viewportMode.value === 'tablet');
const isMobileViewport = computed(() => viewportMode.value === 'mobile');
const isLaptopViewport = computed(() => viewportMode.value === 'laptop');
const isDesktopViewport = computed(() => viewportMode.value === 'desktop');
const isDesktopLikeViewport = computed(() => isDesktopViewport.value || isLaptopViewport.value);
const showMobileShellHeader = computed(() => isMobileViewport.value && !isCallWorkspace.value);

const profileAvatarSrc = computed(() => sessionState.avatarPath || placeholderAvatar);
const sidebarExpanded = computed(() => {
  if (isTabletViewport.value) return isTabletSidebarOpen.value;
  if (isMobileViewport.value) return isMobileSidebarOpen.value;
  return !leftSidebarCollapsed.value;
});
const leftSidebarToggleIcon = computed(() => (
  !sidebarExpanded.value
    ? '/assets/orgas/kingrt/icons/forward.png'
    : '/assets/orgas/kingrt/icons/backward.png'
));
const leftSidebarToggleLabel = computed(() => (
  !sidebarExpanded.value ? 'Open sidebar' : 'Hide sidebar'
));
const shellClasses = computed(() => ({
  'left-collapsed': (isDesktopLikeViewport.value && leftSidebarCollapsed.value) || (isMobileViewport.value && !isMobileSidebarOpen.value),
  'laptop-mode': isLaptopViewport.value,
  'tablet-mode': isTabletViewport.value,
  'tablet-left-open': isTabletViewport.value && isTabletSidebarOpen.value,
  'mobile-mode': isMobileViewport.value,
  'mobile-left-open': isMobileViewport.value && isMobileSidebarOpen.value,
  'call-workspace-mode': isCallWorkspace.value,
}));
const leftSidebarClasses = computed(() => ({
  collapsed: (isDesktopLikeViewport.value && leftSidebarCollapsed.value) || (isMobileViewport.value && !isMobileSidebarOpen.value),
}));
const micLevelPercent = ref(0);
let micLevelStream = null;
let micLevelAudioContext = null;
let micLevelSource = null;
let micLevelAnalyser = null;
let micLevelData = null;
let micLevelFrame = 0;
let micLevelMonitorToken = 0;

const settingsDraft = reactive({
  displayName: '',
  theme: 'dark',
  timeFormat: '24h',
  language: 'en',
  avatarDataUrl: '',
});

const settingsState = reactive({
  open: false,
  loading: false,
  saving: false,
  dragging: false,
  message: '',
  avatarStatus: '',
});
const activeSettingsTile = ref('about-me');
const settingsTiles = computed(() => ([
  { id: 'about-me', label: 'About Me' },
  { id: 'credentials-email', label: 'Credentials + Email' },
  { id: 'regional-time', label: 'Regional Time + Language' },
  { id: 'theme', label: 'Theme' },
  { id: 'notifications', label: 'Notifications' },
  { id: 'apps', label: 'Apps' },
]));

const settingsAvatarPreviewSrc = computed(() => settingsDraft.avatarDataUrl || profileAvatarSrc.value);

function normalizeSettingsLanguage(value) {
  const normalized = String(value || '').trim().toLowerCase();
  return SUPPORTED_SETTINGS_LANGUAGES.includes(normalized) ? normalized : 'en';
}

function readStoredSettingsLanguage() {
  if (typeof localStorage === 'undefined') return 'en';
  return normalizeSettingsLanguage(localStorage.getItem(SETTINGS_LANGUAGE_STORAGE_KEY));
}

function storeSettingsLanguage(language) {
  if (typeof localStorage === 'undefined') return;
  localStorage.setItem(SETTINGS_LANGUAGE_STORAGE_KEY, normalizeSettingsLanguage(language));
}

function applySettingsLanguage(language) {
  if (typeof document === 'undefined') return;
  document.documentElement.lang = normalizeSettingsLanguage(language);
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

function isUuidLike(value) {
  const normalized = String(value || '').trim().toLowerCase();
  return /^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/.test(normalized);
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
  title: '',
  accessMode: 'invite_only',
  roomId: 'lobby',
  startsLocal: '',
  endsLocal: '',
  replaceParticipants: false,
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

const showInCallOwnerEditCard = computed(() => isCallWorkspace.value && callOwnerEditState.visible);
const canLoadCallOwnerInternalDirectory = computed(() => normalizeRole(sessionState.role) === 'admin');

function isNavItemActive(item) {
  if (item.to.startsWith('/workspace/call')) {
    return route.path.startsWith('/workspace/call');
  }

  return route.path === item.to;
}

function syncViewportState() {
  if (!laptopMedia || !tabletMedia || !mobileMedia) return;

  if (mobileMedia.matches) {
    viewportMode.value = 'mobile';
    leftSidebarCollapsed.value = false;
    isTabletSidebarOpen.value = false;
    isMobileSidebarOpen.value = false;
    return;
  }

  if (tabletMedia.matches) {
    viewportMode.value = 'tablet';
    leftSidebarCollapsed.value = false;
    isTabletSidebarOpen.value = false;
    isMobileSidebarOpen.value = false;
    return;
  }

  if (laptopMedia.matches) {
    viewportMode.value = 'laptop';
    isTabletSidebarOpen.value = false;
    isMobileSidebarOpen.value = false;
    return;
  }

  viewportMode.value = 'desktop';
  isTabletSidebarOpen.value = false;
  isMobileSidebarOpen.value = false;
}

function syncMobileScrollLock(forceUnlock = false) {
  if (typeof document === 'undefined') return;
  const lockScroll = !forceUnlock && isMobileViewport.value && isMobileSidebarOpen.value;
  document.documentElement.style.overflow = lockScroll ? 'hidden' : '';
  document.body.style.overflow = lockScroll ? 'hidden' : '';
}

function handleViewportChange() {
  syncViewportState();
}

function handleLeftSidebarToggle() {
  if (isTabletViewport.value) {
    isTabletSidebarOpen.value = !isTabletSidebarOpen.value;
    return;
  }

  if (isMobileViewport.value) {
    isMobileSidebarOpen.value = !isMobileSidebarOpen.value;
    return;
  }

  leftSidebarCollapsed.value = true;
}

function showLeftSidebar() {
  if (isTabletViewport.value) {
    isTabletSidebarOpen.value = true;
    return;
  }

  if (isMobileViewport.value) {
    isMobileSidebarOpen.value = true;
    return;
  }

  leftSidebarCollapsed.value = false;
}

function handleMainClick() {
  if (isTabletViewport.value && isTabletSidebarOpen.value) {
    isTabletSidebarOpen.value = false;
    return;
  }

  if (isMobileViewport.value && isMobileSidebarOpen.value) {
    isMobileSidebarOpen.value = false;
  }
}

function handleNavItemClick() {
  if (isTabletViewport.value && isTabletSidebarOpen.value) {
    isTabletSidebarOpen.value = false;
  }

  if (isMobileViewport.value && isMobileSidebarOpen.value) {
    isMobileSidebarOpen.value = false;
  }
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

async function fetchCallById(callId) {
  const normalizedCallId = String(callId || '').trim();
  if (normalizedCallId === '') {
    throw new Error('Missing call id.');
  }
  const payload = await apiRequest(`/api/calls/${encodeURIComponent(normalizedCallId)}`);
  const call = extractCallFromPayload(payload);
  if (!call || typeof call !== 'object') {
    throw new Error('Call payload is invalid.');
  }
  return call;
}

async function resolveEditableCallFromRouteRef(callRef) {
  const normalized = String(callRef || '').trim();
  if (normalized === '') {
    throw new Error('Missing call reference.');
  }

  if (isUuidLike(normalized)) {
    try {
      return await fetchCallById(normalized);
    } catch (directError) {
      const directStatus = Number(directError?.responseStatus || 0);
      if (directStatus !== 404) {
        throw directError;
      }

      const accessPayload = await apiRequest(`/api/call-access/${encodeURIComponent(normalized)}`);
      const accessCall = extractCallFromPayload(accessPayload);
      if (accessCall && typeof accessCall === 'object') {
        return accessCall;
      }
      throw new Error('Call payload is invalid.');
    }
  }

  return fetchCallById(normalized);
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

function hydrateCallOwnerDraftFromCall(call) {
  const normalizedCall = call && typeof call === 'object' ? call : {};

  callOwnerEditState.callId = String(normalizedCall.id || '').trim();
  callOwnerEditState.title = String(normalizedCall.title || '').trim();
  callOwnerEditState.roomId = String(normalizedCall.room_id || 'lobby').trim() || 'lobby';
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
    closeInCallEditModal();
    resetCallOwnerParticipantsState();
    return;
  }

  const callRef = String(route.params.callRef || '').trim();
  if (callRef === '') {
    callOwnerEditState.visible = false;
    callOwnerEditState.loadingContext = false;
    callOwnerEditState.contextError = '';
    callOwnerEditState.callId = '';
    return;
  }

  callOwnerEditState.loadingContext = true;
  callOwnerEditState.contextError = '';
  try {
    const call = await resolveEditableCallFromRouteRef(callRef);
    if (sequence !== callOwnerContextSeq) return;

    const currentUserId = Number(sessionState.userId || 0);
    const ownerUserId = Number(call?.owner?.user_id || 0);
    const isOwner = Number.isInteger(currentUserId) && currentUserId > 0 && currentUserId === ownerUserId;
    callOwnerEditState.visible = isOwner;

    if (isOwner) {
      hydrateCallOwnerDraftFromCall(call);
    } else {
      callOwnerEditState.callId = '';
      closeInCallEditModal();
      resetCallOwnerParticipantsState();
    }
  } catch (error) {
    if (sequence !== callOwnerContextSeq) return;
    callOwnerEditState.visible = false;
    callOwnerEditState.callId = '';
    closeInCallEditModal();
    resetCallOwnerParticipantsState();
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
    room_id: String(callOwnerEditState.roomId || '').trim() || 'lobby',
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

function stopMicLevelMonitor() {
  micLevelMonitorToken += 1;
  if (micLevelFrame !== 0 && typeof cancelAnimationFrame === 'function') {
    cancelAnimationFrame(micLevelFrame);
  }
  micLevelFrame = 0;

  if (micLevelSource && typeof micLevelSource.disconnect === 'function') {
    try {
      micLevelSource.disconnect();
    } catch {
      // ignore
    }
  }
  micLevelSource = null;

  if (micLevelAnalyser && typeof micLevelAnalyser.disconnect === 'function') {
    try {
      micLevelAnalyser.disconnect();
    } catch {
      // ignore
    }
  }
  micLevelAnalyser = null;
  micLevelData = null;

  if (micLevelStream instanceof MediaStream) {
    for (const track of micLevelStream.getTracks()) {
      try {
        track.stop();
      } catch {
        // ignore
      }
    }
  }
  micLevelStream = null;

  if (micLevelAudioContext && typeof micLevelAudioContext.close === 'function') {
    micLevelAudioContext.close().catch(() => {});
  }
  micLevelAudioContext = null;
  micLevelPercent.value = 0;
}

function sampleMicLevel(token) {
  if (token !== micLevelMonitorToken) return;
  if (!micLevelAnalyser || !micLevelData) {
    micLevelPercent.value = 0;
    return;
  }

  micLevelAnalyser.getByteTimeDomainData(micLevelData);
  let energy = 0;
  let peak = 0;
  for (let index = 0; index < micLevelData.length; index += 1) {
    const centered = (micLevelData[index] - 128) / 128;
    energy += centered * centered;
    const amplitude = Math.abs(centered);
    if (amplitude > peak) peak = amplitude;
  }

  const rms = Math.sqrt(energy / micLevelData.length);
  const micScale = Math.max(0, Math.min(100, Number(callMediaPrefs.microphoneVolume || 100))) / 100;
  const gated = Math.max(0, Math.max(rms * 8.6, peak * 1.28) - 0.02);
  const normalized = Math.min(1, gated / 0.98);
  const boostedPercent = normalized * 100 * micScale * 3;
  micLevelPercent.value = Math.max(0, Math.min(100, Math.round(boostedPercent)));

  if (typeof requestAnimationFrame === 'function') {
    micLevelFrame = requestAnimationFrame(() => sampleMicLevel(token));
  }
}

async function startMicLevelMonitor() {
  stopMicLevelMonitor();
  if (!isCallWorkspace.value) return;
  if (
    typeof window === 'undefined'
    || typeof navigator === 'undefined'
    || !navigator.mediaDevices
    || typeof navigator.mediaDevices.getUserMedia !== 'function'
  ) {
    return;
  }

  const token = micLevelMonitorToken + 1;
  micLevelMonitorToken = token;
  const AudioContextCtor = window.AudioContext || window.webkitAudioContext;
  if (!AudioContextCtor) return;

  const selectedMicId = String(callMediaPrefs.selectedMicrophoneId || '').trim();
  const audioConstraints = selectedMicId !== ''
    ? { deviceId: { exact: selectedMicId }, echoCancellation: false, noiseSuppression: false, autoGainControl: false }
    : { echoCancellation: false, noiseSuppression: false, autoGainControl: false };

  try {
    const stream = await navigator.mediaDevices.getUserMedia({ audio: audioConstraints, video: false });
    if (token !== micLevelMonitorToken) {
      for (const track of stream.getTracks()) {
        track.stop();
      }
      return;
    }

    const context = new AudioContextCtor({ latencyHint: 'interactive' });
    const source = context.createMediaStreamSource(stream);
    const analyser = context.createAnalyser();
    analyser.fftSize = 256;
    analyser.smoothingTimeConstant = 0.08;
    source.connect(analyser);

    micLevelStream = stream;
    micLevelAudioContext = context;
    micLevelSource = source;
    micLevelAnalyser = analyser;
    micLevelData = new Uint8Array(analyser.fftSize);
    sampleMicLevel(token);
  } catch {
    if (token === micLevelMonitorToken) {
      micLevelPercent.value = 0;
    }
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

provide('workspaceSidebarState', {
  leftSidebarCollapsed,
  isTabletViewport,
  isMobileViewport,
  isTabletSidebarOpen,
  showLeftSidebar,
});

watch([isMobileViewport, isMobileSidebarOpen], () => {
  syncMobileScrollLock();
}, { immediate: true });

watch(() => route.fullPath, () => {
  if (isMobileViewport.value && isMobileSidebarOpen.value) {
    isMobileSidebarOpen.value = false;
  }
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
}, { immediate: true });

watch(
  () => [isCallWorkspace.value, callMediaPrefs.selectedMicrophoneId],
  ([inCallWorkspace]) => {
    if (inCallWorkspace) {
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
  ],
  () => {
    void refreshCallOwnerContext();
  },
  { immediate: true }
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
  applySettingsLanguage(readStoredSettingsLanguage());
  if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') return;
  laptopMedia = window.matchMedia(`(max-width: ${LAPTOP_BREAKPOINT}px)`);
  tabletMedia = window.matchMedia(`(max-width: ${TABLET_BREAKPOINT}px)`);
  mobileMedia = window.matchMedia(`(max-width: ${MOBILE_BREAKPOINT}px)`);
  syncViewportState();
  if (typeof laptopMedia.addEventListener === 'function') {
    laptopMedia.addEventListener('change', handleViewportChange);
    tabletMedia.addEventListener('change', handleViewportChange);
    mobileMedia.addEventListener('change', handleViewportChange);
  } else if (typeof laptopMedia.addListener === 'function') {
    laptopMedia.addListener(handleViewportChange);
    tabletMedia.addListener(handleViewportChange);
    mobileMedia.addListener(handleViewportChange);
  }
});

onBeforeUnmount(() => {
  stopMicLevelMonitor();
  if (detachCallMediaWatcher) {
    detachCallMediaWatcher();
    detachCallMediaWatcher = null;
  }
  if (!laptopMedia || !tabletMedia || !mobileMedia) return;
  if (typeof laptopMedia.removeEventListener === 'function') {
    laptopMedia.removeEventListener('change', handleViewportChange);
    tabletMedia.removeEventListener('change', handleViewportChange);
    mobileMedia.removeEventListener('change', handleViewportChange);
  } else if (typeof laptopMedia.removeListener === 'function') {
    laptopMedia.removeListener(handleViewportChange);
    tabletMedia.removeListener(handleViewportChange);
    mobileMedia.removeListener(handleViewportChange);
  }
  laptopMedia = null;
  tabletMedia = null;
  mobileMedia = null;
  syncMobileScrollLock(true);
});

function openCallsRegistry() {
  router.push('/admin/calls');
}

function openGrafana() {
  window.open('https://grafana.example.local', '_blank', 'noopener,noreferrer');
}

function resetSettingsDraft() {
  settingsDraft.displayName = sessionState.displayName || '';
  settingsDraft.theme = sessionState.theme || 'dark';
  settingsDraft.timeFormat = sessionState.timeFormat || '24h';
  settingsDraft.language = readStoredSettingsLanguage();
  settingsDraft.avatarDataUrl = '';
}

function setAvatarStatus(message = '') {
  settingsState.avatarStatus = message;
}

function normalizeSettingsTile(tileId) {
  const normalized = String(tileId || '').trim();
  const fallback = 'about-me';
  if (normalized === '') return fallback;
  return settingsTiles.value.some((tile) => tile.id === normalized) ? normalized : fallback;
}

function closeSettingsModal() {
  if (settingsState.saving) return;
  settingsState.open = false;
  settingsState.dragging = false;
  settingsState.loading = false;
  settingsState.message = '';
  settingsState.avatarStatus = '';
  activeSettingsTile.value = 'about-me';
  resetSettingsDraft();
}

function openSettingsModal(tileId = 'about-me') {
  activeSettingsTile.value = normalizeSettingsTile(tileId);
  if (settingsState.open) return;
  settingsState.open = true;
  settingsState.loading = false;
  settingsState.message = '';
  settingsState.avatarStatus = '';
  settingsState.dragging = false;
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
    setAvatarStatus('Avatar must be PNG, JPEG, or WEBP.');
    return;
  }

  try {
    const dataUrl = await readFileAsDataUrl(file);
    settingsDraft.avatarDataUrl = dataUrl;
    setAvatarStatus(`Selected ${file.name}. Save to upload.`);
  } catch (error) {
    setAvatarStatus(error instanceof Error ? error.message : 'Could not prepare avatar upload.');
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
  const theme = settingsDraft.theme.trim();
  const timeFormat = settingsDraft.timeFormat.trim();
  const language = normalizeSettingsLanguage(settingsDraft.language);

  if (displayName === '') {
    settingsState.message = 'Display name is required.';
    return;
  }

  if (theme === '') {
    settingsState.message = 'Theme is required.';
    return;
  }

  if (!['24h', '12h'].includes(timeFormat)) {
    settingsState.message = 'Time format must be 24h or 12h.';
    return;
  }

  if (!SUPPORTED_SETTINGS_LANGUAGES.includes(language)) {
    settingsState.message = 'Unsupported language selected.';
    return;
  }

  settingsState.saving = true;
  try {
    let avatarPath = sessionState.avatarPath || null;
    if (settingsDraft.avatarDataUrl) {
      const uploadResult = await uploadSessionAvatar(settingsDraft.avatarDataUrl);
      if (!uploadResult.ok) {
        settingsState.message = uploadResult.message || 'Avatar upload failed.';
        if (uploadResult.reason === 'invalid_session') {
          settingsState.open = false;
          router.replace('/login');
        }
        return;
      }

      avatarPath = uploadResult.avatarPath || avatarPath;
      setAvatarStatus('Avatar uploaded on the backend.');
    }

    const saveResult = await saveSessionSettings({
      display_name: displayName,
      theme,
      time_format: timeFormat,
      avatar_path: avatarPath,
    });

    if (!saveResult.ok) {
      settingsState.message = saveResult.message || 'Could not save settings.';
      if (saveResult.reason === 'invalid_session') {
        settingsState.open = false;
        router.replace('/login');
      }
      return;
    }

    storeSettingsLanguage(language);
    applySettingsLanguage(language);
    settingsState.message = 'Settings saved.';
    settingsState.open = false;
    resetSettingsDraft();
  } finally {
    settingsState.saving = false;
  }
}

async function handleSignOut() {
  await logoutSession();
  router.replace('/login');
}
</script>
