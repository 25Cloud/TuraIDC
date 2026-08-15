export interface StatusConfig {
  label: string
  color?: string
  tagType?: string
  icon?: string
  pulse?: boolean
  direction?: string
  [key: string]: unknown
}

export type StatusMap = Record<string | number, StatusConfig>

/**
 * 访问器参数类型。调用方常持有来自 props、接口或局部字面量的宽松映射，
 * 而这些函数只做运行时查表并对缺失项返回兜底配置，因此入参保持宽松。
 */
export type StatusMapInput = Record<string | number, unknown>

export const STATUS_COLORS: Record<string, string>
export const STATUS_TAG_TYPES: Record<string, string>

export const ORDER_STATUS: Record<string, number>
export const INVOICE_STATUS: Record<string, number>
export const PAYMENT_STATUS: Record<string, number>
export const SERVICE_STATUS: Record<string, number>
export const TICKET_STATUS: Record<string, number>
export const NOTIFY_STATUS: Record<string, string>
export const REWARD_STATUS: Record<string, number>

export const ORDER_TYPE_MAP: Record<string, string>
export const INVOICE_TYPE_MAP: Record<string, string>
export const TRANSACTION_TYPE_MAP: Record<string, string>

export const ORDER_STATUS_MAP: StatusMap
export const INVOICE_STATUS_MAP: StatusMap
export const PAYMENT_STATUS_MAP: StatusMap
export const SERVICE_STATUS_MAP: StatusMap
export const TICKET_STATUS_MAP: StatusMap
export const NOTIFY_STATUS_MAP: StatusMap
export const REWARD_STATUS_MAP: StatusMap
export const FINANCE_LEDGER_EVENT_MAP: StatusMap
export const ACCOUNT_TRANSACTION_EVENT_MAP: StatusMap
export const VERIFICATION_STATUS_MAP: StatusMap

export function getStatusConfig(statusMap: StatusMapInput, status: string | number): StatusConfig
export function getStatusLabel(statusMap: StatusMapInput, status: string | number): string
export function getStatusTagType(statusMap: StatusMapInput, status: string | number): string
export function getStatusColor(statusMap: StatusMapInput, status: string | number): string

export function resolveElTagType(tagType: string): string
export function resolveElTagClass(tagType: string): string

export function toSelectOptions(
  statusMap: StatusMapInput,
  includeAll?: boolean,
): Array<{ label: string, value: string | number }>
export function toLabelMap(statusMap: StatusMapInput): Record<string, string>
export function toTagTypeMap(statusMap: StatusMapInput): Record<string, string>
