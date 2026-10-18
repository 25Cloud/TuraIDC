function toPositiveInt(value) {
  const number = Number(value);
  return Number.isInteger(number) && number > 0 ? number : 0;
}

function normalizeTypeId(value) {
  const text = String(value ?? "").trim();
  return text === "" ? "" : text;
}

/**
 * 读取选购页路由参数。
 *
 * 路径形态：
 *  - /products                                  目录首页
 *  - /products/:typeId/:groupId                 目录（二级分组）
 *  - /products/:typeId/:groupId/:thirdId        目录（三级分组）或旧商品深链（商品 ID）
 *  - /products/:typeId/:groupId/:childGroupId/:productId  商品 + 三级分组
 *  - /products/:id                              独立配置页
 *
 * 三段路径里的第三段既可能是三级分组 ID，也可能是历史上商品深链的商品 ID，
 * 两者都是正整数、路径形状相同，路由层无法区分，所以这里只把它标成 thirdId，
 * 由目录数据（children / items_by_group）在页面里判定。
 */
export function readWebsiteProductRouteParams(route) {
  const params = route?.params || {};
  const typeId =
    normalizeTypeId(params.typeId) || normalizeTypeId(route?.query?.type);
  const groupId =
    toPositiveInt(params.groupId) || toPositiveInt(route?.query?.group);
  const legacyChildId = toPositiveInt(route?.query?.child);
  const explicitChildId = toPositiveInt(params.childGroupId);
  const explicitProductId = toPositiveInt(params.productId);

  if (explicitChildId > 0 || explicitProductId > 0) {
    return {
      typeId,
      groupId,
      childGroupId: explicitChildId,
      productId: explicitProductId,
      thirdId: 0,
      legacyChildId,
    };
  }

  return {
    typeId,
    groupId,
    childGroupId: 0,
    productId: 0,
    thirdId: toPositiveInt(params.thirdId) || legacyChildId,
    legacyChildId,
  };
}

export function hasWebsiteProductRouteParams(payload = {}) {
  return (
    toPositiveInt(payload.productId) > 0 ||
    toPositiveInt(payload.thirdId) > 0 ||
    (payload.typeId !== "" && toPositiveInt(payload.groupId) > 0)
  );
}

export function buildWebsiteProductPath(payload = {}) {
  const typeId = String(payload.typeId ?? "").trim();
  const groupId = toPositiveInt(payload.groupId);
  const childGroupId = toPositiveInt(payload.childGroupId);
  const productId = toPositiveInt(payload.productId);

  // 没选中商品时也要保留目录上下文（类型 + 分组 + 三级分组），否则卡片式地址
  // 只剩 /products，刷新/返回都会丢掉当前分类。
  if (!typeId || !groupId) {
    return "/products";
  }

  if (productId > 0) {
    return childGroupId > 0
      ? `/products/${typeId}/${groupId}/${childGroupId}/${productId}`
      : `/products/${typeId}/${groupId}/${productId}`;
  }

  if (childGroupId > 0) {
    return `/products/${typeId}/${groupId}/${childGroupId}`;
  }

  return `/products/${typeId}/${groupId}`;
}

/**
 * 目录路径对应的查询参数：type/group/三级分组都已写进路径，
 * 旧的查询写法（?type= / ?group= / ?child=）一并清掉，
 * 其他查询（优惠券等）原样保留。
 */
export function buildWebsiteProductRouteQuery(currentQuery = {}) {
  const {
    type: _type,
    group: _group,
    child: _child,
    ...rest
  } = currentQuery || {};

  return rest;
}

/**
 * 独立配置页（只有商品 ID）反推目录路由参数。
 * 详情接口顶层就带 first/second/third 分组字段（ProductGroupHierarchyFields），
 * 优先用它们；老数据/嵌套 group 结构作为兜底。
 */
export function resolveWebsiteProductRoutePayloadByDetail(product) {
  const group = product?.group || {};
  const productId = toPositiveInt(product?.id);
  const typeId = String(
    product?.first_product_group_code || group?.first_product_group_code || "",
  ).trim();
  const groupId =
    toPositiveInt(product?.second_product_group_id) ||
    toPositiveInt(group?.second_product_group_id) ||
    toPositiveInt(group?.parent_id) ||
    toPositiveInt(group?.id);
  const childGroupId =
    toPositiveInt(product?.third_product_group_id) ||
    toPositiveInt(group?.third_product_group_id);

  return {
    typeId,
    groupId,
    childGroupId,
    productId,
  };
}
