<template>
  <auth-shell
    :title="$t('auth.loginAsInProgress')"
    :nav-text="$t('clientAuth.loginAs.navText')"
    :nav-link-text="$t('auth.backToLogin')"
    nav-to="/client/login"
    :hero-title="$t('clientAuth.loginAs.heroTitle')"
    :hero-description="$t('clientAuth.loginAs.heroDescription')"
  >
    <div class="login-as-result" :class="`login-as-result--${status}`">
      <div class="login-as-result__icon">
        <check-circle-icon v-if="status === 'success'" />
        <error-circle-icon v-else-if="status === 'error'" />
        <loading-icon v-else />
      </div>
      <h3 class="login-as-result__title">{{ resultTitle }}</h3>
      <p class="login-as-result__desc">{{ resultSubtitle }}</p>
      <t-space>
        <t-button v-if="status === 'error'" theme="primary" :loading="loading" @click="retryExchange">
          {{ $t('auth.loginAsRetry') }}
        </t-button>
        <t-button v-if="status !== 'loading'" variant="outline" @click="router.push('/client/login')">
          {{ $t('auth.backToLogin') }}
        </t-button>
      </t-space>
    </div>
  </auth-shell>
</template>
<script setup lang="ts">
import { CheckCircleIcon, ErrorCircleIcon, LoadingIcon } from 'tdesign-icons-vue-next';
import { MessagePlugin } from 'tdesign-vue-next';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';

import AuthShell from '@/components/auth/AuthShell.vue';
import { t } from '@/locales';
import { useUserStore } from '@/store';
import { toUserMessage } from '@/utils/userMessage';

interface RuntimeHandledError {
  __handled?: boolean;
  message?: string;
}

const route = useRoute();
const router = useRouter();
const userStore = useUserStore();
const status = ref<'loading' | 'success' | 'error'>('loading');
const loading = ref(false);
const openerOrigin = resolveOpenerOrigin();
const LOGIN_AS_READY_EVENT = 'turaidc:login-as-ready';
const LOGIN_AS_CODE_EVENT = 'turaidc:login-as-code';
const LOGIN_AS_MESSAGE_TIMEOUT_MS = 10000;
let messageTimer: number | null = null;

const redirectPath = computed(() => {
  const redirect = route.query.redirect;
  return typeof redirect === 'string' && redirect.startsWith('/') ? redirect : '/client/dashboard';
});

const resultTitle = computed(() => {
  if (status.value === 'success') return t('clientAuth.loginAs.success');
  if (status.value === 'error') return t('clientAuth.loginAs.failed');
  return t('clientAuth.loginAs.processing');
});

const resultSubtitle = computed(() => {
  if (status.value === 'success') return t('clientAuth.loginAs.descriptionSuccess');
  if (status.value === 'error') return t('clientAuth.loginAs.descriptionError');
  return t('clientAuth.loginAs.descriptionProcessing');
});

function resolveOpenerOrigin() {
  const referrer = typeof document !== 'undefined' ? String(document.referrer || '').trim() : '';
  if (!referrer) {
    return '';
  }

  try {
    return new URL(referrer).origin;
  } catch {
    return '';
  }
}

function clearMessageTimer() {
  if (messageTimer !== null) {
    window.clearTimeout(messageTimer);
    messageTimer = null;
  }
}

async function runExchange(code: string) {
  if (!code) {
    status.value = 'error';
    MessagePlugin.error(t('clientAuth.loginAs.credentialMissing'));
    return;
  }

  status.value = 'loading';
  loading.value = true;
  try {
    await userStore.exchangeLoginAsCode(code);
    status.value = 'success';
    MessagePlugin.success(t('clientAuth.loginAs.success'));
    await router.replace(redirectPath.value);
  } catch (error: unknown) {
    const runtimeError = error as RuntimeHandledError;
    status.value = 'error';
    if (!runtimeError.__handled) {
      MessagePlugin.error(toUserMessage(runtimeError.message, t('clientAuth.loginAs.failed')));
    }
  } finally {
    loading.value = false;
  }
}

function handleMissingMessage(reason = t('clientAuth.loginAs.missingDefault')) {
  clearMessageTimer();
  status.value = 'error';
  MessagePlugin.error(reason);
}

function handleLoginAsMessage(event: MessageEvent) {
  if (!window.opener || event.source !== window.opener) {
    return;
  }

  if (!openerOrigin || event.origin !== openerOrigin) {
    return;
  }

  if (event.data?.type !== LOGIN_AS_CODE_EVENT) {
    return;
  }

  const code = typeof event.data?.code === 'string' ? event.data.code.trim() : '';
  if (!code) {
    handleMissingMessage(t('clientAuth.loginAs.missingEmpty'));
    return;
  }

  clearMessageTimer();
  void runExchange(code);
}

function requestLoginAsCode() {
  if (!window.opener || !openerOrigin) {
    handleMissingMessage();
    return;
  }

  status.value = 'loading';
  loading.value = false;
  clearMessageTimer();
  messageTimer = window.setTimeout(() => {
    handleMissingMessage(t('clientAuth.loginAs.missingTimeout'));
  }, LOGIN_AS_MESSAGE_TIMEOUT_MS);
  window.opener.postMessage({ type: LOGIN_AS_READY_EVENT }, openerOrigin);
}

function retryExchange() {
  requestLoginAsCode();
}

onMounted(() => {
  window.addEventListener('message', handleLoginAsMessage);
  requestLoginAsCode();
});

onBeforeUnmount(() => {
  clearMessageTimer();
  window.removeEventListener('message', handleLoginAsMessage);
});
</script>
<style lang="less" scoped>
.login-as-result {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: var(--td-comp-margin-m);
  padding: var(--td-comp-paddingTB-xxl) var(--td-comp-paddingLR-l);
  text-align: center;
}

.login-as-result__icon {
  display: inline-flex;
  font-size: 3rem;
  line-height: 1;

  .login-as-result--success & {
    color: var(--td-success-color);
  }

  .login-as-result--error & {
    color: var(--td-error-color);
  }

  .login-as-result--loading & {
    color: var(--td-brand-color);
    animation: login-as-spin 1s linear infinite;
  }
}

.login-as-result__title {
  margin: 0;
  color: var(--td-text-color-primary);
  font: var(--td-font-title-large);
}

.login-as-result__desc {
  max-width: 22rem;
  margin: 0;
  color: var(--td-text-color-secondary);
  font: var(--td-font-body-medium);
}

@keyframes login-as-spin {
  to {
    transform: rotate(360deg);
  }
}
</style>
