import { ACCOUNT_TRANSACTION_EVENT_MAP, getStatusLabel } from '@shared/statusConfig';
import { MessagePlugin } from 'tdesign-vue-next';
import { computed, onMounted, reactive, ref } from 'vue';

import { clientAuthApi } from '@/api/auth';
import clientApi from '@/api/client';
import { useUserStore } from '@/store';
import type {
  ClientAlipayAccount,
  ReferralAccountLogRecord,
  ReferralOverviewPayload,
  ReferralRewardRecord,
  ReferralWithdrawalRecord,
} from '@/types/client';
import { copyText } from '@/utils/format';

type TagTheme = 'default' | 'success' | 'warning' | 'primary' | 'danger';

interface BindFormState {
  real_name: string;
  account: string;
  code: string;
}

interface WithdrawFormState {
  amount: string;
}

function getErrorMessage(error: unknown, fallback: string) {
  if (error instanceof Error && error.message) return error.message;
  if (typeof error === 'object' && error !== null && 'message' in error && typeof error.message === 'string') {
    return error.message;
  }
  return fallback;
}

export function money(value: unknown) {
  const amount = Number(value || 0);
  return Number.isFinite(amount) ? amount.toFixed(2) : '0.00';
}

export function rewardStatus(status: unknown) {
  const map: Record<number, { label: string; theme: TagTheme }> = {
    0: { label: '冻结中', theme: 'warning' },
    1: { label: '已释放', theme: 'success' },
    2: { label: '已冲正', theme: 'danger' },
  };
  return map[Number(status)] || { label: String(status ?? '--'), theme: 'default' };
}

export function withdrawStatus(status: unknown) {
  const map: Record<number, { label: string; theme: TagTheme }> = {
    0: { label: '审核中', theme: 'warning' },
    1: { label: '已通过', theme: 'success' },
    2: { label: '已拒绝', theme: 'danger' },
  };
  return map[Number(status)] || { label: String(status ?? '--'), theme: 'default' };
}

export function accountEventLabel(eventType: unknown) {
  return getStatusLabel(ACCOUNT_TRANSACTION_EVENT_MAP, String(eventType || ''));
}

function resolveList<T>(list: T[] | undefined) {
  return Array.isArray(list) ? list : [];
}

export function useReferral() {
  const userStore = useUserStore();
  const loading = ref(false);
  const activeTab = ref<'rewards' | 'withdrawals' | 'logs'>('rewards');
  const bindDialogVisible = ref(false);
  const withdrawSubmitting = ref(false);
  const bindSubmitting = ref(false);
  const overview = reactive<ReferralOverviewPayload>({});
  const rewards = ref<ReferralRewardRecord[]>([]);
  const accountLogs = ref<ReferralAccountLogRecord[]>([]);
  const withdrawals = ref<ReferralWithdrawalRecord[]>([]);
  const alipayAccount = reactive<ClientAlipayAccount & { is_bound: boolean }>({
    real_name: '',
    account: '',
    is_bound: false,
  });
  const withdrawForm = reactive<WithdrawFormState>({ amount: '' });
  const bindForm = reactive<BindFormState>({ real_name: '', account: '', code: '' });

  const availableAmountText = computed(() => money(overview.referral_available_amount));
  const frozenAmountText = computed(() => money(overview.referral_frozen_amount));
  const totalRewardText = computed(() => money(overview.total_reward_amount));
  const withdrawnAmountText = computed(() => money(overview.referral_withdrawn_amount));
  const withdrawMinAmountText = computed(() => money(overview.withdraw_min_amount || 20));
  const rewardRateText = computed(
    () => `${money(overview.reward_rate || overview.current_member_level?.reward_rate || 0)}%`,
  );
  const freezeDaysText = computed(() => String(Number(overview.reward_freeze_days || 0)));
  const levelName = computed(() => overview.current_member_level?.name || 'V1');
  const isAlipayBound = computed(() => Boolean(alipayAccount.is_bound && alipayAccount.account));
  const referralLink = computed(() => {
    if (overview.referral_link) return overview.referral_link;
    const code = overview.referral_code || userStore.info?.referral_code || '';
    return code && typeof window !== 'undefined' ? `${window.location.origin}/client/register?ref=${code}` : '--';
  });
  const summaryCards = computed(() => [
    { key: 'available', label: '可提现余额', value: `￥${availableAmountText.value}`, primary: true },
    { key: 'frozen', label: '冻结中', value: `￥${frozenAmountText.value}` },
    { key: 'total', label: '累计奖励', value: `￥${totalRewardText.value}` },
    { key: 'withdrawn', label: '已提现', value: `￥${withdrawnAmountText.value}` },
    { key: 'direct', label: '直推人数', value: `${Number(overview.direct_referral_count || 0)} 人` },
    { key: 'orders', label: '奖励账单数', value: `${Number(overview.rewarded_orders_count || 0)} 单` },
  ]);

  async function loadAlipayAccount() {
    try {
      const response = await clientAuthApi.alipayAccount();
      Object.assign(alipayAccount, response.data || {});
      alipayAccount.is_bound = Boolean(response.data?.is_bound && response.data?.account);
    } catch {
      Object.assign(alipayAccount, userStore.info?.alipay_account || {});
      alipayAccount.is_bound = Boolean(
        userStore.info?.alipay_account?.is_bound && userStore.info?.alipay_account?.account,
      );
    }
  }

  async function loadAll() {
    loading.value = true;
    try {
      const [overviewRes, rewardsRes, logsRes, withdrawalsRes] = await Promise.all([
        clientApi.referralOverview(),
        clientApi.referralRewards({ page: 1, page_size: 10 }),
        clientApi.referralAccountLogs({ page: 1, page_size: 10 }),
        clientApi.referralWithdrawals({ page: 1, page_size: 10 }),
      ]);
      Object.assign(overview, overviewRes.data || {});
      rewards.value = resolveList(rewardsRes.data?.list);
      accountLogs.value = resolveList(logsRes.data?.list);
      withdrawals.value = resolveList(withdrawalsRes.data?.list);
    } catch (error: unknown) {
      MessagePlugin.error(getErrorMessage(error, '推荐奖励数据加载失败'));
    } finally {
      loading.value = false;
    }
  }

  async function copyReferralLink() {
    if (!referralLink.value || referralLink.value === '--') {
      MessagePlugin.warning('推荐链接暂不可用');
      return;
    }
    await copyText(referralLink.value, { successMsg: '推荐链接已复制' });
  }

  function openBindDialog() {
    bindForm.real_name = alipayAccount.real_name || String(userStore.info?.real_name || '');
    bindForm.account = alipayAccount.account || '';
    bindForm.code = '';
    bindDialogVisible.value = true;
  }

  async function submitBindAlipay() {
    bindSubmitting.value = true;
    try {
      const response = await clientAuthApi.updateAlipayAccount({ ...bindForm });
      Object.assign(alipayAccount, response.data || {});
      alipayAccount.is_bound = Boolean(response.data?.is_bound && response.data?.account);
      bindDialogVisible.value = false;
      MessagePlugin.success('支付宝绑定成功');
      await userStore.getUserInfo();
    } catch (error: unknown) {
      MessagePlugin.error(getErrorMessage(error, '支付宝绑定失败'));
    } finally {
      bindSubmitting.value = false;
    }
  }

  async function submitWithdrawal() {
    const amount = Number(withdrawForm.amount || 0);
    if (!Number.isFinite(amount) || amount <= 0) {
      MessagePlugin.warning('请输入提现金额');
      return;
    }

    withdrawSubmitting.value = true;
    try {
      await clientApi.applyWithdrawal({
        amount: withdrawForm.amount,
        method: 'alipay',
        account_name: alipayAccount.real_name,
        account_no: alipayAccount.account,
      });
      MessagePlugin.success('提现申请已提交');
      withdrawForm.amount = '';
      await loadAll();
    } catch (error: unknown) {
      MessagePlugin.error(getErrorMessage(error, '提现申请提交失败'));
    } finally {
      withdrawSubmitting.value = false;
    }
  }

  onMounted(() => {
    void Promise.all([loadAll(), loadAlipayAccount()]);
  });

  return {
    userStore,
    loading,
    activeTab,
    overview,
    rewards,
    accountLogs,
    withdrawals,
    alipayAccount,
    withdrawForm,
    bindForm,
    bindDialogVisible,
    withdrawSubmitting,
    bindSubmitting,
    availableAmountText,
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
    loadAll,
  };
}
