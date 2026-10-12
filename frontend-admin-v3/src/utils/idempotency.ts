/**
 * 管理端写操作的幂等标识。
 *
 * 只给「会改变资金或状态」的接口用：同一个 key 重复提交，服务端只会真正执行一次，
 * 因此按钮连点、请求超时重试、浏览器重发都不会重复加钱/扣钱。
 * key 的生命周期应对应「一次用户操作」——打开表单时生成一次，提交成功后作废重建。
 */
let fallbackCounter = 0;

function randomSegment() {
  const cryptoRef = typeof globalThis !== 'undefined' ? globalThis.crypto : undefined;

  if (cryptoRef && typeof cryptoRef.randomUUID === 'function') {
    return cryptoRef.randomUUID().replace(/-/g, '');
  }

  if (cryptoRef && typeof cryptoRef.getRandomValues === 'function') {
    const bytes = cryptoRef.getRandomValues(new Uint8Array(16));
    return Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
  }

  // 老浏览器没有 Web Crypto：仍要保证同一次操作内 key 稳定可用，
  // 随机性不足时用时间戳 + 自增兜底，避免同一毫秒内生成重复 key
  fallbackCounter += 1;
  return `${Date.now().toString(36)}${fallbackCounter.toString(36)}${Math.random().toString(36).slice(2, 10)}`;
}

/**
 * 生成幂等标识。长度截到 64 字符内——服务端按 varchar(64) 校验。
 */
export function buildIdempotencyKey(prefix = 'admin') {
  return `${prefix}-${Date.now().toString(36)}-${randomSegment()}`.slice(0, 64);
}
