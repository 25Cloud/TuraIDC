<template>
  <div class="auto-refund-page">
    <t-card :bordered="false">
      <div class="auto-refund-filter">
        <t-input
          v-model="filters.keyword"
          class="filter-keyword"
          clearable
          :placeholder="$t('autoRefund.filterKeyword')"
          @enter="handleSearch"
          @clear="handleSearch"
        >
          <template #suffix-icon><search-icon /></template>
        </t-input>
        <t-select
          v-model="filters.status"
          class="filter-status"
          clearable
          :placeholder="$t('autoRefund.filterStatus')"
          @change="handleSearch"
        >
          <t-option v-for="item in statusOptions" :key="item.value" :label="item.label" :value="item.value" />
        </t-select>
        <t-select
          v-model="filters.refund_type"
          class="filter-refund-type"
          clearable
          :placeholder="$t('autoRefund.filterRefundType')"
          @change="handleSearch"
        >
          <t-option v-for="item in refundTypeFilters" :key="item.value" :label="item.label" :value="item.value" />
        </t-select>
        <t-select
          v-model="filters.channel"
          class="filter-channel"
          clearable
          :placeholder="$t('autoRefund.filterChannel')"
          @change="handleSearch"
        >
          <t-option v-for="item in channelFilters" :key="item.value" :label="item.label" :value="item.value" />
        </t-select>
        <t-date-picker
          v-model="filters.start_date"
          class="filter-start"
          clearable
          mode="date"
          format="YYYY-MM-DD"
          value-type="YYYY-MM-DD"
          :placeholder="$t('autoRefund.startDate')"
          @change="handleSearch"
        />
        <t-date-picker
          v-model="filters.end_date"
          class="filter-end"
          clearable
          mode="date"
          format="YYYY-MM-DD"
          value-type="YYYY-MM-DD"
          :placeholder="$t('autoRefund.endDate')"
          @change="handleSearch"
        />
      </div>

      <div class="admin-list-toolbar">
        <div v-if="canGovern" class="auto-refund-toolbar">
          <t-button variant="outline" :loading="upstreamTest.loading" @click="runUpstreamTest">
            {{ $t('autoRefund.upstreamTest.button') }}
          </t-button>
          <t-button variant="outline" @click="upstreamProductsVisible = true">
            {{ $t('autoRefund.upstreamProducts.button') }}
          </t-button>
        </div>
        <admin-view-mode-switch v-if="!isMobile" inline :mode="viewMode" @change="setViewMode" />
      </div>

      <!-- 上游自检结果就地展示：机器数与服务器时间差是判断「能不能结算」的前置信息 -->
      <t-alert
        v-if="upstreamTestMessage"
        class="auto-refund-upstream-alert"
        :theme="upstreamTest.result?.ok ? 'success' : 'error'"
        :message="upstreamTestMessage"
        close
        @close="upstreamTest.result = null"
      />

      <div v-if="!isMobile && !isCardView" class="table-scroll">
        <t-table row-key="id" :data="list" :columns="columns" :loading="loading" hover table-layout="fixed">
          <template #application_no="{ row }">
            <button type="button" class="auto-refund-link" @click="openDetail(row)">
              <strong>{{ fieldValue(row.application_no) }}</strong>
            </button>
          </template>
          <template #user="{ row }">
            <div class="auto-refund-stack">
              <button
                type="button"
                class="auto-refund-link"
                :disabled="!isEntityId(row.user_id)"
                @click="goUser(router, row.user_id)"
              >
                <strong>{{ userLabel(row) }}</strong>
                <span>{{ fieldValue(row.user?.email) }}</span>
              </button>
            </div>
          </template>
          <template #service="{ row }">
            <div class="auto-refund-stack">
              <button
                type="button"
                class="auto-refund-link"
                :disabled="!canGoService(row.service_id, row.user_id)"
                @click="goService(router, row.service_id, row.user_id)"
              >
                <strong>{{ fieldValue(row.service_name) }}</strong>
              </button>
              <span>{{ $t('autoRefund.fields.serviceId') }} {{ fieldValue(row.service_id) }}</span>
            </div>
          </template>
          <template #upstream="{ row }">
            <span class="auto-refund-mono">{{ fieldValue(row.upstream_product_id) }}</span>
          </template>
          <template #refundType="{ row }">
            {{ refundTypeLabel(row.refund_type, row.refund_type_label) }}
          </template>
          <template #channel="{ row }">{{ channelLabel(row.channel, row.channel_label) }}</template>
          <template #estimated="{ row }">{{ formatMoney(row.estimated_amount) }}</template>
          <template #settled="{ row }">{{ row.settled_amount ? formatMoney(row.settled_amount) : '-' }}</template>
          <template #status="{ row }">
            <status-tag :status-map="statusMap" :status="row.status" />
          </template>
          <template #submittedAt="{ row }">
            <span class="auto-refund-time">{{ formatDateTime(row.submitted_at || row.created_at) }}</span>
          </template>
          <template #operation="{ row }">
            <t-space size="small">
              <t-button size="small" variant="text" theme="primary" @click="openDetail(row)">
                {{ $t('autoRefund.detailAction') }}
              </t-button>
              <t-dropdown v-if="canManage" :options="actionOptions(row)" @click="handleDropdownClick($event, row)">
                <t-button size="small" variant="text">{{ $t('autoRefund.moreAction') }}</t-button>
              </t-dropdown>
            </t-space>
          </template>
        </t-table>
      </div>

      <div v-if="isMobile || isCardView" class="auto-refund-mobile-list">
        <t-loading :loading="loading" size="small">
          <div
            v-if="list.length"
            class="auto-refund-mobile-stack"
            :style="isCardView && !isMobile ? ADMIN_CARD_GRID_STYLE : undefined"
          >
            <mobile-record-card
              v-for="row in list"
              :key="row.id"
              :title="fieldValue(row.application_no)"
              :eyebrow="$t('autoRefund.eyebrow')"
              :subtitle="fieldValue(row.service_name)"
              :description="row.reason || ''"
              :highlight-label="$t('autoRefund.fields.estimatedAmount')"
              :highlight-value="formatMoney(row.estimated_amount)"
              :status-map="statusMap"
              :status="row.status"
              :rows="mobileRows(row)"
              :action-options="mobileActionOptions(row)"
              linkable
              @title-click="openDetail(row)"
              @action="(value) => handleRowAction(value, row)"
            />
          </div>
          <t-empty v-else :description="$t('autoRefund.empty')" />
        </t-loading>
      </div>

      <div v-if="total > 0" class="pagination-row">
        <t-pagination
          :current="pagination.page"
          :page-size="pagination.page_size"
          :total="total"
          :page-size-options="pagination.pageSizeOptions"
          show-jumper
          @change="handlePaginationChange"
        />
      </div>
    </t-card>

    <application-detail-drawer
      v-model:visible="detail.visible"
      :loading="detail.loading"
      :application="detail.application"
      :can-manage="canManage"
      @close="closeDetail"
      @refresh="reloadDetail"
      @settle="openAction('settle', detail.application)"
      @reject="openAction('reject', detail.application)"
      @close-application="openAction('close', detail.application)"
      @view-user="goUser(router, detail.application?.user_id)"
      @view-service="goService(router, detail.application?.service_id, detail.application?.user_id)"
      @view-order="goOrder(router, detail.application?.order_id)"
      @view-ticket="goTicket(router, detail.application?.ticket_id)"
    />

    <upstream-products-drawer v-model:visible="upstreamProductsVisible" />

    <!-- 结算/驳回/作废共用一张确认弹窗：三者的差异只有标题、字段与提示语 -->
    <t-dialog
      v-model:visible="action.visible"
      :header="actionDialogHeader"
      width="520px"
      :confirm-btn="{
        content: actionDialogConfirm,
        theme: actionDialogTheme,
        loading: action.submitting,
      }"
      @confirm="submitAction"
    >
      <t-form ref="actionFormRef" :data="action.form" :rules="actionRules" label-align="top">
        <t-form-item v-if="action.kind === 'settle'" :label="$t('autoRefund.settle.amount')" name="amount">
          <t-input-number
            v-model="action.form.amount"
            theme="normal"
            style="width: 100%"
            :min="0"
            :decimal-places="2"
            :step="1"
            :placeholder="$t('autoRefund.settle.amountPlaceholder')"
          />
        </t-form-item>
        <t-form-item :label="actionRemarkLabel" name="remark">
          <t-textarea
            v-model="action.form.remark"
            :autosize="{ minRows: 2, maxRows: 4 }"
            :maxlength="255"
            :placeholder="actionRemarkPlaceholder"
          />
        </t-form-item>
      </t-form>

      <p v-if="actionDialogHint" class="auto-refund-dialog-hint">{{ actionDialogHint }}</p>
      <p class="auto-refund-dialog-target">{{ actionTargetText }}</p>
    </t-dialog>
  </div>
</template>
<script setup lang="ts">
import './index.less';

import { SearchIcon } from 'tdesign-icons-vue-next';
import type { DropdownOption, FormInstanceFunctions, FormRule, PrimaryTableCol, TableRowData } from 'tdesign-vue-next';
import { MessagePlugin } from 'tdesign-vue-next';
import { computed, nextTick, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';

import { adminApi } from '@/api/admin';
import type { AutoRefundApplication, AutoRefundUpstreamTestResult } from '@/api/admin/autoRefund';
import AdminViewModeSwitch from '@/components/admin-view-mode-switch/index.vue';
import MobileRecordCard from '@/components/mobile-record-card/index.vue';
import StatusTag from '@/components/status-tag/index.vue';
import { ADMIN_CARD_GRID_STYLE, useAdminViewMode } from '@/composables/useAdminViewMode';
import { useListPage } from '@/hooks/useListPage';
import { useMediaQuery } from '@/hooks/useMediaQuery';
import { t } from '@/locales';
import { fieldValue, formatDateTime, formatMoney } from '@/utils/format';
import { required } from '@/utils/formRules';
import { hasAdminPermission } from '@/utils/permission';
import { canGoService, goOrder, goService, goTicket, goUser, isEntityId } from '@/utils/route/adminEntityLink';
import { errorMessage } from '@/utils/userMessage';

import { channelLabel, channelOptions, refundTypeLabel, refundTypeOptions } from '../enums';
import ApplicationDetailDrawer from './ApplicationDetailDrawer.vue';
import { canCloseStatus, canRejectStatus, canSettleStatus, REFUND_STATUS_KEYS, refundStatusMap } from './status';
import UpstreamProductsDrawer from './UpstreamProductsDrawer.vue';

defineOptions({ name: 'AdminAutoRefundApplications' });

/**
 * 台账的权限码沿用管理端既有的财务与插件治理权限——后端 `routes/admin.php` 就是按这三档
 * 分组挂的（插件不能自行注册权限码，插件规范第 9 节）。这里只做按钮显隐与二次确认，
 * 真正的拒绝由后端接口负责。
 */
type ActionKind = 'settle' | 'reject' | 'close';

/** 会动钱的动作：与「账单管理」同权 */
const AUTO_REFUND_MANAGE = 'invoice.manage';
/** 上游连通性与对账：与插件治理同权 */
const AUTO_REFUND_GOVERN = 'integration_plugin.test';

const router = useRouter();
const isMobile = useMediaQuery('(max-width: 768px)');
const {
  mode: viewMode,
  isCard: isCardView,
  setMode: setViewMode,
} = useAdminViewMode('admin-auto-refund-applications', 'list');

const canManage = computed(() => hasAdminPermission(AUTO_REFUND_MANAGE));
const canGovern = computed(() => hasAdminPermission(AUTO_REFUND_GOVERN));
const statusMap = computed(() => refundStatusMap());
const statusOptions = computed(() =>
  Object.keys(REFUND_STATUS_KEYS).map((status) => ({ value: status, label: statusMap.value[status]?.label || status })),
);

const { filters, list, total, loading, pagination, handleSearch, handlePaginationChange, loadList } = useListPage<
  { keyword: string; status: string; refund_type: string; channel: string; start_date: string; end_date: string },
  AutoRefundApplication
>({
  fetch: (params) =>
    adminApi.autoRefund.applications({
      page: params.page,
      page_size: params.page_size,
      status: params.status || undefined,
      refund_type: params.refund_type || undefined,
      channel: params.channel || undefined,
      keyword: params.keyword.trim() || undefined,
      start_date: params.start_date || undefined,
      end_date: params.end_date || undefined,
    }),
  defaultFilters: { keyword: '', status: '', refund_type: '', channel: '', start_date: '', end_date: '' },
  onError: (error) => MessagePlugin.error(errorMessage(error, t('autoRefund.loadFailed'))),
});

/** 台账筛选的枚举选项：文案与「退款商品策略」页共用，避免两处各写一套中文 */
const refundTypeFilters = computed(() => refundTypeOptions().map(({ value, label }) => ({ value, label })));
const channelFilters = computed(() => channelOptions().map(({ value, label }) => ({ value, label })));

const columns = computed<PrimaryTableCol<TableRowData>[]>(() => [
  { colKey: 'application_no', title: t('autoRefund.columns.applicationNo'), minWidth: 148 },
  { colKey: 'user', title: t('autoRefund.columns.user'), minWidth: 128 },
  { colKey: 'service', title: t('autoRefund.columns.service'), minWidth: 140 },
  { colKey: 'upstream', title: t('autoRefund.columns.upstreamProductId'), width: 100 },
  { colKey: 'refundType', title: t('autoRefund.columns.refundType'), width: 112 },
  { colKey: 'channel', title: t('autoRefund.columns.channel'), width: 104 },
  { colKey: 'estimated', title: t('autoRefund.columns.estimatedAmount'), width: 92, align: 'right' },
  { colKey: 'settled', title: t('autoRefund.columns.settledAmount'), width: 105, align: 'right' },
  { colKey: 'status', title: t('common.status'), width: 100 },
  { colKey: 'submittedAt', title: t('autoRefund.columns.submittedAt'), width: 160, align: 'right' },
  { colKey: 'operation', title: t('common.action'), width: 105, fixed: 'right', align: 'right' },
]);

function userLabel(row: AutoRefundApplication | null | undefined) {
  const user = row?.user;

  return fieldValue(user?.display_name || user?.nickname || user?.email || user?.phone);
}

function mobileRows(row: AutoRefundApplication) {
  return [
    { label: t('autoRefund.mobile.user'), value: userLabel(row) },
    {
      label: t('autoRefund.mobile.settledAmount'),
      value: row.settled_amount ? formatMoney(row.settled_amount) : '-',
      strong: true,
    },
    { label: t('autoRefund.mobile.refundType'), value: refundTypeLabel(row.refund_type, row.refund_type_label) },
    { label: t('autoRefund.mobile.channel'), value: channelLabel(row.channel, row.channel_label) },
    { label: t('autoRefund.mobile.upstreamProductId'), value: fieldValue(row.upstream_product_id) },
    { label: t('autoRefund.mobile.serviceId'), value: fieldValue(row.service_id) },
    { label: t('autoRefund.mobile.submittedAt'), value: formatDateTime(row.submitted_at || row.created_at) },
  ];
}

/* ===== 行内操作：状态机决定哪些动作出现，权限决定整组是否出现 ===== */

function canSettle(row: AutoRefundApplication) {
  return canManage.value && canSettleStatus(row.status);
}

function canReject(row: AutoRefundApplication) {
  return canManage.value && canRejectStatus(row.status);
}

function canClose(row: AutoRefundApplication) {
  return canManage.value && canCloseStatus(row.status);
}

function actionOptions(row: AutoRefundApplication) {
  return [
    { content: t('autoRefund.settleAction'), value: 'settle', disabled: !canSettle(row) },
    { content: t('autoRefund.rejectAction'), value: 'reject', disabled: !canReject(row) },
    { content: t('autoRefund.closeAction'), value: 'close', disabled: !canClose(row) },
  ];
}

function mobileActionOptions(row: AutoRefundApplication): DropdownOption[] {
  return [{ content: t('autoRefund.detailAction'), value: 'detail' }, ...actionOptions(row)];
}

/** t-dropdown 的 click 回调给的是选项对象本身（或纯字符串），这里统一取 value */
function handleDropdownClick(item: string | number | Record<string, any>, row: AutoRefundApplication) {
  const value = item && typeof item === 'object' ? (item as DropdownOption).value : item;
  handleRowAction(value, row);
}

function handleRowAction(value: unknown, row: AutoRefundApplication) {
  if (value === 'detail') {
    openDetail(row);
    return;
  }
  if (value === 'settle') openAction('settle', row);
  if (value === 'reject') openAction('reject', row);
  if (value === 'close') openAction('close', row);
}

/* ===== 详情抽屉 ===== */

const detail = reactive({
  visible: false,
  loading: false,
  application: null as AutoRefundApplication | null,
});

function openDetail(row: AutoRefundApplication) {
  detail.application = row;
  detail.visible = true;
  void reloadDetail();
}

/**
 * 请求竞态防护：详情抽屉可以直接点另一行走（不用先关），
 * 慢的旧详情响应返回后会把新申请的数据覆盖掉——抽屉标题是新单号、内容是旧单号。
 * 序号 + 单号双重确认：不是最新一次、或单号已经不是当前这行，都丢弃。
 */
let detailRequestSeq = 0;

async function reloadDetail() {
  const id = detail.application?.id;
  if (!id) return;

  const currentSeq = ++detailRequestSeq;
  detail.loading = true;
  try {
    const application = await adminApi.autoRefund.application(id);
    if (currentSeq !== detailRequestSeq || detail.application?.id !== id) return;
    detail.application = application;
  } catch (error) {
    if (currentSeq !== detailRequestSeq) return;
    MessagePlugin.error(errorMessage(error, t('autoRefund.detailFailed')));
  } finally {
    if (currentSeq === detailRequestSeq) detail.loading = false;
  }
}

function closeDetail() {
  detail.visible = false;
  detail.application = null;
}

/* ===== 结算 / 驳回 / 作废 ===== */

const actionFormRef = ref<FormInstanceFunctions>();
const action = reactive({
  kind: 'settle' as ActionKind,
  visible: false,
  submitting: false,
  target: null as AutoRefundApplication | null,
  form: { amount: undefined as number | undefined, remark: '' },
});

const actionDialogHeader = computed(() => t(`autoRefund.${action.kind}.title`));
const actionDialogConfirm = computed(() => t(`autoRefund.${action.kind}.confirm`));
const actionRemarkLabel = computed(() => t(`autoRefund.${action.kind}.remark`));
const actionRemarkPlaceholder = computed(() => t(`autoRefund.${action.kind}.remarkPlaceholder`));

const actionDialogTheme = computed<'primary' | 'warning' | 'danger'>(() => {
  if (action.kind === 'close') return 'danger';
  if (action.kind === 'reject') return 'warning';

  return 'primary';
});

/** 结算与作废各有一句口径提示：结算走既有退款链路，作废不可撤回 */
const actionDialogHint = computed(() => {
  if (action.kind === 'settle') return t('autoRefund.settle.hint');
  if (action.kind === 'close') return t('autoRefund.close.hint');

  return '';
});

const actionTargetText = computed(() => {
  const application = action.target;
  if (!application) return '';

  return t('autoRefund.actionTarget', {
    no: fieldValue(application.application_no),
    user: userLabel(application),
    amount: formatMoney(application.estimated_amount),
  });
});

const actionRules = computed<Record<string, FormRule[]>>(() => {
  if (action.kind === 'settle') {
    return {
      amount: [
        required(t('autoRefund.settle.amountRequired')),
        {
          validator: (value: unknown) => Number(value) > 0,
          message: t('autoRefund.settle.amountInvalid'),
          type: 'error',
          trigger: 'blur',
        },
      ],
    };
  }

  return { remark: [required(t(`autoRefund.${action.kind}.remarkRequired`))] };
});

function openAction(kind: ActionKind, row: AutoRefundApplication | null | undefined) {
  if (!row) return;
  if (!canManage.value) {
    MessagePlugin.warning(t('autoRefund.noManagePermission'));
    return;
  }

  const allowed =
    kind === 'settle'
      ? canSettleStatus(row.status)
      : kind === 'reject'
        ? canRejectStatus(row.status)
        : canCloseStatus(row.status);
  if (!allowed) {
    MessagePlugin.warning(t('autoRefund.statusNotAllowed'));
    return;
  }

  action.kind = kind;
  action.target = row;
  // 结算金额默认填预估金额：预估值只是参考，管理员按上游实际到账改
  action.form.amount = kind === 'settle' ? Number(row.estimated_amount || 0) : undefined;
  action.form.remark = '';
  action.visible = true;
  void nextTick(() => actionFormRef.value?.clearValidate?.());
}

/** TDesign 校验通过时会 resolve true（无规则时可能是 undefined），失败返回 { errors } */
function isValidationPass(result: unknown) {
  return result === true || result === undefined;
}

async function submitAction() {
  const application = action.target;
  if (!application) return;

  const result = await actionFormRef.value?.validate?.();
  if (!isValidationPass(result)) return;

  const remark = action.form.remark.trim();
  action.submitting = true;
  try {
    if (action.kind === 'settle') {
      await adminApi.autoRefund.settle(application.id, {
        amount: Number(Number(action.form.amount ?? 0).toFixed(2)),
        remark: remark || undefined,
      });
      MessagePlugin.success(t('autoRefund.settle.success'));
    } else if (action.kind === 'reject') {
      await adminApi.autoRefund.reject(application.id, { remark });
      MessagePlugin.success(t('autoRefund.reject.success'));
    } else {
      await adminApi.autoRefund.close(application.id, { remark });
      MessagePlugin.success(t('autoRefund.close.success'));
    }

    action.visible = false;
    await loadList();
    if (detail.visible && detail.application?.id === application.id) await reloadDetail();
  } catch (error) {
    // 后端 message 原样透出（例如「金额超过可退金额」「上游返回 400」）
    MessagePlugin.error(errorMessage(error, t(`autoRefund.${action.kind}.failed`)));
  } finally {
    action.submitting = false;
  }
}

/* ===== 上游连通性自检 ===== */

const upstreamTest = reactive({
  loading: false,
  result: null as AutoRefundUpstreamTestResult | null,
});

function formatTimeOffset(seconds: number | null) {
  if (seconds === null) return t('autoRefund.upstreamTest.offsetUnknown');
  const rounded = Math.round(seconds);

  return t('autoRefund.upstreamTest.seconds', { value: rounded > 0 ? `+${rounded}` : String(rounded) });
}

const upstreamTestMessage = computed(() => {
  const result = upstreamTest.result;
  if (!result) return '';
  if (!result.ok) return result.message || t('autoRefund.upstreamTest.failed');

  const parts = [result.message || t('autoRefund.upstreamTest.ok')];
  parts.push(t('autoRefund.upstreamTest.machineCount', { count: result.machine_count ?? '-' }));
  if (result.server_time) parts.push(t('autoRefund.upstreamTest.serverTime', { time: result.server_time }));
  parts.push(t('autoRefund.upstreamTest.timeOffset', { offset: formatTimeOffset(result.time_offset_seconds) }));
  if (result.latency_ms !== null) parts.push(t('autoRefund.upstreamTest.latency', { ms: result.latency_ms }));

  return parts.join(' · ');
});

async function runUpstreamTest() {
  upstreamTest.loading = true;
  try {
    upstreamTest.result = await adminApi.autoRefund.testUpstream();
    if (!upstreamTest.result.ok) {
      MessagePlugin.warning(upstreamTest.result.message || t('autoRefund.upstreamTest.failed'));
    }
  } catch (error) {
    upstreamTest.result = null;
    MessagePlugin.error(errorMessage(error, t('autoRefund.upstreamTest.failed')));
  } finally {
    upstreamTest.loading = false;
  }
}

const upstreamProductsVisible = ref(false);
</script>
