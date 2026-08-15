import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';

import ts from 'typescript';

const require = createRequire(import.meta.url);
const sourcePath = new URL('../../src/domains/marketing/useCoupons.ts', import.meta.url);
const source = readFileSync(sourcePath, 'utf8');
const sourceWithoutImports = source.replace(
  /^[\s\S]*?type CouponTab/,
  `const computed = (getter) => ({ get value() { return getter(); } });
const onMounted = () => {};
const reactive = (value) => value;
const ref = (value) => ({ value });
const watch = () => {};
const MessagePlugin = { error() {}, success() {} };
const clientApi = {};

type CouponTab`,
);

const { outputText } = ts.transpileModule(sourceWithoutImports, {
  compilerOptions: {
    module: ts.ModuleKind.CommonJS,
    target: ts.ScriptTarget.ES2022,
  },
});

const module = { exports: {} as Record<string, (...args: unknown[]) => unknown> };
new Function('exports', 'require', 'module', outputText)(module.exports, require, module);

const {
  formatCouponAmount,
  resolveDiscountAmountText,
  resolveDiscountTypeLabel,
  resolveDiscountValue,
  resolveStatusTheme,
  resolveThresholdText,
} = module.exports;

assert.equal(formatCouponAmount('20.00'), '20');
assert.equal(formatCouponAmount('20.50'), '20.50');
assert.equal(formatCouponAmount('invalid'), '0');

assert.equal(resolveStatusTheme({ status: 'available' }), 'success');
assert.equal(resolveStatusTheme({ status: 'used_up' }), 'warning');
assert.equal(resolveStatusTheme({ status: 'used_up', status_label: '已使用' }), 'default');
assert.equal(resolveStatusTheme({ status: 'expired', revoked_at: '2026-06-23 12:00:00' }), 'danger');
assert.equal(resolveStatusTheme('revoked'), 'danger');

assert.equal(resolveDiscountTypeLabel('fixed'), '满减券');
assert.equal(resolveDiscountTypeLabel('percentage'), '折扣券');
assert.equal(resolveDiscountTypeLabel('unknown'), '优惠券');

assert.equal(resolveDiscountValue({ discount_type: 'fixed', discount_value: '20.00' }), '￥20');
assert.equal(resolveDiscountValue({ discount_type: 'percentage', discount_value: 85 }), '8.5 折');
assert.equal(resolveDiscountValue({ discount_type: 'percentage', discount_value: 80 }), '8 折');
assert.equal(resolveDiscountValue({ discount_type: 'unknown', discount_label: '专属优惠' }), '专属优惠');

assert.equal(resolveThresholdText({ min_amount: 100 }), '满 ￥100 可用');
assert.equal(resolveThresholdText({ min_amount: 0 }), '无门槛');
assert.equal(resolveDiscountAmountText({ discount_type: 'fixed', discount_value: 30 }), '减 ￥30');
assert.equal(resolveDiscountAmountText({ discount_type: 'percentage', max_discount_amount: 60 }), '最高减 ￥60');

console.log('useCoupons domain tests passed');
