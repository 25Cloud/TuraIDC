# syntax=docker/dockerfile:1
# ============================================================================
# TuraIDC 前端镜像：一个构建阶段产出三个 dist，一个 nginx 三端口运行时
# 构建上下文 = 仓库根目录（compose 中 context: ../..）
# 构建时通过 ARG 注入四个公开地址，供 backend/scripts/build_frontends.mjs 使用
# 运行时容器内监听 8081(www) / 8082(console) / 8083(admin)，按端口区分三端
# ============================================================================

# ---------- 构建阶段 ----------
FROM node:22-slim AS builder

# 跳过 husky 的 prepare（容器内无 .git）
ENV HUSKY=0
# corepack 在非 TTY 下载包管理器时不再等待确认
ENV COREPACK_ENABLE_DOWNLOAD_PROMPT=0

ARG APP_URL
ARG FRONTEND_URL
ARG CLIENT_CONSOLE_URL
ARG ADMIN_URL
ARG CLIENT_SESSION_COOKIE_DOMAIN

WORKDIR /app

# 生成 build_frontends.mjs 读取的 backend/.env（仅四个公开地址，不含任何密钥）
RUN mkdir -p backend \
  && printf 'APP_URL=%s\nFRONTEND_URL=%s\nCLIENT_CONSOLE_URL=%s\nADMIN_URL=%s\nCLIENT_SESSION_COOKIE_DOMAIN=%s\n' \
     "$APP_URL" "$FRONTEND_URL" "$CLIENT_CONSOLE_URL" "$ADMIN_URL" "$CLIENT_SESSION_COOKIE_DOMAIN" > backend/.env

# 先拷贝清单文件并安装依赖（源码变更不重复安装，加速重建）
# pnpm 工作区：lockfile 校验要求 pnpm-workspace.yaml 声明的全部成员清单都在场，
# 缺一个就会判定 lockfile 过期，因此 docs-web 的清单也要拷进来
COPY package.json pnpm-lock.yaml pnpm-workspace.yaml ./
COPY shared/package.json ./shared/
COPY docs-web/package.json ./docs-web/
COPY frontend-admin-v3/package.json ./frontend-admin-v3/
COPY frontend-user-v3-www/package.json ./frontend-user-v3-www/
COPY frontend-user-v4-console/package.json ./frontend-user-v4-console/
# 按 package.json 的 packageManager 字段启用 pnpm。
# --shamefully-hoist：本项目依赖根 hoist 布局（v3-www 直接 import element-plus 深路径），
# 与 docs/references/operations/deployment-and-scheduling.md 记载的部署命令保持一致。
# verify-deps-before-run=false：关闭 pnpm run 前的依赖检查，否则它会 purge 掉根 hoist 布局
# （容器内无 TTY，purge 会直接以 ERR_PNPM_ABORTED_REMOVE_MODULES_DIR_NO_TTY 中止构建）
RUN corepack enable pnpm \
  && pnpm install --frozen-lockfile --shamefully-hoist --config.verify-deps-before-run=false

# 源码（含仓库根公共文件：theme.css / tsconfig.base.json / logo / favicon）
COPY backend/scripts ./backend/scripts
COPY theme.css tsconfig.base.json logo.png favicon.png ./
COPY shared ./shared
COPY frontend-admin-v3 ./frontend-admin-v3
COPY frontend-user-v3-www ./frontend-user-v3-www
COPY frontend-user-v4-console ./frontend-user-v4-console

# 统一构建三个前端（产物分别在各自 dist/，互不写入 backend/public）
RUN pnpm --config.verify-deps-before-run=false run build:frontends

# ---------- 运行时：三端合一（三端口） ----------
FROM nginx:1.27-alpine

# 三个 dist 分别放置，nginx 按端口路由
COPY --from=builder /app/frontend-user-v3-www/dist     /usr/share/nginx/html/www
COPY --from=builder /app/frontend-user-v4-console/dist /usr/share/nginx/html/console
COPY --from=builder /app/frontend-admin-v3/dist        /usr/share/nginx/html/admin

COPY deploy/docker/frontends/nginx-default.conf /etc/nginx/conf.d/default.conf
