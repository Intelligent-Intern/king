export interface CopyTextEnvironment {
  clipboardWriteText?: ((value: string) => Promise<void>) | null
  legacyCopy?: ((value: string) => boolean) | null
}

export async function copyTextWithFallback(
  text: string,
  environment: CopyTextEnvironment = resolveCopyTextEnvironment()
): Promise<boolean> {
  const value = text.trim()
  if (value === '') {
    return false
  }

  if (typeof environment.clipboardWriteText === 'function') {
    try {
      await environment.clipboardWriteText(value)
      return true
    } catch {
      // continue to legacy fallback
    }
  }

  if (typeof environment.legacyCopy === 'function') {
    try {
      return environment.legacyCopy(value)
    } catch {
      return false
    }
  }

  return false
}

function resolveCopyTextEnvironment(): CopyTextEnvironment {
  if (typeof window === 'undefined' || typeof document === 'undefined') {
    return {}
  }

  const clipboardWriteText = (
    typeof navigator !== 'undefined'
    && navigator.clipboard
    && typeof navigator.clipboard.writeText === 'function'
  )
    ? (value: string) => navigator.clipboard.writeText(value)
    : null

  const legacyCopy = (value: string): boolean => {
    const textarea = document.createElement('textarea')
    textarea.value = value
    textarea.setAttribute('readonly', 'true')
    textarea.style.position = 'fixed'
    textarea.style.top = '-10000px'
    textarea.style.left = '-10000px'
    textarea.style.opacity = '0'
    document.body.appendChild(textarea)
    textarea.focus()
    textarea.select()

    let copied = false
    try {
      copied = typeof document.execCommand === 'function' && document.execCommand('copy') === true
    } finally {
      document.body.removeChild(textarea)
    }

    return copied
  }

  return {
    clipboardWriteText,
    legacyCopy,
  }
}
