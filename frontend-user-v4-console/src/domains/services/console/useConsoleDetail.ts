import { MessagePlugin } from 'tdesign-vue-next';
import { computed, reactive, ref, shallowRef } from 'vue';
import { useRoute } from 'vue-router';

import clientApi from '@/api/client';
import type { ConsoleServiceDetail } from '@/types/client';

import {
  CLOUD_TABS,
  DEFAULT_TAB,
  isNatConsole,
  mergeConsoleDetail,
  NAT_TABS,
  normalizeConsoleDetail,
  resolveErrorMessage,
} from './useConsoleCore';

export interface UseConsoleDetailOptions {
  //
}

export function useConsoleDetail(_options?: UseConsoleDetailOptions) {
  const route = useRoute();

  // detail 始终整对象替换（normalize/merge 返回新对象），用 shallowRef 省去深响应式代理开销
  const detail = shallowRef<ConsoleServiceDetail>(normalizeConsoleDetail());
  const detailLoading = ref(false);
  const statusSyncing = ref(false);
  const actionLoading = ref(false);
  const autoRenewLoading = ref(false);
  const activeTab = ref(DEFAULT_TAB);
  const operationStatus = reactive({ type: '', label: '' });

  let statusSyncTimer: number | null = null;

  const serviceId = computed(() => {
    const id = Number(route.params.id);
    return Number.isFinite(id) && id > 0 ? id : 0;
  });

  const availableTabs = computed(() => (isNatConsole(detail.value) ? NAT_TABS : CLOUD_TABS));
  const canManageConsole = computed(
    () => Boolean(detail.value.actions?.module_status) || Number(detail.value.upstream?.host_id || 0) > 0,
  );
  const canSyncStatus = computed(() => Boolean(detail.value.actions?.refresh) || canManageConsole.value);

  function clearStatusSyncTimer() {
    if (statusSyncTimer !== null) {
      window.clearTimeout(statusSyncTimer);
      statusSyncTimer = null;
    }
  }

  function scheduleStatusSync(callback: () => void, delay: number) {
    clearStatusSyncTimer();
    statusSyncTimer = window.setTimeout(callback, delay);
  }

  function setOperationStatus(type: string, label: string) {
    operationStatus.type = type;
    operationStatus.label = label;
  }

  function clearOperationStatus(type = '') {
    if (type && operationStatus.type !== type) return;
    operationStatus.type = '';
    operationStatus.label = '';
  }

  function mergeDetail(patch: Partial<ConsoleServiceDetail>) {
    detail.value = mergeConsoleDetail(detail.value, patch);
  }

  async function loadDetailBase() {
    if (!serviceId.value) return;
    detailLoading.value = true;
    try {
      const res = await clientApi.serviceDetail(serviceId.value);
      detail.value = normalizeConsoleDetail(res.data || {});
    } catch (error: unknown) {
      MessagePlugin.error(resolveErrorMessage(error, '加载实例信息失败'));
    } finally {
      detailLoading.value = false;
    }
  }

  async function fetchConnectionInfo() {
    if (!serviceId.value) return;
    try {
      const res = await clientApi.serviceBaseDetail(serviceId.value);
      detail.value = normalizeConsoleDetail(res.data || {});
    } catch {
      // 静默失败，不影响已展示的页面
    }
  }

  async function loadRemoteStatus(silent = false) {
    if (!serviceId.value) return;
    try {
      const res = await clientApi.serviceRemoteStatus(serviceId.value);
      detail.value = mergeConsoleDetail(detail.value, res.data || {});
      if (!silent) MessagePlugin.success('实例状态已刷新');
    } catch (error: unknown) {
      if (!silent) MessagePlugin.error(resolveErrorMessage(error, '刷新实例状态失败'));
    }
  }

  async function refreshHostStatus() {
    if (detail.value.actions?.module_status) {
      await clientApi.serviceModuleStatus(serviceId.value, { type: 'host' });
    }
    await loadRemoteStatus(true);
  }

  async function bootstrap() {
    if (!serviceId.value) return;
    clearOperationStatus();
    activeTab.value = DEFAULT_TAB;
    await loadDetailBase();
    void loadRemoteStatus(true);
    void fetchConnectionInfo();
  }

  return {
    route,
    detail,
    detailLoading,
    statusSyncing,
    actionLoading,
    autoRenewLoading,
    activeTab,
    operationStatus,
    serviceId,
    availableTabs,
    canManageConsole,
    canSyncStatus,
    clearStatusSyncTimer,
    scheduleStatusSync,
    setOperationStatus,
    clearOperationStatus,
    mergeDetail,
    loadDetailBase,
    loadRemoteStatus,
    refreshHostStatus,
    bootstrap,
    fetchConnectionInfo,
  };
}
