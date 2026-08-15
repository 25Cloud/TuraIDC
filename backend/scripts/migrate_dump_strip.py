"""过滤旧 dump 中的 SUPER 权限行，直接导入到目标数据库。

用法：python backend/scripts/migrate_dump_strip.py --dump <旧dump.sql>

说明：
- 不创建临时库，直接在目标库里 INSERT（目标库已有当前 schema，数据为空）。
- 如果目标库已有数据，脚本会先 TRUNCATE 再导入（仅含数据的主表）。
- 不修改表结构。

关于主键与自增（勿改！）：
- users.id 等主键按 dump 原值导入，这是设计约束（外键引用完整性），严禁改成
  迁移时丢弃或重排主键。
- 后果：导入后 AUTO_INCREMENT 变为 max(旧 id)+1，新注册用户 ID 直接延续旧
  系统编号（本地 2026-07-25 迁移后新用户曾从 988049 开始）。
- 本地如需恢复连续小 ID：改大 ID 用户并同步引用表，再 TRUNCATE 按原 id 重导
  （ALTER TABLE 不能调低计数器），不要在本脚本自动重置。
- 2026-07-31 已修复本地库：用户 988048 -> 481，自增恢复为 482。
"""

from __future__ import annotations

import argparse
import os
import shlex
import shutil
import subprocess
import sys
from pathlib import Path


SCRIPT_PATH = Path(__file__).resolve()
BACKEND_DIR = SCRIPT_PATH.parents[1]
ENV_FILE = BACKEND_DIR / ".env"


SKIP_TABLES = {
    "migrations",
    "jobs",
    "password_reset_tokens",
    "personal_access_tokens",
    "sessions",
    "failed_jobs",
}

TABLES_TO_TRUNCATE = {
    "account_transactions",
    "activity_logs",
    "admin_user_roles",
    "admin_users",
    "automation_logs",
    "balance_logs",
    "content_articles",
    "content_categories",
    "coupon_campaigns",
    "coupons",
    "email_logs",
    "gateway_logs",
    "invoice_items",
    "invoices",
    "media_files",
    "member_levels",
    "notice_reads",
    "notification_logs",
    "operation_logs",
    "orders",
    "payment_callbacks",
    "payments",
    "product_groups",
    "products",
    "referral_account_logs",
    "referral_rewards",
    "referral_withdrawals",
    "roles",
    "schedule_run_logs",
    "servers",
    "services",
    "settings",
    "sms_logs",
    "suppliers",
    "ticket_replies",
    "tickets",
    "user_accounts",
    "user_coupons",
    "user_notifications",
    "users",
    "verification_histories",
}


class MigrationError(RuntimeError):
    """迁移过程中的可预期错误。"""


def log(message: str) -> None:
    print(f"[strip-migrate] {message}", flush=True)


def fail(message: str) -> None:
    raise MigrationError(message)


def read_env_value(key: str) -> str:
    if not ENV_FILE.is_file():
        fail(f"未找到 .env 文件：{ENV_FILE}")

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


def mysql_env(db_password: str) -> dict[str, str]:
    env = os.environ.copy()
    if db_password:
        env["MYSQL_PWD"] = db_password
    return env


def mysql_args(db_host: str, db_port: str, db_username: str, db_database: str) -> list[str]:
    return [
        "mysql",
        "--default-character-set=utf8mb4",
        "--connect-timeout=5",
        "-u", db_username,
        "-h", db_host,
        "-P", db_port,
        "--protocol=TCP",
        db_database,
    ]


def run_mysql(
    mysql_base_args: list[str],
    db_password: str,
    input_text: str = "",
) -> subprocess.CompletedProcess[str]:
    env = mysql_env(db_password)
    completed = subprocess.run(
        mysql_base_args,
        input=input_text,
        text=True,
        capture_output=True,
        env=env,
    )
    if completed.returncode != 0:
        detail = (completed.stderr or "").strip() or (completed.stdout or "").strip()
        fail(f"MySQL 命令执行失败：\n{detail}")
    return completed


STOP_LINE_PATTERNS = [
    "SET @MYSQLDUMP_TEMP_LOG_BIN = @@SESSION.SQL_LOG_BIN;",
    "SET @@SESSION.SQL_LOG_BIN= 0;",
    "SET @@SESSION.SQL_LOG_BIN = @MYSQLDUMP_TEMP_LOG_BIN;",
    "SET @@GLOBAL.GTID_PURGED=",
]


def should_skip_line(line: str) -> bool:
    stripped = line.strip()
    if not stripped:
        return False
    for prefix in STOP_LINE_PATTERNS:
        if stripped.startswith(prefix):
            return True
    return False


def filter_dump(dump_path: Path, output_path: Path) -> tuple[int, int]:
    """过滤掉需要 SUPER 权限的行，返回（总行数, 跳过的行数）。"""
    total = 0
    skipped = 0

    with open(dump_path, "r", encoding="utf-8", errors="replace") as src:
        with open(output_path, "w", encoding="utf-8") as dst:
            for line in src:
                total += 1
                if should_skip_line(line):
                    skipped += 1
                    # 写一行注释占位，保持行号和阅读体验
                    dst.write("-- [strip-migrate] stripped SUPER privilege line\n")
                    continue
                dst.write(line)

    return total, skipped


def main() -> int:
    parser = argparse.ArgumentParser(description="过滤并导入旧 MySQL dump 到目标数据库。")
    parser.add_argument("--dump", required=True, help="旧 MySQL dump 文件路径")
    parser.add_argument("--dry-run", action="store_true", help="只过滤不导入")
    args = parser.parse_args()

    dump_path = Path(args.dump).resolve()
    if not dump_path.is_file():
        fail(f"未找到 dump 文件：{dump_path}")

    db_host = read_env_value("DB_HOST") or "127.0.0.1"
    db_port = read_env_value("DB_PORT") or "3306"
    db_database = read_env_value("DB_DATABASE")
    db_username = read_env_value("DB_USERNAME")
    db_password = read_env_value("DB_PASSWORD")

    if not db_database:
        fail(".env 中缺少 DB_DATABASE")
    if not db_username:
        fail(".env 中缺少 DB_USERNAME")

    log(f"数据库：{db_username}@{db_host}:{db_port}/{db_database}")
    log(f"源文件：{dump_path} ({dump_path.stat().st_size / 1024 / 1024:.1f} MB)")

    # 1. 过滤 dump
    filtered_path = dump_path.parent / f"{dump_path.stem}_filtered.sql"
    log("过滤 SUPER 权限行...")
    total, skipped = filter_dump(dump_path, filtered_path)
    log(f"  总行数：{total}，跳过：{skipped} 行（SUPER 权限行）")

    if args.dry_run:
        log("dry-run 模式：已完成过滤，退出。")
        filtered_path.unlink(missing_ok=True)
        return 0

    # 2. Truncate 目标表
    mysql_base = mysql_args(db_host, db_port, db_username, db_database)
    for table in TABLES_TO_TRUNCATE:
        log(f"清理：TRUNCATE TABLE `{table}`")
        run_mysql(mysql_base, db_password, f"SET FOREIGN_KEY_CHECKS=0; TRUNCATE TABLE `{table}`; SET FOREIGN_KEY_CHECKS=1;")

    # 3. 导入过滤后的 dump
    log("导入数据（这可能需要几分钟）...")
    env = mysql_env(db_password)
    completed = subprocess.run(
        mysql_base,
        stdin=open(filtered_path, "r", encoding="utf-8"),
        text=True,
        capture_output=True,
        env=env,
    )
    if completed.returncode != 0:
        detail = (completed.stderr or "").strip()[-500:]
        log(f"导入失败：{detail}")
        filtered_path.unlink(missing_ok=True)
        return 1

    log("导入完成")

    # 4. 清理过滤文件
    filtered_path.unlink(missing_ok=True)

    # 5. 验证
    log("验证导入行数...")
    for table in sorted(TABLES_TO_TRUNCATE):
        if table in SKIP_TABLES:
            continue
        result = run_mysql(mysql_base, db_password, f"SELECT COUNT(*) FROM `{table}`;")
        count = (result.stdout or "").strip()
        log(f"  {table}: {count} 行")

    log("数据迁移完成")
    return 0


if __name__ == "__main__":
    try:
        sys.exit(main())
    except MigrationError as exc:
        print(f"[strip-migrate] 错误：{exc}", file=sys.stderr)
        sys.exit(1)
    except KeyboardInterrupt:
        print("\n[strip-migrate] 用户中断", file=sys.stderr)
        sys.exit(1)
