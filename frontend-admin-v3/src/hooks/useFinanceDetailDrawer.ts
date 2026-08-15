import { MessagePlugin } from 'tdesign-vue-next';
import { reactive } from 'vue';

import type { InvoiceRecord } from '@/api/admin';
import { errorMessage } from '@/utils/userMessage';

export interface InvoiceDetailState {
  visible: boolean;
  loading: boolean;
  cancelLoading: boolean;
  currentId: number;
  detail: {
    invoice: InvoiceRecord;
    payments: Record<string, unknown>[];
    items: Record<string, unknown>[];
    logs: Record<string, unknown>[];
  };
}

interface UseFinanceDetailDrawerOptions {
  /** 加载详情的 API 函数 */
  fetchDetail: (id: number) => Promise<Record<string, unknown>>;
  /** 详情加载失败的回退文案 */
  errorFallback?: string;
}

export function useFinanceDetailDrawer(options: UseFinanceDetailDrawerOptions) {
  const { fetchDetail, errorFallback = '加载详情失败' } = options;

  const detailState = reactive<InvoiceDetailState>({
    visible: false,
    loading: false,
    cancelLoading: false,
    currentId: 0,
    detail: {
      invoice: {} as InvoiceRecord,
      payments: [],
      items: [],
      logs: [],
    },
  });

  function normalizeInvoiceDetail(
    payload: Record<string, unknown> = {},
    fallback: InvoiceRecord = {} as InvoiceRecord,
  ) {
    const invoice =
      payload.invoice && typeof payload.invoice === 'object'
        ? (payload.invoice as InvoiceRecord)
        : (payload as InvoiceRecord);
    return {
      invoice: {
        ...fallback,
        ...invoice,
        payment_summary: { ...(fallback.payment_summary || {}), ...(invoice.payment_summary || {}) },
        order: invoice.order || fallback.order || null,
        product: invoice.product || fallback.product || null,
        scene: invoice.scene || fallback.scene || {},
      },
      payments: Array.isArray(payload.payments) ? (payload.payments as Record<string, unknown>[]) : [],
      items: Array.isArray(payload.items) ? (payload.items as Record<string, unknown>[]) : [],
      logs: Array.isArray(payload.logs) ? (payload.logs as Record<string, unknown>[]) : [],
    };
  }

  async function openDetail(row: InvoiceRecord) {
    if (!row.id) return;
    detailState.visible = true;
    detailState.currentId = Number(row.id);
    detailState.detail = { invoice: row, payments: [], items: [], logs: [] };
    await reloadDetail();
  }

  async function reloadDetail() {
    if (!detailState.currentId) return;
    detailState.loading = true;
    try {
      const response = await fetchDetail(detailState.currentId);
      detailState.detail = normalizeInvoiceDetail(response, detailState.detail.invoice);
    } catch (error) {
      MessagePlugin.error(errorMessage(error, errorFallback));
    } finally {
      detailState.loading = false;
    }
  }

  function closeDetail() {
    detailState.visible = false;
    detailState.currentId = 0;
    detailState.cancelLoading = false;
    detailState.detail = { invoice: {} as InvoiceRecord, payments: [], items: [], logs: [] };
  }

  return { detailState, openDetail, reloadDetail, closeDetail, normalizeInvoiceDetail };
}
