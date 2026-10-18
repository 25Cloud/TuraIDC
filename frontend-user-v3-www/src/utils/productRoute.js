function toPositiveInt(value) {
  const number = Number(value);
  return Number.isInteger(number) && number > 0 ? number : 0;
}

function normalizeTypeId(value) {
  const text = String(value ?? "").trim();
  return text === "" ? "" : text;
}

export function readWebsiteProductRouteParams(route) {
  const typeId =
    normalizeTypeId(route?.params?.typeId) ||
    normalizeTypeId(route?.query?.type);
  const groupId =
    toPositiveInt(route?.params?.groupId) || toPositiveInt(route?.query?.group);
  return {
    typeId,
    groupId,
    // 分组路径不会带商品 ID，三级分组的上下文由 ?child= 承载：
    // /products/:typeId/:groupId/:childGroupId 与 /products/:typeId/:groupId/:productId
    // 的路径形状完全相同（都是数字），路由层无法区分，所以三级分组放查询参数。
    childGroupId:
      toPositiveInt(route?.params?.childGroupId) ||
      toPositiveInt(route?.query?.child),
    productId: toPositiveInt(route?.params?.productId),
  };
}

export function hasWebsiteProductRouteParams(payload = {}) {
  return (
    toPositiveInt(payload.productId) > 0 ||
    (payload.typeId !== "" && toPositiveInt(payload.groupId) > 0)
  );
}

export function buildWebsiteProductPath(payload = {}) {
  const typeId = String(payload.typeId ?? "").trim();
  const groupId = toPositiveInt(payload.groupId);
  const childGroupId = toPositiveInt(payload.childGroupId);
  const productId = toPositiveInt(payload.productId);

  // 没选中商品时也要保留目录上下文（类型 + 分组），否则卡片式地址只剩 /products，
  // 刷新/返回都会丢掉当前分类。三级分组用查询参数，避免与商品 ID 路径混淆。
  if (!typeId || !groupId) {
    return "/products";
  }

  if (!productId) {
    return `/products/${typeId}/${groupId}`;
  }

  if (childGroupId > 0) {
    return `/products/${typeId}/${groupId}/${childGroupId}/${productId}`;
  }

  return `/products/${typeId}/${groupId}/${productId}`;
}

/**
 * 目录路径对应的查询参数：把三级分组放 ?child=，其余查询原样保留。
 * type/group 已经写进路径，旧的查询写法一并清掉，避免出现
 * /products/hosting/112?type=hosting&group=112&child=1030 这种重复地址；
 * 选中商品时三级分组已在路径里，不需要再重复。
 */
export function buildWebsiteProductRouteQuery(currentQuery = {}, payload = {}) {
  const {
    type: _type,
    group: _group,
    child: _child,
    ...rest
  } = currentQuery || {};
  const childGroupId = toPositiveInt(payload.childGroupId);
  const productId = toPositiveInt(payload.productId);

  return !productId && childGroupId > 0
    ? { ...rest, child: String(childGroupId) }
    : rest;
}

export function resolveWebsiteProductRoutePayloadByDetail(product) {
  const group = product?.group || {};
  const productId = toPositiveInt(product?.id);
  const currentGroupId = toPositiveInt(group.id);
  const parentGroupId = toPositiveInt(group.parent_id);
  const hasChildGroup = parentGroupId > 0;

  return {
    typeId: String(group.first_product_group_code || ""),
    groupId: hasChildGroup ? parentGroupId : currentGroupId,
    childGroupId: hasChildGroup ? currentGroupId : 0,
    productId,
  };
}
