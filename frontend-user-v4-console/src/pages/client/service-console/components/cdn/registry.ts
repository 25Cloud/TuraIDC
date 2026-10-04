import { BillIcon, DashboardIcon, ServerIcon } from 'tdesign-icons-vue-next';
import type { Component } from 'vue';

import AreaTab from '../tabs/AreaTab.vue';
import FinanceTab from '../tabs/FinanceTab.vue';
import CdnOverviewTab from './tabs/CdnOverviewTab.vue';

export interface CdnConsoleNavItem {
  key: string;
  label: string;
  icon: Component;
}

/**
 * CDN 专属控制台的 tab 注册表。
 *
 * 与通用控制台（../registry.ts）刻意分开：CDN 只有流量/面板/账单这套语义，
 * 监控、安全组、端口转发、VNC 都不该出现。
 */
const cdnTabMeta: Record<string, Omit<CdnConsoleNavItem, 'key'>> = {
  overview: { label: 'CDN 总览', icon: DashboardIcon },
  panel: { label: '套餐与面板', icon: ServerIcon },
  finance: { label: '财务日志', icon: BillIcon },
};

const cdnTabComponents: Record<string, Component> = {
  overview: CdnOverviewTab,
  panel: AreaTab,
  finance: FinanceTab,
};

const cdnBuiltinTabKeys = new Set(Object.keys(cdnTabComponents));

/** 组装侧边栏：自定义区域（面板信息等）插在 overview 之后 */
export function resolveCdnNavItems(
  tabKeys: string[],
  areaLabels: Record<string, string> = {},
): CdnConsoleNavItem[] {
  return tabKeys.map((key) => ({
    key,
    label: areaLabels[key] || cdnTabMeta[key]?.label || key,
    icon: cdnTabMeta[key]?.icon || DashboardIcon,
  }));
}

/** 未注册的自定义区域 key 走 iframe 隔离渲染 */
export function resolveCdnTabComponent(tabKey: string): Component {
  return cdnBuiltinTabKeys.has(tabKey) ? cdnTabComponents[tabKey] : AreaTab;
}
