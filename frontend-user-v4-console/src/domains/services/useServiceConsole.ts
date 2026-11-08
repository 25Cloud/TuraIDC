import { MessagePlugin } from 'tdesign-vue-next';
import { computed, onUnmounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';

import { formatMoney, resolveServiceStatusLabel, resolveTdesignStatusTheme } from '@/domains/services/useServiceCenter';
import { t } from '@/locales';

import { copyText, DEFAULT_TAB, findSpecValue, isNatConsole, normalizeConsoleDetail } from './console/useConsoleCore';
import { useConsoleDetail } from './console/useConsoleDetail';
import { useConsoleDialogs } from './console/useConsoleDialogs';
import { useConsoleAutoRenew, useConsolePower } from './console/useConsolePower';
import { useConsoleReinstall } from './console/useConsoleReinstall';
import { useConsoleRenew } from './console/useConsoleRenew';
import { useConsoleRescue } from './console/useConsoleRescue';
import { useConsoleSecurity } from './console/useConsoleSecurity';
import { useConsoleTabs } from './console/useConsoleTabs';
import { useConsoleTrafficPackages } from './console/useConsoleTrafficPackages';
import { useConsoleUpgrades } from './console/useConsoleUpgrades';
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
    consoleAreaLabels,
    areaPreloadAll,
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
    renewOriginalAmount,
    renewAgentDiscountRate,
    renewAgentGroupName,
    renewHasAgentDiscount,
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

  // 配置项升级（补差价）
  const {
    upgradeVisible,
    upgradeLoading,
    upgradeQuoting,
    upgradeSubmitting,
    upgradeError,
    upgradeData,
    upgradeQuote,
    upgradeOptions,
    upgradeForm,
    upgradeQuoteKind,
    upgradeQuoteAmount,
    upgradePayableAmount,
    upgradeOptionName,
    upgradeCycleOptions,
    upgradeEmptyText,
    currentConfigRows,
    selectedUpgradeOption,
    canSubmitUpgrade,
    upgradeSubmitBlocked,
    openUpgradeDialog,
    loadUpgradePreview,
    quoteUpgrade,
    handleUpgradeOptionChange,
    handleUpgradeCycleChange,
    reapplyUpgradePromoCode,
    submitUpgradeOrder,
  } = useConsoleUpgrades({ serviceId, detail });

  // Security groups
  const {
    securityState,
    activeSecurityGroup,
    resetSecurityState,
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

  // Rescue mode dialog
  const { rescueVisible, rescueState, openRescueDialog, submitRescue } = useConsoleRescue({
    serviceId,
    actionLoading,
    setOperationStatus,
    loadRemoteStatus,
    clearStatusSyncTimer,
    scheduleStatusSync,
  });

  // VNC
  const { vncUrl, externalVncUrl, handleOpenVnc } = useConsoleVnc({
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
    natVisible,
    natForm,
    openNatForwardingDialog,
    submitNatForwarding,
    deleteNatForwarding,
    loadLogs,
    loadFinanceLogs,
  } = useConsoleTabs({ serviceId });

  // Computed: sync status handler
  async function handleSyncStatus() {
    statusSyncing.value = true;
    try {
      const powerUnavailable = await refreshHostStatus();
      if (powerUnavailable) {
        MessagePlugin.warning(t('serviceConsole.statusSyncedPartial'));
      } else {
        MessagePlugin.success(
          detail.value.actions?.module_status ? t('serviceConsole.statusSynced') : t('serviceConsole.statusRefreshed'),
        );
      }
    } catch (error: unknown) {
      const runtimeError = error as { message?: string };
      MessagePlugin.error(String(runtimeError?.message || '').trim() || t('serviceConsole.statusSyncFailed'));
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
  /**
   * 操作系统名。
   *
   * `upstream.os` 是上游的系统 **ID**（真机上是 "97"），真实系统名在后端 `buildSpecs()`
   * 产出的 `specs[].key === 'os'` 里（`ServiceTransformService::buildSpecs()`，value 形如
   * "Debian-12.1-x64"）。所以优先按 key 精确取，取不到再退回 `upstream.os`。
   */
  const serviceOs = computed(() => {
    const specs = Array.isArray(detail.value.specs) ? detail.value.specs : [];
    const osItem = specs.find(
      (item) =>
        String(item?.key ?? '')
          .trim()
          .toLowerCase() === 'os',
    );
    const osSpecValue = String(osItem?.value ?? '').trim();
    if (osSpecValue !== '') return osSpecValue;

    const byLabel = findSpecValue(detail, ['操作系统', 'os'], '--');

    return byLabel !== '--' ? byLabel : String(detail.value.upstream?.os || '').trim() || '--';
  });
  const primaryConnectionLabel = computed(() =>
    isNatConsole(detail.value) ? t('serviceConsole.remoteAddress') : t('serviceConsole.publicIp'),
  );
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
    if (Number.isFinite(port) && port > 0) return String(port);

    // 上游 host.port 为空时后端下发 0（真机 service 3993 就是这种情形）：只要有登录用户名，
    // 说明这是可 SSH 登录的机器，按默认端口 22 展示；后端同步侧也会补默认值，这里仍兜底。
    const username = String(detail.value.connection?.username || '').trim();
    if (port === 0 && username !== '') return '22';

    return '--';
  });

  /** 流量用量文案：限额内显示「已用 / 总量」，不限量时给「不限」 */
  const trafficText = computed(() =>
    detail.value.traffic?.limited
      ? `${detail.value.traffic.usage_label || '0G'} / ${detail.value.traffic.limit_label || t('serviceConsole.traffic.unlimited')}`
      : t('serviceConsole.traffic.unlimited'),
  );

  /** 流量百分比（后端 traffic.usage_percent，真机是 57.7），不合法时返回 null 表示不画进度条 */
  const trafficPercent = computed<number | null>(() => {
    // 类型上是 number|null，但上游换版本时可能下发空串，这里按 unknown 收一次
    const raw: unknown = detail.value.traffic?.usage_percent;
    if (raw === null || raw === undefined || raw === '') return null;

    const value = Number(raw);
    if (!Number.isFinite(value)) return null;

    return Math.min(100, Math.max(0, Number(value.toFixed(1))));
  });

  const trafficPercentText = computed(() => String(trafficPercent.value ?? 0));

  const trafficProgressStatus = computed<'warning' | 'error' | undefined>(() => {
    const percent = trafficPercent.value;
    if (percent === null) return undefined;
    if (percent >= 100) return 'error';
    if (percent >= 90) return 'warning';

    return undefined;
  });

  /** 操作系统行右侧的两个系统操作入口，跟随 detail.actions */
  const canReinstall = computed(() => Boolean(detail.value.actions?.reinstall));
  const canResetPassword = computed(() => Boolean(detail.value.actions?.password_reset));

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
    if (publicIpValues.value.length) return t('common.countUnit', { count: publicIpValues.value.length });
    return findSpecValue(
      detail,
      ['IP数量', 'IP 数量', 'ip'],
      primaryConnectionText.value !== '--' ? t('common.countUnit', { count: 1 }) : '--',
    );
  });
  const bandwidthText = computed(() => findSpecValue(detail, ['带宽', '宽带', 'bandwidth'], '--'));
  const renewPriceText = computed(() => `¥${formatMoney(detail.value.amount)}`);
  const autoRenewLabel = computed(() =>
    Number(detail.value.auto_renew) === 1 ? t('client.services.enabled') : t('client.services.disabled'),
  );
  const resolvedPassword = computed(() => {
    const password = String(detail.value.connection?.password || '').trim();

    if (password !== '') {
      return showPassword.value ? password : '••••••••';
    }

    return detail.value.connection?.has_password ? t('profile.statusSet') : '--';
  });

  // Copy helper with toast
  async function copyTextWithToast(value: unknown) {
    await copyText(value, {
      successMsg: t('serviceConsole.contentCopied'),
      errorMsg: t('finance.messages.copyUnsupported'),
    });
  }

  // Route watchers
  watch(
    () => route.params.id,
    () => {
      resetLazyTabs();
      resetSecurityState();
      void bootstrap();
    },
    { immediate: true },
  );

  /**
   * 需要走接口取数的数据面板 tab。
   *
   * 与魔方兼容 tab（自定义区域 iframe）共用后台开关 system.console_area_preload_all_enabled：
   * 开关开启时这些面板的数据同样在首屏预热，关闭时保持「切到面板再按需加载」。
   * 总览 / VNC 不在此列：总览由页面级 detailLoading 负责，VNC 是点击后才打开的弹窗。
   */
  const DATA_TAB_KEYS = ['monitor', 'security', 'nat', 'logs', 'finance'] as const;
  type DataTabKey = (typeof DATA_TAB_KEYS)[number];

  function isDataTabKey(key: string): key is DataTabKey {
    return (DATA_TAB_KEYS as readonly string[]).includes(key);
  }

  /**
   * 加载某个数据面板的数据，同一 tab 只发一次请求。
   *
   * 先置 loadedTabs 再 await：预热与用户切 tab 可能同时命中同一 tab，
   * 置位在前才能去重，否则会重复回源上游。
   */
  async function ensureDataTabLoaded(key: DataTabKey, silent = false) {
    if (loadedTabs[key]) return;
    loadedTabs[key] = true;

    if (key === 'monitor') await loadMonitor();
    else if (key === 'security') await loadSecurityGroups();
    else if (key === 'nat') await loadNatForwardings();
    else if (key === 'logs') await loadLogs(silent);
    else if (key === 'finance') await loadFinanceLogs(silent);
  }

  watch(activeTab, async (tab) => {
    if (!availableTabs.value.includes(tab)) {
      activeTab.value = DEFAULT_TAB;
      return;
    }
    // 开关关闭时的按需加载路径：切到面板才发请求
    if (isDataTabKey(tab)) await ensureDataTabLoaded(tab);
  });

  /**
   * 首屏预热数据面板。
   *
   * 后台开关 system.console_area_preload_all_enabled 开启（缺省开启）时，在用户还停在总览页
   * 就把安全组 / NAT / 监控 / 日志等面板的数据拉回来，切 tab 零等待；
   * 关闭时上面的 activeTab watcher 按需加载，用来规避首屏并发回源上游占用带宽。
   *
   * detail 落地前不预热：那之前还不确定这个实例到底有哪些面板（能力接口可能还会裁掉监控 / NAT）。
   */
  watch(
    () => [areaPreloadAll.value, availableTabs.value.join(','), Number(detail.value.id || 0)] as const,
    ([preloadAll, tabs, id]) => {
      if (!preloadAll || id <= 0 || tabs === '') return;
      for (const tab of tabs.split(',')) {
        if (isDataTabKey(tab)) void ensureDataTabLoaded(tab, true);
      }
    },
    { immediate: true },
  );

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
    externalVncUrl,
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
    upgradeVisible,
    upgradeLoading,
    upgradeQuoting,
    upgradeSubmitting,
    upgradeError,
    upgradeData,
    upgradeQuote,
    upgradeOptions,
    upgradeForm,
    upgradeQuoteKind,
    upgradeQuoteAmount,
    upgradePayableAmount,
    upgradeOptionName,
    upgradeCycleOptions,
    upgradeEmptyText,
    currentConfigRows,
    selectedUpgradeOption,
    canSubmitUpgrade,
    upgradeSubmitBlocked,
    openUpgradeDialog,
    loadUpgradePreview,
    quoteUpgrade,
    handleUpgradeOptionChange,
    handleUpgradeCycleChange,
    reapplyUpgradePromoCode,
    submitUpgradeOrder,
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
    rescueVisible,
    rescueState,
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
    natVisible,
    natForm,
    logsState,
    financeState,

    // Computed
    serviceId,
    availableTabs,
    consoleAreaLabels,
    areaPreloadAll,
    canManageConsole,
    canSyncStatus,
    serviceRegion,
    serviceOs,
    primaryConnectionLabel,
    primaryConnectionValues,
    primaryConnectionText,
    connectionEndpointText,
    connectionPortText,
    trafficText,
    trafficPercent,
    trafficPercentText,
    trafficProgressStatus,
    canReinstall,
    canResetPassword,
    instanceStatusText,
    instanceStatusTheme,
    serviceIpCount,
    bandwidthText,
    renewPriceText,
    autoRenewLabel,
    resolvedPassword,
    renewAmount,
    renewOriginalAmount,
    renewAgentDiscountRate,
    renewAgentGroupName,
    renewHasAgentDiscount,
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
    openRescueDialog,
    submitRescue,
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
    openNatForwardingDialog,
    submitNatForwarding,
    deleteNatForwarding,
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
