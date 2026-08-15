import { execFileSync } from 'node:child_process';
import { existsSync, readdirSync, statSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const scriptDir = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(scriptDir, '../..');
process.chdir(root);

const errors = [];
const warnings = [];

function git(args) {
  const output = execFileSync('git', ['-c', 'core.quotePath=false', ...args], {
    cwd: root,
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'pipe'],
  });

  return output
    .split(/\r?\n/)
    .map((line) => line.trim())
    .filter(Boolean);
}

function addPaths(target, label, paths) {
  for (const item of paths) {
    target.push(`${label}: ${item}`);
  }
}

function existingTrackedPaths(pathspec) {
  return git(['ls-files', '--', pathspec])
    .filter((trackedPath) => existsSync(path.join(root, trackedPath)));
}

const trackedForbiddenPathspecs = [
  'backend/storage/framework/testing/*.sqlite',
  'frontend-admin-v3/dist/*',
  'frontend-user-v3-www/dist/*',
  'frontend-user-v4-console/dist/*',
  'node_modules/*',
  '*/node_modules/*',
  '*/package-lock.json',
  '*.zip',
  'backend/storage/app/private/tmp*',
];

for (const pathspec of trackedForbiddenPathspecs) {
  addPaths(errors, 'tracked generated artifact', existingTrackedPaths(pathspec));
}

const trackedSqlite = existingTrackedPaths('*.sqlite');
if (trackedSqlite.length > 0) {
  addPaths(errors, 'tracked sqlite artifact', trackedSqlite);
}

for (const forbiddenDirectory of [
  'backend/public/frontend',
  'backend/public/site',
  'backend/public/client',
  'backend/public/admin',
  'backend/public/vnc',
]) {
  if (existsSync(path.join(root, forbiddenDirectory))) {
    errors.push(`frontend artifact must not live under backend public: ${forbiddenDirectory}`);
  }
}

const rootAllowedMarkdown = new Set([
  'AGENTS.md',
  'CLAUDE.md',
  'README.md',
]);

for (const entry of readdirSync(root)) {
  const fullPath = path.join(root, entry);
  const stat = statSync(fullPath);

  if (stat.isFile() && /^idc_/i.test(entry)) {
    errors.push(`root database artifact: ${entry}`);
    continue;
  }

  if (stat.isFile() && /\.md$/i.test(entry) && !rootAllowedMarkdown.has(entry)) {
    errors.push(`misplaced root markdown: ${entry}`);
  }

  if (stat.isFile() && /\.sql(\.gz)?$/i.test(entry)) {
    errors.push(`root sql dump: ${entry}`);
  }

  if (stat.isFile() && /\.log$/i.test(entry)) {
    errors.push(`root log file: ${entry}`);
  }
}

const appRootMarkdownAllowlist = new Set([
  'README.md',
  'README-zh_CN.md',
  'CHANGELOG.md',
  'PUBLISH.md',
  'LICENSE.md',
  'CONTRIBUTING.md',
]);

for (const appRoot of ['frontend-admin-v3', 'frontend-user-v3-www', 'frontend-user-v4-console']) {
  const fullAppRoot = path.join(root, appRoot);
  if (!existsSync(fullAppRoot)) {
    continue;
  }

  for (const entry of readdirSync(fullAppRoot)) {
    const fullPath = path.join(fullAppRoot, entry);
    if (!statSync(fullPath).isFile() || !/\.md$/i.test(entry)) {
      continue;
    }

    const markdownFile = `${appRoot}/${entry}`;
    const name = path.basename(markdownFile);
    if (!appRootMarkdownAllowlist.has(name)) {
      errors.push(`misplaced app report/document: ${markdownFile}`);
    }
  }
}

for (const staleDir of [
  'frontend-admin',
  'frontend-client',
  'frontend-user-v3-console',
  'frontend-www-v2',
  'frontend-console-v2',
]) {
  if (existsSync(path.join(root, staleDir))) {
    warnings.push(`stale local directory still exists: ${staleDir}`);
  }
}

for (const localToolDir of ['.runlogs', '.codegraph', '.reasonix']) {
  if (existsSync(path.join(root, localToolDir))) {
    warnings.push(`ignored local tool directory exists: ${localToolDir}`);
  }
}

if (warnings.length > 0) {
  console.log('Workspace health warnings:');
  for (const warning of warnings) {
    console.log(`  - ${warning}`);
  }
  console.log('');
}

if (errors.length > 0) {
  console.log('Workspace health check failed:');
  for (const error of [...new Set(errors)]) {
    console.log(`  - ${error}`);
  }
  process.exit(1);
}

console.log('Workspace health check passed.');
