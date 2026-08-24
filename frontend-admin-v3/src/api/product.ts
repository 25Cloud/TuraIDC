import { request } from '@/utils/request';

import type { PagedListParams } from './types';

export interface ProductListParams extends PagedListParams {
  product_type?: string;
  first_product_group_code?: string;
  first_product_group_id?: number | string;
  second_product_group_id?: number | string;
  third_product_group_id?: number | string;
  status?: number | string;
  lifecycle_status?: 'active' | 'deleted' | 'all' | string;
  [key: string]: unknown;
}

export interface ProductUpstreamBindingRecord {
  provider_key?: string;
  provider_label?: string;
  supplier_id?: number | string | null;
  supplier_name?: string | null;
  upstream_product_id?: number | string | null;
  [key: string]: unknown;
}

export interface ProductRecord {
  id: number | string;
  name?: string;
  display_name?: string;
  custom_display_name?: string;
  product_display_name?: string;
  product_spec_display?: string;
  cpu_memory_display?: string;
  combined_display_name?: string;
  description?: string;
  first_product_group_id?: number | string | null;
  first_product_group_code?: string;
  first_product_group_name?: string;
  second_product_group_id?: number | string | null;
  second_product_group_name?: string;
  third_product_group_id?: number | string | null;
  third_product_group_name?: string;
  effective_product_group_id?: number | string | null;
  effective_product_group_level?: number | string | null;
  effective_product_group_full_name?: string;
  service_type_code?: string | null;
  product_type?: string;
  product_type_label?: string;
  status?: number | string;
  status_label?: string;
  is_deleted?: boolean;
  lifecycle_status?: 'active' | 'deleted' | 'all' | string;
  deleted_at?: string | null;
  auto_setup?: number | string | boolean;
  monthly_price?: number | string;
  primary_price?: { cycle?: string; amount?: number | string };
  stock?: number | string;
  services_count?: number | string;
  active_services_count?: number | string;
  total_services_count?: number | string;
  pricing?: Record<string, number | string>;
  upstream_binding?: ProductUpstreamBindingRecord | null;
  sort_order?: number | string;
  [key: string]: unknown;
}

export interface ProductTypeRecord {
  value: string;
  label: string;
  product_type?: string;
  product_type_label?: string;
  product_type_icon?: string;
  product_type_plugin_driven?: boolean;
  icon?: string;
  usage_count?: number;
  first_product_group_id?: number | string | null;
  first_product_group_code?: string;
  first_product_group_name?: string;
  is_hidden?: boolean;
  sort_order?: number;
  [key: string]: unknown;
}

export interface ProductCategoryRecord {
  id: number | string;
  name?: string;
  label?: string;
  product_type?: string;
  product_type_label?: string;
  first_product_group_id?: number | string | null;
  first_product_group_code?: string;
  first_product_group_name?: string;
  second_product_group_id?: number | string | null;
  second_product_group_name?: string;
  third_product_group_id?: number | string | null;
  third_product_group_name?: string | null;
  effective_product_group_id?: number | string | null;
  effective_product_group_level?: number | string | null;
  parent_id?: number | string | null;
  slogan?: string;
  is_visible?: number | string | boolean;
  children?: ProductCategoryRecord[];
  children_count?: number | string;
  sort_order?: number | string;
  product_count?: number | string;
  products_count?: number | string;
  products_with_trashed_count?: number | string;
  [key: string]: unknown;
}

export interface ProductGroupV2ListParams extends PagedListParams {
  keyword?: string;
  first_product_group_code?: string;
  product_type?: string;
  status?: number | string;
}

export interface ProductGroupV2Record extends ProductCategoryRecord {
  node_type?: string;
  parent_level?: number | string | null;
  service_type_code?: string;
  service_type_label?: string;
  slug?: string;
  direct_products_count?: number | string;
  status?: number | string;
}

export interface ProductV2ListParams extends ProductListParams {
  keyword?: string;
  /** 仅返回已绑定该上游供应商（product_upstream_bindings）的产品 */
  supplier_id?: number | string;
  /** 与 supplier_id 配合限定上游绑定来源 */
  provider_key?: string;
}

export interface ProductV2SummaryRecord {
  id: number | string;
  display_name?: string;
  product_spec_display?: string;
  custom_display_name?: string;
  product_type?: string;
  product_type_label?: string;
  category_full_name?: string;
  first_product_group_id?: number | string | null;
  second_product_group_id?: number | string | null;
  third_product_group_id?: number | string | null;
  effective_product_group_id?: number | string | null;
  effective_product_group_level?: number | string | null;
  primary_price?: { cycle?: string; amount?: string };
  stock?: number | string;
  status?: number | string;
  lifecycle_status?: string;
  auto_setup?: number | string | boolean;
  services_count?: number | string;
  sort_order?: number | string;
  updated_at?: string | null;
  [key: string]: unknown;
}

type V2ProductDetailRecord = Record<string, unknown> & {
  id?: number | string;
  display?: Record<string, unknown>;
  classification?: Record<string, unknown>;
  pricing?: Record<string, unknown>;
  configuration?: Record<string, unknown>;
  purchase_requirements?: Record<string, unknown>;
  provisioning?: Record<string, unknown>;
  upstream_binding?: ProductUpstreamBindingRecord | null;
  statistics?: Record<string, unknown>;
  lifecycle?: Record<string, unknown>;
  timestamps?: Record<string, unknown>;
};

export interface SpecHighlightDimension {
  key: string;
  label: string;
}

export interface SpecHighlightItem {
  key: string;
  label: string;
  value: string;
}

export interface ProductSpecHighlightResponse {
  product_id: number | string;
  spec_highlights?: SpecHighlightItem[];
  spec_highlight_text?: string;
  overrides?: Record<string, string>;
  dimensions?: SpecHighlightDimension[];
}

interface V2ProductDetailResponse {
  product?: V2ProductDetailRecord;
}

interface V2ProductGroupTreeResponse {
  tree?: ProductGroupV2Record[];
  list?: ProductGroupV2Record[];
  total?: number;
}

export interface ProductSummary {
  groups_total?: number;
  root_groups_total?: number;
  sub_groups_total?: number;
  products_total?: number;
  products_active?: number;
  products_low_stock?: number;
  [key: string]: unknown;
}

function toRecord(value: unknown): Record<string, unknown> {
  return value && typeof value === 'object' && !Array.isArray(value) ? (value as Record<string, unknown>) : {};
}

function toPriceRecord(value: unknown): Record<string, string | number> {
  return Object.entries(toRecord(value)).reduce<Record<string, string | number>>((result, [key, item]) => {
    if (typeof item === 'string' || typeof item === 'number') {
      result[key] = item;
    }
    return result;
  }, {});
}

function normalizeV2ProductListItem(item: ProductV2SummaryRecord): ProductRecord {
  const primaryPrice = toRecord(item.primary_price);
  const lifecycleStatus = String(item.lifecycle_status || 'active');
  const monthlyPriceValue = item.monthly_price ?? (primaryPrice.cycle === 'monthly' ? primaryPrice.amount : undefined);
  const monthlyPrice =
    monthlyPriceValue === undefined || monthlyPriceValue === null ? undefined : String(monthlyPriceValue);

  return {
    ...item,
    name: String(item.name || item.display_name || item.product_display_name || item.id || ''),
    display_name: String(item.display_name || item.name || ''),
    product_display_name: String(item.product_display_name || item.display_name || item.name || ''),
    product_type: String(item.product_type || item.type || ''),
    type: String(item.type || item.product_type || ''),
    product_type_label: String(item.product_type_label || item.type_label || ''),
    type_label: String(item.type_label || item.product_type_label || ''),
    effective_product_group_full_name: String(item.effective_product_group_full_name || item.category_full_name || ''),
    monthly_price: monthlyPrice,
    primary_cycle: String(item.primary_cycle ?? primaryPrice.cycle ?? ''),
    is_deleted: Boolean(item.is_deleted ?? lifecycleStatus === 'deleted'),
    lifecycle_status: lifecycleStatus,
  };
}

function normalizeV2ProductList(response: {
  list?: ProductV2SummaryRecord[];
  total?: number;
  page?: number;
  page_size?: number;
}) {
  return {
    ...response,
    list: Array.isArray(response.list) ? response.list.map((item) => normalizeV2ProductListItem(item)) : [],
  };
}

function normalizeV2ProductDetail(response: V2ProductDetailResponse): ProductRecord {
  const product = toRecord(response.product);
  const display = toRecord(product.display);
  const classification = toRecord(product.classification);
  const pricing = toRecord(product.pricing);
  const pricingItems = toPriceRecord(pricing.items);
  const primaryPrice = toRecord(pricing.primary_price);
  const primaryPricePayload = {
    cycle: String(primaryPrice.cycle || ''),
    amount: String(primaryPrice.amount || '0.00'),
  };
  const configuration = toRecord(product.configuration);
  const purchaseRequirements = toRecord(product.purchase_requirements);
  const provisioning = toRecord(product.provisioning);
  const statistics = toRecord(product.statistics);
  const lifecycle = toRecord(product.lifecycle);
  const timestamps = toRecord(product.timestamps);
  const provisionHostname = purchaseRequirements.provision_hostname;
  const productType = String(classification.product_type || '');
  const typeLabel = String(classification.product_type_label || '');
  const displayName = String(display.display_name || display.product_display_name || product.id || '');

  return {
    id: (product.id as number | string) || 0,
    effective_product_group_name: String(
      classification.third_product_group_name || classification.second_product_group_name || '',
    ),
    effective_product_group_parent_name: String(classification.second_product_group_name || ''),
    effective_product_group_full_name: String(classification.category_full_name || ''),
    name: displayName,
    display_name: displayName,
    custom_display_name: String(display.custom_display_name || ''),
    product_spec_display: String(display.product_spec_display || ''),
    product_display_name: displayName,
    cpu_memory_display: String(display.cpu_memory_display || ''),
    combined_display_name: String(display.combined_display_name || ''),
    product_type: productType,
    type: productType,
    product_type_label: typeLabel,
    type_label: typeLabel,
    ...classification,
    pricing: pricingItems,
    product_prices: pricingItems,
    primary_price: primaryPricePayload,
    primary_cycle: primaryPricePayload.cycle,
    setup_fee: String(pricing.setup_fee || '0.00'),
    config_options: Array.isArray(configuration.config_options) ? configuration.config_options : [],
    product_options: Array.isArray(configuration.config_options) ? configuration.config_options : [],
    purchase_requires: {
      require_verification: Boolean(purchaseRequirements.require_verification),
      require_phone: Boolean(purchaseRequirements.require_phone),
      provision_hostname: provisionHostname,
    },
    stock: (provisioning.stock as number | string) ?? -1,
    status: (lifecycle.status as number | string) ?? 0,
    is_deleted: String(lifecycle.lifecycle_status || 'active') === 'deleted',
    lifecycle_status: String(lifecycle.lifecycle_status || 'active'),
    deleted_at: (lifecycle.deleted_at as string | null) ?? null,
    sort_order: (lifecycle.sort_order as number | string) ?? 0,
    auto_setup: (provisioning.auto_setup as number | string | boolean) ?? 0,
    provision_hostname: provisionHostname,
    upstream_binding: (product.upstream_binding as ProductUpstreamBindingRecord | null) || null,
    product_discount_group:
      (product.product_discount_group as {
        id?: number | string;
        name?: string;
        code?: string;
        min_discount_rate?: number | string;
        cost_rate?: number | string;
      } | null) || null,
    product_discount_group_id: (product.product_discount_group_id as number | string | null) ?? null,
    orders_count: (statistics.orders_count as number | string) ?? 0,
    services_count: (statistics.services_count as number | string) ?? 0,
    active_services_count: (statistics.active_services_count as number | string) ?? 0,
    created_at: (timestamps.created_at as string | undefined) || '',
    updated_at: (timestamps.updated_at as string | undefined) || '',
  };
}

function normalizeV2ProductGroup(
  item: ProductGroupV2Record,
  children: ProductCategoryRecord[] = [],
): ProductCategoryRecord {
  const level = Number(item.effective_product_group_level || item.level || 0);
  const productType = String(item.product_type || '');
  const firstGroupCode = String(item.first_product_group_code || '');
  const name = String(item.name || item.label || '');
  const firstGroupId = item.first_product_group_id ?? (level === 1 ? item.id : null);
  const secondGroupId = item.second_product_group_id ?? (level === 2 ? item.id : null);
  const thirdGroupId = item.third_product_group_id ?? (level === 3 ? item.id : null);

  return {
    ...item,
    id: item.id,
    name,
    label: name,
    product_type: productType,
    product_type_label: String(item.product_type_label || item.service_type_label || ''),
    service_type_code: productType,
    first_product_group_id: firstGroupId,
    first_product_group_code: firstGroupCode,
    first_product_group_name: String(item.first_product_group_name || (level === 1 ? name : '')),
    second_product_group_id: secondGroupId,
    second_product_group_name: String(item.second_product_group_name || (level === 2 ? name : '')),
    third_product_group_id: thirdGroupId,
    third_product_group_name:
      level === 3 ? String(item.third_product_group_name || name) : item.third_product_group_name,
    effective_product_group_id: item.effective_product_group_id || item.id,
    effective_product_group_level: level,
    parent_id: item.parent_id ?? null,
    is_visible: item.status ?? item.is_visible ?? 0,
    status: item.status ?? item.is_visible ?? 0,
    product_count: item.products_count ?? item.product_count ?? 0,
    products_count: item.products_count ?? item.product_count ?? 0,
    children_count: item.children_count ?? children.length,
    children,
  };
}

function normalizeV2GroupParams(params?: ProductGroupV2ListParams): Record<string, unknown> {
  return { ...(params || {}) };
}

function compactQueryParams<T extends Record<string, unknown>>(params?: T): T | undefined {
  if (!params) return undefined;

  const normalized = Object.entries(params).reduce<Record<string, unknown>>((result, [key, value]) => {
    if (value === undefined || value === null) return result;
    if (typeof value === 'string') {
      const trimmed = value.trim();
      if (trimmed === '') return result;
      result[key] = trimmed;
      return result;
    }

    result[key] = value;
    return result;
  }, {});

  return Object.keys(normalized).length ? (normalized as T) : undefined;
}

function normalizeV2ProductGroupTree(items?: ProductGroupV2Record[]): ProductCategoryRecord[] {
  if (!Array.isArray(items)) return [];

  return items.map((item) =>
    normalizeV2ProductGroup(item, normalizeV2ProductGroupTree(item.children as ProductGroupV2Record[] | undefined)),
  );
}

async function fetchV2ProductCategoryTree(params?: Record<string, unknown>) {
  const response = await request.get<V2ProductGroupTreeResponse>({
    url: '/v2/admin/product-groups/tree',
    params: compactQueryParams(normalizeV2GroupParams(params)),
  });
  const source = Array.isArray(response.tree) ? response.tree : response.list;
  const tree = normalizeV2ProductGroupTree(source);

  return {
    ...response,
    tree,
    list: tree,
  };
}

export const productApi = {
  summary: () => request.get<ProductSummary>({ url: '/v2/admin/products/summary' }),
  list: (params: ProductListParams) =>
    request
      .get<{ list?: ProductV2SummaryRecord[]; total?: number; page?: number; page_size?: number }>({
        url: '/v2/admin/products',
        params: compactQueryParams(params),
      })
      .then((response) => normalizeV2ProductList(response)),
  detail: (id: number | string) =>
    request
      .get<V2ProductDetailResponse>({ url: `/v2/admin/products/${id}` })
      .then((response) => normalizeV2ProductDetail(response)),
  create: (data: Record<string, unknown>) =>
    request
      .post<V2ProductDetailResponse>({ url: '/v2/admin/products', data })
      .then((response) => normalizeV2ProductDetail(response)),
  update: (id: number | string, data: Record<string, unknown>) =>
    request
      .put<V2ProductDetailResponse>({ url: `/v2/admin/products/${id}`, data })
      .then((response) => normalizeV2ProductDetail(response)),
  delete: (id: number | string) => request.delete({ url: `/v2/admin/products/${id}` }),
  restore: (id: number | string) =>
    request
      .post<V2ProductDetailResponse>({ url: `/v2/admin/products/${id}/restorations` })
      .then((response) => normalizeV2ProductDetail(response)),
  forceDelete: (id: number | string) => request.delete({ url: `/v2/admin/products/${id}/force` }),
  toggleStatus: (id: number | string, enabled: boolean) =>
    request.patch({ url: `/v2/admin/products/${id}/status`, data: { enabled } }),
  reorderProduct: (data: Record<string, unknown>) => request.post({ url: '/v2/admin/products/reorders', data }),
  splitPreview: (data: Record<string, unknown>) => request.post({ url: '/v2/admin/products/split-previews', data }),
  splitProducts: (data: Record<string, unknown>) => request.post({ url: '/v2/admin/products/splits', data }),
  batchUpdateCategory: (data: Record<string, unknown>) =>
    request.post({ url: '/v2/admin/products/category-batches', data }),
  batchUpdateProducts: (data: Record<string, unknown>) =>
    request.post({ url: '/v2/admin/products/batch-update', data }),
  batchDeleteProducts: (data: Record<string, unknown>) =>
    request.post({ url: '/v2/admin/products/batch-delete', data }),
  batchForceDeleteProducts: (data: Record<string, unknown>) =>
    request.post({ url: '/v2/admin/products/batch-force-delete', data }),
  batchUpdateProvisionHostname: (data: Record<string, unknown>) =>
    request.post({ url: '/v2/admin/products/provision-hostname-batches', data }),
  pullTrafficPackages: (data: Record<string, unknown>) =>
    request.post({ url: '/v2/admin/products/traffic-package-pulls', data }),
  owners: (id: number | string, params?: Record<string, unknown>) =>
    request.get({ url: `/v2/admin/products/${id}/owners`, params }),
  specHighlight: (id: number | string) =>
    request.get<ProductSpecHighlightResponse>({ url: `/v2/admin/products/${id}/spec-highlight` }),
  saveSpecHighlight: (id: number | string, items: Record<string, string>) =>
    request.post({ url: `/v2/admin/products/${id}/spec-highlight`, data: { items } }),
  types: () => request.get<ProductTypeRecord[] | { list?: ProductTypeRecord[] }>({ url: '/v2/admin/product-types' }),
  reorderTypes: (data: Record<string, unknown>) => request.post({ url: '/v2/admin/product-types/reorders', data }),
  createType: (data: Record<string, unknown>) => request.post({ url: '/v2/admin/product-types', data }),
  updateType: (value: number | string, data: Record<string, unknown>) =>
    request.put({ url: `/v2/admin/product-types/${value}`, data }),
  deleteType: (value: number | string) => request.delete({ url: `/v2/admin/product-types/${value}` }),
  v2Groups: (params?: ProductGroupV2ListParams) =>
    request.get<{ list?: ProductGroupV2Record[]; total?: number; page?: number; page_size?: number }>({
      url: '/v2/admin/product-groups',
      params: compactQueryParams(normalizeV2GroupParams(params)),
    }),
  v2GroupDetail: (id: number | string, level: number | string) =>
    request.get<{ group?: ProductGroupV2Record }>({
      url: `/v2/admin/product-groups/${id}`,
      params: { level },
    }),
  v2List: (params?: ProductV2ListParams) =>
    request.get<{ list?: ProductV2SummaryRecord[]; total?: number; page?: number; page_size?: number }>({
      url: '/v2/admin/products',
      params: compactQueryParams(params),
    }),
  v2Detail: (id: number | string) =>
    request.get<{ product?: ProductRecord }>({
      url: `/v2/admin/products/${id}`,
    }),
  categories: (params?: Record<string, unknown>) => fetchV2ProductCategoryTree(params),
  createCategory: (data: Record<string, unknown>) => request.post({ url: '/v2/admin/product-groups', data }),
  updateCategory: (id: number | string, data: Record<string, unknown>) =>
    request.put({ url: `/v2/admin/product-groups/${id}`, data }),
  deleteCategory: (id: number | string, params?: Record<string, unknown>) =>
    request.delete({ url: `/v2/admin/product-groups/${id}`, params }),
  forceDeleteCategory: (id: number | string, params?: Record<string, unknown>) =>
    request.delete({ url: `/v2/admin/product-groups/${id}/force`, params }),
  batchUpdateGroupProducts: (id: number | string, data: Record<string, unknown>) =>
    request.post({ url: `/v2/admin/product-groups/${id}/batch-update-products`, data }),
  reorderCategory: (data: Record<string, unknown>) => request.post({ url: '/v2/admin/product-groups/reorders', data }),
};
