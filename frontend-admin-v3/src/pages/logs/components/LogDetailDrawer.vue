<template>
  <t-drawer :visible="visible" :size="drawerSize" :header="headerTitle" :footer="false" @close="emit('close')">
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
          title="邮件正文预览"
        />
        <pre v-else class="json-block">{{ item.value }}</pre>
      </div>
      <div class="detail-drawer-actions">
        <t-button variant="outline" @click="emit('close')">
          <template #icon><chevron-left-icon /></template>
          返回
        </t-button>
      </div>
    </template>
  </t-drawer>
</template>
<script setup lang="ts">
import { ChevronLeftIcon } from 'tdesign-icons-vue-next';
import { computed } from 'vue';

import { fieldValue, formatDateTime } from '@/utils/format';

type LogTab = 'system' | 'runtime' | 'admin-logins' | 'api' | 'sms' | 'email' | 'tasks' | 'gateway' | 'upstream';
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
  if (props.activeTab === 'admin-logins') return `管理员登录 · ${fieldValue(row.admin_username) || '详情'}`;
  if (props.activeTab === 'api') return `API 日志 · ${fieldValue(row.request_id) || '详情'}`;
  if (props.activeTab === 'sms') return `短信日志 · ${fieldValue(row.phone) || '详情'}`;
  if (props.activeTab === 'email') return `邮件日志 · ${fieldValue(row.to_email) || '详情'}`;
  if (props.activeTab === 'tasks') return `自动任务日志 · ${fieldValue(row.task_key) || '详情'}`;
  if (props.activeTab === 'gateway')
    return `网关日志 · ${fieldValue(row.gateway) || ''} ${fieldValue(row.action) || ''}`;
  if (props.activeTab === 'system') return `系统日志 · ${fieldValue(row.actor_name) || '详情'}`;
  if (props.activeTab === 'runtime') return `运行日志 · ${fieldValue(row.id) || '详情'}`;
  if (props.activeTab === 'upstream') return `工单推送日志 · 工单 ${fieldValue(row.ticket_id) || '详情'}`;
  return `系统日志 · ${fieldValue(row.id) || '详情'}`;
});

const detailFields = computed(() => {
  const row = props.currentLog || {};
  if (props.activeTab === 'api') {
    return [
      { label: '请求方法', value: fieldValue(row.method) },
      { label: '状态码', value: fieldValue(row.status) },
      { label: '请求路径', value: fieldValue(row.path) },
      { label: '模块', value: fieldValue(row.module) },
      { label: '请求号', value: fieldValue(row.request_id) },
      { label: '记录时间', value: formatDate(row.created_at) },
      { label: '调用端', value: userTypeLabel(row.user_type) },
      { label: 'IP 地址', value: fieldValue(row.ip_address) },
    ];
  }
  if (props.activeTab === 'admin-logins') {
    return [
      { label: '登录账号', value: fieldValue(row.admin_username) },
      { label: '昵称', value: fieldValue(row.admin_nickname || row.actor_name) },
      { label: '角色', value: fieldValue(row.role_name) },
      { label: '登录时间', value: formatDate(row.created_at) },
      { label: 'IP 地址', value: fieldValue(row.ip_address) },
      { label: '数据来源', value: sourceLabel(row.source) },
    ];
  }
  if (props.activeTab === 'sms') {
    return [
      { label: '手机号', value: fieldValue(row.phone) },
      { label: '发送状态', value: statusLabel(row.status) },
      { label: '模板编号', value: fieldValue(row.template_code) },
      { label: '供应商', value: fieldValue(row.provider) },
      { label: '插件 ID', value: fieldValue(row.plugin_id) },
      { label: '驱动 key', value: fieldValue(row.driver_key) },
      { label: 'Trace ID', value: fieldValue(row.trace_id) },
      { label: '请求号', value: fieldValue(row.request_id) },
      { label: '发送时间', value: formatDate(row.sent_at) },
      { label: '创建时间', value: formatDate(row.created_at) },
      { label: '错误信息', value: fieldValue(row.error_msg) },
    ];
  }
  if (props.activeTab === 'email') {
    return [
      { label: '收件邮箱', value: fieldValue(row.to_email) },
      { label: '发送状态', value: statusLabel(row.status) },
      { label: '主题', value: fieldValue(row.subject) },
      { label: '模板编号', value: fieldValue(row.template_code) },
      { label: '插件 ID', value: fieldValue(row.plugin_id) },
      { label: '驱动 key', value: fieldValue(row.driver_key) },
      { label: 'Trace ID', value: fieldValue(row.trace_id) },
      { label: '发送时间', value: formatDate(row.sent_at) },
      { label: '创建时间', value: formatDate(row.created_at) },
      { label: '错误信息', value: fieldValue(row.error_msg) },
    ];
  }
  if (props.activeTab === 'tasks') {
    return [
      { label: '任务名称', value: fieldValue(row.task_title || row.task_key) },
      { label: '任务键', value: fieldValue(row.task_key) },
      { label: '状态', value: taskStatusLabel(row.status) },
      { label: '日志级别', value: fieldValue(row.level) },
      { label: '开始时间', value: formatDate(row.started_at || row.time) },
      { label: '结束时间', value: formatDate(row.finished_at) },
      { label: '耗时(ms)', value: fieldValue(row.duration_ms) },
      { label: '数据来源', value: taskSourceLabel(row.source) },
    ];
  }
  if (props.activeTab === 'gateway') {
    return [
      { label: '网关', value: gatewayLabel(row.gateway) },
      { label: '操作', value: fieldValue(row.action) },
      { label: '结果状态', value: fieldValue(row.result_status) },
      { label: '插件 ID', value: fieldValue(row.plugin_id) },
      { label: '业务 key', value: fieldValue(row.gateway_key) },
      { label: 'Trace ID', value: fieldValue(row.trace_id) },
      { label: '商户单号', value: fieldValue(row.out_trade_no) },
      { label: '交易号', value: fieldValue(row.trade_no) },
      { label: '账单 ID', value: fieldValue(row.invoice_id) },
      { label: '记录时间', value: formatDate(row.created_at) },
    ];
  }
  if (props.activeTab === 'upstream') {
    return [
      { label: '工单 ID', value: fieldValue(row.ticket_id) },
      { label: '回复 ID', value: fieldValue(row.ticket_reply_id) },
      { label: '方向', value: fieldValue(row.direction) },
      { label: '操作', value: fieldValue(row.operation) },
      { label: '事件', value: fieldValue(row.event) },
      { label: '状态', value: statusLabel(row.status) },
      { label: '原因码', value: fieldValue(row.reason_code) },
      { label: 'Provider', value: fieldValue(row.provider_key) },
      { label: '供应商 ID', value: fieldValue(row.supplier_id) },
      { label: '尝试次数', value: fieldValue(row.attempt) },
      { label: 'HTTP 状态', value: fieldValue(row.http_status) },
      { label: '耗时(ms)', value: fieldValue(row.duration_ms) },
      { label: '发生时间', value: formatDate(row.occurred_at || row.created_at) },
    ];
  }
  if (props.activeTab === 'system') {
    return [
      { label: '操作人', value: fieldValue(row.actor_name) },
      { label: '操作人类型', value: fieldValue(row.actor_type) },
      { label: '模块', value: fieldValue(row.module) },
      { label: '动作', value: fieldValue(row.action) },
      { label: '关联类型', value: fieldValue(row.subject_type) },
      { label: '关联 ID', value: fieldValue(row.subject_id) },
      { label: '记录时间', value: formatDate(row.created_at) },
      { label: 'IP 地址', value: fieldValue(row.ip_address) },
      { label: '数据来源', value: activitySourceLabel(row.source) },
    ];
  }
  return [
    { label: '日志级别', value: fieldValue(row.level) },
    { label: '插件 ID', value: fieldValue(row.plugin_id) },
    { label: '插件 key', value: fieldValue(row.plugin_key) },
    { label: 'Trace ID', value: fieldValue(row.trace_id) },
    { label: '记录时间', value: formatDate(row.time) },
  ];
});

const detailBlocks = computed(() => {
  const row = props.currentLog || {};
  if (props.activeTab === 'api') {
    return [
      { label: '请求参数', value: formatJson(row.params) },
      { label: '完整上下文', value: formatJson(row.detail) },
      { label: 'User-Agent', value: formatJson(row.user_agent) },
    ];
  }
  if (props.activeTab === 'admin-logins') {
    return [{ label: '上下文详情', value: formatJson(row.detail) }];
  }
  if (props.activeTab === 'sms') {
    return [
      { label: '模板参数', value: formatJson(row.params, '暂无模板参数') },
      { label: '短信内容', value: fieldValue(row.content) },
    ];
  }
  if (props.activeTab === 'email') {
    return [
      { label: '邮件正文预览', value: buildContentPreviewDoc(row.content), html: true },
      { label: 'HTML 源码', value: fieldValue(row.content) },
    ];
  }
  if (props.activeTab === 'gateway') {
    return [
      { label: '请求数据', value: formatJson(row.request_data) },
      { label: '响应数据', value: formatJson(row.response_data) },
      { label: '错误信息', value: fieldValue(row.error_msg) },
    ];
  }
  if (props.activeTab === 'upstream') {
    return [
      { label: '结果说明', value: fieldValue(row.message) },
      { label: '原因码', value: fieldValue(row.reason_code) },
    ];
  }
  if (props.activeTab === 'tasks') {
    return [
      { label: '摘要 JSON', value: formatJson(row.summary) },
      { label: '错误信息', value: fieldValue(row.error_msg) },
      { label: '原始日志', value: fieldValue(row.raw || row.message) },
    ];
  }
  if (props.activeTab === 'system') {
    return [
      { label: '描述', value: fieldValue(row.description) },
      { label: '上下文', value: formatJson(row.context) },
    ];
  }
  return [
    { label: '格式化内容', value: fieldValue(row.message) },
    { label: '请求上下文', value: formatJson(row.request_meta) },
    { label: '响应上下文', value: formatJson(row.response_meta) },
    { label: '原始日志', value: fieldValue(row.raw) },
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
      { pending: '等待发送', sending: '发送中', delivered: '已转发', failed: '转发失败', skipped: '未转发' }[
        statusKey
      ] || fieldValue(status),
    );
  }
  return String({ success: '发送成功', failed: '发送失败', pending: '待发送' }[statusKey] || fieldValue(status));
}

function taskStatusLabel(status: unknown) {
  return (
    {
      success: '成功',
      failed: '失败',
      skipped: '跳过',
    }[String(status || '').toLowerCase()] || fieldValue(status)
  );
}

function taskSourceLabel(value: unknown) {
  return (
    {
      schedule_run_logs: '调度执行日志',
      activity_logs: '活动日志',
      laravel_log: '运行日志',
    }[String(value || '').toLowerCase()] || fieldValue(value)
  );
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

function activitySourceLabel(value: unknown) {
  return (
    {
      activity_log: '活动日志',
      activity_logs: '活动日志',
      operation_log: '操作日志',
      operation_logs: '操作日志',
    }[String(value || '').toLowerCase()] || fieldValue(value)
  );
}

function gatewayLabel(value: unknown) {
  return (
    {
      alipay: '支付宝',
      wechat: '微信支付',
      stripe: 'Stripe',
      alipay_f2f: '支付宝当面付（历史）',
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
    return '<!doctype html><html lang="zh-CN"><body style="margin:0;padding:24px;color:#86909c;">暂无正文内容</body></html>';
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
