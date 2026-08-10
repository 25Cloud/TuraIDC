const DEFAULT_CURRENCY_SYMBOL = '¥'
const DEFAULT_DECIMALS = 2

export interface FormatCurrencyOptions {
  symbol?: string
  decimals?: number
  nullDisplay?: string
}

export function formatCurrency(
  value: number | string | null | undefined,
  options: FormatCurrencyOptions = {}
): string {
  const { symbol = DEFAULT_CURRENCY_SYMBOL, decimals = DEFAULT_DECIMALS, nullDisplay } = options

  if (value === null || value === undefined || value === '') {
    if (nullDisplay !== undefined) return nullDisplay
    return `${symbol}${(0).toFixed(decimals)}`
  }

  const amount = Number(value)
  if (isNaN(amount)) {
    if (nullDisplay !== undefined) return nullDisplay
    return `${symbol}${(0).toFixed(decimals)}`
  }

  return `${symbol}${amount.toFixed(decimals)}`
}
