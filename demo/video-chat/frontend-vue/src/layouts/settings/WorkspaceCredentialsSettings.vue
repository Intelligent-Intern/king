<template>
  <section class="settings-panel settings-credentials-panel">
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
                @click="deleteEmail(email.id)"
              >
                <img src="/assets/orgas/kingrt/icons/remove_user.png" alt="" />
              </button>
            </div>
          </div>
          <div v-else class="settings-upload-status">{{ t('settings.no_unconfirmed_emails') }}</div>
        </div>
      </div>

      <form class="settings-inline-form" @submit.prevent="addEmail">
        <label class="settings-field">
          <span>{{ t('settings.add_email_address') }}</span>
          <input v-model.trim="draft.email" class="input" type="email" autocomplete="email" />
        </label>
        <button class="btn" type="submit" :disabled="state.loading || state.submitting">
          {{ t('settings.add_email_address') }}
        </button>
      </form>
    </section>

    <form class="settings-section settings-password-form" @submit.prevent="changePassword">
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

    <div class="settings-upload-status">{{ state.message }}</div>
  </section>
</template>

<script setup>
import { computed, onMounted, reactive } from 'vue';
import {
  addSessionEmailAddress,
  changeSessionPassword,
  deleteSessionEmailAddress,
  fetchSessionEmailAddresses,
} from '../../domain/auth/session';
import { t } from '../../modules/localization/i18nRuntime.js';

const emails = reactive([]);
const draft = reactive({
  email: '',
  currentPassword: '',
  newPassword: '',
  repeatPassword: '',
});
const state = reactive({
  loading: false,
  submitting: false,
  message: '',
});

const confirmedEmails = computed(() => emails.filter((email) => email.is_verified === true));
const unconfirmedEmails = computed(() => emails.filter((email) => email.is_verified !== true));

function normalizeEmailRows(rows) {
  if (!Array.isArray(rows)) return [];
  return rows
    .map((row) => {
      const source = row && typeof row === 'object' ? row : {};
      const id = Number.parseInt(String(source.id || 0), 10);
      return {
        id: Number.isInteger(id) && id > 0 ? id : 0,
        email: String(source.email || '').trim(),
        is_verified: source.is_verified === true,
        is_primary: source.is_primary === true,
      };
    })
    .filter((row) => row.email !== '');
}

function replaceEmails(rows) {
  emails.splice(0, emails.length, ...normalizeEmailRows(rows));
}

async function loadEmails() {
  state.loading = true;
  state.message = '';
  try {
    const result = await fetchSessionEmailAddresses();
    if (!result.ok) {
      state.message = result.message || t('settings.email_load_failed');
      return;
    }
    replaceEmails(result.emails);
  } finally {
    state.loading = false;
  }
}

async function addEmail() {
  if (state.submitting) return;
  state.submitting = true;
  state.message = '';
  try {
    const result = await addSessionEmailAddress(draft.email);
    if (!result.ok) {
      state.message = result.message || t('settings.email_add_failed');
      return;
    }
    draft.email = '';
    await loadEmails();
    state.message = t('settings.email_confirmation_sent');
  } finally {
    state.submitting = false;
  }
}

async function deleteEmail(emailId) {
  if (state.submitting) return;
  state.submitting = true;
  state.message = '';
  try {
    const result = await deleteSessionEmailAddress(emailId);
    if (!result.ok) {
      state.message = result.message || t('settings.email_delete_failed');
      return;
    }
    await loadEmails();
  } finally {
    state.submitting = false;
  }
}

async function changePassword() {
  if (state.submitting) return;
  if (draft.newPassword !== draft.repeatPassword) {
    state.message = t('settings.password_mismatch');
    return;
  }
  state.submitting = true;
  state.message = '';
  try {
    const result = await changeSessionPassword({
      currentPassword: draft.currentPassword,
      newPassword: draft.newPassword,
      repeatPassword: draft.repeatPassword,
    });
    if (!result.ok) {
      state.message = result.message || t('settings.password_change_failed');
      return;
    }
    draft.currentPassword = '';
    draft.newPassword = '';
    draft.repeatPassword = '';
    state.message = t('settings.password_changed');
  } finally {
    state.submitting = false;
  }
}

onMounted(() => {
  loadEmails();
});
</script>

<style scoped>
.settings-credentials-panel {
  gap: 18px;
}

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

.settings-inline-form .btn,
.settings-password-submit .btn {
  min-height: 42px;
}

.settings-password-form {
  margin-top: 0;
}

@media (max-width: 760px) {
  .settings-email-columns,
  .settings-inline-form {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
