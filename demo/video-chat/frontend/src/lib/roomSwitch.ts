export interface RoomSwitchDecision {
  previousRoomId: string
  nextRoomId: string
  shouldSwitch: boolean
  shouldEmitTypingStop: boolean
}

export interface RoomSwitchUiReset {
  activeTab: 'chat'
  messageInput: string
  lastInviteCode: string
}

export function decideRoomSwitch(previousRoomId: string, requestedRoomId: string): RoomSwitchDecision {
  const previous = normalizeRoomIdForSwitch(previousRoomId)
  const next = normalizeRoomIdForSwitch(requestedRoomId)
  const shouldSwitch = previous !== next

  return {
    previousRoomId: previous,
    nextRoomId: next,
    shouldSwitch,
    shouldEmitTypingStop: shouldSwitch && previous !== '',
  }
}

export function roomSwitchUiReset(): RoomSwitchUiReset {
  return {
    activeTab: 'chat',
    messageInput: '',
    lastInviteCode: '',
  }
}

function normalizeRoomIdForSwitch(value: string): string {
  const normalized = value.trim().toLowerCase().replace(/[^a-z0-9-_]/g, '-')
  return normalized || 'lobby'
}
