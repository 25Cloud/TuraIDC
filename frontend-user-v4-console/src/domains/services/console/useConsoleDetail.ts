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

// 已提示过的后台同步变更标记时间戳，避免同一标记重复弹提示
let lastNotifiedChangedAt = '';

function consumeSyncChangedMarker(payload: Partial<ConsoleServiceDetail>): boolean {
  const marker = payload?._sync;
  if (!marker?.changed || !marker.changed_at) return false;
  if (marker.changed_at === lastNotifiedChangedAt) return false;
  lastNotifiedChangedAt = marker.changed_at;
  return true;
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

  async function loadDetailBase(forceRefresh = false) {
    if (!serviceId.value) return;
    detailLoading.value = true;
    try {
      const res = await clientApi.serviceDetail(serviceId.value, forceRefresh ? { params: { refresh: 1 } } : undefined);
      const payload = normalizeConsoleDetail(res.data || {});
      detail.value = payload;
      if (consumeSyncChangedMarker(payload)) {
        // 后台同步已产生新数据：本次可能命中旧缓存，重新拉取最新快照并提示
        if (!forceRefresh) {
          const fresh = await clientApi.serviceDetail(serviceId.value);
          detail.value = normalizeConsoleDetail(fresh.data || {});
        }
        MessagePlugin.info('实例信息已更新');
      }
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
      if (consumeSyncChangedMarker(res.data || {})) {
        // 后台有信息变动：自动重拉详情更新页面
        void loadDetailBase();
        if (!silent) MessagePlugin.info('实例信息已更新');
      } else if (!silent) {
        MessagePlugin.success('实例状态已刷新');
      }
    } catch (error: unknown) {
      if (!silent) MessagePlugin.error(resolveErrorMessage(error, '刷新实例状态失败'));
    }
  }

  async function refreshHostStatus() {
    if (detail.value.actions?.module_status) {
      await clientApi.serviceModuleStatus(serviceId.value, { type: 'host' });
    }
    await loadDetailBase(true);
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
