export function isTrustedChineseMessage(message: unknown) {
  const text = String(message || '').trim();
  return text !== '' && /[\u4E00-\u9FA5]/.test(text);
}

export function toUserMessage(message: unknown, fallback = '操作失败，请稍后重试') {
  return isTrustedChineseMessage(message) ? String(message).trim() : fallback;
}

interface ErrorPayload {
  data?: unknown;
  errors?: unknown;
  message?: unknown;
  response?: {
    data?: unknown;
  };
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null;
}

function firstTrustedMessage(value: unknown): string | null {
  if (Array.isArray(value)) {
    for (const item of value) {
      const message = firstTrustedMessage(item);
      if (message) return message;
    }

    return null;
  }

  if (isTrustedChineseMessage(value)) {
    return String(value).trim();
  }

  if (isRecord(value)) {
    for (const item of Object.values(value)) {
      const message = firstTrustedMessage(item);
      if (message) return message;
    }
  }

  return null;
}

function getValidationErrors(payload: unknown): unknown {
  if (!isRecord(payload)) return undefined;

  const nestedData = payload.data;
  if (isRecord(nestedData) && Reflect.has(nestedData, 'errors')) {
    return nestedData.errors;
  }

  if (Reflect.has(payload, 'errors')) {
    return payload.errors;
  }

  return undefined;
}

export function validationErrorMessage(error: unknown): string | null {
  const payload = error as ErrorPayload;
  const candidates = [payload?.response?.data, payload?.data, error];

  for (const candidate of candidates) {
    const message = firstTrustedMessage(getValidationErrors(candidate));
    if (message) return message;
  }

  return null;
}

/**
 * 统一错误提示信息提取。
 * 优先用字段级验证错误，其次用可信的中文后端消息，否则回退 fallback。
 * 所有页面应使用本函数，禁止本地重写 errorMessage。
 */
export function errorMessage(error: { message?: unknown } | unknown, fallback = '操作失败，请稍后重试'): string {
  const validationMessage = validationErrorMessage(error);
  if (validationMessage) return validationMessage;

  const message = (error as { message?: unknown })?.message;
  return toUserMessage(message, fallback);
}
