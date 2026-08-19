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
docker compose exec -T mysql sh -c \
  'exec mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction --routines --triggers "$MYSQL_DATABASE"' \
  | gzip > "$OUT_FILE"

echo "[backup] 完成：$(du -h "$OUT_FILE" | cut -f1)"

# 清理过期备份
find "$BACKUP_DIR" -name 'turaidc-*.sql.gz' -mtime +"${RETENTION_DAYS}" -delete
echo "[backup] 已清理 ${RETENTION_DAYS} 天前的备份"
