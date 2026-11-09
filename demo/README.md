# 演示站（demo site）

纯静态演示站：三个前端在 `VITE_DEMO_MODE=true` 下各构建一次，请求由 `shared/demo` 的传输层
从打包进产物的示例数据应答，**没有任何后端**。

- 设计文档：[演示站设计](../docs/internal/designs/frontend/demo-site-design.md)
- 实施计划：[演示站实施计划](../docs/internal/execution-plans/active/demo-site-2026-11-09.md)

## 目录

```text
demo/
├── landing/          # /start/ 说明页与 robots.txt
├── fixtures/         # 示例数据生成器（base + 三端模块 → 三份 JSON）
├── edgeone.json      # EdgeOne Pages 项目配置（根目录 demo/）
└── dist/             # 构建产物（gitignore）
```

## 本地构建与预览

```bash
node demo/fixtures/generate.mjs          # 生成示例数据（改了 *.mjs 之后必跑）
node backend/scripts/build_demo.mjs      # 产出 demo/dist（用户端铺在根，控制台 /console，管理端 /admin）
node backend/scripts/serve_demo.mjs      # http://127.0.0.1:4190/
```

冒烟（跑的就是刚构建的产物）：

```bash
pnpm --filter turaidc-user-v4-console run test:demo
```

## 站点结构

| 路径 | 内容 |
| --- | --- |
| `/` | 系统首页（`frontend-user-v3-www`，base `/`） |
| `/console/` | 控制台（hash 路由，`#/client/login`） |
| `/admin/` | 管理端（hash 路由，直接进 `#/admin/dashboard`） |
| `/start/` | 演示说明页：三端入口、演示账号、使用须知 |

## 演示账号

| 端 | 账号 | 密码 |
| --- | --- | --- |
| 控制台 | `demo@example.com` | `demo123456` |
| 管理端 | `admin` | `demo123456` |

演示环境不校验凭据（任意账号密码都能进），登录页已自动填好这两组。

## 三端互跳

三个应用都在右下角挂了同一份导览浮层（`shared/demo/nav.ts`，Shadow DOM 注入），可以互跳，
并写着演示账号。官网首页 `site-web` 的 hero 也给了三端直链（`DEMO_ENTRIES`）。

## 示例数据怎么改

- **只改一端的接口数据**：改 `demo/fixtures/{console,admin,user}.mjs`，然后跑一次生成器；
- **改公共数据**（客户、站点、商品、实例、账单、工单）：改 `demo/fixtures/base.mjs`；
- 键是 `METHOD /path`，路径不带查询串、允许 `:id` 通配；值是业务 `data`，传输层自己套
  `{code, message, data}`；
- **字面路径要排在同段数的 `:id` 之前**（传输层按声明顺序首次匹配）；
- `refused` 里登记「演示环境不执行」的写操作；
- 生成器带 `--check`，CI/本地可用来确认产物没漂移。

## 部署（EdgeOne Pages）

新建项目，**根目录设为 `demo/`**，其余交给 `demo/edgeone.json`：

| 控制台字段 | 取值 |
| --- | --- |
| Preset framework | `Other` |
| Root directory | `demo/` |
| Build output directory | `dist` |
| Install command | `cd .. && HUSKY=0 pnpm install --frozen-lockfile` |
| Build command | `cd .. && node backend/scripts/build_demo.mjs` |
| Node.js 版本 | `24.18.0`（`edgeone.json` 里的 `nodeVersion` 会覆盖控制台设置） |

Node 必须是 22.13+：镜像里的 pnpm 11 在 22.11 上会直接报版本要求不满足。
