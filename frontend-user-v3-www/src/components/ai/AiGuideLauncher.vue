<template>
  <div class="ai-guide-launcher">
    <button
      type="button"
      class="ai-guide-launcher__ball"
      :aria-label="$t('ai.launcher.label')"
      @click="handleToggle"
    >
      <el-icon class="ai-guide-launcher__icon"><ChatDotRound /></el-icon>
      <span class="ai-guide-launcher__text">{{ $t("ai.launcher.label") }}</span>
    </button>

    <AiGuidePanel v-model="panelVisible" :chat="chat" />
  </div>
</template>

<script setup>
import { ChatDotRound } from "@element-plus/icons-vue";
import { useAiChat } from "@turaidc/shared/runtime";
import { ElMessage } from "element-plus/es/components/message/index.mjs";
import { ref } from "vue";

import { aiClient } from "@/api/ai";
import { t } from "@/locales";
import { isLoggedIn } from "@/utils/auth";
import { navigateToConsole } from "@/utils/consoleUrl";

import AiGuidePanel from "./AiGuidePanel.vue";

/**
 * 浮球挂在 App.vue，切路由不销毁，因此会话状态就活在这里。
 *
 * autoLoad: false —— 未登录访客不该在首屏打出 /ai/bootstrap（后端按 401 拒绝，
 * 每页都刷一次错误提示更难看）；只有已登录用户第一次点开时才初始化。
 */
const chat = useAiChat(aiClient, { channel: "site", autoLoad: false });

const panelVisible = ref(false);
let initialized = false;
let redirectTimer = null;

function handleToggle() {
  if (!isLoggedIn()) {
    // 匿名不消耗 token、也不进会话审计：提示后送去控制台登录页
    ElMessage.warning(t("ai.launcher.loginRequired"));
    if (redirectTimer) {
      return;
    }
    // 留一小段可见时间：跨域跳转会立即卸载当前页，提示否则等于没提示
    redirectTimer = window.setTimeout(() => {
      redirectTimer = null;
      navigateToConsole("/client/login");
    }, 600);
    return;
  }

  panelVisible.value = !panelVisible.value;
  if (panelVisible.value && !initialized) {
    initialized = true;
    void chat.initialize();
  }
}
</script>

<style scoped lang="scss">
.ai-guide-launcher {
  position: fixed;
  right: 24px;
  bottom: 28px;
  /* 浮球层级夹在 Element 全局弹层（3200）与启动骨架（9999）之间，面板再高一层 */
  z-index: 3300;
}

.ai-guide-launcher__ball {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 10px 16px;
  border: none;
  border-radius: 999px;
  font-size: 13px;
  font-weight: 600;
  line-height: 1;
  color: #fff;
  cursor: pointer;
  background: var(--el-color-primary);
  box-shadow: 0 10px 24px rgba(15, 23, 42, 0.18);
  transition:
    transform 0.16s ease,
    box-shadow 0.16s ease;

  &:hover {
    transform: translateY(-1px);
    box-shadow: 0 14px 30px rgba(15, 23, 42, 0.22);
  }

  &:focus-visible {
    outline: 2px solid var(--el-color-primary-light-5, #c9dbff);
    outline-offset: 2px;
  }
}

.ai-guide-launcher__icon {
  font-size: 16px;
}

@media (max-width: 767px) {
  .ai-guide-launcher {
    right: 16px;
    bottom: calc(16px + env(safe-area-inset-bottom, 0px));
  }

  .ai-guide-launcher__ball {
    width: 52px;
    height: 52px;
    padding: 0;
    justify-content: center;
  }

  .ai-guide-launcher__text {
    display: none;
  }

  .ai-guide-launcher__icon {
    font-size: 22px;
  }
}
</style>
