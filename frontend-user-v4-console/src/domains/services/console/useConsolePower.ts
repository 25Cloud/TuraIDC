import { DialogPlugin, MessagePlugin } from 'tdesign-vue-next';

import clientApi from '@/api/client';
import type { ConsoleRuntimeInfo, ConsoleServiceDetail } from '@/types/client';

import { mergeConsoleDetail, normalizeConsoleDetail, resolveErrorMessage } from './useConsoleCore';

export const POWER_LABELS: Record<string, string> = {
  on: '开机',
  off: '关机',
  reboot: '重启',
  hard_off: '强制关机',
  hard_reboot: '强制重启',
};

export const OPTIMISTIC_POWER_DETAIL: Record<string, { runtime: ConsoleRuntimeInfo }> = {
  on: { runtime: { power_state: 'starting', power_label: '开机中', description: '开机中' } },
  off: { runtime: { power_state: 'stopping', power_label: '关机中', description: '关机中' } },
  hard_off: { runtime: { power_state: 'stopping', power_label: '关机中', description: '关机中' } },
  reboot: { runtime: { power_state: 'rebooting', power_label: '重启中', description: '重启中' } },
  hard_reboot: { runtime: { power_state: 'rebooting', power_label: '重启中', description: '重启中' } },
};

export interface UseConsolePowerOptions {
  serviceId: { value: number };
  detail: { value: ConsoleServiceDetail };
  actionLoading: { value: boolean };
  setOperationStatus: (type: string, label: string) => void;
  clearOperationStatus: (type?: string) => void;
  loadRemoteStatus: (silent: boolean) => Promise<void>;
  clearStatusSyncTimer: () => void;
  scheduleStatusSync: (callback: () => void, delay: number) => void;
}

export function useConsolePower(options: UseConsolePowerOptions) {
  const {
    serviceId,
    detail,
    actionLoading,
    setOperationStatus,
    clearOperationStatus,
    loadRemoteStatus,
    clearStatusSyncTimer,
    scheduleStatusSync,
  } = options;

  function handlePowerAction(action: string) {
    const label = POWER_LABELS[action] || action;
    const dialog = DialogPlugin.confirm({
      header: `${label}确认`,
      body: `确认对实例 ${detail.value.name || `#${serviceId.value}`} 执行“${label}”操作吗？`,
      confirmBtn: `确认${label}`,
      cancelBtn: '取消',
      theme: ['hard_off', 'hard_reboot'].includes(action) ? 'danger' : 'warning',
      async onConfirm() {
        dialog.setConfirmLoading(true);
        const previousDetail = normalizeConsoleDetail(detail.value);
        const optimisticPatch = OPTIMISTIC_POWER_DETAIL[action];
        if (optimisticPatch) {
          detail.value = mergeConsoleDetail(detail.value, optimisticPatch);
          setOperationStatus('power', String(optimisticPatch.runtime.power_label || `${label}中`));
        } else {
          setOperationStatus('power', `${label}中`);
        }
        actionLoading.value = true;
        try {
          const res = await clientApi.servicePower(serviceId.value, { action });
          const payload = res.data || {};
          MessagePlugin.success(payload.message || `${label}指令已下发`);
          dialog.hide();
          clearStatusSyncTimer();
          scheduleStatusSync(() => loadRemoteStatus(true), 1500);
        } catch (error: unknown) {
          detail.value = previousDetail;
          clearOperationStatus('power');
          MessagePlugin.error(resolveErrorMessage(error, `${label}失败`));
        } finally {
          actionLoading.value = false;
          dialog.setConfirmLoading(false);
        }
      },
    });
  }

  return {
    handlePowerAction,
    POWER_LABELS,
    OPTIMISTIC_POWER_DETAIL,
  };
}

export interface UseConsoleAutoRenewOptions {
  serviceId: { value: number };
  detail: { value: ConsoleServiceDetail };
  autoRenewLoading: { value: boolean };
  mergeDetail: (patch: Partial<ConsoleServiceDetail>) => void;
}

export function useConsoleAutoRenew(options: UseConsoleAutoRenewOptions) {
  const { serviceId, autoRenewLoading, mergeDetail } = options;

  async function handleToggleAutoRenew(value: boolean) {
    autoRenewLoading.value = true;
    try {
      await clientApi.updateAutoRenew(serviceId.value, { auto_renew: value ? 1 : 0 });
      mergeDetail({ auto_renew: value ? 1 : 0 });
      MessagePlugin.success(`自动续费已${value ? '开启' : '关闭'}`);
    } catch (error: unknown) {
      MessagePlugin.error(resolveErrorMessage(error, '自动续费更新失败'));
    } finally {
      autoRenewLoading.value = false;
    }
  }

  return {
    handleToggleAutoRenew,
  };
}
