import { DialogPlugin, MessagePlugin } from 'tdesign-vue-next';
import { computed, reactive, ref } from 'vue';

import clientApi from '@/api/client';
import { t } from '@/locales';
import type { ConsoleServiceDetail, ServiceReinstallOption } from '@/types/client';

import { resolveErrorMessage } from './useConsoleCore';

export interface UseConsoleReinstallOptions {
  serviceId: { value: number };
  actionLoading: { value: boolean };
  setOperationStatus: (_type: string, _label: string) => void;
  loadRemoteStatus: (_silent: boolean) => Promise<void>;
  clearStatusSyncTimer: () => void;
  scheduleStatusSync: (_callback: () => void, _delay: number) => void;
  normalizeDetail: (_payload: Partial<ConsoleServiceDetail>) => ConsoleServiceDetail;
  mergeDetail: (_patch: Partial<ConsoleServiceDetail>) => void;
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
    request_timeout_ms: 0,
  });

  const reinstallGroupedOptions = computed<ReinstallGroupOption[]>(() => {
    const groups: Record<string, ReinstallGroupOption> = {};
    for (const item of reinstallState.os) {
      const groupName = String(item.group_name || t('serviceConsole.reinstall.defaultGroup'));
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
    reinstallState.request_timeout_ms = 0;
    try {
      const res = await clientApi.serviceReinstallOptions(serviceId.value);
      const payload = res.data || {};
      const timeout = Number(payload.request_timeout_ms);
      reinstallState.request_timeout_ms = Number.isFinite(timeout) && timeout >= 18000 ? Math.min(timeout, 120000) : 0;
      reinstallState.os = Array.isArray(payload.os) ? payload.os : [];
      const firstGroup = reinstallGroupedOptions.value[0];
      reinstallState.os_group = firstGroup?.group_name || '';
      reinstallState.os_id = String(firstGroup?.items?.[0]?.os_id || '');
    } catch (error: unknown) {
      MessagePlugin.error(resolveErrorMessage(error, t('serviceConsole.errors.reinstallOptionsFailed')));
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
      MessagePlugin.warning(t('serviceConsole.reinstall.selectOsVersion'));
      return;
    }

    const confirmed = await new Promise<boolean>((resolve) => {
      const dialog = DialogPlugin.confirm({
        header: t('serviceConsole.reinstall.confirmHeader'),
        body: t('serviceConsole.reinstall.confirmBody'),
        theme: 'danger',
        confirmBtn: { content: t('serviceConsole.reinstall.confirmBtn'), theme: 'danger' },
        onConfirm: () => {
          dialog.destroy();
          resolve(true);
        },
        onCancel: () => {
          dialog.destroy();
          resolve(false);
        },
        onClose: () => {
          dialog.destroy();
          resolve(false);
        },
      });
    });
    if (!confirmed) return;

    actionLoading.value = true;
    try {
      const res = await clientApi.serviceReinstall(
        serviceId.value,
        { os_id: reinstallState.os_id },
        reinstallState.request_timeout_ms ? { timeout: reinstallState.request_timeout_ms } : undefined,
      );
      const payload = res.data || {};
      setOperationStatus('reinstall', t('serviceConsole.reinstall.running'));
      reinstallVisible.value = false;
      MessagePlugin.success(String(payload.message || t('serviceConsole.reinstall.submitted')));
      clearStatusSyncTimer();
      scheduleStatusSync(() => loadRemoteStatus(true), 1500);
    } catch (error: unknown) {
      MessagePlugin.error(resolveErrorMessage(error, t('serviceConsole.reinstall.failed')));
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
