function normalizeApiBaseUrl(value: unknown): string {
  return String(value || '')
    .trim()
    .replace(/\/+$/, '');
}

export function resolveApiAssetUrl(value: unknown, apiBaseUrl: unknown): string {
  const normalized = String(value || '')
    .trim()
    .replace(/\\/g, '/');
  if (!normalized || /^(?:https?:)?\/\//i.test(normalized) || normalized.startsWith('data:')) {
    return normalized;
  }

  const managedPath = normalized.match(/^(?:\.\/|\/+)?((?:uploads|media)\/.+)$/i)?.[1];
  if (!managedPath) {
    return normalized;
  }

  const apiBase = normalizeApiBaseUrl(apiBaseUrl);
  try {
    const url = new URL(apiBase);
    return /^https?:$/.test(url.protocol) ? `${url.origin}/${managedPath}` : `/${managedPath}`;
  } catch {
    return `/${managedPath}`;
  }
}
