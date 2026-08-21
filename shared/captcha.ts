/**
 * Cap（capjs）自托管人机验证客户端：challenge → Worker 求解 PoW → redeem → token。
 *
 * 协议与 https://trycap.dev 的 capjs 一致：
 *  - POST {apiEndpoint}challenge → { token, challenge: { c, s, d }, expires }
 *  - POST {apiEndpoint}redeem（{ token, solutions }）→ { token }
 * 所得 token 交由后端 siteverify 校验（见 backend/plugins/captcha/cap）。
 *
 * 注意：CapChallenge 的字段名（token/count/saltLength/difficulty）是本站对协议
 * JSON（token 与 challenge:{c,s,d}）的映射层命名，二者不同层，勿直接视为协议字段。
 */

export interface CapChallenge {
  token: string;
  count: number;
  saltLength: number;
  difficulty: number;
}

/** API 请求超时：Cap 实例不可达（配置错误/宕机）时不让卡片无限转圈 */
const CAP_FETCH_TIMEOUT_MS = 15_000;

/** challenge 参数上限（capjs 默认量级为 count≤16、difficulty≤8）：防恶意/误配置实例让 Worker 空转 */
const CAP_COUNT_LIMIT = 64;
const CAP_DIFFICULTY_LIMIT = 8;
const CAP_SALT_LENGTH_LIMIT = 128;

async function fetchWithTimeout(url: string, init: RequestInit): Promise<Response> {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), CAP_FETCH_TIMEOUT_MS);
  try {
    return await fetch(url, { ...init, signal: controller.signal });
  } catch (error) {
    // AbortError 转成可读错误；网络异常原样抛给上层统一提示
    if (error instanceof DOMException && error.name === 'AbortError') {
      throw new Error('验证服务响应超时，请稍后重试', { cause: error });
    }
    throw error;
  } finally {
    clearTimeout(timer);
  }
}

async function fetchCapChallenge(apiEndpoint: string): Promise<CapChallenge> {
  const res = await fetchWithTimeout(`${apiEndpoint}challenge`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: '{}',
  });
  if (!res.ok) {
    throw new Error(`获取验证挑战失败 (HTTP ${res.status})`);
  }

  const body = await res.json();
  const root = (body.data || body) ?? {};
  const token = root.token as string | undefined;
  const challenge = root.challenge as Record<string, unknown> | undefined;
  if (!token || !challenge) {
    throw new Error('验证挑战接口缺少 token/challenge');
  }

  const count = Number(challenge.c);
  const saltLength = Number(challenge.s);
  const difficulty = Number(challenge.d);
  if (!Number.isFinite(count) || !Number.isFinite(saltLength) || !Number.isFinite(difficulty)) {
    throw new Error('验证挑战参数解析失败');
  }

  if (count <= 0 || count > CAP_COUNT_LIMIT || difficulty > CAP_DIFFICULTY_LIMIT || saltLength > CAP_SALT_LENGTH_LIMIT) {
    throw new Error('验证挑战参数超出安全范围');
  }

  return { token, count, saltLength, difficulty };
}

async function redeemCapSolution(apiEndpoint: string, token: string, solutions: number[]): Promise<string> {
  const res = await fetchWithTimeout(`${apiEndpoint}redeem`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ token, solutions }),
  });
  if (!res.ok) {
    throw new Error(`提交验证解答失败 (HTTP ${res.status})`);
  }

  const body = await res.json();
  const root = (body.data || body) as Record<string, unknown>;
  if (root.success === false) {
    throw new Error(`服务端拒绝: ${String(root.message || 'unknown')}`);
  }
  if (!root.token) {
    throw new Error('服务端未返回 token');
  }

  return String(root.token);
}

let worker: Worker | null = null;
let workerRequestSeq = 0;

interface CapWorkerReply {
  type: 'progress' | 'done' | 'error';
  id: number;
  pct?: number;
  solutions?: number[];
  error?: string;
}

function getCapWorker(): Worker {
  if (!worker) {
    worker = new Worker(new URL('./captcha.worker.ts', import.meta.url), { type: 'module' });
  }
  return worker;
}

export function destroyCapWorker(): void {
  worker?.terminate();
  worker = null;
}

/**
 * 完成一次 Cap 人机验证并返回可用于后端 siteverify 的 token。
 *
 * @param apiEndpoint Cap 实例端点（{server}/{siteId}/，须以 / 结尾）
 * @param onProgress 进度回调（0-100）
 */
export async function solveCapCaptcha(apiEndpoint: string, onProgress?: (pct: number) => void): Promise<string> {
  onProgress?.(5);

  const challenge = await fetchCapChallenge(apiEndpoint);
  onProgress?.(15);

  const { token, count, saltLength, difficulty } = challenge;
  // 请求 id 用于隔离并发求解：模块级单例 Worker 可能同时服务多个卡片实例，
  // 消息必须按 id 归属，避免不同调用的 done/error 互相串扰。
  const requestId = ++workerRequestSeq;

  const solutions = await new Promise<number[]>((resolve, reject) => {
    const w = getCapWorker();

    const detach = () => {
      w.removeEventListener('message', onMessage);
      w.removeEventListener('error', onError);
    };

    const onMessage = (e: MessageEvent<CapWorkerReply>) => {
      if (e.data.id !== requestId) {
        return;
      }

      if (e.data.type === 'progress') {
        onProgress?.(15 + Math.round(((e.data.pct ?? 0) / 100) * 55));
      } else if (e.data.type === 'done') {
        detach();
        resolve(e.data.solutions ?? []);
      } else if (e.data.type === 'error') {
        detach();
        reject(new Error(e.data.error || '验证求解失败'));
      }
    };

    const onError = (err: ErrorEvent) => {
      detach();
      // Worker 崩溃后不可复用：终止并置空，下次求解会重建 worker，
      // 避免向已死 worker postMessage 静默失败导致 Promise 永久 pending。
      w.terminate();
      if (worker === w) {
        worker = null;
      }
      reject(new Error(err.message || '验证求解失败'));
    };

    w.addEventListener('message', onMessage);
    w.addEventListener('error', onError);

    w.postMessage({ type: 'solve', id: requestId, token, count, saltLength, difficulty });
  });

  if (solutions.length === 0) {
    throw new Error('求解器未产生任何 nonce');
  }
  onProgress?.(70);

  const resultToken = await redeemCapSolution(apiEndpoint, token, solutions);
  onProgress?.(100);

  return resultToken;
}