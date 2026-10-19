<template>
  <div>
    <t-card :bordered="false" class="type-card">
      <div class="type-strip">
        <t-button
          v-for="item in productTypeOptions"
          :key="item.value"
          :theme="catalogFilters.product_type === item.value ? 'primary' : 'default'"
          :variant="catalogFilters.product_type === item.value ? 'base' : 'outline'"
          :aria-label="`${item.label}${$t('products.catalog.typeCountLabel', { count: item.usage_count || 0 })}`"
          :aria-pressed="catalogFilters.product_type === item.value ? 'true' : 'false'"
          @click="handleProductTypeChange(item.value)"
        >
          {{ item.label }}
          <span class="type-count">{{ item.usage_count || 0 }}</span>
        </t-button>
        <t-tooltip :content="$t('products.catalog.manageProductType')">
          <t-button
            class="type-manage-button"
            shape="square"
            variant="outline"
            :aria-label="$t('products.catalog.manageProductType')"
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
            <strong>{{ $t('products.catalog.productCategories') }}</strong>
            <span class="category-panel-scope"
              >{{ $t('products.catalog.typeScopePrefix') }}{{ selectedProductTypeLabel }} ·
              {{ $t('products.catalog.subCategoryScopeSuffix') }}</span
            >
          </div>
          <t-space size="small">
            <t-button
              shape="square"
              variant="text"
              :loading="categoryLoading"
              :aria-label="$t('products.catalog.refreshCategories')"
              @click="loadCategories"
            >
              <template #icon><refresh-icon /></template>
            </t-button>
            <t-button
              theme="primary"
              size="small"
              :aria-label="$t('products.catalog.addSecondCategory')"
              @click="openSecondCategoryDialog()"
            >
              <template #icon><add-icon /></template>
              {{ $t('products.catalog.addSecondCategory') }}
            </t-button>
          </t-space>
        </div>
        <div class="category-toolbar">
          <t-input v-model="categoryKeyword" clearable :placeholder="$t('products.catalog.categorySearchPlaceholder')">
            <template #prefix-icon><search-icon /></template>
          </t-input>
        </div>

        <div
          v-if="categoryTreeRows.length"
          class="category-tree"
          role="tree"
          :aria-label="$t('products.catalog.categoryTree')"
        >
          <div
            v-for="{ node: item, level, childCount } in categoryTreeRows"
            :key="categoryIdKey(item)"
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
                isCategoryExpanded(item)
                  ? `${$t('products.catalog.collapse')}${categoryDisplayName(item)}`
                  : `${$t('products.catalog.expand')}${categoryDisplayName(item)}`
              "
              @click.stop="toggleCategoryExpanded(item)"
            />
            <button
              type="button"
              class="category-drag"
              :class="{ 'is-dragging': categoryDragState?.key === categoryIdKey(item) }"
              draggable="true"
              :disabled="categorySortLoadingId ? true : undefined"
              :aria-label="`${$t('products.catalog.dragSortPrefix')}${categoryDisplayName(item)}`"
              @dragstart="handleCategoryDragStart(item, $event)"
              @dragend="handleCategoryDragEnd"
              @click.stop
            >
              ::
            </button>
            <button type="button" class="category-tree-main" @click="handleCategorySelect(item)">
              <span class="category-title-line">
                <span class="category-level-tag" :class="categoryLevelTagClass(item)">{{
                  categoryLevelLabel(item)
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
                  >{{ $t('products.catalog.hidden') }}</t-tag
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
                :aria-label="`${$t('products.catalog.moreActionsPrefix')}${categoryDisplayName(item)}`"
                >...</t-button
              >
            </t-dropdown>
          </div>
        </div>
        <t-empty v-else-if="categoryOptions.length" :description="$t('products.catalog.noMatchingCategory')" />
        <t-empty v-else :description="$t('products.catalog.emptyCategory')" />
      </t-card>

      <t-card :bordered="false" class="product-table-card">
        <button type="button" class="mobile-catalog-sidebar-entry" @click="mobileCatalogDrawerVisible = true">
          <span>{{ $t('products.catalog.filterCategory') }}</span>
          <strong>{{ selectedProductTypeLabel }} / {{ selectedCategoryLabel }}</strong>
        </button>

        <div class="filter-row catalog-filter-row">
          <t-input
            v-model="catalogFilters.keyword"
            class="catalog-filter-keyword"
            clearable
            :placeholder="$t('products.catalog.productSearchPlaceholder')"
            :aria-label="$t('products.catalog.productSearchPlaceholder')"
            @enter="handleCatalogSearch"
            @clear="handleCatalogSearch"
          >
            <template #suffix-icon><search-icon /></template>
          </t-input>
          <t-select
            v-model="catalogFilters.status"
            class="catalog-filter-status"
            clearable
            :placeholder="$t('products.catalog.visibilityPlaceholder')"
            :aria-label="$t('products.catalog.filterVisibilityAria')"
            @change="handleCatalogSearch"
          >
            <t-option :value="1" :label="$t('products.catalog.visibilityShown')" />
            <t-option :value="0" :label="$t('products.catalog.visibilityHidden')" />
          </t-select>
          <t-select
            v-model="catalogFilters.lifecycle_status"
            class="catalog-filter-status"
            :placeholder="$t('products.catalog.lifecyclePlaceholder')"
            :aria-label="$t('products.catalog.filterLifecycleAria')"
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
            <admin-view-mode-switch inline :mode="productViewMode" @change="setProductViewMode" />
            <t-button theme="primary" @click="router.push({ name: 'AdminProductCreate' })">
              <template #icon><add-icon /></template>
              {{ $t('products.catalog.addProduct') }}
            </t-button>
          </div>
        </div>

        <div v-if="selectedProductKeys.length" class="product-batch-bar" aria-live="polite">
          <span
            >{{ $t('products.catalog.selectedPrefix') }} {{ selectedProductKeys.length }}
            {{ $t('products.catalog.selectedSuffix') }}</span
          >
          <t-space size="small">
            <t-button variant="outline" @click="openBatchCategoryDialog">
              {{ $t('products.catalog.batchCategorize') }}
            </t-button>
            <t-button variant="outline" @click="openProductBatchDialog('console-template', selectedProductRows())">
              {{ $t('products.catalog.consoleType') }}
            </t-button>
            <t-button variant="outline" @click="openProductBatchDialog('discount-group', selectedProductRows())">
              {{ $t('products.catalog.discountGroup') }}
            </t-button>
            <t-button variant="outline" :loading="splitProductPreviewLoading" @click="openSplitProductDialog">
              {{ $t('products.catalog.splitProduct') }}
            </t-button>
            <t-button variant="outline" @click="openProvisionHostnameDialog">
              {{ $t('products.catalog.batchHostname') }}
            </t-button>
            <t-button variant="outline" theme="danger" @click="handleBatchDeleteProducts">
              {{ $t('products.catalog.delete') }}
            </t-button>
            <t-button variant="outline" theme="danger" @click="handleBatchForceDeleteProducts">
              {{ $t('products.catalog.forceDelete') }}
            </t-button>
            <t-button variant="text" @click="clearProductSelection">{{
              $t('products.catalog.clearSelection')
            }}</t-button>
          </t-space>
        </div>

        <!-- 卡片视图：字段与列表式同源（同一批行数据与格式化函数），不另建一份字段清单 -->
        <div v-if="isProductCardView" class="admin-card-grid product-card-view">
          <t-card v-for="row in products" :key="row.id" :bordered="false" class="product-record-card">
            <div class="product-record-head">
              <button
                type="button"
                class="user-link product-record-title"
                :class="{ 'is-hidden': !isProductVisible(row) }"
                :disabled="!isEntityId(row.id)"
                @click="goProductEditPage(row)"
              >
                <strong>{{ productSpecDisplayName(row) }}</strong>
              </button>
              <span
                class="visibility-status-text"
                :class="{ 'is-hidden': !isProductVisible(row), 'is-deleted': row.is_deleted }"
                >{{ productVisibilityLabel(row) }}</span
              >
            </div>

            <p class="product-record-sub">{{ productSubtitle(row) }}</p>

            <dl class="product-record-meta">
              <div>
                <dt>{{ $t('products.catalog.categoryColumn') }}</dt>
                <dd>{{ row.effective_product_group_full_name || '-' }}</dd>
              </div>
              <div>
                <dt>{{ $t('products.catalog.priceMonthly') }}</dt>
                <dd>{{ formatMonthlyPrice(row) }}</dd>
              </div>
              <div>
                <dt>{{ $t('products.catalog.stock') }}</dt>
                <dd>{{ formatStock(row.stock) }}</dd>
              </div>
              <div>
                <dt>{{ $t('products.catalog.servicesCount') }}</dt>
                <dd>{{ formatCount(row.active_services_count ?? row.services_count) }}</dd>
              </div>
            </dl>

            <div class="product-record-actions">
              <t-button size="small" variant="outline" @click="goProductEditPage(row)">
                {{ $t('products.catalog.editProduct') }}
              </t-button>
            </div>
          </t-card>

          <t-empty
            v-if="!products.length"
            class="product-record-empty"
            :description="$t('products.catalog.emptyProduct')"
          />
        </div>

        <div v-else class="table-scroll">
          <t-table
            row-key="id"
            :data="products"
            :columns="productColumns"
            :loading="productLoading"
            :selected-row-keys="selectedProductKeys"
            hover
            table-layout="fixed"
            :aria-label="$t('products.catalog.productListAria')"
            @select-change="handleProductSelectChange"
          >
            <template #empty>
              <t-empty
                :description="
                  productLoading
                    ? $t('products.catalog.loading')
                    : catalogFilters.keyword ||
                        catalogFilters.status !== '' ||
                        catalogFilters.lifecycle_status !== 'active'
                      ? $t('products.catalog.noMatchingProduct')
                      : $t('products.catalog.emptyProduct')
                "
              />
            </template>
            <template #id="{ row }">
              <button
                type="button"
                class="user-link"
                :disabled="!isEntityId(row.id)"
                @click="goProductEdit(router, row.id)"
              >
                <strong>{{ row.id }}</strong>
              </button>
            </template>
            <template #name="{ row }">
              <div
                class="product-name"
                :class="{ 'is-drop-target': productDragState && productDragState.id !== row.id }"
                @dragover="handleProductDragOver(row, $event)"
                @drop="handleProductDrop(row, $event)"
              >
                <button
                  v-if="canSortProducts"
                  type="button"
                  class="product-drag"
                  :class="{ 'is-dragging': productDragState?.id === row.id }"
                  :disabled="productSortLoading ? true : undefined"
                  :aria-label="`${$t('products.catalog.dragSortPrefix')}${productSpecDisplayName(row)}${$t(
                    'products.catalog.dragSortKeyboardSuffix',
                  )}`"
                  draggable="true"
                  @dragstart="handleProductDragStart(row, $event)"
                  @dragend="handleProductDragEnd"
                  @keydown.up.prevent="moveProduct(row, 'up')"
                  @keydown.down.prevent="moveProduct(row, 'down')"
                  @click.stop
                >
                  ::
                </button>
                <!-- 键盘可达的排序入口：拖拽对键盘用户不可用 -->
                <div class="product-name-main">
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
                <span v-if="row.cpu_turbo" class="product-cpu-cell__turbo"
                  >{{ $t('products.catalog.turboPrefix') }} {{ row.cpu_turbo }}</span
                >
              </div>
            </template>
            <template #discountGroup="{ row }">
              <t-tag v-if="row.product_discount_group_id" theme="primary" variant="light" size="small">
                {{
                  row.product_discount_group_name ||
                  `${$t('products.catalog.groupPrefix')}#${row.product_discount_group_id}`
                }}
              </t-tag>
              <span v-else class="product-discount-group-empty">{{ $t('products.catalog.notSet') }}</span>
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
                    :aria-label="`${$t('products.catalog.moreActionsPrefix')}${row.display_name || row.name || row.id}`"
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
          <button type="button" class="mobile-catalog-back" @click="mobileCatalogDrawerVisible = false">
            {{ $t('products.catalog.back') }}
          </button>
          <div class="mobile-catalog-title">
            <strong>{{ $t('products.catalog.filterProduct') }}</strong>
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
            :aria-label="$t('products.catalog.manageProductType')"
            :title="$t('products.catalog.manageProductType')"
            @click="openTypeManagerDialog()"
          >
            <setting-icon />
          </button>
        </aside>

        <section class="mobile-category-sidebar">
          <div class="category-panel-head">
            <div class="category-panel-title">
              <strong>{{ $t('products.catalog.productCategories') }}</strong>
              <span class="category-panel-scope"
                >{{ $t('products.catalog.typeScopePrefix') }}{{ selectedProductTypeLabel }} ·
                {{ $t('products.catalog.subCategoryScopeSuffix') }}</span
              >
            </div>
            <t-space size="small">
              <t-button shape="square" variant="text" :loading="categoryLoading" @click="loadCategories">
                <template #icon><refresh-icon /></template>
              </t-button>
              <t-button theme="primary" size="small" @click="openSecondCategoryDialog()">
                <template #icon><add-icon /></template>
                {{ $t('products.catalog.addSecondCategory') }}
              </t-button>
            </t-space>
          </div>
          <div class="category-toolbar">
            <t-input
              v-model="categoryKeyword"
              clearable
              :placeholder="$t('products.catalog.categorySearchPlaceholder')"
            >
              <template #prefix-icon><search-icon /></template>
            </t-input>
          </div>

          <div
            v-if="categoryTreeRows.length"
            class="category-tree mobile-category-tree"
            role="tree"
            aria-label="$t('products.catalog.categoryTree')"
          >
            <div
              v-for="{ node: item, level, childCount } in categoryTreeRows"
              :key="categoryIdKey(item)"
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
                  isCategoryExpanded(item)
                    ? `${$t('products.catalog.collapse')}${categoryDisplayName(item)}`
                    : `${$t('products.catalog.expand')}${categoryDisplayName(item)}`
                "
                @click.stop="toggleCategoryExpanded(item)"
              />
              <button
                type="button"
                class="category-drag"
                :class="{ 'is-dragging': categoryDragState?.key === categoryIdKey(item) }"
                draggable="true"
                :disabled="categorySortLoadingId ? true : undefined"
                :aria-label="`${$t('products.catalog.dragSortPrefix')}${categoryDisplayName(item)}`"
                @dragstart="handleCategoryDragStart(item, $event)"
                @dragend="handleCategoryDragEnd"
                @click.stop
              >
                ::
              </button>
              <button type="button" class="category-tree-main" @click="handleMobileCategorySelect(item)">
                <span class="category-title-line">
                  <span class="category-level-tag" :class="categoryLevelTagClass(item)">{{
                    categoryLevelLabel(item)
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
                    >{{ $t('products.catalog.hidden') }}</t-tag
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
                  :aria-label="`${$t('products.catalog.moreActionsPrefix')}${categoryDisplayName(item)}`"
                  >...</t-button
                >
              </t-dropdown>
            </div>
          </div>
          <t-empty v-else-if="categoryOptions.length" :description="$t('products.catalog.noMatchingCategory')" />
          <t-empty v-else :description="$t('products.catalog.emptyCategory')" />
        </section>
      </div>
    </t-drawer>

    <t-drawer
      v-model:visible="productDialogVisible"
      :header="editingProduct ? $t('products.catalog.editProduct') : $t('products.catalog.addProduct')"
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
                  aria-label="$t('products.catalog.completedAria')"
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
              <section
                v-show="activeProductDrawerSection === 'basic'"
                class="product-drawer-section"
                :data-title="$t('products.catalog.sectionDetails')"
              >
                <div class="product-drawer-grid">
                  <t-form-item :label="$t('products.catalog.productName')" name="display_name">
                    <t-input v-model="productForm.display_name" />
                  </t-form-item>
                  <t-form-item :label="$t('products.catalog.belongingCategory')" name="selected_product_group_key">
                    <t-select v-model="productForm.selected_product_group_key" filterable clearable>
                      <t-option
                        v-for="item in selectableProductGroupOptions"
                        :key="productGroupOptionKey(item)"
                        :label="productGroupOptionLabel(item)"
                        :value="productGroupOptionKey(item)"
                      />
                    </t-select>
                  </t-form-item>
                  <t-form-item :label="$t('products.catalog.status')" name="status"
                    ><t-switch v-model="productForm.status" :custom-value="[1, 0]"
                  /></t-form-item>
                </div>
              </section>

              <section
                v-show="activeProductDrawerSection === 'pricing'"
                class="product-drawer-section"
                :data-title="$t('products.catalog.sectionPricing')"
              >
                <div class="product-pricing-actions">
                  <span class="pricing-hint">{{ $t('products.catalog.pricingHint') }}</span>
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
                  <t-form-item :label="$t('products.catalog.monthlyPrice')" name="monthly_price">
                    <t-input-number v-model="productForm.monthly_price" :min="0" style="width: 100%" />
                  </t-form-item>
                  <t-form-item :label="$t('products.catalog.quarterlyPrice')" name="quarterly_price">
                    <t-input-number v-model="productForm.quarterly_price" :min="0" style="width: 100%" />
                  </t-form-item>
                  <t-form-item :label="$t('products.catalog.semiannualPrice')" name="semiannually_price">
                    <t-input-number v-model="productForm.semiannually_price" :min="0" style="width: 100%" />
                  </t-form-item>
                  <t-form-item :label="$t('products.catalog.annualPrice')" name="annually_price">
                    <t-input-number v-model="productForm.annually_price" :min="0" style="width: 100%" />
                  </t-form-item>
                </div>
              </section>

              <section
                v-show="activeProductDrawerSection === 'interface'"
                class="product-drawer-section product-interface-section"
                :data-title="$t('products.catalog.sectionAutoSetup')"
              >
                <div class="product-interface-tip">{{ $t('products.catalog.interfaceTip') }}</div>
                <div class="product-interface-panel">
                  <t-form-item :label="$t('products.catalog.provider')" name="supplier_id">
                    <t-select
                      v-model="productForm.supplier_id"
                      filterable
                      clearable
                      :placeholder="$t('products.catalog.selectProviderPlaceholder')"
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
                  <t-form-item :label="$t('products.catalog.providerProduct')" name="upstream_product_id">
                    <div class="supplier-product-row">
                      <t-cascader
                        v-model="productForm.upstream_product_id"
                        :options="supplierProductCascaderOptions"
                        filterable
                        clearable
                        :placeholder="$t('products.catalog.selectProviderProductPlaceholder')"
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
                        {{ $t('products.catalog.syncData') }}
                      </t-button>
                    </div>
                  </t-form-item>
                  <div class="product-interface-grid">
                    <t-form-item :label="$t('products.catalog.autoSetup')" name="auto_setup">
                      <div class="product-switch-line">
                        <span>{{ $t('products.catalog.manual') }}</span>
                        <t-switch v-model="productForm.auto_setup" :custom-value="[1, 0]" />
                        <span>{{ $t('products.catalog.automatic') }}</span>
                      </div>
                    </t-form-item>
                  </div>
                </div>
              </section>

              <section
                v-show="activeProductDrawerSection === 'config'"
                class="product-drawer-section"
                :data-title="$t('products.catalog.sectionProductConfig')"
              >
                <div class="product-config-actions">
                  <t-button
                    size="small"
                    variant="outline"
                    :loading="configTemplateLoading"
                    @click="pullProductConfigTemplate"
                  >
                    {{ $t('products.catalog.pullTemplate') }}
                  </t-button>
                  <t-button size="small" theme="primary" variant="outline" @click="openConfigOptionDialog()">{{
                    $t('products.catalog.addConfig')
                  }}</t-button>
                </div>
                <div class="config-option-panel">
                  <div class="config-option-count">
                    {{ productForm.config_options.length }} {{ $t('products.catalog.configCountSuffix') }}
                  </div>
                  <div v-if="productForm.config_options.length" class="config-option-list">
                    <article v-for="(item, index) in productForm.config_options" :key="item.uid || item.field || index">
                      <div>
                        <strong>{{ item.name || item.option_name || item.field }}</strong>
                        <span>{{ item.field || '-' }} · {{ configOptionModeLabel(item) }}</span>
                      </div>
                      <t-space size="small">
                        <t-button
                          size="small"
                          variant="text"
                          theme="primary"
                          @click="openConfigOptionDialog(item, index)"
                          >{{ $t('products.catalog.edit') }}</t-button
                        >
                        <t-button size="small" variant="text" theme="danger" @click="removeConfigOption(index)">{{
                          $t('products.catalog.delete')
                        }}</t-button>
                      </t-space>
                    </article>
                  </div>
                  <t-empty v-else :description="$t('products.catalog.emptyConfigOption')" />
                </div>
              </section>
            </t-form>
          </div>
        </div>

        <div class="product-drawer-footer">
          <t-button variant="outline" @click="closeProductDrawer">{{ $t('products.catalog.cancel') }}</t-button>
          <t-button theme="primary" :loading="productSubmitting" @click="submitProduct">{{
            $t('products.catalog.saveChanges')
          }}</t-button>
        </div>
      </div>
    </t-drawer>

    <t-dialog
      v-model:visible="configOptionDialogVisible"
      class="config-option-edit-dialog"
      :header="
        configOptionEditingIndex >= 0 ? $t('products.catalog.editConfigOption') : $t('products.catalog.addConfigOption')
      "
      width="760px"
      :confirm-btn="{ content: $t('products.catalog.saveConfig'), loading: configOptionSubmitting }"
      @confirm="submitConfigOption"
    >
      <t-form class="config-option-edit-form" :data="configOptionForm" label-align="top">
        <div class="config-option-basic-grid">
          <t-form-item :label="$t('products.catalog.configOptionName')" name="name" required-mark>
            <t-input
              v-model="configOptionForm.name"
              :placeholder="$t('products.catalog.configOptionNamePlaceholder')"
            />
          </t-form-item>
          <t-form-item :label="$t('products.catalog.configOptionType')" name="option_mode" required-mark>
            <t-select v-model="configOptionForm.option_mode" @change="handleConfigOptionModeChange">
              <t-option value="select" :label="$t('products.catalog.optionModeSelect')" />
              <t-option value="range" :label="$t('products.catalog.optionModeRange')" />
              <t-option value="text" :label="$t('products.catalog.optionModeText')" />
            </t-select>
          </t-form-item>
          <t-form-item :label="$t('products.catalog.advancedSettings')">
            <t-checkbox v-model="configOptionForm.advanced" />
          </t-form-item>

          <t-form-item :label="$t('products.catalog.configField')" name="field" required-mark>
            <t-input v-model="configOptionForm.field" :placeholder="$t('products.catalog.configFieldPlaceholder')" />
          </t-form-item>
          <t-form-item :label="$t('products.catalog.optionDescription')">
            <t-input
              v-model="configOptionForm.description"
              :placeholder="$t('products.catalog.optionDescriptionPlaceholder')"
            />
          </t-form-item>
          <t-form-item :label="$t('products.catalog.suffixText')">
            <t-input
              v-model="configOptionForm.suffix_text"
              :placeholder="$t('products.catalog.suffixTextPlaceholder')"
            />
          </t-form-item>
        </div>

        <!-- 文字提示：只展示购买须知，不计价也不提交上游 -->
        <t-form-item
          v-if="configOptionForm.option_mode === 'text'"
          :label="$t('products.catalog.tipContent')"
          name="text_content"
        >
          <t-textarea
            v-model="configOptionForm.text_content"
            :placeholder="$t('products.catalog.tipContentPlaceholder')"
          />
        </t-form-item>
        <t-alert
          v-if="configOptionForm.option_mode === 'text'"
          class="config-option-mode-tip"
          theme="info"
          :message="configOptionTextTip"
        />

        <!-- 数量范围：数据范围即滑块上下限，需明确回传字段 -->
        <div v-else-if="configOptionForm.option_mode === 'range'" class="config-range-panel">
          <div class="config-range-head">
            <strong>{{ $t('products.catalog.dataRange') }}</strong>
            <span class="config-range-hint">{{ $t('products.catalog.dataRangeHint') }}</span>
          </div>
          <div class="config-range-grid">
            <t-form-item :label="$t('products.catalog.minValue')">
              <t-input-number v-model="configOptionForm.qty_minimum" :min="0" />
            </t-form-item>
            <t-form-item :label="$t('products.catalog.maxValue')">
              <t-input-number v-model="configOptionForm.qty_maximum" :min="0" />
            </t-form-item>
            <t-form-item :label="$t('products.catalog.step')">
              <t-input-number v-model="configOptionForm.qty_step" :min="1" />
            </t-form-item>
            <t-form-item :label="$t('products.catalog.unit')">
              <t-input v-model="configOptionForm.unit" :placeholder="$t('products.catalog.unitPlaceholder')" />
            </t-form-item>
            <t-form-item :label="$t('products.catalog.defaultValue')">
              <t-input-number v-model="configOptionForm.default_value" :min="0" />
            </t-form-item>
            <t-form-item :label="$t('products.catalog.submitFieldName')">
              <t-input
                v-model="configOptionForm.submit_field"
                :placeholder="$t('products.catalog.submitFieldNamePlaceholder')"
              />
            </t-form-item>
          </div>
        </div>

        <div v-else class="config-subitem-table">
          <div class="config-subitem-head">
            <strong>{{ $t('products.catalog.subItemList') }}</strong>
            <t-button size="small" variant="outline" @click="addConfigSubItemRow">{{
              $t('products.catalog.addSubItem')
            }}</t-button>
          </div>
          <div class="config-subitem-grid config-subitem-grid--header">
            <span>{{ $t('products.catalog.subItemName') }}</span>
            <span>{{ $t('products.catalog.subItemParamValue') }}</span>
            <span>{{ $t('products.catalog.subItemMonthlyPrice') }}</span>
            <span>{{ $t('products.catalog.subItemSort') }}</span>
            <span>{{ $t('products.catalog.subItemOperation') }}</span>
          </div>
          <div v-for="(row, index) in configOptionSubItemRows" :key="row.uid" class="config-subitem-grid">
            <t-input v-model="row.name" :placeholder="$t('products.catalog.subItemNamePlaceholder')" />
            <t-input v-model="row.value" :placeholder="$t('products.catalog.subItemValuePlaceholder')" />
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
          <t-form-item :label="$t('products.catalog.sortOrder')" name="sort_order">
            <t-input-number v-model="configOptionForm.sort_order" :min="0" />
          </t-form-item>
          <t-form-item :label="$t('products.catalog.switches')">
            <t-space>
              <t-checkbox v-model="configOptionForm.required">{{ $t('products.catalog.required') }}</t-checkbox>
              <t-checkbox v-model="configOptionForm.hidden">{{ $t('products.catalog.hidden') }}</t-checkbox>
            </t-space>
          </t-form-item>
        </div>
      </t-form>
    </t-dialog>

    <t-dialog
      v-model:visible="batchCategoryDialogVisible"
      :header="$t('products.catalog.batchCategorize')"
      width="520px"
      :confirm-btn="{ content: $t('products.catalog.confirmCategorize'), loading: batchCategorySubmitting }"
      @confirm="submitBatchCategory"
    >
      <div class="batch-dialog-summary">
        {{ $t('products.catalog.moveProductsSummary', { count: batchCategoryTargetKeys.length }) }}
      </div>
      <t-form :data="batchCategoryForm" label-width="110px">
        <t-form-item :label="$t('products.catalog.targetCategory')" name="target_product_group_key">
          <t-select
            v-model="batchCategoryForm.target_product_group_key"
            filterable
            clearable
            :placeholder="$t('products.catalog.selectTargetCategoryPlaceholder')"
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
      :confirm-btn="{ content: $t('products.catalog.confirmModify'), loading: productBatchSubmitting }"
      @confirm="submitProductBatch"
    >
      <div class="batch-dialog-summary">
        {{
          productBatchTargetGroup
            ? $t('products.catalog.batchModifyGroupSummary', { name: productBatchTargetGroup.name })
            : $t('products.catalog.batchModifySelectedSummary', { count: productBatchTargetProductIds.length })
        }}
      </div>
      <t-form label-width="110px">
        <t-form-item v-if="productBatchDialogKind === 'console-template'" :label="$t('products.catalog.consoleType')">
          <t-radio-group v-model="consoleTemplateValue" variant="default-filled">
            <t-radio-button value="compute">{{ $t('products.catalog.consoleCompute') }}</t-radio-button>
            <t-radio-button value="port_mapping">{{ $t('products.catalog.consolePortMapping') }}</t-radio-button>
            <t-radio-button value="cdn">{{ $t('products.catalog.consoleCdn') }}</t-radio-button>
            <t-radio-button value="virtualhost">{{ $t('products.catalog.consoleVirtualHost') }}</t-radio-button>
          </t-radio-group>
          <p class="batch-dialog-tip">{{ $t('products.catalog.consoleTypeTip') }}</p>
        </t-form-item>
        <t-form-item
          v-else-if="productBatchDialogKind === 'discount-group'"
          :label="$t('products.catalog.discountGroup')"
        >
          <t-select
            v-model="discountGroupValue"
            clearable
            :loading="discountGroupLoading"
            :placeholder="$t('products.catalog.selectDiscountGroupPlaceholder')"
          >
            <t-option
              v-for="item in discountGroupOptions"
              :key="item.id"
              :value="item.id"
              :label="`${item.name || item.code || item.id}${item.min_discount_rate ? $t('products.catalog.minDiscountRateSuffix', { rate: item.min_discount_rate }) : ''}`"
            />
          </t-select>
        </t-form-item>
        <template v-else-if="productBatchDialogKind === 'cpu-spec'">
          <t-form-item :label="$t('products.catalog.cpuModel')">
            <t-input
              v-model="cpuModelValue"
              :placeholder="$t('products.catalog.cpuModelPlaceholder')"
              maxlength="120"
            />
          </t-form-item>
          <t-form-item :label="$t('products.catalog.cpuTurbo')">
            <t-input v-model="cpuTurboValue" :placeholder="$t('products.catalog.cpuTurboPlaceholder')" maxlength="40" />
          </t-form-item>
          <p class="batch-dialog-tip">{{ $t('products.catalog.cpuSpecTip') }}</p>
        </template>
      </t-form>
    </t-dialog>

    <t-dialog
      v-model:visible="splitProductDialogVisible"
      :header="$t('products.catalog.splitProduct')"
      width="680px"
      :confirm-btn="{
        content: $t('products.catalog.confirmSplit'),
        loading: splitProductSubmitting,
        disabled: splitProductPreviewLoading || !splitProductPreviewRows.length,
      }"
      @confirm="submitSplitProducts"
    >
      <div class="split-dialog-intro">
        <strong>{{ $t('products.catalog.splitIntroTitle') }}</strong>
        <p>{{ $t('products.catalog.splitIntroTip') }}</p>
      </div>

      <div class="split-product-meta">
        <span
          >{{ $t('products.catalog.selectedProductsPrefix') }} {{ splitProductTargetKeys.length }}
          {{ $t('products.catalog.itemUnit') }}</span
        >
        <span v-if="splitProductPreviewCount">
          {{ $t('products.catalog.expectedSpecPrefix') }} {{ splitProductPreviewCount }}
          {{ $t('products.catalog.specUnit') }}
        </span>
        <span v-if="splitProductSkippedCount">
          {{ $t('products.catalog.skippedPrefix') }} {{ splitProductSkippedCount }}
          {{ $t('products.catalog.itemUnit') }}
        </span>
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
          <t-empty v-else :description="$t('products.catalog.noSplittableSpec')" />
        </div>
      </t-loading>

      <t-alert theme="warning" :message="$t('products.catalog.splitAlert')" />
    </t-dialog>

    <t-dialog
      v-model:visible="provisionHostnameDialogVisible"
      :header="$t('products.catalog.batchHostnameRuleTitle')"
      width="620px"
      :confirm-btn="{ content: $t('products.catalog.saveRule'), loading: provisionHostnameSubmitting }"
      @confirm="submitProvisionHostname"
    >
      <div class="split-dialog-intro">
        <strong>{{ $t('products.catalog.setProvisionHostnameTitle') }}</strong>
        <p>{{ $t('products.catalog.setProvisionHostnameTip') }}</p>
      </div>

      <div class="split-product-meta">
        <span
          >{{ $t('products.catalog.selectedProductsPrefix') }} {{ provisionHostnameTargetKeys.length }}
          {{ $t('products.catalog.itemUnit') }}</span
        >
        <span v-if="provisionHostnameHasMixedRules">{{ $t('products.catalog.mixedRulesTip') }}</span>
      </div>

      <t-alert
        v-if="provisionHostnameHasMixedRules"
        theme="warning"
        :message="$t('products.catalog.mixedRulesAlert')"
      />

      <t-form class="hostname-rule-form" :data="provisionHostnameForm" label-width="120px">
        <t-form-item :label="$t('products.catalog.provisionStrategy')" name="mode">
          <t-radio-group v-model="provisionHostnameForm.mode" variant="default-filled">
            <t-radio-button value="system">{{ $t('products.catalog.followUpstream') }}</t-radio-button>
            <t-radio-button value="fixed">{{ $t('products.catalog.fixedHostname') }}</t-radio-button>
            <t-radio-button value="prefix">{{ $t('products.catalog.customPrefix') }}</t-radio-button>
          </t-radio-group>
        </t-form-item>
        <t-form-item
          v-if="provisionHostnameForm.mode === 'fixed'"
          :label="$t('products.catalog.fixedHostname')"
          name="value"
        >
          <t-input
            v-model="provisionHostnameForm.value"
            maxlength="200"
            :placeholder="$t('products.catalog.fixedHostnamePlaceholder')"
          />
        </t-form-item>
        <template v-if="provisionHostnameForm.mode === 'prefix'">
          <t-form-item :label="$t('products.catalog.hostnamePrefix')" name="value">
            <t-input
              v-model="provisionHostnameForm.value"
              maxlength="20"
              :placeholder="$t('products.catalog.hostnamePrefixPlaceholder')"
            />
          </t-form-item>
          <t-form-item :label="$t('products.catalog.hostnameTotalLength')" name="length">
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
      :confirm-btn="{ content: $t('products.catalog.save'), loading: categorySubmitting }"
      @confirm="submitCategory"
    >
      <t-form ref="categoryFormRef" :data="categoryForm" :rules="categoryRules" label-width="110px">
        <t-form-item :label="$t('products.catalog.categoryName')" name="name"
          ><t-input v-model="categoryForm.name"
        /></t-form-item>
        <t-form-item :label="$t('products.catalog.productType')" name="product_type">
          <t-select
            v-model="categoryForm.product_type"
            filterable
            :placeholder="$t('products.catalog.selectProductTypePlaceholder')"
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
        <t-form-item
          v-if="categoryParentFieldVisible"
          :label="$t('products.catalog.secondCategoryParent')"
          name="parent_id"
        >
          <t-select
            v-model="categoryForm.parent_id"
            clearable
            filterable
            :placeholder="$t('products.catalog.secondCategoryParentPlaceholder')"
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
        <t-form-item :label="$t('products.catalog.slogan')" name="slogan"
          ><t-input v-model="categoryForm.slogan"
        /></t-form-item>
        <t-form-item :label="$t('products.catalog.sortOrder')" name="sort_order"
          ><t-input-number v-model="categoryForm.sort_order" :min="0"
        /></t-form-item>
        <t-form-item :label="$t('products.catalog.frontendVisible')" name="is_visible"
          ><t-switch v-model="categoryForm.is_visible" :custom-value="[1, 0]"
        /></t-form-item>
      </t-form>
    </t-dialog>

    <t-dialog
      v-model:visible="typeManagerDialogVisible"
      class="type-manager-dialog"
      :header="$t('products.catalog.manageProductType')"
      width="880px"
      :footer="false"
    >
      <div class="type-manager">
        <section class="type-manager-editor" :class="{ 'is-editing': editingTypeValue }">
          <div class="type-manager-editor__head">
            <div>
              <strong>{{
                editingTypeValue ? $t('products.catalog.editProductType') : $t('products.catalog.addProductType')
              }}</strong>
              <span v-if="editingTypeValue">{{ normalizeBusinessProductType(typeForm.product_type) }}</span>
            </div>
          </div>

          <t-form :data="typeForm" label-align="top" class="type-form">
            <div class="type-form-grid">
              <t-form-item :label="$t('products.catalog.productTypeName')" name="label" required-mark>
                <t-input
                  v-model="typeForm.label"
                  maxlength="40"
                  :placeholder="$t('products.catalog.productTypeNamePlaceholder')"
                />
              </t-form-item>
              <t-form-item :label="$t('products.catalog.productType')" name="product_type" required-mark>
                <t-select v-model="typeForm.product_type" :options="businessProductTypeOptions" />
              </t-form-item>
              <t-form-item :label="$t('products.catalog.productTypeSlug')" name="slug">
                <t-input
                  v-model="typeForm.slug"
                  maxlength="60"
                  :placeholder="$t('products.catalog.productTypeSlugPlaceholder')"
                />
                <p class="type-form-hint">{{ $t('products.catalog.productTypeSlugHint') }}</p>
              </t-form-item>
              <t-form-item :label="$t('products.catalog.icon')" name="icon" class="type-icon-form-item">
                <div
                  class="type-icon-picker"
                  role="radiogroup"
                  :aria-label="$t('products.catalog.selectProductTypeIconAria')"
                >
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
                  {{ editingTypeValue ? $t('products.catalog.save') : $t('products.catalog.add') }}
                </t-button>
                <t-button variant="outline" @click="resetTypeForm">{{
                  editingTypeValue ? $t('products.catalog.cancelEdit') : $t('products.catalog.reset')
                }}</t-button>
              </t-form-item>
            </div>
          </t-form>
        </section>

        <div class="type-manager-list">
          <div class="type-manager-list-head">
            <div>
              <strong>{{ $t('products.catalog.categoryList') }}</strong>
              <span
                >{{ productTypes.length }} {{ $t('products.catalog.productTypeUnit') }} · {{ typeManagerProductCount }}
                {{ $t('products.catalog.productUnit') }} · {{ typeManagerHiddenCount }}
                {{ $t('products.catalog.hiddenUnit') }}</span
              >
            </div>
            <t-button size="small" variant="outline" :loading="typeLoading" @click="loadProductTypes">
              <template #icon><refresh-icon /></template>
              {{ $t('products.catalog.refresh') }}
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
                  <t-tag v-if="item.is_hidden" theme="warning" variant="light">{{
                    $t('products.catalog.hidden')
                  }}</t-tag>
                  <t-tag v-if="editingTypeValue === item.value" theme="primary" variant="light">{{
                    $t('products.catalog.editing')
                  }}</t-tag>
                </div>
                <div class="type-manager-item__meta">
                  <span>{{ normalizeBusinessProductType(item.product_type) }}</span>
                  <span v-if="item.slug" class="type-manager-item__slug">/products/{{ item.slug }}</span>
                  <span v-if="item.icon" class="type-manager-item__icon-meta">
                    <t-icon :name="resolveProductTypeIconName(item.icon)" />
                    {{ productTypeIconDisplayLabel(item.icon) }}
                  </span>
                </div>
              </div>
              <dl class="type-manager-item__stats">
                <div>
                  <dt>{{ $t('products.catalog.product') }}</dt>
                  <dd>{{ item.usage_count || 0 }}</dd>
                </div>
                <div>
                  <dt>{{ $t('products.catalog.group') }}</dt>
                  <dd>{{ item.group_count || 0 }}</dd>
                </div>
              </dl>
              <div class="type-manager-actions">
                <div class="type-manager-order-actions">
                  <t-tooltip :content="$t('products.catalog.moveUp')">
                    <t-button
                      size="small"
                      shape="square"
                      variant="outline"
                      :aria-label="$t('products.catalog.moveUpProductTypeAria')"
                      :disabled="index === 0 || typeSubmitting"
                      @click="moveType(item.value, -1)"
                    >
                      <template #icon><arrow-up-icon /></template>
                    </t-button>
                  </t-tooltip>
                  <t-tooltip :content="$t('products.catalog.moveDown')">
                    <t-button
                      size="small"
                      shape="square"
                      variant="outline"
                      :aria-label="$t('products.catalog.moveDownProductTypeAria')"
                      :disabled="index === productTypes.length - 1 || typeSubmitting"
                      @click="moveType(item.value, 1)"
                    >
                      <template #icon><arrow-down-icon /></template>
                    </t-button>
                  </t-tooltip>
                </div>
                <t-tooltip :content="$t('products.catalog.edit')">
                  <t-button
                    size="small"
                    shape="square"
                    variant="text"
                    theme="primary"
                    :aria-label="$t('products.catalog.editProductTypeAria')"
                    :disabled="typeSubmitting"
                    @click="editType(item)"
                  >
                    <template #icon><edit-icon /></template>
                  </t-button>
                </t-tooltip>
                <t-tooltip :content="item.is_hidden ? $t('products.catalog.show') : $t('products.catalog.hidden')">
                  <t-button
                    size="small"
                    shape="square"
                    variant="text"
                    :aria-label="$t('products.catalog.toggleProductTypeVisibilityAria')"
                    :disabled="typeSubmitting"
                    @click="toggleTypeHidden(item)"
                  >
                    <template #icon><browse-icon v-if="item.is_hidden" /><browse-off-icon v-else /></template>
                  </t-button>
                </t-tooltip>
                <t-tooltip :content="$t('products.catalog.delete')">
                  <t-button
                    size="small"
                    shape="square"
                    variant="text"
                    theme="danger"
                    :aria-label="$t('products.catalog.deleteProductTypeAria')"
                    :disabled="typeSubmitting"
                    @click="deleteType(item)"
                  >
                    <template #icon><delete-icon /></template>
                  </t-button>
                </t-tooltip>
              </div>
            </article>
          </div>
          <t-empty v-if="productTypes.length === 0" :description="$t('products.catalog.emptyProductType')" />
        </div>
        <div class="type-manager-footer">
          <t-button variant="outline" @click="typeManagerDialogVisible = false">{{
            $t('products.catalog.close')
          }}</t-button>
        </div>
      </div>
    </t-dialog>

    <t-dialog
      v-model:visible="specHighlightDialogVisible"
      :header="$t('products.catalog.specHighlightTitle')"
      :confirm-btn="{ content: $t('products.catalog.save'), loading: specHighlightSubmitting }"
      :cancel-btn="{}"
      width="560px"
      @confirm="submitSpecHighlight"
    >
      <p class="spec-highlight-summary">
        {{ $t('products.catalog.currentSummaryPrefix') }} {{ specHighlightSummary || '—' }}
      </p>
      <p class="spec-highlight-tip">{{ $t('products.catalog.specHighlightTip') }}</p>
      <div class="spec-highlight-form">
        <label v-for="dimension in specHighlightDimensions" :key="dimension.key" class="spec-highlight-field">
          <span>{{ dimension.label }}</span>
          <t-input
            v-model="specHighlightForm[dimension.key]"
            :placeholder="$t('products.catalog.autoExtractPlaceholder')"
            clearable
          />
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
import AdminViewModeSwitch from '@/components/admin-view-mode-switch/index.vue';
import { useAdminViewMode } from '@/composables/useAdminViewMode';
import { AdminPermissions, hasPermissionInList } from '@/constants/permissions';
import { t } from '@/locales';
import { useUserStore } from '@/store';
import { goProductEdit, isEntityId } from '@/utils/route/adminEntityLink';

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

/** 配置项在后台可编辑的呈现类型 */
type ConfigOptionEditMode = 'select' | 'range' | 'text';

interface ConfigOptionSubItemFormRow {
  uid: string;
  name: string;
  value: string;
  monthly_price: string;
  sort_order: number;
}

type ProductLifecycleStatus = 'active' | 'deleted' | 'all';

/**
 * 区间型（滑块/数量）配置项的 option_type 数值集合。
 *
 * 与后端 ProductConfigOptionPresenter::RANGE_OPTION_TYPES、
 * HandlesOrderCalculation::RANGE_TYPES、购买页 QTY_OPTION_TYPES 保持一致。
 */
const RANGE_OPTION_TYPES = [4, 7, 9, 11, 14, 15, 16, 17, 18, 19];

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

/**
 * 商品列表的展示方式：与其它列表页共用 useAdminViewMode，偏好按页面记住，
 * 默认值在设置面板里配置。列表式是既有形态，卡片式是可选的第二形态——
 * 两者只影响展示，不影响勾选与批量操作（卡片视图下暂不提供批选）。
 */
const {
  mode: productViewMode,
  isCard: isProductCardView,
  setMode: setProductViewMode,
} = useAdminViewMode('admin-products', 'list');

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
  // 前台商品页地址里的类型段：/products/{slug}/...
  slug: '',
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
  // 数量范围（滑块）字段
  qty_minimum: 0,
  qty_maximum: 0,
  qty_step: 1,
  unit: '',
  default_value: 0,
  submit_field: '',
  // 文字提示（仅展示）字段
  text_content: '',
  advanced: true,
  required: true,
  hidden: false,
  sort_order: 0,
});
const configOptionSubItemRows = ref<ConfigOptionSubItemFormRow[]>([]);

/** 文字提示类型的固定说明：避免管理员把它当真实规格配置使用 */
const configOptionTextTip = computed(() => {
  const field = configOptionForm.field.trim();
  return field
    ? t('products.catalog.optionDescriptionHintExcluded', { field })
    : t('products.catalog.optionDescriptionHint');
});
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
  {
    value: 'standard',
    label: t('products.catalog.discountStandard'),
    ratios: { quarterly: 3, semiannually: 6, annually: 12 },
  },
  {
    value: 'annual',
    label: t('products.catalog.discountAnnual'),
    ratios: { quarterly: 3, semiannually: 6, annually: 10 },
  },
  {
    value: 'bulk',
    label: t('products.catalog.discountBulk'),
    ratios: { quarterly: 2.7, semiannually: 5.1, annually: 8.4 },
  },
  {
    value: 'rule1',
    label: t('products.catalog.discountRuleOne'),
    ratios: { quarterly: 3, semiannually: 4.8, annually: 9 },
  },
  {
    value: 'rule2',
    label: t('products.catalog.discountRuleTwo'),
    ratios: { quarterly: 2.7, semiannually: 5.1, annually: 9.6 },
  },
];
const lifecycleStatusOptions = [
  { value: 'active', label: t('products.catalog.filterActive') },
  { value: 'deleted', label: t('products.catalog.filterDeleted') },
  { value: 'all', label: t('products.catalog.filterAll') },
] satisfies Array<{ value: ProductLifecycleStatus; label: string }>;
const productTypeIconOptions = [
  { value: 'server', label: t('products.catalog.typeServer'), aliases: ['vps', 'Server'] },
  { value: 'cloud', label: t('products.catalog.typeCloud'), aliases: ['cloud'] },
  { value: 'control-platform', label: t('products.catalog.typePlatform'), aliases: ['Platform'] },
  { value: 'building', label: t('products.catalog.typeDatacenter'), aliases: ['dedicated', 'OfficeBuilding'] },
  { value: 'desktop', label: t('products.catalog.typeHost'), aliases: ['hosting', 'Monitor'] },
  { value: 'internet', label: t('products.catalog.typeNetwork'), aliases: ['cdn'] },
  { value: 'hard-disk-storage', label: t('products.catalog.typeStorage'), aliases: ['storage'] },
  { value: 'data-base', label: t('products.catalog.typeDatabase'), aliases: ['database', 'db'] },
  { value: 'link', label: t('products.catalog.typeDomain'), aliases: ['domain', 'Link'] },
  { value: 'lock-on', label: t('products.catalog.typeSecurity'), aliases: ['ssl'] },
  { value: 'component-grid', label: t('products.catalog.typeApplication'), aliases: ['Grid', 'other'] },
  { value: 'tools', label: t('products.catalog.typeTool'), aliases: ['tool'] },
  { value: 'shop', label: t('products.catalog.product'), aliases: ['product'] },
] satisfies Array<{ value: string; label: string; aliases?: string[] }>;

const productDrawerSections = [
  { key: 'basic', label: t('products.catalog.sectionDetails'), description: t('products.catalog.sectionDetailsDesc') },
  {
    key: 'pricing',
    label: t('products.catalog.sectionPricing'),
    description: t('products.catalog.sectionPricingDesc'),
  },
  {
    key: 'interface',
    label: t('products.catalog.sectionInterface'),
    description: t('products.catalog.sectionInterfaceDesc'),
  },
  {
    key: 'config',
    label: t('products.catalog.sectionProductConfig'),
    description: t('products.catalog.sectionProductConfigDesc'),
  },
];

const productColumns: PrimaryTableCol<TableRowData>[] = [
  { colKey: 'row-select', type: 'multiple', width: 54, fixed: 'left' },
  { colKey: 'id', title: 'ID', width: 80 },
  { colKey: 'name', title: t('products.catalog.product'), minWidth: 260 },
  { colKey: 'effective_product_group_full_name', title: t('products.catalog.categoryColumn'), width: 180 },
  { colKey: 'price', title: t('products.catalog.priceMonthly'), width: 120 },
  { colKey: 'stock', title: t('products.catalog.stock'), width: 100 },
  { colKey: 'services_count', title: t('products.catalog.servicesCount'), width: 100 },
  { colKey: 'cpu', title: t('products.catalog.cpuModel'), width: 180 },
  { colKey: 'discountGroup', title: t('products.catalog.discountGroup'), width: 140 },
  { colKey: 'sort_order', title: t('products.catalog.sortOrder'), width: 76 },
  { colKey: 'status', title: t('products.catalog.visibilityPlaceholder'), width: 110 },
  { colKey: 'operation', title: t('common.action'), width: 72, fixed: 'right' },
];

const productRules: FormRules<ProductFormData> = {
  display_name: [{ required: true, message: t('products.catalog.productNameRequired'), trigger: 'blur' }],
  selected_product_group_key: [{ required: true, message: t('products.catalog.categoryRequired'), trigger: 'change' }],
};
const categoryRules: FormRules<CategoryFormData> = {
  name: [{ required: true, message: t('products.catalog.categoryNameRequired'), trigger: 'blur' }],
  product_type: [{ required: true, message: t('products.catalog.productTypeRequired'), trigger: 'change' }],
};

// --- Computeds ---
const productTypeOptions = computed(() => {
  if (productTypes.value.length) return productTypes.value;
  return [{ value: '', label: t('products.catalog.filterAll'), usage_count: productTotal.value }];
});
const businessProductTypeOptions = [
  { value: 'cloud_server', label: t('products.catalog.specCloudServer') },
  { value: 'game_cloud', label: t('products.catalog.specGameCloud') },
  { value: 'cloud_desktop', label: t('products.catalog.specCloudDesktop') },
  { value: 'bare_metal', label: t('products.catalog.specBareMetal') },
  { value: 'cdn', label: 'CDN' },
  { value: 'other', label: t('products.catalog.specOther') },
  { value: 'physical_machine', label: t('products.catalog.specPhysicalMachine') },
  { value: 'web_hosting', label: t('products.catalog.specWebHosting') },
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
  return current?.label || t('products.catalog.filterAll');
});
const categoryProductTypeOptions = computed(() => {
  const options = productTypeOptions.value.filter((item) => String(item.value || '').trim());
  if (options.length) return options;
  return catalogFilters.product_type
    ? [{ value: catalogFilters.product_type, label: selectedProductTypeLabel.value }]
    : [];
});
const categoryTree = computed(() => buildCategoryTree(categoryOptions.value));
// 顶部 type-strip 就是一级分组（L1，代码里叫 product_type，实际存的是菜单 code），
// 分类接口也按它过滤，所以展示树从 L2 起，节点层级标签按真实层级给，不再按树深度给。
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
  if (editingCategory.value) return t('products.catalog.editCategory');
  return creatingThirdCategoryParent.value
    ? t('products.catalog.createThirdCategory')
    : t('products.catalog.createSecondCategory');
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
  if (!selectedKey) return t('products.catalog.allCategories');
  const category = findProductGroupByKey(categoryOptions.value, selectedKey);
  return category ? categoryDisplayName(category) : t('products.catalog.allCategories');
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
      ? t('products.catalog.provisionFixedHint', { hostname: value })
      : t('products.catalog.provisionFixedRequired');
  }

  if (provisionHostnameForm.mode === 'prefix') {
    const prefix = provisionHostnameForm.value.trim() || 'prefix';
    return t('products.catalog.provisionRandomHint', {
      prefix,
      length: Number(provisionHostnameForm.length || 12),
    });
  }

  return t('products.catalog.provisionFollowHint');
});
const providerTypeOptions = computed(() => {
  return mergeProviderTypeOptions(providerTypes.value);
});
const supplierProductCascaderOptions = computed(() => {
  const groups = new Map<string, SupplierBatchProduct[]>();
  supplierProductOptions.value.forEach((item) => {
    const groupName = String(item.remote_group_name || item.type_label || t('products.catalog.defaultGroup'));
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

// 树里的层级标签必须按真实层级给，而不是按「在树中的第几层」给：
// 顶部一级分组条已经选定了 L1，树根是 L2、子节点是 L3，
// 之前按深度标成「一级 / 二级」，和上面的 L1 撞车，看起来就像两套一级分组。
function categoryLevelLabel(row: ProductCategoryRecord) {
  const level = productGroupLevel(row);
  if (level === 1) return t('products.catalog.level1Group');
  if (level === 2) return t('products.catalog.level2Group');
  if (level === 3) return t('products.catalog.level3Group');
  return t('products.catalog.categoryColumn');
}

function categoryLevelTagClass(row: ProductCategoryRecord) {
  return `category-level-${productGroupLevel(row) || 0}`;
}

function categoryDisplayName(row: ProductCategoryRecord) {
  return String(row.name || row.label || t('products.catalog.categoryFallbackWithId', { id: row.id })).trim();
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
  if (row.is_deleted) return t('products.catalog.deletedState');
  return isProductVisible(row) ? t('products.catalog.visibilityShown') : t('products.catalog.visibilityHidden');
}

function isProductVisible(row: ProductRecord) {
  return Number(row.status) === 1;
}

function productRowMenuOptions(row: ProductRecord): DropdownOption[] {
  if (row.is_deleted) {
    return [
      {
        content: t('products.catalog.restore'),
        value: 'restore',
        theme: 'primary',
        loading: productActionLoading.value === `restore:${row.id}`,
      },
      {
        content: t('products.catalog.purge'),
        value: 'force-delete',
        theme: 'error',
        loading: productActionLoading.value === `force:${row.id}`,
      },
    ] as DropdownOption[];
  }
  return [
    { content: t('products.catalog.edit'), value: 'edit', theme: 'default' },
    { content: t('products.catalog.specHighlightTitle'), value: 'spec-highlight', theme: 'default' },
    { content: t('products.catalog.consoleType'), value: 'console-template', theme: 'default' },
    { content: t('products.catalog.agentDiscountGroup'), value: 'discount-group', theme: 'default' },
    {
      content: Number(row.status) === 1 ? t('products.catalog.hidden') : t('products.catalog.show'),
      value: 'toggle',
      theme: 'default',
      loading: productActionLoading.value === row.id,
    },
    { content: t('products.catalog.delete'), value: 'delete', theme: 'error' },
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
    MessagePlugin.error(errorMessage(error, t('products.catalog.loadSpecHighlightFailed')));
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
    MessagePlugin.success(t('products.catalog.specHighlightSaved'));
    specHighlightDialogVisible.value = false;
  } catch (error) {
    MessagePlugin.error(errorMessage(error, t('products.catalog.saveSpecHighlightFailed')));
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
    { content: t('products.catalog.moveUp'), value: 'up', disabled: !canMoveCategory(row, 'up') },
    { content: t('products.catalog.moveDown'), value: 'down', disabled: !canMoveCategory(row, 'down') },
  ];
  if (productGroupLevel(row) === 2) {
    options.push({ content: t('products.catalog.createThirdCategory'), value: 'create-child', divider: true });
  }
  options.push({ content: t('products.catalog.discountGroup'), value: 'batch-discount', divider: true });
  options.push({ content: t('products.catalog.consoleType'), value: 'batch-console' });
  options.push({ content: t('products.catalog.batchCpu'), value: 'batch-cpu', divider: true });
  options.push(
    { content: t('products.catalog.edit'), value: 'edit', divider: true },
    { content: t('products.catalog.delete'), value: 'delete', theme: 'error' } as DropdownOption,
    { content: t('products.catalog.forceDelete'), value: 'force-delete', theme: 'error' } as DropdownOption,
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
  // 编辑三级分组：一级分组变更后，父级必须重新选择，并按新一级分组加载父级选项
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
    item.second_product_group_name ||
      item.name ||
      item.label ||
      t('products.catalog.secondGroupFallback', { id: productGroupEffectiveId(item) }),
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
    MessagePlugin.error(errorMessage(error, t('products.catalog.loadSecondCategoryParentFailed')));
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
    MessagePlugin.error(errorMessage(error, t('products.catalog.loadFirstGroupFailed')));
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
  typeForm.slug = '';
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
  typeForm.slug = String(type.slug || '');
}

const PRODUCT_TYPE_SLUG_PATTERN = /^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/;

function normalizeTypeSlugInput(value: string) {
  return String(value || '')
    .trim()
    .toLowerCase();
}

function validateTypeSlugInput(slug: string) {
  if (!slug) {
    // 留空交给后端按 value 生成，不强制填写
    return true;
  }

  if (slug.length < 2 || slug.length > 60) {
    return false;
  }

  if (!PRODUCT_TYPE_SLUG_PATTERN.test(slug) || /^\d+$/.test(slug)) {
    return false;
  }

  return true;
}

async function submitType() {
  const label = typeForm.label.trim();
  if (!label) {
    MessagePlugin.warning(t('products.catalog.productTypeNameRequired'));
    return;
  }

  const slug = normalizeTypeSlugInput(typeForm.slug);
  if (!validateTypeSlugInput(slug)) {
    MessagePlugin.warning(t('products.catalog.productTypeSlugInvalid'));
    return;
  }

  typeSubmitting.value = true;
  try {
    const icon = resolveProductTypeIconName(typeForm.icon);
    const productType = normalizeBusinessProductType(typeForm.product_type);
    if (editingTypeValue.value) {
      await productApi.updateType(editingTypeValue.value, {
        label,
        product_type: productType,
        icon,
        // 空字符串表示不修改（后端 nullable）
        slug,
      });
      MessagePlugin.success(t('products.catalog.productTypeUpdated'));
    } else {
      await productApi.createType({ label, product_type: productType, icon, slug });
      MessagePlugin.success(t('products.catalog.productTypeCreated'));
    }
    resetTypeForm();
    await refreshTypeCatalog();
  } catch (error) {
    MessagePlugin.error(errorMessage(error, t('products.catalog.saveFirstGroupFailed')));
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
    MessagePlugin.success(
      type.is_hidden ? t('products.catalog.firstGroupShown') : t('products.catalog.firstGroupHidden'),
    );
    await refreshTypeCatalog();
  } catch (error) {
    MessagePlugin.error(errorMessage(error, t('products.catalog.updateFirstGroupFailed')));
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
    MessagePlugin.success(t('products.catalog.productTypeSorted'));
  } catch (error) {
    MessagePlugin.error(errorMessage(error, t('products.catalog.updateFirstGroupSortFailed')));
    await loadProductTypes();
  } finally {
    typeSubmitting.value = false;
  }
}

function deleteType(type: ProductTypeRecord) {
  const dialog = DialogPlugin.confirm({
    header: t('products.catalog.deleteFirstGroupTitle'),
    body: t('products.catalog.deleteNamedConfirm', { name: type.label || type.value }),
    theme: 'warning',
    confirmBtn: t('products.catalog.confirmDelete'),
    cancelBtn: t('products.catalog.cancel'),
    async onConfirm() {
      typeSubmitting.value = true;
      try {
        await productApi.deleteType(type.value);
        MessagePlugin.success(t('products.catalog.productTypeDeleted'));
        if (false && catalogFilters.lifecycle_status === 'all') {
          MessagePlugin.info(t('products.catalog.deletedProductsVisibleTip'));
        }
        dialog.hide();
        if (catalogFilters.product_type === type.value) {
          catalogFilters.product_type = '';
          catalogFilters.product_group_key = '';
        }
        resetTypeForm();
        await refreshTypeCatalog();
      } catch (error) {
        MessagePlugin.error(errorMessage(error, t('products.catalog.deleteFirstGroupFailed')));
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
    MessagePlugin.error(errorMessage(error, t('products.catalog.loadCategoriesFailed')));
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
    MessagePlugin.success(t('products.catalog.categorySorted'));
    await reloadCategoryScopedProducts();
  } catch (error) {
    MessagePlugin.error(errorMessage(error, t('products.catalog.updateCategorySortFailed')));
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

// ---------- 商品拖拽排序 ----------

interface ProductDragState {
  id: number;
}

const productDragState = ref<ProductDragState | null>(null);
const productSortLoading = ref(false);

// 拖拽排序会改写商品顺序，属于 product.manage 权限范围；
// 无权限用户不应看到排序控件（后端仍会独立校验）。
const userStore = useUserStore();
const canSortProducts = computed(() =>
  hasPermissionInList(userStore.userInfo?.permissions || [], AdminPermissions.PRODUCT_MANAGE),
);

function handleProductDragStart(row: TableRowData, event: DragEvent) {
  if (productSortLoading.value) {
    event.preventDefault();
    return;
  }
  productDragState.value = { id: Number(row.id) };
  if (event.dataTransfer) {
    event.dataTransfer.effectAllowed = 'move';
    event.dataTransfer.setData('text/plain', String(row.id));
  }
}

function handleProductDragOver(targetRow: TableRowData, event: DragEvent) {
  const state = productDragState.value;
  if (!state) return;
  if (Number(targetRow.id) === state.id) return;
  event.preventDefault();
  if (event.dataTransfer) event.dataTransfer.dropEffect = 'move';
}

function handleProductDrop(targetRow: TableRowData, event: DragEvent) {
  event.preventDefault();
  const state = productDragState.value;
  productDragState.value = null;
  if (!state) return;
  if (Number(targetRow.id) === state.id) return;

  const list = [...products.value];
  const fromIndex = list.findIndex((item) => Number(item.id) === state.id);
  const toIndex = list.findIndex((item) => Number(item.id) === Number(targetRow.id));
  if (fromIndex < 0 || toIndex < 0) return;

  const [moved] = list.splice(fromIndex, 1);
  list.splice(toIndex, 0, moved);
  const nextIds = list.map((item) => Number(item.id));
  if (nextIds.join(',') === products.value.map((item) => Number(item.id)).join(',')) return;

  void commitProductSort(nextIds);
}

function handleProductDragEnd() {
  productDragState.value = null;
}

function canMoveProduct(row: TableRowData, direction: 'up' | 'down') {
  const index = products.value.findIndex((item) => Number(item.id) === Number(row.id));
  if (index < 0) return false;
  const target = direction === 'up' ? index - 1 : index + 1;
  return target >= 0 && target < products.value.length;
}

/** 键盘可达的上移/下移，复用拖拽相同的提交逻辑 */
function moveProduct(row: TableRowData, direction: 'up' | 'down') {
  if (productSortLoading.value || !canMoveProduct(row, direction)) return;

  const list = [...products.value];
  const fromIndex = list.findIndex((item) => Number(item.id) === Number(row.id));
  const toIndex = direction === 'up' ? fromIndex - 1 : fromIndex + 1;
  if (fromIndex < 0 || toIndex < 0 || toIndex >= list.length) return;

  const [moved] = list.splice(fromIndex, 1);
  list.splice(toIndex, 0, moved);
  const nextIds = list.map((item) => Number(item.id));
  if (nextIds.join(',') === products.value.map((item) => Number(item.id)).join(',')) return;

  void commitProductSort(nextIds);
}

async function commitProductSort(orderedIds: number[]) {
  productSortLoading.value = true;
  try {
    // 筛选条件必须与 loadProducts 保持一致：后端按筛选后的全量列表切页，
    // 若直接传含 product_group_key 的 catalogFilters（后端不识别该键），
    // 服务端会按未过滤列表算本页位置，导致「当前页没有可排序的商品」。
    const selectedGroup = findProductGroupByKey(categoryOptions.value, catalogFilters.product_group_key);
    await productApi.sortOrder({
      product_ids: orderedIds,
      page: Number(productPage.value || 1),
      page_size: Number(productPageSize.value || 20),
      filters: {
        keyword: catalogFilters.keyword,
        first_product_group_code: String(catalogFilters.product_type || '').trim(),
        status: catalogFilters.status,
        lifecycle_status: catalogFilters.lifecycle_status,
        ...productGroupPayload(selectedGroup),
      },
    });
    MessagePlugin.success(t('products.catalog.productSorted'));
    await loadProducts();
  } catch (error) {
    MessagePlugin.error(errorMessage(error, t('products.catalog.updateProductSortFailed')));
    await loadProducts();
  } finally {
    productSortLoading.value = false;
  }
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
      MessagePlugin.info(t('products.catalog.switchedToAllProducts'));
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
    MessagePlugin.error(errorMessage(error, t('products.catalog.loadProductsFailed')));
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
  goProductEdit(router, row.id);
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
    MessagePlugin.warning(t('products.catalog.selectProductFirst'));
    return;
  }

  productBatchDialogKind.value = kind;
  productBatchDialogTitle.value =
    kind === 'console-template'
      ? t('products.catalog.batchConsoleTitle')
      : kind === 'discount-group'
        ? t('products.catalog.batchDiscountTitle')
        : t('products.catalog.batchCpuTitle');
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
    MessagePlugin.error(errorMessage(error, t('products.catalog.loadDiscountGroupsFailed')));
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
      : t('products.catalog.batchTargetProducts', { count: productBatchTargetProductIds.value.length });
    MessagePlugin.success(
      updatedCount > 0
        ? t('products.catalog.batchUpdated', { count: updatedCount, target: targetLabel })
        : t('products.catalog.batchUnchanged', { target: targetLabel }),
    );
    productBatchDialogVisible.value = false;
    await loadProducts();
  } catch (error) {
    MessagePlugin.error(errorMessage(error, t('products.catalog.batchUpdateFailed')));
  } finally {
    productBatchSubmitting.value = false;
  }
}

function handleBatchDeleteProducts() {
  const rows = selectedProductRows();
  if (!rows.length) {
    MessagePlugin.warning(t('products.catalog.selectProductFirst'));
    return;
  }
  const dialog = DialogPlugin.confirm({
    header: t('products.catalog.batchDeleteTitle'),
    body: t('products.catalog.batchDeleteConfirm', { count: rows.length }),
    theme: 'warning',
    confirmBtn: t('products.catalog.confirmDelete'),
    cancelBtn: t('products.catalog.cancel'),
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
            ? t('products.catalog.batchDeleted', {
                count: deletedCount,
                skipped: failed.length ? t('products.catalog.batchDeleteSkipped', { count: failed.length }) : '',
              })
            : t('products.catalog.batchNothingDeleted', {
                failed: failed.length ? t('products.catalog.batchDeleteFailedCount', { count: failed.length }) : '',
              }),
        );
        dialog.hide();
        selectedProductKeys.value = [];
        await loadProducts();
      } catch (error) {
        MessagePlugin.error(errorMessage(error, t('products.catalog.batchDeleteFailed')));
      }
    },
  });
}

function handleBatchForceDeleteProducts() {
  const rows = selectedProductRows();
  if (!rows.length) {
    MessagePlugin.warning(t('products.catalog.selectProductFirst'));
    return;
  }
  const dialog = DialogPlugin.confirm({
    header: t('products.catalog.batchForceDeleteTitle'),
    body: t('products.catalog.batchForceDeleteConfirm', { count: rows.length }),
    theme: 'danger',
    confirmBtn: t('products.catalog.confirmForceDelete'),
    cancelBtn: t('products.catalog.cancel'),
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
            ? t('products.catalog.batchForceDeleted', {
                count: deletedCount,
                services:
                  deletedServices > 0
                    ? t('products.catalog.batchForceDeletedServices', { count: deletedServices })
                    : '',
              })
            : t('products.catalog.batchForceNothing'),
        );
        dialog.hide();
        selectedProductKeys.value = [];
        await loadProducts();
      } catch (error) {
        MessagePlugin.error(errorMessage(error, t('products.catalog.batchForceDeleteFailed')));
      }
    },
  });
}

function handleForceDeleteCategory(row: ProductCategoryRecord) {
  const level = productGroupLevel(row);
  const dialog = DialogPlugin.confirm({
    header: t('products.catalog.forceDeleteCategoryTitle'),
    body: t('products.catalog.forceDeleteCategoryConfirm', { name: categoryDisplayName(row) }),
    theme: 'danger',
    confirmBtn: t('products.catalog.confirmForceDelete'),
    cancelBtn: t('products.catalog.cancel'),
    async onConfirm() {
      categorySubmitting.value = true;
      try {
        const response = await productApi.forceDeleteCategory(productGroupEffectiveId(row), {
          effective_product_group_level: level,
        });
        const record = toPlainRecord(response);
        MessagePlugin.success(
          t('products.catalog.forceDeleteCategoryResult', {
            products: record.deleted_products || 0,
            services: record.deleted_services || 0,
          }),
        );
        dialog.hide();
        if (catalogFilters.product_group_key === categoryIdKey(row)) {
          catalogFilters.product_group_key = '';
        }
        await reloadCategoryScopedProducts();
      } catch (error) {
        MessagePlugin.error(errorMessage(error, t('products.catalog.forceDeleteCategoryFailed')));
      } finally {
        categorySubmitting.value = false;
      }
    },
  });
}

function openBatchCategoryDialog() {
  const targetRows = selectedProductRows();
  if (!targetRows.length) {
    MessagePlugin.warning(t('products.catalog.selectProductFirst'));
    return;
  }

  batchCategoryTargetKeys.value = targetRows.map((row) => row.id);
  batchCategoryForm.target_product_group_key = '';
  batchCategoryDialogVisible.value = true;
}

async function openSplitProductDialog() {
  const targetRows = selectedProductRows();
  if (!targetRows.length) {
    MessagePlugin.warning(t('products.catalog.selectSplitProductFirst'));
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
    MessagePlugin.error(errorMessage(error, t('products.catalog.loadSplitPreviewFailed')));
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
    MessagePlugin.warning(t('products.catalog.selectProductFirst'));
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
  return group ? productGroupOptionLabel(group) : t('products.catalog.targetCategory');
}

async function submitBatchCategory() {
  const productIds = selectedProductIdsFromKeys(batchCategoryTargetKeys.value);
  const targetGroup = findProductGroupByKey(categoryOptions.value, batchCategoryForm.target_product_group_key);

  if (!productIds.length) {
    MessagePlugin.warning(t('products.catalog.selectProductFirst'));
    return;
  }
  if (!targetGroup) {
    MessagePlugin.warning(t('products.catalog.selectTargetCategory'));
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
      MessagePlugin.success(t('products.catalog.categorized', { count: updatedCount, target: targetLabel }));
    } else {
      MessagePlugin.success(t('products.catalog.alreadyCategorized', { target: targetLabel }));
    }

    batchCategoryDialogVisible.value = false;
    batchCategoryTargetKeys.value = [];
    await reloadCategoryScopedProducts();
  } catch (error) {
    MessagePlugin.error(errorMessage(error, t('products.catalog.categorizeFailed')));
  } finally {
    batchCategorySubmitting.value = false;
  }
}

async function submitSplitProducts() {
  const productIds = selectedProductIdsFromKeys(splitProductTargetKeys.value);
  if (!productIds.length) {
    MessagePlugin.warning(t('products.catalog.selectSplitProductFirst'));
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
      MessagePlugin.success(
        t('products.catalog.splitCreated', {
          created: createdCount,
          updated: updatedCount,
          skipped: skippedCount,
        }),
      );
    } else {
      MessagePlugin.warning(
        skippedCount > 0
          ? t('products.catalog.splitSkipped', { count: skippedCount })
          : t('products.catalog.splitNoMatch'),
      );
    }

    splitProductDialogVisible.value = false;
    splitProductTargetKeys.value = [];
    selectedProductKeys.value = [];
    await reloadCategoryScopedProducts();
  } catch (error) {
    MessagePlugin.error(errorMessage(error, t('products.catalog.splitFailed')));
  } finally {
    splitProductSubmitting.value = false;
  }
}

async function submitProvisionHostname() {
  const productIds = selectedProductIdsFromKeys(provisionHostnameTargetKeys.value);
  if (!productIds.length) {
    MessagePlugin.warning(t('products.catalog.selectProductFirst'));
    return;
  }
  if (provisionHostnameForm.mode !== 'system' && !provisionHostnameForm.value.trim()) {
    MessagePlugin.warning(
      provisionHostnameForm.mode === 'fixed'
        ? t('products.catalog.hostnameFixedRequired')
        : t('products.catalog.hostnamePrefixRequired'),
    );
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
    MessagePlugin.success(t('products.catalog.hostnameRuleUpdated'));
    provisionHostnameDialogVisible.value = false;
    provisionHostnameTargetKeys.value = [];
    selectedProductKeys.value = [];
    await loadProducts();
  } catch (error) {
    MessagePlugin.error(errorMessage(error, t('products.catalog.updateHostnameRuleFailed')));
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
    MessagePlugin.warning(t('products.catalog.monthlyPriceFirst'));
    return;
  }

  productPricingPlan.value = plan.value;
  productForm.quarterly_price = roundPricingAmount(monthly * plan.ratios.quarterly);
  productForm.semiannually_price = roundPricingAmount(monthly * plan.ratios.semiannually);
  productForm.annually_price = roundPricingAmount(monthly * plan.ratios.annually);
  MessagePlugin.success(t('products.catalog.cyclePricesSynced'));
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
    MessagePlugin.warning(t('products.catalog.cyclePriceRequired'));
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
      MessagePlugin.success(t('products.catalog.productUpdated'));
    } else {
      await productApi.create(payload);
      MessagePlugin.success(t('products.catalog.productCreated'));
    }
    productDialogVisible.value = false;
    await loadProducts();
  } catch (error) {
    MessagePlugin.error(errorMessage(error, t('products.catalog.saveProductFailed')));
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
      option_mode: normalizeConfigOptionMode({ ...item, field, name } as ProductConfigOptionRecord),
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
  return options.map((item, index) => {
    const mode = normalizeConfigOptionMode(item);
    return {
      ...item,
      name: String(item.name || item.option_name || item.field || '').trim(),
      option_name: String(item.option_name || item.name || item.field || '').trim(),
      field: String(item.field || '').trim(),
      option_mode: mode,
      // 范围型与提示型字段必须原样回传：range 丢了 qty_maximum 会退化成
      // 0~9999 的滑块，text 丢了 text_content 购买页就没有提示文案。
      qty_minimum: mode === 'range' ? toNumber(item.qty_minimum) : 0,
      qty_maximum: mode === 'range' ? toNumber(item.qty_maximum) : 0,
      qty_step: mode === 'range' ? Math.max(1, toNumber(item.qty_step, 1)) : 1,
      unit: mode === 'range' ? String(item.unit || '').trim() : '',
      submit_field: String(item.submit_field || item.field || '').trim(),
      text_content: mode === 'text' ? String(item.text_content || '').trim() : '',
      parameter: String(item.parameter || '').trim(),
      required: mode === 'text' ? false : Boolean(item.required),
      hidden: Boolean(item.hidden),
      sort_order: Number(item.sort_order || index + 1),
    };
  });
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
    qty_minimum: 0,
    qty_maximum: 0,
    qty_step: 1,
    unit: '',
    default_value: 0,
    submit_field: '',
    text_content: '',
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
    // 传参值取上游真实提交值（value），其次才是 option_name_first。
    // 上游 sub 只有自增 id，option_name_first 在旧数据里等于 id；
    // 若优先取它，本地一保存就会把 sub id 当参数写回 parameter，上游无法识别。
    value: String(item.value ?? item.option_name_first ?? '').trim(),
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
  const mode = String(value || 'select');
  configOptionForm.option_mode = normalizeModeValue(mode);
  if (configOptionForm.option_mode === 'select' && configOptionSubItemRows.value.length === 0) {
    configOptionSubItemRows.value = [createConfigSubItemRow({}, 0)];
  }
}

function normalizeModeValue(mode: string): ConfigOptionEditMode {
  return mode === 'range' || mode === 'text' ? mode : 'select';
}

function openConfigOptionDialog(row?: ProductConfigOptionRecord, index = -1) {
  configOptionEditingIndex.value = index;
  if (row) {
    Object.assign(configOptionForm, {
      name: row.name || row.option_name || '',
      field: row.field || '',
      option_mode: normalizeConfigOptionMode(row),
      parameter: row.parameter || '',
      sub_items_text: formatConfigSubItems(row.sub_items || row.sub || []),
      description: String(row.description || row.option_description || '').trim(),
      suffix_text: String(row.suffix_text || row.suffix || '').trim(),
      qty_minimum: toNumber(row.qty_minimum),
      qty_maximum: toNumber(row.qty_maximum),
      qty_step: Math.max(1, toNumber(row.qty_step ?? row.qty_stage, 1)),
      unit: String(row.unit || row.suffix_text || '').trim(),
      default_value: toNumber(row.default_value),
      submit_field: String(row.submit_field || row.field || '').trim(),
      text_content: String(row.text_content || '').trim(),
      advanced: Boolean(row.advanced ?? true),
      required: Boolean(row.required ?? true),
      hidden: Boolean(row.hidden ?? false),
      sort_order: Number(row.sort_order || index + 1),
    });
    // 文字提示项的文案可能只存在于 parameter / 子项里，载入时兜底回填，
    // 否则打开弹窗是一片空白，管理员不知道上游同步下来的提示是什么。
    if (configOptionForm.option_mode === 'text' && !configOptionForm.text_content) {
      configOptionForm.text_content = resolveTextContentFallback(row);
    }
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
    throw new Error(t('products.catalog.configItemsIncomplete'));
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
  const mode = normalizeModeValue(configOptionForm.option_mode);
  if (!name || !field) {
    MessagePlugin.warning(t('products.catalog.configNameFieldRequired'));
    return;
  }
  let subItems: ReturnType<typeof parseConfigSubItems> = [];
  try {
    subItems = mode === 'select' ? buildConfigSubItemsFromRows() : [];
  } catch (error) {
    MessagePlugin.warning(errorMessage(error, t('products.catalog.checkConfigItems')));
    return;
  }
  const parameter = subItems.length
    ? subItems.map((item) => `${item.value}|${item.label}`).join(',')
    : configOptionForm.parameter.trim();
  if (mode === 'select' && !parameter) {
    MessagePlugin.warning(t('products.catalog.paramValueRequired'));
    return;
  }
  if (mode === 'text' && !configOptionForm.text_content.trim()) {
    MessagePlugin.warning(t('products.catalog.tipContentRequired'));
    return;
  }
  if (mode === 'range' && toNumber(configOptionForm.qty_maximum) <= 0) {
    MessagePlugin.warning(t('products.catalog.maxValueRequired'));
    return;
  }

  configOptionSubmitting.value = true;
  const qtyStep = Math.max(1, toNumber(configOptionForm.qty_step, 1));
  const payload: ProductConfigOptionRecord = {
    uid: `${field}-${Date.now()}`,
    source: 'manual',
    field,
    name,
    option_name: name,
    option_mode: mode,
    // option_type 必须存下游真正认的类型值，不能自造字符串。
    // 计价层、展示层、购买页都按 (int) 比对 [4,7,9,11,14,15,16,17,18,19]，
    // 而 (int)'quantity' 是 0：新建的数量范围项在购买页会被判成非数量项、
    // 渲染成没有子项的单选，用户根本选不了数量。
    // 取值沿用官方映射（ZProductService::mapConfigOptionType）：
    // 4=quantity 数量型、1=dropdown 选择型；text 存 0（靠 option_mode 判定，
    // 填 5 会被上游 isOsConfigItem() 误认成操作系统项）。
    option_type: mode === 'range' ? 4 : mode === 'text' ? 0 : 1,
    parameter,
    sub: subItems,
    sub_items: subItems,
    range_pricing: [],
    description: configOptionForm.description.trim(),
    suffix_text: configOptionForm.suffix_text.trim(),
    qty_minimum: mode === 'range' ? toNumber(configOptionForm.qty_minimum) : 0,
    qty_maximum: mode === 'range' ? toNumber(configOptionForm.qty_maximum) : 0,
    qty_step: mode === 'range' ? qtyStep : 1,
    qty_stage: mode === 'range' ? qtyStep : 1,
    unit: mode === 'range' ? configOptionForm.unit.trim() : '',
    default_value: mode === 'range' ? toNumber(configOptionForm.default_value) : '',
    submit_field: configOptionForm.submit_field.trim() || field,
    text_content: mode === 'text' ? configOptionForm.text_content.trim() : '',
    advanced: Boolean(configOptionForm.advanced),
    required: mode === 'text' ? false : Boolean(configOptionForm.required),
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

/**
 * 判定配置项是否区间型（滑块/数量）。
 *
 * 数值集合对齐后端与购买页；额外兼容 option_type='quantity' 这种历史字符串写法
 * （Number('quantity') 是 NaN，落在集合外），避免存量数据被降级成单选。
 */
function isRangeOptionType(value: unknown): boolean {
  if (
    String(value ?? '')
      .trim()
      .toLowerCase() === 'quantity'
  ) {
    return true;
  }
  return RANGE_OPTION_TYPES.includes(Number(value));
}

/**
 * 配置项呈现类型归一化。
 *
 * 上游同步的 range 项带 option_mode='range'，option_type 落在区间型数值集合
 * [4,7,9,11,14,15,16,17,18,19] 里；历史数据里还存在 option_type='quantity'
 * 这种字符串写法。两者都要识别成 range，否则数据范围会被当成单选子项编辑。
 * 提示项（text）优先级最高：它常与真实规格项共用 field，不能被类型推断覆盖。
 */
function normalizeConfigOptionMode(row: ProductConfigOptionRecord): ConfigOptionEditMode {
  const mode = String(row.option_mode || '').trim();
  if (mode === 'text' || mode === 'range' || mode === 'select') {
    return mode;
  }
  if (row.text_content) {
    return 'text';
  }
  if (isRangeOptionType(row.option_type) || toNumber(row.qty_maximum) > 0) {
    return 'range';
  }
  return 'select';
}

function resolveTextContentFallback(row: ProductConfigOptionRecord): string {
  const sourceSubItems = Array.isArray(row.sub_items) && row.sub_items.length ? row.sub_items : row.sub;
  const firstSub = Array.isArray(sourceSubItems) ? toPlainRecord(sourceSubItems[0]) : {};
  const fromSub = String(firstSub.option_name || firstSub.label || firstSub.version || '').trim();
  if (fromSub) {
    return fromSub;
  }
  const firstPair = String(row.parameter || '').split(',')[0] || '';
  const parts = firstPair.split('|');
  return String(parts[1] ?? parts[0] ?? '').trim();
}

function toNumber(value: unknown, fallback = 0): number {
  const parsed = Number(value);
  return Number.isFinite(parsed) ? parsed : fallback;
}

/**
 * 配置项列表的类型标签。
 *
 * 直接显示 option_mode 会出现「select」这种内部值，且 range 项看不出滑块范围，
 * 提示项也看不出是段购买须知文案，这里翻译成人能读懂的说明。
 */
function configOptionModeLabel(item: ProductConfigOptionRecord): string {
  const mode = normalizeConfigOptionMode(item);
  if (mode === 'text') {
    return t('products.catalog.optionModeTextHint');
  }
  if (mode === 'range') {
    const min = toNumber(item.qty_minimum);
    const max = toNumber(item.qty_maximum);
    const unit = String(item.unit || '').trim();
    const range = max > 0 ? `${min}~${max}${unit}` : t('products.catalog.rangePending');
    return t('products.catalog.rangeLabel', { range });
  }
  return t('products.catalog.optionModeSingle');
}

async function pullProductConfigTemplate() {
  const supplierId = productForm.supplier_id;
  const upstreamProductId = productForm.upstream_product_id;
  if (!supplierId || !upstreamProductId) {
    MessagePlugin.warning(t('products.catalog.providerProductRequired'));
    return;
  }

  configTemplateLoading.value = true;
  try {
    const response = await supplierApi.productConfigTemplate(supplierId, upstreamProductId);
    const options = Array.isArray(response.config_options) ? response.config_options : [];
    productForm.config_options = normalizeConfigOptions(options);
    MessagePlugin.success(t('products.catalog.templatePulled'));
  } catch (error) {
    MessagePlugin.error(errorMessage(error, t('products.catalog.pullTemplateFailed')));
  } finally {
    configTemplateLoading.value = false;
  }
}

async function handleToggleProduct(row: ProductRecord) {
  const newStatus = Number(row.status) !== 1;
  const productLabel = row.display_name || row.name || row.id;
  const dialog = DialogPlugin.confirm({
    header: newStatus ? t('products.catalog.toggleShowTitle') : t('products.catalog.toggleHideTitle'),
    body: newStatus
      ? t('products.catalog.toggleShowConfirm', { name: productLabel })
      : t('products.catalog.toggleHideConfirm', { name: productLabel }),
    confirmBtn: newStatus ? t('products.catalog.confirmShow') : t('products.catalog.confirmHide'),
    cancelBtn: t('products.catalog.cancel'),
    async onConfirm() {
      productActionLoading.value = row.id;
      try {
        await productApi.toggleStatus(row.id, newStatus);
        MessagePlugin.success({
          content: newStatus ? t('products.catalog.productShown') : t('products.catalog.productHidden'),
          duration: 3000,
        });
        dialog.hide();
        await loadProducts();
      } catch (error) {
        MessagePlugin.error(errorMessage(error, t('products.catalog.updateStatusFailed')));
      } finally {
        productActionLoading.value = null;
      }
    },
  });
}

function handleDeleteProduct(row: ProductRecord) {
  const dialog = DialogPlugin.confirm({
    header: t('products.catalog.deleteProductTitle'),
    body: t('products.catalog.deleteProductConfirm', {
      name: row.display_name || row.name || row.id,
      services:
        Number(row.active_services_count ?? row.services_count ?? 0) > 0
          ? t('products.catalog.deleteProductServices', {
              count: row.active_services_count ?? row.services_count,
            })
          : '',
    }),
    theme: 'warning',
    confirmBtn: t('products.catalog.confirmDelete'),
    cancelBtn: t('products.catalog.cancel'),
    async onConfirm() {
      productActionLoading.value = row.id;
      try {
        await productApi.delete(row.id);
        MessagePlugin.success({ content: t('products.catalog.productDeleted'), duration: 3000 });
        dialog.hide();
        if (catalogFilters.lifecycle_status === 'all') {
          MessagePlugin.info(t('products.catalog.deletedProductsVisibleTip'));
        }
        await loadProducts();
      } catch (error) {
        MessagePlugin.error(errorMessage(error, t('products.catalog.deleteProductFailed')));
      } finally {
        productActionLoading.value = null;
      }
    },
  });
}

function handleRestoreProduct(row: ProductRecord) {
  const dialog = DialogPlugin.confirm({
    header: t('products.catalog.restoreProductTitle'),
    body: t('products.catalog.restoreProductConfirm', { name: row.display_name || row.name || row.id }),
    confirmBtn: t('products.catalog.confirmRestore'),
    cancelBtn: t('products.catalog.cancel'),
    async onConfirm() {
      productActionLoading.value = `restore:${row.id}`;
      try {
        await productApi.restore(row.id);
        MessagePlugin.success(t('products.catalog.productRestored'));
        dialog.hide();
        await loadProducts();
      } catch (error) {
        MessagePlugin.error(errorMessage(error, t('products.catalog.restoreProductFailed')));
      } finally {
        productActionLoading.value = null;
      }
    },
  });
}

function handleForceDeleteProduct(row: ProductRecord) {
  const dialog = DialogPlugin.confirm({
    header: t('products.catalog.purgeProductTitle'),
    body: t('products.catalog.purgeProductConfirm', { name: row.display_name || row.name || row.id }),
    theme: 'warning',
    confirmBtn: t('products.catalog.confirmPurge'),
    cancelBtn: t('products.catalog.cancel'),
    async onConfirm() {
      productActionLoading.value = `force:${row.id}`;
      try {
        await productApi.forceDelete(row.id);
        MessagePlugin.success(t('products.catalog.productPurged'));
        dialog.hide();
        await loadProducts();
      } catch (error) {
        MessagePlugin.error(errorMessage(error, t('products.catalog.purgeProductFailed')));
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
    // 编辑三级分组：确保父级下拉按当前一级分组加载（分类树只含当前筛选的一级分组）
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
      MessagePlugin.warning(t('products.catalog.productTypeNotSynced'));
      return;
    }
    if (targetLevel === 3 && !selectedParent) {
      MessagePlugin.warning(t('products.catalog.selectSecondCategoryParent'));
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
      MessagePlugin.success(t('products.catalog.categoryUpdated'));
    } else {
      await productApi.createCategory(payload);
      MessagePlugin.success(t('products.catalog.categoryCreated'));
    }
    categoryDialogVisible.value = false;
    await reloadCategoryScopedProducts();
  } catch (error) {
    MessagePlugin.error(errorMessage(error, t('products.catalog.saveCategoryFailed')));
  } finally {
    categorySubmitting.value = false;
  }
}

function handleDeleteCategory(row: ProductCategoryRecord) {
  const dialog = DialogPlugin.confirm({
    header: t('products.catalog.deleteCategoryTitle'),
    body: t('products.catalog.deleteNamedConfirm', { name: row.name || row.label || row.id }),
    theme: 'warning',
    confirmBtn: t('products.catalog.confirmDelete'),
    cancelBtn: t('products.catalog.cancel'),
    async onConfirm() {
      categorySubmitting.value = true;
      try {
        await productApi.deleteCategory(productGroupEffectiveId(row), {
          effective_product_group_level: productGroupLevel(row),
        });
        MessagePlugin.success(t('products.catalog.categoryDeleted'));
        dialog.hide();
        if (catalogFilters.product_group_key === categoryIdKey(row)) {
          catalogFilters.product_group_key = '';
        }
        await reloadCategoryScopedProducts();
      } catch (error) {
        MessagePlugin.error(errorMessage(error, t('products.catalog.deleteCategoryFailed')));
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
    MessagePlugin.warning(t('products.catalog.interfaceIncomplete'));
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
          name: t('products.catalog.boundProduct', { id: productForm.upstream_product_id }),
          type_label: t('products.catalog.currentBinding'),
          remote_group_name: '',
          is_connected: true,
          connected_display_name: '',
        });
      }
    }
    if (notify) MessagePlugin.success(t('products.catalog.upstreamSynced'));
  } catch (error) {
    supplierProductOptions.value = [];
    MessagePlugin.error(errorMessage(error, t('products.catalog.syncUpstreamFailed')));
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
  return action === 'update' ? t('products.catalog.actionUpdate') : t('products.catalog.actionCreate');
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
