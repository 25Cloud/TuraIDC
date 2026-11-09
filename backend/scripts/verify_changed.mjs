#!/usr/bin/env node
/**
 * 增量验证：只跑「改动真正影响到的」检查，替代每次都全量。
 *
 * 为什么需要它（2026-11-08 本机实测）：
 *   - `php artisan test tests/Feature/X.php`（7 条）= 48.1 秒，`vendor/bin/phpunit` 同一个文件 = 5.2 秒。
 *     差额来自 artisan 启动时按 `.env` 连开发库，本机那个库一次连接要 40 秒级；
 *     phpunit 走 `phpunit.xml.dist` 的 `APP_ENV=testing`，连的是本地测试库，秒级。
 *   - `phpstan analyse 单个文件` 同理：不指定 APP_ENV 时 90.3 秒，`APP_ENV=testing` 后 6.3 秒。
 *   所以这里的后端命令一律用 phpunit / 带 APP_ENV=testing，别再退回 `php artisan test`。
 *
 * 用法：
 *   pnpm run verify:changed                # 全自动：改到哪跑到哪
 *   pnpm run verify:changed -- --dry-run   # 只打印将要执行的命令
 *   pnpm run verify:changed -- --only=test # 只跑测试
 *   pnpm run verify:changed -- --base=origin/main
 */

import { execFileSync, spawnSync } from 'node:child_process';
import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs';
import { dirname, join, relative, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..');
const BACKEND = join(ROOT, 'backend');

// PHP 解释器：本机 PATH 上的 `php` 可能是别的版本（phpstudy 里就有 7.4），
// 版本不对会让 php -l / phpstan / phpunit 成片报错，所以允许用 TURAIDC_PHP 指定。
const PHP = (process.env.TURAIDC_PHP ?? '').trim() || 'php';

const FRONTEND_BUILDS = {
    'frontend-admin-v3': ['pnpm', ['--filter', 'turaidc-admin-v3', 'build']],
    'frontend-user-v4-console': ['pnpm', ['--filter', 'turaidc-user-v4-console', 'build']],
    'frontend-user-v3-www': ['pnpm', ['--filter', 'turaidc-user-v3-www', 'build']],
    'shared': ['pnpm', ['run', 'typecheck:shared']],
    'site-web': ['npm', ['--prefix', 'site-web', 'run', 'build']],
};

const args = process.argv.slice(2).filter((arg) => arg !== '--');
const dryRun = args.includes('--dry-run');
const only = (args.find((arg) => arg.startsWith('--only=')) ?? '').slice('--only='.length);
const baseArg = args.find((arg) => arg.startsWith('--base='));
const base = baseArg ? baseArg.slice('--base='.length) : 'HEAD';

function git(argsList) {
    try {
        return execFileSync('git', argsList, { cwd: ROOT, encoding: 'utf8' }).split('\n').filter(Boolean);
    } catch {
        return [];
    }
}

function changedFiles() {
    const tracked = base === 'HEAD' ? git(['diff', '--name-only', 'HEAD']) : git(['diff', '--name-only', base]);
    const untracked = git(['ls-files', '--others', '--exclude-standard']);
    const merged = new Set([...tracked, ...untracked]);

    return [...merged].map((file) => file.replaceAll('\\', '/')).filter((file) => file !== '');
}

function walk(dir, out = []) {
    for (const entry of readdirSync(dir)) {
        if (entry === 'node_modules' || entry === 'vendor' || entry === 'dist') {
            continue;
        }

        const full = join(dir, entry);
        const stat = statSync(full);
        if (stat.isDirectory()) {
            walk(full, out);
        } else if (entry.endsWith('Test.php')) {
            out.push(full);
        }
    }

    return out;
}

function testIndex() {
    const roots = [join(BACKEND, 'tests'), join(BACKEND, 'plugins')];
    const files = [];

    for (const root of roots) {
        if (existsSync(root)) {
            walk(root, files);
        }
    }

    return files.map((file) => ({
        path: file,
        relative: relative(ROOT, file).replaceAll('\\', '/'),
        content: readFileSync(file, 'utf8'),
    }));
}

function run(label, command, commandArgs, options = {}) {
    const printable = [command, ...commandArgs].join(' ');
    if (dryRun) {
        console.log(`  [dry-run] ${printable}`);
        return true;
    }

    const startedAt = Date.now();
    const result = spawnSync(command, commandArgs, {
        cwd: options.cwd ?? ROOT,
        stdio: 'inherit',
        shell: process.platform === 'win32',
        env: { ...process.env, ...(options.env ?? {}) },
    });
    const seconds = ((Date.now() - startedAt) / 1000).toFixed(1);
    const ok = result.status === 0;

    console.log(`${ok ? '✅' : '❌'} ${label}（${seconds}s）`);

    return ok;
}

const files = changedFiles();
if (files.length === 0) {
    console.log('没有改动，无需验证。');
    process.exit(0);
}

const backendPhp = files.filter((file) => file.startsWith('backend/') && file.endsWith('.php'));
const frontends = [...new Set(files.map((file) => file.split('/')[0]).filter((dir) => FRONTEND_BUILDS[dir]))];
const docsTouched = files.some((file) => file.startsWith('docs/') || file.endsWith('.md'));
const want = (step) => only === '' || only === step;
const failed = [];

console.log(`改动文件 ${files.length} 个${dryRun ? '（dry-run）' : ''}`);

// 1) 语法：改动到的 PHP 文件先过一遍 php -l，语法错不该等到 phpunit 才暴露
if (want('lint') && backendPhp.length > 0) {
    console.log('\n[1/4] PHP 语法检查');
    for (const file of backendPhp) {
        if (! run(`php -l ${file}`, PHP, ['-l', file], { cwd: ROOT })) {
            failed.push(`语法错误：${file}`);
        }
    }
}

// 2) 静态分析：只分析改动文件，且必须带 APP_ENV=testing（本机 .env 的库连接要 40 秒级）
if (want('phpstan') && backendPhp.filter((file) => file.startsWith('backend/app/')).length > 0) {
    console.log('\n[2/4] phpstan（仅改动文件，APP_ENV=testing）');
    const targets = backendPhp.filter((file) => file.startsWith('backend/app/')).map((file) => relative(BACKEND, join(ROOT, file)));
    if (! run('phpstan', PHP, ['vendor/bin/phpstan', 'analyse', '--no-progress', '--memory-limit=1G', ...targets], {
        cwd: BACKEND,
        env: { APP_ENV: 'testing' },
    })) {
        failed.push('phpstan 未通过');
    }
}

// 3) 测试：改动文件 → 引用到该类的测试文件（按类名整词匹配），命中不了就跳过
if (want('test') && backendPhp.length > 0) {
    console.log('\n[3/4] 相关测试');
    const index = testIndex();
    const matched = new Set();
    const unmapped = [];

    for (const file of backendPhp) {
        if (file.startsWith('backend/tests/') || file.includes('/Tests/')) {
            matched.add(file);
            continue;
        }

        if (! file.startsWith('backend/app/') && ! file.startsWith('backend/plugins/')) {
            // 路由/配置这类文件映射不到具体测试，只能提示人工挑
            unmapped.push(file);
            continue;
        }

        const source = readFileSync(join(ROOT, file), 'utf8');
        const className = /(?:final\s+|abstract\s+)?class\s+(\w+)/.exec(source)?.[1] ?? '';
        if (className === '') {
            unmapped.push(file);
            continue;
        }

        // 用整词匹配：`ProductController` 在大量测试里出现，子串匹配会把半套测试都拉进来
        const word = new RegExp(`\\b${className}\\b`);
        for (const test of index) {
            if (word.test(test.content)) {
                matched.add(test.relative);
            }
        }
    }

    if (unmapped.length > 0) {
        console.log(`  ${unmapped.length} 个文件无法自动映射（路由/配置等），按需手动补：`);
        for (const file of unmapped.slice(0, 5)) {
            console.log(`    - ${file}`);
        }
    }

    if (matched.size === 0) {
        console.log('  没找到引用改动类的测试，跳过（高风险改动请手动指定）');
    } else {
        // phpunit 在 backend/ 下执行，路径要相对 backend/（插件测试也在 backend/plugins 下）
        const list = [...new Set([...matched].map((file) => file.replace(/^backend\//, '')))].sort();
        console.log(`  命中 ${list.length} 个测试文件`);
        const ok = run('phpunit', PHP, ['vendor/bin/phpunit', ...list], { cwd: BACKEND, env: { APP_ENV: 'testing' } });
        if (! ok) {
            failed.push('测试未通过');
        }
    }
}

// 4) 前端构建：只有改到哪个前端才构建哪个
if (want('build') && frontends.length > 0) {
    console.log('\n[4/4] 前端构建');
    for (const dir of frontends) {
        const [command, commandArgs] = FRONTEND_BUILDS[dir];
        if (! run(`${dir} 构建`, command, commandArgs)) {
            failed.push(`${dir} 构建失败`);
        }
    }
}

if (want('docs') && docsTouched) {
    console.log('\n[附加] 文档校验');
    if (! run('docs:check', 'pnpm', ['run', 'docs:check'])) {
        failed.push('docs:check 未通过');
    }
}

console.log('');
if (failed.length > 0) {
    console.log(`未通过 ${failed.length} 项：`);
    for (const item of failed) {
        console.log(`  - ${item}`);
    }
    process.exit(1);
}

console.log('增量验证通过。发布前请再跑一次全量（见 docs/develop/testing.md）。');
