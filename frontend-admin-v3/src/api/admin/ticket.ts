import { request } from '@/utils/request';

import type {
  TicketAdminUser,
  TicketAttachment,
  TicketDeliveryDepartmentsResponse,
  TicketDeliveryRulePayload,
  TicketDeliveryRuleRecord,
  TicketDeliveryRulesResponse,
  TicketDetail,
  TicketListParams,
  TicketRecord,
  TicketUpstreamDeliveryLogsResponse,
  TicketUpstreamUploadGuardConfig,
  TicketUpstreamUploadGuardPayload,
} from './types';

interface TicketV2DetailPayload {
  ticket?: TicketDetail | null;
}

interface TicketV2RepliesPayload {
  list?: TicketDetail['replies'];
  total?: number;
  page?: number;
  page_size?: number;
}

interface TicketV2AdminUsersPayload {
  list?: TicketAdminUser[];
}

interface TicketV2UploadPayload {
  attachment?: TicketAttachment;
}

async function v2TicketDetail(id: number | string): Promise<TicketDetail> {
  const [detailPayload, repliesPayload] = await Promise.all([
    request.get<TicketV2DetailPayload>({ url: `/v2/admin/tickets/${id}` }),
    request.get<TicketV2RepliesPayload>({
      url: `/v2/admin/tickets/${id}/replies`,
      params: { page: 1, page_size: 100 },
    }),
  ]);
  const ticket = detailPayload.ticket || ({} as TicketDetail);

  return {
    ...ticket,
    replies: repliesPayload.list || [],
  };
}

export const ticketsApi = {
  list: (params: TicketListParams) =>
    request.get<{ list?: TicketRecord[]; total?: number; page?: number; page_size?: number }>({
      url: '/v2/admin/tickets',
      params,
    }),
  summary: () => request.get<Record<string, unknown>>({ url: '/v2/admin/tickets/summary' }),
  detail: (id: number | string) => v2TicketDetail(id),
  upstreamDeliveryLogs: (id: number | string, params: { page?: number; page_size?: number } = {}) =>
    request.get<TicketUpstreamDeliveryLogsResponse>({
      url: `/v2/admin/tickets/${id}/upstream-delivery/logs`,
      params,
    }),
  registerUpstreamCallback: (id: number | string) =>
    request.post({ url: `/v2/admin/tickets/${id}/upstream-delivery/callback-registration` }),
  adminUsers: () => request.get<TicketV2AdminUsersPayload>({ url: '/v2/admin/tickets/admin-users' }),
  close: (id: number | string) => request.post({ url: `/v2/admin/tickets/${id}/closures` }),
  assign: (id: number | string, data: { assignee_id?: number | string | null }) =>
    request.put({ url: `/v2/admin/tickets/${id}/assignment`, data }),
  reply: (id: number | string, data: { content?: string; attachments?: string[]; quote_reply_id?: number | string }) =>
    request.post({ url: `/v2/admin/tickets/${id}/replies`, data }),
  recall: (id: number | string, replyId: number | string) =>
    request.post({ url: `/v2/admin/tickets/${id}/replies/${replyId}/recalls` }),
  uploadImage: (data: FormData) =>
    request
      .post<TicketV2UploadPayload>({
        url: '/v2/admin/tickets/upload-images',
        data,
        headers: { 'Content-Type': 'multipart/form-data' },
      })
      .then((response) => response.attachment || {}),
  deliveryDepartments: (supplierId: number | string) =>
    request.get<TicketDeliveryDepartmentsResponse>({
      url: '/v2/admin/ticket-delivery-departments',
      params: { supplier_id: supplierId },
    }),
  deliveryRules: {
    list: () => request.get<TicketDeliveryRulesResponse>({ url: '/v2/admin/ticket-delivery-rules' }),
    create: (data: TicketDeliveryRulePayload) =>
      request.post<TicketDeliveryRuleRecord>({ url: '/v2/admin/ticket-delivery-rules', data }),
    update: (id: number | string, data: TicketDeliveryRulePayload) =>
      request.put<TicketDeliveryRuleRecord>({ url: `/v2/admin/ticket-delivery-rules/${id}`, data }),
    delete: (id: number | string) => request.delete({ url: `/v2/admin/ticket-delivery-rules/${id}` }),
  },
  uploadGuard: {
    config: () => request.get<TicketUpstreamUploadGuardConfig>({ url: '/v2/admin/ticket-delivery-upload-guard' }),
    save: (data: TicketUpstreamUploadGuardPayload) =>
      request.post<TicketUpstreamUploadGuardConfig>({ url: '/v2/admin/ticket-delivery-upload-guard', data }),
  },
};
