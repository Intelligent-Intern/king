export function normalizeSfuIdentifier(value: string, fallback = ''): string {
  const normalized = String(value || '')
    .trim()
    .toLowerCase()
    .replace(/[^a-z0-9._:-]+/g, '_')
    .replace(/^[_:.-]+|[_:.-]+$/g, '')

  return normalized || fallback
}
