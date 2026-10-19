import { DialogPlugin, MessagePlugin } from 'tdesign-vue-next';
import { reactive, ref } from 'vue';

import { t } from '@/locales';

import { createServiceConsoleApi } from './adminServiceApi';
import { resolveErrorMessage } from './useConsoleCore';

export interface UseConsoleRescueOptions {
  userId: number | string;
  serviceId: { value: number };
  actionLoading: { value: boolean };
  setOperationStatus: (_type: string, _label: string) => void;
  loadRemoteStatus: (_silent: boolean) => Promise<void>;
  clearStatusSyncTimer: () => void;
  scheduleStatusSync: (_callback: () => void, _delay: number) => void;
}

export function useConsoleRescue(options: UseConsoleRescueOptions) {
  const { serviceId, actionLoading, setOperationStatus, loadRemoteStatus, clearStatusSyncTimer, scheduleStatusSync } =
    options;

  const clientApi = createServiceConsoleApi(options.userId);

  const rescueVisible = ref(false);
  const rescueState = reactive({
    system: '1' as '1' | '2',
  });

  function openRescueDialog() {
    rescueState.system = '1';
    rescueVisible.value = true;
  }

  async function submitRescue() {
    const confirmed = await new Promise<boolean>((resolve) => {
      const dialog = DialogPlugin.confirm({
        header: t('services.console.rescueConfirmTitle'),
        body: t('services.console.rescueConfirmBody'),
        theme: 'warning',
        confirmBtn: { content: t('services.console.confirmEnter'), theme: 'warning' },
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
      const res = await clientApi.serviceRescue(serviceId.value, { system: rescueState.system });
      const payload = res.data || {};
      setOperationStatus('rescue', t('services.console.rescueInProgress'));
      rescueVisible.value = false;
      MessagePlugin.success(String(payload.message || t('services.console.rescueSubmitted')));
      clearStatusSyncTimer();
      scheduleStatusSync(() => loadRemoteStatus(true), 1500);
    } catch (error: unknown) {
      MessagePlugin.error(resolveErrorMessage(error, t('services.console.rescueFailed')));
    } finally {
      actionLoading.value = false;
    }
  }

  return {
    rescueVisible,
    rescueState,
    openRescueDialog,
    submitRescue,
  };
}
