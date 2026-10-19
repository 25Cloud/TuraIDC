<template>
  <t-drawer :visible="visible" :size="drawerSize" :header="headerTitle" @close="emit('close')">
    <template v-if="currentLog">
      <div class="detail-grid">
        <article v-for="item in detailFields" :key="item.label">
          <span>{{ item.label }}</span>
          <strong>{{ item.value }}</strong>
        </article>
      </div>
      <div v-for="item in detailBlocks" :key="item.label" class="detail-block">
        <h3>{{ item.label }}</h3>
        <iframe
          v-if="item.html"
          class="mail-preview-frame"
          :srcdoc="item.value"
          sandbox="allow-same-origin"
          :title="$t('logs.detailDrawer.mailPreview')"
        />
        <pre v-else class="json-block">{{ item.value }}</pre>
      </div>
    </template>
    <template #footer>
      <t-button variant="outline" @click="emit('close')">
        <template #icon><chevron-left-icon /></template>
        {{ $t('logs.detailDrawer.back') }}
      </t-button>
    </template>
  </t-drawer>
</template>
<script setup lang="ts">
import { ChevronLeftIcon } from 'tdesign-icons-vue-next';
import { computed } from 'vue';

import { t } from '@/locales';
import { fieldValue, formatDateTime } from '@/utils/format';

type LogTab =
  | 'system'
  | 'runtime'
  | 'admin-logins'
  | 'api'
  | 'sms'
  | 'email'
  | 'tasks'
  | 'gateway'
  | 'upstream'
  | 'upstream-uploads';
type RecordRow = Record<string, unknown>;

defineOptions({ name: 'LogDetailDrawer' });

const props = defineProps<{
  visible: boolean;
  currentLog: RecordRow | null;
  activeTab: LogTab | 'schedules' | 'cleanup';
  drawerSize: string;
}>();

const emit = defineEmits<{
  (e: 'close'): void;
}>();

const headerTitle = computed(() => {
  const row = props.currentLog || {};
  const detailText = t('logs.detailDrawer.detail');
  if (props.activeTab === 'admin-logins')
    return t('logs.detailDrawer.titleAdminLogin', { value: fieldValue(row.admin_username) || detailText });
  if (props.activeTab === 'api')
    return t('logs.detailDrawer.titleApi', { value: fieldValue(row.request_id) || detailText });
  if (props.activeTab === 'sms') return t('logs.detailDrawer.titleSms', { value: fieldValue(row.phone) || detailText });
  if (props.activeTab === 'email')
    return t('logs.detailDrawer.titleEmail', { value: fieldValue(row.to_email) || detailText });
  if (props.activeTab === 'tasks')
    return t('logs.detailDrawer.titleTask', { value: fieldValue(row.task_key) || detailText });
  if (props.activeTab === 'gateway') {
    return t('logs.detailDrawer.titleGateway', {
      gateway: fieldValue(row.gateway),
      action: fieldValue(row.action),
    });
  }
  if (props.activeTab === 'system')
    return t('logs.detailDrawer.titleSystem', { value: fieldValue(row.actor_name) || detailText });
  if (props.activeTab === 'runtime')
    return t('logs.detailDrawer.titleRuntime', { value: fieldValue(row.id) || detailText });
  if (props.activeTab === 'upstream')
    return t('logs.detailDrawer.titleUpstream', { value: fieldValue(row.ticket_id) || detailText });
  if (props.activeTab === 'upstream-uploads')
    return t('logs.detailDrawer.titleUpstreamUpload', { value: fieldValue(row.id) || detailText });
  return t('logs.detailDrawer.titleSystem', { value: fieldValue(row.id) || detailText });
});

const detailFields = computed(() => {
  const row = props.currentLog || {};
  if (props.activeTab === 'api') {
    return [
      { label: t('logs.detailDrawer.requestMethod'), value: fieldValue(row.method) },
      { label: t('logs.detailDrawer.statusCode'), value: fieldValue(row.status) },
      { label: t('logs.detailDrawer.requestPath'), value: fieldValue(row.path) },
      { label: t('logs.detailDrawer.module'), value: fieldValue(row.module) },
      { label: t('logs.detailDrawer.requestId'), value: fieldValue(row.request_id) },
      { label: t('logs.detailDrawer.recordedAt'), value: formatDate(row.created_at) },
      { label: t('logs.detailDrawer.clientType'), value: userTypeLabel(row.user_type) },
      { label: t('logs.detailDrawer.ipAddress'), value: fieldValue(row.ip_address) },
    ];
  }
  if (props.activeTab === 'admin-logins') {
    return [
      { label: t('logs.detailDrawer.loginAccount'), value: fieldValue(row.admin_username) },
      { label: t('logs.detailDrawer.nickname'), value: fieldValue(row.admin_nickname || row.actor_name) },
      { label: t('logs.detailDrawer.role'), value: fieldValue(row.role_name) },
      { label: t('logs.detailDrawer.loginTime'), value: formatDate(row.created_at) },
      { label: t('logs.detailDrawer.ipAddress'), value: fieldValue(row.ip_address) },
      { label: t('logs.detailDrawer.dataSource'), value: sourceLabel(row.source) },
    ];
  }
  if (props.activeTab === 'sms') {
    return [
      { label: t('logs.detailDrawer.phone'), value: fieldValue(row.phone) },
      { label: t('logs.detailDrawer.sendStatus'), value: statusLabel(row.status) },
      { label: t('logs.detailDrawer.templateCode'), value: fieldValue(row.template_code) },
      { label: t('logs.detailDrawer.provider'), value: fieldValue(row.provider) },
      { label: t('logs.detailDrawer.pluginId'), value: fieldValue(row.plugin_id) },
      { label: t('logs.detailDrawer.driverKey'), value: fieldValue(row.driver_key) },
      { label: 'Trace ID', value: fieldValue(row.trace_id) },
      { label: t('logs.detailDrawer.requestId'), value: fieldValue(row.request_id) },
      { label: t('logs.detailDrawer.sentAt'), value: formatDate(row.sent_at) },
      { label: t('logs.detailDrawer.createdAt'), value: formatDate(row.created_at) },
      { label: t('logs.detailDrawer.errorMessage'), value: fieldValue(row.error_msg) },
    ];
  }
  if (props.activeTab === 'email') {
    return [
      { label: t('logs.detailDrawer.recipientEmail'), value: fieldValue(row.to_email) },
      { label: t('logs.detailDrawer.sendStatus'), value: statusLabel(row.status) },
      { label: t('logs.detailDrawer.subject'), value: fieldValue(row.subject) },
      { label: t('logs.detailDrawer.templateCode'), value: fieldValue(row.template_code) },
      { label: t('logs.detailDrawer.pluginId'), value: fieldValue(row.plugin_id) },
      { label: t('logs.detailDrawer.driverKey'), value: fieldValue(row.driver_key) },
      { label: 'Trace ID', value: fieldValue(row.trace_id) },
      { label: t('logs.detailDrawer.sentAt'), value: formatDate(row.sent_at) },
      { label: t('logs.detailDrawer.createdAt'), value: formatDate(row.created_at) },
      { label: t('logs.detailDrawer.errorMessage'), value: fieldValue(row.error_msg) },
    ];
  }
  if (props.activeTab === 'tasks') {
    return [
      { label: t('logs.detailDrawer.taskName'), value: fieldValue(row.task_title || row.task_key) },
      { label: t('logs.detailDrawer.taskKey'), value: fieldValue(row.task_key) },
      { label: t('common.status'), value: taskStatusLabel(row.status) },
      { label: t('logs.detailDrawer.logLevel'), value: fieldValue(row.level) },
      { label: t('logs.detailDrawer.startedAt'), value: formatDate(row.started_at || row.time) },
      { label: t('logs.detailDrawer.finishedAt'), value: formatDate(row.finished_at) },
      { label: t('logs.detailDrawer.durationMs'), value: fieldValue(row.duration_ms) },
      { label: t('logs.detailDrawer.dataSource'), value: taskSourceLabel(row.source) },
    ];
  }
  if (props.activeTab === 'gateway') {
    return [
      { label: t('logs.detailDrawer.gateway'), value: gatewayLabel(row.gateway) },
      { label: t('common.action'), value: fieldValue(row.action) },
      { label: t('logs.detailDrawer.resultStatus'), value: fieldValue(row.result_status) },
      { label: t('logs.detailDrawer.pluginId'), value: fieldValue(row.plugin_id) },
      { label: t('logs.detailDrawer.businessKey'), value: fieldValue(row.gateway_key) },
      { label: 'Trace ID', value: fieldValue(row.trace_id) },
      { label: t('logs.detailDrawer.merchantTradeNo'), value: fieldValue(row.out_trade_no) },
      { label: t('logs.detailDrawer.tradeNo'), value: fieldValue(row.trade_no) },
      { label: t('logs.detailDrawer.invoiceId'), value: fieldValue(row.invoice_id) },
      { label: t('logs.detailDrawer.recordedAt'), value: formatDate(row.created_at) },
    ];
  }
  if (props.activeTab === 'upstream') {
    return [
      { label: t('logs.detailDrawer.ticketId'), value: fieldValue(row.ticket_id) },
      { label: t('logs.detailDrawer.replyId'), value: fieldValue(row.ticket_reply_id) },
      { label: t('logs.detailDrawer.direction'), value: fieldValue(row.direction) },
      { label: t('common.action'), value: fieldValue(row.operation) },
      { label: t('logs.detailDrawer.event'), value: fieldValue(row.event) },
      { label: t('common.status'), value: statusLabel(row.status) },
      { label: t('logs.detailDrawer.reasonCode'), value: fieldValue(row.reason_code) },
      { label: t('logs.detailDrawer.provider'), value: fieldValue(row.supplier_name || row.provider_key) },
      { label: t('logs.detailDrawer.providerId'), value: fieldValue(row.supplier_id) },
      { label: t('logs.detailDrawer.attempts'), value: fieldValue(row.attempt) },
      { label: t('logs.detailDrawer.httpStatus'), value: fieldValue(row.http_status) },
      { label: t('logs.detailDrawer.durationMs'), value: fieldValue(row.duration_ms) },
      { label: t('logs.detailDrawer.occurredAt'), value: formatDate(row.occurred_at || row.created_at) },
    ];
  }
  if (props.activeTab === 'system') {
    return [
      { label: t('logs.detailDrawer.actor'), value: fieldValue(row.actor_name) },
      { label: t('logs.detailDrawer.actorType'), value: fieldValue(row.actor_type) },
      { label: t('logs.detailDrawer.module'), value: fieldValue(row.module) },
      { label: t('logs.detailDrawer.action'), value: fieldValue(row.action) },
      { label: t('logs.detailDrawer.subjectType'), value: fieldValue(row.subject_type) },
      { label: t('logs.detailDrawer.subjectId'), value: fieldValue(row.subject_id) },
      { label: t('logs.detailDrawer.recordedAt'), value: formatDate(row.created_at) },
      { label: t('logs.detailDrawer.ipAddress'), value: fieldValue(row.ip_address) },
      { label: t('logs.detailDrawer.dataSource'), value: activitySourceLabel(row.source) },
    ];
  }
  if (props.activeTab === 'upstream-uploads') {
    return [
      { label: t('logs.detailDrawer.logLevel'), value: fieldValue(row.level) },
      { label: t('logs.detailDrawer.pluginId'), value: fieldValue(row.plugin_id) },
      { label: t('logs.detailDrawer.pluginKey'), value: fieldValue(row.plugin_key) },
      { label: 'Trace ID', value: fieldValue(row.trace_id) },
      { label: t('logs.detailDrawer.recordedAt'), value: formatDate(row.time) },
    ];
  }
  return [
    { label: t('logs.detailDrawer.logLevel'), value: fieldValue(row.level) },
    { label: t('logs.detailDrawer.pluginId'), value: fieldValue(row.plugin_id) },
    { label: t('logs.detailDrawer.pluginKey'), value: fieldValue(row.plugin_key) },
    { label: 'Trace ID', value: fieldValue(row.trace_id) },
    { label: t('logs.detailDrawer.recordedAt'), value: formatDate(row.time) },
  ];
});

const detailBlocks = computed(() => {
  const row = props.currentLog || {};
  if (props.activeTab === 'api') {
    return [
      { label: t('logs.detailDrawer.requestParams'), value: formatJson(row.params) },
      { label: t('logs.detailDrawer.fullContext'), value: formatJson(row.detail) },
      { label: 'User-Agent', value: formatJson(row.user_agent) },
    ];
  }
  if (props.activeTab === 'admin-logins') {
    return [{ label: t('logs.detailDrawer.contextDetail'), value: formatJson(row.detail) }];
  }
  if (props.activeTab === 'sms') {
    return [
      {
        label: t('logs.detailDrawer.templateParams'),
        value: formatJson(row.params, t('logs.detailDrawer.noTemplateParams')),
      },
      { label: t('logs.detailDrawer.smsContent'), value: fieldValue(row.content) },
    ];
  }
  if (props.activeTab === 'email') {
    return [
      { label: t('logs.detailDrawer.mailBodyPreview'), value: buildContentPreviewDoc(row.content), html: true },
      { label: t('logs.detailDrawer.htmlSource'), value: fieldValue(row.content) },
    ];
  }
  if (props.activeTab === 'gateway') {
    return [
      { label: t('logs.detailDrawer.requestData'), value: formatJson(row.request_data) },
      { label: t('logs.detailDrawer.responseData'), value: formatJson(row.response_data) },
      { label: t('logs.detailDrawer.errorMessage'), value: fieldValue(row.error_msg) },
    ];
  }
  if (props.activeTab === 'upstream') {
    return [
      { label: t('logs.detailDrawer.resultMessage'), value: fieldValue(row.message) },
      { label: t('logs.detailDrawer.reasonCode'), value: fieldValue(row.reason_code) },
    ];
  }
  if (props.activeTab === 'tasks') {
    return [
      { label: t('logs.detailDrawer.summaryJson'), value: formatJson(row.summary) },
      { label: t('logs.detailDrawer.errorMessage'), value: fieldValue(row.error_msg) },
      { label: t('logs.detailDrawer.rawLog'), value: fieldValue(row.raw || row.message) },
    ];
  }
  if (props.activeTab === 'system') {
    return [
      { label: t('logs.detailDrawer.description'), value: fieldValue(row.description) },
      { label: t('logs.detailDrawer.context'), value: formatJson(row.context) },
    ];
  }
  if (props.activeTab === 'upstream-uploads') {
    return [
      { label: t('logs.detailDrawer.formattedContent'), value: fieldValue(row.message) },
      { label: t('logs.detailDrawer.requestContext'), value: formatJson(row.request_meta) },
      { label: t('logs.detailDrawer.responseContext'), value: formatJson(row.response_meta) },
      { label: t('logs.detailDrawer.rawLog'), value: fieldValue(row.raw) },
    ];
  }
  return [
    { label: t('logs.detailDrawer.formattedContent'), value: fieldValue(row.message) },
    { label: t('logs.detailDrawer.requestContext'), value: formatJson(row.request_meta) },
    { label: t('logs.detailDrawer.responseContext'), value: formatJson(row.response_meta) },
    { label: t('logs.detailDrawer.rawLog'), value: fieldValue(row.raw) },
  ];
});

function formatDate(value: unknown) {
  return formatDateTime(value);
}

function statusLabel(status: unknown) {
  if (props.activeTab === 'admin-logins') return sourceLabel(status);
  if (props.activeTab === 'tasks') return taskStatusLabel(status);
  const statusKey = String(status || '').toLowerCase();
  if (props.activeTab === 'upstream') {
    return String(
      {
        pending: t('logs.forward.pending'),
        sending: t('logs.forward.sending'),
        delivered: t('logs.forward.delivered'),
        failed: t('logs.forward.failed'),
        skipped: t('logs.forward.notForwarded'),
      }[statusKey] || fieldValue(status),
    );
  }
  return String(
    { success: t('logs.notify.sent'), failed: t('logs.notify.failed'), pending: t('logs.notify.pending') }[statusKey] ||
      fieldValue(status),
  );
}

function taskStatusLabel(status: unknown) {
  return (
    {
      success: t('logs.status.success'),
      failed: t('logs.status.failed'),
      skipped: t('logs.status.skipped'),
    }[String(status || '').toLowerCase()] || fieldValue(status)
  );
}

function taskSourceLabel(value: unknown) {
  return (
    {
      schedule_run_logs: t('logs.source.scheduleRun'),
      activity_logs: t('logs.source.activityLog'),
      laravel_log: t('logs.source.laravelLog'),
    }[String(value || '').toLowerCase()] || fieldValue(value)
  );
}

function userTypeLabel(value: unknown) {
  return (
    {
      admin: t('logs.index.actorAdmin'),
      client: t('logs.index.actorCustomer'),
      guest: t('logs.index.actorGuest'),
    }[String(value || '').toLowerCase()] || t('logs.index.actorSystem')
  );
}

function sourceLabel(value: unknown) {
  return (
    {
      operation_log: t('logs.source.operationLog'),
      admin_snapshot: t('logs.source.adminSnapshot'),
    }[String(value || '').toLowerCase()] || t('logs.source.unknown')
  );
}

function activitySourceLabel(value: unknown) {
  return (
    {
      activity_log: t('logs.source.activityLog'),
      activity_logs: t('logs.source.activityLog'),
      operation_log: t('logs.source.operationLog'),
      operation_logs: t('logs.source.operationLog'),
    }[String(value || '').toLowerCase()] || fieldValue(value)
  );
}

function gatewayLabel(value: unknown) {
  return (
    {
      alipay: t('logs.gateway.alipay'),
      wechat: t('logs.gateway.wechat'),
      stripe: 'Stripe',
      alipay_f2f: t('logs.gateway.alipayF2f'),
    }[String(value || '').toLowerCase()] || fieldValue(value)
  );
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

function buildContentPreviewDoc(value: unknown) {
  const normalized = String(value ?? '').trim();
  if (!normalized) {
    return `<!doctype html><html lang="zh-CN"><body style="margin:0;padding:24px;color:#86909c;">${t('logs.detailDrawer.noContentPreview')}</body></html>`;
  }
  if (/<!doctype\s+html|<html\b|<body\b/i.test(normalized)) return sanitizeHtmlDoc(normalized);
  if (/<[a-z][a-z0-9]*(?:\s|>)/i.test(normalized)) {
    const sanitized = sanitizeHtml(normalized);
    return `<!doctype html><html lang="zh-CN"><head><meta charset="UTF-8"><meta http-equiv="Content-Security-Policy" content="default-src 'none'; style-src 'unsafe-inline'; img-src data:;"><style>body{margin:0;padding:24px;background:#f5f7fa;font-family:Arial,sans-serif;color:#1f2329}.mail-preview{max-width:680px;margin:0 auto;padding:24px;border:1px solid #dce7ff;border-radius: var(--td-radius-extraLarge, 12px);background:#fff}</style></head><body><div class="mail-preview">${sanitized}</div></body></html>`;
  }
  return `<!doctype html><html lang="zh-CN"><body style="margin:0;padding:24px;font-family:Arial,sans-serif;background:#f8fafc;color:#1f2329;"><pre style="margin:0;white-space:pre-wrap;line-height:1.75;">${escapeHtml(normalized)}</pre></body></html>`;
}

/** 消毒 HTML 片段：移除 script/iframe/object/embed 标签、事件处理器、javascript: 协议 */
function sanitizeHtml(html: string): string {
  return html
    .replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, '')
    .replace(/<iframe\b[^>]*>[\s\S]*?<\/iframe>/gi, '')
    .replace(/<object\b[^>]*>[\s\S]*?<\/object>/gi, '')
    .replace(/<embed\b[^>]*>/gi, '')
    .replace(/\bon\w+\s*=\s*["'][^"']*["']/gi, '')
    .replace(/\bon\w+\s*=\s*[^\s>]+/gi, '')
    .replace(/javascript\s*:/gi, '')
    .replace(/<link\b[^>]*>/gi, '');
}

/** 消毒完整 HTML 文档：注入 CSP 头部 */
function sanitizeHtmlDoc(doc: string): string {
  const csp =
    '<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; style-src \'unsafe-inline\'; img-src data:;">';
  if (/<head\b/i.test(doc)) {
    return doc.replace(/<head\b[^>]*>/i, `$&${csp}`);
  }
  if (/<html\b/i.test(doc)) {
    return doc.replace(/<html\b[^>]*>/i, `$&<head>${csp}</head>`);
  }
  return `<!doctype html><html lang="zh-CN"><head>${csp}</head><body>${doc}</body></html>`;
}

function escapeHtml(value: string) {
  return value
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}
</script>
