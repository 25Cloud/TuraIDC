#!/usr/bin/env bash
# 一次性工具：在 CI PHP 容器内跑 pint 修复（供本地 CI 回归用）
set -eux
git config --global --add safe.directory /repo
mkdir -p /ci && git -C /repo archive HEAD | tar -x -C /ci
cd /ci/backend
composer install --no-interaction --prefer-dist --no-progress
vendor/bin/pint