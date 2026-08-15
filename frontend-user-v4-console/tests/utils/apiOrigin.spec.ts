import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';

import ts from 'typescript';

const require = createRequire(import.meta.url);
const sourcePath = new URL('../../src/utils/apiOrigin.ts', import.meta.url);
const source = readFileSync(sourcePath, 'utf8');
const { outputText } = ts.transpileModule(source, {
  compilerOptions: {
    module: ts.ModuleKind.CommonJS,
    target: ts.ScriptTarget.ES2022,
  },
});
const module = { exports: {} as Record<string, (...args: unknown[]) => string> };
new Function('exports', 'require', 'module', outputText)(module.exports, require, module);

const { resolveApiManagedAssetUrl, resolveApiOrigin, resolveApiProxyUrl } = module.exports;

assert.equal(resolveApiOrigin('https://api.coyjs.cn/api'), 'https://api.coyjs.cn');
assert.equal(resolveApiOrigin('http://127.0.0.1:8000/api'), 'http://127.0.0.1:8000');
assert.equal(resolveApiOrigin('/api'), '');

assert.equal(
  resolveApiProxyUrl('/api/v2/client/auth/captcha-script', 'https://api.coyjs.cn/api'),
  'https://api.coyjs.cn/api/v2/client/auth/captcha-script',
);
assert.equal(
  resolveApiProxyUrl('https://static.geetest.com/v4/gt4.js', 'https://api.coyjs.cn/api'),
  'https://static.geetest.com/v4/gt4.js',
);
assert.equal(
  resolveApiProxyUrl('//static.geetest.com/v4/gt4.js', 'https://api.coyjs.cn/api'),
  '//static.geetest.com/v4/gt4.js',
);
assert.equal(resolveApiProxyUrl('/api/v2/client/auth/captcha-script', '/api'), '/api/v2/client/auth/captcha-script');

assert.equal(
  resolveApiManagedAssetUrl('/uploads/content/logo.png', 'https://api.coyjs.cn/api'),
  'https://api.coyjs.cn/uploads/content/logo.png',
);
assert.equal(
  resolveApiManagedAssetUrl('/media/site/logo.svg', 'http://127.0.0.1:8000/api'),
  'http://127.0.0.1:8000/media/site/logo.svg',
);
assert.equal(
  resolveApiManagedAssetUrl('uploads/content/logo.png', 'https://api.coyjs.cn/api'),
  'https://api.coyjs.cn/uploads/content/logo.png',
);
assert.equal(resolveApiManagedAssetUrl('/branding/logo.svg', 'https://api.coyjs.cn/api'), '');
assert.equal(resolveApiManagedAssetUrl('/uploads/test.png', '/api'), '/uploads/test.png');

console.log('API origin asset URL tests passed');
