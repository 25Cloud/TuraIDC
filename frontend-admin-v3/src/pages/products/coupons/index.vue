<template>
  <div class="coupons-page">
    <!-- 优惠券列表 -->
    <template v-if="activeTab === 'coupons'">
      <t-alert
        v-if="!couponFeatureEnabled"
        theme="warning"
        message="当前环境未启用优惠券功能，新增、编辑、启停和删除操作已禁用。"
      />

      <t-card :bordered="false">
        <div class="coupons-filter">
          <t-input
            v-model="filters.keyword"
            clearable
            placeholder="搜索优惠券名称 / 描述"
            @enter="handleSearch"
            @clear="handleSearch"
          >
            <template #suffix-icon><search-icon /></template>
          </t-input>
          <t-select v-model="filters.status" clearable placeholder="状态" @change="handleSearch">
            <t-option value="1" label="生效中" />
            <t-option value="0" label="已停用" />
            <t-option value="expired" label="已过期" />
          </t-select>
          <t-select v-model="filters.discount_type" clearable placeholder="类型" @change="handleSearch">
            <t-option value="fixed" label="满减券" />
            <t-option value="percentage" label="折扣券" />
          </t-select>
          <t-select v-model="filters.discount_scope" clearable placeholder="优惠阶段" @change="handleSearch">
            <t-option value="first_month" label="首月优惠" />
            <t-option value="recurring" label="持续优惠" />
            <t-option value="renew" label="续费优惠" />
          </t-select>
          <t-select v-model="filters.distribution_type" clearable placeholder="发放方式" @change="handleSearch">
            <t-option value="public" label="公开优惠券" />
            <t-option value="private" label="私有优惠券" />
          </t-select>
          <t-button theme="primary" :disabled="!couponFeatureEnabled || !canManage" @click="openCouponDialog()">
            <template #icon><add-icon /></template>
            新增优惠券
          </t-button>
        </div>

        <div v-if="!isMobile" class="table-scroll">
          <t-table row-key="id" :data="coupons" :columns="columns" :loading="loading" hover table-layout="fixed">
            <template #coupon="{ row }">
              <div class="coupon-main">
                <div class="coupon-title-row">
                  <strong>{{ row.name || '-' }}</strong>
                  <t-tag size="small" variant="light">{{
                    row.distribution_type_label || distributionLabel(row.distribution_type)
                  }}</t-tag>
                  <t-tag v-if="row.coupon_campaign_name" size="small" theme="warning" variant="light">
                    活动：{{ row.coupon_campaign_name }}
                  </t-tag>
                  <t-tag v-if="couponHasLockedFields(row)" size="small" theme="default" variant="light">部分锁定</t-tag>
                </div>
                <span>{{ row.description || '暂无描述' }}</span>
              </div>
            </template>

            <template #rule="{ row }">
              <div class="coupon-meta">
                <div class="coupon-title-row">
                  <t-tag size="small" :theme="row.discount_type === 'fixed' ? 'danger' : 'warning'" variant="light">
                    {{ row.discount_type_label || discountTypeLabel(row.discount_type) }}
                  </t-tag>
                  <t-tag size="small" theme="primary" variant="light">
                    {{ row.discount_scope_label || discountScopeLabel(row.discount_scope) }}
                  </t-tag>
                </div>
                <strong>{{ row.discount_label || formatDiscount(row) }}</strong>
                <span>最低消费：{{ moneyText(row.min_amount) }}</span>
                <span v-if="row.max_discount_amount">最高优惠：{{ moneyText(row.max_discount_amount) }}</span>
              </div>
            </template>

            <template #usage="{ row }">
              <div class="coupon-meta">
                <span>已使用 {{ row.used_count || 0 }} 次</span>
                <span>{{ formatLimitText(row.total_usage_limit, row.remaining_stock, '总量') }}</span>
                <span>{{ formatLimitText(row.per_user_limit, null, '每人') }}</span>
              </div>
            </template>

            <template #status="{ row }">
              <t-tag :theme="statusTheme(row.display_status)" variant="light">
                {{ row.display_status_label || statusLabel(row) }}
              </t-tag>
            </template>

            <template #validity="{ row }">
              <div class="coupon-meta">
                <span>{{ row.validity_text || validityText(row) }}</span>
                <span>{{ row.display_status_reason || '-' }}</span>
              </div>
            </template>

            <template #updatedAt="{ row }">{{ formatDateTime(row.updated_at) }}</template>

            <template #operation="{ row }">
              <t-space v-if="!isMobile" size="small">
                <t-button
                  size="small"
                  variant="text"
                  theme="primary"
                  :disabled="couponEditDisabled(row)"
                  @click="openCouponDialog(row)"
                >
                  编辑
                </t-button>
                <t-button
                  size="small"
                  variant="text"
                  :loading="actionLoading === row.id"
                  :disabled="!couponFeatureEnabled || !canManage"
                  @click="handleToggleStatus(row)"
                >
                  {{ Number(row.status) === 1 ? '停用' : '启用' }}
                </t-button>
                <t-button
                  size="small"
                  variant="text"
                  theme="danger"
                  :disabled="couponDeleteDisabled(row)"
                  @click="handleDelete(row)"
                >
                  删除
                </t-button>
              </t-space>
              <t-dropdown v-else :options="mobileActionOptions(row)" @click="handleMobileActionHandler(row)">
                <t-button size="small" variant="text">更多</t-button>
              </t-dropdown>
            </template>
          </t-table>
        </div>

        <div v-else class="coupon-mobile-list">
          <t-loading :loading="loading" size="small">
            <div v-if="coupons.length" class="coupon-mobile-stack">
              <mobile-record-card
                v-for="row in coupons"
                :key="row.id"
                :title="row.name || '-'"
                :subtitle="row.distribution_type_label || distributionLabel(row.distribution_type)"
                :description="row.description || ''"
                :status-label="row.display_status_label || statusLabel(row)"
                :status-theme="statusTheme(row.display_status)"
                :rows="couponMobileRows(row)"
                :action-options="mobileActionOptions(row)"
                @action="handleCouponCardAction(row, $event)"
              />
            </div>
            <t-empty v-else-if="!loading" description="暂无优惠券" />
          </t-loading>
        </div>

        <div v-if="total > 0" class="pagination-row">
          <t-pagination
            :current="page"
            :page-size="pageSize"
            :total="total"
            :page-size-options="[20, 50, 100]"
            show-jumper
            @change="handlePageChange"
          />
        </div>
      </t-card>

      <t-drawer
        v-model:visible="dialogVisible"
        :header="form.id ? '编辑优惠券' : '新增优惠券'"
        size="820px"
        class="coupon-edit-drawer"
        :close-on-overlay-click="false"
        :footer="false"
        @close="handleDialogClosed"
      >
        <div class="coupon-drawer-shell">
          <t-alert
            v-if="lockedFields.length"
            theme="warning"
            :message="`${lockReason}，部分关键字段不允许修改`"
            style="margin-bottom: 16px"
          />
          <t-form ref="formRef" class="coupon-drawer-form" :data="form" :rules="formRules" label-align="top">
            <section class="coupon-drawer-section" data-title="基础信息">
              <div class="coupon-form-grid">
                <t-form-item label="优惠券名称" name="name">
                  <t-input v-model="form.name" maxlength="120" placeholder="例如：新客首单立减券" />
                </t-form-item>
                <t-form-item label="发放方式" name="distribution_type">
                  <t-select v-model="form.distribution_type" :disabled="isFieldLocked('distribution_type')">
                    <t-option value="public" label="公开优惠券" />
                    <t-option value="private" label="私有优惠券" />
                  </t-select>
                </t-form-item>
                <t-form-item label="优惠类型" name="discount_type">
                  <t-select v-model="form.discount_type" :disabled="isFieldLocked('discount_type')">
                    <t-option value="fixed" label="满减券" />
                    <t-option value="percentage" label="折扣券" />
                  </t-select>
                </t-form-item>
                <t-form-item label="优惠阶段" name="discount_scope">
                  <t-select v-model="form.discount_scope" :disabled="isFieldLocked('discount_scope')">
                    <t-option value="first_month" label="首月优惠" />
                    <t-option value="recurring" label="持续优惠" />
                    <t-option value="renew" label="续费优惠" />
                  </t-select>
                </t-form-item>
              </div>
            </section>

            <section class="coupon-drawer-section" data-title="优惠规则">
              <div class="coupon-form-grid">
                <t-form-item
                  :label="form.discount_type === 'percentage' ? '优惠值（百分比）' : '优惠金额'"
                  name="discount_value"
                >
                  <t-input-number
                    v-model="form.discount_value"
                    :min="0"
                    :max="form.discount_type === 'percentage' ? 100 : 999999999"
                  />
                </t-form-item>
                <t-form-item label="最低消费金额" name="min_amount">
                  <t-input-number v-model="form.min_amount" :min="0" :decimal-places="2" />
                </t-form-item>
                <t-form-item label="最高优惠金额" name="max_discount_amount">
                  <t-input-number
                    v-model="form.max_discount_amount"
                    :min="0"
                    :decimal-places="2"
                    placeholder="留空表示不限制"
                  />
                </t-form-item>
                <t-form-item label="总发放次数上限" name="total_usage_limit">
                  <t-input-number v-model="form.total_usage_limit" :min="0" placeholder="留空表示不限" />
                </t-form-item>
                <t-form-item label="每人可用次数" name="per_user_limit">
                  <t-input-number v-model="form.per_user_limit" :min="0" placeholder="留空表示不限" />
                </t-form-item>
                <t-form-item label="排序值" name="sort_order">
                  <t-input-number v-model="form.sort_order" :min="0" />
                </t-form-item>
                <t-form-item label="状态" name="status">
                  <div class="coupon-switch-line">
                    <span>停用</span>
                    <t-switch v-model="form.status" :custom-value="[1, 0]" />
                    <span>启用</span>
                  </div>
                </t-form-item>
                <t-form-item label="仅限首单可用" name="first_order_only">
                  <t-switch v-model="form.first_order_only" />
                </t-form-item>
                <t-form-item label="允许代理使用" name="allow_agent">
                  <t-switch v-model="form.allow_agent" />
                </t-form-item>
              </div>
            </section>

            <section class="coupon-drawer-section" data-title="时间与范围">
              <div class="coupon-form-grid">
                <t-form-item label="开始时间" name="starts_at">
                  <t-date-picker
                    v-model="form.starts_at"
                    clearable
                    enable-time-picker
                    mode="date"
                    format="YYYY-MM-DD HH:mm:ss"
                    value-type="YYYY-MM-DD HH:mm:ss"
                    placeholder="请选择开始时间，留空立即生效"
                  />
                </t-form-item>
                <t-form-item label="结束时间" name="expires_at">
                  <t-date-picker
                    v-model="form.expires_at"
                    clearable
                    enable-time-picker
                    mode="date"
                    format="YYYY-MM-DD HH:mm:ss"
                    value-type="YYYY-MM-DD HH:mm:ss"
                    placeholder="请选择结束时间，留空长期有效"
                  />
                </t-form-item>
                <t-form-item label="适用计费周期" name="billing_cycles" class="form-span-2">
                  <t-select v-model="form.billing_cycles" multiple clearable placeholder="留空表示全部周期可用">
                    <t-option
                      v-for="item in billingCycleOptions"
                      :key="item.value"
                      :label="item.label"
                      :value="item.value"
                    />
                  </t-select>
                </t-form-item>
                <t-form-item label="适用商品" name="product_ids" class="form-span-2">
                  <product-binding-tree-select
                    v-model="form.product_ids"
                    mode="batch"
                    placeholder="按分类批量选择适用商品，留空表示全站商品可用"
                  />
                </t-form-item>
              </div>
            </section>

            <section class="coupon-drawer-section" data-title="发放用户">
              <div class="user-picker-head">
                <div>
                  <strong>发放用户</strong>
                  <span>{{
                    form.distribution_type === 'private' ? '私有优惠券至少选择一个用户' : '公开优惠券无需指定客户'
                  }}</span>
                </div>
                <t-tag :theme="form.distribution_type === 'private' ? 'primary' : 'default'" variant="light">
                  {{ distributionLabel(form.distribution_type) }}
                </t-tag>
              </div>
              <div v-if="form.distribution_type === 'private'" class="user-picker-body">
                <div class="user-search-row">
                  <t-input
                    v-model="userSearchKeyword"
                    clearable
                    :loading="userOptionsLoading"
                    placeholder="搜索用户 ID / 邮箱 / 手机号 / 昵称"
                    @enter="searchUsers"
                    @clear="searchUsers"
                  />
                </div>
                <div class="user-picker-grid">
                  <div>
                    <div class="user-picker-title">搜索结果 {{ userSearchResults.length }} 条</div>
                    <div class="user-list">
                      <button
                        v-for="item in userSearchResults"
                        :key="item.value"
                        type="button"
                        @click="toggleUserSelection(item)"
                      >
                        <strong>{{ item.title }}</strong>
                        <span>{{ item.meta }}</span>
                        <em>{{ form.user_ids.includes(item.value) ? '已选' : '添加' }}</em>
                      </button>
                      <t-empty v-if="!userSearchResults.length" description="暂无搜索结果" />
                    </div>
                  </div>
                  <div>
                    <div class="user-picker-title">已选用户 {{ selectedUsers.length }} 人</div>
                    <div class="user-list">
                      <button
                        v-for="item in selectedUsers"
                        :key="item.value"
                        type="button"
                        @click="removeSelectedUser(item.value)"
                      >
                        <strong>{{ item.title }}</strong>
                        <span>{{ item.meta }}</span>
                        <em>移除</em>
                      </button>
                      <t-empty v-if="!selectedUsers.length" description="还没有选择发放用户" />
                    </div>
                  </div>
                </div>
              </div>
              <t-alert v-else theme="info" message="公开优惠券会在客户端公开列表展示，用户领取后再使用。" />
            </section>

            <section class="coupon-drawer-section" data-title="备注">
              <div class="coupon-form-grid">
                <t-form-item label="描述" name="description" class="form-span-2">
                  <t-textarea v-model="form.description" :autosize="{ minRows: 3, maxRows: 5 }" maxlength="255" />
                </t-form-item>
                <t-form-item label="后台备注" name="remark" class="form-span-2">
                  <t-textarea v-model="form.remark" :autosize="{ minRows: 3, maxRows: 5 }" maxlength="255" />
                </t-form-item>
              </div>
            </section>
          </t-form>

          <div class="coupon-drawer-footer">
            <t-button variant="outline" @click="closeCouponDrawer">取消</t-button>
            <t-button theme="primary" :loading="saving" @click="submitForm">保存更改</t-button>
          </div>
        </div>
      </t-drawer>
    </template>

    <!-- 活动券管理 -->
    <coupon-campaigns v-else />
  </div>
</template>
<script setup lang="ts">
import { billingCycleOptions as billingCycleOptionsOf } from '@shared/billingCycle';
import { AddIcon, SearchIcon } from 'tdesign-icons-vue-next';
import type { DropdownOption, FormInstanceFunctions, FormRule, PrimaryTableCol, TableRowData } from 'tdesign-vue-next';
import { DialogPlugin, MessagePlugin } from 'tdesign-vue-next';
import { computed, defineAsyncComponent, onMounted, reactive, ref, watch } from 'vue';
import { useRoute } from 'vue-router';

import type { CouponPayload, CouponRecord } from '@/api/admin';
import { adminApi } from '@/api/admin';
import type { AdminUser } from '@/api/user';
import { userApi } from '@/api/user';
import MobileRecordCard from '@/components/mobile-record-card/index.vue';
import ProductBindingTreeSelect from '@/components/product-binding-tree-select/index.vue';
import { AdminPermissions } from '@/constants/permissions';
import { useMediaQuery } from '@/hooks/useMediaQuery';
import { formatDateTime } from '@/utils/format';
import { hasAdminPermission } from '@/utils/permission';
import { errorMessage } from '@/utils/userMessage';

const CouponCampaigns = defineAsyncComponent(() => import('@/pages/products/coupon-campaigns/index.vue'));

import './index.less';

interface UserOption {
  value: number;
  title: string;
  meta: string;
  label: string;
}

interface CouponForm {
  id: number | null;
  name: string;
  distribution_type: string;
  discount_scope: string;
  discount_type: string;
  discount_value: number;
  min_amount: number;
  max_discount_amount: number | null;
  billing_cycles: string[];
  product_ids: number[];
  first_order_only: boolean;
  allow_agent: boolean;
  user_ids: number[];
  total_usage_limit: number | null;
  per_user_limit: number | null;
  status: number;
  sort_order: number;
  starts_at: string;
  expires_at: string;
  description: string;
  remark: string;
}

// 与后端 CouponService::SUPPORTED_BILLING_CYCLE_LABELS 同源（均取自可续费周期白名单）
const billingCycleOptions = billingCycleOptionsOf();

// ── Tab 切换（优惠券 / 活动券）──
const route = useRoute();
const activeTab = ref<'coupons' | 'campaigns'>(resolveCouponTab());

function resolveCouponTab() {
  return route.query.tab === 'campaigns' || route.meta.couponTab === 'campaigns' ? 'campaigns' : 'coupons';
}

function syncTabFromRoute() {
  activeTab.value = resolveCouponTab();
}

onMounted(syncTabFromRoute);
watch(() => [route.path, route.query.tab, route.meta.couponTab], syncTabFromRoute);

const loading = ref(false);
const saving = ref(false);
const actionLoading = ref<number | string | null>(null);
const dialogVisible = ref(false);
const formRef = ref<FormInstanceFunctions>();
const coupons = ref<CouponRecord[]>([]);
const total = ref(0);
const page = ref(1);
const pageSize = ref(20);
const couponFeatureEnabled = ref(false);
const userOptions = ref<UserOption[]>([]);
const userSearchResults = ref<UserOption[]>([]);
const userSearchKeyword = ref('');
const userOptionsLoading = ref(false);
const isMobile = useMediaQuery('(max-width: 768px)');
const canManage = computed(() => hasAdminPermission(AdminPermissions.PRODUCT_MANAGE));

const filters = reactive({
  keyword: '',
  status: '',
  discount_type: '',
  discount_scope: '',
  distribution_type: '',
});

const form = reactive<CouponForm>(createDefaultForm());
const lockedFields = ref<string[]>([]);
const lockReason = ref('');

const formRules: Record<string, FormRule[]> = {
  name: [{ required: true, message: '请输入优惠券名称', type: 'error' }],
  distribution_type: [{ required: true, message: '请选择发放方式', type: 'error' }],
  discount_scope: [{ required: true, message: '请选择优惠阶段', type: 'error' }],
  discount_type: [{ required: true, message: '请选择优惠类型', type: 'error' }],
  discount_value: [{ required: true, message: '请输入优惠值', type: 'error' }],
};

const columns: PrimaryTableCol<TableRowData>[] = [
  { colKey: 'id', title: 'ID', width: 72 },
  { colKey: 'coupon', title: '优惠券信息', minWidth: 260 },
  { colKey: 'rule', title: '优惠规则', minWidth: 220 },
  { colKey: 'usage', title: '使用情况', minWidth: 180 },
  { colKey: 'status', title: '状态', width: 110 },
  { colKey: 'validity', title: '有效期', minWidth: 200 },
  { colKey: 'updatedAt', title: '更新时间', minWidth: 170 },
  { colKey: 'operation', title: '操作', width: 160, fixed: 'right' },
];

const selectedUsers = computed(() => {
  return form.user_ids.map((id) => {
    const matched = userOptions.value.find((item) => item.value === Number(id));
    return matched || { value: Number(id), title: `#${id}`, meta: '用户信息加载中', label: `#${id}` };
  });
});

function createDefaultForm(): CouponForm {
  return {
    id: null,
    name: '',
    distribution_type: 'public',
    discount_scope: 'first_month',
    discount_type: 'fixed',
    discount_value: 0,
    min_amount: 0,
    max_discount_amount: null,
    billing_cycles: [],
    product_ids: [],
    first_order_only: false,
    allow_agent: true,
    user_ids: [],
    total_usage_limit: null,
    per_user_limit: null,
    status: 1,
    sort_order: 0,
    starts_at: '',
    expires_at: '',
    description: '',
    remark: '',
  };
}

function resetForm() {
  Object.assign(form, createDefaultForm());
  userSearchKeyword.value = '';
  userSearchResults.value = [];
  lockedFields.value = [];
  lockReason.value = '';
}

function handleDialogClosed() {
  formRef.value?.clearValidate?.();
  resetForm();
}

function closeCouponDrawer() {
  dialogVisible.value = false;
  handleDialogClosed();
}

function couponEditDisabled(row: CouponRecord) {
  return !couponFeatureEnabled.value || !canManage.value || row.can_update === false;
}

function couponDeleteDisabled(row: CouponRecord) {
  return !couponFeatureEnabled.value || !canManage.value || row.can_delete === false;
}

function couponEditDisabledReason(row: CouponRecord) {
  return String(row.lock_reason || '已发放或活动生成的优惠券不允许修改');
}

function couponDeleteDisabledReason(row: CouponRecord) {
  return String(row.delete_reason || '该优惠券当前不允许删除');
}

function isFieldLocked(field: string): boolean {
  return lockedFields.value.includes(field);
}

function couponHasLockedFields(row: CouponRecord): boolean {
  return Array.isArray(row.locked_fields) && row.locked_fields.length > 0;
}

function normalizeUserOption(row: AdminUser): UserOption {
  const id = Number(row.id);
  return {
    value: id,
    title: `#${id} / ${row.email || '未填写邮箱'}`,
    meta: `${row.phone || '未填写手机号'}${row.nickname ? ` / ${row.nickname}` : ''}`,
    label: `#${id} / ${row.email || ''}${row.phone ? ` / ${row.phone}` : ''}${row.nickname ? ` / ${row.nickname}` : ''}`,
  };
}

function mergeUserOptions(items: UserOption[]) {
  const map = new Map(userOptions.value.map((item) => [item.value, item]));
  items.forEach((item) => map.set(item.value, item));
  userOptions.value = Array.from(map.values());
}

async function loadUserOptions(keyword = '') {
  userOptionsLoading.value = true;
  try {
    const trimmed = String(keyword || '').trim();
    const isUserId = /^\d+$/.test(trimmed);
    const response = await userApi.list({
      user_id: isUserId ? Number(trimmed) : undefined,
      keyword: !isUserId ? trimmed || undefined : undefined,
      page_size: 50,
    });
    const items = (response.list || []).map(normalizeUserOption);
    mergeUserOptions(items);
    userSearchResults.value = items;
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '加载用户列表失败'));
  } finally {
    userOptionsLoading.value = false;
  }
}

async function ensureSelectedUsersLoaded(userIds: number[]) {
  const missingIds = userIds.filter((id) => !userOptions.value.some((item) => item.value === Number(id)));
  if (!missingIds.length) return;
  const results = await Promise.all(
    missingIds.map(async (userId) => {
      const response = await userApi.list({ user_id: Number(userId), page_size: 1 });
      const row = response.list?.[0];
      return row ? normalizeUserOption(row) : null;
    }),
  );
  mergeUserOptions(results.filter(Boolean) as UserOption[]);
}

async function loadList() {
  loading.value = true;
  try {
    const response = await adminApi.coupons.list({
      ...filters,
      page: page.value,
      page_size: pageSize.value,
    });
    coupons.value = response.list || [];
    total.value = Number(response.total || 0);
  } catch (error) {
    coupons.value = [];
    total.value = 0;
    MessagePlugin.error(errorMessage(error, '加载优惠券列表失败'));
  } finally {
    loading.value = false;
  }
}

async function loadSummary() {
  try {
    const response = await adminApi.coupons.summary({
      keyword: filters.keyword || undefined,
      discount_type: filters.discount_type || undefined,
      discount_scope: filters.discount_scope || undefined,
      distribution_type: filters.distribution_type || undefined,
    });
    couponFeatureEnabled.value = Boolean(response.enabled);
  } catch (error) {
    couponFeatureEnabled.value = false;
    MessagePlugin.error(errorMessage(error, '加载优惠券开关失败'));
  }
}

async function loadData() {
  await Promise.all([loadList(), loadSummary()]);
}

function handleSearch() {
  page.value = 1;
  loadData();
}

function handlePageChange(data: { current: number; pageSize: number }) {
  page.value = data.current;
  pageSize.value = data.pageSize;
  loadList();
}

async function openCouponDialog(row?: CouponRecord) {
  if (!canManage.value) {
    MessagePlugin.warning('您没有管理优惠券的权限');
    return;
  }
  if (!couponFeatureEnabled.value) {
    MessagePlugin.warning('当前环境未启用优惠券功能');
    return;
  }
  if (row?.can_update === false) {
    MessagePlugin.warning(couponEditDisabledReason(row));
    return;
  }
  resetForm();
  if (row) {
    lockedFields.value = Array.isArray(row.locked_fields) ? [...row.locked_fields] : [];
    lockReason.value = String(row.lock_reason || '');
    form.id = Number(row.id);
    form.name = String(row.name || '');
    form.distribution_type = String(row.distribution_type || 'public');
    form.discount_scope = String(row.discount_scope || 'first_month');
    form.discount_type = String(row.discount_type || 'fixed');
    form.discount_value = Number(row.discount_value_raw ?? row.discount_value ?? 0);
    form.min_amount = Number(row.min_amount_raw ?? row.min_amount ?? 0);
    form.max_discount_amount =
      row.max_discount_amount_raw === null || row.max_discount_amount_raw === undefined
        ? null
        : Number(row.max_discount_amount_raw || 0);
    form.billing_cycles = Array.isArray(row.billing_cycles) ? [...row.billing_cycles] : [];
    form.product_ids = Array.isArray(row.product_ids) ? row.product_ids.map(Number).filter(Boolean) : [];
    form.first_order_only = Boolean(row.first_order_only);
    form.allow_agent = row.allow_agent === undefined || row.allow_agent === null ? true : Boolean(row.allow_agent);
    form.user_ids = Array.isArray(row.user_ids) ? row.user_ids.map(Number).filter(Boolean) : [];
    form.total_usage_limit =
      row.total_usage_limit === null || row.total_usage_limit === undefined ? null : Number(row.total_usage_limit || 0);
    form.per_user_limit =
      row.per_user_limit === null || row.per_user_limit === undefined ? null : Number(row.per_user_limit || 0);
    form.status = Number(row.status ?? 1);
    form.sort_order = Number(row.sort_order || 0);
    form.starts_at = String(row.starts_at || '');
    form.expires_at = String(row.expires_at || '');
    form.description = String(row.description || '');
    form.remark = String(row.remark || '');
  }
  dialogVisible.value = true;
  if (form.user_ids.length) await ensureSelectedUsersLoaded(form.user_ids);
  await loadUserOptions('');
}

function buildPayload(): CouponPayload {
  return {
    name: form.name.trim(),
    description: form.description.trim() || null,
    distribution_type: form.distribution_type,
    discount_scope: form.discount_scope,
    discount_type: form.discount_type,
    discount_value: Number(form.discount_value || 0),
    min_amount: Number(form.min_amount || 0),
    max_discount_amount: form.max_discount_amount === null ? null : Number(form.max_discount_amount || 0),
    billing_cycles: form.billing_cycles,
    product_ids: form.product_ids.map(Number).filter((id) => id > 0),
    first_order_only: Boolean(form.first_order_only),
    allow_agent: Boolean(form.allow_agent),
    user_ids: form.distribution_type === 'private' ? form.user_ids.map(Number).filter((id) => id > 0) : [],
    total_usage_limit: form.total_usage_limit === null ? null : Number(form.total_usage_limit || 0),
    per_user_limit: form.per_user_limit === null ? null : Number(form.per_user_limit || 0),
    status: Number(form.status ?? 1),
    sort_order: Number(form.sort_order || 0),
    starts_at: form.starts_at || null,
    expires_at: form.expires_at || null,
    remark: form.remark.trim() || null,
  };
}

async function submitForm() {
  if (!canManage.value) {
    MessagePlugin.warning('您没有管理优惠券的权限');
    return;
  }
  if (!couponFeatureEnabled.value) {
    MessagePlugin.warning('当前环境未启用优惠券功能');
    return;
  }
  const validateResult = await formRef.value?.validate?.();
  if (validateResult !== true) return;
  if (Number(form.discount_value || 0) <= 0) {
    MessagePlugin.warning('优惠值必须大于 0');
    return;
  }
  if (form.discount_type === 'percentage' && Number(form.discount_value || 0) > 100) {
    MessagePlugin.warning('折扣值不能大于 100');
    return;
  }
  if (form.distribution_type === 'private' && !form.user_ids.length) {
    MessagePlugin.warning('私有优惠券至少需要选择一个发放用户');
    return;
  }
  saving.value = true;
  try {
    const payload = buildPayload();
    if (form.id) {
      await adminApi.coupons.update(form.id, payload);
      MessagePlugin.success('优惠券已更新');
    } else {
      await adminApi.coupons.create(payload);
      MessagePlugin.success('优惠券已创建');
    }
    dialogVisible.value = false;
    await loadData();
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '保存优惠券失败'));
  } finally {
    saving.value = false;
  }
}

async function handleToggleStatus(row: CouponRecord) {
  if (!canManage.value) {
    MessagePlugin.warning('您没有管理优惠券的权限');
    return;
  }
  if (!couponFeatureEnabled.value) {
    MessagePlugin.warning('当前环境未启用优惠券功能');
    return;
  }
  actionLoading.value = row.id;
  try {
    await adminApi.coupons.toggleStatus(row.id, Number(row.status) !== 1);
    MessagePlugin.success('优惠券状态已更新');
    await loadData();
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '更新优惠券状态失败'));
  } finally {
    actionLoading.value = null;
  }
}

function handleDelete(row: CouponRecord) {
  if (!canManage.value) {
    MessagePlugin.warning('您没有管理优惠券的权限');
    return;
  }
  if (!couponFeatureEnabled.value) {
    MessagePlugin.warning('当前环境未启用优惠券功能');
    return;
  }
  if (row.can_delete === false) {
    MessagePlugin.warning(couponDeleteDisabledReason(row));
    return;
  }
  const dialog = DialogPlugin.confirm({
    header: '删除优惠券',
    body: `确认删除优惠券「${row.name || row.id}」吗？未使用的领取和发放记录会一并清理。`,
    theme: 'warning',
    confirmBtn: '确认删除',
    cancelBtn: '取消',
    async onConfirm() {
      actionLoading.value = row.id;
      try {
        await adminApi.coupons.delete(row.id);
        MessagePlugin.success('优惠券已删除');
        dialog.hide();
        await loadData();
      } catch (error) {
        MessagePlugin.error(errorMessage(error, '删除优惠券失败'));
      } finally {
        actionLoading.value = null;
      }
    },
  });
}

function mobileActionOptions(row: CouponRecord) {
  return [
    { content: '编辑', value: 'edit', disabled: couponEditDisabled(row) },
    {
      content: Number(row.status) === 1 ? '停用' : '启用',
      value: 'toggle',
      disabled: !couponFeatureEnabled.value || !canManage.value,
    },
    { content: '删除', value: 'delete', disabled: couponDeleteDisabled(row) },
  ];
}

function couponMobileRows(row: CouponRecord) {
  return [
    { label: '优惠', value: row.discount_label || formatDiscount(row), strong: true },
    {
      label: '类型',
      value: `${row.discount_type_label || discountTypeLabel(row.discount_type)} / ${
        row.discount_scope_label || discountScopeLabel(row.discount_scope)
      }`,
    },
    { label: '门槛', value: moneyText(row.min_amount) },
    { label: '库存', value: row.total_usage_limit ? `${row.remaining_stock ?? 0} / ${row.total_usage_limit}` : '不限' },
    { label: '有效期', value: row.validity_text || validityText(row) },
    { label: '活动', value: row.coupon_campaign_name || '', show: Boolean(row.coupon_campaign_name) },
  ];
}

function handleCouponCardAction(row: CouponRecord, action: unknown) {
  handleMobileAction(action, row);
}

function handleMobileActionHandler(row: CouponRecord) {
  return (data: DropdownOption) => handleMobileAction(data.value, row);
}

function handleMobileAction(action: unknown, row: CouponRecord) {
  if (action === 'edit') openCouponDialog(row);
  if (action === 'toggle') handleToggleStatus(row);
  if (action === 'delete') handleDelete(row);
}

function searchUsers() {
  loadUserOptions(userSearchKeyword.value);
}

function toggleUserSelection(item: UserOption) {
  if (form.user_ids.includes(item.value)) {
    form.user_ids = form.user_ids.filter((id) => id !== item.value);
  } else {
    form.user_ids = [...form.user_ids, item.value];
  }
}

function removeSelectedUser(userId: number) {
  form.user_ids = form.user_ids.filter((id) => id !== Number(userId));
}

function discountTypeLabel(value: unknown) {
  return value === 'percentage' ? '折扣券' : '满减券';
}

function discountScopeLabel(value: unknown) {
  const map: Record<string, string> = { first_month: '首月优惠', recurring: '持续优惠', renew: '续费优惠' };
  return map[String(value || '')] || '-';
}

function distributionLabel(value: unknown) {
  return value === 'private' ? '私有优惠券' : '公开优惠券';
}

function statusTheme(value: unknown) {
  if (value === 'active') return 'success';
  if (value === 'expired') return 'warning';
  return 'default';
}

function statusLabel(row: CouponRecord) {
  if (row.display_status === 'expired') return '已过期';
  return Number(row.status) === 1 ? '生效中' : '已停用';
}

function formatDiscount(row: CouponRecord) {
  const value = Number(row.discount_value_raw ?? row.discount_value ?? 0);
  return row.discount_type === 'percentage' ? `${value}%` : moneyText(value);
}

function moneyText(value: unknown) {
  return `¥${Number(value || 0).toFixed(2)}`;
}

function formatLimitText(limit: unknown, remain: unknown, label: string) {
  if (!limit) return `${label}：不限`;
  if (label === '总量') return `${label}：${limit} 次，剩余 ${remain ?? 0} 次`;
  return `${label}：${limit} 次`;
}

function validityText(row: CouponRecord) {
  if (!row.starts_at && !row.expires_at) return '长期有效';
  return `${row.starts_at || '立即'} 至 ${row.expires_at || '长期'}`;
}

onMounted(async () => {
  await Promise.all([loadData(), loadUserOptions('')]);
});
</script>
