import { MessagePlugin } from 'tdesign-vue-next';
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';

import { clientAuthApi } from '@/api/auth';
import { useGeeTestCaptcha } from '@/composables/useGeeTestCaptcha';
import {
  agentGroup,
  isProfileDataLoaded,
  notificationList,
  profileForm,
  resetProfileState,
  setProfileDataLoaded,
} from '@/domains/account/profileState';
import { t } from '@/locales';
import { useUserStore } from '@/store';
import type { ClientNotificationPreferences, ClientUserInfo } from '@/types/client';
import { getErrorMessage } from '@/utils/error';
import { copyText as copyShared } from '@/utils/format';

type TagTheme = 'default' | 'success' | 'warning' | 'primary' | 'danger';
type ProfileTab = 'profile' | 'security' | 'agent' | 'notification';

const PROFILE_TABS = new Set<ProfileTab>(['profile', 'security', 'agent', 'notification']);

export function useProfile() {
  const router = useRouter();
  const userStore = useUserStore();
  const { runWithCaptcha } = useGeeTestCaptcha({
    onPrompt: () => MessagePlugin.warning(t('profile.captchaRequired')),
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
  const passwordForm = reactive({ oldPassword: '', newPassword: '', confirmPassword: '' });
  const passwordMode = ref<'old' | 'reset'>('old');
  const resetForm = reactive({ type: 'phone' as 'phone' | 'email', code: '', password: '', confirmPassword: '' });
  const resetCountdown = ref(0);
  let resetTimer: ReturnType<typeof setInterval> | null = null;

  const balanceText = computed(() => `¥${profileForm.cash_balance || '0.00'}`);
  const enabledNotificationCount = computed(() => notificationList.filter((item) => item.enabled).length);
  const securityItems = computed(() => [
    {
      key: 'verification',
      name: t('profile.realNameAuth'),
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
      name: t('profile.securePhone'),
      desc: profileForm.phone || '绑定手机号后，可用于验证码接收和安全校验',
      theme: (profileForm.phone ? 'success' : 'warning') as TagTheme,
      tag: profileForm.phone ? '已绑定' : '未绑定',
      actionLabel: profileForm.phone ? '更换绑定' : '前往绑定',
      action: () => openPhoneDialog(),
    },
    {
      key: 'email',
      name: t('profile.secureEmail'),
      desc: profileForm.email || '建议绑定常用邮箱，用于接收通知与安全提醒',
      theme: (profileForm.email ? 'success' : 'warning') as TagTheme,
      tag: profileForm.email ? '已绑定' : '未绑定',
      actionLabel: profileForm.email ? '更换绑定' : '前往绑定',
      action: () => openEmailDialog(),
    },
    {
      key: 'password',
      name: t('profile.loginPassword'),
      desc: t('account.password.tips'),
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
      MessagePlugin.success(t('profile.codeSent'));
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
      MessagePlugin.warning(t('profile.codeRequired'));
      return;
    }
    if (!resetForm.password) {
      MessagePlugin.warning(t('profile.newPasswordRequired'));
      return;
    }
    // 这里此前只判空。后端 ResetPasswordRequest 是 min:8，短密码会一路发出去再被 422 打回。
    if (resetForm.password.length < 8) {
      MessagePlugin.warning(t('profile.passwordMinLength'));
      return;
    }
    if (resetForm.password !== resetForm.confirmPassword) {
      MessagePlugin.warning(t('profile.passwordMismatch'));
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
      MessagePlugin.success(t('profile.passwordResetSuccess'));
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
      MessagePlugin.warning(t('profile.phoneNotBound'));
      return;
    }
    try {
      await runWithCaptcha(async (captcha: unknown) => {
        await clientAuthApi.sendPhoneCode({ phone: profileForm.phone, purpose: 'verify_bound_phone', captcha });
      });
      MessagePlugin.success(t('profile.codeSentToOldPhone'));
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
      MessagePlugin.warning(t('profile.emailNotBound'));
      return;
    }
    try {
      await runWithCaptcha(async (captcha: unknown) => {
        await clientAuthApi.sendEmailCode({ email: profileForm.email, purpose: 'verify_bound_email', captcha });
      });
      MessagePlugin.success(t('profile.codeSentToOldEmail'));
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
      MessagePlugin.warning(t('profile.newPhoneRequired'));
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
      MessagePlugin.success(t('profile.codeSent'));
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
      MessagePlugin.warning(t('profile.newEmailRequired'));
      return;
    }
    try {
      await runWithCaptcha(async (captcha: unknown) => {
        await clientAuthApi.sendEmailCode({ email: emailForm.email, captcha });
      });
      MessagePlugin.success(t('profile.codeSent'));
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
      MessagePlugin.warning(t('profile.newPhoneRequired'));
      return;
    }
    if (!phoneForm.code || phoneForm.code.length !== 6) {
      MessagePlugin.warning(t('profile.codeRequired'));
      return;
    }
    if (profileForm.phone && (!phoneForm.oldCode || phoneForm.oldCode.length !== 6)) {
      MessagePlugin.warning(t('profile.oldPhoneCodeRequired'));
      return;
    }
    profileLoading.value = true;
    try {
      await clientAuthApi.updatePhone({
        phone: phoneForm.phone,
        code: phoneForm.code,
        old_code: phoneForm.oldCode || undefined,
      });
      MessagePlugin.success(t('profile.phoneUpdated'));
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
      MessagePlugin.warning(t('profile.newEmailRequired'));
      return;
    }
    if (!emailForm.code || emailForm.code.length !== 6) {
      MessagePlugin.warning(t('profile.codeRequired'));
      return;
    }
    if (profileForm.email && (!emailForm.oldCode || emailForm.oldCode.length !== 6)) {
      MessagePlugin.warning(t('profile.oldEmailCodeRequired'));
      return;
    }
    profileLoading.value = true;
    try {
      await clientAuthApi.updateEmail({
        email: emailForm.email,
        code: emailForm.code,
        old_code: emailForm.oldCode || undefined,
      });
      MessagePlugin.success(t('profile.emailUpdated'));
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
    profileForm.qq = String(info.qq || '');
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
      MessagePlugin.warning(t('profile.usernameRequired'));
      return;
    }
    if (trimmed.length > 50) {
      MessagePlugin.warning(t('profile.usernameMaxLength'));
      return;
    }
    const trimmedQq = (profileForm.qq || '').trim();
    if (trimmedQq && !/^\d{5,20}$/.test(trimmedQq)) {
      MessagePlugin.warning(t('profile.qqFormatInvalid'));
      return;
    }
    profileLoading.value = true;
    try {
      await clientAuthApi.updateProfile({ nickname: trimmed, qq: trimmedQq });
      await loadProfile();
      MessagePlugin.success(t('profile.updated'));
    } catch (error: unknown) {
      MessagePlugin.error(getErrorMessage(error, '资料保存失败'));
    } finally {
      profileLoading.value = false;
    }
  }

  async function changePassword() {
    if (!passwordForm.oldPassword || !passwordForm.newPassword) {
      MessagePlugin.warning(t('profile.passwordInfoRequired'));
      return;
    }
    // 与 submitResetPassword 同口径：后端 UpdatePasswordRequest 的 newPassword 是 min:8。
    if (passwordForm.newPassword.length < 8) {
      MessagePlugin.warning(t('profile.newPasswordMinLength'));
      return;
    }
    if (passwordForm.newPassword !== passwordForm.confirmPassword) {
      MessagePlugin.warning(t('profile.passwordMismatch'));
      return;
    }
    profileLoading.value = true;
    try {
      await clientAuthApi.changePassword({
        oldPassword: passwordForm.oldPassword,
        newPassword: passwordForm.newPassword,
        confirmPassword: passwordForm.confirmPassword,
      });
      MessagePlugin.success(t('profile.passwordChanged'));
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
      MessagePlugin.success(t('profile.settingsSaved'));
    } catch (error: unknown) {
      MessagePlugin.error(getErrorMessage(error, '保存失败'));
    } finally {
      notificationLoading.value = false;
    }
  }

  onMounted(() => {
    // 已有数据才复用。若共享数据为空（例如会话被清理过），照常重新拉取，
    // 避免页面停在空状态；复用时只用内存里的用户信息同步展示字段，
    // 不发网络请求，也不会覆盖消息提醒里尚未保存的开关改动。
    // profileForm 是跨组件共享状态，必须确认已加载的资料属于当前登录账号：
    // 否则切换账号后会把上一个账号的资料当成当前账号展示。
    const currentUserId = userStore.info?.id != null ? String(userStore.info.id) : '';
    const loadedUserId = profileForm.id != null ? String(profileForm.id) : '';
    const isSameAccount = currentUserId !== '' && loadedUserId !== '' && currentUserId === loadedUserId;

    if (isProfileDataLoaded() && loadedUserId !== '' && isSameAccount) {
      if (userStore.info) {
        hydrateProfile(userStore.info as ClientUserInfo);
      }
      return;
    }

    // 账号不一致（或共享状态无归属）：清掉旧账号残留，避免被当作当前账号资料
    if (loadedUserId !== '' && !isSameAccount) {
      resetProfileState();
    }

    setProfileDataLoaded(true);
    void Promise.all([loadProfile(), loadNotificationPreferences()]).catch(() => {
      // 拉取失败时复位标记，下次进入页面会自动重试，不会停在空状态
      setProfileDataLoaded(false);
    });
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
