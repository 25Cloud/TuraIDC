export function resolveNumberOptionBounds(item) {
  const hasExplicitMin = hasExplicitNumber(item?.qty_minimum)
  const hasExplicitMax = hasExplicitNumber(item?.qty_maximum)
  const min = hasExplicitMin ? Number(item.qty_minimum) : 1
  const rawMax = hasExplicitMax ? Number(item.qty_maximum) : 9999

  return {
    min,
    max: Math.max(rawMax, min),
    hasExplicitMin,
    hasExplicitMax,
  }
}

function hasExplicitNumber(value) {
  return value !== undefined && value !== null && value !== '' && Number.isFinite(Number(value))
}
