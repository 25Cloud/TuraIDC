import { MessagePlugin } from 'tdesign-vue-next';
import { onMounted, reactive, ref } from 'vue';

import clientApi from '@/api/client';
import type {
  ApiKeyUsageLogRecord,
  UpstreamApiCredential,
  UpstreamApiPolicyPayload,
  UpstreamApiStatus,
} from '@/types/client';

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
 * 但治理能力与开放接口对齐：同样有 IP 白名单、有效期与调用审计。
 */
export function useUpstreamApi() {
  const loading = ref(false);
  const status = ref<UpstreamApiStatus | null>(null);
  const loadError = ref('');
  const submitting = ref(false);

  const credentialVisible = ref(false);
  const credential = ref<UpstreamApiCredential | null>(null);
  const credentialTitle = ref('');

  const logVisible = ref(false);
  const logLoading = ref(false);
  const logs = ref<ApiKeyUsageLogRecord[]>([]);

  /** 安全策略表单：多个 IP 用英文逗号分隔，与开放接口密钥的编辑体验一致 */
  const policyForm = reactive({
    ip_allowlist: '',
    expires_at: '',
  });

  function syncPolicyForm(source: UpstreamApiStatus | null) {
    const allowlist = Array.isArray(source?.ip_allowlist) ? source?.ip_allowlist : [];
    policyForm.ip_allowlist = allowlist.join(',');
    policyForm.expires_at = source?.expires_at ? String(source.expires_at).slice(0, 16) : '';
  }

  async function loadStatus() {
    loading.value = true;
    loadError.value = '';
    try {
      const response = await clientApi.upstreamApiStatus();
      status.value = (response?.data ?? null) as UpstreamApiStatus | null;
      syncPolicyForm(status.value);
    } catch (error) {
      loadError.value = getErrorMessage(error, '加载上游 API 状态失败');
      status.value = null;
    } finally {
      loading.value = false;
    }
  }

  function collectPolicyPayload(): UpstreamApiPolicyPayload {
    const payload: UpstreamApiPolicyPayload = {
      ip_allowlist: policyForm.ip_allowlist
        .split(',')
        .map((item) => item.trim())
        .filter(Boolean),
      expires_at: policyForm.expires_at || null,
    };

    return payload;
  }

  async function enable() {
    submitting.value = true;
    try {
      const response = await clientApi.enableUpstreamApi(collectPolicyPayload());
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
      MessagePlugin.success('上游 API 已关闭，同账号的开放接口密钥也已停用');
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

  async function savePolicy() {
    submitting.value = true;
    try {
      await clientApi.updateUpstreamApiPolicy(collectPolicyPayload());
      await loadStatus();
      MessagePlugin.success('安全策略已更新');
    } catch (error) {
      MessagePlugin.error(getErrorMessage(error, '策略更新失败'));
    } finally {
      submitting.value = false;
    }
  }

  async function openUsageLogs() {
    logVisible.value = true;
    logLoading.value = true;
    logs.value = [];
    try {
      const response = await clientApi.upstreamApiUsageLogs();
      logs.value = Array.isArray(response.data?.list) ? response.data.list : [];
    } catch (error) {
      MessagePlugin.error(getErrorMessage(error, '调用日志加载失败'));
    } finally {
      logLoading.value = false;
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
    logVisible,
    logLoading,
    logs,
    policyForm,
    loadStatus,
    enable,
    disable,
    resetPassword,
    savePolicy,
    openUsageLogs,
    copyText,
    closeCredential,
  };
}
