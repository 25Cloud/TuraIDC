import { MessagePlugin } from 'tdesign-vue-next';

/**
 * 金额格式化（不含货币符号，¥ 由模板/调用方拼接）。
 *
 * 收敛自各 domain 内重复定义的 formatMoney，行为保持一致：
 * - 非有限数字 → '0.00'
 * - 其余 → 保留两位小数
 */
export function formatMoney(value: unknown): string {
  const amount = Number(value ?? 0);
  return Number.isFinite(amount) ? amount.toFixed(2) : '0.00';
}

const pad = (num: number) => String(num).padStart(2, '0');
const DASH_RE = /-/g;

/**
 * 日期时间格式化为 `YYYY-MM-DD HH:mm`。
 * 兼容 `YYYY-MM-DD HH:mm:ss` 与带 `-` 分隔的字符串（Safari 友好）。
 */
export function formatDateTime(value: unknown): string {
  if (!value) return '--';
  const date = new Date(String(value).replace(DASH_RE, '/'));
  if (Number.isNaN(date.getTime())) return String(value);
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} ${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

/**
 * 日期时间格式化为 `MM-DD HH:mm`（仪表盘等紧凑场景）。
 */
export function formatShortDateTime(value: unknown): string {
  if (!value) return '--';
  const date = new Date(String(value).replace(DASH_RE, '/'));
  if (Number.isNaN(date.getTime())) return '--';
  return `${pad(date.getMonth() + 1)}-${pad(date.getDate())} ${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

export interface CopyTextOptions {
  /** 复制成功提示文案；为空则不弹提示 */
  successMsg?: string;
  /** 复制失败提示文案 */
  errorMsg?: string;
}

/**
 * 统一的剪贴板复制能力，收敛自各 domain 内重复实现。
 * 空值/占位符 `--` 直接跳过；可选地弹出成功/失败提示。
 *
 * @returns 是否复制成功
 */
export async function copyText(value: unknown, options: CopyTextOptions = {}): Promise<boolean> {
  const text = String(value ?? '').trim();
  if (!text || text === '--') return false;

  try {
    await navigator.clipboard.writeText(text);
    if (options.successMsg) MessagePlugin.success(options.successMsg);
    return true;
  } catch {
    MessagePlugin.warning(options.errorMsg ?? '复制失败，请手动复制');
    return false;
  }
}
