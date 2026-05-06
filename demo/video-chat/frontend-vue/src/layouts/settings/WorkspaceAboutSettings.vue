<template>
  <section class="settings-panel">
    <div class="settings-row">
      <label class="settings-field">
        <span>{{ t('settings.display_name') }}</span>
        <input v-model.trim="draft.displayName" class="input" type="text" autocomplete="name" />
      </label>
      <div class="settings-field">
        <span>{{ t('settings.email') }}</span>
        <div class="settings-readonly-value">{{ email || t('common.not_available') }}</div>
      </div>
    </div>

    <div class="settings-row">
      <div class="settings-field">
        <span>{{ t('settings.avatar_preview') }}</span>
        <img class="settings-avatar-preview-lg" :src="avatarPreviewSrc" :alt="t('settings.avatar_preview')" />
      </div>
      <div class="settings-field">
        <label
          class="settings-dropzone"
          :class="{ 'is-over': state.dragging }"
          for="settings-avatar-input"
          @dragenter.prevent="state.dragging = true"
          @dragover.prevent="state.dragging = true"
          @dragleave.prevent="state.dragging = false"
          @drop.prevent="$emit('avatar-drop', $event)"
        >
          <input
            id="settings-avatar-input"
            class="settings-hidden-input"
            type="file"
            accept="image/png,image/jpeg,image/webp"
            @change="$emit('avatar-select', $event)"
          />
          <span class="settings-dropzone-title">{{ t('settings.avatar_drop_title') }}</span>
          <span class="settings-dropzone-subtitle">{{ t('settings.avatar_drop_subtitle') }}</span>
        </label>
        <div class="settings-upload-status">{{ state.avatarStatus }}</div>
      </div>
    </div>

    <label class="settings-field settings-field-wide">
      <span>{{ t('settings.about_me') }}</span>
      <textarea v-model.trim="draft.aboutMe" class="input settings-profile-textarea" rows="4"></textarea>
    </label>

    <div class="settings-row">
      <label class="settings-field">
        <span>{{ t('settings.linkedin_url') }}</span>
        <input v-model.trim="draft.linkedinUrl" class="input" type="url" autocomplete="url" />
      </label>
      <label class="settings-field">
        <span>{{ t('settings.x_url') }}</span>
        <input v-model.trim="draft.xUrl" class="input" type="url" autocomplete="url" />
      </label>
    </div>

    <div class="settings-row">
      <label class="settings-field">
        <span>{{ t('settings.youtube_url') }}</span>
        <input v-model.trim="draft.youtubeUrl" class="input" type="url" autocomplete="url" />
      </label>
    </div>

    <section class="settings-field settings-field-wide">
      <div class="settings-messenger-head">
        <span>{{ t('settings.messenger_contacts') }}</span>
        <button class="btn" type="button" @click="addMessengerContact">{{ t('settings.add_messenger_contact') }}</button>
      </div>
      <div class="settings-messenger-list">
        <div
          v-for="(contact, index) in messengerContacts"
          :key="contact.localId || index"
          class="settings-messenger-row"
        >
          <input
            v-model.trim="contact.channel"
            class="input"
            type="text"
            :aria-label="t('settings.messenger_channel')"
            :placeholder="t('settings.messenger_channel')"
          />
          <input
            v-model.trim="contact.handle"
            class="input"
            type="text"
            :aria-label="t('settings.messenger_handle')"
            :placeholder="t('settings.messenger_handle')"
          />
          <button
            class="icon-mini-btn"
            type="button"
            :aria-label="t('settings.remove_messenger_contact', { number: index + 1 })"
            :title="t('settings.remove_messenger_contact', { number: index + 1 })"
            @click="removeMessengerContact(index)"
          >
            <img src="/assets/orgas/kingrt/icons/remove_user.png" alt="" />
          </button>
        </div>
        <div v-if="messengerContacts.length === 0" class="settings-upload-status">
          {{ t('settings.no_messenger_contacts') }}
        </div>
      </div>
    </section>

    <section class="settings-field settings-field-wide">
      <div class="settings-messenger-head">
        <span>{{ t('settings.onboarding_badges') }}</span>
      </div>
      <div v-if="onboardingBadges.length > 0" class="settings-onboarding-badges">
        <span v-for="badge in onboardingBadges" :key="badge.tour_key" class="settings-onboarding-badge">
          {{ badgeLabel(badge.tour_key) }}
        </span>
      </div>
      <div v-else class="settings-upload-status">
        {{ t('settings.no_onboarding_badges') }}
      </div>
    </section>
  </section>
</template>

<script setup>
import { computed } from 'vue';
import { sessionState } from '../../domain/auth/session';
import { t } from '../../modules/localization/i18nRuntime.js';

const props = defineProps({
  draft: {
    type: Object,
    required: true,
  },
  state: {
    type: Object,
    required: true,
  },
  email: {
    type: String,
    default: '',
  },
  avatarPreviewSrc: {
    type: String,
    required: true,
  },
});

defineEmits(['avatar-select', 'avatar-drop']);

const messengerContacts = computed(() => (
  Array.isArray(props.draft.messengerContacts) ? props.draft.messengerContacts : []
));
const onboardingBadges = computed(() => (
  Array.isArray(sessionState.onboardingBadges)
    ? sessionState.onboardingBadges.filter((badge) => badge && typeof badge === 'object' && String(badge.tour_key || '').trim() !== '')
    : []
));

function badgeLabel(tourKey) {
  const normalizedKey = String(tourKey || '').trim().toLowerCase();
  const translationKey = `onboarding.badge.${normalizedKey.replace(/[^a-z0-9]+/g, '_')}`;
  const translated = t(translationKey);
  if (translated !== translationKey) return translated;
  return normalizedKey.replace(/[._:-]+/g, ' ');
}

function addMessengerContact() {
  if (!Array.isArray(props.draft.messengerContacts)) {
    props.draft.messengerContacts = [];
  }
  props.draft.messengerContacts.push({
    localId: `messenger-${Date.now()}-${props.draft.messengerContacts.length}`,
    channel: '',
    handle: '',
  });
}

function removeMessengerContact(index) {
  if (!Array.isArray(props.draft.messengerContacts)) return;
  props.draft.messengerContacts.splice(index, 1);
}
</script>

<style scoped>
.settings-field-wide {
  display: grid;
  gap: 6px;
  margin-top: 12px;
}

.settings-profile-textarea {
  min-height: 96px;
  resize: vertical;
}

.settings-messenger-head {
  display: flex;
  gap: 10px;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
}

.settings-messenger-list {
  display: grid;
  gap: 8px;
}

.settings-messenger-row {
  display: grid;
  grid-template-columns: minmax(0, 180px) minmax(0, 1fr) 34px;
  gap: 8px;
  align-items: center;
}

.settings-onboarding-badges {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}

.settings-onboarding-badge {
  max-width: 100%;
  border: 1px solid var(--border-subtle);
  border-radius: 999px;
  padding: 5px 9px;
  color: var(--text);
  background: var(--bg-ui-soft);
  font-size: 12px;
  overflow-wrap: anywhere;
}

@media (max-width: 760px) {
  .settings-messenger-row {
    grid-template-columns: minmax(0, 1fr) 34px;
  }

  .settings-messenger-row input:first-child {
    grid-column: 1 / -1;
  }
}
</style>
