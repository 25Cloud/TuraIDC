<template>
  <section class="record-page client-api-keys">
    <t-card class="record-card api-key-hero" :bordered="false">
      <div class="api-key-hero__body">
        <div>
          <h3 class="api-key-hero__title">API 密钥</h3>
          <p class="api-key-hero__desc">
            通过密钥调用开放接口（/api/v2/open）实现系统间对接，可精确控制每个密钥的访问范围与读写权限。
          </p>
        </div>
        <t-button theme="primary" @click="openCreateDialog">创建密钥</t-button>
      </div>
    </t-card>

    <section class="record-list-card">
      <data-state :loading="loading" :empty="!list.length" :description="listError || '暂无 API 密钥，点击右上角创建'">
        <t-table class="record-table" row-key="id" :data="list" :columns="columns" :pagination="null" hover>
          <template #key_name="{ row }">
            <div class="stack-cell">
              <strong>{{ row.name || '未命名' }}</strong>
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
              {{ row.status === 'enabled' ? '已启用' : '已停用' }}
            </t-tag>
          </template>
          <template #expires_at="{ row }">{{ row.expires_at || '永不过期' }}</template>
          <template #last_used_at="{ row }">{{ row.last_used_at || '从未使用' }}</template>
          <template #operation="{ row }">
            <t-space size="0">
              <t-button size="small" theme="primary" variant="text" @click="openEditDialog(row)">编辑</t-button>
              <t-button size="small" theme="primary" variant="text" @click="openUsageLogs(row)">日志</t-button>
              <t-button
                size="small"
                variant="text"
                :theme="row.status === 'enabled' ? 'warning' : 'success'"
                @click="toggleStatus(row)"
              >
                {{ row.status === 'enabled' ? '停用' : '启用' }}
              </t-button>
              <t-button size="small" theme="danger" variant="text" @click="confirmRemove(row)">删除</t-button>
            </t-space>
          </template>
        </t-table>

        <div class="record-mobile-list">
          <article v-for="row in list" :key="row.id" class="record-mobile-card">
            <div class="record-mobile-card__head">
              <div class="stack-cell">
                <strong>{{ row.name || '未命名' }}</strong>
                <span>{{ row.key_prefix }}••••{{ row.secret_last4 }}</span>
              </div>
              <t-tag :theme="row.status === 'enabled' ? 'success' : 'default'" variant="light" size="small">
                {{ row.status === 'enabled' ? '已启用' : '已停用' }}
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
              <span>到期：{{ row.expires_at || '永不过期' }}</span>
              <span>最近使用：{{ row.last_used_at || '从未使用' }}</span>
            </div>
            <div class="record-mobile-card__actions">
              <t-button size="small" theme="primary" variant="text" @click="openEditDialog(row)">编辑</t-button>
              <t-button size="small" theme="primary" variant="text" @click="openUsageLogs(row)">日志</t-button>
              <t-button
                size="small"
                variant="text"
                :theme="row.status === 'enabled' ? 'warning' : 'success'"
                @click="toggleStatus(row)"
              >
                {{ row.status === 'enabled' ? '停用' : '启用' }}
              </t-button>
              <t-button size="small" theme="danger" variant="text" @click="confirmRemove(row)">删除</t-button>
            </div>
          </article>
        </div>
      </data-state>
    </section>

    <t-dialog
      v-model:visible="dialogVisible"
      :header="dialogMode === 'create' ? '创建 API 密钥' : '编辑 API 密钥'"
      :confirm-btn="{ content: submitting ? '提交中...' : '保存', loading: submitting }"
      cancel-btn="取消"
      width="560px"
      @confirm="submit"
    >
      <t-form label-align="top">
        <t-form-item label="密钥名称" required-mark>
          <t-input v-model="form.name" placeholder="例如：魔方平台对接" maxlength="64" />
        </t-form-item>

        <t-form-item label="权限范围">
          <div class="scope-matrix">
            <div v-for="domain in SCOPE_DOMAINS" :key="domain.key" class="scope-matrix__row">
              <span class="scope-matrix__label">{{ domain.label }}</span>
              <t-radio-group v-model="form.scopes[domain.key]" variant="default-filled">
                <t-radio-button value="none">无</t-radio-button>
                <t-radio-button value="read">只读</t-radio-button>
                <t-radio-button value="write">读写</t-radio-button>
              </t-radio-group>
            </div>
            <p class="scope-matrix__tip">读写权限包含只读权限；已授权的接口范围见开放接口文档。</p>
          </div>
        </t-form-item>

        <t-form-item label="过期时间（可选）">
          <t-date-picker
            v-model="form.expires_at"
            format="YYYY-MM-DD HH:mm"
            value-type="YYYY-MM-DD HH:mm"
            clearable
            style="width: 100%"
          />
        </t-form-item>

        <t-form-item label="IP 白名单（可选，多个用英文逗号分隔）">
          <t-input v-model="form.ip_allowlist" placeholder="例如：203.0.113.9, 198.51.100.2" />
        </t-form-item>
      </t-form>
    </t-dialog>

    <t-dialog v-model:visible="secretVisible" header="密钥创建成功" :footer="false" width="560px">
      <div class="secret-box">
        <p class="secret-box__warning">{{ secretWarning }}</p>
        <div class="secret-box__value">
          <code>{{ createdSecret }}</code>
          <t-button size="small" theme="primary" variant="outline" @click="copySecret">复制</t-button>
        </div>
        <div class="secret-box__hint">
          完整密钥仅显示这一次，关闭后无法再次查看。请立即保存到安全位置，并通过「编辑」更新过期时间或白名单。
        </div>
        <t-button block theme="primary" @click="closeSecret">我已保存</t-button>
      </div>
    </t-dialog>

    <t-drawer
      v-model:visible="logVisible"
      :header="`调用日志 · ${logKey?.name || '密钥'}`"
      size="560px"
      :footer="false"
    >
      <data-state :loading="logLoading" :empty="!logs.length" description="暂无调用记录">
        <t-table row-key="created_at" :data="logs" :columns="logColumns" :pagination="null" hover />
      </data-state>
    </t-drawer>
  </section>
</template>
<script setup lang="ts">
import DataState from '@shared/user-v3/components/DataState.vue';
import type { PrimaryTableCol } from 'tdesign-vue-next';
import { DialogPlugin } from 'tdesign-vue-next';

import {
  resolveScopeLevelLabel,
  resolveScopeTheme,
  SCOPE_DOMAINS,
  scopesDisplay,
  useApiKeys,
} from '@/domains/developer/useApiKeys';
import type { ApiKeyRecord } from '@/types/client';

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
    header: '删除 API 密钥',
    body: `确定删除密钥「${row.name || '未命名'}」吗？删除后使用该密钥的对接将立即失效。`,
    theme: 'warning',
    confirmBtn: { content: '删除', theme: 'danger' },
    onConfirm: async () => {
      await removeKey(row);
      dialog.destroy();
    },
  });
}

const columns: PrimaryTableCol[] = [
  { colKey: 'key_name', title: '名称', minWidth: '12rem' },
  { colKey: 'scopes', title: '权限范围', minWidth: '16rem' },
  { colKey: 'status', title: '状态', width: '6rem' },
  { colKey: 'expires_at', title: '到期时间', minWidth: '10rem' },
  { colKey: 'last_used_at', title: '最近使用', minWidth: '10rem' },
  { colKey: 'operation', title: '操作', width: '16rem', fixed: 'right', align: 'right' },
];

const logColumns: PrimaryTableCol[] = [
  { colKey: 'created_at', title: '时间', minWidth: '10rem' },
  { colKey: 'method', title: '方法', width: '5rem' },
  { colKey: 'path', title: '接口路径', minWidth: '14rem' },
  { colKey: 'status_code', title: '状态', width: '5rem' },
  { colKey: 'ip', title: 'IP', minWidth: '8rem' },
  { colKey: 'duration_ms', title: '耗时', width: '6rem' },
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
  color: var(--td-text-color-secondary);
  font: var(--td-font-body-small);
  max-width: 42rem;
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
