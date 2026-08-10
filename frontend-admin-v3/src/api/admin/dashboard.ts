import { request } from '@/utils/request';

import type { DashboardStats, MonthlyRevenue, RecentInvoice } from './types';

export const dashboardApi = {
  stats: () => request.get<DashboardStats>({ url: '/v2/admin/dashboard/stats' }),
  recentInvoices: () =>
    request.get<{ recent_invoices?: RecentInvoice[] }>({ url: '/v2/admin/dashboard/recent-invoices' }),
  monthlyRevenue: () => request.get<MonthlyRevenue>({ url: '/v2/admin/dashboard/monthly-revenue' }),
};
