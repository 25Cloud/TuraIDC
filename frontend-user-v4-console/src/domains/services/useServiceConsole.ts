import { MessagePlugin } from 'tdesign-vue-next';
import { computed, onUnmounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';

import { formatMoney, resolveServiceStatusLabel, resolveTdesignStatusTheme } from '@/domains/services/useServiceCenter';

import { copyText, DEFAULT_TAB, findSpecValue, isNatConsole, normalizeConsoleDetail } from './console/useConsoleCore';
import { useConsoleDetail } from './console/useConsoleDetail';
import { useConsoleDialogs } from './console/useConsoleDialogs';
import { useConsoleAutoRenew, useConsolePower } from './console/useConsolePower';
import { useConsoleReinstall } from './console/useConsoleReinstall';
import { useConsoleRenew } from './console/useConsoleRenew';
import { useConsoleSecurity } from './console/useConsoleSecurity';
import { useConsoleTabs } from './console/useConsoleTabs';
import { useConsoleTrafficPackages } from './console/useConsoleTrafficPackages';
import { useConsoleVnc } from './console/useConsoleVnc';

export function useServiceConsole() {
  const router = useRouter();
  const showPassword = ref(true);

  // Core detail state
  const detailComposable = useConsoleDetail();
  const {
    detail,
    detailLoading,
    statusSyncing,
    actionLoading,
    autoRenewLoading,
    activeTab,
    operationStatus,
    serviceId,
    availableTabs,
    canManageConsole,
    canSyncStatus,
    clearStatusSyncTimer,
    scheduleStatusSync,
    setOperationStatus,
    clearOperationStatus,
    mergeDetail,
    loadRemoteStatus,
    refreshHostStatus,
    bootstrap,
  } = detailComposable;
  const route = detailComposable.route;

  // Power management
  const { handlePowerAction } = useConsolePower({
    serviceId,
    detail,
    actionLoading,
    setOperationStatus,
    clearOperationStatus,
    loadRemoteStatus,
    clearStatusSyncTimer,
    scheduleStatusSync,
  });

  // Auto-renew toggle
  const { handleToggleAutoRenew } = useConsoleAutoRenew({
    serviceId,
    detail,
    autoRenewLoading,
    mergeDetail,
  });

  // Renew dialog
  const {
    renewVisible,
    renewLoading,
    renewSubmitting,
    renewData,
    renewForm,
    renewAmount,
    renewCoupons,
    openRenewDialog,
    handleRenewCycleChange,
    handleRenewCouponChange,
    submitRenew,
  } = useConsoleRenew({ serviceId });

  const {
    trafficVisible,
    trafficLoading,
    trafficQuoting,
    trafficSubmitting,
    trafficData,
    trafficQuote,
    trafficForm,
    trafficPackages,
    selectedTrafficPackage,
    trafficPayableAmount,
    openTrafficPackageDialog,
    handleTrafficPackageChange,
    submitTrafficPackageOrder,
  } = useConsoleTrafficPackages({ serviceId });

  // Security groups
  const {
    securityState,
    activeSecurityGroup,
    groupVisible,
    groupForm,
    ruleVisible,
    ruleForm,
    isPortDisabled,
    isAllPortProtocol,
    onProtocolChange,
    loadSecurityGroups,
    loadSecurityGroupRules,
    selectSecurityGroup,
    openSecurityGroupDialog,
    openSecurityRuleDialog,
    submitSecurityGroup,
    applySecurityGroup,
    deleteSecurityGroup,
    submitSecurityRule,
    deleteSecurityRule,
  } = useConsoleSecurity({ serviceId });

  // Reinstall dialog
  const {
    reinstallVisible,
    reinstallState,
    reinstallGroupedOptions,
    currentReinstallOptions,
    openReinstallDialog,
    handleReinstallGroupChange,
    submitReinstall,
  } = useConsoleReinstall({
    serviceId,
    actionLoading,
    setOperationStatus,
    loadRemoteStatus,
    clearStatusSyncTimer,
    scheduleStatusSync,
    normalizeDetail: normalizeConsoleDetail,
    mergeDetail,
  });

  // VNC
  const { vncUrl, handleOpenVnc } = useConsoleVnc({
    serviceId,
    actionLoading,
    activeTab,
  });

  // Simple dialogs (name, remark, password)
  const {
    nameVisible,
    nameSubmitting,
    nameForm,
    openNameDialog,
    submitName,
    remarkVisible,
    remarkSubmitting,
    remarkForm,
    openRemarkDialog,
    submitRemark,
    passwordVisible,
    passwordForm,
    openPasswordDialog,
    generateStrongPassword,
    submitResetPassword,
  } = useConsoleDialogs({
    serviceId,
    detail,
    actionLoading,
    setOperationStatus,
    loadRemoteStatus,
    clearStatusSyncTimer,
    scheduleStatusSync,
    mergeDetail,
  });

  // Tab-specific data loaders
  const {
    monitorState,
    natState,
    logsState,
    financeState,
    loadedTabs,
    resetLazyTabs,
    loadMonitor,
    loadNatForwardings,
    loadLogs,
    loadFinanceLogs,
  } = useConsoleTabs({ serviceId });

  // Computed: sync status handler
  async function handleSyncStatus() {
    statusSyncing.value = true;
    try {
      await refreshHostStatus();
      MessagePlugin.success(detail.value.actions?.module_status ? '实例状态已同步' : '实例状态已刷新');
    } catch (error: unknown) {
      const runtimeError = error as { message?: string };
      MessagePlugin.error(String(runtimeError?.message || '').trim() || '同步实例状态失败');
    } finally {
      statusSyncing.value = false;
    }
  }

  // Computed: detail derivatives
  const serviceRegion = computed(
    () =>
      String(detail.value.machine_category?.label || '').trim() ||
      findSpecValue(detail, ['区域', '地区', '机房', 'region'], '--'),
  );
  const serviceOs = computed(
    () => String(detail.value.upstream?.os || '').trim() || findSpecValue(detail, ['操作系统', 'os'], '--'),
  );
  const primaryConnectionLabel = computed(() => (isNatConsole(detail.value) ? '远程地址' : '公网 IP'));
  const publicIpValues = computed(() => {
    const connection = detail.value.connection || {};

    return normalizeConnectionValues([
      connection.dedicated_ip,
      detail.value.upstream?.dedicated_ip,
      connection.assigned_ips,
    ]);
  });
  const primaryConnectionValues = computed(() => {
    const connection = detail.value.connection || {};

    if (isNatConsole(detail.value)) {
      return normalizeConnectionValues([connection.nat_remote_address || connection.nat_remote_host]);
    }

    return publicIpValues.value;
  });
  const primaryConnectionText = computed(() =>
    primaryConnectionValues.value.length ? primaryConnectionValues.value.join(' / ') : '--',
  );
  const connectionEndpointText = computed(
    () => String(detail.value.connection?.hostname || detail.value.domain || '').trim() || '--',
  );
  const connectionPortText = computed(() => {
    const port = Number(detail.value.connection?.nat_remote_port || detail.value.connection?.port || 0);
    return Number.isFinite(port) && port > 0 ? String(port) : '--';
  });
  const instanceStatusText = computed(() => {
    const operationLabel = String(operationStatus.label || '').trim();
    if (operationLabel) return operationLabel;
    const runtimeLabel = String(detail.value.runtime?.power_label || '').trim();
    if (runtimeLabel) return runtimeLabel;
    const runtimeDescription = String(detail.value.runtime?.description || '').trim();
    if (runtimeDescription) return runtimeDescription;
    return resolveServiceStatusLabel(detail.value.status);
  });
  const instanceStatusTheme = computed(() => {
    const text = instanceStatusText.value;
    if (/运行中|运行|正常|已开通|开机|成功|完成/.test(text)) return 'success';
    if (/创建中|开机中|关机中|重启中|启动中|重置密码中|重装系统中|处理中|同步中|执行中/.test(text)) return 'warning';
    if (/失败|错误|异常|超时|欠费|锁定/.test(text)) return 'danger';
    if (/已关机|关机|已停止|停止|已暂停|暂停|未开通|待开通/.test(text)) return 'default';
    return resolveTdesignStatusTheme(detail.value);
  });
  const serviceIpCount = computed(() => {
    if (publicIpValues.value.length) return `${publicIpValues.value.length} 个`;
    return findSpecValue(detail, ['IP数量', 'IP 数量', 'ip'], primaryConnectionText.value !== '--' ? '1 个' : '--');
  });
  const bandwidthText = computed(() => findSpecValue(detail, ['带宽', '宽带', 'bandwidth'], '--'));
  const renewPriceText = computed(() => `¥${formatMoney(detail.value.amount)}`);
  const autoRenewLabel = computed(() => (Number(detail.value.auto_renew) === 1 ? '已开启' : '未开启'));
  const resolvedPassword = computed(() => {
    const password = String(detail.value.connection?.password || '').trim();

    if (password !== '') {
      return showPassword.value ? password : '••••••••';
    }

    return detail.value.connection?.has_password ? '已设置' : '--';
  });

  // Copy helper with toast
  async function copyTextWithToast(value: unknown) {
    await copyText(value, {
      successMsg: '内容已复制',
      errorMsg: '当前浏览器不支持自动复制，请手动复制',
    });
  }

  // Route watchers
  watch(
    () => route.params.id,
    () => {
      resetLazyTabs();
      void bootstrap();
    },
    { immediate: true },
  );

  watch(activeTab, async (tab) => {
    if (!availableTabs.value.includes(tab)) {
      activeTab.value = DEFAULT_TAB;
      return;
    }
    if (tab === 'monitor' && !loadedTabs.monitor) {
      loadedTabs.monitor = true;
      await loadMonitor();
    }
    if (tab === 'security' && !loadedTabs.security) {
      loadedTabs.security = true;
      await loadSecurityGroups();
    }
    if (tab === 'nat' && !loadedTabs.nat) {
      loadedTabs.nat = true;
      await loadNatForwardings();
    }
    if (tab === 'logs' && !loadedTabs.logs) {
      loadedTabs.logs = true;
      await loadLogs();
    }
    if (tab === 'finance' && !loadedTabs.finance) {
      loadedTabs.finance = true;
      await loadFinanceLogs();
    }
  });

  onUnmounted(() => {
    clearStatusSyncTimer();
  });

  return {
    // State refs
    detail,
    detailLoading,
    statusSyncing,
    actionLoading,
    autoRenewLoading,
    showPassword,
    activeTab,
    vncUrl,
    renewVisible,
    renewLoading,
    renewSubmitting,
    renewData,
    renewForm,
    trafficVisible,
    trafficLoading,
    trafficQuoting,
    trafficSubmitting,
    trafficData,
    trafficQuote,
    trafficForm,
    nameVisible,
    nameSubmitting,
    nameForm,
    remarkVisible,
    remarkSubmitting,
    remarkForm,
    passwordVisible,
    passwordForm,
    reinstallVisible,
    reinstallState,
    monitorState,
    securityState,
    activeSecurityGroup,
    groupVisible,
    groupForm,
    ruleVisible,
    ruleForm,
    isPortDisabled,
    isAllPortProtocol,
    onProtocolChange,
    natState,
    logsState,
    financeState,

    // Computed
    serviceId,
    availableTabs,
    canManageConsole,
    canSyncStatus,
    serviceRegion,
    serviceOs,
    primaryConnectionLabel,
    primaryConnectionValues,
    primaryConnectionText,
    connectionEndpointText,
    connectionPortText,
    instanceStatusText,
    instanceStatusTheme,
    serviceIpCount,
    bandwidthText,
    renewPriceText,
    autoRenewLabel,
    resolvedPassword,
    renewAmount,
    renewCoupons,
    trafficPackages,
    selectedTrafficPackage,
    trafficPayableAmount,
    reinstallGroupedOptions,
    currentReinstallOptions,

    // Helper functions
    findSpecValue: (aliases: string[], fallback = '--') => findSpecValue(detail, aliases, fallback),
    resolveServiceStatusLabel,
    resolveTdesignStatusTheme,
    formatMoney,
    router,
    copyText: copyTextWithToast,

    // Action handlers
    handleSyncStatus,
    handlePowerAction,
    openRenewDialog,
    handleRenewCycleChange,
    handleRenewCouponChange,
    submitRenew,
    openTrafficPackageDialog,
    handleTrafficPackageChange,
    submitTrafficPackageOrder,
    openNameDialog,
    submitName,
    openRemarkDialog,
    submitRemark,
    openPasswordDialog,
    generateStrongPassword,
    submitResetPassword,
    openReinstallDialog,
    handleReinstallGroupChange,
    submitReinstall,
    handleToggleAutoRenew,
    loadMonitor,
    loadSecurityGroups,
    loadSecurityGroupRules,
    selectSecurityGroup,
    openSecurityGroupDialog,
    openSecurityRuleDialog,
    submitSecurityGroup,
    applySecurityGroup,
    deleteSecurityGroup,
    submitSecurityRule,
    deleteSecurityRule,
    loadNatForwardings,
    loadLogs,
    loadFinanceLogs,
    handleOpenVnc,
  };
}

// Re-export utilities for external use
export { mergeConsoleDetail, normalizeConsoleDetail } from './console/useConsoleCore';

function normalizeConnectionValues(values: unknown[]): string[] {
  const normalized: string[] = [];

  for (const value of values) {
    const items = Array.isArray(value) ? value : [value];
    for (const item of items) {
      const candidates = String(item || '').split(/[\s,，;；、]+/);
      for (const candidate of candidates) {
        const text = candidate.trim();
        if (text !== '' && text !== '--' && !normalized.includes(text)) {
          normalized.push(text);
        }
      }
    }
  }

  return normalized;
}
