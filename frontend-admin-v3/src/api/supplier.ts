import { request } from '@/utils/request';

import type { PagedListParams } from './types';

export type SupplierProviderConfig = Record<string, unknown>;

export interface SupplierListParams extends PagedListParams {
  status?: number | string;
  [key: string]: unknown;
}

export interface SupplierFormOption {
  label: string;
  value: string | number | boolean;
}

export type SupplierFormFieldType =
  'text' | 'url' | 'password' | 'select' | 'switch' | 'boolean' | 'number' | 'textarea';

export interface SupplierFormField {
  key: string;
  label: string;
  type?: SupplierFormFieldType;
  required?: boolean;
  secret?: boolean;
  placeholder?: string;
  description?: string;
  default?: unknown;
  options?: SupplierFormOption[];
}

export interface SupplierFormSchema {
  fields?: SupplierFormField[];
  help?: string;
}

export interface SupplierUpstreamBindingRecord {
  id?: number | string | null;
  provider_key?: string;
  base_url?: string;
  has_base_url?: boolean;
  account_name?: string;
  status?: number | string;
  last_checked_at?: string | null;
  last_check_status?: string | null;
  last_check_error?: string | null;
  config?: SupplierProviderConfig;
  has_secret_values?: Record<string, boolean>;
}

export interface SupplierCardStatus {
  label?: string;
  theme?: string;
  variant?: string;
}

export interface SupplierCardField {
  key?: string;
  label?: string;
  value?: unknown;
  theme?: string;
}

export interface SupplierCardAction {
  key: string;
  label: string;
  action: string;
  request_action?: string;
  theme?: string;
  variant?: string;
  disabled?: boolean;
  disabled_reason?: string;
}

export interface SupplierCardRecord {
  provided?: boolean;
  title?: string;
  subtitle?: string;
  status?: SupplierCardStatus | null;
  fields?: SupplierCardField[];
  actions?: SupplierCardAction[];
  empty_text?: string;
}

export interface SupplierRecord {
  id: number | string;
  name?: string;
  provider_key?: string;
  provider_label?: string;
  api_url?: string;
  api_username?: string;
  has_api_url?: boolean;
  has_api_key?: boolean;
  has_provider_secret_values?: Record<string, boolean>;
  provider_config?: SupplierProviderConfig;
  upstream_binding?: SupplierUpstreamBindingRecord | null;
  remote_balance?: number | string;
  remote_balance_status?: string;
  status?: number | string;
  updated_at?: string;
  card?: SupplierCardRecord;
  [key: string]: unknown;
}

export interface SupplierUpsertPayload {
  name: string;
  status: number;
  api_url: unknown;
  api_username: unknown;
  api_key: unknown;
  provider_config: SupplierProviderConfig;
  upstream_binding?: {
    provider_key: string;
    base_url?: unknown;
    account_name?: unknown;
  };
}

export interface SupplierSummary {
  total?: number;
  active?: number;
  inactive?: number;
  [key: string]: unknown;
}

export interface SupplierActionResult {
  id?: number | string;
  status?: string;
  message?: string;
  detail?: {
    type?: string;
    result?: Record<string, unknown>;
    [key: string]: unknown;
  };
}

export interface ProviderTypeRecord {
  value: string;
  label: string;
  supplier_form?: SupplierFormSchema;
  [key: string]: unknown;
}

type V2SupplierRecord = SupplierRecord & {
  connection?: {
    base_url?: string;
    base_url_configured?: boolean;
    account_name?: string;
  };
  credentials?: {
    api_credential_configured?: boolean;
    provider_values_configured?: Record<string, boolean>;
  };
  upstream_binding?: SupplierUpstreamBindingRecord & {
    base_url_configured?: boolean;
    credentials_configured?: Record<string, boolean>;
  };
};

interface V2SupplierDetailResponse {
  supplier?: V2SupplierRecord;
}

interface V2SupplierListResponse {
  list?: V2SupplierRecord[];
  total?: number;
  page?: number;
  page_size?: number;
}

function normalizeV2Supplier(record: V2SupplierRecord): SupplierRecord {
  const connection = record.connection || {};
  const credentials = record.credentials || {};
  const upstreamBinding = record.upstream_binding || {};
  const baseUrl = record.api_url || connection.base_url || upstreamBinding.base_url || '';

  return {
    ...record,
    api_url: baseUrl,
    has_api_url: Boolean(record.has_api_url ?? connection.base_url_configured ?? upstreamBinding.base_url_configured),
    api_username: record.api_username || connection.account_name || upstreamBinding.account_name || '',
    has_api_key: Boolean(record.has_api_key ?? credentials.api_credential_configured),
    has_provider_secret_values: record.has_provider_secret_values || credentials.provider_values_configured || {},
    upstream_binding: {
      ...upstreamBinding,
      base_url: baseUrl,
      has_base_url: Boolean(upstreamBinding.has_base_url ?? upstreamBinding.base_url_configured),
      has_secret_values: upstreamBinding.has_secret_values || upstreamBinding.credentials_configured || {},
    },
  };
}

function normalizeV2SupplierList(response: V2SupplierListResponse) {
  return {
    ...response,
    list: Array.isArray(response.list) ? response.list.map((item) => normalizeV2Supplier(item)) : [],
  };
}

export const supplierApi = {
  list: (params: SupplierListParams) =>
    request
      .get<V2SupplierListResponse>({
        url: '/v2/admin/suppliers',
        params,
      })
      .then((response) => normalizeV2SupplierList(response)),
  summary: () => request.get<SupplierSummary>({ url: '/v2/admin/suppliers/summary' }),
  providerTypes: () =>
    request.get<ProviderTypeRecord[] | Record<string, string>>({ url: '/v2/admin/suppliers/provider-types' }),
  detail: async (id: number | string) => {
    const response = await request.get<V2SupplierDetailResponse>({ url: `/v2/admin/suppliers/${id}` });
    return normalizeV2Supplier((response.supplier || {}) as V2SupplierRecord);
  },
  revealSecret: (id: number | string, key: string) =>
    request.get<{ key: string; value: unknown }>({
      url: `/v2/admin/suppliers/${id}/secrets/${encodeURIComponent(key)}`,
    }),
  create: async (data: SupplierUpsertPayload) => {
    const response = await request.post<V2SupplierDetailResponse>({ url: '/v2/admin/suppliers', data });
    return normalizeV2Supplier((response.supplier || {}) as V2SupplierRecord);
  },
  update: async (id: number | string, data: SupplierUpsertPayload) => {
    const response = await request.put<V2SupplierDetailResponse>({ url: `/v2/admin/suppliers/${id}`, data });
    return normalizeV2Supplier((response.supplier || {}) as V2SupplierRecord);
  },
  delete: (id: number | string) => request.delete<SupplierActionResult>({ url: `/v2/admin/suppliers/${id}` }),
  toggleStatus: (id: number | string, enabled: boolean) =>
    request.patch<SupplierActionResult>({ url: `/v2/admin/suppliers/${id}/status`, data: { enabled } }),
  balance: (id: number | string, config: Record<string, unknown> = {}) =>
    request.get<Record<string, unknown>>({ url: `/v2/admin/suppliers/${id}/balance`, ...config }),
  products: (id: number | string, config: Record<string, unknown> = {}) =>
    request.get<{ list?: Record<string, unknown>[]; groups?: Record<string, unknown>[] } | Record<string, unknown>[]>({
      url: `/v2/admin/suppliers/${id}/products`,
      ...config,
    }),
  executeAction: (id: number | string, action: string, payload: Record<string, unknown> = {}) =>
    request
      .post<SupplierActionResult>({
        url: `/v2/admin/suppliers/${id}/tasks`,
        data: { type: action, payload },
      })
      .then((response) => response.detail?.result || {}),
  productConfigTemplate: (
    supplierId: number | string,
    productId: number | string,
    config: Record<string, unknown> = {},
  ) =>
    request.get<Record<string, unknown>>({
      url: `/v2/admin/suppliers/${supplierId}/products/${productId}/config-template`,
      ...config,
    }),
};
