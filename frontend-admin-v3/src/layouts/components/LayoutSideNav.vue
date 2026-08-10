<template>
  <l-side-nav
    v-if="settingStore.showSidebar"
    :show-logo="settingStore.showSidebarLogo"
    :layout="settingStore.layout"
    :is-fixed="settingStore.isSidebarFixed"
    :menu="sideMenu"
    :theme="settingStore.displaySideMode"
    :is-compact="settingStore.isSidebarCompact"
  />
</template>
<script setup lang="ts">
import { storeToRefs } from 'pinia';
import { computed } from 'vue';
import { useRoute } from 'vue-router';

import { usePermissionStore, useSettingStore } from '@/store';
import type { MenuRoute } from '@/types/interface';

import LSideNav from './SideNav.vue';

const route = useRoute();
const permissionStore = usePermissionStore();
const settingStore = useSettingStore();
const { routers: menuRouters } = storeToRefs(permissionStore);

const sideMenu = computed(() => {
  const { layout, splitMenu } = settingStore;
  if (layout === 'mix' && splitMenu) {
    const active = menuRouters.value.find((m: MenuRoute) => route.path.startsWith(m.path));
    return (
      active?.children?.map((subMenu: MenuRoute) => ({ ...subMenu, path: `${active.path}/${subMenu.path}` })) ?? []
    );
  }
  return menuRouters.value as Array<MenuRoute>;
});
</script>
