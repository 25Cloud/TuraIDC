<template>
  <section class="client-services">
    <t-card class="service-filter-card" :bordered="false">
      <div class="service-filter-bar">
        <t-input
          v-model="filters.keyword"
          clearable
          class="service-filter-bar__search"
          placeholder="搜索服务名称、域名、账单号"
          @enter="handleSearch"
          @clear="handleSearch"
        >
          <template #suffixIcon>
            <search-icon />
          </template>
        </t-input>

        <t-select v-model="filters.catalog_type" clearable placeholder="服务分类" @change="handleSearch">
          <t-option label="全部分类" value="" />
          <t-option
            v-for="item in catalogTypeOptions"
            :key="item.value"
            :label="`${item.label} (${item.count})`"
            :value="item.value"
          />
        </t-select>

        <t-select v-model="filters.status" clearable placeholder="状态分类" @change="handleSearch">
          <t-option v-for="item in statusOptions" :key="item.value" :label="item.label" :value="item.value" />
        </t-select>

        <t-select v-model="filters.auto_renew" clearable placeholder="续费方式" @change="handleSearch">
          <t-option label="全部" value="" />
          <t-option label="自动续费" value="1" />
          <t-option label="未开启自动续费" value="0" />
        </t-select>

        <div class="service-filter-actions">
          <t-button
            variant="outline"
            class="service-view-toggle"
            @click="setViewMode(viewMode === 'grid' ? 'list' : 'grid')"
          >
            <template #icon>
              <catalog-icon v-if="viewMode === 'grid'" />
              <dashboard-icon v-else />
            </template>
            {{ viewMode === 'grid' ? '列表' : '卡片' }}
          </t-button>
        </div>
      </div>
    </t-card>

    <section class="service-list-section">
      <data-state :loading="loading" :empty="!list.length" description="当前还没有任何服务实例">
        <template #default>
          <div v-if="viewMode === 'grid'" class="service-card-grid">
            <article v-for="item in list" :key="item.id" class="service-row-card">
              <div class="service-row-actions service-row-actions--corner">
                <button
                  type="button"
                  class="service-action-console"
                  :aria-label="`${resolveServiceName(item)} 控制台`"
                  @click="openDetail(item.id)"
                >
                  控制台
                </button>
                <t-dropdown
                  trigger="click"
                  :options="actionOptions(item)"
                  @click="({ value }: { value: string | number }) => handleServiceAction(String(value), item)"
                >
                  <button
                    type="button"
                    class="service-action-more"
                    :aria-label="`${resolveServiceName(item)} 更多操作`"
                  >
                    更多
                  </button>
                </t-dropdown>
              </div>
              <div class="service-row-head">
                <div class="service-system-icon" :class="{ 'is-provisioning': isProvisioningService(item) }">
                  <span v-if="isProvisioningService(item)" class="service-system-loader" aria-hidden="true">
                    <span class="service-system-loader__ring"></span>
                    <span class="service-system-loader__core"></span>
                  </span>
                  <img
                    v-else-if="shouldShowServiceOsIcon(item)"
                    :src="resolveServiceOsIcon(item)"
                    :alt="String(resolveServiceOsText(item) || resolveServiceName(item))"
                    class="service-system-icon__image"
                    @error="markServiceOsIconFailed(item)"
                  />
                  <span v-else class="service-system-icon__fallback">{{ resolveServiceMark(item) }}</span>
                </div>

                <div class="service-row-body">
                  <div class="service-row-body-top">
                    <div class="service-row-topline">
                      <div class="service-row-titleblock">
                        <div class="service-title-row">
                          <button type="button" class="service-name-button" @click="openDetail(item.id)">
                            {{ resolveServiceName(item) }}
                          </button>
                          <span class="service-row-id">ID {{ item.id }}</span>
                        </div>

                        <div class="service-remark-line">
                          <span
                            class="service-remark-text"
                            :class="{ empty: !item.remark }"
                            :title="item.remark || '添加备注'"
                          >
                            {{ item.remark || '添加备注' }}
                          </span>
                          <button
                            type="button"
                            class="service-remark-trigger"
                            :aria-label="item.remark ? '编辑备注' : '添加备注'"
                            @click="openRemark(item)"
                          >
                            <edit-icon />
                          </button>
                        </div>
                      </div>
                    </div>

                    <div class="service-spec-line">
                      <span>CPU {{ findListSpecValue(item, ['CPU', '核心']) }}</span>
                      <span>内存 {{ findListSpecValue(item, ['内存', 'RAM']) }}</span>
                      <span>带宽 {{ resolveListBandwidthText(item) }}</span>
                    </div>

                    <div class="service-expire-line" :class="{ warning: isExpiringSoon(item.expires_at) }">
                      到期时间：{{ item.expires_at || '长期有效' }}
                    </div>
                  </div>

                  <div class="service-row-foot">
                    <t-tag :theme="resolveTdesignStatusTheme(item)" variant="light" class="service-status-tag">
                      {{ resolveRuntimeStatusLabel(item) }}
                    </t-tag>

                    <div class="service-ip-line">
                      <span class="service-ip-label">公网 IP</span>
                      <button
                        type="button"
                        class="service-ip-button"
                        :class="{ disabled: !(item.upstream?.dedicated_ip && item.upstream.dedicated_ip !== '--') }"
                        :disabled="!(item.upstream?.dedicated_ip && item.upstream.dedicated_ip !== '--')"
                        :title="item.upstream?.dedicated_ip ? `点击复制 ${item.upstream.dedicated_ip}` : '暂无公网 IP'"
                        @click="copyText(item.upstream?.dedicated_ip || '')"
                      >
                        {{ item.upstream?.dedicated_ip || '--' }}
                      </button>
                    </div>
                  </div>
                </div>
              </div>
              <div v-if="Number(item.auto_renew) === 1" class="service-auto-renew-badge">
                <span class="service-auto-renew-badge__text">自</span>
              </div>
            </article>
          </div>

          <div v-else class="service-table-card">
            <t-table row-key="id" :data="list" :columns="columns" :pagination="null" hover>
              <template #service="{ row }">
                <div class="service-table-service">
                  <div class="service-system-icon">
                    <img
                      v-if="shouldShowServiceOsIcon(row)"
                      :src="resolveServiceOsIcon(row)"
                      :alt="String(resolveServiceOsText(row) || resolveServiceName(row))"
                      class="service-system-icon__image"
                      @error="markServiceOsIconFailed(row)"
                    />
                    <span v-else class="service-system-icon__fallback">{{ resolveServiceMark(row) }}</span>
                  </div>
                  <div class="service-table-copy">
                    <div class="service-table-title-row">
                      <t-button theme="primary" variant="text" class="service-name-button" @click="openDetail(row.id)">
                        {{ resolveServiceName(row) }}
                      </t-button>
                      <span v-if="Number(row.auto_renew) === 1" class="service-table-auto-renew">自</span>
                      <span class="service-row-id">ID {{ row.id }}</span>
                    </div>
                    <div class="service-table-meta">
                      <span>{{ row.product?.group_name || row.product?.type_label || '云服务' }}</span>
                      <span class="service-table-meta__dot"></span>
                      <span :class="{ empty: !row.remark }">{{ row.remark || '未添加备注' }}</span>
                      <t-button shape="square" variant="text" size="small" @click="openRemark(row)">
                        <template #icon><edit-icon /></template>
                      </t-button>
                    </div>
                  </div>
                </div>
              </template>
              <template #specs="{ row }">
                <div class="service-table-specs">
                  <span>CPU {{ findListSpecValue(row, ['CPU', '核心']) }}</span>
                  <span>内存 {{ findListSpecValue(row, ['内存', 'RAM']) }}</span>
                  <span>带宽 {{ resolveListBandwidthText(row) }}</span>
                </div>
              </template>
              <template #expires="{ row }">
                <span class="service-expire-line" :class="{ warning: isExpiringSoon(row.expires_at) }">
                  {{ row.expires_at || '长期有效' }}
                </span>
              </template>
              <template #status="{ row }">
                <t-tag :theme="resolveTdesignStatusTheme(row)" variant="light">{{
                  resolveRuntimeStatusLabel(row)
                }}</t-tag>
              </template>
              <template #ip="{ row }">
                <t-button
                  variant="text"
                  size="small"
                  :disabled="!(row.upstream?.dedicated_ip && row.upstream.dedicated_ip !== '--')"
                  @click="copyText(row.upstream?.dedicated_ip || '')"
                >
                  {{ row.upstream?.dedicated_ip || '--' }}
                </t-button>
              </template>
              <template #operation="{ row }">
                <t-space>
                  <t-button size="small" theme="primary" variant="outline" @click="openDetail(row.id)">控制台</t-button>
                  <t-dropdown
                    trigger="click"
                    :options="actionOptions(row)"
                    @click="({ value }: { value: string | number }) => handleServiceAction(String(value), row)"
                  >
                    <t-button size="small" variant="outline">更多</t-button>
                  </t-dropdown>
                </t-space>
              </template>
            </t-table>
          </div>
        </template>
      </data-state>

      <div v-if="!loading && !list.length" class="service-empty-action">
        <t-button theme="primary" @click="openPublicProducts">去选购产品</t-button>
      </div>
    </section>

    <div v-if="total > 0" class="service-pagination">
      <t-pagination
        v-model="filters.page"
        v-model:page-size="filters.page_size"
        :page-size-options="[10, 20, 50]"
        :total="total"
        show-total
        @change="loadList"
        @page-size-change="handlePageSizeChange"
      />
    </div>

    <t-dialog v-model:visible="renewVisible" header="服务续费" width="min(34rem, calc(100vw - 2rem))" destroy-on-close>
      <loading-state :loading="renewPreviewLoading" text="正在加载续费信息" compact>
        <template v-if="renewData">
          <div class="renew-summary-card">
            <div class="renew-summary-row">
              <span>服务实例</span>
              <strong>{{ resolveServiceName(renewTarget) }}</strong>
            </div>
            <div class="renew-summary-row">
              <span>当前到期</span>
              <strong>{{ renewData.expires_at || '--' }}</strong>
            </div>
            <div class="renew-summary-row">
              <span>自动续费</span>
              <strong>{{ Number(renewData.auto_renew) === 1 ? '已开启' : '未开启' }}</strong>
            </div>
          </div>

          <t-radio-group v-model="renewForm.billing_cycle" class="renew-cycle-group" @change="handleRenewCycleChange">
            <t-radio-button
              v-for="cycle in renewData.cycles || []"
              :key="cycle.billing_cycle"
              :value="cycle.billing_cycle"
            >
              {{ cycle.billing_cycle_label }} · ￥{{ formatMoney(cycle.amount) }}
            </t-radio-button>
          </t-radio-group>

          <div v-if="availableRenewCoupons.length" class="renew-coupon-row">
            <span>续费优惠</span>
            <t-select
              :model-value="renewForm.user_coupon_id || undefined"
              clearable
              placeholder="选择优惠券"
              @change="handleRenewCouponChange"
            >
              <t-option
                v-for="coupon in availableRenewCoupons"
                :key="coupon.id"
                :label="`${coupon.name} · ${coupon.discount_label}`"
                :value="coupon.id"
              />
            </t-select>
          </div>

          <div class="renew-total-line">
            <span>本次应付</span>
            <strong>￥{{ selectedRenewAmount }}</strong>
          </div>
        </template>

        <t-empty v-else description="未获取到可续费周期" />
      </loading-state>

      <template #footer>
        <t-button variant="outline" @click="renewVisible = false">取消</t-button>
        <t-button theme="primary" :loading="renewSubmitting" :disabled="!renewForm.billing_cycle" @click="submitRenew">
          创建续费账单
        </t-button>
      </template>
    </t-dialog>

    <t-dialog v-model:visible="remarkVisible" header="编辑备注" width="min(28rem, calc(100vw - 2rem))" destroy-on-close>
      <t-textarea
        v-model="remarkForm.remark"
        :autosize="{ minRows: 4, maxRows: 6 }"
        :maxlength="120"
        placeholder="填写服务备注，便于快速识别"
      />
      <template #footer>
        <t-button variant="outline" @click="remarkVisible = false">取消</t-button>
        <t-button theme="primary" :loading="remarkSubmitting" @click="submitRemark">保存备注</t-button>
      </template>
    </t-dialog>
  </section>
</template>
<script setup lang="ts">
import DataState from '@shared/user-v3/components/DataState.vue';
import LoadingState from '@shared/user-v3/components/LoadingState.vue';
import { CatalogIcon, DashboardIcon, EditIcon, SearchIcon } from 'tdesign-icons-vue-next';
import type { PrimaryTableCol } from 'tdesign-vue-next';
import { shallowRef, triggerRef } from 'vue';

import {
  findListSpecValue,
  formatMoney,
  isExpiringSoon,
  isProvisioningService,
  resolveListBandwidthText,
  resolveRuntimeStatusLabel,
  resolveServiceMark,
  resolveServiceName,
  resolveServiceOsIcon,
  resolveServiceOsText,
  resolveTdesignStatusTheme,
  useServiceCenter,
} from '@/domains/services/useServiceCenter';
import { openPublicProducts } from '@/utils/publicSite';

const {
  loading,
  list,
  total,
  filters,
  viewMode,
  renewVisible,
  renewPreviewLoading,
  renewSubmitting,
  renewTarget,
  renewData,
  remarkVisible,
  remarkSubmitting,
  renewForm,
  remarkForm,
  statusOptions,
  catalogTypeOptions,
  selectedRenewAmount,
  availableRenewCoupons,
  loadList,
  handleSearch,
  handlePageSizeChange,
  setViewMode,
  openDetail,
  handleRenewCycleChange,
  handleRenewCouponChange,
  submitRenew,
  openRemark,
  submitRemark,
  copyText,
  handleServiceAction,
} = useServiceCenter();

const failedServiceOsIconKeys = shallowRef(new Set<string>());

function resolveServiceIconKey(item: Record<string, any>) {
  return `${item?.id || ''}:${resolveServiceOsText(item)}`;
}

function shouldShowServiceOsIcon(item: Record<string, any>) {
  return Boolean(resolveServiceOsIcon(item)) && !failedServiceOsIconKeys.value.has(resolveServiceIconKey(item));
}

function markServiceOsIconFailed(item: Record<string, any>) {
  // 原地 add + triggerRef，避免每次失败都克隆整个 Set 触发所有卡片重渲染
  failedServiceOsIconKeys.value.add(resolveServiceIconKey(item));
  triggerRef(failedServiceOsIconKeys);
}

const columns: PrimaryTableCol[] = [
  { colKey: 'service', title: '服务信息', minWidth: '18rem' },
  { colKey: 'specs', title: '配置摘要', minWidth: '14rem' },
  { colKey: 'expires', title: '到期时间', minWidth: '10rem' },
  { colKey: 'status', title: '状态', width: '8rem' },
  { colKey: 'ip', title: '公网 IP', minWidth: '10rem' },
  { colKey: 'operation', title: '操作', width: '13rem', fixed: 'right', align: 'right' },
];

function actionOptions(item: Record<string, any>) {
  const options = [{ content: '立即续费', value: 'renew' }];
  if (item.invoice?.id) {
    options.push({ content: '账单详情', value: 'invoice' });
  }
  return options;
}
</script>
<style scoped lang="less">
.client-services {
  display: flex;
  flex-direction: column;
  gap: var(--td-comp-margin-m);
  // padding 由 Starter 布局层统一提供
}

.service-filter-card {
  background: var(--td-bg-color-container);
  border: thin solid var(--td-border-color);
  border-radius: var(--td-radius-medium);
  box-shadow: var(--td-shadow-1);
}

.service-list-section {
  display: grid;
  gap: var(--td-comp-margin-m);
}

.service-filter-bar {
  display: grid;
  grid-template-columns: minmax(16rem, 1.5fr) minmax(10rem, 0.72fr) minmax(10rem, 0.72fr) minmax(8rem, 0.5fr) auto;
  gap: var(--td-comp-margin-s);
  align-items: center;
}

.service-filter-actions {
  display: flex;
  gap: var(--td-comp-margin-s);
  justify-content: flex-end;
}

.service-card-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(24rem, 1fr));
  justify-content: stretch;
  gap: var(--td-comp-margin-m);
}

.service-row-card {
  position: relative;
  width: 100%;
  aspect-ratio: 389 / 187;
  padding: 1rem 1.125rem 0.875rem;
  border: 0.0625rem solid var(--td-component-stroke);
  border-radius: var(--td-radius-large);
  background: var(--td-bg-color-container);
  box-shadow: var(--td-shadow-1);
  overflow: hidden;
  transition:
    border-color 0.2s ease,
    box-shadow 0.2s ease;

  &:hover {
    border-color: var(--td-brand-color-focus);
    box-shadow: var(--td-shadow-2);
  }
}

.service-auto-renew-badge {
  position: absolute;
  left: 0;
  bottom: 0;
  width: 1.75rem;
  height: 1.75rem;
  z-index: 1;
  pointer-events: none;
}

.service-auto-renew-badge::before {
  content: '';
  position: absolute;
  inset: 0;
  background: var(--td-success-color);
  clip-path: polygon(0 0, 100% 100%, 0 100%);
}

.service-auto-renew-badge__text {
  position: absolute;
  left: 0.125rem;
  bottom: 0.125rem;
  color: #fff;
  font-size: 0.6875rem;
  font-weight: 700;
  line-height: 1;
  white-space: nowrap;
  z-index: 1;
}

.service-table-auto-renew {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 1.125rem;
  height: 1.125rem;
  background: var(--td-success-color);
  color: #fff;
  font-size: 0.6875rem;
  font-weight: 700;
  line-height: 1;
  border-radius: var(--td-radius-small);
  flex-shrink: 0;
}

.service-row-head {
  display: flex;
  align-items: stretch;
  gap: 0.75rem;
  height: 100%;
}

.service-system-icon {
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 3rem;
  min-width: 3rem;
  height: 3rem;
  border-radius: 0.75rem;
  overflow: hidden;
}

.service-system-icon__image {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.service-system-icon__fallback {
  color: var(--td-brand-color);
  font-size: 1.125rem;
  font-weight: 700;
}

.service-system-loader {
  position: relative;
  width: 1.75rem;
  height: 1.75rem;
}

.service-system-loader__ring,
.service-system-loader__core {
  position: absolute;
  inset: 0;
  border-radius: 50%;
}

.service-system-loader__ring {
  border: 0.1875rem solid var(--td-error-color-1);
  border-top-color: var(--td-error-color);
  animation: service-loader-spin 1.2s linear infinite;
}

.service-system-loader__core {
  inset: 0.4375rem;
  background: radial-gradient(circle, var(--td-error-color-2), transparent);
}

.service-row-body {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
}

.service-row-body-top {
  flex: 0 0 auto;
}

.service-row-topline,
.service-title-row,
.service-remark-line,
.service-row-actions,
.service-row-foot,
.service-ip-line,
.service-table-title-row,
.service-table-meta {
  display: flex;
  align-items: center;
}

.service-row-topline {
  align-items: flex-start;
  gap: 0.75rem;
}

.service-row-titleblock {
  min-width: 0;
  flex: 1;
}

.service-title-row {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  flex-wrap: wrap;
  min-width: 0;
}

.service-name-button,
.service-action-console,
.service-action-more,
.service-remark-trigger,
.service-ip-button {
  border: none;
  padding: 0;
  background: transparent;
  cursor: pointer;
  font-family: inherit;
}

.service-name-button {
  display: block;
  max-width: 100%;
  color: var(--td-text-color-primary);
  font-size: 0.875rem;
  font-weight: 700;
  line-height: 1.3;
  text-align: left;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.service-row-id {
  display: inline-flex;
  align-items: center;
  min-height: 1.25rem;
  padding: 0 0.4375rem;
  border-radius: 62.4375rem;
  background: var(--td-bg-color-component);
  color: var(--td-text-color-placeholder);
  font-size: 0.6875rem;
  font-weight: 600;
}

.service-remark-line,
.service-ip-line,
.service-table-meta {
  display: flex;
  align-items: center;
  gap: 0.375rem;
}

.service-remark-line {
  margin-top: 0.25rem;
}

.service-remark-text {
  color: var(--td-text-color-secondary);
  font-size: 0.6875rem;
  line-height: 1.4;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;

  &.empty {
    color: var(--td-text-color-placeholder);
  }
}

.service-remark-trigger {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 1.125rem;
  height: 1.125rem;
  border-radius: 0.5rem;
  color: var(--td-text-color-secondary);
  font-size: 0.8125rem;
  transition:
    background-color 0.2s ease,
    color 0.2s ease;

  &:hover {
    background: var(--td-brand-color-light);
    color: var(--td-brand-color);
  }
}

.service-row-actions {
  display: flex;
  align-items: center;
  gap: 0.375rem;
  flex-shrink: 0;

  &--corner {
    position: absolute;
    top: 0.875rem;
    right: 1rem;
    z-index: 1;
  }
}

.service-action-console {
  color: var(--td-brand-color);
  font-size: 0.75rem;
  font-weight: 600;
  padding: 0 0.6875rem;
  height: 1.875rem;
  border-radius: 0.625rem;
}

.service-action-more {
  border: 0.0625rem solid var(--td-component-border);
  background: var(--td-bg-color-container);
  color: var(--td-text-color-secondary);
  font-size: 0.75rem;
  font-weight: 600;
  min-width: 3.25rem;
  height: 1.875rem;
  padding: 0 0.6875rem;
  border-radius: 0.625rem;
}

.service-spec-line {
  display: flex;
  flex-wrap: wrap;
  gap: 0.375rem 0.875rem;
  margin-top: 0.875rem;

  span {
    display: inline-block;
    color: var(--td-text-color-secondary);
    font-size: 0.75rem;
    font-weight: 600;
  }
}

.service-expire-line {
  margin-top: 0.5rem;
  color: var(--td-text-color-secondary);
  font-size: 0.75rem;

  &.warning {
    color: var(--td-warning-color);
    font-weight: 600;
  }
}

.service-row-foot {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.875rem;
  margin-top: auto;
  padding-top: 0.625rem;
  border-top: 0.0625rem solid var(--td-component-stroke);
  flex-wrap: wrap;
}

.service-status-tag {
  flex-shrink: 0;
}

.service-ip-line {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  flex-wrap: wrap;
}

.service-ip-label {
  color: var(--td-text-color-placeholder);
  font-size: 0.6875rem;
}

.service-ip-button {
  display: inline-flex;
  align-items: center;
  min-height: 1.75rem;
  padding: 0;
  border: none;
  background: transparent;
  color: var(--td-text-color-primary);
  font-size: 0.75rem;
  font-weight: 600;
  cursor: pointer;

  &.disabled {
    color: var(--td-text-color-placeholder);
    cursor: default;
  }
}

.service-table-card {
  overflow: hidden;
  background: var(--td-bg-color-container);
  border: thin solid var(--td-border-color);
  border-radius: var(--td-radius-medium);
  box-shadow: var(--td-shadow-1);
}

.service-table-service {
  display: flex;
  gap: var(--td-comp-margin-s);
  align-items: flex-start;

  .service-system-icon {
    width: 2.5rem;
    min-width: 2.5rem;
    height: 2.5rem;
    border-radius: 0.625rem;
  }

  .service-system-icon__fallback {
    font-size: 1rem;
  }
}

.service-table-copy {
  flex: 1;
  min-width: 0;
}

.service-table-title-row,
.service-table-meta {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
}

.service-table-meta {
  margin-top: 0.25rem;
  gap: 0.375rem;
  color: var(--td-text-color-secondary);
  font-size: 0.75rem;
}

.service-table-meta__dot {
  width: 0.25rem;
  height: 0.25rem;
  background: var(--td-border-color);
  border-radius: 50%;
}

.service-table-specs {
  display: grid;
  gap: 0.25rem;
  color: var(--td-text-color-secondary);
  font-size: 0.75rem;
}

.service-pagination {
  display: flex;
  justify-content: flex-end;
}

.renew-summary-card {
  display: grid;
  gap: var(--td-comp-margin-s);
  padding: var(--td-comp-paddingTB-m) var(--td-comp-paddingLR-m);
  background: var(--td-bg-color-component);
  border: thin solid var(--td-border-color);
  border-radius: var(--td-radius-medium);
}

.renew-summary-row,
.renew-total-line,
.renew-coupon-row {
  display: flex;
  gap: var(--td-comp-margin-s);
  align-items: center;
  justify-content: space-between;

  span {
    color: var(--td-text-color-secondary);
    font: var(--td-font-body-small);
  }

  strong {
    color: var(--td-text-color-primary);
    font: var(--td-font-body-medium);
  }
}

.renew-cycle-group {
  display: flex;
  flex-wrap: wrap;
  gap: var(--td-comp-margin-s);
  margin-top: var(--td-comp-margin-m);
}

.renew-coupon-row,
.renew-total-line {
  margin-top: var(--td-comp-margin-m);
}

@keyframes service-loader-spin {
  to {
    transform: rotate(360deg);
  }
}

@media (width <= 67.5rem) {
  .service-filter-bar {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .service-filter-actions {
    justify-content: flex-start;
  }
}

@media (width <= 60rem) {
  .service-card-grid {
    grid-template-columns: minmax(0, 1fr);
  }

  .service-pagination {
    justify-content: flex-start;
  }
}

@media (max-width: @screen-sm-rem) {
  .client-services {
    padding: var(--td-comp-paddingTB-l) var(--td-comp-paddingLR-l);
  }

  .service-filter-card :deep(.t-card__body) {
    padding: var(--td-comp-paddingTB-l) var(--td-comp-paddingLR-l);
  }

  .service-filter-bar {
    grid-template-columns: repeat(2, minmax(0, 1fr));

    > :first-child,
    > :last-child {
      grid-column: 1 / -1;
    }
  }

  .service-filter-actions {
    display: none;
  }

  .service-view-toggle {
    display: none;
  }

  .service-row-card {
    aspect-ratio: auto;
    min-height: 12rem;
    padding: 0.75rem 0.875rem;
  }

  .service-system-icon {
    width: 2.5rem;
    min-width: 2.5rem;
    height: 2.5rem;
    border-radius: 0.625rem;
  }

  .service-row-head {
    gap: 0.625rem;
  }

  .service-row-topline {
    gap: 0.5rem;
  }

  .service-name-button {
    font-size: 0.8125rem;
  }

  .service-action-console,
  .service-action-more {
    min-width: 3rem;
    height: 1.75rem;
    padding: 0 0.5625rem;
    font-size: 0.6875rem;
  }

  .service-spec-line {
    gap: 0.25rem 0.625rem;
    margin-top: 0.75rem;

    span {
      font-size: 0.6875rem;
    }
  }

  .service-expire-line,
  .service-ip-button {
    font-size: 0.6875rem;
  }

  .service-row-foot {
    gap: 0.625rem;
    padding-top: 0.5rem;
  }

  .service-ip-label {
    font-size: 0.625rem;
  }

  .service-pagination {
    justify-content: flex-start;
    overflow-x: auto;
  }

  .service-row-topline,
  .renew-summary-row,
  .renew-total-line,
  .renew-coupon-row {
    align-items: flex-start;
    flex-direction: column;
  }
}
</style>
