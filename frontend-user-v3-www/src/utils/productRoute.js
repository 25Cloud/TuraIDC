function toPositiveInt(value) {
  const number = Number(value)
  return Number.isInteger(number) && number > 0 ? number : 0
}

function normalizeTypeId(value) {
  const text = String(value ?? '').trim()
  return text === '' ? '' : text
}

export function readWebsiteProductRouteParams(route) {
  const typeId = normalizeTypeId(route?.params?.typeId) || normalizeTypeId(route?.query?.type)
  const groupId = toPositiveInt(route?.params?.groupId) || toPositiveInt(route?.query?.group)
  return {
    typeId,
    groupId,
    childGroupId: toPositiveInt(route?.params?.childGroupId),
    productId: toPositiveInt(route?.params?.productId),
  }
}

export function hasWebsiteProductRouteParams(payload = {}) {
  return toPositiveInt(payload.productId) > 0
    || (payload.typeId !== '' && toPositiveInt(payload.groupId) > 0)
}

export function buildWebsiteProductPath(payload = {}) {
  const typeId = String(payload.typeId ?? '').trim()
  const groupId = toPositiveInt(payload.groupId)
  const childGroupId = toPositiveInt(payload.childGroupId)
  const productId = toPositiveInt(payload.productId)

  if (!typeId || !groupId || !productId) {
    return '/products'
  }

  if (childGroupId > 0) {
    return `/products/${typeId}/${groupId}/${childGroupId}/${productId}`
  }

  return `/products/${typeId}/${groupId}/${productId}`
}

export function resolveWebsiteProductRoutePayloadByDetail(product) {
  const group = product?.group || {}
  const productId = toPositiveInt(product?.id)
  const currentGroupId = toPositiveInt(group.id)
  const parentGroupId = toPositiveInt(group.parent_id)
  const hasChildGroup = parentGroupId > 0

  return {
    typeId: String(group.first_product_group_code || ''),
    groupId: hasChildGroup ? parentGroupId : currentGroupId,
    childGroupId: hasChildGroup ? currentGroupId : 0,
    productId,
  }
}
