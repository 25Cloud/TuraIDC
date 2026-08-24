import { MessagePlugin } from 'tdesign-vue-next';
import { onMounted, reactive, ref } from 'vue';

import clientApi from '@/api/client';
import type { ApiKeyRecord, ApiKeyStorePayload, ApiKeyUsageLogRecord } from '@/types/client';

export const SCOPE_DOMAINS = [
  { key: 'products', label: '产品目录' },
  { key: 'orders', label: '订单与账单' },
  { key: 'services', label: '服务实例' },
  { key: 'finance', label: '余额与财务' },
] as const;

export type ScopeLevel = 'read' | 'write';

export function resolveScopeLevelLabel(level: unknown) {
  if (level === 'write') return '读写';
  if (level === 'read') return '只读';
  return '无';
}

export function resolveScopeTheme(level: unknown) {
  if (level === 'write') return 'primary';
  if (level === 'read') return 'success';
  return 'default';
}

export function scopesDisplay(scopes: ApiKeyRecord['scopes']) {
  if (!scopes || typeof scopes !== 'object') return [];
  return SCOPE_DOMAINS.filter((domain) => (scopes as Record<string, unknown>)[domain.key]).map((domain) => ({
    ...domain,
    level: (scopes as Record<string, string>)[domain.key],
  }));
}

function getErrorMessage(error: unknown, fallback: string) {
  if (error instanceof Error && error.message) return error.message;
  if (typeof error === 'object' && error !== null && 'message' in error && typeof error.message === 'string') {
    return error.message;
  }
  return fallback;
}

export function useApiKeys() {
  const loading = ref(false);
  const list = ref<ApiKeyRecord[]>([]);
  const listError = ref('');

  const dialogVisible = ref(false);
  const dialogMode = ref<'create' | 'edit'>('create');
  const editingKey = ref<ApiKeyRecord | null>(null);
  const submitting = ref(false);

  const form = reactive({
    name: '',
    scopes: {} as Record<string, string>,
    expires_at: '',
    ip_allowlist: '',
  });

  const secretVisible = ref(false);
  const createdSecret = ref('');
  const secretWarning = ref('');
  const createdKey = ref<ApiKeyRecord | null>(null);

  const logVisible = ref(false);
  const logLoading = ref(false);
  const logKey = ref<ApiKeyRecord | null>(null);
  const logs = ref<ApiKeyUsageLogRecord[]>([]);

  async function loadList() {
    loading.value = true;
    listError.value = '';
    try {
      const response = await clientApi.apiKeys();
      list.value = Array.isArray(response.data?.list) ? response.data.list : [];
    } catch (error: unknown) {
      listError.value = getErrorMessage(error, 'API 密钥列表加载失败');
      MessagePlugin.error(listError.value);
    } finally {
      loading.value = false;
    }
  }

  function resetForm() {
    form.name = '';
    form.scopes = {};
    form.expires_at = '';
    form.ip_allowlist = '';
  }

  function openCreateDialog() {
    dialogMode.value = 'create';
    editingKey.value = null;
    resetForm();
    dialogVisible.value = true;
  }

  function openEditDialog(key: ApiKeyRecord) {
    dialogMode.value = 'edit';
    editingKey.value = key;
    resetForm();
    form.name = key.name || '';
    form.scopes = { ...(key.scopes || {}) };
    form.expires_at = key.expires_at ? String(key.expires_at).slice(0, 16) : '';
    form.ip_allowlist = Array.isArray(key.ip_allowlist) ? key.ip_allowlist.join(',') : '';
    dialogVisible.value = true;
  }

  function collectPayload() {
    const payload: Record<string, unknown> = {
      name: form.name.trim(),
    };
    if (form.expires_at) payload.expires_at = form.expires_at;
    const allowlist = form.ip_allowlist
      .split(',')
      .map((item) => item.trim())
      .filter(Boolean);
    if (allowlist.length) payload.ip_allowlist = allowlist;

    const scopes: Record<string, ScopeLevel> = {};
    for (const domain of SCOPE_DOMAINS) {
      const level = form.scopes[domain.key];
      if (level === 'read' || level === 'write') scopes[domain.key] = level;
    }
    payload.scopes = scopes;

    return payload;
  }

  async function submit() {
    if (!form.name.trim()) {
      MessagePlugin.warning('请输入密钥名称');
      return;
    }
    submitting.value = true;
    try {
      if (dialogMode.value === 'create') {
        const response = await clientApi.createApiKey(collectPayload());
        const payload = response.data as ApiKeyStorePayload;
        createdKey.value = payload.key || null;
        createdSecret.value = payload.secret || '';
        secretWarning.value = payload.secret_warning || '';
        dialogVisible.value = false;
        secretVisible.value = true;
        await loadList();
      } else {
        const id = editingKey.value?.id;
        if (!id) return;
        await clientApi.updateApiKey(id, collectPayload());
        MessagePlugin.success('密钥已更新');
        dialogVisible.value = false;
        await loadList();
      }
    } catch (error: unknown) {
      MessagePlugin.error(getErrorMessage(error, dialogMode.value === 'create' ? '创建失败' : '更新失败'));
    } finally {
      submitting.value = false;
    }
  }

  async function toggleStatus(key: ApiKeyRecord) {
    const next = key.status === 'enabled' ? 'disabled' : 'enabled';
    try {
      await clientApi.setApiKeyStatus(key.id, next);
      MessagePlugin.success(next === 'enabled' ? '密钥已启用' : '密钥已停用');
      await loadList();
    } catch (error: unknown) {
      MessagePlugin.error(getErrorMessage(error, '操作失败'));
    }
  }

  async function removeKey(key: ApiKeyRecord) {
    try {
      await clientApi.deleteApiKey(key.id);
      MessagePlugin.success('密钥已删除');
      await loadList();
    } catch (error: unknown) {
      MessagePlugin.error(getErrorMessage(error, '删除失败'));
    }
  }

  function copySecret() {
    const text = createdSecret.value;
    if (!text) return;
    if (navigator.clipboard?.writeText) {
      navigator.clipboard.writeText(text).then(
        () => MessagePlugin.success('已复制到剪贴板'),
        () => MessagePlugin.error('复制失败，请手动复制'),
      );
    } else {
      MessagePlugin.info(text);
    }
  }

  async function openUsageLogs(key: ApiKeyRecord) {
    logKey.value = key;
    logVisible.value = true;
    logLoading.value = true;
    logs.value = [];
    try {
      const response = await clientApi.apiKeyUsageLogs(key.id);
      logs.value = Array.isArray(response.data?.list) ? response.data.list : [];
    } catch (error: unknown) {
      MessagePlugin.error(getErrorMessage(error, '调用日志加载失败'));
    } finally {
      logLoading.value = false;
    }
  }

  function closeSecret() {
    secretVisible.value = false;
    createdSecret.value = '';
    createdKey.value = null;
  }

  onMounted(() => {
    void loadList();
  });

  return {
    loading,
    list,
    listError,
    dialogVisible,
    dialogMode,
    editingKey,
    submitting,
    form,
    secretVisible,
    createdSecret,
    secretWarning,
    createdKey,
    logVisible,
    logLoading,
    logKey,
    logs,
    loadList,
    openCreateDialog,
    openEditDialog,
    submit,
    toggleStatus,
    removeKey,
    copySecret,
    openUsageLogs,
    closeSecret,
  };
}
