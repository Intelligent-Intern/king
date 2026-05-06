import { nextTick, reactive, ref } from 'vue';

function sanitizeCancelMessageHtml(value) {
  const html = String(value || '');
  if (typeof window === 'undefined') {
    return html.trim();
  }

  const container = document.createElement('div');
  container.innerHTML = html;
  for (const node of container.querySelectorAll('script,style')) {
    node.remove();
  }
  for (const element of container.querySelectorAll('*')) {
    for (const attribute of Array.from(element.attributes)) {
      const attributeName = String(attribute.name || '').toLowerCase();
      if (attributeName.startsWith('on')) {
        element.removeAttribute(attribute.name);
      }
    }
  }

  return container.innerHTML.trim();
}

function normalizeCancelMessageHtml(value) {
  return sanitizeCancelMessageHtml(value).replace(/>\s+</g, '><').trim();
}

function cancelMessageHtmlToPlainText(value) {
  const html = String(value || '');
  if (typeof window === 'undefined') {
    return html.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
  }

  const container = document.createElement('div');
  container.innerHTML = html;
  return String(container.textContent || '').replace(/\s+/g, ' ').trim();
}

function prettifyCancelReason(reason) {
  const normalized = String(reason || '').trim().replace(/[_-]+/g, ' ');
  if (normalized === '') return 'Custom template';
  return normalized.charAt(0).toUpperCase() + normalized.slice(1);
}

const CANCEL_TEMPLATE_STORAGE_KEY = 'king.video.calls.cancel.templates.v1';
const DEFAULT_CANCEL_TEMPLATES = Object.freeze([
  {
    reason: 'scheduler_conflict',
    label: 'Scheduler conflict',
    messageHtml: '<p>Call cancelled due to scheduling conflict.</p>',
  },
  {
    reason: 'host_unavailable',
    label: 'Host unavailable',
    messageHtml: '<p>Call cancelled because the host is currently unavailable.</p>',
  },
  {
    reason: 'technical_issue',
    label: 'Technical issue',
    messageHtml: '<p>Call cancelled due to a technical issue. We will reschedule shortly.</p>',
  },
  {
    reason: 'emergency_stop',
    label: 'Emergency stop',
    messageHtml: '<p>Call cancelled due to an urgent operational reason.</p>',
  },
]);

function normalizeCancelTemplateItem(rawTemplate, index) {
  const rawReason = String(rawTemplate?.reason || '').trim().toLowerCase();
  const reason = rawReason.replace(/[^a-z0-9_]+/g, '_').replace(/^_+|_+$/g, '');
  if (reason === '') {
    return null;
  }

  const fallbackLabel = prettifyCancelReason(reason);
  const label = String(rawTemplate?.label || '').trim() || fallbackLabel;
  const rawMessage = String(rawTemplate?.messageHtml || rawTemplate?.message || '').trim();
  const messageHtml = normalizeCancelMessageHtml(rawMessage || `<p>${fallbackLabel}.</p>`);

  return {
    id: `${reason}-${index}`,
    reason,
    label,
    messageHtml,
  };
}

function cloneCancelTemplateList(list) {
  return list
    .map((entry, index) => normalizeCancelTemplateItem(entry, index))
    .filter((entry) => entry !== null);
}

function loadCancelTemplates() {
  const fallback = cloneCancelTemplateList(DEFAULT_CANCEL_TEMPLATES);
  if (typeof window === 'undefined') {
    return fallback;
  }

  try {
    const raw = window.localStorage.getItem(CANCEL_TEMPLATE_STORAGE_KEY);
    if (typeof raw !== 'string' || raw.trim() === '') {
      return fallback;
    }
    const parsed = JSON.parse(raw);
    if (!Array.isArray(parsed)) {
      return fallback;
    }
    const templates = cloneCancelTemplateList(parsed);
    return templates.length > 0 ? templates : fallback;
  } catch {
    return fallback;
  }
}

function persistCancelTemplates(templates) {
  if (typeof window === 'undefined') return;
  try {
    window.localStorage.setItem(
      CANCEL_TEMPLATE_STORAGE_KEY,
      JSON.stringify(
        templates.map((template) => ({
          reason: template.reason,
          label: template.label,
          messageHtml: template.messageHtml,
        })),
      ),
    );
  } catch {
    // ignore storage failures
  }
}

export function createCancelDeleteController({
  apiRequest,
  clearNotice,
  setNotice,
  publishAdminSync,
  loadCalls,
  loadCalendar,
  closeEnterCallModal,
  isDeletable,
}) {
  const cancelTemplates = ref(loadCancelTemplates());
  const cancelEditorRef = ref(null);

  const cancelState = reactive({
    open: false,
    submitting: false,
    templateSaving: false,
    error: '',
    callId: '',
    callTitle: '',
    overrideTemplate: true,
    selectedTemplateId: '',
    customReason: '',
    reason: '',
    messageHtml: '',
    templateDirty: false,
  });

  const deleteState = reactive({
    open: false,
    submitting: false,
    error: '',
    callId: '',
    callTitle: '',
  });

  function findCancelTemplate(reason) {
    const normalizedReason = String(reason || '').trim().toLowerCase();
    if (normalizedReason === '') return null;
    return cancelTemplates.value.find((template) => template.reason === normalizedReason) || null;
  }

  function syncCancelEditorFromState() {
    const normalizedHtml = normalizeCancelMessageHtml(cancelState.messageHtml);
    cancelState.messageHtml = normalizedHtml;
    nextTick(() => {
      const editor = cancelEditorRef.value;
      if (!(editor instanceof HTMLElement)) return;
      if (editor.innerHTML !== normalizedHtml) {
        editor.innerHTML = normalizedHtml;
      }
    });
  }

  function refreshCancelTemplateDirty() {
    if (!cancelState.overrideTemplate) {
      cancelState.templateDirty = false;
      return;
    }

    const template = findCancelTemplate(cancelState.selectedTemplateId);
    if (!template) {
      cancelState.templateDirty = false;
      return;
    }

    const currentHtml = normalizeCancelMessageHtml(cancelState.messageHtml);
    const templateHtml = normalizeCancelMessageHtml(template.messageHtml);
    cancelState.templateDirty = currentHtml !== templateHtml;
  }

  function applyCancelTemplate(reason) {
    const template = findCancelTemplate(reason);
    if (!template) {
      cancelState.reason = String(reason || '').trim();
      cancelState.messageHtml = '';
      cancelState.templateDirty = false;
      syncCancelEditorFromState();
      return;
    }

    cancelState.selectedTemplateId = template.reason;
    cancelState.reason = template.reason;
    cancelState.messageHtml = template.messageHtml;
    cancelState.templateDirty = false;
    syncCancelEditorFromState();
  }

  function openCancel(call) {
    clearNotice();
    closeEnterCallModal();
    cancelState.open = true;
    cancelState.submitting = false;
    cancelState.templateSaving = false;
    cancelState.error = '';
    cancelState.callId = String(call?.id || '');
    cancelState.callTitle = String(call?.title || call?.id || '');
    cancelState.overrideTemplate = true;
    cancelState.customReason = '';
    const preferredTemplate = findCancelTemplate('scheduler_conflict');
    const defaultTemplate = preferredTemplate || cancelTemplates.value[0] || null;
    cancelState.selectedTemplateId = defaultTemplate ? defaultTemplate.reason : '';
    applyCancelTemplate(cancelState.selectedTemplateId);
  }

  function closeCancel() {
    cancelState.open = false;
    cancelState.submitting = false;
    cancelState.templateSaving = false;
    cancelState.error = '';
  }

  function handleCancelEditorInput() {
    const editor = cancelEditorRef.value;
    if (!(editor instanceof HTMLElement)) return;
    cancelState.messageHtml = normalizeCancelMessageHtml(editor.innerHTML);
    refreshCancelTemplateDirty();
  }

  function execCancelEditorCommand(commandName, commandValue = null) {
    if (typeof document === 'undefined') return;
    const editor = cancelEditorRef.value;
    if (!(editor instanceof HTMLElement)) return;
    editor.focus();
    document.execCommand(commandName, false, commandValue);
    handleCancelEditorInput();
  }

  function toggleCancelOverride(nextValue) {
    cancelState.overrideTemplate = Boolean(nextValue);
    cancelState.error = '';

    if (!cancelState.overrideTemplate) {
      cancelState.customReason = cancelState.reason || cancelState.selectedTemplateId || '';
      cancelState.templateDirty = false;
      return;
    }

    const defaultReason = String(cancelState.selectedTemplateId || cancelTemplates.value[0]?.reason || '').trim();
    if (defaultReason !== '') {
      applyCancelTemplate(defaultReason);
    }
  }

  async function saveCancelTemplate() {
    cancelState.error = '';
    if (!cancelState.overrideTemplate) return;

    const template = findCancelTemplate(cancelState.selectedTemplateId);
    if (!template) {
      cancelState.error = 'Select a template first.';
      return;
    }

    const messageHtml = normalizeCancelMessageHtml(cancelState.messageHtml);
    const plainText = cancelMessageHtmlToPlainText(messageHtml);
    if (plainText === '') {
      cancelState.error = 'Cancel message is required.';
      return;
    }

    cancelState.templateSaving = true;
    try {
      const nextTemplates = cancelTemplates.value
        .map((entry, index) => {
          if (entry.reason !== template.reason) return entry;
          return normalizeCancelTemplateItem({
            reason: entry.reason,
            label: entry.label,
            messageHtml,
          }, index);
        })
        .filter((entry) => entry !== null);

      cancelTemplates.value = nextTemplates;
      persistCancelTemplates(nextTemplates);
      cancelState.templateDirty = false;
    } finally {
      cancelState.templateSaving = false;
    }
  }

  function openDelete(call) {
    if (!call || !call.id || !isDeletable(call)) {
      return;
    }

    clearNotice();
    closeEnterCallModal();
    deleteState.open = true;
    deleteState.submitting = false;
    deleteState.error = '';
    deleteState.callId = String(call.id || '');
    deleteState.callTitle = String(call.title || call.id || '');
  }

  function closeDelete() {
    deleteState.open = false;
    deleteState.submitting = false;
    deleteState.error = '';
  }

  async function submitCancel() {
    cancelState.error = '';
    clearNotice();

    const reason = cancelState.overrideTemplate
      ? cancelState.reason.trim()
      : cancelState.customReason.trim();
    const message = cancelMessageHtmlToPlainText(cancelState.messageHtml).trim();
    if (reason === '' || message === '') {
      cancelState.error = 'Cancel reason and message are required.';
      return;
    }

    cancelState.submitting = true;

    try {
      await apiRequest(`/api/calls/${encodeURIComponent(cancelState.callId)}/cancel`, {
        method: 'POST',
        body: {
          cancel_reason: reason,
          cancel_message: message,
        },
      });

      closeCancel();
      setNotice('ok', 'Call cancelled.');
      publishAdminSync('calls', 'call_cancelled');
      await Promise.all([loadCalls(), loadCalendar()]);
    } catch (error) {
      cancelState.error = error instanceof Error ? error.message : 'Could not cancel call.';
    } finally {
      cancelState.submitting = false;
    }
  }

  async function submitDelete() {
    deleteState.error = '';
    clearNotice();

    const callId = String(deleteState.callId || '').trim();
    if (callId === '') {
      deleteState.error = 'Missing call id.';
      return;
    }

    deleteState.submitting = true;
    try {
      await apiRequest(`/api/calls/${encodeURIComponent(callId)}`, {
        method: 'DELETE',
      });

      closeDelete();
      setNotice('ok', 'Call deleted.');
      publishAdminSync('calls', 'call_deleted');
      await Promise.all([loadCalls(), loadCalendar()]);
    } catch (error) {
      deleteState.error = error instanceof Error ? error.message : 'Could not delete call.';
    } finally {
      deleteState.submitting = false;
    }
  }

  return {
    cancelTemplates,
    cancelEditorRef,
    cancelState,
    deleteState,
    openCancel,
    closeCancel,
    applyCancelTemplate,
    handleCancelEditorInput,
    execCancelEditorCommand,
    toggleCancelOverride,
    saveCancelTemplate,
    openDelete,
    closeDelete,
    submitCancel,
    submitDelete,
  };
}
