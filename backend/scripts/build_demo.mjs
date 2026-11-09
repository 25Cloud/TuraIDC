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
  user: {
    workspace: 'turaidc-user-v3-www',
    directory: 'frontend-user-v3-www',
    assetVariable: 'VITE_WWW_ASSET_BASE_URL',
    base: '/user/',
  },
  console: {
    workspace: 'turaidc-user-v4-console',
    directory: 'frontend-user-v4-console',
    assetVariable: 'VITE_CONSOLE_ASSET_BASE_URL',
    base: '/console/',
  },
  admin: {
    workspace: 'turaidc-admin-v3',
    directory: 'frontend-admin-v3',
    assetVariable: 'VITE_ADMIN_ASSET_BASE_URL',
    base: '/admin/',
  },
};

function parseArguments(argv) {
  const options = { target: 'all', skipSite: false };

  for (const argument of argv) {
    if (argument === '--skip-site') {
      options.skipSite = true;
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
  const target = path.join(distRoot, name);

  await rm(target, { recursive: true, force: true });
  await cp(source, target, { recursive: true });
  console.log(`  → demo/dist/${name}`);
}

/** 首页：优先用 site-web 的真实产物；没有依赖时退化成一个演示入口页，保证产物可用 */
async function buildSite() {
  const siteRoot = path.join(repositoryRoot, 'site-web');
  const hasDependencies = existsSync(path.join(siteRoot, 'node_modules'));
  const output = path.join(siteRoot, 'dist');
  const target = path.join(distRoot, 'index.html');

  if (hasDependencies) {
    console.log('\n构建首页（site-web）...');
    await run(process.platform === 'win32' ? 'npm.cmd' : 'npm', ['--prefix', siteRoot, 'run', 'build'], process.env, repositoryRoot);

    if (existsSync(output)) {
      // site-web 是多页产物（四语言），整目录铺到 demo/dist 根
      await rm(distRoot, { recursive: true, force: true });
      await cp(output, distRoot, { recursive: true });
      console.log('  → demo/dist（site-web 首页四语言）');
      return;
    }
  }

  console.log('\nsite-web 未构建（缺依赖或缺产物），使用演示入口页兜底。');
  const landing = await readFile(path.join(repositoryRoot, 'demo', 'landing', 'index.html'), 'utf8');
  await writeFile(target, landing.replaceAll('{{ORIGIN}}', DEMO_ORIGIN), 'utf8');
}

const options = parseArguments(process.argv.slice(2));

await mkdir(distRoot, { recursive: true });

if (!options.skipSite) {
  await buildSite();
}

const selected = options.target === 'all'
  ? Object.entries(applications)
  : [[options.target, applications[options.target]]];

for (const [name, application] of selected) {
  await buildApplication(name, application);
}

const size = await directorySize(distRoot);
console.log(`\n演示站产物就绪：demo/dist（${(size / 1024 / 1024).toFixed(1)} MB）`);
console.log(`本地预览：npx serve demo/dist，或 node backend/scripts/serve_demo.mjs`);
