<template>
  <auth-shell
    title="免费注册"
    nav-text="已经有账户？"
    nav-link-text="立即登录"
    :nav-to="loginLink"
    cta-text="查看产品目录"
    cta-to="/products"
    hero-title="注册后即可进入控制台开始购买"
    hero-description="完成开户注册后，可以继续创建账单、管理服务，并处理实名认证与账户安全设置。"
  >
    <t-form ref="formRef" class="client-auth-form" :data="form" :rules="rules" label-width="0" @submit="handleRegister">
      <t-form-item name="account">
        <div class="client-auth-field">
          <label class="client-auth-label is-required" for="register-account">手机号 / 邮箱</label>
          <t-input
            id="register-account"
            v-model="form.account"
            size="large"
            clearable
            autocomplete="username"
            placeholder="请输入手机号或邮箱"
          />
        </div>
      </t-form-item>

      <t-form-item name="code">
        <div class="client-auth-field">
          <label class="client-auth-label is-required" for="register-code">验证码</label>
          <div class="client-auth-code-row">
            <t-input id="register-code" v-model="form.code" size="large" maxlength="6" placeholder="请输入验证码" />
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

      <t-form-item name="nickname">
        <div class="client-auth-field">
          <label class="client-auth-label" for="register-nickname">用户名</label>
          <t-input
            id="register-nickname"
            v-model="form.nickname"
            size="large"
            maxlength="50"
            placeholder="选填，最多 50 个字符"
          />
        </div>
      </t-form-item>

      <t-form-item name="referral_code">
        <div class="client-auth-field">
          <label class="client-auth-label" for="register-referral">推荐码</label>
          <t-input
            id="register-referral"
            v-model="form.referral_code"
            size="large"
            maxlength="24"
            placeholder="选填，如有邀请推荐可填写"
          />
        </div>
      </t-form-item>

      <t-form-item name="password">
        <div class="client-auth-field">
          <label class="client-auth-label is-required" for="register-password">登录密码</label>
          <t-input
            id="register-password"
            v-model="form.password"
            size="large"
            :type="showPassword ? 'text' : 'password'"
            clearable
            autocomplete="new-password"
            placeholder="请输入至少 8 位密码"
          >
            <template #prefix-icon><lock-on-icon /></template>
            <template #suffix-icon>
              <password-toggle v-model="showPassword" />
            </template>
          </t-input>
        </div>
      </t-form-item>

      <t-form-item name="password_confirmation">
        <div class="client-auth-field">
          <label class="client-auth-label is-required" for="register-confirm-password">确认密码</label>
          <t-input
            id="register-confirm-password"
            v-model="form.password_confirmation"
            size="large"
            :type="showConfirmPassword ? 'text' : 'password'"
            clearable
            autocomplete="new-password"
            placeholder="请再次输入密码"
            @enter="submitForm"
          >
            <template #prefix-icon><lock-on-icon /></template>
            <template #suffix-icon>
              <password-toggle v-model="showConfirmPassword" />
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
        注册并进入控制台
      </t-button>
    </t-form>
  </auth-shell>
</template>
<script setup lang="ts">
import { LockOnIcon } from 'tdesign-icons-vue-next';
import type { FormInstanceFunctions, FormRule, FormValidateMessage, SubmitContext } from 'tdesign-vue-next';
import { MessagePlugin } from 'tdesign-vue-next';
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';

import { clientAuthApi } from '@/api/auth';
import AuthShell from '@/components/auth/AuthShell.vue';
import PasswordToggle from '@/components/auth/PasswordToggle.vue';
import { useGeeTestCaptcha } from '@/composables/useGeeTestCaptcha';
import { useUserStore } from '@/store';
import { buildAccountPayload, detectAccountType, normalizeAccountValue } from '@/utils/account';
import { toUserMessage } from '@/utils/userMessage';

interface RegisterForm {
  account: string;
  code: string;
  nickname: string;
  referral_code: string;
  password: string;
  password_confirmation: string;
}

interface RuntimeHandledError {
  __handled?: boolean;
  message?: string;
}

const route = useRoute();
const router = useRouter();
const userStore = useUserStore();
const formRef = ref<FormInstanceFunctions<RegisterForm>>();
const loading = ref(false);
const sendingCode = ref(false);
const countdown = ref(0);
const showPassword = ref(false);
const showConfirmPassword = ref(false);
// 验证 SDK 在点击提交时才加载。渲染形态由后端下发：
// popup（极验）由插件自行弹窗，inline（Turnstile）则渲染进按钮上方的容器。
const captchaContainer = ref<HTMLElement>();
const {
  loading: captchaLoading,
  renderMode,
  runWithCaptcha,
  prepare: prepareCaptcha,
} = useGeeTestCaptcha({
  appendTo: captchaContainer,
  onPrompt: () => MessagePlugin.warning('请先完成人机验证'),
  // 本页涉及注册提交与发码两类动作，任一场景开启即需要验证
  scenes: ['client_register', 'email_code', 'phone_code'],
});
let countdownTimer: ReturnType<typeof setInterval> | null = null;

const form = reactive<RegisterForm>({
  account: '',
  code: '',
  nickname: '',
  referral_code: '',
  password: '',
  password_confirmation: '',
});

const loginLink = computed(() => ({
  path: '/client/login',
  query: route.query.redirect ? { redirect: route.query.redirect } : {},
}));

const redirectPath = computed(() => {
  const redirect = route.query.redirect;
  return typeof redirect === 'string' && redirect.startsWith('/') ? redirect : '/client/dashboard';
});

const accountRule: FormRule = {
  validator: (value: string) => ({
    result: Boolean(detectAccountType(value)),
    message: '请输入正确的手机号或邮箱',
    type: 'error',
  }),
  trigger: 'blur',
};

const rules: Record<keyof RegisterForm, FormRule[]> = {
  account: [accountRule],
  code: [
    { required: true, message: '请输入验证码', type: 'error', trigger: 'blur' },
    { len: 6, message: '验证码为 6 位', type: 'error', trigger: 'blur' },
  ],
  nickname: [{ max: 50, message: '用户名不能超过 50 个字符', type: 'error', trigger: 'blur' }],
  referral_code: [{ max: 24, message: '推荐码不能超过 24 个字符', type: 'error', trigger: 'blur' }],
  password: [
    { required: true, message: '请输入登录密码', type: 'error', trigger: 'blur' },
    // 与后端 RegisterRequest 的 min:8 对齐；写 6 会让用户输 6-7 位时前端放行、后端 422。
    { min: 8, message: '密码长度不能少于 8 位', type: 'error', trigger: 'blur' },
  ],
  password_confirmation: [
    { required: true, message: '请再次输入密码', type: 'error', trigger: 'blur' },
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
    // 发码只看 email_code / phone_code，不受本页 client_register 开关牵连
    await runWithCaptcha(
      async (captcha: unknown) => {
        if (accountPayload.accountType === 'phone') {
          await clientAuthApi.sendPhoneCode({ phone: accountPayload.phone, purpose: 'register', captcha });
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
      MessagePlugin.error(toUserMessage(runtimeError.message, '验证码发送失败'));
    }
  } finally {
    sendingCode.value = false;
  }
}

async function submitForm() {
  if (!validateForm()) {
    return;
  }
  await runRegister();
}

async function handleRegister(ctx: SubmitContext) {
  if (ctx.validateResult !== true || !validateForm()) return;
  await runRegister();
}

function setFormErrors(errors: Partial<Record<keyof RegisterForm, string>>) {
  const validateMessage: FormValidateMessage<RegisterForm> = {
    account: errors.account ? [{ type: 'error', message: errors.account }] : [],
    code: errors.code ? [{ type: 'error', message: errors.code }] : [],
    nickname: errors.nickname ? [{ type: 'error', message: errors.nickname }] : [],
    referral_code: errors.referral_code ? [{ type: 'error', message: errors.referral_code }] : [],
    password: errors.password ? [{ type: 'error', message: errors.password }] : [],
    password_confirmation: errors.password_confirmation
      ? [{ type: 'error', message: errors.password_confirmation }]
      : [],
  };
  formRef.value?.setValidateMessage(validateMessage);
}

function validateForm() {
  const errors: Partial<Record<keyof RegisterForm, string>> = {};
  if (!detectAccountType(form.account)) {
    errors.account = '请输入正确的手机号或邮箱';
  }
  if (!form.code) {
    errors.code = '请输入验证码';
  } else if (form.code.length !== 6) {
    errors.code = '验证码为 6 位';
  }
  if (form.nickname.length > 50) {
    errors.nickname = '用户名不能超过 50 个字符';
  }
  if (form.referral_code.length > 24) {
    errors.referral_code = '推荐码不能超过 24 个字符';
  }
  if (!form.password) {
    errors.password = '请输入登录密码';
  } else if (form.password.length < 8) {
    errors.password = '密码长度不能少于 8 位';
  }
  if (!form.password_confirmation) {
    errors.password_confirmation = '请再次输入密码';
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

async function runRegister() {
  loading.value = true;
  try {
    // 注册提交需要独立的人机验证：发码时那次的 token 已被一次性消费，
    // 这里取的是组件重置后新解出的结果。注册是刷号主入口，后端默认要求验证。
    await runWithCaptcha(
      async (captcha: unknown) => {
        await userStore.clientRegister({
          account: normalizeAccountValue(form.account),
          code: form.code,
          nickname: form.nickname || undefined,
          referral_code: form.referral_code || undefined,
          password: form.password,
          password_confirmation: form.password_confirmation,
          ...(captcha ? { captcha } : {}),
        });
      },
      { scene: 'client_register' },
    );
    MessagePlugin.success('注册成功');
    await router.push(redirectPath.value);
  } catch (error: unknown) {
    const runtimeError = error as RuntimeHandledError;
    if (!runtimeError.__handled) {
      MessagePlugin.error(toUserMessage(runtimeError.message, '注册失败'));
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
