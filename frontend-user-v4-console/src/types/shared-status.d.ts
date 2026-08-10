declare module '@shared/statusConfig' {
  export const SERVICE_STATUS: Record<string, number>;
  export const ORDER_TYPE_MAP: Record<string, string>;
  export const INVOICE_TYPE_MAP: Record<string, string>;
  export const INVOICE_STATUS_MAP: Record<string | number, StatusConfig>;
  export const PAYMENT_STATUS_MAP: Record<string | number, StatusConfig>;
  export const ACCOUNT_TRANSACTION_EVENT_MAP: Record<string | number, StatusConfig>;

  export interface StatusConfig {
    label: string;
    color?: string;
    tagType?: string;
    theme?: string;
    direction?: string;
    [key: string]: unknown;
  }

  export const SERVICE_STATUS_MAP: Record<string | number, StatusConfig>;

  export function toSelectOptions(
    statusMap: Record<string | number, unknown>,
    includeAll?: boolean,
  ): Array<{
    label: string;
    value: string | number;
    [key: string]: unknown;
  }>;

  export function getStatusConfig(statusMap: Record<string | number, unknown>, status: string | number): unknown;
  export function getStatusLabel(statusMap: Record<string | number, unknown>, status: string | number): string;
  export function getStatusTagType(statusMap: Record<string | number, unknown>, status: string | number): string;
}

declare module '@ewyfinance/shared/statusConfig' {
  export const SERVICE_STATUS: Record<string, number>;
  export const ORDER_TYPE_MAP: Record<string, string>;
  export const INVOICE_TYPE_MAP: Record<string, string>;
  export const ORDER_STATUS_MAP: Record<string | number, StatusConfig>;
  export const INVOICE_STATUS_MAP: Record<string | number, StatusConfig>;
  export const PAYMENT_STATUS_MAP: Record<string | number, StatusConfig>;
  export const ACCOUNT_TRANSACTION_EVENT_MAP: Record<string | number, StatusConfig>;

  export interface StatusConfig {
    label: string;
    color?: string;
    tagType?: string;
    theme?: string;
    direction?: string;
    [key: string]: unknown;
  }

  export const SERVICE_STATUS_MAP: Record<string | number, StatusConfig>;

  export function toSelectOptions(
    statusMap: Record<string | number, unknown>,
    includeAll?: boolean,
  ): Array<{
    label: string;
    value: string | number;
    [key: string]: unknown;
  }>;

  export function getStatusConfig(statusMap: Record<string | number, unknown>, status: string | number): unknown;
  export function getStatusLabel(statusMap: Record<string | number, unknown>, status: string | number): string;
  export function getStatusTagType(statusMap: Record<string | number, unknown>, status: string | number): string;
}
