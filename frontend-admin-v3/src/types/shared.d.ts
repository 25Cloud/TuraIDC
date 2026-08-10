declare module '@shared/statusConfig' {
  export const INVOICE_TYPE_MAP: Record<string, string>;
  export const ORDER_TYPE_MAP: Record<string, string>;
  export const INVOICE_STATUS_MAP: Record<string | number, { label: string; tagType: string }>;
  export const ORDER_STATUS_MAP: Record<string | number, { label: string; tagType: string }>;
  export const PAYMENT_STATUS_MAP: Record<string | number, { label: string; tagType: string }>;
  export const ACCOUNT_TRANSACTION_EVENT_MAP: Record<
    string | number,
    { label: string; tagType: string; direction?: string }
  >;
  export const REWARD_STATUS_MAP: Record<string | number, { label: string; tagType: string }>;
  export const SERVICE_STATUS_MAP: Record<string | number, { label: string; tagType: string }>;
  export function getStatusLabel(statusMap: Record<string | number, { label: string }>, value: string | number): string;
  export function getStatusTagType(
    statusMap: Record<string | number, { tagType: string }>,
    value: string | number,
  ): string;
  export function getStatusConfig(
    statusMap: Record<string | number, { label: string; tagType: string; color?: string; icon?: string }>,
    value: string | number,
  ): { label: string; tagType: string; color?: string; icon?: string; pulse?: boolean };
  export function toLabelMap(statusMap: Record<string | number, { label: string }>): Record<string, string>;
  export function toSelectOptions(
    statusMap: Record<string | number, { label: string }>,
    includeAll?: boolean,
  ): Array<{ label: string; value: string | number }>;
  export function toTagTypeMap(statusMap: Record<string | number, { tagType: string }>): Record<string, string>;
}
