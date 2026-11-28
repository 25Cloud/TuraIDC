<template>
  <auth-shell
    :title="$t('clientAuth.register.title')"
    :nav-text="$t('clientAuth.register.hasAccount')"
    :nav-link-text="$t('clientAuth.register.signInNow')"
    :nav-to="loginLink"
    :cta-text="$t('clientAuth.register.viewCatalog')"
    cta-to="/products"
    :hero-title="$t('clientAuth.register.heroTitle')"
    :hero-description="$t('clientAuth.register.heroDescription')"
  >
    <t-form ref="formRef" class="client-auth-form" :data="form" :rules="rules" label-width="0" @submit="handleRegister">
      <t-form-item name="account">
        <div class="client-auth-field">
          <label class="client-auth-label is-required" for="register-account">{{
            $t('clientAuth.register.accountLabel')
          }}</label>
          <t-input
            id="register-account"
            v-model="form.account"
            size="large"
            clearable
            autocomplete="username"
            :placeholder="$t('clientAuth.register.accountPlaceholder')"
          />
        </div>
      </t-form-item>

      <t-form-item name="code">
        <div class="client-auth-field">
          <label class="client-auth-label is-required" for="register-code">{{
            $t('clientAuth.register.codeLabel')
          }}</label>
          <div class="client-auth-code-row">
            <t-input
              id="register-code"
              v-model="form.code"
              size="large"
              maxlength="6"
              :placeholder="$t('clientAuth.register.codePlaceholder')"
            />
            <t-button
              variant="outline"
              :disabled="countdown > 0"
              :loading="sendingCode || captchaLoading"
              @click="handleSendCode"
            >
              {{ countdown > 0 ? `${countdown}s` : $t('clientAuth.register.sendCode') }}
            </t-button>
          </div>
        </div>
      </t-form-item>

      <t-form-item name="nickname">
        <div class="client-auth-field">
          <label class="client-auth-label" for="register-nickname">{{ $t('clientAuth.register.nicknameLabel') }}</label>
          <t-input
            id="register-nickname"
            v-model="form.nickname"
            size="large"
            maxlength="50"
            :placeholder="$t('clientAuth.register.nicknamePlaceholder')"
          />
        </div>
      </t-form-item>

      <t-form-item name="referral_code">
        <div class="client-auth-field">
          <label class="client-auth-label" for="register-referral">{{ $t('clientAuth.register.referralLabel') }}</label>
          <t-input
            id="register-referral"
            v-model="form.referral_code"
            size="large"
            maxlength="24"
            :placeholder="$t('clientAuth.register.referralPlaceholder')"
          />
        </div>
      </t-form-item>

      <t-form-item name="password">
        <div class="client-auth-field">
          <label class="client-auth-label is-required" for="register-password">{{
            $t('clientAuth.register.passwordLabel')
          }}</label>
          <t-input
            id="register-password"
            v-model="form.password"
            size="large"
            :type="showPassword ? 'text' : 'password'"
            clearable
            autocomplete="new-password"
            :placeholder="$t('clientAuth.register.passwordPlaceholder')"
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
          <label class="client-auth-label is-required" for="register-confirm-password">{{
            $t('clientAuth.register.confirmPasswordLabel')
          }}</label>
          <t-input
            id="register-confirm-password"
            v-model="form.password_confirmation"
            size="large"
            :type="showConfirmPassword ? 'text' : 'password'"
            clearable
            autocomplete="new-password"
            :placeholder="$t('clientAuth.register.confirmPasswordPlaceholder')"
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

      <!-- 注册必须显式同意服务条款与隐私政策：既是最低合规要求，也是出事时的举证依据 -->
      <t-form-item>
        <t-checkbox v-model="form.agreed" class="client-auth-agree">
          <span>
            {{ t('clientAuth.register.agreePrefix') }}
            <a :href="termsLink" target="_blank" rel="noopener noreferrer">{{ t('clientAuth.register.termsName') }}</a>
            {{ t('clientAuth.register.agreeAnd') }}
            <a :href="privacyLink" target="_blank" rel="noopener noreferrer">{{
              t('clientAuth.register.privacyName')
            }}</a>
          </span>
        </t-checkbox>
        <p v-if="agreedError" class="client-auth-agree__error">{{ agreedError }}</p>
      </t-form-item>

      <t-button
        class="client-auth-submit"
        block
        size="large"
        theme="primary"
        :loading="loading || captchaLoading"
        @click="submitForm"
      >
        {{ $t('clientAuth.register.submit') }}
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
import { useSiteBrandingStore } from '@/app/stores/siteBranding';
import AuthShell from '@/components/auth/AuthShell.vue';
import PasswordToggle from '@/components/auth/PasswordToggle.vue';
import { useGeeTestCaptcha } from '@/composables/useGeeTestCaptcha';
import { t } from '@/locales';
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
  /** 是否已勾选同意服务条款与隐私政策；不勾不允许提交 */
  agreed: boolean;
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
  onPrompt: () => MessagePlugin.warning(t('clientAuth.register.captchaRequired')),
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
  agreed: false,
});

const loginLink = computed(() => ({
  path: '/client/login',
  query: route.query.redirect ? { redirect: route.query.redirect } : {},
}));

const redirectPath = computed(() => {
  const redirect = route.query.redirect;
  return typeof redirect === 'string' && redirect.startsWith('/') ? redirect : '/client/dashboard';
});

/**
 * 条款链接：优先用站点配置的地址，没配就落到官网对应页面。
 * 官网首页地址来自 frontend_url，两者都是站点级配置，不在前端硬编码域名。
 */
const siteBranding = useSiteBrandingStore();
const agreedError = ref('');

const officialBase = computed(() => String(siteBranding.frontendUrl || '').replace(/\/+$/, ''));

const termsLink = computed(
  () => siteBranding.termsUrl || (officialBase.value ? `${officialBase.value}/terms` : '/terms'),
);
const privacyLink = computed(
  () => siteBranding.privacyUrl || (officialBase.value ? `${officialBase.value}/privacy` : '/privacy'),
);

const accountRule: FormRule = {
  validator: (value: string) => ({
    result: Boolean(detectAccountType(value)),
    message: t('clientAuth.register.accountInvalid'),
    type: 'error',
  }),
  trigger: 'blur',
};

const rules: Record<keyof RegisterForm, FormRule[]> = {
  account: [accountRule],
  code: [
    { required: true, message: t('clientAuth.register.codePlaceholder'), type: 'error', trigger: 'blur' },
    { len: 6, message: t('clientAuth.register.codeLength'), type: 'error', trigger: 'blur' },
  ],
  nickname: [{ max: 50, message: t('clientAuth.register.nicknameTooLong'), type: 'error', trigger: 'blur' }],
  referral_code: [{ max: 24, message: t('clientAuth.register.referralCodeTooLong'), type: 'error', trigger: 'blur' }],
  password: [
    { required: true, message: t('clientAuth.register.passwordRequired'), type: 'error', trigger: 'blur' },
    // 与后端 RegisterRequest 的 min:8 对齐；写 6 会让用户输 6-7 位时前端放行、后端 422。
    { min: 8, message: t('clientAuth.register.passwordTooShort'), type: 'error', trigger: 'blur' },
  ],
  password_confirmation: [
    { required: true, message: t('clientAuth.register.confirmPasswordPlaceholder'), type: 'error', trigger: 'blur' },
    {
      validator: (value: string) => ({
        result: value === form.password,
        message: t('clientAuth.register.passwordMismatch'),
        type: 'error',
      }),
      trigger: 'blur',
    },
  ],
  // 同意条款是 boolean，用 validateForm 里的显式检查报错，这里留空规则占位
  agreed: [],
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
    MessagePlugin.warning(t('clientAuth.register.accountFirst'));
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

    MessagePlugin.success(
      accountPayload.accountType === 'phone' ? t('clientAuth.codeSentSms') : t('clientAuth.codeSentEmail'),
    );
    startCountdown();
  } catch (error: unknown) {
    const runtimeError = error as RuntimeHandledError;
    if (!runtimeError.__handled) {
      MessagePlugin.error(toUserMessage(runtimeError.message, t('clientAuth.codeSendFailed')));
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
    // 同意条款的提示走表单外的 agreedError，这里占位保持类型完整
    agreed: [],
  };
  formRef.value?.setValidateMessage(validateMessage);
}

function validateForm() {
  const errors: Partial<Record<keyof RegisterForm, string>> = {};
  agreedError.value = '';
  if (!form.agreed) {
    agreedError.value = t('clientAuth.register.agreementRequired');
  }
  if (!detectAccountType(form.account)) {
    errors.account = t('clientAuth.register.accountInvalid');
  }
  if (!form.code) {
    errors.code = t('clientAuth.register.codeRequired');
  } else if (form.code.length !== 6) {
    errors.code = t('clientAuth.register.codeLength');
  }
  if (form.nickname.length > 50) {
    errors.nickname = t('clientAuth.register.nicknameTooLong');
  }
  if (form.referral_code.length > 24) {
    errors.referral_code = t('clientAuth.register.referralCodeTooLong');
  }
  if (!form.password) {
    errors.password = t('clientAuth.register.passwordRequired');
  } else if (form.password.length < 8) {
    errors.password = t('clientAuth.register.passwordTooShort');
  }
  if (!form.password_confirmation) {
    errors.password_confirmation = t('clientAuth.register.confirmPasswordRequired');
  } else if (form.password_confirmation !== form.password) {
    errors.password_confirmation = t('clientAuth.register.passwordMismatch');
  }

  if (Object.keys(errors).length > 0) {
    setFormErrors(errors);
    return false;
  }

  if (agreedError.value) {
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
    MessagePlugin.success(t('clientAuth.register.success'));
    await router.push(redirectPath.value);
  } catch (error: unknown) {
    const runtimeError = error as RuntimeHandledError;
    if (!runtimeError.__handled) {
      MessagePlugin.error(toUserMessage(runtimeError.message, t('clientAuth.register.failed')));
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

.client-auth-agree {
  align-items: flex-start;
  font-size: 0.8125rem;
  line-height: 1.5;
}

/* 条款链接与页面其他链接同一套：品牌色、无下划线，hover 才出现下划线 */
.client-auth-agree a {
  color: var(--td-brand-color);
  text-decoration: none;
  transition: color 0.2s ease;
}

.client-auth-agree a:hover {
  text-decoration: underline;
  text-underline-offset: 0.15em;
}

.client-auth-agree__error {
  margin: 0.25rem 0 0;
  color: var(--td-error-color, #d54941);
  font-size: 0.75rem;
}
</style>
