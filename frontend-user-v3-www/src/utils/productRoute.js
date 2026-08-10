function toPositiveInt(value) {
  const number = Number(value)
  return Number.isInteger(number) && number > 0 ? number : 0
}

export function readWebsiteProductRouteParams(route) {
  const typeId = toPositiveInt(route?.params?.typeId) || toPositiveInt(route?.query?.type)
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
    || (toPositiveInt(payload.typeId) > 0 && toPositiveInt(payload.groupId) > 0)
}

export function buildWebsiteProductPath(payload = {}) {
  const typeId = toPositiveInt(payload.typeId)
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
    typeId: hasChildGroup
      ? toPositiveInt(group.parent_product_type_id) || toPositiveInt(group.product_type_id)
      : toPositiveInt(group.product_type_id),
    groupId: hasChildGroup ? parentGroupId : currentGroupId,
    childGroupId: hasChildGroup ? currentGroupId : 0,
    productId,
  }
}
