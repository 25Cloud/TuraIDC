import { t } from '@/locales';

export function isTrustedChineseMessage(message: unknown) {
  const text = String(message || '').trim();
  return text !== '' && /[\u4E00-\u9FA5]/.test(text);
}

export function toUserMessage(message: unknown, fallback = t('common.operationFailed')) {
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

/**
 * 结构化校验错误里的第一条消息。
 *
 * 与 firstTrustedMessage 的区别：这里**不要求中文**。422 的字段级错误来自 Laravel 校验器，
 * 是 name → [message] 的结构化数据，不会夹带堆栈或 SQL；只认中文会让
 * 「后端 locale 为英文」的部署把真实原因（The third product group id field is required.）
 * 过滤掉，管理员端只剩一句「参数验证失败」——那正是客户报障时看到的东西。
 */
function firstNonEmptyMessage(value: unknown): string | null {
  if (Array.isArray(value)) {
    for (const item of value) {
      const message = firstNonEmptyMessage(item);
      if (message) return message;
    }

    return null;
  }

  if (typeof value === 'string') {
    const text = value.trim();

    return text !== '' ? text : null;
  }

  if (isRecord(value)) {
    for (const item of Object.values(value)) {
      const message = firstNonEmptyMessage(item);
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
    const message = firstNonEmptyMessage(getValidationErrors(candidate));
    if (message) return message;
  }

  return null;
}

/**
 * 统一错误提示信息提取。
 * 优先用字段级验证错误，其次用可信的中文后端消息，否则回退 fallback。
 * 所有页面应使用本函数，禁止本地重写 errorMessage。
 */
export function errorMessage(error: { message?: unknown } | unknown, fallback = t('common.operationFailed')): string {
  const validationMessage = validationErrorMessage(error);
  if (validationMessage) return validationMessage;

  const message = (error as { message?: unknown })?.message;
  return toUserMessage(message, fallback);
}
