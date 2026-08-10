<template>
  <div class="email-template-detail-page">
    <div class="template-toolbar">
      <div class="template-toolbar__left">
        <t-button theme="primary" variant="text" @click="goBack">
          <template #icon><chevron-left-icon /></template>
          返回列表
        </t-button>
        <div v-if="templateDefinition" class="template-title">
          <h2>{{ templateDefinition.name }}</h2>
          <span
            >{{ templateDefinition.channel === 'sms' ? '短信模板' : '邮件模板' }} · {{ templateDefinition.code }} ·
            {{ templateDefinition.isEnabled ? '启用' : '停用' }}</span
          >
        </div>
      </div>
      <t-space>
        <t-button variant="outline" :disabled="!templateDefinition" @click="openTestSendDialog">测试发送</t-button>
        <t-button variant="outline" :disabled="!templateDefinition" @click="resetCurrentTemplate">恢复默认</t-button>
        <t-button theme="primary" :loading="saving" :disabled="!templateDefinition" @click="saveCurrentTemplate"
          >保存模板</t-button
        >
      </t-space>
    </div>

    <template v-if="templateDefinition">
      <t-card :bordered="false" :loading="loading">
        <template #title>可用变量</template>
        <div class="token-list">
          <t-tag v-for="variable in templateDefinition.variables" :key="variable" variant="light">{{ variable }}</t-tag>
        </div>
        <div class="preview-params">
          <article v-for="variable in templateDefinition.variables" :key="`${variable}-sample`">
            <span>{{ variable }}</span>
            <strong>{{ getPreviewValue(variable) }}</strong>
          </article>
        </div>
      </t-card>

      <t-card :bordered="false" :loading="loading">
        <t-form :data="templateDefinition" label-align="top">
          <t-form-item label="发送状态" name="isEnabled">
            <div class="status-control">
              <t-switch
                v-model="templateDefinition.isEnabled"
                :custom-value="[true, false]"
                :label="['启用', '停用']"
              />
            </div>
          </t-form-item>
          <t-form-item v-if="templateDefinition.channel === 'email'" label="邮件主题" name="subject">
            <t-input v-model="templateDefinition.subject" placeholder="请输入邮件主题" />
          </t-form-item>
          <t-form-item v-if="templateDefinition.channel === 'sms'" label="供应商模板 ID" name="providerTemplateId">
            <t-input v-model="templateDefinition.providerTemplateId" placeholder="请输入运营商审核通过的模板 ID" />
          </t-form-item>
          <div v-if="templateDefinition.channel === 'email'" class="subject-preview">
            <span>主题预览</span>
            <strong>{{ renderPreviewSubject(templateDefinition) }}</strong>
          </div>

          <div class="editor-grid">
            <div class="editor-stack">
              <section class="editor-pane">
                <div class="pane-header">
                  <strong>{{ templateDefinition.channel === 'sms' ? '短信正文' : 'HTML 正文' }}</strong>
                  <span>{{
                    templateDefinition.channel === 'sms'
                      ? '业务层会先渲染正文，再连同供应商模板 ID 一起交给短信插件发送。'
                      : '可填写完整 HTML 文档或正文 HTML。'
                  }}</span>
                </div>
                <t-textarea
                  v-model="templateDefinition.content"
                  class="template-code-input"
                  :placeholder="templateDefinition.channel === 'sms' ? '请输入短信正文' : '请输入邮件 HTML 正文'"
                  :autosize="{ minRows: templateDefinition.channel === 'sms' ? 18 : 14, maxRows: 26 }"
                />
              </section>
            </div>

            <section class="preview-pane">
              <div class="pane-header">
                <strong>实时预览</strong>
                <span>{{ templateDefinition.channel === 'sms' ? '短信内容' : '按真实发送结构渲染' }}</span>
              </div>
              <iframe
                v-if="templateDefinition.channel === 'email'"
                class="preview-frame"
                :srcdoc="buildPreviewDocument(templateDefinition)"
                sandbox="allow-same-origin"
                title="邮件模板预览"
              />
              <div v-else class="sms-preview">
                <p>{{ renderPreviewSmsContent(templateDefinition) }}</p>
              </div>
            </section>
          </div>
        </t-form>
      </t-card>
    </template>

    <t-card v-else :bordered="false">
      <t-empty title="模板不存在" description="当前模板编号不在系统通知模板目录中。">
        <t-button theme="primary" @click="goBack">返回模板列表</t-button>
      </t-empty>
    </t-card>

    <t-dialog
      v-model:visible="testSendVisible"
      :header="testSendDialogTitle"
      width="560px"
      :confirm-btn="{ content: '确认发送', loading: testSending }"
      @confirm="submitTestSend"
    >
      <div class="template-test-dialog">
        <div v-if="templateDefinition" class="template-test-target">
          <strong>{{ templateDefinition.name }}</strong>
          <span
            >{{ templateDefinition.channel === 'sms' ? '短信模板' : '邮件模板' }} · {{ templateDefinition.code }}</span
          >
        </div>
        <t-form label-align="top">
          <t-form-item :label="templateChannel === 'sms' ? '接收手机号' : '接收邮箱地址'">
            <t-input
              v-model="testSendRecipient"
              :placeholder="testSendPlaceholder"
              clearable
              :type="templateChannel === 'sms' ? 'tel' : 'email'"
              @input="testSendResult = null"
            />
          </t-form-item>
        </t-form>

        <div
          v-if="testSendResult"
          class="template-test-feedback"
          :class="`template-test-feedback--${testSendResult.status}`"
        >
          <strong>{{ testSendSummaryText }}</strong>
          <span>成功 {{ testSendResult.success_count }} 条，失败 {{ testSendResult.failed_count }} 条</span>
        </div>
        <div v-if="testSendResult?.results?.length" class="template-test-results">
          <div
            v-for="item in testSendResult.results"
            :key="`${item.recipient}:${item.status}`"
            class="template-test-result-row"
          >
            <span>{{ item.recipient }}</span>
            <t-tag :theme="item.status === 'success' ? 'success' : 'danger'" variant="light">
              {{ item.status === 'success' ? '成功' : '失败' }}
            </t-tag>
            <small v-if="item.error">{{ item.error }}</small>
          </div>
        </div>
      </div>
    </t-dialog>
  </div>
</template>
<script setup lang="ts">
import './index.less';

import { ChevronLeftIcon } from 'tdesign-icons-vue-next';
import { MessagePlugin } from 'tdesign-vue-next';
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';

import { adminApi } from '@/api/admin';
import type { NotificationTemplateItem } from '@/api/admin/types';
import { resolveApiAssetUrl } from '@/utils/apiAssetUrl';

interface EditableTemplate extends NotificationTemplateItem {
  providerTemplateId?: string;
  isEnabled?: boolean;
  defaultSubject: string;
  defaultContent: string;
  defaultProviderTemplateId: string;
  defaultIsEnabled: boolean;
}

const route = useRoute();
const router = useRouter();
const loading = ref(false);
const saving = ref(false);
const testSendVisible = ref(false);
const testSending = ref(false);
const testSendRecipient = ref('');
const testSendResult = ref(null);
const DEFAULT_SITE_NAME = '二五云';
const DEFAULT_SITE_LOGO = '/branding/logo.svg';
const siteName = ref(DEFAULT_SITE_NAME);
const siteLogo = ref(DEFAULT_SITE_LOGO);
const templateDefinitions = ref<EditableTemplate[]>([]);

const templateCode = computed(() => String(route.params.code || '').trim());
const templateChannel = computed(() => (route.path.includes('/sms-templates/') ? 'sms' : 'email'));
const templateDefinition = computed(() => templateDefinitions.value.find((item) => item.code === templateCode.value));
const currentTab = computed(() => {
  if (route.query.tab === 'admin' || route.query.tab === 'user') return route.query.tab;
  return templateDefinition.value?.audience === 'admin' ? 'admin' : 'user';
});
const testSendDialogTitle = computed(() => (templateChannel.value === 'sms' ? '测试发送短信' : '测试发送邮件'));
const testSendPlaceholder = computed(() =>
  templateChannel.value === 'sms' ? '请输入接收手机号，例如：13900001234' : '请输入接收邮箱，例如：tester@example.com',
);
const testSendSummaryText = computed(() => {
  if (!testSendResult.value) return '';
  if (testSendResult.value.status === 'success') return '测试发送成功';
  if (testSendResult.value.status === 'partial_failed') return '测试发送部分失败';
  return '测试发送失败';
});

const previewBaseParams = computed(() => ({
  site_name: siteName.value,
  site_logo: siteLogo.value,
  display_name: '张三',
  username: 'zhangsan',
  recipient_name: '运维管理员',
  email: 'demo@example.com',
  client_email: 'client@example.com',
  client_name: '测试用户',
  login_at: '2026-04-01 14:30:00',
  ip: '203.0.113.25',
  device: 'Windows / Chrome',
  code: '482915',
  expire_minutes: '10',
  min: '5',
  service_name: '香港云服务器 CSP-2G',
  days_left: '3',
  expires_at: '2026-04-10 23:59:59',
  billing_cycle_label: '月付',
  urgency_message: '您的服务将在 3 天后到期，请提前完成续费。',
  invoice_no: 'zd202604011430004821',
  order_no: 'dd202604011430004821',
  order_id: 'dd202604011430004821',
  product_name: 'ecs.g9i.2c2g 2 vCPU 2G',
  product_main_ip: '203.0.113.18',
  product_username: 'administrator',
  operating_system: 'Ubuntu 22.04',
  addon_ip: '203.0.113.19',
  activated_at: '2026-04-01 14:36:00',
  hostname: 'hk-node-01',
  amount: '199.00',
  due_date: '2026-04-05',
  due_at: '2026-04-05 23:59:59',
  paid_at: '2026-04-01 10:12:33',
  refunded_at: '2026-04-02 09:10:00',
  payment_method: '支付宝',
  trade_no: '2026040100001001',
  notice_title: '账单提醒',
  notice_message: '请在到期前完成支付，以免影响关联服务。',
  status_label: '待支付',
  paid_amount: '199.00',
  order_total_fee: '199.00',
  remark: '人工核账通过',
  ticket_id: '1024',
  ticket_subject: '实例网络不通',
  ticket_created_at: '2026-04-01 14:40:00',
  ticket_closed_at: '2026-04-04 14:40:00',
  department: '技术支持',
  priority: '高',
  status: '处理中',
  message_preview: '您好，实例无法 SSH 登录，请协助排查。',
  staff_name: '技术支持 A',
  tickets_url: '/client/tickets',
  login_tip: '如您尚未登录，请先登录后查看工单详情。',
  bind_type: '手机',
  bind_account: '138****8000',
  registered_at: '2026-04-01 09:00:00',
  terminated_at: '2026-05-10 03:00:00',
  resumed_at: '2026-04-02 12:00:00',
  approved_at: '2026-04-03 16:00:00',
  bound_at: '2026-04-01 09:30:00',
}));

function normalizeSettings(response: any): Record<string, any> {
  if (Array.isArray(response)) return Object.fromEntries(response.map((item: any) => [item.key, item.value]));
  if (response && Array.isArray(response.list))
    return Object.fromEntries(response.list.map((item: any) => [item.key, item.value]));
  return response && typeof response === 'object' ? response : {};
}

function applySettings(template: NotificationTemplateItem, settings: Record<string, any>): EditableTemplate {
  const settingKeys = template.setting_keys || {};
  const subjectKey = settingKeys.subject || `email_template_subject_${template.code}`;
  const contentKey = settingKeys.content || `${template.channel}_template_content_${template.code}`;
  const providerTemplateIdKey =
    settingKeys.provider_template_id || `sms_template_provider_template_id_${template.code}`;
  const enabledKey = settingKeys.enabled || `${template.channel}_template_enabled_${template.code}`;
  const defaultSubject = template.subject || '';
  const defaultContent = template.content || '';
  const defaultProviderTemplateId = template.provider_template_id || '';
  const defaultIsEnabled = toBooleanValue(template.is_enabled ?? true);

  return {
    ...template,
    defaultSubject,
    defaultContent,
    defaultProviderTemplateId,
    subject: settingValue(settings, subjectKey, defaultSubject),
    content: settingValue(settings, contentKey, defaultContent),
    providerTemplateId: settingValue(settings, providerTemplateIdKey, defaultProviderTemplateId),
    defaultIsEnabled,
    isEnabled: toBooleanValue(settingValue(settings, enabledKey, defaultIsEnabled)),
  };
}

async function loadSettings() {
  loading.value = true;
  try {
    const [templateResponse, response, basicResponse] = await Promise.all([
      adminApi.settings.notificationTemplates({ channel: templateChannel.value }),
      adminApi.settings.list({ group: 'notification' }),
      adminApi.settings.list({ group: 'basic' }),
    ]);
    const settings = normalizeSettings(response);
    const basicSettings = normalizeSettings(basicResponse);
    siteName.value = String(basicSettings.site_name || DEFAULT_SITE_NAME);
    siteLogo.value = resolvePreviewLogo(String(basicSettings.site_logo || DEFAULT_SITE_LOGO));
    templateDefinitions.value = (templateResponse.list || []).map((template) => applySettings(template, settings));
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '加载通知模板失败'));
  } finally {
    loading.value = false;
  }
}

async function saveCurrentTemplate() {
  if (!templateDefinition.value) return;
  saving.value = true;
  try {
    const settingKeys = templateDefinition.value.setting_keys || {};
    const settings: Record<string, any> = {
      [settingKeys.content || `${templateDefinition.value.channel}_template_content_${templateDefinition.value.code}`]:
        templateDefinition.value.content,
      [settingKeys.enabled || `${templateDefinition.value.channel}_template_enabled_${templateDefinition.value.code}`]:
        templateDefinition.value.isEnabled ? 1 : 0,
    };

    if (templateDefinition.value.channel === 'email') {
      settings[settingKeys.subject || `email_template_subject_${templateDefinition.value.code}`] =
        templateDefinition.value.subject;
    }
    if (templateDefinition.value.channel === 'sms') {
      settings[
        settingKeys.provider_template_id || `sms_template_provider_template_id_${templateDefinition.value.code}`
      ] = templateDefinition.value.providerTemplateId || '';
    }

    await adminApi.settings.save({
      group: 'notification',
      settings,
    });
    MessagePlugin.success('模板已保存');
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '保存模板失败'));
  } finally {
    saving.value = false;
  }
}

function resetCurrentTemplate() {
  if (templateDefinition.value) resetTemplate(templateDefinition.value);
}

function openTestSendDialog() {
  if (!templateDefinition.value) return;

  testSendRecipient.value = '';
  testSendResult.value = null;
  testSendVisible.value = true;
}

async function submitTestSend() {
  if (!templateDefinition.value) return;

  const recipient = normalizeRecipient(testSendRecipient.value);
  if (!recipient) {
    MessagePlugin.warning(templateChannel.value === 'sms' ? '请输入接收手机号' : '请输入接收邮箱地址');
    return;
  }

  testSending.value = true;
  try {
    const result = await adminApi.settings.testNotificationTemplateSend({
      channel: templateChannel.value,
      code: templateDefinition.value.code,
      recipient,
    });
    testSendResult.value = result;

    if (result.failed_count > 0) {
      MessagePlugin.warning(result.status === 'partial_failed' ? '测试发送部分失败' : '测试发送失败');
    } else {
      MessagePlugin.success('测试发送成功');
    }
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '测试发送失败'));
  } finally {
    testSending.value = false;
  }
}

function resetTemplate(template: EditableTemplate) {
  template.subject = template.defaultSubject || '';
  template.content = template.defaultContent;
  template.providerTemplateId = template.defaultProviderTemplateId || '';
  template.isEnabled = template.defaultIsEnabled;
}

function goBack() {
  router.push({
    path: '/admin/notifications',
    query: templateChannel.value === 'sms' ? { tab: 'sms-templates' } : { tab: currentTab.value },
  });
}

function getPreviewParams(template: EditableTemplate) {
  const params: Record<string, any> = { ...previewBaseParams.value };
  template.variables.forEach((key) => {
    if (!(key in params)) params[key] = `示例${key}`;
  });
  return params;
}

function getPreviewValue(key: string) {
  if (!templateDefinition.value) return '-';
  return String(getPreviewParams(templateDefinition.value)[key] ?? '-');
}

function renderPreviewSubject(template: EditableTemplate) {
  return renderTemplateText(template.subject, getPreviewParams(template)) || '未设置主题';
}

function renderPreviewSmsContent(template: EditableTemplate) {
  return renderTemplateText(template.content, getPreviewParams(template)) || '未设置短信正文';
}

function buildPreviewDocument(template: EditableTemplate) {
  const params = getPreviewParams(template);
  const subject = renderPreviewSubject(template);
  const content = renderTemplateContent(template.content, params);
  if (looksLikeFullHtmlDocument(content)) return content;
  return `<!doctype html><html lang="zh-CN"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>${escapeHtml(subject)}</title></head><body>${content}</body></html>`;
}

function renderTemplateContent(template: string, params: Record<string, any>) {
  if (looksLikeHtml(template))
    return renderTemplateWithResolver(template, params, (key) => escapeHtml(params[key] ?? ''), true);
  return String(renderTemplateText(template, params) || '')
    .split(/\n{2,}/)
    .map((block) => `<p>${escapeHtml(block).replace(/\n/g, '<br>')}</p>`)
    .join('');
}

function renderTemplateText(template: string | null | undefined, params: Record<string, any>) {
  return renderTemplateWithResolver(template, params, (key) => String(params[key] ?? ''));
}

function renderTemplateWithResolver(
  template: string | null | undefined,
  params: Record<string, any>,
  resolver: (key: string) => string,
  htmlMode = false,
) {
  const variablePattern = /\{\{#(\w+)\}\}([\s\S]*?)\{\{\/\1\}\}/g;
  const resolveVariable = (_match: string, key: string, content: string) => (hasValue(params[key]) ? content : '');
  let rendered = String(template || '').replace(variablePattern, resolveVariable);
  rendered = rendered.replace(/\{\{\s*(\w+)\s*\}\}/g, (_match, key) => resolver(key));
  return htmlMode
    ? rendered.trim()
    : rendered
        .replace(/[ \t]+\n/g, '\n')
        .replace(/\n{3,}/g, '\n\n')
        .trim();
}

function looksLikeHtml(template: string | null | undefined) {
  return /<[a-z][a-z0-9]*(?:\s|>)/i.test(String(template || '').trim());
}

function looksLikeFullHtmlDocument(template: string | null | undefined) {
  return /^(?:<!doctype\s+html|<html\b)/i.test(String(template || '').trim());
}

function hasValue(value: any) {
  return ![null, undefined, '', false].includes(value);
}

function settingValue(settings: Record<string, any>, key: string, fallback: any) {
  return Object.hasOwn(settings, key) ? (settings[key] ?? '') : fallback;
}

function normalizeRecipient(value: any) {
  const recipient = String(value || '').trim();
  if (templateChannel.value === 'sms') return recipient.replace(/[\s-]+/g, '');
  return recipient;
}

function toBooleanValue(value: any) {
  if (value === true || value === 1) return true;
  return ['1', 'true', 'on', 'yes'].includes(String(value).trim().toLowerCase());
}

function resolvePreviewLogo(value: any) {
  const logo = String(value || '').trim() || DEFAULT_SITE_LOGO;
  const resolved = resolveApiAssetUrl(logo, import.meta.env.VITE_API_BASE_URL);
  if (/^(?:https?:)?\/\//i.test(resolved) || resolved.startsWith('data:') || resolved.startsWith('/')) return resolved;
  return `/${resolved}`;
}

function escapeHtml(value: any) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function errorMessage(error: any, fallback: string) {
  return String(error?.response?.data?.message || error?.message || fallback);
}

onMounted(loadSettings);
</script>
