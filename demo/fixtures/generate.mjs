#!/usr/bin/env node
/**
 * 演示站示例数据生成器。
 *
 * 为什么不手写散装 JSON：示例数据必须内部自洽——同一个客户名要同时出现在实例、账单、
 * 工单里，到期时间要跟账期对得上，金额要跟订单明细合得上。基础数据只在 `base.mjs` 定义
 * 一次，三端各自的模块从它派生。
 *
 * 产物：demo/fixtures/{console,admin,user}.json
 * 键是 `METHOD /path`，路径允许 `:id` 通配；值是业务 `data`（传输层自己套 {code,message,data}）。
 *
 * 用法：node demo/fixtures/generate.mjs [--check]
 *   --check  只校验产物是否与生成结果一致（CI 用），不写文件
 */
import { readFileSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

import { adminFixtures } from './admin.mjs';
import { consoleFixtures } from './console.mjs';
import { PRODUCTS, SERVICES, TICKETS } from './base.mjs';
import { userFixtures } from './user.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const checkOnly = process.argv.includes('--check');

const outputs = [
  { file: 'console.json', payload: consoleFixtures({ accounts: 'demo' }) },
  { file: 'admin.json', payload: adminFixtures() },
  { file: 'user.json', payload: userFixtures() },
];

let drifted = 0;

for (const output of outputs) {
  const target = resolve(here, output.file);
  const serialized = `${JSON.stringify(output.payload, null, 2)}\n`;

  if (checkOnly) {
    const current = (() => {
      try {
        return readFileSync(target, 'utf8');
      } catch {
        return '';
      }
    })();

    if (current !== serialized) {
      console.error(`fixture 与生成器不一致：${output.file}`);
      drifted += 1;
    }
    continue;
  }

  writeFileSync(target, serialized, 'utf8');
}

if (checkOnly) {
  if (drifted > 0) {
    console.error(`共 ${drifted} 个 fixture 需要重新生成：node demo/fixtures/generate.mjs`);
    process.exit(1);
  }

  console.log('演示站 fixture 与生成器一致。');
  process.exit(0);
}

console.log(`演示站 fixture 已生成：${outputs.map((item) => item.file).join(', ')}`);
console.log(`基础数据：${SERVICES.length} 台实例 / ${PRODUCTS.length} 个商品 / ${TICKETS.length} 个工单`);
