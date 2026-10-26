<template>
  <div class="ai-conversations-page">
    <t-card :bordered="false">
      <div class="ai-conversations-filter">
        <t-input
          v-model="filters.user_id"
          clearable
          class="ai-conversations-filter__user"
          :placeholder="$t('ai.conversations.userIdPlaceholder')"
          @enter="handleSearch"
          @clear="handleSearch"
        />
        <t-select
          v-model="filters.channel"
          clearable
          class="ai-conversations-filter__channel"
          :placeholder="$t('ai.conversations.channelAll')"
          @change="handleSearch"
        >
          <t-option v-for="option in channelOptions" :key="option.value" :label="option.label" :value="option.value" />
        </t-select>
        <t-button theme="primary" @click="handleSearch">{{ $t('ai.conversations.search') }}</t-button>
        <t-button variant="outline" @click="handleReset">{{ $t('ai.conversations.reset') }}</t-button>
      </div>

      <p v-if="usageSummary" class="ai-conversations-usage">{{ usageSummary }}</p>
    </t-card>

    <t-card :bordered="false" class="ai-conversations-table-card">
      <t-table
        row-key="id"
        :data="list"
        :columns="columns"
        :loading="loading"
        hover
        table-layout="fixed"
        @row-click="handleRowClick"
      >
        <template #id="{ row }">
          <strong>#{{ row.id }}</strong>
        </template>
        <template #user="{ row }">{{ userLabel(row) }}</template>
        <template #channel="{ row }">
          <t-tag size="small" variant="light" :theme="channelTheme(row.channel)">
            {{ channelLabel(row.channel) }}
          </t-tag>
        </template>
        <template #title="{ row }">{{ row.title || '-' }}</template>
        <template #message_count="{ row }">{{ row.message_count ?? 0 }}</template>
        <template #permission_mode="{ row }">{{ modeLabel(row.permission_mode) }}</template>
        <template #last_message_at="{ row }">
          {{ row.last_message_at ? formatDateTime(row.last_message_at) : '-' }}
        </template>
        <template #op="{ row }">
          <t-link theme="primary" hover="color" @click.stop="openDetail(row.id)">
            {{ $t('ai.conversations.view') }}
          </t-link>
        </template>
      </t-table>

      <t-empty v-if="!list.length && !loading" :description="$t('ai.conversations.empty')" />

      <div v-if="total > 0" class="ai-conversations-pagination">
        <t-pagination
          :current="pagination.page"
          :page-size="pagination.page_size"
          :total="total"
          :page-size-options="pagination.pageSizeOptions"
          show-jumper
          @change="handlePaginationChange"
        />
      </div>
    </t-card>

    <t-drawer
      :visible="detailVisible"
      :header="$t('ai.conversations.drawerTitle', { id: detailId || '' })"
      :size="drawerSize"
      @close="closeDetail"
    >
      <t-loading :loading="detailLoading" size="small" show-overlay>
        <div v-if="detail" class="ai-conversations-detail">
          <div class="ai-conversations-detail__meta">
            <span>
              <em>{{ $t('ai.conversations.drawerUser') }}</em>
              {{ userLabel(detail.conversation) }}
            </span>
            <span>
              <em>{{ $t('ai.conversations.drawerChannel') }}</em>
              {{ channelLabel(detail.conversation.channel) }}
            </span>
            <span>
              <em>{{ $t('ai.conversations.drawerMode') }}</em>
              {{ modeLabel(detail.conversation.permission_mode) }}
            </span>
            <span>
              <em>{{ $t('ai.conversations.drawerMessages') }}</em>
              {{ detail.messages.length }}
            </span>
            <span>
              <em>{{ $t('ai.conversations.drawerCreated') }}</em>
              {{ detail.conversation.created_at ? formatDateTime(detail.conversation.created_at) : '-' }}
            </span>
            <span>
              <em>{{ $t('ai.conversations.drawerLast') }}</em>
              {{ detail.conversation.last_message_at ? formatDateTime(detail.conversation.last_message_at) : '-' }}
            </span>
          </div>

          <div v-if="detail.pending_actions && detail.pending_actions.length" class="ai-conversations-detail__pending">
            <h3>{{ $t('ai.conversations.drawerPending') }}</h3>
            <div v-for="action in detail.pending_actions" :key="action.id" class="ai-conversations-pending">
              <strong>{{ action.title }}</strong>
              <span>{{ action.risk }} · {{ action.description }}</span>
            </div>
          </div>

          <p v-if="!detail.messages.length" class="ai-conversations-detail__empty">
            {{ $t('ai.conversations.noMessages') }}
          </p>

          <article v-for="message in detail.messages" :key="message.id" class="ai-conversations-message">
            <header class="ai-conversations-message__head">
              <t-tag size="small" variant="light" :theme="messageTheme(message.role)">
                {{ messageRoleLabel(message.role) }}
              </t-tag>
              <time>{{ message.created_at ? formatDateTime(message.created_at) : '' }}</time>
            </header>
            <p v-if="message.content" class="ai-conversations-message__content">{{ message.content }}</p>

            <div v-if="message.tool_calls && message.tool_calls.length" class="ai-conversations-tools">
              <span class="ai-conversations-tools__label">{{ $t('ai.conversations.toolCalls') }}</span>
              <div
                v-for="(call, index) in message.tool_calls"
                :key="`${message.id}-${index}`"
                class="ai-conversations-tool"
              >
                <span class="ai-conversations-tool__name">{{ call.name }}</span>
                <span v-if="call.status" class="ai-conversations-tool__status">{{ call.status }}</span>
                <span v-if="call.summary" class="ai-conversations-tool__summary">{{ call.summary }}</span>
                <pre v-if="call.arguments" class="ai-conversations-tool__args">{{ call.arguments }}</pre>
              </div>
            </div>
          </article>
        </div>
      </t-loading>
    </t-drawer>
  </div>
</template>
<script setup lang="ts">
import type { AiConversationDetail } from '@turaidc/shared/runtime';
import type { PrimaryTableCol, TableRowData } from 'tdesign-vue-next';
import { MessagePlugin } from 'tdesign-vue-next';
import { computed, onMounted, ref } from 'vue';

import type { AiAdminConversation, AiUsageResponse } from '@/api/ai';
import { aiAdminApi } from '@/api/ai';
import { useListPage } from '@/hooks/useListPage';
import { useMediaQuery } from '@/hooks/useMediaQuery';
import { t } from '@/locales';
import { formatDateTime } from '@/utils/format';
import { errorMessage } from '@/utils/userMessage';

defineOptions({ name: 'AdminAiConversations' });

const isMobile = useMediaQuery('(max-width: 768px)');
const drawerSize = computed(() => (isMobile.value ? '100%' : '720px'));

const CHANNEL_KEYS: Record<string, string> = {
  console: 'ai.conversations.channelConsole',
  site: 'ai.conversations.channelSite',
  admin: 'ai.conversations.channelAdmin',
};

const MODE_KEYS: Record<string, string> = {
  read_only: 'ai.mode.readOnly',
  confirm: 'ai.mode.confirm',
  auto_approve: 'ai.mode.autoApprove',
};

const channelOptions = computed(() => Object.entries(CHANNEL_KEYS).map(([value, key]) => ({ value, label: t(key) })));

const columns = computed<PrimaryTableCol<TableRowData>[]>(() => [
  { colKey: 'id', title: t('ai.conversations.colId'), width: 100 },
  { colKey: 'user', title: t('ai.conversations.colUser'), width: 180 },
  { colKey: 'channel', title: t('ai.conversations.colChannel'), width: 130 },
  { colKey: 'title', title: t('ai.conversations.colTitle'), minWidth: 220, ellipsis: true },
  { colKey: 'message_count', title: t('ai.conversations.colMessages'), width: 100, align: 'center' },
  { colKey: 'permission_mode', title: t('ai.conversations.colMode'), width: 130 },
  { colKey: 'last_message_at', title: t('ai.conversations.colLastMessageAt'), width: 180 },
  { colKey: 'op', title: t('ai.conversations.colOp'), width: 90, fixed: 'right' },
]);

const { filters, list, total, loading, pagination, handleSearch, resetFilters, handlePaginationChange } = useListPage<
  { user_id: string; channel: string },
  AiAdminConversation
>({
  fetch: (params) =>
    aiAdminApi.conversations({
      page: params.page,
      page_size: params.page_size,
      user_id: params.user_id ? params.user_id.trim() : undefined,
      channel: params.channel || undefined,
    }),
  defaultFilters: { user_id: '', channel: '' },
  onError: (error) => MessagePlugin.error(errorMessage(error, t('ai.conversations.loadFailed'))),
});

// 重置走 hook 的默认筛选值，同时回到第一页重新拉取
function handleReset() {
  void resetFilters();
}

function userLabel(row: AiAdminConversation | null | undefined) {
  if (!row) return '-';
  const user = row.user || {};
  const name = user.display_name || user.nickname || user.email || user.phone;
  if (name) return String(name);
  return row.user_id ? t('ai.conversations.userIdFallback', { id: row.user_id }) : '-';
}

function channelLabel(value?: string) {
  const key = CHANNEL_KEYS[String(value || '')];
  return key ? t(key) : String(value || '-');
}

function channelTheme(value?: string): 'default' | 'primary' | 'success' | 'warning' {
  if (value === 'site') return 'success';
  if (value === 'admin') return 'warning';
  if (value === 'console') return 'primary';
  return 'default';
}

function modeLabel(value?: string) {
  const key = MODE_KEYS[String(value || '')];
  return key ? t(key) : String(value || '-');
}

function messageRoleLabel(role: string) {
  if (role === 'user') return t('ai.conversations.messageUser');
  if (role === 'assistant') return t('ai.conversations.messageAssistant');
  if (role === 'tool') return t('ai.conversations.messageTool');
  return t('ai.conversations.messageSystem');
}

function messageTheme(role: string): 'default' | 'primary' | 'success' | 'warning' {
  if (role === 'user') return 'primary';
  if (role === 'assistant') return 'success';
  if (role === 'tool') return 'warning';
  return 'default';
}

function handleRowClick({ row }: { row: TableRowData }) {
  if (row?.id) {
    openDetail(row.id as number);
  }
}

/* ===== 详情抽屉 ===== */
const detailVisible = ref(false);
const detailLoading = ref(false);
const detailId = ref<number | null>(null);
const detail = ref<AiConversationDetail | null>(null);

async function openDetail(id: number) {
  detailVisible.value = true;
  detailId.value = id;
  detailLoading.value = true;
  detail.value = null;
  try {
    detail.value = await aiAdminApi.conversation(id);
  } catch (error) {
    MessagePlugin.error(errorMessage(error, t('ai.conversations.detailFailed')));
    detailVisible.value = false;
  } finally {
    detailLoading.value = false;
  }
}

function closeDetail() {
  detailVisible.value = false;
  detailId.value = null;
  detail.value = null;
}

/* ===== 近 14 天用量合计：与审计列表同一屏，方便对照成本 ===== */
const usage = ref<AiUsageResponse | null>(null);

const usageSummary = computed(() => {
  const data = usage.value;
  if (!data) return '';
  const cost = (data.totals.cost_cents / 100).toFixed(2);
  return t('ai.conversations.usageSummary', {
    days: data.days,
    messages: data.totals.messages,
    prompt: data.totals.prompt_tokens,
    completion: data.totals.completion_tokens,
    cost,
  });
});

async function loadUsage() {
  try {
    usage.value = await aiAdminApi.usage(14);
  } catch {
    // 用量是辅助信息，拿不到不影响审计主流程
    usage.value = null;
  }
}

onMounted(loadUsage);
</script>
<style scoped lang="less">
.ai-conversations-filter {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.ai-conversations-filter__user {
  width: 220px;
}

.ai-conversations-filter__channel {
  width: 160px;
}

.ai-conversations-usage {
  margin: 12px 0 0;
  font-size: 12px;
  color: var(--td-text-color-secondary, #4e5969);
}

.ai-conversations-table-card {
  margin-top: 12px;
}

.ai-conversations-pagination {
  display: flex;
  justify-content: flex-end;
  margin-top: 16px;
}

.ai-conversations-detail__meta {
  display: flex;
  flex-wrap: wrap;
  gap: 8px 24px;
  padding: 0 0 12px;
  border-bottom: 1px solid var(--td-component-stroke, #e7e7e7);
  font-size: 12px;
  color: var(--td-text-color-primary, #1f2937);

  em {
    margin-right: 4px;
    font-style: normal;
    color: var(--td-text-color-placeholder, #a9aeb8);
  }
}

.ai-conversations-detail__pending {
  margin: 12px 0;
  padding: 10px 12px;
  border-radius: 6px;
  background: var(--td-warning-color-1, #fff1e9);

  h3 {
    margin: 0 0 6px;
    font-size: 12px;
    font-weight: 600;
  }
}

.ai-conversations-pending {
  display: flex;
  flex-direction: column;
  gap: 2px;
  padding: 4px 0;
  font-size: 12px;
  color: var(--td-text-color-secondary, #4e5969);
}

.ai-conversations-detail__empty {
  margin: 24px 0;
  text-align: center;
  font-size: 12px;
  color: var(--td-text-color-placeholder, #a9aeb8);
}

.ai-conversations-message {
  padding: 12px 0;
  border-bottom: 1px dashed var(--td-component-stroke, #e7e7e7);
}

.ai-conversations-message__head {
  display: flex;
  align-items: center;
  gap: 8px;

  time {
    font-size: 12px;
    color: var(--td-text-color-placeholder, #a9aeb8);
  }
}

.ai-conversations-message__content {
  margin: 8px 0 0;
  font-size: 13px;
  line-height: 1.7;
  color: var(--td-text-color-primary, #1f2937);
  white-space: pre-wrap;
  word-break: break-word;
}

.ai-conversations-tools {
  margin-top: 10px;
  padding: 8px 10px;
  border-radius: 6px;
  background: var(--td-bg-color-container-hover, #f3f3f3);
}

.ai-conversations-tools__label {
  font-size: 12px;
  font-weight: 600;
  color: var(--td-text-color-secondary, #4e5969);
}

.ai-conversations-tool {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  gap: 6px;
  margin-top: 6px;
  font-size: 12px;
}

.ai-conversations-tool__name {
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  color: var(--td-brand-color);
}

.ai-conversations-tool__status {
  padding: 0 6px;
  border-radius: 8px;
  background: var(--td-bg-color-container, #fff);
  color: var(--td-text-color-secondary, #4e5969);
}

.ai-conversations-tool__summary {
  color: var(--td-text-color-primary, #1f2937);
}

.ai-conversations-tool__args {
  flex: 1 0 100%;
  margin: 4px 0 0;
  padding: 6px 8px;
  border-radius: 4px;
  background: var(--td-bg-color-container, #fff);
  font-size: 12px;
  line-height: 1.5;
  white-space: pre-wrap;
  word-break: break-all;
}

@media (max-width: 768px) {
  .ai-conversations-filter__user,
  .ai-conversations-filter__channel {
    width: 100%;
  }
}
</style>
