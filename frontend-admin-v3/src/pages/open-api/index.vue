<template>
  <div class="open-api-page">
    <t-card :bordered="false" class="open-api-tabs-card">
      <t-tabs v-model="activeTab">
        <t-tab-panel value="config" :label="$t('openApi.index.tabConfig')">
          <div v-if="configLoading" class="state-placeholder">{{ $t('openApi.index.loading') }}</div>
          <template v-else>
            <!-- 先开关、后数值限额，一项占一格：标签一行 → 说明一行 → 控件一行，
                 说明不再和控件抢同一行（此前说明长了控件被压窄，控件长了说明被压成两行）。 -->
            <div class="admin-field-grid">
              <div class="admin-field admin-field--switch">
                <span class="admin-field__label">{{ $t('openApi.index.enableOpenApi') }}</span>
                <div class="admin-field__control">
                  <t-switch v-model="config.enabled" :custom-value="[1, 0]" :disabled="!canManage" />
                </div>
                <p class="admin-field__desc">{{ $t('openApi.index.enableOpenApiDesc') }}</p>
              </div>

              <div class="admin-field admin-field--switch">
                <span class="admin-field__label">{{ $t('openApi.index.requirePhone') }}</span>
                <div class="admin-field__control">
                  <t-switch v-model="config.require_phone" :custom-value="[1, 0]" :disabled="!canManage" />
                </div>
                <p class="admin-field__desc">{{ $t('openApi.index.requirePhoneDesc') }}</p>
              </div>

              <div class="admin-field admin-field--switch">
                <span class="admin-field__label">{{ $t('openApi.index.requireVerified') }}</span>
                <div class="admin-field__control">
                  <t-switch v-model="config.require_verified" :custom-value="[1, 0]" :disabled="!canManage" />
                </div>
                <p class="admin-field__desc">{{ $t('openApi.index.requireVerifiedDesc') }}</p>
              </div>

              <div class="admin-field">
                <span class="admin-field__label">{{ $t('openApi.index.maxKeysPerUser') }}</span>
                <p class="admin-field__desc">{{ $t('openApi.index.maxKeysPerUserDesc') }}</p>
                <div class="admin-field__control admin-field__control--narrow">
                  <t-input-number v-model="config.max_keys_per_user" :min="1" :max="100" :disabled="!canManage" />
                </div>
              </div>

              <div class="admin-field">
                <span class="admin-field__label">{{ $t('openApi.index.rateLimit') }}</span>
                <p class="admin-field__desc">{{ $t('openApi.index.rateLimitDesc') }}</p>
                <div class="admin-field__control admin-field__control--narrow">
                  <t-input-number v-model="config.rate_limit" :min="1" :max="3600" :disabled="!canManage" />
                </div>
              </div>

              <div class="admin-field">
                <span class="admin-field__label">{{ $t('openApi.index.writeRateLimit') }}</span>
                <p class="admin-field__desc">{{ $t('openApi.index.writeRateLimitDesc') }}</p>
                <div class="admin-field__control admin-field__control--narrow">
                  <t-input-number v-model="config.write_rate_limit" :min="1" :max="3600" :disabled="!canManage" />
                </div>
              </div>

              <!-- 最后一项整行铺满：7 项两列排下来不留半行空位 -->
              <div class="admin-field admin-field--full">
                <span class="admin-field__label">{{ $t('openApi.index.usageLogRetentionDays') }}</span>
                <p class="admin-field__desc">{{ $t('openApi.index.usageLogRetentionDesc') }}</p>
                <div class="admin-field__control admin-field__control--narrow">
                  <t-input-number
                    v-model="config.usage_log_retention_days"
                    :min="1"
                    :max="3650"
                    :disabled="!canManage"
                  />
                </div>
              </div>
            </div>

            <!-- 保存操作条与设置页一致：吸在视口底部右对齐 -->
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
            <t-select
              v-model="filters.status"
              clearable
              :placeholder="$t('openApi.index.allStatuses')"
              @change="handleSearch"
            >
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
                  {{ item.label }} ·
                  {{ item.level === 'write' ? $t('openApi.index.scopeReadWrite') : $t('openApi.index.scopeReadOnly') }}
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
                <t-button size="small" variant="text" :disabled="!canManage" @click="openUsageLogs(row)">{{
                  $t('openApi.index.viewLogs')
                }}</t-button>
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
            <template #user_id="{ row }">
              <button
                type="button"
                class="user-link"
                :disabled="!isEntityId(row.user_id)"
                @click="goUser(router, row.user_id)"
              >
                <strong>#{{ row.user_id ?? '--' }}</strong>
              </button>
            </template>
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
import { useRouter } from 'vue-router';

import { adminApi } from '@/api/admin';
import type { OpenApiConfigPayload, OpenApiKeyRecord, OpenApiUsageLogRecord } from '@/api/admin/openApi';
import { AdminPermissions } from '@/constants/permissions';
import { t } from '@/locales';
import { hasAdminPermission } from '@/utils/permission';
import { goUser, isEntityId } from '@/utils/route/adminEntityLink';
import { errorMessage } from '@/utils/userMessage';

defineOptions({ name: 'AdminOpenApi' });

const SCOPE_DOMAINS = [
  { key: 'products', label: t('openApi.index.scopeProducts') },
  { key: 'orders', label: t('openApi.index.scopeOrders') },
  { key: 'services', label: t('openApi.index.scopeServices') },
  { key: 'finance', label: t('openApi.index.scopeFinance') },
] as const;

const router = useRouter();
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
    MessagePlugin.error(errorMessage(error, t('openApi.index.loadConfigFailed')));
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
    MessagePlugin.error(errorMessage(error, t('openApi.index.saveConfigFailed')));
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
    MessagePlugin.error(errorMessage(error, t('openApi.index.loadKeysFailed')));
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
    MessagePlugin.success(next === 'enabled' ? t('openApi.index.keyEnabled') : t('openApi.index.keyDisabled'));
    await loadKeys();
  } catch (error) {
    MessagePlugin.error(errorMessage(error, t('openApi.index.operationFailed')));
  }
}

function confirmRemove(row: OpenApiKeyRecord) {
  const dialog = DialogPlugin.confirm({
    header: t('openApi.index.deleteKeyTitle'),
    body: t('openApi.index.deleteKeyConfirm', {
      name: row.name || t('openApi.index.untitled'),
      user: row.user?.nickname || row.user?.email || t('openApi.index.unknownUser'),
    }),
    theme: 'warning',
    confirmBtn: { content: t('openApi.index.remove'), theme: 'danger' },
    onConfirm: async () => {
      try {
        await adminApi.openApi.remove(row.id);
        MessagePlugin.success(t('openApi.index.keyDeleted'));
        await loadKeys();
      } catch (error) {
        MessagePlugin.error(errorMessage(error, t('openApi.index.deleteFailed')));
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
    MessagePlugin.error(errorMessage(error, t('openApi.index.loadUsageLogsFailed')));
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
    MessagePlugin.error(errorMessage(error, t('openApi.index.loadUpstreamAuditFailed')));
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

/* 配置项排版走全局 .admin-field / .admin-field-grid，页面内不再套一层小卡片 */
/* 保存操作条：与设置页同一形态，吸在视口底部右对齐 */
.config-actions {
  position: sticky;
  bottom: 0;
  z-index: 10;
  display: flex;
  justify-content: flex-end;
  margin-top: var(--td-comp-margin-l);
  padding: var(--td-comp-paddingTB-s) var(--td-comp-paddingLR-l);
  border-top: 1px solid var(--td-component-stroke);
  border-radius: var(--td-radius-medium);
  background: var(--td-bg-color-container);
  box-shadow: var(--td-shadow-1);
}

/* 数字控件铺满自己的格子，宽度由 --narrow 收窄 */
.open-api-page :deep(.admin-field__control > .t-input-number) {
  width: 100%;
}

/* 窄屏控件铺满整行，加减号也不会被挤成小点 */
@media (width <= 768px) {
  .open-api-page :deep(.admin-field__control--narrow),
  .open-api-page :deep(.admin-field__control--medium) {
    max-width: none;
  }

  .config-actions {
    flex-wrap: wrap;
    padding-right: 12px;
    padding-left: 12px;
  }

  /* 窄屏保存按钮铺满整行 */
  .config-actions .t-button {
    width: 100%;
  }
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
