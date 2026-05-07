<template>
  <form class="settings-section settings-password-form" @submit.prevent="$emit('change-password')">
    <div class="settings-row">
      <label class="settings-field">
        <span>{{ t('settings.current_password') }}</span>
        <input
          v-model="draft.currentPassword"
          class="input"
          type="password"
          autocomplete="current-password"
        />
      </label>
      <label class="settings-field">
        <span>{{ t('settings.new_password') }}</span>
        <input
          v-model="draft.newPassword"
          class="input"
          type="password"
          autocomplete="new-password"
        />
      </label>
    </div>
    <div class="settings-row">
      <label class="settings-field">
        <span>{{ t('settings.repeat_new_password') }}</span>
        <input
          v-model="draft.repeatPassword"
          class="input"
          type="password"
          autocomplete="new-password"
        />
      </label>
      <div class="settings-field settings-password-submit">
        <span>&nbsp;</span>
        <button class="btn" type="submit" :disabled="state.loading || state.submitting">
          {{ t('settings.change_password') }}
        </button>
      </div>
    </div>
  </form>
</template>

<script setup>
import { t } from '../../modules/localization/i18nRuntime.js';

defineProps({
  draft: {
    type: Object,
    required: true,
  },
  state: {
    type: Object,
    required: true,
  },
});

defineEmits(['change-password']);
</script>

<style scoped>
.settings-password-submit .btn {
  min-height: 42px;
}

.settings-password-form {
  margin-top: 0;
}
</style>
