import { MessagePlugin } from 'tdesign-vue-next';
import { computed, onMounted, reactive, ref, watch } from 'vue';

import clientApi from '@/api/client';
import type { ApiEnvelope, CouponRecord, PagedList } from '@/types/client';

type CouponTab = 'owned' | 'plaza';
type CouponTabChangeValue = CouponTab | { value?: CouponTab | string } | null | undefined;

function getErrorMessage(error: unknown, fallback: string) {
  if (error instanceof Error && error.message) return error.message;
  if (typeof error === 'object' && error !== null && 'message' in error && typeof error.message === 'string') {
    return error.message;
  }
  return fallback;
}

function createTabState() {
  return reactive({
    loading: false,
    list: [] as CouponRecord[],
    total: 0,
    page: 1,
    pageSize: 10,
    keyword: '',
    status: '',
  });
}

export function formatCouponAmount(value: unknown) {
  const amount = Number(value || 0);
  if (!Number.isFinite(amount) || amount <= 0) return '0';
  return amount % 1 === 0 ? String(amount) : amount.toFixed(2);
}

export function resolveStatusTheme(statusOrItem: unknown, statusLabel?: unknown) {
  const item = typeof statusOrItem === 'object' && statusOrItem !== null ? (statusOrItem as CouponRecord) : null;
  const status = item?.status ?? statusOrItem;
  const label = item?.status_label ?? statusLabel;

  if (status === 'revoked' || item?.revoked_at) return 'danger';
  if (item?.used_at || label === '已使用') return 'default';
  if (status === 'available') return 'success';
  if (status === 'used_up') return 'warning';
  return 'default';
}

export function resolveDiscountTypeLabel(type: unknown) {
  if (type === 'fixed') return '满减券';
  if (type === 'percentage') return '折扣券';
  return '优惠券';
}

export function resolveDiscountValue(item: CouponRecord) {
  if (item.discount_type === 'fixed') return `￥${formatCouponAmount(item.discount_value)}`;
  if (item.discount_type === 'percentage') {
    const discount = Number(item.discount_value || 0) / 10;
    if (!Number.isFinite(discount) || discount <= 0) return item.discount_label || '--';
    return `${discount % 1 === 0 ? discount.toFixed(0) : discount.toFixed(1)} 折`;
  }
  return item.discount_label || '--';
}

export function resolveThresholdText(item: CouponRecord) {
  const amount = Number(item.min_amount || 0);
  return amount > 0 ? `满 ￥${formatCouponAmount(amount)} 可用` : '无门槛';
}

export function resolveDiscountAmountText(item: CouponRecord) {
  if (item.discount_type === 'fixed') return `减 ￥${formatCouponAmount(item.discount_value)}`;
  if (item.discount_type === 'percentage') {
    return item.max_discount_amount
      ? `最高减 ￥${formatCouponAmount(item.max_discount_amount)}`
      : item.discount_label || '--';
  }
  return item.discount_amount ? `减 ￥${formatCouponAmount(item.discount_amount)}` : item.discount_label || '--';
}

function resolveListPayload(response: ApiEnvelope<PagedList<CouponRecord>>) {
  const payload = response.data || { list: [], total: 0 };
  return {
    list: Array.isArray(payload.list) ? payload.list : [],
    total: Number(payload.total || 0),
  };
}

export function useCoupons() {
  const activeTab = ref<CouponTab>('owned');
  const claimingId = ref(0);
  const detailVisible = ref(false);
  const selectedCoupon = ref<CouponRecord | null>(null);
  const ownedState = createTabState();
  const plazaState = createTabState();

  // 默认筛选"可用"优惠券
  ownedState.status = 'available';

  const currentState = computed(() => (activeTab.value === 'plaza' ? plazaState : ownedState));
  const currentStatusOptions = computed(() =>
    activeTab.value === 'plaza'
      ? [
          { label: '可领取', value: 'available' },
          { label: '已领完', value: 'used_up' },
          { label: '已过期', value: 'expired' },
        ]
      : [
          { label: '可用', value: 'available' },
          { label: '已用完', value: 'used_up' },
          { label: '已过期', value: 'expired' },
        ],
  );

  function getState(tab: CouponTab = activeTab.value) {
    return tab === 'plaza' ? plazaState : ownedState;
  }

  async function loadList(tab: CouponTab = activeTab.value) {
    const state = getState(tab);
    const requestMethod = tab === 'plaza' ? clientApi.publicCoupons : clientApi.coupons;
    state.loading = true;
    try {
      const response = await requestMethod({
        page: state.page,
        page_size: state.pageSize,
        keyword: state.keyword || undefined,
        status: state.status || undefined,
      });
      const payload = resolveListPayload(response);
      state.list = payload.list;
      state.total = payload.total;
    } catch (error: unknown) {
      MessagePlugin.error(getErrorMessage(error, tab === 'plaza' ? '优惠券广场加载失败' : '优惠券列表加载失败'));
    } finally {
      state.loading = false;
    }
  }

  function handleSearch(tab: CouponTab = activeTab.value) {
    getState(tab).page = 1;
    void loadList(tab);
  }

  function handlePageChange(tab: CouponTab = activeTab.value) {
    void loadList(tab);
  }

  function handlePageSizeChange(tab: CouponTab = activeTab.value) {
    getState(tab).page = 1;
    void loadList(tab);
  }

  async function switchTab(tabValue: CouponTabChangeValue) {
    const candidate =
      typeof tabValue === 'object' && tabValue !== null && 'value' in tabValue ? tabValue.value : tabValue;
    const tab = String(candidate || activeTab.value || 'owned') === 'plaza' ? 'plaza' : 'owned';
    activeTab.value = tab;
    const state = getState(tab);
    if (!state.list.length && !state.loading) await loadList(tab);
  }

  function openCouponDetail(item: CouponRecord) {
    selectedCoupon.value = item;
    detailVisible.value = true;
  }

  async function claimCoupon(couponId: unknown) {
    const id = Number(couponId || 0);
    if (id <= 0 || claimingId.value) return;
    claimingId.value = id;
    try {
      await clientApi.claimCoupon(id);
      MessagePlugin.success('领取成功');
      await Promise.all([loadList('owned'), loadList('plaza')]);
    } catch (error: unknown) {
      MessagePlugin.error(getErrorMessage(error, '优惠券领取失败'));
    } finally {
      claimingId.value = 0;
    }
  }

  onMounted(() => {
    void loadList('owned');
  });

  watch(activeTab, (tab) => {
    void switchTab(tab);
  });

  return {
    activeTab,
    claimingId,
    detailVisible,
    selectedCoupon,
    ownedState,
    plazaState,
    currentState,
    currentStatusOptions,
    loadList,
    handleSearch,
    handlePageChange,
    handlePageSizeChange,
    switchTab,
    openCouponDetail,
    claimCoupon,
  };
}
