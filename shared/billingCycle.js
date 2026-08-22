/**
 * ============================================================
 *  计费周期 —— 前端唯一真源
 * ============================================================
 *  引入方式：import { billingCycleLabel } from '@shared/billingCycle'
 *
 *  与后端 backend/app/Constants/BillingCycle.php 一一对应，两侧同时改。
 *
 *  收敛前三个前端各自维护标签表（admin 服务列表/服务详情/用户详情、
 *  官网 websiteProductConfig、控制台 useRecords/order-detail 共 6 份），
 *  文案一致但**覆盖范围**长期漂移：
 *    - `yearly`   只有控制台 useRecords 认，其余 4 处显示英文原文
 *    - `onetime`  只有 admin 服务列表与 useRecords 认
 *    - `free`     只有 useRecords 与 order-detail 认
 *    - `biannually`（早期拼写错误，已落库）只有 admin 服务列表认
 *  这些值都会真实出现在接口返回里，于是同一个服务在不同页面显示不同文案。
 */

export const BILLING_CYCLE = {
  MONTHLY: 'monthly',
  QUARTERLY: 'quarterly',
  SEMIANNUALLY: 'semiannually',
  ANNUALLY: 'annually',
  BIENNIALLY: 'biennially',
  TRIENNIALLY: 'triennially',
  ONE_TIME: 'one_time',
  FREE: 'free',
}

/**
 * 历史别名 → 规范值。
 * biannually 是早期前端把 semiannually 拼错留下的脏数据，只做读取兼容，不作为写入值。
 */
export const BILLING_CYCLE_ALIASES = {
  onetime: BILLING_CYCLE.ONE_TIME,
  'one-time': BILLING_CYCLE.ONE_TIME,
  yearly: BILLING_CYCLE.ANNUALLY,
  biannually: BILLING_CYCLE.SEMIANNUALLY,
}

export const BILLING_CYCLE_LABELS = {
  [BILLING_CYCLE.MONTHLY]: '月付',
  [BILLING_CYCLE.QUARTERLY]: '季付',
  [BILLING_CYCLE.SEMIANNUALLY]: '半年付',
  [BILLING_CYCLE.ANNUALLY]: '年付',
  [BILLING_CYCLE.BIENNIALLY]: '两年付',
  [BILLING_CYCLE.TRIENNIALLY]: '三年付',
  [BILLING_CYCLE.ONE_TIME]: '一次性',
  [BILLING_CYCLE.FREE]: '免费',
}

/** 周期对应的自然月数。一次性与免费不产生续期，记 0。 */
export const BILLING_CYCLE_MONTHS = {
  [BILLING_CYCLE.MONTHLY]: 1,
  [BILLING_CYCLE.QUARTERLY]: 3,
  [BILLING_CYCLE.SEMIANNUALLY]: 6,
  [BILLING_CYCLE.ANNUALLY]: 12,
  [BILLING_CYCLE.BIENNIALLY]: 24,
  [BILLING_CYCLE.TRIENNIALLY]: 36,
  [BILLING_CYCLE.ONE_TIME]: 0,
  [BILLING_CYCLE.FREE]: 0,
}

/** 展示与排序用的规范顺序，由短到长。 */
export const BILLING_CYCLE_ORDER = [
  BILLING_CYCLE.MONTHLY,
  BILLING_CYCLE.QUARTERLY,
  BILLING_CYCLE.SEMIANNUALLY,
  BILLING_CYCLE.ANNUALLY,
  BILLING_CYCLE.BIENNIALLY,
  BILLING_CYCLE.TRIENNIALLY,
  BILLING_CYCLE.ONE_TIME,
  BILLING_CYCLE.FREE,
]

/**
 * 允许下单/续费的周期白名单。
 *
 * 刻意窄于 BILLING_CYCLE_LABELS：这是业务允许的取值集合（下拉枚举与校验），
 * 而 LABELS 回答「任何已落库的值该怎么显示」。两者不可互相替代。
 */
export const RENEWABLE_BILLING_CYCLES = [
  BILLING_CYCLE.MONTHLY,
  BILLING_CYCLE.QUARTERLY,
  BILLING_CYCLE.SEMIANNUALLY,
  BILLING_CYCLE.ANNUALLY,
]

/** 别名归一 + 去空白 + 转小写。无法识别时返回归一后的原值，便于排查脏数据。 */
export function normalizeBillingCycle(value) {
  const normalized = String(value ?? '')
    .trim()
    .toLowerCase()

  return BILLING_CYCLE_ALIASES[normalized] ?? normalized
}

/**
 * 周期 → 中文标签。
 *
 * @param {unknown} value 接口返回的周期值，允许为空或别名
 * @param {string} fallback 未知周期的兜底文案；留空则回退到归一后的原值
 */
export function billingCycleLabel(value, fallback = '') {
  const normalized = normalizeBillingCycle(value)
  const label = BILLING_CYCLE_LABELS[normalized]

  if (label) return label
  if (fallback) return fallback

  return normalized
}

/** 周期 → 月数。未知周期返回 null，便于区分「0 个月」与「不认识」。 */
export function billingCycleMonths(value) {
  const months = BILLING_CYCLE_MONTHS[normalizeBillingCycle(value)]

  return months === undefined ? null : months
}

/** 排序序号，值越小越靠前；未知周期排在所有已知周期之后。 */
export function billingCycleSortRank(value) {
  const index = BILLING_CYCLE_ORDER.indexOf(normalizeBillingCycle(value))

  return index === -1 ? BILLING_CYCLE_ORDER.length : index
}

/**
 * 加月份并夹住月末，与后端 BillingCycle::advance() 的 addMonthsNoOverflow() 一致。
 *
 * JS 原生 setMonth 与 PHP Carbon 的 addMonths() **都会溢出**：
 * 1 月 31 日 +3 个月得到 5 月 1 日（4 月 31 日不存在）。用在到期日上，
 * 「月付」会直接跳过 2 月、到期日每期向后漂移，账期与月份对不上。
 * 两侧现在统一夹到当月最后一天（4 月 30 日）。
 */
function addMonthsClamped(date, months) {
  const day = date.getDate()
  const result = new Date(date.getTime())

  // 先归到 1 号再加月，避免加月过程中就发生溢出
  result.setDate(1)
  result.setMonth(result.getMonth() + months)

  const lastDayOfTargetMonth = new Date(result.getFullYear(), result.getMonth() + 1, 0).getDate()
  result.setDate(Math.min(day, lastDayOfTargetMonth))

  return result
}

/**
 * 按周期推进到期时间，返回新的 Date（不修改入参）。
 *
 * 一次性、免费与未知周期返回 null——它们不产生新的到期时间，由调用方兜底。
 * 月数取自 BILLING_CYCLE_MONTHS，与后端 BillingCycle::advance() 同一张表。
 *
 * @param {Date|number|string} base 起算时间
 * @param {unknown} cycle 周期值
 * @returns {Date|null}
 */
export function advanceByBillingCycle(base, cycle) {
  const date = base instanceof Date ? new Date(base.getTime()) : new Date(base)
  if (Number.isNaN(date.getTime())) return null

  const months = billingCycleMonths(cycle)
  if (!months) return null

  return addMonthsClamped(date, months)
}

/**
 * 生成下拉选项，保持传入顺序；默认为可续费周期。
 *
 * @param {string[]} cycles
 * @returns {{ label: string, value: string }[]}
 */
export function billingCycleOptions(cycles = RENEWABLE_BILLING_CYCLES) {
  return cycles
    .map((cycle) => normalizeBillingCycle(cycle))
    .filter((cycle) => BILLING_CYCLE_LABELS[cycle])
    .map((cycle) => ({ label: BILLING_CYCLE_LABELS[cycle], value: cycle }))
}
