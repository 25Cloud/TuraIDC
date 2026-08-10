import { request } from '@/utils/request';

import type { MemberLevelPayload, MemberLevelRecord } from './types';

interface MemberLevelListResponse {
  list?: MemberLevelRecord[];
}

export const memberLevelsApi = {
  list: async () => {
    const response = await request.get<MemberLevelListResponse>({ url: '/v2/admin/member-levels' });
    return response.list || [];
  },
  create: (data: MemberLevelPayload) => request.post<MemberLevelRecord>({ url: '/v2/admin/member-levels', data }),
  update: (id: number | string, data: MemberLevelPayload) =>
    request.put<MemberLevelRecord>({ url: `/v2/admin/member-levels/${id}`, data }),
  delete: (id: number | string) => request.delete({ url: `/v2/admin/member-levels/${id}` }),
};
