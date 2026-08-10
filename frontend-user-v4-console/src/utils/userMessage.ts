function isTrustedChineseMessage(message: unknown) {
  const text = String(message || '').trim();
  return text !== '' && /[\u4E00-\u9FA5]/.test(text);
}

export function toUserMessage(message: unknown, fallback = '操作失败，请稍后重试') {
  return isTrustedChineseMessage(message) ? String(message).trim() : fallback;
}
