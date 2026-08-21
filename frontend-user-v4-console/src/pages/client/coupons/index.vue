<template>
  <section class="coupon-page">
    <header class="client-page-heading">
      <h1>优惠券中心</h1>
    </header>

    <t-card class="coupon-card-shell" :bordered="false">
      <div class="coupon-filter-bar">
        <t-input
          v-model="currentState.keyword"
          clearable
          :placeholder="activeTab === 'plaza' ? '搜索可领取的优惠券' : '搜索优惠券名称'"
          @enter="handleSearch(activeTab)"
          @clear="handleSearch(activeTab)"
        >
          <template #suffixIcon><search-icon /></template>
        </t-input>
        <t-select v-model="currentState.status" clearable placeholder="全部状态" @change="handleSearch(activeTab)">
          <t-option v-for="item in currentStatusOptions" :key="item.value" :label="item.label" :value="item.value" />
        </t-select>
      </div>
    </t-card>

    <div class="coupon-tabs" role="tablist" aria-label="优惠券分类">
      <t-button :theme="activeTab === 'owned' ? 'primary' : 'default'" @click="switchTab('owned')"
        >我拥有的优惠券</t-button
      >
      <t-button :theme="activeTab === 'plaza' ? 'primary' : 'default'" @click="switchTab('plaza')">优惠券广场</t-button>
    </div>

    <template v-if="activeTab === 'owned'">
      <div class="coupon-list-shell">
        <data-state :loading="ownedState.loading" :empty="!ownedState.list.length" description="你还没有优惠券">
          <div class="coupon-grid">
            <article v-for="item in ownedState.list" :key="item.id" class="coupon-item">
              <button class="coupon-item__detail" type="button" @click="openCouponDetail(item)">详情</button>
              <div class="coupon-item__head">
                <div class="coupon-item__value">
                  <span>{{ resolveDiscountTypeLabel(item.discount_type) }}</span>
                  <strong>{{ resolveDiscountValue(item) }}</strong>
                </div>
                <t-tag :theme="resolveStatusTheme(item)" variant="light">
                  {{ item.status_label || item.status || '--' }}
                </t-tag>
              </div>
              <div class="coupon-item__body">
                <strong>{{ item.name || '优惠券' }}</strong>
                <p>{{ item.description || item.status_reason || '满足条件后可在结算时直接抵扣' }}</p>
                <div class="coupon-item__chips">
                  <span>{{ resolveThresholdText(item) }}</span>
                  <span>{{ resolveDiscountAmountText(item) }}</span>
                  <span v-if="item.first_order_only">限首单</span>
                  <span v-if="item.per_user_limit"
                    >可使用 {{ item.remaining_times ?? 0 }}/{{ item.per_user_limit }} 次</span
                  >
                  <span v-else>不限次</span>
                </div>
              </div>
              <div class="coupon-item__foot">
                <span>{{ item.validity_text || item.expires_at || '--' }}</span>
              </div>
            </article>
          </div>
        </data-state>
      </div>
      <div v-if="ownedState.total > 0" class="coupon-pagination">
        <t-pagination
          v-model="ownedState.page"
          v-model:page-size="ownedState.pageSize"
          :total="ownedState.total"
          :page-size-options="[10, 20, 50]"
          show-total
          @change="handlePageChange('owned')"
          @page-size-change="handlePageSizeChange('owned')"
        />
      </div>
    </template>

    <template v-else>
      <div class="coupon-list-shell">
        <data-state :loading="plazaState.loading" :empty="!plazaState.list.length" description="当前暂无可领取的优惠券">
          <div class="coupon-grid">
            <article v-for="item in plazaState.list" :key="item.id" class="coupon-item">
              <button class="coupon-item__detail" type="button" @click="openCouponDetail(item)">详情</button>
              <div class="coupon-item__head">
                <div class="coupon-item__value">
                  <span>{{ resolveDiscountTypeLabel(item.discount_type) }}</span>
                  <strong>{{ resolveDiscountValue(item) }}</strong>
                </div>
                <t-tag :theme="resolveStatusTheme(item)" variant="light">
                  {{ item.status_label || item.status || '--' }}
                </t-tag>
              </div>
              <div class="coupon-item__body">
                <strong>{{ item.name || '优惠券' }}</strong>
                <p>{{ item.description || item.status_reason || '领取后可在结算时使用' }}</p>
                <div class="coupon-item__chips">
                  <span>{{ resolveThresholdText(item) }}</span>
                  <span>{{ resolveDiscountAmountText(item) }}</span>
                  <span v-if="item.first_order_only">限首单</span>
                  <span v-if="item.total_usage_limit"
                    >剩余 {{ item.remaining_stock }}/{{ item.total_usage_limit }} 张</span
                  >
                </div>
              </div>
              <div class="coupon-item__foot">
                <span>{{ item.validity_text || item.expires_at || '--' }}</span>
                <t-button
                  size="small"
                  theme="primary"
                  :disabled="!item.can_claim"
                  :loading="claimingId === item.id"
                  @click="claimCoupon(item.id)"
                >
                  {{ item.can_claim ? '领取' : item.status_label || '不可领' }}
                </t-button>
              </div>
            </article>
          </div>
        </data-state>
      </div>
      <div v-if="plazaState.total > 0" class="coupon-pagination">
        <t-pagination
          v-model="plazaState.page"
          v-model:page-size="plazaState.pageSize"
          :total="plazaState.total"
          :page-size-options="[10, 20, 50]"
          show-total
          @change="handlePageChange('plaza')"
          @page-size-change="handlePageSizeChange('plaza')"
        />
      </div>
    </template>

    <t-drawer
      v-model:visible="detailVisible"
      header="优惠券详情"
      size="min(42rem, calc(100vw - 2rem))"
      destroy-on-close
    >
      <div v-if="selectedCoupon" class="coupon-detail">
        <section v-if="selectedCoupon.uid" class="coupon-detail-section">
          <div class="coupon-detail-section__head">
            <h3>券实例编号</h3>
            <span>{{ selectedCoupon.status_label || '--' }}</span>
          </div>
          <div class="coupon-detail-section__body">
            <div class="coupon-product-empty-grid">
              <span>实例编号</span>
              <strong>{{ selectedCoupon.uid }}</strong>
            </div>
          </div>
        </section>
        <section class="coupon-detail-section">
          <div class="coupon-detail-section__head">
            <h3>适用产品</h3>
          </div>
          <div class="coupon-detail-section__body">
            <div v-if="mergedCouponProductHierarchy.length" class="coupon-hierarchy-sheet">
              <table class="coupon-hierarchy-table">
                <tbody>
                  <tr v-for="item in mergedCouponProductHierarchy" :key="item.productId">
                    <td v-if="item.level1Rowspan" :rowspan="item.level1Rowspan">{{ item.level1 || '--' }}</td>
                    <td v-if="item.level2Rowspan" :rowspan="item.level2Rowspan">{{ item.level2 || '--' }}</td>
                    <td v-if="item.level3Rowspan" :rowspan="item.level3Rowspan">{{ item.level3 || '--' }}</td>
                    <td>{{ item.productName || '--' }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
            <div v-else class="coupon-product-empty-grid">
              <span>适用产品</span>
              <strong>{{ selectedCoupon.product_scope_text || '全场通用' }}</strong>
            </div>
          </div>
        </section>
      </div>
    </t-drawer>
  </section>
</template>
<script setup lang="ts">
import DataState from '@shared/user-v3/components/DataState.vue';
import { SearchIcon } from 'tdesign-icons-vue-next';
import { computed } from 'vue';

import {
  resolveDiscountAmountText,
  resolveDiscountTypeLabel,
  resolveDiscountValue,
  resolveStatusTheme,
  resolveThresholdText,
  useCoupons,
} from '@/domains/marketing/useCoupons';
import type { CouponProductScopeItem } from '@/types/client';

const {
  activeTab,
  claimingId,
  detailVisible,
  selectedCoupon,
  ownedState,
  plazaState,
  currentState,
  currentStatusOptions,
  handleSearch,
  handlePageChange,
  handlePageSizeChange,
  switchTab,
  openCouponDetail,
  claimCoupon,
} = useCoupons();

interface CouponHierarchyItem {
  productId: number;
  level1: string;
  level2: string;
  level3: string;
  productName: string;
}

type MergedCouponHierarchyItem = CouponHierarchyItem & {
  index: number;
  level1Rowspan: number;
  level2Rowspan: number;
  level3Rowspan: number;
};

const couponProductHierarchy = computed(() => {
  const products = Array.isArray(selectedCoupon.value?.products) ? selectedCoupon.value.products : [];

  return products
    .map((item: CouponProductScopeItem): CouponHierarchyItem => ({
      productId: Number(item?.id || 0),
      level1: String(item?.type_label || item?.service_type_label || '--').trim() || '--',
      level2: String(item?.parent_group_name || item?.second_product_group_name || '--').trim() || '--',
      level3: String(item?.group_name || item?.third_product_group_name || '--').trim() || '--',
      productName: String(item?.name || item?.product_name || '--').trim() || '--',
    }))
    .filter((item: CouponHierarchyItem) => item.productId > 0 || item.productName !== '--');
});

const mergedCouponProductHierarchy = computed(() => mergeCouponProductHierarchy(couponProductHierarchy.value));

function mergeCouponProductHierarchy(data: CouponHierarchyItem[]): MergedCouponHierarchyItem[] {
  if (!data.length) return [];

  const sorted = [...data].sort((left, right) => {
    const level1 = String(left.level1 || '').localeCompare(String(right.level1 || ''), 'zh-CN');
    if (level1 !== 0) return level1;
    const level2 = String(left.level2 || '').localeCompare(String(right.level2 || ''), 'zh-CN');
    if (level2 !== 0) return level2;
    const level3 = String(left.level3 || '').localeCompare(String(right.level3 || ''), 'zh-CN');
    if (level3 !== 0) return level3;
    return String(left.productName || '').localeCompare(String(right.productName || ''), 'zh-CN');
  });

  const result: MergedCouponHierarchyItem[] = sorted.map((item, index) => ({
    ...item,
    index,
    level1Rowspan: 0,
    level2Rowspan: 0,
    level3Rowspan: 0,
  }));

  let i = 0;
  while (i < result.length) {
    let j = i + 1;
    while (j < result.length && result[j].level1 === result[i].level1) j += 1;
    result[i].level1Rowspan = j - i;
    i = j;
  }

  i = 0;
  while (i < result.length) {
    const level1End = i + result[i].level1Rowspan;
    let j = i;
    while (j < level1End) {
      let k = j + 1;
      while (k < level1End && result[k].level2 === result[j].level2) k += 1;
      result[j].level2Rowspan = k - j;
      j = k;
    }
    i = level1End;
  }

  i = 0;
  while (i < result.length) {
    const level2End = i + result[i].level2Rowspan;
    let j = i;
    while (j < level2End) {
      let k = j + 1;
      while (k < level2End && result[k].level3 === result[j].level3) k += 1;
      result[j].level3Rowspan = k - j;
      j = k;
    }
    i = level2End;
  }

  return result;
}
</script>
<style scoped lang="less">
.coupon-page {
  display: flex;
  flex-direction: column;
  gap: var(--td-comp-margin-m);
  // padding 由 Starter 布局层统一提供
}

.coupon-card-shell {
  background: var(--td-bg-color-container);
  border: thin solid var(--td-border-color);
  border-radius: var(--td-radius-medium);
  box-shadow: var(--td-shadow-1);
}

.client-page-heading {
  h1 {
    margin: 0;
    color: var(--td-text-color-primary);
    font: var(--td-font-title-large);
  }
}

.coupon-filter-bar {
  display: grid;
  grid-template-columns: minmax(16rem, 1fr) minmax(10rem, 16rem);
  gap: var(--td-comp-margin-s);
}

.coupon-list-shell {
  min-height: 14rem;
}

.coupon-tabs {
  display: flex;
  flex-wrap: wrap;
  gap: var(--td-comp-margin-s);
  width: fit-content;
}

.coupon-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(18rem, 1fr));
  gap: var(--td-comp-margin-m);
}

.coupon-item {
  position: relative;
  display: flex;
  flex-direction: column;
  gap: var(--td-comp-margin-m);
  padding: var(--td-comp-paddingTB-l) var(--td-comp-paddingLR-l);
  background: var(--td-bg-color-container);
  border: thin solid var(--td-border-color);
  border-radius: var(--td-radius-medium);
  box-shadow: var(--td-shadow-1);
}

.coupon-item__detail {
  position: absolute;
  top: var(--td-comp-margin-s);
  right: var(--td-comp-margin-s);
  color: var(--td-brand-color);
  background: transparent;
  border: 0;
  cursor: pointer;
  font: var(--td-font-body-small);
}

.coupon-item__head,
.coupon-item__foot {
  display: flex;
  gap: var(--td-comp-margin-s);
  align-items: center;
  justify-content: space-between;
}

.coupon-item__value {
  display: flex;
  flex-direction: column;
  gap: var(--td-comp-margin-xxs);

  span {
    color: var(--td-brand-color);
    font: var(--td-font-body-small);
    font-weight: 600;
  }

  strong {
    color: var(--td-text-color-primary);
    font: var(--td-font-headline-medium);
  }
}

.coupon-item__body {
  display: flex;
  flex-direction: column;
  gap: var(--td-comp-margin-s);
  min-width: 0;

  strong {
    overflow: hidden;
    color: var(--td-text-color-primary);
    font: var(--td-font-body-medium);
    font-weight: 600;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  p {
    margin: 0;
    overflow: hidden;
    color: var(--td-text-color-secondary);
    font: var(--td-font-body-small);
    text-overflow: ellipsis;
    white-space: nowrap;
  }
}

.coupon-item__chips {
  display: flex;
  flex-wrap: wrap;
  gap: var(--td-comp-margin-xs);

  span {
    padding: var(--td-comp-paddingTB-xxs) var(--td-comp-paddingLR-s);
    color: var(--td-text-color-secondary);
    background: var(--td-bg-color-component);
    border-radius: var(--td-radius-small);
    font: var(--td-font-body-small);
  }
}

.coupon-item__foot {
  margin-top: auto;
  padding-top: var(--td-comp-margin-s);
  color: var(--td-text-color-secondary);
  border-top: thin solid var(--td-border-color);
  font: var(--td-font-body-small);
}

.coupon-pagination {
  display: flex;
  justify-content: flex-end;
  margin-top: var(--td-comp-margin-m);
}

.coupon-detail {
  display: grid;
  gap: var(--td-comp-margin-m);
}

.coupon-detail-section {
  display: grid;
  gap: var(--td-comp-margin-s);
  padding: var(--td-comp-paddingTB-l) var(--td-comp-paddingLR-l);
  background: var(--td-bg-color-container);
  border: thin solid var(--td-border-color);
  border-radius: var(--td-radius-large);
}

.coupon-detail-section__head {
  display: flex;
  gap: var(--td-comp-margin-s);
  align-items: center;
  justify-content: space-between;
  min-width: 0;

  h3 {
    margin: 0;
    color: var(--td-text-color-primary);
    font: var(--td-font-title-medium);
  }

  span {
    overflow: hidden;
    max-width: 60%;
    color: var(--td-text-color-secondary);
    font: var(--td-font-body-small);
    text-align: right;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
}

.coupon-detail-section__body {
  display: grid;
  gap: var(--td-comp-margin-s);
}

.coupon-product-empty-grid {
  display: grid;
  grid-template-columns: minmax(7rem, 9rem) minmax(0, 1fr);
  overflow: hidden;
  min-height: 3rem;
  border: 0.0625rem solid var(--td-component-border);
  border-radius: var(--td-radius-medium);

  span,
  strong {
    display: flex;
    align-items: center;
    min-width: 0;
    padding: var(--td-comp-paddingTB-s) var(--td-comp-paddingLR-m);
    font: var(--td-font-body-small);
  }

  span {
    color: var(--td-text-color-secondary);
    background: var(--td-bg-color-component);
    border-right: 0.0625rem solid var(--td-component-border);
  }

  strong {
    color: var(--td-text-color-primary);
    font-weight: 600;
  }
}

.coupon-hierarchy-sheet {
  overflow: hidden;
  border: 0.0625rem solid var(--td-component-border);
  border-radius: var(--td-radius-medium);
  background: var(--td-bg-color-container);
}

.coupon-hierarchy-table {
  width: 100%;
  border-collapse: collapse;
  table-layout: fixed;

  td {
    padding: var(--td-comp-paddingTB-s) var(--td-comp-paddingLR-m);
    border-right: 0.0625rem solid var(--td-component-border);
    border-bottom: 0.0625rem solid var(--td-component-border);
    color: var(--td-text-color-primary);
    font: var(--td-font-body-small);
    line-height: 1.5;
    text-align: left;
    vertical-align: middle;
    overflow-wrap: anywhere;
    background: var(--td-bg-color-container);
  }

  td[rowspan] {
    font-weight: 600;
    background: var(--td-bg-color-container);
  }

  td:last-child {
    border-right: 0;
  }

  tbody tr:last-child td {
    border-bottom: 0;
  }
}

@media (max-width: @screen-sm-rem) {
  .coupon-page {
    padding: var(--td-comp-paddingTB-l) var(--td-comp-paddingLR-l);
  }

  .coupon-card-shell :deep(.t-card__body) {
    padding: var(--td-comp-paddingTB-l) var(--td-comp-paddingLR-l);
  }

  .coupon-filter-bar,
  .coupon-grid {
    grid-template-columns: 1fr;
  }

  .coupon-pagination {
    justify-content: flex-start;
    overflow-x: auto;
  }

  .coupon-product-empty-grid {
    grid-template-columns: 6rem minmax(0, 1fr);
  }

  .coupon-detail-section__head {
    align-items: flex-start;
    flex-direction: column;

    span {
      max-width: 100%;
      text-align: left;
      white-space: normal;
    }
  }

  .coupon-hierarchy-sheet {
    overflow-x: auto;
  }

  .coupon-hierarchy-table {
    min-width: 28rem;
  }
}
</style>
