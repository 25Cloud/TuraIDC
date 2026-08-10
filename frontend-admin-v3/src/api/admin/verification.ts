import { request } from '@/utils/request';

import type { VerificationListParams, VerificationRecord } from './types';

export const verificationsApi = {
  list: (params: VerificationListParams) =>
    request.get<{ list?: VerificationRecord[]; total?: number; page?: number; page_size?: number }>({
      url: '/v2/admin/verifications',
      params,
    }),
  summary: () =>
    request.get<{
      stats?: Record<string, number>;
      config?: Record<string, unknown>;
    }>({ url: '/v2/admin/verifications/summary' }),
  detail: (id: number | string) => request.get<VerificationRecord>({ url: `/v2/admin/verifications/${id}` }),
  history: (id: number | string) =>
    request.get<{ user_name?: string; list?: VerificationRecord[] }>({
      url: `/v2/admin/verifications/${id}/history`,
    }),
  unbind: (id: number | string, data: { reject_reason: string }) =>
    request.post({ url: `/v2/admin/verifications/${id}/unbindings`, data }),
};
