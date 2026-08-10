function pickFirst(...values) {
  return values.find((value) => value !== undefined && value !== null && value !== '')
}

function toNumber(value, fallback = 0) {
  const normalized = Number(value)
  return Number.isFinite(normalized) ? normalized : fallback
}

function toText(value, fallback = '') {
  const picked = pickFirst(value, fallback)
  return picked === undefined || picked === null ? '' : String(picked)
}

function toPayloadObject(data) {
  return data && typeof data === 'object' && !Array.isArray(data) ? data : {}
}

export function normalizeProductType(item = {}, index = 0) {
  const value = toText(pickFirst(item.first_product_group_code, item.value, item.product_type))
  const productType = toText(item.product_type)
  const label = toText(pickFirst(item.first_product_group_name, item.label, item.product_type_label, item.type_label, value))
  const firstProductGroupId = toNumber(item.first_product_group_id, 0)
  const id = firstProductGroupId || toNumber(item.id, index + 1)

  return {
    ...item,
    id,
    value,
    label,
    product_type: productType,
    product_type_id: id,
    product_type_label: toText(pickFirst(item.product_type_label, item.type_label, productType)),
    first_product_group_id: firstProductGroupId || null,
    first_product_group_code: value,
    first_product_group_name: label,
    group_count: toNumber(item.group_count, 0),
    product_count: toNumber(item.product_count, 0),
  }
}

export function normalizeProductGroup(item = {}, index = 0) {
  const secondProductGroupId = toNumber(item.second_product_group_id, 0)
  const thirdProductGroupId = toNumber(item.third_product_group_id, 0)
  const effectiveProductGroupId = toNumber(
    pickFirst(item.effective_product_group_id, thirdProductGroupId, secondProductGroupId, item.id),
    index + 1,
  )
  const id = effectiveProductGroupId
  const firstCode = toText(pickFirst(item.first_product_group_code, item.value, item.product_type))
  const productType = toText(item.product_type)
  const firstName = toText(pickFirst(item.first_product_group_name, item.label, firstCode))
  const productTypeLabel = toText(pickFirst(item.product_type_label, item.type_label, productType))
  const firstProductGroupId = toNumber(item.first_product_group_id, 0)
  const level = toNumber(
    pickFirst(item.effective_product_group_level, thirdProductGroupId > 0 ? 3 : secondProductGroupId > 0 ? 2 : item.level),
    0,
  )

  return {
    ...item,
    id,
    parent_id: toNumber(item.parent_id, 0) || null,
    product_type: productType,
    product_type_id: toNumber(item.product_type_id, 0),
    product_type_label: productTypeLabel,
    first_product_group_id: firstProductGroupId || null,
    first_product_group_code: firstCode,
    first_product_group_name: firstName,
    second_product_group_id: secondProductGroupId || null,
    second_product_group_name: toText(pickFirst(item.second_product_group_name, level === 2 ? item.name : '')),
    third_product_group_id: thirdProductGroupId || null,
    third_product_group_name: toText(pickFirst(item.third_product_group_name, level === 3 ? item.name : '')) || null,
    effective_product_group_id: effectiveProductGroupId || null,
    effective_product_group_level: level || null,
    name: toText(item.name),
    slogan: toText(item.slogan),
    slug: toText(item.slug),
    children_count: toNumber(item.children_count, 0),
    direct_product_count: toNumber(item.direct_product_count, 0),
    product_count: toNumber(item.product_count, 0),
    children: Array.isArray(item.children)
      ? item.children.map((child, childIndex) => normalizeProductGroup(child, childIndex))
      : [],
  }
}

export function normalizeProduct(item = {}) {
  const firstCode = toText(pickFirst(item.first_product_group_code, item.value, item.product_type))
  const productType = toText(item.product_type)
  const firstName = toText(pickFirst(item.first_product_group_name, item.label, firstCode))
  const productTypeLabel = toText(pickFirst(item.product_type_label, item.type_label, productType))
  const group = item.group && typeof item.group === 'object'
    ? normalizeProductGroup(item.group)
    : item.group

  return {
    ...item,
    product_type: productType,
    type: productType,
    type_label: productTypeLabel,
    first_product_group_id: toNumber(item.first_product_group_id, 0) || null,
    first_product_group_code: firstCode,
    first_product_group_name: firstName,
    second_product_group_id: toNumber(item.second_product_group_id, 0) || null,
    third_product_group_id: toNumber(item.third_product_group_id, 0) || null,
    effective_product_group_id: toNumber(item.effective_product_group_id, 0) || null,
    effective_product_group_level: toNumber(item.effective_product_group_level, 0) || null,
    group,
  }
}

export function normalizeProductTypeListPayload(data = {}) {
  const list = Array.isArray(data) ? data : data?.list

  return Array.isArray(data)
    ? list.map(normalizeProductType)
    : {
      ...toPayloadObject(data),
      list: Array.isArray(list) ? list.map(normalizeProductType) : [],
    }
}

export function normalizeProductGroupListPayload(data = {}) {
  const list = Array.isArray(data) ? data : data?.list

  return Array.isArray(data)
    ? list.map(normalizeProductGroup)
    : {
      ...toPayloadObject(data),
      list: Array.isArray(list) ? list.map(normalizeProductGroup) : [],
    }
}

export function normalizeProductGroupCatalogPayload(data = {}) {
  const payload = toPayloadObject(data)

  return {
    ...payload,
    children: Array.isArray(payload.children)
      ? payload.children.map(normalizeProductGroup)
      : [],
    items_by_group: Array.isArray(payload.items_by_group)
      ? payload.items_by_group.map((item) => ({
        ...item,
        products: Array.isArray(item.products) ? item.products.map(normalizeProduct) : [],
      }))
      : [],
  }
}

export function normalizeProductsInitPayload(data = {}) {
  const payload = toPayloadObject(data)

  return {
    ...payload,
    types: Array.isArray(payload.types) ? payload.types.map(normalizeProductType) : [],
    root_groups: Array.isArray(payload.root_groups) ? payload.root_groups.map(normalizeProductGroup) : [],
    catalog: payload.catalog && typeof payload.catalog === 'object'
      ? normalizeProductGroupCatalogPayload(payload.catalog)
      : payload.catalog,
  }
}

export function normalizeSiteHomeProductPayload(data = {}) {
  const payload = toPayloadObject(data)
  const catalogMap = payload.group_catalog_map && typeof payload.group_catalog_map === 'object'
    ? Object.entries(payload.group_catalog_map).reduce((map, [key, value]) => {
      if (!value || typeof value !== 'object') {
        map[key] = value
        return map
      }

      map[key] = {
        ...value,
        featured_product: value.featured_product && typeof value.featured_product === 'object'
          ? normalizeProduct(value.featured_product)
          : value.featured_product,
        preview_products: Array.isArray(value.preview_products)
          ? value.preview_products.map(normalizeProduct)
          : [],
      }

      return map
    }, {})
    : {}

  return {
    ...payload,
    root_groups: Array.isArray(payload.root_groups) ? payload.root_groups.map(normalizeProductGroup) : [],
    group_catalog_map: catalogMap,
  }
}

export function normalizeProductDetailPayload(data = {}) {
  const payload = toPayloadObject(data)

  if (!payload.product || typeof payload.product !== 'object') {
    return payload
  }

  return {
    ...payload,
    product: normalizeProduct(payload.product),
  }
}

export function normalizeProductResponse(response, normalizer) {
  return {
    ...response,
    data: normalizer(response?.data),
  }
}
