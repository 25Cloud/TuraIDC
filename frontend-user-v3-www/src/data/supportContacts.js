export const DEFAULT_SUPPORT_CONTACTS = Object.freeze({
  qqGroup: '待补充群号',
  email: 'support@example.com',
  hours: '工作日 9:00 - 22:00',
  groupTitle: '加入官方群聊',
  groupText: '官方群聊用于发布维护通知、活动消息和常见问题答疑，欢迎扫码加入。',
  groupQr: '',
})

function resolveValue(value, fallback) {
  const normalized = String(value ?? '').trim()
  return normalized || fallback
}

export function buildSupportContacts(config = {}) {
  return [
    {
      key: 'qq-group',
      label: '官方QQ群',
      value: resolveValue(
        config.service_qq_group ?? config.serviceQqGroup ?? config.service_phone ?? config.servicePhone,
        DEFAULT_SUPPORT_CONTACTS.qqGroup,
      ),
    },
    {
      key: 'cooperation-email',
      label: '合作邮箱',
      value: resolveValue(config.service_email ?? config.serviceEmail, DEFAULT_SUPPORT_CONTACTS.email),
    },
    {
      key: 'service-hours',
      label: '服务时间',
      value: resolveValue(config.service_hours ?? config.serviceHours, DEFAULT_SUPPORT_CONTACTS.hours),
    },
  ]
}
