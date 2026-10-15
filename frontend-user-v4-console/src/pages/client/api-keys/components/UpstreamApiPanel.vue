<template>
  <data-state :loading="loading" :empty="false" :description="loadError || ''">
    <div class="upstream-panel">
      <t-form label-align="top" class="upstream-form">
        <t-form-item :label="$t('apiKeys.upstream.endpointLabel')">
          <div class="upstream-field">
            <t-input :value="accessEndpoint" readonly />
            <t-button v-if="accessEndpoint" size="small" variant="outline" @click="copyText(accessEndpoint)">
              {{ $t('apiKeys.upstream.copy') }}
            </t-button>
          </div>
          <p class="upstream-tip">
            {{ $t('apiKeys.upstream.loginEndpointPrefix') }}
            <code>{{ accessEndpoint }}/zjmf_api_login</code
            >{{ $t('apiKeys.upstream.loginEndpointSuffix') }}
          </p>
        </t-form-item>

        <t-form-item :label="$t('apiKeys.upstream.apiUsername')">
          <div class="upstream-field">
            <t-input :value="status?.username || $t('apiKeys.upstream.notGenerated')" readonly />
            <t-button v-if="status?.username" size="small" variant="outline" @click="copyText(status.username)">
              {{ $t('apiKeys.upstream.copy') }}
            </t-button>
          </div>
        </t-form-item>

        <t-form-item :label="$t('apiKeys.upstream.apiPassword')">
          <span class="upstream-password-state">
            {{ status?.has_password ? $t('apiKeys.upstream.passwordSet') : $t('apiKeys.upstream.passwordUnset') }}
          </span>
          <span v-if="status?.last_used_at" class="upstream-password-state"> {{ $t('apiKeys.upstream.lastUsedAt') }}{{ status.last_used_at }} </span>
        </t-form-item>

        <t-form-item :label="$t('apiKeys.upstream.ipAllowlistLabel')">
          <t-input v-model="policyForm.ip_allowlist" :placeholder="$t('apiKeys.upstream.ipAllowlistPlaceholder')" />
          <p class="upstream-tip">{{ $t('apiKeys.upstream.ipAllowlistHint') }}</p>
        </t-form-item>

        <t-form-item :label="$t('apiKeys.upstream.expiresAtLabel')">
          <t-date-picker
            v-model="policyForm.expires_at"
            format="YYYY-MM-DD HH:mm"
            value-type="YYYY-MM-DD HH:mm"
            clearable
            style="width: 100%"
          />
          <p class="upstream-tip">{{ $t('apiKeys.upstream.expiresAtHint') }}</p>
        </t-form-item>
      </t-form>

      <t-space class="upstream-actions" size="small">
        <t-button v-if="!status?.enabled" theme="primary" :loading="submitting" @click="enable">
          {{ $t('apiKeys.upstream.enable') }}
        </t-button>
        <template v-else>
          <t-button variant="outline" :loading="submitting" @click="savePolicy">{{ $t('apiKeys.upstream.savePolicy') }}</t-button>
          <t-button variant="outline" :loading="submitting" @click="openUsageLogs">{{ $t('apiKeys.upstream.usageLogs') }}</t-button>
          <t-button variant="outline" :loading="submitting" @click="resetPassword">{{ $t('apiKeys.upstream.resetPassword') }}</t-button>
          <t-button theme="danger" variant="outline" :loading="submitting" @click="confirmDisable">
            {{ $t('apiKeys.upstream.disable') }}
          </t-button>
        </template>
      </t-space>

      <p v-if="status?.is_expired" class="upstream-warning">{{ $t('apiKeys.upstream.expiredWarning') }}</p>

      <t-dialog v-model:visible="credentialVisible" :header="credentialTitle" :footer="false" width="40rem">
        <div class="credential-box">
          <p class="credential-box__warning">{{ $t('apiKeys.upstream.credentialWarning') }}</p>
          <div class="credential-box__row">
            <span class="credential-box__label">{{ $t('apiKeys.upstream.username') }}</span>
            <div class="credential-box__value">
              <code>{{ credential?.username }}</code>
              <t-button
                size="small"
                theme="primary"
                variant="outline"
                @click="copyText(String(credential?.username || ''))"
              >
                {{ $t('apiKeys.upstream.copy') }}
              </t-button>
            </div>
          </div>
          <div class="credential-box__row">
            <span class="credential-box__label">{{ $t('apiKeys.upstream.password') }}</span>
            <div class="credential-box__value">
              <code>{{ credential?.password }}</code>
              <t-button
                size="small"
                theme="primary"
                variant="outline"
                @click="copyText(String(credential?.password || ''))"
              >
                {{ $t('apiKeys.upstream.copy') }}
              </t-button>
            </div>
          </div>
          <p class="credential-box__hint">
            {{ $t('apiKeys.upstream.credentialHintPrefix') }}
            <code>{{ accessEndpoint }}</code
            >{{ $t('apiKeys.upstream.credentialHintSuffix') }}
          </p>
          <t-button block theme="primary" @click="closeCredential">{{ $t('apiKeys.upstream.saved') }}</t-button>
        </div>
      </t-dialog>

      <t-drawer v-model:visible="logVisible" :header="$t('apiKeys.upstream.usageLogsTitle')" size="560px" :footer="false">
        <data-state
          :loading="logLoading"
          :empty="!logs.length"
          :description="$t('apiKeys.upstream.noUsageLogs')"
        >
          <t-table row-key="created_at" :data="logs" :columns="logColumns" :pagination="null" hover />
        </data-state>
      </t-drawer>
    </div>
  </data-state>
</template>
<script setup lang="ts">
import DataState from '@shared/user-v3/components/DataState.vue';
import type { PrimaryTableCol } from 'tdesign-vue-next';
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
  logVisible,
  logLoading,
  logs,
  policyForm,
  enable,
  disable,
  resetPassword,
  savePolicy,
  openUsageLogs,
  copyText,
  closeCredential,
} = useUpstreamApi();

// 展示给用户填进魔方财务的「上游地址」：登录地址去掉登录路径段，方便直接复制接入
const accessEndpoint = computed(() => {
  const url = status.value?.login_url || '';
  return url.replace(/\/zjmf_api_login$/, '');
});

const logColumns: PrimaryTableCol[] = [
  { colKey: 'created_at', title: '时间', minWidth: '10rem' },
  { colKey: 'method', title: '方法', width: '5rem' },
  { colKey: 'path', title: '接口路径', minWidth: '14rem' },
  { colKey: 'status_code', title: '状态', width: '5rem' },
  { colKey: 'ip', title: 'IP', minWidth: '8rem' },
];

function confirmDisable() {
  const dialog = DialogPlugin.confirm({
    header: '关闭上游 API',
    body: '关闭后凭据立即失效，正在使用该账号的魔方财务对接会中断；同一账号的开放接口密钥也会一并停用。确定关闭？',
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
.upstream-panel {
  padding-top: var(--td-comp-margin-l);
}

.upstream-form {
  max-width: 48rem;
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
  line-height: 1.7;
  color: var(--td-text-color-placeholder);
  font: var(--td-font-body-small);
}

.upstream-tip code,
.credential-box__hint code {
  padding: 0.0625rem 0.25rem;
  border-radius: var(--td-radius-small);
  background: var(--td-bg-color-secondarycontainer);
  overflow-wrap: anywhere;
  color: var(--td-text-color-primary);
}

.upstream-password-state {
  color: var(--td-text-color-secondary);
  font: var(--td-font-body-small);
}

.upstream-password-state + .upstream-password-state {
  margin-left: var(--td-comp-margin-m);
}

.upstream-actions {
  margin-top: var(--td-comp-margin-m);
}

.upstream-warning {
  margin: var(--td-comp-margin-m) 0 0;
  color: var(--td-warning-color);
  font: var(--td-font-body-small);
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
