<template>
  <section class="profile-page">
    <header class="client-page-heading">
      <h1>{{ pageTitle }}</h1>
    </header>

    <main class="profile-main">
      <t-card v-if="activeTab === 'profile'" class="profile-card" :bordered="false">
        <template #actions><t-tag variant="light">基础信息</t-tag></template>
        <t-form label-align="left" label-width="6rem" class="profile-form">
          <t-form-item label="账户ID">
            <div class="profile-id-row">
              <t-input :value="profileForm.id" readonly />
              <button
                type="button"
                class="profile-copy-btn"
                title="复制账户ID"
                aria-label="复制账户ID"
                @click="copyText(profileForm.id)"
              >
                <copy-icon />
              </button>
            </div>
          </t-form-item>
          <t-form-item label="注册时间"><t-input :value="profileForm.createdAt || '--'" readonly /></t-form-item>
          <t-form-item label="用户名"
            ><t-input v-model="profileForm.nickname" maxlength="50" placeholder="请输入用户名"
          /></t-form-item>
          <t-form-item label="QQ 号">
            <t-input v-model="profileForm.qq" maxlength="20" placeholder="填写后将以 QQ 头像作为账户头像" />
          </t-form-item>
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
import { computed, watch } from 'vue';
import { useRoute } from 'vue-router';

import { useProfile } from '@/domains/account/useProfile';

// 三个路由共用本组件，按路由决定展示哪个分区
const SECTION_BY_ROUTE: Record<string, 'profile' | 'security' | 'notification'> = {
  ClientProfile: 'profile',
  ClientSecurity: 'security',
  ClientNotification: 'notification',
};

const SECTION_TITLE: Record<string, string> = {
  profile: '个人资料',
  security: '账户安全',
  notification: '消息提醒',
};

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

const route = useRoute();

// 三个路由复用同一组件实例，路由变化时同步当前分区
watch(
  () => route.name,
  (name) => {
    const section = SECTION_BY_ROUTE[String(name)];
    if (section) {
      handleProfileTabChange(section);
    }
  },
  { immediate: true },
);

const pageTitle = computed(() => SECTION_TITLE[activeTab.value] || '账户设置');
</script>
<style scoped lang="less">
.profile-page {
  display: grid;
  // 分区已拆分为左侧「账户设置」菜单项，页内不再需要二级导航
  grid-template-columns: minmax(0, 1fr);
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

  :deep(.t-input) {
    flex: 1;
    min-width: 0;
  }
}

// 复制账户ID：原生button，尺寸完全由本样式控制。
// 六个尺寸属性全部锁成 32px（= 左侧输入框 .t-input 的高度），结构上就是正方形；
// 必须写全 min-/max-，因为 style/reset.less 在移动端(width<=768px)会给所有
// button 强制 min-height/min-width:40px 的触控区，这里要压住它。
.profile-copy-btn {
  flex: 0 0 auto;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  box-sizing: border-box;
  width: 32px;
  min-width: 32px;
  max-width: 32px;
  height: 32px;
  min-height: 32px;
  max-height: 32px;
  padding: 0;
  color: var(--td-text-color-primary);
  cursor: pointer;
  background: var(--td-bg-color-specialcomponent);
  border: thin solid var(--td-border-level-2-color);
  border-radius: var(--td-radius-default);
  transition:
    color 0.2s,
    border-color 0.2s,
    background-color 0.2s;

  &:hover {
    color: var(--td-brand-color);
    border-color: var(--td-brand-color);
  }

  &:active {
    color: var(--td-brand-color);
    background: var(--td-brand-color-light);
  }
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

  // 账户安全 / 消息提醒在手机端保持左右分栏，操作按钮不再掉到下一行
  .security-item,
  .notification-item {
    flex-direction: row;
    align-items: center;
    gap: var(--td-comp-margin-s);

    > :first-child {
      flex: 1;
      min-width: 0;
    }

    :deep(.t-button),
    :deep(.t-switch) {
      flex-shrink: 0;
    }
  }

  // 底部操作区：说明文字在上，按钮整行铺满，并留出手机安全区避免被Home 条裁切
  .profile-footer {
    flex-direction: column;
    align-items: stretch;
    gap: var(--td-comp-margin-s);
    padding-bottom: calc(env(safe-area-inset-bottom) + var(--td-comp-margin-xs));

    :deep(.t-button) {
      width: 100%;
      min-width: 0;
    }
  }

  .profile-footer {
    .t-button {
      align-self: stretch;
    }
  }
}
</style>
