/**
 * CDN 独立控制台 · tab 注册表
 *
 * 与通用计算控制台（console/registry.ts）刻意分开：CDN 没有 CPU / 内存 / 系统盘、
 * 没有开关机、没有安全组、没有 VNC、没有端口转发，也没有操作系统概念。
 * 沿用通用注册表会让这些 tab 出现后再由别处裁剪，属于「先给再收」，
 * 因此 CDN 单独维护一份注册表，只声明 CDN 真正具备的能力。
 *
 * 保留的四类入口：
 *   overview  CDN 总览（流量、加速区域、防护能力）
 *   panel     套餐配置（产品规格分组 + 面板入口）
 *   areas     上游自定义区域（如天理云的「面板信息」），按 key 动态插入
 *   finance   财务日志
 */
import {
  BillIcon,
  DashboardIcon,
  ServerIcon,
} from 'tdesign-icons-vue-next';
import type { Component } from 'vue';

import AreaTab from '../console/tabs/AreaTab.vue';
import FinanceTab from '../console/tabs/FinanceTab.vue';
import PanelTab from '../console/tabs/PanelTab.vue';
import CdnOverviewTab from './tabs/CdnOverviewTab.vue';

export interface CdnConsoleNavItem {
  key: string;
  label: string;
  icon: Component;
}

/** CDN 内置 tab：面板型（自定义区域）key 不在内置表里，由 areaLabels 提供中文名 */
const cdnTabMeta: Record<string, Omit<CdnConsoleNavItem, 'key'>> = {
  overview: { label: 'CDN 总览', icon: DashboardIcon },
  panel: { label: '套餐与面板', icon: ServerIcon },
  finance: { label: '财务日志', icon: BillIcon },
};

const cdnTabComponents: Record<string, Component> = {
  overview: CdnOverviewTab,
  panel: PanelTab,
  finance: FinanceTab,
};

const cdnBuiltinTabKeys = new Set(Object.keys(cdnTabComponents));

/** 面板型 key（上游自定义区域）走 iframe 内联渲染 */
const DEFAULT_AREA_ICON = DashboardIcon;

export function resolveCdnNavItems(
  tabKeys: string[],
  areaLabels: Record<string, string> = {},
): CdnConsoleNavItem[] {
  return tabKeys.map((key) => ({
    key,
    label: areaLabels[key] || cdnTabMeta[key]?.label || key,
    icon: cdnTabMeta[key]?.icon || DEFAULT_AREA_ICON,
  }));
}

/** 未注册的内置 key 视为上游自定义区域，走 iframe 隔离渲染 */
export function resolveCdnTabComponent(tabKey: string): Component {
  return cdnBuiltinTabKeys.has(tabKey) ? cdnTabComponents[tabKey] : AreaTab;
}
