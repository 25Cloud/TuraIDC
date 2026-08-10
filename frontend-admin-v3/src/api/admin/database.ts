import { getAdminToken } from '@/app/runtime/session';
import { request } from '@/utils/request';

export interface DatabaseTableItem {
  name: string;
  rows: number;
  size_mb: number;
  update_time?: string | null;
}

export interface DatabaseOptimizationCandidate {
  name: string;
  reclaimable_mb: number;
  fragmentation_ratio: number;
}

export interface DatabaseStatus {
  database: string;
  list: DatabaseTableItem[];
  total_count: number;
  total_rows: number;
  total_size_mb: number;
  optimization: {
    candidate_count: number;
    estimated_reclaimable_mb: number;
    candidates: DatabaseOptimizationCandidate[];
    cooldown_remaining_seconds: number;
    last_optimized_at?: string | null;
  };
}

export interface DatabaseOptimizeResult {
  id?: string;
  status?: string;
  message?: string;
  detail?: {
    optimized_count?: number;
    failed_count?: number;
    optimized_tables?: string[];
    failed_tables?: Array<{ table?: string; message?: string } | string>;
  };
}

const DATABASE_OPTIMIZE_TIMEOUT = 5 * 60 * 1000;

function resolveApiBase(): string {
  const apiBaseUrl = String(import.meta.env.VITE_API_BASE_URL || '')
    .trim()
    .replace(/\/+$/, '');
  if (!apiBaseUrl) {
    throw new Error('VITE_API_BASE_URL 必须配置');
  }
  return apiBaseUrl;
}

export const databaseApi = {
  status: () => request.get<DatabaseStatus>({ url: '/v2/admin/database/status' }),
  optimize: (data?: { tables?: string[] }) =>
    request.post<DatabaseOptimizeResult>(
      {
        url: '/v2/admin/database/optimizations',
        data: data || {},
        timeout: DATABASE_OPTIMIZE_TIMEOUT,
      },
      {
        retry: { count: 0, delay: 0 },
      },
    ),
  exportBackup: async (): Promise<void> => {
    const token = getAdminToken();
    const response = await fetch(`${resolveApiBase()}/v2/admin/database/backups`, {
      method: 'POST',
      headers: {
        Accept: 'application/sql, application/json',
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
      },
    });

    const contentType = String(response.headers.get('content-type') || '').toLowerCase();
    if (!response.ok || contentType.includes('application/json')) {
      let message = `导出备份失败（HTTP ${response.status}）`;
      try {
        const payload = await response.json();
        if (payload?.message) {
          message = String(payload.message);
        }
      } catch {
        // ignore parse errors
      }
      throw new Error(message);
    }

    const blob = await response.blob();
    const disposition = String(response.headers.get('content-disposition') || '');
    const matched = disposition.match(/filename\*?=(?:UTF-8''|")?([^";]+)/i);
    const filename = matched?.[1] ? decodeURIComponent(matched[1].replace(/"/g, '')) : `backup_${Date.now()}.sql`;

    const objectUrl = URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href = objectUrl;
    anchor.download = filename;
    document.body.appendChild(anchor);
    anchor.click();
    document.body.removeChild(anchor);
    URL.revokeObjectURL(objectUrl);
  },
};
