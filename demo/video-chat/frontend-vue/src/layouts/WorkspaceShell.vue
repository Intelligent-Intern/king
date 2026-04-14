<template>
  <main class="app">
    <div class="shell no-right-sidebar" :class="shellClasses">
      <aside class="sidebar sidebar-left" :class="leftSidebarClasses">
        <div class="sidebar-content left">
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

const router = useRouter();
const route = useRoute();
const leftSidebarCollapsed = ref(false);
const isTabletSidebarOpen = ref(false);
const isMobileSidebarOpen = ref(false);
const viewportMode = ref('desktop');
let laptopMedia = null;
let tabletMedia = null;
let mobileMedia = null;
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

const pageSubtitle = computed(() => {
  if (route.path === '/admin/overview') {
    return 'Monitor active rooms, cluster health and planned call capacity.';
  }
  return '';
});
const showWorkspaceHeader = computed(() => !['/admin/users', '/admin/calls'].includes(route.path));

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
}));
const leftSidebarClasses = computed(() => ({
  collapsed: (isDesktopLikeViewport.value && leftSidebarCollapsed.value) || (isMobileViewport.value && !isMobileSidebarOpen.value),
}));

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
