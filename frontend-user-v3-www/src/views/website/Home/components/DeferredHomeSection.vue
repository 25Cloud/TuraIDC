<template>
  <Transition name="home-section-reveal" appear>
    <component
      :is="resolvedComponent"
      v-if="resolvedComponent"
      v-bind="sectionProps"
      class="home-deferred-section"
    />
    <HomeSectionSkeleton
      v-else
      :type="skeletonType"
      class="home-deferred-section"
    />
  </Transition>
</template>

<script setup>
/**
 * 首页延迟区块的「chunk 加载骨架」。
 *
 * 之前用 defineAsyncComponent 直接挂载：首屏后滚动到解决方案/合作伙伴/新闻/注册条时，
 * 异步 chunk 还在下载，区块位置是纯空白（骨架已经被 v-if 切走），慢网下像页面缺了一块。
 * 现在 chunk 解析完成前继续显示对应形状的骨架，加载完成后再替换，高度不跳。
 *
 * 外面包一层 appear 过渡：区块就位时做一次 fade-up 入场（尊重系统减弱动效偏好，
 * 关闭逻辑见 global.scss 的 prefers-reduced-motion），让「骨架 → 真区块」的替换
 * 不再是硬切。
 */
import { onBeforeUnmount, shallowRef, watch } from "vue";
import HomeSectionSkeleton from "./HomeSectionSkeleton.vue";

const props = defineProps({
  // 必须是稳定引用（模块级函数），否则每次渲染都会被当成新 loader 重新解析
  loader: {
    type: Function,
    required: true,
  },
  skeletonType: {
    type: String,
    default: "products",
  },
  // 透传给真实区块的 props（如 HomeNewsSection 的 notices）
  sectionProps: {
    type: Object,
    default: () => ({}),
  },
});

const resolvedComponent = shallowRef(null);
let cancelled = false;

async function resolveSection() {
  try {
    const module = await props.loader();
    if (cancelled) {
      return;
    }
    resolvedComponent.value = module?.default || module;
  } catch {
    // chunk 下载失败时保留骨架，避免白屏；刷新页面可重试（构建产物带 hash，无需自愈重试）
  }
}

watch(() => props.loader, resolveSection, { immediate: true });

onBeforeUnmount(() => {
  cancelled = true;
});
</script>

<style scoped lang="scss">
.home-deferred-section {
  will-change: transform, opacity;
}

.home-section-reveal-enter-active {
  transition:
    opacity 0.5s ease,
    transform 0.5s cubic-bezier(0.22, 1, 0.36, 1);
}

.home-section-reveal-enter-from {
  opacity: 0;
  transform: translateY(28px);
}
</style>
