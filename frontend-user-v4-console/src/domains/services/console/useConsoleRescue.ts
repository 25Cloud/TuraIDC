import { DialogPlugin, MessagePlugin } from 'tdesign-vue-next';
import { reactive, ref } from 'vue';

import clientApi from '@/api/client';
import { t } from '@/locales';

import { resolveErrorMessage } from './useConsoleCore';

export interface UseConsoleRescueOptions {
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
        header: t('serviceConsole.rescue.confirmHeader'),
        body: t('serviceConsole.rescue.confirmBody'),
        theme: 'warning',
        confirmBtn: { content: t('serviceConsole.rescue.confirmBtn'), theme: 'warning' },
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
      setOperationStatus('rescue', t('serviceConsole.rescue.entering'));
      rescueVisible.value = false;
      MessagePlugin.success(String(payload.message || t('serviceConsole.rescue.submitted')));
      clearStatusSyncTimer();
      scheduleStatusSync(() => loadRemoteStatus(true), 1500);
    } catch (error: unknown) {
      MessagePlugin.error(resolveErrorMessage(error, t('serviceConsole.rescue.failed')));
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
