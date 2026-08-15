<template>
  <section class="client-verification">
    <loading-state :loading="loading" text="正在加载实名信息">
      <t-card class="verification-status-card" :bordered="false">
        <div class="verification-status-card__main">
          <div>
            <span class="verification-status-card__label">当前状态</span>
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
            <span>认证姓名</span>
            <strong>{{ form.real_name || '--' }}</strong>
          </article>
          <article class="verification-meta-item">
            <span>身份证号</span>
            <strong>{{ form.id_card_masked || '--' }}</strong>
          </article>
          <article class="verification-meta-item">
            <span>登录邮箱</span>
            <strong>{{ form.email || '--' }}</strong>
          </article>
          <article class="verification-meta-item">
            <span>绑定手机</span>
            <strong>{{ form.phone || '--' }}</strong>
          </article>
        </div>
      </t-card>

      <div class="verification-guide-grid">
        <t-card class="verification-guide-card" :bordered="false">
          <h2>认证流程</h2>
          <ol>
            <li>填写真实姓名与身份证号。</li>
            <li>使用手机扫码打开认证页面并完成人脸校验。</li>
            <li>认证成功后自动刷新账户状态。</li>
          </ol>
        </t-card>
        <t-card class="verification-guide-card" :bordered="false">
          <h2>温馨提示</h2>
          <ul>
            <li>请确保姓名与身份证号真实有效。</li>
            <li>认证失败后可重新获取二维码再次提交。</li>
            <li>如实名信息已存在，可在当前页面查看。</li>
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
        <t-form-item label="真实姓名" required-mark>
          <t-input v-model="verificationForm.realName" placeholder="请输入真实姓名" />
        </t-form-item>
        <t-form-item label="身份证号" required-mark>
          <t-input v-model="verificationForm.idCard" maxlength="18" placeholder="请输入身份证号" />
        </t-form-item>
      </t-form>

      <div v-else class="verification-qrcode-panel">
        <qrcode-vue :value="verificationUrl" :size="260" level="H" render-as="svg" />
        <p>请使用手机扫描二维码完成认证</p>
        <p class="verification-countdown" :class="{ expired: isVerificationQrExpired }">
          {{ isVerificationQrExpired ? '二维码已失效' : `二维码 ${verificationCountdownText} 后失效` }}
        </p>
        <p v-if="verificationMessage" class="verification-message">{{ verificationMessage }}</p>
        <div class="verification-link-actions">
          <t-button variant="outline" :loading="verificationLoading" @click="refreshVerificationLink"
            >刷新二维码</t-button
          >
          <t-button theme="primary" :loading="checkingStatus" @click="checkVerificationStatus(false)"
            >查询认证状态</t-button
          >
          <t-button variant="outline" :loading="closingSession" @click="closeVerificationSession(false)"
            >关闭会话</t-button
          >
          <t-button
            v-if="canRestartVerification"
            theme="warning"
            variant="outline"
            :loading="verificationLoading"
            @click="restartVerification"
          >
            重新认证
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
        <t-tag theme="success" variant="light">已认证</t-tag>
        <h2>实名信息</h2>
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
