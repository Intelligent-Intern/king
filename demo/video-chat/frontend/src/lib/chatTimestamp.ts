const INVALID_TIMESTAMP_LABEL = '--:-- UTC'

export function formatChatTimestamp(value: number): string {
  const parsed = Number(value)
  if (!Number.isFinite(parsed) || parsed <= 0) {
    return INVALID_TIMESTAMP_LABEL
  }

  const date = new Date(parsed)
  if (Number.isNaN(date.getTime())) {
    return INVALID_TIMESTAMP_LABEL
  }

  return `${padTime(date.getUTCHours())}:${padTime(date.getUTCMinutes())} UTC`
}

function padTime(value: number): string {
  return String(value).padStart(2, '0')
}
