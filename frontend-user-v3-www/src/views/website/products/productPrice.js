/**
 * 商品价格展示口径（列表式表格与卡片式卡片共用）。
 *
 * 单独成文件而不是各自写一份：两种展示形态并存在同一页，价格文案一旦分叉，
 * 运营切形态时会看到「表格 ¥88.00/月、卡片 ¥88元/月」这种对不上的情况。
 */

export function formatMoneyText(value) {
  const amount = Number(value || 0);
  return amount.toFixed(2);
}

export function formatProductListPrice(product) {
  const pricingEntries = Array.isArray(product?.pricing_entries)
    ? product.pricing_entries
    : [];
  const monthlyEntry = pricingEntries.find(
    (item) => item?.cycle === 'monthly' && Number(item?.amount || 0) > 0,
  );
  const fallbackEntry =
    monthlyEntry ||
    pricingEntries.find((item) => Number(item?.amount || 0) > 0);

  if (fallbackEntry) {
    return `¥${formatMoneyText(fallbackEntry.amount)}/${fallbackEntry.label || '月'}`;
  }

  const pricing =
    product?.pricing && typeof product.pricing === 'object' ? product.pricing : {};
  const monthlyAmount = Number(pricing.monthly || 0);
  if (monthlyAmount > 0) {
    return `¥${formatMoneyText(monthlyAmount)}/月`;
  }

  if (Number(product?.primary_price || 0) > 0) {
    return `¥${formatMoneyText(product.primary_price)}/${product.primary_cycle === 'monthly' ? '月' : '起'}`;
  }

  return '';
}

/**
 * 卡片式要的最小价格字段集合。列表式表格另有一套列渲染，不共用这里。
 */
export function resolveCardPrice(product) {
  const agentPrice = Number(product?.agent_primary_price || 0);

  return {
    hasAgentPrice: Boolean(product?.has_agent_discount) && agentPrice > 0,
    agentPrice,
    agentOriginal: formatMoneyText(product?.primary_price),
    agentPercent: String(product?.agent_discount_percent || ''),
    listPrice: formatProductListPrice(product),
  };
}