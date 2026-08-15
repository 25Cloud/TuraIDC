import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const scriptDirectory = path.dirname(fileURLToPath(import.meta.url));
const repositoryRoot = path.resolve(scriptDirectory, '..', '..');
const buildScript = path.join(scriptDirectory, 'build_frontends.mjs');

function run(args) {
  return spawnSync(process.execPath, [buildScript, ...args], {
    cwd: repositoryRoot,
    encoding: 'utf8',
  });
}

// 默认 dry-run：构建目标为全部三个前端
{
  const result = run(['--dry-run']);
  assert.equal(result.status, 0, result.stderr);
  assert.match(result.stdout, /构建目标: admin, www, console/);
}

// --target 逐个
for (const target of ['admin', 'www', 'console']) {
  const result = run(['--dry-run', '--target', target]);
  assert.equal(result.status, 0, result.stderr);
  assert.match(result.stdout, new RegExp(`构建目标: ${target}`));
}

// 非法 --target 报错
{
  const result = run(['--dry-run', '--target', 'unknown']);
  assert.notEqual(result.status, 0);
  assert.match(result.stderr, /--target 只能是 all、admin、www 或 console/);
}

// 不支持的参数报错（如已废弃的 --env）
{
  const result = run(['--dry-run', '--env', 'production']);
  assert.notEqual(result.status, 0);
  assert.match(result.stderr, /不支持的参数/);
}

console.log('frontend build configuration tests passed');
