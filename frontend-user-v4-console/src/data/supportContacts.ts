import { t } from '@/locales';

export const DEFAULT_SUPPORT_CONTACTS = Object.freeze({
  qqGroup: t('client.support.pendingGroupNo'),
  // 合作邮箱与服务时间由后台「基础信息」配置（basic.service_email / basic.service_hours）。
  // 未配置时留空并整行隐藏，不再回落到写死的示例邮箱与服务时段。
  email: '',
  hours: '',
  groupTitle: t('client.support.joinGroupTitle'),
  groupText: t('client.support.joinGroupText'),
  groupQr: '',
});

function resolveValue(value: unknown, fallback: string) {
  const normalized = String(value ?? '').trim();
  return normalized || fallback;
}

export function buildSupportContacts(config: Record<string, unknown> = {}) {
  const contacts = [
    {
      key: 'qq-group',
      label: t('client.support.officialQqGroup'),
      value: resolveValue(
        config.service_qq_group ?? config.serviceQqGroup ?? config.service_phone ?? config.servicePhone,
        DEFAULT_SUPPORT_CONTACTS.qqGroup,
      ),
    },
    {
      key: 'cooperation-email',
      label: t('client.support.cooperationEmail'),
      value: resolveValue(config.service_email ?? config.serviceEmail, DEFAULT_SUPPORT_CONTACTS.email),
    },
    {
      key: 'service-hours',
      label: t('client.support.serviceHours'),
      value: resolveValue(config.service_hours ?? config.serviceHours, DEFAULT_SUPPORT_CONTACTS.hours),
    },
  ];

  // 未配置的联系方式整行隐藏，避免出现空值行。
  return contacts.filter((item) => item.value !== '');
}
