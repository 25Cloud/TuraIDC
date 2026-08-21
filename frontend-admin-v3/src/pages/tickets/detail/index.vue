<template>
  <div class="ticket-conversation-page">
    <t-loading :loading="detailLoading" size="small">
      <div class="conversation-action-bar">
        <t-button variant="text" theme="default" @click="goBack">
          <template #icon><chevron-left-icon /></template>
          返回工单列表
        </t-button>
        <t-button
          v-if="detail && !isClosed(detail.status)"
          theme="danger"
          variant="outline"
          :loading="closeLoading"
          @click="handleClose"
        >
          <template #icon><close-circle-icon /></template>
          关闭工单
        </t-button>
      </div>
    </t-loading>

    <t-loading :loading="detailLoading" size="small">
      <div v-if="detail" class="conversation-layout">
        <t-card :bordered="false" class="conversation-card">
          <template #header>
            <div class="card-headline">
              <span>沟通记录</span>
              <small>{{ messages.length }} 条消息</small>
            </div>
          </template>

          <div class="message-list">
            <article
              v-for="message in messages"
              :key="message.messageKey"
              class="message-item"
              :class="message.is_staff ? 'is-staff' : 'is-client'"
            >
              <div class="message-item__body">
                <div class="message-meta">
                  <span>{{ messageSenderName(message) }}</span>
                  <time>{{ formatDateTime(message.created_at) }}</time>
                </div>

                <div class="message-bubble">
                  <div v-if="message.recalled" class="recalled-text">消息已撤回</div>
                  <template v-else>
                    <div v-if="message.quote" class="quote-box">
                      <span>{{ message.quote.sender_name || '用户' }}:</span>
                      <strong>{{ message.quote.recalled ? '消息已撤回' : message.quote.content || '-' }}</strong>
                    </div>
                    <div class="message-content">{{ message.content || '无文字内容' }}</div>
                    <div v-if="parseAttachments(message).length" class="attachment-list">
                      <button
                        v-for="attachment in parseAttachments(message)"
                        :key="String(attachment.id || attachment.path || attachment.url)"
                        type="button"
                        class="attachment-thumb"
                        :class="{ 'is-deleted': attachment.deleted || !attachment.url }"
                        @click="previewAttachment(attachment)"
                      >
                        <img v-if="attachment.url" :src="attachment.url" :alt="attachment.name || '附件'" />
                        <span v-else>已删除</span>
                      </button>
                    </div>
                  </template>
                </div>

                <div v-if="!message.recalled && !message.isInitial" class="message-actions">
                  <t-button size="small" variant="text" theme="primary" @click="handleQuote(message)">引用</t-button>
                  <t-button
                    v-if="canRecall(message)"
                    size="small"
                    variant="text"
                    theme="danger"
                    :loading="recallLoading === message.id"
                    @click="handleRecall(message.id)"
                  >
                    撤回
                  </t-button>
                </div>
              </div>
            </article>

            <t-empty v-if="messages.length === 0" description="暂无沟通记录" />
          </div>

          <section v-if="!isClosed(detail.status)" class="reply-composer">
            <div v-if="quoteReply" class="composer-quote">
              <div>
                <span>回复 {{ quoteReply.sender_name }}</span>
                <strong>{{ quoteReply.content || '无文字内容' }}</strong>
              </div>
              <t-button size="small" variant="text" theme="default" @click="cancelQuote">取消引用</t-button>
            </div>

            <t-textarea
              v-model="replyForm.content"
              :autosize="{ minRows: 3, maxRows: 6 }"
              :maxlength="10000"
              placeholder="输入回复内容，或只上传图片后发送"
            />

            <div class="composer-actions">
              <t-upload
                v-model="uploadFiles"
                theme="image"
                accept="image/jpeg,image/png,image/webp"
                multiple
                :max="MAX_TICKET_IMAGES"
                :auto-upload="true"
                :size-limit="{ size: 5, unit: 'MB', message: '单张图片不能超过 5MB' }"
                :request-method="handleUpload"
                :before-upload="beforeUpload"
                :on-remove="handleUploadRemove"
                :on-preview="handleUploadPreview"
              >
                <t-button variant="outline" :disabled="replyUploadDisabled || replyLoading">
                  <template #icon><upload-icon /></template>
                  上传图片
                </t-button>
              </t-upload>

              <t-button theme="primary" :loading="replyLoading" :disabled="replySubmitDisabled" @click="handleReply">
                发送
              </t-button>
            </div>
          </section>

          <t-alert v-else theme="warning" message="此工单已关闭，不能继续回复。" />
        </t-card>

        <aside class="conversation-side">
          <t-card :bordered="false" header="工单信息">
            <t-descriptions :column="1" bordered>
              <t-descriptions-item label="提交用户">
                <t-button variant="text" theme="primary" @click="goUserDetail(detail.user?.id || detail.user_id)">
                  {{ userName }}
                </t-button>
              </t-descriptions-item>
              <t-descriptions-item label="工单分类">{{ departmentLabel(detail.department) }}</t-descriptions-item>
              <t-descriptions-item label="优先级">
                <t-tag :theme="priorityTheme(detail.priority)" variant="light">{{
                  priorityLabel(detail.priority)
                }}</t-tag>
              </t-descriptions-item>
              <t-descriptions-item label="处理人">{{ assigneeName }}</t-descriptions-item>
              <t-descriptions-item label="关联服务">{{ linkedServiceId }}</t-descriptions-item>
              <t-descriptions-item label="创建时间">{{ formatDateTime(detail.created_at) }}</t-descriptions-item>
              <t-descriptions-item v-if="detail.close_reason_label" label="关闭原因">
                {{ detail.close_reason_label }}
              </t-descriptions-item>
            </t-descriptions>

            <div v-if="!isClosed(detail.status)" class="assign-box">
              <t-select v-model="assignForm.assignee_id" clearable filterable placeholder="选择处理人">
                <t-option
                  v-for="admin in adminUsers"
                  :key="admin.id"
                  :label="adminOptionLabel(admin)"
                  :value="admin.id"
                />
              </t-select>
              <t-button theme="primary" :loading="assignLoading" @click="handleAssign">保存指派</t-button>
            </div>
          </t-card>

          <t-card :bordered="false" header="自动转发">
            <t-alert
              theme="info"
              message="自动化转发通常需要 1 分钟左右执行完成，请稍候查看状态。"
              class="upstream-delivery-tip"
            />
            <div class="upstream-delivery-head">
              <t-tag :theme="upstreamDeliveryTheme" variant="light">
                {{ upstreamDelivery?.status_label || '未配置' }}
              </t-tag>
              <div class="upstream-delivery-actions">
                <t-button
                  v-if="upstreamDelivery?.upstream_ticket_id"
                  size="small"
                  variant="text"
                  theme="primary"
                  :loading="callbackRegistrationLoading"
                  @click="registerUpstreamCallback"
                >
                  重新注册回调
                </t-button>
                <t-button size="small" variant="text" theme="primary" @click="openDeliveryLogs">
                  查看转发日志
                </t-button>
              </div>
            </div>
            <t-descriptions :column="1" bordered>
              <t-descriptions-item label="上游工单号">
                {{ upstreamDelivery?.upstream_ticket_id || '--' }}
              </t-descriptions-item>
              <t-descriptions-item label="尝试次数">
                {{ upstreamDelivery?.attempts ?? 0 }}
              </t-descriptions-item>
              <t-descriptions-item label="最近尝试">
                {{ upstreamDelivery?.last_attempt_at || '--' }}
              </t-descriptions-item>
              <t-descriptions-item
                v-if="upstreamDelivery?.last_error || upstreamDelivery?.last_event?.message"
                label="最近结果"
              >
                <span class="upstream-delivery-error">
                  {{ upstreamDelivery.last_error || upstreamDelivery.last_event?.message }}
                </span>
              </t-descriptions-item>
            </t-descriptions>
          </t-card>

          <t-card :bordered="false" header="关联服务信息">
            <div class="service-grid">
              <div>
                <span>商品名称</span>
                <button
                  type="button"
                  class="user-link"
                  :disabled="!Number(linkedServiceId || 0)"
                  @click="goLinkedService"
                >
                  <strong>{{ linkedServiceDisplayName }}</strong>
                </button>
              </div>
              <div>
                <span>公网 IP</span>
                <button type="button" @click="copyText(linkedServiceConnection.dedicated_ip)">
                  {{ linkedServiceConnection.dedicated_ip || '--' }}
                </button>
              </div>
              <div>
                <span>登录账号</span>
                <button type="button" @click="copyText(linkedServiceConnection.username)">
                  {{ linkedServiceConnection.username || '--' }}
                </button>
              </div>
              <div>
                <span>登录密码</span>
                <button type="button" @click="toggleLinkedServicePassword">
                  {{ linkedServicePassword }}
                </button>
              </div>
              <div>
                <span>登录端口</span>
                <strong>{{ linkedServiceConnection.port || '--' }}</strong>
              </div>
              <div>
                <span>到期时间</span>
                <strong>{{ detail.service?.expires_at || '--' }}</strong>
              </div>
            </div>

            <div class="service-specs">
              <div v-for="item in linkedServiceSpecs" :key="item.label" class="service-spec">
                <span>{{ item.label }}</span>
                <strong>{{ item.value }}</strong>
              </div>
              <t-empty v-if="linkedServiceSpecs.length === 0" description="暂无规格信息" />
            </div>
          </t-card>
        </aside>
      </div>

      <t-empty v-else-if="!detailLoading" description="工单不存在">
        <t-button theme="primary" @click="goBack">返回工单列表</t-button>
      </t-empty>
    </t-loading>

    <t-drawer
      v-model:visible="deliveryLogsVisible"
      header="工单转发日志"
      size="620px"
      @confirm="closeDeliveryLogs"
      @cancel="closeDeliveryLogs"
    >
      <t-loading :loading="deliveryLogsLoading" size="small">
        <t-empty v-if="deliveryLogs.length === 0" description="暂无转发日志" />
        <div v-else class="delivery-log-list">
          <article v-for="log in deliveryLogs" :key="String(log.id)" class="delivery-log-item">
            <div class="delivery-log-meta">
              <t-tag :theme="deliveryLogTheme(log.status)" variant="light">{{ log.status_label || log.status }}</t-tag>
              <span>{{ log.operation || '--' }}</span>
              <time>{{ log.occurred_at || '--' }}</time>
            </div>
            <strong>{{ log.message || log.reason_code || '状态已更新' }}</strong>
            <small v-if="log.attempt">第 {{ log.attempt }} 次尝试</small>
          </article>
        </div>
      </t-loading>
    </t-drawer>

    <t-dialog v-model:visible="previewVisible" header="图片预览" width="720px" :footer="false">
      <img v-if="previewUrl" class="preview-image" :src="previewUrl" alt="图片预览" />
      <div class="preview-dialog-actions">
        <t-button variant="outline" @click="previewVisible = false">
          <template #icon><close-circle-icon /></template>
          关闭
        </t-button>
      </div>
    </t-dialog>
  </div>
</template>
<script setup lang="ts">
import './index.less';

import { ChevronLeftIcon, CloseCircleIcon, UploadIcon } from 'tdesign-icons-vue-next';
import { DialogPlugin, MessagePlugin } from 'tdesign-vue-next';
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';

import type {
  TicketAdminUser,
  TicketAttachment,
  TicketDetail,
  TicketReply,
  TicketUpstreamDeliveryLog,
} from '@/api/admin';
import { adminApi } from '@/api/admin';
import { formatDateTime } from '@/utils/format';
import { errorMessage } from '@/utils/userMessage';

const MAX_TICKET_IMAGES = 9;
const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

interface UploadFileLike {
  name?: string;
  size?: number;
  type?: string;
  raw?: File;
  rawFile?: File;
  file?: File;
  response?: TicketAttachment;
  url?: string;
  path?: string;
  [key: string]: unknown;
}

type ConversationMessage = TicketReply & {
  messageKey: string;
  isInitial?: boolean;
};

const route = useRoute();
const router = useRouter();

const detailLoading = ref(false);
const detail = ref<TicketDetail | null>(null);
const adminUsers = ref<TicketAdminUser[]>([]);
const assignForm = reactive<{ assignee_id: number | string | null }>({ assignee_id: null });
const assignLoading = ref(false);
const closeLoading = ref(false);
const recallLoading = ref<number | string | null>(null);
const replyLoading = ref(false);
const uploadFiles = ref<UploadFileLike[]>([]);
const replyAttachments = ref<TicketAttachment[]>([]);
const previewVisible = ref(false);
const previewUrl = ref('');
const quoteReply = ref<{ id: number | string; sender_name: string; content: string } | null>(null);
const replyForm = reactive({ content: '' });
const linkedServicePasswordVisible = ref(false);
const deliveryLogsVisible = ref(false);
const deliveryLogsLoading = ref(false);
const callbackRegistrationLoading = ref(false);
const deliveryLogs = ref<TicketUpstreamDeliveryLog[]>([]);

const priorityOptions = [
  { label: '低', value: 1 },
  { label: '中', value: 2 },
  { label: '高', value: 3 },
  { label: '紧急', value: 4 },
];

const departmentOptions = [
  { label: '销售', value: 'sales' },
  { label: '技术支持', value: 'support' },
  { label: '财务', value: 'billing' },
  { label: '投诉', value: 'abuse' },
];

const messages = computed<ConversationMessage[]>(() => {
  if (!detail.value) return [];
  const list: ConversationMessage[] = [];
  if (detail.value.content || parseAttachments(detail.value).length) {
    list.push({
      id: `initial-${detail.value.id}`,
      messageKey: `initial-${detail.value.id}`,
      content: detail.value.content,
      is_staff: 0,
      sender_name: userName.value,
      attachments: parseAttachments(detail.value),
      created_at: detail.value.created_at,
      isInitial: true,
    });
  }
  return list.concat(
    (detail.value.replies || []).map((reply) => ({
      ...reply,
      messageKey: String(reply.id),
    })),
  );
});

const userName = computed(() => {
  const user = detail.value?.user || {};
  return String(user.display_name || user.nickname || user.email || `用户 #${detail.value?.user_id || '--'}`);
});

const assigneeName = computed(() => {
  const assignee = detail.value?.assignee || {};
  return String(assignee.nickname || assignee.username || '未指派');
});

const linkedServiceId = computed(() => detail.value?.service?.id || detail.value?.service_id || '--');
const linkedServiceDisplayName = computed(
  () =>
    detail.value?.service?.display_name || detail.value?.service?.product_name || detail.value?.service?.name || '--',
);
const linkedServiceConnection = computed(() => ({
  dedicated_ip: '',
  internal_ip: '',
  username: '',
  password: '',
  has_password: false,
  port: '',
  ...(detail.value?.service?.connection || {}),
}));
const linkedServicePassword = computed(() =>
  linkedServiceConnection.value.has_password
    ? linkedServicePasswordVisible.value
      ? linkedServiceConnection.value.password || '******'
      : '******'
    : '--',
);
const linkedServiceSpecs = computed(() => {
  const specs = detail.value?.service?.specs;
  if (!Array.isArray(specs)) return [];
  return specs
    .filter((item) => item && (item.label || item.name))
    .map((item) => ({
      label: String(item.label || item.name),
      value: String(item.value ?? item.text ?? '--'),
    }));
});
const currentAssigneeId = computed(() => (detail.value?.assignee_id ? String(detail.value.assignee_id) : ''));
const upstreamDelivery = computed(() => detail.value?.upstream_delivery || null);
const upstreamDeliveryTheme = computed<'default' | 'success' | 'warning' | 'danger'>(() => {
  const status = upstreamDelivery.value?.status;
  if (status === 'delivered') return 'success';
  if (status === 'failed') return 'danger';
  if (status === 'pending' || status === 'sending') return 'warning';
  return 'default';
});
const replyUploadDisabled = computed(() => replyAttachments.value.length >= MAX_TICKET_IMAGES);
const replySubmitDisabled = computed(
  () => replyLoading.value || (!replyForm.content.trim() && replyAttachments.value.length === 0),
);

function resolveTicketId() {
  return String(route.params.id || '');
}

function priorityLabel(value: unknown) {
  return priorityOptions.find((item) => item.value === Number(value))?.label || '--';
}

function priorityTheme(value: unknown): 'default' | 'success' | 'warning' | 'danger' {
  const number = Number(value);
  if (number === 2) return 'success';
  if (number === 3) return 'warning';
  if (number === 4) return 'danger';
  return 'default';
}

function departmentLabel(value: unknown) {
  return departmentOptions.find((item) => item.value === value)?.label || String(value || '--');
}

function messageSenderName(message: ConversationMessage) {
  if (message.sender_type === 'upstream_admin' || (Number(message.user_id) === 0 && Number(message.is_staff) === 1)) {
    return '上游客服';
  }

  return message.sender_name || (message.is_staff ? '客服' : '客户');
}

function adminOptionLabel(admin: TicketAdminUser) {
  const name = admin.nickname || admin.username || `管理员 #${admin.id}`;
  return admin.email ? `${name}(${admin.email})` : name;
}

function isClosed(status: unknown) {
  return Number(status) === 3;
}

function deliveryLogTheme(status?: string): 'default' | 'success' | 'warning' | 'danger' {
  if (status === 'delivered') return 'success';
  if (status === 'failed') return 'danger';
  if (status === 'pending' || status === 'sending') return 'warning';
  return 'default';
}

async function registerUpstreamCallback() {
  const id = resolveTicketId();
  if (!id || callbackRegistrationLoading.value) return;
  callbackRegistrationLoading.value = true;
  try {
    await adminApi.tickets.registerUpstreamCallback(id);
    MessagePlugin.success('上游工单回调已重新注册');
    await reloadCurrentDetail();
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '重新注册回调失败'));
  } finally {
    callbackRegistrationLoading.value = false;
  }
}

async function openDeliveryLogs() {
  const id = resolveTicketId();
  if (!id) return;
  deliveryLogsVisible.value = true;
  deliveryLogsLoading.value = true;
  try {
    const response = await adminApi.tickets.upstreamDeliveryLogs(id, { page: 1, page_size: 100 });
    deliveryLogs.value = response.list || [];
  } catch (error) {
    deliveryLogs.value = [];
    MessagePlugin.error(errorMessage(error, '加载工单转发日志失败'));
  } finally {
    deliveryLogsLoading.value = false;
  }
}

function closeDeliveryLogs() {
  deliveryLogsVisible.value = false;
}

function parseAttachments(
  item: { attachments?: TicketAttachment[]; attachment_urls?: Array<string | TicketAttachment> } | null,
) {
  const attachments = item?.attachments || item?.attachment_urls || [];
  return attachments
    .map((attachment, index) => {
      if (typeof attachment === 'string') {
        return { id: index, url: attachment, path: attachment, name: '图片', type: 'image' } satisfies TicketAttachment;
      }
      return attachment;
    })
    .filter(Boolean);
}

function normalizeAttachmentPayload() {
  return replyAttachments.value
    .map((item) => item.path)
    .filter((path): path is string => typeof path === 'string' && path.trim() !== '');
}

async function loadAdmins() {
  try {
    const response = await adminApi.tickets.adminUsers();
    adminUsers.value = Array.isArray(response) ? response : response.list || [];
  } catch (error) {
    adminUsers.value = [];
    MessagePlugin.error(errorMessage(error, '加载管理员列表失败'));
  }
}

async function loadDetail() {
  const id = resolveTicketId();
  if (!id) {
    await router.replace('/admin/tickets');
    return;
  }

  detailLoading.value = true;
  detail.value = null;
  deliveryLogs.value = [];
  deliveryLogsVisible.value = false;
  linkedServicePasswordVisible.value = false;
  resetReplyDraft();
  try {
    const response = await adminApi.tickets.detail(id);
    detail.value = response;
    assignForm.assignee_id = response.assignee_id || null;
  } catch (error) {
    detail.value = null;
    MessagePlugin.error(errorMessage(error, '加载工单详情失败'));
  } finally {
    detailLoading.value = false;
  }
}

async function reloadCurrentDetail() {
  if (!detail.value?.id) return;
  const response = await adminApi.tickets.detail(detail.value.id);
  detail.value = response;
  linkedServicePasswordVisible.value = false;
  assignForm.assignee_id = response.assignee_id || null;
}

function toggleLinkedServicePassword() {
  if (!linkedServiceConnection.value.has_password) return;
  linkedServicePasswordVisible.value = !linkedServicePasswordVisible.value;
}

function goBack() {
  router.push('/admin/tickets');
}

function goUserDetail(userId: unknown) {
  if (!userId) return;
  router.push(`/admin/users/${userId}`);
}

function goLinkedService() {
  const id = Number(linkedServiceId.value || 0);
  if (!id) return;
  router.push({
    path: `/admin/services/${id}`,
    query: { user: String(detail.value?.user_id || detail.value?.user?.id || '') },
  });
}

async function copyText(text: unknown) {
  const value = String(text || '').trim();
  if (!value || value === '--' || !navigator?.clipboard) return;
  await navigator.clipboard.writeText(value);
  MessagePlugin.success('已复制');
}

async function handleAssign() {
  if (!detail.value?.id) return;
  if (!assignForm.assignee_id) {
    MessagePlugin.warning('请选择处理人');
    return;
  }
  if (String(assignForm.assignee_id) === currentAssigneeId.value) {
    MessagePlugin.warning('处理人未发生变化');
    return;
  }

  assignLoading.value = true;
  try {
    await adminApi.tickets.assign(detail.value.id, { assignee_id: assignForm.assignee_id });
    MessagePlugin.success('指派成功');
    await reloadCurrentDetail();
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '指派失败'));
  } finally {
    assignLoading.value = false;
  }
}

async function handleReply() {
  if (!detail.value?.id) return;
  if (!replyForm.content.trim() && replyAttachments.value.length === 0) {
    MessagePlugin.warning('请输入回复内容或上传图片');
    return;
  }

  replyLoading.value = true;
  try {
    await adminApi.tickets.reply(detail.value.id, {
      content: replyForm.content.trim(),
      attachments: normalizeAttachmentPayload(),
      quote_reply_id: quoteReply.value?.id,
    });
    resetReplyDraft();
    MessagePlugin.success('回复成功');
    await reloadCurrentDetail();
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '回复失败'));
  } finally {
    replyLoading.value = false;
  }
}

function handleClose() {
  if (!detail.value?.id) return;
  const ticketId = detail.value.id;
  const dialog = DialogPlugin.confirm({
    header: '关闭工单',
    body: '确认关闭该工单吗？关闭后图片会被物理删除。',
    theme: 'warning',
    confirmBtn: '确认关闭',
    cancelBtn: '取消',
    async onConfirm() {
      closeLoading.value = true;
      try {
        await adminApi.tickets.close(ticketId);
        MessagePlugin.success('工单已关闭');
        dialog.hide();
        await reloadCurrentDetail();
      } catch (error) {
        MessagePlugin.error(errorMessage(error, '关闭失败'));
      } finally {
        closeLoading.value = false;
      }
    },
  });
}

function canRecall(reply: TicketReply) {
  if (!reply || reply.recalled || !reply.is_staff || !reply.created_at) return false;
  const created = new Date(String(reply.created_at).replace(/-/g, '/')).getTime();
  return !Number.isNaN(created) && Date.now() - created <= 120_000;
}

async function handleRecall(replyId: number | string) {
  if (!detail.value?.id) return;
  recallLoading.value = replyId;
  try {
    await adminApi.tickets.recall(detail.value.id, replyId);
    MessagePlugin.success('消息已撤回');
    await reloadCurrentDetail();
  } catch (error) {
    MessagePlugin.error(errorMessage(error, '撤回失败'));
  } finally {
    recallLoading.value = null;
  }
}

function handleQuote(reply: TicketReply) {
  if (!reply || reply.recalled) return;
  quoteReply.value = {
    id: reply.id,
    sender_name: reply.sender_name || '用户',
    content: reply.content || '',
  };
}

function cancelQuote() {
  quoteReply.value = null;
}

function resetReplyDraft() {
  replyForm.content = '';
  quoteReply.value = null;
  replyAttachments.value = [];
  uploadFiles.value = [];
}

function beforeUpload(file: UploadFileLike) {
  if (replyAttachments.value.length >= MAX_TICKET_IMAGES) {
    MessagePlugin.warning(`最多上传 ${MAX_TICKET_IMAGES} 张图片`);
    return false;
  }
  if (file.type && !IMAGE_TYPES.includes(file.type)) {
    MessagePlugin.warning('仅支持 jpg、png、webp 图片');
    return false;
  }
  return true;
}

async function handleUpload(files: UploadFileLike | UploadFileLike[]) {
  const file = Array.isArray(files) ? files[0] : files;
  const rawFile = file.raw || file.rawFile || file.file;
  if (!(rawFile instanceof File)) {
    return {
      status: 'fail' as const,
      error: '未获取到有效图片文件',
      response: {},
    };
  }

  const formData = new FormData();
  formData.append('file', rawFile, rawFile.name);

  try {
    const response = await adminApi.tickets.uploadImage(formData);
    replyAttachments.value = [...replyAttachments.value, response].slice(0, MAX_TICKET_IMAGES);
    return {
      status: 'success' as const,
      response: {
        ...response,
        url: response.url || undefined,
      },
    };
  } catch (error) {
    return {
      status: 'fail' as const,
      error: errorMessage(error, '上传失败'),
      response: {},
    };
  }
}

function handleUploadRemove(context: { file?: UploadFileLike; index?: number }) {
  const file = context.file || {};
  const path = file.response?.path || file.path;
  if (path) {
    replyAttachments.value = replyAttachments.value.filter((item) => item.path !== path);
    return;
  }
  if (typeof context.index === 'number') {
    replyAttachments.value.splice(context.index, 1);
  }
}

function handleUploadPreview(context: { file?: UploadFileLike }) {
  const url = context.file?.response?.url || context.file?.url;
  if (url) {
    previewUrl.value = url;
    previewVisible.value = true;
  }
}

function previewAttachment(attachment: TicketAttachment) {
  if (!attachment.url) return;
  previewUrl.value = attachment.url;
  previewVisible.value = true;
}

onMounted(() => {
  void Promise.allSettled([loadAdmins(), loadDetail()]);
});
</script>
