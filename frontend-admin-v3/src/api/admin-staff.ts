import { request } from '@/utils/request';

import type { PagedListParams } from './types';

export interface StaffListParams extends PagedListParams {
  status?: number | string;
  role_id?: number | string;
}

export interface StaffRoleOption {
  id: number | string;
  name?: string;
  label?: string;
}

export interface StaffRecord {
  id: number | string;
  username?: string;
  nickname?: string;
  email?: string;
  status?: number | string;
  role_id?: number | string | null;
  role?: StaffRoleOption | null;
  role_label?: string;
  permissions?: string[];
  last_login_at?: string | null;
  last_login_ip?: string;
  created_at?: string | null;
  updated_at?: string | null;
}

export interface StaffPayload {
  username?: string;
  nickname?: string | null;
  email?: string | null;
  role_id: number | string;
  status: number;
}

export interface CreateStaffPayload extends StaffPayload {
  password: string;
}

export const adminStaffApi = {
  list: (params: StaffListParams) =>
    request.get<{ list?: StaffRecord[]; total?: number; page?: number; page_size?: number }>({
      url: '/v2/admin/staff',
      params,
    }),
  detail: (id: number | string) => request.get<StaffRecord>({ url: `/v2/admin/staff/${id}` }),
  roles: () => request.get<{ list?: StaffRoleOption[] }>({ url: '/v2/admin/staff/roles' }),
  create: (data: CreateStaffPayload) => request.post<StaffRecord>({ url: '/v2/admin/staff', data }),
  update: (id: number | string, data: StaffPayload) => request.put<StaffRecord>({ url: `/v2/admin/staff/${id}`, data }),
  toggleStatus: (id: number | string, enabled: boolean) =>
    request.patch({ url: `/v2/admin/staff/${id}/status`, data: { enabled } }),
  resetPassword: (id: number | string, data: { password: string; password_confirmation: string }) =>
    request.post({ url: `/v2/admin/staff/${id}/password-resets`, data }),
  delete: (id: number | string) => request.delete({ url: `/v2/admin/staff/${id}` }),
};
