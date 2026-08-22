<template>
  <div :class="layoutCls">
    <t-head-menu :class="menuCls" :theme="menuTheme" expand-type="popup" :value="active">
      <template #logo>
        <span v-if="showLogo" class="header-logo-container" @click="handleNav('/client/dashboard')">
          <span class="header-logo-mark">{{ siteBranding.brandInitials }}</span>
          <span class="header-logo-name">{{ siteBranding.siteName }}</span>
        </span>
        <div v-else class="header-operate-left">
          <t-button theme="default" shape="square" variant="text" :title="sidebarButtonTitle" @click="changeCollapsed">
            <t-icon class="collapsed-icon" name="view-list" />
          </t-button>
          <t-breadcrumb class="header-breadcrumb">
            <t-breadcrumbItem v-for="item in breadcrumbs" :key="item.key" :to="item.to">
              {{ item.title }}
            </t-breadcrumbItem>
          </t-breadcrumb>
        </div>
      </template>
      <template v-if="layout !== 'side'" #default>
        <menu-content class="header-menu" :nav-data="menu" />
      </template>
      <template #operations>
        <div class="operations-container">
          <t-popup
            v-model:visible="inboxVisible"
            trigger="click"
            placement="bottom-right"
            :overlay-inner-style="{ padding: 0 }"
            destroy-on-close
            @visible-change="handleInboxVisible"
          >
            <template #content>
              <div class="inbox-panel">
                <div class="inbox-panel__head">
                  <span class="inbox-panel__title">站内信</span>
                  <t-button
                    v-if="inboxUnread > 0"
                    theme="primary"
                    variant="text"
                    size="small"
                    @click="handleMarkAllReadClick"
                  >
                    全部已读
                  </t-button>
                </div>
                <t-loading :loading="feedLoading" size="small">
                  <div v-if="feedItems.length" class="inbox-panel__list">
                    <div
                      v-for="item in feedItems"
                      :key="item.id"
                      class="inbox-item"
                      :class="{ 'inbox-item--unread': !item.read }"
                      @click="handleInboxItemClick(item)"
                    >
                      <div class="inbox-item__top">
                        <t-tag size="small" variant="light" :theme="resolveTagTheme(item.type)">{{
                          item.type_label
                        }}</t-tag>
                        <span class="inbox-item__time">{{ formatInboxTime(item.created_at) }}</span>
                      </div>
                      <div class="inbox-item__title">{{ item.title }}</div>
                      <div v-if="item.summary" class="inbox-item__summary">{{ item.summary }}</div>
                    </div>
                  </div>
                  <div v-else class="inbox-panel__empty">暂无消息</div>
                </t-loading>
                <div class="inbox-panel__foot" @click="handleNav('/client/notices')">查看全部</div>
              </div>
            </template>
            <t-badge :count="inboxUnread" :offset="[-2, 2]" size="small">
              <t-button theme="default" shape="square" variant="text" title="站内信">
                <template #icon><notification-icon /></template>
              </t-button>
            </t-badge>
          </t-popup>
          <t-dropdown :min-column-width="160" trigger="click">
            <template #dropdown>
              <t-dropdown-item class="operations-dropdown-container-item" @click="handleNav('/client/profile')">
                <user-circle-icon />个人资料
              </t-dropdown-item>
              <t-dropdown-item class="operations-dropdown-container-item" @click="handleNav('/client/recharge')">
                <wallet-icon />账户充值
              </t-dropdown-item>
              <t-dropdown-item class="operations-dropdown-container-item" @click="handleLogout">
                <poweroff-icon />退出登录
              </t-dropdown-item>
            </template>
            <t-button class="header-user-btn" theme="default" variant="text">
              <template #icon>
                <t-avatar class="header-user-avatar" size="small">{{ userInitials }}</t-avatar>
              </template>
              <span class="header-user-account">{{ accountName }}</span>
              <span class="header-user-balance">余额 {{ formattedBalance }}</span>
              <template #suffix><chevron-down-icon /></template>
            </t-button>
          </t-dropdown>
        </div>
      </template>
    </t-head-menu>
  </div>
</template>
<script setup lang="ts">
import { ChevronDownIcon, NotificationIcon, PoweroffIcon, UserCircleIcon, WalletIcon } from 'tdesign-icons-vue-next';
import { DialogPlugin, MessagePlugin } from 'tdesign-vue-next';
import type { PropType } from 'vue';
import { computed, onMounted, ref } from 'vue';
import type { RouteLocationRaw } from 'vue-router';
import { useRoute, useRouter } from 'vue-router';

import { useSiteBrandingStore } from '@/app/stores/siteBranding';
import { useDeviceLayout } from '@/composables/useDeviceLayout';
import { prefix } from '@/config/global';
import type { InboxItem } from '@/domains/content/useInbox';
import { useInbox } from '@/domains/content/useInbox';
import type { LocalizedTitle } from '@/locales';
import { useLocale } from '@/locales/useLocale';
import { getActive } from '@/router';
import { useSettingStore, useUserStore } from '@/store';
import type { MenuRoute, ModeType } from '@/types/interface';

import MenuContent from './MenuContent.vue';

const { theme, layout, showLogo, menu, isFixed, isCompact } = defineProps({
  theme: {
    type: String,
    default: 'light',
  },
  layout: {
    type: String,
    default: 'top',
  },
  showLogo: {
    type: Boolean,
    default: true,
  },
  menu: {
    type: Array as PropType<MenuRoute[]>,
    default: (): MenuRoute[] => [],
  },
  isFixed: {
    type: Boolean,
    default: false,
  },
  isCompact: {
    type: Boolean,
    default: false,
  },
  maxLevel: {
    type: Number,
    default: 3,
  },
});

const router = useRouter();
const route = useRoute();
const { locale } = useLocale();
const settingStore = useSettingStore();
const user = useUserStore();
const siteBranding = useSiteBrandingStore();
const {
  unreadCount: inboxUnread,
  feedItems,
  feedLoading,
  fetchUnreadCount,
  fetchFeed,
  markRead,
  markAllRead,
} = useInbox();
const { isMobile } = useDeviceLayout();

const active = computed(() => getActive());
const breadcrumbs = computed(() => {
  return route.matched.reduce<Array<{ key: string; to: RouteLocationRaw; title: string }>>(
    (breadcrumbArray, matchedRoute) => {
      const { meta, path } = matchedRoute;
      if (path === '/client' || meta?.hiddenBreadcrumb) {
        return breadcrumbArray;
      }

      const title = meta?.title as LocalizedTitle | undefined;
      const renderedTitle = title?.[locale.value as keyof LocalizedTitle] || '';
      if (renderedTitle) {
        const to: RouteLocationRaw = matchedRoute.name ? { name: matchedRoute.name, params: route.params } : path;
        breadcrumbArray.push({ key: matchedRoute.name?.toString() || path, to, title: renderedTitle });
      }
      return breadcrumbArray;
    },
    [],
  );
});
const accountName = computed(() => user.userInfo.name || '图拉云用户');
const userInitials = computed(() => {
  const name = accountName.value.trim();
  return name.slice(0, 1) || siteBranding.brandInitials || '图';
});
const formattedBalance = computed(() => {
  const value = user.userInfo.cash_balance;
  const amount = Number(value);
  if (!Number.isFinite(amount)) {
    return '--';
  }
  return `¥${amount.toFixed(2)}`;
});

const layoutCls = computed(() => [`${prefix}-header-layout`]);

const menuCls = computed(() => {
  return [
    {
      [`${prefix}-header-menu`]: !isFixed,
      [`${prefix}-header-menu-fixed`]: isFixed,
      [`${prefix}-header-menu-fixed-side`]: layout === 'side' && isFixed,
      [`${prefix}-header-menu-fixed-side-compact`]: layout === 'side' && isFixed && isCompact,
    },
  ];
});
const menuTheme = computed(() => theme as ModeType);
const sidebarButtonTitle = computed(() => (settingStore.isMobileSidebarVisible ? '关闭菜单' : '打开菜单'));

const changeCollapsed = () => {
  if (isMobile.value) {
    settingStore.updateConfig({
      isMobileSidebarVisible: !settingStore.isMobileSidebarVisible,
    });
    return;
  }

  settingStore.updateConfig({
    isSidebarCompact: !settingStore.isSidebarCompact,
  });
};

const handleNav = (url: string) => {
  inboxVisible.value = false;
  router.push(url);
};

const inboxVisible = ref(false);

const handleInboxVisible = (visible: boolean) => {
  if (visible) {
    fetchFeed(10);
  }
};

const handleInboxItemClick = (item: InboxItem) => {
  markRead(item);
  inboxVisible.value = false;
  if (item.link) {
    router.push(item.link);
  }
};

const handleMarkAllRead = async () => {
  const confirmed = await new Promise<boolean>((resolve) => {
    const dialog = DialogPlugin.confirm({
      header: '全部标记已读',
      body: '确认将所有未读消息标记为已读吗？此操作不可撤销。',
      confirmBtn: '确认',
      onConfirm: () => {
        dialog.destroy();
        resolve(true);
      },
      onCancel: () => {
        dialog.destroy();
        resolve(false);
      },
      onClose: () => {
        dialog.destroy();
        resolve(false);
      },
    });
  });
  if (!confirmed) return;
  markAllRead();
  MessagePlugin.success('已全部标记为已读');
};

const handleMarkAllReadClick = () => {
  handleMarkAllRead();
};

const resolveTagTheme = (type: string): 'primary' | 'warning' | 'danger' | 'success' | 'default' => {
  if (type === 'invoice_overdue_reminder' || type === 'service_expire_reminder') return 'danger';
  if (type === 'invoice_payment_reminder' || type === 'service_renew_reminder' || type === 'ticket_pending_reminder')
    return 'warning';
  if (type === 'order_paid' || type === 'ticket_staff_reply') return 'success';
  if (type === 'notice') return 'primary';
  return 'default';
};

const formatInboxTime = (value: string | null): string => {
  if (!value) return '';
  const date = new Date(value.replace(/-/g, '/'));
  if (Number.isNaN(date.getTime())) return value;
  const diff = Date.now() - date.getTime();
  const minute = 60 * 1000;
  const hour = 60 * minute;
  const day = 24 * hour;
  if (diff < minute) return '刚刚';
  if (diff < hour) return `${Math.floor(diff / minute)} 分钟前`;
  if (diff < day) return `${Math.floor(diff / hour)} 小时前`;
  if (diff < 7 * day) return `${Math.floor(diff / day)} 天前`;
  return `${date.getMonth() + 1}-${date.getDate()}`;
};

const handleLogout = async () => {
  const confirmed = await new Promise<boolean>((resolve) => {
    const dialog = DialogPlugin.confirm({
      header: '退出登录',
      body: '确认退出当前账户吗？',
      confirmBtn: '确认退出',
      onConfirm: () => {
        dialog.destroy();
        resolve(true);
      },
      onCancel: () => {
        dialog.destroy();
        resolve(false);
      },
      onClose: () => {
        dialog.destroy();
        resolve(false);
      },
    });
  });
  if (!confirmed) return;
  const redirect = router.currentRoute.value.fullPath;
  await user.logout();
  await router.push({
    path: '/client/login',
    query: { redirect: encodeURIComponent(redirect) },
  });
};

onMounted(() => {
  siteBranding.fetchSiteConfig();
  fetchUnreadCount();
});
</script>
<style lang="less" scoped>
.@{starter-prefix}-header {
  &-menu-fixed {
    position: fixed;
    top: 0;
    z-index: 1001;

    :deep(.t-head-menu__inner) {
      padding-right: var(--td-comp-margin-xl);
    }

    &-side {
      left: 232px;
      right: 0;
      z-index: 10;
      width: auto;
      transition: all 0.3s;

      &-compact {
        left: 64px;
      }
    }
  }

  &-logo-container {
    cursor: pointer;
    display: inline-flex;
  }
}

.header-menu {
  flex: 1 1 auto;
  display: inline-flex;

  :deep(.t-menu__item) {
    min-width: unset;
  }
}

.operations-container {
  display: flex;
  align-items: center;

  .t-popup__reference {
    display: flex;
    align-items: center;
    justify-content: center;
  }

  gap: var(--td-comp-margin-s);
}

.header-operate-left {
  display: flex;
  align-items: center;
  line-height: 0;
  gap: var(--td-comp-margin-m);
}

.header-breadcrumb {
  margin: 0;

  :deep(.t-breadcrumb__item) {
    max-width: 150px;
  }
}

.header-logo-container {
  min-width: 184px;
  height: var(--td-comp-size-l);
  display: flex;
  align-items: center;
  gap: var(--td-comp-margin-s);
  margin-left: var(--td-comp-margin-xl);
  color: var(--td-text-color-primary);
  font: var(--td-font-title-medium);

  &:hover {
    cursor: pointer;
  }
}

.header-logo-mark {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: var(--td-comp-size-l);
  height: var(--td-comp-size-l);
  border-radius: var(--td-radius-medium);
  color: var(--td-text-color-anti);
  background: var(--td-brand-color);
  font: var(--td-font-body-medium);
}

.header-logo-name {
  white-space: nowrap;
}

.header-user-account {
  display: inline-flex;
  align-items: center;
  color: var(--td-text-color-primary);
  max-width: 10em;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.header-user-btn {
  :deep(.t-button__text) {
    display: inline-flex;
    align-items: center;
    gap: var(--td-comp-margin-s);
  }
}

.header-user-avatar {
  background: var(--td-brand-color-light);
  color: var(--td-brand-color);
  width: 32px;
  height: 32px;
  flex-shrink: 0;
}

.header-user-balance {
  color: var(--td-text-color-secondary);
  font: var(--td-font-body-small);
}

:deep(.t-head-menu__inner) {
  border-bottom: 1px solid var(--td-component-stroke);
}

.t-menu--light {
  .header-user-account {
    color: var(--td-text-color-primary);
  }
}

.t-menu--dark {
  .t-head-menu__inner {
    border-bottom: 1px solid var(--td-gray-color-10);
  }

  .header-user-account {
    color: rgb(255 255 255 / 55%);
  }
}

@media (max-width: @screen-sm-max) {
  .header-breadcrumb,
  .header-user-balance {
    display: none;
  }

  .header-user-account {
    max-width: 6em;
  }
}

.operations-dropdown-container-item {
  width: 100%;
  display: flex;
  align-items: center;

  :deep(.t-dropdown__item-text) {
    display: flex;
    align-items: center;
  }

  .t-icon {
    font-size: var(--td-comp-size-xxxs);
    margin-right: var(--td-comp-margin-s);
  }

  :deep(.t-dropdown__item) {
    width: 100%;
    margin-bottom: 0;
  }

  &:last-child {
    :deep(.t-dropdown__item) {
      margin-bottom: 8px;
    }
  }
}

.inbox-panel {
  width: 340px;
  max-width: 88vw;

  &__head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 16px;
    border-bottom: 1px solid var(--td-component-stroke);
  }

  &__title {
    font: var(--td-font-title-small);
    color: var(--td-text-color-primary);
  }

  &__list {
    max-height: 380px;
    overflow-y: auto;
  }

  &__empty {
    padding: 40px 16px;
    text-align: center;
    color: var(--td-text-color-placeholder);
    font: var(--td-font-body-small);
  }

  &__foot {
    padding: 10px 16px;
    text-align: center;
    color: var(--td-brand-color);
    font: var(--td-font-body-small);
    border-top: 1px solid var(--td-component-stroke);
    cursor: pointer;

    &:hover {
      background: var(--td-bg-color-container-hover);
    }
  }
}

.inbox-item {
  padding: 12px 16px;
  border-bottom: 1px solid var(--td-component-stroke);
  cursor: pointer;
  transition: background 0.2s;

  &:hover {
    background: var(--td-bg-color-container-hover);
  }

  &:last-child {
    border-bottom: none;
  }

  &--unread {
    position: relative;

    .inbox-item__title {
      font-weight: 600;
    }

    &::before {
      content: '';
      position: absolute;
      left: 6px;
      top: 18px;
      width: 6px;
      height: 6px;
      border-radius: 50%;
      background: var(--td-error-color);
    }
  }

  &__top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 6px;
  }

  &__time {
    color: var(--td-text-color-placeholder);
    font: var(--td-font-body-small);
  }

  &__title {
    color: var(--td-text-color-primary);
    font: var(--td-font-body-medium);
    margin-bottom: 4px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  &__summary {
    color: var(--td-text-color-secondary);
    font: var(--td-font-body-small);
    display: -webkit-box;
    -webkit-line-clamp: 2;
    line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
  }
}
</style>
<!-- eslint-disable-next-line vue-scoped-css/enforce-style-type -->
<style lang="less">
.operations-dropdown-container-item {
  .t-dropdown__item-text {
    display: flex;
    align-items: center;
  }
}
</style>
