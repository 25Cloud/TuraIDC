import { t } from "@/locales";

function isTrustedChineseMessage(message: unknown) {
  const text = String(message || "").trim();
  return text !== "" && /[\u4e00-\u9fa5]/.test(text);
}

export function toUserMessage(message: unknown, fallback?: string) {
  if (isTrustedChineseMessage(message)) {
    return String(message).trim();
  }
  return fallback ?? t("common.userMessage.operationFailed");
}
