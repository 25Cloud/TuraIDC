<template>
  <section class="service-console service-console--nat">
    <console-breadcrumb />

    <loading-state :loading="detailLoading" text="正在加载端口映射控制台">
      <console-header />
      <console-alerts />

      <div class="console-workbench">
        <console-sidebar v-model:active-tab="activeTab" :items="consoleNavItems" />
        <main class="console-content">
          <overview-tab v-if="activeTab === 'overview'" />
          <monitor-tab v-else-if="activeTab === 'monitor'" />
          <security-tab v-else-if="activeTab === 'security'" />
          <nat-tab v-else-if="activeTab === 'nat'" />
          <logs-tab v-else-if="activeTab === 'logs'" />
          <finance-tab v-else-if="activeTab === 'finance'" />
          <vnc-tab v-else-if="activeTab === 'vnc'" />
        </main>
      </div>
    </loading-state>

    <console-dialogs />
  </section>
</template>
<script setup lang="ts">
import LoadingState from '@shared/user-v3/components/LoadingState.vue';
import { watch } from 'vue';
import { useRouter } from 'vue-router';

import { isNatConsole, NAT_TABS } from '@/domains/services/console/useConsoleCore';
import { useServiceConsole } from '@/domains/services/useServiceConsole';

import ConsoleAlerts from '../components/ConsoleAlerts.vue';
import ConsoleBreadcrumb from '../components/ConsoleBreadcrumb.vue';
import ConsoleDialogs from '../components/ConsoleDialogs.vue';
import ConsoleHeader from '../components/ConsoleHeader.vue';
import ConsoleSidebar from '../components/ConsoleSidebar.vue';
import { provideServiceConsoleContext } from '../components/context';
import { resolveConsoleNavItems } from '../components/registry';
import FinanceTab from '../components/tabs/FinanceTab.vue';
import LogsTab from '../components/tabs/LogsTab.vue';
import MonitorTab from '../components/tabs/MonitorTab.vue';
import NatTab from '../components/tabs/NatTab.vue';
import OverviewTab from '../components/tabs/OverviewTab.vue';
import SecurityTab from '../components/tabs/SecurityTab.vue';
import VncTab from '../components/tabs/VncTab.vue';

const router = useRouter();
const serviceConsole = useServiceConsole();
provideServiceConsoleContext(serviceConsole);

const { activeTab, detail, detailLoading } = serviceConsole;
const consoleNavItems = resolveConsoleNavItems(NAT_TABS);

watch(
  () => Number(detail.value.id || 0),
  (id) => {
    if (id > 0 && !isNatConsole(detail.value)) {
      void router.replace({ name: 'ClientComputeConsole', params: { id: String(id) } });
    }
  },
);
</script>
<style src="../components/styles.less" lang="less"></style>
