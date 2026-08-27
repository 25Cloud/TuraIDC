#!/usr/bin/env python3
"""初始化当前项目数据库。

说明：
- 读取 backend/.env 中的数据库配置
- 自动创建数据库（如不存在）
- 检查并生成 APP_KEY（如缺失）
- 空库时优先导入由真实库导出的 schema baseline
- 清理 Laravel 缓存
- 执行数据库迁移
- 自动创建默认管理员 cerbo / Temp@123456

schema baseline 更新方式：
    php backend/scripts/export_schema_baseline.php

旧库 SQL 数据迁移继续使用：
    python backend/scripts/migrate_legacy_dump.py
"""

from __future__ import annotations

import argparse
import os
import re
import shlex
import shutil
import subprocess
import sys
from pathlib import Path


if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")
if hasattr(sys.stderr, "reconfigure"):
    sys.stderr.reconfigure(encoding="utf-8", errors="replace")


SCRIPT_PATH = Path(__file__).resolve()
SCRIPT_DIR = SCRIPT_PATH.parent
BACKEND_DIR = SCRIPT_DIR.parent
ENV_FILE = BACKEND_DIR / ".env"
ARTISAN_FILE = BACKEND_DIR / "artisan"
SCHEMA_FILE = BACKEND_DIR / "database" / "schema" / "mysql-schema.sql"
SCHEMA_EXPORT_SCRIPT = SCRIPT_DIR / "export_schema_baseline.php"
ADMIN_PASSWORD_ENV_KEY = "INSTALL_ADMIN_PASSWORD"
DEFAULT_ADMIN_PASSWORD = "Temp@123456"

ADMIN_BOOTSTRAP_CODE = r"""use App\Models\AdminUser;
use App\Models\Role;
use App\Services\Admin\Rbac\BuiltinAdminRoleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

app(BuiltinAdminRoleService::class)->sync();
$superAdminRole = Role::query()->where('name', 'super_admin')->first();

$admin = AdminUser::query()->firstOrNew(['username' => 'cerbo']);
$isNewAdmin = ! $admin->exists;

$admin->forceFill([
    'role_id' => (int) $superAdminRole->id,
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
            'role_id' => (int) $superAdminRole->id,
        ],
        []
    );
}"""

DEFAULT_DATA_BOOTSTRAP_CODE = r"""use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\Schema;

if (Schema::hasTable('settings')) {
    SettingsSeeder::seed();
}

// SettingsSeeder 会幂等种入：
// 1) 系统核心默认配置（settings 表，含 notification.email_enabled / sms_enabled / sms_template_code）；
// 2) 通知模板默认数据（notification_templates 表）：email 全量模板 + sms 验证码模板。
// schema baseline 仅导出表结构、不含模板数据，模板种子必须经此入口执行。
"""


class InstallDbError(RuntimeError):
    """安装过程中的可预期错误。"""


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        prog="python backend/scripts/install_db.py",
        description="初始化当前项目数据库。",
    )
    parser.add_argument(
        "--dry-run",
        action="store_true",
        help="只打印将要执行的步骤，不真正写入数据库。",
    )
    parser.add_argument(
        "--reset",
        action="store_true",
        help="先删除并重建目标数据库，再执行初始化。会清空目标库所有数据。",
    )
    return parser.parse_args()


def log(message: str) -> None:
    print(f"[install-db] {message}", flush=True)


def fail(message: str) -> None:
    raise InstallDbError(message)


def read_env_value(key: str) -> str:
    if not ENV_FILE.is_file():
        fail(f"未找到 {ENV_FILE}，请先准备后端 .env 文件")

    value = ""
    for raw_line in ENV_FILE.read_text(encoding="utf-8", errors="replace").splitlines():
        line = raw_line.lstrip()
        if not line.startswith(f"{key}=") and not line.startswith(f"{key} "):
            continue
        if "=" not in line:
            continue

        _, value = line.split("=", 1)
        value = value.rstrip("\r").lstrip()
        if len(value) >= 2 and value[0] == value[-1] and value[0] in {"'", '"'}:
            value = value[1:-1]

    return value


def mask_secret(value: str) -> str:
    return "******" if value else "(空)"


def resolve_admin_password(app_env: str) -> str:
    configured = read_env_value(ADMIN_PASSWORD_ENV_KEY) or os.environ.get(ADMIN_PASSWORD_ENV_KEY, "")
    password = configured or DEFAULT_ADMIN_PASSWORD
    normalized_env = app_env.strip().lower()

    if normalized_env == "production":
        if not configured:
            fail(f"生产环境必须在 .env 或环境变量中设置 {ADMIN_PASSWORD_ENV_KEY}")
        if password == DEFAULT_ADMIN_PASSWORD:
            fail(f"生产环境禁止使用默认管理员密码，请修改 {ADMIN_PASSWORD_ENV_KEY}")
        if len(password) < 12:
            fail(f"生产环境 {ADMIN_PASSWORD_ENV_KEY} 长度不能少于 12 位")

    return password


def ensure_file(path: Path, message: str) -> None:
    if not path.is_file():
        fail(message)


def ensure_command(command: str, message: str) -> None:
    if shutil.which(command) is None:
        fail(message)


def mysql_env(db_password: str) -> dict[str, str]:
    env = os.environ.copy()
    if db_password:
        env["MYSQL_PWD"] = db_password
    return env


def php_mysql_env(
    db_username: str,
    db_password: str,
    db_host: str,
    db_port: str,
    db_socket: str,
    db_database: str = "",
) -> dict[str, str]:
    env = os.environ.copy()
    env["INSTALL_DB_USERNAME"] = db_username
    env["INSTALL_DB_PASSWORD"] = db_password
    env["INSTALL_DB_HOST"] = db_host
    env["INSTALL_DB_PORT"] = db_port
    env["INSTALL_DB_SOCKET"] = db_socket
    env["INSTALL_DB_DATABASE"] = db_database

    return env


def mysql_args(
    db_username: str,
    db_host: str,
    db_port: str,
    db_socket: str,
) -> list[str]:
    args = [
        "mysql",
        "--default-character-set=utf8mb4",
        "--connect-timeout=5",
        "-u",
        db_username,
    ]
    if db_socket:
        args.append(f"--socket={db_socket}")
    else:
        args.extend(["-h", db_host, "-P", db_port, "--protocol=TCP"])
    return args


def render_command(args: list[str]) -> str:
    return " ".join(shlex.quote(part) for part in args)


def run_command(
    args: list[str],
    *,
    cwd: Path | None = None,
    env: dict[str, str] | None = None,
    dry_run: bool = False,
    capture: bool = False,
) -> subprocess.CompletedProcess[str] | None:
    if dry_run:
        log(f"dry-run: {render_command(args)}")
        return None

    try:
        completed = subprocess.run(
            args,
            cwd=str(cwd) if cwd else None,
            env=env,
            text=True,
            encoding="utf-8",
            errors="replace",
            capture_output=capture,
            check=False,
        )
    except FileNotFoundError as exc:
        fail(f"命令不存在：{args[0]} ({exc})")

    if completed.returncode != 0:
        detail = (completed.stderr or "").strip() or (completed.stdout or "").strip() or f"退出码 {completed.returncode}"
        fail(f"命令执行失败：{render_command(args)}\n{detail}")

    return completed


def run_mysql_sql(
    mysql_base_args: list[str],
    db_password: str,
    sql: str,
    *,
    dry_run: bool,
) -> None:
    if dry_run:
        log(f"dry-run: mysql -e {sql}")
        return

    run_command(
        mysql_base_args + ["-e", sql],
        env=mysql_env(db_password),
        capture=True,
    )


def php_pdo_bootstrap(*, with_database: bool) -> str:
    required_database_check = """
if ($database === '') {
    fwrite(STDERR, 'INSTALL_DB_DATABASE is required' . PHP_EOL);
    exit(1);
}
""" if with_database else ""

    return f"""
$host = getenv('INSTALL_DB_HOST') ?: '127.0.0.1';
$port = getenv('INSTALL_DB_PORT') ?: '3306';
$socket = getenv('INSTALL_DB_SOCKET') ?: '';
$database = getenv('INSTALL_DB_DATABASE') ?: '';
$username = getenv('INSTALL_DB_USERNAME') ?: '';
$password = getenv('INSTALL_DB_PASSWORD') ?: '';

if ($username === '') {{
    fwrite(STDERR, 'INSTALL_DB_USERNAME is required' . PHP_EOL);
    exit(1);
}}

{required_database_check}

$databaseSuffix = $database !== '' ? ';dbname=' . $database : '';

$dsn = $socket !== ''
    ? 'mysql:unix_socket=' . $socket . $databaseSuffix . ';charset=utf8mb4'
    : 'mysql:host=' . $host . ';port=' . $port . $databaseSuffix . ';charset=utf8mb4';

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

if (defined('PDO::MYSQL_ATTR_MULTI_STATEMENTS')) {{
    $options[PDO::MYSQL_ATTR_MULTI_STATEMENTS] = true;
}}

$pdo = new PDO($dsn, $username, $password, $options);
""".strip()


def run_php_mysql_sql(
    db_username: str,
    db_password: str,
    db_host: str,
    db_port: str,
    db_socket: str,
    sql: str,
) -> None:
    php_code = php_pdo_bootstrap(with_database=False) + "\n$pdo->exec(getenv('INSTALL_DB_SQL') ?: '');"
    env = php_mysql_env(db_username, db_password, db_host, db_port, db_socket)
    env["INSTALL_DB_SQL"] = sql

    run_command(["php", "-r", php_code], env=env, capture=True)


def run_server_sql(
    mysql_base_args: list[str],
    db_username: str,
    db_password: str,
    db_host: str,
    db_port: str,
    db_socket: str,
    sql: str,
    *,
    dry_run: bool,
    mysql_client_available: bool,
) -> None:
    if dry_run or mysql_client_available:
        run_mysql_sql(
            mysql_base_args,
            db_password,
            sql,
            dry_run=dry_run,
        )

        return

    run_php_mysql_sql(db_username, db_password, db_host, db_port, db_socket, sql)


def query_server_version_with_php(
    db_username: str,
    db_password: str,
    db_host: str,
    db_port: str,
    db_socket: str,
    db_database: str,
) -> str:
    php_code = php_pdo_bootstrap(with_database=False) + """
echo (string) $pdo->query('SELECT VERSION()')->fetchColumn();
"""
    completed = run_command(
        ["php", "-r", php_code],
        env=php_mysql_env(db_username, db_password, db_host, db_port, db_socket, db_database),
        capture=True,
    )
    assert completed is not None

    return (completed.stdout or "").strip()


def check_database_version(raw_version: str) -> None:
    """数据库最低版本闸门。

    此前没有任何版本探测：过低版本会在初始化阶段一路绿灯（基线能导入、迁移能跑），
    直到运行期才在个别页面炸出 SQL 语法/类型错误，最难排查。下限依据（本仓实测）：
    MySQL 5.7.8 起才有 json 类型（基线 58 个 json 列）与虚拟生成列上的唯一索引；
    MariaDB 取 Laravel 12 官方支持矩阵的 10.3。版本串解析不出来时保守放行。

    MariaDB 10.x 会在握手包版本串前加 `5.5.5-` 假前缀（老客户端按字符串比大小会把 10.0
    判成比 5.5 小），11.0 起取消。`SELECT VERSION()` 通常不带该前缀，但经 ProxySQL/MaxScale
    等代理取值时会带上；若直接取第一个版本号会把 10.3 误读成 5.5.5 而拒绝安装，故先剥前缀。
    """
    is_mariadb = "mariadb" in raw_version.lower()
    candidate = re.sub(r"^5\.5\.5-", "", raw_version) if is_mariadb else raw_version

    match = re.search(r"(\d+\.\d+(?:\.\d+)?)", candidate)
    if not match:
        return
    version = tuple(int(part) for part in match.group(1).split("."))

    if is_mariadb:
        if version < (10, 3):
            fail(f"MariaDB 版本过低（当前 {raw_version}）：最低需要 10.3。")
        return

    if version < (5, 7, 8):
        fail(f"MySQL 版本过低（当前 {raw_version}）：最低需要 5.7.8，推荐 8.0+。")
    if version < (8, 0):
        log(
            f"MySQL {raw_version}：兼容支持档（5.7 已停止官方维护，建议尽快升级到 8.0+）。"
            "强烈建议在 my.cnf 设置 explicit_defaults_for_timestamp=ON："
            "5.7 默认 OFF 时首个无显式默认值的 timestamp 列会被隐式附加 "
            "ON UPDATE CURRENT_TIMESTAMP，与 8.0 行为不一致。"
        )


def query_php_mysql_value(
    db_username: str,
    db_password: str,
    db_host: str,
    db_port: str,
    db_socket: str,
    db_database: str,
) -> str:
    php_code = php_pdo_bootstrap(with_database=False) + """
$statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ?');
$statement->execute([$database]);
echo (string) $statement->fetchColumn();
"""
    completed = run_command(
        ["php", "-r", php_code],
        env=php_mysql_env(db_username, db_password, db_host, db_port, db_socket, db_database),
        capture=True,
    )
    assert completed is not None

    return (completed.stdout or "").strip()


def import_schema_with_php(
    db_username: str,
    db_password: str,
    db_host: str,
    db_port: str,
    db_socket: str,
    db_database: str,
    schema_file: Path,
) -> None:
    php_code = php_pdo_bootstrap(with_database=True) + """
$schemaFile = getenv('INSTALL_DB_SCHEMA_FILE') ?: '';
$sql = $schemaFile !== '' && is_file($schemaFile) ? file_get_contents($schemaFile) : false;
if ($sql === false || $sql === '') {
    fwrite(STDERR, 'Schema file is empty or unreadable: ' . $schemaFile . PHP_EOL);
    exit(1);
}
$pdo->exec($sql);
"""
    env = php_mysql_env(db_username, db_password, db_host, db_port, db_socket, db_database)
    env["INSTALL_DB_SCHEMA_FILE"] = str(schema_file)

    run_command(["php", "-r", php_code], env=env, capture=True)


def query_mysql_value(
    mysql_base_args: list[str],
    db_password: str,
    sql: str,
) -> str:
    completed = run_command(
        mysql_base_args + ["-Nse", sql],
        env=mysql_env(db_password),
        capture=True,
    )
    assert completed is not None
    return (completed.stdout or "").strip()


def run_artisan_php(php_code: str, *, dry_run: bool, admin_password: str) -> None:
    if dry_run:
        log("dry-run: php artisan tinker --execute '<初始化默认管理员代码>'")
        return

    env = os.environ.copy()
    env[ADMIN_PASSWORD_ENV_KEY] = admin_password

    run_command(
        ["php", "artisan", "tinker", f"--execute={php_code}"],
        cwd=BACKEND_DIR,
        env=env,
    )


def run_default_data_bootstrap(*, dry_run: bool) -> None:
    if dry_run:
        log("dry-run: php artisan tinker --execute '<初始化默认配置和通知模板代码>'")
        return

    run_command(
        ["php", "artisan", "tinker", f"--execute={DEFAULT_DATA_BOOTSTRAP_CODE}"],
        cwd=BACKEND_DIR,
    )


def main() -> int:
    args = parse_args()

    try:
        ensure_file(ENV_FILE, f"未找到 {ENV_FILE}，请先准备后端 .env 文件")
        ensure_file(ARTISAN_FILE, f"未找到 {ARTISAN_FILE}")
        ensure_file(
            SCHEMA_EXPORT_SCRIPT,
            f"未找到 {SCHEMA_EXPORT_SCRIPT}，无法确认 schema baseline 更新入口",
        )
        ensure_file(
            BACKEND_DIR / "vendor" / "autoload.php",
            "未检测到 Composer 依赖，请先在 backend 目录执行 composer install",
        )
        ensure_command("php", "未检测到 php 命令")

        db_connection = read_env_value("DB_CONNECTION")
        db_host = read_env_value("DB_HOST") or "127.0.0.1"
        db_port = read_env_value("DB_PORT") or "3306"
        db_database = read_env_value("DB_DATABASE")
        db_username = read_env_value("DB_USERNAME")
        db_password = read_env_value("DB_PASSWORD")
        db_socket = read_env_value("DB_SOCKET")
        app_key_value = read_env_value("APP_KEY")
        app_env = read_env_value("APP_ENV") or os.environ.get("APP_ENV", "local")
        admin_password = resolve_admin_password(app_env)

        if not db_connection:
            fail(".env 中缺少 DB_CONNECTION")
        if db_connection != "mysql":
            fail(f"当前脚本仅支持 mysql，实际为：{db_connection}")
        if not db_database:
            fail(".env 中缺少 DB_DATABASE")
        if not db_username:
            fail(".env 中缺少 DB_USERNAME")
        mysql_client_available = shutil.which("mysql") is not None
        if not args.dry_run and not mysql_client_available:
            log("未检测到 mysql 客户端，将使用 PHP PDO 执行数据库创建、表数量检查和 schema baseline 导入")

        mysql_base_args = mysql_args(db_username, db_host, db_port, db_socket)
        escaped_database_name = db_database.replace("`", "``")
        create_database_sql = (
            f"CREATE DATABASE IF NOT EXISTS `{escaped_database_name}` "
            "CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
        )

        log("开始初始化后端数据库")
        log(f"后端目录：{BACKEND_DIR}")
        log(f"数据库连接：{db_username}@{db_host}:{db_port}/{db_database}")
        log(f"数据库密码：{mask_secret(db_password)}")
        if db_socket:
            log("检测到 DB_SOCKET，将优先使用 Unix Socket 连接")

        if args.dry_run:
            log("dry-run: 检查数据库服务器版本")
        else:
            if mysql_client_available:
                server_version = query_mysql_value(mysql_base_args, db_password, "SELECT VERSION();")
            else:
                server_version = query_server_version_with_php(
                    db_username, db_password, db_host, db_port, db_socket, db_database
                )
            check_database_version(server_version)

        if args.reset:
            drop_database_sql = f"DROP DATABASE IF EXISTS `{escaped_database_name}`;"
            log(f"重置目标数据库：{db_database}")
            run_server_sql(
                mysql_base_args,
                db_username,
                db_password,
                db_host,
                db_port,
                db_socket,
                drop_database_sql,
                dry_run=args.dry_run,
                mysql_client_available=mysql_client_available,
            )

        run_server_sql(
            mysql_base_args,
            db_username,
            db_password,
            db_host,
            db_port,
            db_socket,
            create_database_sql,
            dry_run=args.dry_run,
            mysql_client_available=mysql_client_available,
        )

        escaped_table_schema = db_database.replace("'", "''")
        table_count_sql = (
            "SELECT COUNT(*) FROM information_schema.tables "
            f"WHERE table_schema = '{escaped_table_schema}';"
        )
        existing_table_count = "0"
        if args.dry_run:
            log("dry-run: 检查目标数据库当前表数量")
        elif mysql_client_available:
            existing_table_count = query_mysql_value(mysql_base_args, db_password, table_count_sql) or "0"
        else:
            existing_table_count = query_php_mysql_value(
                db_username,
                db_password,
                db_host,
                db_port,
                db_socket,
                db_database,
            ) or "0"

        if not app_key_value:
            log("未检测到 APP_KEY，开始生成 Laravel 应用密钥")
            run_command(
                ["php", "artisan", "key:generate", "--ansi"],
                cwd=BACKEND_DIR,
                dry_run=args.dry_run,
            )
        else:
            log("已检测到 APP_KEY，跳过密钥生成")

        log("清理 Laravel 缓存")
        run_command(
            ["php", "artisan", "optimize:clear"],
            cwd=BACKEND_DIR,
            dry_run=args.dry_run,
        )

        if args.dry_run:
            if SCHEMA_FILE.is_file():
                log(f"dry-run: 若数据库为空，将优先导入 schema baseline {SCHEMA_FILE}")
            else:
                log("dry-run: 未检测到 schema baseline，将直接执行普通迁移")
        else:
            if existing_table_count == "0":
                if not SCHEMA_FILE.is_file():
                    fail(
                        f"目标数据库为空，但未找到 schema baseline：{SCHEMA_FILE}\n"
                        f"请先执行：php {SCHEMA_EXPORT_SCRIPT}"
                    )
                log("检测到空数据库，将通过 schema baseline 初始化当前表结构")
            else:
                log(f"检测到数据库已有 {existing_table_count} 张表，跳过 schema baseline 导入")

        imported_schema_with_php = False
        if not args.dry_run and existing_table_count == "0" and SCHEMA_FILE.is_file() and not mysql_client_available:
            log("使用 PHP PDO 导入 schema baseline")
            import_schema_with_php(
                db_username,
                db_password,
                db_host,
                db_port,
                db_socket,
                db_database,
                SCHEMA_FILE,
            )
            imported_schema_with_php = True

        log("执行数据库迁移")
        migrate_args = ["php", "artisan", "migrate", "--force"]
        if SCHEMA_FILE.is_file():
            if (args.dry_run or existing_table_count == "0") and not imported_schema_with_php:
                migrate_args = [
                    "php",
                    "artisan",
                    "migrate",
                    "--schema-path=database/schema/mysql-schema.sql",
                    "--force",
                ]

        run_command(
            migrate_args,
            cwd=BACKEND_DIR,
            dry_run=args.dry_run,
        )

        log("初始化默认配置和通知模板")
        run_default_data_bootstrap(dry_run=args.dry_run)

        log("初始化默认管理员 cerbo")
        run_artisan_php(ADMIN_BOOTSTRAP_CODE, dry_run=args.dry_run, admin_password=admin_password)

        log("数据库初始化完成")
        return 0
    except InstallDbError as exc:
        print(f"[install-db] 错误：{exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
