<template>
  <section class="record-page client-refunds">
    <!-- 开关还没拿到：只给加载态，避免先闪一片空表 -->
    <t-card v-if="bootstrapLoading" class="record-card" :bordered="false">
      <data-state :loading="true" :loading-text="$t('shared.state.loading')" />
    </t-card>

    <!-- enabled=false / configured=false：整页空状态，不再发后续请求 -->
    <t-card v-else-if="!available" class="record-card" :bordered="false">
      <div class="refund-unavailable">
        <h1>{{ $t('client.refunds.unavailableTitle') }}</h1>
        <t-empty :description="$t('client.refunds.unavailableHint')" />
      </div>
    </t-card>

    <template v-else>
      <!-- 说明卡：通用说明来自 /bootstrap 的 notice / policy.text，可退规则在每台机器上标注 -->
      <t-card class="record-card" :bordered="false">
        <div class="refund-intro">
          <h1 class="refund-intro__title">{{ $t('client.refunds.title') }}</h1>
          <p class="refund-intro__notice">{{ noticeText || $t('client.refunds.noticeFallback') }}</p>
          <ul v-if="policyLines.length" class="refund-intro__policy">
            <li v-for="line in policyLines" :key="line">{{ line }}</li>
          </ul>
          <p class="refund-intro__hint">{{ $t('client.refunds.policyPerMachineHint') }}</p>
          <t-alert theme="warning" :message="$t('client.refunds.disclaimer')" />
        </div>
      </t-card>

      <!-- 可申请退款 -->
      <t-card class="record-card" :bordered="false">
        <div class="refund-section-head">
          <h2>{{ $t('client.refunds.candidatesTitle') }}</h2>
          <span>{{ $t('client.refunds.candidatesHint') }}</span>
        </div>

        <t-alert
          v-if="upstreamUnavailable"
          class="refund-block-alert"
          theme="warning"
          :message="$t('client.refunds.upstreamUnavailable')"
        />

        <data-state
          :loading-text="$t('shared.state.loading')"
          :empty-text="$t('shared.state.empty')"
          :loading="candidatesLoading"
          :empty="!candidates.length"
          :description="candidatesError || $t('client.refunds.candidatesEmpty')"
        >
          <t-table
            class="record-table"
            row-key="service_id"
            :data="candidates"
            :columns="candidateColumns"
            :pagination="null"
            hover
          >
            <template #instance="{ row }">
              <div class="stack-cell">
                <strong>{{ resolveAutoRefundServiceTitle(row) }}</strong>
                <span>{{ row.product_name || '--' }}</span>
              </div>
            </template>
            <template #address="{ row }">{{ resolveAutoRefundAddress(row) }}</template>
            <template #policy="{ row }">
              <span class="refund-policy">{{ resolveAutoRefundPolicyText(row) }}</span>
            </template>
            <template #regdate="{ row }">{{ row.regdate || '--' }}</template>
            <template #nextduedate="{ row }">{{ row.nextduedate || '--' }}</template>
            <template #estimate="{ row }">
              <div class="stack-cell">
                <strong class="refund-money">{{ resolveAutoRefundAmountText(row.estimated_amount) }}</strong>
                <span>{{ row.estimate_hint || '--' }}</span>
              </div>
            </template>
            <template #operation="{ row }">
              <div class="refund-action">
                <template v-if="row.application">
                  <span class="refund-action__label">{{ $t('client.refunds.currentApplication') }}</span>
                  <t-tag :theme="autoRefundStatusTheme(row.application.status)" variant="light">
                    {{ autoRefundStatusLabel(row.application.status) }}
                  </t-tag>
                  <span v-if="isUncertainStatus(row.application.status)" class="refund-uncertain-tip">
                    {{ $t('client.refunds.uncertainShortTip') }}
                  </span>
                </template>
                <template v-else>
                  <t-button
                    size="small"
                    theme="primary"
                    :disabled="isAutoRefundCandidateBlocked(row) || upstreamUnavailable || submitting"
                    @click="openSubmitDialog(row)"
                  >
                    {{ $t('client.refunds.apply') }}
                  </t-button>
                  <ul v-if="resolveBlockedReasonLabels(row).length" class="refund-reasons">
                    <li v-for="reason in resolveBlockedReasonLabels(row)" :key="reason">{{ reason }}</li>
                  </ul>
                </template>
              </div>
            </template>
          </t-table>

          <div class="record-mobile-list">
            <article v-for="row in candidates" :key="row.service_id" class="record-mobile-card">
              <div class="record-mobile-card__head">
                <strong>{{ resolveAutoRefundServiceTitle(row) }}</strong>
                <t-tag v-if="row.application" :theme="autoRefundStatusTheme(row.application.status)" variant="light">
                  {{ autoRefundStatusLabel(row.application.status) }}
                </t-tag>
              </div>

              <div class="stack-cell">
                <span>{{ row.product_name || '--' }}</span>
                <span>{{ resolveAutoRefundAddress(row) }}</span>
                <span>{{
                  $t('common.labelValue', {
                    label: $t('client.refunds.columnPolicy'),
                    value: resolveAutoRefundPolicyText(row),
                  })
                }}</span>
                <span>{{
                  $t('common.labelValue', { label: $t('client.refunds.regdateLabel'), value: row.regdate || '--' })
                }}</span>
                <span>{{
                  $t('common.labelValue', {
                    label: $t('client.refunds.nextduedateLabel'),
                    value: row.nextduedate || '--',
                  })
                }}</span>
                <span>{{
                  $t('common.labelValue', {
                    label: $t('client.refunds.estimateLabel'),
                    value: resolveAutoRefundAmountText(row.estimated_amount),
                  })
                }}</span>
                <span v-if="row.estimate_hint">{{ row.estimate_hint }}</span>
              </div>

              <div class="record-mobile-card__actions">
                <template v-if="row.application">
                  <span class="refund-action__label">{{ $t('client.refunds.currentApplication') }}</span>
                  <span v-if="isUncertainStatus(row.application.status)" class="refund-uncertain-tip">
                    {{ $t('client.refunds.uncertainShortTip') }}
                  </span>
                </template>
                <template v-else>
                  <t-button
                    size="small"
                    theme="primary"
                    :disabled="isAutoRefundCandidateBlocked(row) || upstreamUnavailable || submitting"
                    @click="openSubmitDialog(row)"
                  >
                    {{ $t('client.refunds.apply') }}
                  </t-button>
                  <ul v-if="resolveBlockedReasonLabels(row).length" class="refund-reasons">
                    <li v-for="reason in resolveBlockedReasonLabels(row)" :key="reason">{{ reason }}</li>
                  </ul>
                </template>
              </div>
            </article>
          </div>
        </data-state>
      </t-card>

      <!-- 我的申请 -->
      <t-card class="record-card" :bordered="false">
        <div class="refund-section-head">
          <h2>{{ $t('client.refunds.applicationsTitle') }}</h2>
          <span>{{ $t('client.refunds.applicationsHint') }}</span>
        </div>

        <t-alert
          v-if="hasUncertainApplication"
          class="refund-block-alert"
          theme="warning"
          :message="$t('client.refunds.uncertainTip')"
        />

        <data-state
          :loading-text="$t('shared.state.loading')"
          :empty-text="$t('shared.state.empty')"
          :loading="applicationsLoading"
          :empty="!applications.length"
          :description="applicationsError || $t('client.refunds.applicationsEmpty')"
        >
          <t-table
            class="record-table"
            row-key="id"
            :data="applications"
            :columns="applicationColumns"
            :pagination="null"
            hover
          >
            <template #application_no="{ row }">{{ resolveAutoRefundApplicationNo(row) }}</template>
            <template #instance="{ row }">
              <div class="stack-cell">
                <strong>{{ resolveAutoRefundServiceTitle(row) }}</strong>
                <span>{{ row.product_name || '--' }}</span>
              </div>
            </template>
            <template #reason="{ row }">{{ row.reason || '--' }}</template>
            <template #estimated_amount="{ row }">
              <span class="refund-money">{{ resolveAutoRefundAmountText(row.estimated_amount) }}</span>
            </template>
            <template #settled_amount="{ row }">
              <span class="refund-money">{{ resolveAutoRefundAmountText(row.settled_amount) }}</span>
            </template>
            <template #channel="{ row }">
              <div class="refund-channel-cell">
                <span class="refund-channel-cell__type">{{
                  autoRefundTypeLabel(row.refund_type, row.refund_type_label)
                }}</span>
                <span v-if="row.channel" class="refund-channel-cell__route">{{
                  autoRefundChannelLabel(row.channel, row.channel_label)
                }}</span>
              </div>
            </template>
            <template #ticket="{ row }">
              <div class="refund-ticket-cell">
                <span class="refund-ticket-cell__no">{{ resolveAutoRefundUpstreamTicketText(row) }}</span>
                <t-button
                  v-if="resolveAutoRefundTicketId(row)"
                  size="small"
                  theme="primary"
                  variant="text"
                  @click="openApplicationTicket(row)"
                >
                  {{ $t('client.refunds.ticketOpenAction') }}
                </t-button>
              </div>
            </template>
            <template #status="{ row }">
              <div class="refund-status-cell">
                <t-tag :theme="autoRefundStatusTheme(row.status)" variant="light">
                  {{ autoRefundStatusLabel(row.status) }}
                </t-tag>
                <span v-if="isUncertainStatus(row.status)" class="refund-uncertain-tip">
                  {{ $t('client.refunds.uncertainShortTip') }}
                </span>
              </div>
            </template>
            <template #submitted_at="{ row }">{{ resolveAutoRefundSubmittedAt(row) }}</template>
            <template #operation="{ row }">
              <t-button
                v-if="canCancelApplication(row)"
                size="small"
                theme="danger"
                variant="text"
                :loading="cancelingId === Number(row.id)"
                @click="handleCancel(row)"
              >
                {{ $t('client.refunds.cancelAction') }}
              </t-button>
              <span v-else>--</span>
            </template>
          </t-table>

          <div class="record-mobile-list">
            <article v-for="row in applications" :key="row.id" class="record-mobile-card">
              <div class="record-mobile-card__head">
                <strong>{{ resolveAutoRefundApplicationNo(row) }}</strong>
                <t-tag :theme="autoRefundStatusTheme(row.status)" variant="light">
                  {{ autoRefundStatusLabel(row.status) }}
                </t-tag>
              </div>

              <div class="stack-cell">
                <strong>{{ resolveAutoRefundServiceTitle(row) }}</strong>
                <span>{{ row.reason || '--' }}</span>
                <span>{{
                  $t('common.labelValue', {
                    label: $t('client.refunds.columnEstimatedAmount'),
                    value: resolveAutoRefundAmountText(row.estimated_amount),
                  })
                }}</span>
                <span>{{
                  $t('common.labelValue', {
                    label: $t('client.refunds.columnSettledAmount'),
                    value: resolveAutoRefundAmountText(row.settled_amount),
                  })
                }}</span>
                <span>{{ resolveAutoRefundSubmittedAt(row) }}</span>
                <span>{{
                  $t('common.labelValue', {
                    label: $t('client.refunds.columnChannel'),
                    value: autoRefundTypeLabel(row.refund_type, row.refund_type_label),
                  })
                }}</span>
                <span v-if="row.channel">{{
                  $t('common.labelValue', {
                    label: $t('client.refunds.columnChannelRoute'),
                    value: autoRefundChannelLabel(row.channel, row.channel_label),
                  })
                }}</span>
                <span>{{
                  $t('common.labelValue', {
                    label: $t('client.refunds.columnTicket'),
                    value: resolveAutoRefundUpstreamTicketText(row),
                  })
                }}</span>
                <span v-if="isUncertainStatus(row.status)" class="refund-uncertain-tip">
                  {{ $t('client.refunds.uncertainShortTip') }}
                </span>
              </div>

              <div class="record-mobile-card__actions">
                <t-button
                  v-if="resolveAutoRefundTicketId(row)"
                  size="small"
                  theme="primary"
                  variant="outline"
                  @click="openApplicationTicket(row)"
                >
                  {{ $t('client.refunds.ticketOpenAction') }}
                </t-button>
                <t-button
                  v-if="canCancelApplication(row)"
                  size="small"
                  theme="danger"
                  variant="outline"
                  :loading="cancelingId === Number(row.id)"
                  @click="handleCancel(row)"
                >
                  {{ $t('client.refunds.cancelAction') }}
                </t-button>
              </div>
            </article>
          </div>
        </data-state>

        <div v-if="total > 0" class="record-pagination">
          <t-pagination
            v-model="filters.page"
            v-model:page-size="filters.page_size"
            :page-size-options="[10, 20, 50]"
            :total="total"
            show-total
            @change="handlePageChange"
            @page-size-change="handlePageSizeChange"
          />
        </div>
      </t-card>
    </template>

    <!-- 提交申请：填原因 → 二次确认 → 上游 submit -->
    <t-dialog
      v-model:visible="submitVisible"
      :header="$t('client.refunds.submitTitle')"
      width="min(34rem, calc(100vw - var(--td-comp-margin-xl)))"
      destroy-on-close
      :confirm-btn="{
        content: $t('client.refunds.submitAction'),
        loading: submitting,
        disabled: !canSubmit,
      }"
      :cancel-btn="$t('common.cancel')"
      @confirm="handleSubmit"
      @close="closeSubmitDialog"
    >
      <t-form label-align="top">
        <p class="refund-submit-tip">{{ $t('client.refunds.submitIntro') }}</p>
        <p class="refund-submit-service">
          {{
            $t('common.labelValue', {
              label: $t('client.refunds.columnInstance'),
              value: submitTargetTitle,
            })
          }}
        </p>
        <t-form-item :label="$t('client.refunds.reasonLabel')" required-mark>
          <t-textarea
            v-model="submitForm.reason"
            :autosize="{ minRows: 4, maxRows: 8 }"
            :maxlength="reasonMaxLength"
            show-limit-number
            :placeholder="$t('client.refunds.reasonPlaceholder')"
          />
        </t-form-item>
        <p class="refund-submit-tip">{{ $t('client.refunds.submitAmountHint') }}</p>
      </t-form>
    </t-dialog>
  </section>
</template>
<script setup lang="ts">
import DataState from '@shared/user-v3/components/DataState.vue';
import type { PrimaryTableCol } from 'tdesign-vue-next';

import {
  AUTO_REFUND_REASON_MAX_LENGTH,
  autoRefundChannelLabel,
  autoRefundStatusLabel,
  autoRefundStatusTheme,
  autoRefundTypeLabel,
  canCancelApplication,
  isAutoRefundCandidateBlocked,
  isUncertainStatus,
  resolveAutoRefundAddress,
  resolveAutoRefundAmountText,
  resolveAutoRefundApplicationNo,
  resolveAutoRefundPolicyText,
  resolveAutoRefundServiceTitle,
  resolveAutoRefundSubmittedAt,
  resolveAutoRefundTicketId,
  resolveAutoRefundUpstreamTicketText,
  resolveBlockedReasonLabels,
  useAutoRefundCenter,
} from '@/domains/finance/useAutoRefund';
import { t } from '@/locales';

const reasonMaxLength = AUTO_REFUND_REASON_MAX_LENGTH;

const {
  bootstrapLoading,
  available,
  noticeText,
  policyLines,
  candidatesLoading,
  candidates,
  candidatesError,
  upstreamUnavailable,
  applicationsLoading,
  applications,
  applicationsError,
  hasUncertainApplication,
  total,
  filters,
  submitting,
  cancelingId,
  submitVisible,
  submitTargetTitle,
  submitForm,
  canSubmit,
  handlePageChange,
  handlePageSizeChange,
  openSubmitDialog,
  closeSubmitDialog,
  handleSubmit,
  handleCancel,
  openApplicationTicket,
} = useAutoRefundCenter();

const candidateColumns: PrimaryTableCol[] = [
  { colKey: 'instance', title: t('client.refunds.columnInstance'), minWidth: '12rem' },
  { colKey: 'address', title: t('client.refunds.columnAddress'), minWidth: '10rem' },
  // 每台机器自己的退款策略（类型 · 规则 · 要求），列宽给足，允许折行
  { colKey: 'policy', title: t('client.refunds.columnPolicy'), minWidth: '13rem' },
  { colKey: 'regdate', title: t('client.refunds.columnRegdate'), minWidth: '10rem' },
  { colKey: 'nextduedate', title: t('client.refunds.columnNextduedate'), minWidth: '10rem' },
  { colKey: 'estimate', title: t('client.refunds.columnEstimate'), minWidth: '11rem' },
  { colKey: 'operation', title: t('common.action'), minWidth: '8rem', fixed: 'right', align: 'right' },
];

const applicationColumns: PrimaryTableCol[] = [
  { colKey: 'application_no', title: t('client.refunds.columnApplicationNo'), minWidth: '10rem' },
  { colKey: 'instance', title: t('client.refunds.columnInstance'), minWidth: '10rem' },
  { colKey: 'reason', title: t('client.refunds.columnReason'), minWidth: '10rem' },
  { colKey: 'estimated_amount', title: t('client.refunds.columnEstimatedAmount'), minWidth: '6rem' },
  { colKey: 'settled_amount', title: t('client.refunds.columnSettledAmount'), minWidth: '6rem' },
  { colKey: 'channel', title: t('client.refunds.columnChannel'), minWidth: '7rem' },
  { colKey: 'status', title: t('common.status'), minWidth: '7rem' },
  { colKey: 'ticket', title: t('client.refunds.columnTicket'), minWidth: '7rem' },
  { colKey: 'submitted_at', title: t('client.refunds.columnSubmittedAt'), minWidth: '9rem' },
  { colKey: 'operation', title: t('common.action'), width: '6rem', fixed: 'right', align: 'right' },
];
</script>
<style scoped lang="less">
@import '../record-page.less';

.refund-unavailable {
  display: grid;
  gap: var(--td-comp-margin-m);
  padding: var(--td-comp-paddingTB-m) 0;

  h1 {
    margin: 0;
    color: var(--td-text-color-primary);
    font: var(--td-font-title-large);
  }
}

.refund-intro {
  display: grid;
  gap: var(--td-comp-margin-s);
}

.refund-intro__title {
  margin: 0;
  color: var(--td-text-color-primary);
  font: var(--td-font-title-large);
}

.refund-intro__notice {
  margin: 0;
  color: var(--td-text-color-secondary);
  font: var(--td-font-body-medium);
  overflow-wrap: anywhere;
  white-space: pre-line;
}

.refund-intro__policy {
  margin: 0;
  padding-left: 1.25rem;
  color: var(--td-text-color-secondary);
  font: var(--td-font-body-small);

  li {
    overflow-wrap: anywhere;
  }
}

.refund-intro__hint {
  margin: 0;
  color: var(--td-text-color-primary);
  font: var(--td-font-body-small);
  font-weight: 600;
  overflow-wrap: anywhere;
}

.refund-section-head {
  display: flex;
  flex-wrap: wrap;
  gap: var(--td-comp-margin-xs) var(--td-comp-margin-s);
  align-items: baseline;
  margin-bottom: var(--td-comp-margin-m);

  h2 {
    margin: 0;
    color: var(--td-text-color-primary);
    font: var(--td-font-title-medium);
  }

  span {
    color: var(--td-text-color-secondary);
    font: var(--td-font-body-small);
  }
}

.refund-block-alert {
  margin-bottom: var(--td-comp-margin-m);
}

.refund-money {
  color: var(--td-text-color-primary);
  font: var(--td-font-body-medium);
  font-weight: 600;
}

/* 每台机器自己的退款策略：类型 · 规则 · 要求，允许折行，不做省略号截断 */
.refund-policy {
  color: var(--td-text-color-secondary);
  font: var(--td-font-body-small);
  overflow-wrap: anywhere;
  white-space: normal;
}

.refund-ticket-cell {
  display: flex;
  flex-direction: column;
  gap: var(--td-comp-margin-xxs);
  align-items: flex-start;
  min-width: 0;
}

/* 退款方式 = 退款类型（主）+ 实际通道（副），通道只有 上游接口 / 上游工单 / 仅本站 三个值 */
.refund-channel-cell {
  display: flex;
  flex-direction: column;
  gap: var(--td-comp-margin-xxs);
  min-width: 0;
}

.refund-channel-cell__type {
  color: var(--td-text-color-primary);
  font: var(--td-font-body-small);
}

.refund-channel-cell__route {
  color: var(--td-text-color-placeholder);
  font: var(--td-font-body-small);
}

.refund-ticket-cell__no {
  color: var(--td-text-color-primary);
  font: var(--td-font-body-small);
  overflow-wrap: anywhere;
}

.refund-action {
  display: flex;
  flex-direction: column;
  gap: var(--td-comp-margin-xs);
  align-items: flex-end;
  min-width: 0;
}

.refund-action__label {
  color: var(--td-text-color-secondary);
  font: var(--td-font-body-small);
}

.refund-reasons {
  margin: 0;
  padding: 0;
  color: var(--td-text-color-secondary);
  font: var(--td-font-body-small);
  list-style: none;
  text-align: right;

  li {
    overflow-wrap: anywhere;
  }
}

.refund-status-cell {
  display: flex;
  flex-direction: column;
  gap: var(--td-comp-margin-xxs);
  align-items: flex-start;
  min-width: 0;
}

.refund-uncertain-tip {
  color: var(--td-warning-color);
  font: var(--td-font-body-small);
  white-space: normal;
}

.refund-submit-tip {
  margin: 0 0 var(--td-comp-margin-s);
  color: var(--td-text-color-secondary);
  font: var(--td-font-body-small);
}

.refund-submit-service {
  margin: 0 0 var(--td-comp-margin-m);
  color: var(--td-text-color-primary);
  font: var(--td-font-body-medium);
  font-weight: 600;
  overflow-wrap: anywhere;
}

@media (max-width: @screen-sm-rem) {
  .refund-action {
    align-items: flex-start;
  }

  .refund-reasons {
    text-align: left;
  }
}
</style>
