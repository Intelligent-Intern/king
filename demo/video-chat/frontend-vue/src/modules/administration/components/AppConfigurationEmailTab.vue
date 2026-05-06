<template>
  <form class="app-config-tab app-config-email" autocomplete="off" @submit.prevent="save">
    <section v-if="state.loading" class="settings-upload-status">{{ t('administration.loading_settings') }}</section>
    <section v-if="state.error" class="settings-upload-status error">{{ state.error }}</section>
    <section v-if="state.message" class="settings-upload-status">{{ state.message }}</section>

    <section v-if="isPrimaryAdmin" class="app-config-form-grid">
      <section class="settings-section">
        <header class="settings-subhead">
          <h5>{{ t('administration.mail_server') }}</h5>
        </header>
        <section class="settings-row">
          <label class="settings-field">
            <span>{{ t('administration.sender_email') }}</span>
            <input v-model.trim="draft.mail_from_email" class="input" type="email" autocomplete="email" />
          </label>
          <label class="settings-field">
            <span>{{ t('administration.sender_name') }}</span>
            <input v-model.trim="draft.mail_from_name" class="input" type="text" autocomplete="organization" />
          </label>
        </section>
        <section class="settings-row">
          <label class="settings-field">
            <span>{{ t('administration.smtp_host') }}</span>
            <input v-model.trim="draft.mail_smtp_host" class="input" type="text" autocomplete="off" />
          </label>
          <label class="settings-field">
            <span>{{ t('administration.smtp_port') }}</span>
            <input v-model.number="draft.mail_smtp_port" class="input" type="number" min="1" max="65535" />
          </label>
        </section>
        <section class="settings-row">
          <label class="settings-field">
            <span>{{ t('administration.encryption') }}</span>
            <AppSelect v-model="draft.mail_smtp_encryption">
              <option value="starttls">{{ t('administration.encryption_starttls') }}</option>
              <option value="ssl">{{ t('administration.encryption_ssl_tls') }}</option>
              <option value="none">{{ t('administration.encryption_none') }}</option>
            </AppSelect>
          </label>
          <label class="settings-field">
            <span>{{ t('administration.smtp_username') }}</span>
            <input v-model.trim="draft.mail_smtp_username" class="input" type="text" autocomplete="username" />
          </label>
        </section>
        <section class="settings-row">
          <label class="settings-field">
            <span>{{ t('administration.smtp_password') }}</span>
            <input
              v-model="draft.mail_smtp_password"
              class="input"
              type="password"
              autocomplete="new-password"
              :placeholder="draft.mail_smtp_password_set ? t('administration.password_keep_placeholder') : ''"
            />
          </label>
          <label class="settings-field settings-password-clear">
            <span>{{ t('administration.password_state') }}</span>
            <label class="settings-checkbox-row">
              <input v-model="draft.mail_smtp_password_clear" type="checkbox" />
              <span>{{ t('administration.clear_saved_password') }}</span>
            </label>
          </label>
        </section>
      </section>

      <section class="settings-section">
        <header class="settings-subhead">
          <h5>{{ t('administration.lead_recipients') }}</h5>
        </header>
        <section class="settings-recipient-list">
          <div v-for="(recipient, index) in leadRecipients" :key="recipient.id" class="settings-recipient-row">
            <input
              v-model.trim="recipient.email"
              class="input"
              type="email"
              :placeholder="t('administration.lead_recipient_placeholder')"
              autocomplete="email"
            />
            <button
              class="icon-mini-btn danger"
              type="button"
              :aria-label="t('administration.remove_recipient', { number: index + 1 })"
              @click="removeLeadRecipient(index)"
            >
              <img src="/assets/orgas/kingrt/icons/remove_user.png" alt="" />
            </button>
          </div>
          <button class="icon-mini-btn" type="button" :aria-label="t('administration.add_lead_recipient')" @click="addLeadRecipient">
            <img src="/assets/orgas/kingrt/icons/add.png" alt="" />
          </button>
        </section>
      </section>
    </section>

    <section v-else class="settings-upload-status">
      {{ t('administration.primary_admin_only_email') }}
    </section>

    <footer v-if="isPrimaryAdmin" class="app-config-actions">
      <button class="btn btn-cyan" type="submit" :disabled="state.saving">
        {{ state.saving ? t('settings.saving') : t('administration.save_configuration') }}
      </button>
    </footer>
  </form>
</template>

<script setup>
import { computed, onMounted, reactive } from 'vue';
import AppSelect from '../../../components/AppSelect.vue';
import { sessionState } from '../../../domain/auth/session';
import { loadWorkspaceAdministration, saveWorkspaceAdministration } from '../../../domain/workspace/administrationApi';
import { t } from '../../localization/i18nRuntime.js';

const state = reactive({
  loading: false,
  saving: false,
  error: '',
  message: '',
});
const draft = reactive({
  mail_from_email: '',
  mail_from_name: '',
  mail_smtp_host: '',
  mail_smtp_port: 587,
  mail_smtp_encryption: 'starttls',
  mail_smtp_username: '',
  mail_smtp_password: '',
  mail_smtp_password_clear: false,
  mail_smtp_password_set: false,
});
const leadRecipients = reactive([]);
const isPrimaryAdmin = computed(() => Number(sessionState.userId || 0) === 1);

function addLeadRecipient() {
  leadRecipients.push({ id: globalThis.crypto?.randomUUID?.() || `${Date.now()}-${Math.random()}`, email: '' });
}

function removeLeadRecipient(index) {
  leadRecipients.splice(index, 1);
  if (leadRecipients.length === 0) addLeadRecipient();
}

function replaceRecipients(emails = []) {
  leadRecipients.splice(0, leadRecipients.length, ...emails.map((email) => ({
    id: globalThis.crypto?.randomUUID?.() || `${Date.now()}-${Math.random()}`,
    email: String(email || ''),
  })));
  if (leadRecipients.length === 0) addLeadRecipient();
}

function applySettings(result) {
  const settings = result?.settings || {};
  draft.mail_from_email = String(settings.mail_from_email || '');
  draft.mail_from_name = String(settings.mail_from_name || '');
  draft.mail_smtp_host = String(settings.mail_smtp_host || '');
  draft.mail_smtp_port = Number.parseInt(String(settings.mail_smtp_port || 587), 10) || 587;
  draft.mail_smtp_encryption = ['none', 'ssl', 'starttls'].includes(String(settings.mail_smtp_encryption || ''))
    ? String(settings.mail_smtp_encryption)
    : 'starttls';
  draft.mail_smtp_username = String(settings.mail_smtp_username || '');
  draft.mail_smtp_password = '';
  draft.mail_smtp_password_clear = false;
  draft.mail_smtp_password_set = Boolean(settings.mail_smtp_password_set);
  replaceRecipients(settings.lead_recipients || []);
}

async function load() {
  if (!isPrimaryAdmin.value) return;
  state.loading = true;
  state.error = '';
  state.message = '';
  try {
    applySettings(await loadWorkspaceAdministration());
  } catch (error) {
    state.error = error instanceof Error ? error.message : t('administration.load_settings_failed');
  } finally {
    state.loading = false;
  }
}

function buildPayload() {
  const payload = {
    mail_from_email: draft.mail_from_email,
    mail_from_name: draft.mail_from_name,
    mail_smtp_host: draft.mail_smtp_host,
    mail_smtp_port: draft.mail_smtp_port,
    mail_smtp_encryption: draft.mail_smtp_encryption,
    mail_smtp_username: draft.mail_smtp_username,
    mail_smtp_password_clear: draft.mail_smtp_password_clear,
    lead_recipients: leadRecipients.map((row) => row.email).filter(Boolean),
  };
  if (String(draft.mail_smtp_password || '').trim() !== '') {
    payload.mail_smtp_password = draft.mail_smtp_password;
  }
  return payload;
}

async function save() {
  if (!isPrimaryAdmin.value || state.saving) return;
  state.saving = true;
  state.error = '';
  state.message = '';
  try {
    applySettings(await saveWorkspaceAdministration(buildPayload()));
    state.message = t('administration.settings_saved');
  } catch (error) {
    state.error = error instanceof Error ? error.message : t('administration.save_settings_failed');
  } finally {
    state.saving = false;
  }
}

onMounted(() => {
  void load();
});
</script>

<style scoped>
.app-config-tab {
  height: 100%;
  min-height: 0;
  overflow: auto;
}

.app-config-form-grid {
  display: grid;
  gap: 20px;
}

.settings-subhead {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
}

.settings-subhead h5 {
  margin: 0;
  font-size: 13px;
  color: var(--text-main);
}

.settings-checkbox-row,
.settings-recipient-row {
  min-height: 38px;
  display: flex;
  align-items: center;
  gap: 8px;
}

.settings-checkbox-row {
  border: 1px solid var(--border-subtle);
  border-radius: 0;
  padding: 0 10px;
}

.settings-recipient-list {
  display: grid;
  gap: 8px;
}

.settings-recipient-row {
  grid-template-columns: minmax(0, 1fr) auto;
}

.settings-upload-status.error {
  color: var(--color-heading);
}

.app-config-actions {
  display: flex;
  justify-content: flex-end;
  padding: 20px 0 0;
}

@media (max-width: 760px) {
  .settings-row {
    grid-template-columns: 1fr;
  }
}
</style>
