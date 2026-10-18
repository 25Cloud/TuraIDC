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

/**
 * 商品列表价格拆成「金额 + 周期」两段：卡片式要把金额放大、周期做小字，
 * 列表式仍用拼好的整串。两处同源，避免金额口径分叉。
 */
export function formatProductListPriceParts(product) {
  const pricingEntries = Array.isArray(product?.pricing_entries)
    ? product.pricing_entries
    : [];
  const monthlyEntry = pricingEntries.find(
    (item) => item?.cycle === "monthly" && Number(item?.amount || 0) > 0,
  );
  const fallbackEntry =
    monthlyEntry ||
    pricingEntries.find((item) => Number(item?.amount || 0) > 0);

  if (fallbackEntry) {
    return {
      amount: formatMoneyText(fallbackEntry.amount),
      cycle: fallbackEntry.label || "月",
    };
  }

  const pricing =
    product?.pricing && typeof product.pricing === "object"
      ? product.pricing
      : {};
  const monthlyAmount = Number(pricing.monthly || 0);
  if (monthlyAmount > 0) {
    return { amount: formatMoneyText(monthlyAmount), cycle: "月" };
  }

  if (Number(product?.primary_price || 0) > 0) {
    return {
      amount: formatMoneyText(product.primary_price),
      cycle: product.primary_cycle === "monthly" ? "月" : "起",
    };
  }

  return null;
}

export function formatProductListPrice(product) {
  const parts = formatProductListPriceParts(product);

  return parts ? `¥${parts.amount}/${parts.cycle}` : "";
}

/**
 * 卡片式要的最小价格字段集合。列表式表格另有一套列渲染，不共用这里。
 */
export function resolveCardPrice(product) {
  const parts = formatProductListPriceParts(product);
  const agentPrice = Number(product?.agent_primary_price || 0);

  return {
    hasAgentPrice: Boolean(product?.has_agent_discount) && agentPrice > 0,
    agentPrice,
    agentAmount: formatMoneyText(product?.agent_primary_price),
    agentOriginal: formatMoneyText(product?.primary_price),
    agentPercent: String(product?.agent_discount_percent || ""),
    listAmount: parts?.amount || "",
    listCycle: parts?.cycle || "",
    listPrice: parts ? `¥${parts.amount}/${parts.cycle}` : "",
  };
}
