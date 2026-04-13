export type CallTeardownBoundary = 'room-switch' | 'sign-out' | 'unmount'

export interface CallBoundaryResetOptions {
  notify: boolean
  roomId: string
  stopLocalMedia: boolean
}

export function callResetOptionsForBoundary(
  boundary: CallTeardownBoundary,
  roomId: string
): CallBoundaryResetOptions {
  const normalizedRoomId = roomId.trim() === '' ? 'lobby' : roomId.trim()

  if (boundary === 'room-switch') {
    return {
      notify: true,
      roomId: normalizedRoomId,
      stopLocalMedia: true,
    }
  }

  if (boundary === 'sign-out') {
    return {
      notify: true,
      roomId: normalizedRoomId,
      stopLocalMedia: true,
    }
  }

  return {
    notify: true,
    roomId: normalizedRoomId,
    stopLocalMedia: true,
  }
}
