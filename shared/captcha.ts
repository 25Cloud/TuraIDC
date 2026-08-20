/**
 * Cap（capjs）自托管人机验证客户端：challenge → Worker 求解 PoW → redeem → token。
 *
 * 协议与 https://trycap.dev 的 capjs 一致：
 *  - POST {apiEndpoint}challenge → { token, challenge: { c, s, d }, expires }
 *  - POST {apiEndpoint}redeem（{ token, solutions }）→ { token }
 * 所得 token 交由后端 siteverify 校验（见 backend/plugins/captcha/cap）。
 */

export interface CapChallenge {
  token: string;
  count: number;
  saltLength: number;
  difficulty: number;
}

async function fetchCapChallenge(apiEndpoint: string): Promise<CapChallenge> {
  const res = await fetch(`${apiEndpoint}challenge`, {
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

  return { token, count, saltLength, difficulty };
}

async function redeemCapSolution(apiEndpoint: string, token: string, solutions: number[]): Promise<string> {
  const res = await fetch(`${apiEndpoint}redeem`, {
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

  const solutions = await new Promise<number[]>((resolve, reject) => {
    const w = getCapWorker();

    const onMessage = (e: MessageEvent<{ type: string; pct?: number; solutions?: number[]; error?: string }>) => {
      if (e.data.type === 'progress') {
        onProgress?.(15 + Math.round(((e.data.pct ?? 0) / 100) * 55));
      } else if (e.data.type === 'done') {
        w.removeEventListener('message', onMessage);
        w.removeEventListener('error', onError);
        resolve(e.data.solutions ?? []);
      }
    };

    const onError = (err: ErrorEvent) => {
      w.removeEventListener('message', onMessage);
      w.removeEventListener('error', onError);
      reject(new Error(err.message || '验证求解失败'));
    };

    w.addEventListener('message', onMessage);
    w.addEventListener('error', onError);

    w.postMessage({ type: 'solve', token, count, saltLength, difficulty });
  });

  if (solutions.length === 0) {
    throw new Error('求解器未产生任何 nonce');
  }
  onProgress?.(70);

  const resultToken = await redeemCapSolution(apiEndpoint, token, solutions);
  onProgress?.(100);

  return resultToken;
}
