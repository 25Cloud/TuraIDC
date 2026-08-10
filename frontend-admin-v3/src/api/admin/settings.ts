import { request } from '@/utils/request';

import type {
  NotificationTemplateItem,
  NotificationTemplateTestSendPayload,
  NotificationTemplateTestSendResponse,
  ScheduleOverview,
  SettingItem,
} from './types';

type V2SettingItem = SettingItem & {
  sensitive?: boolean;
  configured?: boolean;
  display_value?: string | number | boolean | null;
};

interface V2SettingListResponse {
  list?: V2SettingItem[];
  total?: number;
  page?: number;
  page_size?: number;
}

interface NotificationTemplateListResponse {
  list?: NotificationTemplateItem[];
  total?: number;
}

function normalizeV2Setting(item: V2SettingItem): SettingItem {
  return {
    ...item,
    is_secret: Boolean(item.is_secret ?? item.sensitive),
    has_value: Boolean(item.has_value ?? item.configured),
    masked_value: item.masked_value ?? item.display_value ?? '',
  };
}

export const settingsApi = {
  list: (params?: Record<string, unknown>) =>
    request.get<V2SettingListResponse>({ url: '/v2/admin/settings', params }).then((response) => ({
      ...response,
      list: Array.isArray(response.list) ? response.list.map((item) => normalizeV2Setting(item)) : [],
    })),
  notificationTemplates: (params?: { channel?: 'email' | 'sms' }) =>
    request
      .get<NotificationTemplateListResponse>({ url: '/v2/admin/notification-templates', params })
      .then((response) => ({
        ...response,
        list: Array.isArray(response.list) ? response.list : [],
      })),
  testNotificationTemplateSend: (data: NotificationTemplateTestSendPayload) =>
    request.post<NotificationTemplateTestSendResponse>({ url: '/v2/admin/notification-templates/test-send', data }),
  save: (data: Record<string, unknown>) => request.post({ url: '/v2/admin/settings', data }),
  revealSecret: (group: string, key: string) =>
    request.get<{ group: string; key: string; value: unknown }>({
      url: `/v2/admin/settings/${encodeURIComponent(group)}/secrets/${encodeURIComponent(key)}`,
    }),
};

export const schedulesApi = {
  overview: () => request.get<ScheduleOverview>({ url: '/v2/admin/schedules/overview' }),
  trigger: (data: { task: string }) =>
    request.post<Record<string, unknown>>({ url: '/v2/admin/schedule-triggers', data }).then((response) => {
      const detail = (response as { detail?: { task?: Record<string, unknown> } }).detail;
      return (detail?.task || response) as Record<string, unknown>;
    }),
};
