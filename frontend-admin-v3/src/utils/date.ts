// 获取常用时间
import dayjs from 'dayjs';

export function getLast7Days(): [string, string] {
  return [dayjs().subtract(7, 'day').format('YYYY-MM-DD'), dayjs().subtract(1, 'day').format('YYYY-MM-DD')];
}

export function getLast30Days(): [string, string] {
  return [dayjs().subtract(30, 'day').format('YYYY-MM-DD'), dayjs().subtract(1, 'day').format('YYYY-MM-DD')];
}

/** @deprecated 使用 getLast7Days() 替代 */
export const LAST_7_DAYS = getLast7Days();
/** @deprecated 使用 getLast30Days() 替代 */
export const LAST_30_DAYS = getLast30Days();
