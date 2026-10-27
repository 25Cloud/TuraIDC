<template>
  <div class="ai-conversations-page">
    <header class="ai-page-head">
      <ai-robot-avatar :size="22" class="ai-page-head__logo" />
      <h1 class="ai-page-head__title">{{ $t('ai.conversations.title') }}</h1>
    </header>

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
      <!-- 空数据时不渲染表格：否则 t-table 自带的「暂无数据」会和下面的空态叠两层 -->
      <t-table
        v-if="list.length"
        row-key="id"
        :data="list"
        :columns="columns"
        :loading="loading"
        hover
        table-layout="fixed"
        @row-click="handleRowClick"
      >
        <template #id="{ row }">
          <strong>{{ `#${row.id}` }}</strong>
        </template>
        <template #user="{ row }">{{ userLabel(row) }}</template>
        <template #channel="{ row }">
          <t-tag size="small" variant="light" :theme="channelTheme(row.channel)">
            {{ channelLabel(row.channel) }}
          </t-tag>
        </template>
        <template #title="{ row }">{{ row.title || '-' }}</template>
        <template #message_count="{ row }">
          <span class="ai-conversations-num">{{ row.message_count ?? 0 }}</span>
        </template>
        <template #permission_mode="{ row }">{{ modeLabel(row.permission_mode) }}</template>
        <template #last_message_at="{ row }">
          <span class="ai-conversations-time">
            {{ row.last_message_at ? formatDateTime(row.last_message_at) : '-' }}
          </span>
        </template>
        <template #op="{ row }">
          <t-link theme="primary" hover="color" @click.stop="openDetail(row.id)">
            {{ $t('ai.conversations.view') }}
          </t-link>
        </template>
      </t-table>

      <!-- 空态与浮窗同一套：机器人头 56px + 16px 标题 + 13px 灰色副标题 -->
      <div v-if="!list.length && !loading" class="ai-empty">
        <ai-robot-avatar :size="56" class="ai-empty__icon" />
        <p class="ai-empty__title">{{ $t('ai.empty.title') }}</p>
        <p class="ai-empty__desc">{{ $t('ai.conversations.empty') }}</p>
      </div>

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
      :footer="false"
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
              <span class="ai-conversations-num">{{ detail.messages.length }}</span>
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

          <!-- 按角色分列：用户靠右、助手带头像靠左，思考过程折叠在正文上方 -->
          <section class="ai-conversations-transcript">
            <article
              v-for="message in detail.messages"
              :key="message.id"
              class="ai-conversations-message"
              :class="`ai-conversations-message--${message.role}`"
            >
              <t-avatar v-if="message.role === 'assistant'" class="ai-conversations-message__avatar" size="32px">
                <template #icon><ai-robot-avatar :size="16" /></template>
              </t-avatar>

              <div class="ai-conversations-message__body">
                <header class="ai-conversations-message__head">
                  <span class="ai-conversations-message__role">{{ messageRoleLabel(message.role) }}</span>
                  <time v-if="message.created_at">{{ formatDateTime(message.created_at) }}</time>
                  <span v-if="toolCallRows(message).length" class="ai-conversations-message__count">
                    {{ $t('ai.conversations.toolCallCount', { count: toolCallRows(message).length }) }}
                  </span>
                </header>

                <div class="ai-conversations-message__bubble">
                  <t-collapse v-if="message.reasoning" class="ai-conversations-reasoning" borderless>
                    <t-collapse-panel value="reasoning" :header="$t('ai.conversations.reasoning')">
                      <p class="ai-conversations-reasoning__text">{{ message.reasoning }}</p>
                    </t-collapse-panel>
                  </t-collapse>

                  <p v-if="message.content" class="ai-conversations-message__text">{{ message.content }}</p>

                  <div v-if="toolCallRows(message).length" class="ai-conversations-tools">
                    <t-table
                      row-key="key"
                      size="small"
                      hover
                      table-layout="fixed"
                      :data="toolCallRows(message)"
                      :columns="toolColumns"
                    >
                      <template #name="{ row }">
                        <span class="ai-conversations-mono">{{ row.name }}</span>
                      </template>
                      <template #risk="{ row }">
                        <t-tag v-if="row.risk" size="small" variant="light" :theme="riskTheme(row.risk)">
                          {{ riskLabel(row.risk) }}
                        </t-tag>
                        <span v-else>-</span>
                      </template>
                      <template #status="{ row }">{{ row.status || '-' }}</template>
                      <template #summary="{ row }">{{ row.summary || '-' }}</template>
                      <template #arguments="{ row }">
                        <t-tooltip
                          v-if="row.arguments"
                          :content="row.arguments"
                          placement="top-left"
                          :overlay-inner-style="{ maxWidth: '560px', wordBreak: 'break-all' }"
                        >
                          <span class="ai-conversations-mono ai-conversations-arg">{{ row.arguments }}</span>
                        </t-tooltip>
                        <span v-else>-</span>
                      </template>
                    </t-table>
                  </div>
                </div>
              </div>
            </article>
          </section>
        </div>
      </t-loading>
    </t-drawer>
  </div>
</template>
<script setup lang="ts">
import type { AiConversationDetail, AiMessageRow } from '@turaidc/shared/runtime';
import type { PrimaryTableCol, TableRowData } from 'tdesign-vue-next';
import { MessagePlugin } from 'tdesign-vue-next';
import { computed, onMounted, ref } from 'vue';

import type { AiAdminConversation, AiUsageResponse } from '@/api/ai';
import { aiAdminApi } from '@/api/ai';
import AiRobotAvatar from '@/components/ai/AiRobotAvatar.vue';
import { useListPage } from '@/hooks/useListPage';
import { useMediaQuery } from '@/hooks/useMediaQuery';
import { t } from '@/locales';
import { formatDateTime } from '@/utils/format';
import { errorMessage } from '@/utils/userMessage';

defineOptions({ name: 'AdminAiConversations' });

const isMobile = useMediaQuery('(max-width: 768px)');
const drawerSize = computed(() => (isMobile.value ? '100%' : '760px'));

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

const RISK_KEYS: Record<string, string> = {
  read: 'ai.risk.read',
  write: 'ai.risk.write',
  dangerous: 'ai.risk.dangerous',
};

const channelOptions = computed(() => Object.entries(CHANNEL_KEYS).map(([value, key]) => ({ value, label: t(key) })));

/** 列宽全部按 colKey 固定：table-layout=fixed 下不允许列宽随内容跳动 */
const columns = computed<PrimaryTableCol<TableRowData>[]>(() => [
  { colKey: 'id', title: t('ai.conversations.colId'), width: 100 },
  { colKey: 'user', title: t('ai.conversations.colUser'), width: 180, ellipsis: true },
  { colKey: 'channel', title: t('ai.conversations.colChannel'), width: 130 },
  { colKey: 'title', title: t('ai.conversations.colTitle'), minWidth: 220, ellipsis: true },
  { colKey: 'message_count', title: t('ai.conversations.colMessages'), width: 110, align: 'right' },
  { colKey: 'permission_mode', title: t('ai.conversations.colMode'), width: 140 },
  { colKey: 'last_message_at', title: t('ai.conversations.colLastMessageAt'), width: 180, align: 'right' },
  { colKey: 'op', title: t('ai.conversations.colOp'), width: 90, fixed: 'right', align: 'right' },
]);

/** 抽屉里的工具调用表：参数单独一列，超长用 tooltip，不再整段 JSON 直出 */
const toolColumns = computed<PrimaryTableCol<TableRowData>[]>(() => [
  { colKey: 'name', title: t('ai.conversations.toolCalls'), width: 160, ellipsis: true },
  { colKey: 'risk', title: t('ai.conversations.toolRisk'), width: 84 },
  { colKey: 'status', title: t('ai.conversations.toolStatus'), width: 84, ellipsis: true },
  { colKey: 'summary', title: t('ai.conversations.toolSummary'), minWidth: 160, ellipsis: true },
  // 参数不截断成半段 JSON：允许折行两行，完整内容走 tooltip
  { colKey: 'arguments', title: t('ai.conversations.toolArguments'), minWidth: 180 },
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

function riskLabel(risk: string) {
  const key = RISK_KEYS[String(risk || '')];
  return key ? t(key) : String(risk || '-');
}

function riskTheme(risk: string): 'default' | 'warning' | 'danger' {
  if (risk === 'dangerous') return 'danger';
  if (risk === 'write') return 'warning';
  return 'default';
}

/** 工具调用行：补一个稳定的 key，后端不一定给 id */
function toolCallRows(message: AiMessageRow) {
  return (message.tool_calls || []).map((call, index) => ({
    ...call,
    key: `${message.id}-${call.id || index}`,
  }));
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
.ai-conversations-page {
  display: flex;
  flex-direction: column;
  gap: var(--td-comp-margin-xl);
  width: 100%;
  /* 与「AI 设置」同一版心：内容区最大 920px 居中 */
  max-width: 920px;
  margin: 0 auto;
  padding: var(--td-comp-paddingTB-l) var(--td-comp-paddingLR-l);
}

/* 页面头：机器人头 + 标题，不写说明文案（管理端不加头部说明卡片） */
.ai-page-head {
  display: flex;
  gap: var(--td-comp-margin-s);
  align-items: center;
}

.ai-page-head__logo {
  color: var(--td-brand-color);
}

.ai-page-head__title {
  margin: 0;
  color: var(--td-text-color-primary);
  font: var(--td-font-title-medium);
}

/* 空态：机器人头 56px + 16px 标题 + 13px 灰色副标题，垂直居中 */
.ai-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 8px;
  min-height: 320px;
  padding: 32px 0;
  text-align: center;
}

.ai-empty__icon {
  color: var(--td-text-color-placeholder);
}

.ai-empty__title {
  margin: 8px 0 0;
  color: var(--td-text-color-primary);
  font-size: 16px;
  font-weight: 500;
  line-height: 1.5;
}

.ai-empty__desc {
  max-width: 20rem;
  margin: 0;
  color: var(--td-text-color-secondary);
  font-size: 13px;
  line-height: 1.7;
}

.ai-conversations-filter {
  display: flex;
  align-items: center;
  gap: var(--td-comp-margin-s);
  flex-wrap: wrap;
}

.ai-conversations-filter__user {
  width: 220px;
}

.ai-conversations-filter__channel {
  width: 160px;
}

.ai-conversations-usage {
  margin: var(--td-comp-margin-s) 0 0;
  font-size: var(--td-font-size-body-small);
  color: var(--td-text-color-secondary);
}

/* 数字列固定等宽，避免逐行跳动 */
.ai-conversations-num {
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-variant-numeric: tabular-nums;
}

.ai-conversations-time {
  font-variant-numeric: tabular-nums;
}

.ai-conversations-mono {
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: var(--td-font-size-body-small);
}

/* 工具参数最多两行，完整内容靠 tooltip，不留半段 JSON 悬在单元格里 */
.ai-conversations-arg {
  display: -webkit-box;
  overflow: hidden;
  word-break: break-all;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
}

.ai-conversations-pagination {
  display: flex;
  justify-content: flex-end;
  margin-top: var(--td-comp-margin-l);
}

.ai-conversations-detail__meta {
  display: flex;
  flex-wrap: wrap;
  gap: var(--td-comp-margin-s) var(--td-comp-margin-xl);
  padding: 0 0 var(--td-comp-margin-m);
  border-bottom: 1px solid var(--td-component-stroke);
  font-size: var(--td-font-size-body-small);
  color: var(--td-text-color-primary);

  em {
    margin-right: var(--td-comp-margin-xs);
    font-style: normal;
    color: var(--td-text-color-placeholder);
  }
}

.ai-conversations-detail__pending {
  margin: var(--td-comp-margin-m) 0;
  padding: var(--td-comp-paddingTB-s) var(--td-comp-paddingLR-s);
  border-radius: var(--td-radius-medium);
  background: var(--td-warning-color-light);

  h3 {
    margin: 0 0 var(--td-comp-margin-xs);
    font-size: var(--td-font-size-body-small);
    font-weight: 600;
  }
}

.ai-conversations-pending {
  display: flex;
  flex-direction: column;
  gap: var(--td-comp-margin-xxs);
  padding: var(--td-comp-paddingTB-xxs) 0;
  font-size: var(--td-font-size-body-small);
  color: var(--td-text-color-secondary);
}

.ai-conversations-detail__empty {
  margin: var(--td-comp-margin-xl) 0;
  text-align: center;
  font-size: var(--td-font-size-body-small);
  color: var(--td-text-color-placeholder);
}

.ai-conversations-transcript {
  display: flex;
  flex-direction: column;
  gap: var(--td-comp-margin-l);
  padding-top: var(--td-comp-margin-l);
}

.ai-conversations-message {
  display: flex;
  gap: var(--td-comp-margin-s);
  align-items: flex-start;

  &--user {
    flex-direction: row-reverse;
  }
}

.ai-conversations-message__avatar {
  flex: none;
  background: var(--td-brand-color-light);
  color: var(--td-brand-color);
}

.ai-conversations-message__body {
  display: flex;
  flex-direction: column;
  gap: var(--td-comp-margin-xs);
  min-width: 0;
  max-width: 100%;
}

.ai-conversations-message--user .ai-conversations-message__body {
  align-items: flex-end;
}

.ai-conversations-message--system .ai-conversations-message__body {
  align-items: center;
  max-width: 100%;
  margin: 0 auto;
}

.ai-conversations-message__head {
  display: flex;
  align-items: center;
  gap: var(--td-comp-margin-s);
  font-size: var(--td-font-size-body-small);
  color: var(--td-text-color-placeholder);

  time,
  .ai-conversations-message__count {
    font-variant-numeric: tabular-nums;
  }

  .ai-conversations-message__count {
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  }
}

.ai-conversations-message--user .ai-conversations-message__head {
  flex-direction: row-reverse;
}

.ai-conversations-message__bubble {
  /* 与浮窗同一条可读性规则：一行不超过 72ch，也不超过容器的 88% */
  max-width: min(72ch, 88%);
  padding: var(--td-comp-paddingTB-s) var(--td-comp-paddingLR-s);
  border-radius: var(--td-radius-medium);
  background: var(--td-bg-color-container-hover);
  font-size: var(--td-font-size-body-medium);
  line-height: var(--td-line-height-body-medium);
  color: var(--td-text-color-primary);
  word-break: break-word;
}

.ai-conversations-message--user .ai-conversations-message__bubble {
  background: var(--td-brand-color-light);
}

.ai-conversations-message--system .ai-conversations-message__bubble {
  background: transparent;
  font-size: var(--td-font-size-body-small);
  color: var(--td-text-color-secondary);
}

.ai-conversations-message__text {
  margin: 0;
  white-space: pre-wrap;
  word-break: break-word;
}

/* 思考过程折叠在正文上方：默认收起，想看再点开 */
.ai-conversations-reasoning {
  margin-bottom: var(--td-comp-margin-s);

  :deep(.t-collapse-panel__header) {
    padding: var(--td-comp-paddingTB-xs) 0;
    font: var(--td-font-body-small);
    color: var(--td-text-color-secondary);
  }

  :deep(.t-collapse-panel__body) {
    background: transparent;
  }

  :deep(.t-collapse-panel__content) {
    padding: var(--td-comp-margin-xs) 0 0;
  }
}

.ai-conversations-reasoning__text {
  margin: 0;
  font-size: var(--td-font-size-body-small);
  line-height: var(--td-line-height-body-small);
  color: var(--td-text-color-secondary);
  white-space: pre-wrap;
  word-break: break-word;
}

.ai-conversations-tools {
  margin-top: var(--td-comp-margin-s);
  border-radius: var(--td-radius-medium);
  overflow-x: auto;
}

@media (width <= 768px) {
  .ai-conversations-page {
    padding: 12px;
  }

  .ai-conversations-filter__user,
  .ai-conversations-filter__channel {
    width: 100%;
  }

  .ai-conversations-message__body {
    max-width: 100%;
  }
}
</style>
