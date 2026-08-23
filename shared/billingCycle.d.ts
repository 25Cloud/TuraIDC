export declare const BILLING_CYCLE: {
  readonly MONTHLY: 'monthly'
  readonly QUARTERLY: 'quarterly'
  readonly SEMIANNUALLY: 'semiannually'
  readonly ANNUALLY: 'annually'
  readonly BIENNIALLY: 'biennially'
  readonly TRIENNIALLY: 'triennially'
  readonly ONE_TIME: 'one_time'
  readonly FREE: 'free'
}

export type BillingCycleValue = (typeof BILLING_CYCLE)[keyof typeof BILLING_CYCLE]

export declare const BILLING_CYCLE_ALIASES: Record<string, BillingCycleValue>
export declare const BILLING_CYCLE_LABELS: Record<string, string>
export declare const BILLING_CYCLE_MONTHS: Record<string, number>
export declare const BILLING_CYCLE_ORDER: BillingCycleValue[]
export declare const RENEWABLE_BILLING_CYCLES: BillingCycleValue[]

export declare function normalizeBillingCycle(value: unknown): string

/**
 * 入参保持 unknown：调用方拿到的周期多来自接口响应或 props 的宽松类型，
 * 函数本身只做运行时查表并对未知值兜底。
 */
export declare function billingCycleLabel(value: unknown, fallback?: string): string

export declare function billingCycleMonths(value: unknown): number | null

export declare function billingCycleSortRank(value: unknown): number

export declare function advanceByBillingCycle(base: Date | number | string, cycle: unknown): Date | null

export declare function billingCycleOptions(cycles?: string[]): { label: string; value: string }[]
