<template>
  <div class="login-container">
    <div class="login-card">
      <div class="login-header">
        <h1>{{ $t('login.consoleTitle') }}</h1>
        <p>{{ $t('login.subtitle') }}</p>
      </div>
      <t-form ref="formRef" :data="formData" :rules="formRules" label-align="top" @submit="handleLogin">
        <t-form-item :label="$t('login.account')" name="account">
          <t-input
            v-model="formData.account"
            :placeholder="$t('login.accountPlaceholder')"
            size="large"
            clearable
            autocomplete="username"
          />
        </t-form-item>
        <t-form-item :label="$t('login.password')" name="password">
          <t-input
            v-model="formData.password"
            type="password"
            :placeholder="$t('login.passwordPlaceholder')"
            size="large"
            clearable
            autocomplete="current-password"
          />
        </t-form-item>
        <!--
          验证组件落点：容器常驻渲染，不再等配置回来才 display。
          配置/组件未就绪时先用等高占位块顶住高度，组件渲染完成后原地替换，
          杜绝「先空白后出现」的布局跳变；加载失败时给出显式重试入口。
        -->
        <div v-show="captchaSlotVisible" class="login-captcha">
          <div v-if="captchaPending" class="login-captcha-placeholder" aria-hidden="true"></div>
          <t-link v-if="captchaInitError" class="login-captcha-retry" theme="primary" @click="handleCaptchaRetry">
            {{ $t('login.captchaRetry') }}
          </t-link>
          <div ref="captchaContainer" class="login-captcha-container"></div>
        </div>
        <div class="login-remember">
          <t-checkbox v-model="rememberAccount">{{ $t('login.rememberAccount') }}</t-checkbox>
        </div>
        <t-form-item class="login-submit-item">
          <t-button block theme="primary" size="large" type="submit" :loading="loading || captchaLoading">
            {{ $t('login.submit') }}
          </t-button>
          <span class="sr-only" role="alert" aria-live="assertive">{{ errorMessage }}</span>
        </t-form-item>
      </t-form>
      <div class="login-footer">
        <span>© {{ currentYear }} {{ $t('login.consoleTitle') }}</span>
      </div>
    </div>
  </div>
</template>
<script setup lang="ts">
import type { FormInstanceFunctions, FormRule } from 'tdesign-vue-next';
import { MessagePlugin } from 'tdesign-vue-next';
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';

import { useGeeTestCaptcha } from '@/hooks/useGeeTestCaptcha';
import { t } from '@/locales';
import { useUserStore } from '@/store';
import { resolveAdminHomePath } from '@/utils/route/adminHome';

const router = useRouter();
const userStore = useUserStore();
const formRef = ref<FormInstanceFunctions>();
const loading = ref(false);
const errorMessage = ref('');
const currentYear = computed(() => new Date().getFullYear());

// 管理员登录的人机验证：是否要求由后端场景开关决定（验证码插件配置里的「管理员登录」）。
//
// 首屏不再串行：配置请求、适配脚本加载、组件渲染全部由 hook 在挂载后并行推进，
// 页面不 await 探测结果，表单先渲染出来，验证码就绪后原地出现。
// 探测失败一律视为不要求——验证码配置出问题不应该把管理员挡在后台之外。
//
// 渲染形态由后端下发：popup（极验）由插件自行弹窗，页面没有组件可放；
// inline（Turnstile/Corptcha）渲染进登录按钮上方的容器。
const captchaContainer = ref<HTMLElement>();
const {
  verify: verifyCaptcha,
  loading: captchaLoading,
  prepare: prepareCaptcha,
  enabled: captchaEnabled,
  required: captchaRequired,
  renderMode: captchaRenderMode,
  initialized: captchaInitialized,
  ready: captchaReady,
  initError: captchaInitError,
  ensureConfig: ensureCaptchaConfig,
} = useGeeTestCaptcha({
  scene: 'admin_login',
  autoPrepare: true,
  appendTo: captchaContainer,
  onPrompt: () => MessagePlugin.warning(t('login.captchaRequired')),
});

// 只有 inline 形态才有内联组件可放；popup 形态不占位
const captchaNeedsSlot = computed(() => captchaEnabled.value && captchaRenderMode.value === 'inline');
// 配置还没回来、或 inline 组件还没渲染好时都保留占位（首屏即为 true，先顶住高度）
const captchaPending = computed(() => !captchaReady.value && !(captchaInitialized.value && !captchaNeedsSlot.value));
// popup 形态本来就没有内联区域，但组件加载失败时仍要留一个显式重试入口，
// 不能只依赖「点登录才报错」
const captchaSlotVisible = computed(
  () => captchaPending.value || captchaNeedsSlot.value || Boolean(captchaInitError.value),
);

// 组件加载失败时给出显式重试入口，避免验证码区域永久空白把人堵在门外
function handleCaptchaRetry() {
  void prepareCaptcha();
}

// 「记住账号」只持久化账号，密码一律不落本地存储。
// 后台账号一旦泄露即等同于整套财务系统失守，而 localStorage 可被任意同源脚本读取
// （一个 XSS 就能把明文密码整包捞走），风险与收益不成比例。密码交给浏览器自带的
// 凭据管理器——登录表单已带 autocomplete="username" / "current-password"，
// 浏览器会在用户许可下加密保存并自动填充，体验一致但密钥由系统钥匙串保管。
const REMEMBER_ACCOUNT_KEY = 'turaidc-admin-remembered-account';
const rememberAccount = ref(false);

const formData = ref({
  account: '',
  password: '',
});

onMounted(() => {
  try {
    const saved = window.localStorage.getItem(REMEMBER_ACCOUNT_KEY) || '';
    if (saved) {
      formData.value.account = saved;
      rememberAccount.value = true;
    }
  } catch {
    // 隐私模式等场景下 localStorage 不可用，忽略即可，不影响登录
  }
});

function persistRememberedAccount(account: string) {
  try {
    if (rememberAccount.value && account) {
      window.localStorage.setItem(REMEMBER_ACCOUNT_KEY, account);
    } else {
      window.localStorage.removeItem(REMEMBER_ACCOUNT_KEY);
    }
  } catch {
    // 同上：存储不可用不应阻断登录流程
  }
}

const formRules: Record<string, FormRule[]> = {
  account: [{ required: true, message: t('login.accountRequired'), trigger: 'blur' }],
  password: [{ required: true, message: t('login.passwordRequired'), trigger: 'blur' }],
};

async function handleLogin() {
  const valid = await formRef.value?.validate();
  if (valid !== true) return;

  loading.value = true;
  try {
    // 提交瞬间配置可能还没回来（首屏与验证码初始化是并行的）：
    // 此时 required 还是初始的 false，直接提交会被后端以 captcha_required 拒掉。
    // 先把配置等落地，再决定这次要不要带 captcha。
    await ensureCaptchaConfig();
    // 验证结果随登录请求一起提交；未要求验证时不带 captcha 字段
    const captcha = captchaRequired.value ? await verifyCaptcha() : null;

    await userStore.login({
      account: formData.value.account,
      password: formData.value.password,
      ...(captcha ? { captcha } : {}),
    });
    persistRememberedAccount(formData.value.account);
    MessagePlugin.success(t('login.loginSuccess'));
    const redirect = router.currentRoute.value.query.redirect;
    if (redirect) {
      router.push(decodeURIComponent(redirect as string));
    } else {
      // 无 redirect 时落当前角色可访问的首页：仅持非视图权限的角色（如只有 ticket.manage）
      // 不应落到无权限的 /admin/dashboard，否则守卫会反复重定向导致页面卡死。
      router.push(resolveAdminHomePath(userStore.userInfo?.permissions ?? []));
    }
  } catch (error) {
    // verify() 的 inline 分支已通过 onPrompt 提示"请先完成人机验证"，这里不再重复弹窗
    const handled = (error as { __handled?: boolean } | null)?.__handled === true;
    const msg = error instanceof Error ? error.message : t('login.loginFailed');
    errorMessage.value = msg;
    if (!handled) {
      MessagePlugin.error(msg);
    }
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

/* 验证组件落点：常驻占位，组件未就绪时用等高骨架块撑住布局 */
.login-captcha {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 12px;
  margin-bottom: 16px;

  /* 占位高度对齐常见内联组件（Turnstile/Corptcha），组件渲染后不产生位移 */
  &-placeholder {
    width: 300px;
    max-width: 100%;
    height: 64px;
    border-radius: var(--td-radius-medium, 6px);
    background: var(--td-bg-color-secondarycontainer, #f3f3f3);
  }

  &-container {
    display: flex;
    justify-content: center;
    width: 100%;
    min-width: 0;
  }

  &-retry {
    font-size: var(--td-font-size-size-2, 13px);
  }
}

.login-remember {
  display: flex;
  align-items: center;
  margin-bottom: 20px;
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
