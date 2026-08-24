import type { AxiosRequestConfig } from 'axios';

import type {
  ApiEnvelope,
  ApiKeyRecord,
  ApiKeyStorePayload,
  ApiKeyUsageLogRecord,
  BalanceLog,
  ClientFinanceListParams,
  ConsoleConnectionInfo,
  ConsoleServiceDetail,
  ContentDetailPayload,
  ContentListPayload,
  ContentOverviewPayload,
  ContentUnreadCountPayload,
  CouponRecord,
  CouponSummary,
  FinanceLedgerRecord,
  FinanceLedgerSummary,
  InvoiceAlipayPaymentPayload,
  InvoiceAlipayStatusPayload,
  InvoiceBalancePaymentResult,
  InvoiceCreatePayload,
  InvoiceListSummary,
  InvoiceRecord,
  MonitorBatchPayload,
  NatForwardingPayload,
  OrderListSummary,
  OrderRecord,
  PagedList,
  PaymentRecord,
  RechargeGatewayOptionsPayload,
  RechargeOrderPayload,
  RechargeStatusPayload,
  ReferralAccountLogRecord,
  ReferralOverviewPayload,
  ReferralRewardRecord,
  ReferralUserBrief,
  ReferralWithdrawalApplyResult,
  ReferralWithdrawalRecord,
  SecurityGroupPayload,
  SecurityRulePayload,
  ServiceInstance,
  ServiceNameUpdatePayload,
  ServiceOperationLogPayload,
  ServiceOverviewPayload,
  ServicePasswordResetPayload,
  ServicePowerActionPayload,
  ServiceReinstallOptionsPayload,
  ServiceReinstallPayload,
  ServiceRemarkUpdatePayload,
  ServiceRenewPreview,
  ServiceTrafficPackageOrderPayload,
  ServiceTrafficPackagePreview,
  ServiceTrafficPackageQuote,
  ServiceVncPayload,
  TicketImageUploadPayload,
  TicketRecord,
  TicketReplyRecord,
  TicketServiceOption,
} from '@/types/client';
import request from '@/utils/request';

import {
  normalizeContentDetailPayload,
  normalizeContentListPayload,
  normalizeContentOverviewPayload,
  withNormalizedData,
} from './contentNormalizer';

type QueryParams = Record<string, unknown> | undefined;
type RequestConfig = (AxiosRequestConfig & { silentError?: boolean }) | undefined;

const LONG_RUNNING_REQUEST_TIMEOUT = 45000;
const LONG_RUNNING_REQUEST_CONFIG: RequestConfig = { timeout: LONG_RUNNING_REQUEST_TIMEOUT };
const SILENT_LONG_RUNNING_REQUEST_CONFIG: RequestConfig = { ...LONG_RUNNING_REQUEST_CONFIG, silentError: true };

function getEnvelope<T>(url: string, config?: RequestConfig) {
  return request.get<ApiEnvelope<T>, ApiEnvelope<T>>(url, config);
}

function postEnvelope<T>(url: string, data?: Record<string, unknown> | FormData, config?: RequestConfig) {
  return request.post<ApiEnvelope<T>, ApiEnvelope<T>>(url, data, config);
}

function putEnvelope<T>(url: string, data?: Record<string, unknown> | FormData, config?: RequestConfig) {
  return request.put<ApiEnvelope<T>, ApiEnvelope<T>>(url, data, config);
}

interface V2ServiceDetailPayload {
  service?: ConsoleServiceDetail | null;
}

interface V2ServiceConnectionPayload {
  connection?: ConsoleConnectionInfo | null;
}

interface V2ServiceRuntimePayload {
  runtime?: Partial<ConsoleServiceDetail> | null;
}

interface V2TicketDetailPayload {
  ticket?: TicketRecord | null;
}

type V2TicketRepliesPayload = PagedList<TicketReplyRecord>;

interface V2InvoiceDetailPayload {
  invoice?: Record<string, unknown> | null;
}

type V2ClientLedgerPayload = PagedList<FinanceLedgerRecord> & {
  summary?: FinanceLedgerSummary;
};

function dataEnvelope<TData, TNextData>(response: ApiEnvelope<TData>, data: TNextData): ApiEnvelope<TNextData> {
  return {
    ...response,
    data,
  };
}

function toRecord(value: unknown): Record<string, unknown> {
  return value && typeof value === 'object' ? (value as Record<string, unknown>) : {};
}

function normalizeV2InvoiceDetail(payload: V2InvoiceDetailPayload | null | undefined): InvoiceRecord {
  const invoice = toRecord(payload?.invoice);
  const basic = toRecord(invoice.basic);
  const display = toRecord(invoice.display);
  const financial = toRecord(invoice.financial);
  const order = toRecord(invoice.order);
  const product = toRecord(invoice.product);
  const service = toRecord(invoice.service);
  const configuration = toRecord(invoice.configuration);
  const paymentChain = toRecord(invoice.payment_chain);
  const paymentOptions = toRecord(invoice.payment_options);
  const timestamps = toRecord(invoice.timestamps);

  return {
    id: Number(invoice.id || 0),
    invoice_no: String(basic.invoice_no || ''),
    type: String(basic.type || ''),
    type_label: String(basic.type_label || ''),
    status: basic.status as number | string | undefined,
    status_label: basic.status_label as string | undefined,
    billing_cycle: basic.billing_cycle as string | undefined,
    quantity: basic.quantity as number | undefined,
    due_date: basic.due_date as string | undefined,
    product_spec_snapshot: display.product_spec_snapshot as string | undefined,
    product_spec_display: display.product_spec_display as string | undefined,
    product_display_name: display.product_display_name as string | undefined,
    combined_display_name: display.combined_display_name as string | undefined,
    summary: display.summary as InvoiceRecord['summary'],
    amount: financial.amount as number | string | undefined,
    discount: financial.discount as number | string | undefined,
    paid_amount: financial.paid_amount as number | string | undefined,
    payable_amount: financial.payable_amount as number | string | undefined,
    paid_at: financial.paid_at as string | undefined,
    order_id: order.id,
    order: invoice.order as InvoiceRecord['order'],
    product_id: Number(product.id || 0),
    product: invoice.product as InvoiceRecord['product'],
    service_id: Number(paymentOptions.service_id || service.id || 0),
    service: invoice.service as InvoiceRecord['service'],
    scene: invoice.scene,
    config_snapshot: configuration.config_snapshot as Record<string, unknown> | null,
    config_pricing_snapshot: configuration.config_pricing_snapshot as Record<string, unknown> | null,
    coupon: configuration.coupon,
    payment_summary: paymentChain.payment_summary as InvoiceRecord['payment_summary'],
    payments: Array.isArray(paymentChain.payments) ? (paymentChain.payments as PaymentRecord[]) : [],
    pay_methods: Array.isArray(paymentOptions.pay_methods)
      ? (paymentOptions.pay_methods as InvoiceRecord['pay_methods'])
      : [],
    payment_security: (paymentOptions.payment_security as InvoiceRecord['payment_security']) || null,
    can_cancel: paymentOptions.can_cancel,
    items: Array.isArray(invoice.items) ? invoice.items : [],
    logs: Array.isArray(invoice.logs) ? invoice.logs : [],
    created_at: timestamps.created_at as string | undefined,
    updated_at: timestamps.updated_at as string | undefined,
  };
}

function v2InvoiceDetail(id: number | string) {
  return getEnvelope<V2InvoiceDetailPayload>(`/v2/client/invoices/${id}`).then((response) =>
    dataEnvelope(response, normalizeV2InvoiceDetail(response.data)),
  );
}

function v2FinanceLedger(params?: QueryParams) {
  return getEnvelope<V2ClientLedgerPayload>('/v2/client/finance/ledger', { params }).then((response) =>
    dataEnvelope(response, {
      list: response.data?.list || [],
      total: Number(response.data?.total || 0),
      page: Number(response.data?.page || 1),
      page_size: Number(response.data?.page_size || 15),
    }),
  );
}

function v2FinanceLedgerSummary(params?: QueryParams) {
  return getEnvelope<FinanceLedgerSummary>('/v2/client/finance/ledger/summary', { params }).then((response) =>
    dataEnvelope(response, response.data || {}),
  );
}

function v2ServiceDetail(id: number | string, config?: RequestConfig) {
  return getEnvelope<V2ServiceDetailPayload>(`/v2/client/services/${id}`, config).then((response) =>
    dataEnvelope(response, (response.data?.service || {}) as ConsoleServiceDetail),
  );
}

function v2ServiceBaseDetail(id: number | string) {
  return Promise.all([
    getEnvelope<V2ServiceDetailPayload>(`/v2/client/services/${id}`),
    getEnvelope<V2ServiceConnectionPayload>(`/v2/client/services/${id}/connection`),
  ]).then(([detailResponse, connectionResponse]) => {
    const service = (detailResponse.data?.service || {}) as ConsoleServiceDetail;

    return dataEnvelope(detailResponse, {
      ...service,
      connection: connectionResponse.data?.connection || null,
    });
  });
}

function v2ServiceRuntime(id: number | string) {
  return getEnvelope<V2ServiceRuntimePayload>(
    `/v2/client/services/${id}/runtime`,
    SILENT_LONG_RUNNING_REQUEST_CONFIG,
  ).then((response) => dataEnvelope(response, (response.data?.runtime || {}) as Partial<ConsoleServiceDetail>));
}

function v2TicketDetail(id: number | string) {
  return Promise.all([
    getEnvelope<V2TicketDetailPayload>(`/v2/client/tickets/${id}`),
    getEnvelope<V2TicketRepliesPayload>(`/v2/client/tickets/${id}/replies`, {
      params: { page: 1, page_size: 100 },
    }),
  ]).then(([detailResponse, repliesResponse]) => {
    const ticket = (detailResponse.data?.ticket || {}) as TicketRecord;

    return dataEnvelope(detailResponse, {
      ...ticket,
      replies: repliesResponse.data?.list || [],
    });
  });
}

const clientApi = {
  services: (params?: QueryParams) => getEnvelope<PagedList<ServiceInstance>>('/v2/client/services', { params }),
  groupedOverview: () => getEnvelope<ServiceOverviewPayload>('/v2/client/services/grouped-overview'),
  serviceDetail: (id: number | string) => v2ServiceDetail(id, { silentError: true }),
  serviceBaseDetail: (id: number | string) => v2ServiceBaseDetail(id),
  serviceRemoteStatus: (id: number | string) => v2ServiceRuntime(id),
  serviceConfig: (id: number | string) => request.get(`/v2/client/services/${id}/config`),
  updateServiceName: (id: number | string, data: Record<string, unknown>) =>
    putEnvelope<ServiceNameUpdatePayload>(`/v2/client/services/${id}/name`, data),
  updateServiceRemark: (id: number | string, data: Record<string, unknown>) =>
    putEnvelope<ServiceRemarkUpdatePayload>(`/v2/client/services/${id}/remark`, data),
  serviceTrafficPackages: (id: number | string) =>
    getEnvelope<ServiceTrafficPackagePreview>(`/v2/client/services/${id}/traffic-packages`),
  quoteTrafficPackage: (id: number | string, data: Record<string, unknown>) =>
    postEnvelope<ServiceTrafficPackageQuote>(`/v2/client/services/${id}/traffic-packages/quote`, data),
  createTrafficPackageOrder: (id: number | string, data: Record<string, unknown>) =>
    postEnvelope<ServiceTrafficPackageOrderPayload>(`/v2/client/services/${id}/traffic-packages/orders`, data),
  serviceRenewPreview: (id: number | string, params?: QueryParams) =>
    getEnvelope<ServiceRenewPreview>(`/v2/client/services/${id}/renewals`, { params }),
  createRenewOrder: (id: number | string, data: Record<string, unknown>) =>
    postEnvelope<{ id?: number }>(`/v2/client/services/${id}/renewals`, data),
  updateAutoRenew: (id: number | string, data: Record<string, unknown>) =>
    request.put(`/v2/client/services/${id}/renewals/auto`, data),
  servicePower: (id: number | string, data: Record<string, unknown>) =>
    postEnvelope<ServicePowerActionPayload>(`/v2/client/services/${id}/power-actions`, data),
  serviceModuleStatus: (id: number | string, params?: QueryParams) =>
    request.get(`/v2/client/services/${id}/module-status`, { params }),
  serviceReinstallOptions: (id: number | string, params?: QueryParams) =>
    getEnvelope<ServiceReinstallOptionsPayload>(`/v2/client/services/${id}/reinstallations/options`, { params }),
  serviceResetPassword: (id: number | string, data: Record<string, unknown>) =>
    postEnvelope<ServicePasswordResetPayload>(`/v2/client/services/${id}/password-resets`, data),
  serviceReinstall: (id: number | string, data: Record<string, unknown>) =>
    postEnvelope<ServiceReinstallPayload>(`/v2/client/services/${id}/reinstallations`, data),
  serviceOperationLogs: (id: number | string, params?: QueryParams) =>
    getEnvelope<ServiceOperationLogPayload>(`/v2/client/services/${id}/operation-logs`, { params }),
  serviceMonitor: (id: number | string, params?: QueryParams, config: Record<string, unknown> = {}) =>
    request.get(`/v2/client/services/${id}/monitor`, { params, ...config }),
  serviceMonitorBatch: (id: number | string, params?: QueryParams, config: Record<string, unknown> = {}) =>
    request.get<ApiEnvelope<MonitorBatchPayload>, ApiEnvelope<MonitorBatchPayload>>(
      `/v2/client/services/${id}/monitor/batch`,
      { params, ...config },
    ),
  serviceNatForwardings: (id: number | string) =>
    request.get<ApiEnvelope<NatForwardingPayload>, ApiEnvelope<NatForwardingPayload>>(
      `/v2/client/services/${id}/nat-forwardings`,
      LONG_RUNNING_REQUEST_CONFIG,
    ),
  createNatForwarding: (id: number | string, data: Record<string, unknown>) =>
    request.post(`/v2/client/services/${id}/nat-forwardings`, data, LONG_RUNNING_REQUEST_CONFIG),
  deleteNatForwarding: (id: number | string, forwardingId: number | string) =>
    request.delete(`/v2/client/services/${id}/nat-forwardings/${forwardingId}`, LONG_RUNNING_REQUEST_CONFIG),
  serviceSecurityGroups: (id: number | string, params?: QueryParams) =>
    getEnvelope<SecurityGroupPayload>(`/v2/client/services/${id}/security-groups`, { params }),
  serviceSecurityGroupRules: (id: number | string, groupId: number | string) =>
    getEnvelope<SecurityRulePayload>(`/v2/client/services/${id}/security-groups/${groupId}/rules`),
  createSecurityGroup: (id: number | string, data: Record<string, unknown>) =>
    postEnvelope<{ message?: string }>(`/v2/client/services/${id}/security-groups`, data),
  applySecurityGroup: (id: number | string, groupId: number | string) =>
    postEnvelope<{ message?: string }>(`/v2/client/services/${id}/security-groups/${groupId}/apply`),
  deleteSecurityGroup: (id: number | string, groupId: number | string) =>
    request.delete<ApiEnvelope<{ message?: string }>, ApiEnvelope<{ message?: string }>>(
      `/v2/client/services/${id}/security-groups/${groupId}`,
    ),
  createSecurityRule: (id: number | string, groupId: number | string, data: Record<string, unknown>) =>
    postEnvelope<{ message?: string }>(`/v2/client/services/${id}/security-groups/${groupId}/rules`, data),
  deleteSecurityRule: (id: number | string, groupId: number | string, ruleId: number | string) =>
    request.delete<ApiEnvelope<{ message?: string }>, ApiEnvelope<{ message?: string }>>(
      `/v2/client/services/${id}/security-groups/${groupId}/rules/${ruleId}`,
    ),
  serviceVnc: (id: number | string, config: Record<string, unknown> = {}) =>
    getEnvelope<ServiceVncPayload>(`/v2/client/services/${id}/vnc`, config),
  vncToken: (token: string) => request.get(`/v2/client/vnc-tokens/${token}`),

  balanceLogs: (params?: QueryParams) => getEnvelope<PagedList<BalanceLog>>('/v2/client/balance-logs', { params }),
  balanceLogsSummary: (params?: QueryParams) => request.get('/v2/client/balance-logs/summary', { params }),
  financeLedger: (params?: QueryParams) => v2FinanceLedger(params),
  financeLedgerSummary: (params?: QueryParams) => v2FinanceLedgerSummary(params),
  financeLedgerDetail: (id: number | string) => request.get(`/v2/client/finance/ledger/${id}`),
  coupons: (params?: QueryParams) => getEnvelope<PagedList<CouponRecord>>('/v2/client/coupons', { params }),
  couponsSummary: (params?: QueryParams) => getEnvelope<CouponSummary>('/v2/client/coupons/summary', { params }),
  publicCoupons: (params?: QueryParams) =>
    getEnvelope<PagedList<CouponRecord>>('/v2/client/coupons/public', { params }),
  publicCouponsSummary: (params?: QueryParams) =>
    getEnvelope<CouponSummary>('/v2/client/coupons/public/summary', { params }),
  claimCoupon: (couponId: number | string) => postEnvelope<CouponRecord>(`/v2/client/coupons/${couponId}/claim`),

  rechargeGateways: () => getEnvelope<RechargeGatewayOptionsPayload>('/v2/client/recharge/gateways'),
  recharge: (data: Record<string, unknown>) => postEnvelope<RechargeOrderPayload>('/v2/client/recharge', data),
  rechargeStatus: (paymentNo: string, params?: QueryParams) =>
    getEnvelope<RechargeStatusPayload>(`/v2/client/recharge/${paymentNo}/status`, { params }),

  invoices: (params?: ClientFinanceListParams) =>
    getEnvelope<PagedList<InvoiceRecord>>('/v2/client/invoices', { params }),
  invoicesSummary: (params?: ClientFinanceListParams) =>
    getEnvelope<InvoiceListSummary>('/v2/client/invoices/summary', { params }),
  createInvoice: (data: Record<string, unknown>, config?: Record<string, unknown>) =>
    postEnvelope<InvoiceCreatePayload>('/v2/client/invoices', data, config),
  invoiceDetail: (id: number | string) => v2InvoiceDetail(id),
  cancelInvoice: (id: number | string) =>
    postEnvelope<Record<string, unknown>>(`/v2/client/invoices/${id}/cancellations`),
  payInvoiceByBalance: (id: number | string, data: Record<string, unknown>) =>
    postEnvelope<InvoiceBalancePaymentResult>(`/v2/client/invoices/${id}/pay/balance`, data),
  payInvoiceByBalanceAndAlipay: (id: number | string, data: Record<string, unknown>) =>
    postEnvelope<InvoiceAlipayPaymentPayload>(`/v2/client/invoices/${id}/pay/mix`, data),
  payInvoiceByAlipay: (id: number | string, data: Record<string, unknown>) =>
    postEnvelope<InvoiceAlipayPaymentPayload>(`/v2/client/invoices/${id}/pay/alipay`, data),
  queryInvoiceAlipayStatus: (id: number | string, params?: QueryParams) =>
    getEnvelope<InvoiceAlipayStatusPayload>(`/v2/client/invoices/${id}/pay/alipay/status`, { params }),

  payments: (params?: ClientFinanceListParams) =>
    getEnvelope<PagedList<PaymentRecord>>('/v2/client/payments', { params }),
  paymentsSummary: (params?: ClientFinanceListParams) => request.get('/v2/client/payments/summary', { params }),
  paymentDetail: (id: number | string) => getEnvelope<PaymentRecord>(`/v2/client/payments/${id}`),

  orders: (params?: ClientFinanceListParams) => getEnvelope<PagedList<OrderRecord>>('/v2/client/orders', { params }),
  orderSummary: (params?: ClientFinanceListParams) =>
    getEnvelope<OrderListSummary>('/v2/client/orders/summary', { params }),
  orderDetail: (id: number | string) => getEnvelope<OrderRecord>(`/v2/client/orders/${id}`),
  cancelOrder: (id: number | string) => postEnvelope<Record<string, unknown>>(`/v2/client/orders/${id}/cancellations`),

  referralOverview: () => getEnvelope<ReferralOverviewPayload>('/v2/client/referral/overview'),
  referralRewards: (params?: QueryParams) =>
    getEnvelope<PagedList<ReferralRewardRecord>>('/v2/client/referral/rewards', { params }),
  referralAccountLogs: (params?: QueryParams) =>
    getEnvelope<PagedList<ReferralAccountLogRecord>>('/v2/client/referral/account-logs', { params }),
  referralWithdrawals: (params?: QueryParams) =>
    getEnvelope<PagedList<ReferralWithdrawalRecord>>('/v2/client/referral/withdrawals', { params }),
  referralDirectReferrals: (params?: QueryParams) =>
    getEnvelope<PagedList<ReferralUserBrief>>('/v2/client/referral/direct-referrals', { params }),
  applyWithdrawal: (data: Record<string, unknown>) =>
    postEnvelope<ReferralWithdrawalApplyResult>('/v2/client/referral/withdrawals', data),

  tickets: (params?: QueryParams) => getEnvelope<PagedList<TicketRecord>>('/v2/client/tickets', { params }),
  ticketServiceOptions: (params?: QueryParams) =>
    getEnvelope<TicketServiceOption[]>('/v2/client/tickets/service-options', { params }),
  ticketDetail: (id: number | string) => v2TicketDetail(id),
  createTicket: (data: Record<string, unknown>) => request.post('/v2/client/tickets', data),
  replyTicket: (id: number | string, data: Record<string, unknown>) =>
    request.post(`/v2/client/tickets/${id}/replies`, data),
  recallTicketReply: (id: number | string, replyId: number | string) =>
    postEnvelope<Record<string, unknown>>(`/v2/client/tickets/${id}/replies/${replyId}/recalls`),
  closeTicket: (id: number | string) => request.post(`/v2/client/tickets/${id}/closures`),
  uploadTicketImage: (data: FormData) =>
    postEnvelope<TicketImageUploadPayload>('/v2/client/tickets/upload-images', data, {
      headers: { 'Content-Type': 'multipart/form-data' },
    }),

  contentOverview: () =>
    getEnvelope<Record<string, unknown>>('/v2/client/content/overview').then((response) =>
      withNormalizedData<ContentOverviewPayload>(response, normalizeContentOverviewPayload),
    ),
  notices: (params?: QueryParams) =>
    getEnvelope<Record<string, unknown>>('/v2/client/notices', { params }).then((response) =>
      withNormalizedData<ContentListPayload>(response, normalizeContentListPayload),
    ),
  noticeDetail: (id: number | string) =>
    getEnvelope<Record<string, unknown>>(`/v2/client/notices/${id}`).then((response) =>
      withNormalizedData<ContentDetailPayload>(response, normalizeContentDetailPayload),
    ),
  noticeUnreadCount: () => getEnvelope<ContentUnreadCountPayload>('/v2/client/notices/unread-count'),
  markNoticeRead: (id: number | string) => putEnvelope<Record<string, unknown>>(`/v2/client/notices/${id}/read-state`),
  markAllNoticesRead: () => request.post('/v2/client/notices/mark-all-read'),

  // 站内信（铃铛：公告 + 个性化通知聚合）
  notificationUnreadCount: () => request.get('/v2/client/notifications/unread-count'),
  notificationFeed: (limit = 10) => request.get('/v2/client/notifications/feed', { params: { limit } }),
  notificationList: (params?: QueryParams) => request.get('/v2/client/notifications', { params }),
  markNotificationRead: (id: number | string) =>
    putEnvelope<Record<string, unknown>>(`/v2/client/notifications/${id}/read-state`),
  markAllNotificationsRead: () => request.post('/v2/client/notifications/mark-all-read'),
  helpArticles: (params?: QueryParams) =>
    getEnvelope<Record<string, unknown>>('/v2/client/help-articles', { params }).then((response) =>
      withNormalizedData<ContentListPayload>(response, normalizeContentListPayload),
    ),
  helpDetail: (id: number | string) =>
    getEnvelope<Record<string, unknown>>(`/v2/client/help-articles/${id}`).then((response) =>
      withNormalizedData<ContentDetailPayload>(response, normalizeContentDetailPayload),
    ),

  apiKeys: () => getEnvelope<{ list: ApiKeyRecord[] }>('/v2/client/api-keys'),
  createApiKey: (data: Record<string, unknown>) => postEnvelope<ApiKeyStorePayload>('/v2/client/api-keys', data),
  updateApiKey: (id: number | string, data: Record<string, unknown>) =>
    putEnvelope<{ key: ApiKeyRecord }>(`/v2/client/api-keys/${id}`, data),
  setApiKeyStatus: (id: number | string, status: 'enabled' | 'disabled') =>
    putEnvelope<{ key: ApiKeyRecord }>(`/v2/client/api-keys/${id}/status`, { status }),
  deleteApiKey: (id: number | string) => request.delete(`/v2/client/api-keys/${id}`),
  apiKeyUsageLogs: (id: number | string) =>
    getEnvelope<{ list: ApiKeyUsageLogRecord[] }>(`/v2/client/api-keys/${id}/usage-logs`),
};

export default clientApi;
