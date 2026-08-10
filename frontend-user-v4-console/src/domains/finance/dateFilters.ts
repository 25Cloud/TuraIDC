export type FinanceQuickFilter = '' | 'week' | 'month' | 'pending' | string;

export interface DateRangeFilter {
  start_date?: string;
  end_date?: string;
}

function formatYmd(date: Date) {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');
  return `${year}-${month}-${day}`;
}

export function resolveQuickDateRange(key: FinanceQuickFilter, now = new Date()): DateRangeFilter {
  if (key === 'week') {
    const start = new Date(now);
    start.setDate(now.getDate() - 6);
    return {
      start_date: formatYmd(start),
      end_date: formatYmd(now),
    };
  }

  if (key === 'month') {
    const start = new Date(now.getFullYear(), now.getMonth(), 1);
    const end = new Date(now.getFullYear(), now.getMonth() + 1, 0);
    return {
      start_date: formatYmd(start),
      end_date: formatYmd(end),
    };
  }

  return {};
}
