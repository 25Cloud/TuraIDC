#!/usr/bin/env node
/**
 * 演示站本地预览服务器（零依赖）。
 *
 * 只做两件静态托管需要的事：
 * 1. 按目录返回文件，缺扩展名的路径回落到自身目录的 index.html（`/console/` → `/console/index.html`）；
 * 2. 顺手补齐 MIME，避免浏览器把 ES module 当成 text/plain。
 *
 * 演示站用的是 hash 路由，所以**不需要**任何 SPA 回退：任何 `#/...` 都在 `/console/index.html` 里解析。
 *
 * 用法：node backend/scripts/serve_demo.mjs [--port=4190] [--root=demo/dist]
 */
import { createReadStream, statSync } from 'node:fs';
import { createServer } from 'node:http';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const scriptDirectory = path.dirname(fileURLToPath(import.meta.url));
const repositoryRoot = path.resolve(scriptDirectory, '..', '..');

const args = process.argv.slice(2);
const port = Number((args.find((item) => item.startsWith('--port=')) ?? '--port=4190').slice('--port='.length));
const rootArgument = (args.find((item) => item.startsWith('--root=')) ?? '').slice('--root='.length);
const root = path.resolve(repositoryRoot, rootArgument || path.join('demo', 'dist'));

const mimeTypes = {
  '.html': 'text/html; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8',
  '.mjs': 'text/javascript; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.svg': 'image/svg+xml',
  '.png': 'image/png',
  '.jpg': 'image/jpeg',
  '.jpeg': 'image/jpeg',
  '.webp': 'image/webp',
  '.avif': 'image/avif',
  '.ico': 'image/x-icon',
  '.woff': 'font/woff',
  '.woff2': 'font/woff2',
  '.ttf': 'font/ttf',
  '.txt': 'text/plain; charset=utf-8',
  '.xml': 'application/xml; charset=utf-8',
  '.map': 'application/json; charset=utf-8',
};

function resolveFile(urlPath) {
  const decoded = decodeURIComponent(urlPath.split('?')[0].split('#')[0]);
  const normalized = path.normalize(decoded).replace(/^(\.\.[/\\])+/, '');
  const absolute = path.join(root, normalized.startsWith(path.sep) ? normalized.slice(1) : normalized);

  if (!absolute.startsWith(root)) {
    return null;
  }

  const candidates = [absolute, path.join(absolute, 'index.html'), `${absolute}.html`];

  for (const candidate of candidates) {
    try {
      const stats = statSync(candidate);
      if (stats.isFile()) {
        return candidate;
      }
    } catch {
      continue;
    }
  }

  return null;
}

const server = createServer((request, response) => {
  const file = resolveFile(request.url ?? '/');

  if (!file) {
    response.writeHead(404, { 'content-type': 'text/plain; charset=utf-8' });
    response.end(`404 ${request.url}\n提示：先跑 node backend/scripts/build_demo.mjs`);
    return;
  }

  response.writeHead(200, {
    'content-type': mimeTypes[path.extname(file).toLowerCase()] ?? 'application/octet-stream',
    'cache-control': 'no-store',
  });
  createReadStream(file).pipe(response);
});

server.listen(port, '127.0.0.1', () => {
  console.log(`演示站预览：http://127.0.0.1:${port}/`);
  console.log(`  首页   http://127.0.0.1:${port}/`);
  console.log(`  用户端 http://127.0.0.1:${port}/user/`);
  console.log(`  控制台 http://127.0.0.1:${port}/console/`);
  console.log(`  管理端 http://127.0.0.1:${port}/admin/`);
  console.log(`根目录：${root}`);
});
