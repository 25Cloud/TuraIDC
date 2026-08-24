import { request } from '@/utils/request';

export interface OpenApiConfigPayload {
  enabled?: number;
  require_phone?: number;
  require_verified?: number;
  max_keys_per_user?: number;
  rate_limit?: number;
}

export interface OpenApiKeyUser {
  id?: number;
  email?: string;
  phone?: string;
  nickname?: string;
  status?: number;
}

export interface OpenApiKeyRecord {
  id: number;
  name?: string;
  key_prefix?: string;
  secret_last4?: string;
  scopes?: Record<string, 'read' | 'write'>;
  expires_at?: string | null;
  ip_allowlist?: string[];
  status?: 'enabled' | 'disabled';
  last_used_at?: string | null;
  created_at?: string;
  user?: OpenApiKeyUser | null;
}

export interface OpenApiUsageLogRecord {
  method?: string;
  path?: string;
  status_code?: number;
  ip?: string;
  duration_ms?: number;
  created_at?: string;
}

export interface OpenApiKeyListResponse {
  list?: OpenApiKeyRecord[];
  total?: number;
  page?: number;
  page_size?: number;
}

export interface OpenApiUsageLogListResponse {
  list?: OpenApiUsageLogRecord[];
  total?: number;
  page?: number;
  page_size?: number;
}

export const openApiApi = {
  getConfig: async () => {
    const response = await request.get<OpenApiConfigPayload>({ url: '/v2/admin/open-api/config' });
    return response;
  },
  saveConfig: (data: OpenApiConfigPayload) =>
    request.put<OpenApiConfigPayload>({ url: '/v2/admin/open-api/config', data }),
  keys: async (params?: Record<string, unknown>) => {
    const response = await request.get<OpenApiKeyListResponse>({ url: '/v2/admin/open-api/keys', params });
    return {
      list: response.list || [],
      total: Number(response.total || 0),
      page: Number(response.page || 1),
      page_size: Number(response.page_size || 20),
    };
  },
  setStatus: (id: number | string, status: 'enabled' | 'disabled') =>
    request.patch<{ key: OpenApiKeyRecord }>({ url: `/v2/admin/open-api/keys/${id}/status`, data: { status } }),
  remove: (id: number | string) => request.delete({ url: `/v2/admin/open-api/keys/${id}` }),
  usageLogs: async (id: number | string, params?: Record<string, unknown>) => {
    const response = await request.get<OpenApiUsageLogListResponse>({
      url: `/v2/admin/open-api/keys/${id}/usage-logs`,
      params,
    });
    return {
      list: response.list || [],
      total: Number(response.total || 0),
      page: Number(response.page || 1),
      page_size: Number(response.page_size || 20),
    };
  },
};
