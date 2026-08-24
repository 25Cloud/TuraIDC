<template>
  <div class="open-api-page">
    <t-card :bordered="false" class="open-api-tabs-card">
      <t-tabs v-model="activeTab">
        <t-tab-panel value="config" label="配置">
          <div v-if="configLoading" class="state-placeholder">加载中...</div>
          <template v-else>
            <div class="config-grid">
              <article class="config-card">
                <div class="config-card__info">
                  <strong>启用开放接口</strong>
                  <p>关闭后所有 /api/v2/open 请求直接返回 403，已生成的密钥不可用。</p>
                </div>
                <t-switch v-model="config.enabled" :disabled="!canManage" />
              </article>

              <article class="config-card">
                <div class="config-card__info">
                  <strong>必须绑定手机号</strong>
                  <p>开启后，未绑定手机号的用户无法创建 API 密钥。</p>
                </div>
                <t-switch v-model="config.require_phone" :disabled="!canManage" />
              </article>

              <article class="config-card">
                <div class="config-card__info">
                  <strong>必须实名认证</strong>
                  <p>开启后，未完成实名认证的用户无法创建 API 密钥。</p>
                </div>
                <t-switch v-model="config.require_verified" :disabled="!canManage" />
              </article>

              <article class="config-card">
                <div class="config-card__info">
                  <strong>每用户密钥上限</strong>
                  <p>单个用户最多可创建的密钥数量。</p>
                </div>
                <t-input-number v-model="config.max_keys_per_user" :min="1" :max="100" :disabled="!canManage" />
              </article>

              <article class="config-card">
                <div class="config-card__info">
                  <strong>接口限流（次/分钟）</strong>
                  <p>同一密钥每分钟最大请求数，超出返回 429。</p>
                </div>
                <t-input-number v-model="config.rate_limit" :min="1" :max="3600" :disabled="!canManage" />
              </article>
            </div>

            <div class="config-actions">
              <t-button theme="primary" :loading="configSaving" :disabled="!canManage" @click="saveConfig">
                <template #icon><check-icon /></template>
                保存配置
              </t-button>
            </div>
          </template>
        </t-tab-panel>

        <t-tab-panel value="keys" label="密钥管理">
          <div class="keys-toolbar">
            <t-input
              v-model="filters.keyword"
              clearable
              placeholder="搜索密钥名称 / 前缀"
              @enter="handleSearch"
              @clear="handleSearch"
            >
              <template #suffixIcon><search-icon /></template>
            </t-input>
            <t-select v-model="filters.status" clearable placeholder="全部状态" @change="handleSearch">
              <t-option label="已启用" value="enabled" />
              <t-option label="已停用" value="disabled" />
            </t-select>
          </div>

          <t-table
            class="keys-table"
            row-key="id"
            :data="keys"
            :columns="columns"
            :loading="keysLoading"
            :pagination="{
              total: keysTotal,
              current: filters.page,
              pageSize: filters.page_size,
              showJumper: true,
              onChange: (pageInfo: { current: number; pageSize: number }) => handlePageChange(pageInfo),
            }"
            hover
          >
            <template #key_name="{ row }">
              <div class="stack-cell">
                <strong>{{ row.name || '未命名' }}</strong>
                <span>{{ row.key_prefix }}••••{{ row.secret_last4 }}</span>
              </div>
            </template>
            <template #owner="{ row }">
              <div class="stack-cell">
                <strong>{{ row.user?.nickname || '--' }}</strong>
                <span>{{ row.user?.email || row.user?.phone || '--' }}</span>
              </div>
            </template>
            <template #scopes="{ row }">
              <div class="scope-tags">
                <t-tag
                  v-for="item in scopesDisplay(row.scopes)"
                  :key="item.key"
                  size="small"
                  :theme="item.level === 'write' ? 'primary' : 'success'"
                  variant="light"
                >
                  {{ item.label }} · {{ item.level === 'write' ? '读写' : '只读' }}
                </t-tag>
              </div>
            </template>
            <template #status="{ row }">
              <t-tag :theme="row.status === 'enabled' ? 'success' : 'default'" variant="light">
                {{ row.status === 'enabled' ? '已启用' : '已停用' }}
              </t-tag>
            </template>
            <template #expires_at="{ row }">{{ row.expires_at || '永不过期' }}</template>
            <template #operation="{ row }">
              <t-space size="0">
                <t-button size="small" variant="text" :disabled="!canManage" @click="openUsageLogs(row)">日志</t-button>
                <t-button
                  size="small"
                  variant="text"
                  :theme="row.status === 'enabled' ? 'warning' : 'success'"
                  :disabled="!canManage"
                  @click="toggleStatus(row)"
                >
                  {{ row.status === 'enabled' ? '停用' : '启用' }}
                </t-button>
                <t-button size="small" theme="danger" variant="text" :disabled="!canManage" @click="confirmRemove(row)">
                  删除
                </t-button>
              </t-space>
            </template>
          </t-table>
        </t-tab-panel>
      </t-tabs>
    </t-card>

    <t-drawer
      v-model:visible="logVisible"
      :header="`调用日志 · ${logKey?.name || '密钥'}`"
      size="560px"
      :footer="false"
    >
      <t-table row-key="created_at" :data="logs" :columns="logColumns" :loading="logLoading" :pagination="null" hover />
    </t-drawer>
  </div>
</template>
<script setup lang="ts">
import { CheckIcon, SearchIcon } from 'tdesign-icons-vue-next';
import type { PrimaryTableCol } from 'tdesign-vue-next';
import { DialogPlugin, MessagePlugin } from 'tdesign-vue-next';
import { computed, onMounted, reactive, ref } from 'vue';

import { adminApi } from '@/api/admin';
import type { OpenApiConfigPayload, OpenApiKeyRecord, OpenApiUsageLogRecord } from '@/api/admin/openApi';
import { AdminPermissions } from '@/constants/permissions';
import { hasAdminPermission } from '@/utils/permission';
import { errorMessage } from '@/utils/userMessage';

defineOptions({ name: 'AdminOpenApi' });

const SCOPE_DOMAINS = [
  { key: 'products', label: '产品目录' },
  { key: 'orders', label: '订单与账单' },
  { key: 'services', label: '服务实例' },
  { key: 'finance', label: '余额与财务' },
] as const;

const activeTab = ref('config');
const canManage = computed(() => hasAdminPermission(AdminPermissions.OPEN_API_MANAGE));

const config = reactive<OpenApiConfigPayload>({
  enabled: 0,
  require_phone: 0,
  require_verified: 0,
  max_keys_per_user: 10,
  rate_limit: 60,
});
const configLoading = ref(false);
const configSaving = ref(false);

const filters = reactive({ keyword: '', status: '', page: 1, page_size: 20 });
const keys = ref<OpenApiKeyRecord[]>([]);
const keysLoading = ref(false);
const keysTotal = ref(0);

const logVisible = ref(false);
const logLoading = ref(false);
const logKey = ref<OpenApiKeyRecord | null>(null);
const logs = ref<OpenApiUsageLogRecord[]>([]);

function scopesDisplay(scopes: OpenApiKeyRecord['scopes']) {
  if (!scopes || typeof scopes !== 'object') return [];
  return SCOPE_DOMAINS.filter((domain) => (scopes as Record<string, unknown>)[domain.key]).map((domain) => ({
    ...domain,
    level: (scopes as Record<string, string>)[domain.key],
  }));
}

async function loadConfig() {
  configLoading.value = true;
  try {
    const data = await adminApi.openApi.getConfig();
    Object.assign(config, data);
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '加载配置失败'));
  } finally {
    configLoading.value = false;
  }
}

async function saveConfig() {
  configSaving.value = true;
  try {
    const data = await adminApi.openApi.saveConfig({ ...config });
    Object.assign(config, data);
    MessagePlugin.success('开放接口配置已保存');
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '保存配置失败'));
  } finally {
    configSaving.value = false;
  }
}

async function loadKeys() {
  keysLoading.value = true;
  try {
    const result = await adminApi.openApi.keys({
      page: filters.page,
      page_size: filters.page_size,
      keyword: filters.keyword || undefined,
      status: filters.status || undefined,
    });
    keys.value = result.list;
    keysTotal.value = result.total;
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '密钥列表加载失败'));
  } finally {
    keysLoading.value = false;
  }
}

function handleSearch() {
  filters.page = 1;
  void loadKeys();
}

function handlePageChange(pageInfo: { current: number; pageSize: number }) {
  filters.page = pageInfo.current;
  filters.page_size = pageInfo.pageSize;
  void loadKeys();
}

async function toggleStatus(row: OpenApiKeyRecord) {
  const next = row.status === 'enabled' ? 'disabled' : 'enabled';
  try {
    await adminApi.openApi.setStatus(row.id, next);
    MessagePlugin.success(next === 'enabled' ? '密钥已启用' : '密钥已停用');
    await loadKeys();
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '操作失败'));
  }
}

function confirmRemove(row: OpenApiKeyRecord) {
  const dialog = DialogPlugin.confirm({
    header: '删除 API 密钥',
    body: `确定删除密钥「${row.name || '未命名'}」（${row.user?.nickname || row.user?.email || '未知用户'}）吗？删除后该密钥立即失效。`,
    theme: 'warning',
    confirmBtn: { content: '删除', theme: 'danger' },
    onConfirm: async () => {
      try {
        await adminApi.openApi.remove(row.id);
        MessagePlugin.success('密钥已删除');
        await loadKeys();
      } catch (error) {
        MessagePlugin.error(errorMessage(error, '删除失败'));
      }
      dialog.destroy();
    },
  });
}

async function openUsageLogs(row: OpenApiKeyRecord) {
  logKey.value = row;
  logVisible.value = true;
  logLoading.value = true;
  logs.value = [];
  try {
    const result = await adminApi.openApi.usageLogs(row.id, { page: 1, page_size: 50 });
    logs.value = result.list;
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '调用日志加载失败'));
  } finally {
    logLoading.value = false;
  }
}

const columns: PrimaryTableCol[] = [
  { colKey: 'key_name', title: '密钥', minWidth: '11rem' },
  { colKey: 'owner', title: '所属用户', minWidth: '11rem' },
  { colKey: 'scopes', title: '权限范围', minWidth: '16rem' },
  { colKey: 'status', title: '状态', width: '6rem' },
  { colKey: 'expires_at', title: '到期时间', minWidth: '9rem' },
  { colKey: 'operation', title: '操作', width: '12rem', fixed: 'right', align: 'right' },
];

const logColumns: PrimaryTableCol[] = [
  { colKey: 'created_at', title: '时间', minWidth: '10rem' },
  { colKey: 'method', title: '方法', width: '5rem' },
  { colKey: 'path', title: '接口路径', minWidth: '14rem' },
  { colKey: 'status_code', title: '状态', width: '5rem' },
  { colKey: 'ip', title: 'IP', minWidth: '8rem' },
  { colKey: 'duration_ms', title: '耗时', width: '6rem' },
];

onMounted(() => {
  void loadConfig();
  void loadKeys();
});
</script>
<style scoped lang="less">
.open-api-page {
  display: flex;
  flex-direction: column;
  gap: var(--td-comp-margin-m);
}

.open-api-tabs-card {
  background: var(--td-bg-color-container);
  border: thin solid var(--td-border-color);
  border-radius: var(--td-radius-medium);
  box-shadow: var(--td-shadow-1);
}

.state-placeholder {
  padding: var(--td-comp-paddingTB-xxl) 0;
  text-align: center;
  color: var(--td-text-color-placeholder);
}

.config-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(20rem, 1fr));
  gap: var(--td-comp-margin-m);
  margin-bottom: var(--td-comp-margin-l);
}

.config-card {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--td-comp-margin-l);
  padding: var(--td-comp-paddingLR-l) var(--td-comp-paddingLR-m);
  border: thin solid var(--td-component-border);
  border-radius: var(--td-radius-medium);
  background: var(--td-bg-color-secondarycontainer);
}

.config-card__info {
  min-width: 0;

  strong {
    display: block;
    margin-bottom: var(--td-comp-margin-xxs);
    color: var(--td-text-color-primary);
    font: var(--td-font-body-medium);
  }

  p {
    margin: 0;
    color: var(--td-text-color-secondary);
    font: var(--td-font-body-small);
  }
}

.config-actions {
  display: flex;
  justify-content: flex-end;
}

.keys-toolbar {
  display: grid;
  grid-template-columns: minmax(14rem, 1fr) 12rem;
  gap: var(--td-comp-margin-s);
  margin-bottom: var(--td-comp-margin-m);
}

.keys-table {
  width: 100%;
}

.stack-cell {
  display: flex;
  flex-direction: column;
  gap: var(--td-comp-margin-xxs);
  min-width: 0;

  strong,
  span {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  strong {
    color: var(--td-text-color-primary);
    font-weight: 600;
  }

  span {
    color: var(--td-text-color-secondary);
    font-size: 12px;
  }
}

.scope-tags {
  display: flex;
  flex-wrap: wrap;
  gap: var(--td-comp-margin-xxs);
}
</style>
