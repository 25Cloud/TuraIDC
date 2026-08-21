#!/usr/bin/env bash
# 本地 Docker CI 入口 —— 复刻 .github/workflows/ci.yml（backend + frontend 两个 job）。
#
# 用法：bash deploy/docker/ci/run-ci-local.sh [backend|frontend|all]
#   - 默认 all：先构建 PHP 镜像，再顺序跑 backend、frontend
#   - backend：仅 backend job（composer validate → install → Pint → PHPStan）
#   - frontend：仅 frontend job（pnpm install → lint → typecheck → build）
#
# 前置：root 侧 docker ≥ 29（含 compose 插件）、镜像走得通 gh.yealqp.cn 前缀，
#       本机 127.0.0.1:7897 有 HTTP 代理（host 网络下容器直接复用）。
set -u

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
COMPOSE_FILE="$REPO_ROOT/deploy/docker/ci/docker-compose.ci.yml"
PHP_IMAGE="gh.yealqp.cn/turaidc-ci-php:8.3"

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
  if ! docker image inspect "$PHP_IMAGE" >/dev/null 2>&1; then
    step "构建 PHP 8.3 CI 镜像（compose build）"
    dc build backend || { echo "PHP 镜像构建失败" >&2; exit 1; }
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