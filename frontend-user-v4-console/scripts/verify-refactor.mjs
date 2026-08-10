import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const failures = [];

const expectedRoutes = [
  '/client/login',
  '/client/login-as',
  '/client/register',
  '/client/forgot-password',
  '/client/dashboard',
  '/client/profile',
  '/client/verification',
  '/client/order/create',
  '/client/checkout-resume',
  '/client/services',
  '/client/services/:id',
  '/client/invoices',
  '/client/invoices/:id',
  '/client/catalog',
  '/client/recharge',
  '/client/payments',
  '/client/balance-logs',
  '/client/coupons',
  '/client/referral',
  '/client/tickets',
  '/client/tickets/:id',
  '/client/ticket-conversations/:id',
  '/client/notices',
  '/client/notices/:id',
  '/client/help',
  '/client/help/:id',
];

const forbiddenTerms = [
  'element-plus',
  '@element-plus/icons-vue',
  'ElMessage',
  'ElMessageBox',
  '<el-',
  'BaseButton',
  'BaseCard',
];

const scanTargets = ['package.json', 'vite.config.ts', 'index.html', 'src'].map((item) => path.join(root, item));

const pxScanTargets = [
  'src/app',
  'src/domains',
  'src/pages/client',
  'src/router/modules/client.ts',
  'src/style/index.less',
].map((item) => path.join(root, item));

function fail(message) {
  failures.push(message);
}

function readFile(filePath) {
  return fs.readFileSync(filePath, 'utf8');
}

function listFiles(targets, files = []) {
  for (const target of targets) {
    if (!fs.existsSync(target)) continue;
    const stat = fs.statSync(target);
    if (stat.isDirectory()) {
      for (const child of fs.readdirSync(target)) {
        if (['node_modules', 'dist', '.git'].includes(child)) continue;
        listFiles([path.join(target, child)], files);
      }
    } else if (/\.(?:vue|ts|tsx|js|jsx|json|less|css|html)$/.test(target)) {
      files.push(target);
    }
  }
  return files;
}

function normalizePath(rawPath, parentPath = '') {
  if (!rawPath) return '';
  if (rawPath.startsWith('/')) return rawPath;
  const base = parentPath.replace(/\/$/, '');
  return `${base}/${rawPath}`.replace(/\/+/g, '/');
}

function extractClientRoutes() {
  const source = readFile(path.join(root, 'src/router/modules/client.ts'));
  const routeRegex = /path:\s*['"]([^'"]+)['"]/g;
  const routes = [];
  const stack = [];
  let match;

  match = routeRegex.exec(source);
  while (match) {
    const rawPath = match[1];
    if (rawPath === '/' || rawPath === '/client' || rawPath.startsWith('/client/')) {
      stack.length = 0;
    }
    const parent = rawPath.startsWith('/client') ? '' : '/client';
    const fullPath = normalizePath(rawPath, parent);
    if (fullPath.startsWith('/client/')) routes.push(fullPath);
    match = routeRegex.exec(source);
  }

  return new Set(routes);
}

function assertExpectedRoutes() {
  const routes = extractClientRoutes();
  for (const expected of expectedRoutes) {
    if (!routes.has(expected)) {
      fail(`missing route: ${expected}`);
    }
  }
}

function assertNoForbiddenTerms() {
  const files = listFiles(scanTargets);
  for (const file of files) {
    const content = readFile(file);
    for (const term of forbiddenTerms) {
      if (content.includes(term)) {
        fail(`forbidden term "${term}" in ${path.relative(root, file)}`);
      }
    }
  }
}

function assertNoPlaceholderRoute() {
  const routerSource = readFile(path.join(root, 'src/router/modules/client.ts'));
  if (routerSource.includes('pages/client/placeholder') || routerSource.includes('component: placeholder')) {
    fail('client router still references placeholder page');
  }
  if (fs.existsSync(path.join(root, 'src/pages/client/placeholder'))) {
    fail('placeholder page directory still exists');
  }
}

function assertNoFixedPxInMigratedClientCode() {
  const files = listFiles(pxScanTargets);
  const pxPattern = /\b\d+(?:\.\d+)?px\b/;
  for (const file of files) {
    const content = readFile(file);
    if (pxPattern.test(content)) {
      fail(`fixed px token in migrated code: ${path.relative(root, file)}`);
    }
  }
}

function assertNoIndexAndTheme() {
  const indexHtml = readFile(path.join(root, 'index.html'));
  if (!indexHtml.includes('<meta name="robots" content="noindex,nofollow"')) {
    fail('index.html is missing noindex,nofollow robots meta');
  }

  const styleIndex = readFile(path.join(root, 'src/style/index.less'));
  if (!styleIndex.includes('../../../theme.css')) {
    fail('src/style/index.less does not import repository theme.css');
  }
}

function assertPackage() {
  const pkg = JSON.parse(readFile(path.join(root, 'package.json')));
  const dependencies = { ...pkg.dependencies, ...pkg.devDependencies };
  for (const key of Object.keys(dependencies)) {
    if (key === 'element-plus' || key === '@element-plus/icons-vue') {
      fail(`forbidden dependency: ${key}`);
    }
  }
  if (!dependencies['tdesign-vue-next'] || !dependencies['tdesign-icons-vue-next']) {
    fail('missing TDesign Vue Next dependencies');
  }
  if (pkg.scripts?.['verify:refactor'] !== 'node scripts/verify-refactor.mjs') {
    fail('package.json verify:refactor script is not configured');
  }
}

assertExpectedRoutes();
assertNoForbiddenTerms();
assertNoPlaceholderRoute();
assertNoFixedPxInMigratedClientCode();
assertNoIndexAndTheme();
assertPackage();

if (failures.length) {
  console.error('verify:refactor failed');
  for (const item of failures) {
    console.error(`- ${item}`);
  }
  process.exit(1);
}

console.log('verify:refactor passed');
