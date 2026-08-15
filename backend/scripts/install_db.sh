#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
ENV_FILE="${BACKEND_DIR}/.env"
ARTISAN_FILE="${BACKEND_DIR}/artisan"
SCHEMA_FILE="${BACKEND_DIR}/database/schema/mysql-schema.sql"
ADMIN_PASSWORD_ENV_KEY="INSTALL_ADMIN_PASSWORD"
DEFAULT_ADMIN_PASSWORD="Temp@123456"

DRY_RUN=0

log() {
  printf '[install-db] %s\n' "$*"
}

fail() {
  printf '[install-db] 错误：%s\n' "$*" >&2
  exit 1
}

usage() {
  cat <<'EOF'
用法：
  bash backend/scripts/install_db.sh [--dry-run]

说明：
  1. 读取 backend/.env 中的数据库配置
  2. 自动创建数据库（如不存在）
  3. 检查并生成 APP_KEY（如缺失）
  4. 空库时优先导入基础 schema
  5. 清理 Laravel 缓存
  6. 执行数据库迁移
  7. 自动创建默认管理员 cerbo / Temp@123456

参数：
  --dry-run   只打印将要执行的步骤，不真正写入数据库
  -h, --help  查看帮助

生产环境要求：
  APP_ENV=production 时必须设置 INSTALL_ADMIN_PASSWORD，且不能使用默认弱口令
EOF
}

for arg in "$@"; do
  case "$arg" in
    --dry-run)
      DRY_RUN=1
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      fail "不支持的参数：${arg}"
      ;;
  esac
done

read_env_value() {
  local key="$1"
  local line=""
  local value=""

  line="$(grep -E "^[[:space:]]*${key}[[:space:]]*=" "${ENV_FILE}" | tail -n 1 || true)"
  if [[ -z "${line}" ]]; then
    printf ''
    return 0
  fi

  line="${line#"${line%%[![:space:]]*}"}"
  value="${line#*=}"
  value="${value%$'\r'}"
  value="${value#"${value%%[![:space:]]*}"}"

  if [[ "${value}" =~ ^\".*\"$ ]]; then
    value="${value:1:${#value}-2}"
  elif [[ "${value}" =~ ^\'.*\'$ ]]; then
    value="${value:1:${#value}-2}"
  fi

  printf '%s' "${value}"
}

mask_secret() {
  local value="$1"
  if [[ -z "${value}" ]]; then
    printf '(空)'
    return 0
  fi

  printf '******'
}

resolve_admin_password() {
  local configured="${INSTALL_ADMIN_PASSWORD:-}"
  local password=""
  local normalized_env="${APP_ENV_VALUE,,}"

  if [[ -z "${configured}" ]]; then
    configured="$(read_env_value "${ADMIN_PASSWORD_ENV_KEY}")"
  fi

  password="${configured:-${DEFAULT_ADMIN_PASSWORD}}"

  if [[ "${normalized_env}" == "production" ]]; then
    [[ -n "${configured}" ]] || fail "生产环境必须在 .env 或环境变量中设置 ${ADMIN_PASSWORD_ENV_KEY}"
    [[ "${password}" != "${DEFAULT_ADMIN_PASSWORD}" ]] || fail "生产环境禁止使用默认管理员密码，请修改 ${ADMIN_PASSWORD_ENV_KEY}"
    [[ "${#password}" -ge 12 ]] || fail "生产环境 ${ADMIN_PASSWORD_ENV_KEY} 长度不能少于 12 位"
  fi

  printf '%s' "${password}"
}

run_cmd() {
  if (( DRY_RUN )); then
    printf '[install-db] dry-run:'
    printf ' %q' "$@"
    printf '\n'
    return 0
  fi

  "$@"
}

run_mysql_sql() {
  local sql="$1"

  if (( DRY_RUN )); then
    log "dry-run: mysql -e ${sql}"
    return 0
  fi

  if [[ -n "${DB_PASSWORD}" ]]; then
    MYSQL_PWD="${DB_PASSWORD}" mysql "${MYSQL_ARGS[@]}" -e "${sql}"
    return 0
  fi

  mysql "${MYSQL_ARGS[@]}" -e "${sql}"
}

query_mysql_value() {
  local sql="$1"

  if [[ -n "${DB_PASSWORD}" ]]; then
    MYSQL_PWD="${DB_PASSWORD}" mysql "${MYSQL_ARGS[@]}" -Nse "${sql}"
    return 0
  fi

  mysql "${MYSQL_ARGS[@]}" -Nse "${sql}"
}

run_artisan_php() {
  local php_code="$1"

  if (( DRY_RUN )); then
    log "dry-run: php artisan tinker --execute '<初始化默认管理员代码>'"
    return 0
  fi

  INSTALL_ADMIN_PASSWORD="${ADMIN_PASSWORD_VALUE}" php artisan tinker --execute="${php_code}"
}

[[ -f "${ENV_FILE}" ]] || fail "未找到 ${ENV_FILE}，请先准备后端 .env 文件"
[[ -f "${ARTISAN_FILE}" ]] || fail "未找到 ${ARTISAN_FILE}"
[[ -f "${BACKEND_DIR}/vendor/autoload.php" ]] || fail "未检测到 Composer 依赖，请先在 backend 目录执行 composer install"

command -v php >/dev/null 2>&1 || fail "未检测到 php 命令"
command -v mysql >/dev/null 2>&1 || fail "未检测到 mysql 客户端命令"

DB_CONNECTION="$(read_env_value DB_CONNECTION)"
DB_HOST="$(read_env_value DB_HOST)"
DB_PORT="$(read_env_value DB_PORT)"
DB_DATABASE="$(read_env_value DB_DATABASE)"
DB_USERNAME="$(read_env_value DB_USERNAME)"
DB_PASSWORD="$(read_env_value DB_PASSWORD)"
DB_SOCKET="$(read_env_value DB_SOCKET)"
APP_KEY_VALUE="$(read_env_value APP_KEY)"
APP_ENV_VALUE="$(read_env_value APP_ENV)"
APP_ENV_VALUE="${APP_ENV_VALUE:-local}"
ADMIN_PASSWORD_VALUE="$(resolve_admin_password)"

[[ -n "${DB_CONNECTION}" ]] || fail ".env 中缺少 DB_CONNECTION"
[[ "${DB_CONNECTION}" == "mysql" ]] || fail "当前脚本仅支持 mysql，实际为：${DB_CONNECTION}"
[[ -n "${DB_DATABASE}" ]] || fail ".env 中缺少 DB_DATABASE"
[[ -n "${DB_USERNAME}" ]] || fail ".env 中缺少 DB_USERNAME"

DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"

MYSQL_ARGS=(
  --default-character-set=utf8mb4
  --connect-timeout=5
  -u "${DB_USERNAME}"
)

if [[ -n "${DB_SOCKET}" ]]; then
  MYSQL_ARGS+=(--socket="${DB_SOCKET}")
else
  MYSQL_ARGS+=(-h "${DB_HOST}" -P "${DB_PORT}" --protocol=TCP)
fi

ESCAPED_DATABASE_NAME="${DB_DATABASE//\`/\`\`}"
CREATE_DATABASE_SQL="CREATE DATABASE IF NOT EXISTS \`${ESCAPED_DATABASE_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

log "开始初始化后端数据库"
log "后端目录：${BACKEND_DIR}"
log "数据库连接：${DB_USERNAME}@${DB_HOST}:${DB_PORT}/${DB_DATABASE}"
log "数据库密码：$(mask_secret "${DB_PASSWORD}")"
if [[ -n "${DB_SOCKET}" ]]; then
  log "检测到 DB_SOCKET，将优先使用 Unix Socket 连接"
fi

run_mysql_sql "${CREATE_DATABASE_SQL}"

TABLE_COUNT_SQL="SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '${DB_DATABASE//\'/\'\'}';"
EXISTING_TABLE_COUNT=0
if (( DRY_RUN )); then
  log "dry-run: 检查目标数据库当前表数量"
else
  EXISTING_TABLE_COUNT="$(query_mysql_value "${TABLE_COUNT_SQL}")"
fi

cd "${BACKEND_DIR}"

if [[ -z "${APP_KEY_VALUE}" ]]; then
  log "未检测到 APP_KEY，开始生成 Laravel 应用密钥"
  run_cmd php artisan key:generate --ansi
else
  log "已检测到 APP_KEY，跳过密钥生成"
fi

log "清理 Laravel 缓存"
run_cmd php artisan optimize:clear

if (( DRY_RUN )); then
  if [[ -f "${SCHEMA_FILE}" ]]; then
    log "dry-run: 若数据库为空，将优先导入基础 schema ${SCHEMA_FILE}"
  else
    log "dry-run: 未检测到基础 schema，将直接执行普通迁移"
  fi
else
  if [[ "${EXISTING_TABLE_COUNT}" == "0" ]]; then
    [[ -f "${SCHEMA_FILE}" ]] || fail "目标数据库为空，但未找到基础 schema 文件：${SCHEMA_FILE}"
    log "检测到空数据库，开始导入基础 schema"
  else
    log "检测到数据库已有 ${EXISTING_TABLE_COUNT} 张表，跳过基础 schema 导入"
  fi
fi

log "执行数据库迁移"
if (( DRY_RUN )); then
  if [[ -f "${SCHEMA_FILE}" ]]; then
    run_cmd php artisan migrate --schema-path=database/schema/mysql-schema.sql --force
  else
    run_cmd php artisan migrate --force
  fi
elif [[ "${EXISTING_TABLE_COUNT}" == "0" ]]; then
  run_cmd php artisan migrate --schema-path=database/schema/mysql-schema.sql --force
else
  run_cmd php artisan migrate --force
fi

log "初始化默认管理员 cerbo"
read -r -d '' ADMIN_BOOTSTRAP_CODE <<'PHP' || true
use App\Models\AdminUser;
use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$role = Role::query()->firstOrCreate(
    ['name' => 'super_admin'],
    [
        'label' => '超级管理员',
        'permissions' => ['*'],
    ]
);

$role->forceFill([
    'label' => trim((string) ($role->label ?? '')) !== '' ? $role->label : '超级管理员',
    'permissions' => ['*'],
])->save();

$admin = AdminUser::query()->firstOrNew(['username' => 'cerbo']);
$isNewAdmin = ! $admin->exists;

$admin->forceFill([
    'role_id' => (int) $role->id,
    'nickname' => trim((string) ($admin->nickname ?? '')) !== '' ? $admin->nickname : '默认管理员',
    'email' => trim((string) ($admin->email ?? '')) !== '' ? $admin->email : 'cerbo@example.com',
    'status' => $isNewAdmin ? 1 : (int) ($admin->status ?? 1),
]);

if ($isNewAdmin) {
  $adminPassword = getenv('INSTALL_ADMIN_PASSWORD') ?: 'Temp@123456';
  $admin->password = $adminPassword;
}

$admin->save();

if (Schema::hasTable('admin_user_roles')) {
    DB::table('admin_user_roles')->updateOrInsert(
        [
            'admin_user_id' => (int) $admin->id,
            'role_id' => (int) $role->id,
        ],
        []
    );
}
PHP
run_artisan_php "${ADMIN_BOOTSTRAP_CODE}"

log "数据库初始化完成"
