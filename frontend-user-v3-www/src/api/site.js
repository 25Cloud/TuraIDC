import request from "@/utils/request";
import {
  normalizeContentDetailPayload,
  normalizeContentListPayload,
  normalizeContentOverviewPayload,
  normalizeSiteHomePayload,
  withNormalizedData,
} from "./contentNormalizer";
import {
  normalizeProduct,
  normalizeProductDetailPayload,
  normalizeProductGroupCatalogPayload,
  normalizeProductGroupListPayload,
  normalizeProductsInitPayload,
  normalizeProductResponse,
  normalizeProductTypeListPayload,
  normalizeSiteHomeProductPayload,
} from "./productCatalogNormalizer";

const V2_SITE_PRODUCT_GROUP_PAGE_SIZE = 50;

// 目录数据缓存下沉到 api 层，供 productsInit 兜底与前端 composable 共享，
// 避免深链/切组重复拉取同一完整目录。存 normalizer 后的 catalog data（幂等）。
const CATALOG_CACHE_TTL = 3 * 60 * 1000;
const catalogCache = new Map();

export function getCachedCatalog(groupId) {
  const entry = catalogCache.get(groupId);
  if (!entry) {
    return null;
  }

  if (Date.now() - entry.timestamp > CATALOG_CACHE_TTL) {
    catalogCache.delete(groupId);
    return null;
  }

  return entry.data;
}

export function setCachedCatalog(groupId, data) {
  catalogCache.set(groupId, { data, timestamp: Date.now() });
}

export function invalidateCatalogCache(groupId) {
  catalogCache.delete(groupId);
}

function configWithoutParams(config = {}) {
  const { params: _params, ...requestConfig } = config || {};
  return requestConfig;
}

function mergeParams(config = {}, params = {}) {
  return {
    ...(config?.params || {}),
    ...params,
  };
}

function normalizeProductListPayload(data = {}) {
  const list = Array.isArray(data?.list) ? data.list.map(normalizeProduct) : [];

  return {
    ...(data && typeof data === "object" && !Array.isArray(data) ? data : {}),
    list,
  };
}

async function fetchAllV2SiteProductGroups(url, params = {}, config = {}) {
  const list = [];
  let page = 1;
  let total = 0;
  let baseResponse = null;

  do {
    const response = await request
      .get(url, {
        ...configWithoutParams(config),
        params: mergeParams(config, {
          ...params,
          page,
          page_size: V2_SITE_PRODUCT_GROUP_PAGE_SIZE,
        }),
      })
      .then((payload) =>
        normalizeProductResponse(payload, normalizeProductGroupListPayload),
      );

    baseResponse ||= response;
    const data = response.data || {};
    const pageList = data.list || [];
    list.push(...pageList);
    total = Number(data.total || list.length);
    page += 1;
    // 终止保护：本页返回空列表或已达最大页数即退出，
    // 避免后端 total 与分页返回数不一致时无限翻页
    if (!pageList.length || page > 100) {
      break;
    }
  } while (list.length < total);

  return { response: baseResponse, list, total };
}

async function fetchAllV2SiteProducts(groupId, level, config = {}) {
  const products = [];
  let page = 1;
  let total = 0;

  do {
    const response = await request
      .get(`/v2/site/product-groups/${groupId}/products`, {
        ...configWithoutParams(config),
        params: mergeParams(config, {
          level,
          page,
          page_size: V2_SITE_PRODUCT_GROUP_PAGE_SIZE,
        }),
      })
      .then((payload) =>
        normalizeProductResponse(payload, normalizeProductListPayload),
      );

    const data = response.data || {};
    const pageList = data.list || [];
    products.push(...pageList);
    total = Number(data.total || products.length);
    page += 1;
    // 终止保护：本页返回空列表或已达最大页数即退出，避免无限翻页
    if (!pageList.length || page > 100) {
      break;
    }
  } while (products.length < total);

  return products;
}

async function fetchV2SiteProductGroupCatalog(groupId, config = {}) {
  const cached = getCachedCatalog(groupId);
  if (cached) {
    return { code: 0, message: "操作成功", data: cached };
  }

  // children 列表与根产品无数据依赖，并行拉取省一个 RTT
  const [childrenResult, rootProducts] = await Promise.all([
    fetchAllV2SiteProductGroups(
      `/v2/site/product-groups/${groupId}/children`,
      {},
      config,
    ),
    fetchAllV2SiteProducts(groupId, 2, config),
  ]);
  const children = childrenResult.list || [];
  const itemsByGroup = [
    {
      effective_product_group_id: Number(groupId),
      products: rootProducts,
    },
  ];

  const childItems = await Promise.all(
    children.map(async (child) => {
      const childGroupId = Number(
        child.effective_product_group_id || child.id || 0,
      );
      const childLevel = Number(child.effective_product_group_level || 3);

      if (childGroupId <= 0) {
        return null;
      }

      return {
        effective_product_group_id: childGroupId,
        products: await fetchAllV2SiteProducts(
          childGroupId,
          childLevel,
          config,
        ),
      };
    }),
  );

  itemsByGroup.push(...childItems.filter(Boolean));

  const response = normalizeProductResponse(
    {
      ...(childrenResult.response || { code: 0, message: "操作成功" }),
      data: {
        effective_product_group_id: Number(groupId),
        effective_product_group_level: 2,
        children,
        items_by_group: itemsByGroup,
      },
    },
    normalizeProductGroupCatalogPayload,
  );

  setCachedCatalog(groupId, response.data);
  return response;
}

async function fetchV2SiteProductPurchaseContext(params, config = {}) {
  const response = await request
    .get("/v2/site/product-purchase-context", {
      ...configWithoutParams(config),
      params: mergeParams(config, params || {}),
    })
    .then((payload) =>
      normalizeProductResponse(payload, normalizeProductsInitPayload),
    );

  const rootGroups = response.data?.root_groups || [];
  const firstGroupId = Number(
    rootGroups[0]?.id || rootGroups[0]?.effective_product_group_id || 0,
  );
  let catalog = response.data?.catalog;

  if (firstGroupId > 0 && !catalog) {
    // 优先复用 api 层目录缓存，避免每次进入 /products 都重复拉取首组完整目录
    catalog = getCachedCatalog(firstGroupId);
    if (!catalog) {
      const catalogResponse = await fetchV2SiteProductGroupCatalog(
        firstGroupId,
        config,
      );
      catalog = catalogResponse.data;
    }
  }

  return normalizeProductResponse(
    {
      ...response,
      data: {
        ...(response.data || {}),
        catalog,
      },
    },
    normalizeProductsInitPayload,
  );
}

export default {
  config: (config) => request.get("/v2/site/config", config),
  home: (config) =>
    request
      .get("/v2/site/home", config)
      .then((response) =>
        withNormalizedData(response, normalizeSiteHomePayload),
      )
      .then((response) =>
        normalizeProductResponse(response, normalizeSiteHomeProductPayload),
      ),
  // 复用 index.html 预热期 fetch 到的原始 /v2/site/home 响应，
  // 走与 home() 完全一致的 normalizer 管线，避免字段结构差异。
  homeFromRaw: (rawResponse) => {
    const response =
      rawResponse && typeof rawResponse === "object" ? rawResponse : {};
    const payload =
      response.data && typeof response.data === "object" ? response.data : {};
    return normalizeProductResponse(
      withNormalizedData(
        { ...response, data: payload },
        normalizeSiteHomePayload,
      ),
      normalizeSiteHomeProductPayload,
    );
  },
  productsInit: (params, config = {}) =>
    fetchV2SiteProductPurchaseContext(params, config),
  productTypes: (config) =>
    request
      .get("/v2/site/product-types", config)
      .then((response) =>
        normalizeProductResponse(response, normalizeProductTypeListPayload),
      ),
  productGroups: (params, config = {}) =>
    request
      .get("/v2/site/product-groups", {
        ...config,
        params: {
          page: 1,
          page_size: V2_SITE_PRODUCT_GROUP_PAGE_SIZE,
          ...params,
        },
      })
      .then((response) =>
        normalizeProductResponse(response, normalizeProductGroupListPayload),
      ),
  productGroupChildren: (groupId, config) =>
    request
      .get(`/v2/site/product-groups/${groupId}/children`, config)
      .then((response) =>
        normalizeProductResponse(response, normalizeProductGroupListPayload),
      ),
  productGroupProducts: (groupId, params, config = {}) =>
    request
      .get(`/v2/site/product-groups/${groupId}/products`, { ...config, params })
      .then((response) =>
        normalizeProductResponse(response, normalizeProductListPayload),
      ),
  productGroupCatalog: (groupId, config) =>
    fetchV2SiteProductGroupCatalog(groupId, config),
  products: (params, config = {}) =>
    request
      .get("/v2/site/products", { ...config, params })
      .then((response) =>
        normalizeProductResponse(response, normalizeProductListPayload),
      ),
  productStock: (id, config) =>
    request.get(`/v2/site/products/${id}/stock`, config),
  product: (id, config) =>
    request
      .get(`/v2/site/products/${id}`, config)
      .then((response) =>
        normalizeProductResponse(response, normalizeProductDetailPayload),
      ),
  productQuote: (id, data, config) =>
    request.post(`/v2/site/products/${id}/quote`, data, config),
  contentOverview: (config) =>
    request
      .get("/v2/site/content/overview", config)
      .then((response) =>
        withNormalizedData(response, normalizeContentOverviewPayload),
      ),
  notices: (params, config = {}) =>
    request
      .get("/v2/site/notices", { ...config, params })
      .then((response) =>
        withNormalizedData(response, normalizeContentListPayload),
      ),
  noticeDetail: (id, config) =>
    request
      .get(`/v2/site/notices/${id}`, config)
      .then((response) =>
        withNormalizedData(response, normalizeContentDetailPayload),
      ),
  helpArticles: (params, config = {}) =>
    request
      .get("/v2/site/help-articles", { ...config, params })
      .then((response) =>
        withNormalizedData(response, normalizeContentListPayload),
      ),
  helpDetail: (id, config) =>
    request
      .get(`/v2/site/help-articles/${id}`, config)
      .then((response) =>
        withNormalizedData(response, normalizeContentDetailPayload),
      ),
};
