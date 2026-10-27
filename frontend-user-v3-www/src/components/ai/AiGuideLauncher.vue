<template>
  <div class="ai-guide-launcher">
    <!-- 只留一个圆形机器人头按钮：文字在窄视口上挤成一团，也没有任何信息增量。
         面板展开时隐藏浮球——面板就落在同一个右下角，两个入口叠着只会互相盖住。 -->
    <button
      v-show="!panelVisible"
      type="button"
      class="ai-guide-launcher__ball"
      data-ai-launcher
      :aria-label="$t('ai.launcher.label')"
      :aria-expanded="panelVisible ? 'true' : 'false'"
      @click="handleToggle"
    >
      <AiRobotAvatar :size="26" class="ai-guide-launcher__robot" />
    </button>

    <AiGuidePanel v-model="panelVisible" :chat="chat" />
  </div>
</template>

<script setup>
import { useAiChat } from "@turaidc/shared/runtime";
import { ElMessage } from "element-plus/es/components/message/index.mjs";
import { ref } from "vue";

import { aiClient } from "@/api/ai";
import { t } from "@/locales";
import { isLoggedIn } from "@/utils/auth";
import { navigateToConsole } from "@/utils/consoleUrl";

import AiGuidePanel from "./AiGuidePanel.vue";
import AiRobotAvatar from "./AiRobotAvatar.vue";

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
  bottom: calc(24px + env(safe-area-inset-bottom, 0px));
  /* 浮球层级夹在 Element 全局弹层（3200）与启动骨架（9999）之间，面板与它同层 */
  z-index: 3300;
}

/*
 * 尺寸与三端统一：直径 3.25rem（52px），品牌色做径向渐变，
 * 中性阴影 + 品牌色投影；hover 上浮 2px，不做缩放。
 */
.ai-guide-launcher__ball {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 52px;
  height: 52px;
  padding: 0;
  color: var(--td-text-color-anti, #fff);
  cursor: pointer;
  background: radial-gradient(
    circle at 32% 26%,
    /* 官网没有 --td-* 变量：回退到 Element Plus 确实存在的三个品牌色档位 */
    var(--td-brand-color-hover, var(--el-color-primary-light-3)),
    var(--td-brand-color, var(--el-color-primary)) 58%,
    var(--td-brand-color-active, var(--el-color-primary-dark-2))
  );
  border: 0;
  border-radius: 50%;
  box-shadow:
    0 12px 26px -14px var(--td-brand-color, var(--el-color-primary)),
    var(--td-shadow-2, 0 6px 16px rgba(15, 23, 42, 0.16));
  transition:
    transform 0.16s ease,
    box-shadow 0.16s ease;

  &:hover {
    transform: translateY(-2px);
    box-shadow:
      0 16px 30px -14px var(--td-brand-color, var(--el-color-primary)),
      var(--td-shadow-2, 0 6px 16px rgba(15, 23, 42, 0.16));
  }

  &:active {
    transform: translateY(0);
  }

  &:focus-visible {
    outline: 2px solid var(--td-brand-color-focus, var(--el-color-primary-light-5));
    outline-offset: 3px;
  }
}

.ai-guide-launcher__robot {
  color: var(--td-text-color-anti, #fff);
}

@media (max-width: 767px) {
  .ai-guide-launcher {
    right: 16px;
    /* 全面屏手机底部有手势条，浮球必须避让安全区 */
    bottom: calc(16px + env(safe-area-inset-bottom, 0px));
  }
}

@media (prefers-reduced-motion: reduce) {
  .ai-guide-launcher__ball:hover,
  .ai-guide-launcher__ball:active {
    transform: none;
  }
}
</style>
