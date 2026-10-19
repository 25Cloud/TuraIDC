function toPositiveInt(value) {
  const number = Number(value);
  return Number.isInteger(number) && number > 0 ? number : 0;
}

function normalizeTypeSegment(value) {
  return String(value ?? "").trim();
}

/**
 * 按路由里的类型段（slug 优先，兼容旧 code）找出对应的商品种类。
 *
 * 地址里只写 slug；旧链接/旧代码仍可能带 code（如 `vps`、`type_xo7hkf`），
 * 两者都认，页面后续会把地址规范成 slug。
 *
 * @param {Array<Record<string, any>>} productTypes
 * @param {string} segment
 */
export function resolveWebsiteProductTypeBySegment(productTypes, segment) {
  const normalized = normalizeTypeSegment(segment).toLowerCase();

  if (!normalized || !Array.isArray(productTypes)) {
    return null;
  }

  return (
    productTypes.find(
      (item) => String(item?.slug ?? "").toLowerCase() === normalized,
    ) ||
    productTypes.find(
      (item) => String(item?.value ?? "").toLowerCase() === normalized,
    ) ||
    null
  );
}

/**
 * 读取选购页路由参数。
 *
 * 路径形态（列表式 / 卡片式完全一致，展示方式不参与路由）：
 *  - /products                                                  目录首页
 *  - /products/:typeSlug                                        一级分组目录
 *  - /products/:typeSlug/:groupId                               二级分组目录
 *  - /products/:typeSlug/:groupId/:thirdId                      三级分组目录或旧商品深链（商品 ID）
 *  - /products/:typeSlug/:groupId/:childGroupId/:productId      商品（列表式内联配置 / 卡片式配置页）
 *  - /products/:id                                              旧版独立配置页（兼容）
 *
 * 三段路径里的第三段既可能是三级分组 ID，也可能是历史上商品深链的商品 ID，
 * 两者都是正整数、路径形状相同，路由层无法区分，所以这里只把它标成 thirdId，
 * 由目录数据（children / items_by_group）在页面里判定。
 */
export function readWebsiteProductRouteParams(route) {
  const params = route?.params || {};
  const typeSlug =
    normalizeTypeSegment(params.typeSlug) ||
    normalizeTypeSegment(route?.query?.type);
  const groupId =
    toPositiveInt(params.groupId) || toPositiveInt(route?.query?.group);
  const legacyChildId = toPositiveInt(route?.query?.child);
  const explicitChildId = toPositiveInt(params.childGroupId);
  const explicitProductId = toPositiveInt(params.productId);
  const legacyProductId = toPositiveInt(params.id);

  return {
    typeSlug,
    groupId,
    childGroupId: explicitChildId,
    productId: explicitProductId || legacyProductId,
    thirdId:
      explicitChildId > 0 || explicitProductId > 0
        ? 0
        : toPositiveInt(params.thirdId) || legacyChildId,
    legacyChildId,
    // 地址里显式写了多少层：1=类型、2=二级分组、3=三级分组/旧商品、4=商品。
    // 卡片式按这个层级保持地址深度，列表式会自动补全到商品段。
    depth:
      explicitProductId || legacyProductId
        ? 4
        : explicitChildId > 0
          ? 3
          : toPositiveInt(params.thirdId) > 0 || legacyChildId > 0
            ? 3
            : groupId > 0
              ? 2
              : typeSlug !== ""
                ? 1
                : 0,
  };
}

export function hasWebsiteProductRouteParams(payload = {}) {
  return (
    toPositiveInt(payload.productId) > 0 ||
    toPositiveInt(payload.thirdId) > 0 ||
    normalizeTypeSegment(payload.typeSlug) !== ""
  );
}

/**
 * 生成选购页地址。参数里的 typeSlug 优先，旧调用方传 typeId/code 也兼容。
 *
 * productId 存在但没有 childGroupId 时退回三段旧商品深链（页面会再规范成四段），
 * 避免生成 /products/{type}/{group}/{productId} 与实际层级错位。
 */
export function buildWebsiteProductPath(payload = {}) {
  const typeSegment = normalizeTypeSegment(payload.typeSlug ?? payload.typeId);
  const groupId = toPositiveInt(payload.groupId);
  const childGroupId = toPositiveInt(payload.childGroupId);
  const productId = toPositiveInt(payload.productId);
  // 类型段只允许一段：防止调用方把多段路径拼进来
  const typePath = typeSegment.split("/").filter(Boolean)[0] || "";

  if (!typePath) {
    return "/products";
  }

  if (!groupId) {
    return `/products/${typePath}`;
  }

  if (productId > 0) {
    return childGroupId > 0
      ? `/products/${typePath}/${groupId}/${childGroupId}/${productId}`
      : `/products/${typePath}/${groupId}/${productId}`;
  }

  if (childGroupId > 0) {
    return `/products/${typePath}/${groupId}/${childGroupId}`;
  }

  return `/products/${typePath}/${groupId}`;
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
 * 配置页（商品数据）反推目录路由参数。
 * 详情接口顶层就带 first/second/third 分组字段（ProductGroupHierarchyFields），
 * 优先用它们；老数据/嵌套 group 结构作为兜底。
 */
export function resolveWebsiteProductRoutePayloadByDetail(product) {
  const group = product?.group || {};
  const productId = toPositiveInt(product?.id);
  const typeSlug = String(
    product?.first_product_group_slug ||
      group?.first_product_group_slug ||
      product?.first_product_group_code ||
      group?.first_product_group_code ||
      "",
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
    typeSlug,
    groupId,
    childGroupId,
    productId,
  };
}
