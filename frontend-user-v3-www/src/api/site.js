import request from '@/utils/request'
import {
  normalizeContentDetailPayload,
  normalizeContentListPayload,
  normalizeContentOverviewPayload,
  normalizeSiteHomePayload,
  withNormalizedData,
} from './contentNormalizer'
import {
  normalizeProduct,
  normalizeProductDetailPayload,
  normalizeProductGroupCatalogPayload,
  normalizeProductGroupListPayload,
  normalizeProductsInitPayload,
  normalizeProductResponse,
  normalizeProductTypeListPayload,
  normalizeSiteHomeProductPayload,
} from './productCatalogNormalizer'

const V2_SITE_PRODUCT_GROUP_PAGE_SIZE = 50

function configWithoutParams(config = {}) {
  const { params, ...requestConfig } = config || {}
  void params
  return requestConfig
}

function mergeParams(config = {}, params = {}) {
  return {
    ...(config?.params || {}),
    ...params,
  }
}

function normalizeProductListPayload(data = {}) {
  const list = Array.isArray(data?.list) ? data.list.map(normalizeProduct) : []

  return {
    ...(data && typeof data === 'object' && !Array.isArray(data) ? data : {}),
    list,
  }
}

async function fetchAllV2SiteProductGroups(url, params = {}, config = {}) {
  const list = []
  let page = 1
  let total = 0
  let baseResponse = null

  do {
    const response = await request.get(url, {
      ...configWithoutParams(config),
      params: mergeParams(config, {
        ...params,
        page,
        page_size: V2_SITE_PRODUCT_GROUP_PAGE_SIZE,
      }),
    }).then((payload) => normalizeProductResponse(payload, normalizeProductGroupListPayload))

    baseResponse ||= response
    const data = response.data || {}
    list.push(...(data.list || []))
    total = Number(data.total || list.length)
    page += 1
  } while (list.length < total)

  return { response: baseResponse, list, total }
}

async function fetchAllV2SiteProducts(groupId, level, config = {}) {
  const products = []
  let page = 1
  let total = 0

  do {
    const response = await request.get(`/v2/site/product-groups/${groupId}/products`, {
      ...configWithoutParams(config),
      params: mergeParams(config, {
        level,
        page,
        page_size: V2_SITE_PRODUCT_GROUP_PAGE_SIZE,
      }),
    }).then((payload) => normalizeProductResponse(payload, normalizeProductListPayload))

    const data = response.data || {}
    products.push(...(data.list || []))
    total = Number(data.total || products.length)
    page += 1
  } while (products.length < total)

  return products
}

async function fetchV2SiteProductGroupCatalog(groupId, config = {}) {
  const childrenResult = await fetchAllV2SiteProductGroups(`/v2/site/product-groups/${groupId}/children`, {}, config)
  const children = childrenResult.list || []
  const itemsByGroup = []
  const rootProducts = await fetchAllV2SiteProducts(groupId, 2, config)

  itemsByGroup.push({
    effective_product_group_id: Number(groupId),
    products: rootProducts,
  })

  const childItems = await Promise.all(children.map(async (child) => {
    const childGroupId = Number(child.effective_product_group_id || child.id || 0)
    const childLevel = Number(child.effective_product_group_level || 3)

    if (childGroupId <= 0) {
      return null
    }

    return {
      effective_product_group_id: childGroupId,
      products: await fetchAllV2SiteProducts(childGroupId, childLevel, config),
    }
  }))

  itemsByGroup.push(...childItems.filter(Boolean))

  return normalizeProductResponse({
    ...(childrenResult.response || { code: 0, message: '操作成功' }),
    data: {
      effective_product_group_id: Number(groupId),
      effective_product_group_level: 2,
      children,
      items_by_group: itemsByGroup,
    },
  }, normalizeProductGroupCatalogPayload)
}

async function fetchV2SiteProductPurchaseContext(params, config = {}) {
  const response = await request.get('/v2/site/product-purchase-context', {
    ...configWithoutParams(config),
    params: mergeParams(config, params || {}),
  }).then((payload) => normalizeProductResponse(payload, normalizeProductsInitPayload))

  const rootGroups = response.data?.root_groups || []
  const firstGroupId = Number(rootGroups[0]?.id || rootGroups[0]?.effective_product_group_id || 0)
  let catalog = response.data?.catalog

  if (firstGroupId > 0 && !catalog) {
    const catalogResponse = await fetchV2SiteProductGroupCatalog(firstGroupId, config)
    catalog = catalogResponse.data
  }

  return normalizeProductResponse({
    ...response,
    data: {
      ...(response.data || {}),
      catalog,
    },
  }, normalizeProductsInitPayload)
}

export default {
  config: (config) => request.get('/v2/site/config', config),
  home: (config) => request.get('/v2/site/home', config)
    .then((response) => withNormalizedData(response, normalizeSiteHomePayload))
    .then((response) => normalizeProductResponse(response, normalizeSiteHomeProductPayload)),
  homeHero: (config) => request.get('/v2/site/home-hero', config),
  productsInit: (params, config = {}) => fetchV2SiteProductPurchaseContext(params, config),
  productTypes: (config) => request.get('/v2/site/product-types', config).then((response) => normalizeProductResponse(response, normalizeProductTypeListPayload)),
  productGroups: (params, config = {}) => request.get('/v2/site/product-groups', {
    ...config,
    params: {
      page: 1,
      page_size: V2_SITE_PRODUCT_GROUP_PAGE_SIZE,
      ...params,
    },
  }).then((response) => normalizeProductResponse(response, normalizeProductGroupListPayload)),
  productGroupChildren: (groupId, config) => request.get(`/v2/site/product-groups/${groupId}/children`, config).then((response) => normalizeProductResponse(response, normalizeProductGroupListPayload)),
  productGroupProducts: (groupId, params, config = {}) => request.get(`/v2/site/product-groups/${groupId}/products`, { ...config, params }).then((response) => normalizeProductResponse(response, normalizeProductListPayload)),
  productGroupCatalog: (groupId, config) => fetchV2SiteProductGroupCatalog(groupId, config),
  products: (params, config = {}) => request.get('/v2/site/products', { ...config, params }).then((response) => normalizeProductResponse(response, normalizeProductListPayload)),
  productStock: (id, config) => request.get(`/v2/site/products/${id}/stock`, config),
  product: (id, config) => request.get(`/v2/site/products/${id}`, config).then((response) => normalizeProductResponse(response, normalizeProductDetailPayload)),
  productQuote: (id, data, config) => request.post(`/v2/site/products/${id}/quote`, data, config),
  contentOverview: (config) => request.get('/v2/site/content/overview', config).then((response) => withNormalizedData(response, normalizeContentOverviewPayload)),
  notices: (params, config = {}) => request.get('/v2/site/notices', { ...config, params }).then((response) => withNormalizedData(response, normalizeContentListPayload)),
  noticeDetail: (id, config) => request.get(`/v2/site/notices/${id}`, config).then((response) => withNormalizedData(response, normalizeContentDetailPayload)),
  helpArticles: (params, config = {}) => request.get('/v2/site/help-articles', { ...config, params }).then((response) => withNormalizedData(response, normalizeContentListPayload)),
  helpDetail: (id, config) => request.get(`/v2/site/help-articles/${id}`, config).then((response) => withNormalizedData(response, normalizeContentDetailPayload)),
}
