import {
  BillIcon,
  CatalogIcon,
  ChartLineDataIcon,
  DashboardIcon,
  DesktopIcon,
  ForwardIcon,
  LockOnIcon,
} from 'tdesign-icons-vue-next';
import type { Component } from 'vue';

import AreaTab from './tabs/AreaTab.vue';
import FinanceTab from './tabs/FinanceTab.vue';
import LogsTab from './tabs/LogsTab.vue';
import MonitorTab from './tabs/MonitorTab.vue';
import NatTab from './tabs/NatTab.vue';
import OverviewTab from './tabs/OverviewTab.vue';
import SecurityTab from './tabs/SecurityTab.vue';
import VncTab from './tabs/VncTab.vue';

export interface ServiceConsoleNavItem {
  key: string;
  label: string;
  icon: Component;
}

const consoleTabMeta: Record<string, Omit<ServiceConsoleNavItem, 'key'>> = {
  overview: { label: '控制台总览', icon: DashboardIcon },
  monitor: { label: '监控信息', icon: ChartLineDataIcon },
  security: { label: '安全组', icon: LockOnIcon },
  nat: { label: '端口转发', icon: ForwardIcon },
  logs: { label: '操作日志', icon: CatalogIcon },
  finance: { label: '财务日志', icon: BillIcon },
  vnc: { label: 'VNC 控制台', icon: DesktopIcon },
};

export const consoleTabComponents: Record<string, Component> = {
  overview: OverviewTab,
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
