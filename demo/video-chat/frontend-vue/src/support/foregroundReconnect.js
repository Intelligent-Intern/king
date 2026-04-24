export function attachForegroundReconnectHandlers({ onBackground = null, onForeground = null } = {}) {
  if (typeof window === 'undefined' || typeof document === 'undefined') {
    return () => {};
  }

  const handleBackground = () => {
    if (typeof onBackground === 'function') {
      onBackground();
    }
  };

  const handleForeground = () => {
    if (document.visibilityState === 'hidden') {
      return;
    }
    if (typeof onForeground === 'function') {
      onForeground();
    }
  };

  const handleVisibilityChange = () => {
    if (document.visibilityState === 'hidden') {
      handleBackground();
      return;
    }
    handleForeground();
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
