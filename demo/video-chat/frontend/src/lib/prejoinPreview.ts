export type MediaPreviewState = 'idle' | 'requesting' | 'ready' | 'error'

export function canJoinFromPreview(status: MediaPreviewState): boolean {
  return status === 'ready'
}

export function mapPreviewAccessError(error: unknown): string {
  const name = resolveErrorName(error)
  if (name === 'NotAllowedError' || name === 'SecurityError') {
    return 'Camera/microphone permission was denied. Allow access and retry preview.'
  }
  if (name === 'NotFoundError' || name === 'OverconstrainedError') {
    return 'No compatible camera/microphone was found for local preview.'
  }
  if (name === 'NotReadableError' || name === 'AbortError') {
    return 'Camera/microphone is currently unavailable. Close other apps and retry preview.'
  }

  return 'Local preview could not start right now. Please retry.'
}

function resolveErrorName(error: unknown): string {
  if (typeof error !== 'object' || error === null) {
    return ''
  }

  const maybeName = (error as { name?: unknown }).name
  return typeof maybeName === 'string' ? maybeName : ''
}
