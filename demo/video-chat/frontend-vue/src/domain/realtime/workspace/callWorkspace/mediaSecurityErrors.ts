export function mediaSecurityErrorCode(error) {
  const code = String(error?.code || '').trim().toLowerCase();
  if (code === 'participant_set_mismatch') return 'participant_set_mismatch';
  if (code === 'downgrade_attempt') return 'downgrade_attempt';

  const message = String(error?.message || error || '').trim().toLowerCase();
  if (message.includes('participant_set_mismatch')) return 'participant_set_mismatch';
  if (message.includes('downgrade_attempt') || message.includes('downgrade')) return 'downgrade_attempt';
  if (message.includes('wrong_key_id')) return 'wrong_key_id';
  if (message.includes('wrong_epoch')) return 'wrong_epoch';
  if (message.includes('malformed_protected_frame')) return 'malformed_protected_frame';
  return message;
}
