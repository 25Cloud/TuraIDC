<template>
  <section class="console-panel-section area-panel">
    <t-card :title="panelTitle" :bordered="false">
      <template v-if="frameSrc" #actions>
        <t-space>
          <t-button variant="outline" size="small" :loading="loading" @click="reloadArea">
            <template #icon><refresh-icon /></template>
            刷新
          </t-button>
          <t-button variant="outline" size="small" @click="openInNewWindow">
            <template #icon><jump-icon /></template>
            新窗口打开
          </t-button>
        </t-space>
      </template>

      <div class="area-frame-shell">
        <iframe
          v-if="frameSrc"
          :key="frameSrc"
          class="area-frame"
          :src="frameSrc"
          :title="panelTitle"
          referrerpolicy="no-referrer"
          @load="handleFrameLoaded"
        />
        <div v-else-if="errorText" class="area-state">
          <div class="area-state__body">
            <p>{{ errorText }}</p>
            <t-button size="small" variant="outline" @click="reloadArea">重新加载</t-button>
          </div>
        </div>
        <div v-else class="area-state">
          <div class="area-state__body">
            <span class="area-spinner" aria-hidden="true" />
            <p>正在加载功能面板</p>
          </div>
        </div>
      </div>
    </t-card>
  </section>
</template>
<script setup lang="ts">
import { JumpIcon, RefreshIcon } from 'tdesign-icons-vue-next';
import { computed, ref, watch } from 'vue';

import clientApi from '@/api/client';
import { resolveErrorMessage } from '@/domains/services/console/useConsoleCore';
import type { ServiceConsoleAreaTicket } from '@/types/client';

import { useServiceConsoleContext } from '../context';

const TICKET_TTL_MS = 10 * 60 * 1000;
const TICKET_SAFE_MARGIN_MS = 60 * 1000;

interface TicketEntry {
  ticket: string;
  expiresAt: number;
}

// 票据按服务缓存（内容接口本身不区分模块），多个自定义 tab 切换时可复用，避免频繁签发触发限流
const ticketCache = new Map<number, TicketEntry>();

const { serviceId, activeTab, consoleAreaLabels } = useServiceConsoleContext();

const loading = ref(false);
const errorText = ref('');
const ticket = ref('');
const loadingToken = ref(0);

const moduleKey = computed(() => String(activeTab.value || '').trim());
const panelTitle = computed(() =>
  String(consoleAreaLabels.value?.[moduleKey.value] || moduleKey.value || '自定义功能面板'),
);
const frameSrc = computed(() => {
  const id = serviceId.value;
  const key = moduleKey.value;
  const token = ticket.value;

  return id > 0 && key && token ? clientApi.serviceConsoleAreaContentUrl(id, token, key) : '';
});

async function ensureTicket(id: number): Promise<string> {
  const cached = ticketCache.get(id);
  if (cached && cached.expiresAt - Date.now() > TICKET_SAFE_MARGIN_MS) {
    return cached.ticket;
  }

  const res = await clientApi.createServiceConsoleAreaTicket(id);
  const next = String((res.data as ServiceConsoleAreaTicket | undefined)?.ticket || '');
  if (!next) {
    throw new Error('访问凭证生成失败，请稍后重试');
  }

  ticketCache.set(id, { ticket: next, expiresAt: Date.now() + TICKET_TTL_MS });
  return next;
}

async function loadArea() {
  const id = serviceId.value;
  const key = moduleKey.value;
  if (!(id > 0) || !key) return;

  const token = ++loadingToken.value;
  loading.value = true;
  errorText.value = '';

  try {
    const next = await ensureTicket(id);
    if (token !== loadingToken.value) return;
    ticket.value = next;
  } catch (error: unknown) {
    if (token !== loadingToken.value) return;
    errorText.value = resolveErrorMessage(error, '功能面板加载失败，请稍后重试');
  } finally {
    if (token === loadingToken.value) {
      loading.value = false;
    }
  }
}

function reloadArea() {
  ticket.value = '';
  if (serviceId.value > 0) {
    ticketCache.delete(serviceId.value);
  }
  void loadArea();
}

function handleFrameLoaded() {
  loading.value = false;
}

function openInNewWindow() {
  const url = frameSrc.value;
  if (url) {
    window.open(url, '_blank', 'noopener,noreferrer');
  }
}

watch(
  () => serviceId.value,
  () => {
    ticket.value = '';
    errorText.value = '';
    void loadArea();
  },
  { immediate: true },
);

// 组件被复用于不同自定义模块时，清除旧错误态并重置加载态
watch(moduleKey, () => {
  errorText.value = '';
  loading.value = false;
});
</script>
