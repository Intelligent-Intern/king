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
import {
  STYLEGUIDE_DERIVED_COLOR_KEYS,
  defaultWorkspaceThemeColors,
  normalizeStyleguideThemeColors,
} from './domain/workspace/styleguidePalette';
import { syncI18nDocumentState } from './modules/localization/i18nRuntime.js';

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
  const fallbackPalette = defaultWorkspaceThemeColors(theme === 'light' ? 'light' : 'dark');
  const palette = normalizeStyleguideThemeColors(themeColorsForId(theme) || fallbackPalette, fallbackPalette);

  for (const key of STYLEGUIDE_DERIVED_COLOR_KEYS) {
    document.documentElement.style.removeProperty(key);
  }

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
