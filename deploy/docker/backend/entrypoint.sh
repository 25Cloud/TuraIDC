#!/usr/bin/env bash
# ============================================================================
# TuraIDC 后端容器入口：
#   1. 由容器环境变量生成 backend/.env（单一配置源 = deploy/docker/.env）
#   2. 等待 MySQL 就绪，补 APP_KEY
#   3. 空库 -> install_db.py 全量初始化（含默认管理员）；有数据 -> migrate --force
#   4. 建目录、修权限、装 crontab、生成生产配置缓存
#   5. 交给 Supervisor 拉起 php-fpm / nginx / cron / vnc:relay
# ============================================================================
set -euo pipefail

log() { echo "[entrypoint] $*"; }

BACKEND_DIR=/var/www/backend

# ---------------------------------------------------------------------------
# 0. 拷贝代码到可写层（Docker Desktop Windows overlayfs workaround）
#    镜像层文件在 /opt/backend-src，php-fpm 读镜像层会 EPERM，
#    拷贝到 /var/www/backend（可写层）后 fpm 正常读取。
#    仅首次启动拷贝；容器 restart 时可写层已有代码，跳过。
# ---------------------------------------------------------------------------
if [ ! -f "$BACKEND_DIR/artisan" ]; then
  log "拷贝代码到 /var/www/backend（tmpfs）..."
  mkdir -p "$BACKEND_DIR"
  cp -a /opt/backend-src/. "$BACKEND_DIR/"
  chown -R www-data:www-data "$BACKEND_DIR"
fi

# 覆盖宝塔专用 .user.ini：原文件 open_basedir 指向 /www/wwwroot/backend/，
# Docker 容器内代码路径是 /var/www/backend，不修正会导致 fpm 全部文件操作被拒。
echo 'open_basedir=/var/www/backend/:/tmp/' > "$BACKEND_DIR/public/.user.ini"
chown www-data:www-data "$BACKEND_DIR/public/.user.ini"

cd "$BACKEND_DIR"

# ---------------------------------------------------------------------------
# 1. 生成 backend/.env
# 只写入关键键；未列出的键走 Laravel 默认值，避免"写空覆盖默认"的陷阱。
# 值统一用双引号包裹；密码不要包含双引号/反斜杠。
# ---------------------------------------------------------------------------
log "生成 backend/.env"
: > .env
# 空值兜底：这些键若容器环境未提供，显式给默认值，避免"写空覆盖 Laravel 默认"。
# （REDIS_CLIENT 为空会导致 RedisManager 拿不到 phpredis connector，MAIL_MAILER 为空会破坏邮件默认驱动）
export REDIS_CLIENT="${REDIS_CLIENT:-phpredis}"
export MAIL_MAILER="${MAIL_MAILER:-log}"
# APP_KEY 不走引号模板：key:generate 用正则 ^APP_KEY=.* 替换整行，
# 引号包裹会导致替换后残留 "" 使 key 损坏。单独写裸值。
for var in \
  APP_NAME APP_ENV APP_DEBUG \
  APP_URL FRONTEND_URL CLIENT_CONSOLE_URL ADMIN_URL CLIENT_SESSION_COOKIE_DOMAIN \
  SESSION_SECURE_COOKIE INSTALL_ADMIN_PASSWORD \
  DB_DATABASE DB_USERNAME DB_PASSWORD \
  REDIS_PASSWORD \
  CACHE_STORE QUEUE_CONNECTION SESSION_DRIVER REDIS_CLIENT \
  TURAIDC_BUSINESS_QUEUES TURAIDC_SCHEDULE_QUEUE \
  SENTRY_LARAVEL_DSN MAIL_MAILER MAIL_FROM_ADDRESS; do
  val="$(printenv "$var" || true)"
  printf '%s="%s"\n' "$var" "$val" >> .env
done

# APP_KEY 裸值写入（不用引号），后续 key:generate 能正确替换
APP_KEY_VAL="$(printenv APP_KEY || true)"
printf 'APP_KEY=%s\n' "$APP_KEY_VAL" >> .env

# 容器内固定使用 compose 网络的服务名
printf '%s\n' \
  'DB_CONNECTION="mysql"' \
  'DB_HOST="mysql"' \
  'DB_PORT="3306"' \
  'REDIS_HOST="redis"' \
  'REDIS_PORT="6379"' \
  'DB_TIMEZONE="+08:00"' \
  >> .env

chown www-data:www-data .env

# ---------------------------------------------------------------------------
# 2. 等待 MySQL 就绪
# ---------------------------------------------------------------------------
log "等待 MySQL 就绪..."
DB_HOST="$(grep '^DB_HOST=' .env | cut -d'"' -f2)"
DB_USERNAME="$(grep '^DB_USERNAME=' .env | cut -d'"' -f2)"
DB_PASSWORD="$(grep '^DB_PASSWORD=' .env | cut -d'"' -f2)"
DB_DATABASE="$(grep '^DB_DATABASE=' .env | cut -d'"' -f2)"

# 密码为空时不要传 -p（否则 mysql 客户端会交互式等待输入导致卡死）
DB_AUTH=()
[ -n "$DB_PASSWORD" ] && DB_AUTH=(-p"$DB_PASSWORD")

until mysqladmin ping -h"$DB_HOST" -u"$DB_USERNAME" "${DB_AUTH[@]}" --silent; do
  sleep 3
done
log "MySQL 已就绪"

# ---------------------------------------------------------------------------
# 3. APP_KEY
# ---------------------------------------------------------------------------
if [ -z "$(grep '^APP_KEY=' .env | cut -d'=' -f2-)" ]; then
  log "APP_KEY 为空，生成中..."
  php artisan key:generate --force
fi

# ---------------------------------------------------------------------------
# 4. 建目录、权限、storage 软链
# ---------------------------------------------------------------------------
mkdir -p \
  storage/logs \
  storage/framework/cache/data \
  storage/framework/sessions \
  storage/framework/views \
  storage/framework/testing \
  storage/app/public \
  storage/app/backups \
  public/uploads \
  bootstrap/cache

log "建立 public/storage 软链"
php artisan storage:link >/dev/null 2>&1 || true

chown -R www-data:www-data storage bootstrap/cache public/uploads

# ---------------------------------------------------------------------------
# 5. 数据库初始化
#   空库  -> install_db.py（导入 baseline + 增量迁移 + 默认管理员）
#   有数据 -> 只做增量迁移（与现网宝塔口径一致）
# ---------------------------------------------------------------------------
TABLE_COUNT="$(mysql -h"$DB_HOST" -u"$DB_USERNAME" "${DB_AUTH[@]}" -N -s \
  -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB_DATABASE';" 2>/dev/null || echo 0)"

if [ "${TABLE_COUNT:-0}" = "0" ]; then
  log "空库，执行 install_db.py 全量初始化（首次运行会创建默认管理员 cerbo）..."
  INSTALL_ADMIN_PASSWORD="$(grep '^INSTALL_ADMIN_PASSWORD=' .env | cut -d'"' -f2)" \
    python3 scripts/install_db.py
else
  log "检测到已有数据（${TABLE_COUNT} 张表），执行增量迁移..."
  php artisan migrate --force
fi

chown -R www-data:www-data storage bootstrap/cache

# ---------------------------------------------------------------------------
# 6. crontab（每分钟 schedule:run，驱动心跳与队列消费；与宝塔口径一致）
# ---------------------------------------------------------------------------
echo '* * * * * cd /var/www/backend && php artisan schedule:run >> /dev/null 2>&1' \
  | crontab -u www-data -

# ---------------------------------------------------------------------------
# 7. 生产配置缓存
# 注意：routes/ 含闭包路由，route:cache 会失败，只做 config:cache
# ---------------------------------------------------------------------------
log "生成 config 缓存"
php artisan config:cache || log "警告：config:cache 失败，继续启动（不影响可用性）"

# ---------------------------------------------------------------------------
# 8. 交给 Supervisor
# ---------------------------------------------------------------------------
log "启动 Supervisor（php-fpm / nginx / cron / vnc:relay）"
exec /usr/bin/supervisord -n -c /etc/supervisor/supervisord.conf
