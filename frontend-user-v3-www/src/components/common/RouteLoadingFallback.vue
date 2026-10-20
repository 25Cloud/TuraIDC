<template>
  <PageSkeleton :variant="resolvedVariant" :with-header="withHeader" />
</template>

<script setup>
/**
 * 路由加载 fallback。
 *
 * 之前这里是一套「通用 Hero + 三张卡」的骨架，和真实页面结构对不上：
 * 进入内容页/详情页时会先看到一块与最终布局无关的占位，切换时整页跳一下。
 * 现在按当前路由推导变体，交给 PageSkeleton 画与真实页面同形的骨架，
 * 页面组件（或布局组件）挂载后直接替换，不出现空白和高度跳动。
 */
import { computed } from "vue";
import { useRoute } from "vue-router";
import PageSkeleton from "@/components/common/PageSkeleton.vue";

const props = defineProps({
  // 显式指定时优先使用（例如首页以外的聚合页），留空按路由推导
  variant: {
    type: String,
    default: "",
  },
  // 整页首次加载：布局组件尚未挂载，需要补顶栏骨架
  withHeader: {
    type: Boolean,
    default: false,
  },
});

const route = useRoute();

function resolveSkeletonVariant(path) {
  const normalized = String(path || "/");

  if (normalized === "/" || normalized === "") {
    return "home";
  }

  if (/^\/(notices|help)\/\d+/.test(normalized)) {
    return "content-detail";
  }

  if (normalized === "/notices" || normalized === "/help") {
    return "content-list";
  }

  return "generic";
}

const resolvedVariant = computed(
  () => props.variant || resolveSkeletonVariant(route.path),
);
</script>
