const OPERATOR_FEEDBACK_KIND = 'operator_feedback';
const OPERATOR_FEEDBACK_VERSION = 1;

function normalizedText(value, maxLength = 2000) {
  return String(value || '').replace(/\s+/g, ' ').trim().slice(0, maxLength);
}

function normalizedFeatureLabel(value) {
  return normalizedText(value, 140).replace(/'/g, '').trim();
}

function normalizedAttachmentIds(attachments) {
  return (Array.isArray(attachments) ? attachments : [])
    .map((attachment) => String(attachment?.id || attachment || '').trim())
    .filter((id) => id !== '');
}

function normalizedReporter(reporter) {
  const userId = Number(reporter?.user_id || reporter?.userId || 0);
  return {
    user_id: Number.isInteger(userId) && userId > 0 ? userId : 0,
    display_name: normalizedText(reporter?.display_name || reporter?.displayName || '', 160),
    role: normalizedText(reporter?.role || '', 64),
  };
}

export function buildOperatorFeedbackChatPayload({
  callId,
  roomId,
  clientMessageId,
  text,
  attachments,
  reporter,
  createdAt,
  fallbackFeature,
}) {
  const message = String(text || '').trim();
  const requestedFeature = normalizedFeatureLabel(message || fallbackFeature || 'Attachment feedback');

  // Backend OCA-04 is expected to accept the same object either on
  // POST /api/calls/{call_id}/operator-feedback or on chat/send.operator_feedback.
  return {
    kind: OPERATOR_FEEDBACK_KIND,
    version: OPERATOR_FEEDBACK_VERSION,
    status: 'submitted',
    call_id: String(callId || '').trim(),
    room_id: String(roomId || '').trim(),
    client_message_id: String(clientMessageId || '').trim(),
    requested_feature: requestedFeature,
    message,
    attachment_ids: normalizedAttachmentIds(attachments),
    reporter: normalizedReporter(reporter),
    created_at: String(createdAt || new Date().toISOString()),
  };
}

export function buildOperatorFeedbackChatFramePatch(payload) {
  if (!payload || typeof payload !== 'object') return {};
  return {
    operator_feedback: payload,
  };
}

export function normalizeOperatorFeedbackMessageMetadata(payload, message) {
  const directSource = (
    (message && typeof message.operator_feedback === 'object' ? message.operator_feedback : null)
    || (payload && typeof payload.operator_feedback === 'object' ? payload.operator_feedback : null)
  );
  const source = directSource
    || (message && typeof message.feedback === 'object' ? message.feedback : null)
    || (payload && typeof payload.feedback === 'object' ? payload.feedback : null);
  if (!source || typeof source !== 'object') return null;

  const kind = normalizedText(source.kind || source.type || (directSource ? OPERATOR_FEEDBACK_KIND : ''), 64);
  const isOperatorFeedback = kind === OPERATOR_FEEDBACK_KIND
    || source.operator === true
    || source.is_operator_feedback === true;
  if (!isOperatorFeedback) return null;

  return {
    kind: OPERATOR_FEEDBACK_KIND,
    status: normalizedText(source.status || 'submitted', 64),
    requested_feature: normalizedFeatureLabel(source.requested_feature || source.requestedFeature || source.message || ''),
  };
}

export function normalizeOperatorFeedbackDeployment(payload) {
  const source = (
    (payload && typeof payload.operator_feedback_delivery === 'object' ? payload.operator_feedback_delivery : null)
    || (payload?.message && typeof payload.message.operator_feedback_delivery === 'object' ? payload.message.operator_feedback_delivery : null)
    || (payload && typeof payload.operator_feedback === 'object' ? payload.operator_feedback : null)
    || (payload?.message && typeof payload.message.operator_feedback === 'object' ? payload.message.operator_feedback : null)
  );
  if (!source || typeof source !== 'object') return null;

  const status = normalizedText(source.status || source.state || '', 64).toLowerCase();
  if (status !== 'deployed') return null;

  const requestedFeature = normalizedFeatureLabel(
    source.requested_feature
      || source.requestedFeature
      || source.feature
      || source.title
      || source.message
      || '',
  );
  if (requestedFeature === '') return null;
  return { requested_feature: requestedFeature };
}

export function formatOperatorFeedbackDeployedToast(requestedFeature) {
  const feature = normalizedFeatureLabel(requestedFeature) || 'requested feature';
  return `feature '${feature}' deployed`;
}
