<template>
  <section class="settings-section">
    <div class="settings-email-columns">
      <div class="settings-email-list">
        <span class="settings-section-label">{{ t('settings.confirmed_emails') }}</span>
        <div v-if="confirmedEmails.length > 0" class="settings-email-rows">
          <div v-for="email in confirmedEmails" :key="email.id || email.email" class="settings-email-row">
            <span>{{ email.email }}</span>
            <span v-if="email.is_primary" class="settings-email-pill">{{ t('settings.primary') }}</span>
          </div>
        </div>
        <div v-else class="settings-upload-status">{{ t('settings.no_confirmed_emails') }}</div>
      </div>

      <div class="settings-email-list">
        <span class="settings-section-label">{{ t('settings.unconfirmed_emails') }}</span>
        <div v-if="unconfirmedEmails.length > 0" class="settings-email-rows">
          <div v-for="email in unconfirmedEmails" :key="email.id || email.email" class="settings-email-row">
            <span>{{ email.email }}</span>
            <button
              class="icon-mini-btn"
              type="button"
              :disabled="state.loading || state.submitting"
              :aria-label="t('settings.remove_email_address')"
              :title="t('settings.remove_email_address')"
              @click="$emit('delete-email', email.id)"
            >
              <img src="/assets/orgas/kingrt/icons/remove_user.png" alt="" />
            </button>
          </div>
        </div>
        <div v-else class="settings-upload-status">{{ t('settings.no_unconfirmed_emails') }}</div>
      </div>
    </div>

    <form class="settings-inline-form" @submit.prevent="$emit('add-email')">
      <label class="settings-field">
        <span>{{ t('settings.add_email_address') }}</span>
        <input v-model.trim="draft.email" class="input" type="email" autocomplete="email" />
      </label>
      <button class="btn" type="submit" :disabled="state.loading || state.submitting">
        {{ t('settings.add_email_address') }}
      </button>
    </form>
  </section>
</template>

<script setup>
import { t } from '../../modules/localization/i18nRuntime.js';

defineProps({
  confirmedEmails: {
    type: Array,
    default: () => [],
  },
  unconfirmedEmails: {
    type: Array,
    default: () => [],
  },
  draft: {
    type: Object,
    required: true,
  },
  state: {
    type: Object,
    required: true,
  },
});

defineEmits(['add-email', 'delete-email']);
</script>

<style scoped>
.settings-section-label {
  color: var(--text-muted);
  font-weight: 700;
}

.settings-email-columns {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 20px;
}

.settings-email-list,
.settings-email-rows {
  display: grid;
  gap: 10px;
}

.settings-email-row {
  min-height: 40px;
  display: flex;
  gap: 10px;
  align-items: center;
  justify-content: space-between;
  border: 1px solid var(--border-subtle);
  padding: 8px 10px;
  color: var(--text);
  background: var(--surface-navy);
}

.settings-email-row > span:first-child {
  min-width: 0;
  overflow-wrap: anywhere;
}

.settings-email-pill {
  flex: 0 0 auto;
  border: 1px solid var(--border);
  padding: 3px 8px;
  color: var(--text-primary);
  background: var(--border);
  font-size: 12px;
}

.settings-inline-form {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: 20px;
  align-items: end;
}

.settings-inline-form .btn {
  min-height: 42px;
}

@media (max-width: 760px) {
  .settings-email-columns,
  .settings-inline-form {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
