function isRecord(value: unknown): value is Record<string, unknown> {
  return value !== null && typeof value === 'object' && !Array.isArray(value)
}

export function extractValidationErrors(payload: unknown): string[] {
  if (!isRecord(payload)) {
    return []
  }

  const nestedData = isRecord(payload.data) ? payload.data : {}
  const errors = isRecord(nestedData.errors)
    ? nestedData.errors
    : (isRecord(payload.errors) ? payload.errors : {})

  return Object.values(errors)
    .flatMap((value) => (Array.isArray(value) ? value : [value]))
    .map((value) => String(value || '').trim())
    .filter(Boolean)
}
