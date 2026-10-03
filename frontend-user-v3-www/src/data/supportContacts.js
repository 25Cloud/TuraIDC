export const DEFAULT_SUPPORT_CONTACTS = Object.freeze({
  qqGroup: '待补充群号',
  // 合作邮箱与服务时间由后台「基础信息」配置（basic.service_email / basic.service_hours）。
  // 未配置时留空并整行隐藏，不再回落到写死的示例邮箱与服务时段。
  email: '',
  hours: '',
  groupTitle: '加入官方群聊',
  groupText: '官方群聊用于发布维护通知、活动消息和常见问题答疑，欢迎扫码加入。',
  groupQr: '',
})

function resolveValue(value, fallback) {
  const normalized = String(value ?? '').trim()
  return normalized || fallback
}

export function buildSupportContacts(config = {}) {
  const contacts = [
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

  // 未配置的联系方式整行隐藏，避免页脚出现空值行。
  return contacts.filter((item) => item.value !== '')
}
