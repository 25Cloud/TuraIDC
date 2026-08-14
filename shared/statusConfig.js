/**
 * ============================================================
 *  IDC 财务系统 —— 全局状态颜色 & 映射配置
 * ============================================================
 *  多个前端共用此文件
 *  引入方式：import { ORDER_STATUS, ... } from '@turaidc/shared/statusConfig'
 *
 *  颜色体系说明：
 *  ┌──────────┬────────────┬───────────────────────────────┐
 *  │  语义     │  色值       │  用途                          │
 *  ├──────────┼────────────┼───────────────────────────────┤
 *  │ warning  │ #E6A23C    │ 待支付/待付款 — 需要行动        │
 *  │ blue     │ #409EFF    │ 已支付/已付款 — 流程中          │
 *  │ primary  │ #409EFF    │ 开通中 — 处理中(带呼吸动画)     │
 *  │ success  │ #67C23A    │ 已开通/已完成 — 正向完结        │
 *  │ info     │ #909399    │ 已关闭/已取消/已到期 — 终态      │
 *  │ danger   │ #F56C6C    │ 已退款 — 资金逆向              │
 *  │ purple   │ #8B5CF6    │ 已冻结/已暂停 — 异常锁定        │
 *  └──────────┴────────────┴───────────────────────────────┘
 *
 *  tagType 字段为 UI 框架无关的语义值，各前端自行映射到组件属性：
 *  - TDesign 端：通过 toTagTypeMap() 直接用作 t-tag theme
 *  - Element Plus 端：通过 resolveElTagType() 转换后用作 el-tag type
 * ============================================================
 */

// ===================== 颜色常量 =====================

export const STATUS_COLORS = {
  warning: '#E6A23C',   // 待支付
  blue:    '#409EFF',   // 已支付 / 开通中
  success: '#67C23A',   // 已开通 / 已完成
  info:    '#909399',   // 已关闭 / 已取消 / 已到期
  danger:  '#F56C6C',   // 已退款
  purple:  '#8B5CF6',   // 已冻结 / 已暂停
}

// tagType 语义值（UI 框架无关）
// 各前端按需映射到组件属性
export const STATUS_TAG_TYPES = {
  warning: 'warning',
  blue:    '',          // 默认/primary
  success: 'success',
  info:    'info',
  danger:  'danger',
  purple:  'purple',    // 需要配合自定义样式
}

// ===================== 购买状态（历史订单状态） =====================

export const ORDER_STATUS = {
  PENDING:    0,  // 待付款
  PAID:       1,  // 已付款
  PROCESSING: 2,  // 开通中
  COMPLETED:  3,  // 已完成
  CANCELLED:  4,  // 已取消
  REFUNDED:   5,  // 已退款
}

export const ORDER_STATUS_MAP = {
  [ORDER_STATUS.PENDING]:    { label: '待付款', color: STATUS_COLORS.warning, tagType: 'warning',  icon: 'Clock'         },
  [ORDER_STATUS.PAID]:       { label: '已付款', color: STATUS_COLORS.blue,    tagType: '',         icon: 'CreditCard'    },
  [ORDER_STATUS.PROCESSING]: { label: '开通中', color: STATUS_COLORS.blue,    tagType: '',         icon: 'Loading',      pulse: true },
  [ORDER_STATUS.COMPLETED]:  { label: '已完成', color: STATUS_COLORS.success, tagType: 'success',  icon: 'CircleCheck'   },
  [ORDER_STATUS.CANCELLED]:  { label: '已取消', color: STATUS_COLORS.info,    tagType: 'info',     icon: 'CircleClose'   },
  [ORDER_STATUS.REFUNDED]:   { label: '已退款', color: STATUS_COLORS.danger,  tagType: 'danger',   icon: 'RefreshLeft'   },
}

// 购买类型（历史订单类型）
export const ORDER_TYPE_MAP = {
  new:     '新购',
  renew:   '续费',
  upgrade: '附加配置',
}

// 账单类型
export const INVOICE_TYPE_MAP = {
  new:             '新购',
  normal:          '新购',
  renew:           '续费',
  recharge:        '充值',
  upgrade:         '附加配置',
  deduction:       '扣款',
  referral_credit: '推荐奖励账单',
  manual:          '手工账单',
}

// ===================== 账单/发票状态 =====================

export const INVOICE_STATUS = {
  UNPAID:    0,  // 待支付
  PAID:      1,  // 已支付
  CANCELLED: 2,  // 已取消
  OVERDUE:   3,  // 已逾期
  REFUNDED:  5,  // 已退款
}

export const INVOICE_STATUS_MAP = {
  [INVOICE_STATUS.UNPAID]:    { label: '待支付', color: STATUS_COLORS.warning, tagType: 'warning', icon: 'Clock'       },
  [INVOICE_STATUS.PAID]:      { label: '已支付', color: STATUS_COLORS.success, tagType: 'success', icon: 'Select'      },
  [INVOICE_STATUS.CANCELLED]: { label: '已取消', color: STATUS_COLORS.info,    tagType: 'info',    icon: 'CircleClose' },
  [INVOICE_STATUS.OVERDUE]:   { label: '已逾期', color: STATUS_COLORS.danger,  tagType: 'danger',  icon: 'WarningFilled'},
  [INVOICE_STATUS.REFUNDED]:  { label: '已退款', color: STATUS_COLORS.danger,  tagType: 'danger',  icon: 'RefreshLeft' },
}

// ===================== 第三方支付状态 =====================

export const PAYMENT_STATUS = {
  PENDING:  0,  // 待支付
  PAID:     1,  // 成功
  FAILED:   2,  // 失败
  REFUNDED: 3,  // 已退款
  CANCELLED: 4, // 已取消
}

export const PAYMENT_STATUS_MAP = {
  [PAYMENT_STATUS.PENDING]:  { label: '待支付', color: STATUS_COLORS.warning, tagType: 'warning', icon: 'Clock'       },
  [PAYMENT_STATUS.PAID]:     { label: '成功',   color: STATUS_COLORS.success, tagType: 'success', icon: 'Select'      },
  [PAYMENT_STATUS.FAILED]:   { label: '失败',   color: STATUS_COLORS.danger,  tagType: 'danger',  icon: 'CircleClose' },
  [PAYMENT_STATUS.REFUNDED]: { label: '已退款', color: STATUS_COLORS.info,    tagType: 'info',    icon: 'RefreshLeft' },
  [PAYMENT_STATUS.CANCELLED]: { label: '已取消', color: STATUS_COLORS.info, tagType: 'info', icon: 'CircleClose' },
}

// ===================== 服务/产品实例状态 =====================

export const SERVICE_STATUS = {
  PENDING:   0,  // 开通中
  ACTIVE:    1,  // 已开通
  SUSPENDED: 2,  // 已暂停（冻结）
  EXPIRED:   3,  // 已到期
  CANCELLED: 4,  // 已取消
}

export const SERVICE_STATUS_MAP = {
  [SERVICE_STATUS.PENDING]:   { label: '开通中', color: STATUS_COLORS.warning, tagType: 'warning', icon: 'Clock'       },
  [SERVICE_STATUS.ACTIVE]:    { label: '已开通', color: STATUS_COLORS.success, tagType: 'success', icon: 'CircleCheck' },
  [SERVICE_STATUS.SUSPENDED]: { label: '已暂停', color: STATUS_COLORS.purple,  tagType: 'purple',  icon: 'Lock'        },
  [SERVICE_STATUS.EXPIRED]:   { label: '已到期', color: STATUS_COLORS.info,    tagType: 'info',    icon: 'Timer'       },
  [SERVICE_STATUS.CANCELLED]: { label: '已取消', color: STATUS_COLORS.info,    tagType: 'info',    icon: 'CircleClose' },
}

// ===================== 工单状态 =====================

export const TICKET_STATUS = {
  OPEN:        0,  // 开启（待处理）
  USER_REPLY:  1,  // 客户回复
  STAFF_REPLY: 2,  // 员工回复
  CLOSED:      3,  // 已关闭
}

export const TICKET_STATUS_MAP = {
  [TICKET_STATUS.OPEN]:        { label: '开启',   color: STATUS_COLORS.warning, tagType: 'warning', icon: 'ChatDotRound'   },
  [TICKET_STATUS.USER_REPLY]:  { label: '客户回复', color: STATUS_COLORS.danger,  tagType: 'danger',  icon: 'Comment'        },
  [TICKET_STATUS.STAFF_REPLY]: { label: '员工回复', color: STATUS_COLORS.success, tagType: 'success', icon: 'ChatLineSquare' },
  [TICKET_STATUS.CLOSED]:      { label: '已关闭', color: STATUS_COLORS.info,    tagType: 'info',    icon: 'Finished'       },
}

// ===================== 邮件/通知状态 =====================

export const NOTIFY_STATUS = {
  PENDING: 'pending',
  SUCCESS: 'success',
  FAILED:  'failed',
}

export const NOTIFY_STATUS_MAP = {
  [NOTIFY_STATUS.PENDING]: { label: '待发送', color: STATUS_COLORS.warning, tagType: 'warning', icon: 'Clock'     },
  [NOTIFY_STATUS.SUCCESS]: { label: '成功',   color: STATUS_COLORS.success, tagType: 'success', icon: 'Select'    },
  [NOTIFY_STATUS.FAILED]:  { label: '失败',   color: STATUS_COLORS.danger,  tagType: 'danger',  icon: 'CloseBold' },
}

// ===================== 佣金奖励状态 =====================

export const REWARD_STATUS = {
  FROZEN:   0,  // 冻结中
  RELEASED: 1,  // 已释放
  REVOKED:  2,  // 已撤销
}

export const REWARD_STATUS_MAP = {
  [REWARD_STATUS.FROZEN]:   { label: '冻结中', color: STATUS_COLORS.purple,  tagType: 'purple',  icon: 'Lock'        },
  [REWARD_STATUS.RELEASED]: { label: '已释放', color: STATUS_COLORS.success, tagType: 'success', icon: 'CircleCheck' },
  [REWARD_STATUS.REVOKED]:  { label: '已回退', color: STATUS_COLORS.info,    tagType: 'info',    icon: 'CircleClose' },
}

// ===================== 交易流水类型 =====================

export const TRANSACTION_TYPE_MAP = {
  recharge:  '充值',
  consume:   '消费',
  refund:    '退款',
  withdraw:  '提现',
  commission:'佣金',
  adjust:    '调账',
}

export const FINANCE_LEDGER_EVENT_MAP = {
  invoice_payment: { label: '账单支付', tagType: 'danger', direction: 'out' },
  invoice_refund: { label: '账单退款', tagType: 'success', direction: 'in' },
  recharge: { label: '充值到账', tagType: 'success', direction: 'in' },
  manual_recharge: { label: '手动充值', tagType: 'success', direction: 'in' },
  manual_deduction: { label: '手动扣款', tagType: 'danger', direction: 'out' },
  referral_credit_cash: { label: '奖励转余额', tagType: 'success', direction: 'in' },
  system_adjustment: { label: '系统调账', tagType: 'info', direction: 'in' },
}

export const ACCOUNT_TRANSACTION_EVENT_MAP = {
  ...FINANCE_LEDGER_EVENT_MAP,
  consume: { label: '消费', tagType: 'danger', direction: 'out' },
  refund: { label: '退款', tagType: 'success', direction: 'in' },
  adjust: { label: '调账', tagType: 'info', direction: 'in' },
  reward_frozen: { label: '奖励冻结', tagType: 'warning', direction: 'in' },
  reward_released: { label: '奖励释放', tagType: 'success', direction: 'in' },
  reward_reversed: { label: '奖励冲正', tagType: 'danger', direction: 'out' },
  withdraw_apply: { label: '提现申请', tagType: 'warning', direction: 'out' },
  withdraw_approved: { label: '提现通过', tagType: 'success', direction: 'out' },
  withdraw_rejected: { label: '提现驳回', tagType: 'danger', direction: 'in' },
  referral_withdraw_approved: { label: '提现通过', tagType: 'success', direction: 'out' },
}

// ===================== 通用工具函数 =====================

/**
 * 获取状态配置项
 * @param {object} statusMap - 状态映射对象（如 ORDER_STATUS_MAP）
 * @param {number|string} status - 状态值
 * @returns {{ label: string, color: string, tagType: string, icon: string, pulse?: boolean }}
 */
export function getStatusConfig(statusMap, status) {
  return statusMap[status] || { label: '未知', color: STATUS_COLORS.info, tagType: 'info', icon: 'QuestionFilled' }
}

/**
 * 获取状态标签文本
 */
export function getStatusLabel(statusMap, status) {
  return getStatusConfig(statusMap, status).label
}

/**
 * 获取 tagType 语义值
 */
export function getStatusTagType(statusMap, status) {
  return getStatusConfig(statusMap, status).tagType
}

/**
 * 获取状态颜色
 */
export function getStatusColor(statusMap, status) {
  return getStatusConfig(statusMap, status).color
}

// ===================== Element Plus 适配函数 =====================
// 以下函数仅供 Element Plus 端使用，TDesign 端无需调用

const VALID_TAG_TYPES = new Set(['primary', 'success', 'info', 'warning', 'danger'])

/**
 * 将通用 tagType 转为 Element Plus el-tag 可接受的 type
 * - 空字符串 → 'primary'
 * - 'purple' → 'info'（配合 resolveElTagClass 附加自定义样式）
 * - 其余值若在 Element Plus 合法范围内则原样返回，否则降级为 'info'
 */
export function resolveElTagType(tagType) {
  if (tagType === '') {
    return 'primary'
  }

  if (tagType === 'purple') {
    return 'info'
  }

  return VALID_TAG_TYPES.has(tagType) ? tagType : 'info'
}

/**
 * 返回 Element Plus el-tag 需要附加的自定义 class（仅 purple 需要）
 */
export function resolveElTagClass(tagType) {
  return tagType === 'purple' ? 'el-tag--purple' : ''
}

// ===================== 通用转换函数 =====================

/**
 * 将状态映射转换为选择器选项数组
 * @param {object} statusMap - 状态映射对象
 * @param {boolean} includeAll - 是否包含"全部"选项
 * @returns {Array<{ label: string, value: number|string }>}
 */
export function toSelectOptions(statusMap, includeAll = true) {
  const options = Object.entries(statusMap).map(([value, config]) => ({
    label: config.label,
    value: isNaN(Number(value)) ? value : Number(value),
  }))
  if (includeAll) {
    options.unshift({ label: '全部', value: '' })
  }
  return options
}

/**
 * 生成 { 0: '待付款', 1: '已付款', ... } 扁平映射
 */
export function toLabelMap(statusMap) {
  const map = {}
  for (const [key, config] of Object.entries(statusMap)) {
    map[key] = config.label
  }
  return map
}

/**
 * 生成 { 0: 'warning', 1: 'success', ... } 的 tagType 映射
 */
export function toTagTypeMap(statusMap) {
  const map = {}
  for (const [key, config] of Object.entries(statusMap)) {
    map[key] = config.tagType
  }
  return map
}

export const VERIFICATION_STATUS_MAP = {
  0: { label: '未提交', color: STATUS_COLORS.info,    tagType: 'info',    icon: 'CircleClose' },
  1: { label: '待认证', color: STATUS_COLORS.warning, tagType: 'warning', icon: 'Clock' },
  2: { label: '认证成功', color: STATUS_COLORS.success, tagType: 'success', icon: 'CircleCheck' },
  3: { label: '认证失败', color: STATUS_COLORS.danger,  tagType: 'danger',  icon: 'WarningFilled' },
  4: { label: '待认证', color: STATUS_COLORS.warning, tagType: 'warning', icon: 'Clock' },
  5: { label: '已驳回', color: STATUS_COLORS.danger,  tagType: 'danger',  icon: 'CircleClose' },
}
