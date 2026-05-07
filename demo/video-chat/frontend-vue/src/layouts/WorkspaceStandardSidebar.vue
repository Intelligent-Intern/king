<template>
  <div class="sidebar-content left">
    <div class="brand-strip">
      <img data-brand-logo :src="sidebarLogoSrc" alt="KingRT" />
      <button
        class="sidebar-toggle-btn"
        type="button"
        :title="leftSidebarToggleLabel"
        :aria-label="leftSidebarToggleLabel"
        @click="$emit('toggle-sidebar')"
      >
        <span v-if="isMobileViewport" class="sidebar-close-mark" aria-hidden="true">x</span>
        <img v-else class="arrow-icon-image" :src="leftSidebarToggleIcon" alt="" />
      </button>
    </div>

    <div class="sidebar-scroll-body">
      <WorkspaceNavigation
        :role="role"
        :current-path="currentPath"
        @navigate="$emit('navigate')"
      />

      <section class="sidebar-profile avatar-only">
        <button class="sidebar-avatar-trigger" type="button" :aria-label="t('common.open_settings')" @click="$emit('open-settings')">
          <img
            class="sidebar-avatar-image"
            :src="profileAvatarSrc"
            :alt="t('common.profile_avatar')"
          />
        </button>
      </section>

      <div class="logout-wrap">
        <button class="btn full" type="button" @click="$emit('sign-out')">{{ t('common.log_out') }}</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import WorkspaceNavigation from './WorkspaceNavigation.vue';
import { t } from '../modules/localization/i18nRuntime.js';

defineProps({
  sidebarLogoSrc: {
    type: String,
    required: true,
  },
  leftSidebarToggleLabel: {
    type: String,
    required: true,
  },
  leftSidebarToggleIcon: {
    type: String,
    required: true,
  },
  isMobileViewport: {
    type: Boolean,
    default: false,
  },
  role: {
    type: String,
    default: '',
  },
  currentPath: {
    type: String,
    required: true,
  },
  profileAvatarSrc: {
    type: String,
    required: true,
  },
});

defineEmits([
  'toggle-sidebar',
  'navigate',
  'open-settings',
  'sign-out',
]);
</script>
