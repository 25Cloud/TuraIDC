import { request } from '@/utils/request';

export type IntegrationPluginDomain = 'captcha' | 'verification' | 'payment' | 'mail' | 'sms' | 'upstream' | 'addons';

export interface IntegrationPluginConfigSchema {
  key: string;
  label?: string;
  title?: string;
  type?: string;
  required?: boolean;
  secret?: boolean;
  options?: Record<string, string> | Array<{ label?: string; value?: string | number | boolean }>;
  default?: unknown;
  value?: unknown;
  placeholder?: string;
  description?: string;
  content?: string;
  theme?: 'success' | 'info' | 'warning' | 'error';
  width?: 'full' | 'half';
  disabled?: boolean;
  visible?: boolean;
  min?: number;
  max?: number;
  step?: number;
  rows?: number;
  /** divider 专用：该分组是否可折叠 */
  collapsible?: boolean;
  /** divider 专用：初始是否收起（可折叠分组默认收起） */
  collapsed?: boolean;
  visible_when?: {
    field?: string;
    operator?: 'eq' | 'neq' | 'in' | 'not_in';
    value?: unknown;
  };
}

export interface IntegrationPluginRecord {
  id?: number | string | null;
  domain: IntegrationPluginDomain;
  slug: string;
  key: string;
  name: string;
  version?: string;
  entry_class?: string;
  provider_class?: string | null;
  capabilities?: string[];
  config_schema?: IntegrationPluginConfigSchema[];
  base_path?: string;
  is_installed?: boolean;
  is_enabled?: boolean;
  can_enable?: boolean;
  enable_disabled_reason?: string | null;
  status?: number;
  installed_at?: string | null;
  updated_at?: string | null;
  config?: Record<string, unknown>;
  has_secret_values?: Record<string, boolean>;
  secret_previews?: Record<string, IntegrationPluginSecretPreview>;
  binding_counts?: Record<string, number>;
  business_reference_count?: number;
  delete_mode?: 'delete' | 'disable_archive' | 'not_installed' | string;
  manifest_missing?: boolean;
  latest_runtime_log?: IntegrationPluginRuntimeLog | null;
}

export interface IntegrationPluginRuntimeLog {
  id?: number | string;
  trace_id?: string;
  action?: string;
  status?: string;
  error_message?: string;
  created_at?: string | null;
}

export interface IntegrationPluginHealthCheckResult {
  healthy: boolean;
  message: string;
  entry_class?: string;
  provider_class?: string | null;
  details?: Record<string, unknown>;
}

export interface IntegrationPluginSecretPreview {
  type?: string;
  configured?: boolean;
  count?: number;
  items?: Array<Record<string, unknown>>;
}

export interface IntegrationPluginSecretValueResponse {
  key: string;
  value: unknown;
}

export interface IntegrationPluginActionResult {
  id?: number | string;
  status?: string;
  task_id?: string;
  message?: string;
  detail?: Record<string, unknown>;
}

export interface IntegrationPluginTestResultData {
  success?: boolean;
  action?: string;
  message?: string;
  data?: Record<string, unknown>;
  raw?: Record<string, unknown>;
  status?: number;
  sent?: boolean;
  certify_id?: string;
  verify_url?: string;
  task_no?: string;
  qr_code?: string;
  out_trade_no?: string;
  verified?: boolean;
  healthy?: boolean;
  capability?: string;
  error_type?: string;
  amount?: number | string;
  provider?: string;
  entry_class?: string;
  provider_class?: string;
  trace_id?: string;
}

export interface IntegrationPluginTestResult {
  id?: number | string;
  status?: string;
  task_id?: string;
  message?: string;
  detail?: {
    type?: string;
    result?: IntegrationPluginTestResultData;
  };
}

export interface IntegrationPluginListResponse {
  list?: IntegrationPluginRecord[];
  total?: number;
  page?: number;
  page_size?: number;
}

type V2IntegrationPluginRecord = IntegrationPluginRecord & {
  configured_credentials?: Record<string, boolean>;
  credential_previews?: Record<string, IntegrationPluginSecretPreview>;
};

interface V2IntegrationPluginDetailResponse {
  plugin?: V2IntegrationPluginRecord;
}

interface V2IntegrationPluginSchemaResponse {
  schema?: Array<IntegrationPluginConfigSchema & { sensitive?: boolean }>;
}

function normalizeV2PluginSchemaField(
  field: IntegrationPluginConfigSchema & { sensitive?: boolean },
): IntegrationPluginConfigSchema {
  return {
    ...field,
    secret: Boolean(field.secret ?? field.sensitive),
  };
}

function normalizeV2PluginRecord(
  record?: V2IntegrationPluginRecord,
  schema?: V2IntegrationPluginSchemaResponse,
): IntegrationPluginRecord {
  const plugin = record || ({} as V2IntegrationPluginRecord);

  return {
    ...plugin,
    config_schema: Array.isArray(schema?.schema)
      ? schema.schema.map((field) => normalizeV2PluginSchemaField(field))
      : plugin.config_schema || [],
    has_secret_values: plugin.has_secret_values || plugin.configured_credentials || {},
    secret_previews: plugin.secret_previews || plugin.credential_previews || {},
  };
}

export const pluginsApi = {
  list: (params?: { domain?: IntegrationPluginDomain | '' }) =>
    request.get<IntegrationPluginListResponse>({ url: '/v2/admin/integration-plugins', params }),
  scan: async (params?: { domain?: IntegrationPluginDomain | '' }) => {
    await request.post<IntegrationPluginActionResult>({
      url: '/v2/admin/integration-plugin-scans',
      data: params || {},
    });

    return request.get<IntegrationPluginListResponse>({ url: '/v2/admin/integration-plugins', params });
  },
  install: async (data: { domain: IntegrationPluginDomain; slug: string }) => {
    const detail = await request.post<V2IntegrationPluginDetailResponse>({
      url: '/v2/admin/integration-plugins',
      data,
    });

    return normalizeV2PluginRecord(detail.plugin);
  },
  detail: async (id: number | string) => {
    const [detail, schema] = await Promise.all([
      request.get<V2IntegrationPluginDetailResponse>({ url: `/v2/admin/integration-plugins/${id}` }),
      request.get<V2IntegrationPluginSchemaResponse>({ url: `/v2/admin/integration-plugins/${id}/schema` }),
    ]);

    return normalizeV2PluginRecord(detail.plugin, schema);
  },
  updateConfig: async (id: number | string, config: Record<string, unknown>) => {
    const [detail, schema] = await Promise.all([
      request.put<V2IntegrationPluginDetailResponse>({
        url: `/v2/admin/integration-plugins/${id}/config`,
        data: { config },
      }),
      request.get<V2IntegrationPluginSchemaResponse>({ url: `/v2/admin/integration-plugins/${id}/schema` }),
    ]);

    return normalizeV2PluginRecord(detail.plugin, schema);
  },
  revealSecret: (id: number | string, key: string) =>
    request.get<IntegrationPluginSecretValueResponse>({
      url: `/v2/admin/integration-plugins/${id}/secrets/${encodeURIComponent(key)}`,
    }),
  enable: (id: number | string) =>
    request.patch<IntegrationPluginActionResult>({
      url: `/v2/admin/integration-plugins/${id}/status`,
      data: { enabled: true },
    }),
  disable: (id: number | string) =>
    request.patch<IntegrationPluginActionResult>({
      url: `/v2/admin/integration-plugins/${id}/status`,
      data: { enabled: false },
    }),
  remove: (id: number | string, force = false) =>
    request.delete<IntegrationPluginActionResult>({
      url: `/v2/admin/integration-plugins/${id}${force ? '?force=1' : ''}`,
    }),
  healthCheck: (id: number | string) =>
    request.post<IntegrationPluginTestResult>({
      url: `/v2/admin/integration-plugins/${id}/tasks`,
      data: { type: 'health_check' },
    }),
  testEmail: (id: number | string, data: { account_index: number; to: string }) =>
    request.post<IntegrationPluginTestResult>({
      url: `/v2/admin/integration-plugins/${id}/tasks`,
      data: { type: 'test_email', payload: data },
    }),
  testSms: (id: number | string, data: { phone: string }) =>
    request.post<IntegrationPluginTestResult>({
      url: `/v2/admin/integration-plugins/${id}/tasks`,
      data: { type: 'test_sms', payload: data },
    }),
  testVerification: (id: number | string, data: { real_name: string; card_no: string }) =>
    request.post<IntegrationPluginTestResult>({
      url: `/v2/admin/integration-plugins/${id}/tasks`,
      data: { type: 'test_verification', payload: data },
    }),
  testPayment: (id: number | string) =>
    request.post<IntegrationPluginTestResult>({
      url: `/v2/admin/integration-plugins/${id}/tasks`,
      data: { type: 'test_payment', payload: {} },
    }),
  testCaptcha: (
    id: number | string,
    data: {
      lot_number: string;
      captcha_output: string;
      pass_token: string;
      gen_time: string;
    },
  ) =>
    request.post<IntegrationPluginTestResult>({
      url: `/v2/admin/integration-plugins/${id}/tasks`,
      data: { type: 'test_captcha', payload: data },
    }),
  testConnection: (id: number | string) =>
    request.post<IntegrationPluginTestResult>({
      url: `/v2/admin/integration-plugins/${id}/tasks`,
      data: { type: 'test_connection', payload: {} },
    }),
};
