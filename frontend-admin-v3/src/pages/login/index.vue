<template>
  <div class="login-container">
    <div class="login-card">
      <div class="login-header">
        <h1>管理后台</h1>
        <p>请登录您的管理员账号</p>
      </div>
      <t-form ref="formRef" :data="formData" :rules="formRules" label-align="top" @submit="handleLogin">
        <t-form-item label="账号" name="account">
          <t-input
            v-model="formData.account"
            placeholder="请输入管理员账号"
            size="large"
            clearable
            autocomplete="username"
          />
        </t-form-item>
        <t-form-item label="密码" name="password">
          <t-input
            v-model="formData.password"
            type="password"
            placeholder="请输入密码"
            size="large"
            clearable
            autocomplete="current-password"
          />
        </t-form-item>
        <!-- inline 形态（Turnstile）的验证组件落点：点击登录时就地加载，无感通过时不占位 -->
        <div v-show="captchaRenderMode === 'inline'" ref="captchaContainer" class="login-captcha"></div>
        <t-form-item class="login-submit-item">
          <t-button block theme="primary" size="large" type="submit" :loading="loading || captchaLoading">
            登录
          </t-button>
          <span class="sr-only" role="alert" aria-live="assertive">{{ errorMessage }}</span>
        </t-form-item>
      </t-form>
      <div class="login-footer">
        <span>© {{ currentYear }} 图拉云 管理后台</span>
      </div>
    </div>
  </div>
</template>
<script setup lang="ts">
import type { FormInstanceFunctions, FormRule } from 'tdesign-vue-next';
import { MessagePlugin } from 'tdesign-vue-next';
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';

import { resolveCaptchaRequirement, useGeeTestCaptcha } from '@/hooks/useGeeTestCaptcha';
import { useUserStore } from '@/store';
import { resolveAdminHomePath } from '@/utils/route/adminHome';

const router = useRouter();
const userStore = useUserStore();
const formRef = ref<FormInstanceFunctions>();
const loading = ref(false);
const errorMessage = ref('');
const currentYear = computed(() => new Date().getFullYear());

// 管理员登录的人机验证：是否要求由后端场景开关决定（验证码插件配置里的「管理员登录」）。
// 探测失败一律视为不要求——验证码配置出问题不应该把管理员挡在后台之外。
//
// 验证 SDK 在点击登录时才加载。渲染形态由后端下发：popup（极验）由插件自行弹窗，
// inline（Turnstile）渲染进登录按钮上方的容器，无感通过时不占位。
const captchaRequired = ref(false);
const captchaRenderMode = ref<'popup' | 'inline'>('popup');
const captchaContainer = ref<HTMLElement>();
const { verify: verifyCaptcha, loading: captchaLoading } = useGeeTestCaptcha({ appendTo: captchaContainer });

onMounted(async () => {
  const { required, renderMode } = await resolveCaptchaRequirement('admin_login');
  captchaRequired.value = required;
  captchaRenderMode.value = renderMode;
});

const formData = ref({
  account: '',
  password: '',
});

const formRules: Record<string, FormRule[]> = {
  account: [{ required: true, message: '请输入账号', trigger: 'blur' }],
  password: [{ required: true, message: '请输入密码', trigger: 'blur' }],
};

async function handleLogin() {
  const valid = await formRef.value?.validate();
  if (valid !== true) return;

  loading.value = true;
  try {
    // 验证结果随登录请求一起提交；未要求验证时不带 captcha 字段
    const captcha = captchaRequired.value ? await verifyCaptcha() : null;

    await userStore.login({
      account: formData.value.account,
      password: formData.value.password,
      ...(captcha ? { captcha } : {}),
    });
    MessagePlugin.success('登录成功');
    const redirect = router.currentRoute.value.query.redirect;
    if (redirect) {
      router.push(decodeURIComponent(redirect as string));
    } else {
      // 无 redirect 时落当前角色可访问的首页：仅持非视图权限的角色（如只有 ticket.manage）
      // 不应落到无权限的 /admin/dashboard，否则守卫会反复重定向导致页面卡死。
      router.push(resolveAdminHomePath(userStore.userInfo?.permissions ?? []));
    }
  } catch (error) {
    const msg = error instanceof Error ? error.message : '登录失败，请检查账号密码';
    errorMessage.value = msg;
    MessagePlugin.error(msg);
  } finally {
    loading.value = false;
  }
}
</script>
<style lang="less" scoped>
.login-container {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 40px 20px;
  position: relative;
  overflow: hidden;
  background: var(--td-bg-color-page, #f5f7fb);

  /* brand-tinted radial glow spots */
  &::before,
  &::after {
    content: '';
    position: absolute;
    border-radius: 50%;
    pointer-events: none;
    z-index: 0;
  }

  &::before {
    top: -180px;
    right: -120px;
    width: 520px;
    height: 520px;
    background: radial-gradient(circle, rgb(22 93 255 / 10%), rgb(22 93 255 / 0%) 70%);
  }

  &::after {
    bottom: -140px;
    left: -100px;
    width: 440px;
    height: 440px;
    background: radial-gradient(circle, rgb(22 93 255 / 7%), rgb(22 93 255 / 0%) 70%);
  }
}

.login-card {
  width: 100%;
  max-width: 420px;
  padding: 48px 40px 36px;
  background: var(--td-bg-color-container, #fff);
  border-radius: var(--td-radius-extraLarge, 12px);
  box-shadow:
    0 8px 32px rgb(0 0 0 / 6%),
    0 1px 4px rgb(0 0 0 / 4%);
  position: relative;
  z-index: 1;
}

.login-header {
  text-align: center;
  margin-bottom: 36px;
  padding-bottom: 28px;
  border-bottom: 1px solid var(--td-component-stroke, #eef2f7);

  h1 {
    font-size: var(--td-font-size-size-7, 22px);
    font-weight: 600;
    color: var(--td-text-color-primary, #1f2937);
    margin-bottom: 6px;
    letter-spacing: -0.01em;
  }

  p {
    font-size: var(--td-font-size-size-3, 14px);
    color: var(--td-text-color-secondary, #5b6b82);
  }
}

.login-submit-item {
  :deep(.t-form__label) {
    display: none;
  }
}

/* 验证组件落点：无感通过时容器内为空、不占高度；需要挑战时才撑开 */
.login-captcha {
  display: flex;
  justify-content: center;

  &:not(:empty) {
    margin-bottom: 16px;
  }
}

.login-footer {
  margin-top: 28px;
  text-align: center;
  font-size: var(--td-font-size-size-1, 12px);
  color: var(--td-text-color-placeholder, #94a0b2);
  line-height: 1.6;
}

/* responsive: remove decorative glow on small screens */
@media (width <= 640px) {
  .login-container {
    padding: 24px 16px;

    &::before,
    &::after {
      display: none;
    }
  }

  .login-card {
    padding: 36px 24px 28px;
    border-radius: 10px;
    box-shadow: 0 2px 12px rgb(0 0 0 / 5%);
  }

  .login-header {
    margin-bottom: 28px;
    padding-bottom: 22px;

    h1 {
      font-size: var(--td-font-size-size-6, 20px);
    }

    p {
      font-size: var(--td-font-size-size-2, 13px);
    }
  }

  .login-footer {
    margin-top: 20px;
    font-size: 11px;
  }
}
</style>
