import { computed, onBeforeUnmount, ref, watch } from "vue";
import { ElMessage } from "element-plus/es/components/message/index.mjs";
import siteApi from "@/api/site";
import {
  resolveMissingPurchaseRequirements,
  resolvePurchaseRequirementList,
  resolvePurchaseRequirementSummary,
} from "@/utils/productPurchaseRequirements";
import {
  normalizeMoneyText,
  resolveProductDisplayName,
} from "@/utils/websiteProductConfig";
import { useUserStore } from "@/stores/user";
import { getToken } from "@/utils/auth";
import { navigateToConsole } from "@/utils/consoleUrl";
import {
  buildIdempotencyKey,
  encodePendingWebsiteCheckout,
  savePendingWebsiteCheckout,
} from "@/utils/websiteCheckout";
import {
  buildPendingCouponRedirectUrl,
  clearPendingWebsiteCoupon,
  getPendingWebsiteCouponId,
} from "@/utils/websiteCoupon";

export function useWebsiteProductCheckout({
  productDetail,
  configForm: _configForm,
  pricingEntries,
  buildConfigPayload,
  initProductDefaults,
  resetConfigForm,
}) {
  const userStore = useUserStore();
  const configLoading = ref(false);
  const submitting = ref(false);
  const selectedCycle = ref("");
  const quantity = ref(1);
  const quoteResult = ref(null);
  const quoteLoading = ref(false);
  const quoteToken = ref("");
  const productStock = ref(null);
  const productStockLoading = ref(false);
  const productStockError = ref("");
  const selectedCouponId = ref(getPendingWebsiteCouponId());
  const productDetailCache = {};
  const productDetailPending = {};
  const PRODUCT_DETAIL_PREFETCH_CONCURRENCY = 2;
  // 单分类详情预取上限：仅预取列表前若干个（多在首屏视口附近），
  // 避免大分类（page_size=50）对全部商品逐个发请求
  const PRODUCT_DETAIL_PREFETCH_LIMIT = 8;

  let currentProductId = 0;
  let detailToken = 0;
  let stockToken = 0;
  let quoteTokenId = 0;
  let prefetchToken = 0;
  let quoteTimer = null;
  let detailAbortController = null;
  let stockAbortController = null;
  let quoteAbortController = null;
  let prefetchAbortController = null;
  let quoteWatchSuspendCount = 0;

  const selectedProduct = computed(() => productDetail.value);
  const selectedProductDisplayName = computed(() =>
    resolveProductDisplayName(selectedProduct.value),
  );
  const selectedPricingEntry = computed(
    () =>
      pricingEntries.value.find((item) => item.cycle === selectedCycle.value) ||
      null,
  );
  const cyclePrice = computed(
    () => selectedPricingEntry.value?.amount || "0.00",
  );
  const baseAmount = computed(
    () => quoteResult.value?.base_amount ?? cyclePrice.value,
  );
  const setupFee = computed(
    () =>
      quoteResult.value?.setup_fee ??
      productDetail.value?.setup_fee_display ??
      normalizeMoneyText(productDetail.value?.setup_fee || 0),
  );
  const quoteItems = computed(() => quoteResult.value?.items || []);
  const appliedCoupon = computed(() => quoteResult.value?.coupon || null);
  const availableCoupons = computed(
    () => quoteResult.value?.available_coupons || [],
  );
  const totalPrice = computed(() => {
    if (quoteResult.value) {
      return quoteResult.value.total_amount || "0.00";
    }

    return (
      Number(selectedPricingEntry.value?.total_amount || 0) * quantity.value
    ).toFixed(2);
  });
  const agentDiscountAmount = computed(() =>
    normalizeMoneyText(quoteResult.value?.agent_discount_amount),
  );
  const agentOriginalAmount = computed(() =>
    normalizeMoneyText(quoteResult.value?.original_total_amount),
  );
  const agentAmount = computed(() =>
    normalizeMoneyText(quoteResult.value?.agent_amount),
  );
  const agentGroupName = computed(() =>
    String(quoteResult.value?.agent_group_name || ""),
  );
  const hasAgentDiscount = computed(() => {
    const quote = quoteResult.value;
    const rate = Number(quote?.agent_discount_rate);
    return Boolean(
      quote &&
      quote.agent_discount_rate !== null &&
      quote.agent_discount_rate !== undefined &&
      Number.isFinite(rate) &&
      rate < 100,
    );
  });
  const agentDiscountPercent = computed(() => {
    const rate = Number(quoteResult.value?.agent_discount_rate);
    if (!Number.isFinite(rate) || rate <= 0) return "";
    const value = Math.round((rate / 10) * 10) / 10;
    return `${String(value).replace(/\.0$/, "")}折`;
  });
  const selectedCycleLabel = computed(
    () => selectedPricingEntry.value?.label || "",
  );
  const resolvedStock = computed(() => {
    if (productStock.value !== null && productStock.value !== undefined) {
      return Number(productStock.value);
    }

    return null;
  });
  const stockClass = computed(() => {
    if (productStockLoading.value || productStockError.value) return "sync";
    const stock = resolvedStock.value;
    if (stock === null) return "sync";
    if (stock === -1 || stock > 10) return "ok";
    if (stock > 0) return "warn";
    return "empty";
  });
  const stockLabel = computed(() => {
    if (productStockLoading.value) return "库存同步中";
    if (productStockError.value) return "库存同步失败";
    const stock = resolvedStock.value;
    if (stock === null) return "库存同步中";
    if (stock === -1 || stock > 10) return "库存充足";
    if (stock > 0) return "库存紧张";
    return "暂无库存";
  });
  const stockHint = computed(() => {
    if (productStockLoading.value) return "正在同步实时库存，请稍候。";
    if (productStockError.value) return "实时库存同步失败，请稍后重试。";
    const stock = resolvedStock.value;
    if (stock === null) return "正在同步实时库存，请稍候。";
    if (stock === -1 || stock > 10) return "当前库存充足，可直接提交账单。";
    if (stock > 0) return `剩余 ${stock} 台，请尽快购买。`;
    return "当前库存不足，请联系客服。";
  });
  const canSubmit = computed(() => {
    const stock = resolvedStock.value;
    return (
      Boolean(
        selectedCycle.value && selectedProduct.value && quoteToken.value,
      ) &&
      !productStockLoading.value &&
      !productStockError.value &&
      stock !== null &&
      stock !== 0
    );
  });
  const purchaseRequirementList = computed(() =>
    resolvePurchaseRequirementList(selectedProduct.value),
  );
  const purchaseRequirementSummary = computed(() =>
    resolvePurchaseRequirementSummary(selectedProduct.value),
  );

  function resetQuoteState() {
    quoteResult.value = null;
    quoteToken.value = "";
  }

  function suspendQuoteWatch() {
    quoteWatchSuspendCount += 1;
  }

  function resumeQuoteWatch(triggerQuote = false) {
    quoteWatchSuspendCount = Math.max(quoteWatchSuspendCount - 1, 0);

    if (quoteWatchSuspendCount === 0 && triggerQuote) {
      fetchQuote();
    }
  }

  function applyQuoteResult(payload, nextCouponId = selectedCouponId.value) {
    quoteResult.value = payload || null;
    quoteToken.value = String(payload?.quote_token || "");
    selectedCouponId.value = Number(
      nextCouponId || payload?.user_coupon_id || 0,
    );
  }

  function normalizeProductId(id) {
    const productId = Number(id || 0);
    return Number.isFinite(productId) && productId > 0 ? productId : 0;
  }

  function isCanceledError(error) {
    return error?.code === "ERR_CANCELED" || error?.name === "CanceledError";
  }

  function getCachedProductDetail(id) {
    return productDetailCache[normalizeProductId(id)] || null;
  }

  function cacheProductDetail(id, product) {
    const productId = normalizeProductId(id);
    if (productId > 0 && product) {
      productDetailCache[productId] = product;
    }
  }

  function fetchProductDetail(id, options = {}) {
    const productId = normalizeProductId(id);
    if (!productId) {
      return Promise.resolve(null);
    }

    const cached = getCachedProductDetail(productId);
    if (cached) {
      return Promise.resolve(cached);
    }

    if (productDetailPending[productId]) {
      return productDetailPending[productId];
    }

    const request = siteApi
      .product(
        productId,
        options.signal ? { signal: options.signal } : undefined,
      )
      .then((res) => {
        const product = res.data.product || null;
        cacheProductDetail(productId, product);
        return product;
      })
      .finally(() => {
        if (productDetailPending[productId] === request) {
          delete productDetailPending[productId];
        }
      });

    productDetailPending[productId] = request;
    return request;
  }

  function applyProductDetail(product) {
    productDetail.value = product;
    suspendQuoteWatch();
    try {
      initProductDefaults({
        selectedCycleRef: selectedCycle,
        quantityRef: quantity,
        resetQuoteState,
      });
    } finally {
      resumeQuoteWatch(true);
    }
  }

  function resetSelection() {
    currentProductId = 0;
    detailAbortController?.abort();
    stockAbortController?.abort();
    quoteAbortController?.abort();
    productDetail.value = null;
    resetConfigForm();
    selectedCycle.value = "";
    quantity.value = 1;
    resetQuoteState();
    productStock.value = null;
    productStockLoading.value = false;
    productStockError.value = "";
  }

  function buildOrderPayload() {
    const payload = {
      product_id: Number(selectedProduct.value?.id || 0),
      billing_cycle: selectedCycle.value,
      quantity: quantity.value,
      config: buildConfigPayload(),
      quote_token: quoteToken.value,
    };

    if (selectedCouponId.value > 0) {
      payload.user_coupon_id = selectedCouponId.value;
    }

    return payload;
  }

  function redirectToConsoleCheckout(orderPayload, idempotencyKey) {
    const pendingCheckout = {
      source: "website-products",
      createdAt: Date.now(),
      idempotencyKey,
      orderPayload,
    };

    savePendingWebsiteCheckout(pendingCheckout);
    const checkoutPayload = encodePendingWebsiteCheckout(pendingCheckout);
    const checkoutPath = buildPendingCouponRedirectUrl(
      "/client/checkout-resume",
      orderPayload.user_coupon_id,
    );
    ElMessage.success("正在进入控制台继续创建账单");

    navigateToConsole(
      checkoutPath,
      checkoutPayload ? { checkout_payload: checkoutPayload } : {},
    );
  }

  async function requestQuote(nextCouponId = selectedCouponId.value) {
    return siteApi.productQuote(
      selectedProduct.value.id,
      {
        billing_cycle: selectedCycle.value,
        config: buildConfigPayload(),
        quantity: quantity.value,
        user_coupon_id: Number(nextCouponId || 0) || undefined,
      },
      {
        signal: quoteAbortController?.signal,
      },
    );
  }

  function looksLikeCouponError(error) {
    const message = String(
      error?.response?.data?.message || error?.message || "",
    );
    return message.includes("优惠券") || message.includes("优惠码");
  }

  async function executeQuote(
    nextCouponId = selectedCouponId.value,
    options = {},
  ) {
    if (!selectedProduct.value || !selectedCycle.value) {
      resetQuoteState();
      return false;
    }

    const snapshot = options.rollbackOnError
      ? {
          quoteResult: quoteResult.value,
          quoteToken: quoteToken.value,
          selectedCouponId: selectedCouponId.value,
        }
      : null;

    const token = ++quoteTokenId;
    quoteAbortController?.abort();
    quoteAbortController = new AbortController();
    quoteLoading.value = true;

    try {
      const res = await requestQuote(nextCouponId);
      if (
        token !== quoteTokenId ||
        currentProductId !== Number(selectedProduct.value?.id || 0)
      ) {
        return false;
      }

      applyQuoteResult(res.data || null, nextCouponId);
      return true;
    } catch (error) {
      if (error?.code === "ERR_CANCELED" || error?.name === "CanceledError") {
        return false;
      }

      if (snapshot) {
        quoteResult.value = snapshot.quoteResult;
        quoteToken.value = snapshot.quoteToken;
        selectedCouponId.value = snapshot.selectedCouponId;
        return false;
      }

      if (
        Number(nextCouponId || 0) > 0 &&
        options.fallbackInvalidCoupon &&
        looksLikeCouponError(error)
      ) {
        selectedCouponId.value = 0;
        clearPendingWebsiteCoupon();

        try {
          const fallbackRes = await requestQuote(0);
          applyQuoteResult(fallbackRes.data || null, 0);
          return false;
        } catch {
          resetQuoteState();
          return false;
        }
      }

      resetQuoteState();
      return false;
    } finally {
      if (token === quoteTokenId) {
        quoteLoading.value = false;
      }
    }
  }

  function handleCouponChange(value) {
    if (!selectedProduct.value || !selectedCycle.value) {
      selectedCouponId.value = Number(value || 0);
      return;
    }

    selectedCouponId.value = Number(value || 0);
    fetchQuote();
  }

  async function clearCoupon() {
    selectedCouponId.value = 0;
    clearPendingWebsiteCoupon();
    await executeQuote(0, { fallbackInvalidCoupon: false });
  }

  function fetchQuote() {
    if (quoteWatchSuspendCount > 0) {
      return;
    }

    clearTimeout(quoteTimer);
    quoteTimer = setTimeout(() => {
      executeQuote(selectedCouponId.value, { fallbackInvalidCoupon: true });
    }, 250);
  }

  async function ensurePurchaseRequirementsBeforeCheckout() {
    const requirements = resolvePurchaseRequirementList(selectedProduct.value);
    if (!requirements.length) {
      return true;
    }

    if (!getToken()) {
      ElMessage.warning("请先登录后再购买该商品");
      navigateToConsole("/client/login");
      return false;
    }

    let user = userStore.info;
    if (!user) {
      try {
        user = await userStore.fetchUserInfo();
      } catch {
        ElMessage.warning("登录状态已失效，请重新登录");
        navigateToConsole("/client/login");
        return false;
      }
    }

    const missing = resolveMissingPurchaseRequirements(
      selectedProduct.value,
      user,
    );
    if (!missing.length) {
      return true;
    }

    const primary = missing[0];
    ElMessage.warning(primary.unmetMessage || "请先完成购买前置要求");
    navigateToConsole(primary.route || "/client/profile");
    return false;
  }

  async function submitOrder() {
    if (productStockLoading.value) {
      ElMessage.warning("库存同步中，请稍候");
      return;
    }

    if (productStockError.value) {
      ElMessage.warning("库存同步失败，请稍后重试");
      return;
    }

    if (resolvedStock.value === 0) {
      ElMessage.warning("当前库存不足，暂时无法购买");
      return;
    }

    if (!quoteToken.value) {
      ElMessage.warning("报价凭证已失效，请稍后重试");
      return;
    }

    if (!canSubmit.value) {
      ElMessage.warning("请选择计费周期");
      return;
    }

    if (quoteLoading.value) {
      ElMessage.warning("价格计算中，请稍候");
      return;
    }

    if (!(await ensurePurchaseRequirementsBeforeCheckout())) {
      return;
    }

    submitting.value = true;

    try {
      const orderPayload = buildOrderPayload();
      const idempotencyKey = buildIdempotencyKey("website-order");

      redirectToConsoleCheckout(orderPayload, idempotencyKey);
    } catch (error) {
      ElMessage.error(
        error?.response?.data?.message || "跳转控制台失败，请重试",
      );
    } finally {
      submitting.value = false;
    }
  }

  async function loadProductDetail(id) {
    const productId = normalizeProductId(id);
    if (!productId) {
      return false;
    }

    const token = ++detailToken;
    const cached = getCachedProductDetail(productId);
    if (cached) {
      if (token !== detailToken || currentProductId !== productId) {
        return false;
      }

      applyProductDetail(cached);
      return true;
    }

    detailAbortController?.abort();
    detailAbortController = new AbortController();
    configLoading.value = true;
    try {
      const product = await fetchProductDetail(productId, {
        signal: detailAbortController.signal,
      });
      if (token !== detailToken || currentProductId !== productId) {
        return false;
      }

      if (product) {
        applyProductDetail(product);
        return true;
      }

      productDetail.value = null;
      resetConfigForm();
      selectedCycle.value = "";
      quantity.value = 1;
      resetQuoteState();
      return false;
    } catch (error) {
      if (isCanceledError(error)) {
        return false;
      }

      if (token === detailToken && currentProductId === productId) {
        productDetail.value = null;
        resetConfigForm();
        selectedCycle.value = "";
        quantity.value = 1;
        resetQuoteState();
      }

      return false;
    } finally {
      if (token === detailToken && currentProductId === productId) {
        configLoading.value = false;
      }
    }
  }

  function loadSelectedProduct(id, options = {}) {
    currentProductId = normalizeProductId(id);

    if (options.refreshStockOnly && productDetail.value) {
      refreshProductStock(currentProductId);
      return;
    }

    const cached = getCachedProductDetail(currentProductId);
    detailAbortController?.abort();
    stockAbortController?.abort();
    quoteAbortController?.abort();
    // 用户已选中商品，中止对该分类其余商品的详情预取，避免与当前商品的请求争抢连接
    cancelProductDetailPrefetch();
    if (!cached) {
      productDetail.value = null;
      resetConfigForm();
      selectedCycle.value = "";
      quantity.value = 1;
    }
    resetQuoteState();
    productStock.value = null;
    productStockLoading.value = false;
    productStockError.value = "";
    void loadProductDetail(currentProductId).finally(() => {
      if (currentProductId === normalizeProductId(id)) {
        refreshProductStock(currentProductId);
      }
    });
  }

  function cancelProductDetailPrefetch() {
    prefetchToken += 1;
    prefetchAbortController?.abort();
    prefetchAbortController = null;
  }

  function prefetchProductDetails(products = []) {
    cancelProductDetailPrefetch();

    const ids = Array.from(
      new Set(
        (Array.isArray(products) ? products : [])
          .map((product) => normalizeProductId(product?.id || product))
          .filter(
            (id) =>
              id > 0 &&
              id !== currentProductId &&
              !getCachedProductDetail(id) &&
              !productDetailPending[id],
          ),
      ),
    ).slice(0, PRODUCT_DETAIL_PREFETCH_LIMIT);

    if (!ids.length) {
      return;
    }

    const token = ++prefetchToken;
    prefetchAbortController = new AbortController();
    let cursor = 0;

    const runWorker = async () => {
      while (token === prefetchToken && cursor < ids.length) {
        const productId = ids[cursor];
        cursor += 1;

        try {
          await fetchProductDetail(productId, {
            signal: prefetchAbortController?.signal,
          });
        } catch (error) {
          if (isCanceledError(error)) {
            return;
          }
        }
      }
    };

    const workerCount = Math.min(
      PRODUCT_DETAIL_PREFETCH_CONCURRENCY,
      ids.length,
    );
    for (let index = 0; index < workerCount; index += 1) {
      void runWorker();
    }
  }

  async function refreshProductStock(id) {
    const productId = normalizeProductId(id);
    if (!productId) {
      return;
    }

    const token = ++stockToken;
    stockAbortController?.abort();
    stockAbortController = new AbortController();
    productStockLoading.value = true;
    productStockError.value = "";
    productStock.value = null;

    try {
      const res = await siteApi.productStock(productId, {
        signal: stockAbortController.signal,
      });
      if (token !== stockToken || currentProductId !== productId) {
        return;
      }

      productStock.value = Number(res.data?.stock ?? 0);
    } catch (error) {
      if (isCanceledError(error)) {
        return;
      }

      if (token !== stockToken || currentProductId !== productId) {
        return;
      }

      productStockError.value =
        error?.response?.data?.message || "库存同步失败";
    } finally {
      if (token === stockToken && currentProductId === productId) {
        productStockLoading.value = false;
      }
    }
  }

  // 用序列化键替代 deep watch，避免每次 configForm 深层遍历
  const quoteTrigger = computed(() => {
    const payload = buildConfigPayload();
    return `${selectedCycle.value}|${quantity.value}|${JSON.stringify(payload)}`;
  });
  watch(quoteTrigger, () => fetchQuote(), { flush: "post" });
  onBeforeUnmount(() => {
    clearTimeout(quoteTimer);
    detailAbortController?.abort();
    stockAbortController?.abort();
    quoteAbortController?.abort();
    cancelProductDetailPrefetch();
    clearPendingWebsiteCoupon();
  });

  return {
    configLoading,
    submitting,
    selectedProduct,
    selectedCycle,
    quantity,
    quoteLoading,
    productStockLoading,
    productStockError,
    selectedProductDisplayName,
    baseAmount,
    setupFee,
    quoteItems,
    appliedCoupon,
    availableCoupons,
    totalPrice,
    agentDiscountAmount,
    agentOriginalAmount,
    agentAmount,
    agentGroupName,
    hasAgentDiscount,
    agentDiscountPercent,
    selectedCycleLabel,
    resolvedStock,
    stockClass,
    stockLabel,
    stockHint,
    selectedCouponId,
    canSubmit,
    purchaseRequirementList,
    purchaseRequirementSummary,
    handleCouponChange,
    clearCoupon,
    handleSubmit: submitOrder,
    loadSelectedProduct,
    prefetchProductDetails,
    resetSelection,
  };
}
