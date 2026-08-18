import { DialogPlugin, MessagePlugin } from 'tdesign-vue-next';
import { reactive, ref } from 'vue';

import clientApi from '@/api/client';
import type {
  ConsoleSelectOption,
  FinanceLedgerRecord,
  FinanceLedgerSummary,
  MonitorChartRecord,
  MonitorRangePayload,
  NatForwardingRecord,
  PagedList,
  ServiceOperationLogRecord,
  ServiceOperationLogSummary,
} from '@/types/client';

import { resolveErrorMessage } from './useConsoleCore';

type MonitorRangePreset = '3h' | '24h' | '7d' | '30d';

function createEmptyLogsState() {
  return {
    loading: false,
    list: [] as ServiceOperationLogRecord[],
    total: 0,
    page: 1,
    page_size: 10,
    category: '',
    keyword: '',
    summary: {
      total: 0,
      today_total: 0,
      latest_created_at: '',
      service_name: '',
    } as ServiceOperationLogSummary,
  };
}

function createEmptyFinanceState() {
  return {
    loading: false,
    list: [] as FinanceLedgerRecord[],
    total: 0,
    error: '',
    page: 1,
    page_size: 10,
    summary: {
      total_in: '0.00',
      total_out: '0.00',
      refund_in: '0.00',
      total_count: 0,
    } as FinanceLedgerSummary,
  };
}

export interface UseConsoleTabsOptions {
  serviceId: { value: number };
}

export function useConsoleTabs(options: UseConsoleTabsOptions) {
  const { serviceId } = options;

  const monitorState = reactive({
    loading: false,
    range: '3h' as MonitorRangePreset,
    window: null as MonitorRangePayload | null,
    supported: true,
    message: '',
    error: '',
    charts: [] as MonitorChartRecord[],
  });

  const natState = reactive({
    loading: false,
    submitting: false,
    supported: true,
    message: '',
    error: '',
    can_create: false,
    protocols: [] as ConsoleSelectOption[],
    list: [] as NatForwardingRecord[],
  });
  const natVisible = ref(false);
  const natForm = reactive({ name: '', ext_port: '', int_port: '', protocol: '' });

  const logsState = reactive(createEmptyLogsState());
  const financeState = reactive(createEmptyFinanceState());
  const loadedTabs = reactive({ monitor: false, security: false, nat: false, logs: false, finance: false });

  function resetLazyTabs() {
    loadedTabs.monitor = false;
    loadedTabs.security = false;
    loadedTabs.nat = false;
    loadedTabs.logs = false;
    loadedTabs.finance = false;
  }

  async function loadMonitor(force = false) {
    monitorState.loading = true;
    monitorState.error = '';
    try {
      const params: Record<string, unknown> = { range: monitorState.range };
      if (force) params.fresh = 1;
      const res = await clientApi.serviceMonitorBatch(serviceId.value, params, { timeout: 12000 });
      const payload = res.data || {};
      monitorState.supported = payload.supported !== false;
      monitorState.message = String(payload.message || '');
      monitorState.window = payload.range || null;
      monitorState.charts = Array.isArray(payload.charts) ? payload.charts : [];
    } catch (error: unknown) {
      monitorState.error = resolveErrorMessage(error, '加载监控数据失败');
    } finally {
      monitorState.loading = false;
    }
  }

  async function loadNatForwardings() {
    natState.loading = true;
    natState.error = '';
    try {
      const res = await clientApi.serviceNatForwardings(serviceId.value);
      const payload = res.data || {};
      natState.supported = payload.supported !== false;
      natState.message = String(payload.message || '');
      natState.error = String(payload.error || '');
      natState.can_create = Boolean(payload.can_create);
      natState.protocols = Array.isArray(payload.protocols) ? payload.protocols : [];
      natState.list = Array.isArray(payload.list) ? payload.list : [];
    } catch (error: unknown) {
      natState.error = resolveErrorMessage(error, '加载 NAT 转发失败');
    } finally {
      natState.loading = false;
    }
  }

  function openNatForwardingDialog() {
    natForm.name = '';
    natForm.ext_port = '';
    natForm.int_port = '';
    natForm.protocol = String(natState.protocols[0]?.value || '');
    natVisible.value = true;
  }

  async function submitNatForwarding() {
    const name = natForm.name.trim();
    const externalPort = natForm.ext_port.trim();
    const internalPort = natForm.int_port.trim();
    const protocol = natForm.protocol.trim();

    if (!name || !internalPort || !protocol) {
      MessagePlugin.warning('请填写完整的端口转发信息');
      return;
    }

    if (!isValidPort(internalPort) || (externalPort !== '' && !isValidPort(externalPort))) {
      MessagePlugin.warning('端口范围应为 1 到 65535');
      return;
    }

    natState.submitting = true;
    try {
      const response = await clientApi.createNatForwarding(serviceId.value, {
        name,
        ext_port: externalPort || undefined,
        int_port: internalPort,
        protocol,
      });
      natVisible.value = false;
      const message = String((response.data as { message?: unknown } | undefined)?.message || '').trim();
      MessagePlugin.success(message || '端口转发创建成功');
      await loadNatForwardings();
    } catch (error: unknown) {
      MessagePlugin.error(resolveErrorMessage(error, '创建端口转发失败'));
    } finally {
      natState.submitting = false;
    }
  }

  function deleteNatForwarding(forwarding: NatForwardingRecord) {
    const forwardingId = Number(forwarding.id || 0);
    if (!forwardingId || forwarding.can_delete === false) return;

    const dialog = DialogPlugin.confirm({
      header: '删除端口转发',
      body: `确认删除端口转发“${String(forwarding.name || forwardingId)}”吗？`,
      theme: 'warning',
      confirmBtn: '确认删除',
      cancelBtn: '取消',
      onConfirm: async () => {
        dialog.setConfirmLoading(true);
        natState.submitting = true;
        try {
          const response = await clientApi.deleteNatForwarding(serviceId.value, forwardingId);
          const message = String((response.data as { message?: unknown } | undefined)?.message || '').trim();
          MessagePlugin.success(message || '端口转发删除成功');
          await loadNatForwardings();
          dialog.hide();
        } catch (error: unknown) {
          MessagePlugin.error(resolveErrorMessage(error, '删除端口转发失败'));
        } finally {
          natState.submitting = false;
          dialog.setConfirmLoading(false);
        }
      },
    });
  }

  async function loadLogs() {
    logsState.loading = true;
    try {
      const params: Record<string, unknown> = { page: logsState.page, page_size: logsState.page_size };
      if (logsState.category) params.category = logsState.category;
      if (logsState.keyword.trim()) params.keyword = logsState.keyword.trim();
      const res = await clientApi.serviceOperationLogs(serviceId.value, params);
      const payload: PagedList<ServiceOperationLogRecord> & { summary?: ServiceOperationLogSummary } = res.data || {
        list: [],
        total: 0,
      };
      logsState.list = Array.isArray(payload.list) ? payload.list : [];
      logsState.total = Number(payload.total || 0);
      logsState.summary = { ...logsState.summary, ...(payload.summary || {}) };
    } catch (error: unknown) {
      MessagePlugin.error(resolveErrorMessage(error, '加载操作日志失败'));
    } finally {
      logsState.loading = false;
    }
  }

  async function loadFinanceLogs() {
    financeState.loading = true;
    try {
      financeState.error = '';
      const params: Record<string, unknown> = {
        page: financeState.page,
        page_size: financeState.page_size,
        tab: 'invoices',
        service_id: serviceId.value,
      };
      const [listRes, summaryRes] = await Promise.all([
        clientApi.financeLedger(params),
        clientApi.financeLedgerSummary({ tab: 'invoices', service_id: serviceId.value }),
      ]);
      const listPayload: PagedList<FinanceLedgerRecord> = listRes.data || { list: [], total: 0 };
      const summaryPayload = summaryRes.data || {};
      financeState.list = Array.isArray(listPayload.list) ? listPayload.list : [];
      financeState.total = Number(listPayload.total || 0);
      financeState.summary = { ...financeState.summary, ...summaryPayload };
    } catch (error: unknown) {
      financeState.list = [];
      financeState.total = 0;
      financeState.error = resolveErrorMessage(error, '加载财务日志失败');
      MessagePlugin.error(financeState.error);
    } finally {
      financeState.loading = false;
    }
  }

  return {
    monitorState,
    natState,
    natVisible,
    natForm,
    logsState,
    financeState,
    loadedTabs,
    resetLazyTabs,
    loadMonitor,
    loadNatForwardings,
    openNatForwardingDialog,
    submitNatForwarding,
    deleteNatForwarding,
    loadLogs,
    loadFinanceLogs,
  };
}

function isValidPort(value: string): boolean {
  const port = Number(value);

  return Number.isInteger(port) && port >= 1 && port <= 65535;
}
