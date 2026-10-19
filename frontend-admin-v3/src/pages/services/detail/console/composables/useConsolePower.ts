import type { SwitchValue } from 'tdesign-vue-next';
import { DialogPlugin, MessagePlugin } from 'tdesign-vue-next';

import { t } from '@/locales';

import type { ConsoleRuntimeInfo, ConsoleServiceDetail } from '../types';
import { createServiceConsoleApi } from './adminServiceApi';
import { mergeConsoleDetail, normalizeConsoleDetail, resolveErrorMessage } from './useConsoleCore';

export const POWER_LABELS: Record<string, string> = {
  on: t('services.console.powerOn'),
  off: t('services.console.powerOff'),
  reboot: t('services.console.powerReboot'),
  hard_off: t('services.console.powerHardOff'),
  hard_reboot: t('services.console.powerHardReboot'),
};

export const OPTIMISTIC_POWER_DETAIL: Record<string, { runtime: ConsoleRuntimeInfo }> = {
  on: {
    runtime: {
      power_state: 'starting',
      power_label: t('serviceConsole.power.starting'),
      description: t('serviceConsole.power.starting'),
    },
  },
  off: {
    runtime: {
      power_state: 'stopping',
      power_label: t('serviceConsole.power.stopping'),
      description: t('serviceConsole.power.stopping'),
    },
  },
  hard_off: {
    runtime: {
      power_state: 'stopping',
      power_label: t('serviceConsole.power.stopping'),
      description: t('serviceConsole.power.stopping'),
    },
  },
  reboot: {
    runtime: {
      power_state: 'rebooting',
      power_label: t('serviceConsole.power.rebooting'),
      description: t('serviceConsole.power.rebooting'),
    },
  },
  hard_reboot: {
    runtime: {
      power_state: 'rebooting',
      power_label: t('serviceConsole.power.rebooting'),
      description: t('serviceConsole.power.rebooting'),
    },
  },
};

export interface UseConsolePowerOptions {
  userId: number | string;
  serviceId: { value: number };
  detail: { value: ConsoleServiceDetail };
  actionLoading: { value: boolean };
  setOperationStatus: (_type: string, _label: string) => void;
  clearOperationStatus: (_type?: string) => void;
  loadRemoteStatus: (_silent: boolean) => Promise<void>;
  clearStatusSyncTimer: () => void;
  scheduleStatusSync: (_callback: () => void, _delay: number) => void;
}

export function useConsolePower(options: UseConsolePowerOptions) {
  const {
    userId,
    serviceId,
    detail,
    actionLoading,
    setOperationStatus,
    clearOperationStatus,
    loadRemoteStatus,
    clearStatusSyncTimer,
    scheduleStatusSync,
  } = options;

  const clientApi = createServiceConsoleApi(userId);

  function handlePowerAction(action: string) {
    const label = POWER_LABELS[action] || action;
    const dialog = DialogPlugin.confirm({
      header: t('services.console.powerConfirmTitle', { label }),
      body: t('services.console.powerConfirmBody', {
        name: detail.value.name || `#${serviceId.value}`,
        label,
      }),
      confirmBtn: t('services.console.powerConfirmBtn', { label }),
      cancelBtn: t('common.cancel'),
      theme: ['hard_off', 'hard_reboot'].includes(action) ? 'danger' : 'warning',
      async onConfirm() {
        dialog.setConfirmLoading(true);
        const previousDetail = normalizeConsoleDetail(detail.value);
        const optimisticPatch = OPTIMISTIC_POWER_DETAIL[action];
        if (optimisticPatch) {
          detail.value = mergeConsoleDetail(detail.value, optimisticPatch);
          setOperationStatus(
            'power',
            String(optimisticPatch.runtime.power_label || t('services.console.powerInProgress', { label })),
          );
        } else {
          setOperationStatus('power', t('services.console.powerInProgress', { label }));
        }
        actionLoading.value = true;
        try {
          const res = await clientApi.servicePower(serviceId.value, { action });
          const payload = res.data || {};
          MessagePlugin.success(payload.message || t('services.console.powerDispatched', { label }));
          dialog.hide();
          clearStatusSyncTimer();
          scheduleStatusSync(() => loadRemoteStatus(true), 1500);
        } catch (error: unknown) {
          detail.value = previousDetail;
          clearOperationStatus('power');
          MessagePlugin.error(resolveErrorMessage(error, t('services.console.powerFailed', { label })));
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
  userId: number | string;
  serviceId: { value: number };
  detail: { value: ConsoleServiceDetail };
  autoRenewLoading: { value: boolean };
  mergeDetail: (_patch: Partial<ConsoleServiceDetail>) => void;
}

export function useConsoleAutoRenew(options: UseConsoleAutoRenewOptions) {
  const { userId, serviceId, autoRenewLoading, mergeDetail } = options;

  const clientApi = createServiceConsoleApi(userId);

  async function handleToggleAutoRenew(value: SwitchValue) {
    const enabled = Boolean(value);
    autoRenewLoading.value = true;
    try {
      await clientApi.updateAutoRenew(serviceId.value, { auto_renew: enabled ? 1 : 0 });
      mergeDetail({ auto_renew: enabled ? 1 : 0 });
      MessagePlugin.success(enabled ? t('services.console.autoRenewEnabled') : t('services.console.autoRenewDisabled'));
    } catch (error: unknown) {
      MessagePlugin.error(resolveErrorMessage(error, t('services.console.autoRenewUpdateFailed')));
    } finally {
      autoRenewLoading.value = false;
    }
  }

  return {
    handleToggleAutoRenew,
  };
}
