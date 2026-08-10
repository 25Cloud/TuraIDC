import type { AxiosRequestConfig } from 'axios';

import type {
  ApiEnvelope,
  ClientAlipayAccount,
  ClientAuthSessionPayload,
  ClientNotificationPreferences,
  ClientUserInfo,
  ClientVerificationPayload,
} from '@/types/client';
import request from '@/utils/request';

type RequestConfig = AxiosRequestConfig & { silentError?: boolean };

const SILENT_ERROR_CONFIG: RequestConfig = { silentError: true };

function getEnvelope<T>(url: string, config?: RequestConfig) {
  return request.get<ApiEnvelope<T>, ApiEnvelope<T>>(url, config);
}

function postEnvelope<T>(url: string, data?: Record<string, unknown>, config?: RequestConfig) {
  return request.post<ApiEnvelope<T>, ApiEnvelope<T>>(url, data, config);
}

function putEnvelope<T>(url: string, data?: Record<string, unknown>) {
  return request.put<ApiEnvelope<T>, ApiEnvelope<T>>(url, data);
}

export const clientAuthApi = {
  login: (data: Record<string, unknown>) => postEnvelope<ClientAuthSessionPayload>('/v2/client/login', data),
  loginByCode: (data: Record<string, unknown>) =>
    postEnvelope<ClientAuthSessionPayload>('/v2/client/auth/login-by-code', data),
  register: (data: Record<string, unknown>) => postEnvelope<ClientAuthSessionPayload>('/v2/client/register', data),
  exchangeLoginAsCode: (data: Record<string, unknown>) =>
    postEnvelope<ClientAuthSessionPayload>('/v2/client/auth/login-as/exchange', data),
  captchaConfig: () => request.get('/v2/client/auth/captcha-config'),
  info: () => getEnvelope<ClientUserInfo>('/v2/client/auth/info'),
  updateProfile: (data: Record<string, unknown>) => request.put('/v2/client/auth/profile', data),
  changePassword: (data: Record<string, unknown>) => request.put('/v2/client/password', data),
  updatePhone: (data: Record<string, unknown>) => request.put('/v2/client/auth/phone', data),
  updateEmail: (data: Record<string, unknown>) => request.put('/v2/client/auth/email', data),
  alipayAccount: () => getEnvelope<ClientAlipayAccount>('/v2/client/auth/alipay-account'),
  updateAlipayAccount: (data: Record<string, unknown>) =>
    putEnvelope<ClientAlipayAccount>('/v2/client/auth/alipay-account', data),
  initVerification: (data: Record<string, unknown>) =>
    postEnvelope<ClientVerificationPayload>('/v2/client/verification/init', data, SILENT_ERROR_CONFIG),
  verificationQrcode: (data: Record<string, unknown>) =>
    postEnvelope<ClientVerificationPayload>('/v2/client/verification/qrcode', data, SILENT_ERROR_CONFIG),
  closeVerificationSession: (data: Record<string, unknown>) =>
    postEnvelope<ClientVerificationPayload>('/v2/client/verification/close', data, SILENT_ERROR_CONFIG),
  verificationStatus: (params?: Record<string, unknown>) =>
    getEnvelope<ClientVerificationPayload>('/v2/client/verification/status', { params, ...SILENT_ERROR_CONFIG }),
  restartVerification: () =>
    postEnvelope<ClientVerificationPayload>('/v2/client/verification/restart', undefined, SILENT_ERROR_CONFIG),
  verificationFeeConfig: () => request.get('/v2/client/verification/fee-config'),
  notificationPreferences: () => getEnvelope<ClientNotificationPreferences>('/v2/client/auth/notification-preferences'),
  updateNotificationPreferences: (data: Record<string, unknown>) =>
    putEnvelope<ClientNotificationPreferences>('/v2/client/auth/notification-preferences', data),
  sendPhoneCode: (data: Record<string, unknown>) => request.post('/v2/client/auth/phone-code', data),
  sendEmailCode: (data: Record<string, unknown>) => request.post('/v2/client/auth/email-code', data),
  resetPassword: (data: Record<string, unknown>) => request.post('/v2/client/auth/reset-password', data),
  logout: () => request.post('/v2/client/auth/logout'),
};
