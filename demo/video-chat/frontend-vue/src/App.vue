<template>
  <RouterView />
</template>

<script setup>
import { onBeforeUnmount, onMounted, watchEffect } from 'vue';
import { RouterView } from 'vue-router';
import { probeBackendRuntime } from './support/runtime';
import { sessionState } from './domain/auth/session';
import {
  applyWorkspaceBrandingDom,
  loadWorkspaceAppearance,
  themeColorsForId,
} from './domain/workspace/appearance';
import { syncI18nDocumentState } from './modules/localization/i18nRuntime.js';

const THEME_PRESETS = {
  dark: {
    '--bg-shell': '#000010',
    '--bg-pane': '#000010',
    '--brand-bg': '#000010',
    '--bg-surface': '#00052d',
    '--bg-surface-strong': '#00052d',
    '--bg-input': '#00052d',
    '--bg-action': '#1582bf',
    '--bg-action-hover': '#59c7f2',
    '--bg-row': '#00052d',
    '--bg-row-hover': '#03275a',
    '--line': '#03275a',
    '--text-main': '#ffffff',
    '--text-muted': '#efefe7',
    '--ok': '#00652f',
    '--wait': '#f47221',
    '--danger': '#ef4423',
    '--bg-sidebar': '#000010',
    '--bg-main': '#000010',
    '--bg-tab': '#00052d',
    '--bg-tab-hover': '#03275a',
    '--bg-tab-active': '#1582bf',
    '--bg-icon': '#00052d',
    '--bg-icon-active': '#1582bf',
    '--border-subtle': '#03275a',
    '--text-primary': '#ffffff',
    '--text-secondary': '#efefe7',
    '--text-dim': '#efefe7',
    '--warn': '#f47221',
    '--brand-cyan': '#1582bf',
    '--brand-cyan-hover': '#59c7f2',
    '--brand-cyan-active': '#1582bf',
  },
  light: {
    '--bg-shell': '#efefe7',
    '--bg-pane': '#efefe7',
    '--brand-bg': '#000010',
    '--bg-surface': '#ffffff',
    '--bg-surface-strong': '#ffffff',
    '--bg-input': '#ffffff',
    '--bg-action': '#1582bf',
    '--bg-action-hover': '#59c7f2',
    '--bg-row': '#ffffff',
    '--bg-row-hover': '#efefe7',
    '--line': '#03275a',
    '--text-main': '#000010',
    '--text-muted': '#03275a',
    '--ok': '#00652f',
    '--wait': '#f47221',
    '--danger': '#ef4423',
    '--bg-sidebar': '#000010',
    '--bg-main': '#efefe7',
    '--bg-tab': '#00052d',
    '--bg-tab-hover': '#03275a',
    '--bg-tab-active': '#1582bf',
    '--bg-icon': '#00052d',
    '--bg-icon-active': '#1582bf',
    '--border-subtle': '#03275a',
    '--text-primary': '#000010',
    '--text-secondary': '#03275a',
    '--text-dim': '#03275a',
    '--warn': '#f47221',
    '--brand-cyan': '#1582bf',
    '--brand-cyan-hover': '#59c7f2',
    '--brand-cyan-active': '#1582bf',
  },
};

const BUILD_VERSION = String(import.meta.env.VIDEOCHAT_ASSET_VERSION || '').trim();
const BUILD_VERSION_HEADER = 'x-kingrt-asset-version';
const BUILD_VERSION_CHECK_INTERVAL_MS = 30000;

let buildVersionGuardTimerId = 0;
let buildVersionGuardListenerBound = false;
let buildVersionCheckPromise = null;
let buildVersionReloadPending = false;

function applyTheme(themeValue) {
  if (typeof document === 'undefined') return;

  const theme = String(themeValue || '').trim().toLowerCase();
  const palette = themeColorsForId(theme) || (theme === 'light' ? THEME_PRESETS.light : THEME_PRESETS.dark);

  for (const [key, value] of Object.entries(palette)) {
    document.documentElement.style.setProperty(key, value);
  }

  document.documentElement.dataset.theme = theme || 'dark';
  document.documentElement.style.colorScheme = theme === 'light' ? 'light' : 'dark';
}

function applyTimeFormat(timeFormatValue) {
  if (typeof document === 'undefined') return;

  const timeFormat = String(timeFormatValue || '').trim().toLowerCase() === '12h' ? '12h' : '24h';
  document.documentElement.dataset.timeFormat = timeFormat;
}

function stopBuildVersionGuard() {
  if (typeof window !== 'undefined' && buildVersionGuardListenerBound) {
    window.removeEventListener('focus', handleBuildVersionGuardTrigger);
    document.removeEventListener('visibilitychange', handleBuildVersionGuardTrigger);
    buildVersionGuardListenerBound = false;
  }
  if (buildVersionGuardTimerId !== 0) {
    window.clearInterval(buildVersionGuardTimerId);
    buildVersionGuardTimerId = 0;
  }
}

async function fetchLiveBuildVersion() {
  const response = await fetch(`/?build_check=${Date.now()}`, {
    method: 'HEAD',
    cache: 'no-store',
    credentials: 'same-origin',
  });
  if (!response.ok) {
    return '';
  }
  return String(response.headers.get(BUILD_VERSION_HEADER) || '').trim();
}

async function checkForBuildVersionMismatch() {
  if (import.meta.env.DEV || buildVersionReloadPending) return;
  if (typeof window === 'undefined' || typeof document === 'undefined') return;
  if (BUILD_VERSION === '' || document.visibilityState === 'hidden') return;
  if (buildVersionCheckPromise) {
    await buildVersionCheckPromise;
    return;
  }

  buildVersionCheckPromise = (async () => {
    try {
      const liveBuildVersion = await fetchLiveBuildVersion();
      if (liveBuildVersion !== '' && liveBuildVersion !== BUILD_VERSION) {
        buildVersionReloadPending = true;
        window.location.reload();
      }
    } catch {
      // Ignore transient network failures while the app is already running.
    }
  })();

  try {
    await buildVersionCheckPromise;
  } finally {
    buildVersionCheckPromise = null;
  }
}

function handleBuildVersionGuardTrigger() {
  void checkForBuildVersionMismatch();
}

function startBuildVersionGuard() {
  if (import.meta.env.DEV || typeof window === 'undefined' || typeof document === 'undefined') return;
  if (BUILD_VERSION === '') return;
  if (!buildVersionGuardListenerBound) {
    window.addEventListener('focus', handleBuildVersionGuardTrigger);
    document.addEventListener('visibilitychange', handleBuildVersionGuardTrigger);
    buildVersionGuardListenerBound = true;
  }
  if (buildVersionGuardTimerId === 0) {
    buildVersionGuardTimerId = window.setInterval(handleBuildVersionGuardTrigger, BUILD_VERSION_CHECK_INTERVAL_MS);
  }
  window.setTimeout(handleBuildVersionGuardTrigger, 2000);
}

watchEffect(() => {
  applyTheme(sessionState.theme);
  applyTimeFormat(sessionState.timeFormat);
  syncI18nDocumentState(sessionState.locale, sessionState.direction);
  applyWorkspaceBrandingDom();
});

onMounted(() => {
  startBuildVersionGuard();
  void loadWorkspaceAppearance({ force: true });
  void probeBackendRuntime();
});

onBeforeUnmount(() => {
  stopBuildVersionGuard();
});
</script>
