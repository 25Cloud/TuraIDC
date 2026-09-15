<template>
  <section class="record-page client-upstream-api">
    <t-card class="record-card" :bordered="false">
      <div class="upstream-hero">
        <div>
          <h3 class="upstream-hero__title">上游 API（魔方财务对接）</h3>
          <p class="upstream-hero__desc">
            开启后可用本账号承接魔方财务的系统对接：对方在「上游」里填下面的登录地址与账号密码即可。 与「API
            密钥」是两套独立凭据，互不影响。
          </p>
        </div>
        <t-tag :theme="status?.enabled ? 'success' : 'default'" variant="light">
          {{ status?.enabled ? '已开启' : '未开启' }}
        </t-tag>
      </div>
    </t-card>

    <section class="record-list-card">
      <data-state :loading="loading" :empty="false" :description="loadError || ''">
        <div class="upstream-body">
          <t-form label-align="top" class="upstream-form">
            <t-form-item label="登录接口地址">
              <div class="upstream-field">
                <t-input :value="status?.login_url || ''" readonly />
                <t-button v-if="status?.login_url" size="small" variant="outline" @click="copyText(status.login_url)">
                  复制
                </t-button>
              </div>
              <p class="upstream-tip">
                魔方财务「上游地址」请填到 <code>/api/v2/zjmf</code> 为止（不含
                <code>/zjmf_api_login</code>），对方会自动拼接。
              </p>
            </t-form-item>

            <t-form-item label="API 用户名">
              <div class="upstream-field">
                <t-input :value="status?.username || '未生成'" readonly />
                <t-button v-if="status?.username" size="small" variant="outline" @click="copyText(status.username)">
                  复制
                </t-button>
              </div>
            </t-form-item>

            <t-form-item label="API 密码">
              <span class="upstream-password-state">
                {{ status?.has_password ? '已设置（出于安全不显示，可重置）' : '未设置' }}
              </span>
            </t-form-item>
          </t-form>

          <t-space class="upstream-actions" size="small">
            <t-button v-if="!status?.enabled" theme="primary" :loading="submitting" @click="enable">
              开启上游 API
            </t-button>
            <template v-else>
              <t-button variant="outline" :loading="submitting" @click="resetPassword">重置密码</t-button>
              <t-button theme="danger" variant="outline" :loading="submitting" @click="confirmDisable">
                关闭上游 API
              </t-button>
            </template>
          </t-space>
        </div>
      </data-state>
    </section>

    <t-dialog v-model:visible="credentialVisible" :header="credentialTitle" :footer="false" width="40rem">
      <div class="credential-box">
        <p class="credential-box__warning">密码仅显示这一次，关闭后无法再次查看，请立即保存。</p>
        <div class="credential-box__row">
          <span class="credential-box__label">用户名</span>
          <div class="credential-box__value">
            <code>{{ credential?.username }}</code>
            <t-button
              size="small"
              theme="primary"
              variant="outline"
              @click="copyText(String(credential?.username || ''))"
            >
              复制
            </t-button>
          </div>
        </div>
        <div class="credential-box__row">
          <span class="credential-box__label">密码</span>
          <div class="credential-box__value">
            <code>{{ credential?.password }}</code>
            <t-button
              size="small"
              theme="primary"
              variant="outline"
              @click="copyText(String(credential?.password || ''))"
            >
              复制
            </t-button>
          </div>
        </div>
        <p class="credential-box__hint">
          在魔方财务后台「上游」新增 API 账号：上游地址填
          <code>{{ loginHost }}</code
          >，用户名与密码填上面两项。
        </p>
        <t-button block theme="primary" @click="closeCredential">我已保存</t-button>
      </div>
    </t-dialog>
  </section>
</template>
<script setup lang="ts">
import DataState from '@shared/user-v3/components/DataState.vue';
import { DialogPlugin } from 'tdesign-vue-next';
import { computed } from 'vue';

import { useUpstreamApi } from '@/domains/developer/useUpstreamApi';

const {
  loading,
  status,
  loadError,
  submitting,
  credentialVisible,
  credential,
  credentialTitle,
  enable,
  disable,
  resetPassword,
  copyText,
  closeCredential,
} = useUpstreamApi();

// 展示给用户填进魔方财务的「上游地址」：登录地址去掉登录路径段
const loginHost = computed(() => {
  const url = status.value?.login_url || '';
  return url.replace(/\/zjmf_api_login$/, '');
});

function confirmDisable() {
  const dialog = DialogPlugin.confirm({
    header: '关闭上游 API',
    body: '关闭后凭据立即失效，正在使用该账号的魔方财务对接会中断。确定关闭？',
    confirmBtn: { content: '确认关闭', theme: 'danger' },
    onConfirm: async () => {
      await disable();
      dialog.destroy();
    },
    onClose: () => dialog.destroy(),
  });
}
</script>
<style scoped>
.upstream-hero {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: var(--td-comp-margin-l);
}

.upstream-hero__title {
  margin: 0 0 var(--td-comp-margin-xs);
  font: var(--td-font-title-medium);
  color: var(--td-text-color-primary);
}

.upstream-hero__desc {
  margin: 0;
  color: var(--td-text-color-secondary);
  font: var(--td-font-body-small);
  max-width: 46rem;
}

.upstream-body {
  padding: var(--td-comp-paddingTB-l) var(--td-comp-paddingLR-l);
}

.upstream-form {
  max-width: 44rem;
}

.upstream-field {
  display: flex;
  align-items: center;
  gap: var(--td-comp-margin-s);
  width: 100%;
}

.upstream-field :deep(.t-input__wrap) {
  flex: 1;
}

.upstream-tip {
  margin: var(--td-comp-margin-xs) 0 0;
  color: var(--td-text-color-placeholder);
  font: var(--td-font-body-small);
}

.upstream-password-state {
  color: var(--td-text-color-secondary);
  font: var(--td-font-body-small);
}

.upstream-actions {
  margin-top: var(--td-comp-margin-m);
}

.credential-box__warning {
  margin: 0 0 var(--td-comp-margin-m);
  color: var(--td-warning-color);
  font: var(--td-font-body-medium);
}

.credential-box__row {
  margin-bottom: var(--td-comp-margin-m);
}

.credential-box__label {
  display: block;
  margin-bottom: var(--td-comp-margin-xs);
  color: var(--td-text-color-secondary);
  font: var(--td-font-body-small);
}

.credential-box__value {
  display: flex;
  align-items: center;
  gap: var(--td-comp-margin-s);
  padding: var(--td-comp-paddingLR-m);
  border-radius: var(--td-radius-medium);
  background: var(--td-bg-color-secondarycontainer);
  border: thin solid var(--td-component-border);
}

.credential-box__value code {
  flex: 1;
  overflow-x: auto;
  white-space: nowrap;
  font-family: ui-monospace, SFMono-Regular, Consolas, monospace;
  color: var(--td-text-color-primary);
  font: var(--td-font-body-medium);
}

.credential-box__hint {
  margin-bottom: var(--td-comp-margin-l);
  color: var(--td-text-color-secondary);
  font: var(--td-font-body-small);
}
</style>
