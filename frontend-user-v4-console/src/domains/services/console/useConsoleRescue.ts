import { DialogPlugin, MessagePlugin } from 'tdesign-vue-next';
import { reactive, ref } from 'vue';

import clientApi from '@/api/client';

import { resolveErrorMessage } from './useConsoleCore';

export interface UseConsoleRescueOptions {
  serviceId: { value: number };
  actionLoading: { value: boolean };
  setOperationStatus: (type: string, label: string) => void;
  loadRemoteStatus: (silent: boolean) => Promise<void>;
  clearStatusSyncTimer: () => void;
  scheduleStatusSync: (callback: () => void, delay: number) => void;
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
        header: '确认进入救援模式',
        body: '进入救援模式后，实例将重启并挂载救援系统，用于排查系统故障。确认继续吗？',
        theme: 'warning',
        confirmBtn: { content: '确认进入', theme: 'warning' },
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
      setOperationStatus('rescue', '进入救援模式中');
      rescueVisible.value = false;
      MessagePlugin.success(String(payload.message || '救援模式指令已提交'));
      clearStatusSyncTimer();
      scheduleStatusSync(() => loadRemoteStatus(true), 1500);
    } catch (error: unknown) {
      MessagePlugin.error(resolveErrorMessage(error, '进入救援模式失败'));
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
