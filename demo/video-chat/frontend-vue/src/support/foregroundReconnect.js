export function attachForegroundReconnectHandlers({ onBackground = null, onForeground = null } = {}) {
  if (typeof window === 'undefined' || typeof document === 'undefined') {
    return () => {};
  }

  const backgroundContextFor = (event = null) => ({
    reason: String(event?.type || 'background'),
    hidden: document.visibilityState === 'hidden',
    visibility_state: String(document.visibilityState || ''),
  });

  const foregroundContextFor = (event = null) => ({
    reason: String(event?.type || 'foreground'),
    hidden: false,
    visibility_state: String(document.visibilityState || ''),
  });

  const handleBackground = (event = null) => {
    if (typeof onBackground === 'function') {
      onBackground(backgroundContextFor(event));
    }
  };

  const handleForeground = (event = null) => {
    if (document.visibilityState === 'hidden') {
      return;
    }
    if (typeof onForeground === 'function') {
      onForeground(foregroundContextFor(event));
    }
  };

  const handleVisibilityChange = () => {
    if (document.visibilityState === 'hidden') {
      handleBackground({ type: 'document_hidden' });
      return;
    }
    handleForeground({ type: 'document_visible' });
  };

  window.addEventListener('blur', handleBackground);
  window.addEventListener('pagehide', handleBackground);
  window.addEventListener('focus', handleForeground);
  window.addEventListener('pageshow', handleForeground);
  window.addEventListener('online', handleForeground);
  document.addEventListener('visibilitychange', handleVisibilityChange);

  return () => {
    window.removeEventListener('blur', handleBackground);
    window.removeEventListener('pagehide', handleBackground);
    window.removeEventListener('focus', handleForeground);
    window.removeEventListener('pageshow', handleForeground);
    window.removeEventListener('online', handleForeground);
    document.removeEventListener('visibilitychange', handleVisibilityChange);
  };
}
