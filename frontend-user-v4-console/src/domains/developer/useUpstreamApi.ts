import { MessagePlugin } from 'tdesign-vue-next';
import { onMounted, ref } from 'vue';

import clientApi from '@/api/client';
import type { UpstreamApiCredential, UpstreamApiStatus } from '@/types/client';

function getErrorMessage(error: unknown, fallback: string) {
  if (error instanceof Error && error.message) return error.message;
  if (typeof error === 'object' && error !== null && 'message' in error && typeof error.message === 'string') {
    return error.message;
  }
  return fallback;
}

/**
 * 魔方财务上游 API 凭据（本系统作为上游被魔方财务对接）。
 *
 * 与开放接口密钥（useApiKeys）是两条独立鉴权链路：这里的凭据落在账号上，
 * 供魔方财务在「上游」配置里填 username/password 换 JWT。
 */
export function useUpstreamApi() {
  const loading = ref(false);
  const status = ref<UpstreamApiStatus | null>(null);
  const loadError = ref('');
  const submitting = ref(false);

  const credentialVisible = ref(false);
  const credential = ref<UpstreamApiCredential | null>(null);
  const credentialTitle = ref('');

  async function loadStatus() {
    loading.value = true;
    loadError.value = '';
    try {
      const response = await clientApi.upstreamApiStatus();
      status.value = (response?.data ?? null) as UpstreamApiStatus | null;
    } catch (error) {
      loadError.value = getErrorMessage(error, '加载上游 API 状态失败');
      status.value = null;
    } finally {
      loading.value = false;
    }
  }

  async function enable() {
    submitting.value = true;
    try {
      const response = await clientApi.enableUpstreamApi();
      const payload = (response?.data ?? null) as UpstreamApiCredential | null;
      if (payload?.password) {
        credential.value = payload;
        credentialTitle.value = '上游 API 凭据（仅显示这一次）';
        credentialVisible.value = true;
      }
      await loadStatus();
      MessagePlugin.success('上游 API 已开启');
    } catch (error) {
      MessagePlugin.error(getErrorMessage(error, '开启失败'));
    } finally {
      submitting.value = false;
    }
  }

  async function disable() {
    submitting.value = true;
    try {
      await clientApi.disableUpstreamApi();
      await loadStatus();
      MessagePlugin.success('上游 API 已关闭');
    } catch (error) {
      MessagePlugin.error(getErrorMessage(error, '关闭失败'));
    } finally {
      submitting.value = false;
    }
  }

  async function resetPassword() {
    submitting.value = true;
    try {
      const response = await clientApi.resetUpstreamApiPassword();
      const payload = (response?.data ?? null) as UpstreamApiCredential | null;
      if (payload?.password) {
        credential.value = payload;
        credentialTitle.value = '新的上游 API 密码（仅显示这一次）';
        credentialVisible.value = true;
      }
      await loadStatus();
      MessagePlugin.success('密码已重置');
    } catch (error) {
      MessagePlugin.error(getErrorMessage(error, '重置失败'));
    } finally {
      submitting.value = false;
    }
  }

  async function copyText(value: string) {
    try {
      await navigator.clipboard.writeText(value);
      MessagePlugin.success('已复制');
    } catch {
      MessagePlugin.warning('复制失败，请手动选择复制');
    }
  }

  function closeCredential() {
    credentialVisible.value = false;
    credential.value = null;
  }

  onMounted(loadStatus);

  return {
    loading,
    status,
    loadError,
    submitting,
    credentialVisible,
    credential,
    credentialTitle,
    loadStatus,
    enable,
    disable,
    resetPassword,
    copyText,
    closeCredential,
  };
}
