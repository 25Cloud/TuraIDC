import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';

import ts from 'typescript';

const require = createRequire(import.meta.url);
const sourcePath = new URL('../../src/domains/services/console/useConsoleCore.ts', import.meta.url);
const source = readFileSync(sourcePath, 'utf8');

// 只取 tab 组装相关的纯函数，避免把 Vue 运行时依赖拉进断言脚本
const sliced = source.slice(
  source.indexOf('export const DEFAULT_TAB'),
  source.indexOf('export function findSpecValue'),
);
const { outputText } = ts.transpileModule(sliced, {
  compilerOptions: {
    module: ts.ModuleKind.CommonJS,
    target: ts.ScriptTarget.ES2022,
  },
});

interface ResolvedTabs {
  keys: string[];
  areaLabels: Record<string, string>;
}

const module = { exports: {} as Record<string, unknown> };
new Function('exports', 'require', 'module', outputText)(module.exports, require, module);

const resolveAvailableTabs = module.exports.resolveAvailableTabs as (
  detail: Record<string, unknown>,
  capabilities?: Record<string, unknown> | null,
) => ResolvedTabs;
const isPanelConsole = module.exports.isPanelConsole as (detail: Record<string, unknown>) => boolean;

const baseDetail = {
  id: 1,
  status: 0,
  billing_cycle: '',
  machine_category: { key: 'cloud_server', label: '云服务器' },
  product: { id: 1, name: '云服务器', type: 'cloud_server' },
};

// 云服务器：能力不可用时回退云主机集合
assert.deepEqual(resolveAvailableTabs(baseDetail, null).keys, [
  'overview',
  'monitor',
  'security',
  'logs',
  'finance',
  'vnc',
]);

// CDN：面板型产品，兜底只保留 overview + finance，不套用云主机能力 tab
const cdnDetail = { ...baseDetail, machine_category: { key: 'cdn', label: 'CDN' } };
assert.equal(isPanelConsole(cdnDetail), true);
assert.deepEqual(resolveAvailableTabs(cdnDetail, null).keys, ['overview', 'finance']);

// 虚拟主机同样按面板型处理
const hostingDetail = { ...baseDetail, machine_category: { key: 'web_hosting', label: '虚拟主机' } };
assert.deepEqual(resolveAvailableTabs(hostingDetail, null).keys, ['overview', 'finance']);

// 面板型产品在能力可用时插入上游下发的自定义区域，且不追加监控/安全/VNC
const capabilities = {
  supported: true,
  fetchable: true,
  areas: [
    { key: 'info', name: '配置信息' },
    { key: 'domain', name: '域名管理' },
  ],
  nat_supported: false,
  monitor_supported: false,
};
const cdnTabs = resolveAvailableTabs(cdnDetail, capabilities);
assert.deepEqual(cdnTabs.keys, ['overview', 'info', 'domain', 'finance']);
assert.equal(cdnTabs.areaLabels.info, '配置信息');
assert.equal(cdnTabs.areaLabels.domain, '域名管理');

// 云服务器能力可用时仍保留全部内置能力 tab
const cloudTabs = resolveAvailableTabs(baseDetail, capabilities);
assert.deepEqual(cloudTabs.keys, ['overview', 'info', 'domain', 'monitor', 'security', 'logs', 'finance', 'vnc']);

console.log('console tab domain tests passed');
