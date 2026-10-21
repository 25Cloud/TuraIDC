#!/usr/bin/env bash
# 本地 Docker CI 入口 —— 复刻 .github/workflows/ci.yml（backend + frontend 两个 job）。
#
# 用法：bash deploy/docker/ci/run-ci-local.sh [backend|frontend|all]
#   - 默认 all：先构建 PHP 镜像，再顺序跑 backend、frontend
#   - backend：仅 backend job（composer validate → install → Pint → PHPStan）
#   - frontend：仅 frontend job（pnpm install → lint → typecheck → build）
#
# 前置要求：
#   - 本机 docker ≥ 29（含 compose 插件）
#   - 基础镜像可拉取（PHP/MySQL/Node 镜像；如本机配置了镜像加速器或代理则自动生效）
#   - 如包仓库（composer/pnpm）需要 HTTP 代理，请先导出 CI_HTTP_PROXY / CI_HTTPS_PROXY：
#       CI_HTTP_PROXY=http://127.0.0.1:7897 CI_HTTPS_PROXY=http://127.0.0.1:7897 \
#         bash deploy/docker/ci/run-ci-local.sh
set -u

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
COMPOSE_FILE="$REPO_ROOT/deploy/docker/ci/docker-compose.ci.yml"
PHP_IMAGE="turaidc-ci-php:8.3"
# 含 CVE-2026-109618 / CVE-2026-109619 修复的最低补丁版（8.3.36 / 8.4.27 / 8.5.12）。CI 容器只做静态检查不提供请求，
# 但镜像里的 PHP 过旧会让「本机复现 CI」与线上环境脱节，因此给出刷新开关。
PHP_PATCHED_MIN="8.3.36"

TARGET="${1:-all}"
case "$TARGET" in
  all|backend|frontend) ;;
  *) echo "用法: $0 [backend|frontend|all]"; exit 2 ;;
esac

if [ ! -d "$REPO_ROOT/.git" ]; then
  echo "错误: 未在 TuraIDC 仓库内运行（$REPO_ROOT）" >&2
  exit 1
fi

dc() { docker compose -f "$COMPOSE_FILE" "$@"; }

step() { echo; echo "===== $* ====="; }

# 确保基础镜像可达（失败时给出提示）
if [ "$TARGET" = "backend" ] || [ "$TARGET" = "all" ]; then
  if [ "${REFRESH_PHP_IMAGE:-0}" = "1" ] || ! docker image inspect "$PHP_IMAGE" >/dev/null 2>&1; then
    step "构建 PHP 8.3 CI 镜像（compose build）"
    dc build backend || { echo "PHP 镜像构建失败" >&2; exit 1; }
  else
    step "复用已有 PHP CI 镜像（要取最新补丁：REFRESH_PHP_IMAGE=1 bash $0 ${TARGET}）"
  fi
  step "CI 镜像内的 PHP 版本"
  php_ver="$(dc run --rm --no-deps backend php -r 'echo PHP_VERSION;' 2>/dev/null | tr -d '\r' | tail -n1)"
  if [ -n "$php_ver" ]; then
    echo "镜像内 PHP: $php_ver（含 CVE 修复的补丁下限 $PHP_PATCHED_MIN）"
    if [ "$(printf '%s\n%s\n' "$PHP_PATCHED_MIN" "$php_ver" | sort -V | head -n1)" != "$PHP_PATCHED_MIN" ]; then
      echo "警告：镜像内 PHP 低于 $PHP_PATCHED_MIN，未含 CVE-2026-109618/109619 修复；用 REFRESH_PHP_IMAGE=1 重建" >&2
    fi
  else
    echo "（取不到版本，忽略）"
  fi
fi

if [ "$TARGET" = "backend" ] || [ "$TARGET" = "all" ]; then
  step "backend job：composer validate → install → Pint → PHPStan"
  dc run --rm backend || { echo "backend job 失败" >&2; exit 1; }
fi

if [ "$TARGET" = "frontend" ] || [ "$TARGET" = "all" ]; then
  step "frontend job：pnpm install → lint → typecheck → build"
  dc run --rm frontend || { echo "frontend job 失败" >&2; exit 1; }
fi

step "本地 Docker CI 全部通过 ✅"