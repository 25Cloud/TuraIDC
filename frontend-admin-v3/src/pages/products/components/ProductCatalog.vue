<template>
  <div>
    <t-card :bordered="false" class="type-card">
      <div class="type-strip">
        <t-button
          v-for="item in productTypeOptions"
          :key="item.value"
          :theme="catalogFilters.product_type === item.value ? 'primary' : 'default'"
          :variant="catalogFilters.product_type === item.value ? 'base' : 'outline'"
          :aria-label="`${item.label}，共${item.usage_count || 0}个商品`"
          :aria-pressed="catalogFilters.product_type === item.value ? 'true' : 'false'"
          @click="handleProductTypeChange(item.value)"
        >
          {{ item.label }}
          <span class="type-count">{{ item.usage_count || 0 }}</span>
        </t-button>
        <t-tooltip content="管理一级分类">
          <t-button
            class="type-manage-button"
            shape="square"
            variant="outline"
            aria-label="管理一级分类"
            @click="openTypeManagerDialog()"
          >
            <template #icon><setting-icon /></template>
          </t-button>
        </t-tooltip>
      </div>
    </t-card>

    <div class="catalog-layout">
      <t-card :bordered="false" class="category-panel">
        <div class="category-panel-head">
          <div class="category-panel-title">
            <strong>商品分类</strong>
          </div>
          <t-space size="small">
            <t-button
              shape="square"
              variant="text"
              :loading="categoryLoading"
              aria-label="刷新分类列表"
              @click="loadCategories"
            >
              <template #icon><refresh-icon /></template>
            </t-button>
            <t-button theme="primary" size="small" aria-label="新增二级分类" @click="openSecondCategoryDialog()">
              <template #icon><add-icon /></template>
              新增二级
            </t-button>
          </t-space>
        </div>
        <div class="category-toolbar">
          <t-input v-model="categoryKeyword" clearable placeholder="搜索分类或子菜单">
            <template #prefix-icon><search-icon /></template>
          </t-input>
        </div>

        <div v-if="categoryTreeRows.length" class="category-tree" role="tree" aria-label="商品分类树">
          <div
            v-for="{ node: item, level, childCount } in categoryTreeRows"
            :key="String(item.id)"
            class="category-tree-row"
            :class="{
              active: isCategoryRowActive(item),
              'active-parent': isCategoryRowParentOfSelected(item),
              disabled: categorySortLoadingId,
              'drag-over': categoryDragState?.level === level && categoryDragState?.scopeKey === categoryScopeKey(item),
            }"
            :style="{ '--category-level': level }"
            role="treeitem"
            :aria-level="level + 1"
            :aria-expanded="isCategoryExpanded(item) ? 'true' : 'false'"
            :aria-selected="isCategoryRowActive(item) ? 'true' : 'false'"
            @dragover.prevent="handleCategoryDragOver(item, $event)"
            @drop.prevent="handleCategoryDrop(item, $event)"
            @contextmenu.prevent.stop="openCategoryMenu(item, 'desktop')"
          >
            <button
              type="button"
              class="category-expand"
              :class="{ visible: childCount > 0, expanded: isCategoryExpanded(item) }"
              :disabled="childCount <= 0"
              :aria-label="
                isCategoryExpanded(item) ? `折叠${categoryDisplayName(item)}` : `展开${categoryDisplayName(item)}`
              "
              @click.stop="toggleCategoryExpanded(item)"
            />
            <button
              type="button"
              class="category-drag"
              :class="{ 'is-dragging': categoryDragState?.key === categoryIdKey(item) }"
              draggable="true"
              :disabled="categorySortLoadingId ? true : undefined"
              :aria-label="`拖动排序：${categoryDisplayName(item)}`"
              @dragstart="handleCategoryDragStart(item, $event)"
              @dragend="handleCategoryDragEnd"
              @click.stop
            >
              ::
            </button>
            <button type="button" class="category-tree-main" @click="handleCategorySelect(item)">
              <span class="category-title-line">
                <span class="category-level-tag" :class="`category-level-${level}`">{{
                  level === 0 ? '一级' : '二级'
                }}</span>
                <span class="category-name" :class="{ 'is-hidden': !isCategoryVisible(item) }">
                  {{ categoryDisplayName(item) }}
                </span>
                <t-tag
                  v-if="!isCategoryVisible(item)"
                  theme="warning"
                  variant="light"
                  size="small"
                  style="margin-left: 4px"
                  >隐藏</t-tag
                >
              </span>
            </button>
            <t-dropdown
              class="category-menu"
              trigger="click"
              placement="bottom-right"
              :options="categoryMenuOptions(item)"
              :popup-props="{
                visible: categoryMenuKey === categoryMenuVisibleKey('desktop', item),
                onVisibleChange: (visible: boolean) => handleCategoryMenuVisible(visible, item, 'desktop'),
              }"
              @click="handleCategoryMenuClick(item, $event as DropdownOption)"
            >
              <t-button
                class="category-menu-trigger"
                size="small"
                shape="square"
                variant="text"
                :aria-label="`更多操作：${categoryDisplayName(item)}`"
                >...</t-button
              >
            </t-dropdown>
          </div>
        </div>
        <t-empty v-else-if="categoryOptions.length" description="无匹配分类" />
        <t-empty v-else description="暂无分类" />
      </t-card>

      <t-card :bordered="false" class="product-table-card">
        <button type="button" class="mobile-catalog-sidebar-entry" @click="mobileCatalogDrawerVisible = true">
          <span>筛选分类</span>
          <strong>{{ selectedProductTypeLabel }} / {{ selectedCategoryLabel }}</strong>
        </button>

        <div class="filter-row catalog-filter-row">
          <t-input
            v-model="catalogFilters.keyword"
            class="catalog-filter-keyword"
            clearable
            placeholder="搜索商品名称"
            aria-label="搜索商品名称"
            @enter="handleCatalogSearch"
            @clear="handleCatalogSearch"
          >
            <template #suffix-icon><search-icon /></template>
          </t-input>
          <t-select
            v-model="catalogFilters.status"
            class="catalog-filter-status"
            clearable
            placeholder="显示状态"
            aria-label="筛选显示状态"
            @change="handleCatalogSearch"
          >
            <t-option :value="1" label="显示中" />
            <t-option :value="0" label="已隐藏" />
          </t-select>
          <t-select
            v-model="catalogFilters.lifecycle_status"
            class="catalog-filter-status"
            placeholder="商品范围"
            aria-label="筛选商品范围"
            @change="handleCatalogSearch"
          >
            <t-option
              v-for="item in lifecycleStatusOptions"
              :key="item.value"
              :value="item.value"
              :label="item.label"
            />
          </t-select>
          <div class="catalog-filter-actions">
            <t-button theme="primary" @click="router.push({ name: 'AdminProductCreate' })">
              <template #icon><add-icon /></template>
              新增商品
            </t-button>
          </div>
        </div>

        <div v-if="selectedProductKeys.length" class="product-batch-bar" aria-live="polite">
          <span>已选 {{ selectedProductKeys.length }} 个商品</span>
          <t-space size="small">
            <t-button variant="outline" @click="openBatchCategoryDialog"> 批量归类 </t-button>
            <t-button variant="outline" @click="openProductBatchDialog('console-template', selectedProductRows())">
              控制台类型
            </t-button>
            <t-button variant="outline" @click="openProductBatchDialog('discount-group', selectedProductRows())">
              折扣分组
            </t-button>
            <t-button variant="outline" :loading="splitProductPreviewLoading" @click="openSplitProductDialog">
              拆分商品
            </t-button>
            <t-button variant="outline" @click="openProvisionHostnameDialog"> 批量主机名 </t-button>
            <t-button variant="outline" theme="danger" @click="handleBatchDeleteProducts"> 删除 </t-button>
            <t-button variant="outline" theme="danger" @click="handleBatchForceDeleteProducts"> 强制删除 </t-button>
            <t-button variant="text" @click="clearProductSelection">清空选择</t-button>
          </t-space>
        </div>

        <div class="table-scroll">
          <t-table
            row-key="id"
            :data="products"
            :columns="productColumns"
            :loading="productLoading"
            :selected-row-keys="selectedProductKeys"
            hover
            table-layout="fixed"
            aria-label="商品列表"
            @select-change="handleProductSelectChange"
          >
            <template #empty>
              <t-empty
                :description="
                  productLoading
                    ? '正在加载...'
                    : catalogFilters.keyword ||
                        catalogFilters.status !== '' ||
                        catalogFilters.lifecycle_status !== 'active'
                      ? '筛选无匹配商品'
                      : '暂无商品'
                "
              />
            </template>
            <template #name="{ row }">
              <div class="product-name">
                <button
                  type="button"
                  class="product-name-link"
                  :class="{ 'is-hidden': !isProductVisible(row) }"
                  :title="productSpecDisplayName(row)"
                  @click.stop="goProductEditPage(row)"
                >
                  {{ productSpecDisplayName(row) }}
                </button>
                <span>{{ productSubtitle(row) }}</span>
              </div>
            </template>
            <template #status="{ row }">
              <span
                class="visibility-status-text"
                :class="{ 'is-hidden': !isProductVisible(row), 'is-deleted': row.is_deleted }"
              >
                {{ productVisibilityLabel(row) }}
              </span>
            </template>
            <template #cpu="{ row }">
              <div class="product-cpu-cell">
                <span class="product-cpu-cell__model">{{ row.cpu_model || '—' }}</span>
                <span v-if="row.cpu_turbo" class="product-cpu-cell__turbo">睿频 {{ row.cpu_turbo }}</span>
              </div>
            </template>
            <template #discountGroup="{ row }">
              <t-tag v-if="row.product_discount_group_id" theme="primary" variant="light" size="small">
                {{ row.product_discount_group_name || `分组#${row.product_discount_group_id}` }}
              </t-tag>
              <span v-else class="product-discount-group-empty">未设置</span>
            </template>
            <template #price="{ row }">
              {{ formatMonthlyPrice(row) }}
            </template>
            <template #stock="{ row }">
              {{ formatStock(row.stock) }}
            </template>
            <template #services_count="{ row }">
              {{ formatCount(row.active_services_count ?? row.services_count) }}
            </template>
            <template #operation="{ row }">
              <div class="product-row-actions" @contextmenu.prevent.stop="openProductRowMenu(row.id)">
                <t-dropdown
                  trigger="click"
                  placement="bottom-right"
                  :options="productRowMenuOptions(row)"
                  :popup-props="{
                    overlayClassName: 'product-row-dropdown',
                    visible: productRowMenuKey === row.id,
                    onVisibleChange: (visible: boolean) => handleProductRowMenuVisible(visible, row.id),
                  }"
                  @click="handleProductRowMenuClick(row, $event as DropdownOption)"
                >
                  <t-button
                    size="small"
                    shape="square"
                    variant="text"
                    :aria-label="`更多操作：${row.display_name || row.name || row.id}`"
                    >...</t-button
                  >
                </t-dropdown>
              </div>
            </template>
          </t-table>
        </div>

        <div v-if="productTotal > 0" class="pagination-row">
          <t-pagination
            :current="productPage"
            :page-size="productPageSize"
            :total="productTotal"
            :page-size-options="[20, 50, 100]"
            show-jumper
            @change="handleProductPageChange"
          />
        </div>
      </t-card>
    </div>

    <t-drawer
      v-model:visible="mobileCatalogDrawerVisible"
      placement="left"
      size="100vw"
      class="mobile-catalog-drawer"
      :footer="false"
    >
      <template #header>
        <div class="mobile-catalog-header">
          <button type="button" class="mobile-catalog-back" @click="mobileCatalogDrawerVisible = false">返回</button>
          <div class="mobile-catalog-title">
            <strong>筛选商品</strong>
            <span>{{ selectedProductTypeLabel }} / {{ selectedCategoryLabel }}</span>
          </div>
        </div>
      </template>

      <div class="mobile-catalog-drawer-body">
        <aside class="mobile-type-sidebar">
          <button
            v-for="item in productTypeOptions"
            :key="item.value"
            type="button"
            class="mobile-type-item"
            :class="{ active: catalogFilters.product_type === item.value }"
            @click="handleMobileProductTypeChange(item.value)"
          >
            <strong>{{ item.label }}</strong>
            <span>{{ item.usage_count || 0 }}</span>
          </button>
          <button
            type="button"
            class="mobile-type-tool mobile-type-tool--icon"
            aria-label="管理一级分类"
            title="管理一级分类"
            @click="openTypeManagerDialog()"
          >
            <setting-icon />
          </button>
        </aside>

        <section class="mobile-category-sidebar">
          <div class="category-panel-head">
            <div class="category-panel-title">
              <strong>商品分类</strong>
            </div>
            <t-space size="small">
              <t-button shape="square" variant="text" :loading="categoryLoading" @click="loadCategories">
                <template #icon><refresh-icon /></template>
              </t-button>
              <t-button theme="primary" size="small" @click="openSecondCategoryDialog()">
                <template #icon><add-icon /></template>
                新增二级
              </t-button>
            </t-space>
          </div>
          <div class="category-toolbar">
            <t-input v-model="categoryKeyword" clearable placeholder="搜索分类或子菜单">
              <template #prefix-icon><search-icon /></template>
            </t-input>
          </div>

          <div
            v-if="categoryTreeRows.length"
            class="category-tree mobile-category-tree"
            role="tree"
            aria-label="商品分类树"
          >
            <div
              v-for="{ node: item, level, childCount } in categoryTreeRows"
              :key="String(item.id)"
              class="category-tree-row"
              :class="{
                active: isCategoryRowActive(item),
                'active-parent': isCategoryRowParentOfSelected(item),
                disabled: categorySortLoadingId,
                'drag-over':
                  categoryDragState?.level === level && categoryDragState?.scopeKey === categoryScopeKey(item),
              }"
              :style="{ '--category-level': level }"
              role="treeitem"
              :aria-level="level + 1"
              :aria-expanded="isCategoryExpanded(item) ? 'true' : 'false'"
              :aria-selected="isCategoryRowActive(item) ? 'true' : 'false'"
              @dragover.prevent="handleCategoryDragOver(item, $event)"
              @drop.prevent="handleCategoryDrop(item, $event)"
              @contextmenu.prevent.stop="openCategoryMenu(item, 'mobile')"
            >
              <button
                type="button"
                class="category-expand"
                :class="{ visible: childCount > 0, expanded: isCategoryExpanded(item) }"
                :disabled="childCount <= 0"
                :aria-label="
                  isCategoryExpanded(item) ? `折叠${categoryDisplayName(item)}` : `展开${categoryDisplayName(item)}`
                "
                @click.stop="toggleCategoryExpanded(item)"
              />
              <button
                type="button"
                class="category-drag"
                :class="{ 'is-dragging': categoryDragState?.key === categoryIdKey(item) }"
                draggable="true"
                :disabled="categorySortLoadingId ? true : undefined"
                :aria-label="`拖动排序：${categoryDisplayName(item)}`"
                @dragstart="handleCategoryDragStart(item, $event)"
                @dragend="handleCategoryDragEnd"
                @click.stop
              >
                ::
              </button>
              <button type="button" class="category-tree-main" @click="handleMobileCategorySelect(item)">
                <span class="category-title-line">
                  <span class="category-level-tag" :class="`category-level-${level}`">{{
                    level === 0 ? '一级' : '二级'
                  }}</span>
                  <span class="category-name" :class="{ 'is-hidden': !isCategoryVisible(item) }">
                    {{ categoryDisplayName(item) }}
                  </span>
                  <t-tag
                    v-if="!isCategoryVisible(item)"
                    theme="warning"
                    variant="light"
                    size="small"
                    style="margin-left: 4px"
                    >隐藏</t-tag
                  >
                </span>
              </button>
              <t-dropdown
                class="category-menu"
                trigger="click"
                placement="bottom-right"
                :options="categoryMenuOptions(item)"
                :popup-props="{
                  visible: categoryMenuKey === categoryMenuVisibleKey('mobile', item),
                  onVisibleChange: (visible: boolean) => handleCategoryMenuVisible(visible, item, 'mobile'),
                }"
                @click="handleCategoryMenuClick(item, $event as DropdownOption)"
              >
                <t-button
                  class="category-menu-trigger"
                  size="small"
                  shape="square"
                  variant="text"
                  :aria-label="`更多操作：${categoryDisplayName(item)}`"
                  >...</t-button
                >
              </t-dropdown>
            </div>
          </div>
          <t-empty v-else-if="categoryOptions.length" description="无匹配分类" />
          <t-empty v-else description="暂无分类" />
        </section>
      </div>
    </t-drawer>

    <t-drawer
      v-model:visible="productDialogVisible"
      :header="editingProduct ? '编辑商品' : '新增商品'"
      size="860px"
      class="product-edit-drawer"
      :close-on-overlay-click="false"
      :footer="false"
      @close="closeProductDrawer"
    >
      <div class="product-drawer-shell">
        <div class="product-drawer">
          <aside class="product-drawer-nav">
            <button
              v-for="section in productDrawerSections"
              :key="section.key"
              type="button"
              class="product-drawer-nav-item"
              :class="[{ active: activeProductDrawerSection === section.key }]"
              :aria-current="activeProductDrawerSection === section.key ? 'step' : undefined"
              @click="setProductDrawerSection(section.key)"
            >
              <strong
                >{{ section.label
                }}<span
                  v-if="activeProductDrawerSection !== section.key && productDrawerSectionValid(section.key)"
                  class="nav-dot nav-dot--done"
                  aria-label="已完成"
                  >●</span
                ></strong
              >
              <span>{{ section.description }}</span>
            </button>
          </aside>

          <div class="product-drawer-main">
            <t-form
              ref="productFormRef"
              class="product-drawer-form"
              :data="productForm"
              :rules="productRules"
              label-width="120px"
            >
              <section v-show="activeProductDrawerSection === 'basic'" class="product-drawer-section" data-title="详情">
                <div class="product-drawer-grid">
                  <t-form-item label="商品名称" name="display_name">
                    <t-input v-model="productForm.display_name" />
                  </t-form-item>
                  <t-form-item label="所属分类" name="selected_product_group_key">
                    <t-select v-model="productForm.selected_product_group_key" filterable clearable>
                      <t-option
                        v-for="item in selectableProductGroupOptions"
                        :key="productGroupOptionKey(item)"
                        :label="productGroupOptionLabel(item)"
                        :value="productGroupOptionKey(item)"
                      />
                    </t-select>
                  </t-form-item>
                  <t-form-item label="状态" name="status"
                    ><t-switch v-model="productForm.status" :custom-value="[1, 0]"
                  /></t-form-item>
                </div>
              </section>

              <section
                v-show="activeProductDrawerSection === 'pricing'"
                class="product-drawer-section"
                data-title="定价"
              >
                <div class="product-pricing-actions">
                  <span class="pricing-hint">输入月付价格后，选择方案并自动计算其他周期</span>
                  <t-select
                    v-model="productPricingPlan"
                    size="small"
                    style="width: 150px"
                    @change="syncProductPricingCycles"
                  >
                    <t-option
                      v-for="item in productPricingPlanOptions"
                      :key="item.value"
                      :label="item.label"
                      :value="item.value"
                    />
                  </t-select>
                </div>
                <div class="product-drawer-grid">
                  <t-form-item label="月付价格" name="monthly_price">
                    <t-input-number v-model="productForm.monthly_price" :min="0" style="width: 100%" />
                  </t-form-item>
                  <t-form-item label="季付价格" name="quarterly_price">
                    <t-input-number v-model="productForm.quarterly_price" :min="0" style="width: 100%" />
                  </t-form-item>
                  <t-form-item label="半年付价格" name="semiannually_price">
                    <t-input-number v-model="productForm.semiannually_price" :min="0" style="width: 100%" />
                  </t-form-item>
                  <t-form-item label="年付价格" name="annually_price">
                    <t-input-number v-model="productForm.annually_price" :min="0" style="width: 100%" />
                  </t-form-item>
                </div>
              </section>

              <section
                v-show="activeProductDrawerSection === 'interface'"
                class="product-drawer-section product-interface-section"
                data-title="自动开通"
              >
                <div class="product-interface-tip">配置开通模块、支付方式和上架状态。</div>
                <div class="product-interface-panel">
                  <t-form-item label="提供商" name="supplier_id">
                    <t-select
                      v-model="productForm.supplier_id"
                      filterable
                      clearable
                      placeholder="请选择提供商接口"
                      :loading="productSupplierLoading"
                      @change="handleProductSupplierChange"
                    >
                      <t-option
                        v-for="item in productSupplierOptions"
                        :key="item.id"
                        :label="supplierOptionLabel(item)"
                        :value="item.id"
                      >
                        <div class="supplier-option-row">
                          <span>{{ item.name || item.id }}</span>
                          <span>{{ supplierInterfaceTypeLabel(item) }}</span>
                        </div>
                      </t-option>
                    </t-select>
                  </t-form-item>
                  <t-form-item label="提供商商品" name="upstream_product_id">
                    <div class="supplier-product-row">
                      <t-cascader
                        v-model="productForm.upstream_product_id"
                        :options="supplierProductCascaderOptions"
                        filterable
                        clearable
                        placeholder="请选择提供商商品"
                        :loading="supplierProductLoading"
                        :disabled="!productForm.supplier_id"
                        value-mode="onlyLeaf"
                        :show-all-levels="false"
                      />
                      <t-button
                        theme="primary"
                        :loading="supplierProductLoading"
                        :disabled="!productForm.supplier_id"
                        @click="loadProductSupplierProducts(productForm.supplier_id, true)"
                      >
                        同步数据
                      </t-button>
                    </div>
                  </t-form-item>
                  <div class="product-interface-grid">
                    <t-form-item label="自动开通" name="auto_setup">
                      <div class="product-switch-line">
                        <span>手动</span>
                        <t-switch v-model="productForm.auto_setup" :custom-value="[1, 0]" />
                        <span>自动</span>
                      </div>
                    </t-form-item>
                  </div>
                </div>
              </section>

              <section
                v-show="activeProductDrawerSection === 'config'"
                class="product-drawer-section"
                data-title="产品配置"
              >
                <div class="product-config-actions">
                  <t-button
                    size="small"
                    variant="outline"
                    :loading="configTemplateLoading"
                    @click="pullProductConfigTemplate"
                  >
                    拉取模板
                  </t-button>
                  <t-button size="small" theme="primary" variant="outline" @click="openConfigOptionDialog()"
                    >新增配置</t-button
                  >
                </div>
                <div class="config-option-panel">
                  <div class="config-option-count">
                    {{ productForm.config_options.length }} 项配置，保存商品后生效。
                  </div>
                  <div v-if="productForm.config_options.length" class="config-option-list">
                    <article v-for="(item, index) in productForm.config_options" :key="item.uid || item.field || index">
                      <div>
                        <strong>{{ item.name || item.option_name || item.field }}</strong>
                        <span>{{ item.field || '-' }} · {{ item.option_mode || 'select' }}</span>
                      </div>
                      <t-space size="small">
                        <t-button
                          size="small"
                          variant="text"
                          theme="primary"
                          @click="openConfigOptionDialog(item, index)"
                          >编辑</t-button
                        >
                        <t-button size="small" variant="text" theme="danger" @click="removeConfigOption(index)"
                          >删除</t-button
                        >
                      </t-space>
                    </article>
                  </div>
                  <t-empty v-else description="暂无配置项" />
                </div>
              </section>
            </t-form>
          </div>
        </div>

        <div class="product-drawer-footer">
          <t-button variant="outline" @click="closeProductDrawer">取消</t-button>
          <t-button theme="primary" :loading="productSubmitting" @click="submitProduct">保存更改</t-button>
        </div>
      </div>
    </t-drawer>

    <t-dialog
      v-model:visible="configOptionDialogVisible"
      class="config-option-edit-dialog"
      :header="configOptionEditingIndex >= 0 ? '编辑配置项' : '新增配置项'"
      width="760px"
      :confirm-btn="{ content: '保存配置', loading: configOptionSubmitting }"
      @confirm="submitConfigOption"
    >
      <t-form class="config-option-edit-form" :data="configOptionForm" label-align="top">
        <div class="config-option-basic-grid">
          <t-form-item label="配置项名称" name="name" required-mark>
            <t-input v-model="configOptionForm.name" placeholder="例如 CPU、内存" />
          </t-form-item>
          <t-form-item label="配置项类型" name="option_mode" required-mark>
            <t-select v-model="configOptionForm.option_mode" @change="handleConfigOptionModeChange">
              <t-option label="单选（固定选项）" value="select" />
              <t-option label="数量范围" value="range" />
            </t-select>
          </t-form-item>
          <t-form-item label="高级设置">
            <t-checkbox v-model="configOptionForm.advanced" />
          </t-form-item>

          <t-form-item label="配置标识" name="field" required-mark>
            <t-input v-model="configOptionForm.field" placeholder="例如 cpu、memory、os" />
          </t-form-item>
          <t-form-item label="选项说明">
            <t-input v-model="configOptionForm.description" placeholder="例如 镜像管理中的操作系统 ID。" />
          </t-form-item>
          <t-form-item label="选项尾部文字">
            <t-input v-model="configOptionForm.suffix_text" placeholder="请输入选项尾部文字" />
          </t-form-item>
        </div>

        <t-form-item v-if="configOptionForm.option_mode !== 'select'" label="参数值" name="parameter">
          <t-input v-model="configOptionForm.parameter" placeholder="例如 1-16 / 1|1核,2|2核" />
        </t-form-item>

        <div v-else class="config-subitem-table">
          <div class="config-subitem-head">
            <strong>子项列表</strong>
            <t-button size="small" variant="outline" @click="addConfigSubItemRow">新增子项</t-button>
          </div>
          <div class="config-subitem-grid config-subitem-grid--header">
            <span>子项名称</span>
            <span>传参值</span>
            <span>月付价格</span>
            <span>排序</span>
            <span>操作</span>
          </div>
          <div v-for="(row, index) in configOptionSubItemRows" :key="row.uid" class="config-subitem-grid">
            <t-input v-model="row.name" placeholder="例如 CentOS^CentOS-7.6" />
            <t-input v-model="row.value" placeholder="例如 27" />
            <t-input v-model="row.monthly_price" placeholder="0.00" />
            <t-input-number v-model="row.sort_order" :min="0" theme="column" />
            <t-button
              shape="square"
              variant="outline"
              :disabled="configOptionSubItemRows.length <= 1"
              @click="removeConfigSubItemRow(index)"
            >
              <delete-icon />
            </t-button>
          </div>
        </div>

        <div class="config-option-footer-row">
          <t-form-item label="排序" name="sort_order">
            <t-input-number v-model="configOptionForm.sort_order" :min="0" />
          </t-form-item>
          <t-form-item label="开关">
            <t-space>
              <t-checkbox v-model="configOptionForm.required">必选</t-checkbox>
              <t-checkbox v-model="configOptionForm.hidden">隐藏</t-checkbox>
            </t-space>
          </t-form-item>
        </div>
      </t-form>
    </t-dialog>

    <t-dialog
      v-model:visible="batchCategoryDialogVisible"
      header="批量归类"
      width="520px"
      :confirm-btn="{ content: '确认归类', loading: batchCategorySubmitting }"
      @confirm="submitBatchCategory"
    >
      <div class="batch-dialog-summary">将 {{ batchCategoryTargetKeys.length }} 个商品移动到目标分类。</div>
      <t-form :data="batchCategoryForm" label-width="110px">
        <t-form-item label="目标分类" name="target_product_group_key">
          <t-select
            v-model="batchCategoryForm.target_product_group_key"
            filterable
            clearable
            placeholder="请选择目标分类"
          >
            <t-option
              v-for="item in selectableProductGroupOptions"
              :key="productGroupOptionKey(item)"
              :label="productGroupOptionLabel(item)"
              :value="productGroupOptionKey(item)"
            />
          </t-select>
        </t-form-item>
      </t-form>
    </t-dialog>

    <t-dialog
      v-model:visible="productBatchDialogVisible"
      :header="productBatchDialogTitle"
      width="520px"
      :confirm-btn="{ content: '确认修改', loading: productBatchSubmitting }"
      @confirm="submitProductBatch"
    >
      <div class="batch-dialog-summary">
        {{
          productBatchTargetGroup
            ? `将修改「${productBatchTargetGroup.name}」下全部商品的设置。`
            : `将修改已选 ${productBatchTargetProductIds.length} 个商品的设置。`
        }}
      </div>
      <t-form label-width="110px">
        <t-form-item v-if="productBatchDialogKind === 'console-template'" label="控制台类型">
          <t-radio-group v-model="consoleTemplateValue" variant="default-filled">
            <t-radio-button value="compute">通用计算型</t-radio-button>
            <t-radio-button value="port_mapping">端口映射型</t-radio-button>
          </t-radio-group>
          <p class="batch-dialog-tip">端口映射型控制台适用于端口转发类业务，通用计算型为默认控制台。</p>
        </t-form-item>
        <t-form-item v-else-if="productBatchDialogKind === 'discount-group'" label="折扣分组">
          <t-select
            v-model="discountGroupValue"
            clearable
            :loading="discountGroupLoading"
            placeholder="选择代理折扣分组，清空则不分组"
          >
            <t-option
              v-for="item in discountGroupOptions"
              :key="item.id"
              :value="item.id"
              :label="`${item.name || item.code || item.id}${item.min_discount_rate ? `（最低 ${item.min_discount_rate}%）` : ''}`"
            />
          </t-select>
        </t-form-item>
        <template v-else-if="productBatchDialogKind === 'cpu-spec'">
          <t-form-item label="CPU 型号">
            <t-input v-model="cpuModelValue" placeholder="留空则清除 CPU 型号" maxlength="120" />
          </t-form-item>
          <t-form-item label="CPU 睿频">
            <t-input v-model="cpuTurboValue" placeholder="如 3.8GHz；留空则保持不变" maxlength="40" />
          </t-form-item>
          <p class="batch-dialog-tip">CPU 型号/睿频将直接写入商品，官网展示优先级高于 CPU 型号目录绑定。</p>
        </template>
      </t-form>
    </t-dialog>

    <t-dialog
      v-model:visible="splitProductDialogVisible"
      header="拆分商品"
      width="680px"
      :confirm-btn="{
        content: '确认拆分',
        loading: splitProductSubmitting,
        disabled: splitProductPreviewLoading || !splitProductPreviewRows.length,
      }"
      @confirm="submitSplitProducts"
    >
      <div class="split-dialog-intro">
        <strong>按 CPU 与内存子选项生成独立商品</strong>
        <p>系统会读取已选商品的 CPU、内存配置子项，基础规格更新原商品，其余规格生成独立商品。</p>
      </div>

      <div class="split-product-meta">
        <span>已选商品 {{ splitProductTargetKeys.length }} 个</span>
        <span v-if="splitProductPreviewCount">预计处理 {{ splitProductPreviewCount }} 个规格</span>
        <span v-if="splitProductSkippedCount">跳过 {{ splitProductSkippedCount }} 个</span>
      </div>

      <t-loading :loading="splitProductPreviewLoading">
        <div class="split-product-preview">
          <template v-if="splitProductPreviewRows.length">
            <article v-for="row in splitProductPreviewRows" :key="row.key" class="split-product-item">
              <div>
                <strong>{{ row.display_name || '-' }}</strong>
                <span>{{ row.source_display_name || '-' }}</span>
              </div>
              <t-tag :theme="row.action === 'update' ? 'warning' : 'success'" variant="light">
                {{ formatSplitAction(row.action) }}
              </t-tag>
            </article>
          </template>
          <t-empty v-else description="未找到可拆分的 CPU 或内存子选项" />
        </div>
      </t-loading>

      <t-alert theme="warning" message="基础规格沿用原商品；同名拆分商品会更新，不会重复创建。" />
    </t-dialog>

    <t-dialog
      v-model:visible="provisionHostnameDialogVisible"
      header="批量主机名规则"
      width="620px"
      :confirm-btn="{ content: '保存规则', loading: provisionHostnameSubmitting }"
      @confirm="submitProvisionHostname"
    >
      <div class="split-dialog-intro">
        <strong>设置商品开通主机名</strong>
        <p>该配置会在自动开通时优先用于上游 host 参数，保存后会覆盖所选商品的现有规则。</p>
      </div>

      <div class="split-product-meta">
        <span>已选商品 {{ provisionHostnameTargetKeys.length }} 个</span>
        <span v-if="provisionHostnameHasMixedRules">当前包含多种主机名规则</span>
      </div>

      <t-alert
        v-if="provisionHostnameHasMixedRules"
        theme="warning"
        message="当前选中的商品存在不同规则，保存后会统一覆盖为当前设置。"
      />

      <t-form class="hostname-rule-form" :data="provisionHostnameForm" label-width="120px">
        <t-form-item label="开通策略" name="mode">
          <t-radio-group v-model="provisionHostnameForm.mode" variant="default-filled">
            <t-radio-button value="system">跟随上游</t-radio-button>
            <t-radio-button value="fixed">固定主机名</t-radio-button>
            <t-radio-button value="prefix">指定前缀</t-radio-button>
          </t-radio-group>
        </t-form-item>
        <t-form-item v-if="provisionHostnameForm.mode === 'fixed'" label="固定主机名" name="value">
          <t-input v-model="provisionHostnameForm.value" maxlength="200" placeholder="例如 hk-vps-core" />
        </t-form-item>
        <template v-if="provisionHostnameForm.mode === 'prefix'">
          <t-form-item label="主机名前缀" name="value">
            <t-input v-model="provisionHostnameForm.value" maxlength="20" placeholder="例如 hk / sg / us" />
          </t-form-item>
          <t-form-item label="主机名总长度" name="length">
            <t-input-number v-model="provisionHostnameForm.length" :min="4" :max="63" />
          </t-form-item>
        </template>
      </t-form>

      <t-alert theme="info" :message="provisionHostnamePreviewText" />
    </t-dialog>

    <t-dialog
      v-model:visible="categoryDialogVisible"
      :header="categoryDialogHeader"
      width="560px"
      :confirm-btn="{ content: '保存', loading: categorySubmitting }"
      @confirm="submitCategory"
    >
      <t-form ref="categoryFormRef" :data="categoryForm" :rules="categoryRules" label-width="110px">
        <t-form-item label="分类名称" name="name"><t-input v-model="categoryForm.name" /></t-form-item>
        <t-form-item label="一级分类" name="product_type">
          <t-select
            v-model="categoryForm.product_type"
            filterable
            placeholder="请选择一级分类"
            :disabled="categoryProductTypeDisabled"
            @change="handleCategoryProductTypeChange"
          >
            <t-option
              v-for="item in categoryProductTypeOptions"
              :key="item.value"
              :label="item.label"
              :value="item.value"
            />
          </t-select>
        </t-form-item>
        <t-form-item v-if="categoryParentFieldVisible" label="二级分类父级" name="parent_id">
          <t-select
            v-model="categoryForm.parent_id"
            clearable
            filterable
            placeholder="不选择则新增二级分类，选择后新增三级分类"
            :disabled="categoryParentSelectDisabled"
            :loading="categoryParentLoading"
          >
            <t-option
              v-for="item in categoryParentCategoryOptions"
              :key="item.id"
              :label="categoryParentOptionLabel(item)"
              :value="item.id"
            />
          </t-select>
        </t-form-item>
        <t-form-item label="一句话说明" name="slogan"><t-input v-model="categoryForm.slogan" /></t-form-item>
        <t-form-item label="排序" name="sort_order"
          ><t-input-number v-model="categoryForm.sort_order" :min="0"
        /></t-form-item>
        <t-form-item label="前台可见" name="is_visible"
          ><t-switch v-model="categoryForm.is_visible" :custom-value="[1, 0]"
        /></t-form-item>
      </t-form>
    </t-dialog>

    <t-dialog
      v-model:visible="typeManagerDialogVisible"
      class="type-manager-dialog"
      header="管理一级分类"
      width="880px"
      :footer="false"
    >
      <div class="type-manager">
        <section class="type-manager-editor" :class="{ 'is-editing': editingTypeValue }">
          <div class="type-manager-editor__head">
            <div>
              <strong>{{ editingTypeValue ? '编辑一级分类' : '新增一级分类' }}</strong>
              <span v-if="editingTypeValue">{{ normalizeBusinessProductType(typeForm.product_type) }}</span>
            </div>
          </div>

          <t-form :data="typeForm" label-align="top" class="type-form">
            <div class="type-form-grid">
              <t-form-item label="一级分类名称" name="label" required-mark>
                <t-input v-model="typeForm.label" maxlength="40" placeholder="如 云服务器" />
              </t-form-item>
              <t-form-item label="产品类型" name="product_type" required-mark>
                <t-select v-model="typeForm.product_type" :options="businessProductTypeOptions" />
              </t-form-item>
              <t-form-item label="图标" name="icon" class="type-icon-form-item">
                <div class="type-icon-picker" role="radiogroup" aria-label="选择一级分类图标">
                  <button
                    v-for="item in productTypeIconOptions"
                    :key="item.value"
                    type="button"
                    class="type-icon-option"
                    :class="{ active: normalizedTypeFormIcon === item.value }"
                    role="radio"
                    :aria-checked="normalizedTypeFormIcon === item.value"
                    @click="selectTypeIcon(item.value)"
                  >
                    <t-icon :name="item.value" />
                    <span>{{ item.label }}</span>
                  </button>
                </div>
              </t-form-item>
              <t-form-item class="type-form-actions">
                <t-button theme="primary" :loading="typeSubmitting" @click="submitType">
                  <template #icon><add-icon v-if="!editingTypeValue" /><edit-icon v-else /></template>
                  {{ editingTypeValue ? '保存' : '新增' }}
                </t-button>
                <t-button variant="outline" @click="resetTypeForm">{{
                  editingTypeValue ? '取消编辑' : '重置'
                }}</t-button>
              </t-form-item>
            </div>
          </t-form>
        </section>

        <div class="type-manager-list">
          <div class="type-manager-list-head">
            <div>
              <strong>分类列表</strong>
              <span
                >{{ productTypes.length }} 个分类 · {{ typeManagerProductCount }} 个商品 ·
                {{ typeManagerHiddenCount }} 个隐藏</span
              >
            </div>
            <t-button size="small" variant="outline" :loading="typeLoading" @click="loadProductTypes">
              <template #icon><refresh-icon /></template>
              刷新
            </t-button>
          </div>

          <div class="type-manager-items">
            <article
              v-for="(item, index) in productTypes"
              :key="item.value"
              class="type-manager-item"
              :class="{ 'is-hidden': item.is_hidden, 'is-editing': editingTypeValue === item.value }"
            >
              <span class="type-manager-item__order">{{ index + 1 }}</span>
              <div class="type-manager-item__main">
                <div class="type-manager-item__title">
                  <strong>{{ item.label }}</strong>
                  <t-tag v-if="item.is_hidden" theme="warning" variant="light">隐藏</t-tag>
                  <t-tag v-if="editingTypeValue === item.value" theme="primary" variant="light">编辑中</t-tag>
                </div>
                <div class="type-manager-item__meta">
                  <span>{{ normalizeBusinessProductType(item.product_type) }}</span>
                  <span v-if="item.icon" class="type-manager-item__icon-meta">
                    <t-icon :name="resolveProductTypeIconName(item.icon)" />
                    {{ productTypeIconDisplayLabel(item.icon) }}
                  </span>
                </div>
              </div>
              <dl class="type-manager-item__stats">
                <div>
                  <dt>商品</dt>
                  <dd>{{ item.usage_count || 0 }}</dd>
                </div>
                <div>
                  <dt>分组</dt>
                  <dd>{{ item.group_count || 0 }}</dd>
                </div>
              </dl>
              <div class="type-manager-actions">
                <div class="type-manager-order-actions">
                  <t-tooltip content="上移">
                    <t-button
                      size="small"
                      shape="square"
                      variant="outline"
                      aria-label="上移一级分类"
                      :disabled="index === 0 || typeSubmitting"
                      @click="moveType(item.value, -1)"
                    >
                      <template #icon><arrow-up-icon /></template>
                    </t-button>
                  </t-tooltip>
                  <t-tooltip content="下移">
                    <t-button
                      size="small"
                      shape="square"
                      variant="outline"
                      aria-label="下移一级分类"
                      :disabled="index === productTypes.length - 1 || typeSubmitting"
                      @click="moveType(item.value, 1)"
                    >
                      <template #icon><arrow-down-icon /></template>
                    </t-button>
                  </t-tooltip>
                </div>
                <t-tooltip content="编辑">
                  <t-button
                    size="small"
                    shape="square"
                    variant="text"
                    theme="primary"
                    aria-label="编辑一级分类"
                    :disabled="typeSubmitting"
                    @click="editType(item)"
                  >
                    <template #icon><edit-icon /></template>
                  </t-button>
                </t-tooltip>
                <t-tooltip :content="item.is_hidden ? '显示' : '隐藏'">
                  <t-button
                    size="small"
                    shape="square"
                    variant="text"
                    aria-label="切换一级分类显示状态"
                    :disabled="typeSubmitting"
                    @click="toggleTypeHidden(item)"
                  >
                    <template #icon><browse-icon v-if="item.is_hidden" /><browse-off-icon v-else /></template>
                  </t-button>
                </t-tooltip>
                <t-tooltip content="删除">
                  <t-button
                    size="small"
                    shape="square"
                    variant="text"
                    theme="danger"
                    aria-label="删除一级分类"
                    :disabled="typeSubmitting"
                    @click="deleteType(item)"
                  >
                    <template #icon><delete-icon /></template>
                  </t-button>
                </t-tooltip>
              </div>
            </article>
          </div>
          <t-empty v-if="productTypes.length === 0" description="暂无一级分类" />
        </div>
        <div class="type-manager-footer">
          <t-button variant="outline" @click="typeManagerDialogVisible = false">关闭</t-button>
        </div>
      </div>
    </t-dialog>

    <t-dialog
      v-model:visible="specHighlightDialogVisible"
      header="规格描述栏"
      :confirm-btn="{ content: '保存', loading: specHighlightSubmitting }"
      :cancel-btn="{}"
      width="560px"
      @confirm="submitSpecHighlight"
    >
      <p class="spec-highlight-summary">当前生效摘要：{{ specHighlightSummary || '—' }}</p>
      <p class="spec-highlight-tip">留空维度自动从商品名 / 商品卡片 / 配置项提取。</p>
      <div class="spec-highlight-form">
        <label v-for="dimension in specHighlightDimensions" :key="dimension.key" class="spec-highlight-field">
          <span>{{ dimension.label }}</span>
          <t-input v-model="specHighlightForm[dimension.key]" placeholder="留空自动提取" clearable />
        </label>
      </div>
    </t-dialog>
  </div>
</template>
<script setup lang="ts">
import {
  AddIcon,
  ArrowDownIcon,
  ArrowUpIcon,
  BrowseIcon,
  BrowseOffIcon,
  DeleteIcon,
  EditIcon,
  RefreshIcon,
  SearchIcon,
  SettingIcon,
} from 'tdesign-icons-vue-next';
import type { DropdownOption, FormRules, PageInfo, PrimaryTableCol, SelectValue, TableRowData } from 'tdesign-vue-next';
import { DialogPlugin, MessagePlugin } from 'tdesign-vue-next';
import { computed, onMounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';

import { productDiscountGroupsApi } from '@/api/admin/agentDiscount';
import type { ProductDiscountGroupRecord } from '@/api/admin/types';
import type { ProductCategoryRecord, ProductRecord, ProductTypeRecord, SpecHighlightDimension } from '@/api/product';
import { productApi } from '@/api/product';
import type { ProviderTypeRecord, SupplierFormField, SupplierRecord } from '@/api/supplier';
import { supplierApi } from '@/api/supplier';

import {
  errorMessage,
  findProductGroupByKey,
  flattenCategories,
  formatCount,
  formatMonthlyPrice,
  formatStock,
  isSelectableProductGroup,
  mergeProviderTypeOptions,
  normalizeProviderTypeOptions,
  productGroupEffectiveId,
  productGroupLevel,
  productGroupOptionKey,
  productGroupOptionLabel,
  productGroupPayload,
  providerTypeFallbackLabels,
  providerTypeLabel,
  toPlainRecord,
} from '../composables/useProductShared';

type ProvisionHostnameMode = 'system' | 'fixed' | 'prefix';

interface SplitProductPreviewRow {
  key: string;
  product_id: number;
  display_name: string;
  source_product_id: number;
  source_display_name: string;
  action: string;
}

interface SupplierBatchProduct {
  id: number;
  name: string;
  type_label: string;
  remote_group_name: string;
  is_connected: boolean;
  connected_display_name: string;
  [key: string]: unknown;
}

interface CategoryTreeNode {
  item: ProductCategoryRecord;
  children: CategoryTreeNode[];
}

interface ProductConfigOptionRecord {
  uid?: string;
  field: string;
  name: string;
  option_name?: string;
  option_mode: string;
  parameter?: string;
  sub?: Array<Record<string, unknown>>;
  sub_items?: Array<Record<string, unknown>>;
  required?: boolean;
  hidden?: boolean;
  sort_order?: number;
  [key: string]: unknown;
}

interface ConfigOptionSubItemFormRow {
  uid: string;
  name: string;
  value: string;
  monthly_price: string;
  sort_order: number;
}

type ProductLifecycleStatus = 'active' | 'deleted' | 'all';

// --- State ---
const router = useRouter();
const typeLoading = ref(false);
const typeSubmitting = ref(false);
const categoryLoading = ref(false);
const productLoading = ref(false);
const productSubmitting = ref(false);
const productActionLoading = ref<number | string | null>(null);

const productTypes = ref<ProductTypeRecord[]>([]);
const categoryOptions = ref<ProductCategoryRecord[]>([]);
const categoryParentOptions = ref<ProductCategoryRecord[]>([]);
const categoryParentLoading = ref(false);
const products = ref<ProductRecord[]>([]);
const productTotal = ref(0);
const productPage = ref(1);
const productPageSize = ref(20);
const categoryKeyword = ref('');
const activeProductDrawerSection = ref('basic');
const categoryExpandedKeys = ref<Set<string>>(new Set());
const mobileCatalogDrawerVisible = ref(false);
const productSupplierOptions = ref<SupplierRecord[]>([]);
const productSupplierLoading = ref(false);
const supplierProductOptions = ref<SupplierBatchProduct[]>([]);
const supplierProductLoading = ref(false);
const providerTypes = ref<ProviderTypeRecord[]>([]);
const typeManagerDialogVisible = ref(false);
const editingTypeValue = ref('');
const typeForm = reactive({
  label: '',
  product_type: 'cloud_server',
  icon: '',
});

const catalogFilters = reactive({
  keyword: '',
  product_type: '',
  product_group_key: '' as string,
  status: '' as number | string,
  lifecycle_status: 'active' as ProductLifecycleStatus,
});

const productDialogVisible = ref(false);
const editingProduct = ref<ProductRecord | null>(null);
const productFormRef = ref();
const selectedProductKeys = ref<Array<string | number>>([]);
const batchCategoryDialogVisible = ref(false);
const batchCategorySubmitting = ref(false);
const batchCategoryTargetKeys = ref<Array<string | number>>([]);
const batchCategoryForm = reactive({
  target_product_group_key: '' as string,
});
const splitProductDialogVisible = ref(false);
const splitProductPreviewLoading = ref(false);
const splitProductSubmitting = ref(false);
const splitProductTargetKeys = ref<Array<string | number>>([]);
const splitProductPreview = ref<Record<string, unknown> | null>(null);
const provisionHostnameDialogVisible = ref(false);
const provisionHostnameSubmitting = ref(false);
const provisionHostnameTargetKeys = ref<Array<string | number>>([]);
const provisionHostnameHasMixedRules = ref(false);
const provisionHostnameForm = reactive({
  mode: 'system' as ProvisionHostnameMode,
  value: '',
  length: 12,
});
const specHighlightDialogVisible = ref(false);
const specHighlightSubmitting = ref(false);
const specHighlightTarget = ref<ProductRecord | null>(null);
const specHighlightSummary = ref('');
const specHighlightDimensions = ref<SpecHighlightDimension[]>([]);
const specHighlightForm = reactive<Record<string, string>>({});
interface ProductFormData {
  display_name: string;
  custom_display_name: string;
  product_spec_display: string;
  selected_product_group_key: string;
  monthly_price: number;
  quarterly_price: number;
  semiannually_price: number;
  annually_price: number;
  auto_setup: number;
  status: number;
  supplier_id: number | string;
  upstream_product_id: number | string;
  config_options: ProductConfigOptionRecord[];
}

const productForm = reactive<ProductFormData>({
  display_name: '',
  custom_display_name: '',
  product_spec_display: '',
  selected_product_group_key: '',
  monthly_price: 0,
  quarterly_price: 0,
  semiannually_price: 0,
  annually_price: 0,
  auto_setup: 1,
  status: 1,
  supplier_id: '',
  upstream_product_id: '',
  config_options: [],
});
const configOptionDialogVisible = ref(false);
const configOptionSubmitting = ref(false);
const configOptionEditingIndex = ref(-1);
const configTemplateLoading = ref(false);
const configOptionForm = reactive({
  name: '',
  field: '',
  option_mode: 'select',
  parameter: '',
  sub_items_text: '',
  description: '',
  suffix_text: '',
  advanced: true,
  required: true,
  hidden: false,
  sort_order: 0,
});
const configOptionSubItemRows = ref<ConfigOptionSubItemFormRow[]>([]);
const categoryDialogVisible = ref(false);
const editingCategory = ref<ProductCategoryRecord | null>(null);
const creatingThirdCategoryParent = ref<ProductCategoryRecord | null>(null);
const categoryFormRef = ref();
const categorySubmitting = ref(false);
const categorySortLoadingId = ref('');
const productRowMenuKey = ref<string | number | null>(null);
const categoryMenuKey = ref<string | null>(null);
const categoryDragState = ref<{ key: string; level: number; scopeKey: string } | null>(null);
const productBatchDialogVisible = ref(false);
const productBatchDialogKind = ref<'console-template' | 'discount-group' | 'cpu-spec'>('console-template');
const productBatchDialogTitle = ref('');
const productBatchSubmitting = ref(false);
const productBatchTargetProductIds = ref<number[]>([]);
const productBatchTargetGroup = ref<{ id: number; level: number; name: string } | null>(null);
const consoleTemplateValue = ref('compute');
const discountGroupValue = ref<number | string>('');
const discountGroupOptions = ref<ProductDiscountGroupRecord[]>([]);
const discountGroupLoading = ref(false);
const cpuModelValue = ref('');
const cpuTurboValue = ref('');
let categoryRequestVersion = 0;
let productRequestVersion = 0;
interface CategoryFormData {
  name: string;
  product_type: string;
  parent_id: number | string;
  slogan: string;
  sort_order: number;
  is_visible: number;
}

const categoryForm = reactive<CategoryFormData>({
  name: '',
  product_type: '',
  parent_id: '',
  slogan: '',
  sort_order: 0,
  is_visible: 1,
});

const productPricingPlan = ref('standard');
const productPricingPlanOptions = [
  { value: 'standard', label: '无优惠', ratios: { quarterly: 3, semiannually: 6, annually: 12 } },
  { value: 'annual', label: '年付优惠', ratios: { quarterly: 3, semiannually: 6, annually: 10 } },
  { value: 'bulk', label: '大额优惠', ratios: { quarterly: 2.7, semiannually: 5.1, annually: 8.4 } },
  { value: 'rule1', label: '规则一', ratios: { quarterly: 3, semiannually: 4.8, annually: 9 } },
  { value: 'rule2', label: '规则二', ratios: { quarterly: 2.7, semiannually: 5.1, annually: 9.6 } },
];
const lifecycleStatusOptions = [
  { value: 'active', label: '正常商品' },
  { value: 'deleted', label: '已删除商品' },
  { value: 'all', label: '全部商品' },
] satisfies Array<{ value: ProductLifecycleStatus; label: string }>;
const productTypeIconOptions = [
  { value: 'server', label: '服务器', aliases: ['vps', 'Server'] },
  { value: 'cloud', label: '云服务', aliases: ['cloud'] },
  { value: 'control-platform', label: '平台', aliases: ['Platform'] },
  { value: 'building', label: '机房', aliases: ['dedicated', 'OfficeBuilding'] },
  { value: 'desktop', label: '主机', aliases: ['hosting', 'Monitor'] },
  { value: 'internet', label: '网络', aliases: ['cdn'] },
  { value: 'hard-disk-storage', label: '存储', aliases: ['storage'] },
  { value: 'data-base', label: '数据库', aliases: ['database', 'db'] },
  { value: 'link', label: '域名', aliases: ['domain', 'Link'] },
  { value: 'lock-on', label: '安全', aliases: ['ssl'] },
  { value: 'component-grid', label: '应用', aliases: ['Grid', 'other'] },
  { value: 'tools', label: '工具', aliases: ['tool'] },
  { value: 'shop', label: '商品', aliases: ['product'] },
] satisfies Array<{ value: string; label: string; aliases?: string[] }>;

const productDrawerSections = [
  { key: 'basic', label: '详情', description: '名称、分类、状态' },
  { key: 'pricing', label: '定价', description: '多周期价格' },
  { key: 'interface', label: '接口设置', description: '上游商品绑定' },
  { key: 'config', label: '产品配置', description: '规格与可选项' },
];

const productColumns: PrimaryTableCol<TableRowData>[] = [
  { colKey: 'row-select', type: 'multiple', width: 54, fixed: 'left' },
  { colKey: 'id', title: 'ID', width: 80 },
  { colKey: 'name', title: '商品', minWidth: 220 },
  { colKey: 'effective_product_group_full_name', title: '分类', width: 180 },
  { colKey: 'price', title: '月价格', width: 120 },
  { colKey: 'stock', title: '库存', width: 100 },
  { colKey: 'services_count', title: '现存', width: 100 },
  { colKey: 'cpu', title: 'CPU 型号', width: 180 },
  { colKey: 'discountGroup', title: '折扣分组', width: 140 },
  { colKey: 'status', title: '显示状态', width: 110 },
  { colKey: 'operation', title: '操作', width: 72, fixed: 'right' },
];

const productRules: FormRules<ProductFormData> = {
  display_name: [{ required: true, message: '请输入商品名称', trigger: 'blur' }],
  selected_product_group_key: [{ required: true, message: '请选择所属分类', trigger: 'change' }],
};
const categoryRules: FormRules<CategoryFormData> = {
  name: [{ required: true, message: '请输入分类名称', trigger: 'blur' }],
  product_type: [{ required: true, message: '请选择一级分类', trigger: 'change' }],
};

// --- Computeds ---
const productTypeOptions = computed(() => {
  if (productTypes.value.length) return productTypes.value;
  return [{ value: '', label: '全部商品', usage_count: productTotal.value }];
});
const businessProductTypeOptions = [
  { value: 'cloud_server', label: '云服务器 (cloud_server)' },
  { value: 'game_cloud', label: '游戏云 (game_cloud)' },
  { value: 'cloud_desktop', label: '云电脑 (cloud_desktop)' },
  { value: 'bare_metal', label: '裸金属 (bare_metal)' },
  { value: 'cdn', label: 'CDN' },
  { value: 'other', label: '其他 (other)' },
  { value: 'physical_machine', label: '物理机 (physical_machine)' },
  { value: 'web_hosting', label: '虚拟主机 (web_hosting)' },
];
const businessProductTypeValueSet = new Set(businessProductTypeOptions.map((item) => item.value));
const typeManagerProductCount = computed(() =>
  productTypes.value.reduce((total, item) => total + (Number(item.usage_count || 0) || 0), 0),
);
const typeManagerHiddenCount = computed(() => productTypes.value.filter((item) => item.is_hidden).length);
const normalizedTypeFormIcon = computed(() => resolveProductTypeIconName(typeForm.icon));
const productDrawerSectionValid = (key: string) => {
  switch (key) {
    case 'basic':
      return !!(productForm.display_name || productForm.selected_product_group_key);
    case 'pricing':
      return !!(productForm.monthly_price || productForm.quarterly_price || productForm.annually_price);
    case 'interface':
      return !!(productForm.supplier_id || productForm.upstream_product_id);
    case 'config':
      return productForm.config_options.length > 0;
    default:
      return false;
  }
};
const selectedProductTypeLabel = computed(() => {
  const current = productTypeOptions.value.find((item) => String(item.value) === String(catalogFilters.product_type));
  return current?.label || '全部商品';
});
const categoryProductTypeOptions = computed(() => {
  const options = productTypeOptions.value.filter((item) => String(item.value || '').trim());
  if (options.length) return options;
  return catalogFilters.product_type
    ? [{ value: catalogFilters.product_type, label: selectedProductTypeLabel.value }]
    : [];
});
const categoryTree = computed(() => buildCategoryTree(categoryOptions.value));
// 顶部 type-strip 已展示一级分类（L1，例如"云服务器"），分类树里跳过该层，
// 将 L2（例如"襄阳"）作为树根展示，L3（例如"高宽"）作为其子节点，形成一级 + 二级菜单。
const displayCategoryTree = computed(() => toDisplayCategoryTree(categoryTree.value));
const filteredCategoryTree = computed(() =>
  filterCategoryTree(displayCategoryTree.value, categoryKeyword.value.trim().toLowerCase()),
);
const categoryTreeRows = computed(() => flattenCategoryTreeRows(filteredCategoryTree.value));
const selectableProductGroupOptions = computed(() =>
  categoryOptions.value.filter((item) => isSelectableProductGroup(item)),
);
const editingCategoryHasChildren = computed(() => {
  const current = editingCategory.value;
  if (!current) return false;
  if (Array.isArray(current.children) && current.children.length > 0) return true;
  return Number(current.children_count || 0) > 0;
});
const categoryDialogHeader = computed(() => {
  if (editingCategory.value) return '编辑分类';
  return creatingThirdCategoryParent.value ? '新增三级分类' : '新增二级分类';
});
const categoryParentFieldVisible = computed(() => {
  if (creatingThirdCategoryParent.value) return true;
  return Boolean(editingCategory.value && productGroupLevel(editingCategory.value) === 3);
});
const categoryProductTypeDisabled = computed(() => Boolean(creatingThirdCategoryParent.value));
const categoryParentSelectDisabled = computed(() => {
  if (creatingThirdCategoryParent.value) return true;
  return editingCategoryHasChildren.value || !categoryParentCategoryOptions.value.length;
});
const categoryParentCategoryOptions = computed(() => {
  const editingCategoryKey = editingCategory.value ? productGroupOptionKey(editingCategory.value) : '';
  const selectedFirstGroupCode = String(categoryForm.product_type || catalogFilters.product_type || '').trim();

  return categoryParentOptions.value.filter((item) => {
    if (editingCategoryKey && productGroupOptionKey(item) === editingCategoryKey) return false;
    if (productGroupLevel(item) !== 2) return false;
    if (
      selectedFirstGroupCode &&
      String(item.first_product_group_code || '') &&
      String(item.first_product_group_code) !== selectedFirstGroupCode
    ) {
      return false;
    }
    return true;
  });
});
const selectedCategoryLabel = computed(() => {
  const selectedKey = String(catalogFilters.product_group_key || '');
  if (!selectedKey) return '全部分类';
  const category = findProductGroupByKey(categoryOptions.value, selectedKey);
  return category ? categoryDisplayName(category) : '全部分类';
});
const splitProductPreviewItems = computed(() => {
  const items = splitProductPreview.value?.items;
  return Array.isArray(items) ? items : [];
});
const splitProductPreviewRows = computed<SplitProductPreviewRow[]>(() =>
  splitProductPreviewItems.value.flatMap((itemValue) => {
    const item = toPlainRecord(itemValue);
    const variants = Array.isArray(item.variants) ? item.variants : [];
    return variants.map((variantValue) => {
      const variant = toPlainRecord(variantValue);
      return {
        key: `${item.source_product_id || 'source'}-${variant.variant_key || variant.display_name || variant.product_id || 'row'}`,
        product_id: Number(variant.product_id || variant.id || 0),
        display_name: String(variant.display_name || '-'),
        source_product_id: Number(item.source_product_id || item.product_id || 0),
        source_display_name: String(variant.source_display_name || item.source_display_name || '-'),
        action: String(variant.action || 'create'),
      };
    });
  }),
);
const splitProductPreviewCount = computed(() => Number(splitProductPreview.value?.preview_count || 0));
const splitProductSkippedCount = computed(() => Number(splitProductPreview.value?.skipped_count || 0));
const provisionHostnamePreviewText = computed(() => {
  if (provisionHostnameForm.mode === 'fixed') {
    const value = provisionHostnameForm.value.trim();
    return value
      ? `开通时将固定提交主机名：${value}。若上游不允许重复主机名，可能会被拒绝。`
      : '固定主机名模式需要填写一个上游允许的 host 值。';
  }

  if (provisionHostnameForm.mode === 'prefix') {
    const prefix = provisionHostnameForm.value.trim() || 'prefix';
    return `开通时将按"${prefix} + 随机后缀"生成主机名，总长度 ${Number(provisionHostnameForm.length || 12)}。`;
  }

  return '默认优先按上游商品规则生成；若上游未返回规则，则回退到系统默认规则或账单快照。';
});
const providerTypeOptions = computed(() => {
  return mergeProviderTypeOptions(providerTypes.value);
});
const supplierProductCascaderOptions = computed(() => {
  const groups = new Map<string, SupplierBatchProduct[]>();
  supplierProductOptions.value.forEach((item) => {
    const groupName = String(item.remote_group_name || item.type_label || '默认分组');
    const list = groups.get(groupName) || [];
    list.push(item);
    groups.set(groupName, list);
  });

  return Array.from(groups.entries()).map(([groupName, items], index) => ({
    label: groupName,
    value: `supplier-product-group-${index}`,
    children: items.map((item) => ({
      label: supplierProductOptionLabel(item),
      value: item.id,
    })),
  }));
});

// --- Category tree helpers ---
function categoryIdKey(row: ProductCategoryRecord) {
  return productGroupOptionKey(row);
}

function categoryDisplayName(row: ProductCategoryRecord) {
  return String(row.name || row.label || `分类 #${row.id}`).trim();
}

function isCategoryVisible(row: ProductCategoryRecord) {
  const value = row.is_visible;
  return !(value === false || value === 0 || value === '0');
}

function countValue(value: unknown) {
  const count = Number(value);
  return Number.isFinite(count) && count > 0 ? count : 0;
}

function categoryActiveProductCount(row: ProductCategoryRecord) {
  return countValue(row.products_count ?? row.product_count);
}

function categoryTotalProductCount(row: ProductCategoryRecord) {
  const total = countValue(row.products_with_trashed_count);
  return total > 0 ? total : categoryActiveProductCount(row);
}

function productVisibilityLabel(row: ProductRecord) {
  if (row.is_deleted) return '已删除';
  return isProductVisible(row) ? '显示中' : '已隐藏';
}

function isProductVisible(row: ProductRecord) {
  return Number(row.status) === 1;
}

function productRowMenuOptions(row: ProductRecord): DropdownOption[] {
  if (row.is_deleted) {
    return [
      {
        content: '恢复',
        value: 'restore',
        theme: 'primary',
        loading: productActionLoading.value === `restore:${row.id}`,
      },
      {
        content: '彻底删除',
        value: 'force-delete',
        theme: 'error',
        loading: productActionLoading.value === `force:${row.id}`,
      },
    ] as DropdownOption[];
  }
  return [
    { content: '编辑', value: 'edit', theme: 'default' },
    { content: '规格描述栏', value: 'spec-highlight', theme: 'default' },
    { content: '控制台类型', value: 'console-template', theme: 'default' },
    { content: '代理折扣分组', value: 'discount-group', theme: 'default' },
    {
      content: Number(row.status) === 1 ? '隐藏' : '显示',
      value: 'toggle',
      theme: 'default',
      loading: productActionLoading.value === row.id,
    },
    { content: '删除', value: 'delete', theme: 'error' },
  ] as DropdownOption[];
}

function handleProductRowMenuClick(row: ProductRecord, dropdownItem: DropdownOption) {
  const value = String(dropdownItem.value || '');
  switch (value) {
    case 'edit':
      goProductEditPage(row);
      break;
    case 'spec-highlight':
      openSpecHighlightDialog(row);
      break;
    case 'console-template':
      openProductBatchDialog('console-template', [row]);
      break;
    case 'discount-group':
      openProductBatchDialog('discount-group', [row]);
      break;
    case 'toggle':
      handleToggleProduct(row);
      break;
    case 'delete':
      handleDeleteProduct(row);
      break;
    case 'restore':
      handleRestoreProduct(row);
      break;
    case 'force-delete':
      handleForceDeleteProduct(row);
      break;
  }
}

async function openSpecHighlightDialog(row: ProductRecord) {
  specHighlightTarget.value = row;
  specHighlightSummary.value = '';
  specHighlightDimensions.value = [];
  specHighlightDialogVisible.value = true;
  Object.keys(specHighlightForm).forEach((key) => delete specHighlightForm[key]);
  try {
    const response = await productApi.specHighlight(String(row.id));
    specHighlightSummary.value = response.spec_highlight_text || '';
    specHighlightDimensions.value = Array.isArray(response.dimensions) ? response.dimensions : [];
    const overrides = response.overrides || {};
    for (const dimension of specHighlightDimensions.value) {
      specHighlightForm[dimension.key] = overrides[dimension.key] || '';
    }
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '加载规格描述栏失败'));
  }
}

async function submitSpecHighlight() {
  const target = specHighlightTarget.value;
  if (!target) return;
  specHighlightSubmitting.value = true;
  try {
    const items: Record<string, string> = {};
    for (const dimension of specHighlightDimensions.value) {
      const value = String(specHighlightForm[dimension.key] || '').trim();
      if (value !== '') {
        items[dimension.key] = value;
      }
    }
    await productApi.saveSpecHighlight(String(target.id), items);
    MessagePlugin.success('规格描述栏已更新');
    specHighlightDialogVisible.value = false;
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '保存规格描述栏失败'));
  } finally {
    specHighlightSubmitting.value = false;
  }
}

function buildCategoryTree(list: ProductCategoryRecord[]): CategoryTreeNode[] {
  const nodeMap = new Map<string, CategoryTreeNode>();
  const roots: CategoryTreeNode[] = [];

  // First pass: create all nodes
  list.forEach((item) => {
    const key = categoryIdKey(item);
    const existingNode = nodeMap.get(key);
    if (existingNode) {
      // Merge children from backend response if node already exists
      const backendChildren = Array.isArray(item.children) ? item.children : [];
      if (backendChildren.length > 0 && existingNode.children.length === 0) {
        existingNode.children = backendChildren.map((child: ProductCategoryRecord): CategoryTreeNode => ({
          item: child,
          children: [],
        }));
      }
    } else {
      nodeMap.set(key, { item, children: [] });
    }
  });

  // Second pass: build parent-child relationships using parent_id
  // We need to find parent by trying different level prefixes since parent_id is just a number
  list.forEach((item) => {
    const node = nodeMap.get(categoryIdKey(item));
    if (!node) return;

    const parentId =
      item.parent_id === undefined || item.parent_id === null || item.parent_id === '' ? '' : String(item.parent_id);
    if (!parentId) {
      roots.push(node);
      return;
    }

    // Try to find parent by trying level prefixes (parent level = current level - 1)
    const currentLevel = productGroupLevel(item);
    const parentLevel = currentLevel - 1;
    if (parentLevel < 1) {
      roots.push(node);
      return;
    }

    const parentKey = `${parentLevel}:${parentId}`;
    const parent = nodeMap.get(parentKey);
    if (parent) {
      // Check if node is already in parent's children
      if (!parent.children.some((child) => categoryIdKey(child.item) === categoryIdKey(item))) {
        parent.children.push(node);
      }
    } else {
      roots.push(node);
    }
  });

  return roots;
}

function filterCategoryTree(nodes: CategoryTreeNode[], keyword: string): CategoryTreeNode[] {
  if (!keyword) return nodes;

  return nodes
    .map((node) => {
      const children = filterCategoryTree(node.children, keyword);
      const matched = [node.item.name, node.item.label].some((value) =>
        String(value || '')
          .toLowerCase()
          .includes(keyword),
      );
      if (matched) return { ...node, children: node.children };
      if (children.length) return { ...node, children };
      return null;
    })
    .filter((node): node is CategoryTreeNode => Boolean(node));
}

function toDisplayCategoryTree(nodes: CategoryTreeNode[]): CategoryTreeNode[] {
  const lifted: CategoryTreeNode[] = [];

  nodes.forEach((root) => {
    if (productGroupLevel(root.item) === 1) {
      lifted.push(...root.children);
      return;
    }

    lifted.push(root);
  });

  return lifted;
}

function flattenCategoryTreeRows(
  nodes: CategoryTreeNode[],
  level = 0,
): Array<{ node: ProductCategoryRecord; level: number; childCount: number }> {
  const rows: Array<{ node: ProductCategoryRecord; level: number; childCount: number }> = [];
  const keyword = categoryKeyword.value.trim();

  nodes.forEach((treeNode) => {
    const childCount = treeNode.children.length;
    rows.push({ node: treeNode.item, level, childCount });

    if (childCount > 0 && (keyword || categoryExpandedKeys.value.has(categoryIdKey(treeNode.item)))) {
      rows.push(...flattenCategoryTreeRows(treeNode.children, level + 1));
    }
  });

  return rows;
}

function syncCategoryExpandedKeys(nodes: CategoryTreeNode[]) {
  const validKeys = new Set<string>();
  const defaultKeys = new Set<string>();
  const selectedAncestorKeys = new Set<string>();
  const selectedKey = String(catalogFilters.product_group_key || '');

  function visit(node: CategoryTreeNode, level: number) {
    const key = categoryIdKey(node.item);
    validKeys.add(key);
    if (level === 0 && node.children.length) defaultKeys.add(key);
    node.children.forEach((child) => visit(child, level + 1));
  }

  function collectSelectedAncestors(node: CategoryTreeNode): boolean {
    const key = categoryIdKey(node.item);
    if (key === selectedKey) return true;

    const hasSelectedChild = node.children.some(collectSelectedAncestors);
    if (hasSelectedChild && node.children.length) selectedAncestorKeys.add(key);
    return hasSelectedChild;
  }

  nodes.forEach((node) => visit(node, 0));
  if (selectedKey) nodes.forEach(collectSelectedAncestors);

  const preserved = Array.from(categoryExpandedKeys.value).filter((key) => validKeys.has(key));
  categoryExpandedKeys.value = new Set([...defaultKeys, ...preserved, ...selectedAncestorKeys]);
}

function findCategoryTreeNode(nodes: CategoryTreeNode[], key: number | string): CategoryTreeNode | null {
  const targetKey = String(key);
  for (const node of nodes) {
    if (categoryIdKey(node.item) === targetKey) return node;
    const matchedChild = findCategoryTreeNode(node.children, targetKey);
    if (matchedChild) return matchedChild;
  }
  return null;
}

function firstDisplayCategoryNode(node: CategoryTreeNode): ProductCategoryRecord {
  if (isSelectableProductGroup(node.item)) return node.item;
  for (const child of node.children) {
    const matched = firstDisplayCategoryNode(child);
    if (isSelectableProductGroup(matched)) return matched;
  }
  return node.item;
}

function syncDefaultCatalogCategory(nodes = displayCategoryTree.value) {
  if (!nodes.length) {
    catalogFilters.product_group_key = '';
    return;
  }

  const selectedKey = String(catalogFilters.product_group_key || '');
  if (selectedKey) {
    const selectedNode = findCategoryTreeNode(nodes, selectedKey);
    if (selectedNode) {
      catalogFilters.product_group_key = categoryIdKey(firstDisplayCategoryNode(selectedNode));
      return;
    }
  }

  catalogFilters.product_group_key = categoryIdKey(firstDisplayCategoryNode(nodes[0]));
}

function isCategoryExpanded(row: ProductCategoryRecord) {
  return categoryExpandedKeys.value.has(categoryIdKey(row));
}

function isCategoryRowActive(row: ProductCategoryRecord) {
  const selectedKey = String(catalogFilters.product_group_key || '');
  if (!selectedKey) return false;
  // 仅高亮精确选中的行，父节点不再显示选中背景，避免父子背景连成一片
  return categoryIdKey(row) === selectedKey;
}

function isCategoryRowParentOfSelected(row: ProductCategoryRecord) {
  const selectedKey = String(catalogFilters.product_group_key || '');
  if (!selectedKey) return false;
  if (categoryIdKey(row) === selectedKey) return false;
  const node = findCategoryTreeNode(categoryTree.value, categoryIdKey(row));
  return Boolean(node?.children.length && categoryIdKey(firstDisplayCategoryNode(node)) === selectedKey);
}

function toggleCategoryExpanded(row: ProductCategoryRecord) {
  const next = new Set(categoryExpandedKeys.value);
  const key = categoryIdKey(row);
  if (next.has(key)) {
    next.delete(key);
  } else {
    next.add(key);
  }
  categoryExpandedKeys.value = next;
}

function textValue(value: unknown) {
  return String(value ?? '').trim();
}

function productSpecDisplayName(row: ProductRecord) {
  return (
    [
      row.product_display_name,
      row.display_name,
      row.name,
      row.combined_display_name,
      row.custom_display_name,
      row.product_spec_display,
      row.cpu_memory_display,
    ]
      .map(textValue)
      .find(Boolean) || '-'
  );
}

function productSubtitle(row: ProductRecord) {
  const specName = productSpecDisplayName(row);
  return (
    [row.name, row.product_type_label, row.product_type, row.effective_product_group_full_name]
      .map(textValue)
      .find((item) => item && item !== specName) || '-'
  );
}

function normalizeCategoryScopeValue(value: unknown) {
  return value === undefined || value === null || value === '' ? '' : String(value);
}

function categoryScopeKey(row: ProductCategoryRecord) {
  return [
    normalizeCategoryScopeValue(row.first_product_group_code || catalogFilters.product_type),
    normalizeCategoryScopeValue(row.parent_id),
  ].join(':');
}

function getCategorySiblings(row: ProductCategoryRecord) {
  const scopeKey = categoryScopeKey(row);
  return categoryOptions.value.filter((item) => categoryScopeKey(item) === scopeKey);
}

function getCategorySiblingIndex(row: ProductCategoryRecord) {
  return getCategorySiblings(row).findIndex((item) => String(item.id) === String(row.id));
}

function canMoveCategory(row: ProductCategoryRecord, direction: 'up' | 'down') {
  if (categoryKeyword.value.trim() || categorySortLoadingId.value) return false;
  const siblings = getCategorySiblings(row);
  const currentIndex = getCategorySiblingIndex(row);
  if (currentIndex < 0) return false;
  return direction === 'up' ? currentIndex > 0 : currentIndex < siblings.length - 1;
}

function categoryMenuOptions(row: ProductCategoryRecord): DropdownOption[] {
  const options: DropdownOption[] = [
    { content: '上移', value: 'up', disabled: !canMoveCategory(row, 'up') },
    { content: '下移', value: 'down', disabled: !canMoveCategory(row, 'down') },
  ];
  if (productGroupLevel(row) === 2) {
    options.push({ content: '新增三级分类', value: 'create-child', divider: true });
  }
  options.push({ content: '折扣分组', value: 'batch-discount', divider: true });
  options.push({ content: '控制台类型', value: 'batch-console' });
  options.push({ content: 'CPU规格', value: 'batch-cpu', divider: true });
  options.push(
    { content: '编辑', value: 'edit', divider: true },
    { content: '删除', value: 'delete', theme: 'error' } as DropdownOption,
    { content: '强制删除', value: 'force-delete', theme: 'error' } as DropdownOption,
  );
  return options;
}

function handleCategoryMenuClick(row: ProductCategoryRecord, option: DropdownOption) {
  const action = String(option.value || '');
  if (action === 'up' || action === 'down') {
    void handleMoveCategory(row, action);
    return;
  }
  if (action === 'create-child') {
    openThirdCategoryDialog(row);
    return;
  }
  if (action === 'edit') {
    openCategoryDialog(row);
    return;
  }
  if (action === 'delete') {
    void handleDeleteCategory(row);
    return;
  }
  if (action === 'force-delete') {
    void handleForceDeleteCategory(row);
    return;
  }
  if (action === 'batch-discount' || action === 'batch-console' || action === 'batch-cpu') {
    const kind =
      action === 'batch-console' ? 'console-template' : action === 'batch-cpu' ? 'cpu-spec' : 'discount-group';
    openProductBatchDialog(kind, [], row);
  }
}

function handleCategoryProductTypeChange(value: unknown) {
  // 编辑三级分类：一级分类变更后，父级必须重新选择，并按新一级分类加载父级选项
  if (editingCategory.value && productGroupLevel(editingCategory.value) === 3) {
    categoryForm.parent_id = '';
    void loadCategoryParentOptions(String(value || ''));
    return;
  }
  const selectedParent = categoryParentOptions.value.find(
    (item) => productGroupLevel(item) === 2 && String(productGroupEffectiveId(item)) === String(categoryForm.parent_id),
  );
  if (selectedParent && String(selectedParent.first_product_group_code || '') !== String(value || '')) {
    categoryForm.parent_id = '';
  }
}

function resolveFirstProductGroupId(firstGroupCode: string) {
  const selectedType = categoryProductTypeOptions.value.find((item) => String(item.value) === String(firstGroupCode));
  return selectedType?.first_product_group_id || null;
}

function resolveSelectedParentCategory() {
  const parentId = String(categoryForm.parent_id || '');
  if (!parentId) return null;
  return (
    categoryParentOptions.value.find(
      (item) => productGroupLevel(item) === 2 && String(productGroupEffectiveId(item)) === parentId,
    ) || null
  );
}

function categoryParentOptionLabel(item: ProductCategoryRecord) {
  return String(
    item.second_product_group_name || item.name || item.label || `二级分类 #${productGroupEffectiveId(item)}`,
  ).trim();
}

async function loadCategoryParentOptions(firstGroupCode: string) {
  const code = String(firstGroupCode || '').trim();
  if (!code) {
    categoryParentOptions.value = [];
    return;
  }
  categoryParentLoading.value = true;
  try {
    const response = await productApi.categories({ first_product_group_code: code });
    const scopedTree = (Array.isArray(response.tree) ? response.tree : []).filter(
      (root) => String(root.first_product_group_code || '').trim() === code,
    );
    categoryParentOptions.value = flattenCategories(scopedTree).filter((item) => productGroupLevel(item) === 2);
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '加载二级分类父级失败'));
    categoryParentOptions.value = [];
  } finally {
    categoryParentLoading.value = false;
  }
}

// --- Data loading ---
async function loadProductTypes() {
  typeLoading.value = true;
  try {
    const response = await productApi.types();
    productTypes.value = Array.isArray(response) ? response : response.list || [];
    if (!catalogFilters.product_type && productTypes.value[0]?.value) {
      catalogFilters.product_type = productTypes.value[0].value;
    }
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '加载一级分类失败'));
  } finally {
    typeLoading.value = false;
  }
}

async function refreshTypeCatalog() {
  await loadProductTypes();
  await reloadCategoryScopedProducts();
}

function resolveProductTypeIconName(value: unknown) {
  const rawValue = String(value || '').trim();
  if (!rawValue) return '';

  const normalizedRawValue = rawValue.toLowerCase();
  const matched = productTypeIconOptions.find((item) => {
    if (item.value.toLowerCase() === normalizedRawValue) return true;
    return item.aliases?.some((alias) => alias.toLowerCase() === normalizedRawValue);
  });

  return matched?.value || rawValue;
}

function productTypeIconDisplayLabel(value: unknown) {
  const iconName = resolveProductTypeIconName(value);
  const matched = productTypeIconOptions.find((item) => item.value === iconName);
  return matched?.label || iconName;
}

function normalizeBusinessProductType(value?: string) {
  const normalized = String(value || '').trim();
  return businessProductTypeValueSet.has(normalized) ? normalized : 'other';
}

function selectTypeIcon(value: string) {
  typeForm.icon = value;
}

function resetTypeForm() {
  editingTypeValue.value = '';
  typeForm.label = '';
  typeForm.product_type = 'cloud_server';
  typeForm.icon = '';
}

function openTypeManagerDialog() {
  resetTypeForm();
  typeManagerDialogVisible.value = true;
}

function editType(type: ProductTypeRecord) {
  editingTypeValue.value = type.value;
  typeForm.label = type.label;
  typeForm.product_type = normalizeBusinessProductType(type.product_type);
  typeForm.icon = resolveProductTypeIconName(type.icon);
}

async function submitType() {
  const label = typeForm.label.trim();
  if (!label) {
    MessagePlugin.warning('请输入一级分类名称');
    return;
  }

  typeSubmitting.value = true;
  try {
    const icon = resolveProductTypeIconName(typeForm.icon);
    const productType = normalizeBusinessProductType(typeForm.product_type);
    if (editingTypeValue.value) {
      await productApi.updateType(editingTypeValue.value, { label, product_type: productType, icon });
      MessagePlugin.success('一级分类已更新');
    } else {
      await productApi.createType({ label, product_type: productType, icon });
      MessagePlugin.success('一级分类已创建');
    }
    resetTypeForm();
    await refreshTypeCatalog();
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '保存一级分类失败'));
  } finally {
    typeSubmitting.value = false;
  }
}

async function toggleTypeHidden(type: ProductTypeRecord) {
  typeSubmitting.value = true;
  try {
    await productApi.updateType(type.value, {
      label: type.label,
      product_type: normalizeBusinessProductType(type.product_type),
      icon: type.icon || '',
      is_hidden: !type.is_hidden,
    });
    MessagePlugin.success(type.is_hidden ? '一级分类已显示' : '一级分类已隐藏');
    await refreshTypeCatalog();
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '更新一级分类失败'));
  } finally {
    typeSubmitting.value = false;
  }
}

async function moveType(value: string, direction: -1 | 1) {
  const index = productTypes.value.findIndex((item) => item.value === value);
  const targetIndex = index + direction;
  if (index < 0 || targetIndex < 0 || targetIndex >= productTypes.value.length) return;
  const nextTypes = [...productTypes.value];
  const [current] = nextTypes.splice(index, 1);
  nextTypes.splice(targetIndex, 0, current);

  typeSubmitting.value = true;
  try {
    await productApi.reorderTypes({ values: nextTypes.map((item) => item.value) });
    productTypes.value = nextTypes;
    MessagePlugin.success('一级分类排序已更新');
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '更新一级分类排序失败'));
    await loadProductTypes();
  } finally {
    typeSubmitting.value = false;
  }
}

function deleteType(type: ProductTypeRecord) {
  const dialog = DialogPlugin.confirm({
    header: '删除一级分类',
    body: `确认删除「${type.label || type.value}」吗？`,
    theme: 'warning',
    confirmBtn: '确认删除',
    cancelBtn: '取消',
    async onConfirm() {
      typeSubmitting.value = true;
      try {
        await productApi.deleteType(type.value);
        MessagePlugin.success('一级分类已删除');
        if (false && catalogFilters.lifecycle_status === 'all') {
          MessagePlugin.info('当前筛选为“全部商品”，已删除商品仍会显示在列表中');
        }
        dialog.hide();
        if (catalogFilters.product_type === type.value) {
          catalogFilters.product_type = '';
          catalogFilters.product_group_key = '';
        }
        resetTypeForm();
        await refreshTypeCatalog();
      } catch (error) {
        MessagePlugin.error(errorMessage(error, '删除一级分类失败'));
      } finally {
        typeSubmitting.value = false;
      }
    },
  });
}

async function loadCategories() {
  const requestVersion = ++categoryRequestVersion;
  const productType = String(catalogFilters.product_type || '').trim();
  categoryLoading.value = true;
  try {
    const response = await productApi.categories({ first_product_group_code: productType });
    if (requestVersion !== categoryRequestVersion || productType !== catalogFilters.product_type) return;

    const scopedTree = response.tree.filter(
      (root) => String(root.first_product_group_code || '').trim() === productType,
    );
    categoryOptions.value = flattenCategories(scopedTree);
    const tree = buildCategoryTree(categoryOptions.value);
    const displayTree = toDisplayCategoryTree(tree);
    syncDefaultCatalogCategory(displayTree);
    syncCategoryExpandedKeys(displayTree);
  } catch (error) {
    if (requestVersion !== categoryRequestVersion || productType !== catalogFilters.product_type) return;
    categoryOptions.value = [];
    categoryExpandedKeys.value = new Set();
    MessagePlugin.error(errorMessage(error, '加载商品分类失败'));
  } finally {
    if (requestVersion === categoryRequestVersion) categoryLoading.value = false;
  }
}

async function handleMoveCategory(row: ProductCategoryRecord, direction: 'up' | 'down') {
  if (!canMoveCategory(row, direction)) return;

  const siblings = getCategorySiblings(row);
  const currentIndex = getCategorySiblingIndex(row);
  const reference = siblings[direction === 'up' ? currentIndex - 1 : currentIndex + 1];
  if (!reference) return;

  const nextSiblings = [...siblings];
  const targetIndex = direction === 'up' ? currentIndex - 1 : currentIndex + 1;
  [nextSiblings[currentIndex], nextSiblings[targetIndex]] = [nextSiblings[targetIndex], nextSiblings[currentIndex]];
  await commitCategoryReorder(row, nextSiblings);
}

async function commitCategoryReorder(row: ProductCategoryRecord, orderedSiblings: ProductCategoryRecord[]) {
  const level = productGroupLevel(row);
  const payload: Record<string, unknown> = {
    effective_product_group_level: level,
  };
  if (level === 1) {
    payload.first_product_group_ids = orderedSiblings.map(productGroupEffectiveId);
  } else if (level === 2) {
    payload.first_product_group_id = row.first_product_group_id;
    payload.second_product_group_ids = orderedSiblings.map(productGroupEffectiveId);
  } else {
    payload.second_product_group_id = row.second_product_group_id;
    payload.third_product_group_ids = orderedSiblings.map(productGroupEffectiveId);
  }

  categorySortLoadingId.value = `${row.id}:reorder`;
  try {
    await productApi.reorderCategory(payload);
    MessagePlugin.success('分类排序已更新');
    await reloadCategoryScopedProducts();
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '更新分类排序失败'));
  } finally {
    categorySortLoadingId.value = '';
  }
}

function handleCategoryDragStart(row: ProductCategoryRecord, event: DragEvent) {
  if (categoryKeyword.value.trim() || categorySortLoadingId.value) {
    event.preventDefault();
    return;
  }
  categoryDragState.value = {
    key: categoryIdKey(row),
    level: productGroupLevel(row),
    scopeKey: categoryScopeKey(row),
  };
  if (event.dataTransfer) {
    event.dataTransfer.effectAllowed = 'move';
    event.dataTransfer.setData('text/plain', String(row.id));
  }
}

function handleCategoryDragOver(targetRow: ProductCategoryRecord, event: DragEvent) {
  const state = categoryDragState.value;
  if (!state) return;
  if (productGroupLevel(targetRow) !== state.level || categoryScopeKey(targetRow) !== state.scopeKey) return;
  if (categoryIdKey(targetRow) === state.key) return;
  event.preventDefault();
  if (event.dataTransfer) event.dataTransfer.dropEffect = 'move';
}

function handleCategoryDrop(targetRow: ProductCategoryRecord, event: DragEvent) {
  event.preventDefault();
  const state = categoryDragState.value;
  categoryDragState.value = null;
  if (!state) return;
  if (productGroupLevel(targetRow) !== state.level || categoryScopeKey(targetRow) !== state.scopeKey) return;
  if (categoryIdKey(targetRow) === state.key) return;

  const siblings = getCategorySiblings(targetRow);
  const fromIndex = siblings.findIndex((item) => categoryIdKey(item) === state.key);
  const toIndex = siblings.findIndex((item) => categoryIdKey(item) === categoryIdKey(targetRow));
  if (fromIndex < 0 || toIndex < 0) return;

  const nextSiblings = [...siblings];
  const [moved] = nextSiblings.splice(fromIndex, 1);
  nextSiblings.splice(toIndex, 0, moved);
  if (nextSiblings.map(categoryIdKey).join(',') === siblings.map(categoryIdKey).join(',')) return;

  void commitCategoryReorder(targetRow, nextSiblings);
}

function handleCategoryDragEnd() {
  categoryDragState.value = null;
}

function shouldAutoRevealDeletedProducts(row: ProductCategoryRecord | null) {
  if (!row || catalogFilters.lifecycle_status !== 'active') return false;
  return categoryActiveProductCount(row) === 0 && categoryTotalProductCount(row) > 0;
}

async function loadProducts() {
  const requestVersion = ++productRequestVersion;
  const productType = String(catalogFilters.product_type || '').trim();
  productLoading.value = true;
  try {
    const selectedGroup = findProductGroupByKey(categoryOptions.value, catalogFilters.product_group_key);
    if (shouldAutoRevealDeletedProducts(selectedGroup)) {
      catalogFilters.lifecycle_status = 'all';
      MessagePlugin.info('当前分类仅包含已删除商品，已自动切换到“全部商品”');
    }
    const response = await productApi.list({
      keyword: catalogFilters.keyword,
      first_product_group_code: productType,
      status: catalogFilters.status,
      lifecycle_status: catalogFilters.lifecycle_status,
      ...productGroupPayload(selectedGroup),
      page: productPage.value,
      page_size: productPageSize.value,
    });
    if (requestVersion !== productRequestVersion || productType !== catalogFilters.product_type) return;
    products.value = Array.isArray(response.list) ? response.list : [];
    selectedProductKeys.value = [];
    productTotal.value = Number(response.total || 0);
    productPage.value = Number(response.page || productPage.value);
    productPageSize.value = Number(response.page_size || productPageSize.value);
  } catch (error) {
    if (requestVersion !== productRequestVersion || productType !== catalogFilters.product_type) return;
    products.value = [];
    selectedProductKeys.value = [];
    productTotal.value = 0;
    MessagePlugin.error(errorMessage(error, '加载商品列表失败'));
  } finally {
    if (requestVersion === productRequestVersion) productLoading.value = false;
  }
}

async function reloadCategoryScopedProducts() {
  await loadCategories();
  await loadProducts();
}

async function loadProviderTypes() {
  try {
    const response = await supplierApi.providerTypes();
    providerTypes.value = normalizeProviderTypeOptions(response);
  } catch {
    providerTypes.value = [];
  }
}

async function ensureProviderTypes() {
  if (providerTypes.value.length) return;
  await loadProviderTypes();
}

// --- Event handlers ---
function handleProductTypeChange(value: string) {
  catalogFilters.product_type = value;
  catalogFilters.product_group_key = '';
  categoryExpandedKeys.value = new Set();
  categoryOptions.value = [];
  products.value = [];
  selectedProductKeys.value = [];
  productTotal.value = 0;
  productPage.value = 1;
  void reloadCategoryScopedProducts();
}

function handleMobileProductTypeChange(value: string) {
  handleProductTypeChange(value);
}

function handleCategorySelect(row: ProductCategoryRecord) {
  const node = findCategoryTreeNode(categoryTree.value, categoryIdKey(row));
  const target = node ? firstDisplayCategoryNode(node) : row;
  catalogFilters.product_group_key = categoryIdKey(target);
  productPage.value = 1;
  void loadProducts();
}

function handleMobileCategorySelect(row: ProductCategoryRecord) {
  handleCategorySelect(row);
  mobileCatalogDrawerVisible.value = false;
}

function handleCatalogSearch() {
  productPage.value = 1;
  void loadProducts();
}

function handleProductSelectChange(value: Array<string | number>) {
  selectedProductKeys.value = value;
}

function clearProductSelection() {
  selectedProductKeys.value = [];
}

function selectedProductRows() {
  return products.value.filter((row) => selectedProductKeys.value.some((key) => String(key) === String(row.id)));
}

function selectedProductIdsFromKeys(keys: Array<string | number>) {
  return keys.map((id) => Number(id)).filter((id) => Number.isFinite(id) && id > 0);
}

function goProductEditPage(row: ProductRecord) {
  router.push({ name: 'AdminProductEdit', params: { id: row.id } });
}

function openProductRowMenu(id: number | string) {
  productRowMenuKey.value = id;
}

function handleProductRowMenuVisible(visible: boolean, id: number | string) {
  productRowMenuKey.value = visible ? id : null;
}

type CategoryMenuScope = 'desktop' | 'mobile';

function categoryMenuVisibleKey(scope: CategoryMenuScope, item: ProductCategoryRecord) {
  return `${scope}:${categoryIdKey(item)}`;
}

function openCategoryMenu(item: ProductCategoryRecord, scope: CategoryMenuScope = 'desktop') {
  categoryMenuKey.value = categoryMenuVisibleKey(scope, item);
}

function handleCategoryMenuVisible(
  visible: boolean,
  item: ProductCategoryRecord,
  scope: CategoryMenuScope = 'desktop',
) {
  categoryMenuKey.value = visible ? categoryMenuVisibleKey(scope, item) : null;
}

function openProductBatchDialog(
  kind: 'console-template' | 'discount-group' | 'cpu-spec',
  targetRows: ProductRecord[],
  group?: ProductCategoryRecord,
) {
  const targetRowsIds = targetRows.map((row) => Number(row.id)).filter((id) => Number.isFinite(id) && id > 0);
  if (!group && !targetRowsIds.length) {
    MessagePlugin.warning('请先选择商品');
    return;
  }

  productBatchDialogKind.value = kind;
  productBatchDialogTitle.value =
    kind === 'console-template' ? '设置控制台类型' : kind === 'discount-group' ? '设置代理折扣分组' : '设置 CPU 规格';
  productBatchTargetProductIds.value = targetRowsIds;
  productBatchTargetGroup.value = group
    ? { id: productGroupEffectiveId(group), level: productGroupLevel(group), name: categoryDisplayName(group) }
    : null;
  if (kind === 'console-template') {
    consoleTemplateValue.value = 'compute';
  } else if (kind === 'discount-group') {
    discountGroupValue.value = '';
    void loadDiscountGroups();
    void prefillDiscountGroup(targetRows, group);
  } else {
    cpuModelValue.value = '';
    cpuTurboValue.value = '';
    void prefillCpuSpec(targetRows, group);
  }
  productBatchDialogVisible.value = true;
}

async function resolveBatchTargetProductRows(targetRows: ProductRecord[], group?: ProductCategoryRecord) {
  if (targetRows.length) return targetRows;
  if (!group) return [];
  try {
    const response = await productApi.list({
      ...productGroupPayload(group),
      lifecycle_status: 'all',
      page: 1,
      page_size: 100,
    });
    return Array.isArray(response.list) ? response.list : [];
  } catch {
    return [];
  }
}

async function prefillDiscountGroup(targetRows: ProductRecord[], group?: ProductCategoryRecord) {
  const rows = await resolveBatchTargetProductRows(targetRows, group);
  if (!rows.length) return;
  const groupIds = rows.map((row) => Number(row.product_discount_group_id || 0)).filter((id) => id > 0);
  if (groupIds.length === rows.length && new Set(groupIds).size === 1) discountGroupValue.value = groupIds[0];
}

async function prefillCpuSpec(targetRows: ProductRecord[], group?: ProductCategoryRecord) {
  const rows = await resolveBatchTargetProductRows(targetRows, group);
  if (!rows.length) return;
  const cpuModels = rows.map((row) => String(row.cpu_model || '').trim()).filter(Boolean);
  const cpuTurbos = rows.map((row) => String(row.cpu_turbo || '').trim()).filter(Boolean);
  if (cpuModels.length === rows.length && new Set(cpuModels).size === 1) cpuModelValue.value = cpuModels[0];
  if (cpuTurbos.length === rows.length && new Set(cpuTurbos).size === 1) cpuTurboValue.value = cpuTurbos[0];
}

async function loadDiscountGroups() {
  if (discountGroupOptions.value.length || discountGroupLoading.value) return;
  discountGroupLoading.value = true;
  try {
    discountGroupOptions.value = await productDiscountGroupsApi.list();
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '加载折扣分组失败'));
  } finally {
    discountGroupLoading.value = false;
  }
}

async function submitProductBatch() {
  const payload: Record<string, unknown> = { product_ids: productBatchTargetProductIds.value };
  if (productBatchDialogKind.value === 'console-template') {
    payload.console_template = consoleTemplateValue.value;
  } else if (productBatchDialogKind.value === 'discount-group') {
    payload.product_discount_group_id =
      discountGroupValue.value === '' || discountGroupValue.value === null ? null : Number(discountGroupValue.value);
  } else {
    payload.cpu_model = cpuModelValue.value.trim() === '' ? null : cpuModelValue.value.trim();
    if (cpuTurboValue.value.trim() !== '') payload.cpu_turbo = cpuTurboValue.value.trim();
  }

  productBatchSubmitting.value = true;
  try {
    const response = productBatchTargetGroup.value
      ? await productApi.batchUpdateGroupProducts(productBatchTargetGroup.value.id, {
          effective_product_group_level: productBatchTargetGroup.value.level,
          ...payload,
        })
      : await productApi.batchUpdateProducts(payload);
    const record = toPlainRecord(response);
    const updatedCount = Number(record.updated_count ?? 0);
    const targetLabel = productBatchTargetGroup.value
      ? productBatchTargetGroup.value.name
      : `${productBatchTargetProductIds.value.length} 个商品`;
    MessagePlugin.success(
      updatedCount > 0 ? `已更新 ${updatedCount} 个商品（${targetLabel}）` : `所选商品无需变更（${targetLabel}）`,
    );
    productBatchDialogVisible.value = false;
    await loadProducts();
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '批量更新失败'));
  } finally {
    productBatchSubmitting.value = false;
  }
}

function handleBatchDeleteProducts() {
  const rows = selectedProductRows();
  if (!rows.length) {
    MessagePlugin.warning('请先选择商品');
    return;
  }
  const dialog = DialogPlugin.confirm({
    header: '批量删除商品',
    body: `确认删除选中的 ${rows.length} 个商品吗？存在服务实例的商品将自动跳过。`,
    theme: 'warning',
    confirmBtn: '确认删除',
    cancelBtn: '取消',
    async onConfirm() {
      try {
        const response = await productApi.batchDeleteProducts({
          product_ids: selectedProductIdsFromKeys(selectedProductKeys.value),
        });
        const record = toPlainRecord(response);
        const deletedCount = Number(record.deleted_count ?? 0);
        const failed = Array.isArray(record.failed) ? record.failed : [];
        MessagePlugin.success(
          deletedCount > 0
            ? `已删除 ${deletedCount} 个商品${failed.length ? `，跳过 ${failed.length} 个（存在实例）` : ''}`
            : `无商品可删除${failed.length ? `，${failed.length} 个存在实例` : ''}`,
        );
        dialog.hide();
        selectedProductKeys.value = [];
        await loadProducts();
      } catch (error) {
        MessagePlugin.error(errorMessage(error, '批量删除失败'));
      }
    },
  });
}

function handleBatchForceDeleteProducts() {
  const rows = selectedProductRows();
  if (!rows.length) {
    MessagePlugin.warning('请先选择商品');
    return;
  }
  const dialog = DialogPlugin.confirm({
    header: '批量强制删除商品',
    body: `确认彻底删除选中的 ${rows.length} 个商品吗？其下服务实例将同步删除，该操作不可恢复。`,
    theme: 'danger',
    confirmBtn: '确认强制删除',
    cancelBtn: '取消',
    async onConfirm() {
      try {
        const response = await productApi.batchForceDeleteProducts({
          product_ids: selectedProductIdsFromKeys(selectedProductKeys.value),
        });
        const record = toPlainRecord(response);
        const deletedCount = Number(record.deleted_count ?? 0);
        const deletedServices = Number(record.deleted_services ?? 0);
        MessagePlugin.success(
          deletedCount > 0
            ? `已强制删除 ${deletedCount} 个商品${deletedServices > 0 ? `，同步删除 ${deletedServices} 个实例` : ''}`
            : '未删除任何商品',
        );
        dialog.hide();
        selectedProductKeys.value = [];
        await loadProducts();
      } catch (error) {
        MessagePlugin.error(errorMessage(error, '批量强制删除失败'));
      }
    },
  });
}

function handleForceDeleteCategory(row: ProductCategoryRecord) {
  const level = productGroupLevel(row);
  const dialog = DialogPlugin.confirm({
    header: '强制删除分类',
    body: `确认强制删除「${categoryDisplayName(row)}」吗？其下所有商品与相关服务实例将同步删除，该操作不可恢复。`,
    theme: 'danger',
    confirmBtn: '确认强制删除',
    cancelBtn: '取消',
    async onConfirm() {
      categorySubmitting.value = true;
      try {
        const response = await productApi.forceDeleteCategory(productGroupEffectiveId(row), {
          effective_product_group_level: level,
        });
        const record = toPlainRecord(response);
        MessagePlugin.success(
          `已强制删除，同步删除 ${record.deleted_products || 0} 个商品、${record.deleted_services || 0} 个实例`,
        );
        dialog.hide();
        if (catalogFilters.product_group_key === categoryIdKey(row)) {
          catalogFilters.product_group_key = '';
        }
        await reloadCategoryScopedProducts();
      } catch (error) {
        MessagePlugin.error(errorMessage(error, '强制删除分类失败'));
      } finally {
        categorySubmitting.value = false;
      }
    },
  });
}

function openBatchCategoryDialog() {
  const targetRows = selectedProductRows();
  if (!targetRows.length) {
    MessagePlugin.warning('请先选择商品');
    return;
  }

  batchCategoryTargetKeys.value = targetRows.map((row) => row.id);
  batchCategoryForm.target_product_group_key = '';
  batchCategoryDialogVisible.value = true;
}

async function openSplitProductDialog() {
  const targetRows = selectedProductRows();
  if (!targetRows.length) {
    MessagePlugin.warning('请先选择需要拆分的商品');
    return;
  }

  splitProductTargetKeys.value = targetRows.map((row) => row.id);
  splitProductPreview.value = null;
  splitProductDialogVisible.value = true;
  splitProductPreviewLoading.value = true;
  try {
    const response = await productApi.splitPreview({
      product_ids: selectedProductIdsFromKeys(splitProductTargetKeys.value),
    });
    splitProductPreview.value = toPlainRecord(response);
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '加载拆分预览失败'));
  } finally {
    splitProductPreviewLoading.value = false;
  }
}

function normalizeProvisionHostnameRule(ruleValue: unknown) {
  const rule = toPlainRecord(ruleValue);
  const modeValue = String(rule.mode || 'system');
  const mode: ProvisionHostnameMode = ['system', 'fixed', 'prefix'].includes(modeValue)
    ? (modeValue as ProvisionHostnameMode)
    : 'system';
  const rawLength = Number(rule.length || 12);

  return {
    mode,
    value: String(rule.value || '').trim(),
    length: Number.isFinite(rawLength) ? Math.min(63, Math.max(4, rawLength)) : 12,
  };
}

function isSameProvisionHostnameRule(left: unknown, right: unknown) {
  const current = normalizeProvisionHostnameRule(left);
  const target = normalizeProvisionHostnameRule(right);
  return (
    current.mode === target.mode && current.value === target.value && Number(current.length) === Number(target.length)
  );
}

function openProvisionHostnameDialog() {
  const targetRows = selectedProductRows();
  if (!targetRows.length) {
    MessagePlugin.warning('请先选择商品');
    return;
  }

  const currentRule = normalizeProvisionHostnameRule(targetRows[0]?.provision_hostname);
  provisionHostnameTargetKeys.value = targetRows.map((row) => row.id);
  provisionHostnameHasMixedRules.value = targetRows.some(
    (row) => !isSameProvisionHostnameRule(row.provision_hostname, currentRule),
  );
  provisionHostnameForm.mode = currentRule.mode;
  provisionHostnameForm.value = currentRule.value;
  provisionHostnameForm.length = currentRule.length;
  provisionHostnameDialogVisible.value = true;
}

function resolveProductGroupLabel(key: string) {
  const group = findProductGroupByKey(categoryOptions.value, key);
  return group ? productGroupOptionLabel(group) : '目标分类';
}

async function submitBatchCategory() {
  const productIds = selectedProductIdsFromKeys(batchCategoryTargetKeys.value);
  const targetGroup = findProductGroupByKey(categoryOptions.value, batchCategoryForm.target_product_group_key);

  if (!productIds.length) {
    MessagePlugin.warning('请先选择商品');
    return;
  }
  if (!targetGroup) {
    MessagePlugin.warning('请选择目标分类');
    return;
  }

  batchCategorySubmitting.value = true;
  try {
    const response = await productApi.batchUpdateCategory({
      product_ids: productIds,
      ...productGroupPayload(targetGroup, 'target_'),
    });
    const responseRecord = response && typeof response === 'object' ? (response as Record<string, unknown>) : {};
    const updatedCount = Number(responseRecord.updated_count ?? productIds.length);
    const targetLabel = String(
      responseRecord.target_effective_product_group_full_name ||
        responseRecord.target_effective_product_group_name ||
        resolveProductGroupLabel(batchCategoryForm.target_product_group_key),
    );

    if (updatedCount > 0) {
      MessagePlugin.success(`已将 ${updatedCount} 个商品移入「${targetLabel}」`);
    } else {
      MessagePlugin.success(`所选商品已在「${targetLabel}」下`);
    }

    batchCategoryDialogVisible.value = false;
    batchCategoryTargetKeys.value = [];
    await reloadCategoryScopedProducts();
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '批量归类失败'));
  } finally {
    batchCategorySubmitting.value = false;
  }
}

async function submitSplitProducts() {
  const productIds = selectedProductIdsFromKeys(splitProductTargetKeys.value);
  if (!productIds.length) {
    MessagePlugin.warning('请先选择需要拆分的商品');
    return;
  }

  splitProductSubmitting.value = true;
  try {
    const response = await productApi.splitProducts({ product_ids: productIds });
    const responseRecord = toPlainRecord(response);
    const createdCount = Number(responseRecord.created_count || 0);
    const updatedCount = Number(responseRecord.updated_count || 0);
    const skippedCount = Number(responseRecord.skipped_count || 0);
    const changedCount = createdCount + updatedCount;

    if (changedCount > 0) {
      MessagePlugin.success(`已生成 ${createdCount} 个商品，更新 ${updatedCount} 个，跳过 ${skippedCount} 个`);
    } else {
      MessagePlugin.warning(skippedCount > 0 ? `未生成新商品，跳过 ${skippedCount} 个` : '未找到可拆分商品');
    }

    splitProductDialogVisible.value = false;
    splitProductTargetKeys.value = [];
    selectedProductKeys.value = [];
    await reloadCategoryScopedProducts();
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '拆分商品失败'));
  } finally {
    splitProductSubmitting.value = false;
  }
}

async function submitProvisionHostname() {
  const productIds = selectedProductIdsFromKeys(provisionHostnameTargetKeys.value);
  if (!productIds.length) {
    MessagePlugin.warning('请先选择商品');
    return;
  }
  if (provisionHostnameForm.mode !== 'system' && !provisionHostnameForm.value.trim()) {
    MessagePlugin.warning(provisionHostnameForm.mode === 'fixed' ? '请输入固定主机名' : '请输入主机名前缀');
    return;
  }

  provisionHostnameSubmitting.value = true;
  try {
    await productApi.batchUpdateProvisionHostname({
      product_ids: productIds,
      provision_hostname: {
        mode: provisionHostnameForm.mode,
        value: provisionHostnameForm.value.trim(),
        length: Number(provisionHostnameForm.length || 12),
      },
    });
    MessagePlugin.success('商品开通主机名规则已更新');
    provisionHostnameDialogVisible.value = false;
    provisionHostnameTargetKeys.value = [];
    selectedProductKeys.value = [];
    await loadProducts();
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '更新主机名规则失败'));
  } finally {
    provisionHostnameSubmitting.value = false;
  }
}

function handleProductPageChange(pageInfo: PageInfo) {
  productPage.value = pageInfo.current;
  productPageSize.value = pageInfo.pageSize;
  void loadProducts();
}

function syncProductPricingCycles(planValue?: unknown) {
  const planKey = String(planValue || productPricingPlan.value || 'standard');
  const plan = productPricingPlanOptions.find((item) => item.value === planKey) || productPricingPlanOptions[0];
  const monthly = Number(productForm.monthly_price || 0);

  if (monthly <= 0) {
    MessagePlugin.warning('请先填写月付价格');
    return;
  }

  productPricingPlan.value = plan.value;
  productForm.quarterly_price = roundPricingAmount(monthly * plan.ratios.quarterly);
  productForm.semiannually_price = roundPricingAmount(monthly * plan.ratios.semiannually);
  productForm.annually_price = roundPricingAmount(monthly * plan.ratios.annually);
  MessagePlugin.success('已同步其他计费周期价格');
}

function roundPricingAmount(value: number) {
  return Number(value.toFixed(2));
}

function hasPositiveProductPrice() {
  return [
    productForm.monthly_price,
    productForm.quarterly_price,
    productForm.semiannually_price,
    productForm.annually_price,
  ].some((value) => Number(value || 0) > 0);
}

function closeProductDrawer() {
  productDialogVisible.value = false;
}

function setProductDrawerSection(key: string) {
  activeProductDrawerSection.value = key;
}

function resolveProductCustomDisplayNamePayload() {
  const value = String(productForm.display_name || '').trim();
  if (!value) return null;

  const defaultDisplayName = String(productForm.product_spec_display || '').trim();
  return defaultDisplayName && value === defaultDisplayName ? null : value;
}

async function submitProduct() {
  const validateResult = await productFormRef.value?.validate?.();
  if (validateResult !== true) return;
  if (!hasPositiveProductPrice()) {
    activeProductDrawerSection.value = 'pricing';
    MessagePlugin.warning('请至少填写一个大于 0 的计费周期价格');
    return;
  }
  productSubmitting.value = true;
  try {
    const payload = {
      custom_display_name: resolveProductCustomDisplayNamePayload(),
      ...productGroupPayload(findProductGroupByKey(categoryOptions.value, productForm.selected_product_group_key)),
      pricing: {
        monthly: productForm.monthly_price,
        quarterly: productForm.quarterly_price,
        semiannually: productForm.semiannually_price,
        annually: productForm.annually_price,
      },
      auto_setup: productForm.auto_setup,
      status: productForm.status,
      upstream_binding: {
        supplier_id: productForm.supplier_id || undefined,
        upstream_product_id: productForm.upstream_product_id || undefined,
      },
      config_options: serializeConfigOptions(productForm.config_options),
    };
    if (editingProduct.value?.id) {
      await productApi.update(editingProduct.value.id, payload);
      MessagePlugin.success('商品已更新');
    } else {
      await productApi.create(payload);
      MessagePlugin.success('商品已创建');
    }
    productDialogVisible.value = false;
    await loadProducts();
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '保存商品失败'));
  } finally {
    productSubmitting.value = false;
  }
}

// --- Config option helpers ---
function normalizeConfigOptions(value: unknown): ProductConfigOptionRecord[] {
  const items = Array.isArray(value) ? value : [];
  return items.map((itemValue, index) => {
    const item = toPlainRecord(itemValue);
    const field = String(item.field || item.spec_key || `option_${index + 1}`).trim();
    const name = String(item.name || item.option_name || field).trim();
    return {
      ...item,
      uid: String(item.uid || `${field}-${index}`),
      field,
      name,
      option_name: name,
      option_mode: String(item.option_mode || (item.option_type === 'quantity' ? 'range' : 'select')),
      parameter: String(item.parameter || ''),
      sub: Array.isArray(item.sub) ? (item.sub as Array<Record<string, unknown>>) : [],
      sub_items: Array.isArray(item.sub_items)
        ? (item.sub_items as Array<Record<string, unknown>>)
        : Array.isArray(item.sub)
          ? (item.sub as Array<Record<string, unknown>>)
          : [],
      required: Boolean(item.required ?? true),
      hidden: Boolean(item.hidden ?? false),
      sort_order: Number(item.sort_order || index + 1),
    };
  });
}

function serializeConfigOptions(options: ProductConfigOptionRecord[]) {
  return options.map((item, index) => ({
    ...item,
    name: String(item.name || item.option_name || item.field || '').trim(),
    option_name: String(item.option_name || item.name || item.field || '').trim(),
    field: String(item.field || '').trim(),
    option_mode: String(item.option_mode || 'select'),
    parameter: String(item.parameter || '').trim(),
    required: Boolean(item.required),
    hidden: Boolean(item.hidden),
    sort_order: Number(item.sort_order || index + 1),
  }));
}

function resetConfigOptionForm() {
  Object.assign(configOptionForm, {
    name: '',
    field: '',
    option_mode: 'select',
    parameter: '',
    sub_items_text: '',
    description: '',
    suffix_text: '',
    advanced: true,
    required: true,
    hidden: false,
    sort_order: productForm.config_options.length + 1,
  });
  configOptionSubItemRows.value = [createConfigSubItemRow({}, 0)];
}

function formatConfigSubItems(items: Array<Record<string, unknown>> = []) {
  return items
    .map(
      (item) =>
        `${item.value || item.option_name_first || item.label || item.option_name || ''}|${item.label || item.option_name || item.value || ''}`,
    )
    .join(',');
}

function createConfigSubItemRow(item: Record<string, unknown> = {}, index = 0): ConfigOptionSubItemFormRow {
  const pricing = toPlainRecord(item.pricing);
  const monthlyPrice = item.monthly_price ?? item.monthly ?? item.month ?? pricing.monthly ?? pricing.month ?? '';
  return {
    uid: String(item.uid || `config-subitem-${Date.now()}-${index}-${Math.random().toString(36).slice(2)}`),
    name: String(item.label || item.option_name || item.name || '').trim(),
    value: String(item.value || item.option_name_first || '').trim(),
    monthly_price:
      monthlyPrice === '' || monthlyPrice === undefined || monthlyPrice === null ? '0.00' : String(monthlyPrice),
    sort_order: Number(item.sort_order || index + 1),
  };
}

function configSubItemRecordsFromParameter(rawValue: string): Array<Record<string, unknown>> {
  return parseConfigSubItems(rawValue);
}

function addConfigSubItemRow() {
  configOptionSubItemRows.value.push(createConfigSubItemRow({}, configOptionSubItemRows.value.length));
}

function removeConfigSubItemRow(index: number) {
  if (configOptionSubItemRows.value.length <= 1) return;
  configOptionSubItemRows.value.splice(index, 1);
}

function handleConfigOptionModeChange(value: SelectValue) {
  configOptionForm.option_mode = String(value || 'select');
  if (configOptionForm.option_mode === 'select' && configOptionSubItemRows.value.length === 0) {
    configOptionSubItemRows.value = [createConfigSubItemRow({}, 0)];
  }
}

function openConfigOptionDialog(row?: ProductConfigOptionRecord, index = -1) {
  configOptionEditingIndex.value = index;
  if (row) {
    Object.assign(configOptionForm, {
      name: row.name || row.option_name || '',
      field: row.field || '',
      option_mode: row.option_mode || 'select',
      parameter: row.parameter || '',
      sub_items_text: formatConfigSubItems(row.sub_items || row.sub || []),
      description: String(row.description || row.option_description || '').trim(),
      suffix_text: String(row.suffix_text || row.suffix || '').trim(),
      advanced: Boolean(row.advanced ?? true),
      required: Boolean(row.required ?? true),
      hidden: Boolean(row.hidden ?? false),
      sort_order: Number(row.sort_order || index + 1),
    });
    const sourceSubItems =
      Array.isArray(row.sub_items) && row.sub_items.length ? row.sub_items : Array.isArray(row.sub) ? row.sub : [];
    const rowSubItems = sourceSubItems.length
      ? sourceSubItems.map((item) => toPlainRecord(item))
      : configSubItemRecordsFromParameter(String(row.parameter || ''));
    configOptionSubItemRows.value = rowSubItems.length
      ? rowSubItems.map((item, itemIndex) => createConfigSubItemRow(item, itemIndex))
      : [createConfigSubItemRow({}, 0)];
  } else {
    resetConfigOptionForm();
  }
  configOptionDialogVisible.value = true;
}

function splitConfigSubItemText(rawValue: string) {
  const tokens = rawValue
    .split(',')
    .map((item) => item.trim())
    .filter(Boolean);
  const items: string[] = [];
  let buffer: string[] = [];

  tokens.forEach((token) => {
    buffer.push(token);
    if (token.includes('|')) {
      items.push(buffer.join(','));
      buffer = [];
    }
  });

  if (buffer.length) {
    if (items.length === 0) return tokens;
    items.push(buffer.join(','));
  }

  return items;
}

function parseConfigSubItems(rawValue: string) {
  return splitConfigSubItemText(rawValue).map((item, index) => {
    const separatorIndex = item.indexOf('|');
    const rawValue = separatorIndex >= 0 ? item.slice(0, separatorIndex).trim() : item.trim();
    const rawLabel = separatorIndex >= 0 ? item.slice(separatorIndex + 1).trim() : '';
    const value = rawValue || rawLabel || '';
    const label = rawLabel || rawValue || '';
    return {
      id: value || index,
      value: value || label,
      label: label || value,
      option_name: label || value,
      option_name_first: value || label,
      pricing: {},
      sort_order: index + 1,
      hidden: 0,
    };
  });
}

function buildConfigSubItemsFromRows() {
  const rows = configOptionSubItemRows.value
    .map((row, index) => ({
      ...row,
      name: row.name.trim(),
      value: row.value.trim(),
      sort_order: Number(row.sort_order || index + 1),
      monthly_price: String(row.monthly_price ?? '').trim(),
    }))
    .filter((row) => row.name || row.value);

  const invalidRow = rows.find((row) => !row.name || !row.value);
  if (invalidRow) {
    throw new Error('请完整填写子项名称和传参值');
  }

  return rows.map((row, index) => {
    const monthlyPrice = Number(row.monthly_price || 0);
    const monthlyAmount = Number.isFinite(monthlyPrice) ? monthlyPrice.toFixed(2) : '0.00';
    return {
      id: row.value || index,
      value: row.value,
      label: row.name,
      option_name: row.name,
      option_name_first: row.value,
      monthly: monthlyAmount,
      monthly_price: monthlyAmount,
      pricing: {
        monthly: monthlyAmount,
      },
      sort_order: row.sort_order || index + 1,
      hidden: 0,
    };
  });
}

function submitConfigOption() {
  const name = configOptionForm.name.trim();
  const field = configOptionForm.field.trim();
  if (!name || !field) {
    MessagePlugin.warning('请填写配置名称和字段名');
    return;
  }
  let subItems: ReturnType<typeof parseConfigSubItems> = [];
  try {
    subItems = configOptionForm.option_mode === 'select' ? buildConfigSubItemsFromRows() : [];
  } catch (error) {
    MessagePlugin.warning(errorMessage(error, '请检查子项配置'));
    return;
  }
  const parameter = subItems.length
    ? subItems.map((item) => `${item.value}|${item.label}`).join(',')
    : configOptionForm.parameter.trim();
  if (configOptionForm.option_mode === 'select' && !parameter) {
    MessagePlugin.warning('请填写参数值或子项');
    return;
  }

  configOptionSubmitting.value = true;
  const payload: ProductConfigOptionRecord = {
    uid: `${field}-${Date.now()}`,
    source: 'manual',
    field,
    name,
    option_name: name,
    option_mode: configOptionForm.option_mode,
    option_type: configOptionForm.option_mode === 'range' ? 'quantity' : 'select',
    parameter,
    sub: subItems,
    sub_items: subItems,
    range_pricing: [],
    description: configOptionForm.description.trim(),
    suffix_text: configOptionForm.suffix_text.trim(),
    advanced: Boolean(configOptionForm.advanced),
    required: Boolean(configOptionForm.required),
    hidden: Boolean(configOptionForm.hidden),
    sort_order: Number(configOptionForm.sort_order || productForm.config_options.length + 1),
  };
  if (configOptionEditingIndex.value >= 0) {
    productForm.config_options.splice(configOptionEditingIndex.value, 1, payload);
  } else {
    productForm.config_options.push(payload);
  }
  configOptionDialogVisible.value = false;
  configOptionSubmitting.value = false;
}

function removeConfigOption(index: number) {
  productForm.config_options.splice(index, 1);
}

async function pullProductConfigTemplate() {
  const supplierId = productForm.supplier_id;
  const upstreamProductId = productForm.upstream_product_id;
  if (!supplierId || !upstreamProductId) {
    MessagePlugin.warning('请先绑定提供商和提供商商品 ID');
    return;
  }

  configTemplateLoading.value = true;
  try {
    const response = await supplierApi.productConfigTemplate(supplierId, upstreamProductId);
    const options = Array.isArray(response.config_options) ? response.config_options : [];
    productForm.config_options = normalizeConfigOptions(options);
    MessagePlugin.success('配置项模板已拉取');
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '拉取配置项模板失败'));
  } finally {
    configTemplateLoading.value = false;
  }
}

async function handleToggleProduct(row: ProductRecord) {
  const newStatus = Number(row.status) !== 1;
  const actionLabel = newStatus ? '显示' : '隐藏';
  const dialog = DialogPlugin.confirm({
    header: `${actionLabel}商品`,
    body: `确定${actionLabel}「${row.display_name || row.name || row.id}」吗？${newStatus ? '' : '隐藏后前台不显示，可在筛选中找回。'}`,
    confirmBtn: `确认${actionLabel}`,
    cancelBtn: '取消',
    async onConfirm() {
      productActionLoading.value = row.id;
      try {
        await productApi.toggleStatus(row.id, newStatus);
        MessagePlugin.success({ content: `商品已${actionLabel}`, duration: 3000 });
        dialog.hide();
        await loadProducts();
      } catch (error) {
        MessagePlugin.error(errorMessage(error, '更新状态失败'));
      } finally {
        productActionLoading.value = null;
      }
    },
  });
}

function handleDeleteProduct(row: ProductRecord) {
  const dialog = DialogPlugin.confirm({
    header: '删除商品',
    body: `确认删除「${row.display_name || row.name || row.id}」吗？${Number(row.active_services_count ?? row.services_count ?? 0) > 0 ? ` 该商品下有 ${row.active_services_count ?? row.services_count} 个现存服务，删除后可能影响已开通实例。` : ''}`,
    theme: 'warning',
    confirmBtn: '确认删除',
    cancelBtn: '取消',
    async onConfirm() {
      productActionLoading.value = row.id;
      try {
        await productApi.delete(row.id);
        MessagePlugin.success({ content: '商品已删除', duration: 3000 });
        dialog.hide();
        if (catalogFilters.lifecycle_status === 'all') {
          MessagePlugin.info('当前筛选为“全部商品”，已删除商品仍会显示在列表中');
        }
        await loadProducts();
      } catch (error) {
        MessagePlugin.error(errorMessage(error, '删除商品失败'));
      } finally {
        productActionLoading.value = null;
      }
    },
  });
}

function handleRestoreProduct(row: ProductRecord) {
  const dialog = DialogPlugin.confirm({
    header: '恢复商品',
    body: `确认恢复《${row.display_name || row.name || row.id}》吗？`,
    confirmBtn: '确认恢复',
    cancelBtn: '取消',
    async onConfirm() {
      productActionLoading.value = `restore:${row.id}`;
      try {
        await productApi.restore(row.id);
        MessagePlugin.success('商品已恢复');
        dialog.hide();
        await loadProducts();
      } catch (error) {
        MessagePlugin.error(errorMessage(error, '恢复商品失败'));
      } finally {
        productActionLoading.value = null;
      }
    },
  });
}

function handleForceDeleteProduct(row: ProductRecord) {
  const dialog = DialogPlugin.confirm({
    header: '彻底删除商品',
    body: `确认彻底删除《${row.display_name || row.name || row.id}》吗？该操作不可恢复。`,
    theme: 'warning',
    confirmBtn: '确认彻底删除',
    cancelBtn: '取消',
    async onConfirm() {
      productActionLoading.value = `force:${row.id}`;
      try {
        await productApi.forceDelete(row.id);
        MessagePlugin.success('商品已彻底删除');
        dialog.hide();
        await loadProducts();
      } catch (error) {
        MessagePlugin.error(errorMessage(error, '彻底删除商品失败'));
      } finally {
        productActionLoading.value = null;
      }
    },
  });
}

function openSecondCategoryDialog() {
  openCategoryDialog();
}

function openThirdCategoryDialog(parent: ProductCategoryRecord) {
  openCategoryDialog(undefined, parent);
}

function openCategoryDialog(row?: ProductCategoryRecord, thirdCategoryParent?: ProductCategoryRecord) {
  editingCategory.value = row || null;
  creatingThirdCategoryParent.value = !row && thirdCategoryParent ? thirdCategoryParent : null;
  const level = row ? productGroupLevel(row) : thirdCategoryParent ? 3 : 2;
  const parentFirstGroupCode = thirdCategoryParent?.first_product_group_code || '';
  Object.assign(categoryForm, {
    name: row?.name || row?.label || '',
    product_type: String(
      row?.first_product_group_code ||
        parentFirstGroupCode ||
        catalogFilters.product_type ||
        categoryProductTypeOptions.value[0]?.value ||
        '',
    ),
    parent_id: level === 3 ? row?.second_product_group_id || productGroupEffectiveId(thirdCategoryParent) || '' : '',
    slogan: String(row?.slogan || ''),
    sort_order: Number(row?.sort_order || 0),
    is_visible: Number(row?.is_visible ?? 1),
  });
  categoryDialogVisible.value = true;
  if (level === 3) {
    // 编辑三级分类：确保父级下拉按当前一级分类加载（分类树只含当前筛选的一级分类）
    void loadCategoryParentOptions(categoryForm.product_type);
  }
}

async function submitCategory() {
  const validateResult = await categoryFormRef.value?.validate?.();
  if (validateResult !== true) return;
  categorySubmitting.value = true;
  try {
    const firstGroupCode = categoryForm.product_type || catalogFilters.product_type || '';
    let firstProductGroupId = resolveFirstProductGroupId(firstGroupCode);
    const selectedParent = resolveSelectedParentCategory();
    const targetLevel = editingCategory.value
      ? productGroupLevel(editingCategory.value)
      : creatingThirdCategoryParent.value
        ? 3
        : 2;
    if (targetLevel === 2 && !firstProductGroupId) {
      await loadProductTypes();
      firstProductGroupId = resolveFirstProductGroupId(firstGroupCode);
    }
    if (targetLevel === 2 && !firstProductGroupId) {
      MessagePlugin.warning('一级分类数据未同步，请刷新一级分类后重试');
      return;
    }
    if (targetLevel === 3 && !selectedParent) {
      MessagePlugin.warning('请选择二级分类父级');
      return;
    }
    const payload: Record<string, unknown> = {
      name: categoryForm.name,
      effective_product_group_level: targetLevel,
      first_product_group_id: targetLevel === 2 ? firstProductGroupId || undefined : undefined,
      first_product_group_code: targetLevel === 1 ? firstGroupCode || undefined : undefined,
      product_type:
        targetLevel === 1
          ? normalizeBusinessProductType(
              categoryProductTypeOptions.value.find((item) => String(item.value) === String(firstGroupCode))
                ?.product_type,
            )
          : undefined,
      second_product_group_id: targetLevel === 3 ? productGroupEffectiveId(selectedParent) || undefined : undefined,
      slogan: categoryForm.slogan,
      sort_order: categoryForm.sort_order,
      is_visible: categoryForm.is_visible,
    };
    if (editingCategory.value?.id) {
      await productApi.updateCategory(productGroupEffectiveId(editingCategory.value), payload);
      MessagePlugin.success('分类已更新');
    } else {
      await productApi.createCategory(payload);
      MessagePlugin.success('分类已创建');
    }
    categoryDialogVisible.value = false;
    await reloadCategoryScopedProducts();
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '保存分类失败'));
  } finally {
    categorySubmitting.value = false;
  }
}

function handleDeleteCategory(row: ProductCategoryRecord) {
  const dialog = DialogPlugin.confirm({
    header: '删除分类',
    body: `确认删除「${row.name || row.label || row.id}」吗？`,
    theme: 'warning',
    confirmBtn: '确认删除',
    cancelBtn: '取消',
    async onConfirm() {
      categorySubmitting.value = true;
      try {
        await productApi.deleteCategory(productGroupEffectiveId(row), {
          effective_product_group_level: productGroupLevel(row),
        });
        MessagePlugin.success('分类已删除');
        dialog.hide();
        if (catalogFilters.product_group_key === categoryIdKey(row)) {
          catalogFilters.product_group_key = '';
        }
        await reloadCategoryScopedProducts();
      } catch (error) {
        MessagePlugin.error(errorMessage(error, '删除分类失败'));
      } finally {
        categorySubmitting.value = false;
      }
    },
  });
}

// --- Supplier helpers (used within catalog for product editing) ---
function supplierOptionLabel(row: SupplierRecord) {
  const typeLabel = supplierInterfaceTypeLabel(row);
  return typeLabel ? `${row.name || row.id} / ${typeLabel}` : String(row.name || row.id || '-');
}

function supplierInterfaceTypeLabel(row: SupplierRecord) {
  const upstreamBinding = toPlainRecord(row.upstream_binding);
  const rawType = String(upstreamBinding.provider_key || row.provider_key || '').trim();
  if (rawType && providerTypeFallbackLabels[rawType]) return providerTypeFallbackLabels[rawType];
  return row.provider_label || providerTypeLabel(rawType, providerTypeOptions.value);
}

function supplierProductOptionLabel(row: SupplierBatchProduct) {
  const typeLabel = row.type_label || row.remote_group_name || '';
  return typeLabel ? `${row.name || row.id} · ${typeLabel}` : String(row.name || row.id || '-');
}

function canSupplierBatchConnect(row: SupplierRecord) {
  const upstreamBinding = toPlainRecord(row.upstream_binding);
  const providerKey = String(upstreamBinding.provider_key || row.provider_key || '').trim();
  const schema = providerTypeOptions.value.find((item) => item.value === providerKey)?.supplier_form;
  const fields = normalizeSupplierCredentialFields(toPlainRecord(schema).fields);
  if (!fields.length) {
    return Boolean(
      (upstreamBinding.has_base_url || row.has_api_url || row.api_url) &&
      (upstreamBinding.account_name || row.api_username) &&
      (row.has_api_key || row.api_key),
    );
  }

  return fields.every((field) => {
    if (!field.required) return true;
    if (field.key === 'api_url') return Boolean(upstreamBinding.has_base_url || row.has_api_url || row.api_url);
    if (field.key === 'api_username') return Boolean(upstreamBinding.account_name || row.api_username);
    if (field.key === 'api_key')
      return Boolean(toPlainRecord(upstreamBinding.has_secret_values).api_key || row.has_api_key || row.api_key);
    if (field.secret) {
      return Boolean(
        toPlainRecord(upstreamBinding.has_secret_values)[field.key] || row.has_provider_secret_values?.[field.key],
      );
    }

    return hasSupplierCredentialValue(toPlainRecord(row.provider_config)[field.key]);
  });
}

function normalizeSupplierCredentialFields(value: unknown): SupplierFormField[] {
  if (!Array.isArray(value)) return [];

  return value
    .map((item) => {
      const record = toPlainRecord(item);
      const key = String(record.key || '').trim();
      if (!key) return null;

      const type = normalizeSupplierCredentialFieldType(record.type);
      const field: SupplierFormField = {
        key,
        label: String(record.label || record.title || key).trim(),
        type,
        required: Boolean(record.required),
        secret: Boolean(record.secret),
      };

      return field;
    })
    .filter((item): item is SupplierFormField => Boolean(item));
}

function normalizeSupplierCredentialFieldType(value: unknown): SupplierFormField['type'] {
  const type = String(value || 'text').trim();
  if (['text', 'url', 'password', 'select', 'switch', 'boolean', 'number', 'textarea'].includes(type)) {
    return type as SupplierFormField['type'];
  }

  return 'text';
}

function hasSupplierCredentialValue(value: unknown) {
  if (typeof value === 'boolean') return true;
  if (typeof value === 'number') return Number.isFinite(value);
  return String(value ?? '').trim() !== '';
}

function handleProductSupplierChange(value: SelectValue) {
  const supplierId = typeof value === 'string' || typeof value === 'number' ? value : '';
  productForm.supplier_id = supplierId;
  productForm.upstream_product_id = '';
  supplierProductOptions.value = [];
  if (supplierId) {
    void loadProductSupplierProducts(supplierId, true);
  }
}

async function loadProductSupplierProducts(supplierId: string | number, notify = false) {
  if (!supplierId) return;
  await ensureProviderTypes();

  const supplier = productSupplierOptions.value.find((item) => String(item.id) === String(supplierId));
  if (supplier && !canSupplierBatchConnect(supplier)) {
    supplierProductOptions.value = [];
    MessagePlugin.warning('接口配置不完整，暂时无法同步上游商品');
    return;
  }

  supplierProductLoading.value = true;
  try {
    const response = await supplierApi.products(supplierId, { silent: true });
    supplierProductOptions.value = buildSupplierBatchProducts(response);
    if (productForm.upstream_product_id) {
      const hasCurrent = supplierProductOptions.value.some(
        (item) => String(item.id) === String(productForm.upstream_product_id),
      );
      if (!hasCurrent) {
        supplierProductOptions.value.unshift({
          id: Number(productForm.upstream_product_id),
          name: `已绑定商品 #${productForm.upstream_product_id}`,
          type_label: '当前绑定',
          remote_group_name: '',
          is_connected: true,
          connected_display_name: '',
        });
      }
    }
    if (notify) MessagePlugin.success('上游商品已同步');
  } catch (error) {
    supplierProductOptions.value = [];
    MessagePlugin.error(errorMessage(error, '同步上游商品失败'));
  } finally {
    supplierProductLoading.value = false;
  }
}

function normalizeSupplierBatchProduct(itemValue: unknown): SupplierBatchProduct {
  const item = toPlainRecord(itemValue);
  return {
    ...item,
    id: Number(item.id || item.product_id || 0),
    name: String(item.name || item.product_name || '').trim(),
    type_label: String(item.type_label || item.type_name || item.type || item.billingcycle || '').trim(),
    remote_group_name: String(
      item.remote_group_name || item.group_name || item.second_group_name || item._group_label || '',
    ).trim(),
    is_connected: Boolean(item.is_connected),
    connected_display_name: String(item.connected_display_name || '').trim(),
  };
}

function buildSupplierBatchProducts(payloadValue: unknown) {
  const payload = toPlainRecord(payloadValue);
  const directProducts = Array.isArray(payload.products) ? payload.products : Array.isArray(payload) ? payload : [];
  if (directProducts.length) {
    return directProducts.map(normalizeSupplierBatchProduct).filter((item) => item.id > 0);
  }

  const groups = Array.isArray(payload.groups) ? payload.groups : [];
  return groups
    .flatMap((groupValue) => {
      const group = toPlainRecord(groupValue);
      const groupLabel = String(group.label || group.name || '').trim();
      const items = Array.isArray(group.items) ? group.items : [];
      return items.map((item) => normalizeSupplierBatchProduct({ ...toPlainRecord(item), _group_label: groupLabel }));
    })
    .filter((item) => item.id > 0);
}

function formatSplitAction(action: string) {
  return action === 'update' ? '更新' : '新建';
}

// --- Init ---
async function loadCatalog() {
  await loadProductTypes();
  await Promise.all([loadCategories(), loadProducts()]);
}

onMounted(() => {
  void loadCatalog();
  void loadDiscountGroups();
});
</script>
