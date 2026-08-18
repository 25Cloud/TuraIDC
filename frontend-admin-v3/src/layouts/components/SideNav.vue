<template>
  <template v-if="settingStore.showSidebar">
    <div
      v-if="isMobile && settingStore.isMobileSidebarVisible"
      :class="`${prefix}-side-nav-mask`"
      @click="closeMobileSidebar"
    ></div>
    <nav :class="sideNavCls" :aria-label="t('common.appName')">
      <t-menu
        :class="menuCls"
        :theme="theme"
        :value="active"
        :collapsed="collapsed"
        :expanded="expanded"
        :expand-mutex="true"
        @change="handleMenuChange"
        @expand="onExpanded"
      >
        <template #logo>
          <button
            v-if="showLogo"
            :class="`${prefix}-side-nav-logo-wrapper`"
            type="button"
            :aria-label="t('common.appName')"
            @click="goHome"
          >
            <component :is="getLogo()" :class="logoCls" />
          </button>
        </template>
        <menu-content :nav-data="menu" />
        <template #operations>
          <span :class="versionCls">
            <span v-if="!collapsed">{{ t('common.appName') }}</span>
            <span>v{{ pgk.version }}</span>
          </span>
        </template>
      </t-menu>
      <div :class="`${prefix}-side-nav-placeholder${collapsed ? '-hidden' : ''}`"></div>
    </nav>
  </template>
</template>
<script setup lang="ts">
import type { MenuValue } from 'tdesign-vue-next';
import type { PropType } from 'vue';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';

import AssetLogoFull from '@/assets/assets-logo-full.svg?component';
import AssetLogo from '@/assets/assets-t-logo.svg?component';
import { prefix } from '@/config/global';
import { t } from '@/locales';
import { getActive } from '@/router';
import { useSettingStore } from '@/store';
import type { MenuRoute, ModeType } from '@/types/interface';

import pgk from '../../../package.json';
import MenuContent from './MenuContent.vue';

const { menu, showLogo, isFixed, layout, theme, isCompact } = defineProps({
  menu: {
    type: Array as PropType<MenuRoute[]>,
    default: () => [],
  },
  showLogo: {
    type: Boolean as PropType<boolean>,
    default: true,
  },
  isFixed: {
    type: Boolean as PropType<boolean>,
    default: true,
  },
  layout: {
    type: String as PropType<string>,
    default: '',
  },
  headerHeight: {
    type: String as PropType<string>,
    default: '64px',
  },
  theme: {
    type: String as PropType<ModeType>,
    default: 'light',
  },
  isCompact: {
    type: Boolean as PropType<boolean>,
    default: false,
  },
});

const MIN_POINT = 992 - 1;
const MOBILE_POINT = 768;

const router = useRouter();
const settingStore = useSettingStore();
const isMobile = ref(false);

const collapsed = computed(() => (isMobile.value ? false : settingStore.isSidebarCompact));

const active = computed(() => getActive());

const expanded = ref<MenuValue[]>([]);

const getExpanded = () => {
  const path = getActive();
  // 优先精确匹配菜单叶子（跨分组同级前缀路由如 /admin/products 与
  // /admin/products/suppliers 不会互相误展开）；无精确项（隐藏路由）时回退前缀匹配。
  const result = findExactMenuBranch(menu as MenuRoute[], path) || findExpandedByMenu(menu as MenuRoute[], path) || fallbackExpanded(path);

  expanded.value = result;
};

watch(
  () => active.value,
  () => {
    getExpanded();
  },
);

const onExpanded = (value: MenuValue[]) => {
  const openedMenus = value.filter((item) => !hasMenuValue(expanded.value, item));
  const latestOpenedMenu = openedMenus.at(-1);

  if (latestOpenedMenu) {
    expanded.value = findExpandedMenuBranch(menu as MenuRoute[], latestOpenedMenu) || [latestOpenedMenu];
    return;
  }

  expanded.value = removeClosedMenuDescendants(menu as MenuRoute[], value, expanded.value);
};

const sideMode = computed(() => {
  return theme === 'dark';
});
const sideNavCls = computed(() => {
  return [
    `${prefix}-sidebar-layout`,
    {
      [`${prefix}-sidebar-compact`]: isCompact,
      [`${prefix}-sidebar-mobile`]: isMobile.value,
      [`${prefix}-sidebar-mobile-open`]: isMobile.value && settingStore.isMobileSidebarVisible,
    },
  ];
});
const logoCls = computed(() => {
  return [
    `${prefix}-side-nav-logo-${collapsed.value ? 't' : 'tdesign'}-logo`,
    {
      [`${prefix}-side-nav-dark`]: sideMode.value,
    },
  ];
});
const versionCls = computed(() => {
  return [
    `${prefix}-side-nav-version`,
    {
      [`${prefix}-side-nav-dark`]: sideMode.value,
    },
  ];
});
const menuCls = computed(() => {
  return [
    `${prefix}-side-nav`,
    {
      [`${prefix}-side-nav-no-logo`]: !showLogo,
      [`${prefix}-side-nav-no-fixed`]: !isFixed,
      [`${prefix}-side-nav-mix-fixed`]: layout === 'mix' && isFixed,
    },
  ];
});

const autoCollapsed = () => {
  const mobile = window.innerWidth <= MOBILE_POINT;
  const isCompact = !mobile && window.innerWidth <= MIN_POINT;

  isMobile.value = mobile;
  settingStore.updateConfig({
    isSidebarCompact: isCompact,
    isMobileSidebarVisible: mobile ? settingStore.isMobileSidebarVisible : false,
  });
};

onMounted(() => {
  getExpanded();
  autoCollapsed();

  window.addEventListener('resize', autoCollapsed);
});

onUnmounted(() => {
  window.removeEventListener('resize', autoCollapsed);
});

const goHome = () => {
  closeMobileSidebar();
  router.push('/admin/dashboard');
};

const getLogo = () => {
  if (collapsed.value) return AssetLogo;
  return AssetLogoFull;
};

/**
 * 精确匹配：返回 activePath 对应菜单叶子（path 完全相等）的祖先链。
 * 与 findExpandedByMenu 的前缀匹配不同，避免同级前缀路由（如
 * /admin/products 与 /admin/products/suppliers）互相误展开。
 */
function findExactMenuBranch(list: MenuRoute[], activePath: string, parents: MenuValue[] = []): MenuValue[] | null {
  for (const item of list || []) {
    const currentPath = String(item.path);
    if (currentPath === activePath) {
      return parents;
    }

    const childResult = findExactMenuBranch(item.children || [], activePath, [...parents, currentPath]);
    if (childResult) return childResult;
  }

  return null;
}

function findExpandedByMenu(list: MenuRoute[], activePath: string, parents: MenuValue[] = []): MenuValue[] | null {
  for (const item of list || []) {
    const currentPath = String(item.path);
    if (isRouteMatch(currentPath, activePath)) return parents;

    const childResult = findExpandedByMenu(item.children || [], activePath, [...parents, currentPath]);
    if (childResult) return childResult;
  }

  return null;
}

function isRouteMatch(routePath: string, activePath: string) {
  return activePath === routePath || activePath.startsWith(`${routePath}/`);
}

function fallbackExpanded(path: string) {
  const parts = path.split('/').slice(1);
  return parts.map((_, index) => `/${parts.slice(0, index + 1).join('/')}`);
}

function findExpandedMenuBranch(list: MenuRoute[], target: MenuValue, parents: MenuValue[] = []): MenuValue[] | null {
  for (const item of list || []) {
    const currentPath = String(item.path);
    const branch = [...parents, currentPath];

    if (isSameMenuValue(currentPath, target)) return branch;

    const childResult = findExpandedMenuBranch(item.children || [], target, branch);
    if (childResult) return childResult;
  }

  return null;
}

function removeClosedMenuDescendants(list: MenuRoute[], nextExpanded: MenuValue[], previousExpanded: MenuValue[]) {
  const closedMenus = previousExpanded.filter((item) => !hasMenuValue(nextExpanded, item));

  if (!closedMenus.length) return nextExpanded;

  return nextExpanded.filter(
    (item) => !closedMenus.some((closedItem) => isDescendantMenuValue(list, item, closedItem)),
  );
}

function isDescendantMenuValue(list: MenuRoute[], target: MenuValue, ancestor: MenuValue) {
  const branch = findExpandedMenuBranch(list, target) || [];
  return branch.slice(0, -1).some((item) => isSameMenuValue(item, ancestor));
}

function hasMenuValue(list: MenuValue[], target: MenuValue) {
  return list.some((item) => isSameMenuValue(item, target));
}

function isSameMenuValue(left: MenuValue, right: MenuValue) {
  return String(left) === String(right);
}

function closeMobileSidebar() {
  if (!isMobile.value || !settingStore.isMobileSidebarVisible) return;
  settingStore.updateConfig({
    isMobileSidebarVisible: false,
  });
}

function handleMenuChange() {
  closeMobileSidebar();
}
</script>
<style lang="less" scoped></style>
