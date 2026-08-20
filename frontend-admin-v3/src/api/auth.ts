import { request } from '@/utils/request';

export interface AdminCaptchaPayload {
  lot_number?: string;
  captcha_output?: string;
  pass_token?: string;
  gen_time?: string;
  token?: string;
  knock?: string;
  dfu?: string;
  provider?: string;
  [key: string]: string | undefined;
}

export interface AdminLoginPayload {
  username?: string;
  account?: string;
  password?: string;
  captcha?: AdminCaptchaPayload;
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
  login: (data: AdminLoginPayload) =>
    request.post({
      url: '/v2/admin/login',
      data,
      requestOptions: {
        // 登录失败不重试，避免重复请求触发后端限流（throttle:5,1）
        retry: { count: 0, delay: 1000 },
        withToken: false,
      },
    }),
  info: () => request.get({ url: '/v2/admin/auth/info' }),
  updateProfile: (data: AdminProfilePayload) => request.put({ url: '/v2/admin/auth/profile', data }),
  updatePassword: (data: AdminPasswordPayload) => request.put({ url: '/v2/admin/auth/password', data }),
  logout: () => request.post({ url: '/v2/admin/auth/logout' }),
};
