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
        <template #title>推广达人排行</template>
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
                <strong>{{ fieldValue(row.member_level?.name || '未分级') }}</strong>
                <span>{{
                  row.member_level?.reward_rate ? formatPercent(row.member_level.reward_rate) : '默认比例'
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
            placeholder="搜索推荐人 / 被推荐人 / 账单号"
            @enter="handleRewardSearch"
            @clear="handleRewardSearch"
          >
            <template #suffix-icon><search-icon /></template>
          </t-input>
          <t-select v-model="rewardFilters.status" clearable placeholder="奖励状态" @change="handleRewardSearch">
            <t-option v-for="item in rewardStatusOptions" :key="item.value" :label="item.label" :value="item.value" />
          </t-select>
        </div>
      </t-card>

      <t-card :bordered="false" :loading="rewardsLoading">
        <template #title>奖励记录</template>
        <div v-if="!isMobile" class="table-scroll">
          <t-table row-key="id" :data="rewardList" :columns="rewardColumns" hover table-layout="fixed">
            <template #relation="{ row }">
              <div class="stack-cell">
                <strong>{{ userName(row.referrer) }}</strong>
                <span>下级：{{ userName(row.referred_user) }}</span>
              </div>
            </template>
            <template #order="{ row }">
              <div class="stack-cell">
                <strong>{{ fieldValue(row.order?.order_no) }}</strong>
                <span>{{ rewardProductName(row) }}</span>
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
                  row.released_at ? `释放：${row.released_at}` : `可用：${fieldValue(row.available_at)}`
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
                <dt>下级</dt>
                <dd>{{ userName(row.referred_user) }}</dd>
              </div>
              <div>
                <dt>订单</dt>
                <dd>{{ fieldValue(row.order?.order_no) }}</dd>
              </div>
              <div>
                <dt>奖励金额</dt>
                <dd>{{ formatMoney(row.reward_amount) }}</dd>
              </div>
              <div>
                <dt>奖励比例</dt>
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
            placeholder="搜索用户 / 邮箱 / 账号 / 备注"
            @enter="handleWithdrawalSearch"
            @clear="handleWithdrawalSearch"
          >
            <template #suffix-icon><search-icon /></template>
          </t-input>
          <t-select
            v-model="withdrawalFilters.status"
            clearable
            placeholder="提现状态"
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
        <template #title>提现记录</template>
        <div v-if="!isMobile" class="table-scroll">
          <t-table row-key="id" :data="withdrawalList" :columns="withdrawalColumns" hover table-layout="fixed">
            <template #user="{ row }">
              <div class="stack-cell">
                <strong>{{ userName(row.user) }}</strong>
                <span>{{ fieldValue(row.user?.email) }}</span>
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
                <span>{{ row.processed_at ? `处理：${formatDateTime(row.processed_at)}` : '待审核' }}</span>
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
                <t-button theme="primary" variant="text" @click="openWithdrawalDialog('approve', row)">通过</t-button>
                <t-button theme="danger" variant="text" @click="openWithdrawalDialog('reject', row)">拒绝</t-button>
              </t-space>
              <span v-else class="muted-text">已处理</span>
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
                <dt>金额</dt>
                <dd>{{ formatMoney(row.amount) }}</dd>
              </div>
              <div>
                <dt>方式</dt>
                <dd>{{ withdrawalMethodLabel(row.method) }}</dd>
              </div>
              <div>
                <dt>账号</dt>
                <dd>{{ fieldValue(row.account_no) }}</dd>
              </div>
              <div>
                <dt>申请时间</dt>
                <dd>{{ formatDateTime(row.created_at) }}</dd>
              </div>
            </dl>
            <div v-if="Number(row.status) === 0" class="referral-mobile-card__actions">
              <t-button theme="primary" variant="outline" @click="openWithdrawalDialog('approve', row)">通过</t-button>
              <t-button theme="danger" variant="outline" @click="openWithdrawalDialog('reject', row)">拒绝</t-button>
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
      :header="withdrawalDialog.mode === 'approve' ? '通过提现申请' : '拒绝提现申请'"
      width="520px"
      :confirm-btn="{
        content: withdrawalDialog.mode === 'approve' ? '确认通过' : '确认拒绝',
        theme: withdrawalDialog.mode === 'approve' ? 'primary' : 'danger',
      }"
      :confirm-loading="withdrawalSubmitting"
      @confirm="submitWithdrawalAction"
    >
      <div class="withdrawal-dialog">
        <p>申请 #{{ withdrawalDialog.row?.id || '-' }}， 金额 {{ formatMoney(withdrawalDialog.row?.amount) }}</p>
        <t-textarea
          v-model="withdrawalDialog.remark"
          :placeholder="withdrawalDialog.mode === 'approve' ? '通过备注，可留空' : '拒绝原因，必填'"
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
import { useRoute } from 'vue-router';

import type { ReferralOverview, ReferralRewardRecord, ReferralWithdrawalRecord } from '@/api/admin';
import { adminApi } from '@/api/admin';
import { useMediaQuery } from '@/hooks/useMediaQuery';
import { fieldValue, formatDateTime, formatMoney } from '@/utils/format';
import { errorMessage } from '@/utils/userMessage';

defineOptions({ name: 'AdminReferral' });

type ReferralTab = 'overview' | 'rewards' | 'withdrawals';
type WithdrawalMode = 'approve' | 'reject';
type ThemeType = NonNullable<TagProps['theme']>;

const route = useRoute();
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
  { label: '待审核', value: 0 },
  { label: '已通过', value: 1 },
  { label: '已拒绝', value: 2 },
];

const overviewMetrics = computed(() => {
  const summary = toRecord(overview.value.summary);
  return [
    { label: '累计销售额', value: formatMoney(summary.total_sales_amount) },
    { label: '冻结奖励', value: formatMoney(summary.frozen_amount) },
    { label: '可提现奖励', value: formatMoney(summary.available_amount) },
    { label: '已提现奖励', value: formatMoney(summary.withdrawn_amount) },
  ];
});

const overviewColumns: PrimaryTableCol<Record<string, unknown>>[] = [
  { colKey: 'user', title: '推广用户', minWidth: 220 },
  { colKey: 'level', title: '会员等级', minWidth: 150 },
  { colKey: 'sales', title: '累计销售额', width: 130 },
  { colKey: 'frozen', title: '冻结中', width: 120 },
  { colKey: 'available', title: '可提现', width: 120 },
  { colKey: 'withdrawing', title: '提现中', width: 120 },
  { colKey: 'withdrawn', title: '已提现', width: 120 },
];

const rewardColumns: PrimaryTableCol<TableRowData>[] = [
  { colKey: 'id', title: 'ID', width: 80 },
  { colKey: 'relation', title: '推荐关系', minWidth: 220 },
  { colKey: 'order', title: '订单 / 配置', minWidth: 220 },
  { colKey: 'orderAmount', title: '订单金额', width: 120 },
  { colKey: 'rate', title: '比例', width: 100 },
  { colKey: 'amount', title: '奖励金额', width: 120 },
  { colKey: 'status', title: '状态', width: 110 },
  { colKey: 'time', title: '时间', minWidth: 190 },
  { colKey: 'remark', title: '备注', minWidth: 180 },
];

const withdrawalColumns: PrimaryTableCol<TableRowData>[] = [
  { colKey: 'id', title: 'ID', width: 80 },
  { colKey: 'user', title: '申请用户', minWidth: 220 },
  { colKey: 'amount', title: '提现金额', width: 120 },
  { colKey: 'method', title: '提现方式', minWidth: 220 },
  { colKey: 'status', title: '状态', width: 110 },
  { colKey: 'time', title: '申请 / 处理时间', minWidth: 220 },
  { colKey: 'operator', title: '处理信息', minWidth: 200 },
  { colKey: 'actions', title: '操作', fixed: 'right', width: 130 },
];

async function loadOverview() {
  overviewLoading.value = true;
  try {
    const response = await adminApi.referral.overview();
    overview.value = {
      summary: response.summary || {},
      top_referrers: response.top_referrers || [],
    };
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '加载推广概览失败'));
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
    MessagePlugin.error(errorMessage(error, '加载奖励记录失败'));
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
    MessagePlugin.error(errorMessage(error, '加载提现记录失败'));
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
    MessagePlugin.warning('请输入拒绝原因');
    return;
  }

  withdrawalSubmitting.value = true;
  try {
    if (withdrawalDialog.mode === 'approve') {
      await adminApi.referral.approveWithdrawal(withdrawalDialog.row.id, { remark: remark || undefined });
      MessagePlugin.success('提现申请已通过');
    } else {
      await adminApi.referral.rejectWithdrawal(withdrawalDialog.row.id, { remark });
      MessagePlugin.success('提现申请已拒绝');
    }
    withdrawalDialog.visible = false;
    await Promise.allSettled([loadOverview(), loadWithdrawals()]);
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '处理提现申请失败'));
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
    0: '待审核',
    1: '已通过',
    2: '已拒绝',
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
    alipay: '支付宝',
    balance: '余额转回',
  };
  return labels[String(method ?? '')] || fieldValue(method);
}

function rewardProductName(row: ReferralRewardRecord) {
  return fieldValue(row.order?.product_spec_display || row.order?.product_display_name || row.product?.display_name);
}

function userName(user: unknown) {
  const record = toRecord(user);
  return fieldValue(record.display_name || record.nickname || record.email || (record.id ? `用户 #${record.id}` : ''));
}

function formatPercent(value: unknown) {
  return `${Number(value || 0).toFixed(2)}%`;
}

function toRecord(value: unknown): Record<string, unknown> {
  return value && typeof value === 'object' ? (value as Record<string, unknown>) : {};
}

onMounted(loadAll);
</script>
