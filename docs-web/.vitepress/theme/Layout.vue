<script setup lang="ts">
import DefaultTheme from "vitepress/theme";
import { useRoute } from "vitepress";
import HomePortal from "./components/HomePortal.vue";

const route = useRoute();
</script>

<template>
  <HomePortal v-if="route.path === '/'" />
  <DefaultTheme.Layout v-else />
</template>
