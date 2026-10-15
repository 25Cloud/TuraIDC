<template>
  <section class="referral-page">
    <header class="client-page-heading">
      <h1>{{ $t('client.referral.title') }}</h1>
    </header>

    <t-loading :loading="loading" :text="$t('client.referral.loading')">
      <div class="referral-stats">
        <t-card v-for="item in summaryCards" :key="item.key" class="referral-stat" :bordered="false">
          <span>{{ item.label }}</span>
          <strong :class="{ primary: item.primary }">{{ item.value }}</strong>
        </t-card>
      </div>

      <t-card class="referral-card" :bordered="false">
        <template #title>{{ $t('client.referral.linkTitle') }}</template>
        <template #actions>
          <t-tag variant="light">{{ levelName }} - {{ $t('client.referral.commissionRate') }} {{ rewardRateText }}</t-tag>
        </template>
        <div class="referral-link-row">
          <t-input :value="referralLink" readonly>
            <template #prefixIcon><link-icon /></template>
          </t-input>
          <t-button @click="copyReferralLink">{{ $t('client.referral.copyLink') }}</t-button>
        </div>
        <p>
          {{ $t('client.referral.description', {
            rate: rewardRateText,
            days: freezeDaysText,
            min: withdrawMinAmountText,
          }) }}
        </p>
      </t-card>

      <t-card class="referral-card" :bordered="false">
        <template #title>{{ $t('client.referral.alipayTitle') }}</template>
        <template #actions>
          <t-tag :theme="isAlipayBound ? 'success' : 'warning'" variant="light">{{
            isAlipayBound ? $t('client.referral.alipayBound') : $t('client.referral.alipayUnbound')
          }}</t-tag>
        </template>
        <div v-if="!isAlipayBound" class="referral-empty">
          <t-empty :description="$t('client.referral.alipayBindTip')" />
          <t-button theme="primary" @click="openBindDialog">{{ $t('client.referral.bindNow') }}</t-button>
        </div>
        <t-form v-else class="withdraw-form" layout="inline">
          <t-form-item :label="$t('client.referral.payeeAccount')">
            <span>{{ alipayAccount.real_name || '--' }} {{ alipayAccount.account || '--' }}</span>
          </t-form-item>
          <t-form-item :label="$t('client.referral.withdrawAmount')">
            <t-input v-model="withdrawForm.amount" :placeholder="$t('client.referral.withdrawAmountPlaceholder')" />
          </t-form-item>
          <t-form-item>
            <t-button theme="primary" :loading="withdrawSubmitting" @click="submitWithdrawal">{{ $t('client.referral.submitWithdraw') }}</t-button>
          </t-form-item>
        </t-form>
      </t-card>

      <t-card class="referral-card" :bordered="false">
        <t-tabs v-model="activeTab">
          <t-tab-panel value="direct" :label="$t('client.referral.inviteesTab')">
            <t-table row-key="id" :data="directReferrals" :columns="directColumns" :pagination="null">
              <template #user="{ row }">{{ row.display_name || row.nickname || row.email || '--' }}</template>
              <template #referred_at="{ row }">{{ row.referred_at || row.created_at || '--' }}</template>
              <template #consumption="{ row }">¥{{ money(row.customer_consumption) }}</template>
              <template #earnings="{ row }">¥{{ money(row.my_earnings) }}</template>
            </t-table>
          </t-tab-panel>
          <t-tab-panel value="rewards" :label="$t('client.referral.rewardsTab')">
            <t-table row-key="id" :data="rewards" :columns="rewardColumns" :pagination="null">
              <template #user="{ row }">{{
                row.referred_user?.display_name || row.referred_user?.nickname || row.referred_user?.email || '--'
              }}</template>
              <template #product="{ row }">{{
                row.invoice?.product_display_name || row.product?.display_name || row.product?.name || '--'
              }}</template>
              <template #order_type="{ row }">{{ row.order_type || '--' }}</template>
              <template #order_amount="{ row }">¥{{ money(row.order_amount) }}</template>
              <template #amount="{ row }"
                >¥{{ money(row.reward_amount) }}({{ Number(row.reward_rate || 0) }}%)</template
              >
              <template #status="{ row }">
                <t-tag :theme="rewardStatus(row.status).theme" variant="light">{{
                  rewardStatus(row.status).label
                }}</t-tag>
              </template>
            </t-table>
          </t-tab-panel>
          <t-tab-panel value="withdrawals" :label="$t('client.referral.withdrawalsTab')">
            <t-table row-key="id" :data="withdrawals" :columns="withdrawColumns" :pagination="null">
              <template #method="{ row }">{{ row.method === 'balance' ? $t('client.referral.toBalance') : $t('client.referral.alipay') }}</template>
              <template #amount="{ row }">¥{{ money(row.amount) }}</template>
              <template #account="{ row }">{{ row.account_name || '--' }} {{ row.account_no || '' }}</template>
              <template #status="{ row }">
                <t-tag :theme="withdrawStatus(row.status).theme" variant="light">{{
                  withdrawStatus(row.status).label
                }}</t-tag>
              </template>
            </t-table>
          </t-tab-panel>
          <t-tab-panel value="logs" :label="$t('client.referral.accountLogsTab')">
            <t-table row-key="id" :data="accountLogs" :columns="logColumns" :pagination="null">
              <template #event_type="{ row }">{{ accountEventLabel(row.event_type) }}</template>
              <template #amount="{ row }">¥{{ money(row.amount) }}</template>
            </t-table>
          </t-tab-panel>
        </t-tabs>
      </t-card>
    </t-loading>

    <t-dialog
      v-model:visible="bindDialogVisible"
      :header="$t('client.referral.bindDialogTitle')"
      width="min(30rem, calc(100vw - 2rem))"
    >
      <t-form label-align="top">
        <p class="bind-dialog-tip">{{ $t('client.referral.bindDialogTip') }}</p>
        <t-form-item :label="$t('client.referral.realName')"
          ><t-input v-model="bindForm.real_name" :placeholder="$t('client.referral.realNamePlaceholder')"
        /></t-form-item>
        <t-form-item :label="$t('client.referral.alipayPhone')"
          ><t-input v-model="bindForm.account" :placeholder="$t('client.referral.alipayPhonePlaceholder')"
        /></t-form-item>
        <t-form-item :label="$t('client.referral.smsCode')"
          ><t-input v-model="bindForm.code" :placeholder="$t('client.referral.smsCodePlaceholder')" /></t-form-item>
        <t-form-item :label="$t('client.referral.loginPassword')"
          ><t-input v-model="bindForm.password" type="password" :placeholder="$t('client.referral.loginPasswordPlaceholder')"
        /></t-form-item>
      </t-form>
      <template #footer>
        <t-button variant="outline" @click="bindDialogVisible = false">{{ $t('client.referral.cancel') }}</t-button>
        <t-button theme="primary" :loading="bindSubmitting" @click="submitBindAlipay">{{ $t('client.referral.saveBind') }}</t-button>
      </template>
    </t-dialog>
  </section>
</template>
<script setup lang="ts">
import { LinkIcon } from 'tdesign-icons-vue-next';
import type { PrimaryTableCol } from 'tdesign-vue-next';

import { t } from '@/locales';
import { accountEventLabel, money, rewardStatus, useReferral, withdrawStatus } from '@/domains/marketing/useReferral';

const {
  loading,
  activeTab,
  rewards,
  accountLogs,
  withdrawals,
  directReferrals,
  alipayAccount,
  withdrawForm,
  bindForm,
  bindDialogVisible,
  withdrawSubmitting,
  bindSubmitting,
  withdrawMinAmountText,
  rewardRateText,
  freezeDaysText,
  levelName,
  isAlipayBound,
  referralLink,
  summaryCards,
  copyReferralLink,
  openBindDialog,
  submitBindAlipay,
  submitWithdrawal,
} = useReferral();

const rewardColumns: PrimaryTableCol[] = [
  { colKey: 'rewarded_at', title: t('referral.time'), minWidth: '12rem' },
  { colKey: 'user', title: t('referral.sourceUser'), minWidth: '10rem' },
  { colKey: 'product', title: t('referral.product'), minWidth: '14rem' },
  { colKey: 'order_type', title: t('referral.orderType'), width: '8rem' },
  { colKey: 'order_amount', title: t('referral.orderAmount'), width: '8rem' },
  { colKey: 'amount', title: t('referral.rewardAmount'), width: '8rem' },
  { colKey: 'status', title: t('common.status'), width: '8rem' },
];
const directColumns: PrimaryTableCol[] = [
  { colKey: 'user', title: t('referral.user'), minWidth: '10rem' },
  { colKey: 'email', title: t('referral.email'), minWidth: '14rem' },
  { colKey: 'referred_at', title: t('referral.referredAt'), minWidth: '12rem' },
  { colKey: 'consumption', title: t('referral.customerConsumption'), width: '8rem' },
  { colKey: 'earnings', title: t('referral.earnings'), width: '8rem' },
];
const withdrawColumns: PrimaryTableCol[] = [
  { colKey: 'created_at', title: t('referral.time'), minWidth: '12rem' },
  { colKey: 'method', title: t('referral.withdrawMethod'), width: '8rem' },
  { colKey: 'amount', title: t('referral.withdrawAmount'), width: '8rem' },
  { colKey: 'account', title: t('referral.account'), minWidth: '14rem' },
  { colKey: 'status', title: t('common.status'), width: '8rem' },
];
const logColumns: PrimaryTableCol[] = [
  { colKey: 'created_at', title: t('referral.time'), minWidth: '12rem' },
  { colKey: 'event_type', title: t('referral.event'), width: '9rem' },
  { colKey: 'amount', title: t('referral.changeAmount'), width: '9rem' },
  { colKey: 'remark', title: t('referral.remark'), minWidth: '14rem' },
];
</script>
<style scoped lang="less">
.referral-page {
  display: flex;
  flex-direction: column;
  gap: var(--td-comp-margin-m);
  // padding 由 Starter 布局层统一提供
}

.referral-stats {
  display: grid;
  grid-template-columns: repeat(6, minmax(0, 1fr));
  gap: var(--td-comp-margin-s);
}

.referral-card,
.referral-stat {
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

.referral-stat {
  span {
    color: var(--td-text-color-secondary);
    font: var(--td-font-body-small);
  }

  strong {
    display: block;
    margin-top: var(--td-comp-margin-xs);
    color: var(--td-text-color-primary);
    font: var(--td-font-title-medium);

    &.primary {
      color: var(--td-brand-color);
    }
  }
}

.referral-link-row {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: var(--td-comp-margin-s);
  align-items: center;
}

.referral-card p {
  margin: var(--td-comp-margin-s) 0 0;
  color: var(--td-text-color-secondary);
  font: var(--td-font-body-small);
}

.bind-dialog-tip {
  margin: 0 0 var(--td-comp-margin-s);
  color: var(--td-text-color-secondary);
  font: var(--td-font-body-small);
}

.referral-empty {
  display: flex;
  flex-direction: column;
  gap: var(--td-comp-margin-m);
  align-items: center;
}

@media (width <= 80rem) {
  .referral-stats {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }
}

@media (width <= 48rem) {
  .referral-stats {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: @screen-sm-rem) {
  .referral-stats,
  .referral-link-row {
    grid-template-columns: 1fr;
  }
}
</style>
