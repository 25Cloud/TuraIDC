import { MessagePlugin } from 'tdesign-vue-next';
import { computed, reactive, ref, shallowRef, watch } from 'vue';
import { useRoute } from 'vue-router';

import clientApi from '@/api/client';
import { t } from '@/locales';
import type { ConsoleServiceDetail, ServiceConsoleCapabilities } from '@/types/client';

import {
  DEFAULT_TAB,
  mergeConsoleDetail,
  normalizeConsoleDetail,
  resolveAvailableTabs,
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

  // 上游控制台能力（自定义区域 / NAT / 监控）。拉取失败时为 null，走静态兜底 tab。
  const consoleCapabilities = shallowRef<ServiceConsoleCapabilities | null>(null);
  const resolvedTabs = computed(() => resolveAvailableTabs(detail.value, consoleCapabilities.value));
  const availableTabs = computed(() => resolvedTabs.value.keys);
  const consoleAreaLabels = computed(() => resolvedTabs.value.areaLabels);

  /**
   * 面板区域（魔方兼容 tab）是否首屏全量预热。
   *
   * 只有显式 false 才关闭：能力请求失败、字段缺失、后端读不到设置时都按开启处理，
   * 与后端 interpretPreloadSetting 对空值的口径一致。关掉后只预热第一个区域，
   * 其余按需加载，用来规避多个 iframe 同时回源上游对首屏带宽的占用。
   */
  const areaPreloadAll = computed(() => consoleCapabilities.value?.area_preload_all !== false);

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
      const res = await clientApi.serviceDetail(serviceId.value, forceRefresh ? { params: { refresh: 1 } } : undefined);
      const payload = normalizeConsoleDetail(res.data || {});
      detail.value = payload;
      if (consumeSyncChangedMarker(payload)) {
        // 后台同步已产生新数据：本次可能命中旧缓存，重新拉取最新快照并提示
        if (!forceRefresh) {
          const fresh = await clientApi.serviceDetail(serviceId.value);
          detail.value = normalizeConsoleDetail(fresh.data || {});
        }
        MessagePlugin.info(t('serviceConsole.detailUpdated'));
      }
    } catch (error: unknown) {
      MessagePlugin.error(resolveErrorMessage(error, t('serviceConsole.errors.detailLoadFailed')));
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
        if (!silent) MessagePlugin.info(t('serviceConsole.detailUpdated'));
      } else if (!silent) {
        MessagePlugin.success(t('serviceConsole.statusRefreshed'));
      }
    } catch (error: unknown) {
      if (!silent) MessagePlugin.error(resolveErrorMessage(error, t('serviceConsole.errors.statusRefreshFailed')));
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
    areaPreloadAll,
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
