<template>
  <section class="referral-page">
    <header class="client-page-heading">
      <h1>推荐奖励</h1>
    </header>

    <t-loading :loading="loading" text="正在加载推荐奖励">
      <div class="referral-stats">
        <t-card v-for="item in summaryCards" :key="item.key" class="referral-stat" :bordered="false">
          <span>{{ item.label }}</span>
          <strong :class="{ primary: item.primary }">{{ item.value }}</strong>
        </t-card>
      </div>

      <t-card class="referral-card" :bordered="false">
        <template #title>我的推荐链接</template>
        <template #actions>
          <t-tag variant="light">{{ levelName }} - 佣金比例 {{ rewardRateText }}</t-tag>
        </template>
        <div class="referral-link-row">
          <t-input :value="referralLink" readonly>
            <template #prefixIcon><link-icon /></template>
          </t-input>
          <t-button @click="copyReferralLink">复制链接</t-button>
        </div>
        <p>
          好友通过此链接注册并消费，您可获得 {{ rewardRateText }} 佣金，冻结
          {{ freezeDaysText }} 天后自动释放。最低提现金额 ¥{{ withdrawMinAmountText }}。
        </p>
      </t-card>

      <t-card class="referral-card" :bordered="false">
        <template #title>提现支付宝</template>
        <template #actions>
          <t-tag :theme="isAlipayBound ? 'success' : 'warning'" variant="light">{{
            isAlipayBound ? '已绑定' : '提现前需先绑定'
          }}</t-tag>
        </template>
        <div v-if="!isAlipayBound" class="referral-empty">
          <t-empty description="提现前需要先绑定支付宝，并完成该手机号的短信验证，无需上传支付宝图片。" />
          <t-button theme="primary" @click="openBindDialog">立即绑定</t-button>
        </div>
        <t-form v-else class="withdraw-form" layout="inline">
          <t-form-item label="收款账户">
            <span>{{ alipayAccount.real_name || '--' }} {{ alipayAccount.account || '--' }}</span>
          </t-form-item>
          <t-form-item label="提现金额">
            <t-input v-model="withdrawForm.amount" placeholder="请输入提现金额" />
          </t-form-item>
          <t-form-item>
            <t-button theme="primary" :loading="withdrawSubmitting" @click="submitWithdrawal">提交提现</t-button>
          </t-form-item>
        </t-form>
      </t-card>

      <t-card class="referral-card" :bordered="false">
        <t-tabs v-model="activeTab">
          <t-tab-panel value="direct" label="被邀请人列表">
            <t-table row-key="id" :data="directReferrals" :columns="directColumns" :pagination="null">
              <template #user="{ row }">{{ row.display_name || row.nickname || row.email || '--' }}</template>
              <template #referred_at="{ row }">{{ row.referred_at || row.created_at || '--' }}</template>
              <template #consumption="{ row }">¥{{ money(row.customer_consumption) }}</template>
              <template #earnings="{ row }">¥{{ money(row.my_earnings) }}</template>
            </t-table>
          </t-tab-panel>
          <t-tab-panel value="rewards" label="奖励明细">
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
          <t-tab-panel value="withdrawals" label="提现记录">
            <t-table row-key="id" :data="withdrawals" :columns="withdrawColumns" :pagination="null">
              <template #method="{ row }">{{ row.method === 'balance' ? '转余额' : '支付宝' }}</template>
              <template #amount="{ row }">¥{{ money(row.amount) }}</template>
              <template #account="{ row }">{{ row.account_name || '--' }} {{ row.account_no || '' }}</template>
              <template #status="{ row }">
                <t-tag :theme="withdrawStatus(row.status).theme" variant="light">{{
                  withdrawStatus(row.status).label
                }}</t-tag>
              </template>
            </t-table>
          </t-tab-panel>
          <t-tab-panel value="logs" label="账户流水">
            <t-table row-key="id" :data="accountLogs" :columns="logColumns" :pagination="null">
              <template #event_type="{ row }">{{ accountEventLabel(row.event_type) }}</template>
              <template #amount="{ row }">¥{{ money(row.amount) }}</template>
            </t-table>
          </t-tab-panel>
        </t-tabs>
      </t-card>
    </t-loading>

    <t-dialog v-model:visible="bindDialogVisible" header="绑定提现支付宝" width="min(30rem, calc(100vw - 2rem))">
      <t-form label-align="top">
        <p class="bind-dialog-tip">请填写实名、支付宝绑定手机号、短信验证码与登录密码，用于提现打款。</p>
        <t-form-item label="真实姓名"
          ><t-input v-model="bindForm.real_name" placeholder="请输入支付宝实名姓名"
        /></t-form-item>
        <t-form-item label="支付宝手机号"
          ><t-input v-model="bindForm.account" placeholder="请输入支付宝绑定手机号"
        /></t-form-item>
        <t-form-item label="短信验证码"><t-input v-model="bindForm.code" placeholder="请输入短信验证码" /></t-form-item>
        <t-form-item label="登录密码"
          ><t-input v-model="bindForm.password" type="password" placeholder="请输入登录密码确认"
        /></t-form-item>
      </t-form>
      <template #footer>
        <t-button variant="outline" @click="bindDialogVisible = false">取消</t-button>
        <t-button theme="primary" :loading="bindSubmitting" @click="submitBindAlipay">保存绑定</t-button>
      </template>
    </t-dialog>
  </section>
</template>
<script setup lang="ts">
import { LinkIcon } from 'tdesign-icons-vue-next';
import type { PrimaryTableCol } from 'tdesign-vue-next';

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
  { colKey: 'rewarded_at', title: '时间', minWidth: '12rem' },
  { colKey: 'user', title: '来源用户', minWidth: '10rem' },
  { colKey: 'product', title: '产品', minWidth: '14rem' },
  { colKey: 'order_type', title: '消费类型', width: '8rem' },
  { colKey: 'order_amount', title: '消费金额', width: '8rem' },
  { colKey: 'amount', title: '奖励金额', width: '8rem' },
  { colKey: 'status', title: '状态', width: '8rem' },
];
const directColumns: PrimaryTableCol[] = [
  { colKey: 'user', title: '用户', minWidth: '10rem' },
  { colKey: 'email', title: '邮箱', minWidth: '14rem' },
  { colKey: 'referred_at', title: '被邀请时间', minWidth: '12rem' },
  { colKey: 'consumption', title: '客户消费', width: '8rem' },
  { colKey: 'earnings', title: '我的收益', width: '8rem' },
];
const withdrawColumns: PrimaryTableCol[] = [
  { colKey: 'created_at', title: '时间', minWidth: '12rem' },
  { colKey: 'method', title: '提现方式', width: '8rem' },
  { colKey: 'amount', title: '提现金额', width: '8rem' },
  { colKey: 'account', title: '收款账户', minWidth: '14rem' },
  { colKey: 'status', title: '状态', width: '8rem' },
];
const logColumns: PrimaryTableCol[] = [
  { colKey: 'created_at', title: '时间', minWidth: '12rem' },
  { colKey: 'event_type', title: '事件', width: '9rem' },
  { colKey: 'amount', title: '变动金额', width: '9rem' },
  { colKey: 'remark', title: '说明', minWidth: '14rem' },
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
