<template>
  <div
    class="shop-wrap"
    :class="{ 'shop-wrap--contained': isContained }"
    :style="{ '--shop-content-max': contentMaxWidthVar }"
  >
    <!-- 第一个侧栏：一级分组（商品种类），可折叠成缩写方块 -->
    <aside
      v-if="!isMobile && navVisible"
      class="shop-sidebar"
      :class="{ collapsed: sidebarCollapsed }"
      :aria-label="$t('product.list.level1Nav')"
    >
      <div class="sidebar-head">
        <span v-show="!sidebarCollapsed" class="sidebar-head-text">{{
          $t("product.list.level1Nav")
        }}</span>
        <button
          type="button"
          class="sidebar-toggle"
          :aria-label="
            sidebarCollapsed
              ? $t('product.list.expandLevel1Nav')
              : $t('product.list.collapseLevel1Nav')
          "
          :aria-expanded="!sidebarCollapsed"
          @click="sidebarCollapsed = !sidebarCollapsed"
        >
          <el-icon><Expand v-if="sidebarCollapsed" /><Fold v-else /></el-icon>
        </button>
      </div>

      <div class="sidebar-list">
        <button
          v-for="type in productTypes"
          :key="type.value"
          type="button"
          class="sidebar-item"
          :class="{ active: activeTypeValue === type.value }"
          :title="sidebarCollapsed ? type.label : undefined"
          :aria-current="activeTypeValue === type.value ? 'true' : undefined"
          @click="handleSelectType(type.value)"
        >
          <span class="item-abbr">{{
            type.abbr || type.label.slice(0, 2).toUpperCase()
          }}</span>
          <span v-if="!sidebarCollapsed" class="item-body">
            <span class="item-name">{{ type.label }}</span>
            <span class="item-sub">{{
              type.product_count > 0
                ? $t("product.list.productCount", {
                    count: type.product_count,
                  })
                : $t("product.list.empty")
            }}</span>
          </span>
        </button>
      </div>
    </aside>

    <!-- 第二个侧栏：当前一级分组下的二 / 三级分组 -->
    <aside
      v-if="!isMobile && navVisible"
      class="shop-catalog"
      :aria-label="$t('product.list.groupNav')"
    >
      <div class="shop-catalog__head">
        <span class="shop-catalog__title">{{ activeTypeLabel }}</span>
        <span class="shop-catalog__badge">{{
          $t("product.list.level2Nav")
        }}</span>
      </div>

      <div class="shop-catalog__body">
        <ProductGroupNav
          :groups="rootGroups"
          :child-groups="childGroups"
          :active-group-id="activeGroupId"
          :active-child-id="activeChildId"
          @select-group="handleGroupNavSelectGroup"
          @select-child="handleGroupNavSelectChild"
        />
      </div>
    </aside>

    <!-- 右侧主区域 -->
    <div class="shop-body">
      <!-- 中间配置区 -->
      <div class="shop-main" v-loading="pageLoading">
        <!--
            展示方式与版心：站点默认值决定首屏形态，访客可在右侧自行切换。
            手机端也保留「卡片式 / 列表式」切换（此前只在桌面显示，手机端改不了）；
            「页面宽度」只在宽屏显示——手机端始终铺满，调了也没有意义。

            手机端没有侧栏，左侧当前层级文字本身就是目录入口：点开底部抽屉，
            一/二/三级分组都在里面选。
          -->
        <div class="shop-view-bar">
          <button
            v-if="isMobile"
            type="button"
            class="shop-view-bar__catalog"
            :aria-label="$t('product.list.catalogTitle')"
            @click="mobileCatalogDrawer = true"
          >
            <span class="shop-view-bar__name">{{
              mobileCatalogPathText || activeTypeLabel
            }}</span>
            <svg
              viewBox="0 0 12 12"
              width="12"
              height="12"
              fill="none"
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
          <div v-else class="shop-view-bar__summary">
            <span class="shop-view-bar__name">{{
              activeChildName || activeGroupName || activeTypeLabel
            }}</span>
            <span v-if="visibleProducts.length" class="shop-view-bar__count">{{
              $t("product.list.productCount", {
                count: visibleProducts.length,
              })
            }}</span>
          </div>
          <div class="shop-view-bar__controls">
            <DisplayModeSwitch
              :model-value="displayMode"
              @update:model-value="handleDisplayModeChange"
            />

            <!-- 目录侧栏：收起后右侧配置区更宽，偏好只存本机 -->
            <button
              v-if="!isMobile"
              type="button"
              class="shop-group-nav-trigger"
              :class="{ 'is-active': !navVisible }"
              :aria-pressed="!navVisible"
              @click="toggleNavVisible"
            >
              <svg
                viewBox="0 0 16 16"
                width="14"
                height="14"
                fill="none"
                aria-hidden="true"
              >
                <rect
                  x="2"
                  y="3"
                  width="12"
                  height="10"
                  rx="1.5"
                  stroke="currentColor"
                  stroke-width="1.3"
                />
                <path d="M6.5 3v10" stroke="currentColor" stroke-width="1.3" />
              </svg>
              <span>{{
                navVisible
                  ? $t("product.list.hideCatalogNav")
                  : $t("product.list.showCatalogNav")
              }}</span>
            </button>

            <!-- 宽屏版心：固定宽度（含档位）或撑满屏幕，偏好只存本机浏览器 -->
            <div v-if="!isMobile" class="shop-width-control">
              <button
                type="button"
                class="shop-width-trigger"
                :class="{ 'is-open': widthPanelOpen }"
                :aria-expanded="widthPanelOpen"
                aria-haspopup="dialog"
                @click.stop="toggleWidthPanel"
              >
                <svg
                  viewBox="0 0 16 16"
                  width="14"
                  height="14"
                  fill="none"
                  aria-hidden="true"
                >
                  <path
                    d="M2.5 3.5v9M13.5 3.5v9"
                    stroke="currentColor"
                    stroke-width="1.4"
                    stroke-linecap="round"
                  />
                  <path
                    d="M5 8h2M9 8h2"
                    stroke="currentColor"
                    stroke-width="1.4"
                    stroke-linecap="round"
                  />
                  <path
                    d="M7 6.2 5.4 8 7 9.8M9 6.2 10.6 8 9 9.8"
                    stroke="currentColor"
                    stroke-width="1.2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                  />
                </svg>
                <span>{{ $t("product.list.pageWidth") }}</span>
              </button>

              <div
                v-if="widthPanelOpen"
                class="shop-width-panel"
                role="dialog"
                :aria-label="$t('product.list.pageWidth')"
                @click.stop
              >
                <div class="shop-width-panel__head">
                  <strong>{{ $t("product.list.pageWidth") }}</strong>
                  <button
                    v-if="followsUserWidthChoice"
                    type="button"
                    class="shop-width-reset"
                    @click="resetLayoutWidth"
                  >
                    {{ $t("product.list.widthReset") }}
                  </button>
                </div>

                <div class="shop-width-mode">
                  <button
                    type="button"
                    :class="{ 'is-active': layoutMode === 'contained' }"
                    :aria-pressed="layoutMode === 'contained'"
                    @click="setLayoutMode('contained')"
                  >
                    {{ $t("product.list.widthContained") }}
                  </button>
                  <button
                    type="button"
                    :class="{ 'is-active': layoutMode === 'full' }"
                    :aria-pressed="layoutMode === 'full'"
                    @click="setLayoutMode('full')"
                  >
                    {{ $t("product.list.widthFull") }}
                  </button>
                </div>

                <div
                  v-if="layoutMode === 'contained'"
                  class="shop-width-values"
                >
                  <button
                    v-for="width in widthOptions"
                    :key="width"
                    type="button"
                    :class="{ 'is-active': contentMaxWidth === width }"
                    :aria-pressed="contentMaxWidth === width"
                    @click="setContentMaxWidth(width)"
                  >
                    {{ width }}
                  </button>
                </div>

                <p class="shop-width-hint">
                  {{ $t("product.list.widthHint") }}
                </p>
              </div>
            </div>
          </div>
        </div>

        <!--
            分组导航在桌面端是第二个侧栏、手机端是底部抽屉，主区域这里不再渲染
            顶部折叠面板（更早的「顶部折叠 / 侧栏」二选一开关已移除）。
          -->

        <div
          v-if="isMobile && !showCardGrid && visibleProducts.length"
          class="mobile-picker-row"
        >
          <div class="config-block-title">
            {{ $t("product.list.specSelector") }}
          </div>
          <button
            type="button"
            class="mobile-picker-trigger"
            @click="openMobileSpecPicker"
          >
            <span>{{
              mobileSpecPickerLabel || $t("product.list.selectSpec")
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
        </div>

        <!--
            展示方式：后台「商品展示」决定站点默认形态，访客可在上方切换条
            按自己的习惯选择（仅本机记忆）。两种形态共用同一份接口数据。
          -->

        <!-- 卡片式：商品卡片网格。卡片只做辨认与比价，点击进入独立的 /products/:id 配置页 -->
        <ProductCardGrid
          v-if="showCardGrid"
          class="product-card-grid-wrap"
          :products="visibleProducts"
          :show-stock="cardShowStock"
        />

        <div
          v-if="selectedProduct && productIntroText"
          class="product-intro-card"
        >
          <div class="config-block-title">
            {{ $t("product.list.productIntro") }}
          </div>
          <p>{{ productIntroText }}</p>
        </div>

        <!-- 商品配置区 -->
        <div
          class="config-area"
          v-if="selectedProduct"
          v-loading="configLoading"
        >
          <!-- 操作系统 -->
          <div v-if="osGroups.length && isMobile" class="mobile-picker-row">
            <div class="config-block-title">
              {{ $t("product.detail.operatingSystem") }}
            </div>
            <button
              type="button"
              class="mobile-picker-trigger"
              @click="openMobileOsDrawer"
            >
              <span
                >{{ currentOsGroup?.label || $t("product.detail.selectOs")
                }}{{
                  currentOsVersionLabel ? ` · ${currentOsVersionLabel}` : ""
                }}</span
              >
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
          <div
            class="config-block"
            v-if="osConfig && !isMobile && !machineConfigs.length"
          >
            <template>
              <div class="config-block-title">
                {{ $t("product.list.osInstall") }}
              </div>
              <div class="os-section">
                <div v-if="osGroups.length" class="os-cards">
                  <button
                    v-for="os in osGroups"
                    :key="os.id"
                    type="button"
                    class="os-card"
                    :class="{ active: configForm.os_group === os.id }"
                    @click="selectOsGroup(os)"
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
                        currentOsVersionLabel
                      }}</span>
                      <span v-else class="os-placeholder">{{
                        $t("product.list.selectVersion")
                      }}</span>
                    </div>
                    <span
                      class="os-check-mark"
                      v-if="configForm.os_group === os.id"
                      >✓</span
                    >
                  </button>
                </div>
                <div v-else class="spec-fixed">
                  {{ $t("product.list.noSwitchableOsVersion") }}
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
                      @click="configForm.os = ver.id"
                    >
                      {{ ver.label }}
                    </button>
                  </div>
                </div>
              </div>
            </template>
          </div>

          <!-- 机型配置 -->
          <div
            v-if="(machineConfigs.length || mobileGroupedSpecUi) && isMobile"
          >
            <div
              v-if="mobileGroupedSpecUi"
              class="mobile-picker-row mobile-picker-row--duo"
            >
              <div class="mobile-picker-col">
                <div class="config-block-title">CPU</div>
                <button
                  type="button"
                  class="mobile-picker-trigger"
                  @click="openMobileGroupedCpuDrawer"
                >
                  <span>{{
                    selectedMobileCpuLabel || $t("product.list.selectCpu")
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
              </div>
              <div class="mobile-picker-col">
                <div class="config-block-title">
                  {{ $t("product.list.memory") }}
                </div>
                <button
                  type="button"
                  class="mobile-picker-trigger"
                  @click="openMobileGroupedMemoryDrawer"
                >
                  <span>{{
                    selectedMobileMemoryLabel || $t("product.list.selectMemory")
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
              </div>
            </div>
            <div
              v-else-if="cpuConfig || memConfig"
              class="mobile-picker-row mobile-picker-row--duo"
            >
              <div v-if="cpuConfig" class="mobile-picker-col">
                <div class="config-block-title">{{ cpuConfig.label }}</div>
                <button
                  type="button"
                  class="mobile-picker-trigger"
                  @click="openMobileCpuDrawer"
                >
                  <span>{{
                    cpuConfig.options.find(
                      (o) => o.id === configForm[cpuConfig.key],
                    )?.label || $t("product.list.selectOption")
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
              </div>
              <div v-if="memConfig" class="mobile-picker-col">
                <div class="config-block-title">{{ memConfig.label }}</div>
                <button
                  type="button"
                  class="mobile-picker-trigger"
                  @click="openMobileMemDrawer"
                >
                  <span>{{
                    memConfig.options.find(
                      (o) => o.id === configForm[memConfig.key],
                    )?.label || $t("product.list.selectOption")
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
              </div>
            </div>
            <div v-if="otherMachineConfigs.length" class="config-block">
              <div class="spec-list">
                <template v-for="cfg in otherMachineConfigs" :key="cfg.key">
                  <MobileRangePicker
                    v-if="isRangeConfig(cfg)"
                    v-model="configForm[cfg.key + '_num']"
                    :label="cfg.label"
                    :min="cfg.min ?? 1"
                    :max="cfg.max ?? 9999"
                  />
                  <button
                    v-else-if="cfg.options.length"
                    type="button"
                    class="mobile-config-select-row"
                    @click="openMobileSingleConfigDrawer(cfg)"
                  >
                    <span class="mobile-config-select-label">{{
                      cfg.label
                    }}</span>
                    <span class="mobile-config-select-value">
                      {{
                        selectedOptionLabel(cfg) ||
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
                  <div v-else class="spec-row">
                    <span class="spec-label">{{ cfg.label }}</span>
                    <MobileRangePicker
                      v-if="cfg.isNumber"
                      v-model="configForm[cfg.key + '_num']"
                      :label="cfg.label"
                      :min="cfg.min ?? 1"
                      :max="cfg.max ?? 9999"
                    />
                    <div class="opt-row" v-else-if="cfg.options.length > 1">
                      <button
                        v-for="opt in cfg.options"
                        :key="opt.id"
                        type="button"
                        class="opt-btn"
                        :class="{ active: configForm[cfg.key] === opt.id }"
                        @click="configForm[cfg.key] = opt.id"
                      >
                        {{ opt.label }}
                      </button>
                    </div>
                    <span v-else class="spec-fixed">{{
                      cfg.options[0]?.label
                    }}</span>
                  </div>
                </template>
              </div>
            </div>
          </div>
          <div
            class="config-block"
            v-if="desktopMachineSpecRows.length && !isMobile"
          >
            <div class="config-block-title">
              {{ $t("product.detail.serverSpecs") }}
            </div>
            <div
              v-if="desktopMachineSpecRows.length"
              class="desktop-machine-spec-panel"
            >
              <div
                class="desktop-machine-table"
                :style="machineSpecGridStyle"
                role="table"
                :aria-label="$t('product.detail.serverSpecs')"
              >
                <div class="desktop-machine-table-head" role="row">
                  <span class="machine-spec-cell machine-spec-cell--name">{{
                    $t("product.list.instanceSpecs")
                  }}</span>
                  <span
                    v-if="showCpuMemoryColumns"
                    class="machine-spec-cell machine-spec-cell--sortable"
                  >
                    <span>vCPU</span>
                    <span
                      class="machine-spec-sort-controls"
                      :aria-label="$t('product.list.sortVcpu')"
                    >
                      <button
                        type="button"
                        class="machine-spec-sort-btn"
                        :class="{
                          active:
                            machineSpecSort.key === 'cpu' &&
                            machineSpecSort.direction === 'asc',
                        }"
                        :aria-label="$t('product.list.sortVcpuAsc')"
                        @click="setMachineSpecSort('cpu', 'asc')"
                      >
                        <el-icon><CaretTop /></el-icon>
                      </button>
                      <button
                        type="button"
                        class="machine-spec-sort-btn"
                        :class="{
                          active:
                            machineSpecSort.key === 'cpu' &&
                            machineSpecSort.direction === 'desc',
                        }"
                        :aria-label="$t('product.list.sortVcpuDesc')"
                        @click="setMachineSpecSort('cpu', 'desc')"
                      >
                        <el-icon><CaretBottom /></el-icon>
                      </button>
                    </span>
                  </span>
                  <span
                    v-if="showCpuMemoryColumns"
                    class="machine-spec-cell machine-spec-cell--sortable"
                  >
                    <span>{{ $t("product.list.memory") }}</span>
                    <span
                      class="machine-spec-sort-controls"
                      :aria-label="$t('product.list.sortMemory')"
                    >
                      <button
                        type="button"
                        class="machine-spec-sort-btn"
                        :class="{
                          active:
                            machineSpecSort.key === 'memory' &&
                            machineSpecSort.direction === 'asc',
                        }"
                        :aria-label="$t('product.list.sortMemoryAsc')"
                        @click="setMachineSpecSort('memory', 'asc')"
                      >
                        <el-icon><CaretTop /></el-icon>
                      </button>
                      <button
                        type="button"
                        class="machine-spec-sort-btn"
                        :class="{
                          active:
                            machineSpecSort.key === 'memory' &&
                            machineSpecSort.direction === 'desc',
                        }"
                        :aria-label="$t('product.list.sortMemoryDesc')"
                        @click="setMachineSpecSort('memory', 'desc')"
                      >
                        <el-icon><CaretBottom /></el-icon>
                      </button>
                    </span>
                  </span>
                  <span
                    v-if="showProcessorColumns"
                    class="machine-spec-cell machine-spec-cell--processor"
                    >{{ $t("product.list.processor") }}</span
                  >
                  <span v-if="showProcessorColumns" class="machine-spec-cell">{{
                    $t("product.list.clockSpeed")
                  }}</span>
                  <span
                    v-for="column in specHighlightColumns"
                    :key="column.key"
                    class="machine-spec-cell machine-spec-cell--highlight"
                    >{{ column.label }}</span
                  >
                  <span
                    class="machine-spec-cell machine-spec-cell--price machine-spec-cell--sortable"
                  >
                    <span>{{ $t("product.detail.basePrice") }}</span>
                    <span
                      class="machine-spec-sort-controls"
                      :aria-label="$t('product.list.sortPrice')"
                    >
                      <button
                        type="button"
                        class="machine-spec-sort-btn"
                        :class="{
                          active:
                            machineSpecSort.key === 'price' &&
                            machineSpecSort.direction === 'asc',
                        }"
                        :aria-label="$t('product.list.sortPriceAsc')"
                        @click="setMachineSpecSort('price', 'asc')"
                      >
                        <el-icon><CaretTop /></el-icon>
                      </button>
                      <button
                        type="button"
                        class="machine-spec-sort-btn"
                        :class="{
                          active:
                            machineSpecSort.key === 'price' &&
                            machineSpecSort.direction === 'desc',
                        }"
                        :aria-label="$t('product.list.sortPriceDesc')"
                        @click="setMachineSpecSort('price', 'desc')"
                      >
                        <el-icon><CaretBottom /></el-icon>
                      </button>
                    </span>
                  </span>
                </div>
                <button
                  v-for="row in desktopMachineSpecRows"
                  :key="row.id"
                  type="button"
                  class="desktop-machine-table-row"
                  :class="{ active: row.active }"
                  role="row"
                  @click="selectDesktopMachineSpec(row)"
                >
                  <span class="machine-spec-cell machine-spec-cell--name">
                    <span class="machine-spec-radio" aria-hidden="true"></span>
                    <span class="machine-spec-name-wrap">
                      <span class="machine-spec-name">{{ row.name }}</span>
                      <el-tooltip
                        v-if="row.note"
                        placement="top-start"
                        :show-after="120"
                        effect="light"
                        popper-class="spec-note-popper"
                      >
                        <template #content>
                          <div class="spec-note-content">{{ row.note }}</div>
                        </template>
                        <span
                          class="spec-note-trigger"
                          role="button"
                          tabindex="0"
                          :aria-label="$t('product.list.viewSpecNotes')"
                          @click.stop
                          @keydown.enter.stop.prevent
                          @keydown.space.stop.prevent
                          >?</span
                        >
                      </el-tooltip>
                    </span>
                  </span>
                  <span v-if="showCpuMemoryColumns" class="machine-spec-cell">{{
                    row.cpuText
                  }}</span>
                  <span v-if="showCpuMemoryColumns" class="machine-spec-cell">{{
                    row.memoryText
                  }}</span>
                  <span
                    v-if="showProcessorColumns"
                    class="machine-spec-cell machine-spec-cell--processor"
                  >
                    <span
                      v-if="cpuBrandBadgeOf(row)"
                      class="cpu-brand-badge"
                      :class="cpuBrandBadgeOf(row).className"
                    >
                      <span class="cpu-brand-badge__icon">{{
                        cpuBrandBadgeOf(row).icon
                      }}</span>
                      <span class="cpu-brand-badge__label">{{
                        cpuBrandBadgeOf(row).label
                      }}</span>
                    </span>
                    <span class="machine-spec-processor-text">{{
                      row.processorLabel
                    }}</span>
                  </span>
                  <span v-if="showProcessorColumns" class="machine-spec-cell">{{
                    formatFrequencyPair(row.baseFrequency, row.turboFrequency)
                  }}</span>
                  <span
                    v-for="column in specHighlightColumns"
                    :key="column.key"
                    class="machine-spec-cell machine-spec-cell--highlight"
                    >{{ rowHighlightValue(row, column.key) }}</span
                  >
                  <span class="machine-spec-cell machine-spec-cell--price">
                    <template v-if="row.agentDiscount">
                      <span class="agent-price-original"
                        >¥{{ row.agentOriginalPrice }}</span
                      >
                      <em
                        v-if="row.agentDiscountPercent"
                        class="discount-tag"
                        >{{ row.agentDiscountPercent }}</em
                      >
                      <span class="agent-price-current"
                        >¥{{ row.agentPrice }}</span
                      >
                    </template>
                    <template v-else>{{ row.basePriceText }}</template>
                  </span>
                </button>
              </div>
              <div v-if="osConfig" class="desktop-machine-os-panel">
                <div class="desktop-machine-subtitle">
                  {{ $t("product.list.osInstall") }}
                </div>
                <div class="os-section">
                  <div v-if="osGroups.length" class="os-cards">
                    <button
                      v-for="os in osGroups"
                      :key="os.id"
                      type="button"
                      class="os-card"
                      :class="{ active: configForm.os_group === os.id }"
                      @click="selectOsGroup(os)"
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
                          currentOsVersionLabel
                        }}</span>
                        <span v-else class="os-placeholder">{{
                          $t("product.list.selectVersion")
                        }}</span>
                      </div>
                      <span
                        class="os-check-mark"
                        v-if="configForm.os_group === os.id"
                        >✓</span
                      >
                    </button>
                  </div>
                  <div v-else class="spec-fixed">
                    {{ $t("product.list.noSwitchableOsVersion") }}
                  </div>
                  <div
                    class="os-ver-row"
                    v-if="currentOsGroup?.versions?.length"
                  >
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
                        @click="configForm.os = ver.id"
                      >
                        {{ ver.label }}
                      </button>
                    </div>
                  </div>
                </div>
              </div>
              <div
                v-if="
                  desktopMachineCoreConfigs.length ||
                  desktopMachineExtraConfigs.length
                "
                class="desktop-machine-extra-list"
              >
                <!-- CPU / 内存 同样是配置项时必须照常渲染：这个分支只取
                       otherMachineConfigs（已排除 CPU/内存），而机型配置表里的
                       vCPU / 内存 列只是展示当前选中值、不是选择控件。
                       否则 CPU/内存 由配置项下发的商品（如「弹性游戏云」，
                       上游可选 2/4/6/8/16 核、4~32G）在桌面端根本选不了，
                       只能吃商品默认规格。移动端一直是渲染的，这里补齐对齐。

                       判断条件必须带上 desktopMachineCoreConfigs：下面那个
                       v-else 是机型表（desktopMachineSpecRows）的 else，不是
                       本分支的，所以「只有 CPU/内存、没有其它机型附加项」的商品
                       一旦有机型表，两个分支都不会命中，选择控件会整个消失。 -->
                <template
                  v-for="cfg in desktopMachineCoreConfigs"
                  :key="cfg.key"
                >
                  <MobileRangePicker
                    v-if="cfg.isNumber"
                    v-model="configForm[cfg.key + '_num']"
                    :label="cfg.label"
                    :min="cfg.min ?? 1"
                    :max="cfg.max ?? 9999"
                  />
                  <div v-else class="spec-row spec-row--desktop-extra">
                    <span class="spec-label">{{ cfg.label }}</span>
                    <div class="opt-row" v-if="cfg.options.length > 1">
                      <button
                        v-for="opt in cfg.options"
                        :key="opt.id"
                        type="button"
                        class="opt-btn"
                        :class="{ active: configForm[cfg.key] === opt.id }"
                        @click="configForm[cfg.key] = opt.id"
                      >
                        {{ opt.label }}
                      </button>
                    </div>
                    <span v-else class="spec-fixed">{{
                      cfg.options[0]?.label
                    }}</span>
                  </div>
                </template>
                <template
                  v-for="cfg in desktopMachineExtraConfigs"
                  :key="cfg.key"
                >
                  <MobileRangePicker
                    v-if="cfg.isNumber"
                    v-model="configForm[cfg.key + '_num']"
                    :label="cfg.label"
                    :min="cfg.min ?? 1"
                    :max="cfg.max ?? 9999"
                  />
                  <div v-else class="spec-row spec-row--desktop-extra">
                    <span class="spec-label">{{ cfg.label }}</span>
                    <div class="opt-row" v-if="cfg.options.length > 1">
                      <button
                        v-for="opt in cfg.options"
                        :key="opt.id"
                        type="button"
                        class="opt-btn"
                        :class="{ active: configForm[cfg.key] === opt.id }"
                        @click="configForm[cfg.key] = opt.id"
                      >
                        {{ opt.label }}
                      </button>
                    </div>
                    <span v-else class="spec-fixed">{{
                      cfg.options[0]?.label
                    }}</span>
                  </div>
                </template>
              </div>
            </div>
            <!-- 注意：这个 v-else 属于上面 desktopMachineSpecRows 的 v-if
                   （没有机型表时兜底渲染全部机型配置），不是
                   desktop-machine-extra-list 的 else。 -->
            <div v-else class="spec-list">
              <template v-for="cfg in machineConfigs" :key="cfg.key">
                <MobileRangePicker
                  v-if="cfg.isNumber"
                  v-model="configForm[cfg.key + '_num']"
                  :label="cfg.label"
                  :min="cfg.min ?? 1"
                  :max="cfg.max ?? 9999"
                />
                <div v-else class="spec-row">
                  <span class="spec-label">{{ cfg.label }}</span>
                  <div class="opt-row" v-if="cfg.options.length > 1">
                    <button
                      v-for="opt in cfg.options"
                      :key="opt.id"
                      type="button"
                      class="opt-btn"
                      :class="{ active: configForm[cfg.key] === opt.id }"
                      @click="configForm[cfg.key] = opt.id"
                    >
                      {{ opt.label }}
                    </button>
                  </div>
                  <span v-else class="spec-fixed">{{
                    cfg.options[0]?.label
                  }}</span>
                </div>
              </template>
            </div>
          </div>

          <!-- 网络配置 -->
          <div class="config-block" v-if="networkConfigs.length">
            <div class="config-block-title">
              {{ $t("product.detail.network") }}
            </div>
            <div class="spec-list">
              <template v-for="cfg in networkConfigs" :key="cfg.key">
                <MobileRangePicker
                  v-if="isMobile && isRangeConfig(cfg)"
                  v-model="configForm[cfg.key + '_num']"
                  :label="cfg.label"
                  :min="cfg.min ?? 1"
                  :max="cfg.max ?? 9999"
                />
                <button
                  v-else-if="isMobile && cfg.options.length"
                  type="button"
                  class="mobile-config-select-row"
                  @click="openMobileSingleConfigDrawer(cfg)"
                >
                  <span class="mobile-config-select-label">{{
                    cfg.label
                  }}</span>
                  <span class="mobile-config-select-value">
                    {{
                      selectedOptionLabel(cfg) ||
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
                <MobileRangePicker
                  v-else-if="cfg.isNumber"
                  v-model="configForm[cfg.key + '_num']"
                  :label="cfg.label"
                  :min="cfg.min ?? 1"
                  :max="cfg.max ?? 9999"
                />
                <div v-else class="spec-row">
                  <span class="spec-label">{{ cfg.label }}</span>
                  <div class="opt-row" v-if="cfg.options.length > 1">
                    <button
                      v-for="opt in cfg.options"
                      :key="opt.id"
                      type="button"
                      class="opt-btn"
                      :class="{ active: configForm[cfg.key] === opt.id }"
                      @click="configForm[cfg.key] = opt.id"
                    >
                      {{ opt.label }}
                    </button>
                  </div>
                  <span v-else class="spec-fixed">{{
                    cfg.options[0]?.label
                  }}</span>
                </div>
              </template>
            </div>
          </div>

          <!-- 其他配置（兜底） -->
          <div class="config-block" v-if="otherConfigs.length">
            <div class="config-block-title">
              {{ $t("product.list.otherConfig") }}
            </div>
            <div class="spec-list">
              <template v-for="cfg in otherConfigs" :key="cfg.key">
                <MobileRangePicker
                  v-if="isMobile && isRangeConfig(cfg)"
                  v-model="configForm[cfg.key + '_num']"
                  :label="cfg.label"
                  :min="cfg.min ?? 1"
                  :max="cfg.max ?? 9999"
                />
                <button
                  v-else-if="isMobile && cfg.options.length"
                  type="button"
                  class="mobile-config-select-row"
                  @click="openMobileSingleConfigDrawer(cfg)"
                >
                  <span class="mobile-config-select-label">{{
                    cfg.label
                  }}</span>
                  <span class="mobile-config-select-value">
                    {{
                      selectedOptionLabel(cfg) ||
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
                <MobileRangePicker
                  v-else-if="cfg.isNumber"
                  v-model="configForm[cfg.key + '_num']"
                  :label="cfg.label"
                  :min="cfg.min ?? 1"
                  :max="cfg.max ?? 9999"
                />
                <div v-else class="spec-row">
                  <span class="spec-label">{{ cfg.label }}</span>
                  <div class="opt-row" v-if="cfg.options.length > 1">
                    <button
                      v-for="opt in cfg.options"
                      :key="opt.id"
                      type="button"
                      class="opt-btn"
                      :class="{ active: configForm[cfg.key] === opt.id }"
                      @click="configForm[cfg.key] = opt.id"
                    >
                      {{ opt.label }}
                    </button>
                  </div>
                </div>
              </template>
            </div>
          </div>

          <!-- 购买须知：上游以普通配置项下发的文字提示（如「明确禁止」），只展示不参与选配 -->
          <div class="config-block" v-if="noticeConfigs.length">
            <div class="config-block-title">
              {{ $t("product.detail.purchaseNotes") }}
            </div>
            <div class="spec-list">
              <div
                class="spec-row notice-row"
                v-for="cfg in noticeConfigs"
                :key="cfg.key"
              >
                <span class="spec-label" v-if="cfg.label">{{ cfg.label }}</span>
                <div class="notice-text">{{ cfg.textContent }}</div>
              </div>
            </div>
          </div>

          <!-- 基础设置 -->
          <div class="config-block">
            <div class="config-block-title">
              {{ $t("product.detail.baseSettings") }}
            </div>
            <div class="spec-list">
              <!-- 计费周期 -->
              <div class="spec-row">
                <span class="spec-label">{{
                  $t("product.detail.billingCycle")
                }}</span>
                <div class="opt-row">
                  <button
                    v-for="item in pricingEntries"
                    :key="item.cycle"
                    type="button"
                    class="opt-btn cycle-btn"
                    :class="{ active: selectedCycle === item.cycle }"
                    @click="selectedCycle = item.cycle"
                  >
                    <span class="cycle-name">{{ item.label }}</span>
                    <span class="cycle-amt">¥{{ item.amount }}</span>
                  </button>
                </div>
              </div>

              <!-- 单笔订单仅支持创建一台服务实例，避免多台计价但仅开通一台。 -->
              <div class="spec-row">
                <span class="spec-label">{{
                  $t("product.detail.quantity")
                }}</span>
                <span>{{ $t("product.detail.quantityHint") }}</span>
              </div>
            </div>
          </div>
        </div>

        <el-empty
          v-if="
            !pageLoading &&
            !configLoading &&
            !selectedProduct &&
            !visibleProducts.length
          "
          :description="$t('product.list.empty')"
          style="padding: 60px 0"
        />
      </div>

      <!-- 右侧费用摘要 -->
      <aside class="shop-cost" v-if="selectedProduct">
        <!-- 明细区：面板内唯一的滚动容器，内容再长也不会挤压下方结算栏 -->
        <div class="cost-scroll">
          <div class="cost-header">
            <span class="cost-title">{{ $t("product.detail.configFee") }}</span>
            <span class="stock-badge" :class="stockClass">{{
              stockLabel
            }}</span>
          </div>

          <div class="stock-info" v-if="selectedProduct">
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
            <div class="stock-hint">{{ stockHint }}</div>
          </div>

          <el-alert
            v-if="purchaseRequirementList.length"
            type="warning"
            :closable="false"
            show-icon
            class="purchase-requirements-alert"
          >
            <template #title
              >{{ $t("product.list.purchaseRequirements")
              }}{{ purchaseRequirementSummary }}</template
            >
          </el-alert>

          <div class="cost-divider"></div>

          <div
            class="cost-detail"
            :class="{ 'cost-detail--loading': quoteLoading }"
          >
            <div class="cost-item">
              <span>{{ $t("layout.nav.products") }}</span
              ><span>{{
                selectedProductSummaryName || selectedProductDisplayName
              }}</span>
            </div>
            <div class="cost-item" v-for="item in summaryItems" :key="item.key">
              <span>{{ item.label }}</span
              ><span>{{ item.value }}</span>
            </div>
            <div class="cost-item" v-if="selectedCycleLabel">
              <span>{{ $t("product.list.cycle") }}</span
              ><span>{{ selectedCycleLabel }}</span>
            </div>
          </div>

          <div class="cost-divider"></div>

          <div
            class="cost-breakdown"
            :class="{ 'cost-breakdown--loading': quoteLoading }"
          >
            <div class="cost-item">
              <span>{{ $t("product.detail.basePrice") }}</span
              ><span>¥{{ baseAmount }}</span>
            </div>
            <div class="cost-item" v-if="Number(setupFee) > 0">
              <span>{{ $t("product.detail.setupFee") }}</span
              ><span>¥{{ setupFee }}</span>
            </div>
            <div
              class="cost-item cost-item--extra"
              v-for="item in quoteItems"
              :key="item.field"
            >
              <span>+ {{ item.label }}</span
              ><span>¥{{ item.amount }}</span>
            </div>
            <div class="cost-item cost-item--discount" v-if="appliedCoupon">
              <span
                >{{ $t("product.detail.coupon") }}
                {{ appliedCoupon.code }}</span
              ><span>-¥{{ appliedCoupon.discount_amount }}</span>
            </div>
            <div class="cost-item cost-item--discount" v-if="hasAgentDiscount">
              <span
                >{{ $t("product.list.agentDiscount", { name: agentGroupName })
                }}<em class="discount-tag">{{ agentDiscountPercent }}</em></span
              ><span>-¥{{ agentDiscountAmount }}</span>
            </div>
          </div>

          <div class="cost-divider"></div>

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
              v-else-if="quoteLoading"
              class="coupon-panel-tip coupon-panel-tip--muted"
            >
              {{ $t("product.coupon.loading") }}
            </div>
            <div
              v-else-if="!availableCoupons.length"
              class="coupon-panel-tip coupon-panel-tip--muted"
            >
              {{
                selectedProduct
                  ? $t("product.coupon.noneAvailable")
                  : $t("product.coupon.selectProductFirst")
              }}
            </div>
          </div>

          <div class="cost-divider"></div>

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
              <template v-else-if="hasAgentDiscount">
                <span class="cost-amount cost-amount--original"
                  >¥{{ agentOriginalAmount }}</span
                >
                <em v-if="agentDiscountPercent" class="discount-tag">{{
                  agentDiscountPercent
                }}</em>
                <span class="cost-amount">¥{{ agentAmount }}</span>
              </template>
              <template v-else>
                <span class="cost-currency">¥</span>
                <span class="cost-amount">{{ totalPrice }}</span>
              </template>
              <span class="cost-cycle"
                >/{{ selectedCycleLabel || $t("product.list.monthly") }}</span
              >
            </div>
          </div>
        </div>
        <!-- 结算栏：按钮常驻面板底部，不会盖住上方明细 -->
        <div class="cost-footer">
          <button
            class="buy-btn"
            :disabled="soldOut || !canSubmit || submitting || quoteLoading"
            :class="{ loading: submitting, 'is-sold-out': soldOut }"
            @click="handleSubmit"
          >
            <span>{{
              soldOut
                ? $t("product.card.soldOut")
                : submitting
                  ? $t("product.list.submitting")
                  : $t("product.list.buyNow")
            }}</span>
          </button>
        </div>
      </aside>
    </div>

    <div
      v-if="selectedProduct"
      ref="allocationFooterEl"
      class="allocation-footer"
      :class="{ 'allocation-footer--anchored': allocationFooterAnchored }"
    >
      <div ref="allocationFooterInnerEl" class="allocation-footer-inner">
        <div class="allocation-footer-main">
          <div class="allocation-footer-summary">
            <span class="allocation-footer-label">{{
              $t("product.list.totalCostLabel")
            }}</span>
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
                <span class="allocation-footer-num">{{ agentAmount }}</span>
              </template>
              <template v-else>
                <span class="allocation-footer-symbol">¥</span>
                <span class="allocation-footer-num">{{ totalPrice }}</span>
              </template>
            </div>
            <span class="allocation-footer-discount-text">
              {{
                hasAgentDiscount
                  ? $t("product.list.agentDiscountPercent", {
                      name: agentGroupName,
                      percent: agentDiscountPercent,
                    })
                  : appliedCoupon
                    ? $t("product.list.couponApplied", {
                        amount: appliedCoupon.discount_amount,
                      })
                    : $t("product.list.noDiscount")
              }}
            </span>
          </div>
          <div class="allocation-footer-meta">
            <span v-if="stockLabel">{{ stockLabel }}</span>
            <span v-if="selectedCycleLabel">{{ selectedCycleLabel }}</span>
            <button
              type="button"
              class="allocation-footer-coupon-btn"
              @click="mobileCouponDrawer = true"
            >
              <el-icon class="allocation-footer-coupon-icon"
                ><Ticket
              /></el-icon>
              {{
                appliedCoupon
                  ? $t("product.coupon.usedAmount", {
                      amount: appliedCoupon.discount_amount,
                    })
                  : $t("product.coupon.use")
              }}
            </button>
          </div>
        </div>
        <div class="allocation-footer-actions">
          <button
            class="allocation-footer-action"
            :disabled="soldOut || !canSubmit || submitting || quoteLoading"
            :class="{ loading: submitting, 'is-sold-out': soldOut }"
            @click="handleSubmit"
          >
            <span>{{
              soldOut
                ? $t("product.card.soldOut")
                : submitting
                  ? $t("product.list.submitting")
                  : $t("product.list.addToCart")
            }}</span>
          </button>
        </div>
        <div class="allocation-footer-links">
          <button
            v-if="productIntroText"
            type="button"
            class="allocation-footer-detail-btn"
            @click="mobileIntroDrawer = true"
          >
            <el-icon class="allocation-footer-detail-icon"
              ><Document
            /></el-icon>
            {{ $t("product.list.productIntro") }}
          </button>
          <button
            type="button"
            class="allocation-footer-detail-btn"
            @click="mobileCostDrawer = true"
          >
            <el-icon class="allocation-footer-detail-icon"
              ><Document
            /></el-icon>
            {{ $t("product.detail.costBreakdown") }}
          </button>
        </div>
      </div>
    </div>

    <MobileSheet
      v-model="mobileIntroDrawer"
      size="40%"
      :title="$t('product.list.productIntro')"
      :confirm-text="$t('common.close')"
      @confirm="mobileIntroDrawer = false"
    >
      <div class="mobile-intro-sheet-body">
        <p>{{ productIntroText }}</p>
      </div>
    </MobileSheet>

    <MobileSheet
      v-model="mobileCostDrawer"
      size="78%"
      :title="$t('product.detail.costBreakdown')"
      :confirm-text="$t('common.close')"
      @confirm="mobileCostDrawer = false"
    >
      <div class="mobile-cost-sheet-body">
        <el-alert
          v-if="purchaseRequirementList.length"
          type="warning"
          :closable="false"
          show-icon
          class="purchase-requirements-alert purchase-requirements-alert--mobile"
        >
          <template #title
            >{{ $t("product.list.purchaseRequirements")
            }}{{ purchaseRequirementSummary }}</template
          >
        </el-alert>

        <div class="mobile-cost-table-card">
          <div class="mobile-cost-table-head">
            <span>{{ $t("product.list.costDetailsLabel") }}</span>
            <strong>{{
              $t("product.list.costRowsCount", { count: mobileCostRows.length })
            }}</strong>
          </div>
          <div class="mobile-cost-table">
            <div class="mobile-cost-table-header">
              <span>{{ $t("product.list.configName") }}</span>
              <span>{{ $t("product.list.configDetail") }}</span>
              <span>{{
                hasAgentDiscount || appliedCoupon
                  ? $t("product.list.priceDiscounted")
                  : $t("product.list.priceNoDiscount")
              }}</span>
            </div>
            <div
              class="mobile-cost-table-row"
              v-for="row in mobileCostRows"
              :key="row.key"
            >
              <span class="mobile-cost-table-name">{{ row.label }}</span>
              <span class="mobile-cost-table-detail">{{ row.detail }}</span>
              <span class="mobile-cost-table-amount">¥{{ row.amount }}</span>
            </div>
            <div class="mobile-cost-table-total">
              <span>{{ $t("product.list.grandTotalLabel") }}</span>
              <strong>¥{{ totalPrice }}</strong>
            </div>
          </div>
        </div>
      </div>
    </MobileSheet>

    <MobileSheet
      v-model="mobileCouponDrawer"
      size="42%"
      :title="$t('product.detail.coupon')"
      :confirm-text="$t('common.close')"
      @confirm="mobileCouponDrawer = false"
    >
      <div class="mobile-coupon-sheet-body">
        <div class="coupon-panel">
          <div class="coupon-panel-head">
            <span class="coupon-panel-title">{{
              $t("product.list.selectCoupon")
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
            v-else-if="quoteLoading"
            class="coupon-panel-tip coupon-panel-tip--muted"
          >
            {{ $t("product.coupon.loading") }}
          </div>
          <div
            v-else-if="!availableCoupons.length"
            class="coupon-panel-tip coupon-panel-tip--muted"
          >
            {{
              selectedProduct
                ? $t("product.coupon.noneAvailable")
                : $t("product.coupon.selectProductFirst")
            }}
          </div>
        </div>
      </div>
    </MobileSheet>

    <MobileOsPicker
      :visible="mobileOsDrawer"
      :os-groups="osGroups"
      :active-os-group-id="configForm.os_group || ''"
      :active-os-version-id="configForm.os || ''"
      @close="mobileOsDrawer = false"
      @confirm="confirmOsSelection"
    />

    <MobileOptionPicker
      v-if="cpuConfig"
      :visible="mobileCpuDrawer"
      :title="cpuConfig.label"
      :options="cpuConfig.options"
      :active-id="configForm[cpuConfig.key] || ''"
      @close="mobileCpuDrawer = false"
      @confirm="confirmCpuSelection"
    />

    <MobileOptionPicker
      v-if="memConfig"
      :visible="mobileMemDrawer"
      :title="memConfig.label"
      :options="memConfig.options"
      :active-id="configForm[memConfig.key] || ''"
      @close="mobileMemDrawer = false"
      @confirm="confirmMemSelection"
    />

    <MobileOptionPicker
      v-if="mobileGroupedSpecUi"
      :visible="mobileSpecFamilyDrawer"
      :title="$t('product.list.spec')"
      :options="mobileSpecFamilyOptions"
      :active-id="selectedMobileSpecFamily?.key || ''"
      @close="mobileSpecFamilyDrawer = false"
      @confirm="confirmMobileSpecFamilySelection"
    />

    <MobileOptionPicker
      v-if="mobileGroupedSpecUi"
      :visible="mobileGroupedCpuDrawer"
      title="CPU"
      :options="mobileGroupedCpuOptions"
      :active-id="selectedMobileProductGroup?.key || ''"
      @close="mobileGroupedCpuDrawer = false"
      @confirm="confirmMobileGroupedCpuSelection"
    />

    <MobileOptionPicker
      v-if="mobileGroupedSpecUi"
      :visible="mobileGroupedMemoryDrawer"
      :title="$t('product.list.memory')"
      :options="mobileGroupedMemoryOptions"
      :active-id="String(selectedProductId || '')"
      @close="mobileGroupedMemoryDrawer = false"
      @confirm="confirmMobileGroupedMemorySelection"
    />

    <MobileOptionPicker
      v-if="mobileSingleConfig"
      :visible="mobileSingleConfigDrawer"
      :title="mobileSingleConfig.label"
      :options="mobileSingleConfig.options"
      :active-id="configForm[mobileSingleConfig.key] || ''"
      @close="closeMobileSingleConfigDrawer"
      @confirm="confirmMobileSingleConfigSelection"
    />

    <MobileSheet
      v-model="mobileProductDrawer"
      size="40%"
      :title="$t('product.list.selectSpec')"
      :cancel-text="$t('common.cancel')"
      :confirm-text="$t('common.confirm')"
      @confirm="confirmMobileProductSelection"
    >
      <div class="mobile-product-sheet-list">
        <template v-if="mobileGroupedSpecUi">
          <button
            v-for="group in mobileProductGroups"
            :key="group.key"
            type="button"
            class="mobile-product-sheet-item"
            :class="{ active: mobilePendingProductGroupKey === group.key }"
            @click="mobilePendingProductGroupKey = group.key"
          >
            <span class="mobile-product-sheet-name-wrap">
              <span class="mobile-product-sheet-name">{{ group.label }}</span>
              <el-popover
                v-if="group.note"
                placement="top-start"
                trigger="click"
                :width="260"
                popper-class="spec-note-popper"
              >
                <template #reference>
                  <span
                    class="spec-note-trigger"
                    role="button"
                    tabindex="0"
                    :aria-label="$t('product.list.viewSpecNotes')"
                    @click.stop
                    @keydown.enter.stop.prevent
                    @keydown.space.stop.prevent
                    >?</span
                  >
                </template>
                <div class="spec-note-content">{{ group.note }}</div>
              </el-popover>
            </span>
            <span class="mobile-product-sheet-meta">{{ group.cpuText }}</span>
          </button>
        </template>
        <template v-else>
          <button
            v-for="p in visibleProducts"
            :key="p.id"
            type="button"
            class="mobile-product-sheet-item"
            :class="{ active: mobilePendingProductId === p.id }"
            @click="mobilePendingProductId = p.id"
          >
            <span class="mobile-product-sheet-name-wrap">
              <span class="mobile-product-sheet-name">{{
                resolveProductDisplayName(p)
              }}</span>
              <el-popover
                v-if="normalizeInstanceSpecNote(p.instance_spec_note)"
                placement="top-start"
                trigger="click"
                :width="260"
                popper-class="spec-note-popper"
              >
                <template #reference>
                  <span
                    class="spec-note-trigger"
                    role="button"
                    tabindex="0"
                    :aria-label="$t('product.list.viewSpecNotes')"
                    @click.stop
                    @keydown.enter.stop.prevent
                    @keydown.space.stop.prevent
                    >?</span
                  >
                </template>
                <div class="spec-note-content">
                  {{ normalizeInstanceSpecNote(p.instance_spec_note) }}
                </div>
              </el-popover>
            </span>
            <span
              v-if="formatProductListPrice(p)"
              class="mobile-product-sheet-price"
            >
              <template v-if="p?.has_agent_discount">
                <span class="agent-price-original"
                  >¥{{ formatMoneyText(p.primary_price) }}</span
                >
                <em v-if="p?.agent_discount_percent" class="discount-tag">{{
                  p.agent_discount_percent
                }}</em>
                <span class="agent-price-current"
                  >¥{{ p.agent_primary_price }}</span
                >
              </template>
              <template v-else>{{ formatProductListPrice(p) }}</template>
            </span>
          </button>
        </template>
      </div>
    </MobileSheet>

    <!-- 手机端目录抽屉：一级分组标签 + 二/三级分组钻取 -->
    <MobileSheet
      v-model="mobileCatalogDrawer"
      size="82%"
      :title="$t('product.list.catalogTitle')"
      :cancel-text="$t('common.close')"
    >
      <MobileCatalogPicker
        :visible="mobileCatalogDrawer"
        :types="productTypes"
        :active-type="activeTypeValue"
        :groups="rootGroups"
        :child-groups="childGroups"
        :active-group-id="activeGroupId"
        :active-child-id="activeChildId"
        :loading="pageLoading"
        @select-type="handleMobileSelectType"
        @select-group="handleGroupNavSelectGroup"
        @select-child="handleGroupNavSelectChild"
        @done="mobileCatalogDrawer = false"
      />
    </MobileSheet>
  </div>
</template>

<script setup>
import {
  computed,
  nextTick,
  onBeforeUnmount,
  onMounted,
  ref,
  watch,
} from "vue";
import {
  CaretBottom,
  CaretTop,
  Document,
  Expand,
  Fold,
  Ticket,
} from "@element-plus/icons-vue";
import { useWebsiteProductsPage } from "@/domains/products/useWebsiteProductsPage";
import { resolveCpuBrandBadge } from "@/domains/products/cpuBrandBadge";
import MobileOsPicker from "@/components/MobileOsPicker.vue";
import MobileOptionPicker from "@/components/MobileOptionPicker.vue";
import MobileRangePicker from "@/components/MobileRangePicker.vue";
import MobileSheet from "@/components/MobileSheet.vue";
import { t } from "@/locales";
import { resolveProductDisplayName } from "@/utils/websiteProductConfig";
import MobileCatalogPicker from "./MobileCatalogPicker.vue";
import ProductCardGrid from "./ProductCardGrid.vue";
import DisplayModeSwitch from "./DisplayModeSwitch.vue";
import ProductGroupNav from "./ProductGroupNav.vue";
import {
  DISPLAY_MODE_CARD,
  DISPLAY_MODE_LIST,
  useProductDisplayMode,
} from "./useProductDisplayMode";
import {
  useProductDisplaySettings,
  PRODUCT_PAGE_WIDTH_PRESETS,
} from "./useProductDisplaySettings";
import { formatMoneyText, formatProductListPrice } from "./productPrice";
import {
  buildInstanceSpecName,
  buildMachineSpecDisplayName,
  isCpuConfigKey,
  isMemoryConfigKey,
  normalizeMemorySpecText,
  parseMachineSpecFromText,
  resolveMachineSpecSelection,
} from "@/domains/products/machineSpecResolver";

/**
 * 展示方式：站点默认值来自后台「商品展示」，访客可在选购页右上角自行切换。
 * 访客的选择只存本机 localStorage，换浏览器/清缓存后回到站点默认值。
 * 两种形态共用同一份接口数据：列表式读 spec_highlights 填表格列，
 * 卡片式读 description_html 填卡片描述。
 */
const { displayMode, setDisplayMode } = useProductDisplayMode();

// 版心：站点默认值兜底，访客可选固定宽度（含档位）或撑满；卡片库存仍只认后台配置
const {
  isContained,
  contentMaxWidthVar,
  layoutMode,
  contentMaxWidth,
  followsUserWidthChoice,
  setLayoutMode,
  setContentMaxWidth,
  resetLayoutWidth,
  cardShowStock,
} = useProductDisplaySettings();

// 卡片式不自动选中第一个商品：没有选中态才展示卡片网格，
// 选中态交给 /products/:id 独立配置页承载。
const autoSelectProduct = computed(
  () => displayMode.value !== DISPLAY_MODE_CARD,
);

const {
  pageLoading,
  configLoading,
  submitting,
  sidebarCollapsed,
  isMobile,
  mobileProductDrawer,
  mobilePendingProductId,
  productTypes,
  rootGroups,
  childGroups,
  activeTypeValue,
  activeGroupId,
  activeChildId,
  selectedProductId,
  selectedProduct,
  configForm,
  selectedCycle,
  quoteLoading,
  productStockLoading,
  productStockError,
  selectedProductDisplayName,
  activeTypeLabel,
  activeGroupName,
  activeChildName,
  osConfig,
  osGroups,
  currentOsGroup,
  currentOsVersionLabel,
  machineConfigs,
  networkConfigs,
  otherConfigs,
  noticeConfigs,
  pricingEntries,
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
  summaryItems,
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
  visibleProducts,
  selectOsGroup,
  handleSubmit,
  openMobileProductDrawer,
  confirmMobileProductSelection: confirmRawMobileProductSelection,
  switchType,
  switchGroup,
  switchChild,
  selectProduct,
  syncDefaultProduct,
} = useWebsiteProductsPage({
  autoSelectProduct,
  // 卡片式不预取商品详情（卡片不需要配置数据，预取会额外打 3-8 个请求）；
  // 列表式才为机型切换预取首屏附近的几台
  shouldPrefetchProductDetails: () => displayMode.value === DISPLAY_MODE_LIST,
});

// 选中商品后不再显示卡片网格，直接进入配置区（深链 /products/t/g/c/p 亦如此）
const showCardGrid = computed(
  () => displayMode.value === DISPLAY_MODE_CARD && !selectedProduct.value,
);

/**
 * 切换展示方式不再改写地址语义：
 *  - 切到卡片式：地址保持不动。若当前是商品地址（四段），路由入口会换成
 *    卡片式配置页渲染；若是目录地址，卡片网格直接替换列表。
 *  - 切回列表式：按列表式规则自动补全到第一个商品，把地址推进到商品段。
 */
function handleDisplayModeChange(mode) {
  if (mode === displayMode.value) {
    return;
  }

  setDisplayMode(mode);

  if (mode === DISPLAY_MODE_LIST) {
    syncDefaultProduct(visibleProducts.value, { syncRoute: true });
  }
}

/* ===== 目录侧栏：两个侧栏 + 手机抽屉 ===== */
// 桌面端目录占两条侧栏（一级分组 + 二/三级分组）。访客可以整块收起换取更宽的
// 配置区，偏好只存本机；一级分组栏还能单独折成缩写方块。
const NAV_VISIBLE_STORAGE_KEY = "turaidc:shop-nav-visible";
const navVisible = ref(
  (() => {
    try {
      return window.localStorage.getItem(NAV_VISIBLE_STORAGE_KEY) !== "0";
    } catch {
      return true;
    }
  })(),
);

function toggleNavVisible() {
  navVisible.value = !navVisible.value;
  try {
    window.localStorage.setItem(
      NAV_VISIBLE_STORAGE_KEY,
      navVisible.value ? "1" : "0",
    );
  } catch {
    // 写不进去只影响下次是否记得
  }
}

const mobileCatalogDrawer = ref(false);

// 手机端目录栏上的路径文本：一级 > 二级 > 三级，缺哪级就少显示哪级
const mobileCatalogPathText = computed(() => {
  const parts = [
    activeTypeLabel.value,
    activeGroupName.value,
    activeChildName.value,
  ]
    .map((part) => String(part || "").trim())
    .filter(Boolean);

  return parts.join(" / ");
});

/**
 * 点二 / 三级分组时把一级分组栏收起来：目录已经能通过第二个侧栏里的
 * 一级分组标题确认位置，收起后配置区更宽（这正是「隐藏一级」想要的效果）。
 */
function collapseLevel1NavOnCatalogPick() {
  if (isMobile.value || !navVisible.value) {
    return;
  }

  sidebarCollapsed.value = true;
}

function handleSelectType(value) {
  // 从折叠态点一级分组，先展开再切换：不然用户看不到自己切到了哪一类
  sidebarCollapsed.value = false;
  void switchType(value);
}

function handleMobileSelectType(value) {
  void switchType(value);
}

function handleGroupNavSelectGroup(id) {
  const nextId = Number(id || 0);
  if (!nextId) {
    return;
  }

  collapseLevel1NavOnCatalogPick();

  // 点当前分组的组头也要把地址层级补到两段（此前会直接 return，
  // 卡片式从一段地址点进当前分组时地址没有变化）
  void switchGroup(nextId);
}

function handleGroupNavSelectChild(id) {
  const nextId = Number(id || 0);
  if (!nextId) {
    return;
  }

  collapseLevel1NavOnCatalogPick();

  // 点当前三级分组也要把地址补到三段，才能分享/刷新回到该三级分组；
  // 分组商品尚未加载时 switchChild 会按需拉一次
  void switchChild(nextId);
}

/* ===== 宽屏版心面板 ===== */
const widthPanelOpen = ref(false);

// 站点默认宽度可能不在预设档位里（后台允许 960–1920 的任意值），
// 把它并进可选档，避免面板里没有当前值。
const widthOptions = computed(() => {
  const options = [...PRODUCT_PAGE_WIDTH_PRESETS];

  if (!options.includes(contentMaxWidth.value)) {
    options.push(contentMaxWidth.value);
  }

  return options.sort((left, right) => left - right);
});

function toggleWidthPanel() {
  widthPanelOpen.value = !widthPanelOpen.value;
}

function closeWidthPanel() {
  widthPanelOpen.value = false;
}

function handleWidthPanelOutsideClick(event) {
  if (!widthPanelOpen.value) {
    return;
  }

  const target = event.target;
  if (target instanceof Element && target.closest(".shop-width-control")) {
    return;
  }

  closeWidthPanel();
  // 面板打开时，点外部只关闭面板：吞掉这次点击，避免顺带触发下面的卡片跳转
  event.stopPropagation();
}

function handleWidthPanelKeydown(event) {
  if (event.key === "Escape") {
    closeWidthPanel();
  }
}

const mobileOsDrawer = ref(false);
const mobileIntroDrawer = ref(false);
const mobileCostDrawer = ref(false);
const mobileCouponDrawer = ref(false);
const allocationFooterEl = ref(null);
const allocationFooterInnerEl = ref(null);
const allocationFooterAnchored = ref(false);
let allocationFooterObserver = null;
let allocationFooterResizeObserver = null;

function cleanupAllocationFooterObserver() {
  allocationFooterObserver?.disconnect();
  allocationFooterObserver = null;
  allocationFooterResizeObserver?.disconnect();
  allocationFooterResizeObserver = null;
}

function setupAllocationFooterObserver() {
  cleanupAllocationFooterObserver();
  allocationFooterAnchored.value = false;

  if (
    !isMobile.value ||
    !selectedProduct.value ||
    !allocationFooterEl.value ||
    !("IntersectionObserver" in window)
  ) {
    return;
  }

  allocationFooterObserver = new IntersectionObserver(
    ([entry]) => {
      allocationFooterAnchored.value = Boolean(entry?.isIntersecting);
    },
    {
      root: null,
      threshold: 0,
    },
  );
  allocationFooterObserver.observe(allocationFooterEl.value);

  if ("ResizeObserver" in window && allocationFooterInnerEl.value) {
    allocationFooterResizeObserver = new ResizeObserver(([entry]) => {
      const height = Math.ceil(entry?.contentRect?.height || 0);
      if (height > 0) {
        allocationFooterEl.value?.style.setProperty(
          "--allocation-footer-height",
          `${height}px`,
        );
      }
    });
    allocationFooterResizeObserver.observe(allocationFooterInnerEl.value);
  }
}

onMounted(() => {
  nextTick(setupAllocationFooterObserver);
  // capture 阶段监听：面板打开时点外部只关闭面板，不触发下层卡片/按钮
  document.addEventListener("click", handleWidthPanelOutsideClick, true);
  document.addEventListener("keydown", handleWidthPanelKeydown);
});

onBeforeUnmount(() => {
  cleanupAllocationFooterObserver();
  document.removeEventListener("click", handleWidthPanelOutsideClick, true);
  document.removeEventListener("keydown", handleWidthPanelKeydown);
});

watch([isMobile, selectedProduct], () => {
  nextTick(setupAllocationFooterObserver);
});

function normalizeInstanceSpecNote(value) {
  return String(value || "").trim();
}

function formatMobileSpecNumber(value) {
  const number = Number(value);
  if (!Number.isFinite(number)) {
    return String(value || "").trim();
  }

  return Number.isInteger(number) ? String(number) : String(number);
}

function normalizeMobileModelBaseName(value, cpuText, memoryText) {
  let text = String(value || "").trim();
  const cpuSlug = buildMobileCpuSlug(cpuText);
  const memorySlug = buildMobileMemorySlug(memoryText);
  const cpuNumber = parseMachineSpecNumber(cpuText);
  const memoryNumber = parseMachineSpecNumber(memoryText);
  const cpuAliases = Number.isFinite(cpuNumber)
    ? [
        `${formatMobileSpecNumber(cpuNumber)}vcpu`,
        `${formatMobileSpecNumber(cpuNumber)}cpu`,
        `${formatMobileSpecNumber(cpuNumber)}核`,
      ]
    : [];
  const memoryAliases = Number.isFinite(memoryNumber)
    ? [
        `${formatMobileSpecNumber(memoryNumber)}gib`,
        `${formatMobileSpecNumber(memoryNumber)}gb`,
        `${formatMobileSpecNumber(memoryNumber)}g`,
      ]
    : [];
  const cleanupSegments = [
    memorySlug,
    ...memoryAliases,
    normalizeMemorySpecText(memoryText),
    memoryText,
    cpuSlug,
    ...cpuAliases,
    cpuText,
  ]
    .filter(Boolean)
    .sort((left, right) => String(right).length - String(left).length);

  cleanupSegments.forEach((segment) => {
    const escaped = String(segment).replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
    text = text
      .replace(new RegExp(`[-_\\s]*${escaped}\\s*$`, "i"), "")
      .replace(
        new RegExp(`[-_\\s]*${escaped.replace(/\\s\\+/g, "\\s*")}\\s*$`, "i"),
        "",
      );
  });

  // Fallback: strip any remaining vCPU / memory spec patterns that may not have been
  // caught above (e.g. when cpuText / memoryText were not separately available).
  text = text.replace(/[-_\s]+\d+vcpu\s*$/i, "");
  text = text.replace(/[-_\s]+\d+g(?:ib?)?\s*$/i, "");

  return text.replace(/[-_\s]+$/g, "").trim();
}

function buildMobileCpuSlug(value) {
  const number = parseMachineSpecNumber(value);
  return Number.isFinite(number) ? `${formatMobileSpecNumber(number)}vcpu` : "";
}

function buildMobileMemorySlug(value) {
  const normalized = normalizeMemorySpecText(value);
  const number = parseMachineSpecNumber(normalized || value);
  return Number.isFinite(number) ? `${formatMobileSpecNumber(number)}g` : "";
}

function resolveMobileProductSpec(product) {
  const detailProduct =
    selectedProduct.value?.id === product.id ? selectedProduct.value : null;
  const sourceProduct = mergeProductPresentationSource(product, detailProduct);
  const displayName = resolveProductDisplayName(sourceProduct);
  const specSource = String(
    sourceProduct.instance_spec_text ||
      sourceProduct.instance_spec_alias ||
      displayName ||
      "",
  ).trim();
  const cpuMemoryDisplay = String(
    sourceProduct.cpu_memory_display || "",
  ).trim();
  const configSpec = resolveMachineSpecSelection(sourceProduct.config_options, {
    cpu: sourceProduct.purchase_requires?.upstream_default_config?.cpu,
    memory: sourceProduct.purchase_requires?.upstream_default_config?.memory,
  });
  const spec = parseMachineSpecFromText(
    [
      cpuMemoryDisplay,
      specSource,
      displayName,
      configSpec.cpuRaw,
      configSpec.memoryRaw,
    ]
      .filter(Boolean)
      .join(" "),
  );
  const cpuText =
    String(sourceProduct.cpu_display || "").trim() ||
    configSpec.cpuText ||
    spec.cpuText ||
    "";
  const memoryText =
    normalizeMemorySpecText(sourceProduct.memory_display) ||
    configSpec.memoryText ||
    spec.memoryText ||
    "";
  const cpuValue = parseMachineSpecNumber(cpuText);
  const memoryValue = parseMachineSpecNumber(memoryText);
  const explicitSpecText = String(
    sourceProduct.instance_spec_text || sourceProduct.instance_spec_alias || "",
  ).trim();
  const baseName = normalizeMobileModelBaseName(
    explicitSpecText ||
      buildMachineSpecDisplayName({
        combinedDisplayName: sourceProduct.combined_display_name,
        displayName,
        cpuText,
        memoryText,
      }) ||
      displayName,
    cpuText,
    memoryText,
  );
  const cpuSlug = buildMobileCpuSlug(cpuText);
  const memorySlug = buildMobileMemorySlug(memoryText);

  return {
    product,
    productId: product.id,
    displayName,
    baseName,
    cpuText,
    memoryText,
    cpuValue,
    memoryValue,
    cpuSlug,
    memorySlug,
    note: normalizeInstanceSpecNote(sourceProduct.instance_spec_note),
    priceText: formatProductListPrice(product),
  };
}

function sortMobileSpecRows(left, right) {
  const leftValid = Number.isFinite(left.memoryValue);
  const rightValid = Number.isFinite(right.memoryValue);

  if (leftValid && rightValid && left.memoryValue !== right.memoryValue) {
    return left.memoryValue - right.memoryValue;
  }

  if (leftValid !== rightValid) {
    return leftValid ? -1 : 1;
  }

  return left.originalIndex - right.originalIndex;
}

const mobileCostRows = computed(() => {
  const rows = [];
  const productBaseAmount =
    Number(baseAmount.value || 0) + Number(setupFee.value || 0);

  if (selectedProduct.value) {
    rows.push({
      key: "product",
      label: t("product.list.configName"),
      detail:
        selectedProductSummaryName.value ||
        resolveProductDisplayName(selectedProduct.value),
      amount: formatMoneyText(productBaseAmount),
    });
  }

  summaryItems.value.forEach((item) => {
    const matchedQuote = quoteItems.value.find(
      (quoteItem) => quoteItem.label === item.label,
    );
    rows.push({
      key: item.key,
      label: item.label,
      detail: item.value,
      amount: formatMoneyText(matchedQuote?.amount || 0),
    });
  });

  return rows;
});

const selectedProductGroup = computed(
  () => selectedProduct.value?.group || null,
);
const productIntroText = computed(() => {
  const remark = String(selectedProduct.value?.remark || "").trim();
  if (remark) {
    return remark;
  }

  const slogan = String(selectedProductGroup.value?.slogan || "").trim();
  if (slogan) {
    return slogan;
  }

  // 描述全空时用真实配置项拼一段规格摘要，而不是整块留白。生产上全部分组
  // description 都是空的，虚拟主机与 CDN 尤其明显，没有这段用户只能看到价格。
  return summaryItems.value
    .filter(
      (item) =>
        !["region", "os", "cpu_model_name", "cpu_spec", "memory_spec"].includes(
          item.key,
        ),
    )
    .map((item) => `${item.label} ${item.value}`.trim())
    .filter(Boolean)
    .join("\n");
});
const soldOut = computed(
  () =>
    resolvedStock.value !== null &&
    !productStockLoading.value &&
    !productStockError.value &&
    resolvedStock.value === 0,
);

const FREQUENCY_UNIT = "GHz";

function isCpuConfig(cfg) {
  return isCpuConfigKey(cfg.key, cfg.label);
}

function isMemConfig(cfg) {
  return isMemoryConfigKey(cfg.key, cfg.label);
}

function stripFrequencyUnit(value) {
  const text = String(value || "").trim();

  if (!text) {
    return "";
  }

  return text.replace(/\s*GHz$/i, "");
}

function formatFrequencyValue(value) {
  const text = stripFrequencyUnit(value);

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

  if (base) {
    return base;
  }

  if (turbo) {
    return turbo;
  }

  return "";
}

const cpuConfigs = computed(() => machineConfigs.value.filter(isCpuConfig));
const memConfigs = computed(() => machineConfigs.value.filter(isMemConfig));
const cpuConfig = computed(() => cpuConfigs.value[0] || null);
const memConfig = computed(() => memConfigs.value[0] || null);
const otherMachineConfigs = computed(() =>
  machineConfigs.value.filter((cfg) => !isCpuConfig(cfg) && !isMemConfig(cfg)),
);
const desktopMachineExtraConfigs = computed(() => otherMachineConfigs.value);
// 桌面「机型附加项」分支里 CPU / 内存 也要渲染（顺序与移动端一致：先 CPU/内存，再附加项）。
// 机型配置表的 vCPU / 内存 列只是展示当前选中值，不能替代选择控件。
const desktopMachineCoreConfigs = computed(() =>
  [cpuConfig.value, memConfig.value].filter(Boolean),
);
const machineSpecSort = ref({
  key: "",
  direction: "",
});
const mobilePendingProductGroupKey = ref("");
const PRODUCT_DISPLAY_FIELDS = [
  "combined_display_name",
  "product_display_name",
  "display_name",
  "instance_spec_text",
  "instance_spec_alias",
  "cpu_memory_display",
  "cpu_display",
  "memory_display",
];

function parseMachineSpecNumber(value) {
  const text = String(value || "").trim();
  const match = text.match(/\d+(?:\.\d+)?/);
  return match ? Number(match[0]) : Number.NaN;
}

function resolveProductPriceNumber(product) {
  const pricingEntries = Array.isArray(product?.pricing_entries)
    ? product.pricing_entries
    : [];
  const monthlyEntry = pricingEntries.find(
    (item) => item?.cycle === "monthly" && Number(item?.amount || 0) > 0,
  );
  const fallbackEntry =
    monthlyEntry ||
    pricingEntries.find((item) => Number(item?.amount || 0) > 0);
  if (fallbackEntry) {
    return Number(fallbackEntry.amount || 0);
  }

  const pricing =
    product?.pricing && typeof product.pricing === "object"
      ? product.pricing
      : {};
  const monthlyAmount = Number(pricing.monthly || 0);
  if (monthlyAmount > 0) {
    return monthlyAmount;
  }

  const primaryPrice = Number(product?.primary_price || 0);
  return primaryPrice > 0 ? primaryPrice : Number.NaN;
}

function mergeProductPresentationSource(product, detailProduct = null) {
  if (!detailProduct) {
    return product || {};
  }

  const sourceProduct = { ...product, ...detailProduct };

  PRODUCT_DISPLAY_FIELDS.forEach((field) => {
    const listValue = String(product?.[field] || "").trim();
    if (listValue) {
      sourceProduct[field] = product[field];
    }
  });

  return sourceProduct;
}

function resolveMachineSpecSortValue(row) {
  if (machineSpecSort.value.key === "cpu") {
    return row.cpuValue;
  }

  if (machineSpecSort.value.key === "memory") {
    return row.memoryValue;
  }

  if (machineSpecSort.value.key === "price") {
    return row.basePriceValue;
  }

  return Number.NaN;
}

function setMachineSpecSort(key, direction) {
  machineSpecSort.value = { key, direction };
}

// 机器规格解析缓存：按 product.id 缓存解析结果，命中条件为 product 与选中 detail 的引用未变，
// 避免每次选中切换对所有 SKU 重复正则解析（仅重算选中商品那一行）
const desktopSpecRowCache = new Map();
const mobileSpecCache = new Map();

function resolveDesktopSpecRowBase(product) {
  const cacheKey = Number(product?.id || 0);
  if (!cacheKey) return null;

  const detailProduct =
    selectedProduct.value?.id === product.id ? selectedProduct.value : null;
  const detailRef = detailProduct || null;
  const cached = desktopSpecRowCache.get(cacheKey);
  if (
    cached &&
    cached.productRef === product &&
    cached.detailRef === detailRef
  ) {
    return cached.base;
  }

  const sourceProduct = mergeProductPresentationSource(product, detailProduct);
  const displayName = resolveProductDisplayName(sourceProduct);
  const specSource = String(
    sourceProduct.instance_spec_text ||
      sourceProduct.instance_spec_alias ||
      displayName ||
      "",
  ).trim();
  const cpuMemoryDisplay = String(
    sourceProduct.cpu_memory_display || "",
  ).trim();
  const configSpec = resolveMachineSpecSelection(sourceProduct.config_options, {
    cpu: sourceProduct.purchase_requires?.upstream_default_config?.cpu,
    memory: sourceProduct.purchase_requires?.upstream_default_config?.memory,
  });
  const spec = parseMachineSpecFromText(
    [
      cpuMemoryDisplay,
      specSource,
      displayName || "",
      configSpec.cpuRaw,
      configSpec.memoryRaw,
    ]
      .filter(Boolean)
      .join(" "),
  );
  const cpuText = String(sourceProduct.cpu_display || "").trim();
  const memoryText = normalizeMemorySpecText(sourceProduct.memory_display);
  const resolvedCpuText = cpuText || configSpec.cpuText || spec.cpuText || "";
  const resolvedMemoryText =
    memoryText || configSpec.memoryText || spec.memoryText || "";
  const rowName = buildMachineSpecDisplayName({
    combinedDisplayName: sourceProduct.combined_display_name,
    displayName,
    cpuText: resolvedCpuText,
    memoryText: resolvedMemoryText,
  });

  const base = {
    productId: product.id,
    // 机型表在分组内部展示，名字里重复分组名既占宽又让每行长得一样；
    // 这里只留规格部分（剥不出东西时保留原名）。
    name:
      rowName ||
      displayName ||
      buildInstanceSpecName(specSource, spec, product.id),
    note: normalizeInstanceSpecNote(sourceProduct.instance_spec_note),
    family: spec.family,
    cpuText: resolvedCpuText || "-",
    memoryText: resolvedMemoryText || "-",
    processor: spec.processor,
    processorLabel: sourceProduct.cpu_model_name || "",
    baseFrequency: sourceProduct.cpu_base_frequency || "",
    turboFrequency: sourceProduct.cpu_turbo_frequency || "",
    basePriceText: formatProductListPrice(product) || "-",
    agentDiscount: Boolean(sourceProduct.has_agent_discount),
    agentDiscountPercent: String(sourceProduct.agent_discount_percent || ""),
    agentOriginalPrice: formatMoneyText(sourceProduct.primary_price),
    agentPrice: String(sourceProduct.agent_primary_price || "0.00"),
    cpuValue: parseMachineSpecNumber(resolvedCpuText),
    memoryValue: parseMachineSpecNumber(resolvedMemoryText),
    basePriceValue: resolveProductPriceNumber(product),
    highlights: Array.isArray(sourceProduct.spec_highlights)
      ? sourceProduct.spec_highlights
      : [],
  };
  desktopSpecRowCache.set(cacheKey, {
    productRef: product,
    detailRef,
    base,
  });
  return base;
}

function resolveMobileSpecCached(product) {
  const cacheKey = Number(product?.id || 0);
  if (!cacheKey) return null;

  const detailProduct =
    selectedProduct.value?.id === product.id ? selectedProduct.value : null;
  const detailRef = detailProduct || null;
  const cached = mobileSpecCache.get(cacheKey);
  if (
    cached &&
    cached.productRef === product &&
    cached.detailRef === detailRef
  ) {
    return cached.value;
  }

  const value = resolveMobileProductSpec(product);
  mobileSpecCache.set(cacheKey, {
    productRef: product,
    detailRef,
    value,
  });
  return value;
}

const desktopMachineSpecRows = computed(() => {
  if (!visibleProducts.value.length) {
    return [];
  }

  const rows = visibleProducts.value
    .map((product, index) => {
      const base = resolveDesktopSpecRowBase(product);
      if (!base) {
        return null;
      }

      return {
        ...base,
        id: `product-${product.id}`,
        active: selectedProductId.value === product.id,
        // family 的上下文回退（当前分组名）不缓存，取实时值
        family:
          base.family ||
          activeChildName.value ||
          activeGroupName.value ||
          t("product.instanceFamily.general"),
        originalIndex: index,
      };
    })
    .filter(Boolean);

  if (!machineSpecSort.value.key || !machineSpecSort.value.direction) {
    return rows;
  }

  return rows.slice().sort((left, right) => {
    const leftValue = resolveMachineSpecSortValue(left);
    const rightValue = resolveMachineSpecSortValue(right);
    const leftValid = Number.isFinite(leftValue);
    const rightValid = Number.isFinite(rightValue);

    if (!leftValid && !rightValid) {
      return left.originalIndex - right.originalIndex;
    }

    if (!leftValid) {
      return 1;
    }

    if (!rightValid) {
      return -1;
    }

    const factor = machineSpecSort.value.direction === "desc" ? -1 : 1;
    const result = (leftValue - rightValue) * factor;
    return result === 0 ? left.originalIndex - right.originalIndex : result;
  });
});

// 桌面表格规格亮点候选列（当前分类内覆盖率 >= 50% 才占列，最多 4 列）
const SPEC_HIGHLIGHT_CANDIDATES = [
  "bandwidth",
  "bandwidth_up",
  "bandwidth_down",
  "system_disk",
  "data_disk",
  "disk",
  "traffic",
  "port",
  "defense",
  "connection",
  "node",
  "domain",
  "ipv4",
  "ipv6",
  "route",
];

const specHighlightColumns = computed(() => {
  const products = visibleProducts.value;
  if (!products.length) return [];
  const total = products.length;
  const columns = [];
  for (const key of SPEC_HIGHLIGHT_CANDIDATES) {
    const coverage = products.filter((product) =>
      (Array.isArray(product.spec_highlights)
        ? product.spec_highlights
        : []
      ).some(
        (item) =>
          item && item.key === key && String(item.value || "").trim() !== "",
      ),
    ).length;
    if (coverage / total >= 0.5) {
      const first = (
        Array.isArray(products[0].spec_highlights)
          ? products[0].spec_highlights
          : []
      ).find((item) => item && item.key === key);
      columns.push({ key, label: first?.label || key });
    }
    if (columns.length >= 4) break;
  }
  return columns;
});

const showCpuMemoryColumns = computed(() =>
  desktopMachineSpecRows.value.some(
    (row) =>
      String(row.cpuText || "") !== "-" || String(row.memoryText || "") !== "-",
  ),
);

function cpuBrandBadgeOf(row) {
  return resolveCpuBrandBadge(row.processorLabel);
}

const showProcessorColumns = computed(() =>
  desktopMachineSpecRows.value.some(
    (row) => String(row.processorLabel || "").trim() !== "",
  ),
);

// 估算文本像素宽度：用于按最长 CPU 型号算出统一的处理器列宽，
// 保证表头与各行列宽一致（不错位）且能完整展示型号
function measureTextWidth(
  text,
  font = '13px -apple-system, "Segoe UI", sans-serif',
) {
  const value = String(text || "");
  if (!value) return 0;
  if (typeof document === "undefined") return value.length * 7;
  if (!measureTextWidth.ctx) {
    measureTextWidth.ctx = document.createElement("canvas").getContext("2d");
  }
  const ctx = measureTextWidth.ctx;
  if (!ctx) return value.length * 7;
  ctx.font = font;
  return ctx.measureText(value).width;
}

const machineSpecGridStyle = computed(() => {
  // 处理器列使用统一固定宽度（px），由最长 CPU 型号决定：
  // 表头与所有行共用同一列宽不会错位，型号完整不截断，超出容器时整表横向滑动查看
  let processorColPx = 0;
  if (showProcessorColumns.value) {
    let maxTextWidth = 0;
    for (const row of desktopMachineSpecRows.value) {
      const label = String(row.processorLabel || "").trim();
      if (label) {
        maxTextWidth = Math.max(maxTextWidth, measureTextWidth(label));
      }
    }
    // 预留品牌徽标宽度、列间距(gap)与单元格左右内边距
    processorColPx = Math.max(220, Math.ceil(maxTextWidth) + 100);
  }

  // 商品名现在是「分组名 + 规格」，列宽给足并允许换行，不靠省略号猜内容
  const columns = ["minmax(220px, 1.6fr)"];
  let minWidth = 220;
  if (showCpuMemoryColumns.value) {
    columns.push("minmax(88px, 1fr)", "minmax(88px, 1fr)");
    minWidth += 176;
  }
  if (showProcessorColumns.value) {
    // minmax 保证列宽至少能放下最长 CPU 型号（不截断），1.2fr 让它在宽屏下照常伸缩
    columns.push(`minmax(${processorColPx}px, 1.2fr)`, "minmax(120px, 1fr)");
    minWidth += processorColPx + 120;
  }
  for (let index = 0; index < specHighlightColumns.value.length; index++) {
    columns.push("minmax(92px, 1fr)");
    minWidth += 92;
  }
  columns.push("minmax(150px, 1.1fr)");
  minWidth += 150;

  return {
    "--machine-spec-cols": columns.join(" "),
    "--machine-spec-min-width": `${Math.max(minWidth, 700)}px`,
  };
});

function rowHighlightValue(row, key) {
  const item = (Array.isArray(row.highlights) ? row.highlights : []).find(
    (entry) => entry && entry.key === key,
  );
  return item && String(item.value || "").trim() !== "" ? item.value : "-";
}

const mobileProductSpecRows = computed(() =>
  visibleProducts.value
    .map((product, index) => {
      const base = resolveMobileSpecCached(product);
      return base ? { ...base, originalIndex: index } : null;
    })
    .filter(Boolean),
);
const selectedProductSummaryName = computed(() => {
  const productId = Number(
    selectedProductId.value || selectedProduct.value?.id || 0,
  );
  const listProduct =
    visibleProducts.value.find(
      (product) => Number(product.id || 0) === productId,
    ) || null;
  const sourceProduct = mergeProductPresentationSource(
    listProduct || selectedProduct.value,
    selectedProduct.value,
  );
  const displayName = resolveProductDisplayName(sourceProduct);
  const specSource = String(
    sourceProduct.instance_spec_text ||
      sourceProduct.instance_spec_alias ||
      displayName ||
      "",
  ).trim();
  const cpuMemoryDisplay = String(
    sourceProduct.cpu_memory_display || "",
  ).trim();
  const configSpec = resolveMachineSpecSelection(sourceProduct.config_options, {
    cpu: configForm.cpu,
    memory: configForm.memory,
  });
  const spec = parseMachineSpecFromText(
    [
      cpuMemoryDisplay,
      specSource,
      displayName,
      configSpec.cpuRaw,
      configSpec.memoryRaw,
    ]
      .filter(Boolean)
      .join(" "),
  );

  // 用户已选 CPU/内存时，当前配置优先于 API 静态默认规格
  const hasSelectedCpu = String(configForm.cpu || "").trim() !== "";
  const hasSelectedMemory = String(configForm.memory || "").trim() !== "";

  const cpuText =
    (hasSelectedCpu ? configSpec.cpuText : "") ||
    String(sourceProduct.cpu_display || "").trim() ||
    configSpec.cpuText ||
    spec.cpuText ||
    "";
  const memoryText =
    (hasSelectedMemory ? configSpec.memoryText : "") ||
    normalizeMemorySpecText(sourceProduct.memory_display) ||
    configSpec.memoryText ||
    spec.memoryText ||
    "";

  return (
    buildMachineSpecDisplayName({
      combinedDisplayName: sourceProduct.combined_display_name,
      displayName,
      cpuText,
      memoryText,
    }) || displayName
  );
});
const mobileProductSpecFamilies = computed(() => {
  const families = new Map();

  mobileProductSpecRows.value.forEach((row) => {
    if (!row.baseName || !row.cpuSlug || !row.memorySlug) {
      return;
    }

    const key = row.baseName.toLowerCase();
    if (!families.has(key)) {
      families.set(key, {
        key,
        label: row.baseName,
        baseName: row.baseName,
        products: [],
        originalIndex: row.originalIndex,
      });
    }

    families.get(key).products.push(row);
  });

  return Array.from(families.values()).sort(
    (left, right) => left.originalIndex - right.originalIndex,
  );
});
const mobileProductGroups = computed(() => {
  const groups = new Map();

  mobileProductSpecRows.value.forEach((row) => {
    if (!row.baseName || !row.cpuSlug || !row.memorySlug) {
      return;
    }

    const key = `${row.baseName.toLowerCase()}|${row.cpuSlug}`;
    if (!groups.has(key)) {
      groups.set(key, {
        key,
        label: row.baseName ? `${row.baseName}-${row.cpuSlug}` : row.cpuSlug,
        baseName: row.baseName,
        cpuText: row.cpuText,
        cpuValue: row.cpuValue,
        note: row.note,
        products: [],
        originalIndex: row.originalIndex,
      });
    }

    const group = groups.get(key);
    group.products.push(row);
    if (!group.note && row.note) {
      group.note = row.note;
    }
  });

  return Array.from(groups.values())
    .map((group) => ({
      ...group,
      products: group.products.slice().sort(sortMobileSpecRows),
    }))
    .sort((left, right) => {
      const leftValid = Number.isFinite(left.cpuValue);
      const rightValid = Number.isFinite(right.cpuValue);

      if (leftValid && rightValid && left.cpuValue !== right.cpuValue) {
        return left.cpuValue - right.cpuValue;
      }

      if (leftValid !== rightValid) {
        return leftValid ? -1 : 1;
      }

      return left.originalIndex - right.originalIndex;
    });
});
const hasMobileProductGroups = computed(
  () =>
    visibleProducts.value.length > 0 &&
    mobileProductGroups.value.length > 0 &&
    mobileProductSpecRows.value.every(
      (row) => row.baseName && row.cpuSlug && row.memorySlug,
    ),
);
/**
 * 是否用「机型族 → CPU → 内存」的分组式选择。
 *
 * 只有多个机型族时这套 UI 才有意义：单族时 family 下拉只有一项，而且商品名
 * 统一成「分组名 + 规格」之后 family 会被剥成分组名，用户在里面根本切不了商品。
 * 单族直接列出商品（每项只显示规格，如 2核2G / 4核4G），选中即切换。
 */
const mobileGroupedSpecUi = computed(() => {
  if (!hasMobileProductGroups.value) {
    return false;
  }

  const families = mobileProductSpecFamilies.value;
  const rows = mobileProductSpecRows.value;

  // 族数 == 商品数说明每个商品各成一族（商品名带中文规格时旧逻辑去不掉），
  // 此时 family 下拉只是把商品名整串再列一遍，直接列商品更好用。
  return families.length > 1 && families.length < rows.length;
});

const selectedMobileSpecFamily = computed(() => {
  if (!hasMobileProductGroups.value) {
    return null;
  }

  const selectedGroup = selectedMobileProductGroup.value;
  return (
    mobileProductSpecFamilies.value.find(
      (family) => family.baseName === selectedGroup?.baseName,
    ) ||
    mobileProductSpecFamilies.value[0] ||
    null
  );
});
const selectedMobileProductGroup = computed(() => {
  if (!hasMobileProductGroups.value) {
    return null;
  }

  const selectedId = Number(selectedProductId.value || 0);
  return (
    mobileProductGroups.value.find((group) =>
      group.products.some((row) => row.productId === selectedId),
    ) ||
    mobileProductGroups.value[0] ||
    null
  );
});
const selectedMobileProductModelLabel = computed(() => {
  if (hasMobileProductGroups.value) {
    return selectedMobileProductGroup.value?.label || "";
  }
  const selectedId = Number(selectedProductId.value || 0);
  const matched = mobileProductSpecRows.value.find(
    (row) => row.productId === selectedId,
  );
  return matched?.baseName || selectedProductDisplayName.value;
});
const selectedMobileCpuLabel = computed(
  () =>
    selectedMobileProductGroup.value?.cpuText ||
    selectedMobileProductGroup.value?.label ||
    "",
);
const mobileSpecPickerLabel = computed(() => {
  if (mobileGroupedSpecUi.value) {
    return selectedMobileSpecFamily.value?.label || "";
  }

  // 单族：直接显示当前商品的完整名称（不对商品名做任何裁剪）
  return (
    selectedProductDisplayName.value || selectedMobileProductModelLabel.value
  );
});
const selectedMobileMemoryLabel = computed(() => {
  const selectedId = Number(selectedProductId.value || 0);
  const matched = selectedMobileProductGroup.value?.products.find(
    (row) => row.productId === selectedId,
  );
  return matched?.memoryText || "";
});
const mobileGroupedCpuOptions = computed(() =>
  mobileProductGroups.value
    .filter(
      (group) =>
        !selectedMobileSpecFamily.value ||
        group.baseName === selectedMobileSpecFamily.value.baseName,
    )
    .map((group) => ({
      id: group.key,
      label: group.cpuText || group.label,
    })),
);
const mobileSpecFamilyOptions = computed(() =>
  mobileProductSpecFamilies.value.map((family) => ({
    id: family.key,
    label: family.label,
  })),
);
const mobileGroupedMemoryOptions = computed(
  () =>
    selectedMobileProductGroup.value?.products.map((row) => ({
      id: String(row.productId),
      label:
        row.memoryText ||
        row.memorySlug ||
        resolveProductDisplayName(row.product),
    })) || [],
);

const mobileCpuDrawer = ref(false);
const mobileMemDrawer = ref(false);
const mobileSpecFamilyDrawer = ref(false);
const mobileGroupedCpuDrawer = ref(false);
const mobileGroupedMemoryDrawer = ref(false);
const mobileSingleConfigDrawer = ref(false);
const mobileSingleConfig = ref(null);

async function openMobileProductGroupDrawer() {
  await openMobileProductDrawer();

  if (mobileGroupedSpecUi.value) {
    mobilePendingProductGroupKey.value =
      selectedMobileProductGroup.value?.key ||
      mobileProductGroups.value[0]?.key ||
      "";
  } else {
    mobilePendingProductGroupKey.value = "";
  }
}

function openMobileCpuDrawer() {
  if (!cpuConfig.value?.options?.length) return;
  mobileCpuDrawer.value = true;
}

function openMobileMemDrawer() {
  if (!memConfig.value?.options?.length) return;
  mobileMemDrawer.value = true;
}

function openMobileGroupedCpuDrawer() {
  if (!mobileGroupedCpuOptions.value.length) return;
  mobileGroupedCpuDrawer.value = true;
}

function openMobileSpecPicker() {
  if (mobileGroupedSpecUi.value) {
    if (!mobileSpecFamilyOptions.value.length) return;
    mobileSpecFamilyDrawer.value = true;
    return;
  }

  void openMobileProductGroupDrawer();
}

function openMobileGroupedMemoryDrawer() {
  if (!mobileGroupedMemoryOptions.value.length) return;
  mobileGroupedMemoryDrawer.value = true;
}

function findPreferredMobileProductId(group) {
  if (!group?.products?.length) {
    return 0;
  }

  const selectedId = Number(selectedProductId.value || 0);
  const currentMemory =
    mobileProductSpecRows.value.find((row) => row.productId === selectedId)
      ?.memorySlug || "";
  const matchedMemory = currentMemory
    ? group.products.find((row) => row.memorySlug === currentMemory)
    : null;

  return matchedMemory?.productId || group.products[0]?.productId || 0;
}

function findPreferredMobileGroup(groups) {
  if (!groups?.length) {
    return null;
  }

  const selectedId = Number(selectedProductId.value || 0);
  const currentRow = mobileProductSpecRows.value.find(
    (row) => row.productId === selectedId,
  );
  const currentCpuSlug = currentRow?.cpuSlug || "";
  const matchedCpu = currentCpuSlug
    ? groups.find((group) =>
        group.products.some((row) => row.cpuSlug === currentCpuSlug),
      )
    : null;

  return matchedCpu || groups[0];
}

function confirmMobileGroupedMemorySelection(productId) {
  mobileGroupedMemoryDrawer.value = false;
  const nextProductId = Number(productId || 0);
  if (nextProductId > 0) {
    selectProduct(nextProductId, { syncRoute: true });
  }
}

function confirmMobileGroupedCpuSelection(groupKey) {
  mobileGroupedCpuDrawer.value = false;
  const targetGroup = mobileProductGroups.value.find(
    (group) => group.key === groupKey,
  );
  const nextProductId = findPreferredMobileProductId(targetGroup);
  if (nextProductId > 0) {
    selectProduct(nextProductId, { syncRoute: true });
  }
}

function confirmMobileSpecFamilySelection(familyKey) {
  mobileSpecFamilyDrawer.value = false;
  const targetFamily = mobileProductSpecFamilies.value.find(
    (family) => family.key === familyKey,
  );
  if (!targetFamily) {
    return;
  }

  const targetGroups = mobileProductGroups.value.filter(
    (group) => group.baseName === targetFamily.baseName,
  );
  const targetGroup = findPreferredMobileGroup(targetGroups);
  const nextProductId = findPreferredMobileProductId(targetGroup);
  if (nextProductId > 0) {
    selectProduct(nextProductId, { syncRoute: true });
  }
}

function confirmMobileProductSelection() {
  // 与界面同口径：只有多机型族才按「机型族」确认；单族时抽屉里列的是商品，
  // 必须按选中的商品确认，否则会一直回落到当前商品（点确定没反应）。
  if (mobileGroupedSpecUi.value) {
    const targetGroup =
      mobileProductGroups.value.find(
        (group) => group.key === mobilePendingProductGroupKey.value,
      ) ||
      selectedMobileProductGroup.value ||
      mobileProductGroups.value[0];
    const nextProductId = findPreferredMobileProductId(targetGroup);
    if (nextProductId > 0) {
      selectProduct(nextProductId, { syncRoute: true });
    }

    mobileProductDrawer.value = false;
    return;
  }

  if (mobilePendingProductId.value) {
    confirmRawMobileProductSelection();
  } else {
    mobileProductDrawer.value = false;
  }
}

function confirmCpuSelection(optionId) {
  mobileCpuDrawer.value = false;
  if (optionId && cpuConfig.value) {
    configForm[cpuConfig.value.key] = optionId;
  }
}

function confirmMemSelection(optionId) {
  mobileMemDrawer.value = false;
  if (optionId && memConfig.value) {
    configForm[memConfig.value.key] = optionId;
  }
}

function selectDesktopMachineSpec(row) {
  if (row.productId) {
    selectProduct(row.productId, { syncRoute: true });
  }
}

function selectedOptionLabel(cfg) {
  if (!cfg?.options?.length) return "";
  const option = cfg.options.find((item) => item.id === configForm[cfg.key]);
  return option?.label || cfg.options[0]?.label || "";
}

function openMobileSingleConfigDrawer(cfg) {
  if (!cfg?.options?.length) return;
  mobileSingleConfig.value = cfg;
  mobileSingleConfigDrawer.value = true;
}

function closeMobileSingleConfigDrawer() {
  mobileSingleConfigDrawer.value = false;
}

function confirmMobileSingleConfigSelection(optionId) {
  const cfg = mobileSingleConfig.value;
  mobileSingleConfigDrawer.value = false;
  if (optionId && cfg) {
    configForm[cfg.key] = optionId;
  }
}

function isRangeConfig(cfg) {
  return cfg.isNumber;
}

function openMobileOsDrawer() {
  if (!osGroups.value.length) return;
  mobileOsDrawer.value = true;
}

function confirmOsSelection(groupId, versionId) {
  mobileOsDrawer.value = false;
  const group = osGroups.value.find((g) => g.id === groupId);
  if (group) {
    selectOsGroup(group);
    if (versionId) {
      configForm.os = versionId;
    }
  }
}
</script>

<style scoped lang="scss" src="./page.scss"></style>
