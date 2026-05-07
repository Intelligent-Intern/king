import { computed, onMounted, reactive } from 'vue';
import {
  addSessionEmailAddress,
  changeSessionPassword,
  deleteSessionEmailAddress,
  fetchSessionEmailAddresses,
} from '../../domain/auth/session';

export function useWorkspaceCredentialsSettings(options = {}) {
  const translate = typeof options.t === 'function' ? options.t : (key) => key;
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

  function replaceEmails(rows) {
    emails.splice(0, emails.length, ...normalizeEmailRows(rows));
  }

  async function loadEmails() {
    state.loading = true;
    state.message = '';
    try {
      const result = await fetchSessionEmailAddresses();
      if (!result.ok) {
        state.message = result.message || translate('settings.email_load_failed');
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
        state.message = result.message || translate('settings.email_add_failed');
        return;
      }
      draft.email = '';
      await loadEmails();
      state.message = translate('settings.email_confirmation_sent');
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
        state.message = result.message || translate('settings.email_delete_failed');
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
      state.message = translate('settings.password_mismatch');
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
        state.message = result.message || translate('settings.password_change_failed');
        return;
      }
      draft.currentPassword = '';
      draft.newPassword = '';
      draft.repeatPassword = '';
      state.message = translate('settings.password_changed');
    } finally {
      state.submitting = false;
    }
  }

  onMounted(() => {
    void loadEmails();
  });

  return {
    draft,
    state,
    confirmedEmails,
    unconfirmedEmails,
    addEmail,
    deleteEmail,
    changePassword,
    loadEmails,
  };
}

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
