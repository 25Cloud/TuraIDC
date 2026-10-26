<template>
  <t-config-provider :global-config="getComponentsLocale">
    <router-view :key="locale" :class="[mode]" />
    <!-- 兜底层：/client/login 等游客页不经过 layouts/index.vue，这里补一份 AI 入口。
         控制台内页已由布局层挂载，靠 showAiEntry 排除，不会出现两份浮球。 -->
    <template v-if="showAiEntry">
      <ai-assistant-launcher />
      <ai-chat-drawer />
    </template>
  </t-config-provider>
</template>
<script setup lang="ts">
import { computed } from 'vue';
import { useRoute } from 'vue-router';

import AiAssistantLauncher from '@/components/ai/AiAssistantLauncher.vue';
import AiChatDrawer from '@/components/ai/AiChatDrawer.vue';
import { useLocale } from '@/locales/useLocale';
import { useSettingStore } from '@/store';

const route = useRoute();
const store = useSettingStore();

const mode = computed(() => {
  return store.displayMode;
});

const { getComponentsLocale, locale } = useLocale();

// 凡是要登录的控制台页面都带 requireAuth，由 layouts/index.vue 提供 AI 入口；
// 其余（登录、注册、找回密码、代登录、兜底重定向）在这里补一层。
const showAiEntry = computed(() => !route.matched.some((record) => record.meta?.requireAuth === true));
</script>
<style lang="less" scoped>
#nprogress .bar {
  background: var(--td-brand-color) !important;
}
</style>
