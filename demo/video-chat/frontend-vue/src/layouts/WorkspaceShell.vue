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

          <div class="tabs tabs-left" role="tablist" aria-label="Call left sidebar tabs">
            <button
              class="tab"
              :class="{ active: callLeftTab === 'settings' }"
              type="button"
              role="tab"
              :aria-selected="callLeftTab === 'settings'"
              @click="setCallLeftTab('settings')"
            >
              <img class="tab-icon" src="/assets/orgas/kingrt/icons/gear.png" alt="" />
            </button>
            <button
              class="tab"
              :class="{ active: callLeftTab === 'background' }"
              type="button"
              role="tab"
              :aria-selected="callLeftTab === 'background'"
              @click="setCallLeftTab('background')"
            >
              <img class="tab-icon" src="/assets/orgas/kingrt/icons/desktop.png" alt="" />
            </button>
          </div>

          <section class="tab-panel panel-settings" :class="{ active: callLeftTab === 'settings' }">
            <div class="call-left-settings">
              <section class="call-left-settings-block" aria-label="Camera">
                <div class="call-left-settings-title">Camera</div>
                <div class="call-left-settings-field">
                  <select
                    id="call-left-camera-select"
                    class="input call-left-select"
                    aria-label="Camera"
                    :value="callMediaPrefs.selectedCameraId"
                    @change="setCallCameraDevice($event.target.value)"
                  >
                    <option value="">{{ callMediaPrefs.cameras.length === 0 ? 'No camera detected' : 'Select camera' }}</option>
                    <option
                      v-for="camera in callMediaPrefs.cameras"
                      :key="camera.id"
                      :value="camera.id"
                    >
                      {{ camera.label }}
                    </option>
                  </select>
                  <div class="call-left-settings-value">Active: {{ activeCameraLabel }}</div>
                </div>
              </section>

              <section class="call-left-settings-block" aria-label="Mic">
                <div class="call-left-settings-title">Mic</div>
                <div class="call-left-settings-field">
                  <select
                    id="call-left-mic-select"
                    class="input call-left-select"
                    aria-label="Mic"
                    :value="callMediaPrefs.selectedMicrophoneId"
                    @change="setCallMicrophoneDevice($event.target.value)"
                  >
                    <option value="">{{ callMediaPrefs.microphones.length === 0 ? 'No microphone detected' : 'Select mic' }}</option>
                    <option
                      v-for="microphone in callMediaPrefs.microphones"
                      :key="microphone.id"
                      :value="microphone.id"
                    >
                      {{ microphone.label }}
                    </option>
                  </select>
                  <div class="call-left-settings-value">Active: {{ activeMicrophoneLabel }}</div>
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
                  <select
                    id="call-left-speaker-select"
                    class="input call-left-select"
                    aria-label="Speaker"
                    :value="callMediaPrefs.selectedSpeakerId"
                    @change="setCallSpeakerDevice($event.target.value)"
                  >
                    <option value="">{{ callMediaPrefs.speakers.length === 0 ? 'No speaker detected' : 'Select speaker' }}</option>
                    <option
                      v-for="speaker in callMediaPrefs.speakers"
                      :key="speaker.id"
                      :value="speaker.id"
                    >
                      {{ speaker.label }}
                    </option>
                  </select>
                  <div class="call-left-settings-value">Active: {{ activeSpeakerLabel }}</div>
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

              <div v-if="callMediaPrefs.error" class="call-left-settings-error">{{ callMediaPrefs.error }}</div>
            </div>
          </section>

          <section class="tab-panel panel-background" :class="{ active: callLeftTab === 'background' }">
            <div class="call-left-backgrounds">
              <button class="btn full" type="button">No blur</button>
              <button class="btn full" type="button">Slight blur</button>
              <button class="btn full" type="button">Strong blur</button>
            </div>
          </section>
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
        <div v-if="isMobileViewport" class="mobile-brand-strip">
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
                  <button class="btn" type="button" @click="openCallsRegistry">Open Calls</button>
                  <button class="btn" type="button" @click="openGrafana">Open Grafana</button>
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
          <img src="/assets/orgas/kingrt/icon.svg" alt="" />
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
          class="settings-tile"
          :class="{ active: activeSettingsTile === tile.id }"
          type="button"
          :disabled="settingsState.loading"
          @click="activeSettingsTile = tile.id"
        >
          {{ tile.label }}
        </button>
      </div>

      <section v-if="activeSettingsTile === 'about-me'" class="settings-panel">
        <h4>About Me</h4>
        <p>Configure your profile avatar for the sidebar.</p>

        <div class="settings-row">
          <label class="settings-field">
            <span>Display name</span>
            <input v-model.trim="settingsDraft.displayName" class="ii-input" type="text" autocomplete="name" />
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

      <section v-else-if="activeSettingsTile === 'credentials'" class="settings-panel">
        <h4>Credentials</h4>
        <p>Password and OAuth provider settings are managed in backend auth settings.</p>
      </section>

      <section v-else-if="activeSettingsTile === 'theme'" class="settings-panel">
        <h4>Theme</h4>
        <p>Choose visual mode for your workspace.</p>

        <div class="settings-row">
          <label class="settings-field">
            <span>Theme</span>
            <input
              v-model.trim="settingsDraft.theme"
              class="ii-input"
              type="text"
              autocomplete="off"
              placeholder="dark"
            />
          </label>
        </div>
      </section>

      <section v-else-if="activeSettingsTile === 'general'" class="settings-panel">
        <h4>{{ sessionState.role === 'admin' ? 'General' : 'Workspace' }}</h4>
        <p>Branding and icon set management follow the same workflow as in the mock settings.</p>
      </section>

      <section v-else-if="activeSettingsTile === 'regional-time'" class="settings-panel">
        <h4>Regional Time</h4>
        <p>Select how date and time should be displayed across the workspace.</p>

        <div class="settings-row">
          <label class="settings-field">
            <span>Time format</span>
            <select v-model="settingsDraft.timeFormat" class="ii-input">
              <option value="24h">24h</option>
              <option value="12h">12h</option>
            </select>
          </label>
        </div>
      </section>

      <section v-else-if="activeSettingsTile === 'email-texts'" class="settings-panel">
        <h4>Email Texts</h4>
        <p>Email templates and transport profile are configured in backend mail settings.</p>
      </section>

      <div class="settings-actions">
        <button class="btn" type="button" :disabled="settingsState.saving" @click="closeSettingsModal">Cancel</button>
        <button class="btn" type="button" :disabled="settingsState.saving || settingsState.loading" @click="saveSettings">
          {{ settingsState.saving ? 'Saving…' : 'Save settings' }}
        </button>
      </div>

      <div class="settings-upload-status">{{ settingsState.message }}</div>
    </div>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, provide, reactive, ref, watch } from 'vue';
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router';
import {
  logoutSession,
  saveSessionSettings,
  sessionState,
  uploadSessionAvatar,
} from '../domain/auth/session';
import {
  attachCallMediaDeviceWatcher,
  callMediaPrefs,
  setCallCameraDevice,
  setCallMicrophoneDevice,
  setCallMicrophoneVolume,
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
  if (route.path === '/admin/overview') {
    return 'Monitor active rooms, cluster health and planned call capacity.';
  }
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
const callLeftTab = ref('settings');
const micLevelPercent = ref(0);
const activeCameraLabel = computed(() => resolveSelectedDeviceLabel(
  callMediaPrefs.cameras,
  callMediaPrefs.selectedCameraId,
  'No camera detected'
));
const activeMicrophoneLabel = computed(() => resolveSelectedDeviceLabel(
  callMediaPrefs.microphones,
  callMediaPrefs.selectedMicrophoneId,
  'No microphone detected'
));
const activeSpeakerLabel = computed(() => resolveSelectedDeviceLabel(
  callMediaPrefs.speakers,
  callMediaPrefs.selectedSpeakerId,
  'No speaker detected'
));
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
  { id: 'credentials', label: 'Credentials' },
  { id: 'theme', label: 'Theme' },
  { id: 'general', label: sessionState.role === 'admin' ? 'General' : 'Workspace' },
  { id: 'regional-time', label: 'Regional Time' },
  { id: 'email-texts', label: 'Email Texts' },
]));

const settingsAvatarPreviewSrc = computed(() => settingsDraft.avatarDataUrl || profileAvatarSrc.value);

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

function setCallLeftTab(tabId) {
  callLeftTab.value = tabId === 'background' ? 'background' : 'settings';
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
  if (!isCallWorkspace.value || callLeftTab.value !== 'settings') return;
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

function resolveSelectedDeviceLabel(devices, selectedId, emptyLabel) {
  if (!Array.isArray(devices) || devices.length === 0) return emptyLabel;
  const normalizedId = String(selectedId || '').trim();
  const selected = devices.find((device) => String(device?.id || '') === normalizedId);
  const fallback = devices[0];
  const candidate = selected || fallback;
  const label = String(candidate?.label || '').trim();
  return label === '' ? 'Unknown' : label;
}

provide('workspaceSidebarState', {
  leftSidebarCollapsed,
  isTabletViewport,
  isMobileViewport,
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
  () => [isCallWorkspace.value, callLeftTab.value, callMediaPrefs.selectedMicrophoneId],
  ([inCallWorkspace, activeTab]) => {
    if (inCallWorkspace && activeTab === 'settings') {
      void startMicLevelMonitor();
      return;
    }
    stopMicLevelMonitor();
  },
  { immediate: true }
);

onMounted(() => {
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
