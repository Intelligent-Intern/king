const JOIN_VISIBLE_SLO_MS = 1000

function numberField(value: unknown, fallback = 0): number {
  const normalized = Number(value)
  return Number.isFinite(normalized) ? normalized : fallback
}

function stringField(value: unknown, fallback = ''): string {
  const normalized = String(value ?? '').trim()
  return normalized === '' ? fallback : normalized
}

export function maybeReportFirstRemoteFrameVisible(args: {
  peer: Record<string, unknown>
  frame: Record<string, unknown> | null
  renderedAtMs: number
  mediaRuntimePath: string
  captureClientDiagnostic: (details: Record<string, unknown>) => void
}): void {
  const peer = args.peer
  if (numberField(peer.firstRemoteVisibleFrameAtMs) > 0) return
  const publisherJoinStartedAtMs = numberField(args.frame?.publisherJoinStartedAtMs)
  if (publisherJoinStartedAtMs <= 0) return

  const elapsedMs = Math.max(0, Math.round(args.renderedAtMs - publisherJoinStartedAtMs))
  peer.firstRemoteVisibleFrameAtMs = args.renderedAtMs
  peer.firstRemoteVisibleElapsedMs = elapsedMs
  args.captureClientDiagnostic({
    category: 'media',
    level: elapsedMs > JOIN_VISIBLE_SLO_MS ? 'warning' : 'info',
    eventType: 'sfu_first_remote_frame_visible',
    code: elapsedMs > JOIN_VISIBLE_SLO_MS
      ? 'sfu_first_remote_frame_visible_slo_exceeded'
      : 'sfu_first_remote_frame_visible',
    message: 'First remote SFU video frame became visible.',
    payload: {
      publisher_id: stringField(args.frame?.publisherId),
      publisher_user_id: stringField(args.frame?.publisherUserId),
      track_id: stringField(args.frame?.trackId),
      frame_sequence: numberField(args.frame?.frameSequence),
      publisher_join_started_at_ms: publisherJoinStartedAtMs,
      first_remote_visible_at_ms: args.renderedAtMs,
      publisher_join_to_visible_ms: elapsedMs,
      join_visible_slo_ms: JOIN_VISIBLE_SLO_MS,
      media_runtime_path: args.mediaRuntimePath,
    },
    immediate: elapsedMs > JOIN_VISIBLE_SLO_MS,
  })
}
