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

interface ProductTreeGroup {
  group: CouponProductGroupRecord;
  parentId: number | null;
  level: 1 | 2 | 3;
}

const PRODUCT_TREE_PAGE_SIZE = 100;

function productGroupTreeKey(level: number, groupId: number | string) {
  return `${level}:${groupId}`;
}

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
  /** 批量拉取多个分组的产品 */
  batchProducts: (groups: Array<{ id: number; level: number }>) =>
    request.post<Record<string, CouponProductRecord[]>>({
      url: '/v2/admin/coupon-product-groups/batch-products',
      data: { groups },
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

// 商品树加载单例缓存：避免并发重复请求
let cachedTreePromise: Promise<{ tree?: ProductTreeNode[] }> | null = null;

async function buildCouponProductTree(): Promise<{ tree?: ProductTreeNode[] }> {
  // 单例缓存：同一次页面生命周期内复用结果，避免并发重复请求
  if (cachedTreePromise) return cachedTreePromise;
  cachedTreePromise = buildCouponProductTreeInternal();
  return cachedTreePromise;
}

/** 内部实现，不缓存 */
async function buildCouponProductTreeInternal(): Promise<{ tree?: ProductTreeNode[] }> {
  // 快路径：后端整树接口一次返回全部分组与产品，替代递归逐层分页拉取（请求风暴根源）。
  try {
    const flat = await request.get<{
      groups?: CouponProductGroupRecord[];
      products?: Record<string, CouponProductRecord[]>;
    }>({ url: '/v2/admin/coupon-product-groups/tree' });

    if (flat && Array.isArray(flat.groups)) {
      const allGroups: ProductTreeGroup[] = flat.groups
        .map((group) => ({
          group,
          parentId: group.parent_id != null ? Number(group.parent_id) : null,
          level: (Number(group.level) || 1) as 1 | 2 | 3,
        }))
        .filter((entry) => entry.level >= 1 && entry.level <= 3);

      const productMap = new Map<string, CouponProductRecord[]>();
      const productsData = flat.products && typeof flat.products === 'object' ? flat.products : {};
      for (const [key, products] of Object.entries(productsData)) {
        if (Array.isArray(products)) productMap.set(key, products as CouponProductRecord[]);
      }

      const roots = allGroups.filter((entry) => entry.level === 1).map((entry) => entry.group);

      return assembleCouponProductTree(allGroups, productMap, roots);
    }
  } catch {
    // 整树接口不可用时回退到旧递归逐层拉取
  }

  const roots = await fetchAllPages<CouponProductGroupRecord>((page) =>
    couponsApi.productGroups({ page, page_size: PRODUCT_TREE_PAGE_SIZE }),
  );

  // 第一阶段：收集所有分组（不拉产品），保留完整父子关系。
  const allGroups: ProductTreeGroup[] = [];
  const productGroups: ProductTreeGroup[] = [];

  function collectGroups(group: CouponProductGroupRecord, parentId: number | null) {
    const level = Number(group.level || 0) as 1 | 2 | 3;
    const entry: ProductTreeGroup = { group, parentId, level };
    allGroups.push(entry);
    if (Number(group.direct_products_count || 0) > 0) {
      productGroups.push(entry);
    }
    return group;
  }

  // 递归收集（不拉产品，只拉子分组）
  async function collectRecursive(group: CouponProductGroupRecord, parentId: number | null): Promise<void> {
    const level = Number(group.level || 0) as 1 | 2 | 3;
    collectGroups(group, parentId);
    if (group.has_children && level < 3) {
      const childGroups = await fetchAllPages<CouponProductGroupRecord>((page) =>
        couponsApi.productGroupChildren(group.id, { level: level as 1 | 2, page, page_size: PRODUCT_TREE_PAGE_SIZE }),
      );
      await Promise.all(childGroups.map((child) => collectRecursive(child, group.id)));
    }
  }

  await Promise.all(roots.map((root) => collectRecursive(root, null)));

  // 第二阶段：一次性批量拉取所有分组的产品（1 次请求替代 N 次）
  const productMap = new Map<string, CouponProductRecord[]>();
  if (productGroups.length > 0) {
    try {
      const batchResponse = await couponsApi.batchProducts(
        productGroups.map(({ group, level }) => ({ id: group.id, level })),
      );
      // batchResponse 已被 Axios 拦截器解包，键名为“层级:分组 ID”。
      const data = (batchResponse && typeof batchResponse === 'object' ? batchResponse : {}) as Record<string, unknown>;
      for (const { group, level } of productGroups) {
        const key = productGroupTreeKey(level, group.id);
        // 兼容尚未升级批量接口的节点：旧接口仅以分组 ID 为键。
        const products = data[key] ?? data[String(group.id)];
        if (Array.isArray(products)) {
          productMap.set(key, products as CouponProductRecord[]);
        }
      }
      // 如果批量接口未返回全部数据，补漏
      if (productMap.size < productGroups.length) {
        const missing = productGroups.filter(
          ({ group, level }) => !productMap.has(productGroupTreeKey(level, group.id)),
        );
        if (missing.length > 0) {
          const supplement = await Promise.all(
            missing.map(async ({ group, level }) => {
              const products = await fetchAllPages<CouponProductRecord>((page) =>
                couponsApi.productGroupProducts(group.id, {
                  level: level as 1 | 2 | 3,
                  page,
                  page_size: PRODUCT_TREE_PAGE_SIZE,
                }),
              );
              return { key: productGroupTreeKey(level, group.id), products };
            }),
          );
          for (const { key, products } of supplement) {
            productMap.set(key, products);
          }
        }
      }
    } catch {
      // 批量接口失败时回退到逐个请求
      const productResults = await Promise.all(
        productGroups.map(async ({ group, level }) => {
          const response = await fetchAllPages<CouponProductRecord>((page) =>
            couponsApi.productGroupProducts(group.id, {
              level: level as 1 | 2 | 3,
              page,
              page_size: PRODUCT_TREE_PAGE_SIZE,
            }),
          );
          return { key: productGroupTreeKey(level, group.id), products: response };
        }),
      );
      for (const { key, products } of productResults) {
        productMap.set(key, products);
      }
    }
  }

  // 第三阶段：按“层级:ID”重建每一层，避免跨表 ID 重复覆盖节点。
  return assembleCouponProductTree(allGroups, productMap, roots);
}

/**
 * 第三阶段：按“层级:ID”重建每一层，避免跨表 ID 重复覆盖节点。
 * 供整树接口快路径与旧递归兜底路径共用。
 */
function assembleCouponProductTree(
  allGroups: ProductTreeGroup[],
  productMap: Map<string, CouponProductRecord[]>,
  roots: CouponProductGroupRecord[],
): { tree?: ProductTreeNode[] } {
  const childrenByParent = new Map<string, ProductTreeGroup[]>();
  allGroups.forEach((entry) => {
    if (entry.parentId === null) return;

    const parentKey = productGroupTreeKey(entry.level - 1, entry.parentId);
    const children = childrenByParent.get(parentKey) || [];
    children.push(entry);
    childrenByParent.set(parentKey, children);
  });

  function compareGroups(left: ProductTreeGroup, right: ProductTreeGroup) {
    const sortDifference = Number(left.group.sort_order || 0) - Number(right.group.sort_order || 0);
    return sortDifference || Number(left.group.id) - Number(right.group.id);
  }

  function buildNode(entry: ProductTreeGroup): ProductTreeNode {
    const { group, level } = entry;
    const key = productGroupTreeKey(level, group.id);
    const childNodes = [...(childrenByParent.get(key) || [])].sort(compareGroups).map(buildNode);
    const products = productMap.get(key) || [];
    const productNodes = products.map(productTreeNode);
    const children =
      level === 2 && childNodes.length > 0 && productNodes.length > 0
        ? [...childNodes, otherGroupNode(group, productNodes)]
        : [...childNodes, ...productNodes];

    return productGroupTreeNode(group, children);
  }

  // 根分组接口已按排序字段返回，保留其原始顺序。
  const tree = roots
    .map((root) => allGroups.find((entry) => entry.level === 1 && entry.group.id === root.id))
    .filter((entry): entry is ProductTreeGroup => Boolean(entry))
    .map(buildNode);

  return { tree };
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
