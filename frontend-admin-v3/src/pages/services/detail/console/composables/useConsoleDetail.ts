import { MessagePlugin } from 'tdesign-vue-next';
import { computed, onUnmounted, reactive, ref, shallowRef, watch } from 'vue';
import { useRoute } from 'vue-router';

import { t } from '@/locales';
import { isSlowResponseError } from '@/utils/request';

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

/**
 * 实例详情加载结果：
 * - ok：正常返回；
 * - changed：后台同步已产生新数据（响应带 _sync 变更标记）；
 * - timeout：上游响应过慢（强制刷新时请求已提交，后台仍在同步）；
 * - failed：请求真的失败。
 */
export type DetailLoadStatus = 'ok' | 'changed' | 'timeout' | 'failed';

/**
 * 「同步信息」的结果：详情刷新状态 + 电源状态是否暂不可用。
 */
export interface RefreshHostStatusResult {
  detailStatus: DetailLoadStatus;
  powerUnavailable: boolean;
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

  /**
   * 拉取实例详情。
   *
   * @param forceRefresh 是否强制回源上游（refresh=1）
   * @param silent 静默模式：轮询补齐时用，不切 loading 态、不弹错误提示
   */
  async function loadDetailBase(forceRefresh = false, silent = false): Promise<DetailLoadStatus> {
    if (!serviceId.value) return 'ok';
    if (!silent) detailLoading.value = true;
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
        if (!silent) MessagePlugin.info(t('services.console.infoUpdated'));
        return 'changed';
      }
      return 'ok';
    } catch (error: unknown) {
      // 强制刷新时上游回源过慢：请求已提交、后台仍在同步，不按失败报错，
      // 交给调用方给中性提示，避免误报「同步失败」
      if (forceRefresh && isSlowResponseError(error)) {
        return 'timeout';
      }
      if (!silent) MessagePlugin.error(resolveErrorMessage(error, t('services.console.loadInstanceFailed')));
      return 'failed';
    } finally {
      if (!silent) detailLoading.value = false;
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

  // 切换上游绑定后，新上游主机的 IP / 系统 / 连接信息要等后台同步任务回填，
  // 而队列默认每分钟消费一次；不轮询的话页面会一直空着，只能靠用户手动刷新。
  //
  // 轮询只拉「本地详情」：管理端的 remote-status 接口是强制回源的，
  // 拿它轮询等于每一拍都打一次上游；本地详情命中后端 30s 缓存、不回源，
  // 真正的上游拉取由后端派发的后台同步任务完成（带 10 秒节流，只拉一次）。
  let bindSyncPollTimer: number | null = null;
  let bindSyncPollAttempts = 0;

  const BIND_SYNC_POLL_INTERVAL_MS = 5000;
  const BIND_SYNC_POLL_MAX_ATTEMPTS = 24;

  function stopBindSyncPolling(): void {
    if (bindSyncPollTimer !== null) {
      window.clearTimeout(bindSyncPollTimer);
      bindSyncPollTimer = null;
    }
    bindSyncPollAttempts = 0;
  }

  function startBindSyncPolling(): void {
    stopBindSyncPolling();

    const targetServiceId = serviceId.value;
    if (!targetServiceId) return;
    bindSyncPollAttempts = 0;

    const tick = async () => {
      bindSyncPollAttempts += 1;
      const status = await loadDetailBase(false, true);

      // 换了实例就停：旧实例的轮询不该继续打扰新实例
      if (serviceId.value !== targetServiceId) {
        stopBindSyncPolling();
        return;
      }

      // 后台同步已回填：详情带 _sync 变更标记，或上游状态/系统已重新写回快照
      const upstream = detail.value.upstream;
      const snapshotFilled = String(upstream?.status || '').trim() !== '' || String(upstream?.os || '').trim() !== '';
      if (status === 'changed' || snapshotFilled) {
        stopBindSyncPolling();
        MessagePlugin.success(t('services.console.upstreamBindSynced'));
        return;
      }
      if (bindSyncPollAttempts >= BIND_SYNC_POLL_MAX_ATTEMPTS) {
        stopBindSyncPolling();
        MessagePlugin.info(t('services.console.syncStatusPending'));
        return;
      }
      bindSyncPollTimer = window.setTimeout(tick, BIND_SYNC_POLL_INTERVAL_MS);
    };

    bindSyncPollTimer = window.setTimeout(tick, 3000);
  }

  onUnmounted(() => {
    stopBindSyncPolling();
  });

  /**
   * 同步信息：实例详情必须刷新成功；电源状态读取失败只降级为提示，不阻断同步。
   *
   * 电源状态是辅助信息：实例关机、暂停或上游接口临时不可用时读不到，
   * 但到期时间、商品、IP 仍要同步，所以这里吞掉电源状态的失败。
   *
   * @returns 详情刷新状态与电源状态是否暂不可用
   */
  async function refreshHostStatus(): Promise<RefreshHostStatusResult> {
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

    const detailStatus = await loadDetailBase(true);

    return { detailStatus, powerUnavailable };
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
    startBindSyncPolling,
    stopBindSyncPolling,
    bootstrap,
    fetchConnectionInfo,
  };
}
