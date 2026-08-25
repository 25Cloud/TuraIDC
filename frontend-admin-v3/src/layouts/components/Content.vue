<template>
  <div v-if="!isRefreshing">
    <router-view v-if="!isFramePage" v-slot="{ Component }">
      <transition name="fade" mode="out-in">
        <!-- key 用 fullPath：同组件多路由（如商品/流量包/上游共用 products/index.vue）
             各自独立缓存；同一路由不同 query 也各自保留状态。
             注释必须放在 keep-alive 外：dev 编译保留注释节点，会触发
             「<KeepAlive> expects exactly one child component」编译错误，
             整个管理端 dev 模式与全部 e2e 因此无法运行（生产构建剥离注释所以 build 一直是绿的）。 -->
        <keep-alive :include="aliveViews">
          <component :is="Component" :key="route.fullPath" />
        </keep-alive>
      </transition>
    </router-view>
    <frame-page v-else />
  </div>

  <t-loading v-else />
</template>
<script setup lang="ts">
import { isBoolean, isUndefined } from 'lodash-es';
import type { ComputedRef } from 'vue';
import { computed } from 'vue';
import { useRoute } from 'vue-router';

import FramePage from '@/layouts/frame/index.vue';
import { useTabsRouterStore } from '@/store';

// <suspense>标签属于实验性功能，请谨慎使用
// 如果存在需解决/page/1=> /page/2 刷新数据问题 请修改代码 使用activeRouteFullPath 作为key
// <suspense>
//  <component :is="Component" :key="activeRouteFullPath" />
// </suspense>

// import { useRouter } from 'vue-router';
// const activeRouteFullPath = computed(() => {
//   const router = useRouter();
//   return router.currentRoute.value.fullPath;
// });

const aliveViews = computed(() => {
  const tabsRouterStore = useTabsRouterStore();
  const { tabRouters } = tabsRouterStore;
  return (
    tabRouters
      .filter((route) => {
        const keepAliveConfig = route.meta?.keepAlive;
        const isRouteKeepAlive = isUndefined(keepAliveConfig) || (isBoolean(keepAliveConfig) && keepAliveConfig); // 默认开启keepalive
        return route.isAlive && isRouteKeepAlive;
      })
      // keep-alive 的 include 匹配的是「组件自身 name」（defineOptions 或文件名推断），
      // 不是路由 name。路由 meta 里显式声明 keepAliveName 时优先使用它，
      // 否则回退路由 name——因此各页面组件的 defineOptions name 必须与路由 name 保持一致。
      .map((route) => (route.meta?.keepAliveName as string | undefined) ?? route.name)
      .filter((name): name is string => Boolean(name))
  );
}) as ComputedRef<string[]>;

const isRefreshing = computed(() => {
  const tabsRouterStore = useTabsRouterStore();
  const { refreshing } = tabsRouterStore;
  return refreshing;
});

const route = useRoute(); // 这个不能放到computed中，切换页面时会导致被缓存
const isFramePage = computed(() => {
  return !!route.meta?.frameSrc;
});
</script>
<style lang="less" scoped>
.fade-leave-active,
.fade-enter-active {
  transition: opacity @anim-duration-slow @anim-time-fn-easing;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>
