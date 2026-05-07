import { computed, onMounted, reactive } from 'vue';
import { sessionState } from '../../../domain/auth/session';
import { loadWorkspaceAdministration, saveWorkspaceAdministration } from '../../../domain/workspace/administrationApi';

export function useAppConfigurationEmailSettings(options = {}) {
  const translate = typeof options.t === 'function' ? options.t : (key) => key;
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
  const isPrimaryAdmin = computed(() => Number(sessionState.userId || 0) === 1);

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
  }

  async function load() {
    if (!isPrimaryAdmin.value) return;
    state.loading = true;
    state.error = '';
    state.message = '';
    try {
      applySettings(await loadWorkspaceAdministration());
    } catch (error) {
      state.error = error instanceof Error ? error.message : translate('administration.load_settings_failed');
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
      state.message = translate('administration.settings_saved');
    } catch (error) {
      state.error = error instanceof Error ? error.message : translate('administration.save_settings_failed');
    } finally {
      state.saving = false;
    }
  }

  onMounted(() => {
    void load();
  });

  return {
    state,
    draft,
    isPrimaryAdmin,
    applySettings,
    buildPayload,
    load,
    save,
  };
}
