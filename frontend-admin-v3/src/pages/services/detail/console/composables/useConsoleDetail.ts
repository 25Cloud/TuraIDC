import { MessagePlugin } from 'tdesign-vue-next';
import { computed, reactive, ref, shallowRef, watch } from 'vue';
import { useRoute } from 'vue-router';

import { t } from '@/locales';

import type { ConsoleServiceDetail, ServiceConsoleCapabilities } from '../types';
import { createServiceConsoleApi } from './adminServiceApi';
import {
  DEFAULT_TAB,
  mergeConsoleDetail,
  normalizeConsoleDetail,
  resolveAvailableTabs,
  resolveErrorMessage,
} from './useConsoleCore';

export interface UseConsoleDetailOptions {
  userId: number | string;
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

export function useConsoleDetail(options: UseConsoleDetailOptions) {
  const route = useRoute();
  const clientApi = createServiceConsoleApi(options.userId);

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

  // 上游控制台能力（自定义区域 / NAT / 监控）。拉取失败时为 null，走静态兜底 tab。
  const consoleCapabilities = shallowRef<ServiceConsoleCapabilities | null>(null);
  const resolvedTabs = computed(() => resolveAvailableTabs(detail.value, consoleCapabilities.value));
  const availableTabs = computed(() => resolvedTabs.value.keys);
  const consoleAreaLabels = computed(() => resolvedTabs.value.areaLabels);

  // 能力动态下发后，若当前 tab 已被剔除则回到总览
  watch(
    () => availableTabs.value.join(','),
    (keys) => {
      if (!keys.split(',').includes(activeTab.value)) {
        activeTab.value = DEFAULT_TAB;
      }
    },
  );

  async function loadConsoleCapabilities() {
    const id = serviceId.value;
    if (!id) return;
    try {
      const res = await clientApi.serviceConsoleCapabilities(id);
      if (serviceId.value !== id) return;
      consoleCapabilities.value = (res.data || null) as ServiceConsoleCapabilities | null;
    } catch {
      // 能力接口不可用时静默回退到静态 tab 集合
      if (serviceId.value !== id) return;
      consoleCapabilities.value = null;
    }
  }
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
      const res = await clientApi.serviceDetail(serviceId.value);
      const payload = normalizeConsoleDetail(res.data || {});
      detail.value = payload;
      if (consumeSyncChangedMarker(payload)) {
        // 后台同步已产生新数据：本次可能命中旧缓存，重新拉取最新快照并提示
        if (!forceRefresh) {
          const fresh = await clientApi.serviceDetail(serviceId.value);
          detail.value = normalizeConsoleDetail(fresh.data || {});
        }
        MessagePlugin.info(t('services.console.infoUpdated'));
      }
    } catch (error: unknown) {
      MessagePlugin.error(resolveErrorMessage(error, t('services.console.loadInstanceFailed')));
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
        if (!silent) MessagePlugin.info(t('services.console.infoUpdated'));
      } else if (!silent) {
        MessagePlugin.success(t('services.console.statusRefreshed'));
      }
    } catch (error: unknown) {
      if (!silent) MessagePlugin.error(resolveErrorMessage(error, t('services.console.refreshInstanceFailed')));
    }
  }

  /**
   * 同步信息：实例详情必须刷新成功；电源状态读取失败只降级为提示，不阻断同步。
   *
   * 电源状态是辅助信息：实例关机、暂停或上游接口临时不可用时读不到，
   * 但到期时间、商品、IP 仍要同步，所以这里吞掉电源状态的失败。
   *
   * @returns 电源状态是否暂不可用
   */
  async function refreshHostStatus(): Promise<boolean> {
    let powerUnavailable = false;

    if (detail.value.actions?.module_status) {
      try {
        const res = await clientApi.serviceModuleStatus(serviceId.value, { type: 'host' });
        const payload = (res.data ?? {}) as { available?: boolean };
        powerUnavailable = payload.available === false;
      } catch {
        // 旧版后端或上游异常：电源状态读取失败不影响详情同步
        powerUnavailable = true;
      }
    }

    await loadDetailBase(true);

    return powerUnavailable;
  }

  async function bootstrap() {
    if (!serviceId.value) return;
    clearOperationStatus();
    activeTab.value = DEFAULT_TAB;
    consoleCapabilities.value = null;
    await loadDetailBase();
    void loadRemoteStatus(true);
    void fetchConnectionInfo();
    void loadConsoleCapabilities();
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
    consoleAreaLabels,
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
