import request from "@/utils/request";
import { getClientToken } from "@/app/runtime/session";
import { t } from "@/locales";
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

// 目录缓存分两层：
//  1. 进程内 Map：同一次访问里切分组/切三级分组直接命中，不发请求；
//  2. sessionStorage：刷新/从详情页返回时复用（60s 内），避免整页重载后又把
//     目录请求全打一遍。目录接口本身在服务端还有 300s 缓存，双端都省。
const CATALOG_MEMORY_TTL = 3 * 60 * 1000;
const CATALOG_SESSION_TTL = 60 * 1000;
const CATALOG_SESSION_PREFIX = "turaidc:catalog:";
const CATALOG_SESSION_MAX_ENTRIES = 12;
const catalogMemory = new Map();

function catalogAuthScope() {
  // 登录用户与游客的代理折扣价不同，缓存必须按身份隔离，不能串价
  return getClientToken() ? "auth" : "guest";
}

function catalogCacheKey(groupId, childGroupId = 0, resolveId = 0) {
  return `${catalogAuthScope()}:${Number(groupId) || 0}:${Number(childGroupId) || 0}:${Number(resolveId) || 0}`;
}

function readSessionCatalog(key) {
  try {
    const raw = window.sessionStorage.getItem(CATALOG_SESSION_PREFIX + key);
    if (!raw) {
      return null;
    }

    const entry = JSON.parse(raw);
    if (
      !entry ||
      !entry.data ||
      Date.now() - Number(entry.timestamp || 0) > CATALOG_SESSION_TTL
    ) {
      window.sessionStorage.removeItem(CATALOG_SESSION_PREFIX + key);
      return null;
    }

    return entry.data;
  } catch {
    return null;
  }
}

function writeSessionCatalog(key, data) {
  try {
    // 控制条数，避免长会话里把 sessionStorage 塞满
    const keys = [];
    for (let index = 0; index < window.sessionStorage.length; index += 1) {
      const storageKey = window.sessionStorage.key(index);
      if (storageKey && storageKey.startsWith(CATALOG_SESSION_PREFIX)) {
        keys.push(storageKey);
      }
    }
    if (keys.length >= CATALOG_SESSION_MAX_ENTRIES) {
      window.sessionStorage.removeItem(keys[0]);
    }
    window.sessionStorage.setItem(
      CATALOG_SESSION_PREFIX + key,
      JSON.stringify({ timestamp: Date.now(), data }),
    );
  } catch {
    // 隐私模式/存储已满：只影响缓存命中，不影响本次访问
  }
}

/**
 * 读取目录缓存。键里带三级分组与旧深链解析 ID：同一二级分组下不同三级
 * 分组的商品不同，不能共用一个缓存条目。
 */
export function getCachedCatalog(groupId, childGroupId = 0, resolveId = 0) {
  const key = catalogCacheKey(groupId, childGroupId, resolveId);
  const entry = catalogMemory.get(key);
  if (entry && Date.now() - entry.timestamp <= CATALOG_MEMORY_TTL) {
    return entry.data;
  }
  if (entry) {
    catalogMemory.delete(key);
  }

  const sessionData = readSessionCatalog(key);
  if (sessionData) {
    catalogMemory.set(key, { data: sessionData, timestamp: Date.now() });
    return sessionData;
  }

  return null;
}

export function setCachedCatalog(
  groupId,
  data,
  childGroupId = 0,
  resolveId = 0,
) {
  const key = catalogCacheKey(groupId, childGroupId, resolveId);
  catalogMemory.set(key, { data, timestamp: Date.now() });
  writeSessionCatalog(key, data);
}

export function invalidateCatalogCache(groupId) {
  const prefix = `${catalogAuthScope()}:${Number(groupId) || 0}:`;
  for (const key of [...catalogMemory.keys()]) {
    if (key.startsWith(prefix)) {
      catalogMemory.delete(key);
    }
  }
  try {
    const keys = [];
    for (let index = 0; index < window.sessionStorage.length; index += 1) {
      const storageKey = window.sessionStorage.key(index);
      if (
        storageKey &&
        storageKey.startsWith(CATALOG_SESSION_PREFIX + prefix)
      ) {
        keys.push(storageKey);
      }
    }
    keys.forEach((key) => window.sessionStorage.removeItem(key));
  } catch {
    // 存储不可用时只清内存缓存即可
  }
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

async function fetchAllV2SiteProducts(groupId, level, config = {}) {
  const products = [];
  let page = 1;
  let total;

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

async function fetchV2SiteProductGroupCatalog(
  groupId,
  options = {},
  config = {},
) {
  const childGroupId = Number(options.childGroupId || 0);
  const resolveId = Number(options.resolveId || 0);
  const force = Boolean(options.force);

  if (!force) {
    const cached = getCachedCatalog(groupId, childGroupId, resolveId);
    if (cached) {
      return { code: 0, message: t("common.toast.success"), data: cached };
    }
  }

  // 目录聚合接口：一次请求拿回 children + 根分组商品 + 当前三级分组商品，
  // 不再按三级分组数量发 N 个商品列表请求（30 个子分组就是 30 个请求）。
  const params = {};
  if (childGroupId > 0) {
    params.child_group_id = childGroupId;
  }
  if (resolveId > 0) {
    params.resolve_id = resolveId;
  }

  const response = await request
    .get(`/v2/site/product-groups/${groupId}/catalog`, {
      ...configWithoutParams(config),
      params: mergeParams(config, params),
    })
    .then((payload) =>
      normalizeProductResponse(payload, normalizeProductGroupCatalogPayload),
    );

  const data = response.data || {};
  setCachedCatalog(groupId, data, childGroupId, resolveId);
  return response;
}

async function fetchV2SiteProductPurchaseContext(params, config = {}) {
  // 购买上下文只负责「类型 + 二级分组」两件轻量数据；目录由调用方按当前
  // 目标分组单独拉（聚合接口），不再在初始化时顺手把第一个分组的整个目录
  // 拖下来——深链到别的分组时那份目录完全是浪费。
  return request
    .get("/v2/site/product-purchase-context", {
      ...configWithoutParams(config),
      params: mergeParams(config, params || {}),
    })
    .then((payload) =>
      normalizeProductResponse(payload, normalizeProductsInitPayload),
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
  productGroupProducts: (groupId, params, config = {}) =>
    request
      .get(`/v2/site/product-groups/${groupId}/products`, { ...config, params })
      .then((response) =>
        normalizeProductResponse(response, normalizeProductListPayload),
      ),
  // 只拉某个二级分组下的三级分组（配置页目录用，不连带商品）
  productGroupChildren: (groupId, params = {}, config = {}) =>
    request
      .get(`/v2/site/product-groups/${groupId}/children`, {
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
  // 按需拉某个三级分组的全部商品（目录聚合接口只带当前分组，其余分组
  // 在用户真正切过去时再取；结果同样有服务端缓存）
  productGroupProductsAll: (groupId, level, config = {}) =>
    fetchAllV2SiteProducts(groupId, level, config),
  productGroupCatalog: (groupId, options = {}, config = {}) =>
    fetchV2SiteProductGroupCatalog(groupId, options, config),
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
