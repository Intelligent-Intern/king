export function createNativeAudioBridgeStateHelpers(nativeAudioBridgeStatusVersion) {
  function bumpNativeAudioBridgeStatusVersion() {
    nativeAudioBridgeStatusVersion.value = nativeAudioBridgeStatusVersion.value >= 1_000_000
      ? 0
      : nativeAudioBridgeStatusVersion.value + 1;
  }

  function setNativePeerAudioBridgeState(peer, state = '', errorMessage = '') {
    if (!peer || typeof peer !== 'object') return false;
    const nextState = String(state || '').trim().toLowerCase();
    const nextErrorMessage = String(errorMessage || '').trim();
    if (
      String(peer.audioBridgeState || '').trim().toLowerCase() === nextState
      && String(peer.audioBridgeErrorMessage || '').trim() === nextErrorMessage
    ) {
      return false;
    }
    peer.audioBridgeState = nextState;
    peer.audioBridgeErrorMessage = nextErrorMessage;
    bumpNativeAudioBridgeStatusVersion();
    return true;
  }

  function clearNativePeerAudioTrackDeadline(peer) {
    if (!peer || typeof peer !== 'object') return;
    if (peer.audioTrackDeadlineTimer !== null && peer.audioTrackDeadlineTimer !== undefined) {
      clearTimeout(peer.audioTrackDeadlineTimer);
    }
    peer.audioTrackDeadlineTimer = null;
  }

  return {
    bumpNativeAudioBridgeStatusVersion,
    clearNativePeerAudioTrackDeadline,
    setNativePeerAudioBridgeState,
  };
}
