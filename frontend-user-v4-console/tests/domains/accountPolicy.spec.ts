import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';

import ts from 'typescript';

/**
 * 账户策略快照的纯逻辑单测。
 *
 * 判断权在后端：这里只验证「枚举码 → 前端落点」的映射与未知码过滤，
 * 不验证「要不要实名」——那条口径只有后端 AccountPolicyService 说了算。
 */
const require = createRequire(import.meta.url);
const sourcePath = new URL('../../src/utils/accountPolicy.ts', import.meta.url);
const source = readFileSync(sourcePath, 'utf8');
const sourceWithoutImports = source.replace(
  /^[\s\S]*?const ITEM_ROUTES/,
  `const ref = (value) => ({ value });

const ITEM_ROUTES`,
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
  currentMissingItems,
  currentPolicyMessage,
  firstPolicyItemRoute,
  isAccountPolicyItem,
  policyItemRoute,
  setAccountPolicy,
} = module.exports as {
  currentMissingItems: () => string[];
  currentPolicyMessage: () => string;
  firstPolicyItemRoute: (items: readonly string[]) => { path: string; query?: Record<string, string> } | null;
  isAccountPolicyItem: (value: unknown) => boolean;
  policyItemRoute: (item: string) => { path: string; query?: Record<string, string> };
  setAccountPolicy: (policy: unknown) => void;
};

// 后端没下发策略：整块不渲染（空缺失项 + 空文案）
setAccountPolicy(null);
assert.deepEqual(currentMissingItems(), []);
assert.equal(currentPolicyMessage(), '');
assert.deepEqual(firstPolicyItemRoute([]), null);

// 未知枚举码要被过滤掉，不能凭空造出一个跳转目标
setAccountPolicy({
  require_verification: true,
  require_phone: true,
  require_email: false,
  missing_items: ['verification', 'phone', 'unknown_item'],
  message: '  继续操作前请先完成实名认证、绑定手机号  ',
});
assert.deepEqual(currentMissingItems(), ['verification', 'phone']);
assert.equal(currentPolicyMessage(), '继续操作前请先完成实名认证、绑定手机号');
assert.deepEqual(firstPolicyItemRoute(currentMissingItems()), { path: '/client/verification' });

// 缺失项 → 落点：实名去实名页，手机/邮箱落到个人资料页的绑定弹窗
assert.deepEqual(policyItemRoute('verification'), { path: '/client/verification' });
assert.deepEqual(policyItemRoute('phone'), { path: '/client/profile', query: { bind: 'phone' } });
assert.deepEqual(policyItemRoute('email'), { path: '/client/profile', query: { bind: 'email' } });

// 40301 错误响应里的 missing_items 直接决定按钮：只缺邮箱时指向邮箱绑定
assert.deepEqual(firstPolicyItemRoute(['email']), { path: '/client/profile', query: { bind: 'email' } });
assert.deepEqual(firstPolicyItemRoute(['phone', 'email']), { path: '/client/profile', query: { bind: 'phone' } });

assert.equal(isAccountPolicyItem('verification'), true);
assert.equal(isAccountPolicyItem('email'), true);
assert.equal(isAccountPolicyItem('unknown_item'), false);
assert.equal(isAccountPolicyItem(undefined), false);

// 补齐后（空数组）提示条与对话框动作都应消失
setAccountPolicy({ missing_items: [], message: '' });
assert.deepEqual(currentMissingItems(), []);
assert.deepEqual(firstPolicyItemRoute([]), null);

console.log('accountPolicy domain tests passed');
