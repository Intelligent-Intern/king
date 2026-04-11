export const CHAT_COMPOSER_MAX_LENGTH = 4000

export function clampComposerDraft(value: string, maxLength: number = CHAT_COMPOSER_MAX_LENGTH): string {
  const text = String(value ?? '')
  const limit = normalizeMaxLength(maxLength)
  if (text.length <= limit) {
    return text
  }

  return text.slice(0, limit)
}

export function resolveComposerPayload(value: string, maxLength: number = CHAT_COMPOSER_MAX_LENGTH): string | null {
  const payload = String(value ?? '').trim()
  if (payload === '') {
    return null
  }

  const boundedPayload = clampComposerDraft(payload, maxLength)
  if (boundedPayload === '') {
    return null
  }

  return boundedPayload
}

function normalizeMaxLength(value: number): number {
  if (!Number.isFinite(value) || value < 1) {
    return CHAT_COMPOSER_MAX_LENGTH
  }

  return Math.floor(value)
}
