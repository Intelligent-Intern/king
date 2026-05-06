export function selectBackgroundFilterBackend() {
  if (typeof window === 'undefined') {
    return {
      backend: 'unsupported',
      supported: false,
      reason: 'no_window',
    };
  }

  return {
    backend: 'sinet_wasm',
    supported: true,
    reason: 'ok',
  };
}
