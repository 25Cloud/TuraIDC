<template>
  <section class="service-console">
    <console-breadcrumb />

    <loading-state :loading="detailLoading" text="正在加载实例控制台">
      <console-header :compact="isCdn" />
      <console-alerts />

      <div class="console-workbench">
        <console-sidebar v-model:active-tab="activeTab" :items="navItems" />
        <main class="console-content">
          <!-- 面板信息（自定义区域）常驻挂载：进入控制台就后台把 iframe 加载好，
               切 tab 时只是显示已渲染好的同一个实例，不再等上游往返 + 静态资源加载。
               面板内容接口是 no-store，浏览器缓存复用不了，只能靠「iframe 不销毁」来预热。 -->
          <area-tab
            v-if="areaModuleKey"
            :module-key="areaModuleKey"
            :preloading="!isAreaTab"
            :class="{ 'is-offstage': !isAreaTab }"
          />
          <component v-if="!isAreaTab" :is="activeTabComponent" />
        </main>
      </div>
    </loading-state>

    <console-dialogs />
  </section>
</template>
<script setup lang="ts">
import LoadingState from '@shared/user-v3/components/LoadingState.vue';
import { computed } from 'vue';

import { isCdnConsole } from '@/domains/services/console/useConsoleCore';
import { useServiceConsole } from '@/domains/services/useServiceConsole';

import ConsoleAlerts from './components/ConsoleAlerts.vue';
import ConsoleBreadcrumb from './components/ConsoleBreadcrumb.vue';
import ConsoleDialogs from './components/ConsoleDialogs.vue';
import ConsoleHeader from './components/ConsoleHeader.vue';
import ConsoleSidebar from './components/ConsoleSidebar.vue';
import AreaTab from './components/tabs/AreaTab.vue';
import { resolveCdnNavItems, resolveCdnTabComponent } from './components/cdn/registry';
import { provideServiceConsoleContext } from './components/context';
import { resolveConsoleNavItems, resolveConsoleTabComponent } from './components/registry';

const serviceConsole = useServiceConsole();
provideServiceConsoleContext(serviceConsole);

const { detail, detailLoading, activeTab, availableTabs, consoleAreaLabels } = serviceConsole;

/** 产品在后台把「控制台面板」选成 CDN 时走专属控制台，其余保持通用控制台 */
const isCdn = computed(() => isCdnConsole(detail.value));

const consoleNavItems = computed(() =>
  resolveConsoleNavItems(availableTabs.value, consoleAreaLabels.value),
);
const cdnNavItems = computed(() => resolveCdnNavItems(availableTabs.value, consoleAreaLabels.value));

const navItems = computed(() => (isCdn.value ? cdnNavItems.value : consoleNavItems.value));

function resolveTabComponent(tabKey: string) {
  return isCdn.value ? resolveCdnTabComponent(tabKey) : resolveConsoleTabComponent(tabKey);
}

const activeTabComponent = computed(() => resolveTabComponent(activeTab.value));

/** 当前 tab 是否落在走 iframe 的自定义区域上（CDN 的「套餐与面板」也在这里面） */
const isAreaTab = computed(() => activeTabComponent.value === AreaTab);

/**
 * 常驻面板实例当前该加载哪个区域 key。
 * 用户在看别的 tab 时取第一个面板区域 key 预热；切到面板 tab 时用它自己的 key，
 * 两者一致，所以 iframe 不会重新加载。
 */
const areaModuleKey = computed(() => {
  const current = String(activeTab.value || '').trim();
  if (current && resolveTabComponent(current) === AreaTab) {
    return current;
  }

  return availableTabs.value.find((key) => resolveTabComponent(key) === AreaTab) ?? '';
});
</script>
<style src="./components/styles.less" lang="less"></style>