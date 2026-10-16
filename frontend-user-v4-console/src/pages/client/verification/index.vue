<template>
  <section class="client-verification">
    <loading-state :loading="loading" text="正在加载实名信息">
      <t-card class="verification-status-card" :bordered="false">
        <div class="verification-status-card__main">
          <div>
            <span class="verification-status-card__label">{{ $t('verification.currentStatus') }}</span>
            <h1>{{ statusTitle }}</h1>
            <p>{{ statusDescription }}</p>
          </div>
          <div class="verification-status-card__actions">
            <t-tag :theme="statusTheme" variant="light">{{ statusLabel }}</t-tag>
            <t-button theme="primary" @click="openVerificationEntry">
              {{ isVerified ? '查看实名信息' : '立即认证' }}
            </t-button>
          </div>
        </div>

        <div class="verification-meta-grid">
          <article class="verification-meta-item">
            <span>{{ $t('verification.name') }}</span>
            <strong>{{ form.real_name || '--' }}</strong>
          </article>
          <article class="verification-meta-item">
            <span>{{ $t('verification.idCard') }}</span>
            <strong>{{ form.id_card_masked || '--' }}</strong>
          </article>
          <article class="verification-meta-item">
            <span>{{ $t('verification.loginEmail') }}</span>
            <strong>{{ form.email || '--' }}</strong>
          </article>
          <article class="verification-meta-item">
            <span>{{ $t('verification.boundPhone') }}</span>
            <strong>{{ form.phone || '--' }}</strong>
          </article>
        </div>
      </t-card>

      <div class="verification-guide-grid">
        <t-card class="verification-guide-card" :bordered="false">
          <h2>{{ $t('verification.flow') }}</h2>
          <ol>
            <li>{{ $t('verification.step1') }}</li>
            <li>{{ $t('verification.step2') }}</li>
            <li>{{ $t('verification.step3') }}</li>
          </ol>
        </t-card>
        <t-card class="verification-guide-card" :bordered="false">
          <h2>{{ $t('verification.notice') }}</h2>
          <ul>
            <li>{{ $t('verification.tip1') }}</li>
            <li>{{ $t('verification.tip2') }}</li>
            <li>{{ $t('verification.tip3') }}</li>
          </ul>
        </t-card>
      </div>
    </loading-state>

    <t-dialog
      v-model:visible="showVerificationDialog"
      header="实名认证"
      width="min(32rem, calc(100vw - var(--td-comp-margin-xl)))"
      :confirm-btn="
        verificationUrl ? null : { content: '提交认证', loading: verificationLoading, disabled: !canSubmit }
      "
      cancel-btn="取消"
      destroy-on-close
      @confirm="submitVerification"
      @close="handleVerificationDialogClose"
    >
      <p v-if="!verificationUrl && verificationMessage" class="verification-message">
        {{ verificationMessage }}
      </p>
      <t-form v-if="!verificationUrl" label-align="top" class="verification-form">
        <t-form-item :label="$t('verification.realNameLabel')" required-mark>
          <t-input v-model="verificationForm.realName" :placeholder="$t('verification.realNamePlaceholder')" />
        </t-form-item>
        <t-form-item :label="$t('verification.idCardLabel')" required-mark>
          <t-input v-model="verificationForm.idCard" maxlength="18" :placeholder="$t('verification.idCardPlaceholder')" />
        </t-form-item>
      </t-form>

      <div v-else class="verification-qrcode-panel">
        <qrcode-vue :value="verificationUrl" :size="260" level="H" render-as="svg" />
        <p>{{ $t('verification.scanHint') }}</p>
        <p class="verification-countdown" :class="{ expired: isVerificationQrExpired }">
          {{ isVerificationQrExpired ? '二维码已失效' : `二维码 ${verificationCountdownText} 后失效` }}
        </p>
        <p v-if="verificationMessage" class="verification-message">{{ verificationMessage }}</p>
        <div class="verification-link-actions">
          <t-button variant="outline" :loading="verificationLoading" @click="refreshVerificationLink"
            >{{ $t('verification.refreshQrcode') }}</t-button
          >
          <t-button theme="primary" :loading="checkingStatus" @click="checkVerificationStatus(false)"
            >{{ $t('verification.checkStatus') }}</t-button
          >
          <t-button variant="outline" :loading="closingSession" @click="closeVerificationSession(false)"
            >{{ $t('verification.closeSession') }}</t-button
          >
          <t-button
            v-if="canRestartVerification"
            theme="warning"
            variant="outline"
            :loading="verificationLoading"
            @click="restartVerification"
          >
            {{ $t('verification.reverify') }}
          </t-button>
        </div>
      </div>
    </t-dialog>

    <t-dialog
      v-model:visible="showVerifiedInfoDialog"
      header="实名信息"
      width="min(28rem, calc(100vw - var(--td-comp-margin-xl)))"
      cancel-btn="关闭"
      :confirm-btn="null"
    >
      <div class="verified-info-dialog">
        <t-tag theme="success" variant="light">{{ $t('verification.verifiedTag') }}</t-tag>
        <h2>{{ $t('verification.realName') }}</h2>
        <p>姓名：{{ form.real_name || '--' }}</p>
        <p>身份证号：{{ form.id_card_masked || '--' }}</p>
      </div>
    </t-dialog>
  </section>
</template>
<script setup lang="ts">
import LoadingState from '@shared/user-v3/components/LoadingState.vue';
import { defineAsyncComponent, onMounted } from 'vue';

const QrcodeVue = defineAsyncComponent(() => import('qrcode.vue'));

import { useVerification } from '@/domains/account/useVerification';

const {
  loading,
  verificationLoading,
  checkingStatus,
  closingSession,
  showVerificationDialog,
  showVerifiedInfoDialog,
  verificationUrl,
  verificationCountdownText,
  isVerificationQrExpired,
  canRestartVerification,
  verificationMessage,
  form,
  verificationForm,
  isVerified,
  statusTheme,
  statusLabel,
  statusTitle,
  statusDescription,
  canSubmit,
  loadProfile,
  openVerificationEntry,
  submitVerification,
  refreshVerificationLink,
  checkVerificationStatus,
  restartVerification,
  closeVerificationSession,
  handleCallbackQuery,
  handleVerificationDialogClose,
} = useVerification();

onMounted(async () => {
  await loadProfile();
  await handleCallbackQuery();
});
</script>
<style scoped lang="less">
.client-verification {
  display: flex;
  flex-direction: column;
  gap: var(--td-comp-margin-m);
  // padding 由 Starter 布局层统一提供
}

.verification-status-card,
.verification-guide-card {
  background: var(--td-bg-color-container);
  border: thin solid var(--td-border-color);
  border-radius: var(--td-radius-medium);
  box-shadow: var(--td-shadow-1);
}

.verification-status-card__main {
  display: flex;
  gap: var(--td-comp-margin-m);
  align-items: flex-start;
  justify-content: space-between;

  h1 {
    margin: var(--td-comp-margin-xs) 0 0;
    color: var(--td-text-color-primary);
    font: var(--td-font-headline-medium);
  }

  p {
    margin: var(--td-comp-margin-s) 0 0;
    color: var(--td-text-color-secondary);
    font: var(--td-font-body-medium);
    line-height: var(--td-line-height-body-medium);
  }
}

.verification-status-card__label {
  color: var(--td-text-color-secondary);
  font: var(--td-font-body-small);
}

.verification-status-card__actions {
  display: flex;
  flex-shrink: 0;
  gap: var(--td-comp-margin-s);
  align-items: center;
}

.verification-meta-grid,
.verification-guide-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: var(--td-comp-margin-m);
}

.verification-meta-grid {
  margin-top: var(--td-comp-margin-l);
}

.verification-meta-item {
  padding: var(--td-comp-paddingTB-m) var(--td-comp-paddingLR-m);
  background: var(--td-bg-color-container-hover);
  border: thin solid var(--td-border-color);
  border-radius: var(--td-radius-medium);

  span {
    display: block;
    color: var(--td-text-color-secondary);
    font: var(--td-font-body-small);
  }

  strong {
    display: block;
    margin-top: var(--td-comp-margin-xs);
    overflow-wrap: anywhere;
    color: var(--td-text-color-primary);
    font: var(--td-font-body-large);
    font-weight: 600;
  }
}

.verification-guide-card {
  h2 {
    margin: 0 0 var(--td-comp-margin-m);
    color: var(--td-text-color-primary);
    font: var(--td-font-title-medium);
  }

  ol,
  ul {
    display: flex;
    flex-direction: column;
    gap: var(--td-comp-margin-s);
    margin: 0;
    padding-left: var(--td-comp-paddingLR-l);
    color: var(--td-text-color-secondary);
    font: var(--td-font-body-medium);
    line-height: var(--td-line-height-body-medium);
  }
}

.verification-form {
  display: grid;
  gap: var(--td-comp-margin-s);
}

.verification-qrcode-panel,
.verified-info-dialog {
  display: grid;
  gap: var(--td-comp-margin-m);
  justify-items: center;
  text-align: center;

  p,
  h2 {
    margin: 0;
  }
}

.verification-qrcode-panel {
  color: var(--td-text-color-secondary);
  font: var(--td-font-body-medium);
}

.verification-message {
  color: var(--td-warning-color);
}

.verification-countdown {
  color: var(--td-text-color-secondary);
  font: var(--td-font-body-small);

  &.expired {
    color: var(--td-error-color);
  }
}

.verification-link-actions {
  display: flex;
  flex-wrap: wrap;
  gap: var(--td-comp-margin-s);
  justify-content: center;
}

.verified-info-dialog h2 {
  color: var(--td-text-color-primary);
  font: var(--td-font-title-large);
}

.verified-info-dialog p {
  color: var(--td-text-color-secondary);
  font: var(--td-font-body-medium);
}

@media (max-width: @screen-sm-rem) {
  .verification-status-card__main,
  .verification-meta-grid,
  .verification-guide-grid {
    grid-template-columns: 1fr;
    display: grid;
  }

  .verification-status-card__actions,
  .verification-link-actions {
    align-items: stretch;
    flex-direction: column;
  }

  .verification-status-card__actions :deep(.t-button),
  .verification-link-actions :deep(.t-button) {
    width: 100%;
    margin-left: 0;
  }
}
</style>
