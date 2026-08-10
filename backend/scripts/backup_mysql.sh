#!/usr/bin/env bash
# ============================================================
# MySQL 数据库定时备份脚本
# 建议配合宝塔计划任务每日凌晨执行
# 用法：bash backup_mysql.sh
# ============================================================

set -euo pipefail

# ---------- 配置区 ----------
# 从 .env 读取数据库配置
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
ENV_FILE="$PROJECT_ROOT/.env"

# 备份保留天数
RETENTION_DAYS=${BACKUP_RETENTION_DAYS:-14}

# 备份目录
BACKUP_DIR="${BACKUP_DIR:-$PROJECT_ROOT/storage/backups/mysql}"

# 日期标记
DATE_TAG=$(date +%Y%m%d_%H%M%S)
# ---------- 配置区结束 ----------

# 从 .env 读取配置
if [ -f "$ENV_FILE" ]; then
    DB_HOST=$(grep -E '^DB_HOST=' "$ENV_FILE" | cut -d'=' -f2- | tr -d '"' | tr -d "'")
    DB_PORT=$(grep -E '^DB_PORT=' "$ENV_FILE" | cut -d'=' -f2- | tr -d '"' | tr -d "'")
    DB_DATABASE=$(grep -E '^DB_DATABASE=' "$ENV_FILE" | cut -d'=' -f2- | tr -d '"' | tr -d "'")
    DB_USERNAME=$(grep -E '^DB_USERNAME=' "$ENV_FILE" | cut -d'=' -f2- | tr -d '"' | tr -d "'")
    DB_PASSWORD=$(grep -E '^DB_PASSWORD=' "$ENV_FILE" | cut -d'=' -f2- | tr -d '"' | tr -d "'")
else
    echo "[ERROR] .env 文件不存在: $ENV_FILE" >&2
    exit 1
fi

# 校验必要参数
if [ -z "$DB_DATABASE" ] || [ -z "$DB_USERNAME" ]; then
    echo "[ERROR] DB_DATABASE 或 DB_USERNAME 为空，请检查 .env" >&2
    exit 1
fi

# 创建备份目录
mkdir -p "$BACKUP_DIR"

# 备份文件名
BACKUP_FILE="$BACKUP_DIR/${DB_DATABASE}_${DATE_TAG}.sql.gz"

echo "[INFO] 开始备份数据库: $DB_DATABASE"
echo "[INFO] 备份文件: $BACKUP_FILE"

# 使用临时 defaults 文件传递凭据，避免密码出现在进程列表中
DEFAULTS_FILE=$(mktemp)
trap 'rm -f "$DEFAULTS_FILE"' EXIT

# 转义密码中的双引号和反斜杠
ESCAPED_PASSWORD=$(printf '%s' "$DB_PASSWORD" | sed 's/\\/\\\\/g; s/"/\\"/g')

cat > "$DEFAULTS_FILE" <<EOF
[client]
host=${DB_HOST:-127.0.0.1}
port=${DB_PORT:-3306}
user=${DB_USERNAME}
password="${ESCAPED_PASSWORD}"
EOF

chmod 600 "$DEFAULTS_FILE"

# 执行备份
mysqldump \
    --defaults-extra-file="$DEFAULTS_FILE" \
    --single-transaction \
    --routines \
    --triggers \
    --set-gtid-purged=OFF \
    "$DB_DATABASE" | gzip > "$BACKUP_FILE"

# 立即删除凭据文件
rm -f "$DEFAULTS_FILE"
trap - EXIT

# 校验备份文件
if [ -f "$BACKUP_FILE" ] && [ "$(stat -c%s "$BACKUP_FILE" 2>/dev/null || stat -f%z "$BACKUP_FILE" 2>/dev/null)" -gt 0 ]; then
    BACKUP_SIZE=$(du -h "$BACKUP_FILE" | cut -f1)
    echo "[OK] 备份成功: $BACKUP_FILE ($BACKUP_SIZE)"
else
    echo "[ERROR] 备份文件为空或不存在" >&2
    rm -f "$BACKUP_FILE"
    exit 1
fi

# 清理过期备份
echo "[INFO] 清理 ${RETENTION_DAYS} 天前的旧备份..."
find "$BACKUP_DIR" -name "*.sql.gz" -type f -mtime +"$RETENTION_DAYS" -delete -print 2>/dev/null || true

echo "[INFO] 备份完成"
