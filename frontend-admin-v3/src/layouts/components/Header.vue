<template>
  <div :class="layoutCls">
    <t-head-menu :class="menuCls" :theme="menuTheme" expand-type="popup" :value="active">
      <template #logo>
        <span v-if="showLogo" class="header-logo-container" @click="handleNav(ADMIN_HOME_PATH)">
          <img class="t-logo" :src="logoPng" alt="TuraIDC" />
        </span>
        <div v-else-if="!isMobileSideHeader" class="header-operate-left">
          <t-button theme="default" shape="square" variant="text" aria-label="折叠侧边栏" @click="changeCollapsed">
            <t-icon class="collapsed-icon" name="view-list" />
          </t-button>
        </div>
        <!-- 手机端 side 布局：顶栏 logo + 菜单按钮 -->
        <div v-if="isMobileSideHeader" class="header-mobile-logo-bar">
          <t-button theme="default" shape="square" variant="text" @click="toggleMobileSidebar">
            <t-icon name="view-list" />
          </t-button>
          <span class="header-mobile-logo" @click="handleNav(ADMIN_HOME_PATH)">
            <img class="t-logo" :src="logoPng" alt="TuraIDC" />
          </span>
        </div>
      </template>
      <template v-if="layout !== 'side'" #default>
        <menu-content class="header-menu" :nav-data="menu" />
      </template>
      <template #operations>
        <div class="operations-container">
          <t-dropdown :min-column-width="120" trigger="click">
            <template #dropdown>
              <t-dropdown-item class="operations-dropdown-container-item" @click="handleNav('/user/index')">
                <user-circle-icon />{{ t('layout.header.user') }}
              </t-dropdown-item>
              <t-dropdown-item class="operations-dropdown-container-item" @click="openPasswordDialog">
                <setting-icon />修改密码
              </t-dropdown-item>
              <t-dropdown-item class="operations-dropdown-container-item" @click="handleLogout">
                <poweroff-icon />{{ t('layout.header.signOut') }}
              </t-dropdown-item>
            </template>
            <t-button class="header-user-btn" theme="default" variant="text">
              <template #icon>
                <t-icon class="header-user-avatar" name="user-circle" />
              </template>
              <div class="header-user-account">{{ user.userInfo.name }}</div>
              <template #suffix><chevron-down-icon /></template>
            </t-button>
          </t-dropdown>
          <t-tooltip placement="bottom" :content="t('layout.header.setting')">
            <t-button theme="default" shape="square" variant="text" aria-label="设置" @click="toggleSettingPanel">
              <setting-icon />
            </t-button>
          </t-tooltip>
          <t-tooltip placement="bottom" content="仪表盘">
            <t-button
              theme="default"
              shape="square"
              variant="text"
              aria-label="仪表盘"
              @click="handleNav('/admin/dashboard')"
            >
              <home-icon />
            </t-button>
          </t-tooltip>
        </div>
      </template>
    </t-head-menu>

    <t-dialog
      v-model:visible="passwordVisible"
      header="修改密码"
      width="520px"
      :confirm-btn="{ content: '保存', theme: 'primary' }"
      :confirm-loading="passwordSaving"
      @confirm="submitPassword"
    >
      <t-form ref="passwordFormRef" :data="passwordForm" :rules="passwordRules" label-align="top">
        <t-form-item label="当前密码" name="current_password">
          <t-input v-model="passwordForm.current_password" type="password" autocomplete="current-password" />
        </t-form-item>
        <t-form-item label="新密码" name="password">
          <t-input v-model="passwordForm.password" type="password" autocomplete="new-password" />
        </t-form-item>
        <t-form-item label="确认新密码" name="password_confirmation">
          <t-input v-model="passwordForm.password_confirmation" type="password" autocomplete="new-password" />
        </t-form-item>
      </t-form>
    </t-dialog>
  </div>
</template>
<script setup lang="ts">
import { ChevronDownIcon, HomeIcon, PoweroffIcon, SettingIcon, UserCircleIcon } from 'tdesign-icons-vue-next';
import type { FormInstanceFunctions, FormRule } from 'tdesign-vue-next';
import { MessagePlugin } from 'tdesign-vue-next';
import type { PropType } from 'vue';
import { computed, onMounted, onUnmounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';

import { adminAuthApi } from '@/api/auth';
import logoPng from '@/assets/logo.png';
import { prefix } from '@/config/global';
import { t } from '@/locales';
import { getActive } from '@/router';
import { getPermissionStore, getTabsRouterStore, useSettingStore, useUserStore } from '@/store';
import type { MenuRoute, ModeType } from '@/types/interface';
import { ADMIN_HOME_PATH } from '@/utils/route/adminHome';
import { errorMessage } from '@/utils/userMessage';

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
const settingStore = useSettingStore();
const user = useUserStore();
const passwordVisible = ref(false);
const passwordSaving = ref(false);
const passwordFormRef = ref<FormInstanceFunctions>();
const passwordForm = reactive({
  current_password: '',
  password: '',
  password_confirmation: '',
});
const passwordRules: Record<string, FormRule[]> = {
  current_password: [{ required: true, message: '请输入当前密码', type: 'error' }],
  password: [
    { required: true, message: '请输入新密码', type: 'error' },
    { min: 8, message: '新密码至少 8 位', type: 'error' },
  ],
  password_confirmation: [{ required: true, message: '请再次输入新密码', type: 'error' }],
};

const toggleSettingPanel = () => {
  settingStore.updateConfig({
    showSettingPanel: true,
  });
};

const MOBILE_POINT = 768;
const isMobile = ref(false);

const updateIsMobile = () => {
  isMobile.value = window.innerWidth <= MOBILE_POINT;
};

onMounted(() => {
  updateIsMobile();
  window.addEventListener('resize', updateIsMobile);
});

onUnmounted(() => {
  window.removeEventListener('resize', updateIsMobile);
});

// 手机端 side 布局：顶栏同时展示 logo 和菜单按钮
const isMobileSideHeader = computed(() => isMobile.value && layout === 'side' && !showLogo);

const changeCollapsed = () => {
  if (window.innerWidth <= 768) {
    settingStore.updateConfig({
      isMobileSidebarVisible: !settingStore.isMobileSidebarVisible,
    });
    return;
  }

  settingStore.updateConfig({
    isSidebarCompact: !settingStore.isSidebarCompact,
  });
};

const toggleMobileSidebar = () => {
  settingStore.updateConfig({
    isMobileSidebarVisible: !settingStore.isMobileSidebarVisible,
  });
};

const active = computed(() => getActive());

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

const handleNav = (url: string) => {
  router.push(url);
};

const openPasswordDialog = () => {
  Object.assign(passwordForm, {
    current_password: '',
    password: '',
    password_confirmation: '',
  });
  passwordVisible.value = true;
};

const submitPassword = async () => {
  const result = await passwordFormRef.value?.validate?.();
  if (result !== true) return;

  if (passwordForm.password.length < 8) {
    MessagePlugin.warning('新密码至少 8 位');
    return;
  }

  if (passwordForm.password !== passwordForm.password_confirmation) {
    MessagePlugin.warning('两次输入的新密码不一致');
    return;
  }

  passwordSaving.value = true;
  try {
    await adminAuthApi.updatePassword({ ...passwordForm });
    MessagePlugin.success('密码已更新');
    passwordVisible.value = false;
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '修改密码失败'));
  } finally {
    passwordSaving.value = false;
  }
};

// 退出登录必须真正清理会话：吊销服务端 token、清本地 token 与 userInfo、
// 丢弃标签页与已注册的动态路由。只做 router.push 的话 token 仍在，
// 用户按后退键或手输 /admin/* 会被守卫直接放行，等于没有登出。
// 也不要带 redirect：登出后再把人送回原页面与「退出」的语义相悖。
const handleLogout = async () => {
  try {
    await user.logout();
  } finally {
    getTabsRouterStore().removeTabRouterList();
    await getPermissionStore().restoreRoutes();
    router.push({ path: '/admin/login' });
  }
};
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

  .t-button {
    margin-left: var(--td-comp-margin-l);
  }
}

.header-logo-container {
  width: 184px;
  height: 26px;
  display: flex;
  margin-left: 24px;
  color: var(--td-text-color-primary);

  .t-logo {
    width: 100%;
    height: 100%;

    &:hover {
      cursor: pointer;
    }
  }

  &:hover {
    cursor: pointer;
  }
}

.header-user-account {
  display: inline-flex;
  align-items: center;
  color: var(--td-text-color-primary);
}

.header-operate-left {
  display: flex;
  align-items: normal;
  line-height: 0;
}

.header-mobile-logo-bar {
  display: none;
}

:deep(.t-head-menu__inner) {
  border-bottom: 1px solid var(--td-component-stroke);
}

/* 手机端 side 布局：顶栏显示菜单按钮，隐藏 logo 为右侧操作区腾空间 */
@media (width <= 768px) {
  .header-mobile-logo-bar {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    margin-left: 8px;
  }

  .header-mobile-logo {
    display: none;
  }
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
