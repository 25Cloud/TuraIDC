<template>
  <t-drawer
    :visible="visible"
    :size="drawerSize"
    :header="$t('autoRefund.drawerTitle', { no: fieldValue(application?.application_no) })"
    :footer="false"
    @update:visible="(value: boolean) => emit('update:visible', value)"
    @close="emit('close')"
  >
    <record-detail-page
      :loading="loading"
      :ready="Boolean(application?.id)"
      :back-text="$t('autoRefund.closeDetail')"
      :eyebrow="$t('autoRefund.eyebrow')"
      :title="fieldValue(application?.application_no)"
      :description="descriptionText"
      :status-label="refundStatusLabel(application?.status)"
      :status-theme="refundStatusTheme(application?.status)"
      :metrics="metrics"
      :tabs="tabs"
      :active-tab="activeTab"
      :empty-text="$t('autoRefund.notFound')"
      @back="emit('close')"
      @refresh="emit('refresh')"
      @update:active-tab="(value) => (activeTab = value)"
    >
      <!-- 结算/驳回/作废都在抽屉里直接给入口：管理员核对完上游就该能立刻收口 -->
      <template #toolbar-actions>
        <t-button v-if="canManage && canSettleStatus(application?.status)" theme="primary" @click="emit('settle')">
          {{ $t('autoRefund.settleAction') }}
        </t-button>
        <t-button
          v-if="canManage && canRejectStatus(application?.status)"
          variant="outline"
          theme="warning"
          @click="emit('reject')"
        >
          {{ $t('autoRefund.rejectAction') }}
        </t-button>
        <t-button
          v-if="canManage && canCloseStatus(application?.status)"
          variant="outline"
          theme="danger"
          @click="emit('close-application')"
        >
          {{ $t('autoRefund.closeAction') }}
        </t-button>
      </template>

      <template #relations>
        <t-button v-if="isEntityId(application?.user_id)" variant="outline" size="small" @click="emit('view-user')">
          {{ $t('autoRefund.viewUser') }}
        </t-button>
        <t-button
          v-if="canGoService(application?.service_id, application?.user_id)"
          variant="outline"
          size="small"
          @click="emit('view-service')"
        >
          {{ $t('autoRefund.viewService') }}
        </t-button>
        <t-button v-if="isEntityId(application?.order_id)" variant="outline" size="small" @click="emit('view-order')">
          {{ $t('autoRefund.viewOrder') }}
        </t-button>
      </template>

      <template #tab-basic>
        <section class="auto-refund-detail-section">
          <h4>{{ $t('autoRefund.sections.application') }}</h4>
          <div class="auto-refund-detail-grid">
            <div>
              <span>{{ $t('autoRefund.fields.applicationNo') }}</span>
              <strong>{{ fieldValue(application?.application_no) }}</strong>
            </div>
            <div>
              <span>{{ $t('common.status') }}</span>
              <strong>{{ refundStatusLabel(application?.status) }}</strong>
            </div>
            <div>
              <span>{{ $t('autoRefund.fields.refundType') }}</span>
              <strong>{{ refundTypeLabel(application?.refund_type, application?.refund_type_label) }}</strong>
            </div>
            <div>
              <span>{{ $t('autoRefund.fields.channel') }}</span>
              <strong>{{ channelLabel(application?.channel, application?.channel_label) }}</strong>
            </div>
            <div>
              <span>{{ $t('autoRefund.fields.user') }}</span>
              <strong>{{ userName }}</strong>
            </div>
            <div>
              <span>{{ $t('autoRefund.fields.userId') }}</span>
              <strong>{{ fieldValue(application?.user_id) }}</strong>
            </div>
            <div>
              <span>{{ $t('autoRefund.fields.service') }}</span>
              <strong>{{ fieldValue(application?.service_name) }}</strong>
            </div>
            <div>
              <span>{{ $t('autoRefund.fields.serviceId') }}</span>
              <strong>{{ fieldValue(application?.service_id) }}</strong>
            </div>
            <div>
              <span>{{ $t('autoRefund.fields.productName') }}</span>
              <strong>{{ fieldValue(application?.product_name) }}</strong>
            </div>
            <div>
              <span>{{ $t('autoRefund.fields.upstreamProductId') }}</span>
              <strong class="auto-refund-mono">{{ fieldValue(application?.upstream_product_id) }}</strong>
            </div>
            <div>
              <span>{{ $t('autoRefund.fields.ticketId') }}</span>
              <strong>{{ fieldValue(application?.ticket_id) }}</strong>
            </div>
            <div>
              <span>{{ $t('autoRefund.fields.upstreamTicketId') }}</span>
              <strong class="auto-refund-mono">{{ upstreamTicketText }}</strong>
            </div>
            <div>
              <span>{{ $t('autoRefund.fields.activeServiceId') }}</span>
              <strong>{{ fieldValue(application?.active_service_id) }}</strong>
            </div>
            <div>
              <span>{{ $t('autoRefund.fields.orderId') }}</span>
              <strong>{{ fieldValue(application?.order_id) }}</strong>
            </div>
            <div>
              <span>{{ $t('autoRefund.fields.invoiceId') }}</span>
              <strong>{{ fieldValue(application?.invoice_id) }}</strong>
            </div>
            <div>
              <span>{{ $t('autoRefund.fields.estimatedAmount') }}</span>
              <strong>{{ formatMoney(application?.estimated_amount) }}</strong>
            </div>
            <div>
              <span>{{ $t('autoRefund.fields.settledAmount') }}</span>
              <strong>{{ settledAmountText }}</strong>
            </div>
            <div>
              <span>{{ $t('autoRefund.fields.submittedAt') }}</span>
              <strong>{{ formatDateTime(application?.submitted_at) }}</strong>
            </div>
            <div>
              <span>{{ $t('autoRefund.fields.cancelledAt') }}</span>
              <strong>{{ formatDateTime(application?.cancelled_at) }}</strong>
            </div>
            <div>
              <span>{{ $t('autoRefund.fields.settledAt') }}</span>
              <strong>{{ formatDateTime(application?.settled_at) }}</strong>
            </div>
            <div>
              <span>{{ $t('autoRefund.fields.operator') }}</span>
              <strong>{{ operatorText }}</strong>
            </div>
            <div>
              <span>{{ $t('autoRefund.fields.createdAt') }}</span>
              <strong>{{ formatDateTime(application?.created_at) }}</strong>
            </div>
            <div>
              <span>{{ $t('autoRefund.fields.updatedAt') }}</span>
              <strong>{{ formatDateTime(application?.updated_at) }}</strong>
            </div>
            <div class="auto-refund-detail-span">
              <span>{{ $t('autoRefund.fields.reason') }}</span>
              <strong class="auto-refund-pre">{{ fieldValue(application?.reason) }}</strong>
            </div>
            <div class="auto-refund-detail-span">
              <span>{{ $t('autoRefund.fields.settleRemark') }}</span>
              <strong class="auto-refund-pre">{{ fieldValue(application?.settle_remark) }}</strong>
            </div>
            <!-- 工单通道的申请，沟通记录在本站工单里；上游工单号可能拿不到，口径写清楚 -->
            <div v-if="isApiTicketChannel" class="auto-refund-detail-span auto-refund-ticket-hint">
              <span>{{ $t('autoRefund.fields.ticketLinkHint') }}</span>
              <t-button
                v-if="isEntityId(application?.ticket_id)"
                variant="text"
                theme="primary"
                size="small"
                @click="emit('view-ticket')"
              >
                {{ $t('autoRefund.fields.viewTicket') }}
              </t-button>
            </div>
            <!-- 策略是可变配置：不存快照就没人能解释「当时为什么能退」，所以原样 pretty 出来 -->
            <div class="auto-refund-detail-span">
              <span>{{ $t('autoRefund.fields.policySnapshot') }}</span>
              <pre class="auto-refund-json">{{ policySnapshotText }}</pre>
            </div>
          </div>
        </section>
      </template>

      <template #tab-upstream>
        <section class="auto-refund-detail-section">
          <!-- 上游没有进度查询接口：这句话必须在看上游回包的地方出现 -->
          <t-alert theme="warning" :message="$t('autoRefund.upstreamHint')" />
          <div class="auto-refund-detail-grid">
            <div>
              <span>{{ $t('autoRefund.fields.upstreamCode') }}</span>
              <strong class="auto-refund-mono">{{ fieldValue(application?.upstream_code) }}</strong>
            </div>
            <div>
              <span>{{ $t('autoRefund.fields.traceId') }}</span>
              <strong class="auto-refund-mono">{{ fieldValue(application?.trace_id) }}</strong>
            </div>
            <div class="auto-refund-detail-span">
              <span>{{ $t('autoRefund.fields.upstreamMsg') }}</span>
              <strong class="auto-refund-pre">{{ fieldValue(application?.upstream_msg) }}</strong>
            </div>
            <div class="auto-refund-detail-span">
              <span>{{ $t('autoRefund.fields.responseMeta') }}</span>
              <pre class="auto-refund-json">{{ responseMetaText }}</pre>
            </div>
          </div>
        </section>
      </template>
    </record-detail-page>
  </t-drawer>
</template>
<script setup lang="ts">
import { computed, ref, watch } from 'vue';

import type { AutoRefundApplication } from '@/api/admin/autoRefund';
import type { RecordDetailMetric, RecordDetailTab } from '@/components/record-detail-page/index.vue';
import RecordDetailPage from '@/components/record-detail-page/index.vue';
import { useMediaQuery } from '@/hooks/useMediaQuery';
import { t } from '@/locales';
import { fieldValue, formatDateTime, formatMoney } from '@/utils/format';
import { canGoService, isEntityId } from '@/utils/route/adminEntityLink';

import { channelLabel, refundTypeLabel } from '../enums';
import { canCloseStatus, canRejectStatus, canSettleStatus, refundStatusLabel, refundStatusTheme } from './status';

const props = withDefaults(
  defineProps<{
    visible?: boolean;
    loading?: boolean;
    application?: AutoRefundApplication | null;
    /** 结算/驳回/作废需要操作权限；只读角色看不到这几个按钮 */
    canManage: boolean;
  }>(),
  { visible: false, loading: false, application: null },
);

const emit = defineEmits<{
  (event: 'update:visible', value: boolean): void;
  (event: 'close'): void;
  (event: 'refresh'): void;
  (event: 'settle'): void;
  (event: 'reject'): void;
  (event: 'close-application'): void;
  (event: 'view-user'): void;
  (event: 'view-service'): void;
  (event: 'view-order'): void;
  (event: 'view-ticket'): void;
}>();

const isMobile = useMediaQuery('(max-width: 768px)');
const drawerSize = computed(() => (isMobile.value ? '100%' : '720px'));

const activeTab = ref('basic');

// 换一笔申请就回到「申请信息」；同一笔刷新详情不打断管理员当前看的页签
watch(
  () => props.application?.id,
  () => {
    activeTab.value = 'basic';
  },
);

const tabs = computed<RecordDetailTab[]>(() => [
  { value: 'basic', label: t('autoRefund.tabs.basic') },
  { value: 'upstream', label: t('autoRefund.tabs.upstream') },
]);

const metrics = computed<RecordDetailMetric[]>(() => [
  {
    label: t('autoRefund.metrics.estimatedAmount'),
    value: formatMoney(props.application?.estimated_amount),
    primary: true,
  },
  {
    label: t('autoRefund.metrics.settledAmount'),
    value: settledAmountText.value,
  },
  {
    label: t('autoRefund.metrics.upstreamCode'),
    value: fieldValue(props.application?.upstream_code),
  },
]);

const settledAmountText = computed(() => {
  const value = props.application?.settled_amount;
  return value ? formatMoney(value) : '-';
});

const descriptionText = computed(() => {
  const parts = [
    fieldValue(props.application?.service_name),
    fieldValue(props.application?.upstream_product_id),
  ].filter((item) => item !== '-');

  return parts.join(' · ');
});

const userName = computed(() => {
  const user = props.application?.user;

  return fieldValue(user?.display_name || user?.nickname || user?.email || user?.phone);
});

const operatorText = computed(() => {
  const operator = props.application?.operator;
  const name = fieldValue(operator?.display_name || operator?.nickname || operator?.email);

  return name === '-' ? fieldValue(props.application?.operator_id) : name;
});

/** response_meta 是脱敏摘要，键名不固定：原样 pretty 出来，不做二次裁剪 */
const responseMetaText = computed(() => {
  const meta = props.application?.response_meta;
  if (meta === null || meta === undefined || meta === '') return '-';
  if (typeof meta === 'string') return meta;

  try {
    return JSON.stringify(meta, null, 2);
  } catch {
    return String(meta);
  }
});

/** 工单通道：只有 `api_ticket` 才有「到工单模块看上游沟通」这条路径 */
const isApiTicketChannel = computed(() => String(props.application?.channel ?? '') === 'api_ticket');

/**
 * 上游工单号：`zjfm` 协议只回 302 列表页，拿不到号，此时说清楚而不是显示 '-'
 * （契约：`upstream_ticket_id` 允许为空，并在详情里说明「上游未返回工单号」）。
 */
const upstreamTicketText = computed(() => {
  const upstreamTicketId = props.application?.upstream_ticket_id;
  if (upstreamTicketId) return upstreamTicketId;

  return isApiTicketChannel.value ? t('autoRefund.fields.upstreamTicketMissing') : '-';
});

/**
 * `policy_snapshot` 契约里是 JSON 字符串：先解析再 pretty，解析不了就原样展示。
 * 直接糊一坨字符串出来，管理员没法从里面看出当时生效的是哪条规则。
 */
const policySnapshotText = computed(() => {
  const snapshot = props.application?.policy_snapshot;
  if (snapshot === null || snapshot === undefined || snapshot === '') return '-';

  let parsed: unknown = snapshot;
  if (typeof snapshot === 'string') {
    try {
      parsed = JSON.parse(snapshot);
    } catch {
      return snapshot;
    }
  }

  try {
    return JSON.stringify(parsed, null, 2);
  } catch {
    return String(parsed);
  }
});
</script>
<style lang="less" scoped>
.auto-refund-detail-section {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.auto-refund-detail-section + .auto-refund-detail-section {
  margin-top: 16px;
}

.auto-refund-detail-section h4 {
  position: relative;
  margin: 0;
  padding-left: 10px;
  color: var(--td-text-color-primary);
  font-size: var(--td-font-size-size-3, 14px);
  font-weight: 650;
  line-height: 22px;
}

.auto-refund-detail-section h4::before {
  position: absolute;
  top: 4px;
  bottom: 4px;
  left: 0;
  width: 3px;
  border-radius: var(--td-radius-small, 2px);
  background: var(--td-brand-color);
  content: '';
}

.auto-refund-detail-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 1px;
  overflow: hidden;
  border: 1px solid var(--td-component-border);
  border-radius: var(--td-radius-medium, 6px);
  background: var(--td-component-stroke);
}

.auto-refund-detail-grid > div {
  display: flex;
  min-width: 0;
  flex-direction: column;
  gap: 5px;
  padding: 12px;
  background: var(--td-bg-color-container);
}

.auto-refund-detail-grid span {
  color: var(--td-text-color-secondary);
  font-size: var(--td-font-size-size-1, 12px);
  line-height: 1.5;
}

.auto-refund-detail-grid strong {
  color: var(--td-text-color-primary);
  font-size: var(--td-font-size-size-2, 13px);
  font-weight: 600;
  line-height: 1.5;
  overflow-wrap: anywhere;
}

.auto-refund-detail-span {
  grid-column: 1 / -1;
}

/* 工单通道的提示行：一句话 + 一个跳转按钮排在同一行（选择器带上父级，压过网格默认的纵向排列） */
.auto-refund-detail-grid > .auto-refund-ticket-hint {
  align-items: center;
  flex-direction: row;
  justify-content: space-between;
}

.auto-refund-mono {
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
}

/* 上游 msg / 退款原因 / 备注都是原文，保留换行，不做截断 */
.auto-refund-pre {
  white-space: pre-wrap;
  word-break: break-word;
}

.auto-refund-json {
  margin: 0;
  padding: 10px;
  border-radius: var(--td-radius-small, 2px);
  background: var(--td-bg-color-secondarycontainer);
  color: var(--td-text-color-primary);
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: var(--td-font-size-size-1, 12px);
  line-height: 1.6;
  white-space: pre-wrap;
  word-break: break-all;
}

@media (width <= 560px) {
  .auto-refund-detail-grid {
    grid-template-columns: 1fr;
  }
}
</style>
