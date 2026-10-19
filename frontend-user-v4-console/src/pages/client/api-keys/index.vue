<template>
  <section class="record-page client-api-keys">
    <t-card class="record-card api-key-hero" :bordered="false">
      <div class="api-key-hero__body">
        <div>
          <h3 class="api-key-hero__title">{{ $t('apiKeys.title') }}</h3>
          <p class="api-key-hero__desc">{{ $t('apiKeys.overview') }}</p>
          <p class="api-key-hero__linkage">{{ $t('apiKeys.linkageNote') }}</p>
        </div>
        <t-button v-if="activeTab === 'keys'" theme="primary" @click="openCreateDialog">{{
          $t('apiKeys.create')
        }}</t-button>
      </div>
    </t-card>

    <t-card class="record-card" :bordered="false">
      <t-tabs v-model="activeTab">
        <t-tab-panel value="keys" :label="$t('apiKeys.openApiLabel')">
          <div class="api-key-panel">
            <section class="record-list-card">
              <data-state
                :loading-text="$t('shared.state.loading')"
                :empty-text="$t('shared.state.empty')"
                :loading="loading"
                :empty="!list.length"
                :description="listError || $t('apiKeys.empty')"
              >
                <t-table class="record-table" row-key="id" :data="list" :columns="columns" :pagination="null" hover>
                  <template #key_name="{ row }">
                    <div class="stack-cell">
                      <strong>{{ row.name || $t('apiKeys.unnamed') }}</strong>
                      <span>{{ row.key_prefix }}••••{{ row.secret_last4 }}</span>
                    </div>
                  </template>
                  <template #scopes="{ row }">
                    <div class="scope-tags">
                      <t-tag
                        v-for="item in scopesDisplay(row.scopes)"
                        :key="item.key"
                        size="small"
                        :theme="resolveScopeTheme(item.level)"
                        variant="light"
                      >
                        {{ item.label }} · {{ resolveScopeLevelLabel(item.level) }}
                      </t-tag>
                    </div>
                  </template>
                  <template #status="{ row }">
                    <t-tag :theme="row.status === 'enabled' ? 'success' : 'default'" variant="light">
                      {{ row.status === 'enabled' ? $t('apiKeys.enabledTag') : $t('apiKeys.disabledTag') }}
                    </t-tag>
                  </template>
                  <template #expires_at="{ row }">{{ row.expires_at || $t('apiKeys.neverExpires') }}</template>
                  <template #last_used_at="{ row }">{{ row.last_used_at || $t('apiKeys.neverUsed') }}</template>
                  <template #operation="{ row }">
                    <t-space size="0">
                      <t-button size="small" theme="primary" variant="text" @click="openEditDialog(row)">{{
                        t('client.apiKeys.edit')
                      }}</t-button>
                      <t-button size="small" theme="primary" variant="text" @click="openUsageLogs(row)">{{
                        t('client.apiKeys.logs')
                      }}</t-button>
                      <t-button
                        size="small"
                        variant="text"
                        :theme="row.status === 'enabled' ? 'warning' : 'success'"
                        @click="confirmToggleStatus(row)"
                      >
                        {{ row.status === 'enabled' ? $t('apiKeys.disable') : $t('apiKeys.enable') }}
                      </t-button>
                      <t-button size="small" theme="danger" variant="text" @click="confirmRemove(row)">{{
                        t('components.delete')
                      }}</t-button>
                    </t-space>
                  </template>
                </t-table>

                <div class="record-mobile-list">
                  <article v-for="row in list" :key="row.id" class="record-mobile-card">
                    <div class="record-mobile-card__head">
                      <div class="stack-cell">
                        <strong>{{ row.name || $t('apiKeys.unnamed') }}</strong>
                        <span>{{ row.key_prefix }}••••{{ row.secret_last4 }}</span>
                      </div>
                      <t-tag :theme="row.status === 'enabled' ? 'success' : 'default'" variant="light" size="small">
                        {{ row.status === 'enabled' ? $t('apiKeys.enabledTag') : $t('apiKeys.disabledTag') }}
                      </t-tag>
                    </div>
                    <div class="scope-tags">
                      <t-tag
                        v-for="item in scopesDisplay(row.scopes)"
                        :key="item.key"
                        size="small"
                        :theme="resolveScopeTheme(item.level)"
                        variant="light"
                      >
                        {{ item.label }} · {{ resolveScopeLevelLabel(item.level) }}
                      </t-tag>
                    </div>
                    <div class="record-mobile-card__meta">
                      <span>{{ $t('apiKeys.expiresPrefix') }}{{ row.expires_at || $t('apiKeys.neverExpires') }}</span>
                      <span>{{ $t('apiKeys.lastUsedPrefix') }}{{ row.last_used_at || $t('apiKeys.neverUsed') }}</span>
                    </div>
                    <div class="record-mobile-card__actions">
                      <t-button size="small" theme="primary" variant="text" @click="openEditDialog(row)">{{
                        t('client.apiKeys.edit')
                      }}</t-button>
                      <t-button size="small" theme="primary" variant="text" @click="openUsageLogs(row)">{{
                        t('client.apiKeys.logs')
                      }}</t-button>
                      <t-button
                        size="small"
                        variant="text"
                        :theme="row.status === 'enabled' ? 'warning' : 'success'"
                        @click="confirmToggleStatus(row)"
                      >
                        {{ row.status === 'enabled' ? $t('apiKeys.disable') : $t('apiKeys.enable') }}
                      </t-button>
                      <t-button size="small" theme="danger" variant="text" @click="confirmRemove(row)">{{
                        t('components.delete')
                      }}</t-button>
                    </div>
                  </article>
                </div>
              </data-state>
            </section>
          </div>
        </t-tab-panel>

        <t-tab-panel value="upstream" :label="$t('apiKeys.upstreamTabLabel')">
          <upstream-api-panel v-if="upstreamReady" />
        </t-tab-panel>
      </t-tabs>
    </t-card>

    <t-dialog
      v-model:visible="dialogVisible"
      :header="dialogMode === 'create' ? $t('apiKeys.createTitle') : $t('apiKeys.editTitle')"
      :confirm-btn="{ content: submitting ? $t('apiKeys.submitting') : $t('apiKeys.save'), loading: submitting }"
      :cancel-btn="$t('common.cancel')"
      width="560px"
      @confirm="submit"
    >
      <t-form label-align="top">
        <t-form-item :label="$t('apiKeys.nameLabel')" required-mark>
          <t-input v-model="form.name" :placeholder="$t('apiKeys.namePlaceholder')" maxlength="64" />
        </t-form-item>

        <t-form-item :label="$t('apiKeys.scopesLabel')">
          <div class="scope-matrix">
            <div v-for="domain in SCOPE_DOMAINS" :key="domain.key" class="scope-matrix__row">
              <span class="scope-matrix__label">{{ domain.label }}</span>
              <t-radio-group v-model="form.scopes[domain.key]" variant="default-filled">
                <t-radio-button value="none">{{ $t('apiKeys.scopeNone') }}</t-radio-button>
                <t-radio-button value="read">{{ $t('apiKeys.scopeRead') }}</t-radio-button>
                <t-radio-button value="write">{{ $t('apiKeys.scopeWrite') }}</t-radio-button>
              </t-radio-group>
            </div>
            <p class="scope-matrix__tip">{{ $t('apiKeys.scopeHint') }}</p>
          </div>
        </t-form-item>

        <t-form-item :label="$t('apiKeys.expiresLabel')">
          <t-date-picker
            v-model="form.expires_at"
            format="YYYY-MM-DD HH:mm"
            value-type="YYYY-MM-DD HH:mm"
            clearable
            style="width: 100%"
          />
        </t-form-item>

        <t-form-item :label="$t('apiKeys.ipLabel')">
          <t-input v-model="form.ip_allowlist" :placeholder="$t('apiKeys.ipPlaceholder')" />
        </t-form-item>
      </t-form>
    </t-dialog>

    <t-dialog v-model:visible="secretVisible" :header="$t('apiKeys.createdTitle')" :footer="false" width="560px">
      <div class="secret-box">
        <p class="secret-box__warning">{{ secretWarning }}</p>
        <div class="secret-box__value">
          <code>{{ createdSecret }}</code>
          <t-button size="small" theme="primary" variant="outline" @click="copySecret">{{
            $t('apiKeys.upstream.copy')
          }}</t-button>
        </div>
        <div class="secret-box__hint">
          {{ $t('apiKeys.secretWarning') }}
        </div>
        <t-button block theme="primary" @click="closeSecret">{{ $t('apiKeys.upstream.saved') }}</t-button>
      </div>
    </t-dialog>

    <t-drawer
      v-model:visible="logVisible"
      :header="$t('apiKeys.logsTitle', { name: logKey?.name || $t('apiKeys.keyFallback') })"
      size="560px"
      :footer="false"
    >
      <data-state
        :loading-text="$t('shared.state.loading')"
        :empty-text="$t('shared.state.empty')"
        :loading="logLoading"
        :empty="!logs.length"
        :description="$t('apiKeys.upstream.noUsageLogs')"
      >
        <t-table row-key="created_at" :data="logs" :columns="logColumns" :pagination="null" hover />
      </data-state>
    </t-drawer>
  </section>
</template>
<script setup lang="ts">
import DataState from '@shared/user-v3/components/DataState.vue';
import type { PrimaryTableCol } from 'tdesign-vue-next';
import { DialogPlugin } from 'tdesign-vue-next';
import { ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';

import {
  resolveScopeLevelLabel,
  resolveScopeTheme,
  SCOPE_DOMAINS,
  scopesDisplay,
  useApiKeys,
} from '@/domains/developer/useApiKeys';
import { t } from '@/locales';
import type { ApiKeyRecord } from '@/types/client';

import UpstreamApiPanel from './components/UpstreamApiPanel.vue';

type CredentialTab = 'keys' | 'upstream';

const route = useRoute();
const router = useRouter();

/**
 * 两套凭据合并到同一页，用页内标签区分。
 *
 * 支持 ?tab=upstream 直达魔方财务对接分区：旧的 /client/upstream-api 路由
 * 跳转过来时靠它落到正确分区。
 */
const activeTab = ref<CredentialTab>(route.query.tab === 'upstream' ? 'upstream' : 'keys');

watch(activeTab, (tab) => {
  const query = tab === 'upstream' ? { tab } : {};
  void router.replace({ path: '/client/api-keys', query });
});

// 魔方财务对接分区挂载即自行加载状态；首次切到该标签才渲染，避免多余的接口请求
const upstreamReady = ref(activeTab.value === 'upstream');

watch(activeTab, (tab) => {
  if (tab === 'upstream') upstreamReady.value = true;
});

const {
  loading,
  list,
  listError,
  dialogVisible,
  dialogMode,
  submitting,
  form,
  secretVisible,
  createdSecret,
  secretWarning,
  logVisible,
  logLoading,
  logKey,
  logs,
  openCreateDialog,
  openEditDialog,
  submit,
  toggleStatus,
  removeKey,
  copySecret,
  openUsageLogs,
  closeSecret,
} = useApiKeys();

function confirmRemove(row: ApiKeyRecord) {
  const dialog = DialogPlugin.confirm({
    header: t('apiKeys.deleteTitle'),
    body: t('apiKeys.deleteBody', { name: row.name || t('apiKeys.unnamed') }),
    theme: 'warning',
    confirmBtn: { content: t('apiKeys.deleteConfirm'), theme: 'danger' },
    onConfirm: async () => {
      await removeKey(row);
      dialog.destroy();
    },
  });
}

function confirmToggleStatus(row: ApiKeyRecord) {
  if (row.status !== 'enabled') {
    // 启用是无风险操作，直接执行
    void toggleStatus(row);
    return;
  }

  const dialog = DialogPlugin.confirm({
    header: t('apiKeys.disableTitle'),
    body: t('apiKeys.disableBody', { name: row.name || t('apiKeys.unnamed') }),
    theme: 'warning',
    confirmBtn: { content: t('apiKeys.disableConfirm'), theme: 'warning' },
    onConfirm: async () => {
      await toggleStatus(row);
      dialog.destroy();
    },
  });
}

const columns: PrimaryTableCol[] = [
  { colKey: 'key_name', title: t('apiKeys.columnName'), minWidth: '12rem' },
  { colKey: 'scopes', title: t('apiKeys.columnScopes'), minWidth: '16rem' },
  { colKey: 'status', title: t('common.status'), width: '6rem' },
  { colKey: 'expires_at', title: t('apiKeys.columnExpiresAt'), minWidth: '10rem' },
  { colKey: 'last_used_at', title: t('apiKeys.columnLastUsedAt'), minWidth: '10rem' },
  { colKey: 'operation', title: t('common.action'), width: '16rem', fixed: 'right', align: 'right' },
];

const logColumns: PrimaryTableCol[] = [
  { colKey: 'created_at', title: t('common.time'), minWidth: '10rem' },
  { colKey: 'method', title: t('common.method'), width: '5rem' },
  { colKey: 'path', title: t('apiKeys.upstream.logPath'), minWidth: '14rem' },
  { colKey: 'status_code', title: t('common.status'), width: '5rem' },
  { colKey: 'ip', title: 'IP', minWidth: '8rem' },
  { colKey: 'duration_ms', title: t('apiKeys.columnDuration'), width: '6rem' },
];
</script>
<style scoped lang="less">
@import '../record-page.less';

.api-key-hero__body {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--td-comp-margin-l);
  flex-wrap: wrap;
}

.api-key-hero__title {
  margin: 0 0 var(--td-comp-margin-xs);
  font: var(--td-font-title-medium);
  color: var(--td-text-color-primary);
}

.api-key-hero__desc {
  margin: 0;
  line-height: 1.7;
  color: var(--td-text-color-secondary);
  font: var(--td-font-body-small);
  max-width: 52rem;
}

/* 联动说明：与描述同层级但用提示色，强调两套凭据的影响范围。
   line-height 必须放在 font 简写之后，否则会被 font 里的行高覆盖。 */
.api-key-hero__linkage {
  margin: var(--td-comp-margin-xs) 0 0;
  color: var(--td-text-color-placeholder);
  font: var(--td-font-body-small);
  line-height: 1.7;
  max-width: 52rem;
}

.api-key-panel {
  padding-top: var(--td-comp-margin-l);
}

.scope-tags {
  display: flex;
  flex-wrap: wrap;
  gap: var(--td-comp-margin-xxs);
}

.scope-matrix {
  width: 100%;
  display: flex;
  flex-direction: column;
  gap: var(--td-comp-margin-s);
}

.scope-matrix__row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--td-comp-margin-l);
}

.scope-matrix__label {
  flex-shrink: 0;
  color: var(--td-text-color-primary);
  font: var(--td-font-body-medium);
}

.scope-matrix__tip {
  margin: var(--td-comp-margin-xs) 0 0;
  color: var(--td-text-color-placeholder);
  font: var(--td-font-body-small);
}

.secret-box__warning {
  margin: 0 0 var(--td-comp-margin-m);
  color: var(--td-warning-color);
  font: var(--td-font-body-medium);
}

.secret-box__value {
  display: flex;
  align-items: center;
  gap: var(--td-comp-margin-s);
  padding: var(--td-comp-paddingLR-m);
  border-radius: var(--td-radius-medium);
  background: var(--td-bg-color-secondarycontainer);
  border: thin solid var(--td-component-border);
  margin-bottom: var(--td-comp-margin-m);
}

.secret-box__value code {
  flex: 1;
  overflow-x: auto;
  white-space: nowrap;
  font-family: ui-monospace, SFMono-Regular, Consolas, monospace;
  color: var(--td-text-color-primary);
  font-size: 14px;
}

.secret-box__hint {
  margin-bottom: var(--td-comp-margin-l);
  color: var(--td-text-color-secondary);
  font: var(--td-font-body-small);
}
</style>
