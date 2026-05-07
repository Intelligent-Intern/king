<template>
  <form class="settings-section workspace-administration-settings" autocomplete="off" @submit.prevent>
    <h4 v-if="title">{{ title }}</h4>

    <section v-if="state.loading" class="settings-upload-status">{{ t('administration.loading_settings') }}</section>
    <section v-if="state.error" class="settings-upload-status error">{{ state.error }}</section>

    <section class="settings-section">
      <header class="settings-subhead">
        <h5>{{ t('administration.calendar_booking_email') }}</h5>
        <button class="btn" type="button" @click="resetBookingEmailDefaults">{{ t('administration.reset_default') }}</button>
      </header>
      <label class="settings-field">
        <span>{{ t('administration.subject_template') }}</span>
        <input v-model.trim="bookingDraft.mail_subject_template" class="input" type="text" />
      </label>
      <label class="settings-field">
        <span>{{ t('administration.body_template') }}</span>
        <textarea v-model="bookingDraft.mail_body_template" class="settings-textarea" rows="8"></textarea>
      </label>
    </section>

    <section v-if="isPrimaryAdmin" class="settings-section">
      <header class="settings-subhead">
        <h5>{{ t('administration.mail_server') }}</h5>
      </header>
      <section class="settings-row">
        <label class="settings-field">
          <span>{{ t('administration.sender_email') }}</span>
          <input v-model.trim="adminDraft.mail_from_email" class="input" type="email" autocomplete="email" />
        </label>
        <label class="settings-field">
          <span>{{ t('administration.sender_name') }}</span>
          <input v-model.trim="adminDraft.mail_from_name" class="input" type="text" autocomplete="organization" />
        </label>
      </section>
      <section class="settings-row">
        <label class="settings-field">
          <span>{{ t('administration.smtp_host') }}</span>
          <input v-model.trim="adminDraft.mail_smtp_host" class="input" type="text" autocomplete="off" />
        </label>
        <label class="settings-field">
          <span>{{ t('administration.smtp_port') }}</span>
          <input v-model.number="adminDraft.mail_smtp_port" class="input" type="number" min="1" max="65535" />
        </label>
      </section>
      <section class="settings-row">
        <label class="settings-field">
          <span>{{ t('administration.encryption') }}</span>
          <AppSelect v-model="adminDraft.mail_smtp_encryption">
            <option value="starttls">{{ t('administration.encryption_starttls') }}</option>
            <option value="ssl">{{ t('administration.encryption_ssl_tls') }}</option>
            <option value="none">{{ t('administration.encryption_none') }}</option>
          </AppSelect>
        </label>
        <label class="settings-field">
          <span>{{ t('administration.smtp_username') }}</span>
          <input v-model.trim="adminDraft.mail_smtp_username" class="input" type="text" autocomplete="username" />
        </label>
      </section>
      <section class="settings-row">
        <label class="settings-field">
          <span>{{ t('administration.smtp_password') }}</span>
          <input
            v-model="adminDraft.mail_smtp_password"
            class="input"
            type="password"
            autocomplete="new-password"
            :placeholder="adminDraft.mail_smtp_password_set ? t('administration.password_keep_placeholder') : ''"
          />
        </label>
        <label class="settings-field settings-password-clear">
          <span>{{ t('administration.password_state') }}</span>
          <label class="settings-checkbox-row">
            <input v-model="adminDraft.mail_smtp_password_clear" type="checkbox" />
            <span>{{ t('administration.clear_saved_password') }}</span>
          </label>
        </label>
      </section>
    </section>

    <section v-if="isPrimaryAdmin" class="settings-section">
      <header class="settings-subhead">
        <h5>{{ t('administration.website_lead_email') }}</h5>
        <button class="btn" type="button" @click="resetLeadEmailDefaults">{{ t('administration.reset_default') }}</button>
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
      <label class="settings-field">
        <span>{{ t('administration.lead_subject_template') }}</span>
        <input v-model.trim="adminDraft.lead_subject_template" class="input" type="text" />
      </label>
      <label class="settings-field">
        <span>{{ t('administration.lead_body_template') }}</span>
        <textarea v-model="adminDraft.lead_body_template" class="settings-textarea" rows="8"></textarea>
      </label>
    </section>

    <section v-if="isPrimaryAdmin" class="settings-section">
      <header class="settings-subhead">
        <h5>{{ t('administration.branding_logos') }}</h5>
      </header>
      <section class="settings-row">
        <label class="settings-field">
          <span>{{ t('theme_settings.left_sidebar_logo') }}</span>
          <img class="settings-logo-preview" :src="sidebarLogoPreview" alt="" />
          <input class="input" type="file" accept="image/png,image/jpeg,image/webp" :aria-label="t('theme_settings.left_sidebar_logo')" @change="selectLogo($event, 'sidebar')" />
          <button class="btn" type="button" @click="resetLogo('sidebar')">{{ t('administration.restore_default') }}</button>
        </label>
        <label class="settings-field">
          <span>{{ t('theme_settings.modal_logo') }}</span>
          <img class="settings-logo-preview" :src="modalLogoPreview" alt="" />
          <input class="input" type="file" accept="image/png,image/jpeg,image/webp" :aria-label="t('theme_settings.modal_logo')" @change="selectLogo($event, 'modal')" />
          <button class="btn" type="button" @click="resetLogo('modal')">{{ t('administration.restore_default') }}</button>
        </label>
      </section>
    </section>

    <section v-if="!isPrimaryAdmin" class="settings-upload-status">
      {{ t('administration.primary_admin_only') }}
    </section>

    <section class="settings-placeholder-list" :aria-label="t('administration.email_placeholders')">
      <span v-for="placeholder in placeholders" :key="placeholder">{{ placeholder }}</span>
    </section>
  </form>
</template>

<script setup>
import { computed, onMounted, reactive } from 'vue';
import AppSelect from '../../components/AppSelect.vue';
import { sessionState } from '../../domain/auth/session';
import { loadAppointmentSettings, saveAppointmentSettings } from '../../domain/calls/appointment/appointmentCalendarApi';
import { loadWorkspaceAdministration, saveWorkspaceAdministration } from '../../domain/workspace/administrationApi';
import { applyAppearancePayload } from '../../domain/workspace/appearance';
import { t } from '../../modules/localization/i18nRuntime.js';

defineProps({
  title: {
    type: String,
    default: '',
  },
});

const DEFAULT_LOGO = '/assets/orgas/kingrt/logo.svg';
const placeholders = Object.freeze([
  '{recipient_name}', '{recipient_role}', '{join_link}', '{google_calendar_url}', '{call_title}',
  '{starts_at}', '{ends_at}', '{guest_name}', '{guest_email}', '{owner_name}', '{owner_email}', '{message}',
  '{name}', '{email}', '{company}', '{participants}', '{role}', '{use_case}', '{timing}', '{notes}',
]);
const state = reactive({ loading: false, error: '' });
const bookingDefaults = reactive({
  mail_subject_template: 'Video call scheduled: {call_title}',
  mail_body_template: '',
});
const bookingDraft = reactive({
  mail_subject_template: '',
  mail_body_template: '',
  reset: false,
});
const adminDefaults = reactive({
  lead_subject_template: 'New website lead: {name}',
  lead_body_template: '',
  sidebar_logo_path: DEFAULT_LOGO,
  modal_logo_path: DEFAULT_LOGO,
});
const adminDraft = reactive({
  mail_from_email: '',
  mail_from_name: '',
  mail_smtp_host: '',
  mail_smtp_port: 587,
  mail_smtp_encryption: 'starttls',
  mail_smtp_username: '',
  mail_smtp_password: '',
  mail_smtp_password_clear: false,
  mail_smtp_password_set: false,
  lead_subject_template: '',
  lead_body_template: '',
  sidebar_logo_path: DEFAULT_LOGO,
  sidebar_logo_data_url: '',
  sidebar_logo_reset: false,
  modal_logo_path: DEFAULT_LOGO,
  modal_logo_data_url: '',
  modal_logo_reset: false,
});
const leadRecipients = reactive([]);

const isPrimaryAdmin = computed(() => Number(sessionState.userId || 0) === 1);
const sidebarLogoPreview = computed(() => adminDraft.sidebar_logo_data_url || adminDraft.sidebar_logo_path || DEFAULT_LOGO);
const modalLogoPreview = computed(() => adminDraft.modal_logo_data_url || adminDraft.modal_logo_path || DEFAULT_LOGO);

function applyBookingSettings(result) {
  const settings = result?.settings || {};
  const defaults = result?.defaults || {};
  bookingDefaults.mail_subject_template = String(defaults.mail_subject_template || bookingDefaults.mail_subject_template);
  bookingDefaults.mail_body_template = String(defaults.mail_body_template || bookingDefaults.mail_body_template);
  bookingDraft.mail_subject_template = String(settings.mail_subject_template || bookingDefaults.mail_subject_template);
  bookingDraft.mail_body_template = String(settings.mail_body_template || bookingDefaults.mail_body_template);
  bookingDraft.reset = false;
}

function replaceRecipients(emails = []) {
  leadRecipients.splice(0, leadRecipients.length, ...emails.map((email) => ({
    id: globalThis.crypto?.randomUUID?.() || `${Date.now()}-${Math.random()}`,
    email: String(email || ''),
  })));
  if (leadRecipients.length === 0) addLeadRecipient();
}

function applyAdminSettings(result) {
  const settings = result?.settings || {};
  const defaults = result?.appearance?.defaults || {};
  adminDefaults.lead_subject_template = String(defaults.lead_subject_template || adminDefaults.lead_subject_template);
  adminDefaults.lead_body_template = String(defaults.lead_body_template || adminDefaults.lead_body_template);
  adminDefaults.sidebar_logo_path = String(defaults.sidebar_logo_path || DEFAULT_LOGO);
  adminDefaults.modal_logo_path = String(defaults.modal_logo_path || DEFAULT_LOGO);
  adminDraft.mail_from_email = String(settings.mail_from_email || '');
  adminDraft.mail_from_name = String(settings.mail_from_name || '');
  adminDraft.mail_smtp_host = String(settings.mail_smtp_host || '');
  adminDraft.mail_smtp_port = Number.parseInt(String(settings.mail_smtp_port || 587), 10) || 587;
  adminDraft.mail_smtp_encryption = ['none', 'ssl', 'starttls'].includes(String(settings.mail_smtp_encryption || ''))
    ? String(settings.mail_smtp_encryption)
    : 'starttls';
  adminDraft.mail_smtp_username = String(settings.mail_smtp_username || '');
  adminDraft.mail_smtp_password = '';
  adminDraft.mail_smtp_password_clear = false;
  adminDraft.mail_smtp_password_set = Boolean(settings.mail_smtp_password_set);
  adminDraft.lead_subject_template = String(settings.lead_subject_template || adminDefaults.lead_subject_template);
  adminDraft.lead_body_template = String(settings.lead_body_template || adminDefaults.lead_body_template);
  adminDraft.sidebar_logo_path = String(settings.sidebar_logo_path || adminDefaults.sidebar_logo_path);
  adminDraft.sidebar_logo_data_url = '';
  adminDraft.sidebar_logo_reset = false;
  adminDraft.modal_logo_path = String(settings.modal_logo_path || adminDefaults.modal_logo_path);
  adminDraft.modal_logo_data_url = '';
  adminDraft.modal_logo_reset = false;
  replaceRecipients(settings.lead_recipients || []);
  if (result?.appearance) {
    applyAppearancePayload(result.appearance);
  }
}

async function load() {
  state.loading = true;
  state.error = '';
  try {
    const booking = await loadAppointmentSettings();
    applyBookingSettings(booking);
    if (isPrimaryAdmin.value) {
      applyAdminSettings(await loadWorkspaceAdministration());
    }
  } catch (error) {
    state.error = error instanceof Error ? error.message : t('administration.load_settings_failed');
  } finally {
    state.loading = false;
  }
}

function resetBookingEmailDefaults() {
  bookingDraft.mail_subject_template = bookingDefaults.mail_subject_template;
  bookingDraft.mail_body_template = bookingDefaults.mail_body_template;
  bookingDraft.reset = true;
}

function resetLeadEmailDefaults() {
  adminDraft.lead_subject_template = adminDefaults.lead_subject_template;
  adminDraft.lead_body_template = adminDefaults.lead_body_template;
}

function addLeadRecipient() {
  leadRecipients.push({ id: globalThis.crypto?.randomUUID?.() || `${Date.now()}-${Math.random()}`, email: '' });
}

function removeLeadRecipient(index) {
  leadRecipients.splice(index, 1);
  if (leadRecipients.length === 0) addLeadRecipient();
}

function readFileAsDataUrl(file) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(typeof reader.result === 'string' ? reader.result : '');
    reader.onerror = () => reject(new Error(t('theme_settings.image_read_failed')));
    reader.readAsDataURL(file);
  });
}

async function selectLogo(event, kind) {
  const file = event?.target?.files?.[0] || null;
  if (!file) return;
  if (!['image/png', 'image/jpeg', 'image/webp'].includes(file.type)) {
    state.error = t('theme_settings.logo_type_invalid');
    return;
  }
  const dataUrl = await readFileAsDataUrl(file);
  if (kind === 'modal') {
    adminDraft.modal_logo_data_url = dataUrl;
    adminDraft.modal_logo_reset = false;
  } else {
    adminDraft.sidebar_logo_data_url = dataUrl;
    adminDraft.sidebar_logo_reset = false;
  }
}

function resetLogo(kind) {
  if (kind === 'modal') {
    adminDraft.modal_logo_path = adminDefaults.modal_logo_path;
    adminDraft.modal_logo_data_url = '';
    adminDraft.modal_logo_reset = true;
  } else {
    adminDraft.sidebar_logo_path = adminDefaults.sidebar_logo_path;
    adminDraft.sidebar_logo_data_url = '';
    adminDraft.sidebar_logo_reset = true;
  }
}

function buildAdminPayload() {
  const payload = {
    mail_from_email: adminDraft.mail_from_email,
    mail_from_name: adminDraft.mail_from_name,
    mail_smtp_host: adminDraft.mail_smtp_host,
    mail_smtp_port: adminDraft.mail_smtp_port,
    mail_smtp_encryption: adminDraft.mail_smtp_encryption,
    mail_smtp_username: adminDraft.mail_smtp_username,
    mail_smtp_password_clear: adminDraft.mail_smtp_password_clear,
    lead_recipients: leadRecipients.map((row) => row.email).filter(Boolean),
    lead_subject_template: adminDraft.lead_subject_template,
    lead_body_template: adminDraft.lead_body_template,
    sidebar_logo_reset: adminDraft.sidebar_logo_reset,
    modal_logo_reset: adminDraft.modal_logo_reset,
  };
  if (String(adminDraft.mail_smtp_password || '').trim() !== '') {
    payload.mail_smtp_password = adminDraft.mail_smtp_password;
  }
  if (adminDraft.sidebar_logo_data_url) payload.sidebar_logo_data_url = adminDraft.sidebar_logo_data_url;
  if (adminDraft.modal_logo_data_url) payload.modal_logo_data_url = adminDraft.modal_logo_data_url;
  return payload;
}

async function save() {
  state.error = '';
  try {
    const bookingPayload = bookingDraft.reset
      ? { mail_templates_reset: true }
      : {
          mail_subject_template: bookingDraft.mail_subject_template,
          mail_body_template: bookingDraft.mail_body_template,
        };
    applyBookingSettings(await saveAppointmentSettings(bookingPayload));
    if (isPrimaryAdmin.value) {
      const adminResult = await saveWorkspaceAdministration(buildAdminPayload());
      applyAdminSettings(adminResult);
    }
    return { ok: true, message: t('administration.settings_saved') };
  } catch (error) {
    const message = error instanceof Error ? error.message : t('administration.save_settings_failed');
    state.error = message;
    return { ok: false, message };
  }
}

onMounted(() => {
  void load();
});

defineExpose({ save, load });
</script>

<style scoped>
.workspace-administration-settings {
  min-height: 0;
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

.settings-textarea {
  width: 100%;
  min-height: 190px;
  border: 1px solid var(--border-subtle);
  border-radius: 6px;
  background: var(--bg-input);
  color: var(--color-surface-navy);
  padding: 8px 10px;
  resize: vertical;
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
  border-radius: 6px;
  padding: 0 10px;
}

.settings-recipient-list {
  display: grid;
  gap: 8px;
}

.settings-recipient-row {
  grid-template-columns: minmax(0, 1fr) auto;
}

.settings-logo-preview {
  background: var(--brand-bg);
}

.settings-placeholder-list {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

.settings-placeholder-list span {
  border: 1px solid var(--border-subtle);
  border-radius: 6px;
  background: var(--color-border);
  color: var(--text-muted);
  font-size: 11px;
  padding: 4px 7px;
}

.settings-upload-status.error {
  color: var(--color-heading);
}

@media (max-width: 760px) {
  .settings-row {
    grid-template-columns: 1fr;
  }
}
</style>
