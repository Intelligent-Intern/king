import { describe, expect, it } from 'vitest'
import { applyMediaTrackPreferences, setTrackKindEnabled } from './mediaTrackToggle'

interface FakeTrack {
  enabled: boolean
}

interface FakeStream {
  getAudioTracks(): FakeTrack[]
  getVideoTracks(): FakeTrack[]
}

function makeStream(audio: FakeTrack[], video: FakeTrack[]): MediaStream {
  const stream: FakeStream = {
    getAudioTracks: () => audio,
    getVideoTracks: () => video,
  }

  return stream as unknown as MediaStream
}

describe('mediaTrackToggle helpers', () => {
  it('toggles only audio tracks for microphone updates', () => {
    const audioTracks = [{ enabled: true }, { enabled: true }]
    const videoTracks = [{ enabled: true }]
    const stream = makeStream(audioTracks, videoTracks)

    const changed = setTrackKindEnabled(stream, 'audio', false)

    expect(changed).toBe(2)
    expect(audioTracks.map((track) => track.enabled)).toEqual([false, false])
    expect(videoTracks.map((track) => track.enabled)).toEqual([true])
  })

  it('toggles only video tracks for camera updates', () => {
    const audioTracks = [{ enabled: true }]
    const videoTracks = [{ enabled: true }, { enabled: true }]
    const stream = makeStream(audioTracks, videoTracks)

    const changed = setTrackKindEnabled(stream, 'video', false)

    expect(changed).toBe(2)
    expect(audioTracks.map((track) => track.enabled)).toEqual([true])
    expect(videoTracks.map((track) => track.enabled)).toEqual([false, false])
  })

  it('applies both audio and video preferences in one pass', () => {
    const audioTracks = [{ enabled: true }]
    const videoTracks = [{ enabled: true }, { enabled: true }]
    const stream = makeStream(audioTracks, videoTracks)

    applyMediaTrackPreferences(stream, {
      audioEnabled: false,
      videoEnabled: true,
    })

    expect(audioTracks.map((track) => track.enabled)).toEqual([false])
    expect(videoTracks.map((track) => track.enabled)).toEqual([true, true])
  })

  it('handles missing local stream safely', () => {
    expect(setTrackKindEnabled(null, 'audio', false)).toBe(0)
    expect(() => {
      applyMediaTrackPreferences(null, {
        audioEnabled: false,
        videoEnabled: false,
      })
    }).not.toThrow()
  })
})
