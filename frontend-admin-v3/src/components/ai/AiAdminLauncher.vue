<template>
  <div v-if="canUseAi" class="ai-admin-launcher">
    <!-- 面板展开时隐藏浮球：面板落在同一个右下角，两个入口叠着只会互相盖住 -->
    <button
      v-show="!panelVisible"
      type="button"
      class="ai-admin-launcher__ball"
      :aria-label="$t('ai.launcher.label')"
      :title="$t('ai.launcher.label')"
      :aria-expanded="panelVisible"
      data-ai-admin-launcher
      @click="handleToggle"
    >
      <ai-robot-avatar :size="26" class="ai-admin-launcher__robot" />
    </button>

    <ai-admin-panel v-model="panelVisible" :chat="chat" />
  </div>
</template>
<script setup lang="ts">
import { useAiChat } from '@turaidc/shared/runtime';
import { computed, ref } from 'vue';

import { aiClient } from '@/api/ai';
import { hasPermissionInList } from '@/constants/permissions';
import { useUserStore } from '@/store';

import AiAdminPanel from './AiAdminPanel.vue';
import AiRobotAvatar from './AiRobotAvatar.vue';

const userStore = useUserStore();

// 没有 ai.view 就不渲染浮球：否则点开只会收到一串 403
const canUseAi = computed(() => hasPermissionInList(userStore.userInfo?.permissions || [], 'ai.view'));

/**
 * 浮球挂在 layouts/index.vue（与 <setting-com /> 同级），切页不销毁，会话状态活在这里。
 * autoLoad: false —— 只有真的点开浮窗才拉 bootstrap，不给后台每页都打一次请求。
 */
const chat = useAiChat(aiClient, { channel: 'admin', autoLoad: false });

const panelVisible = ref(false);
let initialized = false;

function handleToggle() {
  panelVisible.value = !panelVisible.value;
  if (panelVisible.value && !initialized) {
    initialized = true;
    void chat.initialize();
  }
}
</script>
<style scoped lang="less">
.ai-admin-launcher {
  position: fixed;
  right: 1.5rem;
  bottom: calc(1.5rem + env(safe-area-inset-bottom, 0px));
  // 低于面板（3400）、高于页面内容：与官网 / 控制台同一层级梯度
  z-index: 3300;
}

/*
 * 尺寸与三端统一：直径 3.25rem（52px），品牌色径向渐变，
 * 中性阴影 + 品牌色投影；hover 上浮 2px，不做缩放。
 */
.ai-admin-launcher__ball {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 3.25rem;
  height: 3.25rem;
  padding: 0;
  color: var(--td-text-color-anti);
  cursor: pointer;
  background: radial-gradient(
    circle at 32% 26%,
    var(--td-brand-color-hover) 0%,
    var(--td-brand-color) 58%,
    var(--td-brand-color-active) 100%
  );
  border: 0;
  border-radius: 50%;
  box-shadow:
    0 12px 26px -14px var(--td-brand-color),
    var(--td-shadow-2);
  transition:
    transform 0.16s ease,
    box-shadow 0.16s ease,
    filter 0.16s ease;

  &:hover {
    box-shadow:
      0 16px 30px -14px var(--td-brand-color),
      var(--td-shadow-2);
    transform: translateY(-2px);
  }

  &:active {
    filter: brightness(0.96);
    transform: translateY(0);
  }

  &:focus-visible {
    outline: 2px solid var(--td-brand-color-focus);
    outline-offset: 3px;
  }
}

.ai-admin-launcher__robot {
  color: var(--td-text-color-anti);
}

@media (width <= 767px) {
  .ai-admin-launcher {
    right: 1rem;
    bottom: calc(1rem + env(safe-area-inset-bottom, 0px));
  }
}

@media (prefers-reduced-motion: reduce) {
  .ai-admin-launcher__ball:hover,
  .ai-admin-launcher__ball:active {
    transform: none;
  }
}
</style>
