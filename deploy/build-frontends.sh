#!/usr/bin/env bash
#
# 生产前端构建脚本 —— 必须在生产机上直接跑，不要本地构建再上传 dist。
#
# 为什么：三端仓库里的 `.env` 是开发用配置（API 基址指向开发域名），
# `vite build` 会把它烧进产物。本地构建再上传，等于把开发域名发布到生产，
# 表现就是「站点每个页面都提示网络异常」——请求全打到不存在的域名上。
# 这里显式注入生产基址，并在构建后校验产物，基址不对就直接失败。
#
# 用法：
#   bash deploy/build-frontends.sh                 # 构建三端
#   bash deploy/build-frontends.sh frontend-admin-v3   # 只构建指定端
#
set -euo pipefail

API_BASE="${VITE_API_BASE_URL:-https://api.25y.cn/api}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
APPS="${*:-frontend-user-v3-www frontend-user-v4-console frontend-admin-v3}"

fail() { echo "  ✗ $*" >&2; exit 1; }

for app in $APPS; do
  echo "== $app (VITE_API_BASE_URL=$API_BASE)"
  [ -f "$ROOT/$app/package.json" ] || fail "$app 不存在"

  ( cd "$ROOT/$app" && VITE_API_BASE_URL="$API_BASE" pnpm run build )

  entry=$(grep -o 'assets/js/index-[A-Za-z0-9_-]*\.js' "$ROOT/$app/dist/index.html" | head -1 || true)
  [ -n "$entry" ] || fail "$app 产物里找不到入口 chunk"

  domains=$(grep -ohE 'https://api\.[a-z0-9.-]+/api' "$ROOT/$app/dist/$entry" 2>/dev/null | sort -u || true)
  [ "$domains" = "$API_BASE" ] || fail "$app 入口 chunk 里烧的 API 基址是 [${domains:-无}]，期望 [$API_BASE]"

  chown -R www:www "$ROOT/$app/dist" 2>/dev/null || true
  echo "  ✓ $entry -> $domains"
done

echo "全部完成。浏览器侧请强刷（Ctrl+Shift+R）；走 CDN 的域名记得刷新 HTML 缓存。"