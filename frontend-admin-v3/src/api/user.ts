import { request } from '@/utils/request';

import type { PagedListParams } from './types';

export interface UserListParams extends PagedListParams {
  user_id?: number | string;
  status?: number | string;
}

export interface PageParams {
  page?: number;
  page_size?: number;
  [key: string]: unknown;
}

export interface AdminUser {
  id: number | string;
  phone?: string;
  email?: string;
  nickname?: string;
  display_name?: string;
  company?: string;
  qq?: string;
  admin_note?: string;
  real_name?: string;
  id_card_masked?: string;
  member_level?: Record<string, unknown> | null;
  agent_group?: {
    id?: number | string;
    name?: string;
    code?: string;
    status?: number | string;
  } | null;
  agent_group_id?: number | string | null;
  referrer_user_id?: number | string | null;
  last_login_at?: string;
  last_login_ip?: string;
  cash_balance?: number | string;
  credit_limit?: number | string;
  referral_withdrawn_balance?: number | string;
  opened_product_count?: number | string;
  status?: number | string;
  verification_status?: number | string;
  is_verified?: number | boolean;
  created_at?: string;
}

export interface UserCreatePayload {
  email?: string;
  nickname?: string;
  phone: string;
  password?: string;
}

export interface UserRechargePayload {
  amount: number;
  remark: string;
}

export interface UserUpdatePayload {
  nickname?: string;
  phone?: string;
  password?: string;
  status?: number | string;
  admin_note?: string;
  agent_group_id?: number | string | null;
}

export interface UserDetailResponse {
  user?: AdminUser;
  stats?: Record<string, number | string>;
  referral?: Record<string, unknown> | null;
}

export interface UserServicePayload {
  product_id: number;
  billing_cycle: string;
  source_type?: 'manual' | 'upstream';
  status: number | string;
  name?: string;
  amount: number;
  auto_renew?: number;
  create_order?: number;
  create_invoice?: number;
  deduct_balance?: number;
  upstream_host_id?: number | null;
  upstream_status?: string;
  os?: string;
  remark?: string;
}

export interface ServiceMetaPayload {
  supplier_id?: number | null;
  upstream_host_id?: number | null;
  amount?: number;
  locked_pricing?: Record<string, { enabled?: boolean; manual_amount?: number | null }>;
  clear_locked_pricing?: boolean;
  service_name?: string;
}

export interface RefundPayload {
  refund_method: 'balance' | 'original';
  amount?: number | string;
  remark: string;
}

interface UserServiceV2DetailPayload {
  service?: Record<string, unknown> | null;
}

interface UserServiceV2ConnectionPayload {
  connection?: Record<string, unknown> | null;
}

function normalizeV2ServicePayload(payload: UserServiceV2DetailPayload | Record<string, unknown> | null | undefined) {
  const service = ((payload as UserServiceV2DetailPayload | undefined)?.service || payload || {}) as Record<
    string,
    unknown
  >;
  const renewal = (service.renewal || {}) as Record<string, unknown>;

  return {
    ...service,
    renew_pricing_cycles: Array.isArray(service.renew_pricing_cycles)
      ? service.renew_pricing_cycles
      : Array.isArray(renewal.cycles)
        ? renewal.cycles
        : [],
  };
}

async function v2UserServiceDetail(id: number | string, serviceId: number | string) {
  const [detailPayload, connectionPayload] = await Promise.all([
    request.get<UserServiceV2DetailPayload>({ url: `/v2/admin/users/${id}/services/${serviceId}` }),
    request.get<UserServiceV2ConnectionPayload>({ url: `/v2/admin/users/${id}/services/${serviceId}/connection` }),
  ]);
  const service = normalizeV2ServicePayload(detailPayload);

  return {
    ...service,
    connection: connectionPayload.connection || null,
  };
}

export const userApi = {
  list: (params: UserListParams) =>
    request.get<{ list?: AdminUser[]; total?: number; page?: number; page_size?: number }>({
      url: '/v2/admin/users',
      params,
    }),
  detail: (id: number | string) => request.get<UserDetailResponse>({ url: `/v2/admin/users/${id}` }),
  create: (data: UserCreatePayload) => request.post({ url: '/v2/admin/users', data }),
  update: (id: number | string, data: UserUpdatePayload) => request.put({ url: `/v2/admin/users/${id}`, data }),
  delete: (id: number | string) => request.delete({ url: `/v2/admin/users/${id}` }),
  toggleStatus: (id: number | string, enabled: boolean) =>
    request.patch({ url: `/v2/admin/users/${id}/status`, data: { enabled } }),
  recharge: (id: number | string, data: UserRechargePayload) =>
    request.post({ url: `/v2/admin/users/${id}/recharges`, data }),
  loginAs: (id: number | string) =>
    request.post<{ login_code?: string; target_url?: string }>({ url: `/v2/admin/users/${id}/login-as` }),
  services: (id: number | string, params: PageParams) =>
    request.get<{ list?: Record<string, unknown>[]; total?: number; page?: number; page_size?: number }>({
      url: `/v2/admin/users/${id}/services`,
      params,
    }),
  storeService: (id: number | string, data: UserServicePayload) =>
    request
      .post<UserServiceV2DetailPayload>({
        url: `/v2/admin/users/${id}/services`,
        data: { source_type: 'manual', ...data },
      })
      .then(normalizeV2ServicePayload),
  invoices: (id: number | string, params: PageParams) =>
    request.get<{ list?: Record<string, unknown>[]; total?: number; page?: number; page_size?: number }>({
      url: `/v2/admin/users/${id}/invoices`,
      params,
    }),
  invoiceDetail: (id: number | string, invoiceId: number | string) =>
    request.get<Record<string, unknown>>({ url: `/v2/admin/users/${id}/invoices/${invoiceId}` }),
  refundInvoice: (id: number | string, invoiceId: number | string, data: RefundPayload) =>
    request.post({ url: `/v2/admin/users/${id}/invoices/${invoiceId}/refunds`, data }),
  balanceLogs: (id: number | string, params: PageParams) =>
    request.get<{
      list?: Record<string, unknown>[];
      total?: number;
      page?: number;
      page_size?: number;
      summary?: unknown;
    }>({
      url: `/v2/admin/users/${id}/balance-logs`,
      params,
    }),
  tickets: (id: number | string, params: PageParams) =>
    request.get<{
      list?: Record<string, unknown>[];
      total?: number;
      page?: number;
      page_size?: number;
      summary?: unknown;
    }>({
      url: `/v2/admin/users/${id}/tickets`,
      params,
    }),
  operationLogs: (id: number | string, params: PageParams) =>
    request.get<{ list?: Record<string, unknown>[]; total?: number; page?: number; page_size?: number }>({
      url: `/v2/admin/users/${id}/operation-logs`,
      params,
    }),
  smsLogs: (id: number | string, params: PageParams) =>
    request.get<{ list?: Record<string, unknown>[]; total?: number; page?: number; page_size?: number }>({
      url: `/v2/admin/users/${id}/sms-logs`,
      params,
    }),
  emailLogs: (id: number | string, params: PageParams) =>
    request.get<{ list?: Record<string, unknown>[]; total?: number; page?: number; page_size?: number }>({
      url: `/v2/admin/users/${id}/email-logs`,
      params,
    }),
  serviceDetail: (id: number | string, serviceId: number | string) => v2UserServiceDetail(id, serviceId),
  serviceRemoteStatus: (id: number | string, serviceId: number | string) =>
    request
      .get<UserServiceV2DetailPayload>({ url: `/v2/admin/users/${id}/services/${serviceId}/remote-status` })
      .then(normalizeV2ServicePayload),
  refreshServiceStatuses: (id: number | string, data: { service_ids: Array<number | string> }) =>
    request.post({ url: `/v2/admin/users/${id}/services/refresh-statuses`, data }),
  servicePower: (id: number | string, serviceId: number | string, data: { action: string }) =>
    request.post<{ detail?: Record<string, unknown>; message?: string }>({
      url: `/v2/admin/users/${id}/services/${serviceId}/power-actions`,
      data,
    }),
  serviceResetPassword: (
    id: number | string,
    serviceId: number | string,
    data: { password: string; password_confirmation?: string },
  ) =>
    request.post({
      url: `/v2/admin/users/${id}/services/${serviceId}/password-resets`,
      data: {
        ...data,
        password_confirmation: data.password_confirmation || data.password,
      },
    }),
  serviceDelete: (id: number | string, serviceId: number | string) =>
    request.delete({ url: `/v2/admin/users/${id}/services/${serviceId}` }),
  updateServiceMeta: (id: number | string, serviceId: number | string, data: ServiceMetaPayload) =>
    request
      .put<UserServiceV2DetailPayload>({ url: `/v2/admin/users/${id}/services/${serviceId}/meta`, data })
      .then(normalizeV2ServicePayload),
  manualProvisionService: (id: number | string, serviceId: number | string, data: { upstream_host_id: number }) =>
    request
      .put<UserServiceV2DetailPayload>({ url: `/v2/admin/users/${id}/services/${serviceId}/manual-provision`, data })
      .then(normalizeV2ServicePayload),
  refundService: (id: number | string, serviceId: number | string, data: RefundPayload) =>
    request.post<{ message?: string }>({ url: `/v2/admin/users/${id}/services/${serviceId}/refunds`, data }),
  serviceSuspend: (id: number | string, serviceId: number | string, data: { reason?: string }) =>
    request.post<{ message?: string; detail?: Record<string, unknown> }>({
      url: `/v2/admin/users/${id}/services/${serviceId}/suspend`,
      data,
    }),
  serviceUnsuspend: (id: number | string, serviceId: number | string) =>
    request.post<{ message?: string; detail?: Record<string, unknown> }>({
      url: `/v2/admin/users/${id}/services/${serviceId}/unsuspend`,
    }),
  serviceReinstallOptions: (id: number | string, serviceId: number | string) =>
    request.get<{
      os?: { os_id: string; name: string; group_name: string }[];
      os_groups?: { group_name: string; img: string }[];
    }>({
      url: `/v2/admin/users/${id}/services/${serviceId}/reinstallations/options`,
    }),
  serviceReinstall: (id: number | string, serviceId: number | string, data: { os_id: string }) =>
    request.post<{ message?: string; detail?: Record<string, unknown> }>({
      url: `/v2/admin/users/${id}/services/${serviceId}/reinstallations`,
      data,
    }),
  serviceRenewPreview: (id: number | string, serviceId: number | string) =>
    request.get<{ preview?: Record<string, unknown> }>({
      url: `/v2/admin/users/${id}/services/${serviceId}/renewals`,
    }),
  serviceRenewOrder: (id: number | string, serviceId: number | string, data: { billing_cycle: string }) =>
    request.post<{ message?: string; detail?: { order?: Record<string, unknown> } }>({
      url: `/v2/admin/users/${id}/services/${serviceId}/renewals`,
      data,
    }),
  serviceConfig: (id: number | string, serviceId: number | string) =>
    request.get<Record<string, unknown>>({ url: `/v2/admin/users/${id}/services/${serviceId}/config` }),
  serviceConsoleCapabilities: (id: number | string, serviceId: number | string) =>
    request.get<Record<string, unknown>>({ url: `/v2/admin/users/${id}/services/${serviceId}/console/capabilities` }),
  createConsoleAreaTicket: (id: number | string, serviceId: number | string) =>
    request.post<{ ticket?: string; expires_at?: string }>({
      url: `/v2/admin/users/${id}/services/${serviceId}/console/tickets`,
    }),
  serviceConsoleAreaContentUrl: (
    id: number | string,
    serviceId: number | string,
    ticket: string,
    moduleKey: string,
  ) => {
    const apiBase = String(import.meta.env.VITE_API_BASE_URL || '')
      .trim()
      .replace(/\/+$/, '');
    return `${apiBase}/v2/client/services/${serviceId}/console-area/content?ticket=${encodeURIComponent(
      ticket,
    )}&module=${encodeURIComponent(moduleKey)}`;
  },
  serviceModuleStatus: (id: number | string, serviceId: number | string, params?: PageParams) =>
    request.get<Record<string, unknown>>({
      url: `/v2/admin/users/${id}/services/${serviceId}/module-status`,
      params,
    }),
  serviceOperationLogs: (id: number | string, serviceId: number | string, params: PageParams) =>
    request.get<{ list?: Record<string, unknown>[]; total?: number; page?: number; page_size?: number }>({
      url: `/v2/admin/users/${id}/services/${serviceId}/operation-logs`,
      params,
    }),
  serviceMonitor: (id: number | string, serviceId: number | string, params?: PageParams) =>
    request.get<Record<string, unknown>>({ url: `/v2/admin/users/${id}/services/${serviceId}/monitor`, params }),
  serviceMonitorBatch: (id: number | string, serviceId: number | string, params?: PageParams) =>
    request.get<Record<string, unknown>>({
      url: `/v2/admin/users/${id}/services/${serviceId}/monitor/batch`,
      params,
    }),
  serviceTrafficPackages: (id: number | string, serviceId: number | string) =>
    request.get<Record<string, unknown>>({ url: `/v2/admin/users/${id}/services/${serviceId}/traffic-packages` }),
  quoteTrafficPackage: (id: number | string, serviceId: number | string, data: Record<string, unknown>) =>
    request.post<Record<string, unknown>>({
      url: `/v2/admin/users/${id}/services/${serviceId}/traffic-packages/quote`,
      data,
    }),
  createTrafficPackageOrder: (id: number | string, serviceId: number | string, data: Record<string, unknown>) =>
    request.post<{ id?: number; invoice_no?: string; service_id?: number; message?: string }>({
      url: `/v2/admin/users/${id}/services/${serviceId}/traffic-packages/orders`,
      data,
    }),
  serviceUpgradePreview: (id: number | string, serviceId: number | string) =>
    request.get<Record<string, unknown>>({ url: `/v2/admin/users/${id}/services/${serviceId}/upgrades` }),
  quoteHostUpgrade: (id: number | string, serviceId: number | string, data: Record<string, unknown>) =>
    request.post<Record<string, unknown>>({
      url: `/v2/admin/users/${id}/services/${serviceId}/upgrades/quotes`,
      data,
    }),
  createHostUpgradeOrder: (id: number | string, serviceId: number | string, data: Record<string, unknown>) =>
    request.post<{ id?: number; invoice_no?: string; service_id?: number; message?: string }>({
      url: `/v2/admin/users/${id}/services/${serviceId}/upgrades/orders`,
      data,
    }),
  updateAutoRenew: (id: number | string, serviceId: number | string, data: { auto_renew: number }) =>
    request
      .put<UserServiceV2DetailPayload | Record<string, unknown>>({
        url: `/v2/admin/users/${id}/services/${serviceId}/renewals/auto`,
        data,
      })
      .then(normalizeV2ServicePayload),
  updateServiceName: (id: number | string, serviceId: number | string, data: { name: string | null }) =>
    request
      .put<UserServiceV2DetailPayload>({ url: `/v2/admin/users/${id}/services/${serviceId}/name`, data })
      .then(normalizeV2ServicePayload),
  updateServiceRemark: (id: number | string, serviceId: number | string, data: { remark: string | null }) =>
    request
      .put<UserServiceV2DetailPayload>({ url: `/v2/admin/users/${id}/services/${serviceId}/remark`, data })
      .then(normalizeV2ServicePayload),
  serviceRescue: (id: number | string, serviceId: number | string, data: Record<string, unknown>) =>
    request.post<{
      message?: string;
      second_verify?: boolean;
      detail?: Record<string, unknown>;
      task_id?: string;
      status?: string;
    }>({
      url: `/v2/admin/users/${id}/services/${serviceId}/rescue`,
      data,
    }),
  serviceVnc: (id: number | string, serviceId: number | string) =>
    request.get<Record<string, unknown>>({ url: `/v2/admin/users/${id}/services/${serviceId}/vnc` }),
  serviceNatForwardings: (id: number | string, serviceId: number | string) =>
    request.get<Record<string, unknown>>({ url: `/v2/admin/users/${id}/services/${serviceId}/nat-forwardings` }),
  createNatForwarding: (id: number | string, serviceId: number | string, data: Record<string, unknown>) =>
    request.post<Record<string, unknown>>({
      url: `/v2/admin/users/${id}/services/${serviceId}/nat-forwardings`,
      data,
    }),
  deleteNatForwarding: (id: number | string, serviceId: number | string, forwardingId: number | string) =>
    request.delete<Record<string, unknown>>({
      url: `/v2/admin/users/${id}/services/${serviceId}/nat-forwardings/${forwardingId}`,
    }),
  serviceSecurityGroups: (id: number | string, serviceId: number | string, params?: PageParams) =>
    request.get<Record<string, unknown>>({
      url: `/v2/admin/users/${id}/services/${serviceId}/security-groups`,
      params,
    }),
  serviceSecurityGroupRules: (id: number | string, serviceId: number | string, groupId: number | string) =>
    request.get<Record<string, unknown>>({
      url: `/v2/admin/users/${id}/services/${serviceId}/security-groups/${groupId}/rules`,
    }),
  createSecurityGroup: (id: number | string, serviceId: number | string, data: Record<string, unknown>) =>
    request.post<Record<string, unknown>>({
      url: `/v2/admin/users/${id}/services/${serviceId}/security-groups`,
      data,
    }),
  applySecurityGroup: (id: number | string, serviceId: number | string, groupId: number | string) =>
    request.post<Record<string, unknown>>({
      url: `/v2/admin/users/${id}/services/${serviceId}/security-groups/${groupId}/apply`,
    }),
  deleteSecurityGroup: (id: number | string, serviceId: number | string, groupId: number | string) =>
    request.delete<Record<string, unknown>>({
      url: `/v2/admin/users/${id}/services/${serviceId}/security-groups/${groupId}`,
    }),
  createSecurityRule: (
    id: number | string,
    serviceId: number | string,
    groupId: number | string,
    data: Record<string, unknown>,
  ) =>
    request.post<Record<string, unknown>>({
      url: `/v2/admin/users/${id}/services/${serviceId}/security-groups/${groupId}/rules`,
      data,
    }),
  deleteSecurityRule: (
    id: number | string,
    serviceId: number | string,
    groupId: number | string,
    ruleId: number | string,
  ) =>
    request.delete<Record<string, unknown>>({
      url: `/v2/admin/users/${id}/services/${serviceId}/security-groups/${groupId}/rules/${ruleId}`,
    }),
  osOptions: () => request.get<{ groups?: Record<string, unknown>[] }>({ url: '/v2/admin/os-options' }),
};
