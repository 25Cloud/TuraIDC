import { MessagePlugin } from 'tdesign-vue-next';
import type { Ref } from 'vue';
import { computed, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';

import clientApi from '@/api/client';
import { formatMoney } from '@/domains/services/useServiceCenter';
import { t } from '@/locales';
import type {
  ConsoleServiceDetail,
  ServiceUpgradeOption,
  ServiceUpgradePreview,
  ServiceUpgradeQuote,
} from '@/types/client';
import { reportRequestError } from '@/utils/error';

import { resolveErrorMessage } from './useConsoleCore';

/**
 * 配置项升级（补差价）。
 *
 * 后端链路（本次只读核对，未改动）：
 * - `GET  /v2/client/services/{id}/upgrades` → ServiceConsoleController@hostUpgradePreview
 *   → `ServiceUpgradeService::previewForUser()`（backend/app/Services/ClientServiceConsole/ServiceUpgradeService.php:48-63）
 *   返回 `{service_id, service_name, upstream_host_id, options}`；`options` 是上游
 *   `GET /v1/hosts/{hostId}/actions/upgrade` 的 `data` **原样透传**，核心没有归一化。
 * - `POST .../upgrades/quotes` → `quoteForUser()`（同文件 :65-87），入参只接受
 *   `product_id` + `billing_cycle` + 可选 `promo_code`（QuoteHostUpgradeRequest::rules:13-17）；
 *   返回 `{service_id, service_name, upstream_host_id, product_id, billing_cycle, quote}`。
 * - `POST .../upgrades/orders` → 生成账单，响应 `{id, invoice_no, service_id}`
 *   （ServiceConsoleController.php:221-225）。
 *
 * 因此这里对 `options` 做**别名容错解析**：上游换版本不会直接变成空白页，
 * 解析不到就给「暂无可升级配置」空态，而不是猜一个字段名硬渲染。
 */

export interface UseConsoleUpgradesOptions {
  serviceId: Ref<number>;
  detail: Ref<ConsoleServiceDetail>;
}

/** 上游选项可能的容器字段，按优先级依次尝试 */
const OPTION_CONTAINERS = ['options', 'products', 'upgrade_products', 'list', 'items', 'data'];

/** 目标商品 id 的别名（后端把它原样传给上游 product_id） */
const PRODUCT_ID_KEYS = ['product_id', 'productId', 'pid', 'id'];

/** 目标名称的别名 */
const PRODUCT_NAME_KEYS = ['name', 'product_name', 'productName', 'title', 'label'];

/** 单条价格（仅用于展示，真实金额以 quote.amount_total 为准） */
const PRODUCT_PRICE_KEYS = ['price', 'amount', 'price_monthly', 'monthly_price', 'amount_monthly'];

function normalizeText(value: unknown): string {
  if (value === null || value === undefined) return '';
  return String(value).trim();
}

function pickString(record: Record<string, unknown>, keys: string[]): string {
  for (const key of keys) {
    const text = normalizeText(record[key]);
    if (text !== '') return text;
  }
  return '';
}

function pickProductId(record: Record<string, unknown>): string {
  for (const key of PRODUCT_ID_KEYS) {
    const text = normalizeText(record[key]);
    if (text !== '' && text !== '0') return text;
  }
  return '';
}

/** 从 pricing / cycles / billingcycle 等字段里抽出该目标可选的周期 */
function resolveCycles(record: Record<string, unknown>, fallback: string[]): string[] {
  const collected: string[] = [];
  const push = (value: unknown) => {
    const text = normalizeText(value);
    if (text !== '' && !collected.includes(text)) collected.push(text);
  };

  const arraySource = [record.billing_cycles, record.cycles].find((item) => Array.isArray(item));
  if (Array.isArray(arraySource)) {
    arraySource.forEach((item) => {
      if (item && typeof item === 'object') {
        push(pickString(item as Record<string, unknown>, ['billingcycle', 'billing_cycle', 'cycle', 'value', 'name']));
        return;
      }
      push(item);
    });
  }

  const pricing = record.pricing;
  if (Array.isArray(pricing)) {
    pricing.forEach((item) => {
      if (item && typeof item === 'object') {
        push(pickString(item as Record<string, unknown>, ['billingcycle', 'billing_cycle', 'cycle', 'name']));
      }
    });
  } else if (pricing && typeof pricing === 'object') {
    Object.keys(pricing as Record<string, unknown>).forEach(push);
  }

  push(record.billingcycle);
  push(record.billing_cycle);

  return collected.length > 0 ? collected : fallback;
}

function toUpgradeOption(item: unknown, fallbackCycles: string[]): ServiceUpgradeOption | null {
  if (!item || typeof item !== 'object' || Array.isArray(item)) return null;

  const record = item as Record<string, unknown>;
  const productId = pickProductId(record);
  if (productId === '') return null;

  const price = pickString(record, PRODUCT_PRICE_KEYS);

  return {
    ...record,
    product_id: productId,
    name: pickString(record, PRODUCT_NAME_KEYS),
    billing_cycles: resolveCycles(record, fallbackCycles),
    price: price === '' ? undefined : price,
  };
}

/**
 * 把 preview 的 `options` 归一成可选配置列表。
 *
 * `options` 可能是数组、`{products: []}`、`{data: {list: []}}` 或单个对象；
 * 逐层找数组，找不到任何带 id 的条目就返回空数组（调用方渲染空态）。
 */
export function normalizeUpgradeOptions(raw: unknown, fallbackCycles: string[] = []): ServiceUpgradeOption[] {
  const visit = (value: unknown, depth: number): ServiceUpgradeOption[] => {
    if (depth > 3 || value === null || value === undefined) return [];

    if (Array.isArray(value)) {
      return value
        .map((item) => toUpgradeOption(item, fallbackCycles))
        .filter((item): item is ServiceUpgradeOption => item !== null);
    }

    if (typeof value !== 'object') return [];

    const record = value as Record<string, unknown>;

    // 单个商品对象（自己就带 id）
    const single = toUpgradeOption(record, fallbackCycles);
    if (single !== null) return [single];

    for (const container of OPTION_CONTAINERS) {
      const nested = visit(record[container], depth + 1);
      if (nested.length > 0) return nested;
    }

    return [];
  };

  return visit(raw, 0);
}

/** 差价类型：可付 / 免费 / 降级 / 未知 */
export type UpgradeQuoteKind = 'payable' | 'free' | 'downgrade' | 'unknown';

export function useConsoleUpgrades(options: UseConsoleUpgradesOptions) {
  const { serviceId, detail } = options;
  const router = useRouter();

  const upgradeVisible = ref(false);
  const upgradeLoading = ref(false);
  const upgradeQuoting = ref(false);
  const upgradeSubmitting = ref(false);
  const upgradeError = ref('');
  const upgradeData = ref<ServiceUpgradePreview | null>(null);
  const upgradeQuote = ref<ServiceUpgradeQuote | null>(null);
  const upgradeOptions = ref<ServiceUpgradeOption[]>([]);
  const upgradeForm = reactive({ product_id: '', billing_cycle: '', promo_code: '' });

  // 报价请求序号：连续切换配置时只有最后一次的响应生效，避免旧响应覆盖新选择
  let quoteSeq = 0;

  const currentCycleLabel = computed(() =>
    String(detail.value.billing_cycle_label || detail.value.billing_cycle || '').trim(),
  );

  const currentSpecText = computed(() =>
    String(
      detail.value.product_spec_display ||
        detail.value.product?.display_name ||
        detail.value.product_display_name ||
        '',
    ).trim(),
  );

  /** 当前配置：实例名 / 规格 / 周期 + 规格明细（最多 4 条） */
  const currentConfigRows = computed(() => {
    const rows: Array<{ label: string; value: string }> = [
      {
        label: t('serviceConsole.upgrade.instanceName'),
        value: String(detail.value.custom_service_name || detail.value.name || '').trim() || '--',
      },
      { label: t('serviceConsole.upgrade.currentSpec'), value: currentSpecText.value || '--' },
      { label: t('serviceConsole.upgrade.currentCycle'), value: currentCycleLabel.value || '--' },
    ];

    const specItems = Array.isArray(detail.value.specs) ? detail.value.specs : [];
    specItems
      .filter((item) => String(item?.label || '').trim() !== '' && normalizeText(item?.value) !== '')
      .slice(0, 4)
      .forEach((item) => {
        rows.push({ label: String(item.label).trim(), value: normalizeText(item.value) });
      });

    return rows;
  });

  const selectedUpgradeOption = computed<ServiceUpgradeOption | null>(
    () => upgradeOptions.value.find((item) => String(item.product_id) === upgradeForm.product_id) || null,
  );

  /** 目标周期候选：优先用上游给的，拿不到就退回当前实例周期 */
  const upgradeCycleOptions = computed<string[]>(() => {
    const cycles = selectedUpgradeOption.value?.billing_cycles || [];
    if (cycles.length > 0) return cycles;

    return currentCycleLabel.value === '' ? [] : [currentCycleLabel.value];
  });

  const upgradeQuoteAmount = computed<number | null>(() => {
    const raw = upgradeQuote.value?.quote?.amount_total;
    if (raw === null || raw === undefined || raw === '') return null;

    const amount = Number(raw);
    return Number.isFinite(amount) ? amount : null;
  });

  const upgradeQuoteKind = computed<UpgradeQuoteKind>(() => {
    const amount = upgradeQuoteAmount.value;
    if (amount === null) return 'unknown';
    if (amount > 0) return 'payable';
    if (amount === 0) return 'free';
    return 'downgrade';
  });

  const upgradePayableAmount = computed(() => formatMoney(upgradeQuoteAmount.value ?? 0));

  const upgradeOptionName = computed(
    () =>
      String(
        selectedUpgradeOption.value?.name || upgradeQuote.value?.quote?.name || upgradeForm.product_id || '',
      ).trim() || t('serviceConsole.upgrade.selectedOption'),
  );

  /** 空态/失败态文案：失败优先，其次用后端给的说明 */
  const upgradeEmptyText = computed(() => {
    if (upgradeError.value !== '') return upgradeError.value;

    const backendMessage = String(upgradeData.value?.message || '').trim();
    return backendMessage !== '' ? backendMessage : t('serviceConsole.upgrade.empty');
  });

  const canSubmitUpgrade = computed(
    () =>
      !upgradeLoading.value &&
      !upgradeQuoting.value &&
      !upgradeSubmitting.value &&
      upgradeOptions.value.length > 0 &&
      upgradeForm.product_id !== '' &&
      upgradeForm.billing_cycle !== '' &&
      upgradeQuoteAmount.value !== null,
  );

  /**
   * 降级（上游算出来的差价是负数）不允许提交。
   *
   * 后端 `ServiceUpgradeService::createInvoiceForUser()` 现在也会在 `amount_total < 0` 时抛
   * 「目标配置低于当前配置，升级不支持退还差价；降级请联系客服处理」，前端在入口先挡一层，
   * 用户不用先点一次提交才看到报错；文案与后端那句保持一致（见 locales 的 downgradeNotice）。
   */
  const upgradeSubmitBlocked = computed(() => !canSubmitUpgrade.value || upgradeQuoteKind.value === 'downgrade');

  function resetUpgradeState() {
    upgradeData.value = null;
    upgradeQuote.value = null;
    upgradeOptions.value = [];
    upgradeError.value = '';
    upgradeForm.product_id = '';
    upgradeForm.billing_cycle = '';
    upgradeForm.promo_code = '';
  }

  function buildQuotePayload() {
    const payload: Record<string, unknown> = {
      product_id: Number(upgradeForm.product_id),
      billing_cycle: upgradeForm.billing_cycle,
    };

    const promoCode = upgradeForm.promo_code.trim();
    if (promoCode !== '') payload.promo_code = promoCode;

    return payload;
  }

  /** 拉取可选升级配置；成功后有第一个选项就自动报一次价 */
  async function loadUpgradePreview() {
    upgradeLoading.value = true;
    upgradeError.value = '';
    upgradeQuote.value = null;

    try {
      const res = await clientApi.serviceUpgradePreview(serviceId.value);
      upgradeData.value = res.data || null;

      const fallbackCycles = currentCycleLabel.value === '' ? [] : [currentCycleLabel.value];
      const normalized = normalizeUpgradeOptions(res.data?.options, fallbackCycles);
      upgradeOptions.value = normalized;

      const [first] = normalized;
      if (!first) {
        upgradeForm.product_id = '';
        upgradeForm.billing_cycle = '';
        return;
      }

      upgradeForm.product_id = String(first.product_id);
      upgradeForm.billing_cycle = first.billing_cycles?.[0] || fallbackCycles[0] || '';
      await quoteUpgrade();
    } catch (error: unknown) {
      upgradeData.value = null;
      upgradeOptions.value = [];
      upgradeError.value = resolveErrorMessage(error, t('serviceConsole.upgrade.loadFailed'));
    } finally {
      upgradeLoading.value = false;
    }
  }

  /** 报价：金额以后端 `quote.amount_total` 为准（它同时是账单金额来源） */
  async function quoteUpgrade() {
    const seq = ++quoteSeq;
    upgradeQuote.value = null;

    if (upgradeForm.product_id === '' || upgradeForm.billing_cycle === '') {
      return;
    }

    upgradeQuoting.value = true;
    try {
      const res = await clientApi.quoteServiceUpgrade(serviceId.value, buildQuotePayload());
      if (seq !== quoteSeq) return;
      upgradeQuote.value = res.data || null;
    } catch (error: unknown) {
      if (seq !== quoteSeq) return;
      upgradeQuote.value = null;
      // 拦截器已经提示过就不再重复 toast（reportRequestError 内部按 __handled 判断）
      reportRequestError(error, t('serviceConsole.upgrade.quoteFailed'));
    } finally {
      if (seq === quoteSeq) upgradeQuoting.value = false;
    }
  }

  async function openUpgradeDialog() {
    upgradeVisible.value = true;
    resetUpgradeState();
    await loadUpgradePreview();
  }

  async function handleUpgradeOptionChange(value: unknown) {
    upgradeForm.product_id = normalizeText(value);
    const option = selectedUpgradeOption.value;
    upgradeForm.billing_cycle = option?.billing_cycles?.[0] || currentCycleLabel.value || '';
    await quoteUpgrade();
  }

  async function handleUpgradeCycleChange(value: unknown) {
    upgradeForm.billing_cycle = normalizeText(value);
    await quoteUpgrade();
  }

  async function reapplyUpgradePromoCode() {
    await quoteUpgrade();
  }

  /** 提交：生成升级账单后跳到账单支付页（与流量包对话框同一套处理） */
  async function submitUpgradeOrder() {
    if (upgradeSubmitting.value) return;

    if (upgradeQuoteKind.value === 'downgrade') {
      MessagePlugin.warning(t('serviceConsole.upgrade.downgradeNotice'));
      return;
    }

    if (upgradeForm.product_id === '' || upgradeForm.billing_cycle === '') {
      MessagePlugin.warning(t('serviceConsole.upgrade.selectOption'));
      return;
    }

    if (upgradeQuoteAmount.value === null) {
      MessagePlugin.warning(t('serviceConsole.upgrade.quoteRequired'));
      return;
    }

    upgradeSubmitting.value = true;
    try {
      const res = await clientApi.createServiceUpgradeOrder(serviceId.value, buildQuotePayload());
      const invoiceId = Number(res.data?.id || 0);

      upgradeVisible.value = false;
      MessagePlugin.success(t('serviceConsole.upgrade.billCreated'));
      await router.push(invoiceId > 0 ? `/client/invoices/${invoiceId}/pay` : '/client/invoices');
    } catch (error: unknown) {
      reportRequestError(error, t('serviceConsole.upgrade.invoiceFailed'));
    } finally {
      upgradeSubmitting.value = false;
    }
  }

  return {
    upgradeVisible,
    upgradeLoading,
    upgradeQuoting,
    upgradeSubmitting,
    upgradeError,
    upgradeData,
    upgradeQuote,
    upgradeOptions,
    upgradeForm,
    upgradeQuoteKind,
    upgradeQuoteAmount,
    upgradePayableAmount,
    upgradeOptionName,
    upgradeCycleOptions,
    upgradeEmptyText,
    currentConfigRows,
    selectedUpgradeOption,
    canSubmitUpgrade,
    upgradeSubmitBlocked,
    openUpgradeDialog,
    loadUpgradePreview,
    quoteUpgrade,
    handleUpgradeOptionChange,
    handleUpgradeCycleChange,
    reapplyUpgradePromoCode,
    submitUpgradeOrder,
  };
}
