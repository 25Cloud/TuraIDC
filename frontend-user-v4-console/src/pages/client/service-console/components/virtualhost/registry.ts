import { BillIcon, DashboardIcon, ServerIcon } from 'tdesign-icons-vue-next';
import type { Component } from 'vue';

import AreaTab from '../tabs/AreaTab.vue';
import FinanceTab from '../tabs/FinanceTab.vue';
import VirtualHostOverviewTab from './tabs/VirtualHostOverviewTab.vue';

export interface VirtualHostConsoleNavItem {
  key: string;
  label: string;
  icon: Component;
}

/**
 * 虚拟主机专属控制台的 tab 注册表。
 *
 * 与通用控制台（../registry.ts）和 CDN 控制台（../cdn/registry.ts）都刻意分开：
 * 虚拟主机只有「主机档案 / 空间与站点 / 面板登录 / 账单」这套语义，
 * 监控、安全组、端口转发、VNC 都不该出现。
 *
 * 控制能力（重置密码、续费、面板 iframe 等）仍由上游自定义区域承载，
 * 未注册的自定义区域 key 一律走 AreaTab 的 iframe 隔离渲染。
 */
const virtualHostTabMeta: Record<string, Omit<VirtualHostConsoleNavItem, 'key'>> = {
  overview: { label: '主机总览', icon: DashboardIcon },
  panel: { label: '主机与面板', icon: ServerIcon },
  finance: { label: '财务日志', icon: BillIcon },
};

const virtualHostTabComponents: Record<string, Component> = {
  overview: VirtualHostOverviewTab,
  panel: AreaTab,
  finance: FinanceTab,
};

const virtualHostBuiltinTabKeys = new Set(Object.keys(virtualHostTabComponents));

/** 组装侧边栏：自定义区域（面板信息等）插在 overview 之后 */
export function resolveVirtualHostNavItems(
  tabKeys: string[],
  areaLabels: Record<string, string> = {},
): VirtualHostConsoleNavItem[] {
  return tabKeys.map((key) => ({
    key,
    label: areaLabels[key] || virtualHostTabMeta[key]?.label || key,
    icon: virtualHostTabMeta[key]?.icon || DashboardIcon,
  }));
}

/** 未注册的自定义区域 key 走 iframe 隔离渲染 */
export function resolveVirtualHostTabComponent(tabKey: string): Component {
  return virtualHostBuiltinTabKeys.has(tabKey)
    ? virtualHostTabComponents[tabKey]
    : AreaTab;
}
