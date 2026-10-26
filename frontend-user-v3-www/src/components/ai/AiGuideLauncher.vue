<template>
  <div class="ai-guide-launcher">
    <!-- 只留一个圆形机器人头像按钮：文字在窄视口上挤成一团，也没有任何信息增量 -->
    <button
      type="button"
      class="ai-guide-launcher__ball"
      data-ai-launcher
      :aria-label="$t('ai.launcher.label')"
      :aria-expanded="panelVisible ? 'true' : 'false'"
      @click="handleToggle"
    >
      <el-icon class="ai-guide-launcher__icon"><Service /></el-icon>
    </button>

    <AiGuidePanel v-model="panelVisible" :chat="chat" />
  </div>
</template>

<script setup>
import { Service } from "@element-plus/icons-vue";
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
  /* 浮球层级夹在 Element 全局弹层（3200）与启动骨架（9999）之间，面板与它同层 */
  z-index: 3300;
}

.ai-guide-launcher__ball {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 48px;
  height: 48px;
  padding: 0;
  border: none;
  border-radius: 50%;
  color: var(--el-color-white);
  cursor: pointer;
  /* 左下角品牌色 → 右上角浅一档的品牌色，比纯色更有体积感 */
  background-image: linear-gradient(
    to top right,
    var(--el-color-primary),
    var(--el-color-primary-light-3)
  );
  box-shadow: var(--el-box-shadow);
  transition:
    transform 0.16s ease,
    box-shadow 0.16s ease;

  &:hover {
    transform: scale(1.06);
    box-shadow: var(--el-box-shadow-dark);
  }

  &:active {
    transform: scale(0.98);
  }

  &:focus-visible {
    outline: 2px solid var(--el-color-primary-light-5);
    outline-offset: 2px;
  }
}

.ai-guide-launcher__icon {
  font-size: 24px;
}

@media (max-width: 767px) {
  .ai-guide-launcher {
    right: 16px;
    /* 全面屏手机底部有手势条，浮球必须避让安全区 */
    bottom: calc(16px + env(safe-area-inset-bottom, 0px));
  }
}
</style>
