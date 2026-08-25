import { MessagePlugin } from 'tdesign-vue-next';
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';

import { clientAuthApi } from '@/api/auth';
import { useGeeTestCaptcha } from '@/composables/useGeeTestCaptcha';
import { useUserStore } from '@/store';
import type { ClientAgentGroup, ClientNotificationPreferences, ClientUserInfo } from '@/types/client';
import { getErrorMessage } from '@/utils/error';
import { copyText as copyShared } from '@/utils/format';

type TagTheme = 'default' | 'success' | 'warning' | 'primary' | 'danger';
type ProfileTab = 'profile' | 'security' | 'agent' | 'notification' | 'display';
type NotificationKey = keyof ClientNotificationPreferences;
interface NotificationItem {
  key: NotificationKey;
  name: string;
  desc: string;
  enabled: boolean;
}

const PROFILE_TABS = new Set<ProfileTab>(['profile', 'security', 'agent', 'notification', 'display']);

export function useProfile() {
  const router = useRouter();
  const userStore = useUserStore();
  const { runWithCaptcha } = useGeeTestCaptcha({
    onPrompt: () => MessagePlugin.warning('请先完成人机验证'),
  });
  const activeTab = ref<ProfileTab>('profile');
  const profileLoading = ref(false);
  const passwordDialogVisible = ref(false);
  const phoneDialogVisible = ref(false);
  const emailDialogVisible = ref(false);
  const notificationLoading = ref(false);
  const phoneForm = reactive({ phone: '', code: '', oldCode: '' });
  const emailForm = reactive({ email: '', code: '', oldCode: '' });
  const phoneCountdown = ref(0);
  const emailCountdown = ref(0);
  const phoneOldCountdown = ref(0);
  const emailOldCountdown = ref(0);
  let phoneTimer: ReturnType<typeof setInterval> | null = null;
  let emailTimer: ReturnType<typeof setInterval> | null = null;
  let phoneOldTimer: ReturnType<typeof setInterval> | null = null;
  let emailOldTimer: ReturnType<typeof setInterval> | null = null;
  const profileForm = reactive({
    id: '',
    email: '',
    nickname: '',
    phone: '',
    cash_balance: '0.00',
    createdAt: '',
    is_verified: 0,
    real_name: '',
    id_card_masked: '',
  });
  const agentGroup = ref<ClientAgentGroup | null>(null);
  const passwordForm = reactive({ oldPassword: '', newPassword: '', confirmPassword: '' });
  const passwordMode = ref<'old' | 'reset'>('old');
  const resetForm = reactive({ type: 'phone' as 'phone' | 'email', code: '', password: '', confirmPassword: '' });
  const resetCountdown = ref(0);
  let resetTimer: ReturnType<typeof setInterval> | null = null;
  const notificationList = reactive<NotificationItem[]>([
    {
      key: 'login_notify',
      name: '账号登录提醒',
      desc: '每次账户成功登录后，向绑定邮箱发送登录安全提醒。',
      enabled: false,
    },
    {
      key: 'login_location_alert',
      name: '异地登录提醒',
      desc: '检测到新的登录 IP 环境时，额外发送一次异地登录风险提醒。',
      enabled: false,
    },
    {
      key: 'password_change_alert',
      name: '更改密码提醒',
      desc: '账户密码修改成功后，立即发送安全提醒邮件。',
      enabled: false,
    },
    {
      key: 'phone_change_alert',
      name: '更改手机号提醒',
      desc: '安全手机号发生变更时，及时发送变更提醒。',
      enabled: false,
    },
    {
      key: 'email_change_alert',
      name: '更改邮箱提醒',
      desc: '安全邮箱发生变更时，向原邮箱和新邮箱发送提醒。',
      enabled: false,
    },
    { key: 'marketing_alert', name: '营销提醒接收', desc: '接收产品更新、活动优惠和运营消息。', enabled: false },
  ]);

  const balanceText = computed(() => `¥${profileForm.cash_balance || '0.00'}`);
  const enabledNotificationCount = computed(() => notificationList.filter((item) => item.enabled).length);
  const securityItems = computed(() => [
    {
      key: 'verification',
      name: '实名认证',
      desc: profileForm.real_name
        ? `${profileForm.real_name}${profileForm.id_card_masked ? ` · ${profileForm.id_card_masked}` : ''}`
        : '完成实名认证后，可提升账户可信度与业务可用范围',
      theme: (profileForm.is_verified ? 'success' : 'warning') as TagTheme,
      tag: profileForm.is_verified ? '已完成' : '待处理',
      actionLabel: profileForm.is_verified ? '查看认证' : '立即认证',
      action: () => router.push('/client/verification'),
    },
    {
      key: 'phone',
      name: '安全手机',
      desc: profileForm.phone || '绑定手机号后，可用于验证码接收和安全校验',
      theme: (profileForm.phone ? 'success' : 'warning') as TagTheme,
      tag: profileForm.phone ? '已绑定' : '未绑定',
      actionLabel: profileForm.phone ? '更换绑定' : '前往绑定',
      action: () => openPhoneDialog(),
    },
    {
      key: 'email',
      name: '安全邮箱',
      desc: profileForm.email || '建议绑定常用邮箱，用于接收通知与安全提醒',
      theme: (profileForm.email ? 'success' : 'warning') as TagTheme,
      tag: profileForm.email ? '已绑定' : '未绑定',
      actionLabel: profileForm.email ? '更换绑定' : '前往绑定',
      action: () => openEmailDialog(),
    },
    {
      key: 'password',
      name: '登录密码',
      desc: '建议定期更新密码，并避免与其他平台共用同一组凭证',
      theme: 'success' as TagTheme,
      tag: '已设置',
      actionLabel: '修改密码',
      action: () => openPasswordDialog(),
    },
  ]);

  function clearPhoneTimer() {
    if (phoneTimer) {
      clearInterval(phoneTimer);
      phoneTimer = null;
    }
  }
  function clearEmailTimer() {
    if (emailTimer) {
      clearInterval(emailTimer);
      emailTimer = null;
    }
  }
  function clearPhoneOldTimer() {
    if (phoneOldTimer) {
      clearInterval(phoneOldTimer);
      phoneOldTimer = null;
    }
  }
  function clearEmailOldTimer() {
    if (emailOldTimer) {
      clearInterval(emailOldTimer);
      emailOldTimer = null;
    }
  }
  function clearResetTimer() {
    if (resetTimer) {
      clearInterval(resetTimer);
      resetTimer = null;
    }
  }

  function openPasswordDialog() {
    passwordMode.value = 'old';
    passwordForm.oldPassword = '';
    passwordForm.newPassword = '';
    passwordForm.confirmPassword = '';
    resetForm.type = profileForm.phone ? 'phone' : 'email';
    resetForm.code = '';
    resetForm.password = '';
    resetForm.confirmPassword = '';
    resetCountdown.value = 0;
    clearResetTimer();
    passwordDialogVisible.value = true;
  }

  function togglePasswordMode() {
    passwordMode.value = passwordMode.value === 'old' ? 'reset' : 'old';
  }

  async function sendResetCode() {
    try {
      await runWithCaptcha(async (captcha: unknown) => {
        if (resetForm.type === 'phone') {
          await clientAuthApi.sendPhoneCode({ phone: profileForm.phone, purpose: 'reset_password', captcha });
        } else {
          await clientAuthApi.sendEmailCode({ email: profileForm.email, captcha });
        }
      });
      MessagePlugin.success('验证码已发送');
      resetCountdown.value = 60;
      resetTimer = setInterval(() => {
        resetCountdown.value -= 1;
        if (resetCountdown.value <= 0) {
          clearResetTimer();
          resetCountdown.value = 0;
        }
      }, 1000);
    } catch (error: unknown) {
      MessagePlugin.error(getErrorMessage(error, '验证码发送失败'));
    }
  }

  async function submitResetPassword() {
    if (!resetForm.code || resetForm.code.length !== 6) {
      MessagePlugin.warning('请输入 6 位验证码');
      return;
    }
    if (!resetForm.password) {
      MessagePlugin.warning('请输入新密码');
      return;
    }
    // 这里此前只判空。后端 ResetPasswordRequest 是 min:8，短密码会一路发出去再被 422 打回。
    if (resetForm.password.length < 8) {
      MessagePlugin.warning('密码长度不能少于 8 位');
      return;
    }
    if (resetForm.password !== resetForm.confirmPassword) {
      MessagePlugin.warning('两次密码输入不一致');
      return;
    }
    profileLoading.value = true;
    try {
      const account = resetForm.type === 'phone' ? profileForm.phone : profileForm.email;
      await clientAuthApi.resetPassword({
        account,
        code: resetForm.code,
        password: resetForm.password,
        password_confirmation: resetForm.confirmPassword,
      });
      MessagePlugin.success('密码重置成功，请重新登录');
      passwordDialogVisible.value = false;
      clearResetTimer();
      await userStore.logout();
      router.push('/client/login');
    } catch (error: unknown) {
      MessagePlugin.error(getErrorMessage(error, '密码重置失败'));
    } finally {
      profileLoading.value = false;
    }
  }

  function openPhoneDialog() {
    phoneForm.phone = '';
    phoneForm.code = '';
    phoneForm.oldCode = '';
    phoneCountdown.value = 0;
    phoneOldCountdown.value = 0;
    clearPhoneTimer();
    clearPhoneOldTimer();
    phoneDialogVisible.value = true;
  }

  function openEmailDialog() {
    emailForm.email = '';
    emailForm.code = '';
    emailForm.oldCode = '';
    emailCountdown.value = 0;
    emailOldCountdown.value = 0;
    clearEmailTimer();
    clearEmailOldTimer();
    emailDialogVisible.value = true;
  }

  async function sendPhoneOldVerificationCode() {
    if (!profileForm.phone) {
      MessagePlugin.warning('当前账号未绑定手机号');
      return;
    }
    try {
      await runWithCaptcha(async (captcha: unknown) => {
        await clientAuthApi.sendPhoneCode({ phone: profileForm.phone, purpose: 'verify_bound_phone', captcha });
      });
      MessagePlugin.success('验证码已发送至原手机号');
      phoneOldCountdown.value = 60;
      phoneOldTimer = setInterval(() => {
        phoneOldCountdown.value -= 1;
        if (phoneOldCountdown.value <= 0) {
          clearPhoneOldTimer();
          phoneOldCountdown.value = 0;
        }
      }, 1000);
    } catch (error: unknown) {
      MessagePlugin.error(getErrorMessage(error, '验证码发送失败'));
    }
  }

  async function sendEmailOldVerificationCode() {
    if (!profileForm.email) {
      MessagePlugin.warning('当前账号未绑定邮箱');
      return;
    }
    try {
      await runWithCaptcha(async (captcha: unknown) => {
        await clientAuthApi.sendEmailCode({ email: profileForm.email, purpose: 'verify_bound_email', captcha });
      });
      MessagePlugin.success('验证码已发送至原邮箱');
      emailOldCountdown.value = 60;
      emailOldTimer = setInterval(() => {
        emailOldCountdown.value -= 1;
        if (emailOldCountdown.value <= 0) {
          clearEmailOldTimer();
          emailOldCountdown.value = 0;
        }
      }, 1000);
    } catch (error: unknown) {
      MessagePlugin.error(getErrorMessage(error, '验证码发送失败'));
    }
  }

  async function sendPhoneVerificationCode() {
    if (!phoneForm.phone) {
      MessagePlugin.warning('请输入新手机号');
      return;
    }
    try {
      await runWithCaptcha(async (captcha: unknown) => {
        await clientAuthApi.sendPhoneCode({
          phone: phoneForm.phone,
          purpose: profileForm.phone ? 'change_phone' : 'bind_phone',
          captcha,
        });
      });
      MessagePlugin.success('验证码已发送');
      phoneCountdown.value = 60;
      phoneTimer = setInterval(() => {
        phoneCountdown.value -= 1;
        if (phoneCountdown.value <= 0) {
          clearPhoneTimer();
          phoneCountdown.value = 0;
        }
      }, 1000);
    } catch (error: unknown) {
      MessagePlugin.error(getErrorMessage(error, '验证码发送失败'));
    }
  }

  async function sendEmailVerificationCode() {
    if (!emailForm.email) {
      MessagePlugin.warning('请输入新邮箱');
      return;
    }
    try {
      await runWithCaptcha(async (captcha: unknown) => {
        await clientAuthApi.sendEmailCode({ email: emailForm.email, captcha });
      });
      MessagePlugin.success('验证码已发送');
      emailCountdown.value = 60;
      emailTimer = setInterval(() => {
        emailCountdown.value -= 1;
        if (emailCountdown.value <= 0) {
          clearEmailTimer();
          emailCountdown.value = 0;
        }
      }, 1000);
    } catch (error: unknown) {
      MessagePlugin.error(getErrorMessage(error, '验证码发送失败'));
    }
  }

  async function submitPhoneChange() {
    if (!phoneForm.phone) {
      MessagePlugin.warning('请输入新手机号');
      return;
    }
    if (!phoneForm.code || phoneForm.code.length !== 6) {
      MessagePlugin.warning('请输入 6 位验证码');
      return;
    }
    if (profileForm.phone && (!phoneForm.oldCode || phoneForm.oldCode.length !== 6)) {
      MessagePlugin.warning('请输入原手机号验证码');
      return;
    }
    profileLoading.value = true;
    try {
      await clientAuthApi.updatePhone({
        phone: phoneForm.phone,
        code: phoneForm.code,
        old_code: phoneForm.oldCode || undefined,
      });
      MessagePlugin.success('手机号修改成功');
      phoneDialogVisible.value = false;
      clearPhoneTimer();
      clearPhoneOldTimer();
      await loadProfile();
    } catch (error: unknown) {
      MessagePlugin.error(getErrorMessage(error, '手机号修改失败'));
    } finally {
      profileLoading.value = false;
    }
  }

  async function submitEmailChange() {
    if (!emailForm.email) {
      MessagePlugin.warning('请输入新邮箱');
      return;
    }
    if (!emailForm.code || emailForm.code.length !== 6) {
      MessagePlugin.warning('请输入 6 位验证码');
      return;
    }
    if (profileForm.email && (!emailForm.oldCode || emailForm.oldCode.length !== 6)) {
      MessagePlugin.warning('请输入原邮箱验证码');
      return;
    }
    profileLoading.value = true;
    try {
      await clientAuthApi.updateEmail({
        email: emailForm.email,
        code: emailForm.code,
        old_code: emailForm.oldCode || undefined,
      });
      MessagePlugin.success('邮箱修改成功');
      emailDialogVisible.value = false;
      clearEmailTimer();
      clearEmailOldTimer();
      await loadProfile();
    } catch (error: unknown) {
      MessagePlugin.error(getErrorMessage(error, '邮箱修改失败'));
    } finally {
      profileLoading.value = false;
    }
  }

  function hydrateProfile(info: ClientUserInfo = {}) {
    profileForm.id = String(info.id || '');
    profileForm.email = String(info.email || '');
    profileForm.nickname = String(info.nickname || info.name || '');
    profileForm.phone = String(info.phone || '');
    profileForm.cash_balance = String(info.cash_balance || '0.00');
    profileForm.createdAt = String(info.created_at || '');
    profileForm.is_verified = Number(info.is_verified || 0);
    profileForm.real_name = String(info.real_name || '');
    profileForm.id_card_masked = String(info.id_card_masked || '');
    agentGroup.value = info.agent_group || null;
  }

  async function loadProfile() {
    const info = await userStore.getUserInfo();
    hydrateProfile(info);
  }

  async function copyText(text: string) {
    await copyShared(text, { successMsg: '复制成功' });
  }

  function handleProfileTabChange(value: unknown) {
    if (typeof value === 'string' && PROFILE_TABS.has(value as ProfileTab)) {
      activeTab.value = value as ProfileTab;
    }
  }

  async function updateProfile() {
    const trimmed = (profileForm.nickname || '').trim();
    if (!trimmed) {
      MessagePlugin.warning('用户名不能为空');
      return;
    }
    if (trimmed.length > 50) {
      MessagePlugin.warning('用户名最多 50 个字符');
      return;
    }
    profileLoading.value = true;
    try {
      await clientAuthApi.updateProfile({ nickname: trimmed });
      await loadProfile();
      MessagePlugin.success('用户名修改成功');
    } catch (error: unknown) {
      MessagePlugin.error(getErrorMessage(error, '资料保存失败'));
    } finally {
      profileLoading.value = false;
    }
  }

  async function changePassword() {
    if (!passwordForm.oldPassword || !passwordForm.newPassword) {
      MessagePlugin.warning('请填写完整密码信息');
      return;
    }
    // 与 submitResetPassword 同口径：后端 UpdatePasswordRequest 的 newPassword 是 min:8。
    if (passwordForm.newPassword.length < 8) {
      MessagePlugin.warning('新密码长度不能少于 8 位');
      return;
    }
    if (passwordForm.newPassword !== passwordForm.confirmPassword) {
      MessagePlugin.warning('两次密码输入不一致');
      return;
    }
    profileLoading.value = true;
    try {
      await clientAuthApi.changePassword({
        oldPassword: passwordForm.oldPassword,
        newPassword: passwordForm.newPassword,
        confirmPassword: passwordForm.confirmPassword,
      });
      MessagePlugin.success('密码修改成功，请重新登录');
      passwordDialogVisible.value = false;
      await userStore.logout();
      router.push('/client/login');
    } catch (error: unknown) {
      MessagePlugin.error(getErrorMessage(error, '修改失败'));
    } finally {
      profileLoading.value = false;
    }
  }

  async function loadNotificationPreferences() {
    try {
      const response = await clientAuthApi.notificationPreferences();
      const data = response.data || {};
      notificationList.forEach((item) => {
        item.enabled = Boolean(data[item.key]);
      });
    } catch {
      // 通知设置失败时使用默认关闭状态，不影响资料页主体。
    }
  }

  async function saveNotificationPreferences() {
    notificationLoading.value = true;
    try {
      const settings = Object.fromEntries(notificationList.map((item) => [item.key, item.enabled]));
      await clientAuthApi.updateNotificationPreferences(settings);
      MessagePlugin.success('设置保存成功');
    } catch (error: unknown) {
      MessagePlugin.error(getErrorMessage(error, '保存失败'));
    } finally {
      notificationLoading.value = false;
    }
  }

  onMounted(() => {
    void Promise.all([loadProfile(), loadNotificationPreferences()]);
  });

  onBeforeUnmount(() => {
    clearPhoneTimer();
    clearEmailTimer();
    clearPhoneOldTimer();
    clearEmailOldTimer();
    clearResetTimer();
  });

  return {
    router,
    activeTab,
    profileLoading,
    notificationLoading,
    passwordDialogVisible,
    phoneDialogVisible,
    emailDialogVisible,
    passwordMode,
    profileForm,
    agentGroup,
    passwordForm,
    resetForm,
    resetCountdown,
    phoneForm,
    emailForm,
    phoneCountdown,
    emailCountdown,
    phoneOldCountdown,
    emailOldCountdown,
    notificationList,
    balanceText,
    enabledNotificationCount,
    securityItems,
    copyText,
    updateProfile,
    changePassword,
    togglePasswordMode,
    sendResetCode,
    submitResetPassword,
    sendPhoneVerificationCode,
    sendEmailVerificationCode,
    sendPhoneOldVerificationCode,
    sendEmailOldVerificationCode,
    submitPhoneChange,
    submitEmailChange,
    saveNotificationPreferences,
    handleProfileTabChange,
  };
}
