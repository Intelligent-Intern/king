function documentIsHidden(documentRef) {
  return documentRef?.visibilityState === 'hidden';
}

function firstLiveVideoTrack(stream) {
  if (!stream || typeof stream.getVideoTracks !== 'function') return null;
  const tracks = stream.getVideoTracks();
  return tracks.find((track) => String(track?.readyState || '').toLowerCase() === 'live') || null;
}

function shouldPauseSfuVideoForBackground(context = {}, documentRef = null) {
  const reason = String(context?.reason || '').trim().toLowerCase();
  if (context?.hidden === true || documentIsHidden(documentRef)) return true;
  return reason === 'pagehide' || reason === 'document_hidden';
}

export function createSfuBackgroundTabPolicy({
  callbacks = {},
  refs = {},
  documentRef = typeof document !== 'undefined' ? document : null,
} = {}) {
  let backgroundVideoPaused = false;
  let backgroundVideoPausedAtMs = 0;
  let backgroundPauseCount = 0;

  const {
    captureClientDiagnostic = () => {},
    publishLocalTracks = async () => false,
    stopLocalEncodingPipeline = () => {},
  } = callbacks;

  const {
    callMediaPrefs = {},
    localStreamRef = {},
    localTracksPublishedToSfuRef = null,
    mediaRuntimePath = {},
    sfuClientRef = {},
  } = refs;

  function mediaRuntimeIsSfu() {
    return String(mediaRuntimePath.value || '').trim() === 'wlvc_wasm';
  }

  function diagnosticPayload(reason, track) {
    return {
      reason: String(reason || ''),
      background_video_policy: 'pause_sfu_video_keep_audio_status',
      browser_visibility_state: String(documentRef?.visibilityState || ''),
      track_id: String(track?.id || ''),
      outgoing_video_quality_profile: String(callMediaPrefs.outgoingVideoQualityProfile || ''),
      media_runtime_path: String(mediaRuntimePath.value || ''),
    };
  }

  function pauseVideoForBackground(context = {}) {
    if (!shouldPauseSfuVideoForBackground(context, documentRef)) return false;
    if (!mediaRuntimeIsSfu()) return false;
    if (backgroundVideoPaused) return false;

    const videoTrack = firstLiveVideoTrack(localStreamRef.value);
    if (!videoTrack) return false;

    backgroundVideoPaused = true;
    backgroundVideoPausedAtMs = Date.now();
    backgroundPauseCount += 1;
    stopLocalEncodingPipeline();

    try {
      sfuClientRef.value?.unpublishTrack?.(videoTrack.id);
      localTracksPublishedToSfuRef?.set?.(false);
    } catch {
      // The foreground reconnect path republishes the track if the socket changed.
    }

    captureClientDiagnostic({
      category: 'media',
      level: 'warning',
      eventType: 'sfu_background_tab_video_paused',
      code: 'sfu_background_tab_video_paused',
      message: 'SFU video publishing was paused because the browser moved the call tab into the background.',
      payload: {
        ...diagnosticPayload(context?.reason || 'background', videoTrack),
        background_pause_count: backgroundPauseCount,
      },
      immediate: true,
    });
    return true;
  }

  async function resumeVideoAfterForeground(context = {}) {
    if (!backgroundVideoPaused) return false;
    const videoTrack = firstLiveVideoTrack(localStreamRef.value);
    const pausedForMs = Math.max(0, Date.now() - backgroundVideoPausedAtMs);
    backgroundVideoPaused = false;
    backgroundVideoPausedAtMs = 0;

    captureClientDiagnostic({
      category: 'media',
      level: 'info',
      eventType: 'sfu_background_tab_video_resumed',
      code: 'sfu_background_tab_video_resumed',
      message: 'SFU video publishing is resuming after the call tab returned to the foreground.',
      payload: {
        ...diagnosticPayload(context?.reason || 'foreground', videoTrack),
        background_paused_for_ms: pausedForMs,
      },
      immediate: true,
    });

    await publishLocalTracks();
    return true;
  }

  return {
    pauseVideoForBackground,
    resumeVideoAfterForeground,
    shouldPauseSfuVideoForBackground: (context = {}) => shouldPauseSfuVideoForBackground(context, documentRef),
  };
}
