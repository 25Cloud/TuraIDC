import { request } from '@/utils/request';

export interface AdminLoginPayload {
  username?: string;
  account?: string;
  password?: string;
  [key: string]: unknown;
}

export interface AdminProfilePayload {
  nickname?: string;
}

export interface AdminPasswordPayload {
  current_password: string;
  password: string;
  password_confirmation: string;
}

export const adminAuthApi = {
  login: (data: AdminLoginPayload) => request.post({ url: '/v2/admin/login', data }),
  info: () => request.get({ url: '/v2/admin/auth/info' }),
  updateProfile: (data: AdminProfilePayload) => request.put({ url: '/v2/admin/auth/profile', data }),
  updatePassword: (data: AdminPasswordPayload) => request.put({ url: '/v2/admin/auth/password', data }),
  logout: () => request.post({ url: '/v2/admin/auth/logout' }),
};
