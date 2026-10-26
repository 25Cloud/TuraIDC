<template>
  <div v-if="canUseAi" class="ai-admin-launcher">
    <button type="button" class="ai-admin-launcher__ball" :aria-label="$t('ai.launcher.label')" @click="handleToggle">
      <robot-icon size="20px" />
      <span class="ai-admin-launcher__text">{{ $t('ai.launcher.label') }}</span>
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

.ai-admin-launcher__ball {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 10px 16px;
  border: none;
  border-radius: 999px;
  font-size: 13px;
  font-weight: 600;
  color: var(--td-text-color-anti, #fff);
  cursor: pointer;
  background: var(--td-brand-color);
  box-shadow: 0 8px 20px rgba(0, 0, 0, 0.16);
  transition:
    transform 0.16s ease,
    box-shadow 0.16s ease;

  &:hover {
    transform: translateY(-1px);
    box-shadow: 0 12px 26px rgba(0, 0, 0, 0.2);
  }

  &:focus-visible {
    outline: 2px solid var(--td-brand-color-focus, rgba(0, 82, 217, 0.2));
    outline-offset: 2px;
  }
}

@media (max-width: 960px) {
  .ai-admin-launcher {
    right: 16px;
    bottom: 24px;
  }

  .ai-admin-launcher__ball {
    padding: 0;
    width: 48px;
    height: 48px;
    justify-content: center;
  }

  .ai-admin-launcher__text {
    display: none;
  }
}
</style>
