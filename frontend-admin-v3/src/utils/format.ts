import { MessagePlugin } from 'tdesign-vue-next';

/**
 * 通用格式化工具。所有业务页面应从本模块导入，禁止本地重写。
 */

/** 通用日期时间格式化：YYYY-MM-DD HH:mm:ss，无效或空值返回 '-' */
export function formatDateTime(value?: unknown): string {
  if (!value && value !== 0) return '-';
  const date = new Date(value as string | number | Date);
  if (Number.isNaN(date.getTime())) return String(value);
  const pad = (item: number) => String(item).padStart(2, '0');
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} ${pad(date.getHours())}:${pad(
    date.getMinutes(),
  )}:${pad(date.getSeconds())}`;
}

/** 金额格式化：¥X.XX，空值视为 0 */
export function formatMoney(value?: unknown): string {
  return `¥${Number(value || 0).toFixed(2)}`;
}

/** 字段值兜底：空字符串/undefined/null 显示 '-'，其余转为字符串返回 */
export function fieldValue(value?: unknown): string {
  if (value === '' || value === undefined || value === null) return '-';
  return String(value);
}

export interface CopyTextOptions {
  /** 复制成功提示文案；为空则不弹提示 */
  successMsg?: string;
  /** 复制失败提示文案 */
  errorMsg?: string;
}

/**
 * 统一的剪贴板复制能力。空值/占位符 `--` 直接跳过；可选地弹出成功/失败提示。
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
