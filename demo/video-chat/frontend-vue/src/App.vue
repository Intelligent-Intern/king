<template>
  <RouterView />
</template>

<script setup>
import { onMounted, watchEffect } from 'vue';
import { RouterView } from 'vue-router';
import { probeBackendRuntime } from './support/runtime';
import { sessionState } from './domain/auth/session';

const THEME_PRESETS = {
  dark: {
    '--bg-shell': '#09111e',
    '--bg-pane': '#182c4d',
    '--bg-surface': '#2b3e60',
    '--bg-surface-strong': '#0c1c33',
    '--bg-input': '#d8dadd',
    '--bg-action': '#ffffff',
    '--bg-action-hover': '#5696ef',
    '--bg-row': '#2a569f',
    '--bg-row-hover': '#163260',
    '--line': '#09111e',
    '--text-main': '#edf3ff',
    '--text-muted': '#8490a1',
    '--ok': '#177f22',
    '--wait': '#8d9500',
    '--danger': '#ff0000',
    '--bg-sidebar': '#09111e',
    '--bg-main': '#182c4d',
    '--bg-tab': '#162e51',
    '--bg-tab-hover': '#5696ef',
    '--bg-tab-active': '#2a569f',
    '--bg-icon': '#162e51',
    '--bg-icon-active': '#5696ef',
    '--border-subtle': '#09111e',
    '--text-primary': '#edf3ff',
    '--text-secondary': '#c6d4eb',
    '--text-dim': '#5e6d86',
    '--warn': '#8d9500',
  },
  light: {
    '--bg-shell': '#eff4fb',
    '--bg-pane': '#dce8f6',
    '--bg-surface': '#f4f8fd',
    '--bg-surface-strong': '#ffffff',
    '--bg-input': '#ffffff',
    '--bg-action': '#ffffff',
    '--bg-action-hover': '#9cbcf3',
    '--bg-row': '#b7cdf5',
    '--bg-row-hover': '#8cabdf',
    '--line': '#c4d1e3',
    '--text-main': '#122035',
    '--text-muted': '#5a6780',
    '--ok': '#2e8b57',
    '--wait': '#9a7b00',
    '--danger': '#c62828',
    '--bg-sidebar': '#e8eff8',
    '--bg-main': '#dce8f6',
    '--bg-tab': '#dae7f7',
    '--bg-tab-hover': '#9cbcf3',
    '--bg-tab-active': '#b7cdf5',
    '--bg-icon': '#dae7f7',
    '--bg-icon-active': '#9cbcf3',
    '--border-subtle': '#c4d1e3',
    '--text-primary': '#122035',
    '--text-secondary': '#33425d',
    '--text-dim': '#6d7d96',
    '--warn': '#9a7b00',
  },
};

function applyTheme(themeValue) {
  if (typeof document === 'undefined') return;

  const theme = String(themeValue || '').trim().toLowerCase();
  const palette = theme === 'light' ? THEME_PRESETS.light : THEME_PRESETS.dark;

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

watchEffect(() => {
  applyTheme(sessionState.theme);
  applyTimeFormat(sessionState.timeFormat);
});

onMounted(() => {
  void probeBackendRuntime();
});
</script>
