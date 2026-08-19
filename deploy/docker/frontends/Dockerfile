# syntax=docker/dockerfile:1
# ============================================================================
# TuraIDC 前端镜像：一个构建阶段产出三个 dist，三个 nginx 运行时阶段分别托管
# 构建上下文 = 仓库根目录（compose 中 context: ../..）
# 构建时通过 ARG 注入四个公开地址，供 backend/scripts/build_frontends.mjs 使用
# ============================================================================

# ---------- 构建阶段 ----------
FROM node:22-slim AS builder

# 跳过 husky 的 prepare（容器内无 .git）
ENV HUSKY=0

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
COPY package.json package-lock.json ./
COPY shared/package.json ./shared/
COPY frontend-admin-v3/package.json ./frontend-admin-v3/
COPY frontend-user-v3-www/package.json ./frontend-user-v3-www/
COPY frontend-user-v4-console/package.json ./frontend-user-v4-console/
RUN npm ci

# 源码（含仓库根公共文件：theme.css / tsconfig.base.json / logo / favicon）
COPY backend/scripts ./backend/scripts
COPY theme.css tsconfig.base.json logo.png favicon.png ./
COPY shared ./shared
COPY frontend-admin-v3 ./frontend-admin-v3
COPY frontend-user-v3-www ./frontend-user-v3-www
COPY frontend-user-v4-console ./frontend-user-v4-console

# 统一构建三个前端（产物分别在各自 dist/，互不写入 backend/public）
RUN npm run build:frontends

# ---------- 运行时：官网/用户入口 ----------
FROM nginx:1.27-alpine AS www
COPY --from=builder /app/frontend-user-v3-www/dist /usr/share/nginx/html
COPY deploy/docker/frontends/nginx-default.conf /etc/nginx/conf.d/default.conf

# ---------- 运行时：用户控制台 ----------
FROM nginx:1.27-alpine AS console
COPY --from=builder /app/frontend-user-v4-console/dist /usr/share/nginx/html
COPY deploy/docker/frontends/nginx-default.conf /etc/nginx/conf.d/default.conf

# ---------- 运行时：管理端 ----------
FROM nginx:1.27-alpine AS admin
COPY --from=builder /app/frontend-admin-v3/dist /usr/share/nginx/html
COPY deploy/docker/frontends/nginx-default.conf /etc/nginx/conf.d/default.conf
