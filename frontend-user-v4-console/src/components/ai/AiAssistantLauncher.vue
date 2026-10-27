<template>
  <button
    v-if="visible"
    type="button"
    class="ai-assistant-launcher"
    :aria-label="$t('ai.launcherLabel')"
    :title="$t('ai.launcherLabel')"
    @click="handleOpen"
  >
    <ai-robot-avatar :size="26" class="ai-assistant-launcher__robot" />
  </button>
</template>
<script setup lang="ts">
import { computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';

import { getClientToken } from '@/app/runtime/session';
import AiRobotAvatar from '@/components/ai/AiRobotAvatar.vue';
import { useAiStore } from '@/store/modules/ai';

/** 独立页的路由名，页面上不再叠浮球 */
const AI_PAGE_ROUTE = 'ClientAi';

const route = useRoute();
const store = useAiStore();

/**
 * 不渲染的情况：
 * 1. 没有登录 token —— 登录页 / 已登出时不出现（bootstrap 的 enabled 是上一个账号留下的，不可靠）；
 * 2. bootstrap.enabled=false —— 站点没开 AI 网关，入口整块都不出；
 * 3. 浮窗已打开 —— 抽屉遮罩已经盖住浮球，留着只是多一个点不到的元素；
 * 4. 已在独立页 —— 页面本身就是完整对话区，再叠浮球会挡住输入框。
 */
const visible = computed(
  () => Boolean(getClientToken()) && store.enabled && !store.open && route.name !== AI_PAGE_ROUTE,
);

onMounted(() => {
  // 首次挂载按需拉 bootstrap；未登录时 ensureReady 内部直接跳过，不发请求
  void store.ensureReady();
});

const handleOpen = (): void => {
  store.openDrawer();
};
</script>
<style scoped lang="less">
.ai-assistant-launcher {
  position: fixed;
  right: var(--td-comp-margin-xxl);
  bottom: calc(var(--td-comp-margin-xxl) + env(safe-area-inset-bottom, 0px));
  // 低于启动骨架、高于页面内容：抽屉面板取 3400，浮球取 3300
  z-index: 3300;
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
  // 品牌色做投影、再叠一层中性阴影：柔和但能压住深色背景
  box-shadow:
    0 12px 26px -14px var(--td-brand-color),
    var(--td-shadow-2);
  transition:
    transform @anim-duration-base @anim-time-fn-easing,
    box-shadow @anim-duration-base @anim-time-fn-easing,
    filter @anim-duration-base @anim-time-fn-easing;

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

.ai-assistant-launcher__robot {
  color: var(--td-text-color-anti);
}

@media (max-width: @screen-sm-rem) {
  .ai-assistant-launcher {
    right: var(--td-comp-margin-l);
    bottom: calc(var(--td-comp-margin-l) + env(safe-area-inset-bottom, 0px));
  }
}
</style>
