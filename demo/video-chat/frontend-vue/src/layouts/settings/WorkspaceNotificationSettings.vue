<template>
  <section class="settings-panel settings-notification-panel">
    <section class="settings-section settings-notification-permission">
      <div>
        <span class="settings-section-label">{{ t('settings.web_app_notifications') }}</span>
        <p class="settings-helper">{{ t('settings.web_app_notifications_description') }}</p>
        <p class="settings-upload-status">{{ permissionStatusLabel }}</p>
      </div>
      <button
        class="btn"
        type="button"
        :disabled="!notificationSupported || permissionStatus === 'granted'"
        @click="requestBrowserPermission"
      >
        {{ permissionButtonLabel }}
      </button>
    </section>

    <div class="settings-notification-grid">
      <label class="settings-notification-card">
        <input v-model="draft.webAppNotificationsEnabled" type="checkbox" />
        <span>
          <strong>{{ t('settings.enable_web_app_notifications') }}</strong>
          <small>{{ t('settings.enable_web_app_notifications_description') }}</small>
        </span>
      </label>

      <label class="settings-notification-card">
        <input v-model="draft.webAppNotificationCallInvitesEnabled" type="checkbox" />
        <span>
          <strong>{{ t('settings.notification_call_invites') }}</strong>
          <small>{{ t('settings.notification_call_invites_description') }}</small>
        </span>
      </label>

      <label class="settings-notification-card">
        <input v-model="draft.webAppNotificationCallRemindersEnabled" type="checkbox" />
        <span>
          <strong>{{ t('settings.notification_call_reminders') }}</strong>
          <small>{{ t('settings.notification_call_reminders_description') }}</small>
        </span>
      </label>

      <label class="settings-notification-card">
        <input v-model="draft.webAppNotificationChatMentionsEnabled" type="checkbox" />
        <span>
          <strong>{{ t('settings.notification_chat_mentions') }}</strong>
          <small>{{ t('settings.notification_chat_mentions_description') }}</small>
        </span>
      </label>

      <label class="settings-notification-card">
        <input v-model="draft.webAppNotificationSoundEnabled" type="checkbox" />
        <span>
          <strong>{{ t('settings.notification_sound') }}</strong>
          <small>{{ t('settings.notification_sound_description') }}</small>
        </span>
      </label>
    </div>
  </section>
</template>

<script setup>
import { computed, ref } from 'vue';
import { t } from '../../modules/localization/i18nRuntime.js';

defineProps({
  draft: {
    type: Object,
    required: true,
  },
});

const notificationSupported = typeof window !== 'undefined' && 'Notification' in window;
const permissionStatus = ref(notificationSupported ? window.Notification.permission : 'unsupported');

const permissionStatusLabel = computed(() => {
  if (!notificationSupported) return t('settings.browser_notifications_unsupported');
  if (permissionStatus.value === 'granted') return t('settings.browser_notifications_granted');
  if (permissionStatus.value === 'denied') return t('settings.browser_notifications_blocked');
  return t('settings.browser_notifications_default');
});

const permissionButtonLabel = computed(() => (
  permissionStatus.value === 'granted'
    ? t('settings.browser_notifications_allowed')
    : t('settings.request_browser_notifications')
));

async function requestBrowserPermission() {
  if (!notificationSupported || permissionStatus.value === 'granted') return;
  try {
    const nextPermission = await window.Notification.requestPermission();
    permissionStatus.value = nextPermission;
  } catch {
    permissionStatus.value = window.Notification.permission || 'default';
  }
}
</script>

<style scoped>
.settings-notification-panel {
  gap: 20px;
}

.settings-section-label {
  color: var(--text-muted);
  font-weight: 700;
}

.settings-helper {
  max-width: 680px;
  margin: 6px 0 0;
  color: var(--text-muted);
}

.settings-notification-permission {
  display: flex;
  gap: 20px;
  align-items: flex-start;
  justify-content: space-between;
}

.settings-notification-permission .btn {
  min-height: 42px;
  flex: 0 0 auto;
}

.settings-notification-grid {
  display: grid;
  gap: 12px;
}

.settings-notification-card {
  min-height: 58px;
  display: grid;
  grid-template-columns: auto minmax(0, 1fr);
  gap: 12px;
  align-items: center;
  border: 1px solid var(--border);
  padding: 12px;
  background: var(--surface-navy);
  color: var(--text);
}

.settings-notification-card input {
  width: 18px;
  height: 18px;
  accent-color: var(--cyan-primary);
}

.settings-notification-card span {
  display: grid;
  gap: 3px;
}

.settings-notification-card strong {
  color: var(--text-primary);
}

.settings-notification-card small {
  color: var(--text-muted);
}

@media (max-width: 760px) {
  .settings-notification-permission {
    display: grid;
  }
}
</style>
