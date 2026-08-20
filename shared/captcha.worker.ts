// Web Worker：Cap PoW 求解（完全脱离主线程）。
// 算法与 https://trycap.dev 的 capjs 协议一致：对 salt+nonce 做 SHA-256，
// 命中 prng(token+index+d) 生成的 target 前缀即为有效 nonce。

function fnv1a(str: string): number {
  let hash = 2166136261;
  for (let i = 0; i < str.length; i++) {
    hash ^= str.charCodeAt(i);
    hash += (hash << 1) + (hash << 4) + (hash << 7) + (hash << 8) + (hash << 24);
  }
  return hash >>> 0;
}

function prng(seed: string, length: number): string {
  let state = fnv1a(seed);
  let result = '';
  function next(): number {
    state ^= state << 13;
    state ^= state >>> 17;
    state ^= state << 5;
    return state >>> 0;
  }
  while (result.length < length) {
    result += next().toString(16).padStart(8, '0');
  }
  return result.substring(0, length);
}

async function sha256Hex(input: string): Promise<string> {
  const data = new TextEncoder().encode(input);
  const hashBuffer = await crypto.subtle.digest('SHA-256', data);
  const hashArray = Array.from(new Uint8Array(hashBuffer));
  return hashArray.map((b) => b.toString(16).padStart(2, '0')).join('');
}

async function solveSingleChallenge(
  token: string,
  index: number,
  saltLength: number,
  difficulty: number,
): Promise<number> {
  const salt = prng(`${token}${index}`, saltLength);
  const target = prng(`${token}${index}d`, difficulty);
  let nonce = 0;
  for (;;) {
    const hash = await sha256Hex(salt + nonce);
    if (hash.startsWith(target)) {
      return nonce;
    }
    nonce++;
    if (nonce > 50_000_000) {
      throw new Error(`PoW 求解超限 (idx=${index})`);
    }
  }
}

self.onmessage = async (e: MessageEvent<{ type: 'solve'; token: string; count: number; saltLength: number; difficulty: number }>) => {
  const { token, count, saltLength, difficulty } = e.data;
  const solutions: number[] = [];
  for (let i = 1; i <= count; i++) {
    const nonce = await solveSingleChallenge(token, i, saltLength, difficulty);
    solutions.push(nonce);
    self.postMessage({ type: 'progress', pct: Math.round((i / count) * 100) });
  }
  self.postMessage({ type: 'done', solutions });
};
