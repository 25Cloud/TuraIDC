<script setup lang="ts">
import DefaultTheme from "vitepress/theme";
import { useRoute } from "vitepress";
import HomePortal from "./components/HomePortal.vue";
import {
  NolebaseEnhancedReadabilitiesMenu,
  NolebaseEnhancedReadabilitiesScreenMenu,
} from "@nolebase/vitepress-plugin-enhanced-readabilities";
import { NolebaseHighlightTargetedHeading } from "@nolebase/vitepress-plugin-highlight-targeted-heading";

const route = useRoute();
</script>

<template>
  <HomePortal v-if="route.path === '/'" />
  <DefaultTheme.Layout v-else>
    <!-- 导航栏右上角增强阅读面板（布局切换与聚光灯） -->
    <template #nav-bar-content-after>
      <NolebaseEnhancedReadabilitiesMenu />
    </template>
    <!-- 窄屏导航抽屉中的增强阅读菜单 -->
    <template #nav-screen-content-after>
      <NolebaseEnhancedReadabilitiesScreenMenu />
    </template>
    <!-- URL 锚点跳转时的目标标题高亮 -->
    <template #layout-top>
      <NolebaseHighlightTargetedHeading />
    </template>
  </DefaultTheme.Layout>
</template>
