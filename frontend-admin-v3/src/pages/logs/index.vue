<template>
  <div class="logs-page">
    <template v-if="isLogTab(activeTab)">
      <t-card :bordered="false">
        <div class="log-filter-grid" :class="`log-filter-grid--${activeTab}`">
          <t-select
            v-if="showFilter('level')"
            v-model="filters.level"
            clearable
            placeholder="日志级别"
            @change="handleLogSearch"
          >
            <t-option v-for="item in logLevelOptions" :key="item" :label="item" :value="item" />
          </t-select>
          <t-select
            v-if="showFilter('task_key')"
            v-model="filters.task_key"
            clearable
            placeholder="任务名称"
            @change="handleLogSearch"
          >
            <t-option v-for="item in taskLogOptions" :key="item.value" :label="item.label" :value="item.value" />
          </t-select>
          <t-select
            v-if="showFilter('method')"
            v-model="filters.method"
            clearable
            placeholder="请求方法"
            @change="handleLogSearch"
          >
            <t-option v-for="item in methodOptions" :key="item" :label="item" :value="item" />
          </t-select>
          <t-input
            v-if="showFilter('actor_keyword')"
            v-model="filters.actor_keyword"
            clearable
            placeholder="操作人"
            @enter="handleLogSearch"
            @clear="handleLogSearch"
          />
          <t-input
            v-if="showFilter('description_keyword')"
            v-model="filters.description_keyword"
            clearable
            placeholder="描述关键词"
            @enter="handleLogSearch"
            @clear="handleLogSearch"
          />
          <t-input
            v-if="showFilter('ip_address')"
            v-model="filters.ip_address"
            clearable
            placeholder="IP 地址"
            @enter="handleLogSearch"
            @clear="handleLogSearch"
          />
          <t-input
            v-if="showFilter('module')"
            v-model="filters.module"
            clearable
            placeholder="模块，例如 auth / order"
            @enter="handleLogSearch"
            @clear="handleLogSearch"
          />
          <t-select
            v-if="showFilter('user_type')"
            v-model="filters.user_type"
            clearable
            placeholder="调用端"
            @change="handleLogSearch"
          >
            <t-option label="管理员" value="admin" />
            <t-option label="客户" value="client" />
            <t-option label="访客" value="guest" />
          </t-select>
          <t-select
            v-if="showFilter('status')"
            v-model="filters.status"
            clearable
            :placeholder="statusPlaceholder"
            @change="handleLogSearch"
          >
            <t-option v-for="item in statusOptions" :key="String(item.value)" :label="item.label" :value="item.value" />
          </t-select>
          <t-input
            v-if="showFilter('phone')"
            v-model="filters.phone"
            clearable
            placeholder="输入接收手机号"
            @enter="handleLogSearch"
            @clear="handleLogSearch"
          />
          <t-input
            v-if="showFilter('email')"
            v-model="filters.email"
            clearable
            placeholder="输入收件邮箱"
            @enter="handleLogSearch"
            @clear="handleLogSearch"
          />
          <t-select
            v-if="showFilter('gateway')"
            v-model="filters.gateway"
            clearable
            placeholder="支付网关"
            @change="handleLogSearch"
          >
            <t-option v-for="item in gatewayOptions" :key="item.value" :label="item.label" :value="item.value" />
          </t-select>
          <t-input
            v-if="showFilter('plugin_id')"
            v-model="filters.plugin_id"
            clearable
            placeholder="插件 ID"
            @enter="handleLogSearch"
            @clear="handleLogSearch"
          />
          <t-input
            v-if="showFilter('gateway_key')"
            v-model="filters.gateway_key"
            clearable
            placeholder="业务网关 key"
            @enter="handleLogSearch"
            @clear="handleLogSearch"
          />
          <t-input
            v-if="showFilter('driver_key')"
            v-model="filters.driver_key"
            clearable
            placeholder="驱动 key"
            @enter="handleLogSearch"
            @clear="handleLogSearch"
          />
          <t-input
            v-if="showFilter('trace_id')"
            v-model="filters.trace_id"
            clearable
            placeholder="Trace ID"
            @enter="handleLogSearch"
            @clear="handleLogSearch"
          />
          <t-select
            v-if="showFilter('action')"
            v-model="filters.action"
            clearable
            placeholder="网关操作"
            @change="handleLogSearch"
          >
            <t-option v-for="item in gatewayActionOptions" :key="item.value" :label="item.label" :value="item.value" />
          </t-select>
          <t-select
            v-if="showFilter('result_status')"
            v-model="filters.result_status"
            clearable
            placeholder="结果状态"
            @change="handleLogSearch"
          >
            <t-option
              v-for="item in gatewayResultOptions"
              :key="String(item.value)"
              :label="item.label"
              :value="item.value"
            />
          </t-select>
          <t-select
            v-if="showFilter('actor_type')"
            v-model="filters.actor_type"
            clearable
            placeholder="操作人类型"
            @change="handleLogSearch"
          >
            <t-option label="管理员" value="admin" />
            <t-option label="客户" value="client" />
            <t-option label="系统" value="system" />
          </t-select>
          <t-select
            v-if="showFilter('subject_type')"
            v-model="filters.subject_type"
            clearable
            placeholder="关联类型"
            @change="handleLogSearch"
          >
            <t-option
              v-for="item in activitySubjectOptions"
              :key="item.value"
              :label="item.label"
              :value="item.value"
            />
          </t-select>
          <t-input
            v-if="showFilter('keyword')"
            v-model="filters.keyword"
            clearable
            :placeholder="keywordPlaceholder"
            @enter="handleLogSearch"
            @clear="handleLogSearch"
          >
            <template #suffix-icon><search-icon /></template>
          </t-input>
          <t-date-picker
            v-if="showFilter('date')"
            v-model="filters.start_date"
            clearable
            mode="date"
            format="YYYY-MM-DD"
            value-type="YYYY-MM-DD"
            placeholder="开始日期"
            @change="handleLogSearch"
          />
          <t-date-picker
            v-if="showFilter('date')"
            v-model="filters.end_date"
            clearable
            mode="date"
            format="YYYY-MM-DD"
            value-type="YYYY-MM-DD"
            placeholder="结束日期"
            @change="handleLogSearch"
          />
        </div>

        <div v-if="!isMobile" class="table-scroll">
          <t-table
            row-key="id"
            :data="logRows"
            :columns="logTableColumns"
            :loading="logLoading"
            hover
            table-layout="fixed"
          >
            <template #time="{ row }">{{ formatDate(row.time || row.created_at || row.sent_at) }}</template>
            <template #primary="{ row }">
              <div class="stack-cell">
                <strong>{{ primaryTitle(row) }}</strong>
                <span>{{ primarySubText(row) }}</span>
              </div>
            </template>
            <template #request="{ row }">
              <div class="request-cell">
                <div>
                  <t-tag variant="light">{{ fieldValue(row.method) }}</t-tag>
                  <strong>{{ fieldValue(row.path) }}</strong>
                </div>
                <span>{{ fieldValue(row.request_id || '无请求号') }}</span>
              </div>
            </template>
            <template #level="{ row }">
              <t-tag :theme="levelTheme(row.level)" variant="light">{{ fieldValue(row.level) }}</t-tag>
            </template>
            <template #status="{ row }">
              <t-tag :theme="statusTheme(statusValue(row))" variant="light">{{ statusLabel(statusValue(row)) }}</t-tag>
            </template>
            <template #httpStatus="{ row }">
              <t-tag :theme="httpStatusTheme(row.status)" variant="light">{{ fieldValue(row.status) }}</t-tag>
            </template>
            <template #subject_type="{ row }">
              {{ subjectText(row) }}
            </template>
            <template #message="{ row }">
              <div v-if="isTextLog" class="log-message">
                <t-tag v-if="messageTag(row)" size="small" variant="light" class="log-message__tag">{{
                  messageTag(row)
                }}</t-tag>
                <span class="log-message__body">
                  <template v-for="(seg, si) in parseMessageSegments(row)" :key="si">
                    <span v-if="seg.type === 'text'">{{ seg.text }}</span>
                    <t-tag
                      v-else
                      size="small"
                      variant="outline"
                      class="log-message__id-tag"
                      :title="idSegmentTitle(seg)"
                      @click="copyId(seg.label, seg.id)"
                      >{{ seg.label }}:{{ seg.id }}</t-tag
                    >
                  </template>
                </span>
              </div>
              <span v-else class="line-clamp">{{ messageText(row) }}</span>
            </template>
            <template #error="{ row }">
              <span :class="{ 'danger-text': row.error_msg }">{{ fieldValue(row.error_msg) }}</span>
            </template>
            <template #gateway="{ row }">
              {{ gatewayLabel(row.gateway) }}
            </template>
            <template #result_status="{ row }">
              <t-tag :theme="gatewayResultStatusTheme(row.result_status)" variant="light">{{
                gatewayResultStatusLabel(row.result_status)
              }}</t-tag>
            </template>
            <template #actions="{ row }">
              <t-button theme="primary" variant="text" @click="openDetail(row)">详情</t-button>
            </template>
          </t-table>
        </div>

        <div v-else class="table-scroll">
          <t-loading :loading="logLoading" size="small">
            <div v-if="logRows.length" class="log-mobile-stack">
              <article
                v-for="row in logRows"
                :key="String(row.id || row.order_no || row.sent_no)"
                class="log-mobile-card"
              >
                <div class="log-mobile-card__head">
                  <span class="log-mobile-card__time">{{ formatDate(row.time || row.created_at || row.sent_at) }}</span>
                  <t-tag v-if="row.level" :theme="levelTheme(row.level)" variant="light" size="small">{{
                    fieldValue(row.level)
                  }}</t-tag>
                  <t-tag v-if="isTextLog && messageTag(row)" size="small" variant="light">{{ messageTag(row) }}</t-tag>
                  <t-tag
                    v-if="logStatusValue(row)"
                    :theme="statusTheme(logStatusValue(row))"
                    variant="light"
                    size="small"
                    >{{ statusLabel(logStatusValue(row)) }}</t-tag
                  >
                  <t-tag
                    v-if="row.result_status"
                    :theme="gatewayResultStatusTheme(row.result_status)"
                    variant="light"
                    size="small"
                    >{{ gatewayResultStatusLabel(row.result_status) }}</t-tag
                  >
                </div>
                <div class="log-mobile-card__body">{{ logCardMessage(row) }}</div>
                <div class="log-mobile-card__meta">
                  <t-tag v-if="row.method" variant="outline" size="small">{{ fieldValue(row.method) }}</t-tag>
                  <span v-if="row.path" class="muted-text">{{ fieldValue(row.path) }}</span>
                  <span v-if="row.gateway" class="muted-text">网关: {{ gatewayLabel(row.gateway) }}</span>
                  <span v-if="row.gateway_key" class="muted-text">业务 key: {{ fieldValue(row.gateway_key) }}</span>
                  <span v-if="row.driver_key" class="muted-text">驱动 key: {{ fieldValue(row.driver_key) }}</span>
                  <span v-if="row.plugin_key" class="muted-text">插件: {{ fieldValue(row.plugin_key) }}</span>
                  <span v-if="row.trace_id" class="muted-text">Trace: {{ fieldValue(row.trace_id) }}</span>
                  <span v-if="row.status" class="muted-text">HTTP {{ fieldValue(row.status) }}</span>
                </div>
                <div class="log-mobile-card__actions">
                  <t-button theme="primary" variant="text" size="small" @click="openDetail(row)">详情</t-button>
                </div>
              </article>
            </div>
            <t-empty v-else description="暂无日志" />
          </t-loading>
        </div>

        <div v-if="logPagination.total > 0" class="pagination-row">
          <t-pagination
            :current="logPagination.page"
            :page-size="logPagination.page_size"
            :total="logPagination.total"
            :page-size-options="[10, 15, 20, 50, 100]"
            show-jumper
            @change="handleLogPageChange"
          />
        </div>
      </t-card>
    </template>

    <template v-else-if="activeTab === 'schedules'">
      <t-card v-if="!canViewSchedules" :bordered="false">
        <t-alert theme="warning" message="当前账号缺少 schedule.view 权限，无法查看定时任务。" />
      </t-card>
      <template v-else>
        <t-card v-if="scheduleEnvAlerts.length > 0" :bordered="false" class="schedule-env-card">
          <div class="schedule-env-grid">
            <article
              v-for="alert in scheduleEnvAlerts"
              :key="alert.key"
              class="schedule-env-item"
              :class="`schedule-env-item--${alert.theme}`"
            >
              <div class="schedule-env-item__head">
                <t-tag :theme="alert.theme" variant="light">{{ alert.label }}</t-tag>
                <strong>{{ alert.value }}</strong>
              </div>
              <span v-if="alert.detail" class="schedule-env-item__detail">{{ alert.detail }}</span>
            </article>
          </div>
        </t-card>

        <t-card :bordered="false" :loading="scheduleLoading">
          <template #title>已注册任务</template>
          <template #subtitle>共 {{ scheduleTasks.length }} 个任务</template>
          <div class="schedule-task-toolbar">
            <div class="schedule-task-stats" aria-label="任务来源统计">
              <div class="schedule-task-stat">
                <span>系统任务</span>
                <strong>{{ scheduleSystemTasks.length }}</strong>
              </div>
              <div class="schedule-task-stat schedule-task-stat--third-party">
                <span>第三方任务</span>
                <strong>{{ scheduleThirdPartyTasks.length }}</strong>
              </div>
            </div>
            <t-radio-group v-model="scheduleSourceFilter" variant="default-filled" class="schedule-task-filter">
              <t-radio-button v-for="item in scheduleSourceOptions" :key="item.value" :value="item.value">
                {{ item.label }}
              </t-radio-button>
            </t-radio-group>
          </div>
          <div class="schedule-task-groups">
            <section v-for="group in visibleScheduleTaskGroups" :key="group.key" class="schedule-task-group">
              <div class="schedule-task-group__head">
                <div>
                  <h3>{{ group.title }}</h3>
                  <p>{{ group.description }}</p>
                </div>
                <t-tag :theme="group.theme" variant="light">{{ group.tasks.length }} 个任务</t-tag>
              </div>
              <div v-if="group.tasks.length" class="table-scroll">
                <t-table row-key="key" :data="group.tasks" :columns="scheduleColumns" hover table-layout="fixed">
                  <template #task="{ row }">
                    <div class="stack-cell schedule-task-cell">
                      <div class="schedule-task-cell__name">
                        <strong>{{ fieldValue(row.title || row.key) }}</strong>
                        <t-tag size="small" :theme="scheduleTaskSourceTheme(row)" variant="light">
                          {{ fieldValue(row.source_label || group.title) }}
                        </t-tag>
                      </div>
                      <span>{{ fieldValue(row.category) }}</span>
                    </div>
                  </template>
                  <template #cycle="{ row }">
                    <div class="stack-cell">
                      <strong>{{ formatScheduleCycle(row) }}</strong>
                      <span>{{ fieldValue(row.expression) }}</span>
                    </div>
                  </template>
                  <template #last="{ row }">{{ fieldValue(row.last_run_at || toRecord(row.last_log).time) }}</template>
                  <template #level="{ row }">
                    <t-tag :theme="levelTheme(toRecord(row.last_log).level)" variant="light">
                      {{ fieldValue(toRecord(row.last_log).level || '待执行') }}
                    </t-tag>
                  </template>
                  <template #next="{ row }">{{ fieldValue(row.next_run_at) }}</template>
                  <template #actions="{ row }">
                    <t-button
                      v-if="row.manual_triggerable"
                      theme="primary"
                      variant="outline"
                      size="small"
                      :loading="triggeringKey === row.key"
                      :disabled="!canTriggerSchedules"
                      @click="triggerTask(row)"
                    >
                      立即执行
                    </t-button>
                    <span v-else class="muted-text">自动</span>
                  </template>
                </t-table>
              </div>
              <t-empty v-else :description="`暂无${group.title}`" />
            </section>
          </div>
        </t-card>
      </template>
    </template>

    <template v-else>
      <t-card :bordered="false" :loading="cleanupLoading">
        <t-alert theme="warning" message="日志清理为不可逆操作，请确认保留天数、清理类型和确认文本。" />
        <t-alert
          v-if="!canManageLogCleanup"
          theme="warning"
          message="当前账号缺少 log.manage 权限，只能查看清理概览，不能执行清理。"
        />

        <section class="cleanup-section">
          <div class="section-title">
            <h2>数据库日志概览</h2>
            <p>当前可清理的数据库日志记录总览。</p>
          </div>
          <div class="cleanup-card-grid">
            <article v-for="item in databaseCards" :key="item.key">
              <span>{{ item.label }}</span>
              <strong>{{ item.value }}</strong>
            </article>
          </div>
        </section>

        <section class="cleanup-section">
          <div class="section-title">
            <h2>文件日志概览</h2>
            <p>当前 laravel.log 的基础信息与日志条目数量。</p>
          </div>
          <div class="cleanup-card-grid">
            <article v-for="item in fileCards" :key="item.key">
              <span>{{ item.label }}</span>
              <strong>{{ item.value }}</strong>
            </article>
          </div>
          <div class="file-meta-grid">
            <article>
              <span>文件路径</span>
              <strong>{{ fieldValue(toRecord(cleanupOverview.file).path) }}</strong>
            </article>
            <article>
              <span>最后更新时间</span>
              <strong>{{ formatDate(toRecord(cleanupOverview.file).updated_at) }}</strong>
            </article>
          </div>
        </section>

        <section class="cleanup-section">
          <div class="section-title">
            <h2>执行清理</h2>
            <p>请输入确认文本后执行清理。</p>
          </div>
          <t-form :data="cleanupForm" label-align="top" class="cleanup-form">
            <t-form-item label="清理类型" name="type">
              <t-select v-model="cleanupForm.type" placeholder="请选择清理类型">
                <t-option
                  v-for="item in cleanupTypes"
                  :key="String(item.value)"
                  :label="String(item.label || item.value)"
                  :value="String(item.value)"
                />
              </t-select>
            </t-form-item>
            <t-form-item label="保留天数" name="keep_days">
              <t-input-number v-model="cleanupForm.keep_days" :min="1" :max="3650" theme="normal" />
            </t-form-item>
            <t-form-item label="确认文本" name="confirm_text">
              <t-input v-model="cleanupForm.confirm_text" clearable placeholder="请输入 立即清理" />
            </t-form-item>
            <div class="cleanup-actions">
              <t-button
                theme="danger"
                :loading="cleanupSubmitting"
                :disabled="cleanupSubmitDisabled"
                @click="handleCleanup"
                >立即清理</t-button
              >
              <t-button variant="outline" @click="cleanupForm.confirm_text = ''">清空确认</t-button>
            </div>
          </t-form>
        </section>

        <section v-if="lastCleanupResult" class="cleanup-section">
          <div class="section-title">
            <h2>最近一次清理结果</h2>
            <p>显示本次清理范围、截止时间和影响条数。</p>
          </div>
          <div class="file-meta-grid">
            <article>
              <span>清理类型</span>
              <strong>{{ fieldValue(lastCleanupResult.type) }}</strong>
            </article>
            <article>
              <span>保留天数</span>
              <strong>{{ fieldValue(lastCleanupResult.keep_days) }} 天</strong>
            </article>
            <article>
              <span>截止时间</span>
              <strong>{{ formatDate(lastCleanupResult.cutoff_at) }}</strong>
            </article>
            <article>
              <span>影响条数</span>
              <strong>{{ affectedCountText }}</strong>
            </article>
          </div>
          <pre class="json-block">{{ formatJson(lastCleanupResult.affected) }}</pre>
        </section>
      </t-card>
    </template>

    <log-detail-drawer
      :visible="detailVisible"
      :current-log="currentLog"
      :active-tab="activeTab"
      :drawer-size="drawerSize"
      @close="closeDetailDrawer"
    />
  </div>
</template>
<script setup lang="ts">
import './index.less';

import { SearchIcon } from 'tdesign-icons-vue-next';
import type { PageInfo, PrimaryTableCol, TableRowData } from 'tdesign-vue-next';
import { MessagePlugin } from 'tdesign-vue-next';
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';

import type { LogListParams, PaginatedList } from '@/api/admin';
import { adminApi } from '@/api/admin';
import { AdminPermissions, hasPermissionInList } from '@/constants/permissions';
import { useMediaQuery } from '@/hooks/useMediaQuery';
import { useUserStore } from '@/store';
import { fieldValue, formatDateTime } from '@/utils/format';
import { errorMessage } from '@/utils/userMessage';

import LogDetailDrawer from './components/LogDetailDrawer.vue';

type LogTab = 'system' | 'runtime' | 'admin-logins' | 'api' | 'sms' | 'email' | 'tasks' | 'gateway';
type LogsTab = LogTab | 'schedules' | 'cleanup';
type RecordRow = Record<string, unknown>;

const route = useRoute();
const router = useRouter();
const userStore = useUserStore();
const isMobile = useMediaQuery('(max-width: 768px)');
const validTabs: LogsTab[] = [
  'system',
  'runtime',
  'admin-logins',
  'api',
  'sms',
  'email',
  'tasks',
  'gateway',
  'schedules',
  'cleanup',
];
const activeTab = ref<LogsTab>(resolveRouteTab());
const logLoading = ref(false);
const scheduleLoading = ref(false);
const scheduleSourceFilter = ref<'all' | 'system' | 'third_party'>('all');
const cleanupLoading = ref(false);
const cleanupSubmitting = ref(false);
const triggeringKey = ref('');
const detailVisible = ref(false);
const currentLog = ref<RecordRow | null>(null);
const logRows = ref<RecordRow[]>([]);
const scheduleOverview = ref<Record<string, unknown>>({ tasks: [], recent_logs: [] });
const cleanupOverview = ref<Record<string, unknown>>({ database: {}, file: {}, supported_cleanup_types: [] });
const lastCleanupResult = ref<Record<string, unknown> | null>(null);

const filters = reactive({
  keyword: '',
  actor_keyword: '',
  description_keyword: '',
  ip_address: '',
  level: '',
  task_key: '',
  method: '',
  module: '',
  user_type: '',
  status: '' as string | number,
  phone: '',
  email: '',
  start_date: '',
  end_date: '',
  gateway: '',
  gateway_key: '',
  driver_key: '',
  plugin_id: '',
  trace_id: '',
  action: '',
  result_status: '',
  actor_type: '',
  subject_type: '',
});
type LogFilterKey = keyof typeof filters;

const logPagination = reactive({
  page: 1,
  page_size: 15,
  total: 0,
});
const cleanupForm = reactive({
  type: '',
  keep_days: 30,
  confirm_text: '',
});

const logLevelOptions = ['DEBUG', 'INFO', 'NOTICE', 'WARNING', 'ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'];
const methodOptions = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'];
const httpStatusOptions = [200, 201, 204, 400, 401, 403, 404, 422, 500].map((value) => ({
  label: String(value),
  value,
}));
const notifyStatusOptions = [
  { label: '待发送', value: 'pending' },
  { label: '发送成功', value: 'success' },
  { label: '发送失败', value: 'failed' },
];
const gatewayOptions = [
  { label: '支付宝', value: 'alipay' },
  { label: '微信支付', value: 'wechat' },
  { label: 'Stripe', value: 'stripe' },
  { label: '支付宝当面付（历史）', value: 'alipay_f2f' },
];
const gatewayActionOptions = [
  { label: '预下单', value: 'precreate' },
  { label: '回调通知', value: 'notify' },
  { label: '主动查询', value: 'query' },
  { label: '退款', value: 'refund' },
];
const gatewayResultOptions = [
  { label: '成功', value: 'success' },
  { label: '失败', value: 'failed' },
  { label: '处理中', value: 'pending' },
  { label: '未知', value: 'unknown' },
];
const activitySubjectOptions = [
  { label: '账单', value: 'invoice' },
  { label: '订单', value: 'order' },
  { label: '支付', value: 'payment' },
  { label: '服务', value: 'service' },
  { label: '用户', value: 'user' },
  { label: '工单', value: 'ticket' },
  { label: '商品', value: 'product' },
  { label: '优惠券', value: 'coupon' },
  { label: '系统', value: 'system' },
];
const taskLogOptions = [
  { value: 'refresh-hosting-panel-auth', label: '接口认证刷新' },
  { value: 'service-auto-renew', label: '服务自动续费' },
  { value: 'referral-release-rewards', label: '推荐奖励释放' },
  { value: 'service-lifecycle-maintenance', label: '服务生命周期维护' },
  { value: 'service-status-sync', label: '用户产品状态同步' },
  { value: 'billing-maintenance', label: '账单自动化维护' },
  { value: 'product-upstream-config-sync', label: '上游产品配置同步' },
  { value: 'coupon-campaign-dispatch', label: '优惠券活动发放' },
  { value: 'ticket-auto-close', label: '工单自动关闭' },
  { value: 'order-cleanup', label: '账单与充值清理' },
];
const logMeta: Record<LogTab, { title: string; description: string; filters: string[]; keyword: string }> = {
  system: {
    title: '系统日志',
    description: '业务操作审计日志，记录谁在什么时间、什么 IP 做了什么操作。',
    filters: ['actor_keyword', 'description_keyword', 'ip_address', 'module', 'actor_type', 'subject_type', 'date'],
    keyword: '操作人、模块或描述',
  },
  runtime: {
    title: '运行日志',
    description: '查看应用运行日志、警告与错误信息。',
    filters: ['level', 'plugin_id', 'gateway_key', 'driver_key', 'trace_id', 'keyword', 'date'],
    keyword: '日志内容、插件 key 或 Trace ID',
  },
  'admin-logins': {
    title: '管理员登录日志',
    description: '记录管理员账号最近登录情况。',
    filters: ['keyword', 'date'],
    keyword: '账号、昵称、角色或 IP',
  },
  api: {
    title: 'API 日志',
    description: '审计后台与客户端接口访问情况。',
    filters: ['keyword', 'method', 'module', 'user_type', 'status', 'date'],
    keyword: '路径、模块、请求号或 IP',
  },
  sms: {
    title: '短信日志',
    description: '查看短信发送状态、请求号与失败原因。',
    filters: ['phone', 'plugin_id', 'driver_key', 'trace_id', 'keyword', 'status'],
    keyword: '搜索模板编号、请求号或内容',
  },
  email: {
    title: '邮件日志',
    description: '查看邮件发送状态、失败原因与内容摘要。',
    filters: ['email', 'plugin_id', 'driver_key', 'trace_id', 'keyword', 'status'],
    keyword: '搜索模板编号、主题或正文关键词',
  },
  tasks: {
    title: '自动任务日志',
    description: '聚合展示调度任务执行结果、错误级别和原始日志内容。',
    filters: ['task_key', 'status', 'level', 'keyword', 'date'],
    keyword: '任务名称或日志内容',
  },
  gateway: {
    title: '网关日志',
    description: '支付网关请求/响应日志，含交易号、状态与错误信息。',
    filters: ['keyword', 'gateway', 'gateway_key', 'plugin_id', 'trace_id', 'action', 'result_status', 'date'],
    keyword: '交易号、网关名、Trace ID 或错误信息',
  },
};

const baseLogColumns: Record<LogTab, PrimaryTableCol<RecordRow>[]> = {
  system: [
    { colKey: 'time', title: '时间', width: 170 },
    { colKey: 'primary', title: '操作人', minWidth: 180 },
    { colKey: 'actor_type', title: '操作人类型', width: 120 },
    { colKey: 'message', title: '描述', minWidth: 320 },
    { colKey: 'ip_address', title: 'IP 地址', width: 150 },
    { colKey: 'subject_type', title: '关联对象', width: 140 },
    { colKey: 'actions', title: '操作', fixed: 'right', width: 90 },
  ],
  runtime: [
    { colKey: 'time', title: '记录时间', width: 170 },
    { colKey: 'level', title: '级别', width: 100 },
    { colKey: 'plugin_key', title: '插件 key', minWidth: 120, ellipsis: true },
    { colKey: 'trace_id', title: 'Trace ID', minWidth: 180, ellipsis: true },
    { colKey: 'message', title: '日志内容', minWidth: 520 },
    { colKey: 'actions', title: '操作', fixed: 'right', width: 90 },
  ],
  'admin-logins': [
    { colKey: 'primary', title: '账号信息', minWidth: 230 },
    { colKey: 'role_name', title: '角色', minWidth: 140 },
    { colKey: 'ip_address', title: '登录 IP', minWidth: 150 },
    { colKey: 'time', title: '登录时间', width: 170 },
    { colKey: 'status', title: '来源', width: 120 },
    { colKey: 'actions', title: '操作', fixed: 'right', width: 90 },
  ],
  api: [
    { colKey: 'request', title: '请求', minWidth: 320 },
    { colKey: 'httpStatus', title: '状态码', width: 100 },
    { colKey: 'module', title: '模块', minWidth: 120 },
    { colKey: 'primary', title: '调用端', minWidth: 160 },
    { colKey: 'ip_address', title: 'IP', minWidth: 140 },
    { colKey: 'time', title: '记录时间', width: 170 },
    { colKey: 'actions', title: '操作', fixed: 'right', width: 90 },
  ],
  sms: [
    { colKey: 'id', title: 'ID', width: 72 },
    { colKey: 'primary', title: '接收信息', minWidth: 200 },
    { colKey: 'template_code', title: '模板编号', width: 116, ellipsis: true },
    { colKey: 'message', title: '内容摘要', minWidth: 240 },
    { colKey: 'status', title: '状态', width: 100 },
    { colKey: 'driver_key', title: '驱动 key', minWidth: 112, ellipsis: true },
    { colKey: 'trace_id', title: 'Trace ID', minWidth: 150, ellipsis: true },
    { colKey: 'request_id', title: '请求号', minWidth: 150, ellipsis: true },
    { colKey: 'error', title: '错误信息', minWidth: 190 },
    { colKey: 'time', title: '创建时间', width: 156 },
    { colKey: 'actions', title: '操作', fixed: 'right', width: 80 },
  ],
  email: [
    { colKey: 'id', title: 'ID', width: 72 },
    { colKey: 'primary', title: '收件信息', minWidth: 200 },
    { colKey: 'template_code', title: '模板编号', width: 116, ellipsis: true },
    { colKey: 'subject', title: '主题', minWidth: 200, ellipsis: true },
    { colKey: 'message', title: '正文摘要', minWidth: 240 },
    { colKey: 'status', title: '状态', width: 100 },
    { colKey: 'driver_key', title: '驱动 key', minWidth: 112, ellipsis: true },
    { colKey: 'trace_id', title: 'Trace ID', minWidth: 150, ellipsis: true },
    { colKey: 'error', title: '错误信息', minWidth: 190 },
    { colKey: 'time', title: '创建时间', width: 156 },
    { colKey: 'actions', title: '操作', fixed: 'right', width: 80 },
  ],
  tasks: [
    { colKey: 'time', title: '记录时间', width: 170 },
    { colKey: 'primary', title: '任务', minWidth: 220 },
    { colKey: 'status', title: '状态', width: 110 },
    { colKey: 'level', title: '级别', width: 100 },
    { colKey: 'duration_ms', title: '耗时(ms)', width: 110 },
    { colKey: 'message', title: '摘要', minWidth: 360 },
    { colKey: 'actions', title: '操作', fixed: 'right', width: 90 },
  ],
  gateway: [
    { colKey: 'time', title: '记录时间', width: 170 },
    { colKey: 'gateway', title: '网关', width: 110 },
    { colKey: 'gateway_key', title: '业务 key', minWidth: 120, ellipsis: true },
    { colKey: 'action', title: '操作', width: 110 },
    { colKey: 'out_trade_no', title: '商户单号', minWidth: 180, ellipsis: true },
    { colKey: 'trade_no', title: '交易号', minWidth: 180, ellipsis: true },
    { colKey: 'trace_id', title: 'Trace ID', minWidth: 170, ellipsis: true },
    { colKey: 'result_status', title: '结果', width: 90 },
    { colKey: 'error_msg', title: '错误信息', minWidth: 150, ellipsis: true },
    { colKey: 'actions', title: '操作', fixed: 'right', width: 90 },
  ],
};
const scheduleColumns: PrimaryTableCol<RecordRow>[] = [
  { colKey: 'task', title: '任务名称', minWidth: 260 },
  { colKey: 'cycle', title: '刷新周期', minWidth: 180 },
  { colKey: 'last', title: '最后执行', width: 170 },
  { colKey: 'level', title: '状态', width: 110 },
  { colKey: 'next', title: '下次执行', width: 170 },
  { colKey: 'actions', title: '操作', fixed: 'right', width: 120 },
];
const currentLogMeta = computed(() => (isLogTab(activeTab.value) ? logMeta[activeTab.value] : logMeta.system));
const logTableColumns = computed(() => (isLogTab(activeTab.value) ? baseLogColumns[activeTab.value] : []));
const isTextLog = computed(
  () => activeTab.value === 'system' || activeTab.value === 'runtime' || activeTab.value === 'tasks',
);
const keywordPlaceholder = computed(() => currentLogMeta.value.keyword);
const statusPlaceholder = computed(() => {
  if (activeTab.value === 'api') return '全部状态码';
  if (activeTab.value === 'tasks') return '全部任务状态';
  return '全部发送状态';
});
const taskStatusOptions = [
  { label: '成功', value: 'success' },
  { label: '失败', value: 'failed' },
  { label: '跳过', value: 'skipped' },
];
const statusOptions = computed(() => {
  if (activeTab.value === 'api') return httpStatusOptions;
  if (activeTab.value === 'tasks') return taskStatusOptions;
  return notifyStatusOptions;
});
const drawerSize = computed(() => (isMobile.value ? '100%' : '700px'));
const scheduleTasks = computed(() => asArray(scheduleOverview.value.tasks));
const scheduleSystemTasks = computed(() =>
  scheduleTasks.value.filter((task) => scheduleTaskSourceType(task) === 'system'),
);
const scheduleThirdPartyTasks = computed(() =>
  scheduleTasks.value.filter((task) => scheduleTaskSourceType(task) === 'third_party'),
);
const scheduleSourceOptions = computed(() => [
  { label: `全部 ${scheduleTasks.value.length}`, value: 'all' },
  { label: `系统 ${scheduleSystemTasks.value.length}`, value: 'system' },
  { label: `第三方 ${scheduleThirdPartyTasks.value.length}`, value: 'third_party' },
]);
const scheduleTaskGroups = computed(() => [
  {
    key: 'system',
    title: '系统任务',
    description: '核心自动化',
    theme: 'primary' as const,
    tasks: scheduleSystemTasks.value,
  },
  {
    key: 'third_party',
    title: '第三方任务',
    description: '插件 / Addon / Hook',
    theme: 'warning' as const,
    tasks: scheduleThirdPartyTasks.value,
  },
]);
const visibleScheduleTaskGroups = computed(() => {
  if (scheduleSourceFilter.value === 'all') return scheduleTaskGroups.value;
  return scheduleTaskGroups.value.filter((group) => group.key === scheduleSourceFilter.value);
});
const scheduleEnvironment = computed(() => toRecord(scheduleOverview.value.environment));
const scheduleMutex = computed(() => toRecord(scheduleEnvironment.value.schedule_mutex));
const scheduleAutomationConfig = computed(() => toRecord(scheduleEnvironment.value.automation_config));
const scheduleEnvAlerts = computed(() => {
  const env = scheduleEnvironment.value;
  if (Object.keys(env).length === 0) return [];
  const alerts: Array<{
    key: string;
    label: string;
    value: string;
    detail?: string;
    theme: 'success' | 'warning' | 'danger';
  }> = [];

  const mutex = scheduleMutex.value;
  const mutexEnabled = Boolean(mutex.enabled);
  alerts.push({
    key: 'mutex',
    label: '调度互斥',
    value: mutexEnabled ? '已启用' : '降级中',
    detail: mutexEnabled
      ? String(mutex.mode || 'without_overlapping')
      : `${mutex.reason || '未知原因'}（${mutex.cache_store || '未知缓存'}/${mutex.os_family || '未知系统'}）`,
    theme: mutexEnabled ? 'success' : 'warning',
  });

  const businessQueue = String(env.business_queue || '');
  const automationQueue = String(env.automation_queue || '');
  if (businessQueue && automationQueue) {
    alerts.push({
      key: 'queue_isolation',
      label: '队列隔离',
      value: '业务与定时已分离',
      detail: `业务：${businessQueue}；定时：${automationQueue}`,
      theme: 'success',
    });
  }

  const pendingJobs = env.pending_jobs;
  if (typeof pendingJobs === 'number') {
    alerts.push({
      key: 'pending_jobs',
      label: '待处理任务',
      value: String(pendingJobs),
      detail: env.queue_runtime_mode ? `队列模式：${env.queue_runtime_mode}` : undefined,
      theme: pendingJobs > 50 ? 'danger' : pendingJobs > 10 ? 'warning' : 'success',
    });
  }

  const failedJobs = env.failed_jobs;
  if (typeof failedJobs === 'number') {
    alerts.push({
      key: 'failed_jobs',
      label: '失败队列任务',
      value: String(failedJobs),
      detail: failedJobs > 0 ? 'failed_jobs 表累计失败记录，建议排查后重试或清理' : undefined,
      theme: failedJobs > 0 ? 'danger' : 'success',
    });
  }

  const automationStatus = String(scheduleAutomationConfig.value.status || 'loaded');
  if (automationStatus !== 'loaded') {
    alerts.push({
      key: 'automation_config',
      label: '自动化配置',
      value: automationStatus === 'fallback_default' ? '回退默认' : automationStatus,
      detail: String(scheduleAutomationConfig.value.fallback_reason || '配置读取失败'),
      theme: 'warning',
    });
  }

  return alerts;
});
const canViewSchedules = computed(() => hasPermission(AdminPermissions.SCHEDULE_VIEW));
const canTriggerSchedules = computed(() => hasPermission(AdminPermissions.SCHEDULE_TRIGGER));
const canManageLogCleanup = computed(() => hasPermission(AdminPermissions.LOG_MANAGE));
const cleanupSubmitDisabled = computed(
  () =>
    cleanupSubmitting.value ||
    !canManageLogCleanup.value ||
    !cleanupForm.type ||
    !Number.isFinite(Number(cleanupForm.keep_days)) ||
    Number(cleanupForm.keep_days) < 1 ||
    cleanupForm.confirm_text.trim() !== '立即清理',
);
const cleanupTypes = computed(() => asArray(cleanupOverview.value.supported_cleanup_types));
const databaseCards = computed(() => {
  const database = toRecord(cleanupOverview.value.database);
  return [
    { key: 'sms', label: '短信日志', value: numberText(database.sms) },
    { key: 'email', label: '邮件日志', value: numberText(database.email) },
    { key: 'api', label: 'API 日志', value: numberText(database.api) },
    { key: 'admin_login', label: '管理员登录日志', value: numberText(database.admin_login) },
    { key: 'schedule_run', label: '调度执行日志', value: numberText(database.schedule_run) },
  ];
});
const fileCards = computed(() => {
  const file = toRecord(cleanupOverview.value.file);
  return [
    { key: 'exists', label: '日志文件状态', value: file.exists ? '存在' : '缺失' },
    { key: 'size_bytes', label: '文件大小', value: formatBytes(file.size_bytes) },
    { key: 'task_log_count', label: '自动任务日志条数', value: numberText(file.task_log_count) },
    {
      key: 'runtime_log_count',
      label: '运行日志条数',
      value: numberText(file.runtime_log_count ?? file.system_log_count),
    },
  ];
});
const affectedCountText = computed(() => {
  const affected = Object.values(toRecord(lastCleanupResult.value?.affected));
  return affected.reduce((total: number, value) => total + Number(value || 0), 0);
});

function resolveTabValue(value: unknown): LogsTab | null {
  const tab = Array.isArray(value) ? value[0] : value;
  return validTabs.includes(tab as LogsTab) ? (tab as LogsTab) : null;
}

function resolveRouteTab(): LogsTab {
  return resolveTabValue(route.query.tab) || resolveTabValue(route.meta.logTab) || 'system';
}

function isLogTab(value: LogsTab): value is LogTab {
  return ['system', 'runtime', 'admin-logins', 'api', 'sms', 'email', 'tasks', 'gateway'].includes(value);
}

function showFilter(name: string) {
  return currentLogMeta.value.filters.includes(name);
}

function refreshCurrentTab() {
  if (isLogTab(activeTab.value)) return loadLogs();
  if (activeTab.value === 'schedules') return loadScheduleOverview();
  return loadCleanupOverview();
}

function buildLogParams(): LogListParams {
  const params: LogListParams = {
    page: logPagination.page,
    page_size: logPagination.page_size,
  };
  for (const key of currentLogMeta.value.filters) {
    if (key === 'date') {
      if (filters.start_date.trim()) params.start_date = filters.start_date.trim();
      if (filters.end_date.trim()) params.end_date = filters.end_date.trim();
      continue;
    }
    const value = filters[key as keyof typeof filters];
    if (value !== '' && value !== null && value !== undefined) {
      (params as Record<string, unknown>)[key] = typeof value === 'string' ? value.trim() : value;
    }
  }
  return params;
}

function applyRouteFilterQuery() {
  for (const key of currentLogMeta.value.filters) {
    if (key === 'date') continue;
    const queryValue = route.query[key];
    if (queryValue === undefined) continue;

    const normalizedValue = Array.isArray(queryValue) ? queryValue[0] : queryValue;
    if (normalizedValue !== null && key in filters) {
      (filters as Record<LogFilterKey, string | number>)[key as LogFilterKey] = String(normalizedValue);
    }
  }
}

const logRequestSeq = ref(0);

async function loadLogs() {
  if (!isLogTab(activeTab.value)) return;
  logLoading.value = true;
  const seq = ++logRequestSeq.value;
  try {
    const params = buildLogParams();
    const response = await requestLogList(activeTab.value, params);
    if (seq !== logRequestSeq.value) return;
    logRows.value = response.list || [];
    logPagination.total = Number(response.total || 0);
    logPagination.page = Number(response.page || logPagination.page);
    logPagination.page_size = Number(response.page_size || logPagination.page_size);
  } catch (error) {
    if (seq !== logRequestSeq.value) return;
    logRows.value = [];
    logPagination.total = 0;
    MessagePlugin.error(errorMessage(error, `加载${currentLogMeta.value.title}失败`));
  } finally {
    if (seq === logRequestSeq.value) {
      logLoading.value = false;
    }
  }
}

function requestLogList(tab: LogTab, params: LogListParams): Promise<PaginatedList> {
  const map = {
    system: adminApi.logs.system,
    runtime: adminApi.logs.runtime,
    'admin-logins': adminApi.logs.adminLogins,
    api: adminApi.logs.api,
    sms: adminApi.logs.sms,
    email: adminApi.logs.email,
    tasks: adminApi.logs.tasks,
    gateway: adminApi.logs.gateway,
  };
  return map[tab](params);
}

function handleLogSearch() {
  logPagination.page = 1;
  loadLogs();
}

function resetLogFilters(shouldLoad = true) {
  Object.assign(filters, {
    keyword: '',
    actor_keyword: '',
    description_keyword: '',
    ip_address: '',
    level: '',
    task_key: '',
    method: '',
    module: '',
    user_type: '',
    status: '',
    phone: '',
    email: '',
    start_date: '',
    end_date: '',
    gateway: '',
    gateway_key: '',
    driver_key: '',
    plugin_id: '',
    trace_id: '',
    action: '',
    result_status: '',
    actor_type: '',
    subject_type: '',
  });
  logPagination.page = 1;
  if (shouldLoad && isLogTab(activeTab.value)) loadLogs();
}

function handleLogPageChange(data: PageInfo) {
  logPagination.page = data.current;
  logPagination.page_size = data.pageSize;
  loadLogs();
}

async function loadScheduleOverview() {
  if (!canViewSchedules.value) return;
  scheduleLoading.value = true;
  try {
    scheduleOverview.value = { ...(await adminApi.schedules.overview()) };
  } catch (error) {
    scheduleOverview.value = { tasks: [], recent_logs: [] };
    MessagePlugin.error(errorMessage(error, '定时任务状态加载失败'));
  } finally {
    scheduleLoading.value = false;
  }
}

async function triggerTask(row: TableRowData) {
  if (!canTriggerSchedules.value) {
    MessagePlugin.warning('当前账号缺少 schedule.trigger 权限');
    return;
  }

  const task = String(row.key || '');
  if (!task) return;
  triggeringKey.value = task;
  try {
    const response = await adminApi.schedules.trigger({ task });
    const modeText =
      {
        sync: '同步执行',
        after_response: '后台立即执行',
        queue: '已进入队列',
      }[String(response.execution_mode || '').toLowerCase()] || '已提交执行';
    MessagePlugin.success(`${fieldValue(row.title || row.key)}${modeText}`);
    await loadScheduleOverview();
  } catch (error) {
    MessagePlugin.error(errorMessage(error, `${fieldValue(row.title || row.key)}执行失败`));
  } finally {
    triggeringKey.value = '';
  }
}

async function loadCleanupOverview() {
  cleanupLoading.value = true;
  try {
    cleanupOverview.value = await adminApi.logs.cleanupOverview();
    if (!cleanupForm.type) cleanupForm.type = String(cleanupTypes.value[0]?.value || 'sms');
  } catch (error) {
    cleanupOverview.value = { database: {}, file: {}, supported_cleanup_types: [] };
    MessagePlugin.error(errorMessage(error, '加载日志清理概览失败'));
  } finally {
    cleanupLoading.value = false;
  }
}

async function handleCleanup() {
  if (!canManageLogCleanup.value) {
    MessagePlugin.warning('当前账号缺少 log.manage 权限');
    return;
  }
  if (!cleanupForm.type) {
    MessagePlugin.warning('请选择清理类型');
    return;
  }
  if (!Number.isFinite(Number(cleanupForm.keep_days)) || Number(cleanupForm.keep_days) < 1) {
    MessagePlugin.warning('保留天数必须大于 0');
    return;
  }
  if (cleanupForm.confirm_text.trim() !== '立即清理') {
    MessagePlugin.warning('确认文本必须为 立即清理');
    return;
  }
  cleanupSubmitting.value = true;
  try {
    lastCleanupResult.value = await adminApi.logs.cleanup({
      type: cleanupForm.type,
      keep_days: Number(cleanupForm.keep_days),
      confirm_text: cleanupForm.confirm_text.trim(),
    });
    cleanupForm.confirm_text = '';
    await loadCleanupOverview();
    MessagePlugin.success('日志清理完成');
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '日志清理失败'));
  } finally {
    cleanupSubmitting.value = false;
  }
}

async function openDetail(row: TableRowData) {
  currentLog.value = row as RecordRow;
  detailVisible.value = true;

  if (!isLogTab(activeTab.value) || row.id === undefined || row.id === null || row.id === '') {
    return;
  }

  try {
    const detail = await adminApi.logs.detail(activeTab.value, row.id as string | number);
    currentLog.value = { ...(row as RecordRow), ...detail };
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '加载日志详情失败'));
  }
}

function closeDetailDrawer() {
  detailVisible.value = false;
  currentLog.value = null;
}

function primaryTitle(row: RecordRow) {
  if (activeTab.value === 'system') return fieldValue(row.actor_name || '系统');
  if (activeTab.value === 'admin-logins') return fieldValue(row.admin_username);
  if (activeTab.value === 'api') return fieldValue(row.actor_name || userTypeLabel(row.user_type));
  if (activeTab.value === 'sms') return fieldValue(row.phone);
  if (activeTab.value === 'email') return fieldValue(row.to_email);
  if (activeTab.value === 'tasks') return fieldValue(row.task_title || row.task_key);
  return fieldValue(row.id);
}

function primarySubText(row: RecordRow) {
  if (activeTab.value === 'system')
    return fieldValue(row.actor_type === 'system' ? '系统操作' : `ID: ${row.actor_id || '-'}`);
  if (activeTab.value === 'admin-logins') return fieldValue(row.admin_nickname || row.actor_name || '未设置昵称');
  if (activeTab.value === 'api') return userTypeLabel(row.user_type);
  if (activeTab.value === 'sms' || activeTab.value === 'email') return `发送时间：${formatDate(row.sent_at)}`;
  if (activeTab.value === 'tasks') return fieldValue(row.task_key);
  return '';
}

function messageText(row: RecordRow) {
  if (activeTab.value === 'email') return contentPreview(row.content);
  if (activeTab.value === 'system') return fieldValue(row.description);
  return fieldValue(row.message || row.content);
}

function subjectText(row: RecordRow) {
  const type = fieldValue(row.subject_type);
  const id = fieldValue(row.subject_id);
  if (type === '-' && id === '-') return '-';
  if (id === '-') return type;
  if (type === '-') return id;
  return `${type} #${id}`;
}

function messageTag(row: RecordRow): string {
  const text = messageText(row);
  const match = text.match(/^\[([^\]]+)\]/);
  return match ? match[1] : '';
}

function messageBody(row: RecordRow): string {
  const text = messageText(row);
  return text.replace(/^\[[^\]]+\]\s*/, '');
}

type MessageSegment = { type: 'text'; text: string } | { type: 'id'; label: string; id: string };

const ID_PATTERNS: Array<{ label: string; regex: RegExp }> = [
  { label: 'Invoice ID', regex: /Invoice\s*ID\s*[:：]\s*(\d+)/gi },
  { label: 'Order ID', regex: /Order\s*ID\s*[:：]\s*(\d+)/gi },
  { label: 'Host ID', regex: /Host\s*ID\s*[:：]\s*(\d+)/gi },
  { label: 'User ID', regex: /User\s*ID\s*[:：]\s*(\d+)/gi },
  { label: 'Service ID', regex: /Service\s*ID\s*[:：]\s*(\d+)/gi },
  { label: 'Ticket ID', regex: /Ticket\s*ID\s*[:：]\s*(\d+)/gi },
  { label: 'Payment ID', regex: /Payment\s*ID\s*[:：]\s*(\d+)/gi },
  { label: 'Product ID', regex: /Product\s*ID\s*[:：]\s*(\d+)/gi },
  { label: 'Supplier ID', regex: /Supplier\s*ID\s*[:：]\s*(\d+)/gi },
  { label: 'Admin ID', regex: /Admin\s*ID\s*[:：]\s*(\d+)/gi },
  { label: 'Transaction ID', regex: /Transaction\s*ID\s*[:：]\s*(\d+)/gi },
];

function parseMessageSegments(row: RecordRow): MessageSegment[] {
  const text = messageBody(row);
  if (!text) return [{ type: 'text', text: '-' }];

  const matches: Array<{ index: number; end: number; label: string; id: string }> = [];

  for (const pattern of ID_PATTERNS) {
    pattern.regex.lastIndex = 0;
    let match: RegExpExecArray | null = pattern.regex.exec(text);
    while (match !== null) {
      matches.push({
        index: match.index,
        end: match.index + match[0].length,
        label: pattern.label,
        id: match[1],
      });
      match = pattern.regex.exec(text);
    }
  }

  if (matches.length === 0) return [{ type: 'text', text }];

  matches.sort((a, b) => a.index - b.index);

  const segments: MessageSegment[] = [];
  let cursor = 0;

  for (const m of matches) {
    if (m.index > cursor) {
      segments.push({ type: 'text', text: text.slice(cursor, m.index) });
    }
    segments.push({ type: 'id', label: m.label, id: m.id });
    cursor = m.end;
  }

  if (cursor < text.length) {
    segments.push({ type: 'text', text: text.slice(cursor) });
  }

  return segments;
}

function copyId(label: string, id: string) {
  const value = `${label}:${id}`;
  navigator.clipboard.writeText(value).then(
    () => MessagePlugin.success(`已复制 ${value}`),
    () => MessagePlugin.warning('复制失败，请手动选择'),
  );
  const routePath = routeForIdSegment(label, id);
  if (routePath) {
    router.push(routePath);
  }
}

function idSegmentTitle(seg: Extract<MessageSegment, { type: 'id' }>) {
  return routeForIdSegment(seg.label, seg.id)
    ? `点击复制并跳转 ${seg.label}:${seg.id}`
    : `点击复制 ${seg.label}:${seg.id}`;
}

function routeForIdSegment(label: string, id: string) {
  const cleanId = String(id || '').trim();
  if (!cleanId) return '';
  const routes: Record<string, string> = {
    'Order ID': `/admin/finance/orders/${cleanId}`,
    'User ID': `/admin/users/${cleanId}`,
    'Ticket ID': `/admin/ticket-conversations/${cleanId}`,
    'Host ID': `/admin/services?id=${encodeURIComponent(cleanId)}`,
    'Service ID': `/admin/services?id=${encodeURIComponent(cleanId)}`,
    'Product ID': `/admin/products?product_id=${encodeURIComponent(cleanId)}`,
  };
  return routes[label] || '';
}

function statusLabel(status: unknown) {
  if (activeTab.value === 'admin-logins') return sourceLabel(status);
  const statusKey = String(status || '').toLowerCase();
  if (activeTab.value === 'tasks') {
    return String({ success: '成功', failed: '失败', skipped: '跳过' }[statusKey] || fieldValue(status));
  }
  return String({ success: '发送成功', failed: '发送失败', pending: '待发送' }[statusKey] || fieldValue(status));
}

function statusValue(row: RecordRow) {
  return activeTab.value === 'admin-logins' ? row.source : row.status;
}

/** 为移动端卡片提取状态值（兼容多种日志类型） */
function logStatusValue(row: RecordRow) {
  if (activeTab.value === 'admin-logins') return row.source;
  if (row.result_status) return row.result_status;
  return row.status;
}

/** 为移动端卡片提取核心消息文本 */
function logCardMessage(row: RecordRow) {
  const text = messageText(row);
  if (text) {
    const cleaned = String(text).replace(/\n+/g, ' ').trim();
    return cleaned.length > 160 ? `${cleaned.slice(0, 160)}…` : cleaned;
  }
  return primaryTitle(row) || primarySubText(row) || '-';
}

function statusTheme(status: unknown) {
  if (activeTab.value === 'admin-logins') return String(status) === 'operation_log' ? 'success' : 'warning';
  return (
    {
      success: 'success',
      failed: 'danger',
      pending: 'warning',
      skipped: 'warning',
    }[String(status || '').toLowerCase()] || 'default'
  );
}

function levelTheme(level: unknown) {
  return (
    {
      DEBUG: 'default',
      INFO: 'success',
      NOTICE: 'primary',
      WARNING: 'warning',
      ERROR: 'danger',
      CRITICAL: 'danger',
      ALERT: 'danger',
      EMERGENCY: 'danger',
      SUCCESS: 'success',
    }[String(level || '').toUpperCase()] || 'default'
  );
}

function httpStatusTheme(status: unknown) {
  const code = Number(status || 0);
  if (code >= 500) return 'danger';
  if (code >= 400) return 'warning';
  if (code >= 200) return 'success';
  return 'default';
}

function userTypeLabel(value: unknown) {
  return (
    {
      admin: '管理员',
      client: '客户',
      guest: '访客',
    }[String(value || '').toLowerCase()] || '系统'
  );
}

function sourceLabel(value: unknown) {
  return (
    {
      operation_log: '操作日志',
      admin_snapshot: '账号快照',
    }[String(value || '').toLowerCase()] || '未知来源'
  );
}

function hasPermission(permission: string) {
  const permissions = userStore.userInfo?.permissions || [];
  return hasPermissionInList(permissions, permission);
}

function formatDate(value: unknown) {
  return formatDateTime(value);
}

function asArray(value: unknown): RecordRow[] {
  return Array.isArray(value) ? (value as RecordRow[]) : [];
}

function scheduleTaskSourceType(row: RecordRow) {
  return String(row.source_type || '').trim() === 'third_party' ? 'third_party' : 'system';
}

function scheduleTaskSourceTheme(row: RecordRow) {
  return scheduleTaskSourceType(row) === 'third_party' ? 'warning' : 'primary';
}

function toRecord(value: unknown): Record<string, unknown> {
  return value && typeof value === 'object' ? (value as Record<string, unknown>) : {};
}

function numberText(value: unknown) {
  return Number(value || 0).toLocaleString('zh-CN');
}

function formatJson(value: unknown, fallback = '-') {
  if (value === null || value === undefined || value === '') return fallback;
  if (typeof value === 'string') {
    const text = value.trim();
    if (!text) return fallback;
    try {
      return JSON.stringify(JSON.parse(text), null, 2);
    } catch {
      return text;
    }
  }
  try {
    return JSON.stringify(value, null, 2);
  } catch {
    return fallback;
  }
}

function contentPreview(value: unknown) {
  const text = String(value ?? '')
    .replace(/<style[\s\S]*?<\/style>/gi, ' ')
    .replace(/<script[\s\S]*?<\/script>/gi, ' ')
    .replace(/<[^>]+>/g, ' ')
    .replace(/&nbsp;/gi, ' ')
    .replace(/\s+/g, ' ')
    .trim();
  if (!text) return '-';
  return text.length > 96 ? `${text.slice(0, 96)}...` : text;
}

function formatBytes(value: unknown) {
  const size = Number(value || 0);
  if (!Number.isFinite(size) || size <= 0) return '0 B';
  const units = ['B', 'KB', 'MB', 'GB'];
  let current = size;
  let unitIndex = 0;
  while (current >= 1024 && unitIndex < units.length - 1) {
    current /= 1024;
    unitIndex += 1;
  }
  return `${current.toFixed(current >= 10 || unitIndex === 0 ? 0 : 1)} ${units[unitIndex]}`;
}

function gatewayResultStatusLabel(value: unknown) {
  return (
    {
      success: '成功',
      failed: '失败',
      pending: '处理中',
    }[String(value || '').toLowerCase()] ||
    fieldValue(value) ||
    '未知'
  );
}

function gatewayLabel(value: unknown) {
  const key = String(value || '').toLowerCase();
  return gatewayOptions.find((item) => item.value === key)?.label || fieldValue(value);
}

function gatewayResultStatusTheme(value: unknown) {
  return (
    {
      success: 'success',
      failed: 'danger',
      pending: 'warning',
    }[String(value || '').toLowerCase()] || 'default'
  );
}

function formatScheduleCycle(row: RecordRow) {
  const expression = String(row.expression || '').trim();
  if (!expression) return '--';
  if (expression === '* * * * *') return '每 1 分钟';
  if (expression === '0 * * * *') return '每 1 小时';
  const everyMinutesMatch = expression.match(/^\*\/(\d+) \* \* \* \*$/);
  if (everyMinutesMatch) return `每 ${everyMinutesMatch[1]} 分钟`;
  const fixedMinuteHourlyMatch = expression.match(/^(\d{1,2}) \* \* \* \*$/);
  if (fixedMinuteHourlyMatch) return `每小时第 ${fixedMinuteHourlyMatch[1]} 分钟`;
  const dailyMatch = expression.match(/^(\d{1,2}) (\d{1,2}) \* \* \*$/);
  if (dailyMatch) return `每天 ${String(dailyMatch[2]).padStart(2, '0')}:${String(dailyMatch[1]).padStart(2, '0')}`;
  return '自定义周期';
}

watch(
  () => [route.path, route.query.tab, route.meta.logTab],
  () => {
    const nextTab = resolveRouteTab();
    if (nextTab === activeTab.value) return;
    activeTab.value = nextTab;
    resetLogFilters(false);
    applyRouteFilterQuery();
    refreshCurrentTab();
  },
);

onMounted(() => {
  applyRouteFilterQuery();
  refreshCurrentTab();
});
</script>
