import { ORDER_STATUS_MAP, ORDER_TYPE_MAP, toSelectOptions } from '@turaidc/shared/statusConfig';
import { DialogPlugin, MessagePlugin } from 'tdesign-vue-next';
import { onMounted, reactive, ref, shallowRef } from 'vue';

import clientApi from '@/api/client';
import type { ClientFinanceListParams, OrderListSummary, OrderRecord } from '@/types/client';
import { formatMoney } from '@/utils/format';

import { resolveQuickDateRange } from './dateFilters';

export const ORDER_STATUS_OPTIONS = toSelectOptions(ORDER_STATUS_MAP, false);

export const ORDER_TYPE_OPTIONS = ['new', 'renew', 'upgrade'].map((value) => ({
  label: ORDER_TYPE_MAP[value] || value,
  value,
}));

function getErrorMessage(error: unknown, fallback: string) {
  return error instanceof Error && error.message ? error.message : fallback;
}

export { formatMoney };

export function orderProductDisplay(row: Pick<OrderRecord, 'product_full_path' | 'product_name'> | null | undefined) {
  return String(row?.product_full_path || row?.product_name || '').trim() || '--';
}

export function useOrderList(options: { pageSize?: number } = {}) {
  const loading = ref(false);
  const summaryLoading = ref(false);
  const canceling = ref(false);
  const list = shallowRef<OrderRecord[]>([]);
  const listError = ref('');
  const total = ref(0);
  const summary = shallowRef<OrderListSummary>({});
  const filters = reactive({
    page: 1,
    page_size: Number(options.pageSize || 10),
    keyword: '',
    status: '' as string | number,
    type: '',
    start_date: '',
    end_date: '',
    quickFilter: '' as string,
  });

  function buildParams() {
    const params: ClientFinanceListParams = {
      page: filters.page,
      page_size: filters.page_size,
    };
    if (filters.keyword?.trim()) params.keyword = filters.keyword.trim();
    if (filters.status !== '' && filters.status !== null && filters.status !== undefined)
      params.status = filters.status;
    if (filters.type?.trim()) params.type = filters.type.trim();
    if (filters.start_date) params.start_date = filters.start_date;
    if (filters.end_date) params.end_date = filters.end_date;
    return params;
  }

  async function loadSummary() {
    summaryLoading.value = true;
    try {
      const res = await clientApi.orderSummary();
      summary.value = res.data || {};
    } catch {
      summary.value = {};
    } finally {
      summaryLoading.value = false;
    }
  }

  async function loadList() {
    loading.value = true;
    try {
      listError.value = '';
      const res = await clientApi.orders(buildParams());
      const payload = res.data;
      list.value = payload && !Array.isArray(payload) && Array.isArray(payload.list) ? payload.list : [];
      total.value = payload && !Array.isArray(payload) ? Number(payload.total || 0) : 0;
    } catch (error: unknown) {
      list.value = [];
      total.value = 0;
      listError.value = getErrorMessage(error, '订单列表加载失败');
      MessagePlugin.error(listError.value);
    } finally {
      loading.value = false;
    }
  }

  async function loadData() {
    await Promise.allSettled([loadSummary(), loadList()]);
  }

  function handleSearch() {
    filters.page = 1;
    void loadData();
  }

  function handlePageSizeChange() {
    filters.page = 1;
    void loadList();
  }

  function applyQuickFilter(key: string) {
    filters.quickFilter = key;
    filters.page = 1;
    filters.status = '';
    filters.type = '';
    filters.start_date = '';
    filters.end_date = '';

    if (key === 'pending') {
      filters.status = 0;
    }
    const range = resolveQuickDateRange(key);
    filters.start_date = range.start_date || '';
    filters.end_date = range.end_date || '';
    void loadData();
  }

  function cancelOrder(row: OrderRecord) {
    const dialog = DialogPlugin.confirm({
      header: '取消订单',
      body: '确定取消该订单？取消后不可恢复。\n关联的账单也将被取消，使用的优惠券将退回账户。\n如果是新产品购买，库存将释放。',
      confirmBtn: '确认取消',
      cancelBtn: '再想想',
      theme: 'warning',
      async onConfirm() {
        dialog.setConfirmLoading(true);
        canceling.value = true;
        try {
          await clientApi.cancelOrder(row.id);
          MessagePlugin.success('订单已取消');
          await loadData();
          dialog.hide();
        } catch (error: unknown) {
          MessagePlugin.error(getErrorMessage(error, '取消订单失败'));
        } finally {
          canceling.value = false;
          dialog.setConfirmLoading(false);
        }
      },
    });
  }

  onMounted(() => {
    void loadData();
  });

  return {
    loading,
    summaryLoading,
    canceling,
    list,
    listError,
    total,
    summary,
    filters,
    loadList,
    loadData,
    loadSummary,
    handleSearch,
    handlePageSizeChange,
    applyQuickFilter,
    cancelOrder,
  };
}

export function useOrderDetail() {
  const loading = ref(false);
  const canceling = ref(false);
  const detail = shallowRef<OrderRecord | null>(null);

  // 并发守卫：快速切换订单详情时丢弃晚返回的旧请求结果。
  let requestSeq = 0;

  async function loadDetail(id: number | string) {
    if (!id) return;
    const seq = ++requestSeq;
    loading.value = true;
    try {
      const res = await clientApi.orderDetail(id);
      if (seq !== requestSeq) {
        return;
      }
      detail.value = res.data || null;
    } catch (error: unknown) {
      if (seq !== requestSeq) {
        return;
      }
      MessagePlugin.error(getErrorMessage(error, '订单详情加载失败'));
    } finally {
      if (seq === requestSeq) {
        loading.value = false;
      }
    }
  }

  function cancelOrder(onSuccess?: () => void) {
    if (!detail.value) return;
    const row = detail.value;
    const dialog = DialogPlugin.confirm({
      header: '取消订单',
      body: '确定取消该订单？取消后不可恢复。\n关联的账单也将被取消，使用的优惠券将退回账户。\n如果是新产品购买，库存将释放。',
      confirmBtn: '确认取消',
      cancelBtn: '再想想',
      theme: 'warning',
      async onConfirm() {
        dialog.setConfirmLoading(true);
        canceling.value = true;
        try {
          await clientApi.cancelOrder(row.id);
          MessagePlugin.success('订单已取消');
          await loadDetail(row.id);
          onSuccess?.();
          dialog.hide();
        } catch (error: unknown) {
          MessagePlugin.error(getErrorMessage(error, '取消订单失败'));
        } finally {
          canceling.value = false;
          dialog.setConfirmLoading(false);
        }
      },
    });
  }

  return {
    loading,
    canceling,
    detail,
    loadDetail,
    cancelOrder,
  };
}
