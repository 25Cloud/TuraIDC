import { getStatusLabel, INVOICE_STATUS_MAP, INVOICE_TYPE_MAP, toSelectOptions } from '@shared/statusConfig';
import { DialogPlugin, MessagePlugin } from 'tdesign-vue-next';
import { computed, onBeforeUnmount, onMounted, reactive, ref, shallowRef, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';

import clientApi from '@/api/client';
import { useUserStore } from '@/store';
import type {
  ClientFinanceListParams,
  InvoiceAlipayPaymentPayload,
  InvoiceListSummary,
  InvoicePaymentMethod,
  InvoicePaymentSecurity,
  InvoiceRecord,
} from '@/types/client';
import { formatMoney } from '@/utils/format';

import { resolveQuickDateRange } from './dateFilters';

export type PayMethodKey = string;

export const INVOICE_STATUS_OPTIONS = toSelectOptions(INVOICE_STATUS_MAP, false);

export const INVOICE_TYPE_OPTIONS = [
  'new',
  'renew',
  'upgrade',
  'recharge',
  'deduction',
  'referral_credit',
  'manual',
].map((value) => ({
  label: INVOICE_TYPE_MAP[value] || value,
  value,
}));

function normalizeText(value: unknown) {
  if (typeof value === 'string') return value.trim();
  if (typeof value === 'number') return String(value);
  return '';
}

function getErrorMessage(error: unknown, fallback: string) {
  return error instanceof Error && error.message ? error.message : fallback;
}

function pickText(...values: unknown[]) {
  for (const value of values) {
    const text = normalizeText(value);
    if (text) return text;
  }

  return '--';
}

function resolveSummaryField(
  row: InvoiceRecord | null | undefined,
  field: keyof NonNullable<InvoiceRecord['summary']> | string,
) {
  const summary = row?.summary;
  if (!summary || typeof summary !== 'object' || Array.isArray(summary)) {
    return '';
  }

  return normalizeText(summary[field]);
}

function hasProductBinding(row: InvoiceRecord | null | undefined) {
  return Number(row?.product?.id || row?.product_id || 0) > 0;
}

function normalizeTypeFilter(value: unknown) {
  const rawTypes = Array.isArray(value) ? value : String(value || '').split(',');
  return rawTypes
    .map((item) => normalizeText(item))
    .filter(Boolean)
    .join(',');
}

export { formatMoney };

export function normalizeMoney(value: unknown) {
  const amount = Number(value || 0);
  if (!Number.isFinite(amount)) return 0;
  return Math.max(0, Math.round(amount * 100) / 100);
}

export function resolveInvoiceTitle(row: InvoiceRecord | null | undefined) {
  if (hasProductBinding(row)) {
    return pickText(
      row?.product_spec_display,
      row?.combined_display_name,
      row?.product_display_name,
      resolveSummaryField(row, 'headline'),
      row?.type_label,
    );
  }

  return pickText(
    row?.combined_display_name,
    row?.product_display_name,
    resolveSummaryField(row, 'headline'),
    row?.type_label,
  );
}

export function resolveInvoiceSubtitle(row: InvoiceRecord | null | undefined) {
  const title = resolveInvoiceTitle(row);
  const combinedDisplayName = normalizeText(row?.combined_display_name);
  const productDisplayName = normalizeText(row?.product_display_name);

  if (hasProductBinding(row)) {
    return pickText(
      combinedDisplayName !== title ? combinedDisplayName : '',
      productDisplayName !== title ? productDisplayName : '',
      resolveSummaryField(row, 'subheadline'),
      resolveSummaryField(row, 'remark'),
      row?.type_label,
    );
  }

  return pickText(
    row?.product_spec_display,
    resolveSummaryField(row, 'subheadline'),
    resolveSummaryField(row, 'remark'),
    row?.type_label,
  );
}

export function resolveInvoiceNo(row: InvoiceRecord | null | undefined) {
  return normalizeText(row?.invoice_no) || `#${row?.id || 0}`;
}

export function resolveInvoiceStatusLabel(rowOrStatus: InvoiceRecord | number | string | null | undefined) {
  if (rowOrStatus && typeof rowOrStatus === 'object') {
    return getStatusLabel(INVOICE_STATUS_MAP, Number(rowOrStatus.status));
  }

  return getStatusLabel(INVOICE_STATUS_MAP, Number(rowOrStatus));
}

export function isPayableInvoice(row: InvoiceRecord | null | undefined) {
  const status = Number(row?.status);
  return status === 0 || status === 3;
}

function coercePayMethodKey(value: unknown): PayMethodKey {
  return String(value || '').trim();
}

function payMethodOptionKey(method: InvoicePaymentMethod | null | undefined) {
  const optionKey = normalizeText(method?.option_key);
  if (optionKey) return optionKey;

  return normalizeText(method?.key);
}

function resolveListPayload(response: unknown) {
  const payload = (
    response as { data?: InvoiceRecord[] | { list?: InvoiceRecord[]; total?: number } } | null | undefined
  )?.data;
  return {
    list: payload && !Array.isArray(payload) && Array.isArray(payload.list) ? payload.list : [],
    total: payload && !Array.isArray(payload) ? Number(payload.total || 0) : 0,
  };
}

export function useInvoiceList(options: { fixedTypes?: unknown; pageSize?: number } = {}) {
  const router = useRouter();
  const route = useRoute();
  const loading = ref(false);
  const summaryLoading = ref(false);
  const canceling = ref(false);
  const list = shallowRef<InvoiceRecord[]>([]);
  const total = ref(0);
  const summary = shallowRef<InvoiceListSummary>({});
  const detailVisible = ref(false);
  const routeDetailId = ref(0);
  const currentRow = shallowRef<InvoiceRecord | null>(null);
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

  const showTypeSelector = computed(() => !normalizeTypeFilter(options.fixedTypes));

  function buildParams() {
    const fixedTypes = normalizeTypeFilter(options.fixedTypes);
    const params: ClientFinanceListParams = {
      page: filters.page,
      page_size: filters.page_size,
    };
    if (normalizeText(filters.keyword)) params.keyword = normalizeText(filters.keyword);
    if (filters.status !== '' && filters.status !== null && filters.status !== undefined)
      params.status = filters.status;
    if (fixedTypes) {
      params.type = fixedTypes;
    } else if (normalizeText(filters.type)) {
      params.type = normalizeText(filters.type);
    }
    if (filters.start_date) params.start_date = filters.start_date;
    if (filters.end_date) params.end_date = filters.end_date;
    return params;
  }

  async function loadSummary() {
    summaryLoading.value = true;
    try {
      const res = await clientApi.invoicesSummary(buildParams());
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
      const res = await clientApi.invoices(buildParams());
      const payload = resolveListPayload(res);
      list.value = payload.list;
      total.value = payload.total;
    } catch (error: unknown) {
      MessagePlugin.error(getErrorMessage(error, '账单列表加载失败'));
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

  function normalizeInvoiceId(value: unknown) {
    const raw = Array.isArray(value) ? value[0] : value;
    const id = Number(raw || 0);
    return Number.isFinite(id) && id > 0 ? id : 0;
  }

  async function openDetailById(invoiceId: number) {
    if (!invoiceId) return;
    routeDetailId.value = invoiceId;
    try {
      const res = await clientApi.invoiceDetail(invoiceId);
      if (routeDetailId.value !== invoiceId) return;
      currentRow.value = res.data || null;
      detailVisible.value = Boolean(currentRow.value);
    } catch (error: unknown) {
      MessagePlugin.error(getErrorMessage(error, '账单详情加载失败'));
    }
  }

  function openDetail(row: InvoiceRecord) {
    currentRow.value = row;
    detailVisible.value = true;
    const invoiceId = normalizeInvoiceId(row?.id);
    if (invoiceId) {
      void router.replace({
        path: '/client/invoices',
        query: { ...route.query, detail: String(invoiceId) },
      });
    }
  }

  function closeDetail() {
    detailVisible.value = false;
    currentRow.value = null;
    routeDetailId.value = 0;
    if (route.query.detail !== undefined) {
      const nextQuery = { ...route.query };
      delete nextQuery.detail;
      void router.replace({ path: '/client/invoices', query: nextQuery });
    }
  }

  function goToPay(row: InvoiceRecord) {
    detailVisible.value = false;
    router.push(`/client/invoices/${row.id}/pay`);
  }

  function cancelInvoice(row: InvoiceRecord) {
    const dialog = DialogPlugin.confirm({
      header: '取消账单',
      body: '确定取消该账单？取消后不可恢复。\n使用的优惠券将退回账户。\n如果是新产品购买，库存将释放。',
      confirmBtn: '确认取消',
      cancelBtn: '再想想',
      theme: 'warning',
      async onConfirm() {
        dialog.setConfirmLoading(true);
        canceling.value = true;
        try {
          await clientApi.cancelInvoice(row.id);
          MessagePlugin.success('账单已取消');
          closeDetail();
          await loadData();
          dialog.hide();
        } catch (error: unknown) {
          MessagePlugin.error(getErrorMessage(error, '取消账单失败'));
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

  watch(
    () => route.query.detail,
    (value) => {
      const invoiceId = normalizeInvoiceId(value);
      if (invoiceId) {
        void openDetailById(invoiceId);
        return;
      }

      if (routeDetailId.value) {
        detailVisible.value = false;
        currentRow.value = null;
        routeDetailId.value = 0;
      }
    },
    { immediate: true },
  );

  return {
    router,
    loading,
    summaryLoading,
    canceling,
    list,
    total,
    summary,
    filters,
    detailVisible,
    currentRow,
    showTypeSelector,
    loadList,
    loadData,
    loadSummary,
    handleSearch,
    handlePageSizeChange,
    applyQuickFilter,
    openDetail,
    closeDetail,
    goToPay,
    cancelInvoice,
  };
}

export function useInvoiceDetail() {
  const route = useRoute();
  const router = useRouter();
  const userStore = useUserStore();

  const loading = ref(false);
  const canceling = ref(false);
  const paying = ref(false);
  const polling = ref(false);
  const detail = shallowRef<InvoiceRecord | null>(null);
  const selectedPayMethod = ref<PayMethodKey>('');
  const allowBalanceDeduction = ref(false);
  const alipayDialogVisible = ref(false);
  const alipayQrCode = ref('');
  const alipayPaymentNo = ref('');
  const alipayPollToken = ref('');
  const alipayGateway = ref('');
  const appliedDeductionAmount = ref('0.00');
  const alipayAmount = ref('0.00');
  const pollTimer = ref<number | null>(null);

  const invoiceId = computed(() => Number(route.params.id || 0));
  const payMethods = computed<InvoicePaymentMethod[]>(() =>
    Array.isArray(detail.value?.pay_methods) ? detail.value?.pay_methods : [],
  );
  const hasPayMethods = computed(() => payMethods.value.length > 0);
  const selectedPayMethodRecord = computed(
    () => payMethods.value.find((item) => payMethodOptionKey(item) === selectedPayMethod.value) || null,
  );
  const selectedGatewayKey = computed(() => normalizeText(selectedPayMethodRecord.value?.key));
  const selectedPaymentType = computed(() => normalizeText(selectedPayMethodRecord.value?.payment_type));
  const selectedPayMethodName = computed(
    () =>
      normalizeText(selectedPayMethodRecord.value?.name) ||
      normalizeText(selectedPayMethodRecord.value?.label) ||
      '支付',
  );
  const paySecurity = computed<InvoicePaymentSecurity>(() => detail.value?.payment_security || {});
  const canPay = computed(() => Boolean(paySecurity.value.can_pay) && isPayableInvoice(detail.value));
  const selectedIsAlipay = computed(
    () => selectedGatewayKey.value === 'alipay' || selectedPaymentType.value === 'alipay',
  );
  const alipayPollingReady = computed(() => Boolean(alipayPaymentNo.value && alipayPollToken.value));
  const balanceAmount = computed(() => normalizeMoney(userStore.info?.cash_balance || 0));
  const payableAmount = computed(() => normalizeMoney(detail.value?.payable_amount || 0));
  const canDeductBalance = computed(() => balanceAmount.value > 0 && payableAmount.value > 0);
  const autoDeductionAmount = computed(() => normalizeMoney(Math.min(balanceAmount.value, payableAmount.value)));
  const estimatedAlipayAmount = computed(() =>
    normalizeMoney(Math.max(payableAmount.value - autoDeductionAmount.value, 0)),
  );
  const balanceText = computed(() => `¥${formatMoney(balanceAmount.value)}`);
  const autoDeductionAmountText = computed(() => formatMoney(autoDeductionAmount.value));
  const estimatedAlipayAmountText = computed(() => formatMoney(estimatedAlipayAmount.value));
  const appliedDeductionAmountText = computed(() => formatMoney(appliedDeductionAmount.value));
  const hasAppliedBalanceDeduction = computed(() => Number(appliedDeductionAmount.value || 0) > 0);
  const alipayPayableAmount = computed(() => alipayAmount.value || formatMoney(payableAmount.value));
  const showBalanceDeductionOption = computed(() => selectedIsAlipay.value && canDeductBalance.value);
  const showPayActions = computed(() => Boolean(detail.value && isPayableInvoice(detail.value)));
  const payTip = computed(() => {
    if (!canPay.value) return '当前账单状态不支持继续支付。';
    if (selectedIsAlipay.value && allowBalanceDeduction.value) {
      return `将自动抵扣余额 ¥${autoDeductionAmountText.value}，支付宝支付剩余 ¥${estimatedAlipayAmountText.value}。`;
    }
    if (selectedGatewayKey.value && !['balance', 'free'].includes(selectedGatewayKey.value)) {
      return `生成二维码后请使用${selectedPayMethodName.value}完成支付，系统会自动轮询状态。`;
    }
    if (selectedPayMethod.value === 'balance') return '余额支付会直接扣减账户余额并完成账单。';
    return '零元账单无需额外支付。';
  });

  function clearPollingTimer() {
    if (pollTimer.value !== null) {
      window.clearInterval(pollTimer.value);
      pollTimer.value = null;
    }
  }

  function resetPaymentPayload() {
    alipayDialogVisible.value = false;
    alipayQrCode.value = '';
    alipayPaymentNo.value = '';
    alipayPollToken.value = '';
    alipayGateway.value = '';
    appliedDeductionAmount.value = '0.00';
    alipayAmount.value = formatMoney(payableAmount.value);
    clearPollingTimer();
  }

  function syncPayMethod() {
    if (!payMethods.value.length) {
      selectedPayMethod.value = '';
      return;
    }

    if (payMethods.value.some((item) => payMethodOptionKey(item) === selectedPayMethod.value)) return;
    selectedPayMethod.value = coercePayMethodKey(payMethodOptionKey(payMethods.value[0]));
  }

  function selectPayMethod(value: unknown) {
    selectedPayMethod.value = coercePayMethodKey(value);
    allowBalanceDeduction.value = false;
    resetPaymentPayload();
  }

  function handleDeductionToggle() {
    resetPaymentPayload();
  }

  async function refreshClientInfo() {
    try {
      await userStore.getUserInfo();
    } catch {
      // 余额信息可在下次进入页面时刷新，不影响当前支付状态展示。
    }
  }

  function applyAlipayPayload(payload: InvoiceAlipayPaymentPayload | null | undefined, usedBalanceDeduction: boolean) {
    const data = payload || {};
    alipayQrCode.value = String(data.qr_code || '');
    alipayPaymentNo.value = String(data.payment_no || '');
    alipayPollToken.value = String(data.poll_token || '');
    alipayGateway.value = String(data.gateway || data.gateway_key || selectedGatewayKey.value || '');
    appliedDeductionAmount.value = usedBalanceDeduction
      ? String(data.balance_amount || autoDeductionAmountText.value)
      : '0.00';
    alipayAmount.value = String(
      data.amount || estimatedAlipayAmountText.value || detail.value?.payable_amount || '0.00',
    );
    alipayDialogVisible.value = Boolean(alipayQrCode.value);
  }

  async function loadDetail() {
    if (!invoiceId.value) return;
    loading.value = true;
    try {
      const res = await clientApi.invoiceDetail(invoiceId.value);
      detail.value = res.data || null;
      alipayAmount.value = formatMoney(payableAmount.value);
      syncPayMethod();
      if (detail.value && !isPayableInvoice(detail.value)) {
        resetPaymentPayload();
        await router.replace({
          path: '/client/invoices',
          query: { detail: String(invoiceId.value) },
        });
        return;
      }
      if (detail.value?.status === 1) {
        resetPaymentPayload();
      }
    } catch (error: unknown) {
      MessagePlugin.error(getErrorMessage(error, '账单详情加载失败'));
    } finally {
      loading.value = false;
    }
  }

  function handleCancel() {
    if (!invoiceId.value) return;
    const dialog = DialogPlugin.confirm({
      header: '取消账单',
      body: '取消后需重新创建账单才能继续支付，确认取消吗？',
      confirmBtn: '确认取消',
      cancelBtn: '再想想',
      theme: 'warning',
      async onConfirm() {
        dialog.setConfirmLoading(true);
        canceling.value = true;
        try {
          await clientApi.cancelInvoice(invoiceId.value);
          MessagePlugin.success('账单已取消');
          resetPaymentPayload();
          await loadDetail();
          dialog.hide();
        } catch (error: unknown) {
          MessagePlugin.error(getErrorMessage(error, '取消账单失败'));
        } finally {
          canceling.value = false;
          dialog.setConfirmLoading(false);
        }
      },
    });
  }

  async function executeBalancePayment() {
    const sessionToken = String(paySecurity.value.session_token || '');
    if (!invoiceId.value || !sessionToken) {
      MessagePlugin.warning('支付会话已失效，请刷新页面后重试');
      return;
    }

    paying.value = true;
    try {
      const res = await clientApi.payInvoiceByBalance(invoiceId.value, {
        payment_session_token: sessionToken,
      });
      MessagePlugin.success('账单已支付');
      resetPaymentPayload();
      await refreshClientInfo();
      detail.value = res.data?.invoice || detail.value;
      await loadDetail();
    } catch (error: unknown) {
      MessagePlugin.error(getErrorMessage(error, '余额支付失败'));
    } finally {
      paying.value = false;
    }
  }

  function handlePayByBalance() {
    const dialog = DialogPlugin.confirm({
      header: '余额支付',
      body: `确认使用账户余额支付 ¥${formatMoney(payableAmount.value)} 吗？`,
      confirmBtn: '确认支付',
      cancelBtn: '取消',
      theme: 'warning',
      async onConfirm() {
        dialog.setConfirmLoading(true);
        try {
          await executeBalancePayment();
          if (Number(detail.value?.status) === 1) dialog.hide();
        } finally {
          dialog.setConfirmLoading(false);
        }
      },
    });
  }

  async function handlePayByAlipay() {
    const sessionToken = String(paySecurity.value.session_token || '');
    if (!invoiceId.value || !sessionToken) {
      MessagePlugin.warning('支付会话已失效，请刷新页面后重试');
      return;
    }

    const gateway = selectedGatewayKey.value;
    if (!gateway || ['balance', 'free'].includes(gateway)) {
      MessagePlugin.warning('请选择可用支付方式');
      return;
    }

    if (selectedIsAlipay.value && allowBalanceDeduction.value && balanceAmount.value >= payableAmount.value) {
      handlePayByBalance();
      return;
    }

    const gatewayPayload: Record<string, unknown> = {
      payment_session_token: sessionToken,
      gateway,
    };
    if (selectedPaymentType.value) {
      gatewayPayload.payment_type = selectedPaymentType.value;
    }

    const shouldUseBalanceDeduction = selectedIsAlipay.value && allowBalanceDeduction.value && canDeductBalance.value;
    paying.value = true;
    try {
      const res = shouldUseBalanceDeduction
        ? await clientApi.payInvoiceByBalanceAndAlipay(invoiceId.value, {
            payment_session_token: sessionToken,
            balance_amount: autoDeductionAmount.value,
          })
        : await clientApi.payInvoiceByAlipay(invoiceId.value, gatewayPayload);
      const payload = res.data;
      applyAlipayPayload(payload, shouldUseBalanceDeduction);

      if (alipayQrCode.value) {
        MessagePlugin.success(`${selectedPayMethodName.value}二维码已生成`);
        clearPollingTimer();
        pollTimer.value = window.setInterval(() => {
          if (!polling.value && alipayPollingReady.value) {
            void pollAlipayStatus(true);
          }
        }, 5000);
      }
    } catch (error: unknown) {
      MessagePlugin.error(getErrorMessage(error, `生成${selectedPayMethodName.value}二维码失败`));
    } finally {
      paying.value = false;
    }
  }

  async function pollAlipayStatus(silent = false) {
    if (!invoiceId.value || !alipayPollingReady.value) return;

    polling.value = true;
    try {
      const res = await clientApi.queryInvoiceAlipayStatus(invoiceId.value, {
        payment_no: alipayPaymentNo.value,
        poll_token: alipayPollToken.value,
        ...(alipayGateway.value ? { gateway: alipayGateway.value } : {}),
      });
      const payload = res.data || {};
      if (payload.paid) {
        clearPollingTimer();
        resetPaymentPayload();
        MessagePlugin.success('账单已支付');
        await refreshClientInfo();
        detail.value = payload.invoice || detail.value;
        await loadDetail();
      } else if (!silent) {
        MessagePlugin.info(payload.message || '当前仍未支付成功');
      }
    } catch (error: unknown) {
      MessagePlugin.error(getErrorMessage(error, '查询支付状态失败'));
    } finally {
      polling.value = false;
    }
  }

  watch(
    () => detail.value?.status,
    (status) => {
      if (Number(status) === 1) {
        clearPollingTimer();
        resetPaymentPayload();
      }
    },
  );

  watch(
    () => [detail.value?.payable_amount, userStore.info?.cash_balance],
    () => {
      if (!showBalanceDeductionOption.value) {
        allowBalanceDeduction.value = false;
      }

      if (!alipayQrCode.value) {
        alipayAmount.value = formatMoney(payableAmount.value);
      }
    },
  );

  onMounted(() => {
    void loadDetail();
  });

  onBeforeUnmount(() => {
    clearPollingTimer();
  });

  return {
    router,
    loading,
    canceling,
    paying,
    polling,
    detail,
    selectedPayMethod,
    allowBalanceDeduction,
    alipayDialogVisible,
    alipayQrCode,
    alipayPaymentNo,
    appliedDeductionAmountText,
    hasAppliedBalanceDeduction,
    alipayPayableAmount,
    invoiceId,
    payMethods,
    hasPayMethods,
    canPay,
    alipayPollingReady,
    balanceAmount,
    payableAmount,
    balanceText,
    autoDeductionAmountText,
    estimatedAlipayAmountText,
    selectedPayMethodName,
    showBalanceDeductionOption,
    showPayActions,
    payTip,
    loadDetail,
    handleCancel,
    handlePayByBalance,
    handlePayByAlipay,
    handleDeductionToggle,
    pollAlipayStatus,
    selectPayMethod,
  };
}
