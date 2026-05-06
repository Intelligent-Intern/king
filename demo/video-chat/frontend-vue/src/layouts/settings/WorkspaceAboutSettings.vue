<template>
  <section class="settings-panel">
    <div class="settings-row">
      <label class="settings-field">
        <span>{{ t('settings.display_name') }}</span>
        <input v-model.trim="draft.displayName" class="input" type="text" autocomplete="name" />
      </label>
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

  </section>
</template>

<script setup>
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
  avatarPreviewSrc: {
    type: String,
    required: true,
  },
});

defineEmits(['avatar-select', 'avatar-drop']);
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

</style>
