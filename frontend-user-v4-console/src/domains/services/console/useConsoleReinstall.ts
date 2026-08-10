import { MessagePlugin } from 'tdesign-vue-next';
import { computed, reactive, ref } from 'vue';

import clientApi from '@/api/client';
import type { ConsoleServiceDetail, ServiceReinstallOption } from '@/types/client';

import { resolveErrorMessage } from './useConsoleCore';

export interface UseConsoleReinstallOptions {
  serviceId: { value: number };
  actionLoading: { value: boolean };
  setOperationStatus: (type: string, label: string) => void;
  loadRemoteStatus: (silent: boolean) => Promise<void>;
  clearStatusSyncTimer: () => void;
  scheduleStatusSync: (callback: () => void, delay: number) => void;
  normalizeDetail: (payload: Partial<ConsoleServiceDetail>) => ConsoleServiceDetail;
  mergeDetail: (patch: Partial<ConsoleServiceDetail>) => void;
}

interface ReinstallGroupOption {
  group_name: string;
  items: ServiceReinstallOption[];
}

export function useConsoleReinstall(options: UseConsoleReinstallOptions) {
  const { serviceId, actionLoading, setOperationStatus, loadRemoteStatus, clearStatusSyncTimer, scheduleStatusSync } =
    options;

  const reinstallVisible = ref(false);
  const reinstallState = reactive({
    loading: false,
    os: [] as ServiceReinstallOption[],
    os_group: '',
    os_id: '',
  });

  const reinstallGroupedOptions = computed<ReinstallGroupOption[]>(() => {
    const groups: Record<string, ReinstallGroupOption> = {};
    for (const item of reinstallState.os) {
      const groupName = String(item.group_name || '默认分组');
      if (!groups[groupName]) {
        groups[groupName] = { group_name: groupName, items: [] };
      }
      groups[groupName].items.push(item);
    }
    return Object.values(groups);
  });

  const currentReinstallOptions = computed<ServiceReinstallOption[]>(
    () => reinstallGroupedOptions.value.find((item) => item.group_name === reinstallState.os_group)?.items || [],
  );

  async function openReinstallDialog() {
    reinstallVisible.value = true;
    reinstallState.loading = true;
    reinstallState.os = [];
    reinstallState.os_group = '';
    reinstallState.os_id = '';
    try {
      const res = await clientApi.serviceReinstallOptions(serviceId.value);
      const payload = res.data || {};
      reinstallState.os = Array.isArray(payload.os) ? payload.os : [];
      const firstGroup = reinstallGroupedOptions.value[0];
      reinstallState.os_group = firstGroup?.group_name || '';
      reinstallState.os_id = String(firstGroup?.items?.[0]?.os_id || '');
    } catch (error: unknown) {
      MessagePlugin.error(resolveErrorMessage(error, '加载重装系统选项失败'));
    } finally {
      reinstallState.loading = false;
    }
  }

  function handleReinstallGroupChange(value: unknown) {
    reinstallState.os_group = String(value || '');
    const group = reinstallGroupedOptions.value.find((item) => item.group_name === reinstallState.os_group);
    reinstallState.os_id = String(group?.items?.[0]?.os_id || '');
  }

  async function submitReinstall() {
    if (!reinstallState.os_id) {
      MessagePlugin.warning('请选择系统版本');
      return;
    }
    actionLoading.value = true;
    try {
      const res = await clientApi.serviceReinstall(serviceId.value, { os_id: reinstallState.os_id });
      const payload = res.data || {};
      setOperationStatus('reinstall', '重装系统中');
      reinstallVisible.value = false;
      MessagePlugin.success(String(payload.message || '重装系统任务已提交'));
      clearStatusSyncTimer();
      scheduleStatusSync(() => loadRemoteStatus(true), 1500);
    } catch (error: unknown) {
      MessagePlugin.error(resolveErrorMessage(error, '重装系统失败'));
    } finally {
      actionLoading.value = false;
    }
  }

  return {
    reinstallVisible,
    reinstallState,
    reinstallGroupedOptions,
    currentReinstallOptions,
    openReinstallDialog,
    handleReinstallGroupChange,
    submitReinstall,
  };
}
