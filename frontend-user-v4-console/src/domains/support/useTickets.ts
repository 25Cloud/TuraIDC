import { getStatusLabel, SERVICE_STATUS_MAP } from '@shared/statusConfig';
import { DialogPlugin, MessagePlugin } from 'tdesign-vue-next';
import { computed, reactive, ref, shallowRef } from 'vue';
import { useRoute, useRouter } from 'vue-router';

import clientApi from '@/api/client';
import { t } from '@/locales';
import type {
  TicketAttachment,
  TicketImageUploadPayload,
  TicketRecord,
  TicketReplyRecord,
  TicketServiceOption,
} from '@/types/client';

type ServiceOption = TicketServiceOption;

export const TICKET_STATUS_OPTIONS = [
  { label: t('ticket.replyStatus.open'), value: 0 },
  { label: t('ticket.replyStatus.customerReplied'), value: 1 },
  { label: t('ticket.replyStatus.staffReplied'), value: 2 },
  { label: t('ticket.replyStatus.closed'), value: 3 },
];

export const TICKET_DEPARTMENT_OPTIONS = [
  { label: t('ticket.department.sales'), value: 'sales' },
  { label: t('ticket.department.support'), value: 'support' },
  { label: t('ticket.department.billing'), value: 'billing' },
  { label: t('ticket.department.abuse'), value: 'abuse' },
];

export const TICKET_PRIORITY_OPTIONS = [
  { label: t('ticket.priority.low'), value: 1 },
  { label: t('ticket.priority.medium'), value: 2 },
  { label: t('ticket.priority.high'), value: 3 },
  { label: t('ticket.priority.urgent'), value: 4 },
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
    MessagePlugin.warning(t('ticket.image.unsupportedType'));
    return false;
  }
  if (file.size > MAX_SIZE) {
    MessagePlugin.warning(t('ticket.image.tooLarge'));
    return false;
  }
  if (currentCount >= MAX_IMAGES) {
    MessagePlugin.warning(t('ticket.maxImages', { count: MAX_IMAGES }));
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
      MessagePlugin.error(getErrorMessage(error, t('ticket.errors.listLoadFailed')));
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
      MessagePlugin.error(getErrorMessage(error, t('ticket.errors.servicesLoadFailed')));
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
      MessagePlugin.success(t('ticket.image.uploaded'));
      return true;
    } catch (error: unknown) {
      MessagePlugin.error(getErrorMessage(error, t('ticket.errors.imageUploadFailed')));
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
      MessagePlugin.warning(t('ticket.create.titleRequired'));
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
      MessagePlugin.success(t('ticket.create.success'));
      createVisible.value = false;
      resetCreateForm();
      await loadTickets();
      return true;
    } catch (error: unknown) {
      MessagePlugin.error(getErrorMessage(error, t('ticket.errors.submitFailed')));
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
    String(
      detail.value?.user?.display_name ||
        detail.value?.user?.nickname ||
        detail.value?.user?.email ||
        t('ticket.sender.customer'),
    ),
  );
  const assigneeName = computed(() =>
    String(detail.value?.assignee?.nickname || detail.value?.assignee?.username || t('ticket.unassigned')),
  );
  const isClosed = computed(() => Number(detail.value?.status) === 3);
  const canSubmitReply = computed(() => replyContent.value.trim().length > 0 || replyAttachments.value.length > 0);
  const titleText = computed(() => String(detail.value?.subject || t('ticket.detail.title')));

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
      MessagePlugin.error(t('ticket.detail.notFound'));
      await router.replace('/client/tickets');
      return;
    }

    loading.value = true;
    try {
      const res = await clientApi.ticketDetail(ticketId.value);
      detail.value = res.data || null;
      if (!detail.value) await router.replace('/client/tickets');
    } catch (error: unknown) {
      MessagePlugin.error(getErrorMessage(error, t('ticket.errors.detailLoadFailed')));
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
      MessagePlugin.success(t('ticket.image.uploaded'));
      return true;
    } catch (error: unknown) {
      MessagePlugin.error(getErrorMessage(error, t('ticket.errors.imageUploadFailed')));
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
      MessagePlugin.warning(t('ticket.reply.uploading'));
      return false;
    }
    if (!canSubmitReply.value) {
      MessagePlugin.warning(t('ticket.reply.contentRequired'));
      return false;
    }
    if (!ticketId.value) return false;

    replying.value = true;
    try {
      await clientApi.replyTicket(ticketId.value, {
        content: replyContent.value,
        attachments: replyAttachments.value.map((item) => item.path).filter(Boolean),
      });
      MessagePlugin.success(t('ticket.reply.sent'));
      resetReplyDraft();
      await loadDetail();
      return true;
    } catch (error: unknown) {
      MessagePlugin.error(getErrorMessage(error, t('ticket.errors.replyFailed')));
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
      MessagePlugin.success(t('ticket.reply.recalled'));
      await loadDetail();
      return true;
    } catch (error: unknown) {
      MessagePlugin.error(getErrorMessage(error, t('ticket.errors.recallFailed')));
      return false;
    } finally {
      recalling.value = false;
    }
  }

  function closeTicket() {
    if (!ticketId.value) return;
    const dialog = DialogPlugin.confirm({
      header: t('ticket.close.confirmTitle'),
      body: t('ticket.close.confirmBody'),
      confirmBtn: t('ticket.close.confirm'),
      cancelBtn: t('common.cancel'),
      theme: 'warning',
      async onConfirm() {
        dialog.setConfirmLoading(true);
        closing.value = true;
        try {
          await clientApi.closeTicket(ticketId.value);
          MessagePlugin.success(t('ticket.close.success'));
          await loadDetail();
          dialog.hide();
        } catch (error: unknown) {
          MessagePlugin.error(getErrorMessage(error, t('ticket.errors.closeFailed')));
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
