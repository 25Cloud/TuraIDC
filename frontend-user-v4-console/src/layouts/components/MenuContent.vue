<template>
  <div>
    <template v-for="item in list" :key="item.path">
      <template v-if="!item.children || !item.children.length || item.meta?.single">
        <t-menu-item v-if="getHref(item)" :name="item.path" :value="getPath(item)" @click="openHref(getHref(item)[0])">
          <template v-if="shouldShowIcon(item)" #icon>
            <component :is="menuIcon(item)" class="t-icon"></component>
          </template>
          {{ renderMenuTitle(item.title) }}
        </t-menu-item>
        <t-menu-item v-else :name="item.path" :value="getPath(item)" :to="item.path">
          <template v-if="shouldShowIcon(item)" #icon>
            <component :is="menuIcon(item)" class="t-icon"></component>
          </template>
          {{ renderMenuTitle(item.title) }}
          <span v-if="getMenuBadge(item)" class="menu-badge-dot">{{ getMenuBadge(item) }}</span>
        </t-menu-item>
      </template>
      <t-submenu v-else :name="item.path" :value="item.path" :title="renderMenuTitle(item.title)">
        <template v-if="shouldShowIcon(item)" #icon>
          <component :is="menuIcon(item)" class="t-icon"></component>
        </template>
        <menu-content v-if="item.children" :nav-data="item.children" :level="props.level + 1" />
      </t-submenu>
    </template>
  </div>
</template>
<script setup lang="tsx">
import { computed } from 'vue';

import { useNoticeReadStatus } from '@/domains/content/useNoticeReadStatus';
import type { LocalizedTitle } from '@/locales';
import { useLocale } from '@/locales/useLocale';
import { getActive } from '@/router';
import type { MenuRoute } from '@/types/interface';

type ListItemType = MenuRoute;

const props = withDefaults(
  defineProps<{
    navData?: MenuRoute[];
    level?: number;
  }>(),
  {
    navData: () => [],
    level: 0,
  },
);

const active = computed(() => getActive());

const { locale } = useLocale();

const list = computed(() => {
  return getMenuList(props.navData);
});

const menuIcon = (item: ListItemType) => {
  if (typeof item.icon === 'string') return <t-icon name={item.icon} />;
  const RenderIcon = item.icon;
  return RenderIcon;
};

const shouldShowIcon = (item: ListItemType) => {
  return props.level === 0 && !!item.icon;
};

const renderMenuTitle = (title?: LocalizedTitle) => {
  if (!title) return '';
  return title[locale.value as keyof LocalizedTitle] || '';
};

function getMenuList(list: MenuRoute[], basePath?: string): MenuRoute[] {
  if (!list || list.length === 0) {
    return [];
  }
  // 如果meta中有orderNo则按照从小到大排序
  list.sort((a, b) => {
    return (a.meta?.orderNo || 0) - (b.meta?.orderNo || 0);
  });
  return list
    .map((item) => {
      const rawPath = String(item.path);
      const path = rawPath.startsWith('/')
        ? rawPath
        : basePath && !rawPath.includes(basePath)
          ? `${basePath}/${rawPath}`
          : rawPath;

      return {
        path,
        title: item.meta?.title as LocalizedTitle | undefined,
        icon: item.meta?.icon,
        children: getMenuList(item.children, path),
        meta: item.meta,
        redirect: item.redirect,
      } as MenuRoute;
    })
    .filter((item) => item.meta && item.meta.hidden !== true);
}

const getHref = (item: MenuRoute) => {
  const { frameSrc, frameBlank } = item.meta;
  if (frameSrc && frameBlank) {
    return frameSrc.match(/(https?):\/\/([\w.-]+)(?:\/\S*)?/);
  }
  return null;
};

const getPath = (item: ListItemType) => {
  const activeLevel = active.value.split('/').length;
  const pathLevel = item.path.split('/').length;
  if (activeLevel > pathLevel && active.value.startsWith(item.path)) {
    return active.value;
  }

  if (active.value === item.path) {
    return active.value;
  }

  return item.meta?.single ? item.redirect : item.path;
};

const openHref = (url: string) => {
  window.open(url, '_blank', 'noopener,noreferrer');
};

const { unreadCount } = useNoticeReadStatus();
const getMenuBadge = (item: ListItemType): number => {
  if (item.path === '/client/notices' && unreadCount.value > 0) return unreadCount.value;
  return 0;
};
</script>
<style scoped lang="less">
.menu-badge-dot {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 16px;
  height: 16px;
  padding: 0 4px;
  margin-left: auto;
  color: #fff;
  background: var(--td-error-color);
  border-radius: 8px;
  font-size: 10px;
  line-height: 1;
}
</style>
