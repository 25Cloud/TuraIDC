<template>
  <data-state :loading="loading" :empty="false" :description="loadError || ''">
    <div class="upstream-panel">
      <t-form label-align="top" class="upstream-form">
        <t-form-item label="上游接入地址">
          <div class="upstream-field">
            <t-input :value="accessEndpoint" readonly />
            <t-button v-if="accessEndpoint" size="small" variant="outline" @click="copyText(accessEndpoint)">
              复制
            </t-button>
          </div>
          <p class="upstream-tip">
            登录接口为
            <code>{{ accessEndpoint }}/zjmf_api_login</code
            >。魔方财务「上游地址」直接填上面的接入地址即可，无需拼接登录路径。
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
          <span v-if="status?.last_used_at" class="upstream-password-state"> 最近调用：{{ status.last_used_at }} </span>
        </t-form-item>

        <t-form-item label="IP 白名单（可选，多个用英文逗号分隔）">
          <t-input v-model="policyForm.ip_allowlist" placeholder="例如：203.0.113.9, 198.51.100.0/24" />
          <p class="upstream-tip">留空表示不限制来源 IP；填了之后只有白名单内的 IP 能换取 JWT。</p>
        </t-form-item>

        <t-form-item label="有效期（可选）">
          <t-date-picker
            v-model="policyForm.expires_at"
            format="YYYY-MM-DD HH:mm"
            value-type="YYYY-MM-DD HH:mm"
            clearable
            style="width: 100%"
          />
          <p class="upstream-tip">留空表示永不过期；到期后该凭据无法再换取 JWT。</p>
        </t-form-item>
      </t-form>

      <t-space class="upstream-actions" size="small">
        <t-button v-if="!status?.enabled" theme="primary" :loading="submitting" @click="enable">
          开启上游 API
        </t-button>
        <template v-else>
          <t-button variant="outline" :loading="submitting" @click="savePolicy">保存安全策略</t-button>
          <t-button variant="outline" :loading="submitting" @click="openUsageLogs">调用日志</t-button>
          <t-button variant="outline" :loading="submitting" @click="resetPassword">重置密码</t-button>
          <t-button theme="danger" variant="outline" :loading="submitting" @click="confirmDisable">
            关闭上游 API
          </t-button>
        </template>
      </t-space>

      <p v-if="status?.is_expired" class="upstream-warning">该凭据已过期，魔方财务侧无法再换取 JWT，请更新有效期。</p>

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
            <code>{{ accessEndpoint }}</code
            >，用户名与密码填上面两项。
          </p>
          <t-button block theme="primary" @click="closeCredential">我已保存</t-button>
        </div>
      </t-dialog>

      <t-drawer v-model:visible="logVisible" header="调用日志 · 魔方财务对接" size="560px" :footer="false">
        <data-state :loading="logLoading" :empty="!logs.length" description="暂无调用记录">
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
