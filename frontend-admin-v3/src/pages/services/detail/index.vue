<template>
  <div class="service-detail-page">
    <div class="detail-action-bar">
      <t-button variant="text" theme="default" @click="goBack">
        <template #icon><chevron-left-icon /></template>
        {{ $t('services.console.back') }}
      </t-button>
      <div class="detail-title">
        <h3>{{ consoleTitle }}</h3>
        <span>{{ $t('services.console.serviceLabel', { id: serviceId }) }}</span>
      </div>
      <!-- 「查看用户」只在顶部信息卡片里出现一次（紧邻所属用户），此处不再重复 -->
    </div>

    <t-empty v-if="!hasUser" :description="$t('services.console.detailMissingUser')">
      <t-button theme="primary" @click="goBack">{{ $t('services.console.backToServiceList') }}</t-button>
    </t-empty>
    <template v-else>
      <!-- 形态未判定前先压住渲染，避免控制台闪一下通用形态再切成 CDN -->
      <t-loading v-if="kindPending" size="small" class="service-console-kind-loading" />
      <cdn-console-panel v-else-if="isCdn" :key="userId" :user-id="userId" />
      <console-panel v-else :key="userId" :user-id="userId" />
    </template>
  </div>
</template>
<script setup lang="ts">
import './index.less';

import { ChevronLeftIcon } from 'tdesign-icons-vue-next';
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';

import { userApi } from '@/api/user';
import { t } from '@/locales';

import CdnConsolePanel from './cdn/CdnConsolePanel.vue';
import { isCdnConsole } from './console/composables/useConsoleCore';
import type { ConsoleServiceDetail } from './console/types';
import ConsolePanel from './ConsolePanel.vue';

defineOptions({ name: 'AdminServiceDetail' });

const route = useRoute();
const router = useRouter();

const serviceId = computed(() => Number(route.params.id || 0));
const userId = computed(() => String(route.query.user || ''));
const hasUser = computed(() => Number(userId.value) > 0);

/**
 * 控制台形态分流：由产品配置的「控制台面板」决定，运营在产品编辑页切换。
 *
 * CDN 自有一套控制台（流量 / 加速区域 / 防护能力 / 面板入口），
 * 与云主机的通用计算控制台不是同一套语义：CDN 没有操作系统、开关机、
 * 安全组、VNC、端口转发，这些在通用控制台里都会露出再被裁剪。
 * 判定规则统一走 isCdnConsole（console_template 优先，产品类型兜底）。
 */
const consoleKind = ref<'cdn' | 'generic' | 'unknown'>('unknown');
const kindPending = computed(() => hasUser.value && consoleKind.value === 'unknown');
const isCdn = computed(() => consoleKind.value === 'cdn');
const consoleTitle = computed(() =>
  isCdn.value ? t('services.console.cdnConsoleTitle') : t('services.console.instanceDetailTitle'),
);

let kindToken = 0;

async function resolveConsoleKind() {
  if (!hasUser.value || serviceId.value <= 0) return;
  const token = ++kindToken;
  try {
    // config 响应已带 console_template / product_type / machine_category，
    // 足够判定 CDN；不必拉完整的 detail（会连带触发 connection 等请求）。
    const config = (await userApi.serviceConfig(userId.value, serviceId.value)) || {};
    if (token !== kindToken) return;
    consoleKind.value = isCdnConsole(config as ConsoleServiceDetail) ? 'cdn' : 'generic';
  } catch {
    // 判定失败退回通用控制台：它对 CDN 也能展示（只是 tab 更宽），不会白屏
    if (token === kindToken) consoleKind.value = 'generic';
  }
}

watch(
  [hasUser, serviceId],
  () => {
    consoleKind.value = 'unknown';
    void resolveConsoleKind();
  },
  { immediate: true },
);

function goBack() {
  if (window.history.length > 1) {
    router.back();
  } else {
    router.push({ name: 'AdminServices' });
  }
}
</script>
