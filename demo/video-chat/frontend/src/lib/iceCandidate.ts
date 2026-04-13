export interface NormalizedIceCandidate {
  candidate: string
  sdpMid: string | null
  sdpMLineIndex: number | null
  usernameFragment: string | null
}

export function normalizeInboundIceCandidate(payload: unknown): NormalizedIceCandidate | null {
  if (typeof payload !== 'object' || payload === null) {
    return null
  }

  const record = payload as Record<string, unknown>
  const candidate = String(record.candidate ?? '').trim()
  if (candidate === '') {
    return null
  }

  return {
    candidate,
    sdpMid: normalizeNullableString(record.sdpMid),
    sdpMLineIndex: normalizeNullableInteger(record.sdpMLineIndex),
    usernameFragment: normalizeNullableString(record.usernameFragment),
  }
}

function normalizeNullableString(value: unknown): string | null {
  if (typeof value !== 'string') {
    return null
  }

  const normalized = value.trim()
  return normalized === '' ? null : normalized
}

function normalizeNullableInteger(value: unknown): number | null {
  if (typeof value !== 'number' || !Number.isFinite(value)) {
    return null
  }

  const normalized = Math.trunc(value)
  return normalized < 0 ? null : normalized
}
