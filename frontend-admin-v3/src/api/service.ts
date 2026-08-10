import { request } from '@/utils/request';

import type { PagedListParams } from './types';

export interface ServiceListParams extends PagedListParams {
  status?: string | number;
}

export interface ServiceRecord {
  id: number | string;
  service_id?: number | string;
  name?: string;
  domain?: string;
  custom_hostname?: string;
  product_id?: number | string;
  product_display_name?: string;
  product?: Record<string, unknown> | null;
  user?: Record<string, unknown> | null;
  invoice?: Record<string, unknown> | null;
  upstream_host_id?: number | string;
  upstream_host_id_text?: string;
  host_ips?: string[];
  host_username?: string;
  connection?: Record<string, unknown> | null;
  status?: number | string;
  amount?: number | string;
  billing_cycle?: string;
  expires_at?: string | null;
  created_at?: string | null;
  [key: string]: unknown;
}

export interface CustomHostnamePayload {
  items: Array<{
    service_id: number;
    hostname: string;
  }>;
}

export const serviceApi = {
  list: (params: ServiceListParams) =>
    request.get<{ list?: ServiceRecord[]; total?: number; page?: number; page_size?: number }>({
      url: '/v2/admin/services',
      params,
    }),
  batchUpdateCustomHostnames: (data: CustomHostnamePayload) =>
    request.post<{ message?: string }>({
      url: '/v2/admin/services/custom-hostnames/batch',
      data,
    }),
};
