<template>
  <div class="app-runtime-shell">
    <div class="app-route-progress" :class="{ 'is-active': isRouteLoading }" />

    <router-view v-slot="{ Component }">
      <Transition name="route-fade" mode="out-in">
        <component :is="Component" v-if="Component" />
        <RouteLoadingFallback v-else />
      </Transition>
    </router-view>
  </div>
</template>

<script setup>
import RouteLoadingFallback from '@/components/common/RouteLoadingFallback.vue'
import { isRouteLoading } from '@/utils/routeLoading'
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

/* 顶部进度条 */
.app-route-progress {
  position: fixed;
  inset: 0 auto auto 0;
  width: 100%;
  height: 3px;
  opacity: 0;
  pointer-events: none;
  z-index: 4200;
  transition: opacity 0.2s ease-out;
  background: rgba($color-primary, 0.06);
}

.app-route-progress::before {
  content: '';
  display: block;
  width: 28%;
  height: 100%;
  border-radius: 999px;
  background: linear-gradient(
    90deg,
    rgba($color-primary, 0.02),
    $color-primary 40%,
    rgba($color-primary, 0.6)
  );
  box-shadow: 0 0 8px rgba($color-primary, 0.3);
  animation: app-route-progress 1s cubic-bezier(0.4, 0, 0.2, 1) infinite;
}

.app-route-progress.is-active {
  opacity: 1;
}

@keyframes app-route-progress {
  0% {
    transform: translateX(-100%);
    opacity: 0.6;
  }

  50% {
    opacity: 1;
  }

  100% {
    transform: translateX(420%);
    opacity: 0.4;
  }
}
</style>
