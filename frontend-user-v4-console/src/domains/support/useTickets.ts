import { getStatusLabel, SERVICE_STATUS_MAP } from '@shared/statusConfig';
import { DialogPlugin, MessagePlugin } from 'tdesign-vue-next';
import { computed, reactive, ref, shallowRef } from 'vue';
import { useRoute, useRouter } from 'vue-router';

import clientApi from '@/api/client';
import type {
  TicketAttachment,
  TicketImageUploadPayload,
  TicketRecord,
  TicketReplyRecord,
  TicketServiceOption,
} from '@/types/client';

type ServiceOption = TicketServiceOption;

export const TICKET_STATUS_OPTIONS = [
  { label: '开启', value: 0 },
  { label: '客户回复', value: 1 },
  { label: '员工回复', value: 2 },
  { label: '已关闭', value: 3 },
];

export const TICKET_DEPARTMENT_OPTIONS = [
  { label: '销售', value: 'sales' },
  { label: '技术支持', value: 'support' },
  { label: '财务', value: 'billing' },
  { label: '投诉', value: 'abuse' },
];

export const TICKET_PRIORITY_OPTIONS = [
  { label: '低', value: 1 },
  { label: '中', value: 2 },
  { label: '高', value: 3 },
  { label: '紧急', value: 4 },
];

const MAX_IMAGES = 9;
const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
const MAX_SIZE = 5 * 1024 * 1024;

function getErrorMessage(error: unknown, fallback: string) {
  return error instanceof Error && error.message ? error.message : fallback;
}

function normalizeList(data: unknown): TicketRecord[] {
  if (data && typeof data === 'object' && Array.isArray((data as { list?: unknown[] }).list)) {
    return (data as { list: TicketRecord[] }).list;
  }
  if (Array.isArray(data)) return data;
  return [];
}

export function formatTicketTime(value: unknown) {
  const raw = String(value || '').trim();
  if (!raw) return '--';
  const date = new Date(raw);
  if (Number.isNaN(date.getTime())) return raw.slice(0, 16).replace('T', ' ');
  const pad = (num: number) => String(num).padStart(2, '0');
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} ${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

export function resolveTicketStatusLabel(value: unknown) {
  return TICKET_STATUS_OPTIONS.find((item) => Number(item.value) === Number(value))?.label || '--';
}

export function resolveTicketTagTheme(value: unknown) {
  if (Number(value) === 3) return 'default';
  if (Number(value) === 2) return 'success';
  if (Number(value) === 1) return 'warning';
  return 'primary';
}

export function resolvePriorityLabel(value: unknown) {
  return TICKET_PRIORITY_OPTIONS.find((item) => Number(item.value) === Number(value))?.label || '--';
}

export function resolvePriorityTheme(value: unknown) {
  if (Number(value) >= 4) return 'danger';
  if (Number(value) === 3) return 'warning';
  return 'default';
}

export function resolveDepartmentLabel(value: unknown) {
  return TICKET_DEPARTMENT_OPTIONS.find((item) => String(item.value) === String(value))?.label || '--';
}

export function parseAttachments(item: TicketRecord | TicketReplyRecord | null | undefined) {
  const attachments = item?.attachments || item?.attachment_urls || [];
  if (!Array.isArray(attachments)) return [];
  return attachments
    .map((attachment: TicketAttachment | string, index) => {
      if (typeof attachment === 'string') return { id: index, url: attachment, path: attachment };
      return {
        id: attachment?.id || attachment?.uid || index,
        url: attachment?.url || attachment?.path || '',
        path: attachment?.path || attachment?.url || '',
      };
    })
    .filter((item) => item.url);
}

function resolveServiceName(option?: ServiceOption) {
  const rawName = String(option?.name || option?.display_name || option?.product_name || '').trim();
  if (!rawName) return '';
  const parts = rawName
    .split('/')
    .map((item) => item.trim())
    .filter(Boolean);
  return parts.length >= 2 ? parts.at(-1) : rawName;
}

export function formatTicketServiceOptionLabel(option?: ServiceOption, includeStatus = true) {
  const id = Number(option?.id || 0);
  const name = resolveServiceName(option);
  if (id <= 0 || !name) return '--';
  const status = getStatusLabel(SERVICE_STATUS_MAP, Number(option?.status));
  return includeStatus && status ? `#${id}-${name}-${status}` : `#${id}-${name}`;
}

function validateImage(file: File, currentCount: number) {
  if (!IMAGE_TYPES.includes(file.type)) {
    MessagePlugin.warning('仅支持 jpg、png、webp 图片');
    return false;
  }
  if (file.size > MAX_SIZE) {
    MessagePlugin.warning('单张图片不能超过 5MB');
    return false;
  }
  if (currentCount >= MAX_IMAGES) {
    MessagePlugin.warning(`最多上传 ${MAX_IMAGES} 张图片`);
    return false;
  }
  return true;
}

export function useTicketList() {
  const router = useRouter();
  const loading = ref(false);
  const creating = ref(false);
  const serviceLoading = ref(false);
  const createVisible = ref(false);
  const uploading = ref(false);
  const list = ref<TicketRecord[]>([]);
  const total = ref(0);
  const serviceOptions = ref<ServiceOption[]>([]);
  const uploadFiles = ref<TicketImageUploadPayload[]>([]);
  const previewVisible = ref(false);
  const previewUrl = ref('');
  const filters = reactive({
    keyword: '',
    status: undefined as number | undefined,
    page: 1,
    page_size: 10,
  });
  const createForm = reactive({
    department: 'support',
    subject: '',
    service_id: undefined as number | undefined,
    priority: 2,
    content: '',
  });

  const serviceSelectOptions = computed(() =>
    serviceOptions.value.map((item) => ({
      label: formatTicketServiceOptionLabel(item),
      value: item.id,
    })),
  );

  function resetCreateForm() {
    createForm.department = 'support';
    createForm.subject = '';
    createForm.service_id = undefined;
    createForm.priority = 2;
    createForm.content = '';
    uploadFiles.value = [];
  }

  async function loadTickets() {
    loading.value = true;
    try {
      const res = await clientApi.tickets({
        page: filters.page,
        page_size: filters.page_size,
        keyword: filters.keyword || undefined,
        status: filters.status === undefined ? undefined : filters.status,
      });
      const payload = res.data;
      list.value = normalizeList(payload);
      total.value = Number(payload?.total || list.value.length || 0);
    } catch (error: unknown) {
      MessagePlugin.error(getErrorMessage(error, '工单列表加载失败'));
    } finally {
      loading.value = false;
    }
  }

  async function loadServiceOptions() {
    serviceLoading.value = true;
    try {
      const res = await clientApi.ticketServiceOptions({ limit: 50 });
      serviceOptions.value = Array.isArray(res.data) ? res.data : [];
    } catch (error: unknown) {
      MessagePlugin.error(getErrorMessage(error, '服务选项加载失败'));
    } finally {
      serviceLoading.value = false;
    }
  }

  function handleSearch() {
    filters.page = 1;
    void loadTickets();
  }

  function handlePageSizeChange(value: number) {
    filters.page_size = Number(value || 10);
    filters.page = 1;
    void loadTickets();
  }

  async function openCreateDialog() {
    resetCreateForm();
    createVisible.value = true;
    if (!serviceOptions.value.length) await loadServiceOptions();
  }

  function closeCreateDialog() {
    createVisible.value = false;
    resetCreateForm();
  }

  async function uploadTicketImage(file: File) {
    if (!validateImage(file, uploadFiles.value.length)) return false;
    uploading.value = true;
    try {
      const formData = new FormData();
      formData.append('file', file);
      const res = await clientApi.uploadTicketImage(formData);
      const payload = res.data;
      uploadFiles.value = [...uploadFiles.value, payload].slice(0, MAX_IMAGES);
      MessagePlugin.success('图片已上传');
      return true;
    } catch (error: unknown) {
      MessagePlugin.error(getErrorMessage(error, '图片上传失败'));
      return false;
    } finally {
      uploading.value = false;
    }
  }

  function removeUploadFile(index: number) {
    uploadFiles.value.splice(index, 1);
  }

  function previewUploadFile(file: TicketImageUploadPayload) {
    previewUrl.value = String(file.url || file.path || '');
    previewVisible.value = Boolean(previewUrl.value);
  }

  async function submitTicket() {
    if (!createForm.subject.trim()) {
      MessagePlugin.warning('请输入工单标题');
      return false;
    }

    creating.value = true;
    try {
      await clientApi.createTicket({
        department: createForm.department,
        subject: createForm.subject,
        content: createForm.content,
        priority: createForm.priority,
        service_id: createForm.service_id,
        attachments: uploadFiles.value.map((item) => item.path).filter(Boolean),
      });
      MessagePlugin.success('工单提交成功');
      createVisible.value = false;
      resetCreateForm();
      await loadTickets();
      return true;
    } catch (error: unknown) {
      MessagePlugin.error(getErrorMessage(error, '工单提交失败'));
      return false;
    } finally {
      creating.value = false;
    }
  }

  function openDetail(row: TicketRecord) {
    router.push(`/client/ticket-conversations/${row.id}`);
  }

  return {
    router,
    loading,
    creating,
    serviceLoading,
    createVisible,
    uploading,
    list,
    total,
    filters,
    createForm,
    serviceOptions,
    serviceSelectOptions,
    uploadFiles,
    previewVisible,
    previewUrl,
    loadTickets,
    loadServiceOptions,
    handleSearch,
    handlePageSizeChange,
    openCreateDialog,
    closeCreateDialog,
    uploadTicketImage,
    removeUploadFile,
    previewUploadFile,
    submitTicket,
    openDetail,
  };
}

export function useTicketDetail() {
  const route = useRoute();
  const router = useRouter();
  const loading = ref(false);
  const replying = ref(false);
  const closing = ref(false);
  const recalling = ref(false);
  const replyUploading = ref(false);
  const detail = shallowRef<TicketRecord | null>(null);
  const replyContent = ref('');
  const replyAttachments = ref<TicketImageUploadPayload[]>([]);
  const previewVisible = ref(false);
  const previewUrl = ref('');
  const activeMobileTab = ref<'chat' | 'detail'>('chat');

  const ticketId = computed(() => Number(route.params.id || 0));
  const userName = computed(() =>
    String(detail.value?.user?.display_name || detail.value?.user?.nickname || detail.value?.user?.email || '客户'),
  );
  const assigneeName = computed(() =>
    String(detail.value?.assignee?.nickname || detail.value?.assignee?.username || '未分配'),
  );
  const isClosed = computed(() => Number(detail.value?.status) === 3);
  const canSubmitReply = computed(() => replyContent.value.trim().length > 0 || replyAttachments.value.length > 0);
  const titleText = computed(() => String(detail.value?.subject || '工单详情'));

  function currentUserId() {
    return Number(detail.value?.user?.id || detail.value?.user_id || 0);
  }

  function canRecall(reply: TicketReplyRecord) {
    if (!reply || reply.recalled || reply.is_staff) return false;
    const ownerId = currentUserId();
    if (ownerId && Number(reply.user_id) !== ownerId) return false;
    if (!reply.created_at) return false;
    return Date.now() - new Date(reply.created_at).getTime() <= 120000;
  }

  async function loadDetail() {
    if (!ticketId.value) {
      MessagePlugin.error('工单不存在');
      await router.replace('/client/tickets');
      return;
    }

    loading.value = true;
    try {
      const res = await clientApi.ticketDetail(ticketId.value);
      detail.value = res.data || null;
      if (!detail.value) await router.replace('/client/tickets');
    } catch (error: unknown) {
      MessagePlugin.error(getErrorMessage(error, '工单详情加载失败'));
      await router.replace('/client/tickets');
    } finally {
      loading.value = false;
    }
  }

  function resetReplyDraft() {
    replyContent.value = '';
    replyAttachments.value = [];
  }

  async function uploadReplyImage(file: File) {
    if (!validateImage(file, replyAttachments.value.length)) return false;
    replyUploading.value = true;
    try {
      const formData = new FormData();
      formData.append('file', file);
      const res = await clientApi.uploadTicketImage(formData);
      replyAttachments.value = [...replyAttachments.value, res.data || {}].slice(0, MAX_IMAGES);
      MessagePlugin.success('图片已上传');
      return true;
    } catch (error: unknown) {
      MessagePlugin.error(getErrorMessage(error, '图片上传失败'));
      return false;
    } finally {
      replyUploading.value = false;
    }
  }

  function removeReplyAttachment(index: number) {
    replyAttachments.value.splice(index, 1);
  }

  function previewAttachment(file: TicketImageUploadPayload | { url?: string; path?: string }) {
    previewUrl.value = String(file.url || file.path || '');
    previewVisible.value = Boolean(previewUrl.value);
  }

  async function submitReply() {
    if (replyUploading.value) {
      MessagePlugin.warning('图片上传中，请稍后发送');
      return false;
    }
    if (!canSubmitReply.value) {
      MessagePlugin.warning('请输入回复内容或上传图片');
      return false;
    }
    if (!ticketId.value) return false;

    replying.value = true;
    try {
      await clientApi.replyTicket(ticketId.value, {
        content: replyContent.value,
        attachments: replyAttachments.value.map((item) => item.path).filter(Boolean),
      });
      MessagePlugin.success('回复已发送');
      resetReplyDraft();
      await loadDetail();
      return true;
    } catch (error: unknown) {
      MessagePlugin.error(getErrorMessage(error, '发送回复失败'));
      return false;
    } finally {
      replying.value = false;
    }
  }

  async function recallReply(reply: TicketReplyRecord) {
    if (!ticketId.value || !reply?.id) return false;
    recalling.value = true;
    try {
      await clientApi.recallTicketReply(ticketId.value, reply.id);
      MessagePlugin.success('消息已撤回');
      await loadDetail();
      return true;
    } catch (error: unknown) {
      MessagePlugin.error(getErrorMessage(error, '撤回失败'));
      return false;
    } finally {
      recalling.value = false;
    }
  }

  function closeTicket() {
    if (!ticketId.value) return;
    const dialog = DialogPlugin.confirm({
      header: '确认关闭工单',
      body: '关闭后将无法继续回复此工单，确认关闭吗？',
      confirmBtn: '确认关闭',
      cancelBtn: '取消',
      theme: 'warning',
      async onConfirm() {
        dialog.setConfirmLoading(true);
        closing.value = true;
        try {
          await clientApi.closeTicket(ticketId.value);
          MessagePlugin.success('工单已关闭');
          await loadDetail();
          dialog.hide();
        } catch (error: unknown) {
          MessagePlugin.error(getErrorMessage(error, '关闭工单失败'));
        } finally {
          closing.value = false;
          dialog.setConfirmLoading(false);
        }
      },
    });
  }

  function goBack() {
    router.push('/client/tickets');
  }

  return {
    router,
    loading,
    replying,
    closing,
    recalling,
    replyUploading,
    detail,
    replyContent,
    replyAttachments,
    previewVisible,
    previewUrl,
    activeMobileTab,
    ticketId,
    userName,
    assigneeName,
    isClosed,
    canSubmitReply,
    titleText,
    currentUserId,
    canRecall,
    loadDetail,
    resetReplyDraft,
    uploadReplyImage,
    removeReplyAttachment,
    previewAttachment,
    submitReply,
    recallReply,
    closeTicket,
    goBack,
  };
}
