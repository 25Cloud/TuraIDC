<template>
  <div class="shop-wrap">
    <div
      v-if="showMobileTypePicker"
      class="mobile-type-stage"
      v-loading="pageLoading"
    >
      <section class="mobile-stage-card mobile-stage-card--intro">
        <h1 class="mobile-stage-title">图拉云产品中心 · 选择一级菜单</h1>
        <p class="mobile-stage-desc">
          请先选择你要购买的产品大类，进入对应目录后再继续选择分类、商品和具体配置。
        </p>
      </section>

      <div class="mobile-type-grid">
        <button
          v-for="type in productTypes"
          :key="type.value"
          type="button"
          class="mobile-type-card"
          :class="{ active: activeTypeValue === type.value }"
          @click="enterMobileType(type.value)"
        >
          <div class="mobile-type-card-head">
            <span class="item-abbr">{{
              type.abbr || type.label.slice(0, 2).toUpperCase()
            }}</span>
            <span class="mobile-type-count"
              >{{ type.product_count || 0 }} 个商品</span
            >
          </div>
          <div class="mobile-type-name">{{ type.label }}</div>
          <p class="mobile-type-desc">
            {{
              type.description ||
              `进入 ${type.label} 子目录后可继续选择分类、商品和具体配置。`
            }}
          </p>
          <div class="mobile-type-foot">
            <span>{{ type.slogan || "继续浏览该目录下的可售商品" }}</span>
            <span class="mobile-type-link">进入子目录</span>
          </div>
        </button>
      </div>
    </div>

    <template v-else>
      <!-- 左侧产品目录侧边栏 -->
      <aside
        v-if="!isMobile"
        class="shop-sidebar"
        :class="{ collapsed: sidebarCollapsed }"
      >
        <div class="sidebar-head">
          <span class="sidebar-head-text" v-show="!sidebarCollapsed"
            >产品目录</span
          >
          <button
            class="sidebar-toggle"
            @click="sidebarCollapsed = !sidebarCollapsed"
          >
            <el-icon
              ><Fold v-if="!sidebarCollapsed" /><Expand v-else
            /></el-icon>
          </button>
        </div>
        <div
          v-for="type in productTypes"
          :key="type.value"
          class="sidebar-item"
          :class="{ active: activeTypeValue === type.value }"
          @click="switchType(type.value)"
          :title="sidebarCollapsed ? type.label : ''"
        >
          <span class="item-abbr">{{
            type.abbr || type.label.slice(0, 2).toUpperCase()
          }}</span>
          <template v-if="!sidebarCollapsed">
            <span class="item-body">
              <span class="item-name">{{ type.label }}</span>
              <span class="item-sub">{{
                type.product_count > 0
                  ? `${type.product_count} 个商品`
                  : "暂无商品"
              }}</span>
            </span>
            <span class="item-count">{{ type.product_count || 0 }}</span>
          </template>
        </div>
      </aside>

      <!-- 右侧主区域 -->
      <div class="shop-body">
        <!-- 中间配置区 -->
        <div class="shop-main" v-loading="pageLoading">
          <div v-if="isMobile" class="mobile-picker-row mobile-picker-row--duo">
            <div class="mobile-picker-col">
              <div class="config-block-title">地区</div>
              <button
                type="button"
                class="mobile-picker-trigger"
                @click="openMobileRegionDrawer"
              >
                <span
                  >{{ activeGroupName || "请选择地区"
                  }}{{ activeChildName ? ` · ${activeChildName}` : "" }}</span
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
            <div v-if="visibleProducts.length" class="mobile-picker-col">
              <div class="config-block-title">产品规格选择</div>
              <button
                type="button"
                class="mobile-picker-trigger"
                @click="openMobileSpecPicker"
              >
                <span>{{ mobileSpecPickerLabel || "请选择产品规格" }}</span>
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

          <!-- 产品分类（二级，一级 Tab） -->
          <div class="filter-block" v-if="rootGroups.length && !isMobile">
            <span class="filter-label">地区</span>
            <el-tabs
              class="catalog-tabs"
              :model-value="activeGroupId"
              @tab-change="handleDesktopGroupChange"
            >
              <el-tab-pane
                v-for="g in rootGroups"
                :key="g.id"
                :name="g.id"
                :label="g.name"
              />
            </el-tabs>
          </div>

          <!-- 二级分类（三级，二级 Tab） -->
          <div class="filter-block" v-if="childGroups.length && !isMobile">
            <span class="filter-label">可用区</span>
            <el-tabs
              class="catalog-tabs catalog-tabs--child"
              :model-value="activeChildId"
              @tab-change="handleDesktopChildChange"
            >
              <el-tab-pane
                v-for="c in childGroups"
                :key="c.id"
                :name="c.id"
                :label="c.name"
              />
            </el-tabs>
          </div>

          <div
            v-if="selectedProduct && productIntroText && !isMobile"
            class="product-intro-card"
          >
            <div class="config-block-title">商品介绍</div>
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
              <div class="config-block-title">操作系统</div>
              <button
                type="button"
                class="mobile-picker-trigger"
                @click="openMobileOsDrawer"
              >
                <span
                  >{{ currentOsGroup?.label || "请选择系统"
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
                <div class="config-block-title">系统安装</div>
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
                        <span v-else class="os-placeholder">选择版本</span>
                      </div>
                      <span
                        class="os-check-mark"
                        v-if="configForm.os_group === os.id"
                        >✓</span
                      >
                    </button>
                  </div>
                  <div v-else class="spec-fixed">
                    当前商品未提供可切换的系统版本
                  </div>
                  <div
                    class="os-ver-row"
                    v-if="currentOsGroup?.versions?.length"
                  >
                    <span class="os-ver-label">版本</span>
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
              v-if="
                (machineConfigs.length || hasMobileProductGroups) && isMobile
              "
            >
              <div
                v-if="hasMobileProductGroups"
                class="mobile-picker-row mobile-picker-row--duo"
              >
                <div class="mobile-picker-col">
                  <div class="config-block-title">CPU</div>
                  <button
                    type="button"
                    class="mobile-picker-trigger"
                    @click="openMobileGroupedCpuDrawer"
                  >
                    <span>{{ selectedMobileCpuLabel || "请选择 CPU" }}</span>
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
                  <div class="config-block-title">内存</div>
                  <button
                    type="button"
                    class="mobile-picker-trigger"
                    @click="openMobileGroupedMemoryDrawer"
                  >
                    <span>{{ selectedMobileMemoryLabel || "请选择内存" }}</span>
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
                      )?.label || "请选择"
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
                      )?.label || "请选择"
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
                        {{ selectedOptionLabel(cfg) || "请选择" }}
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
              <div class="config-block-title">机型配置</div>
              <div
                v-if="desktopMachineSpecRows.length"
                class="desktop-machine-spec-panel"
              >
                <div
                  class="desktop-machine-table"
                  :style="machineSpecGridStyle"
                  role="table"
                  aria-label="机型配置"
                >
                  <div class="desktop-machine-table-head" role="row">
                    <span class="machine-spec-cell machine-spec-cell--name"
                      >实例规格</span
                    >
                    <span
                      v-if="showCpuMemoryColumns"
                      class="machine-spec-cell machine-spec-cell--sortable"
                    >
                      <span>vCPU</span>
                      <span
                        class="machine-spec-sort-controls"
                        aria-label="vCPU 排序"
                      >
                        <button
                          type="button"
                          class="machine-spec-sort-btn"
                          :class="{
                            active:
                              machineSpecSort.key === 'cpu' &&
                              machineSpecSort.direction === 'asc',
                          }"
                          aria-label="vCPU 从小到大排序"
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
                          aria-label="vCPU 从大到小排序"
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
                      <span>内存</span>
                      <span
                        class="machine-spec-sort-controls"
                        aria-label="内存排序"
                      >
                        <button
                          type="button"
                          class="machine-spec-sort-btn"
                          :class="{
                            active:
                              machineSpecSort.key === 'memory' &&
                              machineSpecSort.direction === 'asc',
                          }"
                          aria-label="内存从小到大排序"
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
                          aria-label="内存从大到小排序"
                          @click="setMachineSpecSort('memory', 'desc')"
                        >
                          <el-icon><CaretBottom /></el-icon>
                        </button>
                      </span>
                    </span>
                    <span
                      v-if="showProcessorColumns"
                      class="machine-spec-cell machine-spec-cell--processor"
                      >处理器</span
                    >
                    <span v-if="showProcessorColumns" class="machine-spec-cell"
                      >主频/睿频</span
                    >
                    <span
                      v-for="column in specHighlightColumns"
                      :key="column.key"
                      class="machine-spec-cell machine-spec-cell--highlight"
                      >{{ column.label }}</span
                    >
                    <span
                      class="machine-spec-cell machine-spec-cell--price machine-spec-cell--sortable"
                    >
                      <span>基础价格</span>
                      <span
                        class="machine-spec-sort-controls"
                        aria-label="基础价格排序"
                      >
                        <button
                          type="button"
                          class="machine-spec-sort-btn"
                          :class="{
                            active:
                              machineSpecSort.key === 'price' &&
                              machineSpecSort.direction === 'asc',
                          }"
                          aria-label="基础价格从小到大排序"
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
                          aria-label="基础价格从大到小排序"
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
                      <span
                        class="machine-spec-radio"
                        aria-hidden="true"
                      ></span>
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
                            aria-label="查看实例规格说明"
                            @click.stop
                            @keydown.enter.stop.prevent
                            @keydown.space.stop.prevent
                            >?</span
                          >
                        </el-tooltip>
                      </span>
                    </span>
                    <span
                      v-if="showCpuMemoryColumns"
                      class="machine-spec-cell"
                      >{{ row.cpuText }}</span
                    >
                    <span
                      v-if="showCpuMemoryColumns"
                      class="machine-spec-cell"
                      >{{ row.memoryText }}</span
                    >
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
                    <span
                      v-if="showProcessorColumns"
                      class="machine-spec-cell"
                      >{{
                        formatFrequencyPair(
                          row.baseFrequency,
                          row.turboFrequency,
                        )
                      }}</span
                    >
                    <span
                      v-for="column in specHighlightColumns"
                      :key="column.key"
                      class="machine-spec-cell machine-spec-cell--highlight"
                      >{{ rowHighlightValue(row, column.key) }}</span
                    >
                    <span class="machine-spec-cell machine-spec-cell--price">{{
                      row.basePriceText
                    }}</span>
                  </button>
                </div>
                <div v-if="osConfig" class="desktop-machine-os-panel">
                  <div class="desktop-machine-subtitle">系统安装</div>
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
                          <span v-else class="os-placeholder">选择版本</span>
                        </div>
                        <span
                          class="os-check-mark"
                          v-if="configForm.os_group === os.id"
                          >✓</span
                        >
                      </button>
                    </div>
                    <div v-else class="spec-fixed">
                      当前商品未提供可切换的系统版本
                    </div>
                    <div
                      class="os-ver-row"
                      v-if="currentOsGroup?.versions?.length"
                    >
                      <span class="os-ver-label">版本</span>
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
                  v-if="desktopMachineExtraConfigs.length"
                  class="desktop-machine-extra-list"
                >
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
              <div class="config-block-title">网络配置</div>
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
                      {{ selectedOptionLabel(cfg) || "请选择" }}
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
              <div class="config-block-title">其他配置</div>
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
                      {{ selectedOptionLabel(cfg) || "请选择" }}
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

            <!-- 基础设置 -->
            <div class="config-block">
              <div class="config-block-title">基础设置</div>
              <div class="spec-list">
                <!-- 计费周期 -->
                <div class="spec-row">
                  <span class="spec-label">计费周期</span>
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
                  <span class="spec-label">购买数量</span>
                  <span>1 台（多台请分次下单）</span>
                </div>
              </div>
            </div>
          </div>

          <el-empty
            v-else-if="
              !pageLoading &&
              !configLoading &&
              !selectedProduct &&
              visibleProducts.length
            "
            description="请选择需要使用优惠券的商品"
            style="padding: 60px 0"
          />

          <el-empty
            v-if="
              !pageLoading &&
              !configLoading &&
              !selectedProduct &&
              !visibleProducts.length
            "
            description="当前分类暂无商品"
            style="padding: 60px 0"
          />
        </div>

        <!-- 右侧费用摘要 -->
        <aside class="shop-cost" v-if="selectedProduct">
          <div class="cost-header">
            <span class="cost-title">配置费用</span>
            <span class="stock-badge" :class="stockClass">{{
              stockLabel
            }}</span>
          </div>

          <div class="stock-info" v-if="selectedProduct">
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
              >购买要求：{{ purchaseRequirementSummary }}</template
            >
          </el-alert>

          <div class="cost-divider"></div>

          <div
            class="cost-detail"
            :class="{ 'cost-detail--loading': quoteLoading }"
          >
            <div class="cost-item">
              <span>产品</span
              ><span>{{
                selectedProductSummaryName || selectedProductDisplayName
              }}</span>
            </div>
            <div class="cost-item" v-for="item in summaryItems" :key="item.key">
              <span>{{ item.label }}</span
              ><span>{{ item.value }}</span>
            </div>
            <div class="cost-item" v-if="selectedCycleLabel">
              <span>周期</span><span>{{ selectedCycleLabel }}</span>
            </div>
          </div>

          <div class="cost-divider"></div>

          <div
            class="cost-breakdown"
            :class="{ 'cost-breakdown--loading': quoteLoading }"
          >
            <div class="cost-item">
              <span>基础价格</span><span>¥{{ baseAmount }}</span>
            </div>
            <div class="cost-item" v-if="Number(setupFee) > 0">
              <span>开通费</span><span>¥{{ setupFee }}</span>
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
              <span>优惠券 {{ appliedCoupon.code }}</span
              ><span>-¥{{ appliedCoupon.discount_amount }}</span>
            </div>
            <div class="cost-item cost-item--discount" v-if="hasAgentDiscount">
              <span>代理折扣（{{ agentGroupName }}）</span
              ><span>-¥{{ agentDiscountAmount }}</span>
            </div>
          </div>

          <div class="cost-divider"></div>

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
                selectedProduct
                  ? "当前暂无可用优惠券，登录后如有优惠券会自动展示在这里。"
                  : "请选择商品后查看可用优惠券。"
              }}
            </div>
          </div>

          <div class="cost-divider"></div>

          <div
            class="cost-total"
            :class="{ 'cost-total--loading': quoteLoading }"
          >
            <span class="cost-total-label">合计费用</span>
            <div class="cost-price-wrap">
              <template v-if="quoteLoading">
                <span class="cost-currency">¥</span>
                <span class="cost-amount cost-amount--loading">计算中</span>
              </template>
              <template v-else-if="hasAgentDiscount">
                <span class="cost-amount cost-amount--original"
                  >¥{{ agentOriginalAmount }}</span
                >
                <span class="cost-amount">¥{{ agentAmount }}</span>
              </template>
              <template v-else>
                <span class="cost-currency">¥</span>
                <span class="cost-amount">{{ totalPrice }}</span>
              </template>
              <span class="cost-cycle"
                >/{{ selectedCycleLabel || "月付" }}</span
              >
            </div>
          </div>

          <button
            class="buy-btn"
            :disabled="!canSubmit || submitting || quoteLoading"
            :class="{ loading: submitting, 'is-sold-out': soldOut }"
            @click="handleSubmit"
          >
            <span>{{
              soldOut ? "已售罄" : submitting ? "提交中..." : "立即购买"
            }}</span>
          </button>
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
              <span class="allocation-footer-label">费用合计：</span>
              <div class="allocation-footer-price">
                <template v-if="quoteLoading">
                  <span class="allocation-footer-symbol">¥</span>
                  <span class="allocation-footer-num">…</span>
                </template>
                <template v-else-if="hasAgentDiscount">
                  <span class="allocation-footer-original"
                    >¥{{ agentOriginalAmount }}</span
                  >
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
                    ? `代理折扣（${agentGroupName}）`
                    : appliedCoupon
                      ? `已优惠 ¥${appliedCoupon.discount_amount}`
                      : "无折扣"
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
                    ? `使用优惠券 -¥${appliedCoupon.discount_amount}`
                    : "使用优惠券"
                }}
              </button>
            </div>
          </div>
          <div class="allocation-footer-actions">
            <button
              class="allocation-footer-action"
              :disabled="!canSubmit || submitting || quoteLoading"
              :class="{ loading: submitting, 'is-sold-out': soldOut }"
              @click="handleSubmit"
            >
              <span>{{
                soldOut ? "已售罄" : submitting ? "提交中..." : "加入购物车"
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
              商品介绍
            </button>
            <button
              type="button"
              class="allocation-footer-detail-btn"
              @click="mobileCostDrawer = true"
            >
              <el-icon class="allocation-footer-detail-icon"
                ><Document
              /></el-icon>
              费用明细
            </button>
          </div>
        </div>
      </div>

      <MobileSheet
        v-model="mobileIntroDrawer"
        size="40%"
        title="商品介绍"
        confirm-text="关闭"
        @confirm="mobileIntroDrawer = false"
      >
        <div class="mobile-intro-sheet-body">
          <p>{{ productIntroText }}</p>
        </div>
      </MobileSheet>

      <MobileSheet
        v-model="mobileCostDrawer"
        size="78%"
        title="费用明细"
        confirm-text="关闭"
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
              >购买要求：{{ purchaseRequirementSummary }}</template
            >
          </el-alert>

          <div class="mobile-cost-table-card">
            <div class="mobile-cost-table-head">
              <span>费用明细：</span>
              <strong>共{{ mobileCostRows.length }}项</strong>
            </div>
            <div class="mobile-cost-table">
              <div class="mobile-cost-table-header">
                <span>配置名称</span>
                <span>配置详情</span>
                <span>{{
                  appliedCoupon ? "折后价已优惠" : "折后价无折扣"
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
                <span>总价：</span>
                <strong>¥{{ totalPrice }}</strong>
              </div>
            </div>
          </div>
        </div>
      </MobileSheet>

      <MobileSheet
        v-model="mobileCouponDrawer"
        size="42%"
        title="优惠券"
        confirm-text="关闭"
        @confirm="mobileCouponDrawer = false"
      >
        <div class="mobile-coupon-sheet-body">
          <div class="coupon-panel">
            <div class="coupon-panel-head">
              <span class="coupon-panel-title">选择优惠券</span>
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
                selectedProduct
                  ? "当前暂无可用优惠券，登录后如有优惠券会自动展示在这里。"
                  : "请选择商品后查看可用优惠券。"
              }}
            </div>
          </div>
        </div>
      </MobileSheet>

      <MobileRegionPicker
        :visible="mobileRegionDrawer"
        :regions="rootGroups"
        :zone-map="tempChildGroups"
        :active-group-id="activeGroupId"
        :active-zone-id="activeChildId"
        @close="mobileRegionDrawer = false"
        @change="handleRegionChange"
        @confirm="confirmRegionSelection"
      />

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
        v-if="hasMobileProductGroups"
        :visible="mobileSpecFamilyDrawer"
        title="产品规格"
        :options="mobileSpecFamilyOptions"
        :active-id="selectedMobileSpecFamily?.key || ''"
        @close="mobileSpecFamilyDrawer = false"
        @confirm="confirmMobileSpecFamilySelection"
      />

      <MobileOptionPicker
        v-if="hasMobileProductGroups"
        :visible="mobileGroupedCpuDrawer"
        title="CPU"
        :options="mobileGroupedCpuOptions"
        :active-id="selectedMobileProductGroup?.key || ''"
        @close="mobileGroupedCpuDrawer = false"
        @confirm="confirmMobileGroupedCpuSelection"
      />

      <MobileOptionPicker
        v-if="hasMobileProductGroups"
        :visible="mobileGroupedMemoryDrawer"
        title="内存"
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

      <el-drawer
        v-model="mobileCategoryDrawer"
        direction="ltr"
        size="72%"
        :with-header="false"
        class="mobile-category-drawer"
      >
        <div class="mobile-drawer-sheet">
          <div class="mobile-drawer-head">
            <div>
              <div class="mobile-drawer-title">地区</div>
              <p class="mobile-drawer-desc">
                {{ activeTypeLabel || "当前一级菜单" }}
              </p>
            </div>
            <button
              type="button"
              class="mobile-drawer-switch"
              @click="returnToMobileTypePicker"
            >
              切换一级菜单
            </button>
          </div>

          <div class="mobile-drawer-list">
            <button
              v-for="g in rootGroups"
              :key="g.id"
              type="button"
              class="mobile-drawer-item"
              :class="{ active: activeGroupId === g.id }"
              @click="handleMobileGroupSelect(g.id)"
            >
              <span>{{ g.name }}</span>
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
            </button>
          </div>
        </div>
      </el-drawer>

      <MobileSheet
        v-model="mobileProductDrawer"
        size="40%"
        title="请选择产品规格"
        cancel-text="取消"
        confirm-text="确定"
        @confirm="confirmMobileProductSelection"
      >
        <div class="mobile-product-sheet-list">
          <template v-if="hasMobileProductGroups">
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
                      aria-label="查看实例规格说明"
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
                      aria-label="查看实例规格说明"
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
                >{{ formatProductListPrice(p) }}</span
              >
            </button>
          </template>
        </div>
      </MobileSheet>
    </template>
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
import MobileRegionPicker from "@/components/MobileRegionPicker.vue";
import MobileOsPicker from "@/components/MobileOsPicker.vue";
import MobileOptionPicker from "@/components/MobileOptionPicker.vue";
import MobileRangePicker from "@/components/MobileRangePicker.vue";
import MobileSheet from "@/components/MobileSheet.vue";
import { resolveProductDisplayName } from "@/utils/websiteProductConfig";
import {
  buildInstanceSpecName,
  buildMachineSpecDisplayName,
  isCpuConfigKey,
  isMemoryConfigKey,
  normalizeMemorySpecText,
  parseMachineSpecFromText,
  resolveMachineSpecSelection,
} from "@/domains/products/machineSpecResolver";

const {
  pageLoading,
  configLoading,
  submitting,
  sidebarCollapsed,
  isMobile,
  mobileCategoryDrawer,
  mobileProductDrawer,
  mobileRegionDrawer,
  tempChildGroups,
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
  showMobileTypePicker,
  osConfig,
  osGroups,
  currentOsGroup,
  currentOsVersionLabel,
  machineConfigs,
  networkConfigs,
  otherConfigs,
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
  enterMobileType,
  returnToMobileTypePicker,
  openMobileRegionDrawer,
  handleRegionChange,
  confirmRegionSelection,
  handleMobileGroupSelect,
  openMobileProductDrawer,
  confirmMobileProductSelection: confirmRawMobileProductSelection,
  switchType,
  switchGroup,
  switchChild,
  selectProduct,
} = useWebsiteProductsPage();

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
});

onBeforeUnmount(() => {
  cleanupAllocationFooterObserver();
});

watch([isMobile, selectedProduct], () => {
  nextTick(setupAllocationFooterObserver);
});

function formatMoneyText(value) {
  const amount = Number(value || 0);
  return amount.toFixed(2);
}

function formatProductListPrice(product) {
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
    return `¥${formatMoneyText(fallbackEntry.amount)}/${fallbackEntry.label || "月"}`;
  }

  const pricing =
    product?.pricing && typeof product.pricing === "object"
      ? product.pricing
      : {};
  const monthlyAmount = Number(pricing.monthly || 0);
  if (monthlyAmount > 0) {
    return `¥${formatMoneyText(monthlyAmount)}/月`;
  }

  if (Number(product?.primary_price || 0) > 0) {
    return `¥${formatMoneyText(product.primary_price)}/${product.primary_cycle === "monthly" ? "月" : "起"}`;
  }

  return "";
}

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
      label: "配置名称",
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
const productIntroText = computed(() =>
  String(selectedProductGroup.value?.slogan || "").trim(),
);
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
          "通用型",
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

const machineSpecGridStyle = computed(() => {
  const columns = ["minmax(180px, 1.6fr)"];
  if (showCpuMemoryColumns.value) {
    columns.push("minmax(88px, 1fr)", "minmax(88px, 1fr)");
  }
  if (showProcessorColumns.value) {
    columns.push("minmax(120px, 1fr)", "minmax(120px, 1fr)");
  }
  for (let index = 0; index < specHighlightColumns.value.length; index++) {
    columns.push("minmax(92px, 1fr)");
  }
  columns.push("minmax(150px, 1.1fr)");
  const minWidth = 180 + (columns.length - 2) * 110 + 150;
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
const mobileSpecPickerLabel = computed(() =>
  hasMobileProductGroups.value
    ? selectedMobileSpecFamily.value?.label || ""
    : selectedMobileProductModelLabel.value,
);
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

  if (hasMobileProductGroups.value) {
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
  if (hasMobileProductGroups.value) {
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
  if (hasMobileProductGroups.value) {
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

function handleDesktopGroupChange(value) {
  const nextGroupId = Number(value || 0);
  if (!nextGroupId) {
    return;
  }

  void switchGroup(nextGroupId);
}

function handleDesktopChildChange(value) {
  const nextChildId = Number(value || 0);
  if (!nextChildId) {
    return;
  }

  switchChild(nextChildId);
}
</script>

<style scoped lang="scss" src="./page.scss"></style>
