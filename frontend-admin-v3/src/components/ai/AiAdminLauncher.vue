<template>
  <div v-if="canUseAi" class="ai-admin-launcher">
    <button
      type="button"
      class="ai-admin-launcher__ball"
      :aria-label="$t('ai.launcher.label')"
      :title="$t('ai.launcher.label')"
      :aria-expanded="panelVisible"
      @click="handleToggle"
    >
      <robot-icon class="ai-admin-launcher__icon" />
    </button>

    <ai-admin-panel v-model="panelVisible" :chat="chat" />
  </div>
</template>
<script setup lang="ts">
import { useAiChat } from '@turaidc/shared/runtime';
import { RobotIcon } from 'tdesign-icons-vue-next';
import { computed, ref } from 'vue';

import { aiClient } from '@/api/ai';
import { hasPermissionInList } from '@/constants/permissions';
import { useUserStore } from '@/store';

import AiAdminPanel from './AiAdminPanel.vue';

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
  right: 24px;
  bottom: 32px;
  z-index: 100;
}

/* 只留图标：48px 圆形悬浮球，hover 微放大给一点按压反馈 */
.ai-admin-launcher__ball {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 48px;
  height: 48px;
  padding: 0;
  border: none;
  border-radius: 50%;
  color: var(--td-text-color-anti);
  cursor: pointer;
  background: var(--td-brand-color);
  box-shadow: var(--td-shadow-2);
  transition:
    transform 0.16s ease,
    box-shadow 0.16s ease;

  &:hover {
    transform: scale(1.06);
    box-shadow: var(--td-shadow-3);
  }

  &:active {
    transform: scale(0.98);
  }

  &:focus-visible {
    outline: 2px solid var(--td-brand-color-focus);
    outline-offset: 2px;
  }
}

.ai-admin-launcher__icon {
  width: 24px;
  height: 24px;
}

@media (width <= 960px) {
  .ai-admin-launcher {
    right: 16px;
    bottom: 24px;
  }
}

@media (prefers-reduced-motion: reduce) {
  .ai-admin-launcher__ball:hover,
  .ai-admin-launcher__ball:active {
    transform: none;
  }
}
</style>
