import { DialogPlugin, MessagePlugin } from 'tdesign-vue-next';
import { computed, reactive, ref } from 'vue';

import { t } from '@/locales';

import type { ConsoleServiceDetail, ServiceReinstallOption } from '../types';
import { createServiceConsoleApi } from './adminServiceApi';
import { resolveErrorMessage } from './useConsoleCore';

export interface UseConsoleReinstallOptions {
  userId: number | string;
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

  const clientApi = createServiceConsoleApi(options.userId);

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
      const groupName = String(item.group_name || t('services.console.defaultGroup'));
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
      MessagePlugin.error(resolveErrorMessage(error, t('services.console.loadReinstallOptionsFailed')));
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
      MessagePlugin.warning(t('services.console.selectOsVersion'));
      return;
    }

    const confirmed = await new Promise<boolean>((resolve) => {
      const dialog = DialogPlugin.confirm({
        header: t('services.console.reinstallConfirmTitle'),
        body: t('services.console.reinstallConfirmBody'),
        theme: 'danger',
        confirmBtn: { content: t('services.console.reinstallConfirmBtn'), theme: 'danger' },
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
      const res = await clientApi.serviceReinstall(serviceId.value, { os_id: reinstallState.os_id });
      const payload = res.data || {};
      setOperationStatus('reinstall', t('services.console.reinstalling'));
      reinstallVisible.value = false;
      MessagePlugin.success(String(payload.message || t('services.console.reinstallSubmitted')));
      clearStatusSyncTimer();
      scheduleStatusSync(() => loadRemoteStatus(true), 1500);
    } catch (error: unknown) {
      MessagePlugin.error(resolveErrorMessage(error, t('services.console.reinstallFailed')));
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
