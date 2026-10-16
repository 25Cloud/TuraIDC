import type { Router } from 'vue-router';

/**
 * 管理端「有 ID 就该点得进去」的唯一出口。
 *
 * 背景：管理端全站没有 router-link，也没有任何公共跳转辅助函数，
 * 列表页/详情页里的 ID、编号列此前都是纯文本，用户拿着 ID 也只能自己搜。
 * 这里把「ID 有效性校验 + 目标路径拼装」收敛到一处，页面只负责把行数据传进来，
 * 不要再各自拼字符串——历史上拼错的路径在标签栏里只会 404，排查成本很高。
 */

/** 实体 ID 归一化：非正整数一律判为无效（空值、NaN、0、负数、小数全部拦掉）。 */
function normalizeEntityId(value: unknown): number {
  const id = Number(value);
  return Number.isInteger(id) && id > 0 ? id : 0;
}

/**
 * 该 ID 是否可以用于拼接实体详情路径。
 * 页面用它给按钮绑 `:disabled`，避免无效 ID 点下去跳到空白详情页。
 */
export function isEntityId(value: unknown): boolean {
  return normalizeEntityId(value) > 0;
}

/** 跳用户详情。返回是否真的跳转了，方便调用方按需提示。 */
export function goUser(router: Router, userId: unknown): boolean {
  const id = normalizeEntityId(userId);
  if (!id) return false;
  router.push(`/admin/users/${id}`);
  return true;
}

/**
 * 跳服务实例详情。
 *
 * 坑：该详情页缺 `?user=` 会直接空态（它要先按 user 定位实例），
 * 所以 userId 缺失时宁可不跳，由页面把按钮置灰，也不要跳过去让用户看空白页。
 */
export function goService(router: Router, serviceId: unknown, userId: unknown): boolean {
  const service = normalizeEntityId(serviceId);
  const user = normalizeEntityId(userId);
  if (!service || !user) return false;
  router.push({ path: `/admin/services/${service}`, query: { user: String(user) } });
  return true;
}

/** 服务实例链接是否可用：serviceId 与归属 userId 都有效才算可用。 */
export function canGoService(serviceId: unknown, userId: unknown): boolean {
  return normalizeEntityId(serviceId) > 0 && normalizeEntityId(userId) > 0;
}

/** 跳订单详情。 */
export function goOrder(router: Router, orderId: unknown): boolean {
  const id = normalizeEntityId(orderId);
  if (!id) return false;
  router.push(`/admin/finance/orders/${id}`);
  return true;
}

/** 跳工单详情。 */
export function goTicket(router: Router, ticketId: unknown): boolean {
  const id = normalizeEntityId(ticketId);
  if (!id) return false;
  router.push(`/admin/ticket-conversations/${id}`);
  return true;
}

/** 跳商品编辑页（该路由是 `products/:id/edit`，没有独立的商品详情页）。 */
export function goProductEdit(router: Router, productId: unknown): boolean {
  const id = normalizeEntityId(productId);
  if (!id) return false;
  router.push(`/admin/products/${id}/edit`);
  return true;
}
