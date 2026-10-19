import type { SwitchValue } from 'tdesign-vue-next';
import { DialogPlugin, MessagePlugin } from 'tdesign-vue-next';

import clientApi from '@/api/client';
import { t } from '@/locales';
import type { ConsoleRuntimeInfo, ConsoleServiceDetail } from '@/types/client';

import { mergeConsoleDetail, normalizeConsoleDetail, resolveErrorMessage } from './useConsoleCore';

export const POWER_LABEL_KEYS: Record<string, string> = {
  on: 'serviceConsole.power.on',
  off: 'serviceConsole.power.off',
  reboot: 'serviceConsole.power.reboot',
  hard_off: 'serviceConsole.power.hardOff',
  hard_reboot: 'serviceConsole.power.hardReboot',
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
    const label = POWER_LABEL_KEYS[action] ? t(POWER_LABEL_KEYS[action]) : action;
    const dialog = DialogPlugin.confirm({
      header: t('serviceConsole.power.confirmHeader', { label }),
      body: t('serviceConsole.power.confirmBody', {
        name: detail.value.name || `#${serviceId.value}`,
        label,
      }),
      confirmBtn: t('serviceConsole.power.confirmBtn', { label }),
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
            String(optimisticPatch.runtime.power_label || t('serviceConsole.power.operating', { label })),
          );
        } else {
          setOperationStatus('power', t('serviceConsole.power.operating', { label }));
        }
        actionLoading.value = true;
        try {
          const res = await clientApi.servicePower(serviceId.value, { action });
          const payload = res.data || {};
          MessagePlugin.success(payload.message || t('serviceConsole.power.dispatched', { label }));
          dialog.hide();
          clearStatusSyncTimer();
          scheduleStatusSync(() => loadRemoteStatus(true), 1500);
        } catch (error: unknown) {
          detail.value = previousDetail;
          clearOperationStatus('power');
          MessagePlugin.error(resolveErrorMessage(error, t('serviceConsole.power.failed', { label })));
        } finally {
          actionLoading.value = false;
          dialog.setConfirmLoading(false);
        }
      },
    });
  }

  return {
    handlePowerAction,
    POWER_LABEL_KEYS,
    OPTIMISTIC_POWER_DETAIL,
  };
}

export interface UseConsoleAutoRenewOptions {
  serviceId: { value: number };
  detail: { value: ConsoleServiceDetail };
  autoRenewLoading: { value: boolean };
  mergeDetail: (_patch: Partial<ConsoleServiceDetail>) => void;
}

export function useConsoleAutoRenew(options: UseConsoleAutoRenewOptions) {
  const { serviceId, autoRenewLoading, mergeDetail } = options;

  async function handleToggleAutoRenew(value: SwitchValue) {
    const enabled = Boolean(value);
    autoRenewLoading.value = true;
    try {
      await clientApi.updateAutoRenew(serviceId.value, { auto_renew: enabled ? 1 : 0 });
      mergeDetail({ auto_renew: enabled ? 1 : 0 });
      MessagePlugin.success(
        enabled ? t('serviceConsole.power.autoRenewEnabled') : t('serviceConsole.power.autoRenewDisabled'),
      );
    } catch (error: unknown) {
      MessagePlugin.error(resolveErrorMessage(error, t('serviceConsole.power.autoRenewFailed')));
    } finally {
      autoRenewLoading.value = false;
    }
  }

  return {
    handleToggleAutoRenew,
  };
}
