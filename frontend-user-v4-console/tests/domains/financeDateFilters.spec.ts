import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';

import ts from 'typescript';

const require = createRequire(import.meta.url);
const sourcePath = new URL('../../src/domains/finance/dateFilters.ts', import.meta.url);
const source = readFileSync(sourcePath, 'utf8');
const { outputText } = ts.transpileModule(source, {
  compilerOptions: {
    module: ts.ModuleKind.CommonJS,
    target: ts.ScriptTarget.ES2022,
  },
});

const module = { exports: {} as Record<string, (...args: unknown[]) => unknown> };
new Function('exports', 'require', 'module', outputText)(module.exports, require, module);

const { resolveQuickDateRange } = module.exports;

const now = new Date(2026, 6, 3);

assert.deepEqual(resolveQuickDateRange('week', now), {
  start_date: '2026-06-27',
  end_date: '2026-07-03',
});

assert.deepEqual(resolveQuickDateRange('month', now), {
  start_date: '2026-07-01',
  end_date: '2026-07-31',
});

assert.deepEqual(resolveQuickDateRange('pending', now), {});
assert.deepEqual(resolveQuickDateRange('', now), {});

console.log('finance date filter domain tests passed');
