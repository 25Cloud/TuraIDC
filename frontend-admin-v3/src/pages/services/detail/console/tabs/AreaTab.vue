<template>
  <section class="console-panel-section area-panel">
    <t-card :title="panelTitle" :bordered="false">
      <template v-if="frameSrc" #actions>
        <t-space>
          <t-button variant="outline" size="small" :loading="loading" @click="reloadArea">
            <template #icon><refresh-icon /></template>
            刷新
          </t-button>
          <!-- 面板型产品的跳转按钮在 iframe 内，上游脚本用 window.open 打开第三方面板。
               跨域 iframe 里的 window.open 常被浏览器当作无用户手势直接拦掉，
               表现为「点了没反应」。这里由父页面直接代开，绕开 iframe 的弹窗限制。 -->
          <t-button
            v-if="panelEntryUrl"
            variant="outline"
            size="small"
            theme="primary"
            @click="openPanelEntry"
          >
            <template #icon><jump-icon /></template>
            进入面板
          </t-button>
          <t-button variant="outline" size="small" @click="openInNewWindow">
            <template #icon><jump-icon /></template>
            新窗口打开
          </t-button>
        </t-space>
      </template>

      <div class="area-frame-shell">
        <iframe
          v-if="frameSrc && !errorText"
          :key="frameSrc"
          ref="frameRef"
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

        <!-- 面板内容真正渲染出来之前一直压着加载态：
             iframe 的 load 事件要等其子资源（CSS/JS）也加载完才触发，用它作为「内容已出来」的信号，
             避免像以前那样加载态一闪就没、留下一片空白。 -->
        <div v-if="!errorText && !frameLoaded" class="area-loading" role="status" aria-live="polite">
          <div class="area-state__body">
            <span class="area-spinner" aria-hidden="true" />
            <p>{{ frameSrc ? '正在加载功能面板' : '正在准备功能面板' }}</p>
          </div>
        </div>
      </div>
    </t-card>
  </section>
</template>
<script setup lang="ts">
import { JumpIcon, RefreshIcon } from 'tdesign-icons-vue-next';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';

import { resolveErrorMessage } from '../composables/useConsoleCore';
import { useServiceConsoleContext } from '../context';
import type { ServiceConsoleAreaTicket } from '../types';

const TICKET_TTL_MS = 10 * 60 * 1000;
const TICKET_SAFE_MARGIN_MS = 60 * 1000;

interface TicketEntry {
  ticket: string;
  expiresAt: number;
}

// 票据按服务缓存（内容接口本身不区分模块），多个自定义 tab 切换时可复用，避免频繁签发触发限流
const ticketCache = new Map<number, TicketEntry>();

const { serviceId, activeTab, consoleAreaLabels, consoleApi, detail } = useServiceConsoleContext();

const loading = ref(false);
const errorText = ref('');
const ticket = ref('');
const loadingToken = ref(0);
// 面板内容是否已真正渲染出来（iframe load：含其子资源加载完成）
const frameLoaded = ref(false);
// 用于校验 postMessage 来源，避免任意窗口借桥接打开外部地址
const frameRef = ref<HTMLIFrameElement | null>(null);

/**
 * 面板入口地址（面板型产品）。
 * 后端 PanelAccessExtractor 从上游自定义区域解析而来，
 * 这里的 iframe 之外再给一个父页面级入口，规避 iframe 内 window.open 被拦。
 */
const panelEntryUrl = computed(() => String(detail.value?.panel?.panel_url || '').trim());

function openPanelEntry() {
  if (panelEntryUrl.value) {
    window.open(panelEntryUrl.value, '_blank', 'noopener,noreferrer');
  }
}

const moduleKey = computed(() => String(activeTab.value || '').trim());
const panelTitle = computed(() =>
  String(consoleAreaLabels.value?.[moduleKey.value] || moduleKey.value || '自定义功能面板'),
);
const frameSrc = computed(() => {
  const id = serviceId.value;
  const key = moduleKey.value;
  const token = ticket.value;

  return id > 0 && key && token ? consoleApi.serviceConsoleAreaContentUrl(id, token, key) : '';
});

async function ensureTicket(id: number): Promise<string> {
  const cached = ticketCache.get(id);
  if (cached && cached.expiresAt - Date.now() > TICKET_SAFE_MARGIN_MS) {
    return cached.ticket;
  }

  const res = await consoleApi.createServiceConsoleAreaTicket(id);
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
  frameLoaded.value = true;
}

function openInNewWindow() {
  const url = frameSrc.value;
  if (url) {
    window.open(url, '_blank', 'noopener,noreferrer');
  }
}

/**
 * 接住 iframe 内 window.open 的桥接请求。
 *
 * iframe 与控制台不同源，跨源弹窗会被浏览器静默拦掉（面板里「跳转到面板」
 * 点了没反应就是这个原因）。后端在注入运行时里包装了 window.open，
 * 把地址 postMessage 过来，这里以父页面的用户手势代开并回执。
 * 只放行 http/https 绝对地址。
 */
const POPUP_BRIDGE_EVENT = 'tura:open-url';

function handleBridgeMessage(event: MessageEvent) {
  // 只接受本组件 iframe 发来的消息：监听器挂在 window 上，
  // 同页任意窗口（包括被面板打开的第三方页面）都能发 postMessage，
  // 不校验来源等于把「代开任意网址」的能力交给了任何页面。
  const frame = frameRef.value;
  if (!frame || event.source !== frame.contentWindow) return;

  const data = event.data as { type?: string; id?: string; url?: string } | null;
  if (!data || data.type !== POPUP_BRIDGE_EVENT || !data.url) return;

  let href = '';
  try {
    const parsed = new URL(String(data.url));
    if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') return;
    href = parsed.href;
  } catch {
    return;
  }

  window.open(href, '_blank', 'noopener,noreferrer');

  if (data.id) {
    try {
      // iframe 内容与本站同源（走本地代理下发），但运行时无法静态确认，保留 '*' 并只回传 id
      (event.source as Window | null)?.postMessage({ type: `${POPUP_BRIDGE_EVENT}:ack`, id: data.id }, '*');
    } catch {
      // 回执失败不处理：iframe 侧有 1.2s 兜底会自行打开
    }
  }
}

onMounted(() => window.addEventListener('message', handleBridgeMessage));
onUnmounted(() => window.removeEventListener('message', handleBridgeMessage));

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

// 面板地址变了说明要重新加载：先把加载态压回去，等 iframe 真正 load 再撤掉。
// （地址没变时 iframe 不会重载，保持已加载状态，不会卡住转圈）
watch(frameSrc, (next, previous) => {
  if (next && next !== previous) {
    frameLoaded.value = false;
  }
});
</script>
