<template>
  <!--
    整页遮罩只在「还没有商品可看」的首次加载时出现。
    后续任何重载（切换系统、切换付费周期等触发的状态刷新）都不能再盖住整页，
    否则每次改动都像整页重新刷了一下，配置项还会短暂不可点。
  -->
  <div
    class="pd-page"
    :class="{ 'pd-page--full': !isContained }"
    :style="{ '--pd-content-max': contentMaxWidthVar }"
    v-loading="loading && !product"
  >
    <div v-if="product">
      <!--
        顶部只留返回：机型切换在机型对比表（移动端在抽屉）里，
        此前这里还有一排同级 tab + 一条产品名下栏，与下方内容完全重复且占掉首屏。
      -->
      <div class="pd-topbar">
        <button type="button" class="back-btn" @click="goBack">
          <svg
            viewBox="0 0 16 16"
            fill="none"
            width="14"
            height="14"
            aria-hidden="true"
          >
            <path
              d="M10 3L5 8l5 5"
              stroke="currentColor"
              stroke-width="1.6"
              stroke-linecap="round"
              stroke-linejoin="round"
            />
          </svg>
          <span>{{ $t("product.detail.backToProducts") }}</span>
        </button>

        <!-- 配置页也能切列表式 / 卡片式：只改本机偏好，地址不变；
             切列表式后同一个商品地址由选购页内联渲染配置 -->
        <div class="pd-topbar-actions">
          <span class="pd-view-label">{{
            $t("product.list.displayMode")
          }}</span>
          <DisplayModeSwitch
            :model-value="detailDisplayMode"
            @update:model-value="handleDetailDisplayModeChange"
          />
        </div>
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

      <!--
        商品描述：卡片式点进来的落地页。与列表式规格表格同源（都来自
        ProductSpecHighlightService 的维度识别），商家手填时以手填为准。
        后端落库前已过富文本白名单消毒，这里按原样渲染。
      -->
      <section v-if="productDescription" class="pd-desc">
        <h2 class="pd-desc-title">{{ $t("product.detail.description") }}</h2>
        <!-- eslint-disable-next-line vue/no-v-html -- 描述入库前已过 RichHtmlSanitizer 白名单消毒 -->
        <div class="pd-desc-body" v-html="productDescription" />
      </section>

      <!--
        机型对比表：同组在售商品与列表式机型表同源
        （/v2/site/product-groups/{id}/products），独立配置页不再只有一排同级 tab，
        可以直接比较规格与价格后再切换机型。
      -->
      <section
        v-if="detailSpecCompare && !isMobile && skuRows.length > 1"
        class="pd-sku"
      >
        <div class="pd-sku-head">
          <h2 class="pd-sku-title">{{ $t("product.detail.skuCompare") }}</h2>
          <span class="pd-sku-hint">{{
            $t("product.detail.skuCompareHint")
          }}</span>
        </div>
        <div
          class="sku-table"
          role="table"
          :aria-label="$t('product.detail.skuCompare')"
        >
          <div class="sku-row sku-row--head" role="row">
            <span class="sku-cell sku-cell--name">{{
              $t("product.list.instanceSpecs")
            }}</span>
            <span class="sku-cell sku-cell--sortable">
              <span>vCPU</span>
              <span class="sku-sort">
                <button
                  type="button"
                  class="sku-sort-btn"
                  :class="{
                    active:
                      skuSort.key === 'cpu' && skuSort.direction === 'asc',
                  }"
                  :aria-label="$t('product.list.sortVcpuAsc')"
                  @click="setSkuSort('cpu', 'asc')"
                >
                  ▲
                </button>
                <button
                  type="button"
                  class="sku-sort-btn"
                  :class="{
                    active:
                      skuSort.key === 'cpu' && skuSort.direction === 'desc',
                  }"
                  :aria-label="$t('product.list.sortVcpuDesc')"
                  @click="setSkuSort('cpu', 'desc')"
                >
                  ▼
                </button>
              </span>
            </span>
            <span class="sku-cell sku-cell--sortable">
              <span>{{ $t("product.list.memory") }}</span>
              <span class="sku-sort">
                <button
                  type="button"
                  class="sku-sort-btn"
                  :class="{
                    active:
                      skuSort.key === 'memory' && skuSort.direction === 'asc',
                  }"
                  :aria-label="$t('product.list.sortMemoryAsc')"
                  @click="setSkuSort('memory', 'asc')"
                >
                  ▲
                </button>
                <button
                  type="button"
                  class="sku-sort-btn"
                  :class="{
                    active:
                      skuSort.key === 'memory' && skuSort.direction === 'desc',
                  }"
                  :aria-label="$t('product.list.sortMemoryDesc')"
                  @click="setSkuSort('memory', 'desc')"
                >
                  ▼
                </button>
              </span>
            </span>
            <span class="sku-cell">{{ $t("product.list.processor") }}</span>
            <span class="sku-cell">{{ $t("product.list.clockSpeed") }}</span>
            <span class="sku-cell sku-cell--sortable">
              <span>{{ $t("product.detail.skuPrice") }}</span>
              <span class="sku-sort">
                <button
                  type="button"
                  class="sku-sort-btn"
                  :class="{
                    active:
                      skuSort.key === 'price' && skuSort.direction === 'asc',
                  }"
                  :aria-label="$t('product.detail.sortPriceAsc')"
                  @click="setSkuSort('price', 'asc')"
                >
                  ▲
                </button>
                <button
                  type="button"
                  class="sku-sort-btn"
                  :class="{
                    active:
                      skuSort.key === 'price' && skuSort.direction === 'desc',
                  }"
                  :aria-label="$t('product.detail.sortPriceDesc')"
                  @click="setSkuSort('price', 'desc')"
                >
                  ▼
                </button>
              </span>
            </span>
          </div>
          <button
            v-for="row in skuRows"
            :key="row.id"
            type="button"
            class="sku-row"
            :class="{ active: row.id === product.id }"
            role="row"
            @click="switchProduct(row.id)"
          >
            <span class="sku-cell sku-cell--name">
              <span class="sku-radio" aria-hidden="true"></span>
              <span class="sku-name">{{ row.name }}</span>
            </span>
            <span class="sku-cell">{{ row.cpuText }}</span>
            <span class="sku-cell">{{ row.memoryText }}</span>
            <span class="sku-cell sku-cell--processor">
              <span
                v-if="row.brandBadge"
                class="cpu-brand-badge"
                :class="`cpu-brand-badge${row.brandBadge.className}`"
              >
                <span class="cpu-brand-badge__icon">{{
                  row.brandBadge.icon
                }}</span>
                <span class="cpu-brand-badge__label">{{
                  row.brandBadge.label
                }}</span>
              </span>
              <span class="sku-processor-text">{{
                row.processorLabel || "-"
              }}</span>
            </span>
            <span class="sku-cell">{{ row.frequency || "-" }}</span>
            <span class="sku-cell sku-cell--price">
              <template v-if="row.agentDiscount">
                <span class="agent-price-original"
                  >¥{{ row.agentOriginalPrice }}</span
                >
                <em v-if="row.agentDiscountPercent" class="discount-tag">{{
                  row.agentDiscountPercent
                }}</em>
                <span class="agent-price-current">¥{{ row.agentPrice }}</span>
              </template>
              <template v-else>{{ row.priceText }}</template>
            </span>
          </button>
        </div>
      </section>

      <!-- 主体：左配置 + 右摘要 -->
      <div class="pd-body">
        <div class="pd-config">
          <!-- 移动端隐藏右侧摘要后，购买前置要求仍要能看到 -->
          <el-alert
            v-if="isMobile && purchaseRequirementList.length"
            type="warning"
            :closable="false"
            show-icon
            class="purchase-guard-alert"
          >
            <template #title
              >{{ $t("product.list.purchaseRequirements")
              }}{{ purchaseRequirementSummary }}</template
            >
          </el-alert>

          <!-- 移动端：同级机型（SKU）收进抽屉，桌面端用下面的对比表；后台可整体关闭 -->
          <div
            class="cfg-group"
            v-if="detailSpecCompare && isMobile && skuOptions.length > 1"
          >
            <div class="cfg-group-head">{{ $t("product.list.spec") }}</div>
            <div class="cfg-group-body">
              <button
                type="button"
                class="mobile-picker-trigger"
                @click="mobileSkuPicker = true"
              >
                <span>{{ currentSkuLabel }}</span>
                <svg
                  viewBox="0 0 12 12"
                  fill="none"
                  width="12"
                  height="12"
                  aria-hidden="true"
                >
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
          </div>

          <!-- 区域：移动端改抽屉选择，避免一屏铺开一长串按钮 -->
          <div class="cfg-group" v-if="regionOptions.length">
            <div class="cfg-group-head">{{ $t("product.detail.region") }}</div>
            <div class="cfg-group-body">
              <button
                v-if="isMobile"
                type="button"
                class="mobile-picker-trigger"
                @click="mobileRegionPicker = true"
              >
                <span>{{
                  selectedRegionLabel || $t("product.detail.selectRegion")
                }}</span>
                <svg
                  viewBox="0 0 12 12"
                  fill="none"
                  width="12"
                  height="12"
                  aria-hidden="true"
                >
                  <path
                    d="M3 4.5l3 3 3-3"
                    stroke="currentColor"
                    stroke-width="1.5"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                  />
                </svg>
              </button>
              <div class="opt-wrap" v-else>
                <button
                  v-for="opt in regionOptions"
                  :key="opt.id"
                  type="button"
                  class="opt-btn"
                  :class="{ active: regionValue === opt.id }"
                  @click="selectRegion(opt.id)"
                >
                  {{ opt.label }}
                </button>
              </div>
            </div>
          </div>

          <!-- 操作系统：与列表式配置区一致的图标卡片 + 同屏版本选择 -->
          <div class="cfg-group" v-if="osConfig">
            <div class="cfg-group-head">
              {{ $t("product.detail.operatingSystem") }}
            </div>
            <div class="cfg-group-body">
              <!-- 移动端：系统与版本合并进一个抽屉，版本列表跟随所选系统 -->
              <button
                v-if="isMobile"
                type="button"
                class="mobile-picker-trigger"
                @click="mobileOsPicker = true"
              >
                <span>
                  {{ currentOsGroup?.label || $t("product.detail.selectOs")
                  }}{{ currentOsGroup?.label ? ` ${currentOsVerLabel}` : "" }}
                </span>
                <svg
                  viewBox="0 0 12 12"
                  fill="none"
                  width="12"
                  height="12"
                  aria-hidden="true"
                >
                  <path
                    d="M3 4.5l3 3 3-3"
                    stroke="currentColor"
                    stroke-width="1.5"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                  />
                </svg>
              </button>
              <template v-else>
                <div class="os-cards">
                  <button
                    v-for="os in osGroups"
                    :key="os.id"
                    type="button"
                    class="os-card"
                    :class="{ active: configForm.os_group === os.id }"
                    @click="handleOsGroupChange(os.id)"
                  >
                    <div class="os-card-head">
                      <img
                        v-if="os.icon"
                        :src="os.icon"
                        :alt="os.label"
                        class="os-logo-img"
                        width="28"
                        height="28"
                      />
                      <span v-else class="os-logo">{{
                        os.label.slice(0, 2)
                      }}</span>
                      <span class="os-label-name">{{ os.label }}</span>
                    </div>
                    <div class="os-card-ver">
                      <span v-if="configForm.os_group === os.id">{{
                        currentOsVerLabel
                      }}</span>
                      <span v-else class="os-placeholder">{{
                        $t("product.list.selectVersion")
                      }}</span>
                    </div>
                    <span
                      v-if="configForm.os_group === os.id"
                      class="os-check-mark"
                      >✓</span
                    >
                  </button>
                </div>
                <div class="os-ver-row" v-if="currentOsGroup?.versions?.length">
                  <span class="os-ver-label">{{
                    $t("product.osPicker.version")
                  }}</span>
                  <div class="os-ver-btns">
                    <button
                      v-for="ver in currentOsGroup.versions"
                      :key="ver.id"
                      type="button"
                      class="ver-btn"
                      :class="{ active: configForm.os === ver.id }"
                      @click="
                        configForm.os = ver.id;
                        fetchQuote();
                      "
                    >
                      {{ ver.label }}
                    </button>
                  </div>
                </div>
              </template>
            </div>
          </div>

          <!--
            配置分组：机型 / 网络 / 其他，口径与列表式配置区一致。
            分组由共享层（useWebsiteProductConfigurator）给出，这里不再做二次过滤——
            本地过滤曾把「其他配置」整组（绑定域名数 / WEB 空间 / 连接数等）与
            机型里命中网络词的项全部吃掉，虚拟主机与 CDN 商品几乎不剩配置项。
          -->
          <div
            class="cfg-group"
            v-for="group in configGroups"
            :key="group.title"
          >
            <div class="cfg-group-head">{{ group.title }}</div>
            <div class="cfg-group-body">
              <template v-for="cfg in group.configs" :key="cfg.key">
                <!-- 移动端：区间项用滑块，离散项收进抽屉，避免一屏几十个按钮 -->
                <template v-if="isMobile">
                  <MobileRangePicker
                    v-if="cfg.isNumber"
                    v-model="configForm[cfg.key + '_num']"
                    :label="cfg.label"
                    :min="cfg.min ?? 1"
                    :max="cfg.max ?? 9999"
                  />
                  <button
                    v-else-if="cfg.options.length > 1"
                    type="button"
                    class="mobile-config-select-row"
                    @click="openMobileConfigPicker(cfg)"
                  >
                    <span class="mobile-config-select-label">{{
                      cfg.label
                    }}</span>
                    <span class="mobile-config-select-value">
                      {{
                        selectedConfigLabel(cfg) ||
                        $t("product.list.selectOption")
                      }}
                      <svg
                        viewBox="0 0 12 12"
                        fill="none"
                        width="12"
                        height="12"
                        aria-hidden="true"
                      >
                        <path
                          d="M4.5 3l3 3-3 3"
                          stroke="currentColor"
                          stroke-width="1.5"
                          stroke-linecap="round"
                          stroke-linejoin="round"
                        />
                      </svg>
                    </span>
                  </button>
                  <div class="spec-row" v-else-if="cfg.options.length === 1">
                    <span class="spec-label">{{ cfg.label }}</span>
                    <span class="spec-fixed">{{ cfg.options[0].label }}</span>
                  </div>
                </template>
                <div class="spec-row" v-else>
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
                    <!-- 单选项是既成事实而不是可点控件：渲染成静态高亮，避免「看着能点、点了没反应」 -->
                    <span
                      v-else-if="cfg.options.length === 1"
                      class="spec-fixed"
                      >{{ cfg.options[0].label }}</span
                    >
                  </div>
                </div>
              </template>
            </div>
          </div>

          <!-- 购买须知：上游以普通配置项下发的文字提示（如「明确禁止」），只展示不参与选配 -->
          <div class="cfg-group cfg-group--notice" v-if="noticeConfigs.length">
            <div class="cfg-group-head">
              {{ $t("product.detail.purchaseNotes") }}
            </div>
            <div class="cfg-group-body">
              <div
                class="notice-item"
                v-for="cfg in noticeConfigs"
                :key="cfg.key"
              >
                <div class="notice-label" v-if="cfg.label">{{ cfg.label }}</div>
                <div class="notice-text">{{ cfg.textContent }}</div>
              </div>
            </div>
          </div>

          <!-- 基础设置 -->
          <div class="cfg-group">
            <div class="cfg-group-head">
              {{ $t("product.detail.baseSettings") }}
            </div>
            <div class="cfg-group-body">
              <!-- 计费周期 -->
              <div class="spec-row">
                <div class="spec-label">
                  {{ $t("product.detail.billingCycle") }}
                </div>
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
                <div class="spec-label">
                  {{ $t("product.detail.quantity") }}
                </div>
                <div class="spec-ctrl">
                  <span>{{ $t("product.detail.quantityHint") }}</span>
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
              <span class="cost-title">{{
                $t("product.detail.configFee")
              }}</span>
              <span class="stock-badge" :class="stockClass">{{
                stockLabel
              }}</span>
            </div>

            <div :class="['stock-info', `stock-info--${stockClass}`]">
              <div
                v-if="resolvedStock !== null && resolvedStock !== -1"
                class="stock-main"
              >
                {{ $t("product.list.stockLabel") }}&nbsp;&nbsp;
                <strong>{{
                  $t("product.list.stockLeft", { count: resolvedStock })
                }}</strong>
              </div>
              <div v-else-if="productStockLoading" class="stock-main">
                {{ $t("product.list.stockLabel") }}&nbsp;&nbsp;<strong>{{
                  $t("product.detail.syncing")
                }}</strong>
              </div>
              <div v-else-if="productStockError" class="stock-main">
                {{ $t("product.list.stockLabel") }}&nbsp;&nbsp;<strong>{{
                  $t("product.detail.syncFailed")
                }}</strong>
              </div>
              <div v-else class="stock-main">
                {{ $t("product.list.stockLabel") }}&nbsp;&nbsp;<strong>{{
                  $t("product.detail.readyToBuy")
                }}</strong>
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
                >{{ $t("product.list.purchaseRequirements")
                }}{{ purchaseRequirementSummary }}</template
              >
            </el-alert>

            <div class="aside-divider"></div>

            <section class="aside-section">
              <div class="aside-section-title">
                {{ $t("product.detail.summary") }}
              </div>
              <div
                class="cost-detail"
                :class="{ 'cost-detail--loading': quoteLoading }"
              >
                <div class="cost-item">
                  <span>{{ $t("layout.nav.products") }}</span>
                  <span>{{ selectedMachineSpec.displayName }}</span>
                </div>
                <div
                  class="cost-item"
                  v-if="detailGroup && detailGroup.full_name"
                >
                  <span>{{ $t("product.list.groupPath") }}</span>
                  <span>{{ detailGroup.full_name }}</span>
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
              <div class="aside-section-title">
                {{ $t("product.detail.costBreakdown") }}
              </div>
              <div
                class="cost-breakdown"
                :class="{ 'cost-breakdown--loading': quoteLoading }"
              >
                <div class="cost-item">
                  <span>{{ $t("product.detail.basePrice") }}</span>
                  <span>¥{{ baseAmount }}</span>
                </div>
                <div class="cost-item" v-if="Number(setupFee) > 0">
                  <span>{{ $t("product.detail.setupFee") }}</span>
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
                    >{{ $t("product.detail.coupon") }}
                    {{ appliedCoupon.code || appliedCoupon.name }}</span
                  >
                  <span>-¥{{ appliedCoupon.discount_amount }}</span>
                </div>
                <!-- 代理折扣行：没有这一行时，明细各项之和会大于合计，用户会以为算错账 -->
                <div
                  class="cost-item cost-item--discount"
                  v-if="hasAgentDiscount"
                >
                  <span>
                    {{
                      $t("product.list.agentDiscount", { name: agentGroupName })
                    }}
                    <em v-if="agentDiscountPercent" class="discount-tag">{{
                      agentDiscountPercent
                    }}</em>
                  </span>
                  <span>-¥{{ agentDiscountAmount }}</span>
                </div>
              </div>
            </section>

            <div class="coupon-panel">
              <div class="coupon-panel-head">
                <span class="coupon-panel-title">{{
                  $t("product.detail.coupon")
                }}</span>
                <button
                  v-if="appliedCoupon"
                  type="button"
                  class="coupon-clear-btn"
                  @click="clearCoupon"
                >
                  {{ $t("product.coupon.remove") }}
                </button>
              </div>
              <div class="coupon-panel-form">
                <el-select
                  :model-value="selectedCouponId || undefined"
                  clearable
                  :placeholder="$t('product.detail.selectCoupon')"
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
                {{
                  $t("product.coupon.appliedTip", {
                    name: appliedCoupon.name,
                    discount: appliedCoupon.discount_label,
                    amount: appliedCoupon.discount_amount,
                  })
                }}
              </div>
              <div
                v-else-if="!availableCoupons.length"
                class="coupon-panel-tip coupon-panel-tip--muted"
              >
                {{
                  product
                    ? $t("product.coupon.noneAvailable")
                    : $t("product.coupon.selectProductFirst")
                }}
              </div>
            </div>

            <div class="aside-divider"></div>

            <div
              class="cost-total"
              :class="{ 'cost-total--loading': quoteLoading }"
            >
              <span class="cost-total-label">{{
                $t("product.detail.totalCost")
              }}</span>
              <div class="cost-price-wrap">
                <template v-if="quoteLoading">
                  <span class="cost-currency">¥</span>
                  <span class="cost-amount cost-amount--loading">{{
                    $t("product.detail.calculating")
                  }}</span>
                </template>
                <!-- 命中代理折扣：原价划线 + 折扣标签 + 折后应付 -->
                <template v-else-if="hasAgentDiscount">
                  <span class="cost-amount cost-amount--original"
                    >¥{{ agentOriginalAmount }}</span
                  >
                  <em v-if="agentDiscountPercent" class="discount-tag">{{
                    agentDiscountPercent
                  }}</em>
                  <span class="cost-currency">¥</span>
                  <span class="cost-amount">{{ totalAmount }}</span>
                </template>
                <template v-else>
                  <span class="cost-currency">¥</span>
                  <span class="cost-amount">{{ totalAmount }}</span>
                </template>
                <span class="cost-cycle"
                  >/{{ selectedCycleLabel || $t("product.list.monthly") }}</span
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
            <div class="allocation-footer-label">
              {{ $t("product.detail.totalCost") }}
            </div>
            <div class="allocation-footer-price">
              <template v-if="quoteLoading">
                <span class="allocation-footer-symbol">¥</span>
                <span class="allocation-footer-num">…</span>
              </template>
              <template v-else-if="hasAgentDiscount">
                <span class="allocation-footer-original"
                  >¥{{ agentOriginalAmount }}</span
                >
                <em v-if="agentDiscountPercent" class="discount-tag">{{
                  agentDiscountPercent
                }}</em>
                <span class="allocation-footer-symbol">¥</span>
                <span class="allocation-footer-num">{{ totalAmount }}</span>
              </template>
              <template v-else>
                <span class="allocation-footer-symbol">¥</span>
                <span class="allocation-footer-num">{{ totalAmount }}</span>
              </template>
              <span class="allocation-footer-cycle"
                >/{{ selectedCycleLabel || $t("product.list.monthly") }}</span
              >
            </div>
            <div class="allocation-footer-meta">
              <span v-if="stockLabel">{{ stockLabel }}</span>
              <span v-if="selectedCycleLabel">{{ selectedCycleLabel }}</span>
              <span v-if="selectedRegionLabel">{{ selectedRegionLabel }}</span>
              <span v-if="currentOsVerLabel">{{ currentOsVerLabel }}</span>
              <button
                v-if="isMobile && hasAgentDiscount"
                type="button"
                class="allocation-footer-coupon-btn"
                disabled
              >
                {{ $t("product.list.agentDiscount", { name: agentGroupName })
                }}{{ agentDiscountPercent }}
              </button>
              <button
                v-if="isMobile"
                type="button"
                class="allocation-footer-coupon-btn"
                @click="mobileCouponSheet = true"
              >
                {{
                  appliedCoupon
                    ? $t("product.detail.couponUsed", {
                        amount: appliedCoupon.discount_amount,
                      })
                    : $t("product.detail.coupon")
                }}
              </button>
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
      <el-empty :description="$t('product.detail.notFound')">
        <el-button type="primary" @click="router.push('/products')">{{
          $t("product.detail.backToProducts")
        }}</el-button>
      </el-empty>
    </div>

    <!-- 移动端抽屉：与列表式配置区复用同一套选择器组件，交互保持一致 -->
    <MobileOptionPicker
      v-if="regionOptions.length"
      :visible="mobileRegionPicker"
      :title="$t('product.detail.region')"
      :options="regionOptions"
      :active-id="regionValue || ''"
      @close="mobileRegionPicker = false"
      @confirm="selectRegion"
    />
    <MobileOsPicker
      v-if="osGroups.length"
      :visible="mobileOsPicker"
      :os-groups="osGroups"
      :active-os-group-id="configForm.os_group || ''"
      :active-os-version-id="configForm.os || ''"
      @close="mobileOsPicker = false"
      @confirm="confirmMobileOsSelection"
    />
    <MobileOptionPicker
      v-if="mobileConfigPicker"
      :visible="mobileConfigPickerVisible"
      :title="mobileConfigPicker.label"
      :options="mobileConfigPicker.options"
      :active-id="configForm[mobileConfigPicker.key] || ''"
      @close="mobileConfigPickerVisible = false"
      @confirm="confirmMobileConfigSelection"
    />
    <MobileOptionPicker
      v-if="detailSpecCompare && skuOptions.length > 1"
      :visible="mobileSkuPicker"
      :title="$t('product.list.spec')"
      :options="skuOptions"
      :active-id="String(product.id || '')"
      @close="mobileSkuPicker = false"
      @confirm="confirmMobileSkuSelection"
    />

    <!-- 移动端优惠券抽屉：右侧摘要隐藏后，优惠券选择与折扣明细在这里 -->
    <MobileSheet
      :visible="mobileCouponSheet"
      size="58%"
      :title="$t('product.detail.coupon')"
      :cancel-text="$t('common.close')"
      :close-on-press-modal="false"
      @close="mobileCouponSheet = false"
      @cancel="mobileCouponSheet = false"
    >
      <div class="mobile-coupon-sheet">
        <div class="coupon-panel">
          <div class="coupon-panel-head">
            <span class="coupon-panel-title">{{
              $t("product.detail.coupon")
            }}</span>
            <button
              v-if="appliedCoupon"
              type="button"
              class="coupon-clear-btn"
              @click="clearCoupon"
            >
              {{ $t("product.coupon.remove") }}
            </button>
          </div>
          <div class="coupon-panel-form">
            <el-select
              :model-value="selectedCouponId || undefined"
              clearable
              :placeholder="$t('product.detail.selectCoupon')"
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
            {{
              $t("product.coupon.appliedTip", {
                name: appliedCoupon.name,
                discount: appliedCoupon.discount_label,
                amount: appliedCoupon.discount_amount,
              })
            }}
          </div>
          <div
            v-else-if="!availableCoupons.length"
            class="coupon-panel-tip coupon-panel-tip--muted"
          >
            {{ $t("product.coupon.noneAvailable") }}
          </div>
        </div>

        <ul class="mobile-coupon-summary">
          <li>
            <span>{{ $t("product.detail.basePrice") }}</span>
            <span>¥{{ baseAmount }}</span>
          </li>
          <li v-if="Number(setupFee) > 0">
            <span>{{ $t("product.detail.setupFee") }}</span>
            <span>¥{{ setupFee }}</span>
          </li>
          <li v-for="item in quoteItems" :key="item.field">
            <span>+ {{ item.label }}</span>
            <span>¥{{ item.amount }}</span>
          </li>
          <li v-if="hasAgentDiscount" class="is-discount">
            <span
              >{{ $t("product.list.agentDiscount", { name: agentGroupName })
              }}{{ agentDiscountPercent }}</span
            >
            <span>-¥{{ agentDiscountAmount }}</span>
          </li>
          <li v-if="appliedCoupon" class="is-discount">
            <span>{{ appliedCoupon.code || appliedCoupon.name }}</span>
            <span>-¥{{ appliedCoupon.discount_amount }}</span>
          </li>
        </ul>
      </div>
    </MobileSheet>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import { ElMessage } from "element-plus/es/components/message/index.mjs";
import siteApi from "@/api/site";
import { t } from "@/locales";
import { useUserStore } from "@/stores/user";
import { getToken } from "@/utils/auth";
import {
  resolveMissingPurchaseRequirements,
  resolvePurchaseRequirementList,
  resolvePurchaseRequirementSummary,
} from "@/utils/productPurchaseRequirements";
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
import { updatePageMeta } from "@/utils/pageMeta";
import { resolveCpuBrandBadge } from "@/domains/products/cpuBrandBadge";
import { resolveMachineSpecPresentation } from "@/domains/products/machineSpecResolver";
import { formatProductListPrice } from "@/views/website/products/productPrice";
import { useProductDisplaySettings } from "@/views/website/products/useProductDisplaySettings";
import { useProductDisplayMode } from "@/views/website/products/useProductDisplayMode";
import DisplayModeSwitch from "@/views/website/products/DisplayModeSwitch.vue";
import {
  buildWebsiteProductPath,
  resolveWebsiteProductRoutePayloadByDetail,
} from "@/utils/productRoute";
import { useWebsiteProductConfigurator } from "@/domains/products/useWebsiteProductConfigurator";
import MobileOptionPicker from "@/components/MobileOptionPicker.vue";
import MobileOsPicker from "@/components/MobileOsPicker.vue";
import MobileRangePicker from "@/components/MobileRangePicker.vue";
import MobileSheet from "@/components/MobileSheet.vue";

const route = useRoute();
const router = useRouter();

// 商品地址统一为 /products/{typeSlug}/{groupId}/{childGroupId}/{productId}；
// 旧版单段地址 /products/{id} 仍兼容，加载到商品后会被规范成四段地址。
const routeProductId = computed(() =>
  Number(route.params?.productId || route.params?.id || 0),
);

/** 由商品数据反推它自己的四段配置页地址（缺层级字段时返回空，保持旧地址） */
function resolveCanonicalProductPath(nextProduct) {
  const payload = resolveWebsiteProductRoutePayloadByDetail(nextProduct);

  if (!payload.typeSlug || !payload.groupId || !payload.productId) {
    return "";
  }

  return buildWebsiteProductPath(payload);
}

// 版心与「机型对比」开关都由后台「商品展示」页面控制，与选购页共用同一份配置
const { isContained, contentMaxWidthVar, detailSpecCompare } =
  useProductDisplaySettings();

// 同一个商品地址在列表式下由选购页内联渲染，卡片式下由本页渲染；
// 切换展示方式只改偏好、不改地址，路由入口会按偏好换渲染组件。
const { displayMode: detailDisplayMode, setDisplayMode: setDetailDisplayMode } =
  useProductDisplayMode();

function handleDetailDisplayModeChange(mode) {
  if (mode === detailDisplayMode.value) {
    return;
  }

  setDetailDisplayMode(mode);
}

/**
 * 顶部返回：固定跳到该商品的上级目录（三级分组 → 二级 → 类型），
 * 不依赖浏览器历史——从消息、外链、新标签进来时历史里可能是别处，
 * 「返回」把用户带走会很突兀。
 */
function goBack() {
  const payload = resolveWebsiteProductRoutePayloadByDetail(product.value);
  const directoryPath =
    payload.typeSlug && payload.groupId
      ? buildWebsiteProductPath({
          typeSlug: payload.typeSlug,
          groupId: payload.groupId,
          childGroupId: payload.childGroupId,
        })
      : "/products";

  router.push(directoryPath);
}
const userStore = useUserStore();

const loading = ref(false);
const submitting = ref(false);
const product = ref(null);
const selectedCycle = ref("");
const quantity = ref(1);
const selectedCouponId = ref(0);

const productDetailRef = computed(() => product.value);
const productDescription = computed(() =>
  String(product.value?.description_html || ""),
);
const configurator = useWebsiteProductConfigurator(productDetailRef);
const configForm = configurator.configForm;
const quoteResult = ref(null);
const quoteLoading = ref(false);
const quoteToken = ref("");
const productStock = ref(null);
const productStockLoading = ref(false);
const productStockError = ref("");

// 地区读写统一走共享层的 regionValue（各上游地区字段叫 region/area/node 不等，
// 且它同时维护 region 兼容镜像），这里不再按固定的 configForm.region 取值。
const regionValue = configurator.regionValue;
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
const selectedRegionLabel = computed(() => {
  const id = regionValue.value;
  return regionOptions.value.find((item) => item.id === id)?.label || "";
});

// 移动端断点与列表式配置区保持同一个值（900px），避免两处对「手机/桌面」判断分叉
const MOBILE_BREAKPOINT = 900;
const isMobile = ref(false);
const mobileRegionPicker = ref(false);
const mobileOsPicker = ref(false);
const mobileSkuPicker = ref(false);
const mobileConfigPicker = ref(null);
const mobileConfigPickerVisible = ref(false);
const mobileCouponSheet = ref(false);

function updateMobileState() {
  if (typeof window === "undefined") return;
  const nextMobile = window.innerWidth <= MOBILE_BREAKPOINT;
  if (nextMobile === isMobile.value) return;
  isMobile.value = nextMobile;
}

function selectRegion(regionId) {
  regionValue.value = regionId;
  mobileRegionPicker.value = false;
}

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

function confirmMobileOsSelection(groupId, versionId) {
  mobileOsPicker.value = false;
  const group = osGroups.value.find((item) => item.id === groupId);
  if (!group) return;

  configForm.os_group = group.id;
  configForm.os = versionId || group.versions?.[0]?.id || "";
}

function selectedConfigLabel(cfg) {
  const value = configForm[cfg?.key];
  return (
    cfg?.options?.find((item) => item.id === value)?.label ||
    cfg?.options?.[0]?.label ||
    ""
  );
}

function openMobileConfigPicker(cfg) {
  if (!cfg?.options?.length) return;
  mobileConfigPicker.value = cfg;
  mobileConfigPickerVisible.value = true;
}

function confirmMobileConfigSelection(optionId) {
  const cfg = mobileConfigPicker.value;
  mobileConfigPickerVisible.value = false;
  if (cfg && optionId) configForm[cfg.key] = optionId;
}

/**
 * 配置分组直接取共享层（useWebsiteProductConfigurator）的权威口径。
 *
 * 这里曾再按关键词做一次本地过滤，后果是「其他配置」整组（绑定域名数、WEB 空间、
 * 连接数等）与机型里命中网络词的项在两侧都不渲染——虚拟主机 / CDN 商品在
 * 独立配置页几乎不剩任何配置项。分组规则只允许有一处真源。
 */
const configGroups = computed(() =>
  [
    {
      title: t("product.detail.serverSpecs"),
      configs: configurator.machineConfigs.value || [],
    },
    {
      title: t("product.detail.network"),
      configs: configurator.networkConfigs.value || [],
    },
    {
      title: t("product.list.otherConfig"),
      configs: configurator.otherConfigs.value || [],
    },
  ].filter((group) => group.configs.length > 0),
);

// 购买须知：仅展示，不参与选配与计价
const noticeConfigs = computed(() => configurator.noticeConfigs.value || []);

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

/**
 * 代理折扣：命中代理折扣矩阵时后端报价会下发 agent_* 字段
 * （agent_discount_rate < 100），与列表式配置区同一判定口径。
 * 详情接口另外带一份商品级折扣字段，仅在报价尚未返回时兜底。
 */
const hasAgentDiscount = computed(() => {
  const rate = Number(quoteResult.value?.agent_discount_rate);
  if (quoteResult.value && Number.isFinite(rate) && rate > 0) {
    return rate < 100;
  }
  return Boolean(product.value?.has_agent_discount);
});
const agentDiscountPercent = computed(() => {
  const rate = Number(quoteResult.value?.agent_discount_rate);
  if (Number.isFinite(rate) && rate > 0 && rate < 100) {
    const value = Math.round((rate / 10) * 10) / 10;
    return t("product.checkout.discountRate", { rate: value });
  }
  return String(product.value?.agent_discount_percent || "");
});
const agentGroupName = computed(() =>
  String(
    quoteResult.value?.agent_group_name ||
      product.value?.agent_group_name ||
      "",
  ),
);
const agentOriginalAmount = computed(() =>
  normalizeMoneyText(
    quoteResult.value?.original_total_amount || totalAmount.value,
  ),
);
const agentDiscountAmount = computed(() =>
  normalizeMoneyText(quoteResult.value?.agent_discount_amount),
);

// 配置摘要取共享层口径（含地区 / 系统 / CPU 型号 / 机型规格与全部配置项），
// 独立页不再自己维护一套键名与分组，避免与列表式配置区对不上。
const summaryItems = computed(() => {
  const items = (configurator.summaryItems.value || []).slice();
  if (selectedCycleLabel.value) {
    items.push({
      key: "billing_cycle",
      label: t("product.detail.cycle"),
      value: selectedCycleLabel.value,
    });
  }

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
let quoteWatchSuspendCount = 0;
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
  if (quoteWatchSuspendCount > 0) {
    return;
  }

  clearTimeout(quoteTimer);
  quoteTimer = setTimeout(() => {
    executeQuote(selectedCouponId.value, { fallbackInvalidCoupon: true });
  }, 300);
}

function buildConfigPayload() {
  return configurator.buildConfigPayload();
}

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
  if (productStockLoading.value) return t("product.checkout.stockSyncing");
  if (productStockError.value) return t("product.checkout.stockSyncFailed");
  const stock = resolvedStock.value;
  if (stock === null) return t("product.checkout.stockSyncing");
  if (stock === -1 || stock > 10) return t("product.checkout.stockSufficient");
  if (stock > 0) return t("product.checkout.stockLow");
  return t("product.checkout.stockOutOfStock");
});
const stockHint = computed(() => {
  if (productStockLoading.value) return t("product.checkout.stockSyncingHint");
  if (productStockError.value) return t("product.checkout.stockSyncRetryHint");
  const stock = resolvedStock.value;
  if (stock === null) return t("product.checkout.stockSyncingHint");
  if (stock === -1 || stock > 10)
    return t("product.checkout.stockSufficientHint");
  if (stock > 0) return t("product.checkout.stockRemainingHint", { stock });
  return t("product.checkout.stockInsufficientHint");
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
  if (submitting.value) return t("product.list.submitting");
  if (soldOut.value) return t("product.card.soldOut");
  return t("product.list.buyNow");
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
  ElMessage.success(t("product.checkout.enteringConsole"));

  navigateToConsole(
    checkoutPath,
    checkoutPayload ? { checkout_payload: checkoutPayload } : {},
  );
}

async function ensurePurchaseRequirementsBeforeCheckout() {
  const requirements = resolvePurchaseRequirementList(product.value);
  if (!requirements.length) {
    return true;
  }

  if (!getToken()) {
    ElMessage.warning(t("product.checkout.loginRequired"));
    navigateToConsole("/client/login");
    return false;
  }

  let user = userStore.info;
  if (!user) {
    try {
      user = await userStore.fetchUserInfo();
    } catch {
      ElMessage.warning(t("product.checkout.sessionExpired"));
      navigateToConsole("/client/login");
      return false;
    }
  }

  const missing = resolveMissingPurchaseRequirements(product.value, user);
  if (!missing.length) {
    return true;
  }

  const primary = missing[0];
  ElMessage.warning(
    primary.unmetMessage || t("product.checkout.prerequisitesRequired"),
  );
  navigateToConsole(primary.route || "/client/profile");
  return false;
}

async function submitOrder() {
  if (productStockLoading.value) {
    ElMessage.warning(t("product.checkout.stockSyncingNotice"));
    return;
  }
  if (productStockError.value) {
    ElMessage.warning(t("product.checkout.stockSyncFailedNotice"));
    return;
  }
  if (resolvedStock.value === 0) {
    ElMessage.warning(t("product.checkout.purchaseUnavailable"));
    return;
  }
  if (!quoteToken.value) {
    ElMessage.warning(t("product.checkout.quoteExpired"));
    return;
  }
  if (!canSubmit.value) {
    ElMessage.warning(t("product.checkout.selectBillingCycle"));
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
  } catch (err) {
    ElMessage.error(
      err?.response?.data?.message ||
        t("product.checkout.consoleRedirectFailed"),
    );
  } finally {
    submitting.value = false;
  }
}

function switchProduct(id) {
  const productId = Number(id || 0);
  if (!productId || productId === Number(product.value?.id || 0)) {
    return;
  }

  // 换机型留在本页，地址仍然用统一的四段商品地址；同组商品共享
  // 一级/二级/三级分组，所以直接取该商品自己的层级字段拼地址。
  const groupProduct = groupProducts.value.find(
    (item) => Number(item?.id) === productId,
  );
  const canonicalPath = resolveCanonicalProductPath(groupProduct);

  if (canonicalPath) {
    router.replace(canonicalPath);
    return;
  }

  // 兜底：当前已经是四段地址时只替换商品段，保住分类上下文
  if (route.params?.productId) {
    router.replace({
      name: route.name,
      params: { ...route.params, productId },
      query: route.query,
    });
    return;
  }

  router.replace(`/products/${productId}`);
}

// ===== 机型/SKU 对比 =====
// 详情接口的 siblings 只带 id 与几个名称字段，撑不起与列表式一致的机型表。
// 这里直接取同一商品组的在售商品（列表式的机型表用的就是这个接口），
// 价格、CPU 型号、规格亮点因此与列表式同源。
const groupProducts = ref([]);
const skuSort = ref({ key: "", direction: "" });

const FREQUENCY_UNIT = "GHz";

function parseSpecNumber(value) {
  const match = String(value || "").match(/\d+(?:\.\d+)?/);
  return match ? Number(match[0]) : Number.NaN;
}

function formatFrequencyValue(value) {
  const text = String(value || "")
    .replace(/\s*GHz$/i, "")
    .trim();

  if (!text) {
    return "";
  }

  return /^[+-]?\d+(?:\.\d+)?$/.test(text) ? `${text}${FREQUENCY_UNIT}` : text;
}

function formatFrequencyPair(baseFrequency, turboFrequency) {
  const base = formatFrequencyValue(baseFrequency);
  const turbo = formatFrequencyValue(turboFrequency);

  if (base && turbo) {
    return `${base}/${turbo}`;
  }

  return base || turbo || "";
}

const skuRows = computed(() => {
  const rows = groupProducts.value
    .map((item) => {
      const spec = resolveMachineSpecPresentation(item, {});
      const cpuText = spec.cpuText || String(item?.cpu_display || "").trim();
      const memoryText =
        spec.memoryText || String(item?.memory_display || "").trim();

      return {
        id: Number(item?.id || 0),
        name: spec.displayName || resolveProductDisplayName(item),
        cpuText: cpuText || "-",
        memoryText: memoryText || "-",
        processorLabel: String(item?.cpu_model_name || "").trim(),
        brandBadge: resolveCpuBrandBadge(item?.cpu_model_name),
        frequency: formatFrequencyPair(
          item?.cpu_base_frequency,
          item?.cpu_turbo_frequency,
        ),
        priceText: formatProductListPrice(item) || "-",
        agentDiscount: Boolean(item?.has_agent_discount),
        agentOriginalPrice: normalizeMoneyText(item?.primary_price),
        agentPrice: normalizeMoneyText(item?.agent_primary_price),
        agentDiscountPercent: String(item?.agent_discount_percent || ""),
        cpuValue: parseSpecNumber(cpuText),
        memoryValue: parseSpecNumber(memoryText),
        priceValue: Number(item?.primary_price || 0),
      };
    })
    .filter((row) => row.id > 0);

  if (!skuSort.value.key || !skuSort.value.direction) {
    return rows;
  }

  const factor = skuSort.value.direction === "desc" ? -1 : 1;
  const valueKey = `${skuSort.value.key}Value`;

  return rows.slice().sort((left, right) => {
    const leftValue = Number(left[valueKey]);
    const rightValue = Number(right[valueKey]);
    const leftValid = Number.isFinite(leftValue);
    const rightValid = Number.isFinite(rightValue);

    if (!leftValid && !rightValid) return left.id - right.id;
    if (!leftValid) return 1;
    if (!rightValid) return -1;

    const result = (leftValue - rightValue) * factor;
    return result === 0 ? left.id - right.id : result;
  });
});

function setSkuSort(key, direction) {
  skuSort.value =
    skuSort.value.key === key && skuSort.value.direction === direction
      ? { key: "", direction: "" }
      : { key, direction };
}

const skuOptions = computed(() =>
  skuRows.value.map((row) => ({ id: String(row.id), label: row.name })),
);

const currentSkuLabel = computed(() => {
  const currentId = Number(product.value?.id || 0);
  return (
    skuRows.value.find((row) => row.id === currentId)?.name ||
    selectedMachineSpec.value.displayName ||
    resolveProductDisplayName(product.value)
  );
});

function confirmMobileSkuSelection(productId) {
  mobileSkuPicker.value = false;
  const nextId = Number(productId || 0);
  if (nextId > 0 && nextId !== Number(product.value?.id || 0)) {
    switchProduct(nextId);
  }
}

async function loadGroupProducts(detail) {
  const groupId = Number(detail?.effective_product_group_id || 0);
  const level =
    Number(detail?.effective_product_group_level || 3) === 2 ? 2 : 3;

  if (groupId <= 0) {
    groupProducts.value = [];
    return;
  }

  try {
    const res = await siteApi.productGroupProducts(groupId, {
      level,
      page: 1,
      page_size: 50,
    });
    groupProducts.value = Array.isArray(res.data?.list) ? res.data.list : [];
  } catch {
    groupProducts.value = [];
  }
}

function initDefaults() {
  quoteWatchSuspendCount += 1;
  try {
    configurator.initProductDefaults({
      selectedCycleRef: selectedCycle,
      quantityRef: quantity,
      resetQuoteState: () => {
        quoteResult.value = null;
        quoteToken.value = "";
      },
    });
  } finally {
    quoteWatchSuspendCount = Math.max(0, quoteWatchSuspendCount - 1);
  }

  fetchQuote();
}

function plainTextSummary(value, maxLength = 160) {
  return String(value || "")
    .replace(/<[^>]+>/g, " ")
    .replace(/\s+/g, " ")
    .trim()
    .slice(0, maxLength);
}

function syncProductMeta(nextProduct) {
  if (!nextProduct) return;
  const title =
    resolveProductDisplayName(nextProduct) ||
    t("product.detail.cloudProductTitle");
  const description = plainTextSummary(
    nextProduct.description ||
      nextProduct.group?.slogan ||
      nextProduct.group?.parent_slogan ||
      t("product.detail.cloudProductDescription"),
  );
  // canonical 基址用配置的公开站点域名，与后端 SEO 渲染的 canonical 同源，
  // 避免双域名部署下 SSR 与客户端各写一个不同域名的 canonical
  const canonical =
    typeof window !== "undefined"
      ? new URL(
          route.path,
          String(
            import.meta.env.VITE_PUBLIC_SITE_URL || window.location.origin,
          ),
        ).toString()
      : "";

  updatePageMeta({
    title: t("product.detail.windowTitle", { title }),
    description,
    keywords: [title, nextProduct.type_label, nextProduct.group?.full_name]
      .filter(Boolean)
      .join(","),
    canonical,
    structuredData: {
      "@context": "https://schema.org",
      "@type": "Product",
      name: title,
      description,
    },
  });
}

async function loadProduct() {
  const pid = routeProductId.value;
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

    // 旧版单段地址（/products/{id}）加载到商品后规范成四段商品地址：
    // 地址统一后，列表式与卡片式用户分享的链接完全一致。
    const canonicalPath = resolveCanonicalProductPath(product.value);
    if (canonicalPath && !router.currentRoute.value.params?.productId) {
      await router.replace(canonicalPath);
      return;
    }

    syncProductMeta(product.value);
    initDefaults();
    void loadGroupProducts(product.value);
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
    if (routeProductId.value !== id) return;
    productStock.value = Number(res.data?.stock ?? 0);
  } catch (err) {
    if (routeProductId.value !== id) return;
    productStockError.value =
      err?.response?.data?.message || t("product.checkout.stockSyncFailed");
  } finally {
    if (routeProductId.value === id) {
      productStockLoading.value = false;
    }
  }
}

watch(
  () => routeProductId.value,
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
onMounted(() => {
  updateMobileState();
  window.addEventListener("resize", updateMobileState);
  loadProduct();
});

onBeforeUnmount(() => {
  // 清理报价防抖定时器与在途请求，避免卸载后仍发请求/写已卸载组件状态
  clearTimeout(quoteTimer);
  quoteAbortController?.abort();
  // 使仍在途的 loadProduct 响应失效
  productLoadToken += 1;
  window.removeEventListener("resize", updateMobileState);
});
</script>

<style scoped lang="scss">
/* ===== 商品描述 ===== */
.pd-desc {
  margin: 0 0 16px;
  padding: 16px 18px;
  background: $bg-color-card;
  border: 1px solid $divider-color;
  border-radius: 6px;
}

.pd-desc-title {
  margin: 0 0 8px;
  font-size: 15px;
  font-weight: 600;
  color: $text-color-primary;
}

.pd-desc-body {
  font-size: 13px;
  line-height: 1.8;
  color: $text-color-secondary;
  word-break: break-word;

  /* v-html 渲染的是商家填写的富文本片段，scoped 选择器打不到内部节点 */
  :deep(ul),
  :deep(ol) {
    margin: 0;
    padding-left: 18px;
  }

  :deep(li) {
    margin: 2px 0;
  }

  :deep(li::marker) {
    color: $color-primary;
  }

  :deep(p) {
    margin: 0 0 6px;
  }

  :deep(h1),
  :deep(h2),
  :deep(h3),
  :deep(h4) {
    margin: 0 0 6px;
    font-size: 14px;
    font-weight: 600;
  }

  :deep(table) {
    width: 100%;
    border-collapse: collapse;
  }

  :deep(td),
  :deep(th) {
    padding: 4px 6px;
    border: 1px solid $divider-color;
  }

  :deep(img) {
    max-width: 100%;
    height: auto;
  }
}

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

  /* 大屏下内容居中约束，避免右侧摘要栏贴到视口边缘 */
  --pd-content-max: 1280px;
  --pd-content-gutter: 16px;

  @media (max-width: 900px) {
    padding-bottom: 108px;
  }
}

/* 统一内容容器：限制最大宽度并水平居中（左右留出安全间距）。
   .pd-desc 曾经漏在这一组里，导致「商品描述」整块通栏、左右没有边距，
   与上下卡片（顶栏 / 头部 / 主体）对不齐。
   版心宽度与选购页同源（访客选择优先），切换时做同样的宽度过渡。 */
.pd-topbar,
.pd-hero,
.pd-desc,
.pd-sku,
.pd-body {
  width: calc(100% - 2 * var(--pd-content-gutter));
  max-width: calc(var(--pd-content-max) - 2 * var(--pd-content-gutter));
  margin-right: auto;
  margin-left: auto;
  box-sizing: border-box;
  transition: max-width 0.34s cubic-bezier(0.4, 0, 0.2, 1);
}

/* 版心铺满：后台默认值或访客在选购页选择「撑满屏幕」 */
.pd-page--full {
  --pd-content-max: 100vw;
}

@media (max-width: 900px) {
  .pd-page {
    --pd-content-max: 100vw;
  }

  .pd-topbar,
  .pd-hero,
  .pd-desc,
  .pd-sku,
  .pd-body {
    transition: none;
  }
}

/* ===== 顶部工具栏 ===== */
.pd-topbar {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 12px 0 0;
  overflow: hidden;
}

/*
 * 顶部只保留返回：入口可能来自列表、卡片或站外，能回上一页就回上一页。
 * 此前这里放的是「分类」按钮，点它只会把用户丢回商品列表，返回意图落空。
 */
.back-btn {
  flex-shrink: 0;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  height: 34px;
  padding: 0 12px 0 10px;
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

/* 配置页的列表式 / 卡片式切换（与选购页同一套形态口径） */
.pd-topbar-actions {
  display: flex;
  flex-shrink: 0;
  align-items: center;
  gap: 8px;
  margin-left: auto;
}

.pd-view-label {
  color: #86909c;
  font-size: 12px;
}

@media (max-width: 520px) {
  .pd-view-label {
    display: none;
  }
}

.pd-hero {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  gap: 20px;
  margin-top: 12px;
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

/* ===== 主体 ===== */
.pd-body {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 268px;
  gap: 12px;
  padding: 12px 0 0;
  align-items: start;

  @media (max-width: 900px) {
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

/* ===== 规格行（间距与列表式配置区对齐）===== */
.spec-row {
  display: flex;
  align-items: flex-start;
  gap: 16px;
  padding: 12px 0;
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
  color: $text-color-placeholder;
  line-height: 1.4;
}

.spec-ctrl {
  flex: 1;
  min-width: 0;
}

/* ===== 操作系统卡片（与列表式配置区同一套样式）===== */
.os-cards {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
}

.os-card {
  position: relative;
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 6px;
  width: 170px;
  padding: 14px 16px;
  border: 1px solid $border-color;
  border-radius: 4px;
  background: #fff;
  text-align: left;
  cursor: pointer;
  transition: all $motion-fast ease;

  &:hover {
    border-color: $color-primary;
  }

  &.active {
    border-color: $color-primary;
    background: $color-primary-soft;
  }
}

.os-card-head {
  display: flex;
  align-items: center;
  gap: 8px;
}

.os-logo {
  font-size: 20px;
  line-height: 1;
}

.os-logo-img {
  display: block;
  flex-shrink: 0;
  width: 28px;
  height: 28px;
  object-fit: contain;
}

.os-label-name {
  font-size: 14px;
  font-weight: 600;
  color: $text-color-primary;
}

.os-card-ver {
  max-width: 100%;
  overflow: hidden;
  font-size: 12px;
  color: $text-color-placeholder;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.os-placeholder {
  color: $text-color-placeholder;
}

.os-check-mark {
  position: absolute;
  top: 8px;
  right: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 16px;
  height: 16px;
  border-radius: 2px;
  background: $color-primary;
  color: #fff;
  font-size: 10px;
  font-weight: 700;
}

.os-ver-row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
  margin-top: 12px;
}

.os-ver-label {
  flex-shrink: 0;
  font-size: 13px;
  color: $text-color-secondary;
}

.os-ver-btns {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

.ver-btn {
  height: 30px;
  padding: 0 12px;
  border: 1px solid $border-color;
  border-radius: 4px;
  background: #fff;
  color: $text-color-primary;
  font-size: 12px;
  white-space: nowrap;
  cursor: pointer;
  transition: all $motion-fast ease;

  &:hover {
    border-color: $color-primary;
    color: $color-primary;
  }

  &.active {
    border-color: $color-primary;
    background: $color-primary-soft;
    color: $color-primary;
    font-weight: 600;
  }
}

/* 单选项是既成事实而不是可点控件：静态高亮，避免「看着能点、点了没反应」 */
.spec-fixed {
  display: inline-block;
  padding: 5px 12px;
  border: 1px solid $divider-color;
  border-radius: 4px;
  background: $divider-color;
  color: $text-color-secondary;
  font-size: 13px;
}

/* 900px：与列表式的移动断点保持一致；平板不再挤三栏，
   并隐藏摘要卡里的提交按钮——移动底栏已有同一个按钮，否则同屏两个 */
@media (max-width: 900px) {
  .pd-body {
    grid-template-columns: 1fr;
  }

  .aside-card .buy-btn {
    display: none;
  }
}

/* ===== 购买须知 ===== */
.notice-item {
  padding: 10px 0;
  border-bottom: 1px solid #f2f3f5;

  &:first-child {
    padding-top: 0;
  }
  &:last-child {
    border-bottom: none;
    padding-bottom: 0;
  }
}

.notice-label {
  font-size: 13px;
  color: #86909c;
  line-height: 1.4;
  margin-bottom: 4px;
}

.notice-text {
  font-size: 13px;
  color: #c45656;
  line-height: 1.7;
  word-break: break-word;
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

  @media (max-width: 900px) {
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
    border-radius: 6px;
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
  /* 空间不足时让价格块整体换行，而不是把「合计费用」「/月付」拆成两行 */
  flex-wrap: wrap;
  justify-content: space-between;
  align-items: center;
  column-gap: 8px;
  row-gap: 2px;
  padding: 8px 0 16px;
  border-top: 1px solid #f2f3f5;
  margin-top: 8px;
}

.cost-total-label {
  font-size: 13px;
  color: #86909c;
  /* 标签不参与压缩，避免「合计/费用」断行 */
  flex-shrink: 0;
  white-space: nowrap;
}

.cost-price-wrap {
  display: flex;
  /* 价格内部任何情况下都不拆行 */
  flex-wrap: nowrap;
  align-items: baseline;
  justify-content: flex-end;
  gap: 1px;
  /* 单行时靠右；换到第二行时同样靠右 */
  margin-left: auto;
}

.cost-currency {
  font-size: 14px;
  color: #165dff;
  font-weight: 700;
  flex-shrink: 0;
}

.cost-amount {
  font-size: 22px;
  font-weight: 700;
  color: #165dff;
  line-height: 1;
  white-space: nowrap;
  flex-shrink: 0;
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
  /* 「/月付」不能被拆成「/月」「付」两行 */
  white-space: nowrap;
  flex-shrink: 0;
}

@media (max-width: 900px) {
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

  @media (max-width: 900px) {
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
  border-radius: 6px;
  background: rgba(255, 255, 255, 0.98);
  box-shadow: 0 2px 10px rgba(15, 23, 42, 0.06);
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
  border-radius: 3px;
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

@media (max-width: 900px) {
  /* 移动端不再重复一份右侧摘要：费用明细与合计都在底部结算栏 + 优惠券抽屉里，
     与列表式配置区移动端（隐藏 .shop-cost）保持同一套信息结构。 */
  .pd-aside {
    display: none;
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

/* ===== 机型对比表（与列表式机型表同一套字段口径） ===== */
.pd-sku {
  margin-top: 12px;
  padding: 16px;
  background: $bg-color-card;
  border: 1px solid $divider-color;
  border-radius: 6px;
}

.pd-sku-head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 12px;
}

.pd-sku-title {
  margin: 0;
  font-size: 15px;
  font-weight: 600;
  color: $text-color-primary;
}

.pd-sku-hint {
  font-size: 12px;
  color: $text-color-placeholder;
}

.sku-table {
  display: flex;
  flex-direction: column;
  overflow-x: auto;
}

.sku-row {
  display: grid;
  grid-template-columns:
    minmax(180px, 1.6fr) minmax(80px, 0.8fr) minmax(80px, 0.8fr)
    minmax(200px, 1.4fr) minmax(100px, 0.9fr) minmax(150px, 1fr);
  align-items: center;
  gap: 12px;
  min-width: 860px;
  padding: 10px 8px;
  border: none;
  border-bottom: 1px solid $divider-color;
  background: transparent;
  color: $text-color-primary;
  font-size: 13px;
  text-align: left;
}

.sku-row--head {
  color: $text-color-placeholder;
  font-size: 12px;
  font-weight: 600;
  cursor: default;
}

button.sku-row {
  cursor: pointer;
  transition: background-color 0.15s ease;

  &:hover {
    background: rgba($color-primary, 0.04);
  }

  &.active {
    background: rgba($color-primary, 0.08);
  }
}

.sku-cell {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.sku-cell--name {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  white-space: normal;
}

.sku-cell--sortable {
  display: inline-flex;
  align-items: center;
  gap: 6px;
}

.sku-sort {
  display: inline-flex;
  flex-direction: column;
  gap: 1px;
}

.sku-sort-btn {
  border: none;
  background: none;
  padding: 0;
  font-size: 8px;
  line-height: 1;
  color: $text-color-placeholder;
  cursor: pointer;

  &.active {
    color: $color-primary;
  }
}

.sku-radio {
  flex: 0 0 auto;
  width: 14px;
  height: 14px;
  border: 1px solid $divider-color;
  border-radius: 50%;
  box-sizing: border-box;
}

.sku-row.active .sku-radio {
  border: 4px solid $color-primary;
}

.sku-cell--processor {
  display: inline-flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 6px;
  white-space: normal;
}

.sku-cell--price {
  display: inline-flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 4px;
  justify-content: flex-end;
  white-space: normal;
}

/* ===== 移动端选择器（与列表式配置区同一套按钮形态） ===== */
.mobile-picker-trigger {
  width: 100%;
  min-height: 52px;
  padding: 0 14px;
  border: 1px solid $divider-color;
  border-radius: 4px;
  background: #fff;
  color: $text-color-primary;
  display: inline-flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  font-size: 15px;
  font-weight: 700;
  cursor: pointer;
  text-align: left;

  span {
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  svg {
    flex-shrink: 0;
    color: $text-color-placeholder;
  }
}

.mobile-config-select-row {
  width: 100%;
  min-height: 46px;
  padding: 0;
  border: 0;
  border-bottom: 1px solid $divider-color;
  background: #fff;
  color: $text-color-primary;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  font-size: 14px;
  line-height: 1.4;
  cursor: pointer;
  text-align: left;

  &:last-child {
    border-bottom: none;
  }
}

.mobile-config-select-label {
  min-width: 0;
  font-weight: 500;
}

.mobile-config-select-value {
  min-width: 0;
  max-width: 62%;
  display: inline-flex;
  align-items: center;
  justify-content: flex-end;
  gap: 4px;
  font-weight: 500;
  text-align: right;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;

  svg {
    flex-shrink: 0;
    color: #b8bec8;
  }
}

/* ===== 代理折扣（与列表式配置区同一套视觉） ===== */
.discount-tag {
  display: inline-flex;
  align-items: center;
  margin: 0 6px;
  padding: 0 6px;
  font-style: normal;
  font-size: 12px;
  font-weight: 700;
  line-height: 18px;
  color: #fff;
  background: #f56c6c;
  border-radius: 4px;
  vertical-align: 1px;
  white-space: nowrap;
}

.cost-amount--original {
  font-size: 13px;
  font-weight: 500;
  color: $text-color-placeholder;
  text-decoration: line-through;
  margin-right: 2px;
  white-space: nowrap;
  flex-shrink: 0;
}

.allocation-footer-original {
  font-size: 13px;
  line-height: 1.2;
  font-weight: 500;
  color: #b7bcc5;
  text-decoration: line-through;
  margin-right: 2px;
}

.allocation-footer-coupon-btn {
  min-width: 0;
  padding: 0;
  border: none;
  background: none;
  color: $color-primary;
  font-size: 12px;
  line-height: 1.2;
  font-weight: 600;
  cursor: pointer;
  overflow-wrap: anywhere;
  word-break: break-word;

  &:disabled {
    color: #b7bcc5;
    font-weight: 500;
    cursor: default;
  }
}

/* 移动端优惠券抽屉 */
.mobile-coupon-sheet {
  display: flex;
  flex-direction: column;
  gap: 14px;
  padding: 16px 14px calc(16px + env(safe-area-inset-bottom, 0px));
}

.mobile-coupon-summary {
  margin: 0;
  padding: 12px 14px;
  border: 1px solid $divider-color;
  border-radius: 6px;
  background: $bg-color-card;
  list-style: none;

  li {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 12px;
    padding: 4px 0;
    font-size: 13px;
    color: $text-color-secondary;

    &.is-discount {
      color: $color-warning;
    }
  }
}

.agent-price-original {
  color: $text-color-placeholder;
  font-size: 12px;
  font-weight: 500;
  text-decoration: line-through;
}

.agent-price-current {
  color: $color-warning;
  font-size: 15px;
  font-weight: 700;
}

/* CPU 品牌徽标：与列表式机型表同一套配色 */
.cpu-brand-badge {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  flex: 0 0 auto;
  height: 20px;
  padding: 0 6px 0 4px;
  border-radius: 4px;
  font-size: 11px;
  font-weight: 600;
  line-height: 20px;
  white-space: nowrap;

  &__icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 14px;
    height: 14px;
    padding: 0 3px;
    border-radius: 3px;
    color: #fff;
    font-size: 9px;
    font-weight: 700;
    line-height: 14px;
  }

  &__label {
    line-height: 1;
  }

  &--core-ultra {
    color: #5b3df5;
    border: 1px solid rgba(91, 61, 245, 0.35);
    background: rgba(91, 61, 245, 0.08);

    .cpu-brand-badge__icon {
      background: #5b3df5;
    }
  }

  &--platinum {
    color: #475569;
    border: 1px solid rgba(100, 116, 139, 0.4);
    background: linear-gradient(
      180deg,
      rgba(203, 213, 225, 0.5),
      rgba(148, 163, 184, 0.35)
    );

    .cpu-brand-badge__icon {
      background: linear-gradient(180deg, #94a3b8, #64748b);
    }
  }

  &--gold {
    color: #a16207;
    border: 1px solid rgba(202, 138, 4, 0.4);
    background: rgba(250, 204, 21, 0.14);

    .cpu-brand-badge__icon {
      background: linear-gradient(180deg, #eab308, #ca8a04);
    }
  }

  &--amd {
    color: #c4001d;
    border: 1px solid rgba(196, 0, 29, 0.35);
    background: rgba(196, 0, 29, 0.07);

    .cpu-brand-badge__icon {
      background: #c4001d;
    }
  }

  &--xeon {
    color: #0066cc;
    border: 1px solid rgba(0, 102, 204, 0.35);
    background: rgba(0, 102, 204, 0.07);

    .cpu-brand-badge__icon {
      background: #0066cc;
    }
  }

  &--intel {
    color: #0071c5;
    border: 1px solid rgba(0, 113, 197, 0.35);
    background: rgba(0, 113, 197, 0.07);

    .cpu-brand-badge__icon {
      background: #0071c5;
    }
  }
}
</style>
