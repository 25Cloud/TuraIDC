<template>
  <section
    class="console-panel-section area-panel"
    :aria-hidden="preloading ? 'true' : undefined"
  >
    <t-card :title="panelTitle" :bordered="false">
      <template v-if="frameSrc && !preloading" #actions>
        <t-space>
          <t-button variant="outline" size="small" :loading="loading" @click="reloadArea">
            <template #icon><refresh-icon /></template>
            刷新
          </t-button>
          <t-button variant="outline" size="small" @click="openInNewWindow">
            <template #icon><jump-icon /></template>
            新窗口打开
          </t-button>
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

import clientApi from '@/api/client';
import { resolveErrorMessage } from '@/domains/services/console/useConsoleCore';
import { ensureAreaTicket, invalidateAreaTicket } from '@/domains/services/console/useConsoleAreaTicket';

import { useServiceConsoleContext } from '../context';

/** 与后端注入的 popupBridgeScript 约定一致的桥接事件名 */
const POPUP_BRIDGE_EVENT = 'tura:open-url';

const props = defineProps<{
  /**
   * 预热模式：面板在后台离屏加载，用户切到该 tab 时直接显示已渲染好的 iframe。
   *
   * 为什么必须常驻而不是「先请求再缓存」——面板内容接口下发的是 Cache-Control: no-store，
   * 浏览器不会缓存，隐藏 iframe 预热拿到的响应在真正切tab 时还得重新走一遍网络。
   * 只有让同一个 iframe 实例一直活着，才能把上游往返 + jQuery/bootstrap/sweetalert2
   * 加载 + 面板自身渲染的耗时全部挪到用户还在看总览的时候。
   */
  preloading?: boolean;
  /**
   * 显式指定要加载的区域 key。
   * 预热时用户还停在总览，activeTab 是 overview，必须由外部点名要预热哪个面板，
   * 否则会去拉 overview 的内容。
   */
  moduleKey?: string;
}>();

const { serviceId, activeTab, consoleAreaLabels, detail } = useServiceConsoleContext();

const loading = ref(false);
const errorText = ref('');
const ticket = ref('');
const loadingToken = ref(0);
// 面板内容是否已真正渲染出来（iframe load：含其子资源加载完成）
const frameLoaded = ref(false);
// 用于校验 postMessage 来源，避免任意窗口借桥接打开外部地址
const frameRef = ref<HTMLIFrameElement | null>(null);

const moduleKey = computed(() =>
  String(props.moduleKey || activeTab.value || '').trim(),
);
const panelTitle = computed(() =>
  String(consoleAreaLabels.value?.[moduleKey.value] || moduleKey.value || '自定义功能面板'),
);
const frameSrc = computed(() => {
  const id = serviceId.value;
  const key = moduleKey.value;
  const token = ticket.value;

  return id > 0 && key && token ? clientApi.serviceConsoleAreaContentUrl(id, token, key) : '';
});

async function loadArea() {
  const id = serviceId.value;
  const key = moduleKey.value;
  if (!(id > 0) || !key) return;

  const token = ++loadingToken.value;
  loading.value = true;
  errorText.value = '';

  try {
    const next = await ensureAreaTicket(id);
    if (token !== loadingToken.value) return;
    ticket.value = next;
  } catch (error: unknown) {
    if (token !== loadingToken.value) return;
    // 预热失败不打扰用户：用户还没点到这个 tab，报错只会污染当前页面。
    // 真正切过来时会重新触发 loadArea()，那时再如实提示并给出「重新加载」。
    if (props.preloading) return;
    errorText.value = resolveErrorMessage(error, '功能面板加载失败，请稍后重试');
  } finally {
    if (token === loadingToken.value) {
      loading.value = false;
    }
  }
}

function reloadArea() {
  ticket.value = '';
  frameLoaded.value = false;
  errorText.value = '';
  if (serviceId.value > 0) {
    invalidateAreaTicket(serviceId.value);
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

/** 面板地址由后端从上游归一抽取，抽不到时按钮不出现 */
const panelEntryUrl = computed(() => String(detail.value?.panel?.panel_url || '').trim());

function openPanelEntry() {
  if (panelEntryUrl.value) {
    window.open(panelEntryUrl.value, '_blank', 'noopener,noreferrer');
  }
}

/**
 * 弹窗桥接：上游面板的「跳转到面板」用 window.open 打第三方面板，
 * 而 iframe 属于跨域上下文，浏览器会把它判为「无用户手势的跨源弹窗」直接静默拦掉，
 * 表现就是「点了没反应，连拦截提示都没有」。后端已注入脚本把地址 postMessage 过来，
 * 这里负责代开并回执，让 iframe 侧不必等它 1.2s 的兜底超时。
 */
function handleBridgeMessage(event: MessageEvent) {
  // 只接受本组件 iframe 发来的消息：监听器挂在 window 上，
  // 同页任意窗口（含被面板打开的第三方页面）都能发postMessage，
  // 不校验来源等于把「代开任意网址」的能力交给了任何页面。
  const frame = frameRef.value;
  if (!frame || event.source !== frame.contentWindow) return;

  const data = event.data as { type?: string; id?: string; url?: string } | null;
  if (!data || data.type !== POPUP_BRIDGE_EVENT || !data.url) return;

  let href = '';
  try {
    const parsed = new URL(String(data.url));
    // 只放行 http/https，避免上游 HTML 里的 javascript: 之类借桥接执行
    if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') return;
    href = parsed.href;
  } catch {
    return;
  }

  window.open(href, '_blank', 'noopener,noreferrer');

  if (data.id) {
    try {
      (event.source as Window | null)?.postMessage({ type: `${POPUP_BRIDGE_EVENT}:ack`, id: data.id }, '*');
    } catch {
      // 回执失败不影响已开出的窗口，iframe 侧有 1.2s 兜底
    }
  }
}

onMounted(() => window.addEventListener('message', handleBridgeMessage));
onUnmounted(() => window.removeEventListener('message', handleBridgeMessage));

watch(
  () => [serviceId.value, moduleKey.value] as const,
  ([id], previous) => {
    const [prevId, prevKey] = previous ?? [0, ''];
    // 换服务或换区域时，旧票据与旧渲染结果都不能留用
    if (id !== prevId) {
      ticket.value = '';
      frameLoaded.value = false;
    } else if (moduleKey.value !== prevKey) {
      frameLoaded.value = false;
    }
    errorText.value = '';
    void loadArea();
  },
  { immediate: true },
);

// 面板地址变了说明要重新加载：先把加载态压回去，等 iframe 真正 load 再撤掉。
// （地址没变时 iframe 不会重载，保持已加载状态，不会卡住转圈）
watch(frameSrc, (next, previous) => {
  if (next && next !== previous) {
    frameLoaded.value = false;
  }
});

/**
 * 用户真正切到这个 tab 的那一刻（preloading true -> false）补一次重试。
 *
 * 预热失败时 moduleKey 并没有变化（预热和展示本来就是同一个区域 key），
 * 上面的 watcher 不会重跑，frameSrc 又是空的 —— 不补这一下用户会一直卡在
 * 「正在准备功能面板」转圈，只能刷新页面。
 * 已经预热成功的（frameSrc 有值）不重复请求，iframe 直接显示。
 */
watch(
  () => props.preloading,
  (preloading, wasPreloading) => {
    //只关心「预热 -> 展示」这一次切换
    if (preloading !== false || wasPreloading !== true) return;
    // 预热已经拿到地址：说明面板加载过了，切过来直接显示，不重复请求
    if (frameSrc.value) return;
    void loadArea();
  },
);
</script>
