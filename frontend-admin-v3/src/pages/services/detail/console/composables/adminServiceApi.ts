import { userApi } from '@/api/user';

import type {
  ApiEnvelope,
  ConsoleServiceDetail,
  FinanceLedgerRecord,
  FinanceLedgerSummary,
  MonitorBatchPayload,
  NatForwardingPayload,
  PagedList,
  SecurityGroupPayload,
  SecurityRulePayload,
  ServiceConsoleAreaTicket,
  ServiceConsoleCapabilities,
  ServiceNameUpdatePayload,
  ServiceOperationLogPayload,
  ServicePasswordResetPayload,
  ServicePowerActionPayload,
  ServiceReinstallOptionsPayload,
  ServiceReinstallPayload,
  ServiceRemarkUpdatePayload,
  ServiceRenewOrderPayload,
  ServiceRenewPreview,
  ServiceTrafficPackageOrderPayload,
  ServiceTrafficPackagePreview,
  ServiceTrafficPackageQuote,
  ServiceVncPayload,
} from '../types';

export interface ServiceConsoleApi {
  serviceDetail: (
    id: number,
    config?: { params?: Record<string, unknown> } | undefined,
  ) => Promise<ApiEnvelope<ConsoleServiceDetail>>;
  serviceBaseDetail: (id: number) => Promise<ApiEnvelope<ConsoleServiceDetail>>;
  serviceRemoteStatus: (id: number) => Promise<ApiEnvelope<Partial<ConsoleServiceDetail>>>;
  serviceConsoleCapabilities: (id: number) => Promise<ApiEnvelope<ServiceConsoleCapabilities | null>>;
  serviceModuleStatus: (id: number, params?: Record<string, unknown>) => Promise<ApiEnvelope<unknown>>;
  serviceOperationLogs: (
    id: number,
    params?: Record<string, unknown>,
  ) => Promise<ApiEnvelope<ServiceOperationLogPayload>>;
  serviceMonitor: (id: number, params?: Record<string, unknown>) => Promise<ApiEnvelope<unknown>>;
  serviceMonitorBatch: (
    id: number,
    params?: Record<string, unknown>,
    config?: Record<string, unknown>,
  ) => Promise<ApiEnvelope<MonitorBatchPayload>>;
  serviceNatForwardings: (id: number) => Promise<ApiEnvelope<NatForwardingPayload>>;
  createNatForwarding: (id: number, data: Record<string, unknown>) => Promise<ApiEnvelope<unknown>>;
  deleteNatForwarding: (id: number, forwardingId: number) => Promise<ApiEnvelope<unknown>>;
  serviceSecurityGroups: (id: number, params?: Record<string, unknown>) => Promise<ApiEnvelope<SecurityGroupPayload>>;
  serviceSecurityGroupRules: (id: number, groupId: number | string) => Promise<ApiEnvelope<SecurityRulePayload>>;
  createSecurityGroup: (id: number, data: Record<string, unknown>) => Promise<ApiEnvelope<{ message?: string }>>;
  applySecurityGroup: (id: number, groupId: number | string) => Promise<ApiEnvelope<{ message?: string }>>;
  deleteSecurityGroup: (id: number, groupId: number | string) => Promise<ApiEnvelope<{ message?: string }>>;
  createSecurityRule: (
    id: number,
    groupId: number | string,
    data: Record<string, unknown>,
  ) => Promise<ApiEnvelope<{ message?: string }>>;
  deleteSecurityRule: (
    id: number,
    groupId: number | string,
    ruleId: number,
  ) => Promise<ApiEnvelope<{ message?: string }>>;
  serviceVnc: (id: number, config?: Record<string, unknown>) => Promise<ApiEnvelope<ServiceVncPayload>>;
  serviceTrafficPackages: (id: number) => Promise<ApiEnvelope<ServiceTrafficPackagePreview>>;
  quoteTrafficPackage: (id: number, data: Record<string, unknown>) => Promise<ApiEnvelope<ServiceTrafficPackageQuote>>;
  createTrafficPackageOrder: (
    id: number,
    data: Record<string, unknown>,
  ) => Promise<ApiEnvelope<ServiceTrafficPackageOrderPayload>>;
  serviceRenewPreview: (id: number, params?: Record<string, unknown>) => Promise<ApiEnvelope<ServiceRenewPreview>>;
  createRenewOrder: (id: number, data: Record<string, unknown>) => Promise<ApiEnvelope<ServiceRenewOrderPayload>>;
  updateAutoRenew: (id: number, data: Record<string, unknown>) => Promise<ApiEnvelope<ConsoleServiceDetail>>;
  servicePower: (id: number, data: Record<string, unknown>) => Promise<ApiEnvelope<ServicePowerActionPayload>>;
  serviceReinstallOptions: (
    id: number,
    params?: Record<string, unknown>,
  ) => Promise<ApiEnvelope<ServiceReinstallOptionsPayload>>;
  serviceReinstall: (id: number, data: Record<string, unknown>) => Promise<ApiEnvelope<ServiceReinstallPayload>>;
  serviceResetPassword: (
    id: number,
    data: Record<string, unknown>,
  ) => Promise<ApiEnvelope<ServicePasswordResetPayload>>;
  serviceRescue: (id: number, data: Record<string, unknown>) => Promise<ApiEnvelope<ServicePowerActionPayload>>;
  updateServiceName: (id: number, data: Record<string, unknown>) => Promise<ApiEnvelope<ServiceNameUpdatePayload>>;
  updateServiceRemark: (id: number, data: Record<string, unknown>) => Promise<ApiEnvelope<ServiceRemarkUpdatePayload>>;
  createServiceConsoleAreaTicket: (id: number) => Promise<ApiEnvelope<ServiceConsoleAreaTicket>>;
  serviceConsoleAreaContentUrl: (id: number, ticket: string, moduleKey: string) => string;
  financeLedger: (
    params?: Record<string, unknown>,
  ) => Promise<ApiEnvelope<PagedList<FinanceLedgerRecord> & { summary?: FinanceLedgerSummary }>>;
  financeLedgerSummary: (params?: Record<string, unknown>) => Promise<ApiEnvelope<FinanceLedgerSummary>>;
}

function envelope<T>(data: T): ApiEnvelope<T> {
  return { code: 0, data };
}

/** 组装管理端实例控制台 API：统一携带所属用户，响应统一包装为 { data } 信封。 */
export function createServiceConsoleApi(userId: number | string): ServiceConsoleApi {
  const uid = String(userId ?? '');

  return {
    serviceDetail: (id) =>
      userApi.serviceDetail(uid, id).then((data) => envelope((data || {}) as ConsoleServiceDetail)),
    serviceBaseDetail: (id) =>
      userApi.serviceDetail(uid, id).then((data) => envelope((data || {}) as ConsoleServiceDetail)),
    serviceRemoteStatus: (id) =>
      userApi.serviceRemoteStatus(uid, id).then((data) => envelope((data || {}) as Partial<ConsoleServiceDetail>)),
    serviceConsoleCapabilities: (id) =>
      userApi
        .serviceConsoleCapabilities(uid, id)
        .then((data) =>
          envelope(((data as ServiceConsoleCapabilities | null) || null) as ServiceConsoleCapabilities | null),
        ),
    serviceModuleStatus: (id, params) => userApi.serviceModuleStatus(uid, id, params).then((data) => envelope(data)),
    serviceOperationLogs: (id, params) =>
      userApi
        .serviceOperationLogs(uid, id, (params || {}) as { page?: number; page_size?: number })
        .then((data) => envelope((data || { list: [], total: 0 }) as ServiceOperationLogPayload)),
    serviceMonitor: (id, params) => userApi.serviceMonitor(uid, id, params).then((data) => envelope(data)),
    serviceMonitorBatch: (id, params) =>
      userApi.serviceMonitorBatch(uid, id, params).then((data) => envelope((data || {}) as MonitorBatchPayload)),
    serviceNatForwardings: (id) =>
      userApi.serviceNatForwardings(uid, id).then((data) => envelope((data || {}) as NatForwardingPayload)),
    createNatForwarding: (id, data) => userApi.createNatForwarding(uid, id, data).then((result) => envelope(result)),
    deleteNatForwarding: (id, forwardingId) =>
      userApi.deleteNatForwarding(uid, id, forwardingId).then((result) => envelope(result)),
    serviceSecurityGroups: (id, params) =>
      userApi.serviceSecurityGroups(uid, id, params).then((data) => envelope((data || {}) as SecurityGroupPayload)),
    serviceSecurityGroupRules: (id, groupId) =>
      userApi.serviceSecurityGroupRules(uid, id, groupId).then((data) => envelope((data || {}) as SecurityRulePayload)),
    createSecurityGroup: (id, data) =>
      userApi.createSecurityGroup(uid, id, data).then((result) => envelope(result as { message?: string })),
    applySecurityGroup: (id, groupId) =>
      userApi.applySecurityGroup(uid, id, groupId).then((result) => envelope(result as { message?: string })),
    deleteSecurityGroup: (id, groupId) =>
      userApi.deleteSecurityGroup(uid, id, groupId).then((result) => envelope(result as { message?: string })),
    createSecurityRule: (id, groupId, data) =>
      userApi.createSecurityRule(uid, id, groupId, data).then((result) => envelope(result as { message?: string })),
    deleteSecurityRule: (id, groupId, ruleId) =>
      userApi.deleteSecurityRule(uid, id, groupId, ruleId).then((result) => envelope(result as { message?: string })),
    serviceVnc: (id) => userApi.serviceVnc(uid, id).then((data) => envelope((data || {}) as ServiceVncPayload)),
    serviceTrafficPackages: (id) =>
      userApi.serviceTrafficPackages(uid, id).then((data) => envelope((data || {}) as ServiceTrafficPackagePreview)),
    quoteTrafficPackage: (id, data) =>
      userApi.quoteTrafficPackage(uid, id, data).then((result) => envelope(result as ServiceTrafficPackageQuote)),
    createTrafficPackageOrder: (id, data) =>
      userApi
        .createTrafficPackageOrder(uid, id, data)
        .then((result) => envelope(result as ServiceTrafficPackageOrderPayload)),
    serviceRenewPreview: (id) =>
      userApi.serviceRenewPreview(uid, id).then((data) => {
        const payload = (data as { preview?: ServiceRenewPreview }) || {};
        return envelope((payload.preview || {}) as ServiceRenewPreview);
      }),
    createRenewOrder: (id, data) =>
      userApi
        .serviceRenewOrder(uid, id, data as { billing_cycle: string })
        .then((result) => envelope(result as ServiceRenewOrderPayload)),
    updateAutoRenew: (id, data) =>
      userApi
        .updateAutoRenew(uid, id, data as { auto_renew: number })
        .then((result) => envelope((result || {}) as ConsoleServiceDetail)),
    servicePower: (id, data) =>
      userApi
        .servicePower(uid, id, data as { action: string })
        .then((result) => envelope((result || {}) as ServicePowerActionPayload)),
    serviceReinstallOptions: (id) =>
      userApi.serviceReinstallOptions(uid, id).then((data) => envelope((data || {}) as ServiceReinstallOptionsPayload)),
    serviceReinstall: (id, data) =>
      userApi
        .serviceReinstall(uid, id, data as { os_id: string })
        .then((result) => envelope((result || {}) as ServiceReinstallPayload)),
    serviceResetPassword: (id, data) =>
      userApi
        .serviceResetPassword(uid, id, data as { password: string; password_confirmation?: string })
        .then((result) => envelope((result || {}) as ServicePasswordResetPayload)),
    serviceRescue: (id, data) =>
      userApi.serviceRescue(uid, id, data).then((result) => envelope((result || {}) as ServicePowerActionPayload)),
    updateServiceName: (id, data) =>
      userApi
        .updateServiceName(uid, id, data as { name: string | null })
        .then((result) => envelope((result || {}) as ServiceNameUpdatePayload)),
    updateServiceRemark: (id, data) =>
      userApi
        .updateServiceRemark(uid, id, data as { remark: string | null })
        .then((result) => envelope((result || {}) as ServiceRemarkUpdatePayload)),
    createServiceConsoleAreaTicket: (id) =>
      userApi.createConsoleAreaTicket(uid, id).then((data) => envelope((data || {}) as ServiceConsoleAreaTicket)),
    serviceConsoleAreaContentUrl: (id, ticket, moduleKey) =>
      userApi.serviceConsoleAreaContentUrl(uid, id, ticket, moduleKey),
    financeLedger: (params) => {
      const query: { page?: number; page_size?: number; event_type?: string; type?: string } = {
        page: Number(params?.page || 1),
        page_size: Number(params?.page_size || 10),
      };
      if (params?.event_type) query.event_type = String(params.event_type);
      if (params?.type) query.type = String(params.type);
      return userApi.balanceLogs(uid, query).then((data) =>
        envelope(
          (data || { list: [], total: 0 }) as PagedList<FinanceLedgerRecord> & {
            summary?: FinanceLedgerSummary;
          },
        ),
      );
    },
    financeLedgerSummary: (params) =>
      userApi.balanceLogs(uid, { page: 1, page_size: 1, ...params }).then((data) => {
        const payload = data as { summary?: FinanceLedgerSummary };
        return envelope((payload?.summary || {}) as FinanceLedgerSummary);
      }),
  };
}
