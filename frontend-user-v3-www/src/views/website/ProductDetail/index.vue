<template>
  <div class="pd-page" v-loading="loading">
    <div v-if="product">
      <!-- 顶部工具栏：分类 + 型号横向Tab -->
      <div class="pd-topbar">
        <button
          type="button"
          class="category-btn"
          @click="router.push('/products')"
        >
          <svg viewBox="0 0 16 16" fill="none" width="14" height="14">
            <rect
              x="1"
              y="1"
              width="6"
              height="6"
              rx="1"
              stroke="currentColor"
              stroke-width="1.5"
            />
            <rect
              x="9"
              y="1"
              width="6"
              height="6"
              rx="1"
              stroke="currentColor"
              stroke-width="1.5"
            />
            <rect
              x="1"
              y="9"
              width="6"
              height="6"
              rx="1"
              stroke="currentColor"
              stroke-width="1.5"
            />
            <rect
              x="9"
              y="9"
              width="6"
              height="6"
              rx="1"
              stroke="currentColor"
              stroke-width="1.5"
            />
          </svg>
          <span>分类</span>
        </button>

        <!-- 产品线 Tab（同一产品组下的同级） -->
        <div class="sibling-scroll" v-if="siblings.length > 1">
          <button
            v-for="sib in siblings"
            :key="sib.id"
            type="button"
            class="sib-tab"
            :class="{ active: sib.id === product.id }"
            @click="switchProduct(sib.id)"
          >
            {{ siblingDisplayNames[sib.id] }}
          </button>
        </div>
      </div>

      <!-- 产品名称下拉按钮 -->
      <div class="pd-name-bar">
        <button type="button" class="product-name-btn active">
          <span>{{ selectedMachineSpec.displayName }}</span>
          <svg viewBox="0 0 12 12" fill="none" width="12" height="12">
            <path
              d="M3 4.5l3 3 3-3"
              stroke="currentColor"
              stroke-width="1.5"
              stroke-linecap="round"
              stroke-linejoin="round"
            />
          </svg>
        </button>
      </div>

      <section v-if="showCatalogHero" class="pd-hero">
        <div class="pd-hero-copy">
          <span v-if="detailCatalogEyebrow" class="pd-hero-eyebrow">{{
            detailCatalogEyebrow
          }}</span>
          <h1>{{ detailCatalogTitle }}</h1>
          <p v-if="detailCatalogSlogan">{{ detailCatalogSlogan }}</p>
        </div>
        <div v-if="detailCatalogTags.length" class="pd-hero-tags">
          <span
            v-for="tag in detailCatalogTags"
            :key="tag"
            class="pd-hero-tag"
            >{{ tag }}</span
          >
        </div>
      </section>

      <!-- 主体：左配置 + 右摘要 -->
      <div class="pd-body">
        <div class="pd-config">
          <!-- 区域 -->
          <div class="cfg-group" v-if="regionOptions.length">
            <div class="cfg-group-head">区域</div>
            <div class="cfg-group-body">
              <div class="opt-wrap">
                <button
                  v-for="opt in regionOptions"
                  :key="opt.id"
                  type="button"
                  class="opt-btn"
                  :class="{ active: configForm[regionKey] === opt.id }"
                  @click="
                    configForm[regionKey] = opt.id;
                    fetchQuote();
                  "
                >
                  {{ opt.label }}
                </button>
              </div>
            </div>
          </div>

          <!-- 操作系统 -->
          <div class="cfg-group" v-if="osConfig">
            <div class="cfg-group-head">操作系统</div>
            <div class="cfg-group-body">
              <div class="os-row">
                <div class="os-col">
                  <div class="os-col-label">系统</div>
                  <el-select
                    v-model="configForm.os_group"
                    placeholder="请选择系统"
                    @change="handleOsGroupChange"
                  >
                    <el-option
                      v-for="os in osGroups"
                      :key="os.id"
                      :label="os.label"
                      :value="os.id"
                    />
                  </el-select>
                </div>
                <div class="os-col">
                  <div class="os-col-label">版本</div>
                  <el-select
                    v-model="configForm.os"
                    placeholder="请选择版本"
                    :disabled="!currentOsGroup?.versions?.length"
                    @change="fetchQuote"
                  >
                    <el-option
                      v-for="ver in currentOsGroup?.versions"
                      :key="ver.id"
                      :label="ver.label"
                      :value="ver.id"
                    />
                  </el-select>
                </div>
              </div>
            </div>
          </div>

          <!-- 机型配置 -->
          <div class="cfg-group" v-if="specConfigs.length">
            <div class="cfg-group-head">机型配置</div>
            <div class="cfg-group-body">
              <div class="spec-row" v-for="cfg in specConfigs" :key="cfg.key">
                <div class="spec-label">{{ cfg.label }}</div>
                <div class="spec-ctrl">
                  <template v-if="cfg.isNumber">
                    <el-input-number
                      v-model="configForm[cfg.key + '_num']"
                      :min="cfg.min ?? 1"
                      :max="cfg.max ?? undefined"
                      controls-position="right"
                      @change="fetchQuote"
                    />
                  </template>
                  <div class="opt-wrap" v-else-if="cfg.options.length > 1">
                    <button
                      v-for="opt in cfg.options"
                      :key="opt.id"
                      type="button"
                      class="opt-btn"
                      :class="{ active: configForm[cfg.key] === opt.id }"
                      @click="
                        configForm[cfg.key] = opt.id;
                        fetchQuote();
                      "
                    >
                      {{ opt.label }}
                    </button>
                  </div>
                  <button
                    v-else-if="cfg.options.length === 1"
                    type="button"
                    class="opt-btn active"
                  >
                    {{ cfg.options[0].label }}
                  </button>
                </div>
              </div>
            </div>
          </div>

          <!-- 网络配置 -->
          <div class="cfg-group" v-if="netConfigs.length">
            <div class="cfg-group-head">网络配置</div>
            <div class="cfg-group-body">
              <div class="spec-row" v-for="cfg in netConfigs" :key="cfg.key">
                <div class="spec-label">{{ cfg.label }}</div>
                <div class="spec-ctrl">
                  <template v-if="cfg.isNumber">
                    <el-input-number
                      v-model="configForm[cfg.key + '_num']"
                      :min="cfg.min ?? 1"
                      :max="cfg.max ?? undefined"
                      controls-position="right"
                      @change="fetchQuote"
                    />
                  </template>
                  <div class="opt-wrap" v-else-if="cfg.options.length > 1">
                    <button
                      v-for="opt in cfg.options"
                      :key="opt.id"
                      type="button"
                      class="opt-btn"
                      :class="{ active: configForm[cfg.key] === opt.id }"
                      @click="
                        configForm[cfg.key] = opt.id;
                        fetchQuote();
                      "
                    >
                      {{ opt.label }}
                    </button>
                  </div>
                  <button
                    v-else-if="cfg.options.length === 1"
                    type="button"
                    class="opt-btn active"
                  >
                    {{ cfg.options[0].label }}
                  </button>
                </div>
              </div>
            </div>
          </div>

          <!-- 基础设置 -->
          <div class="cfg-group">
            <div class="cfg-group-head">基础设置</div>
            <div class="cfg-group-body">
              <!-- 计费周期 -->
              <div class="spec-row">
                <div class="spec-label">计费周期</div>
                <div class="spec-ctrl">
                  <div class="cycle-wrap">
                    <button
                      v-for="item in pricingEntries"
                      :key="item.cycle"
                      type="button"
                      class="cycle-btn"
                      :class="{ active: selectedCycle === item.cycle }"
                      @click="selectedCycle = item.cycle"
                    >
                      <span class="cycle-name">{{ item.label }}</span>
                      <span class="cycle-price">¥{{ item.amount }}</span>
                    </button>
                  </div>
                </div>
              </div>
              <!-- 单笔订单仅支持创建一台服务实例，避免多台计价但仅开通一台。 -->
              <div class="spec-row spec-row--last">
                <div class="spec-label">购买数量</div>
                <div class="spec-ctrl">
                  <span>1 台（多台请分次下单）</span>
                </div>
              </div>
            </div>
          </div>
        </div>
        <!-- /pd-config -->

        <!-- 右侧：配置费用摘要 -->
        <aside class="pd-aside">
          <div class="aside-card">
            <div class="cost-header">
              <span class="cost-title">配置费用</span>
              <span class="stock-badge" :class="stockClass">{{
                stockLabel
              }}</span>
            </div>

            <div :class="['stock-info', `stock-info--${stockClass}`]">
              <div
                v-if="resolvedStock !== null && resolvedStock !== -1"
                class="stock-main"
              >
                当前库存&nbsp;&nbsp;
                <strong>剩余 {{ resolvedStock }} 台</strong>
              </div>
              <div v-else-if="productStockLoading" class="stock-main">
                当前库存&nbsp;&nbsp;<strong>同步中...</strong>
              </div>
              <div v-else-if="productStockError" class="stock-main">
                当前库存&nbsp;&nbsp;<strong>同步失败</strong>
              </div>
              <div v-else class="stock-main">
                当前库存&nbsp;&nbsp;<strong>可直接购买</strong>
              </div>
              <div class="stock-hint">{{ stockHint }}</div>
            </div>

            <el-alert
              v-if="purchaseRequirementList.length"
              type="warning"
              :closable="false"
              show-icon
              class="purchase-guard-alert"
            >
              <template #title
                >购买要求：{{ purchaseRequirementSummary }}</template
              >
            </el-alert>

            <div class="aside-divider"></div>

            <section class="aside-section">
              <div class="aside-section-title">配置摘要</div>
              <div
                class="cost-detail"
                :class="{ 'cost-detail--loading': quoteLoading }"
              >
                <div class="cost-item">
                  <span>产品</span>
                  <span>{{ selectedMachineSpec.displayName }}</span>
                </div>
                <div
                  class="cost-item"
                  v-for="item in summaryItems"
                  :key="item.key"
                >
                  <span>{{ item.label }}</span>
                  <span>{{ item.value }}</span>
                </div>
              </div>
            </section>

            <div class="aside-divider"></div>

            <section class="aside-section">
              <div class="aside-section-title">费用明细</div>
              <div
                class="cost-breakdown"
                :class="{ 'cost-breakdown--loading': quoteLoading }"
              >
                <div class="cost-item">
                  <span>基础价格</span>
                  <span>¥{{ baseAmount }}</span>
                </div>
                <div class="cost-item" v-if="Number(setupFee) > 0">
                  <span>开通费</span>
                  <span>¥{{ setupFee }}</span>
                </div>
                <div
                  class="cost-item cost-item--extra"
                  v-for="item in quoteItems"
                  :key="item.field"
                >
                  <span>+ {{ item.label }}</span>
                  <span>¥{{ item.amount }}</span>
                </div>
                <div class="cost-item cost-item--discount" v-if="appliedCoupon">
                  <span
                    >优惠券 {{ appliedCoupon.code || appliedCoupon.name }}</span
                  >
                  <span>-¥{{ appliedCoupon.discount_amount }}</span>
                </div>
              </div>
            </section>

            <div class="coupon-panel">
              <div class="coupon-panel-head">
                <span class="coupon-panel-title">优惠券</span>
                <button
                  v-if="appliedCoupon"
                  type="button"
                  class="coupon-clear-btn"
                  @click="clearCoupon"
                >
                  移除
                </button>
              </div>
              <div class="coupon-panel-form">
                <el-select
                  :model-value="selectedCouponId || undefined"
                  clearable
                  placeholder="请选择优惠券"
                  @change="handleCouponChange"
                >
                  <el-option
                    v-for="item in availableCoupons"
                    :key="item.id"
                    :label="`${item.name} · ${item.discount_label}`"
                    :value="item.id"
                  />
                </el-select>
              </div>
              <div v-if="appliedCoupon" class="coupon-panel-tip">
                {{ appliedCoupon.name }}，{{
                  appliedCoupon.discount_label
                }}，本次已减免 ¥{{ appliedCoupon.discount_amount }}
              </div>
              <div
                v-else-if="!availableCoupons.length"
                class="coupon-panel-tip coupon-panel-tip--muted"
              >
                {{
                  product
                    ? "当前暂无可用优惠券，登录后如有优惠券会自动展示在这里。"
                    : "请选择商品后查看可用优惠券。"
                }}
              </div>
            </div>

            <div class="aside-divider"></div>

            <div
              class="cost-total"
              :class="{ 'cost-total--loading': quoteLoading }"
            >
              <span class="cost-total-label">合计费用</span>
              <div class="cost-price-wrap">
                <span class="cost-currency">¥</span>
                <span
                  v-if="quoteLoading"
                  class="cost-amount cost-amount--loading"
                  >计算中</span
                >
                <span v-else class="cost-amount">{{ totalAmount }}</span>
                <span class="cost-cycle"
                  >/{{ selectedCycleLabel || "月付" }}</span
                >
              </div>
            </div>

            <button
              class="buy-btn"
              :disabled="!canSubmit || submitting || quoteLoading"
              :class="{ loading: submitting, 'is-sold-out': soldOut }"
              @click="submitOrder"
            >
              <span>{{ submitButtonText }}</span>
            </button>
          </div>
        </aside>
      </div>
      <!-- /pd-body -->

      <div class="allocation-footer">
        <div class="allocation-footer-inner">
          <div class="allocation-footer-main">
            <div class="allocation-footer-label">合计费用</div>
            <div class="allocation-footer-price">
              <span class="allocation-footer-symbol">¥</span>
              <span class="allocation-footer-num" v-if="quoteLoading">…</span>
              <span class="allocation-footer-num" v-else>{{
                totalAmount
              }}</span>
              <span class="allocation-footer-cycle"
                >/{{ selectedCycleLabel || "月付" }}</span
              >
            </div>
            <div class="allocation-footer-meta">
              <span v-if="selectedCycleLabel">{{ selectedCycleLabel }}</span>
              <span v-if="regionOptions.length && configForm[regionKey]">
                {{
                  regionOptions.find((o) => o.id === configForm[regionKey])
                    ?.label || configForm[regionKey]
                }}
              </span>
              <span v-if="currentOsVerLabel">{{ currentOsVerLabel }}</span>
            </div>
          </div>
          <button
            class="allocation-footer-action"
            :disabled="!canSubmit || submitting || quoteLoading"
            :class="{ loading: submitting, 'is-sold-out': soldOut }"
            @click="submitOrder"
          >
            <span>{{ submitButtonText }}</span>
          </button>
        </div>
      </div>
    </div>

    <div class="pd-empty" v-else-if="!loading">
      <el-empty description="商品不存在或已下架">
        <el-button type="primary" @click="router.push('/products')"
          >返回产品页</el-button
        >
      </el-empty>
    </div>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import { ElMessage } from "element-plus/es/components/message/index.mjs";
import siteApi from "@/api/site";
import {
  resolvePurchaseRequirementList,
  resolvePurchaseRequirementSummary,
} from "@/utils/productPurchaseRequirements";
import {
  buildWebsiteProductPath,
  resolveWebsiteProductRoutePayloadByDetail,
} from "@/utils/productRoute";
import {
  normalizeMoneyText,
  resolveProductDisplayName,
} from "@/utils/websiteProductConfig";
import { navigateToConsole } from "@/utils/consoleUrl";
import {
  buildIdempotencyKey,
  encodePendingWebsiteCheckout,
  savePendingWebsiteCheckout,
} from "@/utils/websiteCheckout";
import { buildPendingCouponRedirectUrl } from "@/utils/websiteCoupon";
import {
  isCpuConfigKey,
  isMemoryConfigKey,
  resolveMachineSpecPresentation,
} from "@/domains/products/machineSpecResolver";
import { useWebsiteProductConfigurator } from "@/domains/products/useWebsiteProductConfigurator";

const route = useRoute();
const router = useRouter();

const loading = ref(false);
const submitting = ref(false);
const product = ref(null);
const siblings = ref([]);
const selectedCycle = ref("");
const hostname = ref("");
const password = ref("");
const quantity = ref(1);
const selectedCouponId = ref(0);

const productDetailRef = computed(() => product.value);
const configurator = useWebsiteProductConfigurator(productDetailRef);
const configForm = configurator.configForm;
const quoteResult = ref(null);
const quoteLoading = ref(false);
const quoteToken = ref("");
const productStock = ref(null);
const productStockLoading = ref(false);
const productStockError = ref("");

// 网络关键词（带宽/IP/流量/防护等）
const NET_KEYWORDS = [
  "bandwidth",
  "bw",
  "ip",
  "traffic",
  "ddos",
  "port",
  "speed",
  "mbps",
  "gbps",
  "带宽",
  "流量",
];

function isNetConfig(key, label) {
  const k = key.toLowerCase();
  const l = String(label || "").toLowerCase();
  return NET_KEYWORDS.some((kw) => k.includes(kw) || l.includes(kw));
}
const regionKey = computed(() => configurator.regionFieldKey.value || "region");
const regionOptions = computed(() => configurator.regionOptions.value || []);
const osConfig = computed(() => configurator.osConfig.value || null);
const osGroups = computed(() => configurator.osGroups.value || []);
const currentOsGroup = computed(
  () => configurator.currentOsGroup.value || null,
);
const currentOsVerLabel = computed(
  () => configurator.currentOsVersionLabel.value || "",
);
const selectedMachineSpec = computed(() =>
  resolveMachineSpecPresentation(product.value, {
    cpu: configForm.cpu,
    memory: configForm.memory,
  }),
);

// 同级 Tab 的规格展示名缓存：仅 siblings 变化时重算，避免每次渲染对每个同级重复正则解析
const siblingDisplayNames = computed(() => {
  const map = new Map();
  (siblings.value || []).forEach((sib) => {
    const id = Number(sib?.id || 0);
    if (id > 0) {
      map.set(id, resolveMachineSpecPresentation(sib).displayName);
    }
  });
  return map;
});

function selectOsGroup(os) {
  configForm.os_group = os.id;
  if (os.versions?.length) configForm.os = os.versions[0].id;
  fetchQuote();
}

function handleOsGroupChange(value) {
  const nextGroup = osGroups.value.find((item) => item.id === value);
  if (!nextGroup) {
    configForm.os = "";
    fetchQuote();
    return;
  }

  selectOsGroup(nextGroup);
}

// 机型配置（排除 区域/OS/网络）
const specConfigs = computed(() =>
  configurator.machineConfigs.value.filter(
    (cfg) => !isNetConfig(cfg.key, cfg.label),
  ),
);

// 网络配置
const netConfigs = computed(() =>
  [
    ...configurator.networkConfigs.value,
    ...configurator.otherConfigs.value,
  ].filter((cfg) => isNetConfig(cfg.key, cfg.label)),
);

function isSpecSummaryConfig(cfg) {
  return (
    isCpuConfigKey(cfg.key, cfg.label) || isMemoryConfigKey(cfg.key, cfg.label)
  );
}

function resolveSummaryConfigValue(cfg) {
  if (cfg.isNumber) {
    const value = configForm[cfg.key + "_num"];
    return value !== undefined && value !== null && value !== ""
      ? String(value)
      : "";
  }

  const value = configForm[cfg.key];
  if (value === undefined || value === null || value === "") {
    return "";
  }

  return cfg.options.find((item) => item.id === value)?.label || String(value);
}

// 价格
const pricingEntries = computed(() => configurator.pricingEntries.value || []);
const detailGroup = computed(() => product.value?.group || {});
const detailCatalogTitle = computed(
  () =>
    selectedMachineSpec.value.displayName ||
    resolveProductDisplayName(product.value),
);
const detailCatalogSlogan = computed(() =>
  String(
    detailGroup.value.slogan || detailGroup.value.parent_slogan || "",
  ).trim(),
);
const detailCatalogEyebrow = computed(() => {
  const parentTitle = String(detailGroup.value.parent_name || "").trim();
  if (parentTitle && parentTitle !== detailCatalogTitle.value) {
    return parentTitle;
  }

  return String(product.value?.type_label || "").trim();
});
const detailCatalogTags = computed(() => {
  const tags = [];
  const fullName = String(detailGroup.value.full_name || "").trim();
  const typeLabel = String(product.value?.type_label || "").trim();

  if (fullName) {
    tags.push(fullName);
  }

  if (typeLabel && !tags.includes(typeLabel)) {
    tags.push(typeLabel);
  }

  return tags;
});
const showCatalogHero = computed(() =>
  Boolean(
    detailCatalogTitle.value ||
    detailCatalogSlogan.value ||
    detailCatalogEyebrow.value,
  ),
);

const selectedPricingEntry = computed(
  () =>
    pricingEntries.value.find((i) => i.cycle === selectedCycle.value) || null,
);
const selectedCycleLabel = computed(
  () => selectedPricingEntry.value?.label || "",
);
const baseAmount = computed(
  () =>
    quoteResult.value?.base_amount ??
    selectedPricingEntry.value?.amount ??
    "0.00",
);
const setupFee = computed(
  () =>
    quoteResult.value?.setup_fee ??
    product.value?.setup_fee_display ??
    normalizeMoneyText(product.value?.setup_fee || 0),
);
const quoteItems = computed(() => quoteResult.value?.items || []);
const appliedCoupon = computed(() => quoteResult.value?.coupon || null);
const availableCoupons = computed(
  () => quoteResult.value?.available_coupons || [],
);
const totalAmount = computed(() => {
  if (quoteResult.value) return quoteResult.value.total_amount || "0.00";
  return (
    Number(selectedPricingEntry.value?.total_amount || 0) * quantity.value
  ).toFixed(2);
});
const summaryItems = computed(() => {
  const items = [];

  if (regionOptions.value.length && configForm[regionKey.value]) {
    items.push({
      key: "region",
      label: "区域",
      value:
        regionOptions.value.find(
          (item) => item.id === configForm[regionKey.value],
        )?.label || configForm[regionKey.value],
    });
  }

  if (currentOsVerLabel.value) {
    items.push({
      key: "os",
      label: "系统",
      value: currentOsVerLabel.value,
    });
  }

  if (selectedCycleLabel.value) {
    items.push({
      key: "billing_cycle",
      label: "周期",
      value: selectedCycleLabel.value,
    });
  }

  specConfigs.value.forEach((cfg) => {
    if (isSpecSummaryConfig(cfg)) {
      return;
    }

    const value = resolveSummaryConfigValue(cfg);
    if (!value) {
      return;
    }

    items.push({
      key: `spec-${cfg.key}`,
      label: cfg.label,
      value,
    });
  });

  netConfigs.value.forEach((cfg) => {
    const value = resolveSummaryConfigValue(cfg);
    if (!value) {
      return;
    }

    items.push({
      key: `net-${cfg.key}`,
      label: cfg.label,
      value,
    });
  });

  return items;
});
const purchaseRequirementList = computed(() =>
  resolvePurchaseRequirementList(product.value),
);
const purchaseRequirementSummary = computed(() =>
  resolvePurchaseRequirementSummary(product.value),
);

let quoteTimer = null;
let quoteAbortController = null;
let quoteExecuteToken = 0;
let productLoadToken = 0;

function createQuoteSignal() {
  // 新报价请求发出前取消上一次在途请求，避免卸载后旧响应回写组件状态
  quoteAbortController?.abort();
  quoteAbortController = new AbortController();
  return quoteAbortController.signal;
}

function applyQuoteResult(payload, nextCouponId = selectedCouponId.value) {
  quoteResult.value = payload || null;
  quoteToken.value = String(payload?.quote_token || "");
  selectedCouponId.value = Number(nextCouponId || payload?.user_coupon_id || 0);
}

async function requestQuote(nextCouponId = selectedCouponId.value) {
  return siteApi.productQuote(
    product.value.id,
    {
      billing_cycle: selectedCycle.value,
      config: buildConfigPayload(),
      quantity: quantity.value,
      user_coupon_id: Number(nextCouponId || 0) || undefined,
    },
    { signal: createQuoteSignal() },
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
  if (!product.value || !selectedCycle.value) return;

  const token = ++quoteExecuteToken;

  const snapshot = options.rollbackOnError
    ? {
        quoteResult: quoteResult.value,
        quoteToken: quoteToken.value,
        selectedCouponId: selectedCouponId.value,
      }
    : null;

  quoteLoading.value = true;

  try {
    const res = await requestQuote(nextCouponId);
    // 已被更新的报价请求取代：不写状态，新请求会接管
    if (token !== quoteExecuteToken) return true;
    applyQuoteResult(res.data || null, nextCouponId);
    return true;
  } catch (error) {
    if (token !== quoteExecuteToken) return false;

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

      try {
        const fallbackRes = await requestQuote(0);
        if (token !== quoteExecuteToken) return false;
        applyQuoteResult(fallbackRes.data || null, 0);
        return false;
      } catch {
        quoteResult.value = null;
        quoteToken.value = "";
        return false;
      }
    }

    quoteResult.value = null;
    quoteToken.value = "";
    return false;
  } finally {
    if (token === quoteExecuteToken) {
      quoteLoading.value = false;
    }
  }
}

function handleCouponChange(value) {
  if (!product.value || !selectedCycle.value) {
    selectedCouponId.value = Number(value || 0);
    return;
  }

  selectedCouponId.value = Number(value || 0);
  fetchQuote();
}

async function clearCoupon() {
  selectedCouponId.value = 0;
  await executeQuote(0, { fallbackInvalidCoupon: false });
}

function fetchQuote() {
  clearTimeout(quoteTimer);
  quoteTimer = setTimeout(() => {
    executeQuote(selectedCouponId.value, { fallbackInvalidCoupon: true });
  }, 300);
}

function buildConfigPayload() {
  return configurator.buildConfigPayload();
}

const defaultHostname = computed(
  () => `svr${Math.floor(Math.random() * 9e8 + 1e8)}`,
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
const soldOut = computed(
  () =>
    resolvedStock.value !== null &&
    !productStockLoading.value &&
    !productStockError.value &&
    resolvedStock.value === 0,
);
const canSubmit = computed(() => {
  const stock = resolvedStock.value;
  return (
    Boolean(selectedCycle.value) &&
    Boolean(quoteToken.value) &&
    !quoteLoading.value &&
    !productStockLoading.value &&
    !productStockError.value &&
    stock !== null &&
    stock !== 0
  );
});
const submitButtonText = computed(() => {
  if (submitting.value) return "提交中...";
  if (soldOut.value) return "已售罄";
  return "立即购买";
});

function buildOrderPayload() {
  const payload = {
    product_id: Number(product.value?.id || 0),
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
    source: "website-product-detail",
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

  submitting.value = true;
  try {
    const orderPayload = buildOrderPayload();
    const idempotencyKey = buildIdempotencyKey("website-order");

    redirectToConsoleCheckout(orderPayload, idempotencyKey);
  } catch (err) {
    ElMessage.error(err?.response?.data?.message || "跳转控制台失败，请重试");
  } finally {
    submitting.value = false;
  }
}

function switchProduct(id) {
  const routePayload = resolveWebsiteProductRoutePayloadByDetail(product.value);
  router.replace(
    buildWebsiteProductPath({
      ...routePayload,
      productId: Number(id || 0),
    }),
  );
}

function initDefaults() {
  configurator.initProductDefaults({
    selectedCycleRef: selectedCycle,
    quantityRef: quantity,
    resetQuoteState: () => {
      quoteResult.value = null;
      quoteToken.value = "";
    },
  });

  hostname.value = defaultHostname.value;
  password.value = "";
  fetchQuote();
}

async function loadProduct() {
  const pid = Number(route.params.id || 0);
  if (!pid) {
    router.push("/products");
    return;
  }
  const token = ++productLoadToken;
  productStock.value = null;
  productStockError.value = "";
  refreshProductStock(pid);
  loading.value = true;
  try {
    const res = await siteApi.product(pid);
    if (token !== productLoadToken) return;
    product.value = res.data.product || null;
    siblings.value = res.data.product?.siblings || [];
    initDefaults();
  } catch {
    if (token !== productLoadToken) return;
    product.value = null;
  } finally {
    if (token === productLoadToken) {
      loading.value = false;
    }
  }
}

async function refreshProductStock(id) {
  if (!id) return;

  productStockLoading.value = true;
  productStockError.value = "";
  productStock.value = null;

  try {
    const res = await siteApi.productStock(id);
    if (Number(route.params.id || 0) !== id) return;
    productStock.value = Number(res.data?.stock ?? 0);
  } catch (err) {
    if (Number(route.params.id || 0) !== id) return;
    productStockError.value = err?.response?.data?.message || "库存同步失败";
  } finally {
    if (Number(route.params.id || 0) === id) {
      productStockLoading.value = false;
    }
  }
}

watch(
  () => route.params.id,
  (v) => {
    if (v) loadProduct();
  },
);
watch(selectedCycle, fetchQuote);
watch(configForm, fetchQuote, { deep: true });
watch(quantity, fetchQuote);
watch(
  () => route.query.user_coupon_id,
  (value) => {
    const nextCouponId = Number(value || 0);

    if (nextCouponId === selectedCouponId.value) {
      return;
    }

    selectedCouponId.value = nextCouponId;

    if (product.value && selectedCycle.value) {
      fetchQuote();
    }
  },
  { immediate: true },
);
onMounted(loadProduct);

onBeforeUnmount(() => {
  // 清理报价防抖定时器与在途请求，避免卸载后仍发请求/写已卸载组件状态
  clearTimeout(quoteTimer);
  quoteAbortController?.abort();
  // 使仍在途的 loadProduct 响应失效
  productLoadToken += 1;
});
</script>

<style scoped lang="scss">
/* 目标页精确色值：
   主色   #165dff (rgb(22,93,255))
   激活bg #e8f1ff (rgb(232,241,255))
   页面bg #f7f8fa (rgb(247,248,250))
   卡片bg #fff
   边框   #e8e8e8 / #d0d3d9
   文字主 #1d2129
   文字辅 #4e5969
   文字淡 #86909c
*/

.pd-page {
  min-height: calc(100vh - 160px);
  background: #f7f8fa;
  padding-bottom: 40px;

  @media (max-width: 768px) {
    padding-bottom: 108px;
  }
}

/* ===== 顶部工具栏 ===== */
.pd-topbar {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 12px 16px 0;
  overflow: hidden;
}

.category-btn {
  flex-shrink: 0;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  height: 34px;
  padding: 0 12px;
  border: 1px solid #d0d3d9;
  border-radius: 4px;
  background: #fff;
  color: #4e5969;
  font-size: 13px;
  cursor: pointer;
  white-space: nowrap;
  transition:
    border-color 0.15s,
    color 0.15s;

  &:hover {
    border-color: #165dff;
    color: #165dff;
  }

  svg {
    color: #86909c;
  }
}

.sibling-scroll {
  display: flex;
  gap: 8px;
  overflow-x: auto;
  scrollbar-width: none;
  &::-webkit-scrollbar {
    display: none;
  }
}

.sib-tab {
  flex-shrink: 0;
  height: 34px;
  padding: 0 14px;
  border: 1px solid #d0d3d9;
  border-radius: 4px;
  background: #fff;
  color: #4e5969;
  font-size: 13px;
  cursor: pointer;
  white-space: nowrap;
  transition: all 0.15s;

  &:hover {
    border-color: #165dff;
    color: #165dff;
  }
  &.active {
    border-color: #165dff;
    background: #165dff;
    color: #fff;
    font-weight: 600;
  }
}

/* ===== 产品名称栏 ===== */
.pd-name-bar {
  padding: 10px 16px 0;
}

.pd-hero {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  gap: 20px;
  margin: 12px 16px 0;
  padding: 18px 20px;
  border: 1px solid #e8e8e8;
  border-radius: 4px;
  background: linear-gradient(135deg, #ffffff 0%, #f5f9ff 100%);
}

.pd-hero-copy {
  min-width: 0;
}

.pd-hero-eyebrow {
  display: inline-flex;
  align-items: center;
  min-height: 24px;
  padding: 0 10px;
  border-radius: 999px;
  background: #e8f1ff;
  color: #165dff;
  font-size: 12px;
  font-weight: 600;
}

.pd-hero-copy h1 {
  margin: 10px 0 0;
  color: #1d2129;
  font-size: 26px;
  line-height: 1.2;
}

.pd-hero-copy p {
  margin: 8px 0 0;
  color: #4e5969;
  font-size: 14px;
  line-height: 1.7;
}

.pd-hero-tags {
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: 8px;
}

.pd-hero-tag {
  display: inline-flex;
  align-items: center;
  min-height: 32px;
  padding: 0 12px;
  border: 1px solid #d0d3d9;
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.9);
  color: #4e5969;
  font-size: 12px;
  white-space: nowrap;
}

.product-name-btn {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  height: 36px;
  padding: 0 14px;
  border: 1px solid #d0d3d9;
  border-radius: 4px;
  background: #fff;
  color: #1d2129;
  font-size: 13px;
  font-weight: 500;
  cursor: pointer;
  max-width: 100%;

  span {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  svg {
    flex-shrink: 0;
    color: #86909c;
  }

  &.active {
    border-color: #165dff;
    color: #165dff;
    svg {
      color: #165dff;
    }
  }
}

/* ===== 主体 ===== */
.pd-body {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 268px;
  gap: 12px;
  padding: 12px 16px 0;
  align-items: start;

  @media (max-width: 768px) {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 768px) {
  .pd-hero {
    flex-direction: column;
    align-items: flex-start;
    margin-top: 10px;
    padding: 16px;
  }

  .pd-hero-copy h1 {
    font-size: 22px;
  }

  .pd-hero-tags {
    justify-content: flex-start;
  }
}

/* ===== 配置分组卡片 ===== */
.cfg-group {
  background: #fff;
  border-radius: 4px;
  margin-bottom: 10px;
  border: 1px solid #e8e8e8;
}

.cfg-group-head {
  padding: 12px 16px 10px;
  font-size: 14px;
  font-weight: 600;
  color: #1d2129;
  border-bottom: 1px solid #f2f3f5;
}

.cfg-group-body {
  padding: 12px 16px;
}

/* ===== 选项按钮 ===== */
.opt-wrap {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.opt-btn {
  display: inline-flex;
  align-items: center;
  height: 34px;
  padding: 0 14px;
  border: 1px solid #d0d3d9;
  border-radius: 4px;
  background: #fafafa;
  color: #1d2129;
  font-size: 13px;
  cursor: pointer;
  white-space: nowrap;
  transition: all 0.15s;

  &:hover {
    border-color: #165dff;
    color: #165dff;
    background: #fff;
  }
  &.active {
    border-color: #165dff;
    background: #e8f1ff;
    color: #165dff;
    font-weight: 600;
  }
}

/* ===== 规格行 ===== */
.spec-row {
  display: flex;
  align-items: flex-start;
  gap: 16px;
  padding: 10px 0;
  border-bottom: 1px solid #f2f3f5;

  &:first-child {
    padding-top: 0;
  }
  &:last-child,
  &--last {
    border-bottom: none;
    padding-bottom: 0;
  }
}

.spec-label {
  flex-shrink: 0;
  width: 64px;
  padding-top: 8px;
  font-size: 13px;
  color: #86909c;
  line-height: 1.4;
}

.spec-ctrl {
  flex: 1;
  min-width: 0;
}

/* ===== 步进器 ===== */
.stepper {
  display: inline-flex;
  align-items: stretch;
  border: 1px solid #d0d3d9;
  border-radius: 4px;
  overflow: hidden;
  height: 34px;
}

.stepper-dec,
.stepper-inc {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 34px;
  border: none;
  background: #fafafa;
  color: #4e5969;
  cursor: pointer;
  transition: background 0.15s;
  flex-shrink: 0;

  &:hover:not(:disabled) {
    background: #e8f1ff;
    color: #165dff;
  }
  &:disabled {
    opacity: 0.38;
    cursor: not-allowed;
  }
}

.stepper-dec {
  border-right: 1px solid #d0d3d9;
}
.stepper-inc {
  border-left: 1px solid #d0d3d9;
}

.stepper-val {
  width: 52px;
  border: none;
  text-align: center;
  font-size: 14px;
  font-weight: 600;
  color: #1d2129;
  background: #fff;
  outline: none;

  &::-webkit-outer-spin-button,
  &::-webkit-inner-spin-button {
    -webkit-appearance: none;
  }
}

/* ===== 操作系统双列 ===== */
.os-row {
  display: flex;
  gap: 12px;
}

.os-col {
  flex: 1;
  min-width: 0;

  :deep(.el-select) {
    width: 100%;
  }
}

.os-col-label {
  font-size: 12px;
  color: #86909c;
  margin-bottom: 6px;
}

/* ===== 计费周期 ===== */
.cycle-wrap {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.cycle-btn {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 2px;
  padding: 8px 14px;
  border: 1px solid #d0d3d9;
  border-radius: 4px;
  background: #fafafa;
  cursor: pointer;
  transition: all 0.15s;

  &:hover {
    border-color: #165dff;
  }
  &.active {
    border-color: #165dff;
    background: #e8f1ff;

    .cycle-name {
      color: #165dff;
      font-weight: 600;
    }
    .cycle-price {
      color: #165dff;
    }
  }
}

.cycle-name {
  font-size: 13px;
  color: #1d2129;
  line-height: 1.4;
}

.cycle-price {
  font-size: 12px;
  color: #86909c;
  line-height: 1.4;
}

/* ===== 右侧摘要 ===== */
.pd-aside {
  position: sticky;
  top: 80px;

  @media (max-width: 768px) {
    position: static;
  }
}

.aside-card {
  background: #fff;
  border: 1px solid #e8e8e8;
  border-radius: 4px;
  padding: 16px;
}

.cost-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 12px;
}

.cost-title {
  font-size: 15px;
  font-weight: 700;
  color: #1d2129;
}

.stock-badge {
  font-size: 12px;
  font-weight: 600;
  padding: 2px 10px;
  border-radius: 2px;

  &.ok {
    background: $color-success-soft;
    color: $color-success;
  }
  &.warn {
    background: $color-warning-soft;
    color: $color-warning;
  }
  &.empty {
    background: $color-danger-soft;
    color: $color-danger;
  }
  &.sync {
    background: $color-primary-soft;
    color: $color-primary;
  }
}

.stock-info {
  padding: 10px 12px;
  border-radius: 2px;
  margin-bottom: 4px;

  &--ok {
    background: $color-success-soft;
  }

  &--warn {
    background: $color-warning-soft;
  }

  &--empty {
    background: $color-danger-soft;
  }

  &--sync {
    background: $color-primary-soft;
  }
}

.stock-main {
  font-size: 13px;
  color: $text-color-secondary;

  strong {
    font-size: 14px;
    font-weight: 700;
  }
}

.stock-info--ok .stock-main strong {
  color: $color-success;
}
.stock-info--warn .stock-main strong {
  color: $color-warning;
}
.stock-info--empty .stock-main strong {
  color: $color-danger;
}
.stock-info--sync .stock-main strong {
  color: $color-primary;
}

.stock-hint {
  font-size: 12px;
  color: $text-color-placeholder;
  margin-top: 4px;
}

.aside-divider {
  height: 1px;
  background: #f2f3f5;
  margin: 12px 0;
}

.aside-section {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.aside-section-title {
  font-size: 13px;
  font-weight: 700;
  color: #1d2129;
}

.cost-detail {
  display: flex;
  flex-direction: column;
  gap: 9px;
}

.cost-detail--loading,
.cost-breakdown--loading,
.cost-total--loading {
  position: relative;

  &::after {
    content: "";
    position: absolute;
    inset: -4px -6px;
    border-radius: 8px;
    background: linear-gradient(
      90deg,
      rgba(#fff, 0) 0%,
      rgba(#165dff, 0.08) 35%,
      rgba(#fff, 0.45) 50%,
      rgba(#165dff, 0.08) 65%,
      rgba(#fff, 0) 100%
    );
    background-size: 220% 100%;
    pointer-events: none;
    animation: costLoadingSweep 1.35s linear infinite;
  }
}

.cost-breakdown {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.cost-item {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  font-size: 13px;
  gap: 8px;

  > span:first-child {
    color: #86909c;
    flex-shrink: 0;
  }

  > span:last-child {
    color: #1d2129;
    font-weight: 500;
    text-align: right;
    word-break: break-all;
  }
}

.cost-item--extra {
  > span:first-child {
    color: #86909c;
    font-size: 12px;
  }

  > span:last-child {
    color: $color-warning;
    font-size: 12px;
  }
}

.cost-item--discount {
  > span:first-child {
    color: $color-success;
    font-size: 12px;
    font-weight: 600;
  }

  > span:last-child {
    color: $color-success;
    font-size: 13px;
    font-weight: 700;
  }
}

.coupon-panel {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.coupon-panel-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
}

.coupon-panel-title {
  font-size: 13px;
  font-weight: 700;
  color: #1d2129;
}

.coupon-clear-btn {
  border: none;
  background: none;
  padding: 0;
  color: $color-danger;
  font-size: 12px;
  cursor: pointer;
}

.coupon-panel-form {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: 8px;
}

.coupon-panel-tip {
  padding: 10px 12px;
  border-radius: 4px;
  background: $color-success-soft;
  color: $color-success;
  font-size: 12px;
  line-height: 1.6;
}

.coupon-panel-tip--muted {
  background: $bg-color-soft;
  color: $text-color-secondary;
}

.purchase-guard-alert {
  margin: 12px 0;
}

.cost-total {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 8px 0 16px;
  border-top: 1px solid #f2f3f5;
  margin-top: 8px;
}

.cost-total-label {
  font-size: 13px;
  color: #86909c;
}

.cost-price-wrap {
  display: flex;
  align-items: baseline;
  gap: 1px;
}

.cost-currency {
  font-size: 14px;
  color: #165dff;
  font-weight: 700;
}

.cost-amount {
  font-size: 26px;
  font-weight: 700;
  color: #165dff;
  line-height: 1;
}

.cost-amount--loading {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 18px;
  color: #86909c;

  &::after {
    content: "";
    width: 22px;
    height: 8px;
    border-radius: 999px;
    background:
      radial-gradient(circle, rgba(#165dff, 0.85) 0 2px, transparent 2.4px) left
        center / 8px 8px no-repeat,
      radial-gradient(circle, rgba(#165dff, 0.55) 0 2px, transparent 2.4px)
        center center / 8px 8px no-repeat,
      radial-gradient(circle, rgba(#165dff, 0.3) 0 2px, transparent 2.4px) right
        center / 8px 8px no-repeat;
    animation: costLoadingDots 0.95s ease-in-out infinite;
  }
}

.cost-cycle {
  font-size: 12px;
  color: #86909c;
  margin-left: 2px;
}

@media (max-width: 768px) {
  .coupon-panel-form {
    grid-template-columns: 1fr;
  }
}

.buy-btn {
  width: 100%;
  height: 44px;
  border: none;
  border-radius: 4px;
  background: #165dff;
  color: #fff;
  font-size: 15px;
  font-weight: 700;
  cursor: pointer;
  letter-spacing: 0.04em;
  transition:
    background 0.15s,
    opacity 0.15s;

  &:hover:not(:disabled) {
    background: #0e4ee0;
  }
  &:disabled {
    opacity: 0.5;
    cursor: not-allowed;
  }
  &.loading {
    opacity: 0.7;
  }

  &.is-sold-out {
    background: linear-gradient(135deg, #b91c1c 0%, #ef4444 100%);
    box-shadow: 0 0 0 0 rgba(#ef4444, 0.34);
    animation: soldOutPulse 1.7s ease-in-out infinite;
  }

  &.is-sold-out:hover {
    background: linear-gradient(135deg, #b91c1c 0%, #ef4444 100%);
  }
}

.allocation-footer {
  display: none;

  @media (max-width: 768px) {
    position: fixed;
    left: 0;
    right: 0;
    bottom: 0;
    z-index: 30;
    display: block;
    padding: 10px 12px calc(10px + env(safe-area-inset-bottom, 0px));
    background: rgba(247, 248, 250, 0.94);
    backdrop-filter: blur(10px);
    border-top: 1px solid rgba(208, 211, 217, 0.78);
  }
}

.allocation-footer-inner {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 12px;
  border: 1px solid #e8e8e8;
  border-radius: 14px;
  background: rgba(255, 255, 255, 0.98);
  box-shadow: 0 10px 28px rgba(29, 33, 41, 0.12);
}

.allocation-footer-main {
  flex: 1;
  min-width: 0;
}

.allocation-footer-label {
  font-size: 11px;
  line-height: 1;
  color: #86909c;
}

.allocation-footer-price {
  display: flex;
  align-items: baseline;
  gap: 2px;
  margin-top: 6px;
  color: #165dff;
}

.allocation-footer-symbol {
  font-size: 14px;
  font-weight: 700;
}

.allocation-footer-num {
  font-size: 24px;
  line-height: 1;
  font-weight: 700;
}

.allocation-footer-cycle {
  font-size: 12px;
  color: #86909c;
}

.allocation-footer-meta {
  display: flex;
  flex-wrap: wrap;
  gap: 4px 8px;
  margin-top: 6px;
  font-size: 11px;
  line-height: 1.5;
  color: #4e5969;

  span {
    min-width: 0;
  }
}

.allocation-footer-action {
  flex-shrink: 0;
  width: 112px;
  height: 44px;
  border: none;
  border-radius: 10px;
  background: #165dff;
  color: #fff;
  font-size: 15px;
  font-weight: 600;
  cursor: pointer;
  transition:
    background 0.15s,
    opacity 0.15s;

  &:hover:not(:disabled) {
    background: #0e4ee0;
  }
  &:disabled {
    opacity: 0.5;
    cursor: not-allowed;
  }
  &.loading {
    opacity: 0.7;
  }

  &.is-sold-out {
    background: linear-gradient(135deg, #b91c1c 0%, #ef4444 100%);
    box-shadow: 0 0 0 0 rgba(#ef4444, 0.34);
    animation: soldOutPulse 1.7s ease-in-out infinite;
  }

  &.is-sold-out:hover {
    background: linear-gradient(135deg, #b91c1c 0%, #ef4444 100%);
  }
}

@keyframes costLoadingSweep {
  0% {
    background-position: 100% 0;
  }

  100% {
    background-position: -120% 0;
  }
}

@keyframes costLoadingDots {
  0%,
  100% {
    opacity: 0.8;
    transform: translateX(0);
  }

  50% {
    opacity: 1;
    transform: translateX(2px);
  }
}

@keyframes soldOutPulse {
  0%,
  100% {
    box-shadow: 0 0 0 0 rgba(#ef4444, 0.34);
  }

  50% {
    box-shadow: 0 0 0 6px rgba(#ef4444, 0);
  }
}

@media (max-width: 768px) {
  .pd-aside {
    position: static;
  }

  .aside-card {
    padding-bottom: 18px;
  }
}

/* ===== 空状态 ===== */
.pd-empty {
  display: flex;
  justify-content: center;
  align-items: center;
  min-height: 400px;
}
</style>
