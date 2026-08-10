import type { ProviderTypeRecord } from '@/api/supplier';
import { toUserMessage } from '@/utils/userMessage';

/**
 * Shared utilities for product management pages
 */

export const providerTypeFallbackLabels: Record<string, string> = {
  zjmf_finance_api: 'ZJMF 财务',
  zjmf_cloud_api: 'ZJMF 云',
  hosting_panel_api: '通用主机面板',
};

export const fallbackProviderTypeOptions = Object.entries(providerTypeFallbackLabels).map(([value, label]) => ({
  value,
  label,
}));

/**
 * Flatten category tree into a flat list with indented labels
 */
export function flattenCategories<
  T extends {
    id: string | number;
    name?: string;
    label?: string;
    children?: T[];
    parent_id?: number | string | null;
    product_type?: string;
    first_product_group_code?: string;
  },
>(nodes: T[], level = 0, parent: T | null = null, catalogProductType = ''): T[] {
  return nodes.flatMap((node) => {
    const label = `${'　'.repeat(level)}${node.label || node.name || `分类 #${node.id}`}`;
    const current = {
      ...node,
      label,
      parent_id: node.parent_id ?? parent?.id ?? null,
      product_type: node.product_type || parent?.product_type || '',
      first_product_group_code:
        node.first_product_group_code || parent?.first_product_group_code || catalogProductType || '',
    } as T;
    return [current, ...flattenCategories(node.children || [], level + 1, current, catalogProductType)];
  });
}

/**
 * Normalize product IDs to a clean array of numbers
 */
export function normalizeProductIds(value: unknown): number[] {
  if (!Array.isArray(value)) return [];
  return value.map((item) => Number(item || 0)).filter((item) => Number.isFinite(item) && item > 0);
}

export interface ProductGroupLike {
  id: string | number;
  label?: string;
  name?: string;
  product_type?: string;
  first_product_group_id?: number | string | null;
  first_product_group_code?: string | null;
  first_product_group_name?: string | null;
  second_product_group_id?: number | string | null;
  second_product_group_name?: string | null;
  third_product_group_id?: number | string | null;
  third_product_group_name?: string | null;
  effective_product_group_id?: number | string | null;
  effective_product_group_level?: number | string | null;
  children?: ProductGroupLike[];
  children_count?: number | string;
}

export function productGroupLevel(row: ProductGroupLike | null | undefined): number {
  const explicit = Number(row?.effective_product_group_level || 0);
  if ([1, 2, 3].includes(explicit)) return explicit;
  if (Number(row?.third_product_group_id || 0) > 0) return 3;
  if (Number(row?.second_product_group_id || 0) > 0) return 2;
  return 1;
}

export function productGroupEffectiveId(row: ProductGroupLike | null | undefined): number {
  const explicit = Number(row?.effective_product_group_id || 0);
  if (explicit > 0) return explicit;
  const level = productGroupLevel(row);
  if (level === 3) return Number(row?.third_product_group_id || row?.id || 0);
  if (level === 2) return Number(row?.second_product_group_id || row?.id || 0);
  return Number(row?.first_product_group_id || row?.id || 0);
}

export function productGroupOptionKey(row: ProductGroupLike | null | undefined): string {
  const id = productGroupEffectiveId(row);
  return id > 0 ? `${productGroupLevel(row)}:${id}` : '';
}

export function findProductGroupByKey<T extends ProductGroupLike>(options: T[], key: unknown): T | null {
  const normalized = String(key || '');
  if (!normalized) return null;
  return options.find((item) => productGroupOptionKey(item) === normalized) || null;
}

export function productGroupOptionLabel(row: ProductGroupLike): string {
  return String(row.label || row.name || `分类 #${productGroupEffectiveId(row)}`).trim();
}

export function isSelectableProductGroup(row: ProductGroupLike): boolean {
  const level = productGroupLevel(row);
  if (level === 3) return true;
  if (level !== 2) return false;
  const childCount = Array.isArray(row.children) ? row.children.length : Number(row.children_count || 0);
  return childCount <= 0;
}

export function productGroupPayload(
  row: ProductGroupLike | null | undefined,
  prefix = '',
): Record<string, number | null> {
  if (!row) return {};
  const firstId = Number(row.first_product_group_id || 0) || null;
  const secondId = Number(row.second_product_group_id || (productGroupLevel(row) === 2 ? row.id : 0) || 0) || null;
  const thirdId = Number(row.third_product_group_id || (productGroupLevel(row) === 3 ? row.id : 0) || 0) || null;
  return {
    [`${prefix}first_product_group_id`]: firstId,
    [`${prefix}second_product_group_id`]: secondId,
    [`${prefix}third_product_group_id`]: thirdId,
  };
}

/**
 * Format date/time string
 */
export function formatDateTime(value: unknown): string {
  if (!value) return '-';
  const date = new Date(String(value).replace(/-/g, '/'));
  if (Number.isNaN(date.getTime())) return String(value);
  const pad = (item: number) => String(item).padStart(2, '0');
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} ${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

/**
 * Format monthly price for display
 */
export function formatMonthlyPrice(row: {
  monthly_price?: number;
  primary_price?: { amount?: number };
  pricing?: { monthly?: number };
}): string {
  const primaryPrice = toPlainRecord(row.primary_price);
  const amount = row.monthly_price ?? primaryPrice.amount ?? row.pricing?.monthly;
  if (amount === undefined || amount === null || amount === '') return '-';
  const numericAmount = Number(amount);
  if (!Number.isFinite(numericAmount)) return `¥ ${amount}`;
  return `¥ ${numericAmount.toFixed(2)}`;
}

/**
 * Format stock count
 */
export function formatStock(value: unknown): string {
  const stock = Number(value);
  if (!Number.isFinite(stock)) return '-';
  return stock < 0 ? '不限' : String(stock);
}

/**
 * Format count number
 */
export function formatCount(value: unknown): string {
  const count = Number(value);
  return Number.isFinite(count) ? String(count) : '0';
}

/**
 * Extract error message from error object
 */
export function errorMessage(error: unknown, fallback: string): string {
  if (error instanceof Error && error.message) return toUserMessage(error.message, fallback);
  return fallback;
}

/**
 * Convert unknown value to plain record object
 */
export function toPlainRecord(value: unknown): Record<string, unknown> {
  return value && typeof value === 'object' ? (value as Record<string, unknown>) : {};
}

/**
 * Get provider type label with fallback
 */
export function providerTypeLabel(value: unknown, providerTypeOptions: ProviderTypeRecord[] = []): string {
  const key = String(value || '').trim();
  if (providerTypeFallbackLabels[key]) return providerTypeFallbackLabels[key];
  return providerTypeOptions.find((item) => item.value === key)?.label || key || '-';
}

/**
 * Merge provider type options with fallbacks
 */
export function mergeProviderTypeOptions(options: ProviderTypeRecord[]): ProviderTypeRecord[] {
  return normalizeProviderTypeOptions(options).map((item) => ({
    ...item,
    label: providerTypeFallbackLabels[item.value] || item.label,
  }));
}

/**
 * Normalize provider type options from various API response formats
 */
export function normalizeProviderTypeOptions(value: unknown): ProviderTypeRecord[] {
  const record = toPlainRecord(value);
  const rawItems = Array.isArray(value)
    ? value
    : Array.isArray(record.list)
      ? record.list
      : Array.isArray(record.options)
        ? record.options
        : Array.isArray(record.items)
          ? record.items
          : Array.isArray(record.types)
            ? record.types
            : Array.isArray(record.provider_types)
              ? record.provider_types
              : record.value
                ? [record]
                : Object.entries(record).map(([entryValue, entryLabel]) => ({ value: entryValue, label: entryLabel }));

  return rawItems
    .map((item) => {
      if (typeof item === 'string') {
        return { value: item, label: providerTypeFallbackLabels[item] || item };
      }

      const rec = toPlainRecord(item);
      const val = String(rec.value ?? rec.key ?? rec.type ?? rec.code ?? '').trim();
      if (!val) return null;

      const rawLabel = rec.label ?? rec.name ?? rec.title;
      const label =
        providerTypeFallbackLabels[val] || (typeof rawLabel === 'string' ? rawLabel : String(rawLabel || val));
      return { ...rec, value: val, label };
    })
    .filter((item): item is ProviderTypeRecord => !!item);
}
