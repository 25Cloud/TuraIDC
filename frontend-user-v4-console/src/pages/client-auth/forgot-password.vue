<template>
  <auth-shell
    title="找回密码"
    nav-text="想起密码了？"
    nav-link-text="返回登录"
    :nav-to="loginLink"
    cta-text="返回产品页"
    cta-to="/products"
    hero-title="重置密码后，继续进入控制台"
    hero-description="找回登录凭证后，可继续处理服务、账单与账户安全设置。"
  >
    <t-form ref="formRef" class="client-auth-form" :data="form" :rules="rules" label-width="0" @submit="handleSubmit">
      <t-form-item name="account">
        <div class="client-auth-field">
          <label class="client-auth-label is-required">手机号 / 邮箱</label>
          <t-input
            v-model="form.account"
            size="large"
            clearable
            autocomplete="username"
            placeholder="请输入注册手机号或邮箱"
          />
        </div>
      </t-form-item>

      <t-form-item name="code">
        <div class="client-auth-field">
          <label class="client-auth-label is-required">验证码</label>
          <div class="client-auth-code-row">
            <t-input v-model="form.code" size="large" maxlength="6" placeholder="请输入验证码" />
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

      <t-form-item name="password">
        <div class="client-auth-field">
          <label class="client-auth-label is-required">新密码</label>
          <t-input
            v-model="form.password"
            size="large"
            :type="showPassword ? 'text' : 'password'"
            clearable
            autocomplete="new-password"
            placeholder="请输入至少 8 位新密码"
          >
            <template #prefix-icon><lock-on-icon /></template>
            <template #suffix-icon>
              <browse-icon v-if="showPassword" class="client-auth-password-icon" @click="showPassword = false" />
              <browse-off-icon v-else class="client-auth-password-icon" @click="showPassword = true" />
            </template>
          </t-input>
        </div>
      </t-form-item>

      <t-form-item name="password_confirmation">
        <div class="client-auth-field">
          <label class="client-auth-label is-required">确认新密码</label>
          <t-input
            v-model="form.password_confirmation"
            size="large"
            :type="showConfirmPassword ? 'text' : 'password'"
            clearable
            autocomplete="new-password"
            placeholder="请再次输入新密码"
            @enter="submitForm"
          >
            <template #prefix-icon><lock-on-icon /></template>
            <template #suffix-icon>
              <browse-icon
                v-if="showConfirmPassword"
                class="client-auth-password-icon"
                @click="showConfirmPassword = false"
              />
              <browse-off-icon v-else class="client-auth-password-icon" @click="showConfirmPassword = true" />
            </template>
          </t-input>
        </div>
      </t-form-item>

      <!-- inline 形态（Turnstile）的验证组件落点：点击时就地加载，无感通过时不占位 -->
      <div v-show="renderMode === 'inline'" ref="captchaContainer" class="client-auth-captcha"></div>

      <t-button
        class="client-auth-submit"
        block
        size="large"
        theme="primary"
        :loading="loading || captchaLoading"
        @click="submitForm"
      >
        重置密码
      </t-button>
    </t-form>
  </auth-shell>
</template>
<script setup lang="ts">
import { BrowseIcon, BrowseOffIcon, LockOnIcon } from 'tdesign-icons-vue-next';
import type { FormInstanceFunctions, FormRule, FormValidateMessage, SubmitContext } from 'tdesign-vue-next';
import { MessagePlugin } from 'tdesign-vue-next';
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';

import { clientAuthApi } from '@/api/auth';
import AuthShell from '@/components/auth/AuthShell.vue';
import { useGeeTestCaptcha } from '@/composables/useGeeTestCaptcha';
import { buildAccountPayload, detectAccountType, normalizeAccountValue } from '@/utils/account';

interface ResetPasswordForm {
  account: string;
  code: string;
  password: string;
  password_confirmation: string;
}

interface RuntimeHandledError {
  __handled?: boolean;
  message?: string;
}

const route = useRoute();
const router = useRouter();
const formRef = ref<FormInstanceFunctions<ResetPasswordForm>>();
const loading = ref(false);
const sendingCode = ref(false);
const countdown = ref(0);
const showPassword = ref(false);
const showConfirmPassword = ref(false);
// 验证 SDK 在点击发送验证码时才加载。渲染形态由后端下发：
// popup（极验）自行弹窗，inline（Turnstile）渲染进按钮上方容器。
// 重置密码本身不再要求人机验证（发码环节已验过），本页只需覆盖发码场景。
const captchaContainer = ref<HTMLElement>();
const {
  loading: captchaLoading,
  renderMode,
  runWithCaptcha,
  prepare: prepareCaptcha,
} = useGeeTestCaptcha({
  appendTo: captchaContainer,
  onPrompt: () => MessagePlugin.warning('请先完成人机验证'),
  scenes: ['email_code', 'phone_code'],
});
let countdownTimer: ReturnType<typeof setInterval> | null = null;

const loginLink = computed(() => ({
  path: '/client/login',
  query: route.query.redirect ? { redirect: route.query.redirect } : {},
}));

const form = reactive<ResetPasswordForm>({
  account: '',
  code: '',
  password: '',
  password_confirmation: '',
});

const rules: Record<keyof ResetPasswordForm, FormRule[]> = {
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
  password: [
    { required: true, message: '请输入新密码', type: 'error', trigger: 'blur' },
    // 与后端 ResetPasswordRequest 的 min:8 对齐。
    { min: 8, message: '密码长度不能少于 8 位', type: 'error', trigger: 'blur' },
  ],
  password_confirmation: [
    { required: true, message: '请再次输入新密码', type: 'error', trigger: 'blur' },
    {
      validator: (value: string) => ({
        result: value === form.password,
        message: '两次输入的密码不一致',
        type: 'error',
      }),
      trigger: 'blur',
    },
  ],
};

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
      countdown.value = 0;
    }
  }, 1000);
}

async function handleSendCode() {
  const accountPayload = buildAccountPayload(form.account);
  if (!accountPayload) {
    MessagePlugin.warning('请先输入正确的手机号或邮箱');
    return;
  }

  sendingCode.value = true;
  try {
    // 发码按账号类型取单一场景，避免邮箱与手机两个开关互相牵连
    await runWithCaptcha(
      async (captcha: unknown) => {
        if (accountPayload.accountType === 'phone') {
          await clientAuthApi.sendPhoneCode({ phone: accountPayload.phone, purpose: 'reset_password', captcha });
        } else {
          await clientAuthApi.sendEmailCode({ email: accountPayload.email, captcha });
        }
      },
      { scene: accountPayload.accountType === 'phone' ? 'phone_code' : 'email_code' },
    );

    MessagePlugin.success(`${accountPayload.accountType === 'phone' ? '短信' : '邮箱'}验证码已发送`);
    startCountdown();
  } catch (error: unknown) {
    const runtimeError = error as RuntimeHandledError;
    if (!runtimeError.__handled) {
      MessagePlugin.error(runtimeError.message || '验证码发送失败');
    }
  } finally {
    sendingCode.value = false;
  }
}

async function submitForm() {
  if (!validateForm()) {
    return;
  }
  await runResetPassword();
}

async function handleSubmit(ctx: SubmitContext) {
  if (ctx.validateResult !== true || !validateForm()) return;
  await runResetPassword();
}

function setFormErrors(errors: Partial<Record<keyof ResetPasswordForm, string>>) {
  const validateMessage: FormValidateMessage<ResetPasswordForm> = {
    account: errors.account ? [{ type: 'error', message: errors.account }] : [],
    code: errors.code ? [{ type: 'error', message: errors.code }] : [],
    password: errors.password ? [{ type: 'error', message: errors.password }] : [],
    password_confirmation: errors.password_confirmation
      ? [{ type: 'error', message: errors.password_confirmation }]
      : [],
  };
  formRef.value?.setValidateMessage(validateMessage);
}

function validateForm() {
  const errors: Partial<Record<keyof ResetPasswordForm, string>> = {};
  if (!detectAccountType(form.account)) {
    errors.account = '请输入正确的手机号或邮箱';
  }
  if (!form.code) {
    errors.code = '请输入验证码';
  } else if (form.code.length !== 6) {
    errors.code = '验证码为 6 位';
  }
  if (!form.password) {
    errors.password = '请输入新密码';
  } else if (form.password.length < 8) {
    errors.password = '密码长度不能少于 8 位';
  }
  if (!form.password_confirmation) {
    errors.password_confirmation = '请再次输入新密码';
  } else if (form.password_confirmation !== form.password) {
    errors.password_confirmation = '两次输入的密码不一致';
  }

  if (Object.keys(errors).length > 0) {
    setFormErrors(errors);
    return false;
  }

  formRef.value?.clearValidate();
  return true;
}

async function runResetPassword() {
  loading.value = true;
  try {
    await clientAuthApi.resetPassword({
      account: normalizeAccountValue(form.account),
      code: form.code,
      password: form.password,
      password_confirmation: form.password_confirmation,
    });
    MessagePlugin.success('密码已重置，请重新登录');
    await router.push({
      path: '/client/login',
      query: { account: normalizeAccountValue(form.account) },
    });
  } catch (error: unknown) {
    const runtimeError = error as RuntimeHandledError;
    if (!runtimeError.__handled) {
      MessagePlugin.error(runtimeError.message || '密码重置失败');
    }
  } finally {
    loading.value = false;
  }
}

onMounted(() => {
  // inline 形态：页面加载即渲染验证组件，用户可提前完成挑战并保留已验证状态
  if (renderMode.value === 'inline') {
    void prepareCaptcha();
  }
});

onBeforeUnmount(() => {
  clearTimer();
});
</script>
<style scoped lang="less">
@import './shared-auth.less';
</style>
