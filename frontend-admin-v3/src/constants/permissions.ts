export const AdminPermissions = {
  ALL: '*',
  DASHBOARD_VIEW: 'dashboard.view',
  USER_LIST: 'user.list',
  USER_DETAIL: 'user.detail',
  USER_MANAGE: 'user.manage',
  USER_LOGIN_AS: 'user.login_as',
  USER_RECHARGE: 'user.recharge',
  VERIFICATION_LIST: 'verification.list',
  VERIFICATION_UNBIND: 'verification.unbind',
  ORDER_LIST: 'order.list',
  ORDER_DETAIL: 'order.detail',
  ORDER_MANAGE: 'order.manage',
  INVOICE_LIST: 'invoice.list',
  INVOICE_DETAIL: 'invoice.detail',
  INVOICE_MANAGE: 'invoice.manage',
  TICKET_LIST: 'ticket.list',
  TICKET_REPLY: 'ticket.reply',
  TICKET_MANAGE: 'ticket.manage',
  TICKET_DELIVERY_MANAGE: 'ticket.delivery_manage',
  TICKET_PRE_REPLY_MANAGE: 'ticket.pre_reply_manage',
  PRODUCT_LIST: 'product.list',
  PRODUCT_MANAGE: 'product.manage',
  PRODUCT_SYNC: 'product.sync',
  SUPPLIER_LIST: 'supplier.list',
  SUPPLIER_DETAIL: 'supplier.detail',
  SUPPLIER_MANAGE: 'supplier.manage',
  SUPPLIER_SYNC: 'supplier.sync',
  SUPPLIER_SECRET_REVEAL: 'supplier.secret_reveal',
  SETTINGS_VIEW: 'settings.view',
  SETTINGS_MANAGE: 'settings.manage',
  SETTINGS_SECRET_REVEAL: 'settings.secret_reveal',
  DATABASE_VIEW: 'database.view',
  DATABASE_MANAGE: 'database.manage',
  INTEGRATION_PLUGIN_VIEW: 'integration_plugin.view',
  INTEGRATION_PLUGIN_MANAGE: 'integration_plugin.manage',
  INTEGRATION_PLUGIN_TEST: 'integration_plugin.test',
  INTEGRATION_PLUGIN_SECRET_REVEAL: 'integration_plugin.secret_reveal',
  SCHEDULE_VIEW: 'schedule.view',
  SCHEDULE_TRIGGER: 'schedule.trigger',
  SCHEDULE_RETRY: 'schedule.retry',
  SITE_VIEW: 'site.view',
  SITE_MANAGE: 'site.manage',
  LOG_LIST: 'log.list',
  LOG_MANAGE: 'log.manage',
  REFERRAL_LIST: 'referral.list',
  FINANCE_REPORT: 'finance.report',
  FINANCE_WITHDRAW: 'finance.withdraw',
  REFERRAL_WITHDRAWAL_LIST: 'referral_withdrawal.list',
  MEMBER_LEVEL_LIST: 'member_level.list',
  MEMBER_LEVEL_MANAGE: 'member_level.manage',
  AGENT_DISCOUNT_LIST: 'agent_discount.list',
  AGENT_DISCOUNT_MANAGE: 'agent_discount.manage',
  OPEN_API_VIEW: 'open_api.view',
  OPEN_API_MANAGE: 'open_api.manage',
  CONTENT_LIST: 'content.list',
  CONTENT_MANAGE: 'content.manage',
  STAFF_LIST: 'staff.list',
  STAFF_MANAGE: 'staff.manage',
  ROLE_LIST: 'role.list',
  ROLE_MANAGE: 'role.manage',
  PERMISSION_LIST: 'permission.list',
  PRIVACY_VIEW_RAW: 'privacy.view_raw',
} as const;

export type AdminPermissionCode = (typeof AdminPermissions)[keyof typeof AdminPermissions];

export const BUILTIN_ROLE_CODES = ['super_admin', 'admin', 'visitor'] as const;

export type BuiltinRoleCode = (typeof BUILTIN_ROLE_CODES)[number];

export const BUILTIN_ROLE_LABELS: Record<BuiltinRoleCode, string> = {
  super_admin: '超级管理员',
  admin: '普通管理员',
  visitor: '访客',
};

export const VISITOR_PERMISSION_CODES = [
  AdminPermissions.DASHBOARD_VIEW,
  AdminPermissions.USER_LIST,
  AdminPermissions.USER_DETAIL,
  AdminPermissions.VERIFICATION_LIST,
  AdminPermissions.ORDER_LIST,
  AdminPermissions.ORDER_DETAIL,
  AdminPermissions.INVOICE_LIST,
  AdminPermissions.INVOICE_DETAIL,
  AdminPermissions.TICKET_LIST,
  AdminPermissions.PRODUCT_LIST,
  AdminPermissions.SUPPLIER_LIST,
  AdminPermissions.SUPPLIER_DETAIL,
  AdminPermissions.SETTINGS_VIEW,
  AdminPermissions.INTEGRATION_PLUGIN_VIEW,
  AdminPermissions.SCHEDULE_VIEW,
  AdminPermissions.SITE_VIEW,
  AdminPermissions.LOG_LIST,
  AdminPermissions.REFERRAL_LIST,
  AdminPermissions.REFERRAL_WITHDRAWAL_LIST,
  AdminPermissions.FINANCE_REPORT,
  AdminPermissions.MEMBER_LEVEL_LIST,
  // 这里曾多给访客一条 AGENT_DISCOUNT_LIST，后端 AdminPermissions::visitorPermissions() 没有它。
  // 结果是访客能看到代理折扣菜单，点进去三条 GET（agent-groups / product-discount-groups /
  // agent-group-discounts）一律 403。以后端为准删掉；普通管理员不受影响——
  // 它的 AGENT_DISCOUNT_MANAGE 本就隐含 AGENT_DISCOUNT_LIST。
  AdminPermissions.CONTENT_LIST,
  AdminPermissions.STAFF_LIST,
  AdminPermissions.ROLE_LIST,
  AdminPermissions.PERMISSION_LIST,
] as const;

export const ADMIN_DEFAULT_PERMISSION_CODES = [
  ...VISITOR_PERMISSION_CODES,
  AdminPermissions.USER_MANAGE,
  AdminPermissions.VERIFICATION_UNBIND,
  AdminPermissions.ORDER_MANAGE,
  AdminPermissions.INVOICE_MANAGE,
  AdminPermissions.TICKET_REPLY,
  AdminPermissions.TICKET_MANAGE,
  AdminPermissions.TICKET_DELIVERY_MANAGE,
  AdminPermissions.TICKET_PRE_REPLY_MANAGE,
  AdminPermissions.PRODUCT_MANAGE,
  AdminPermissions.PRODUCT_SYNC,
  AdminPermissions.SUPPLIER_MANAGE,
  AdminPermissions.SUPPLIER_SYNC,
  AdminPermissions.SITE_MANAGE,
  AdminPermissions.REFERRAL_LIST,
  AdminPermissions.MEMBER_LEVEL_MANAGE,
  AdminPermissions.AGENT_DISCOUNT_MANAGE,
  AdminPermissions.OPEN_API_VIEW,
  AdminPermissions.OPEN_API_MANAGE,
  AdminPermissions.CONTENT_MANAGE,
] as const;

export function isBuiltinRoleCode(value?: string | null): value is BuiltinRoleCode {
  return BUILTIN_ROLE_CODES.includes(String(value || '') as BuiltinRoleCode);
}

export function impliedPermissions(permission: string): string[] {
  switch (permission) {
    case AdminPermissions.USER_MANAGE:
      return [AdminPermissions.USER_LIST, AdminPermissions.USER_DETAIL];
    case AdminPermissions.ORDER_MANAGE:
      return [AdminPermissions.ORDER_LIST, AdminPermissions.ORDER_DETAIL];
    case AdminPermissions.INVOICE_MANAGE:
      return [AdminPermissions.INVOICE_LIST, AdminPermissions.INVOICE_DETAIL];
    case AdminPermissions.TICKET_MANAGE:
      return [AdminPermissions.TICKET_LIST, AdminPermissions.TICKET_REPLY];
    case AdminPermissions.PRODUCT_MANAGE:
    case AdminPermissions.PRODUCT_SYNC:
      return [AdminPermissions.PRODUCT_LIST];
    case AdminPermissions.SUPPLIER_DETAIL:
      return [AdminPermissions.SUPPLIER_LIST];
    case AdminPermissions.SUPPLIER_MANAGE:
    case AdminPermissions.SUPPLIER_SYNC:
      return [AdminPermissions.SUPPLIER_LIST, AdminPermissions.SUPPLIER_DETAIL];
    case AdminPermissions.SETTINGS_MANAGE:
      return [AdminPermissions.SETTINGS_VIEW];
    case AdminPermissions.DATABASE_MANAGE:
      return [AdminPermissions.DATABASE_VIEW];
    case AdminPermissions.INTEGRATION_PLUGIN_MANAGE:
    case AdminPermissions.INTEGRATION_PLUGIN_TEST:
      return [AdminPermissions.INTEGRATION_PLUGIN_VIEW];
    case AdminPermissions.SCHEDULE_TRIGGER:
    case AdminPermissions.SCHEDULE_RETRY:
      return [AdminPermissions.SCHEDULE_VIEW];
    case AdminPermissions.SITE_MANAGE:
      return [AdminPermissions.SITE_VIEW];
    case AdminPermissions.LOG_MANAGE:
      return [AdminPermissions.LOG_LIST];
    case AdminPermissions.MEMBER_LEVEL_MANAGE:
      return [AdminPermissions.MEMBER_LEVEL_LIST];
    case AdminPermissions.AGENT_DISCOUNT_MANAGE:
      return [AdminPermissions.AGENT_DISCOUNT_LIST];
    case AdminPermissions.OPEN_API_MANAGE:
      return [AdminPermissions.OPEN_API_VIEW];
    case AdminPermissions.CONTENT_MANAGE:
      return [AdminPermissions.CONTENT_LIST];
    case AdminPermissions.STAFF_MANAGE:
      return [AdminPermissions.STAFF_LIST];
    case AdminPermissions.ROLE_MANAGE:
      return [AdminPermissions.ROLE_LIST, AdminPermissions.PERMISSION_LIST];
    default:
      return [];
  }
}

export function permissionImplies(granted: string, required: string): boolean {
  return granted === AdminPermissions.ALL || granted === required || impliedPermissions(granted).includes(required);
}

export function hasPermissionInList(permissions: string[] | undefined | null, required: string): boolean {
  return (permissions || []).some((permission) => permissionImplies(permission, required));
}
