// Web Worker：Cap PoW 求解（完全脱离主线程）。
// 算法与 https://trycap.dev 的 capjs 协议一致：对 salt+nonce 做 SHA-256，
// 命中 prng(token+index+d) 生成的 target 前缀即为有效 nonce。
//
// 注意：SHA-256 使用纯 JS 实现，不依赖 crypto.subtle —— 本项目部署在内网
// HTTP（非安全上下文），crypto.subtle 在 Worker 中不可用。

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

// ── 纯 JS SHA-256 ──

const SHA256_K = new Uint32Array([
  0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5,
  0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3, 0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174,
  0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
  0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967,
  0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13, 0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85,
  0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
  0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3,
  0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208, 0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2,
]);

function rotr32(value: number, shift: number): number {
  return (value >>> shift) | (value << (32 - shift));
}

function sha256Hex(input: string): string {
  const data = new TextEncoder().encode(input);
  const bitLenHi = Math.floor(data.length / 0x20000000);
  const bitLenLo = (data.length << 3) >>> 0;
  const padded = new Uint8Array((((data.length + 8) >> 6) << 6) + 64);
  padded.set(data);
  padded[data.length] = 0x80;
  const view = new DataView(padded.buffer);
  view.setUint32(padded.length - 8, bitLenHi, false);
  view.setUint32(padded.length - 4, bitLenLo, false);

  let h0 = 0x6a09e667;
  let h1 = 0xbb67ae85;
  let h2 = 0x3c6ef372;
  let h3 = 0xa54ff53a;
  let h4 = 0x510e527f;
  let h5 = 0x9b05688c;
  let h6 = 0x1f83d9ab;
  let h7 = 0x5be0cd19;

  const w = new Uint32Array(64);
  for (let offset = 0; offset < padded.length; offset += 64) {
    for (let i = 0; i < 16; i++) {
      w[i] = view.getUint32(offset + i * 4, false);
    }
    for (let i = 16; i < 64; i++) {
      const s0 = rotr32(w[i - 15], 7) ^ rotr32(w[i - 15], 18) ^ (w[i - 15] >>> 3);
      const s1 = rotr32(w[i - 2], 17) ^ rotr32(w[i - 2], 19) ^ (w[i - 2] >>> 10);
      w[i] = (w[i - 16] + s0 + w[i - 7] + s1) >>> 0;
    }

    let a = h0;
    let b = h1;
    let c = h2;
    let d = h3;
    let e = h4;
    let f = h5;
    let g = h6;
    let h = h7;

    for (let i = 0; i < 64; i++) {
      const sigma1 = rotr32(e, 6) ^ rotr32(e, 11) ^ rotr32(e, 25);
      const ch = (e & f) ^ (~e & g);
      const temp1 = (h + sigma1 + ch + SHA256_K[i] + w[i]) >>> 0;
      const sigma0 = rotr32(a, 2) ^ rotr32(a, 13) ^ rotr32(a, 22);
      const maj = (a & b) ^ (a & c) ^ (b & c);
      const temp2 = (sigma0 + maj) >>> 0;

      h = g;
      g = f;
      f = e;
      e = (d + temp1) >>> 0;
      d = c;
      c = b;
      b = a;
      a = (temp1 + temp2) >>> 0;
    }

    h0 = (h0 + a) >>> 0;
    h1 = (h1 + b) >>> 0;
    h2 = (h2 + c) >>> 0;
    h3 = (h3 + d) >>> 0;
    h4 = (h4 + e) >>> 0;
    h5 = (h5 + f) >>> 0;
    h6 = (h6 + g) >>> 0;
    h7 = (h7 + h) >>> 0;
  }

  return [h0, h1, h2, h3, h4, h5, h6, h7].map((v) => v.toString(16).padStart(8, '0')).join('');
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
    const hash = sha256Hex(salt + nonce);
    if (hash.startsWith(target)) {
      return nonce;
    }
    nonce++;
    if (nonce > 50_000_000) {
      throw new Error(`PoW 求解超限 (idx=${index})`);
    }
  }
}

type SolveRequest = {
  type: 'solve';
  id: number;
  token: string;
  count: number;
  saltLength: number;
  difficulty: number;
};

self.onmessage = async (e: MessageEvent<SolveRequest>) => {
  const { id, token, count, saltLength, difficulty } = e.data;
  try {
    const solutions: number[] = [];
    for (let i = 1; i <= count; i++) {
      const nonce = await solveSingleChallenge(token, i, saltLength, difficulty);
      solutions.push(nonce);
      self.postMessage({ type: 'progress', id, pct: Math.round((i / count) * 100) });
    }
    self.postMessage({ type: 'done', id, solutions });
  } catch (error) {
    // 求解失败必须回传 error 消息，否则主线程 Promise 永久挂起
    self.postMessage({ type: 'error', id, error: error instanceof Error ? error.message : '验证求解失败' });
  }
};
