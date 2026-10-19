<template>
  <component :is="activeView" />
</template>

<script setup>
import { computed, defineAsyncComponent } from "vue";
import { useRoute } from "vue-router";
import {
  DISPLAY_MODE_CARD,
  useProductDisplayMode,
} from "./useProductDisplayMode";

/**
 * 选购页路由入口：URL 只描述「看哪里」，展示方式只描述「怎么渲染」。
 *
 * 两种形态共用同一套地址：
 *   /products/:typeSlug
 *   /products/:typeSlug/:groupId
 *   /products/:typeSlug/:groupId/:childGroupId
 *   /products/:typeSlug/:groupId/:childGroupId/:productId
 *
 * - 目录地址（1-3 段）：两种形态都由选购页渲染；列表式会自动补全到商品段，
 *   卡片式展示商品卡片网格；
 * - 商品地址（4 段）：列表式在选购页内联配置，卡片式渲染独立配置页——
 *   地址相同，只是渲染方式不同，这样两种形态的用户可以互相分享链接。
 */
const ProductsPage = defineAsyncComponent(() => import("./index.vue"));
const ProductDetailPage = defineAsyncComponent(
  () => import("../ProductDetail/index.vue"),
);

const route = useRoute();
const { displayMode } = useProductDisplayMode();

const activeView = computed(() => {
  const hasProduct = Number(route.params?.productId || 0) > 0;

  return displayMode.value === DISPLAY_MODE_CARD && hasProduct
    ? ProductDetailPage
    : ProductsPage;
});
</script>
