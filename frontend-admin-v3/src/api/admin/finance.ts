import { request } from '@/utils/request';

import type {
  InvoiceDetailResponse,
  InvoiceListParams,
  InvoiceRecord,
  NewCustomerDailySummary,
  NewCustomerSummaryParams,
  OrderListParams,
  OrderRecord,
  RechargeListParams,
  RechargeRecord,
} from './types';

interface V2AdminOrderDetailPayload {
  order?: Record<string, unknown> | null;
}

interface V2AdminInvoiceDetailPayload {
  invoice?: Record<string, unknown> | null;
}

function normalizeV2AdminInvoiceDetail(payload: V2AdminInvoiceDetailPayload): InvoiceDetailResponse {
  const invoice = toRecord(payload.invoice);
  const basic = toRecord(invoice.basic);
  const display = toRecord(invoice.display);
  const financial = toRecord(invoice.financial);
  const order = toRecord(invoice.order);
  const product = toRecord(invoice.product);
  const configuration = toRecord(invoice.configuration);
  const paymentChain = toRecord(invoice.payment_chain);
  const audit = toRecord(invoice.audit);
  const actions = toRecord(invoice.actions);
  const timestamps = toRecord(invoice.timestamps);

  return {
    invoice: {
      id: invoice.id,
      invoice_no: basic.invoice_no,
      user_id: toRecord(invoice.user).id,
      product_spec_snapshot: display.product_spec_snapshot,
      product_spec_display: display.product_spec_display,
      product_display_name: display.product_display_name,
      combined_display_name: display.combined_display_name,
      user: invoice.user,
      order_id: order.id,
      order: invoice.order,
      product_id: product.id,
      product: invoice.product,
      service: invoice.service,
      type: basic.type,
      type_label: basic.type_label,
      scene: invoice.scene,
      amount: financial.amount,
      discount: financial.discount,
      paid_amount: financial.paid_amount,
      payable_amount: financial.payable_amount,
      status: basic.status,
      status_label: basic.status_label,
      raw_status: basic.raw_status,
      raw_status_label: basic.raw_status_label,
      billing_cycle: basic.billing_cycle,
      quantity: basic.quantity,
      summary: display.summary,
      due_date: basic.due_date,
      paid_at: financial.paid_at,
      created_at: timestamps.created_at,
      updated_at: timestamps.updated_at,
      trace_id: audit.trace_id,
      refund_trace_id: audit.refund_trace_id,
      config_snapshot: configuration.config_snapshot,
      config_pricing_snapshot: configuration.config_pricing_snapshot,
      coupon_snapshot: configuration.coupon_snapshot,
      payment_summary: paymentChain.payment_summary,
      can_cancel: actions.can_cancel,
    } as InvoiceRecord,
    payments: Array.isArray(paymentChain.payments) ? (paymentChain.payments as Record<string, unknown>[]) : [],
    items: Array.isArray(invoice.items) ? (invoice.items as Record<string, unknown>[]) : [],
    logs: Array.isArray(invoice.logs) ? (invoice.logs as Record<string, unknown>[]) : [],
  };
}

function normalizeV2AdminOrderDetail(payload: V2AdminOrderDetailPayload): OrderRecord {
  const order = toRecord(payload.order);
  const basic = toRecord(order.basic);
  const financial = toRecord(order.financial);
  const invoice = toRecord(order.invoice);
  const product = toRecord(order.product);
  const coupon = toRecord(order.coupon);
  const configuration = toRecord(order.configuration);
  const paymentChain = toRecord(order.payment_chain);
  const audit = toRecord(order.audit);
  const timestamps = toRecord(order.timestamps);

  return {
    id: order.id as OrderRecord['id'],
    order_no: basic.order_no as string | undefined,
    type: basic.type as string | undefined,
    type_label: basic.type_label as string | undefined,
    status: basic.status as number | string | undefined,
    status_label: basic.status_label as string | undefined,
    billing_cycle: basic.billing_cycle as string | undefined,
    quantity: basic.quantity as number | string | undefined,
    remark: basic.remark,
    amount: financial.amount as number | string | undefined,
    discount: financial.discount as number | string | undefined,
    paid_amount: financial.paid_amount as number | string | undefined,
    paid_at: financial.paid_at as string | undefined,
    user_id: toRecord(order.user).id as number | string | undefined,
    user: order.user as Record<string, unknown> | undefined,
    invoice_id: invoice.id,
    invoice: order.invoice as Record<string, unknown> | null,
    product_id: product.id,
    product_name: product.name as string | undefined,
    product_full_path: product.full_path as string | undefined,
    product_type: product.type as string | undefined,
    service: order.service as Record<string, unknown> | null,
    coupon: order.coupon,
    coupon_code: coupon.code,
    config_snapshot: configuration.config_snapshot,
    config_pricing_snapshot: configuration.config_pricing_snapshot,
    payments: Array.isArray(paymentChain.payments) ? paymentChain.payments : [],
    trace_id: audit.trace_id,
    created_at: timestamps.created_at as string | undefined,
    updated_at: timestamps.updated_at as string | undefined,
  };
}

function toRecord(value: unknown): Record<string, unknown> {
  return value && typeof value === 'object' ? (value as Record<string, unknown>) : {};
}

export const invoiceApi = {
  list: (params: InvoiceListParams) =>
    request.get<{ list?: InvoiceRecord[]; total?: number; page?: number; page_size?: number }>({
      url: '/v2/admin/invoices',
      params,
    }),
  detail: async (id: number | string) =>
    normalizeV2AdminInvoiceDetail(await request.get<V2AdminInvoiceDetailPayload>({ url: `/v2/admin/invoices/${id}` })),
  cancel: (id: number | string) => request.post({ url: `/v2/admin/invoices/${id}/cancellations` }),
};

export const orderApi = {
  list: (params: OrderListParams) =>
    request.get<{ list?: OrderRecord[]; total?: number; page?: number; page_size?: number }>({
      url: '/v2/admin/orders',
      params,
    }),
  detail: async (id: number | string) =>
    normalizeV2AdminOrderDetail(await request.get<V2AdminOrderDetailPayload>({ url: `/v2/admin/orders/${id}` })),
};

export const financeMenuApi = {
  recharges: (params: RechargeListParams) =>
    request.get<{ list?: RechargeRecord[]; total?: number; page?: number; page_size?: number }>({
      url: '/v2/admin/finance/recharges',
      params,
    }),
  newCustomerDailySummary: (params: NewCustomerSummaryParams) =>
    request.get<NewCustomerDailySummary>({
      url: '/v2/admin/finance/new-customer-daily-summary',
      params,
    }),
  renewalOrders: (params: OrderListParams) =>
    request.get<{ list?: OrderRecord[]; total?: number; page?: number; page_size?: number }>({
      url: '/v2/admin/finance/renewal-orders',
      params,
    }),
  upgradeOrders: (params: OrderListParams) =>
    request.get<{ list?: OrderRecord[]; total?: number; page?: number; page_size?: number }>({
      url: '/v2/admin/finance/upgrade-orders',
      params,
    }),
};
