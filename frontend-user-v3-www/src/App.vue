<template>
  <el-config-provider :locale="elementLocale">
    <div class="app-runtime-shell">
      <!-- 路由切换不再显示顶部进度条：所有加载态统一由骨架承担，
         首屏/布局未就绪时用同形页面骨架占位，避免出现「另一种加载动画」 -->
      <router-view v-slot="{ Component }">
        <Transition name="route-fade" mode="out-in">
          <component :is="Component" v-if="Component" />
          <RouteLoadingFallback v-else with-header />
        </Transition>
      </router-view>
    </div>
  </el-config-provider>
</template>

<script setup>
import { computed } from "vue";

import RouteLoadingFallback from "@/components/common/RouteLoadingFallback.vue";
import { useLocale } from "@/locales/useLocale";

const { getComponentsLocale } = useLocale();
const elementLocale = computed(() => getComponentsLocale.value);
</script>

<style scoped lang="scss">
.app-runtime-shell {
  min-height: 100vh;
}

/* 路由切换淡入过渡 */
.route-fade-enter-active {
  transition: opacity 0.22s ease-out;
}

.route-fade-leave-active {
  transition: opacity 0.12s ease-in;
}

.route-fade-enter-from,
.route-fade-leave-to {
  opacity: 0;
}
</style>

<style lang="scss">
/* 全站统一骨架 token 与扫光节奏（.tura-skeleton）：只在这里引入一次 */
@use "@/components/common/skeleton.scss";
</style>
