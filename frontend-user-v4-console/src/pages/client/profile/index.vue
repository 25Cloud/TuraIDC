<template>
  <section class="profile-page">
    <header class="client-page-heading">
      <h1>个人资料</h1>
    </header>

    <nav class="profile-tabs-mobile" aria-label="账户中心">
      <button
        v-for="tab in profileTabs"
        :key="tab.value"
        type="button"
        class="profile-tabs-mobile__item"
        :class="{ 'is-active': activeTab === tab.value }"
        @click="handleProfileTabChange(tab.value)"
      >
        {{ tab.label }}
      </button>
    </nav>

    <aside class="profile-nav">
      <t-card class="profile-card" :bordered="false">
        <template #title>账户中心</template>
        <t-menu :value="activeTab" theme="light" @change="handleProfileTabChange">
          <t-menu-item value="profile">个人资料</t-menu-item>
          <t-menu-item value="security">账户安全</t-menu-item>
          <t-menu-item value="notification">消息提醒</t-menu-item>
          <t-menu-item value="display">显示设置</t-menu-item>
        </t-menu>
      </t-card>
    </aside>

    <main class="profile-main">
      <t-card v-if="activeTab === 'profile'" class="profile-card" :bordered="false">
        <template #title>个人资料</template>
        <template #actions><t-tag variant="light">基础信息</t-tag></template>
        <t-form label-align="left" label-width="6rem" class="profile-form">
          <t-form-item label="账户ID">
            <div class="profile-id-row">
              <t-input :value="profileForm.id" readonly />
              <t-button variant="outline" shape="square" @click="copyText(profileForm.id)">
                <copy-icon />
              </t-button>
            </div>
          </t-form-item>
          <t-form-item label="注册时间"><t-input :value="profileForm.createdAt || '--'" readonly /></t-form-item>
          <t-form-item label="用户名"
            ><t-input v-model="profileForm.nickname" maxlength="50" placeholder="请输入用户名"
          /></t-form-item>
          <t-form-item label="账户余额"><t-input :value="balanceText" readonly /></t-form-item>
          <t-form-item label="登录邮箱"><t-input :value="profileForm.email || '--'" readonly /></t-form-item>
          <t-form-item label="账户状态">
            <t-tag :theme="profileForm.is_verified ? 'success' : 'default'" variant="light">
              {{ profileForm.is_verified ? '已实名' : '未实名' }}
            </t-tag>
          </t-form-item>
          <t-form-item label="代理组">
            <t-tag v-if="agentGroup?.name" theme="primary" variant="light">{{ agentGroup.name }}</t-tag>
            <span v-else class="profile-muted-text">无</span>
          </t-form-item>
        </t-form>
        <div class="profile-footer">
          <span>保存后会立即更新当前账户资料。</span>
          <t-button theme="primary" :loading="profileLoading" @click="updateProfile">保存资料</t-button>
        </div>
      </t-card>

      <t-card v-else-if="activeTab === 'security'" class="profile-card" :bordered="false">
        <template #title>账户安全</template>
        <div class="security-list">
          <article v-for="item in securityItems" :key="item.key" class="security-item">
            <div>
              <div class="security-item__head">
                <strong>{{ item.name }}</strong>
                <t-tag :theme="item.theme" variant="light">{{ item.tag }}</t-tag>
              </div>
              <p>{{ item.desc }}</p>
            </div>
            <t-button theme="primary" variant="text" @click="item.action">{{ item.actionLabel }}</t-button>
          </article>
        </div>
      </t-card>

      <t-card v-else-if="activeTab === 'notification'" class="profile-card" :bordered="false">
        <template #title>消息提醒</template>
        <template #actions
          ><t-tag variant="light">已开启 {{ enabledNotificationCount }}</t-tag></template
        >
        <div class="notification-list">
          <article v-for="item in notificationList" :key="item.key" class="notification-item">
            <div>
              <strong>{{ item.name }}</strong>
              <p>{{ item.desc }}</p>
            </div>
            <t-switch v-model="item.enabled" />
          </article>
        </div>
        <div class="profile-footer">
          <span>关闭安全提醒可能会错过密码、邮箱或手机号变更通知。</span>
          <t-button theme="primary" :loading="notificationLoading" @click="saveNotificationPreferences"
            >保存设置</t-button
          >
        </div>
      </t-card>

      <t-card v-else-if="activeTab === 'display'" class="profile-card" :bordered="false">
        <template #title>显示设置</template>
        <template #actions><t-tag variant="light">主题外观</t-tag></template>
        <div class="display-options">
          <div class="display-option-head">
            <strong>主题模式</strong>
            <p>选择明亮、深色或跟随系统的外观主题；选择会保存在本机。</p>
          </div>
          <t-radio-group v-model="themeMode" variant="default-filled" @change="handleThemeModeChange">
            <t-radio-button value="light">明亮</t-radio-button>
            <t-radio-button value="dark">深色</t-radio-button>
            <t-radio-button value="auto">跟随系统</t-radio-button>
          </t-radio-group>
        </div>
      </t-card>
    </main>

    <t-dialog
      v-model:visible="passwordDialogVisible"
      :header="passwordMode === 'old' ? '修改登录密码' : '验证码重置密码'"
      width="min(30rem, calc(100vw - 2rem))"
    >
      <t-form v-if="passwordMode === 'old'" label-align="top">
        <t-form-item label="原密码"><t-input v-model="passwordForm.oldPassword" type="password" /></t-form-item>
        <t-form-item label="新密码"
          ><t-input v-model="passwordForm.newPassword" type="password" placeholder="至少 8 位"
        /></t-form-item>
        <t-form-item label="确认密码"><t-input v-model="passwordForm.confirmPassword" type="password" /></t-form-item>
        <t-button variant="text" theme="primary" class="password-forgot" @click="togglePasswordMode"
          >忘记原密码？</t-button
        >
      </t-form>
      <t-form v-else label-align="top">
        <t-tabs v-if="profileForm.phone && profileForm.email" v-model="resetForm.type" theme="normal">
          <t-tab-panel value="phone" label="手机验证" />
          <t-tab-panel value="email" label="邮箱验证" />
        </t-tabs>
        <div v-else-if="profileForm.phone" class="reset-single-tip">验证方式：手机验证</div>
        <div v-else class="reset-single-tip">验证方式：邮箱验证</div>
        <t-form-item label="验证对象"
          ><t-input :value="resetForm.type === 'phone' ? profileForm.phone : profileForm.email" readonly
        /></t-form-item>
        <t-form-item label="验证码">
          <div class="bind-code-row">
            <t-input v-model="resetForm.code" placeholder="请输入 6 位验证码" maxlength="6" />
            <t-button variant="outline" :disabled="resetCountdown > 0" @click="sendResetCode">
              {{ resetCountdown > 0 ? `${resetCountdown}s` : '发送验证码' }}
            </t-button>
          </div>
        </t-form-item>
        <t-form-item label="新密码"
          ><t-input v-model="resetForm.password" type="password" placeholder="至少 8 位"
        /></t-form-item>
        <t-form-item label="确认密码"><t-input v-model="resetForm.confirmPassword" type="password" /></t-form-item>
        <t-button variant="text" theme="primary" class="password-forgot" @click="togglePasswordMode"
          >使用原密码修改</t-button
        >
      </t-form>
      <template #footer>
        <t-button variant="outline" @click="passwordDialogVisible = false">取消</t-button>
        <t-button v-if="passwordMode === 'old'" theme="primary" :loading="profileLoading" @click="changePassword"
          >确定</t-button
        >
        <t-button v-else theme="primary" :loading="profileLoading" @click="submitResetPassword">确定</t-button>
      </template>
    </t-dialog>

    <t-dialog v-model:visible="phoneDialogVisible" header="更换绑定手机" width="min(30rem, calc(100vw - 2rem))">
      <t-form label-align="top">
        <t-form-item label="新手机号"><t-input v-model="phoneForm.phone" placeholder="请输入新手机号" /></t-form-item>
        <t-form-item label="验证码">
          <div class="bind-code-row">
            <t-input v-model="phoneForm.code" placeholder="请输入 6 位验证码" maxlength="6" />
            <t-button variant="outline" :disabled="phoneCountdown > 0" @click="sendPhoneVerificationCode">
              {{ phoneCountdown > 0 ? `${phoneCountdown}s` : '发送验证码' }}
            </t-button>
          </div>
        </t-form-item>
      </t-form>
      <template #footer>
        <t-button variant="outline" @click="phoneDialogVisible = false">取消</t-button>
        <t-button theme="primary" :loading="profileLoading" @click="submitPhoneChange">确定</t-button>
      </template>
    </t-dialog>

    <t-dialog v-model:visible="emailDialogVisible" header="更换绑定邮箱" width="min(30rem, calc(100vw - 2rem))">
      <t-form label-align="top">
        <t-form-item label="新邮箱"><t-input v-model="emailForm.email" placeholder="请输入新邮箱" /></t-form-item>
        <t-form-item label="验证码">
          <div class="bind-code-row">
            <t-input v-model="emailForm.code" placeholder="请输入 6 位验证码" maxlength="6" />
            <t-button variant="outline" :disabled="emailCountdown > 0" @click="sendEmailVerificationCode">
              {{ emailCountdown > 0 ? `${emailCountdown}s` : '发送验证码' }}
            </t-button>
          </div>
        </t-form-item>
      </t-form>
      <template #footer>
        <t-button variant="outline" @click="emailDialogVisible = false">取消</t-button>
        <t-button theme="primary" :loading="profileLoading" @click="submitEmailChange">确定</t-button>
      </template>
    </t-dialog>
  </section>
</template>
<script setup lang="ts">
import { CopyIcon } from 'tdesign-icons-vue-next';
import { ref } from 'vue';

import { useProfile } from '@/domains/account/useProfile';
import { getSettingStore, useSettingStore } from '@/store';

const settingStore = useSettingStore();
const themeMode = ref<'light' | 'dark' | 'auto'>(
  settingStore.mode === 'auto' ? 'auto' : (settingStore.mode as 'light' | 'dark') || 'light',
);

const handleThemeModeChange = (mode: unknown) => {
  const next = (mode === 'auto' ? 'auto' : mode === 'dark' ? 'dark' : 'light') as 'light' | 'dark' | 'auto';
  const s = getSettingStore();
  if (s.mode !== next) {
    s.mode = next;
  }
  s.changeMode(next);
};

const profileTabs = [
  { value: 'profile', label: '个人资料' },
  { value: 'security', label: '账户安全' },
  { value: 'notification', label: '消息提醒' },
  { value: 'display', label: '显示设置' },
] as const;

const {
  activeTab,
  profileLoading,
  notificationLoading,
  passwordDialogVisible,
  phoneDialogVisible,
  emailDialogVisible,
  passwordMode,
  profileForm,
  agentGroup,
  passwordForm,
  resetForm,
  resetCountdown,
  phoneForm,
  emailForm,
  phoneCountdown,
  emailCountdown,
  notificationList,
  balanceText,
  enabledNotificationCount,
  securityItems,
  copyText,
  updateProfile,
  changePassword,
  togglePasswordMode,
  sendResetCode,
  submitResetPassword,
  sendPhoneVerificationCode,
  sendEmailVerificationCode,
  submitPhoneChange,
  submitEmailChange,
  saveNotificationPreferences,
  handleProfileTabChange,
} = useProfile();
</script>
<style scoped lang="less">
.profile-page {
  display: grid;
  grid-template-columns: minmax(14rem, 18rem) minmax(0, 1fr);
  gap: var(--td-comp-margin-m);
  // padding 由 Starter 布局层统一提供
}

.client-page-heading {
  grid-column: 1 / -1;

  h1 {
    margin: 0;
    color: var(--td-text-color-primary);
    font: var(--td-font-title-large);
  }
}

.profile-card {
  background: var(--td-bg-color-container);
  border: thin solid var(--td-border-color);
  border-radius: var(--td-radius-medium);
  box-shadow: var(--td-shadow-1);
}

.profile-nav {
  position: sticky;
  top: var(--td-comp-margin-m);
  align-self: start;
}

.profile-main {
  min-width: 0;
}

.profile-form {
  max-width: 46rem;
}

.profile-muted-text {
  color: var(--td-text-color-placeholder);
  font-size: 0.875rem;
}

.profile-id-row {
  display: flex;
  gap: var(--td-comp-margin-s);
  align-items: center;
  width: 100%;
}

.bind-code-row {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: 0.5rem;
  width: 100%;

  .t-button {
    min-width: 8.5rem;
    white-space: nowrap;
  }
}

.password-forgot {
  margin-top: -0.25rem;
  font-size: 0.8125rem;
  color: var(--td-brand-color);
  cursor: pointer;
  user-select: none;

  &:hover {
    text-decoration: underline;
  }
}

.reset-single-tip {
  margin-bottom: var(--td-comp-margin-s);
  font-size: 0.8125rem;
  color: var(--td-text-color-secondary);
}

.profile-tabs-mobile {
  display: none;
}

.display-options {
  max-width: 46rem;

  .display-option-head {
    margin-bottom: var(--td-comp-margin-m);

    strong {
      color: var(--td-text-color-primary);
      font: var(--td-font-title-small);
    }

    p {
      margin: var(--td-comp-margin-xs) 0 0;
      color: var(--td-text-color-secondary);
      font-size: 0.8125rem;
      line-height: 1.6;
    }
  }
}

.profile-footer {
  display: flex;
  gap: var(--td-comp-margin-m);
  align-items: center;
  justify-content: space-between;
  margin-top: var(--td-comp-margin-l);
  padding-top: var(--td-comp-margin-m);
  color: var(--td-text-color-secondary);
  border-top: thin dashed var(--td-border-color);
  font: var(--td-font-body-small);
}

.security-list,
.notification-list {
  display: flex;
  flex-direction: column;
  gap: var(--td-comp-margin-s);
}

.security-item,
.notification-item {
  display: flex;
  gap: var(--td-comp-margin-m);
  align-items: center;
  justify-content: space-between;
  padding: var(--td-comp-paddingTB-m) var(--td-comp-paddingLR-m);
  background: var(--td-bg-color-container);
  border: thin solid var(--td-border-color);
  border-radius: var(--td-radius-medium);

  p {
    margin: var(--td-comp-margin-xs) 0 0;
    color: var(--td-text-color-secondary);
    font: var(--td-font-body-small);
  }
}

.security-item__head {
  display: flex;
  flex-wrap: wrap;
  gap: var(--td-comp-margin-s);
  align-items: center;
}

.agent-list {
  display: grid;
  gap: var(--td-comp-margin-s);
  max-width: 32rem;
  margin: var(--td-comp-margin-m) auto 0;

  span {
    padding: var(--td-comp-paddingTB-s) var(--td-comp-paddingLR-m);
    color: var(--td-text-color-primary);
    background: var(--td-bg-color-component);
    border-radius: var(--td-radius-medium);
  }
}

@media (max-width: @screen-sm-max) {
  .profile-page {
    grid-template-columns: 1fr;
    gap: var(--td-comp-margin-s);
  }

  .client-page-heading {
    h1 {
      font: var(--td-font-title-medium);
    }
  }

  // 手机端隐藏桌面侧栏，使用顶部横向标签
  .profile-nav {
    display: none;
  }

  .profile-tabs-mobile {
    display: flex;
    gap: var(--td-comp-margin-xxs);
    padding: var(--td-comp-paddingTB-xxs) var(--td-comp-paddingLR-xxs);
    background: var(--td-bg-color-container);
    border: thin solid var(--td-border-color);
    border-radius: var(--td-radius-medium);
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: none;

    &::-webkit-scrollbar {
      display: none;
    }

    &__item {
      flex: 1 0 auto;
      padding: var(--td-comp-paddingTB-s) var(--td-comp-paddingLR-m);
      color: var(--td-text-color-primary);
      background: transparent;
      border: none;
      border-radius: var(--td-radius-default);
      font: var(--td-font-body-medium);
      white-space: nowrap;
      cursor: pointer;
      transition:
        background 0.2s,
        color 0.2s;

      &.is-active {
        color: var(--td-brand-color);
        background: var(--td-brand-color-light);
        font-weight: 600;
      }
    }
  }

  // 表单标签顶部对齐，输入框占满整行
  .profile-form {
    :deep(.t-form__label) {
      width: auto !important;
      min-width: 0 !important;
      padding-right: 0;
      padding-bottom: var(--td-comp-margin-xxs);
    }

    :deep(.t-form__controls) {
      width: 100% !important;
      margin-left: 0 !important;
    }
  }

  .profile-id-row {
    flex-direction: row;
    align-items: stretch;
    gap: var(--td-comp-margin-xs);

    :deep(.t-input) {
      flex: 1;
      min-width: 0;
    }

    :deep(.t-button) {
      flex-shrink: 0;
      padding: 0 var(--td-comp-paddingLR-s);
    }
  }

  .profile-footer,
  .security-item,
  .notification-item {
    align-items: flex-start;
    flex-direction: column;
  }

  .profile-footer {
    .t-button {
      align-self: stretch;
    }
  }
}
</style>
