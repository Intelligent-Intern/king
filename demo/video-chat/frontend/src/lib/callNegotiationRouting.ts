export type CallSignalType = 'call/offer' | 'call/answer' | 'call/ice' | 'call/hangup'

export interface InboundCallRoutingInput {
  type: string
  roomId: string
  activeRoomId: string
  senderUserId: string
  currentUserId: string
  targetUserId: string
}

export function requiresDirectCallTarget(type: string): boolean {
  return type === 'call/offer' || type === 'call/answer'
}

export function shouldAcceptInboundCallSignal(input: InboundCallRoutingInput): boolean {
  const type = input.type.trim()
  if (!isCallSignalType(type)) {
    return false
  }

  if (input.roomId !== input.activeRoomId) {
    return false
  }

  if (input.senderUserId === '' || input.currentUserId === '' || input.senderUserId === input.currentUserId) {
    return false
  }

  if (requiresDirectCallTarget(type)) {
    return input.targetUserId !== '' && input.targetUserId === input.currentUserId
  }

  if (input.targetUserId !== '' && input.targetUserId !== input.currentUserId) {
    return false
  }

  return true
}

function isCallSignalType(type: string): type is CallSignalType {
  return type === 'call/offer'
    || type === 'call/answer'
    || type === 'call/ice'
    || type === 'call/hangup'
}
