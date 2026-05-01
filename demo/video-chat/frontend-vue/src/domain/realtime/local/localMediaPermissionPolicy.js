export const LOCAL_MEDIA_PERMISSION_DENIED_RETRY_COOLDOWN_MS = 30_000;

export function isLocalMediaPermissionDeniedError(error) {
  const name = String(error?.name || '').trim();
  const message = String(error?.message || error || '').trim().toLowerCase();
  return name === 'NotAllowedError'
    || name === 'SecurityError'
    || message.includes('permission denied by system')
    || message.includes('permission denied');
}
