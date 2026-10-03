import { spawn } from 'node:child_process';
import { existsSync, readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

// 【本地补丁】支持单域名子路径部署（/console、/admin），原版的"四个无路径不同 origin"限制已放宽为"四个地址互不相同"。
// 上游 git pull 前注意此文件有本地修改。

const scriptDirectory = path.dirname(fileURLToPath(import.meta.url));
const repositoryRoot = path.resolve(scriptDirectory, '..', '..');
const backendEnvPath = path.join(repositoryRoot, 'backend', '.env');
const npmCommand = process.platform === 'win32' ? 'pnpm.cmd' : 'pnpm';

const applications = {
  admin: {
    workspace: 'turaidc-admin-v3',
    assetVariable: 'VITE_ADMIN_ASSET_BASE_URL',
    urlLabel: 'ADMIN_URL',
  },
  www: {
    workspace: 'turaidc-user-v3-www',
    assetVariable: 'VITE_WWW_ASSET_BASE_URL',
    urlLabel: 'FRONTEND_URL',
  },
  console: {
    workspace: 'turaidc-user-v4-console',
    assetVariable: 'VITE_CONSOLE_ASSET_BASE_URL',
    urlLabel: 'CLIENT_CONSOLE_URL',
  },
};

function readEnvFile(filePath) {
  if (!existsSync(filePath)) {
    return {};
  }

  return readFileSync(filePath, 'utf8')
    .split(/\r?\n/)
    .reduce((env, line) => {
      const trimmed = line.trim();
      if (!trimmed || trimmed.startsWith('#')) {
        return env;
      }

      const separator = trimmed.indexOf('=');
      if (separator <= 0) {
        return env;
      }

      const key = trimmed.slice(0, separator).trim();
      const rawValue = trimmed.slice(separator + 1).trim();
      const value = rawValue.length >= 2
        && ((rawValue.startsWith('"') && rawValue.endsWith('"')) || (rawValue.startsWith("'") && rawValue.endsWith("'")))
        ? rawValue.slice(1, -1)
        : rawValue;

      env[key] = value;
      return env;
    }, {});
}

function parseArguments(argv) {
  const options = { dryRun: false, target: 'all' };

  for (let index = 0; index < argv.length; index += 1) {
    const argument = argv[index];
    if (argument === '--dry-run') {
      options.dryRun = true;
      continue;
    }

    if (argument === '--target') {
      options.target = argv[index + 1] || '';
      index += 1;
      continue;
    }

    if (argument.startsWith('--target=')) {
      options.target = argument.slice('--target='.length);
      continue;
    }

    throw new Error(`不支持的参数：${argument}`);
  }

  if (options.target !== 'all' && !Object.hasOwn(applications, options.target)) {
    throw new Error('--target 只能是 all、admin、www 或 console。');
  }

  return options;
}

// 允许无路径根地址或干净的子路径地址（如 https://example.com/console）
// 返回 { origin, base, public }：base 带首尾斜杠供 Vite base 使用；public 无尾斜杠供链接拼接使用。
function normalizePublicUrl(label, rawValue) {
  let url;
  try {
    url = new URL(String(rawValue || '').trim());
  } catch {
    throw new Error(`${label} 必须是 HTTP(S) 地址。`);
  }

  if (!['http:', 'https:'].includes(url.protocol)
    || !url.hostname
    || url.username
    || url.password
    || url.search
    || url.hash) {
    throw new Error(`${label} 必须是无账号信息、无查询串的 HTTP(S) 地址。`);
  }

  const trimmedPath = url.pathname.replace(/\/+$/, '');
  if (trimmedPath !== '' && !/^\/[A-Za-z0-9\-_]+(\/[A-Za-z0-9\-_]+)*$/.test(trimmedPath)) {
    throw new Error(`${label} 路径只允许字母、数字、中划线、下划线。`);
  }

  return {
    origin: url.origin,
    base: `${trimmedPath}/`,
    public: url.origin + trimmedPath,
  };
}

function runNpm(args, env) {
  return new Promise((resolve, reject) => {
    const command = process.platform === 'win32' ? process.env.ComSpec || 'cmd.exe' : npmCommand;
    const commandArgs = process.platform === 'win32'
      ? ['/d', '/s', '/c', [npmCommand, ...args].join(' ')]
      : args;
    const child = spawn(command, commandArgs, {
      cwd: repositoryRoot,
      env,
      stdio: 'inherit',
      shell: false,
    });

    child.once('error', reject);
    child.once('exit', (code, signal) => {
      if (code === 0) {
        resolve();
        return;
      }

      reject(new Error(`前端构建失败（${signal ? `signal ${signal}` : `exit ${code ?? 'unknown'}`}）`));
    });
  });
}

const options = parseArguments(process.argv.slice(2));
const backendEnv = readEnvFile(backendEnvPath);
const value = (key) => String(process.env[key] || backendEnv[key] || '').trim();

const apiUrl = normalizePublicUrl('APP_URL', value('APP_URL'));
const websiteUrl = normalizePublicUrl('FRONTEND_URL', value('FRONTEND_URL'));
const consoleUrl = normalizePublicUrl('CLIENT_CONSOLE_URL', value('CLIENT_CONSOLE_URL'));
const adminUrl = normalizePublicUrl('ADMIN_URL', value('ADMIN_URL'));
// 唯一性只约束三个前端地址；APP_URL 允许与官网同源（API 以 /api 路径区分）
const publicUrls = [websiteUrl.public, consoleUrl.public, adminUrl.public];

if (new Set(publicUrls).size !== publicUrls.length) {
  throw new Error('FRONTEND_URL、CLIENT_CONSOLE_URL、ADMIN_URL 必须为互不相同的地址。');
}

if (new Set(publicUrls.map((item) => new URL(item).protocol)).size !== 1) {
  throw new Error('四个公开地址必须使用同一协议；请统一使用 HTTP 或 HTTPS，避免浏览器混合内容。');
}

const baseByApplication = {
  admin: adminUrl.base,
  www: websiteUrl.base,
  console: consoleUrl.base,
};

const selectedApplications = options.target === 'all'
  ? Object.entries(applications)
  : [[options.target, applications[options.target]]];
const baseBuildEnvironment = {
  ...process.env,
  VITE_API_BASE_URL: `${apiUrl.public}/api`,
  VITE_PUBLIC_SITE_URL: websiteUrl.public,
  VITE_CONSOLE_SITE_URL: consoleUrl.public,
  VITE_SESSION_COOKIE_DOMAIN: value('CLIENT_SESSION_COOKIE_DOMAIN'),
};

if (options.dryRun) {
  console.log(`API: ${apiUrl.public}`);
  console.log(`API base: ${baseBuildEnvironment.VITE_API_BASE_URL}`);
  console.log(`WWW: ${websiteUrl.public} (base ${websiteUrl.base})`);
  console.log(`Console: ${consoleUrl.public} (base ${consoleUrl.base})`);
  console.log(`Admin: ${adminUrl.public} (base ${adminUrl.base})`);
  if (apiUrl.origin === websiteUrl.origin) {
    console.log(
      `注意：API 与官网同源，请确保 ${apiUrl.origin}/api 已反代到后端服务（否则前端请求会落到官网静态目录）。`,
    );
  }
  console.log(`构建目标: ${selectedApplications.map(([name]) => name).join(', ')}`);
  process.exit(0);
}

for (const [name, application] of selectedApplications) {
  const appBase = baseByApplication[name];
  console.log(`构建 ${name} (${application.workspace})，base=${appBase} ...`);
  await runNpm(
    ['--filter', application.workspace, 'run', 'build'],
    {
      ...baseBuildEnvironment,
      VITE_BASE_URL: appBase,
      [application.assetVariable]: appBase,
    },
  );
}

console.log('三个前端保持独立 dist，未写入 backend/public。');
