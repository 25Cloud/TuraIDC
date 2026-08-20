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
        <t-form-item class="login-submit-item">
          <t-button block theme="primary" size="large" type="submit" :loading="submitting"> 登录 </t-button>
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
import { computed, ref } from 'vue';
import { useRouter } from 'vue-router';

import { useGeeTestCaptcha } from '@/hooks/useGeeTestCaptcha';
import { useUserStore } from '@/store';

const router = useRouter();
const userStore = useUserStore();
const formRef = ref<FormInstanceFunctions>();
const loginLoading = ref(false);
const errorMessage = ref('');
const currentYear = computed(() => new Date().getFullYear());
const { loading: captchaLoading, verify: verifyCaptcha } = useGeeTestCaptcha('/v2/admin/auth/captcha-config');
const submitting = computed(() => loginLoading.value || captchaLoading.value);

const formData = ref({
  account: '',
  password: '',
});

const formRules: Record<string, FormRule[]> = {
  account: [{ required: true, message: '请输入账号', trigger: 'blur' }],
  password: [{ required: true, message: '请输入密码', trigger: 'blur' }],
};

let submitLocked = false;

/** 校验表单、完成行为验证并提交管理员登录请求。 */
async function handleLogin() {
  if (submitLocked || submitting.value) return;
  submitLocked = true;
  const valid = await formRef.value?.validate();
  if (valid !== true) {
    submitLocked = false;
    return;
  }

  errorMessage.value = '';
  try {
    const captcha = await verifyCaptcha();
    loginLoading.value = true;
    await userStore.login({
      account: formData.value.account,
      password: formData.value.password,
      captcha,
    });
    MessagePlugin.success('登录成功');
    const redirect = router.currentRoute.value.query.redirect;
    if (redirect) {
      router.push(decodeURIComponent(redirect as string));
    } else {
      router.push('/admin/dashboard');
    }
  } catch (error) {
    const msg = error instanceof Error ? error.message : '登录失败，请检查账号密码';
    errorMessage.value = msg;
    MessagePlugin.error(msg);
  } finally {
    loginLoading.value = false;
    submitLocked = false;
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
