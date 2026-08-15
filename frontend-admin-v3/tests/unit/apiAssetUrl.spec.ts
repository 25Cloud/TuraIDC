import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';

import ts from 'typescript';

const require = createRequire(import.meta.url);
const sourcePath = new URL('../../src/utils/apiAssetUrl.ts', import.meta.url);
const source = readFileSync(sourcePath, 'utf8');
const { outputText } = ts.transpileModule(source, {
  compilerOptions: {
    module: ts.ModuleKind.CommonJS,
    target: ts.ScriptTarget.ES2022,
  },
});
const module = { exports: {} as Record<string, (...args: unknown[]) => string> };
new Function('exports', 'require', 'module', outputText)(module.exports, require, module);

const { resolveApiAssetUrl } = module.exports;

assert.equal(
  resolveApiAssetUrl('/uploads/content/logo.png', 'https://api.coyjs.cn/api'),
  'https://api.coyjs.cn/uploads/content/logo.png',
);
assert.equal(
  resolveApiAssetUrl('/media/site/logo.svg', 'http://127.0.0.1:8000/api'),
  'http://127.0.0.1:8000/media/site/logo.svg',
);
assert.equal(
  resolveApiAssetUrl('uploads/content/logo.png', 'https://api.coyjs.cn/api'),
  'https://api.coyjs.cn/uploads/content/logo.png',
);
assert.equal(resolveApiAssetUrl('/branding/logo.svg', 'https://api.coyjs.cn/api'), '/branding/logo.svg');
assert.equal(
  resolveApiAssetUrl('https://cdn.example.com/logo.svg', 'https://api.coyjs.cn/api'),
  'https://cdn.example.com/logo.svg',
);

console.log('admin API asset URL tests passed');
