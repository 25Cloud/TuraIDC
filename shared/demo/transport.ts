import type { AxiosAdapter, AxiosInstance, AxiosResponse, InternalAxiosRequestConfig } from 'axios';

import { installDemoNav } from './nav';

/** 演示站的三端标识，只用于诊断信息与角标文案 */
export type DemoApp = 'console' | 'admin' | 'user';

/**
 * 一条示例响应的载荷。
 *
 * 绝大多数情况直接给业务 `data`，传输层自己套 `{code: 0, message: 'success', data}`；
 * 需要演示业务失败（比如危险动作拒绝）时给 `{code, message}` 对象覆盖。
 */
export type DemoFixturePayload = unknown;

export interface DemoFixtureFile {
  /**
   * 键是 `METHOD /path`（方法大写，路径不含查询串），路径里允许 `:id` 这类通配段。
   *
   * 例如 `"GET /v2/client/services/:id": { ... }`
   */
  endpoints: Record<string, DemoFixturePayload>;
  /** 命中也返回业务错误的写操作，键的写法同上；用于「删实例 / 退款」这类不该在演示站生效的动作 */
  refused?: Record<string, { code?: number; message?: string }>;
}

export interface DemoTransportOptions {
  app: DemoApp;
  /** 懒加载示例数据；由各端传入，避免 shared 反向依赖具体前端目录 */
  loadFixtures: () => Promise<DemoFixtureFile | { default: DemoFixtureFile }>;
  /** 演示环境是否不挂导览浮层（自检用） */
  silent?: boolean;
}

interface CompiledRoute {
  method: string;
  segments: string[];
  payload: DemoFixturePayload;
  key: string;
}

interface CompiledRefusal {
  method: string;
  segments: string[];
  message: string;
  code: number;
  key: string;
}

/** 未命中过的请求记在这里，方便自检脚本和开发者一眼看出哪块示例数据没铺到 */
interface DemoDiagnostics {
  misses: string[];
  hits: number;
}

declare global {
  interface Window {
    __DEMO_MISSES__?: string[];
    __DEMO_DIAGNOSTICS__?: DemoDiagnostics;
  }
}

const DEFAULT_REFUSED_MESSAGE = '演示环境不执行该操作';
const REFUSED_CODE = 40300;

/**
 * 给 axios 实例装一个「不出网」的 adapter。
 *
 * 为什么在 adapter 层：三端的 `src/api/*.ts` 合计一两百个调用点，改调用点等于把演示逻辑
 * 摊进业务代码；adapter 是 axios 的正式扩展点，拿到 config 直接返回 AxiosResponse，
 * 请求根本不会进入网络栈，连 CORS 都不会触发。
 */
export function installDemoTransport(instance: AxiosInstance, options: DemoTransportOptions): void {
  const diagnostics: DemoDiagnostics = { misses: [], hits: 0 };
  const routers: { routes: CompiledRoute[]; refusals: CompiledRefusal[] } = { routes: [], refusals: [] };
  let loading: Promise<void> | null = null;

  if (typeof window !== 'undefined') {
    window.__DEMO_DIAGNOSTICS__ = diagnostics;
    window.__DEMO_MISSES__ = diagnostics.misses;
  }

  const ensureFixtures = (): Promise<void> => {
    loading ??= Promise.resolve(options.loadFixtures())
      .then((module) => {
        const file = resolveFixtureFile(module);
        routers.routes = compileRoutes(file.endpoints ?? {});
        routers.refusals = compileRefusals(file.refused ?? {});
      })
      .catch((error: unknown) => {
        loading = null;
        throw error;
      });

    return loading;
  };

  const adapter: AxiosAdapter = async (config: InternalAxiosRequestConfig) => {
    await ensureFixtures();

    const method = String(config.method ?? 'get').toUpperCase();
    const path = normalizeMatchPath(String(config.url ?? ''));
    const key = `${method} ${path}`;
    const segments = splitPath(path);

    const refusal = routers.refusals.find((item) => item.method === method && matchSegments(item.segments, segments));
    if (refusal) {
      return buildResponse(config, businessEnvelope(refusal.code, refusal.message, null));
    }

    const route = routers.routes.find((item) => item.method === method && matchSegments(item.segments, segments));
    if (route) {
      diagnostics.hits += 1;
      return buildResponse(config, toEnvelope(route.payload));
    }

    diagnostics.misses.push(key);
    if (typeof console !== 'undefined') {
      console.warn(`[demo:${options.app}] 示例数据缺失：${key}（已返回空数据，把它补进 demo/fixtures/${options.app}）`);
    }

    return buildResponse(config, businessEnvelope(0, 'success', emptyPayloadFor(method)));
  };

  instance.defaults.adapter = adapter;

  // 原生 fetch 的漏网之鱼（AI 端点客户端只用 fetch，不走 axios 实例）：同一个匹配器接到窗口上，
  // 否则演示站会真的去请求 demo 域名的 /api。
  installFetchBridge(options.app, () => routers, diagnostics, ensureFixtures);

  if (!options.silent) {
    installDemoNav(options.app);
  }
}

/**
 * 把 `window.fetch` 上打到 `/api/` 的请求也交给示例数据。
 *
 * 只接管看起来是后端 API 的地址（路径里带 `/api/`），静态资源、字体、图片一律放行，
 * 免得把演示站自己的 JS/CSS 也拦掉。
 */
function installFetchBridge(
  app: DemoApp,
  getRouters: () => { routes: CompiledRoute[]; refusals: CompiledRefusal[] },
  diagnostics: DemoDiagnostics,
  ensureFixtures: () => Promise<void>,
): void {
  if (typeof window === 'undefined' || typeof window.fetch !== 'function') {
    return;
  }

  const originalFetch = window.fetch.bind(window);

  window.fetch = async (input: RequestInfo | URL, init?: RequestInit): Promise<Response> => {
    const rawUrl = typeof input === 'string' ? input : input instanceof URL ? input.href : input.url;
    const path = normalizeApiPath(rawUrl);

    if (!path) {
      return originalFetch(input, init);
    }

    // 必须有：fixture 是懒加载的，首个请求常常来自这里（用户端首页的 LCP 预热、
    // 控制台的 AI bootstrap）。不等加载完就匹配，会把这条请求误记成 miss 并返回空数据。
    await ensureFixtures();

    const method = String(init?.method ?? (typeof input === 'object' && 'method' in input ? input.method : 'GET')).toUpperCase();
    const segments = splitPath(path);
    const { routes, refusals } = getRouters();
    const key = `${method} ${path}`;

    const refusal = refusals.find((item) => item.method === method && matchSegments(item.segments, segments));
    const route = routes.find((item) => item.method === method && matchSegments(item.segments, segments));

    if (!refusal && !route) {
      diagnostics.misses.push(`${key}（fetch）`);
      console.warn(`[demo:${app}] 示例数据缺失（fetch）：${key}`);
    } else {
      diagnostics.hits += 1;
    }

    const envelope = refusal
      ? businessEnvelope(refusal.code, refusal.message, null)
      : route
        ? toEnvelope(route.payload)
        : businessEnvelope(0, 'success', method === 'GET' ? null : { success: true });

    return new Response(JSON.stringify(envelope), {
      status: 200,
      headers: { 'content-type': 'application/json; charset=utf-8' },
    });
  };
}

/** 把 `/api/v2/client/ai/bootstrap` 归一成 `/v2/client/ai/bootstrap`，与 axios 侧键一致 */
function normalizeApiPath(rawUrl: string): string | null {
  const path = normalizeRequestPath(rawUrl);

  if (!path.startsWith('/api/')) {
    return null;
  }

  return path.slice('/api'.length);
}

/**
 * 匹配用的路径：去掉 `/api` 前缀。
 *
 * 三端拼 URL 的方式不一样——控制台把 `/api` 放在 baseURL 里，管理端的 urlPrefix 会写进
 * `config.url`——不归一的话同一份 fixture 在两个端要写两种键。
 */
function normalizeMatchPath(rawUrl: string): string {
  const path = normalizeRequestPath(rawUrl);

  return path.startsWith('/api/') ? path.slice('/api'.length) : path;
}

function resolveFixtureFile(module: DemoFixtureFile | { default: DemoFixtureFile }): DemoFixtureFile {
  const value = module as { default?: DemoFixtureFile; endpoints?: Record<string, unknown> };

  if (value && typeof value === 'object' && value.endpoints) {
    return value as DemoFixtureFile;
  }

  if (value?.default) {
    return value.default;
  }

  return { endpoints: {} };
}

function compileRoutes(endpoints: Record<string, DemoFixturePayload>): CompiledRoute[] {
  return Object.entries(endpoints).map(([key, payload]) => {
    const { method, path } = splitKey(key);

    return { method, segments: splitPath(path), payload, key };
  });
}

function compileRefusals(refused: Record<string, { code?: number; message?: string }>): CompiledRefusal[] {
  return Object.entries(refused).map(([key, value]) => {
    const { method, path } = splitKey(key);

    return {
      method,
      segments: splitPath(path),
      code: Number(value?.code ?? REFUSED_CODE),
      message: String(value?.message ?? DEFAULT_REFUSED_MESSAGE),
      key,
    };
  });
}

function splitKey(key: string): { method: string; path: string } {
  const trimmed = key.trim();
  const spaceIndex = trimmed.indexOf(' ');

  if (spaceIndex < 0) {
    return { method: 'GET', path: normalizeRequestPath(trimmed) };
  }

  return {
    method: trimmed.slice(0, spaceIndex).toUpperCase(),
    path: normalizeRequestPath(trimmed.slice(spaceIndex + 1)),
  };
}

/** 去掉查询串、hash、协议与域名，只留 `/v2/client/services/12` 这样的路径 */
export function normalizeRequestPath(rawUrl: string): string {
  let value = rawUrl.trim();

  if (!value) {
    return '/';
  }

  const schemeIndex = value.indexOf('://');
  if (schemeIndex >= 0) {
    const slashIndex = value.indexOf('/', schemeIndex + 3);
    value = slashIndex >= 0 ? value.slice(slashIndex) : '/';
  }

  const queryIndex = value.indexOf('?');
  if (queryIndex >= 0) {
    value = value.slice(0, queryIndex);
  }

  const hashIndex = value.indexOf('#');
  if (hashIndex >= 0) {
    value = value.slice(0, hashIndex);
  }

  // baseURL 已经吃掉 `/api` 前缀时 url 是相对路径，这里统一补成绝对路径形态。
  if (!value.startsWith('/')) {
    value = `/${value}`;
  }

  return value.length > 1 ? value.replace(/\/+$/, '') : value;
}

function splitPath(path: string): string[] {
  return path.split('/').filter((segment) => segment.length > 0);
}

function matchSegments(pattern: string[], actual: string[]): boolean {
  if (pattern.length !== actual.length) {
    return false;
  }

  return pattern.every((segment, index) => segment.startsWith(':') || segment === actual[index]);
}

function toEnvelope(payload: DemoFixturePayload): Record<string, unknown> {
  if (payload && typeof payload === 'object') {
    const candidate = payload as Record<string, unknown>;

    // 只有显式带 code 的对象才当作「整封」处理，避免业务数据里恰好有 code 字段被误判。
    if (typeof candidate.code === 'number' && ('data' in candidate || 'message' in candidate)) {
      return businessEnvelope(candidate.code, String(candidate.message ?? 'success'), candidate.data ?? null);
    }
  }

  return businessEnvelope(0, 'success', payload ?? null);
}

function businessEnvelope(code: number, message: string, data: unknown): Record<string, unknown> {
  return { code, message, data };
}

function emptyPayloadFor(method: string): unknown {
  return method === 'GET' ? null : { success: true };
}

function buildResponse(config: InternalAxiosRequestConfig, data: unknown): AxiosResponse {
  return {
    data,
    status: 200,
    statusText: 'OK',
    headers: {},
    config,
    request: { __demo: true },
  };
}
