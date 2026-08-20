#!/usr/bin/env bash
# ============================================================================
# TuraIDC 数据库备份脚本（Docker 版）
#
# 用法（在 deploy/docker 目录下执行）：
#   ./backup.sh               # 默认保留 14 天
#   ./backup.sh 30            # 保留 30 天
#
# 定时备份（宿主机 crontab）：
#   0 3 * * * cd /path/to/deploy/docker && ./backup.sh >> /var/log/turaidc-backup.log 2>&1
#
# 安全措施：
#   - pipefail 确保 mysqldump 失败时整条管道失败（不会生成截断的 .sql.gz）
#   - 备份后用 gzip -t 校验文件完整性
#   - 校验通过才清理旧备份；校验失败保留文件并报警
# ============================================================================
set -euo pipefail

cd "$(dirname "$0")"

RETENTION_DAYS="${1:-14}"
BACKUP_DIR="backups"
STAMP="$(date +%Y%m%d_%H%M%S)"
OUT_FILE="${BACKUP_DIR}/turaidc-${STAMP}.sql.gz"

mkdir -p "$BACKUP_DIR"

echo "[backup] 开始备份 -> ${OUT_FILE}"

# 凭据直接取自 mysql 容器环境，无需在宿主机存密码
# pipefail 保证 mysqldump 失败时管道整体失败，不会生成截断文件
if ! docker compose exec -T mysql sh -c \
  'exec mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction --routines --triggers "$MYSQL_DATABASE"' \
  | gzip > "$OUT_FILE"; then
  echo "[backup] 错误：mysqldump 失败，删除不完整的备份文件"
  rm -f "$OUT_FILE"
  exit 1
fi

# 校验 gzip 完整性
if ! gzip -t "$OUT_FILE" 2>/dev/null; then
  echo "[backup] 错误：备份文件校验失败（gzip 损坏），保留文件以供排查：${OUT_FILE}"
  exit 1
fi

echo "[backup] 完成：$(du -h "$OUT_FILE" | cut -f1)（已校验完整性）"

# 清理过期备份（仅在校验通过后执行）
find "$BACKUP_DIR" -name 'turaidc-*.sql.gz' -mtime +"${RETENTION_DAYS}" -delete
echo "[backup] 已清理 ${RETENTION_DAYS} 天前的备份"
