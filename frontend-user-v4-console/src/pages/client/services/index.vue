<template>
  <section class="client-services">
    <!--
      一级分组 tab：云服务器 / 游戏云 / 虚拟主机…… 各自管各自的产品，比一个
      「服务分类」下拉更快看清自己有哪些实例；第一个是「全部产品」。
      数量来自 grouped-overview 的 catalog_types（就是一级分组维度的计数）。
    -->
    <nav v-if="catalogTypeTabs.length > 1" class="service-group-tabs" :aria-label="$t('client.services.productGroups')">
      <button
        v-for="tab in catalogTypeTabs"
        :key="tab.value"
        type="button"
        class="service-group-tab"
        :class="{ 'is-active': tab.value === filters.catalog_type }"
        :aria-current="tab.value === filters.catalog_type ? 'true' : undefined"
        @click="pickCategory(tab.value)"
      >
        <span
          v-if="tab.icon && tab.icon.mode === 'image' && tab.icon.image && !failedGroupIconKeys.has(tab.value)"
          class="service-group-tab__icon service-group-tab__icon--image"
          aria-hidden="true"
        >
          <img :src="tab.icon.image" alt="" loading="lazy" @error="markGroupIconFailed(tab.value)" />
        </span>
        <span
          v-else-if="tab.icon && tab.fallbackText"
          class="service-group-tab__icon"
          :class="tab.icon.mode === 'icon' ? 'service-group-tab__icon--tdesign' : 'service-group-tab__icon--text'"
          aria-hidden="true"
        >
          <t-icon v-if="tab.icon.mode === 'icon' && tab.icon.icon_name" :name="tab.icon.icon_name" />
          <template v-else>{{ tab.fallbackText }}</template>
        </span>
        <span class="service-group-tab__label">{{ tab.label }}</span>
        <span class="service-group-tab__count">{{ tab.count }}</span>
      </button>
    </nav>

    <t-card class="service-filter-card" :bordered="false">
      <div class="service-filter-bar">
        <t-input
          v-model="filters.keyword"
          clearable
          class="service-filter-bar__search"
          :placeholder="$t('client.services.searchPlaceholder')"
          @enter="handleSearch"
          @clear="handleSearch"
        >
          <template #suffixIcon>
            <search-icon />
          </template>
        </t-input>

        <t-select
          v-model="filters.catalog_type"
          clearable
          class="service-filter-bar__catalog"
          :placeholder="$t('client.services.catalogTypePlaceholder')"
          @change="handleSearch"
        >
          <t-option :label="$t('client.services.allProducts')" value="" />
          <t-option
            v-for="item in catalogTypeOptions"
            :key="item.value"
            :label="`${item.label} (${item.count})`"
            :value="item.value"
          />
        </t-select>

        <t-select
          v-model="filters.status"
          clearable
          :placeholder="$t('client.services.statusPlaceholder')"
          @change="handleSearch"
        >
          <t-option v-for="item in statusOptions" :key="item.value" :label="item.label" :value="item.value" />
        </t-select>

        <t-select
          v-model="filters.auto_renew"
          clearable
          :placeholder="$t('client.services.autoRenewPlaceholder')"
          @change="handleSearch"
        >
          <t-option :label="$t('client.services.all')" value="" />
          <t-option :label="$t('client.services.autoRenew')" value="1" />
          <t-option :label="$t('client.services.autoRenewOff')" value="0" />
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
            {{ viewMode === 'grid' ? $t('client.services.listView') : $t('client.services.cardView') }}
          </t-button>
        </div>
      </div>
    </t-card>

    <section class="service-list-section">
      <data-state
        :loading-text="$t('shared.state.loading')"
        :empty-text="$t('shared.state.empty')"
        :loading="loading"
        :empty="!list.length"
        :description="$t('client.services.empty')"
      >
        <template #default>
          <div v-if="viewMode === 'grid'" class="service-card-grid">
            <article
              v-for="item in list"
              :key="item.id"
              class="service-row-card"
              :class="{ 'is-solo': !actionOptions(item).length }"
            >
              <div class="service-row-actions service-row-actions--corner">
                <button
                  type="button"
                  class="service-action-console"
                  :aria-label="`${resolveServiceName(item)} ${$t('client.services.console')}`"
                  @click="openDetail(item.id)"
                >
                  {{ $t('client.services.console') }}
                </button>
                <t-dropdown
                  v-if="actionOptions(item).length"
                  trigger="click"
                  :options="actionOptions(item)"
                  @click="(option) => handleDropdownAction(option, item)"
                >
                  <button
                    type="button"
                    class="service-action-more"
                    :aria-label="`${resolveServiceName(item)} ${$t('client.services.moreActionsAria')}`"
                  >
                    {{ $t('client.services.more') }}
                  </button>
                </t-dropdown>
              </div>
              <div class="service-row-head">
                <div class="service-system-icon" :class="{ 'is-provisioning': isProvisioningService(item) }">
                  <span v-if="isProvisioningService(item)" class="service-system-loader" aria-hidden="true">
                    <span class="service-system-loader__ring"></span>
                    <span class="service-system-loader__core"></span>
                  </span>
                  <cloud-icon v-else-if="isCdnCard(item)" class="service-system-icon__cloud" />
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

                        <div v-if="resolveServiceGroupPath(item)" class="service-group-path">
                          {{ resolveServiceGroupPath(item) }}
                        </div>

                        <div class="service-remark-line">
                          <span
                            class="service-remark-text"
                            :class="{ empty: !item.remark }"
                            :title="item.remark || $t('client.services.addRemark')"
                          >
                            {{ item.remark || $t('client.services.addRemark') }}
                          </span>
                          <button
                            type="button"
                            class="service-remark-trigger"
                            :aria-label="
                              item.remark ? $t('client.services.editRemark') : $t('client.services.addRemark')
                            "
                            @click="openRemark(item)"
                          >
                            <edit-icon />
                          </button>
                        </div>
                      </div>
                    </div>

                    <div v-if="isCdnCard(item)" class="service-spec-line">
                      <span v-if="resolveCdnSpec(item).nodes"
                        >$t('client.services.nodeCount', { count: resolveCdnSpec(item).nodes })</span
                      >
                      <span v-if="resolveCdnSpec(item).region">
                        {{ $t('client.services.nodeRegion') }} {{ resolveCdnSpec(item).region }}
                      </span>
                      <span v-if="!hasCdnSpec(item)" class="service-spec-empty">{{
                        $t('client.services.cdnInstance')
                      }}</span>
                    </div>
                    <div v-else-if="isVirtualHostCard(item)" class="service-spec-line">
                      <span v-if="resolveVirtualHostSpec(item).webSpace">
                        {{ $t('client.services.webSpace') }} {{ resolveVirtualHostSpec(item).webSpace }}
                      </span>
                      <span v-if="resolveVirtualHostSpec(item).dbSpace">
                        {{ $t('client.services.database') }} {{ resolveVirtualHostSpec(item).dbSpace }}
                      </span>
                      <span v-if="resolveVirtualHostSpec(item).traffic">
                        {{ $t('client.services.monthlyTraffic') }} {{ resolveVirtualHostSpec(item).traffic }}
                      </span>
                      <span v-if="!hasVirtualHostCardSpec(item)" class="service-spec-empty">{{
                        $t('client.services.virtualHostInstance')
                      }}</span>
                    </div>
                    <div v-else class="service-spec-line">
                      <span>CPU {{ findListSpecValue(item, ['CPU', '核心']) }}</span>
                      <span>{{ $t('client.services.memory') }} {{ findListSpecValue(item, ['内存', 'RAM']) }}</span>
                      <span>{{ $t('client.services.bandwidth') }} {{ resolveListBandwidthText(item) }}</span>
                    </div>

                    <div
                      v-if="isCdnCard(item)"
                      class="service-expire-line is-cdn"
                      :class="{ warning: isExpiringSoon(item.expires_at) }"
                    >
                      <span
                        >{{ $t('client.services.billingCycleLabel')
                        }}{{ item.billing_cycle_label || $t('client.services.trialMachine') }}</span
                      >
                      <span v-if="item.expires_at"
                        >{{ $t('client.services.expiresAtPrefix') }} {{ item.expires_at }}</span
                      >
                    </div>
                    <div v-else class="service-expire-line" :class="{ warning: isExpiringSoon(item.expires_at) }">
                      {{ $t('client.services.expiresAtLabel')
                      }}{{ item.expires_at || $t('client.services.longTermValid') }}
                    </div>
                  </div>

                  <div class="service-row-foot">
                    <t-tag :theme="resolveTdesignStatusTheme(item)" variant="light" class="service-status-tag">
                      {{ resolveRuntimeStatusLabel(item) }}
                    </t-tag>

                    <div v-if="isCdnCard(item)" class="service-ip-line">
                      <span class="service-ip-label">{{ $t('client.services.traffic') }}</span>
                      <span class="service-ip-button is-static">
                        {{ resolveListTrafficText(item) || '--' }}
                      </span>
                    </div>

                    <!-- 虚拟主机没有独立公网 IP，右下角展示可复制的主机账号 -->
                    <div v-else-if="isVirtualHostCard(item)" class="service-ip-line">
                      <span class="service-ip-label">{{ $t('client.services.hostAccount') }}</span>
                      <button
                        type="button"
                        class="service-ip-button"
                        :title="`${$t('client.services.copyTitle')} ${resolveListHostAccountText(item)}`"
                        @click="copyText(resolveListHostAccountText(item))"
                      >
                        {{ resolveListHostAccountText(item) }}
                      </button>
                    </div>

                    <div v-else class="service-ip-line">
                      <span class="service-ip-label">{{ $t('client.services.publicIp') }}</span>
                      <button
                        type="button"
                        class="service-ip-button"
                        :class="{ disabled: !(item.upstream?.dedicated_ip && item.upstream.dedicated_ip !== '--') }"
                        :disabled="!(item.upstream?.dedicated_ip && item.upstream.dedicated_ip !== '--')"
                        :title="
                          item.upstream?.dedicated_ip
                            ? `${$t('client.services.copyTitle')} ${item.upstream.dedicated_ip}`
                            : $t('client.services.noPublicIp')
                        "
                        @click="copyText(item.upstream?.dedicated_ip || '')"
                      >
                        {{ item.upstream?.dedicated_ip || '--' }}
                      </button>
                    </div>
                  </div>
                </div>
              </div>
              <div v-if="Number(item.auto_renew) === 1" class="service-auto-renew-badge">
                <span class="service-auto-renew-badge__text">{{ $t('client.services.autoRenewBadge') }}</span>
              </div>
            </article>
          </div>

          <div v-else class="service-table-card">
            <t-table row-key="id" :data="list" :columns="columns" :pagination="null" hover>
              <template #service="{ row }">
                <div class="service-table-service">
                  <div class="service-system-icon">
                    <cloud-icon v-if="isCdnCard(row)" class="service-system-icon__cloud" />
                    <img
                      v-else-if="shouldShowServiceOsIcon(row)"
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
                      <span v-if="Number(row.auto_renew) === 1" class="service-table-auto-renew">{{
                        $t('client.services.autoRenewBadge')
                      }}</span>
                      <span class="service-row-id">ID {{ row.id }}</span>
                    </div>
                    <div class="service-table-meta">
                      <span>{{
                        resolveServiceGroupPath(row) ||
                        row.product?.group_name ||
                        row.product?.type_label ||
                        $t('client.services.cloudService')
                      }}</span>
                      <span class="service-table-meta__dot"></span>
                      <span :class="{ empty: !row.remark }">{{ row.remark || $t('client.services.noRemark') }}</span>
                      <t-button shape="square" variant="text" size="small" @click="openRemark(row)">
                        <template #icon><edit-icon /></template>
                      </t-button>
                    </div>
                  </div>
                </div>
              </template>
              <template #specs="{ row }">
                <div v-if="isCdnCard(row)" class="service-table-specs">
                  <span v-if="resolveCdnSpec(row).nodes"
                    >$t('client.services.nodeCount', { count: resolveCdnSpec(row).nodes })</span
                  >
                  <span v-if="resolveCdnSpec(row).region"
                    >{{ $t('client.services.nodeRegion') }} {{ resolveCdnSpec(row).region }}</span
                  >
                  <span v-if="!hasCdnSpec(row)" class="service-spec-empty">{{
                    $t('client.services.cdnInstance')
                  }}</span>
                </div>
                <div v-else-if="isVirtualHostCard(row)" class="service-table-specs">
                  <span v-if="resolveVirtualHostSpec(row).webSpace">
                    {{ $t('client.services.webSpace') }} {{ resolveVirtualHostSpec(row).webSpace }}
                  </span>
                  <span v-if="resolveVirtualHostSpec(row).traffic">
                    {{ $t('client.services.monthlyTraffic') }} {{ resolveVirtualHostSpec(row).traffic }}
                  </span>
                  <span v-if="!hasVirtualHostTableSpec(row)" class="service-spec-empty">{{
                    $t('client.services.virtualHostInstance')
                  }}</span>
                </div>
                <div v-else class="service-table-specs">
                  <span>CPU {{ findListSpecValue(row, ['CPU', '核心']) }}</span>
                  <span>{{ $t('client.services.memory') }} {{ findListSpecValue(row, ['内存', 'RAM']) }}</span>
                  <span>{{ $t('client.services.bandwidth') }} {{ resolveListBandwidthText(row) }}</span>
                </div>
              </template>
              <template #expires="{ row }">
                <span class="service-expire-line" :class="{ warning: isExpiringSoon(row.expires_at) }">
                  {{ row.expires_at || $t('client.services.longTermValid') }}
                </span>
              </template>
              <template #status="{ row }">
                <t-tag :theme="resolveTdesignStatusTheme(row)" variant="light">{{
                  resolveRuntimeStatusLabel(row)
                }}</t-tag>
              </template>
              <template #ip="{ row }">
                <span v-if="isCdnCard(row)" class="service-table-traffic">
                  {{ resolveListTrafficText(row) || '--' }}
                </span>
                <t-button
                  v-else-if="isVirtualHostCard(row)"
                  variant="text"
                  size="small"
                  @click="copyText(resolveListHostAccountText(row))"
                >
                  {{ resolveListHostAccountText(row) }}
                </t-button>
                <t-button
                  v-else
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
                  <t-button size="small" theme="primary" variant="outline" @click="openDetail(row.id)">{{
                    $t('client.services.console')
                  }}</t-button>
                  <t-dropdown
                    v-if="actionOptions(row).length"
                    trigger="click"
                    :options="actionOptions(row)"
                    @click="(option) => handleDropdownAction(option, row)"
                  >
                    <t-button size="small" variant="outline">{{ $t('client.services.more') }}</t-button>
                  </t-dropdown>
                </t-space>
              </template>
            </t-table>
          </div>
        </template>
      </data-state>

      <div v-if="!loading && !list.length" class="service-empty-action">
        <t-button theme="primary" @click="openPublicProducts">{{ $t('client.services.goShopping') }}</t-button>
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

    <t-dialog
      v-model:visible="renewVisible"
      :header="$t('client.services.renewTitle')"
      width="min(34rem, calc(100vw - 2rem))"
      destroy-on-close
    >
      <loading-state :loading="renewPreviewLoading" :text="$t('client.services.renewLoading')" compact>
        <template v-if="renewData">
          <div class="renew-summary-card">
            <div class="renew-summary-row">
              <span>{{ $t('client.services.renewServiceLabel') }}</span>
              <strong>{{ resolveServiceName(renewTarget) }}</strong>
            </div>
            <div class="renew-summary-row">
              <span>{{ $t('client.services.renewCurrentExpiresLabel') }}</span>
              <strong>{{ renewData.expires_at || '--' }}</strong>
            </div>
            <div class="renew-summary-row">
              <span>{{ $t('client.services.renewAutoRenewLabel') }}</span>
              <strong>{{
                Number(renewData.auto_renew) === 1 ? $t('client.services.enabled') : $t('client.services.disabled')
              }}</strong>
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
            <span>{{ $t('client.services.renewCouponLabel') }}</span>
            <t-select
              :model-value="renewForm.user_coupon_id || undefined"
              clearable
              :placeholder="$t('client.services.renewCouponPlaceholder')"
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
            <span>{{ $t('client.services.renewTotalLabel') }}</span>
            <strong>￥{{ selectedRenewAmount }}</strong>
          </div>
        </template>

        <t-empty v-else :description="$t('client.services.noRenewCycle')" />
      </loading-state>

      <template #footer>
        <t-button variant="outline" @click="renewVisible = false">{{ $t('client.services.renewCancel') }}</t-button>
        <t-button theme="primary" :loading="renewSubmitting" :disabled="!renewForm.billing_cycle" @click="submitRenew">
          {{ $t('client.services.renewCreateInvoice') }}
        </t-button>
      </template>
    </t-dialog>

    <t-dialog
      v-model:visible="remarkVisible"
      :header="$t('client.services.remarkTitle')"
      width="min(28rem, calc(100vw - 2rem))"
      destroy-on-close
    >
      <t-textarea
        v-model="remarkForm.remark"
        :autosize="{ minRows: 4, maxRows: 6 }"
        :maxlength="120"
        :placeholder="$t('client.services.remarkPlaceholder')"
      />
      <template #footer>
        <t-button variant="outline" @click="remarkVisible = false">{{ $t('client.services.remarkCancel') }}</t-button>
        <t-button theme="primary" :loading="remarkSubmitting" @click="submitRemark">{{
          $t('client.services.saveRemark')
        }}</t-button>
      </template>
    </t-dialog>
  </section>
</template>
<script setup lang="ts">
import DataState from '@shared/user-v3/components/DataState.vue';
import LoadingState from '@shared/user-v3/components/LoadingState.vue';
import { CatalogIcon, CloudIcon, DashboardIcon, EditIcon, SearchIcon } from 'tdesign-icons-vue-next';
import type { PrimaryTableCol } from 'tdesign-vue-next';
import { shallowRef, triggerRef } from 'vue';

import {
  findListSpecValue,
  formatMoney,
  isCdnListItem,
  isExpiringSoon,
  isProvisioningService,
  isVirtualHostListItem,
  resolveListBandwidthText,
  resolveListCdnSpecText,
  resolveListHostAccountText,
  resolveListTrafficText,
  resolveListVirtualHostSpecText,
  resolveRuntimeStatusLabel,
  resolveServiceGroupPath,
  resolveServiceMark,
  resolveServiceName,
  resolveServiceOsIcon,
  resolveServiceOsText,
  resolveTdesignStatusTheme,
  useServiceCenter,
} from '@/domains/services/useServiceCenter';
import { t } from '@/locales';
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
  catalogTypeTabs,
  pickCategory,
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

/** 分类 tab 图片图标加载失败的分组：失败后回退为文字徽标，不显示破图 */
const failedGroupIconKeys = shallowRef(new Set<string>());

function markGroupIconFailed(value: string) {
  failedGroupIconKeys.value.add(value);
  triggerRef(failedGroupIconKeys);
}

function resolveServiceIconKey(item: Record<string, any>) {
  return `${item?.id || ''}:${resolveServiceOsText(item)}`;
}

/** CDN 卡片：摘要行与底部流量都走CDN 语义，不展示 CPU / 内存 / 公网 IP */
function isCdnCard(item: Record<string, any>) {
  return isCdnListItem(item);
}

function resolveCdnSpec(item: Record<string, any>) {
  return resolveListCdnSpecText(item);
}

function hasCdnSpec(item: Record<string, any>) {
  const spec = resolveListCdnSpecText(item);
  return Boolean(spec.nodes || spec.region);
}

function shouldShowServiceOsIcon(item: Record<string, any>) {
  // CDN / 虚拟主机没有操作系统概念，上游可能回填宿主OS，一律不显示 OS 图标
  if (isCdnListItem(item) || isVirtualHostListItem(item)) return false;
  return Boolean(resolveServiceOsIcon(item)) && !failedServiceOsIconKeys.value.has(resolveServiceIconKey(item));
}

function isVirtualHostCard(item: Record<string, any>) {
  return isVirtualHostListItem(item);
}

function resolveVirtualHostSpec(item: Record<string, any>) {
  return resolveListVirtualHostSpecText(item);
}

/**
 * 卡片区展示「网页空间 / 数据库 / 月流量」，空态只按这三个字段判断。
 * domains 不在卡片展示，不能单独撑起「有规格」，否则会出现既无内容也无占位的留白。
 */
function hasVirtualHostCardSpec(item: Record<string, any>) {
  const spec = resolveListVirtualHostSpecText(item);
  return Boolean(spec.webSpace || spec.dbSpace || spec.traffic);
}

/**
 * 表格区只展示「网页空间 / 月流量」，因此 dbSpace 与 domains 都不参与空态判断。
 */
function hasVirtualHostTableSpec(item: Record<string, any>) {
  const spec = resolveListVirtualHostSpecText(item);
  return Boolean(spec.webSpace || spec.traffic);
}

function markServiceOsIconFailed(item: Record<string, any>) {
  // 原地 add + triggerRef，避免每次失败都克隆整个 Set 触发所有卡片重渲染
  failedServiceOsIconKeys.value.add(resolveServiceIconKey(item));
  triggerRef(failedServiceOsIconKeys);
}

const columns: PrimaryTableCol[] = [
  { colKey: 'service', title: t('client.services.service'), minWidth: '18rem' },
  { colKey: 'specs', title: t('client.services.specSummary'), minWidth: '14rem' },
  { colKey: 'expires', title: t('client.services.expiresAt'), minWidth: '10rem' },
  { colKey: 'status', title: t('common.status'), width: '8rem' },
  { colKey: 'ip', title: t('client.services.ipTraffic'), minWidth: '10rem' },
  { colKey: 'operation', title: t('common.action'), width: '13rem', fixed: 'right', align: 'right' },
];

function resolveDropdownValue(option: unknown) {
  if (typeof option === 'object' && option !== null) {
    return String((option as { value?: string | number }).value ?? '');
  }

  return String(option);
}

function handleDropdownAction(option: unknown, target: Parameters<typeof handleServiceAction>[1]) {
  return handleServiceAction(resolveDropdownValue(option), target);
}

/**
 * 单条服务的操作菜单。
 *
 * 试用机（ontrial）不可续费、无账单时返回空数组 —— 模板侧据此隐藏「更多」，
 * 不渲染一个点开什么都没有的空下拉。
 */
function actionOptions(item: Record<string, any>) {
  const options: { content: string; value: string }[] = [];
  if (resolveBillingCycleValue(item) !== 'ontrial') {
    options.push({ content: t('client.services.renewNow'), value: 'renew' });
  }
  if (item.invoice?.id) {
    options.push({ content: t('client.services.invoiceDetail'), value: 'invoice' });
  }
  return options;
}

/** 取归一后的计费周期原始值（不取 label，label 已被本地化成中文） */
function resolveBillingCycleValue(item: Record<string, any>) {
  return String(item?.billing_cycle || '')
    .trim()
    .toLowerCase();
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

/*
 * 一级分组 tab：横向可滚动，数量随分组走。
 * 与筛选条里的「服务分类」下拉同源，宽屏看 tab、窄屏看下拉，切换的是同一个值。
 */
.service-group-tabs {
  display: none;
  align-items: center;
  gap: var(--td-comp-margin-s);
  padding: var(--td-comp-paddingTB-s) var(--td-comp-paddingLR-s);
  overflow-x: auto;
  scrollbar-width: thin;
  background: var(--td-bg-color-container);
  border: thin solid var(--td-border-color);
  border-radius: var(--td-radius-medium);
  box-shadow: var(--td-shadow-1);
}

.service-group-tab {
  display: inline-flex;
  flex: none;
  align-items: center;
  gap: 0.375rem;
  min-height: 2rem;
  padding: 0 0.75rem;
  border: thin solid transparent;
  border-radius: var(--td-radius-medium);
  background: transparent;
  color: var(--td-text-color-secondary);
  font-size: var(--td-font-size-body-medium);
  cursor: pointer;
  white-space: nowrap;
  transition:
    background-color 0.2s ease,
    color 0.2s ease,
    border-color 0.2s ease;

  &:hover {
    background: var(--td-bg-color-component);
    color: var(--td-text-color-primary);
  }

  &.is-active {
    border-color: var(--td-brand-color);
    background: var(--td-brand-color-light);
    color: var(--td-brand-color);
    font-weight: 600;
  }
}

.service-group-tab__label {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

/* 一级分组图标：图片 / 文字徽标 / 内置 TDesign 图标；缺图标时整块不渲染 */
.service-group-tab__icon {
  display: inline-flex;
  flex: none;
  align-items: center;
  justify-content: center;
  width: 1.25rem;
  height: 1.25rem;
  overflow: hidden;
  border-radius: 0.25rem;
}

.service-group-tab__icon--image img {
  width: 100%;
  height: 100%;
  object-fit: contain;
}

.service-group-tab__icon--text {
  width: auto;
  min-width: 1.25rem;
  padding: 0 0.1875rem;
  background: var(--td-bg-color-component);
  color: var(--td-text-color-secondary);
  font-size: 0.625rem;
  font-weight: 700;
  line-height: 1;
  white-space: nowrap;

  .service-group-tab.is-active & {
    background: var(--td-brand-color);
    color: #fff;
  }
}

.service-group-tab__icon--tdesign {
  color: var(--td-text-color-secondary);
  font-size: 1rem;

  .service-group-tab.is-active & {
    color: var(--td-brand-color);
  }
}

.service-group-tab__count {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 1.25rem;
  height: 1.25rem;
  padding: 0 0.3125rem;
  border-radius: 62.4375rem;
  background: var(--td-bg-color-component);
  color: var(--td-text-color-placeholder);
  font-size: var(--td-font-size-body-small);
  font-weight: 600;
  line-height: 1;

  .service-group-tab.is-active & {
    background: var(--td-brand-color);
    color: #fff;
  }
}

.service-empty-action {
  display: flex;
  justify-content: center;
  margin-top: 4px;
  padding-bottom: 32px;
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
  align-items: start; // 卡片高度按内容自适应，不再强行等高，避免底部留白
  gap: var(--td-comp-margin-m);
}

.service-row-card {
  position: relative;
  width: 100%;
  // 不用 aspect-ratio 锁死宽高比：卡片内容高度随产品类型变化
  // （CDN 的加速区域列表可能换行两行、还多一行计费方式），
  // 固定比例 + overflow:hidden 会把底部状态标签与流量直接裁掉。
  min-height: 11.6875rem; // = 187/16，与原 aspect-ratio 的等效高度
  padding: 1rem 1.125rem 0.875rem;
  border: 0.0625rem solid var(--td-component-stroke);
  border-radius: var(--td-radius-large);
  background: var(--td-bg-color-container);
  box-shadow: var(--td-shadow-1);
  overflow: hidden;
  // 标题行要给右上角绝对定位的按钮让位，默认「控制台 + 更多」两个按钮的宽度；
  // 只有一个按钮时由卡片的 is-solo 修饰类收窄。
  --service-corner-width: 7.25rem;
  transition:
    border-color 0.2s ease,
    box-shadow 0.2s ease;

  /* 无「更多」菜单时（试用机既不能续费也没有账单）只留「控制台」，少让一截 */
  &.is-solo {
    --service-corner-width: 4.25rem;
  }

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
  border-radius: 6px;
  overflow: hidden;
}

.service-system-icon__image {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

/* CDN 用云朵图标，跟后台一级分组的「云服务」保持同一视觉 */
.service-system-icon__cloud {
  width: 1.5rem;
  height: 1.5rem;
  color: var(--td-brand-color);
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
  // 「控制台 / 更多」绝对定位在卡片右上角，只有标题行需要让出这条宽度。
  // 让位放在 .service-row-head 上会把整个正文区（含摘要行、到期行）一起压窄，
  // 导致「节点区域」这类长文本被挤成逐字换行的窄柱。
  padding-right: var(--service-corner-width, 7.25rem);
}

.service-row-titleblock {
  min-width: 0;
  flex: 1;
}

.service-title-row {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  // 不换行：标题压缩后与 ID 徽标始终同一行，标题过长时省略号截断
  flex-wrap: nowrap;
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
  // 允许在flex 行内被压缩，配合 ellipsis 截断；
  // 缺 min-width:0 时 flex 子项不会缩到内容宽度以下，文字会溢出压到「控制台」按钮上
  min-width: 0;
  // 占满剩余宽度，把 ID 徽标顶到右侧；窄卡片下先压缩标题而不是挤掉 ID
  flex: 1 1 auto;
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
  flex: none; // ID 徽标不参与压缩，压缩交给标题文字
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

.service-group-path {
  margin-top: 2px;
  overflow: hidden;
  color: var(--td-text-color-secondary);
  font-size: 12px;
  line-height: 1.5;
  text-overflow: ellipsis;
  white-space: nowrap;
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
  border-radius: 3px;
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
  border-radius: 3px;
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
  border-radius: 3px;
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

  /* CDN 摘要保持流式换行：节点地区是一串很长的列表，
     强行分两列会把它压成逐字换行的窄柱。 */
}

.service-expire-line {
  margin-top: 0.5rem;
  color: var(--td-text-color-secondary);
  font-size: 0.75rem;

  &.warning {
    color: var(--td-warning-color);
    font-weight: 600;
  }

  /* CDN 到期行拆成「计费方式 / 到期时间」两项，跟随内容左对齐排列 */
  &.is-cdn {
    display: flex;
    flex-wrap: wrap;
    align-items: baseline;
    gap: 0.25rem 0.875rem;

    span:first-child {
      flex: none;
      font-weight: 600;
    }
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

  /* CDN 卡片底部展示流量，不是可复制按钮 */
  &.is-static {
    cursor: default;
    color: var(--td-text-color-primary);
  }
}

/* CDN 摘要行：规格取不到时的兜底文案 */
.service-spec-empty {
  color: var(--td-text-color-placeholder);
}

.service-table-traffic {
  color: var(--td-text-color-primary);
  font-size: 0.75rem;
  font-weight: 600;
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
    border-radius: 6px;
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
    border-radius: 6px;
  }

  .service-row-head {
    gap: 0.625rem;
  }

  .service-system-icon__cloud {
    width: 1.25rem;
    height: 1.25rem;
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

  // 移动端按钮更窄，标题行的让位宽度同步收窄
  .service-row-card {
    --service-corner-width: 6.5rem;

    &.is-solo {
      --service-corner-width: 4rem;
    }
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

/*
 * 宽屏用一级分组 tab、窄屏用同源的下拉：两者改的是同一个 filters.catalog_type。
 * tab 行默认 display:none，这里放开，避免手机端多出一条横向滚动条。
 */
@media (min-width: 48rem) {
  .service-group-tabs {
    display: flex;
  }

  .service-filter-bar__catalog {
    display: none;
  }

  // 隐藏下拉后少一列，搜索框 / 状态 / 续费方式 / 视图切换重新占满
  .service-filter-bar {
    grid-template-columns: minmax(16rem, 1.5fr) minmax(10rem, 0.72fr) minmax(8rem, 0.5fr) auto;
  }
}
</style>
