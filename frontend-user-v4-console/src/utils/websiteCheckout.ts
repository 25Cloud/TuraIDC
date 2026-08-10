const CHECKOUT_STORAGE_KEY = 'website_pending_checkout';

export interface PendingWebsiteCheckout {
  source?: string;
  idempotencyKey?: string;
  orderPayload?: Record<string, unknown>;
  [key: string]: unknown;
}

function toBase64Url(value: string) {
  const bytes = new TextEncoder().encode(value);
  let binary = '';
  bytes.forEach((byte) => {
    binary += String.fromCharCode(byte);
  });

  return window.btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '');
}

function fromBase64Url(value: string) {
  const normalized = value.replace(/-/g, '+').replace(/_/g, '/');
  const padded = normalized.padEnd(Math.ceil(normalized.length / 4) * 4, '=');
  const binary = window.atob(padded);
  const bytes = Uint8Array.from(binary, (char) => char.charCodeAt(0));
  return new TextDecoder().decode(bytes);
}

function canUseSessionStorage() {
  return typeof window !== 'undefined' && Boolean(window.sessionStorage);
}

export function buildIdempotencyKey(prefix = 'web-checkout') {
  const random = Math.random().toString(36).slice(2, 10);
  return `${prefix}-${Date.now()}-${random}`;
}

export function encodePendingWebsiteCheckout(payload: PendingWebsiteCheckout) {
  return toBase64Url(JSON.stringify(payload || {}));
}

export function decodePendingWebsiteCheckout(value: string): PendingWebsiteCheckout | null {
  if (!value) {
    return null;
  }

  try {
    return JSON.parse(fromBase64Url(value)) as PendingWebsiteCheckout;
  } catch {
    return null;
  }
}

export function savePendingWebsiteCheckout(payload: PendingWebsiteCheckout) {
  if (!canUseSessionStorage()) {
    return;
  }
  window.sessionStorage.setItem(CHECKOUT_STORAGE_KEY, JSON.stringify(payload || {}));
}

export function getPendingWebsiteCheckout(): PendingWebsiteCheckout | null {
  if (!canUseSessionStorage()) {
    return null;
  }

  const raw = window.sessionStorage.getItem(CHECKOUT_STORAGE_KEY);
  if (!raw) {
    return null;
  }

  try {
    return JSON.parse(raw) as PendingWebsiteCheckout;
  } catch {
    return null;
  }
}

export function clearPendingWebsiteCheckout() {
  if (canUseSessionStorage()) {
    window.sessionStorage.removeItem(CHECKOUT_STORAGE_KEY);
  }
}
