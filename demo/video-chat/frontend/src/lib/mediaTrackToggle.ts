export type MediaTrackKind = 'audio' | 'video'

export interface MediaTrackPreferences {
  audioEnabled: boolean
  videoEnabled: boolean
}

export function setTrackKindEnabled(
  stream: MediaStream | null,
  kind: MediaTrackKind,
  enabled: boolean
): number {
  if (!stream) {
    return 0
  }

  const tracks = kind === 'audio' ? stream.getAudioTracks() : stream.getVideoTracks()
  for (const track of tracks) {
    track.enabled = enabled
  }

  return tracks.length
}

export function applyMediaTrackPreferences(
  stream: MediaStream | null,
  preferences: MediaTrackPreferences
): void {
  setTrackKindEnabled(stream, 'audio', preferences.audioEnabled)
  setTrackKindEnabled(stream, 'video', preferences.videoEnabled)
}
