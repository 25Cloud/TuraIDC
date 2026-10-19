<template>
  <div class="referral-page">
    <template v-if="activeTab === 'overview'">
      <section class="referral-metrics">
        <t-card v-for="item in overviewMetrics" :key="item.label" :bordered="false">
          <span>{{ item.label }}</span>
          <strong>{{ item.value }}</strong>
        </t-card>
      </section>

      <t-card :bordered="false" :loading="overviewLoading">
        <template #title>{{ $t('referral.index.topReferrerRanking') }}</template>
        <div class="table-scroll">
          <t-table row-key="id" :data="overview.top_referrers" :columns="overviewColumns" hover table-layout="fixed">
            <template #user="{ row }">
              <div class="stack-cell">
                <strong>{{ userName(row) }}</strong>
                <span>{{ fieldValue(row.email) }}</span>
              </div>
            </template>
            <template #level="{ row }">
              <div class="stack-cell">
                <strong>{{ fieldValue(row.member_level?.name || $t('referral.ungraded')) }}</strong>
                <span>{{
                  row.member_level?.reward_rate
                    ? formatPercent(row.member_level.reward_rate)
                    : $t('referral.defaultRate')
                }}</span>
              </div>
            </template>
            <template #sales="{ row }">{{ formatMoney(row.total_sales_amount) }}</template>
            <template #frozen="{ row }">{{ formatMoney(row.referral_frozen_amount) }}</template>
            <template #available="{ row }">{{ formatMoney(row.referral_available_amount) }}</template>
            <template #withdrawing="{ row }">{{ formatMoney(row.referral_withdrawing_amount) }}</template>
            <template #withdrawn="{ row }">{{ formatMoney(row.referral_withdrawn_amount) }}</template>
          </t-table>
        </div>
      </t-card>
    </template>

    <template v-else-if="activeTab === 'rewards'">
      <t-card :bordered="false">
        <div class="referral-filter">
          <t-input
            v-model="rewardFilters.keyword"
            clearable
            :placeholder="$t('referral.index.rewardSearchPlaceholder')"
            @enter="handleRewardSearch"
            @clear="handleRewardSearch"
          >
            <template #suffix-icon><search-icon /></template>
          </t-input>
          <t-select
            v-model="rewardFilters.status"
            clearable
            :placeholder="$t('referral.rewardStatus')"
            @change="handleRewardSearch"
          >
            <t-option v-for="item in rewardStatusOptions" :key="item.value" :label="item.label" :value="item.value" />
          </t-select>
        </div>
      </t-card>

      <t-card :bordered="false" :loading="rewardsLoading">
        <template #title>{{ $t('referral.index.rewardRecords') }}</template>
        <div v-if="!isMobile" class="table-scroll">
          <t-table row-key="id" :data="rewardList" :columns="rewardColumns" hover table-layout="fixed">
            <template #id="{ row }">
              <button
                type="button"
                class="user-link"
                :disabled="!isEntityId(row.referred_user?.id)"
                @click="goRewardUser(row)"
              >
                <strong>{{ fieldValue(row.id) }}</strong>
              </button>
            </template>
            <template #relation="{ row }">
              <div class="stack-cell">
                <strong>{{ userName(row.referrer) }}</strong>
                <span>{{ $t('referral.subordinate') }}：{{ userName(row.referred_user) }}</span>
              </div>
            </template>
            <template #order="{ row }">
              <div class="stack-cell">
                <button
                  type="button"
                  class="user-link"
                  :disabled="!isEntityId(row.order?.id)"
                  @click="goRewardOrder(row)"
                >
                  <strong>{{ fieldValue(row.order?.order_no) }}</strong>
                  <span>{{ rewardProductName(row) }}</span>
                </button>
              </div>
            </template>
            <template #orderAmount="{ row }">{{ formatMoney(row.order_amount) }}</template>
            <template #rate="{ row }">{{ formatPercent(row.reward_rate) }}</template>
            <template #amount="{ row }">{{ formatMoney(row.reward_amount) }}</template>
            <template #status="{ row }">
              <t-tag :theme="rewardStatusTheme(row.status)" variant="light">{{ rewardStatusLabel(row.status) }}</t-tag>
            </template>
            <template #time="{ row }">
              <div class="stack-cell">
                <strong>{{ fieldValue(row.rewarded_at) }}</strong>
                <span>{{
                  row.released_at
                    ? $t('referral.releasedAt', { time: row.released_at })
                    : $t('referral.availableAt', { time: fieldValue(row.available_at) })
                }}</span>
              </div>
            </template>
            <template #remark="{ row }">{{ fieldValue(row.remark) }}</template>
          </t-table>
        </div>

        <div v-else class="mobile-list">
          <article v-for="row in rewardList" :key="row.id" class="referral-mobile-card">
            <div class="referral-mobile-card__head">
              <strong>{{ userName(row.referrer) }}</strong>
              <t-tag :theme="rewardStatusTheme(row.status)" variant="light">{{ rewardStatusLabel(row.status) }}</t-tag>
            </div>
            <dl>
              <div>
                <dt>{{ $t('referral.subordinate') }}</dt>
                <dd>{{ userName(row.referred_user) }}</dd>
              </div>
              <div>
                <dt>{{ $t('referral.order') }}</dt>
                <dd>{{ fieldValue(row.order?.order_no) }}</dd>
              </div>
              <div>
                <dt>{{ $t('referral.rewardAmount') }}</dt>
                <dd>{{ formatMoney(row.reward_amount) }}</dd>
              </div>
              <div>
                <dt>{{ $t('referral.rewardRate') }}</dt>
                <dd>{{ formatPercent(row.reward_rate) }}</dd>
              </div>
            </dl>
          </article>
        </div>

        <div v-if="rewardTotal > 0" class="pagination-row">
          <t-pagination
            :current="rewardPagination.page"
            :page-size="rewardPagination.page_size"
            :total="rewardTotal"
            :page-size-options="[20, 50, 100]"
            show-jumper
            @change="handleRewardPageChange"
          />
        </div>
      </t-card>
    </template>

    <template v-else>
      <t-card :bordered="false">
        <div class="referral-filter">
          <t-input
            v-model="withdrawalFilters.keyword"
            clearable
            :placeholder="$t('referral.index.withdrawalSearchPlaceholder')"
            @enter="handleWithdrawalSearch"
            @clear="handleWithdrawalSearch"
          >
            <template #suffix-icon><search-icon /></template>
          </t-input>
          <t-select
            v-model="withdrawalFilters.status"
            clearable
            :placeholder="$t('referral.withdrawStatus')"
            @change="handleWithdrawalSearch"
          >
            <t-option
              v-for="item in withdrawalStatusOptions"
              :key="item.value"
              :label="item.label"
              :value="item.value"
            />
          </t-select>
        </div>
      </t-card>

      <t-card :bordered="false" :loading="withdrawalsLoading">
        <template #title>{{ $t('referral.index.withdrawalRecords') }}</template>
        <div v-if="!isMobile" class="table-scroll">
          <t-table row-key="id" :data="withdrawalList" :columns="withdrawalColumns" hover table-layout="fixed">
            <template #id="{ row }">
              <button
                type="button"
                class="user-link"
                :disabled="!isEntityId(row.user?.id)"
                @click="goWithdrawalUser(row)"
              >
                <strong>{{ fieldValue(row.id) }}</strong>
              </button>
            </template>
            <template #user="{ row }">
              <div class="stack-cell">
                <button
                  type="button"
                  class="user-link"
                  :disabled="!isEntityId(row.user?.id)"
                  @click="goWithdrawalUser(row)"
                >
                  <strong>{{ userName(row.user) }}</strong>
                  <span>{{ fieldValue(row.user?.email) }}</span>
                </button>
              </div>
            </template>
            <template #amount="{ row }">{{ formatMoney(row.amount) }}</template>
            <template #method="{ row }">
              <div class="stack-cell">
                <strong>{{ withdrawalMethodLabel(row.method) }}</strong>
                <span>{{ fieldValue(row.account_name) }} / {{ fieldValue(row.account_no) }}</span>
              </div>
            </template>
            <template #status="{ row }">
              <t-tag :theme="withdrawalStatusTheme(row.status)" variant="light">{{
                withdrawalStatusLabel(row.status)
              }}</t-tag>
            </template>
            <template #time="{ row }">
              <div class="stack-cell">
                <strong>{{ formatDateTime(row.created_at) }}</strong>
                <span>{{
                  row.processed_at
                    ? $t('referral.processedAt', { time: formatDateTime(row.processed_at) })
                    : $t('referral.pendingReview')
                }}</span>
              </div>
            </template>
            <template #operator="{ row }">
              <div class="stack-cell">
                <strong>{{ fieldValue(row.operator) }}</strong>
                <span>{{ fieldValue(row.remark) }}</span>
              </div>
            </template>
            <template #actions="{ row }">
              <t-space v-if="Number(row.status) === 0" size="small">
                <t-button theme="primary" variant="text" @click="openWithdrawalDialog('approve', row)">{{
                  $t('referral.index.actionApprove')
                }}</t-button>
                <t-button theme="danger" variant="text" @click="openWithdrawalDialog('reject', row)">{{
                  $t('referral.index.actionReject')
                }}</t-button>
              </t-space>
              <span v-else class="muted-text">{{ $t('referral.index.alreadyProcessed') }}</span>
            </template>
          </t-table>
        </div>

        <div v-else class="mobile-list">
          <article v-for="row in withdrawalList" :key="row.id" class="referral-mobile-card">
            <div class="referral-mobile-card__head">
              <strong>{{ userName(row.user) }}</strong>
              <t-tag :theme="withdrawalStatusTheme(row.status)" variant="light">{{
                withdrawalStatusLabel(row.status)
              }}</t-tag>
            </div>
            <dl>
              <div>
                <dt>{{ $t('referral.amount') }}</dt>
                <dd>{{ formatMoney(row.amount) }}</dd>
              </div>
              <div>
                <dt>{{ $t('referral.method') }}</dt>
                <dd>{{ withdrawalMethodLabel(row.method) }}</dd>
              </div>
              <div>
                <dt>{{ $t('referral.account') }}</dt>
                <dd>{{ fieldValue(row.account_no) }}</dd>
              </div>
              <div>
                <dt>{{ $t('referral.appliedAt') }}</dt>
                <dd>{{ formatDateTime(row.created_at) }}</dd>
              </div>
            </dl>
            <div v-if="Number(row.status) === 0" class="referral-mobile-card__actions">
              <t-button theme="primary" variant="outline" @click="openWithdrawalDialog('approve', row)">{{
                $t('referral.approve')
              }}</t-button>
              <t-button theme="danger" variant="outline" @click="openWithdrawalDialog('reject', row)">{{
                $t('referral.reject')
              }}</t-button>
            </div>
          </article>
        </div>

        <div v-if="withdrawalTotal > 0" class="pagination-row">
          <t-pagination
            :current="withdrawalPagination.page"
            :page-size="withdrawalPagination.page_size"
            :total="withdrawalTotal"
            :page-size-options="[20, 50, 100]"
            show-jumper
            @change="handleWithdrawalPageChange"
          />
        </div>
      </t-card>
    </template>

    <t-dialog
      v-model:visible="withdrawalDialog.visible"
      :header="withdrawalDialog.mode === 'approve' ? $t('referral.approveTitle') : $t('referral.rejectTitle')"
      width="520px"
      :confirm-btn="{
        content: withdrawalDialog.mode === 'approve' ? $t('referral.confirmApprove') : $t('referral.confirmReject'),
        theme: withdrawalDialog.mode === 'approve' ? 'primary' : 'danger',
      }"
      :confirm-loading="withdrawalSubmitting"
      @confirm="submitWithdrawalAction"
    >
      <div class="withdrawal-dialog">
        <p>
          {{
            $t('referral.applicationInfo', {
              id: withdrawalDialog.row?.id || '-',
              amount: formatMoney(withdrawalDialog.row?.amount),
            })
          }}
        </p>
        <t-textarea
          v-model="withdrawalDialog.remark"
          :placeholder="
            withdrawalDialog.mode === 'approve'
              ? $t('referral.approveRemarkPlaceholder')
              : $t('referral.rejectReasonPlaceholder')
          "
          :autosize="{ minRows: 3, maxRows: 5 }"
          :maxlength="255"
        />
      </div>
    </t-dialog>
  </div>
</template>
<script setup lang="ts">
import './index.less';

import { REWARD_STATUS_MAP, toLabelMap, toSelectOptions, toTagTypeMap } from '@shared/statusConfig';
import { SearchIcon } from 'tdesign-icons-vue-next';
import type { PrimaryTableCol, TableRowData, TagProps } from 'tdesign-vue-next';
import { MessagePlugin } from 'tdesign-vue-next';
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';

import type { ReferralOverview, ReferralRewardRecord, ReferralWithdrawalRecord } from '@/api/admin';
import { adminApi } from '@/api/admin';
import { useMediaQuery } from '@/hooks/useMediaQuery';
import { t } from '@/locales';
import { fieldValue, formatDateTime, formatMoney } from '@/utils/format';
import { goOrder, goUser, isEntityId } from '@/utils/route/adminEntityLink';
import { errorMessage } from '@/utils/userMessage';

defineOptions({ name: 'AdminReferral' });

type ReferralTab = 'overview' | 'rewards' | 'withdrawals';
type WithdrawalMode = 'approve' | 'reject';
type ThemeType = NonNullable<TagProps['theme']>;

const route = useRoute();
const router = useRouter();
const validTabs: ReferralTab[] = ['overview', 'rewards', 'withdrawals'];
const activeTab = ref<ReferralTab>(resolveRouteTab());

function normalizeTab(value: unknown): ReferralTab | null {
  return validTabs.includes(value as ReferralTab) ? (value as ReferralTab) : null;
}

function resolveRouteTab(): ReferralTab {
  return normalizeTab(route.query.tab) || normalizeTab(route.meta.referralTab) || 'overview';
}

watch(
  () => [route.path, route.query.tab, route.meta.referralTab],
  () => {
    const next = resolveRouteTab();
    if (activeTab.value !== next) {
      activeTab.value = next;
      refreshCurrentTab();
    }
  },
);
const overviewLoading = ref(false);
const rewardsLoading = ref(false);
const withdrawalsLoading = ref(false);
const withdrawalSubmitting = ref(false);

const overview = ref<ReferralOverview>({
  summary: {},
  top_referrers: [],
});
const rewardList = ref<ReferralRewardRecord[]>([]);
const rewardTotal = ref(0);
const withdrawalList = ref<ReferralWithdrawalRecord[]>([]);
const withdrawalTotal = ref(0);

const rewardFilters = reactive({
  keyword: '',
  status: '',
});
const withdrawalFilters = reactive({
  keyword: '',
  status: '',
});
const rewardPagination = reactive({
  page: 1,
  page_size: 20,
});
const withdrawalPagination = reactive({
  page: 1,
  page_size: 20,
});
const withdrawalDialog = reactive<{
  visible: boolean;
  mode: WithdrawalMode;
  row: ReferralWithdrawalRecord | null;
  remark: string;
}>({
  visible: false,
  mode: 'approve',
  row: null,
  remark: '',
});

const isMobile = useMediaQuery('(max-width: 768px)');

const rewardLabelMap = toLabelMap(REWARD_STATUS_MAP);
const rewardTypeMap = toTagTypeMap(REWARD_STATUS_MAP);
const rewardStatusOptions = computed(() => toSelectOptions(REWARD_STATUS_MAP, false));
const withdrawalStatusOptions = [
  { label: t('referral.index.statusPending'), value: 0 },
  { label: t('referral.index.statusApproved'), value: 1 },
  { label: t('referral.index.statusRejected'), value: 2 },
];

const overviewMetrics = computed(() => {
  const summary = toRecord(overview.value.summary);
  return [
    { label: t('referral.index.totalSales'), value: formatMoney(summary.total_sales_amount) },
    { label: t('referral.index.frozenReward'), value: formatMoney(summary.frozen_amount) },
    { label: t('referral.index.availableReward'), value: formatMoney(summary.available_amount) },
    { label: t('referral.index.withdrawnReward'), value: formatMoney(summary.withdrawn_amount) },
  ];
});

const overviewColumns: PrimaryTableCol<Record<string, unknown>>[] = [
  { colKey: 'user', title: t('referral.index.promotedUser'), minWidth: 220 },
  { colKey: 'level', title: t('referral.index.memberLevel'), minWidth: 150 },
  { colKey: 'sales', title: t('referral.index.totalSales'), width: 130 },
  { colKey: 'frozen', title: t('referral.index.frozen'), width: 120 },
  { colKey: 'available', title: t('referral.index.available'), width: 120 },
  { colKey: 'withdrawing', title: t('referral.index.withdrawing'), width: 120 },
  { colKey: 'withdrawn', title: t('referral.index.withdrawn'), width: 120 },
];

const rewardColumns: PrimaryTableCol<TableRowData>[] = [
  { colKey: 'id', title: 'ID', width: 80 },
  { colKey: 'relation', title: t('referral.index.relation'), minWidth: 220 },
  { colKey: 'order', title: t('referral.index.orderSpec'), minWidth: 220 },
  { colKey: 'orderAmount', title: t('referral.index.orderAmount'), width: 120 },
  { colKey: 'rate', title: t('referral.index.rate'), width: 100 },
  { colKey: 'amount', title: t('referral.index.rewardAmount'), width: 120 },
  { colKey: 'status', title: t('common.status'), width: 110 },
  { colKey: 'time', title: t('common.time'), minWidth: 190 },
  { colKey: 'remark', title: t('referral.index.remark'), minWidth: 180 },
];

const withdrawalColumns: PrimaryTableCol<TableRowData>[] = [
  { colKey: 'id', title: 'ID', width: 80 },
  { colKey: 'user', title: t('referral.index.applicant'), minWidth: 220 },
  { colKey: 'amount', title: t('referral.index.withdrawAmount'), width: 120 },
  { colKey: 'method', title: t('referral.index.withdrawMethod'), minWidth: 220 },
  { colKey: 'status', title: t('common.status'), width: 110 },
  { colKey: 'time', title: t('referral.index.appliedAt'), minWidth: 220 },
  { colKey: 'operator', title: t('referral.index.reviewInfo'), minWidth: 200 },
  { colKey: 'actions', title: t('common.action'), fixed: 'right', width: 130 },
];

/** 奖励记录：ID 点进被推荐用户，订单列点进订单详情。 */
function goRewardUser(row: ReferralRewardRecord) {
  goUser(router, row.referred_user?.id);
}

function goRewardOrder(row: ReferralRewardRecord) {
  goOrder(router, row.order?.id);
}

/** 提现记录：ID 列与申请人列都指向同一个申请人用户详情。 */
function goWithdrawalUser(row: ReferralWithdrawalRecord) {
  goUser(router, row.user?.id);
}

async function loadOverview() {
  overviewLoading.value = true;
  try {
    const response = await adminApi.referral.overview();
    overview.value = {
      summary: response.summary || {},
      top_referrers: response.top_referrers || [],
    };
  } catch (error) {
    MessagePlugin.error(errorMessage(error, t('referral.loadOverviewFailed')));
  } finally {
    overviewLoading.value = false;
  }
}

async function loadRewards() {
  rewardsLoading.value = true;
  try {
    const response = await adminApi.referral.rewards(buildRewardParams());
    rewardList.value = response.list || [];
    rewardTotal.value = Number(response.total || 0);
    rewardPagination.page = Number(response.page || rewardPagination.page);
    rewardPagination.page_size = Number(response.page_size || rewardPagination.page_size);
  } catch (error) {
    MessagePlugin.error(errorMessage(error, t('referral.loadRewardsFailed')));
  } finally {
    rewardsLoading.value = false;
  }
}

async function loadWithdrawals() {
  withdrawalsLoading.value = true;
  try {
    const response = await adminApi.referral.withdrawals(buildWithdrawalParams());
    withdrawalList.value = response.list || [];
    withdrawalTotal.value = Number(response.total || 0);
    withdrawalPagination.page = Number(response.page || withdrawalPagination.page);
    withdrawalPagination.page_size = Number(response.page_size || withdrawalPagination.page_size);
  } catch (error) {
    MessagePlugin.error(errorMessage(error, t('referral.loadWithdrawalsFailed')));
  } finally {
    withdrawalsLoading.value = false;
  }
}

async function loadAll() {
  await Promise.allSettled([loadOverview(), loadRewards(), loadWithdrawals()]);
}

function refreshCurrentTab() {
  if (activeTab.value === 'overview') return loadOverview();
  if (activeTab.value === 'rewards') return loadRewards();
  return loadWithdrawals();
}

function handleRewardSearch() {
  rewardPagination.page = 1;
  loadRewards();
}

function handleWithdrawalSearch() {
  withdrawalPagination.page = 1;
  loadWithdrawals();
}

function handleRewardPageChange(data: { current: number; pageSize: number }) {
  rewardPagination.page = data.current;
  rewardPagination.page_size = data.pageSize;
  loadRewards();
}

function handleWithdrawalPageChange(data: { current: number; pageSize: number }) {
  withdrawalPagination.page = data.current;
  withdrawalPagination.page_size = data.pageSize;
  loadWithdrawals();
}

function buildRewardParams() {
  const params: Record<string, unknown> = {
    page: rewardPagination.page,
    page_size: rewardPagination.page_size,
  };
  if (rewardFilters.keyword.trim()) params.keyword = rewardFilters.keyword.trim();
  if (rewardFilters.status !== '') params.status = rewardFilters.status;
  return params;
}

function buildWithdrawalParams() {
  const params: Record<string, unknown> = {
    page: withdrawalPagination.page,
    page_size: withdrawalPagination.page_size,
  };
  if (withdrawalFilters.keyword.trim()) params.keyword = withdrawalFilters.keyword.trim();
  if (withdrawalFilters.status !== '') params.status = withdrawalFilters.status;
  return params;
}

function openWithdrawalDialog(mode: WithdrawalMode, row: ReferralWithdrawalRecord) {
  withdrawalDialog.mode = mode;
  withdrawalDialog.row = row;
  withdrawalDialog.remark = '';
  withdrawalDialog.visible = true;
}

async function submitWithdrawalAction() {
  if (!withdrawalDialog.row) return;
  const remark = withdrawalDialog.remark.trim();
  if (withdrawalDialog.mode === 'reject' && !remark) {
    MessagePlugin.warning(t('referral.index.rejectReasonRequired'));
    return;
  }

  withdrawalSubmitting.value = true;
  try {
    if (withdrawalDialog.mode === 'approve') {
      await adminApi.referral.approveWithdrawal(withdrawalDialog.row.id, { remark: remark || undefined });
      MessagePlugin.success(t('referral.index.withdrawApproved'));
    } else {
      await adminApi.referral.rejectWithdrawal(withdrawalDialog.row.id, { remark });
      MessagePlugin.success(t('referral.index.withdrawRejected'));
    }
    withdrawalDialog.visible = false;
    await Promise.allSettled([loadOverview(), loadWithdrawals()]);
  } catch (error) {
    MessagePlugin.error(errorMessage(error, t('referral.handleWithdrawalFailed')));
  } finally {
    withdrawalSubmitting.value = false;
  }
}

function rewardStatusLabel(status: unknown) {
  return rewardLabelMap[String(status ?? '')] || fieldValue(status);
}

function rewardStatusTheme(status: unknown): ThemeType {
  const value = rewardTypeMap[String(status ?? '')] || 'default';
  if (value === 'purple') return 'primary';
  if (value === 'warning' || value === 'success' || value === 'danger') return value;
  return 'default';
}

function withdrawalStatusLabel(status: unknown) {
  const labels: Record<string, string> = {
    0: t('referral.statusPending'),
    1: t('referral.statusApproved'),
    2: t('referral.statusRejected'),
  };
  return labels[String(status ?? '')] || fieldValue(status);
}

function withdrawalStatusTheme(status: unknown): ThemeType {
  const themes: Record<string, 'warning' | 'success' | 'danger' | 'default'> = {
    0: 'warning',
    1: 'success',
    2: 'danger',
  };
  return themes[String(status ?? '')] || 'default';
}

function withdrawalMethodLabel(method: unknown) {
  const labels: Record<string, string> = {
    alipay: t('referral.alipay'),
    balance: t('referral.balanceTransfer'),
  };
  return labels[String(method ?? '')] || fieldValue(method);
}

function rewardProductName(row: ReferralRewardRecord) {
  return fieldValue(row.order?.product_spec_display || row.order?.product_display_name || row.product?.display_name);
}

function userName(user: unknown) {
  const record = toRecord(user);
  return fieldValue(
    record.display_name ||
      record.nickname ||
      record.email ||
      (record.id ? t('referral.userFallback', { id: record.id }) : ''),
  );
}

function formatPercent(value: unknown) {
  return `${Number(value || 0).toFixed(2)}%`;
}

function toRecord(value: unknown): Record<string, unknown> {
  return value && typeof value === 'object' ? (value as Record<string, unknown>) : {};
}

onMounted(loadAll);
</script>
