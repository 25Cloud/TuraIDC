import { MessagePlugin } from 'tdesign-vue-next';
import { computed, onBeforeUnmount, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';

import { clientAuthApi } from '@/api/auth';
import { useUserStore } from '@/store';
import type { ClientUserInfo, ClientVerificationPayload } from '@/types/client';

const IDENTITY_CARD_PATTERN = /^(?:\d{15}|\d{17}[\dX])$/i;
const QR_SESSION_TTL_SECONDS = 300;

function pickQrUrl(payload: Record<string, unknown>) {
  return String(payload.qrcode_url || payload.qr_code_url || payload.scan_url || payload.url || '').trim();
}

function resolveMessage(error: unknown, fallback: string) {
  return error instanceof Error && error.message ? error.message : fallback;
}

function parseDateTime(value: unknown): number | null {
  const text = String(value || '').trim();
  if (!text) return null;
  const directTimestamp = new Date(text).getTime();
  if (Number.isFinite(directTimestamp)) return directTimestamp;

  const normalizedTimestamp = new Date(text.replace(/-/g, '/')).getTime();
  return Number.isFinite(normalizedTimestamp) ? normalizedTimestamp : null;
}

function resolveExpiresAt(payload: ClientVerificationPayload | null | undefined) {
  const data = payload || {};
  const explicitExpiresAt =
    parseDateTime(data.qrcode_expires_at) || parseDateTime(data.qr_code_expires_at) || parseDateTime(data.expires_at);
  if (explicitExpiresAt) return explicitExpiresAt;

  const seconds = Number(data.expires_in_seconds ?? data.expires_in ?? QR_SESSION_TTL_SECONDS);
  const ttlSeconds = Number.isFinite(seconds) && seconds > 0 ? seconds : QR_SESSION_TTL_SECONDS;

  return Date.now() + ttlSeconds * 1000;
}

function formatRemainingSeconds(seconds: number) {
  const normalized = Math.max(0, Math.floor(seconds));
  const minutes = Math.floor(normalized / 60);
  const remainSeconds = normalized % 60;

  return `${String(minutes).padStart(2, '0')}:${String(remainSeconds).padStart(2, '0')}`;
}

export function useVerification() {
  const route = useRoute();
  const router = useRouter();
  const userStore = useUserStore();

  const loading = ref(false);
  const verificationLoading = ref(false);
  const checkingStatus = ref(false);
  const showVerificationDialog = ref(false);
  const showVerifiedInfoDialog = ref(false);
  const verificationUrl = ref('');
  const verificationExpiresAt = ref(0);
  const verificationRemainingSeconds = ref(0);
  const certifyId = ref('');
  const canRestartVerification = ref(false);
  const verificationMessage = ref('');
  const form = reactive({
    id: '',
    email: '',
    nickname: '',
    phone: '',
    is_verified: 0,
    real_name: '',
    id_card_masked: '',
    verification_status: 0,
    verification_message: '',
  });
  const verificationForm = reactive({
    realName: '',
    idCard: '',
  });

  let pollingTimer: number | null = null;
  let expiryTimer: number | null = null;
  const closingSession = ref(false);

  const isVerified = computed(() => Number(form.is_verified || 0) === 1 || Number(form.verification_status || 0) === 2);
  const statusTheme = computed(() => (isVerified.value ? 'success' : 'warning'));
  const statusLabel = computed(() => (isVerified.value ? '已认证' : '待认证'));
  const statusTitle = computed(() => (isVerified.value ? '已完成实名认证' : '尚未完成实名认证'));
  const statusDescription = computed(() =>
    isVerified.value
      ? '当前账户已经通过实名校验，可继续购买需要实名的产品。'
      : '完成实名后，可继续购买受实名限制的商品并提升账户安全性。',
  );
  const canSubmit = computed(
    () => verificationForm.realName.trim().length > 0 && verificationForm.idCard.trim().length > 0,
  );
  const isVerificationQrExpired = computed(
    () => Boolean(verificationUrl.value) && verificationRemainingSeconds.value <= 0,
  );
  const verificationCountdownText = computed(() => formatRemainingSeconds(verificationRemainingSeconds.value));

  function syncFromUserInfo(info: ClientUserInfo = {}) {
    form.id = String(info.id || '');
    form.email = String(info.email || '');
    form.nickname = String(info.nickname || info.name || '');
    form.phone = String(info.phone || '');
    form.is_verified = Number(info.is_verified || 0);
    form.real_name = String(info.real_name || '');
    form.id_card_masked = String(info.id_card_masked || '');
    form.verification_status = Number(info.verification_status || 0);
    form.verification_message = String(info.verification_message || '');
    certifyId.value = String(info.verification_certify_id || certifyId.value || '');
  }

  async function refreshUserInfo() {
    const info = await userStore.getUserInfo();
    syncFromUserInfo(info);
  }

  async function loadProfile() {
    loading.value = true;
    try {
      if (!userStore.info?.name) {
        await refreshUserInfo();
      } else {
        syncFromUserInfo(userStore.info);
      }
    } catch (error: unknown) {
      MessagePlugin.error(resolveMessage(error, '实名信息加载失败'));
    } finally {
      loading.value = false;
    }
  }

  function openVerificationEntry() {
    if (isVerified.value) {
      showVerifiedInfoDialog.value = true;
      return;
    }
    if (!verificationForm.realName && form.real_name) verificationForm.realName = form.real_name;
    showVerificationDialog.value = true;
    if (certifyId.value && !verificationUrl.value) {
      void refreshVerificationLink();
    }
  }

  function validateVerificationForm() {
    if (!verificationForm.realName.trim()) {
      MessagePlugin.warning('请输入真实姓名');
      return false;
    }
    if (!verificationForm.idCard.trim()) {
      MessagePlugin.warning('请输入身份证号');
      return false;
    }
    if (!IDENTITY_CARD_PATTERN.test(verificationForm.idCard.trim())) {
      MessagePlugin.warning('身份证号格式不正确');
      return false;
    }
    return true;
  }

  function applyVerificationPayload(payload: ClientVerificationPayload | null | undefined) {
    const data = payload || {};
    certifyId.value = String(data.certify_id || certifyId.value || '');
    verificationUrl.value = pickQrUrl(data);
    if (verificationUrl.value) {
      verificationExpiresAt.value = resolveExpiresAt(data);
      verificationMessage.value = '';
      startExpiryCountdown();
    }
  }

  async function submitVerification() {
    if (!validateVerificationForm()) return;
    verificationLoading.value = true;
    try {
      const res = await clientAuthApi.initVerification({
        realname: verificationForm.realName.trim(),
        idcard: verificationForm.idCard.trim(),
      });
      applyVerificationPayload(res.data);
      if (!verificationUrl.value) await refreshVerificationLink();
      if (verificationUrl.value) MessagePlugin.success('二维码已生成，请扫码继续认证');
      startPolling();
    } catch (error: unknown) {
      MessagePlugin.error(resolveMessage(error, '提交失败'));
    } finally {
      verificationLoading.value = false;
    }
  }

  async function refreshVerificationLink() {
    if (!certifyId.value) {
      MessagePlugin.warning('缺少认证会话，请重新提交实名信息');
      return;
    }
    verificationLoading.value = true;
    try {
      const res = await clientAuthApi.verificationQrcode({ certify_id: certifyId.value });
      applyVerificationPayload(res.data);
      if (!verificationUrl.value) throw new Error('未获取到实名服务商链接');
      startPolling();
    } catch (error: unknown) {
      MessagePlugin.error(resolveMessage(error, '获取认证链接失败'));
    } finally {
      verificationLoading.value = false;
    }
  }

  function stopPolling() {
    if (pollingTimer !== null) {
      window.clearInterval(pollingTimer);
      pollingTimer = null;
    }
  }

  function stopExpiryCountdown() {
    if (expiryTimer !== null) {
      window.clearInterval(expiryTimer);
      expiryTimer = null;
    }
  }

  function updateExpiryCountdown() {
    if (!verificationExpiresAt.value) {
      verificationRemainingSeconds.value = 0;
      return;
    }

    verificationRemainingSeconds.value = Math.max(0, Math.ceil((verificationExpiresAt.value - Date.now()) / 1000));
    if (verificationRemainingSeconds.value > 0) return;

    stopExpiryCountdown();
    stopPolling();
    if (verificationUrl.value) {
      verificationMessage.value = '二维码已失效，请刷新后继续认证';
    }
  }

  function startExpiryCountdown() {
    stopExpiryCountdown();
    updateExpiryCountdown();
    if (!verificationUrl.value || verificationRemainingSeconds.value <= 0) return;

    expiryTimer = window.setInterval(updateExpiryCountdown, 1000);
  }

  function clearVerificationSessionState() {
    stopPolling();
    stopExpiryCountdown();
    verificationUrl.value = '';
    verificationExpiresAt.value = 0;
    verificationRemainingSeconds.value = 0;
  }

  function startPolling() {
    stopPolling();
    if (!showVerificationDialog.value || !verificationUrl.value || !certifyId.value || isVerificationQrExpired.value)
      return;
    pollingTimer = window.setInterval(() => {
      void checkVerificationStatus(true);
    }, 1000);
  }

  async function checkVerificationStatus(silent = false) {
    if (!certifyId.value || checkingStatus.value) return;
    if (silent && isVerificationQrExpired.value) return;
    checkingStatus.value = true;
    try {
      const res = await clientAuthApi.verificationStatus({ certify_id: certifyId.value });
      const payload = res.data || {};
      verificationMessage.value = String(payload.msg || payload.message || '');
      if (Number(payload.status) === 1) {
        MessagePlugin.success('认证成功');
        clearVerificationSessionState();
        showVerificationDialog.value = false;
        canRestartVerification.value = false;
        await refreshUserInfo();
      } else if (Number(payload.status) === 4) {
        canRestartVerification.value = false;
        if (!silent) MessagePlugin.warning(verificationMessage.value || '认证处理中，请稍后再试');
      } else {
        canRestartVerification.value = Boolean(payload.can_restart);
        if (!silent) MessagePlugin.warning(verificationMessage.value || '认证未完成');
      }
    } catch (error: unknown) {
      if (!silent) MessagePlugin.error(resolveMessage(error, '查询失败'));
    } finally {
      checkingStatus.value = false;
    }
  }

  async function restartVerification() {
    verificationLoading.value = true;
    canRestartVerification.value = false;
    stopPolling();
    try {
      const res = await clientAuthApi.restartVerification();
      applyVerificationPayload(res.data);
      if (!verificationUrl.value) await refreshVerificationLink();
      if (verificationUrl.value) MessagePlugin.success('已重新生成二维码，请重新扫码');
      startPolling();
    } catch (error: unknown) {
      canRestartVerification.value = true;
      MessagePlugin.error(resolveMessage(error, '重新认证失败'));
    } finally {
      verificationLoading.value = false;
    }
  }

  async function closeVerificationSession(silent = false) {
    if (closingSession.value) return;

    const closingCertifyId = certifyId.value;
    const shouldNotifyBackend = Boolean(closingCertifyId && verificationUrl.value);
    closingSession.value = true;
    clearVerificationSessionState();
    showVerificationDialog.value = false;

    try {
      if (shouldNotifyBackend) {
        await clientAuthApi.closeVerificationSession({ certify_id: closingCertifyId });
      }
      verificationMessage.value = '';
      if (!silent && shouldNotifyBackend) MessagePlugin.success('认证会话已关闭');
    } catch (error: unknown) {
      if (!silent) MessagePlugin.error(resolveMessage(error, '关闭认证会话失败'));
    } finally {
      closingSession.value = false;
    }
  }

  function handleVerificationDialogClose() {
    void closeVerificationSession(true);
  }

  async function handleCallbackQuery() {
    if (route.query.verification_callback !== '1') return;

    const callbackCertifyId = typeof route.query.certify_id === 'string' ? route.query.certify_id : '';
    if (callbackCertifyId) certifyId.value = callbackCertifyId;
    if (!isVerified.value) showVerificationDialog.value = true;
    await checkVerificationStatus(false);

    const query = { ...route.query };
    delete query.verification_callback;
    delete query.certify_id;
    delete query.result_status;
    delete query.result_message;
    delete query.t;
    await router.replace({ path: route.path, query });
  }

  onBeforeUnmount(() => {
    stopPolling();
    stopExpiryCountdown();
  });

  return {
    router,
    loading,
    verificationLoading,
    checkingStatus,
    closingSession,
    showVerificationDialog,
    showVerifiedInfoDialog,
    verificationUrl,
    verificationRemainingSeconds,
    verificationCountdownText,
    isVerificationQrExpired,
    certifyId,
    canRestartVerification,
    verificationMessage,
    form,
    verificationForm,
    isVerified,
    statusTheme,
    statusLabel,
    statusTitle,
    statusDescription,
    canSubmit,
    loadProfile,
    refreshUserInfo,
    openVerificationEntry,
    submitVerification,
    refreshVerificationLink,
    checkVerificationStatus,
    restartVerification,
    closeVerificationSession,
    handleVerificationDialogClose,
    handleCallbackQuery,
    stopPolling,
  };
}
