<template>
  <section class="service-console">
    <console-breadcrumb />

    <loading-state :loading="detailLoading" :text="$t('serviceConsole.loadingConsole')">
      <!-- 面板型控制台（CDN / 虚拟主机）没有电源/重装等操作按钮，头部用紧凑版 -->
      <console-header
        :compact="consoleKind !== 'generic'"
        :compact-region-label="
          consoleKind === 'virtualhost'
            ? $t('serviceConsole.areaLabel.idcRegion')
            : $t('serviceConsole.areaLabel.acceleration')
        "
      />
      <console-alerts />

      <div class="console-workbench">
        <console-sidebar v-model:active-tab="activeTab" :items="navItems" />
        <main class="console-content">
          <!-- 面板信息（自定义区域）常驻挂载：进入控制台就后台把 iframe 加载好，
               切 tab 时只是显示已渲染好的同一个实例，不再等上游往返 + 静态资源加载。
               面板内容接口是 no-store，浏览器缓存复用不了，只能靠「iframe 不销毁」来预热。

               后台开关 system.console_area_preload_all_enabled 打开时（默认），所有自定义区域
               各挂一个实例并在首屏全部预热：切换零等待，来回切换也不再重复回源上游。
               关闭时只挂第一个，其余按需加载，规避多个 iframe 同时回源对首屏带宽的占用。 -->
          <area-tab
            v-for="key in areaModuleKeys"
            :key="key"
            :module-key="key"
            :preloading="activeTab !== key"
            :class="{ 'is-offstage': activeTab !== key }"
          />
          <component :is="activeTabComponent" v-if="!isAreaTab" />
        </main>
      </div>
    </loading-state>

    <console-dialogs />
  </section>
</template>
<script setup lang="ts">
import LoadingState from '@shared/user-v3/components/LoadingState.vue';
import { computed, ref, watch } from 'vue';

import { isCdnConsole, isVirtualHostConsole } from '@/domains/services/console/useConsoleCore';
import { useServiceConsole } from '@/domains/services/useServiceConsole';

import { resolveCdnNavItems, resolveCdnTabComponent } from './components/cdn/registry';
import ConsoleAlerts from './components/ConsoleAlerts.vue';
import ConsoleBreadcrumb from './components/ConsoleBreadcrumb.vue';
import ConsoleDialogs from './components/ConsoleDialogs.vue';
import ConsoleHeader from './components/ConsoleHeader.vue';
import ConsoleSidebar from './components/ConsoleSidebar.vue';
import { provideServiceConsoleContext } from './components/context';
import { resolveConsoleNavItems, resolveConsoleTabComponent } from './components/registry';
import AreaTab from './components/tabs/AreaTab.vue';
import { resolveVirtualHostNavItems, resolveVirtualHostTabComponent } from './components/virtualhost/registry';

const serviceConsole = useServiceConsole();
provideServiceConsoleContext(serviceConsole);

const { detail, detailLoading, activeTab, availableTabs, consoleAreaLabels, areaPreloadAll } = serviceConsole;

/**
 * 控制台类别：CDN 与虚拟主机各有专属控制台，其余保持通用控制台。
 * 判定顺序：CDN 优先，避免历史数据同时命中两类。
 */
const consoleKind = computed<'cdn' | 'virtualhost' | 'generic'>(() => {
  const current = detail.value;
  if (isCdnConsole(current)) return 'cdn';
  if (isVirtualHostConsole(current)) return 'virtualhost';
  return 'generic';
});

const consoleNavItems = computed(() => resolveConsoleNavItems(availableTabs.value, consoleAreaLabels.value));
const cdnNavItems = computed(() => resolveCdnNavItems(availableTabs.value, consoleAreaLabels.value));
const virtualHostNavItems = computed(() => resolveVirtualHostNavItems(availableTabs.value, consoleAreaLabels.value));

const navItems = computed(() => {
  if (consoleKind.value === 'cdn') return cdnNavItems.value;
  if (consoleKind.value === 'virtualhost') return virtualHostNavItems.value;
  return consoleNavItems.value;
});

function resolveTabComponent(tabKey: string) {
  if (consoleKind.value === 'cdn') return resolveCdnTabComponent(tabKey);
  if (consoleKind.value === 'virtualhost') return resolveVirtualHostTabComponent(tabKey);
  return resolveConsoleTabComponent(tabKey);
}

const activeTabComponent = computed(() => resolveTabComponent(activeTab.value));

/**
 * 当前 tab 是否落在走 iframe 的自定义区域上
 * （CDN 的「套餐与面板」、虚拟主机的「主机与面板」都在这里面）
 */
const isAreaTab = computed(() => activeTabComponent.value === AreaTab);

/**
 * 需要挂载的面板区域 key 列表，每个 key 对应一个独立的 iframe 实例。
 *
 * 开关打开：所有走 iframe 的自定义区域一次挂全，切换 tab 只做显隐。
 * 开关关闭（按需加载）：**首屏一个都不挂**，切到哪个区域才挂哪个；
 * 挂过的会记住，切回去只做显隐——内容接口是 no-store，重建 iframe 就等于重新回源上游。
 *
 * 这里是首屏并发的主要来源：一次开页挂 N 个 iframe 就是 N 次上游往返，
 * 而上游单次动辄 5–25 秒。按需加载把它从「N 次」压到「0 次」。
 */
const mountedAreaKeys = ref<string[]>([]);

const areaModuleKeys = computed(() => {
  const keys = availableTabs.value.filter((key) => resolveTabComponent(key) === AreaTab);

  if (keys.length === 0) return [];
  if (areaPreloadAll.value) return keys;

  const active = activeTab.value;

  // 当前激活的区域一定挂：不依赖下面的 watch 时序，避免切过去先空白一帧
  return keys.filter((key) => key === active || mountedAreaKeys.value.includes(key));
});

/**
 * 记住访问过的区域：只增不减，保证切回去不重建 iframe（不重复回源上游）。
 */
watch(
  [activeTab, availableTabs],
  () => {
    const key = activeTab.value;
    if (!key || resolveTabComponent(key) !== AreaTab) return;
    if (mountedAreaKeys.value.includes(key)) return;

    mountedAreaKeys.value = [...mountedAreaKeys.value, key];
  },
  { immediate: true },
);
</script>
<style src="./components/styles.less" lang="less"></style>
