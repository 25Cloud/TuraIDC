<template>
  <div class="open-api-page">
    <t-card :bordered="false" class="open-api-tabs-card">
      <t-tabs v-model="activeTab">
        <t-tab-panel value="config" :label="$t('openApi.index.tabConfig')">
          <div v-if="configLoading" class="state-placeholder">{{ $t('openApi.index.loading') }}</div>
          <template v-else>
            <div class="config-grid">
              <article class="config-card">
                <div class="config-card__info">
                  <strong>{{ $t('openApi.index.enableOpenApi') }}</strong>
                  <p>{{ $t('openApi.index.enableOpenApiDesc') }}</p>
                </div>
                <t-switch v-model="config.enabled" :custom-value="[1, 0]" :disabled="!canManage" />
              </article>

              <article class="config-card">
                <div class="config-card__info">
                  <strong>{{ $t('openApi.index.requirePhone') }}</strong>
                  <p>{{ $t('openApi.index.requirePhoneDesc') }}</p>
                </div>
                <t-switch v-model="config.require_phone" :custom-value="[1, 0]" :disabled="!canManage" />
              </article>

              <article class="config-card">
                <div class="config-card__info">
                  <strong>{{ $t('openApi.index.requireVerified') }}</strong>
                  <p>{{ $t('openApi.index.requireVerifiedDesc') }}</p>
                </div>
                <t-switch v-model="config.require_verified" :custom-value="[1, 0]" :disabled="!canManage" />
              </article>

              <article class="config-card">
                <div class="config-card__info">
                  <strong>{{ $t('openApi.index.maxKeysPerUser') }}</strong>
                  <p>{{ $t('openApi.index.maxKeysPerUserDesc') }}</p>
                </div>
                <t-input-number v-model="config.max_keys_per_user" :min="1" :max="100" :disabled="!canManage" />
              </article>

              <article class="config-card">
                <div class="config-card__info">
                  <strong>{{ $t('openApi.index.rateLimit') }}</strong>
                  <p>{{ $t('openApi.index.rateLimitDesc') }}</p>
                </div>
                <t-input-number v-model="config.rate_limit" :min="1" :max="3600" :disabled="!canManage" />
              </article>

              <article class="config-card">
                <div class="config-card__info">
                  <strong>{{ $t('openApi.index.writeRateLimit') }}</strong>
                  <p>{{ $t('openApi.index.writeRateLimitDesc') }}</p>
                </div>
                <t-input-number v-model="config.write_rate_limit" :min="1" :max="3600" :disabled="!canManage" />
              </article>

              <article class="config-card">
                <div class="config-card__info">
                  <strong>{{ $t('openApi.index.usageLogRetentionDays') }}</strong>
                  <p>{{ $t('openApi.index.usageLogRetentionDesc') }}</p>
                </div>
                <t-input-number v-model="config.usage_log_retention_days" :min="1" :max="3650" :disabled="!canManage" />
              </article>
            </div>

            <div class="config-actions">
              <t-button theme="primary" :loading="configSaving" :disabled="!canManage" @click="saveConfig">
                <template #icon><check-icon /></template>
                {{ $t('openApi.index.saveConfig') }}
              </t-button>
            </div>
          </template>
        </t-tab-panel>

        <t-tab-panel value="keys" :label="$t('openApi.index.tabKeys')">
          <div class="keys-toolbar">
            <t-input
              v-model="filters.keyword"
              clearable
              :placeholder="$t('openApi.index.searchKeyPlaceholder')"
              @enter="handleSearch"
              @clear="handleSearch"
            >
              <template #suffixIcon><search-icon /></template>
            </t-input>
            <t-select v-model="filters.status" clearable :placeholder="$t('openApi.index.allStatuses')" @change="handleSearch">
              <t-option :label="$t('openApi.index.statusEnabled')" value="enabled" />
              <t-option :label="$t('openApi.index.statusDisabled')" value="disabled" />
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
                <strong>{{ row.name || $t('openApi.index.untitled') }}</strong>
                <!-- key_prefix 是密钥的公开标识（tura_ 前缀），并非密钥本体；
                     实际凭据是创建时展示一次的 secret，此处仅作识别用。 -->
                <span :title="$t('openApi.index.keyIdentifierTip', { prefix: row.key_prefix })">
                  {{ row.key_prefix }}••••{{ row.secret_last4 }}
                </span>
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
                  {{ item.label }} · {{ item.level === 'write' ? $t('openApi.index.scopeReadWrite') : $t('openApi.index.scopeReadOnly') }}
                </t-tag>
              </div>
            </template>
            <template #status="{ row }">
              <t-tag :theme="row.status === 'enabled' ? 'success' : 'default'" variant="light">
                {{ row.status === 'enabled' ? $t('openApi.index.statusEnabled') : $t('openApi.index.statusDisabled') }}
              </t-tag>
            </template>
            <template #expires_at="{ row }">{{ row.expires_at || $t('openApi.index.neverExpires') }}</template>
            <template #operation="{ row }">
              <t-space size="0">
                <t-button size="small" variant="text" :disabled="!canManage" @click="openUsageLogs(row)">{{ $t('openApi.index.viewLogs') }}</t-button>
                <t-button
                  size="small"
                  variant="text"
                  :theme="row.status === 'enabled' ? 'warning' : 'success'"
                  :disabled="!canManage"
                  @click="toggleStatus(row)"
                >
                  {{ row.status === 'enabled' ? $t('openApi.index.disable') : $t('openApi.index.enable') }}
                </t-button>
                <t-button size="small" theme="danger" variant="text" :disabled="!canManage" @click="confirmRemove(row)">
                  {{ $t('openApi.index.remove') }}
                </t-button>
              </t-space>
            </template>
          </t-table>
        </t-tab-panel>

        <t-tab-panel value="upstream" :label="$t('openApi.index.tabUpstream')">
          <div class="upstream-notice">
            {{ $t('openApi.index.upstreamAuditNotice') }}
          </div>
          <div class="keys-toolbar upstream-toolbar">
            <t-input
              v-model="upstreamFilters.user_id"
              clearable
              type="number"
              :min="1"
              :placeholder="$t('openApi.index.filterByUserId')"
              @enter="handleUpstreamSearch"
              @clear="handleUpstreamSearch"
            />
            <t-select
              v-model="upstreamFilters.status_code"
              clearable
              :placeholder="$t('openApi.index.allStatusCodes')"
              @change="handleUpstreamSearch"
            >
              <t-option :label="$t('openApi.index.code200')" :value="200" />
              <t-option :label="$t('openApi.index.code400')" :value="400" />
              <t-option :label="$t('openApi.index.code405')" :value="405" />
              <t-option :label="$t('openApi.index.code403')" :value="403" />
            </t-select>
          </div>

          <t-table
            class="keys-table"
            row-key="id"
            :data="upstreamLogs"
            :columns="upstreamLogColumns"
            :loading="upstreamLogsLoading"
            :pagination="{
              total: upstreamLogsTotal,
              current: upstreamFilters.page,
              pageSize: upstreamFilters.page_size,
              showJumper: true,
              onChange: (pageInfo: { current: number; pageSize: number }) => handleUpstreamPageChange(pageInfo),
            }"
            hover
          >
            <template #user_id="{ row }">#{{ row.user_id ?? '--' }}</template>
            <template #status_code="{ row }">
              <t-tag
                :theme="row.status_code === 200 ? 'success' : row.status_code === 405 ? 'warning' : 'danger'"
                variant="light"
              >
                {{ row.status_code }}
              </t-tag>
            </template>
          </t-table>
        </t-tab-panel>
      </t-tabs>
    </t-card>

    <t-drawer
      v-model:visible="logVisible"
      :header="$t('openApi.index.usageLogHeader', { name: logKey?.name || $t('openApi.index.keyFallback') })"
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
import { t } from '@/locales';

defineOptions({ name: 'AdminOpenApi' });

const SCOPE_DOMAINS = [
  { key: 'products', label: t('openApi.index.scopeProducts') },
  { key: 'orders', label: t('openApi.index.scopeOrders') },
  { key: 'services', label: t('openApi.index.scopeServices') },
  { key: 'finance', label: t('openApi.index.scopeFinance') },
] as const;

const activeTab = ref('config');
const canManage = computed(() => hasAdminPermission(AdminPermissions.OPEN_API_MANAGE));

const config = reactive<OpenApiConfigPayload>({
  enabled: 0,
  require_phone: 0,
  require_verified: 0,
  max_keys_per_user: 10,
  rate_limit: 60,
  write_rate_limit: 30,
  usage_log_retention_days: 90,
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

const upstreamFilters = reactive<{ user_id: string; status_code: number | ''; page: number; page_size: number }>({
  user_id: '',
  status_code: '',
  page: 1,
  page_size: 20,
});
const upstreamLogs = ref<OpenApiUsageLogRecord[]>([]);
const upstreamLogsLoading = ref(false);
const upstreamLogsTotal = ref(0);

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
    MessagePlugin.success(t('openApi.index.configSaved'));
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
        MessagePlugin.success(t('openApi.index.keyDeleted'));
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
  { colKey: 'key_name', title: t('openApi.index.keyName'), minWidth: '11rem' },
  { colKey: 'owner', title: t('openApi.index.owner'), minWidth: '11rem' },
  { colKey: 'scopes', title: t('openApi.index.scopes'), minWidth: '16rem' },
  { colKey: 'status', title: t('common.status'), width: '6rem' },
  { colKey: 'expires_at', title: t('openApi.index.expiresAt'), minWidth: '9rem' },
  { colKey: 'operation', title: t('common.action'), width: '12rem', fixed: 'right', align: 'right' },
];

const logColumns: PrimaryTableCol[] = [
  { colKey: 'created_at', title: t('openApi.index.time'), minWidth: '10rem' },
  { colKey: 'method', title: t('openApi.index.method'), width: '5rem' },
  { colKey: 'path', title: t('openApi.index.path'), minWidth: '14rem' },
  { colKey: 'status_code', title: t('common.status'), width: '5rem' },
  { colKey: 'ip', title: 'IP', minWidth: '8rem' },
  { colKey: 'duration_ms', title: t('openApi.index.duration'), width: '6rem' },
];

const upstreamLogColumns: PrimaryTableCol[] = [
  { colKey: 'created_at', title: t('openApi.index.time'), minWidth: '10rem' },
  { colKey: 'user_id', title: t('openApi.index.account'), width: '5rem' },
  { colKey: 'method', title: t('openApi.index.method'), width: '5rem' },
  { colKey: 'path', title: t('openApi.index.path'), minWidth: '14rem' },
  { colKey: 'status_code', title: t('common.status'), width: '5rem' },
  { colKey: 'ip', title: 'IP', minWidth: '8rem' },
  { colKey: 'duration_ms', title: t('openApi.index.duration'), width: '6rem' },
];

async function loadUpstreamLogs() {
  upstreamLogsLoading.value = true;
  try {
    const userId = Number(upstreamFilters.user_id);
    const result = await adminApi.openApi.upstreamUsageLogs({
      page: upstreamFilters.page,
      page_size: upstreamFilters.page_size,
      user_id: Number.isInteger(userId) && userId > 0 ? userId : undefined,
      status_code: upstreamFilters.status_code === '' ? undefined : upstreamFilters.status_code,
    });
    upstreamLogs.value = result.list;
    upstreamLogsTotal.value = result.total;
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '上游链路审计加载失败'));
  } finally {
    upstreamLogsLoading.value = false;
  }
}

function handleUpstreamSearch() {
  upstreamFilters.page = 1;
  void loadUpstreamLogs();
}

function handleUpstreamPageChange(pageInfo: { current: number; pageSize: number }) {
  upstreamFilters.page = pageInfo.current;
  upstreamFilters.page_size = pageInfo.pageSize;
  void loadUpstreamLogs();
}

onMounted(() => {
  void loadConfig();
  void loadKeys();
  void loadUpstreamLogs();
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

.upstream-notice {
  margin-bottom: var(--td-comp-margin-m);
  padding: var(--td-comp-paddingTB-s) var(--td-comp-paddingLR-m);
  border: thin solid var(--td-component-border);
  border-radius: var(--td-radius-medium);
  background: var(--td-bg-color-secondarycontainer);
  color: var(--td-text-color-secondary);
  font: var(--td-font-body-small);
}

/* 两个过滤控件并排，沿用 keys-toolbar 的两列网格 */
.upstream-toolbar {
  grid-template-columns: minmax(14rem, 1fr) 12rem;
}
</style>
