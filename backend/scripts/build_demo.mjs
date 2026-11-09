#!/usr/bin/env node
/**
 * 演示站构建：把三个前端在 `VITE_DEMO_MODE=true` 下各构建一次，连同 site-web 首页
 * 组装成一份静态产物 `demo/dist`，供独立 EdgeOne Pages 项目直接托管。
 *
 * 与 `build_frontends.mjs` 的区别：
 * - 不读 `backend/.env`（演示站没有后端，站点地址是写死的演示域名口径）；
 * - 三端都改成 hash 路由 + `/user`、`/console`、`/admin` 子路径 base，静态托管零回退配置；
 * - 输出统一收敛到 `demo/dist`，而不是各端各自的 dist。
 *
 * 用法：
 *   node backend/scripts/build_demo.mjs                 # 四端全量
 *   node backend/scripts/build_demo.mjs --target=console
 *   node backend/scripts/build_demo.mjs --skip-site     # 不构建首页（本地快速验证用）
 */
import { spawn } from 'node:child_process';
import { cp, mkdir, readFile, rm, stat, writeFile } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const scriptDirectory = path.dirname(fileURLToPath(import.meta.url));
const repositoryRoot = path.resolve(scriptDirectory, '..', '..');
const distRoot = path.join(repositoryRoot, 'demo', 'dist');

/** 演示站域名口径：真实 API 一律不会被访问，用 .invalid 顶级域兜底 */
const DEMO_ORIGIN = 'https://demo.turaidc.com';

const applications = {
  // 用户端就是系统首页：真实部署里 FRONTEND_URL 就是站点根，演示站照抄这个形态
  user: {
    workspace: 'turaidc-user-v3-www',
    directory: 'frontend-user-v3-www',
    assetVariable: 'VITE_WWW_ASSET_BASE_URL',
    base: '/',
    target: '',
    publicRoot: true,
  },
  console: {
    workspace: 'turaidc-user-v4-console',
    directory: 'frontend-user-v4-console',
    assetVariable: 'VITE_CONSOLE_ASSET_BASE_URL',
    base: '/console/',
    target: 'console',
  },
  admin: {
    workspace: 'turaidc-admin-v3',
    directory: 'frontend-admin-v3',
    assetVariable: 'VITE_ADMIN_ASSET_BASE_URL',
    base: '/admin/',
    target: 'admin',
  },
};

function parseArguments(argv) {
  const options = { target: 'all', withSite: false };

  for (const argument of argv) {
    if (argument === '--with-site') {
      options.withSite = true;
      continue;
    }

    if (argument.startsWith('--target=')) {
      options.target = argument.slice('--target='.length);
      continue;
    }

    throw new Error(`不支持的参数：${argument}`);
  }

  if (options.target !== 'all' && !Object.hasOwn(applications, options.target)) {
    throw new Error('--target 只能是 all、user、console 或 admin。');
  }

  return options;
}

function run(command, args, env, cwd) {
  return new Promise((resolve, reject) => {
    const child = spawn(command, args, {
      cwd,
      env,
      stdio: 'inherit',
      shell: process.platform === 'win32',
    });

    child.once('error', reject);
    child.once('exit', (code) => {
      if (code === 0) {
        resolve();
        return;
      }

      reject(new Error(`${command} ${args.join(' ')} 失败（exit ${code ?? 'unknown'}）`));
    });
  });
}

async function directorySize(directory) {
  let total = 0;

  const walk = async (current) => {
    const entries = await stat(current);

    if (!entries.isDirectory()) {
      total += entries.size;
      return;
    }

    const list = await (await import('node:fs/promises')).readdir(current, { withFileTypes: true });
    await Promise.all(list.map((entry) => walk(path.join(current, entry.name))));
  };

  await walk(directory).catch(() => {});

  return total;
}

async function buildApplication(name, application) {
  const environment = {
    ...process.env,
    VITE_DEMO_MODE: 'true',
    VITE_API_BASE_URL: `${DEMO_ORIGIN}/api`,
    VITE_BASE_URL: application.base,
    VITE_PUBLIC_SITE_URL: DEMO_ORIGIN,
    VITE_CONSOLE_SITE_URL: `${DEMO_ORIGIN}/console`,
    VITE_SESSION_COOKIE_DOMAIN: '',
    [application.assetVariable]: application.base,
  };

  console.log(`\n构建 ${name}（${application.workspace}，base=${application.base}）...`);
  await run(
    process.platform === 'win32' ? 'pnpm.cmd' : 'pnpm',
    ['--filter', application.workspace, 'run', 'build'],
    environment,
    repositoryRoot,
  );

  const source = path.join(repositoryRoot, application.directory, 'dist');
  const target = application.target ? path.join(distRoot, application.target) : distRoot;

  if (application.publicRoot) {
    // 用户端铺在站点根：先清空根目录（入口页会在这之后再写回去），
    // 并把 public/ 里的静态资源（branding / img / favicon）一并放到根，
    // 因为前端引用的是绝对路径 `/branding/logo.png`。
    await rm(distRoot, { recursive: true, force: true });
    await mkdir(target, { recursive: true });
  } else {
    await rm(target, { recursive: true, force: true });
  }

  await cp(source, target, { recursive: true });

  if (application.publicRoot) {
    const assets = path.join(repositoryRoot, application.directory, 'public');

    if (existsSync(assets)) {
      await cp(assets, target, { recursive: true });
    }

    await patchEarlyApiFetch(target);
  }

  console.log(`  → demo/dist/${application.target || ''}`);
}

/**
 * 用户端的 `index.html` 里有一段内联脚本，会在 JS 加载前预取 `/v2/site/home` 抢 LCP。
 * 它比我们的传输层先执行，所以演示站里会真的去打 `https://demo.turaidc.com/api/...`
 * （浏览器报 CORS 失败）。这里在 `<head>` 之后插一段极小的兜底，把 `/api/` 的 fetch 直接
 * 应答成空信封；真正的示例数据仍由 `shared/demo` 的传输层接管。
 */
async function patchEarlyApiFetch(distDirectory) {
  const indexPath = path.join(distDirectory, 'index.html');

  if (!existsSync(indexPath)) {
    return;
  }

  const html = await readFile(indexPath, 'utf8');

  if (html.includes('__DEMO_EARLY_FETCH__')) {
    return;
  }

  const stub = [
    '<script id="__DEMO_EARLY_FETCH__">',
    '(function(){var real=window.fetch;window.fetch=function(i,o){',
    "var u=typeof i==='string'?i:(i&&i.url)||'';",
    "if(u.indexOf('/api/')<0){return real.apply(window,arguments);}",
    "return Promise.resolve(new Response(JSON.stringify({code:0,message:'success',data:null}),",
    "{status:200,headers:{'content-type':'application/json'}}));};})();",
    '</script>',
  ].join('');

  const patched = html.replace(/<head(\s[^>]*)?>/i, (match) => `${match}${stub}`);

  if (patched === html) {
    console.warn('  ! 用户端 index.html 没找到 <head>，跳过预热请求兜底');
    return;
  }

  await writeFile(indexPath, patched, 'utf8');
}

/**
 * 演示说明页：`/start/`，给官网「在线演示」CTA 落地用。
 *
 * 站点根 `/` 必须是**系统首页**（用户端），跟真实部署一致；
 * 这份说明页只解释「三端各是什么、数据是示例」，不再顶替根路径。
 */
async function buildLanding() {
  const target = path.join(distRoot, 'start');
  await mkdir(target, { recursive: true });
  const landing = await readFile(path.join(repositoryRoot, 'demo', 'landing', 'index.html'), 'utf8');
  await writeFile(path.join(target, 'index.html'), landing, 'utf8');

  // 演示站不该被搜索引擎收录：根目录放一份全站 Disallow
  const robots = path.join(repositoryRoot, 'demo', 'landing', 'robots.txt');
  if (existsSync(robots)) {
    await writeFile(path.join(distRoot, 'robots.txt'), await readFile(robots, 'utf8'), 'utf8');
  }

  console.log('\n演示说明页 → demo/dist/start/index.html');
}

/** 可选：把 site-web 首页放进 `/site/`，默认不产出 */
async function buildSiteCopy() {
  const siteRoot = path.join(repositoryRoot, 'site-web');
  const output = path.join(siteRoot, 'dist');
  const npm = process.platform === 'win32' ? 'npm.cmd' : 'npm';

  try {
    if (!existsSync(path.join(siteRoot, 'node_modules'))) {
      console.log('\n安装官网依赖（site-web：npm ci）...');
      await run(npm, ['--prefix', siteRoot, 'ci'], process.env, repositoryRoot);
    }

    console.log('\n构建官网副本（site-web）...');
    await run(npm, ['--prefix', siteRoot, 'run', 'build'], process.env, repositoryRoot);

    if (!existsSync(output)) {
      throw new Error('site-web 构建完成但没有 dist 产物');
    }

    const target = path.join(distRoot, 'site');
    await rm(target, { recursive: true, force: true });
    await cp(output, target, { recursive: true });
    console.log('  → demo/dist/site（site-web 首页四语言）');
  } catch (error) {
    console.warn(`官网副本构建失败，跳过：${error instanceof Error ? error.message : error}`);
  }
}

const options = parseArguments(process.argv.slice(2));

// 用户端铺在站点根，构建时会重置 demo/dist，所以必须排在第一位；
// 说明页 `/start/` 写在最后，免得被根目录重置带走。
const selected = options.target === 'all'
  ? Object.entries(applications)
  : [[options.target, applications[options.target]]];

if (options.target === 'all') {
  await rm(distRoot, { recursive: true, force: true });
}

for (const [name, application] of selected) {
  await buildApplication(name, application);
}

await buildLanding();

if (options.withSite) {
  await buildSiteCopy();
}

const size = await directorySize(distRoot);
console.log(`\n演示站产物就绪：demo/dist（${(size / 1024 / 1024).toFixed(1)} MB）`);
console.log(`本地预览：node backend/scripts/serve_demo.mjs`);
