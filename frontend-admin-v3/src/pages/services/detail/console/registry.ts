import {
  BillIcon,
  CatalogIcon,
  ChartLineDataIcon,
  DashboardIcon,
  DesktopIcon,
  ForwardIcon,
  LockOnIcon,
  ServerIcon,
} from 'tdesign-icons-vue-next';
import type { Component } from 'vue';

import { t } from '@/locales';

import AreaTab from './tabs/AreaTab.vue';
import FinanceTab from './tabs/FinanceTab.vue';
import LogsTab from './tabs/LogsTab.vue';
import MonitorTab from './tabs/MonitorTab.vue';
import NatTab from './tabs/NatTab.vue';
import OverviewTab from './tabs/OverviewTab.vue';
import PanelTab from './tabs/PanelTab.vue';
import SecurityTab from './tabs/SecurityTab.vue';
import VncTab from './tabs/VncTab.vue';

export interface ServiceConsoleNavItem {
  key: string;
  label: string;
  icon: Component;
}

const consoleTabMeta: Record<string, Omit<ServiceConsoleNavItem, 'key'>> = {
  overview: { label: t('services.console.overviewTab'), icon: DashboardIcon },
  panel: { label: t('services.console.productConfigTab'), icon: ServerIcon },
  monitor: { label: t('services.console.monitorTitle'), icon: ChartLineDataIcon },
  security: { label: t('services.console.securityGroups'), icon: LockOnIcon },
  nat: { label: t('services.console.portForwarding'), icon: ForwardIcon },
  logs: { label: t('services.console.logsTab.title'), icon: CatalogIcon },
  finance: { label: t('services.console.financeLogs'), icon: BillIcon },
  vnc: { label: t('services.console.vncTab.title'), icon: DesktopIcon },
};

export const consoleTabComponents: Record<string, Component> = {
  overview: OverviewTab,
  panel: PanelTab,
  monitor: MonitorTab,
  security: SecurityTab,
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
