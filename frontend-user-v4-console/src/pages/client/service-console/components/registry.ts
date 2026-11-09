import {
  BillIcon,
  CatalogIcon,
  CdIcon,
  ChartLineDataIcon,
  DashboardIcon,
  DesktopIcon,
  ForwardIcon,
  HistoryIcon,
  LockOnIcon,
} from 'tdesign-icons-vue-next';
import type { Component } from 'vue';

import { t } from '@/locales';

import AreaTab from './tabs/AreaTab.vue';
import FinanceTab from './tabs/FinanceTab.vue';
import IsoTab from './tabs/IsoTab.vue';
import LogsTab from './tabs/LogsTab.vue';
import MonitorTab from './tabs/MonitorTab.vue';
import NatTab from './tabs/NatTab.vue';
import OverviewTab from './tabs/OverviewTab.vue';
import SecurityTab from './tabs/SecurityTab.vue';
import SnapshotTab from './tabs/SnapshotTab.vue';
import VncTab from './tabs/VncTab.vue';

export interface ServiceConsoleNavItem {
  key: string;
  label: string;
  icon: Component;
}

const consoleTabMeta: Record<string, Omit<ServiceConsoleNavItem, 'key'>> = {
  overview: { label: t('serviceConsole.overview'), icon: DashboardIcon },
  monitor: { label: t('serviceConsole.monitorInfo'), icon: ChartLineDataIcon },
  security: { label: t('serviceConsole.security.title'), icon: LockOnIcon },
  // 快照/备份与 ISO 挂载是否出现由后端 capabilities 的 snapshot_supported / iso_supported 决定，
  // 这里只是把元数据登记好（顺序见 useConsoleCore 的 BUILTIN_TABS_ORDER）。
  snapshot: { label: t('serviceConsole.snapshot.tab'), icon: HistoryIcon },
  iso: { label: t('serviceConsole.iso.tab'), icon: CdIcon },
  nat: { label: t('serviceConsole.nat.title'), icon: ForwardIcon },
  logs: { label: t('serviceConsole.logs.title'), icon: CatalogIcon },
  finance: { label: t('serviceConsole.financeLog'), icon: BillIcon },
  vnc: { label: t('serviceConsole.vnc.title'), icon: DesktopIcon },
};

export const consoleTabComponents: Record<string, Component> = {
  overview: OverviewTab,
  monitor: MonitorTab,
  security: SecurityTab,
  snapshot: SnapshotTab,
  iso: IsoTab,
  nat: NatTab,
  logs: LogsTab,
  finance: FinanceTab,
  vnc: VncTab,
};

const builtinTabKeys = new Set(Object.keys(consoleTabComponents));

export function resolveConsoleNavItems(
  tabKeys: string[],
  areaLabels: Record<string, string> = {},
): ServiceConsoleNavItem[] {
  return tabKeys.map((key) => ({
    key,
    label: areaLabels[key] || consoleTabMeta[key]?.label || key,
    icon: consoleTabMeta[key]?.icon || DashboardIcon,
  }));
}

/** 未注册的内置 key 视为上游自定义区域，走 iframe 隔离渲染 */
export function resolveConsoleTabComponent(tabKey: string): Component {
  return builtinTabKeys.has(tabKey) ? consoleTabComponents[tabKey] : AreaTab;
}
