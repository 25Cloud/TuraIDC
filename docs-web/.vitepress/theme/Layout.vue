<script setup lang="ts">
import DefaultTheme from "vitepress/theme";
import { computed } from "vue";
import { useData, useRoute } from "vitepress";
import HomePortal from "./components/HomePortal.vue";
import {
  NolebaseEnhancedReadabilitiesMenu,
  NolebaseEnhancedReadabilitiesScreenMenu,
} from "@nolebase/vitepress-plugin-enhanced-readabilities";
import { NolebaseHighlightTargetedHeading } from "@nolebase/vitepress-plugin-highlight-targeted-heading";

const route = useRoute();
const { site } = useData();

// 每种语言的首屏都由 HomePortal 承接：语言首页在站点里就是各自 locale 的根路径
// （/、/en/、/zh-tw/、/zh-hk/），这里按 locale 的 link 判断，避免多语言后漏掉
// 某一个语言的首页，让它掉回默认文档布局。
const homePaths = computed(
  () =>
    new Set(
      Object.values(site.value.locales).map((locale) => {
        const link = (locale.link || "/").replace(/\/$/, "");
        return link === "" ? "/" : link;
      }),
    ),
);

const isHome = computed(() => {
  const path = route.path.replace(/\/$/, "") || "/";
  return homePaths.value.has(path);
});
</script>

<template>
  <HomePortal v-if="isHome" />
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
