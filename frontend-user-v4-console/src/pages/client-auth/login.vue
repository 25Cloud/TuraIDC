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

      <!-- inline 形态（Turnstile）的验证组件落点：点击登录时就地加载，无感通过时不占位 -->
      <div v-show="renderMode === 'inline'" ref="captchaContainer" class="client-auth-captcha"></div>

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


      <!-- 同上：验证码登录表单的 inline 验证组件落点 -->
      <div v-show="renderMode === 'inline'" ref="captchaContainer" class="client-auth-captcha"></div>

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
// 验证 SDK 在点击提交时才加载。渲染形态由后端下发：
// popup（极验）由插件自行弹窗，inline（Turnstile）则渲染进下方容器。
const captchaContainer = ref<HTMLElement>();
const {
  enabled,
  loading: captchaLoading,
  renderMode,
  reinit,
  runWithCaptcha,
} = useGeeTestCaptcha({
  appendTo: captchaContainer,
  onPrompt: () => MessagePlugin.warning('请先完成人机验证'),
  // 本页涉及密码登录与验证码登录的发码动作，任一场景开启即需要验证
  scenes: ['client_login', 'email_code', 'phone_code'],
});

const form = reactive<LoginForm>({
  account: '',
  password: '',
});

const loginMode = ref<'password' | 'code'>('password');

// 切换登录方式时表单 v-if 会重建 DOM，inline 形态的容器随之失效；
// 丢弃已初始化的实例，下次提交时重新绑定到新容器。
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
    // 密码登录只看 client_login 场景。此前用页面级 enabled.value 判定，
    // 而它是「本页任一场景开启」，导致只开发码场景时密码登录也被要求验证。
    // 场景关闭或插件未启用时 verify() 返回 null，performLogin 照常不带 captcha 执行。
    await runWithCaptcha(
      async (captcha: unknown) => {
        await performLogin(captcha);
      },
      { scene: 'client_login' },
    );
    MessagePlugin.success('登录成功');
    await router.push(redirectPath.value);
  } catch (error: unknown) {
    const runtimeError = asRuntimeLoginError(error);
    // 后端口径优先：只要它以 captcha_required 索要验证，就带验证码重试一次，
    // 不再附加 !enabled.value 条件（场景开关与后端判定不一致时也要能自愈）
    if (isCaptchaRequiredError(runtimeError)) {
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
    // 按本次动作对应的单一场景判定：发码只看 email_code / phone_code，
    // 不受本页 client_login 开关的牵连（反之亦然）
    await runWithCaptcha(
      async (captcha: unknown) => {
        if (accountPayload.accountType === 'phone') {
          await clientAuthApi.sendPhoneCode({ phone: accountPayload.phone, purpose: 'login', captcha });
        } else {
          await clientAuthApi.sendEmailCode({ email: accountPayload.email, purpose: 'login', captcha });
        }
      },
      { scene: accountPayload.accountType === 'phone' ? 'phone_code' : 'email_code' },
    );

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
