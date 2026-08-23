import assert from 'node:assert/strict'

import {
  BILLING_CYCLE_LABELS,
  BILLING_CYCLE_ORDER,
  RENEWABLE_BILLING_CYCLES,
  advanceByBillingCycle,
  billingCycleLabel,
  billingCycleMonths,
  billingCycleOptions,
  billingCycleSortRank,
  normalizeBillingCycle,
} from '../billingCycle.js'

// ── 别名归一：这些值都真实存在于接口返回里，收敛前各页面覆盖不齐 ──
assert.equal(normalizeBillingCycle('YEARLY'), 'annually')
assert.equal(normalizeBillingCycle(' onetime '), 'one_time')
assert.equal(normalizeBillingCycle('one-time'), 'one_time')
// biannually 是早期前端把 semiannually 拼错留下的脏数据
assert.equal(normalizeBillingCycle('biannually'), 'semiannually')
assert.equal(normalizeBillingCycle(null), '')

// ── 标签：收敛前 yearly/onetime/free 会在部分页面直接显示英文原文 ──
assert.equal(billingCycleLabel('monthly'), '月付')
assert.equal(billingCycleLabel('semiannually'), '半年付')
assert.equal(billingCycleLabel('yearly'), '年付')
assert.equal(billingCycleLabel('onetime'), '一次性')
assert.equal(billingCycleLabel('free'), '免费')
assert.equal(billingCycleLabel('biannually'), '半年付')

// 未知值：给了兜底用兜底，没给则回退到归一后的原值，不返回空
assert.equal(billingCycleLabel('weird_cycle', '--'), '--')
assert.equal(billingCycleLabel('weird_cycle'), 'weird_cycle')
assert.equal(billingCycleLabel('', '--'), '--')

// ── 月数：未知返回 null，用于区分「0 个月」与「不认识」──
assert.equal(billingCycleMonths('quarterly'), 3)
assert.equal(billingCycleMonths('yearly'), 12)
assert.equal(billingCycleMonths('triennially'), 36)
assert.equal(billingCycleMonths('one_time'), 0)
assert.equal(billingCycleMonths('free'), 0)
assert.equal(billingCycleMonths('weird_cycle'), null)

// ── 排序：未知周期必须排在所有已知周期之后（原 indexOf 会得到 -1 排到最前）──
assert.equal(billingCycleSortRank('monthly'), 0)
assert.ok(billingCycleSortRank('annually') > billingCycleSortRank('quarterly'))
assert.equal(billingCycleSortRank('weird_cycle'), BILLING_CYCLE_ORDER.length)
assert.ok(billingCycleSortRank('weird_cycle') > billingCycleSortRank('free'))

// ── 到期推进：与后端 Carbon addMonths() 同口径，月末必须夹住而不是溢出 ──
// 断言按本地日期比较，避免结果随运行机器时区漂移
const localYmd = (date) =>
  [date.getFullYear(), String(date.getMonth() + 1).padStart(2, '0'), String(date.getDate()).padStart(2, '0')].join('-')

const jan31 = new Date(2026, 0, 31)
assert.equal(localYmd(advanceByBillingCycle(jan31, 'monthly')), '2026-02-28')
// JS 原生 setMonth 会得到 2026-05-01（4 月 31 日溢出），Carbon 夹到 4 月 30 日
assert.equal(localYmd(advanceByBillingCycle(jan31, 'quarterly')), '2026-04-30')
assert.equal(localYmd(advanceByBillingCycle(jan31, 'semiannually')), '2026-07-31')
assert.equal(localYmd(advanceByBillingCycle(jan31, 'annually')), '2027-01-31')
assert.equal(localYmd(advanceByBillingCycle(jan31, 'yearly')), '2027-01-31')
assert.equal(localYmd(advanceByBillingCycle(jan31, 'triennially')), '2029-01-31')

// 闰日跨年同样要夹住：2024-02-29 +1 年 = 2025-02-28，不是 2025-03-01
const leapDay = new Date(2024, 1, 29)
assert.equal(localYmd(advanceByBillingCycle(leapDay, 'annually')), '2025-02-28')
assert.equal(localYmd(advanceByBillingCycle(leapDay, 'biennially')), '2026-02-28')

// 时间部分保持不变（到期时刻不能被推进逻辑抹掉）
const withTime = new Date(2026, 0, 31, 13, 45, 30)
assert.equal(advanceByBillingCycle(withTime, 'monthly').getHours(), 13)
assert.equal(advanceByBillingCycle(withTime, 'monthly').getMinutes(), 45)

// 一次性/免费/未知不产生新到期；入参不得被就地修改
assert.equal(advanceByBillingCycle(jan31, 'one_time'), null)
assert.equal(advanceByBillingCycle(jan31, 'free'), null)
assert.equal(advanceByBillingCycle(jan31, 'weird_cycle'), null)
assert.equal(advanceByBillingCycle('not-a-date', 'monthly'), null)
assert.equal(localYmd(jan31), '2026-01-31')

// ── 白名单刻意窄于标签表：下拉只给可下单周期，但显示仍走同一份文案 ──
assert.deepEqual(RENEWABLE_BILLING_CYCLES, ['monthly', 'quarterly', 'semiannually', 'annually'])
assert.ok(RENEWABLE_BILLING_CYCLES.length < Object.keys(BILLING_CYCLE_LABELS).length)
assert.deepEqual(billingCycleOptions(), [
  { label: '月付', value: 'monthly' },
  { label: '季付', value: 'quarterly' },
  { label: '半年付', value: 'semiannually' },
  { label: '年付', value: 'annually' },
])
// 显式传入时保持给定顺序，并过滤掉不认识的周期
assert.deepEqual(billingCycleOptions(['annually', 'weird_cycle', 'monthly']), [
  { label: '年付', value: 'annually' },
  { label: '月付', value: 'monthly' },
])

// ── 标签表与月数表必须覆盖同一批周期，避免再次出现「能显示但算不出月数」──
assert.deepEqual(Object.keys(BILLING_CYCLE_LABELS), BILLING_CYCLE_ORDER)
BILLING_CYCLE_ORDER.forEach((cycle) => {
  assert.equal(typeof billingCycleMonths(cycle), 'number', `${cycle} 缺月数`)
})

console.log('billing cycle tests passed')
