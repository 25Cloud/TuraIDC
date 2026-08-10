import { request } from '@/utils/request';

import type {
  CouponCampaignListParams,
  CouponCampaignPayload,
  CouponCampaignRecord,
  CouponListParams,
  CouponPayload,
  CouponProductGroupChildrenParams,
  CouponProductGroupListParams,
  CouponProductGroupProductsParams,
  CouponProductGroupRecord,
  CouponProductRecord,
  CouponRecord,
} from './types';

interface PagedResult<T> {
  list?: T[];
  total?: number;
  page?: number;
  page_size?: number;
}

type ProductTreeNode = Record<string, unknown> & {
  children?: ProductTreeNode[];
};

const PRODUCT_TREE_PAGE_SIZE = 100;

async function fetchAllPages<T>(loader: (page: number) => Promise<PagedResult<T>>) {
  const result: T[] = [];
  let page = 1;
  let total = 0;

  do {
    const response = await loader(page);
    const list = Array.isArray(response.list) ? response.list : [];
    total = Number(response.total || list.length || 0);
    result.push(...list);

    const pageSize = Number(response.page_size || PRODUCT_TREE_PAGE_SIZE);
    if (!list.length || result.length >= total || list.length < pageSize) break;
    page += 1;
  } while (page <= 1000);

  return result;
}

function productGroupNodeType(level: number) {
  if (level === 1) return 'first_product_group';
  if (level === 2) return 'second_product_group';
  return 'third_product_group';
}

function productGroupTreeNode(group: CouponProductGroupRecord, children: ProductTreeNode[] = []): ProductTreeNode {
  const level = Number(group.level || group.effective_product_group_level || 0);
  const label = String(group.label || group.name || '未命名分类');

  return {
    ...group,
    id: group.node_key || `${level}:${group.id}`,
    label,
    node_type: group.node_type || productGroupNodeType(level),
    leaf: false,
    disabled: false,
    effective_product_group_full_name: group.group_path || '',
    children,
  };
}

function productTreeNode(product: CouponProductRecord): ProductTreeNode {
  const label = String(
    product.label || product.display_name || product.product_display_name || product.name || `商品 #${product.id}`,
  );

  return {
    ...product,
    id: Number(product.product_id || product.id),
    label,
    node_type: 'product',
    leaf: true,
    disabled: false,
    effective_product_group_full_name: product.group_path || product.category_full_name || '',
  };
}

function otherGroupNode(group: CouponProductGroupRecord, children: ProductTreeNode[]): ProductTreeNode {
  const path = String(group.group_path || group.label || group.name || '').trim();

  return {
    ...group,
    id: `${group.node_key || `second:${group.id}`}:other`,
    label: '其他',
    node_type: 'third_product_group',
    third_product_group_id: null,
    third_product_group_name: '其他',
    effective_product_group_id: group.id,
    effective_product_group_level: 2,
    effective_product_group_full_name: path ? `${path} / 其他` : '其他',
    leaf: false,
    disabled: false,
    children,
  };
}

export const couponsApi = {
  productGroups: (params?: CouponProductGroupListParams) =>
    request.get<PagedResult<CouponProductGroupRecord>>({ url: '/v2/admin/coupon-product-groups', params }),
  productGroupChildren: (group: number | string, params: CouponProductGroupChildrenParams) =>
    request.get<PagedResult<CouponProductGroupRecord>>({
      url: `/v2/admin/coupon-product-groups/${group}/children`,
      params,
    }),
  productGroupProducts: (group: number | string, params: CouponProductGroupProductsParams) =>
    request.get<PagedResult<CouponProductRecord>>({
      url: `/v2/admin/coupon-product-groups/${group}/products`,
      params,
    }),
  productTree: () => buildCouponProductTree(),
  summary: (params?: Record<string, unknown>) =>
    request.get<{ enabled?: boolean; [key: string]: unknown }>({ url: '/v2/admin/coupons/summary', params }),
  list: (params: CouponListParams) =>
    request.get<{ list?: CouponRecord[]; total?: number; page?: number; page_size?: number }>({
      url: '/v2/admin/coupons',
      params,
    }),
  create: (data: CouponPayload) => request.post<CouponRecord>({ url: '/v2/admin/coupons', data }),
  update: (id: number | string, data: CouponPayload) =>
    request.put<CouponRecord>({ url: `/v2/admin/coupons/${id}`, data }),
  toggleStatus: (id: number | string, enabled: boolean) =>
    request.patch({ url: `/v2/admin/coupons/${id}/status`, data: { enabled } }),
  delete: (id: number | string) => request.delete({ url: `/v2/admin/coupons/${id}` }),
};

async function buildCouponProductTree(): Promise<{ tree?: ProductTreeNode[] }> {
  const roots = await fetchAllPages<CouponProductGroupRecord>((page) =>
    couponsApi.productGroups({ page, page_size: PRODUCT_TREE_PAGE_SIZE }),
  );

  const tree = await Promise.all(roots.map((group) => buildCouponProductGroupTree(group)));
  return { tree };
}

async function buildCouponProductGroupTree(group: CouponProductGroupRecord): Promise<ProductTreeNode> {
  const level = Number(group.level || 0) as 1 | 2 | 3;
  const childGroups =
    group.has_children && level < 3
      ? await fetchAllPages<CouponProductGroupRecord>((page) =>
          couponsApi.productGroupChildren(group.id, {
            level: level as 1 | 2,
            page,
            page_size: PRODUCT_TREE_PAGE_SIZE,
          }),
        )
      : [];
  const childNodes = await Promise.all(childGroups.map((child) => buildCouponProductGroupTree(child)));
  const directProducts =
    Number(group.direct_products_count || 0) > 0
      ? await fetchAllPages<CouponProductRecord>((page) =>
          couponsApi.productGroupProducts(group.id, {
            level,
            page,
            page_size: PRODUCT_TREE_PAGE_SIZE,
          }),
        )
      : [];
  const productNodes = directProducts.map(productTreeNode);
  const children =
    level === 2 && childNodes.length > 0 && productNodes.length > 0
      ? [...childNodes, otherGroupNode(group, productNodes)]
      : [...childNodes, ...productNodes];

  return productGroupTreeNode(group, children);
}

export const couponCampaignsApi = {
  summary: (params?: Record<string, unknown>) =>
    request.get<Record<string, unknown>>({ url: '/v2/admin/coupon-campaigns/summary', params }),
  list: (params: CouponCampaignListParams) =>
    request.get<{ list?: CouponCampaignRecord[]; total?: number; page?: number; page_size?: number }>({
      url: '/v2/admin/coupon-campaigns',
      params,
    }),
  create: (data: CouponCampaignPayload) =>
    request.post<CouponCampaignRecord>({ url: '/v2/admin/coupon-campaigns', data }),
  update: (id: number | string, data: CouponCampaignPayload) =>
    request.put<CouponCampaignRecord>({ url: `/v2/admin/coupon-campaigns/${id}`, data }),
  toggleStatus: (id: number | string, enabled: boolean) =>
    request.patch({ url: `/v2/admin/coupon-campaigns/${id}/status`, data: { enabled } }),
  trigger: (id: number | string) =>
    request.post({ url: `/v2/admin/coupon-campaigns/${id}/tasks`, data: { type: 'trigger', payload: {} } }),
  delete: (id: number | string) => request.delete({ url: `/v2/admin/coupon-campaigns/${id}` }),
};
