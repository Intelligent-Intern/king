export function uniqueLocalStreams(values) {
  const out = [];
  const seen = new Set();
  for (const value of values) {
    if (!(value instanceof MediaStream)) continue;
    if (seen.has(value)) continue;
    seen.add(value);
    out.push(value);
  }
  return out;
}

export function unpublishSfuTracksForClient(sfuClient, tracks) {
  if (!sfuClient || !Array.isArray(tracks)) return;
  for (const track of tracks) {
    if (!track?.id) continue;
    try {
      sfuClient.unpublishTrack(track.id);
    } catch {
      // best-effort cleanup for stale tracks
    }
  }
}

export function stopRetiredLocalStreams(retiredStreams, preservedStreams = []) {
  const preserved = new Set();
  const preservedTrackIds = new Set();
  for (const stream of preservedStreams) {
    if (stream instanceof MediaStream) {
      preserved.add(stream);
      for (const track of stream.getTracks()) {
        if (track?.id) preservedTrackIds.add(track.id);
      }
    }
  }

  for (const stream of uniqueLocalStreams(retiredStreams)) {
    if (!(stream instanceof MediaStream)) continue;
    if (preserved.has(stream)) continue;
    for (const track of stream.getTracks()) {
      if (track?.id && preservedTrackIds.has(track.id)) continue;
      try {
        track.stop();
      } catch {
        // ignore stop failures during stream turnover
      }
    }
  }
}
