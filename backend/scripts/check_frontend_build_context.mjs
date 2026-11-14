#!/usr/bin/env node
/**
 * 前端镜像构建上下文校验
 *
 * 为什么需要这个脚本：
 * 三端的 request 模块通过 tsconfig paths 与 vite alias 的 @demo-fixtures 指向仓库根的
 * demo/fixtures/*.json（另有 @shared 指向 shared/）。本地与 CI 都是完整仓库，文件在场，
 * 构建照常通过；但镜像构建只按 deploy/docker/frontends/Dockerfile 里的 COPY 清单装配
 * 上下文，少拷一个目录就会在 vue-tsc / rollup 的模块解析阶段炸成
 *   TS2307: Cannot find module '@demo-fixtures/admin.json'
 * 而且这要等镜像构建跑到一半（先装完依赖）才暴露，一次要烧掉几分钟。
 *
 * 这个脚本把「前端构建实际依赖哪些跨目录路径」与「Dockerfile 到底拷了哪些」做交叉比对，
 * 在装完依赖的第一步就以秒级失败定位到具体文件，同时顺手验证 COPY 的源路径都存在，
 * 免得清单写错文件名。
 *
 * 用法：
 *   node backend/scripts/check_frontend_build_context.mjs
 *   node backend/scripts/check_frontend_build_context.mjs --dockerfile=/tmp/Dockerfile
 */

import { readFileSync, existsSync } from 'node:fs';
import { dirname, resolve, relative, isAbsolute } from 'node:path';
import { fileURLToPath } from 'node:url';

const SCRIPT_DIR = dirname(fileURLToPath(import.meta.url));
const REPO_ROOT = resolve(SCRIPT_DIR, '..', '..');

/** 参与镜像构建的三端（与 Dockerfile 的 COPY 一一对应） */
const FRONTENDS = ['frontend-admin-v3', 'frontend-user-v3-www', 'frontend-user-v4-console'];

const DEFAULT_DOCKERFILE = resolve(REPO_ROOT, 'deploy/docker/frontends/Dockerfile');

/** 转成便于比较的仓库相对路径（统一正斜杠，去掉前导 ./） */
function toRepoPath(absolutePath) {
  return relative(REPO_ROOT, absolutePath).split('\\').join('/').replace(/^\.\//, '');
}

function readArg(name) {
  const prefix = `--${name}=`;
  const hit = process.argv.find((arg) => arg.startsWith(prefix));

  return hit ? hit.slice(prefix.length) : null;
}

/**
 * 解析 Dockerfile 里的构建上下文 COPY。
 * 带 --from=<stage> 的 COPY 取的是前一阶段产物，与构建上下文无关，跳过。
 */
function parseContextCopySources(dockerfileText) {
  const sources = [];

  for (const rawLine of dockerfileText.split('\n')) {
    const line = rawLine.trim();

    if (!line || line.startsWith('#')) {
      continue;
    }

    const match = /^COPY\s+(.+)$/i.exec(line);

    if (!match) {
      continue;
    }

    const tokens = match[1].split(/\s+/).filter(Boolean);
    const flags = tokens.filter((token) => token.startsWith('--'));
    const operands = tokens.filter((token) => !token.startsWith('--'));

    if (flags.some((flag) => /^--from=/i.test(flag))) {
      continue;
    }

    // 最后一个操作数是目标，其余都是源
    for (const source of operands.slice(0, -1)) {
      sources.push(source.replace(/^\.\//, ''));
    }
  }

  return sources;
}

/** 某条 COPY 源路径是否覆盖了目标（源是目标的祖先或就是目标本身） */
function isCoveredByCopy(targetRepoPath, copySources) {
  return copySources.some(
    (source) => targetRepoPath === source || targetRepoPath.startsWith(`${source}/`),
  );
}

/** 把 paths / alias 里的目标模式裁成目录或文件路径 */
function stripWildcard(target) {
  return target.replace(/\*+$/, '');
}

/**
 * 从 tsconfig.json 的 compilerOptions.paths 收集目标。
 * 没有 baseUrl 时，TypeScript 按 tsconfig 所在目录解析，这里同口径处理。
 */
function collectTsconfigTargets(frontendDir, issues) {
  const tsconfigPath = resolve(frontendDir, 'tsconfig.json');

  if (!existsSync(tsconfigPath)) {
    issues.push(`${toRepoPath(tsconfigPath)} 不存在，无法校验 paths`);

    return [];
  }

  let tsconfig;

  try {
    tsconfig = JSON.parse(readFileSync(tsconfigPath, 'utf8'));
  } catch (error) {
    issues.push(`${toRepoPath(tsconfigPath)} 解析失败：${error.message}`);

    return [];
  }

  const compilerOptions = tsconfig.compilerOptions ?? {};
  const paths = compilerOptions.paths ?? {};
  const baseDir = compilerOptions.baseUrl ? resolve(frontendDir, compilerOptions.baseUrl) : frontendDir;
  const targets = [];

  for (const [alias, aliasTargets] of Object.entries(paths)) {
    for (const aliasTarget of aliasTargets) {
      targets.push({
        origin: `${toRepoPath(tsconfigPath)} paths["${alias}"]`,
        absolutePath: resolve(baseDir, stripWildcard(aliasTarget)),
      });
    }
  }

  return targets;
}

/**
 * vite.config.ts 里 path.resolve(__dirname, '../x') 形式的别名。
 * 只做补充扫描：正则抓不到就算了，主判据是 tsconfig paths。
 */
function collectViteTargets(frontendDir) {
  const candidates = ['vite.config.ts', 'vite.config.mts', 'vite.config.js'];
  const targets = [];

  for (const candidate of candidates) {
    const configPath = resolve(frontendDir, candidate);

    if (!existsSync(configPath)) {
      continue;
    }

    const text = readFileSync(configPath, 'utf8');
    const pattern = /path\.resolve\(\s*__dirname\s*,\s*['"](\.\.[^'"]*)['"]\s*\)/g;

    for (const match of text.matchAll(pattern)) {
      targets.push({
        origin: `${toRepoPath(configPath)} path.resolve(__dirname, '${match[1]}')`,
        absolutePath: resolve(frontendDir, match[1]),
      });
    }
  }

  return targets;
}

function main() {
  const dockerfilePath = resolve(REPO_ROOT, readArg('dockerfile') ?? DEFAULT_DOCKERFILE);

  if (!existsSync(dockerfilePath)) {
    console.error(`✖ 找不到 Dockerfile：${dockerfilePath}`);

    return 1;
  }

  const copySources = parseContextCopySources(readFileSync(dockerfilePath, 'utf8'));
  const issues = [];

  // 1. COPY 的源必须真实存在，否则镜像是「看起来有清单但装不进文件」
  for (const source of copySources) {
    const absoluteSource = resolve(REPO_ROOT, source);

    if (!existsSync(absoluteSource)) {
      issues.push(`Dockerfile 的 COPY 源不存在：${source}`);
    }
  }

  // 2. 三端解析到自身目录之外的路径，必须被某条 COPY 覆盖
  const seen = new Set();
  let checked = 0;

  for (const frontend of FRONTENDS) {
    const frontendDir = resolve(REPO_ROOT, frontend);
    const targets = [...collectTsconfigTargets(frontendDir, issues), ...collectViteTargets(frontendDir)];

    for (const target of targets) {
      const targetRepoPath = toRepoPath(target.absolutePath);

      // 解析到仓库根本身（vite 的 server.fs.allow 就是这么写的）不属于跨目录依赖
      if (targetRepoPath === '') {
        continue;
      }

      // 解析到前端自身目录内、或干脆跑出仓库根，不属于构建上下文问题
      if (isAbsolute(targetRepoPath) || targetRepoPath.startsWith('..')) {
        issues.push(`${target.origin} 指向仓库之外：${targetRepoPath}`);

        continue;
      }

      if (targetRepoPath === frontend || targetRepoPath.startsWith(`${frontend}/`)) {
        continue;
      }

      checked += 1;

      const key = `${targetRepoPath}|${target.origin}`;

      if (seen.has(key)) {
        continue;
      }

      seen.add(key);

      if (!isCoveredByCopy(targetRepoPath, copySources)) {
        issues.push(
          `${frontend} 依赖 ${targetRepoPath}（来源 ${target.origin}），` +
            `但 ${toRepoPath(dockerfilePath)} 没有 COPY 它；` +
            `镜像构建会在类型检查阶段报 TS2307。请在 Dockerfile 里补一条 COPY。`,
        );
      }
    }
  }

  if (issues.length > 0) {
    console.error('✖ 前端镜像构建上下文不完整：');

    for (const issue of issues) {
      console.error(`  - ${issue}`);
    }

    return 1;
  }

  console.log(
    `✔ 前端镜像构建上下文校验通过：${FRONTENDS.length} 个前端、${checked} 处跨目录引用、` +
      `${copySources.length} 条构建上下文 COPY。`,
  );

  // 顺带提示：解析到的跨目录路径（便于审查时快速确认覆盖范围）
  for (const entry of [...new Set([...seen].map((key) => key.split('|')[0]))].sort()) {
    console.log(`  · ${entry}`);
  }

  return 0;
}

process.exit(main());
