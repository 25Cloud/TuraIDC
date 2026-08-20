<template>
  <auth-shell
    title="账号登录"
    nav-text="还没有账户？"
    nav-link-text="现在注册"
    :nav-to="registerLink"
    :hero-title="heroTitle"
    hero-description="登录后可继续查看实例、支付账单、提交工单，并维护账户安全与实名认证。"
  >
    <t-tabs v-model="loginMode" class="client-auth-tabs">
      <t-tab-panel value="password" label="密码登录" />
      <t-tab-panel value="code" label="验证码登录" />
    </t-tabs>

    <!-- 密码登录 -->
    <t-form
      v-if="loginMode === 'password'"
      ref="formRef"
      class="client-auth-form"
      :data="form"
      :rules="rules"
      label-width="0"
      @submit="handleLogin"
    >
      <t-form-item name="account">
        <div class="client-auth-field">
          <label class="client-auth-label is-required" for="login-account">手机号 / 邮箱</label>
          <t-input
            id="login-account"
            v-model="form.account"
            size="large"
            clearable
            autocomplete="username"
            placeholder="请输入手机号或邮箱"
            @enter="submitForm"
          >
            <template #prefix-icon><user-icon /></template>
          </t-input>
        </div>
      </t-form-item>

      <t-form-item name="password">
        <div class="client-auth-field">
          <label class="client-auth-label is-required" for="login-password">密码</label>
          <t-input
            id="login-password"
            v-model="form.password"
            size="large"
            :type="showPassword ? 'text' : 'password'"
            clearable
            autocomplete="current-password"
            placeholder="请输入登录密码"
            @enter="submitForm"
          >
            <template #prefix-icon><lock-on-icon /></template>
            <template #suffix-icon>
              <password-toggle v-model="showPassword" />
            </template>
          </t-input>
        </div>
      </t-form-item>

      <div class="client-auth-form__links">
        <router-link to="/client/forgot-password">忘记密码？</router-link>
      </div>

      <div v-show="enabled" ref="captchaContainer" class="client-auth-captcha"></div>

      <t-button block size="large" theme="primary" :loading="loading || captchaLoading" @click="submitForm"
        >登录</t-button
      >
    </t-form>

    <!-- 验证码登录 -->
    <t-form
      v-if="loginMode === 'code'"
      ref="codeFormRef"
      class="client-auth-form"
      :data="codeForm"
      :rules="codeRules"
      label-width="0"
      @submit="handleCodeLogin"
    >
      <t-form-item name="account">
        <div class="client-auth-field">
          <label class="client-auth-label is-required" for="login-code-account">手机号 / 邮箱</label>
          <t-input
            id="login-code-account"
            v-model="codeForm.account"
            size="large"
            clearable
            autocomplete="username"
            placeholder="请输入已注册的手机号或邮箱"
          >
            <template #prefix-icon><user-icon /></template>
          </t-input>
        </div>
      </t-form-item>

      <t-form-item name="code">
        <div class="client-auth-field">
          <label class="client-auth-label is-required" for="login-code">验证码</label>
          <div class="client-auth-code-row">
            <t-input
              v-model="codeForm.code"
              size="large"
              maxlength="6"
              placeholder="请输入验证码"
              @enter="submitCodeForm"
            />
            <t-button
              variant="outline"
              :disabled="countdown > 0"
              :loading="sendingCode || captchaLoading"
              @click="handleSendCode"
            >
              {{ countdown > 0 ? `${countdown}s` : '发送验证码' }}
            </t-button>
          </div>
        </div>
      </t-form-item>

      <div v-show="enabled" ref="captchaContainer" class="client-auth-captcha"></div>

      <t-button
        class="client-auth-submit"
        block
        size="large"
        theme="primary"
        :loading="codeLoading"
        @click="submitCodeForm"
        >登录</t-button
      >
    </t-form>
  </auth-shell>
</template>
<script setup lang="ts">
import { LockOnIcon, UserIcon } from 'tdesign-icons-vue-next';
import type { FormInstanceFunctions, FormRule, FormValidateMessage, SubmitContext } from 'tdesign-vue-next';
import { MessagePlugin } from 'tdesign-vue-next';
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';

import { clientAuthApi } from '@/api/auth';
import AuthShell from '@/components/auth/AuthShell.vue';
import PasswordToggle from '@/components/auth/PasswordToggle.vue';
import { useGeeTestCaptcha } from '@/composables/useGeeTestCaptcha';
import { useUserStore } from '@/store';
import { buildAccountPayload, detectAccountType, normalizeAccountValue } from '@/utils/account';
import { toUserMessage } from '@/utils/userMessage';

interface LoginForm {
  account: string;
  password: string;
}

interface RuntimeLoginError {
  __handled?: boolean;
  message?: string;
  response?: {
    data?: {
      data?: {
        captcha_required?: boolean;
      };
    };
  };
}

function asRuntimeLoginError(error: unknown): RuntimeLoginError {
  return typeof error === 'object' && error !== null ? (error as RuntimeLoginError) : {};
}

interface CodeLoginForm {
  account: string;
  code: string;
}

interface RuntimeCodeSendError {
  __handled?: boolean;
  message?: string;
}

const route = useRoute();
const router = useRouter();
const userStore = useUserStore();
const formRef = ref<FormInstanceFunctions<LoginForm>>();
const loading = ref(false);
const showPassword = ref(false);
const captchaContainer = ref<HTMLElement>();
const {
  enabled,
  loading: captchaLoading,
  runWithCaptcha,
  reinit,
} = useGeeTestCaptcha({
  appendTo: captchaContainer,
});

const form = reactive<LoginForm>({
  account: '',
  password: '',
});

const loginMode = ref<'password' | 'code'>('password');

// 切换登录方式时表单 v-if 重建会卸载旧容器，需重新渲染验证组件到新容器
watch(
  loginMode,
  () => {
    reinit();
  },
  { flush: 'post' },
);
const codeFormRef = ref<FormInstanceFunctions<CodeLoginForm>>();
const codeForm = reactive<CodeLoginForm>({ account: '', code: '' });
const codeLoading = ref(false);
const sendingCode = ref(false);
const countdown = ref(0);
let countdownTimer: ReturnType<typeof setInterval> | null = null;

const codeRules: Record<keyof CodeLoginForm, FormRule[]> = {
  account: [
    {
      validator: (value: string) => ({
        result: Boolean(detectAccountType(value)),
        message: '请输入正确的手机号或邮箱',
        type: 'error',
      }),
      trigger: 'blur',
    },
  ],
  code: [
    { required: true, message: '请输入验证码', type: 'error', trigger: 'blur' },
    { len: 6, message: '验证码为 6 位', type: 'error', trigger: 'blur' },
  ],
};

const redirectPath = computed(() => {
  const redirect = route.query.redirect;
  return typeof redirect === 'string' && redirect.startsWith('/') ? redirect : '/client/dashboard';
});

const registerLink = computed(() => ({
  path: '/client/register',
  query: route.query.redirect ? { redirect: route.query.redirect } : {},
}));

const heroTitle = '进入控制台，\n继续处理服务与账单';

const rules: Record<keyof LoginForm, FormRule[]> = {
  account: [
    {
      validator: (value: string) => ({
        result: Boolean(detectAccountType(value)),
        message: '请输入正确的手机号或邮箱',
        type: 'error',
      }),
      trigger: 'blur',
    },
  ],
  password: [{ required: true, message: '请输入登录密码', type: 'error', trigger: 'blur' }],
};

const isCaptchaRequiredError = (error: unknown) =>
  Boolean(asRuntimeLoginError(error).response?.data?.data?.captcha_required);

async function performLogin(captcha: unknown = null) {
  await userStore.clientLogin({
    account: normalizeAccountValue(form.account),
    password: form.password,
    ...(captcha ? { captcha } : {}),
  });
}

async function submitForm() {
  if (!validateForm()) {
    return;
  }
  await runLogin();
}

async function handleLogin(ctx: SubmitContext) {
  if (ctx.validateResult !== true || !validateForm()) return;
  await runLogin();
}

function setFormErrors(errors: Partial<Record<keyof LoginForm, string>>) {
  const validateMessage: FormValidateMessage<LoginForm> = {
    account: errors.account ? [{ type: 'error', message: errors.account }] : [],
    password: errors.password ? [{ type: 'error', message: errors.password }] : [],
  };
  formRef.value?.setValidateMessage(validateMessage);
}

function validateForm() {
  const errors: Partial<Record<keyof LoginForm, string>> = {};
  if (!detectAccountType(form.account)) {
    errors.account = '请输入正确的手机号或邮箱';
  }
  if (!form.password) {
    errors.password = '请输入登录密码';
  }

  if (Object.keys(errors).length > 0) {
    setFormErrors(errors);
    return false;
  }

  formRef.value?.clearValidate();
  return true;
}

async function runLogin() {
  loading.value = true;
  try {
    if (enabled.value) {
      await runWithCaptcha(
        async (captcha: unknown) => {
          await performLogin(captcha);
        },
        { required: true },
      );
    } else {
      await performLogin();
    }
    MessagePlugin.success('登录成功');
    await router.push(redirectPath.value);
  } catch (error: unknown) {
    const runtimeError = asRuntimeLoginError(error);
    if (!enabled.value && isCaptchaRequiredError(runtimeError)) {
      try {
        await runWithCaptcha(
          async (captcha: unknown) => {
            await performLogin(captcha);
          },
          { required: true },
        );
        MessagePlugin.success('登录成功');
        await router.push(redirectPath.value);
        return;
      } catch (captchaError: unknown) {
        const runtimeCaptchaError = asRuntimeLoginError(captchaError);
        if (!runtimeCaptchaError.__handled) {
          MessagePlugin.error(toUserMessage(runtimeCaptchaError.message, '登录失败'));
        }
        return;
      }
    }

    if (!runtimeError.__handled) {
      MessagePlugin.error(toUserMessage(runtimeError.message, '登录失败'));
    }
  } finally {
    loading.value = false;
  }
}

function clearTimer() {
  if (countdownTimer) {
    clearInterval(countdownTimer);
    countdownTimer = null;
  }
}

function startCountdown() {
  clearTimer();
  countdown.value = 60;
  countdownTimer = setInterval(() => {
    countdown.value -= 1;
    if (countdown.value <= 0) {
      clearTimer();
    }
  }, 1000);
}

async function handleSendCode() {
  const accountPayload = buildAccountPayload(codeForm.account);
  if (!accountPayload) {
    MessagePlugin.warning('请先输入正确的手机号或邮箱');
    return;
  }

  sendingCode.value = true;
  try {
    await runWithCaptcha(async (captcha: unknown) => {
      if (accountPayload.accountType === 'phone') {
        await clientAuthApi.sendPhoneCode({ phone: accountPayload.phone, purpose: 'login', captcha });
      } else {
        await clientAuthApi.sendEmailCode({ email: accountPayload.email, purpose: 'login', captcha });
      }
    });

    MessagePlugin.success(`${accountPayload.accountType === 'phone' ? '短信' : '邮箱'}验证码已发送`);
    startCountdown();
  } catch (error: unknown) {
    const runtimeError = error as RuntimeCodeSendError;
    if (!runtimeError.__handled) {
      MessagePlugin.error(toUserMessage(runtimeError.message, '验证码发送失败'));
    }
  } finally {
    sendingCode.value = false;
  }
}

async function handleCodeLogin(ctx: SubmitContext) {
  if (ctx.validateResult !== true || !validateCodeForm()) return;
  await runCodeLogin();
}

function validateCodeForm() {
  const errors: Partial<Record<keyof CodeLoginForm, string>> = {};
  if (!detectAccountType(codeForm.account)) {
    errors.account = '请输入正确的手机号或邮箱';
  }
  if (!codeForm.code || codeForm.code.length !== 6) {
    errors.code = codeForm.code ? '验证码为 6 位' : '请输入验证码';
  }

  if (Object.keys(errors).length > 0) {
    const validateMessage: FormValidateMessage<CodeLoginForm> = {
      account: errors.account ? [{ type: 'error', message: errors.account }] : [],
      code: errors.code ? [{ type: 'error', message: errors.code }] : [],
    };
    codeFormRef.value?.setValidateMessage(validateMessage);
    return false;
  }

  codeFormRef.value?.clearValidate();
  return true;
}

async function runCodeLogin() {
  codeLoading.value = true;
  try {
    await userStore.clientLoginByCode({
      account: normalizeAccountValue(codeForm.account),
      code: codeForm.code,
    });
    MessagePlugin.success('登录成功');
    await router.push(redirectPath.value);
  } catch (error: unknown) {
    const runtimeError = error as RuntimeLoginError;
    if (!runtimeError.__handled) {
      MessagePlugin.error(toUserMessage(runtimeError.message, '登录失败'));
    }
  } finally {
    codeLoading.value = false;
  }
}

async function submitCodeForm() {
  if (!validateCodeForm()) return;
  await runCodeLogin();
}

onMounted(() => {
  if (typeof route.query.account === 'string') {
    form.account = route.query.account;
  }
});

onBeforeUnmount(() => {
  clearTimer();
});
</script>
<style scoped lang="less">
@import './shared-auth.less';

.client-auth-code-row {
  display: flex;
  align-items: stretch;
  gap: 0.75rem;

  :deep(.t-input) {
    flex: 1;
  }

  :deep(.t-button) {
    height: auto;
    min-height: unset;
  }
}

.client-auth-tabs {
  margin-bottom: 1rem;

  :deep(.t-tabs__nav-item) {
    font-size: 0.9375rem;
  }
}

.client-auth-submit {
  margin-top: 0.5rem;
}
</style>
