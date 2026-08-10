import { request } from '@/utils/request';

import type { TicketAdminUser, TicketAttachment, TicketDetail, TicketListParams, TicketRecord } from './types';

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
};
