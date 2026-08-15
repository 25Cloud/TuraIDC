#!/usr/bin/env python3
"""
Developer-only heterogeneous MySQL data migration.

This tool keeps the final idc schema immutable. It creates and loads a
separate staging database, then performs target-side data DML only.
The only DDL issued by this file is CREATE DATABASE for the separate staging
database. Target AUTO_INCREMENT values are verified after inserts instead of
being changed with ALTER TABLE.

主键与自增说明（勿改！）：
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
from contextlib import contextmanager
import datetime as datetime_module
import hashlib
import json
import os
import re
import shutil
import sqlite3
import subprocess
import sys
import tempfile
import time
import traceback
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Iterable

import pymysql
from pymysql.cursors import DictCursor


TARGET_DATABASE = "idc"
DEFAULT_ENV_FILE = Path(__file__).resolve().parents[1] / ".env"
REPOSITORY_ROOT = Path(__file__).resolve().parents[2]
PANEL_CONFIG_DATABASE = Path(r"D:\BtSoft\panel\data\db\panel.db")
STAGING_DATABASE_PREFIX = "idc_temp_restore_"
MAX_RUN_ID_LENGTH = 47

# State, mapping and dump files are inputs, not executable authority.  Keep the
# accepted SQL surface intentionally small so an altered artifact fails before
# it can use the administrative connection.
SQL_UNSAFE_KEYWORDS = re.compile(
    r"\b(?:ALTER|ANALYZE|CALL|CHANGE|CHECK|CREATE|DEALLOCATE|DO|DROP|"
    r"EXECUTE|FLUSH|GRANT|HANDLER|INSTALL|KILL|LOAD|LOAD_FILE|LOCK\s+INSTANCE|"
    r"OPTIMIZE|PREPARE|PURGE|RENAME|REPAIR|RESET|REVOKE|SET\s+GLOBAL|"
    r"SET\s+PERSIST(?:_ONLY)?|SHUTDOWN|START|STOP|TRUNCATE|UNINSTALL|USE)\b",
    re.IGNORECASE,
)
SQL_COMMENT_MARKERS = re.compile(r"(?:--|/\*|#)")
CONNECTION_SCOPE_ATTRIBUTE = "_heterogeneous_migration_scope"

# Laravel's current migration history describes the final, local schema. It
# is intentionally not replaced with a historical dump's migration records.
PRESERVE_TARGET_TABLES = {"migrations"}

# These tables were added in the final schema and have no source counterpart
# in the supplied dump.  Keeping them empty is an explicitly configured rule;
# any other missing source table blocks the migration before data is cleared.
ALLOW_CLEAR_TARGET_WITHOUT_SOURCE = {
    "first_product_groups",
    "second_product_groups",
    "third_product_groups",
    "recharge_records",
    "refunds",
}

# These source rows reference parents that do not exist in the same dump. Their
# non-null FK columns cannot be repaired with NULL and there is no business key
# that supports a lossless reassignment. Exclude only these explicitly named
# stale configuration rows, record the row-count gap, and reject every other
# non-nullable orphan.
ALLOW_EXCLUDE_STALE_NONNULLABLE_FK_ROWS = {
    "product_upstream_bindings_product_id_foreign": (
        "源 product_upstream_bindings 指向不存在的 products 记录，"
        "无可验证业务键可重关联"
    ),
    "supplier_plugin_bindings_supplier_id_foreign": (
        "源 supplier_plugin_bindings 指向不存在的 suppliers 记录，"
        "无可验证业务键可重关联"
    ),
}

PRODUCT_GROUP_SOURCE = "product_groups"
PRODUCT_GROUP_TARGET_LEVELS = {
    "first_product_groups": 1,
    "second_product_groups": 2,
    "third_product_groups": 3,
}

TEXT_TYPES = {
    "char",
    "varchar",
    "tinytext",
    "text",
    "mediumtext",
    "longtext",
    "enum",
    "set",
}
NUMERIC_TYPES = {
    "tinyint",
    "smallint",
    "mediumint",
    "int",
    "integer",
    "bigint",
    "decimal",
    "numeric",
    "float",
    "double",
    "real",
    "bit",
}
TEMPORAL_TYPES = {"date", "datetime", "timestamp", "time", "year"}


class MigrationFailure(RuntimeError):
    pass


def assert_path_outside_repository(path: Path, label: str) -> Path:
    resolved = path.resolve()
    try:
        resolved.relative_to(REPOSITORY_ROOT)
    except ValueError:
        return resolved
    raise MigrationFailure(label + "必须位于仓库之外，禁止把生产数据或迁移产物放入工作区。")


def validate_run_id(run_id: str) -> str:
    value = str(run_id)
    if not re.fullmatch(r"[A-Za-z0-9_]{1," + str(MAX_RUN_ID_LENGTH) + r"}", value):
        raise MigrationFailure(
            "批次标识只能由字母、数字和下划线组成，且长度不能超过 "
            + str(MAX_RUN_ID_LENGTH)
        )
    return value


def staging_database_for_run(run_id: str) -> str:
    return STAGING_DATABASE_PREFIX + validate_run_id(run_id)


def assert_staging_database(database: str, *, run_id: str | None = None) -> str:
    value = str(database)
    if not re.fullmatch(r"[A-Za-z0-9_]{1,64}", value):
        raise MigrationFailure("中转库名只能由字母、数字和下划线组成，且长度不超过 64")
    if value == TARGET_DATABASE or not value.startswith(STAGING_DATABASE_PREFIX):
        raise MigrationFailure("中转库必须是受本工具管理的独立 idc_temp_restore_ 数据库")
    if run_id is not None and value != staging_database_for_run(run_id):
        raise MigrationFailure("批次状态中的中转库与批次标识不匹配")
    return value


@dataclass(frozen=True)
class DbConfig:
    host: str
    port: int
    username: str
    password: str
    database: str
    socket: str = ""


class Reporter:
    def __init__(self, path: Path, run_id: str) -> None:
        self.path = path
        self.run_id = run_id
        self.path.parent.mkdir(parents=True, exist_ok=True)
        if not self.path.exists():
            self.path.write_text(
                "# 异构表结构数据迁移：完整操作日志及排错记录\n\n"
                f"- 执行批次：{run_id}\n"
                f"- 日志创建时间：{now_text()}\n"
                f"- 目标库：{TARGET_DATABASE}\n"
                "- 结构保护：目标库仅允许元数据读取、DELETE、INSERT、SET 和 SELECT；"
                "不执行 CREATE、ALTER、DROP。\n\n",
                encoding="utf-8",
            )

    def section(self, title: str) -> None:
        self._append(f"\n## {title}\n")

    def line(self, message: str) -> None:
        stamped = f"[{now_text()}] {message}"
        print(f"[heterogeneous-migrate] {stamped}", flush=True)
        self._append(stamped + "\n")

    def bullet(self, message: str) -> None:
        self._append(f"- {message}\n")

    def code(self, text: str) -> None:
        self._append("\n~~~text\n" + text.rstrip() + "\n~~~\n")

    def _append(self, text: str) -> None:
        with self.path.open("a", encoding="utf-8", newline="\n") as handle:
            handle.write(text)


def now_text() -> str:
    return datetime_module.datetime.now().strftime("%Y-%m-%d %H:%M:%S")


def run_id_default() -> str:
    return datetime_module.datetime.now().strftime("%Y%m%d_%H%M%S")


def json_default(value: Any) -> str:
    if isinstance(value, (datetime_module.datetime, datetime_module.date, datetime_module.time)):
        return value.isoformat(sep=" ")
    return str(value)


def quote_identifier(value: str) -> str:
    marker = chr(96)
    return marker + value.replace(marker, marker + marker) + marker


def _sql_code_without_string_literals(sql: str) -> str:
    """Keep SQL syntax while replacing literal values before policy checks."""
    result: list[str] = []
    quote = ""
    index = 0
    while index < len(sql):
        character = sql[index]
        if not quote:
            if character in {"'", '"'}:
                quote = character
                result.append(" ")
            else:
                result.append(character)
            index += 1
            continue
        if character == "\\":
            index += 2
            continue
        if character == quote:
            if index + 1 < len(sql) and sql[index + 1] == quote:
                index += 2
                continue
            quote = ""
        index += 1
    if quote:
        raise MigrationFailure("SQL 字符串字面量未闭合。")
    return "".join(result)


def normalize_single_sql_statement(sql: str, context: str) -> str:
    """Reject multi-statement and comment based bypasses before execution."""
    normalized = str(sql).strip()
    if not normalized:
        raise MigrationFailure(context + " 为空。")
    code = _sql_code_without_string_literals(normalized)
    if SQL_COMMENT_MARKERS.search(code):
        raise MigrationFailure(context + " 不允许包含 SQL 注释。")
    if ";" in code:
        if not code.rstrip().endswith(";") or code.count(";") != 1:
            raise MigrationFailure(context + " 不是单条 SQL 语句。")
        normalized = normalized[:-1].rstrip()
        code = _sql_code_without_string_literals(normalized)
    if not normalized:
        raise MigrationFailure(context + " 为空。")
    return normalized


def sql_first_keyword(sql: str, context: str) -> str:
    match = re.match(r"\s*([A-Za-z]+)\b", _sql_code_without_string_literals(sql))
    if not match:
        raise MigrationFailure(context + " 缺少 SQL 动词。")
    return match.group(1).upper()


def assert_no_unsafe_sql(sql: str, context: str) -> None:
    code = _sql_code_without_string_literals(sql)
    if SQL_UNSAFE_KEYWORDS.search(code):
        raise MigrationFailure(context + " 包含 DDL、管理或数据库切换语句。")
    if re.search(r"\b(?:OUTFILE|DUMPFILE)\b", code, re.IGNORECASE):
        raise MigrationFailure(context + " 不允许读写服务器文件。")


def _unquote_identifier(value: str) -> str:
    token = value.strip()
    if token.startswith("`") and token.endswith("`"):
        return token[1:-1].replace("``", "`")
    return token


def assert_schema_references(
    sql: str,
    allowed_schemas: set[str],
    context: str,
    *,
    allow_managed_staging: bool = False,
) -> None:
    """Only permit explicit schema references at the expected trust boundary."""
    table_reference = re.compile(
        r"\b(?:FROM|JOIN|INTO|UPDATE|TABLE|TABLES|REFERENCES)\s+"
        r"(?:IF\s+(?:NOT\s+)?EXISTS\s+)?"
        r"(?P<schema>`(?:``|[^`])+`|[A-Za-z_][A-Za-z0-9_$]*)\s*\.",
        re.IGNORECASE,
    )
    allowed = {item.casefold() for item in allowed_schemas}
    for match in table_reference.finditer(sql):
        schema = _unquote_identifier(match.group("schema")).casefold()
        if schema in allowed:
            continue
        if allow_managed_staging and schema.startswith(STAGING_DATABASE_PREFIX):
            continue
        raise MigrationFailure(context + " 引用了未批准的数据库：" + schema)


def assert_session_set_statement(sql: str, context: str, *, target: bool) -> None:
    normalized = normalize_single_sql_statement(sql, context)
    code = _sql_code_without_string_literals(normalized)
    if sql_first_keyword(normalized, context) != "SET":
        raise MigrationFailure(context + " 只能是 SET 会话语句。")
    if re.search(
        r"\b(?:GLOBAL|PERSIST(?:_ONLY)?|PASSWORD|ROLE|RESOURCE|SQL_LOG_BIN|GTID_PURGED)\b",
        code,
        re.IGNORECASE,
    ):
        raise MigrationFailure(context + " 不允许修改全局或管理级会话设置。")
    if target and not re.fullmatch(
        r"SET\s+FOREIGN_KEY_CHECKS\s*=\s*[01]", code.strip(), re.IGNORECASE
    ):
        raise MigrationFailure("目标 idc 仅允许 SET FOREIGN_KEY_CHECKS=0 或 1。")


def connection_scope(connection: pymysql.connections.Connection) -> str | None:
    missing = object()
    scope = getattr(connection, CONNECTION_SCOPE_ATTRIBUTE, missing)
    if scope is missing:
        raise MigrationFailure("拒绝在未标记数据库范围的连接上执行 SQL。")
    if scope is not None:
        scope = str(scope)
    return scope


def assert_sql_allowed_for_connection(
    connection: pymysql.connections.Connection,
    sql: str,
) -> str:
    """Last-line policy gate for every PyMySQL statement in this script."""
    normalized = normalize_single_sql_statement(sql, "数据库执行语句")
    scope = connection_scope(connection)
    keyword = sql_first_keyword(normalized, "数据库执行语句")

    if scope is None:
        if keyword == "SELECT":
            assert_no_unsafe_sql(normalized, "服务器级 SELECT")
            assert_schema_references(
                normalized,
                {"information_schema"},
                "服务器级 SELECT",
            )
            return normalized
        if keyword == "CREATE":
            match = re.fullmatch(
                r"CREATE\s+DATABASE\s+`(?P<database>[A-Za-z0-9_]{1,64})`\s+"
                r"CHARACTER\s+SET\s+utf8mb4\s+COLLATE\s+utf8mb4_unicode_ci",
                normalized,
                re.IGNORECASE,
            )
            if not match:
                raise MigrationFailure("服务器级连接只允许创建受管中转库。")
            assert_staging_database(match.group("database"))
            return normalized
        raise MigrationFailure("服务器级连接只允许读取 information_schema 或创建受管中转库。")

    if scope == TARGET_DATABASE:
        if keyword == "SET":
            assert_session_set_statement(normalized, "目标会话设置", target=True)
            return normalized
        if keyword == "SHOW":
            if not re.fullmatch(
                r"SHOW\s+CREATE\s+TABLE\s+`(?:``|[^`])+`",
                normalized,
                re.IGNORECASE,
            ):
                raise MigrationFailure("目标 idc 仅允许 SHOW CREATE TABLE 元数据读取。")
            return normalized
        if keyword == "SELECT":
            assert_no_unsafe_sql(normalized, "目标 SELECT")
            assert_schema_references(
                normalized,
                {TARGET_DATABASE, "information_schema"},
                "目标 SELECT",
                allow_managed_staging=True,
            )
            return normalized
        if keyword == "DELETE":
            if not re.fullmatch(
                r"DELETE\s+FROM\s+`(?:``|[^`])+`", normalized, re.IGNORECASE
            ):
                raise MigrationFailure("目标 idc 只允许按完整表名执行 DELETE 清空。")
            return normalized
        if keyword == "INSERT":
            if not re.match(
                r"INSERT\s+INTO\s+`idc`\s*\.\s*`(?:``|[^`])+`(?=\s|\()",
                normalized,
                re.IGNORECASE,
            ):
                raise MigrationFailure("目标 idc INSERT 必须写入明确的 idc 表。")
            assert_no_unsafe_sql(normalized, "目标 INSERT")
            assert_schema_references(
                normalized,
                {TARGET_DATABASE, "information_schema"},
                "目标 INSERT",
                allow_managed_staging=True,
            )
            return normalized
        if keyword == "UPDATE":
            assert_no_unsafe_sql(normalized, "目标 UPDATE")
            assert_schema_references(
                normalized,
                {TARGET_DATABASE, "information_schema"},
                "目标 UPDATE",
            )
            return normalized
        raise MigrationFailure("目标 idc 仅允许白名单 SELECT、SHOW、DELETE、INSERT、UPDATE 和 FK 会话设置。")

    assert_staging_database(scope)
    if keyword == "SHOW":
        if not re.fullmatch(
            r"SHOW\s+CREATE\s+TABLE\s+`(?:``|[^`])+`", normalized, re.IGNORECASE
        ):
            raise MigrationFailure("中转库仅允许 SHOW CREATE TABLE 元数据读取。")
        return normalized
    if keyword == "SELECT":
        assert_no_unsafe_sql(normalized, "中转库 SELECT")
        assert_schema_references(
            normalized,
            {scope, "information_schema"},
            "中转库 SELECT",
        )
        return normalized
    raise MigrationFailure("中转连接仅允许 SELECT 和 SHOW CREATE TABLE。")


def sql_literal(value: Any) -> str:
    if value is None:
        return "NULL"
    if isinstance(value, bool):
        return "1" if value else "0"
    if isinstance(value, (int, float)):
        return str(value)
    raw = str(value)
    escaped = (
        raw.replace("\\", "\\\\")
        .replace("\x00", "\\0")
        .replace("\n", "\\n")
        .replace("\r", "\\r")
        .replace("\x1a", "\\Z")
        .replace("'", "\\'")
    )
    return "'" + escaped + "'"


def read_env_file(path: Path) -> dict[str, str]:
    if not path.is_file():
        raise MigrationFailure(f"未找到环境文件：{path}")
    values: dict[str, str] = {}
    for raw_line in path.read_text(encoding="utf-8", errors="replace").splitlines():
        line = raw_line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        key = key.strip()
        value = value.strip()
        if len(value) >= 2 and value[0] == value[-1] and value[0] in {"'", '"'}:
            value = value[1:-1]
        if key:
            values[key] = value
    return values


def load_db_config(env_path: Path, target_database: str) -> DbConfig:
    values = read_env_file(env_path)
    if values.get("DB_CONNECTION", "").strip().lower() != "mysql":
        raise MigrationFailure("环境文件的 DB_CONNECTION 不是 mysql")
    database = target_database.strip() or values.get("DB_DATABASE", "").strip()
    if database != TARGET_DATABASE:
        raise MigrationFailure(
            f"安全拒绝：目标库必须严格为 {TARGET_DATABASE!r}，实际为 {database!r}"
        )
    username = values.get("DB_USERNAME", "").strip()
    if not username:
        raise MigrationFailure("环境文件缺少 DB_USERNAME")
    try:
        port = int(values.get("DB_PORT", "3306").strip() or "3306")
    except ValueError as exc:
        raise MigrationFailure("环境文件中的 DB_PORT 无效") from exc
    return DbConfig(
        host=values.get("DB_HOST", "127.0.0.1").strip() or "127.0.0.1",
        port=port,
        username=username,
        password=values.get("DB_PASSWORD", ""),
        database=database,
        socket=values.get("DB_SOCKET", "").strip(),
    )


def load_local_admin_config(application_config: DbConfig) -> DbConfig:
    """
    Read the local panel-managed MySQL root credential without emitting it.

    The application account is intentionally limited to idc and cannot create
    the separate staging schema.  The admin credential is used only by this
    tool for the explicitly named idc and idc_temp_restore batch databases.
    """
    if not PANEL_CONFIG_DATABASE.is_file():
        raise MigrationFailure(
            "应用账号没有 CREATE DATABASE 权限，且未找到本机面板数据库凭据。"
        )
    connection = sqlite3.connect(str(PANEL_CONFIG_DATABASE))
    try:
        row = connection.execute(
            "SELECT mysql_root FROM config WHERE id = 1"
        ).fetchone()
    finally:
        connection.close()
    password = str(row[0] if row and row[0] is not None else "")
    if not password or password.startswith("BT-0x:"):
        raise MigrationFailure("未能读取可用的本机 MySQL 管理凭据。")
    return DbConfig(
        host=application_config.host,
        port=application_config.port,
        username="root",
        password=password,
        database=TARGET_DATABASE,
        socket=application_config.socket,
    )


def mysql_environment(config: DbConfig) -> dict[str, str]:
    environment = os.environ.copy()
    if config.password:
        environment["MYSQL_PWD"] = config.password
    return environment


def open_connection(
    config: DbConfig,
    database: str | None = None,
    *,
    autocommit: bool = False,
) -> pymysql.connections.Connection:
    if database is not None and database != TARGET_DATABASE:
        assert_staging_database(database)
    options: dict[str, Any] = {
        "user": config.username,
        "password": config.password,
        "database": database,
        "charset": "utf8mb4",
        "cursorclass": DictCursor,
        "autocommit": autocommit,
        "connect_timeout": 10,
        "read_timeout": 180,
        "write_timeout": 180,
    }
    if config.socket:
        options["unix_socket"] = config.socket
    else:
        options["host"] = config.host
        options["port"] = config.port
    connection = pymysql.connect(**options)
    try:
        setattr(connection, CONNECTION_SCOPE_ATTRIBUTE, database)
    except Exception as exc:
        connection.close()
        raise MigrationFailure("无法为数据库连接设置安全范围标记。") from exc
    return connection


@contextmanager
def managed_connection(
    config: DbConfig,
    database: str | None = None,
    *,
    autocommit: bool = False,
) -> Any:
    connection = open_connection(config, database, autocommit=autocommit)
    try:
        yield connection
    finally:
        connection.close()


def query_rows(
    connection: pymysql.connections.Connection,
    sql: str,
    params: tuple[Any, ...] | list[Any] = (),
) -> list[dict[str, Any]]:
    assert_sql_allowed_for_connection(connection, sql)
    with connection.cursor() as cursor:
        cursor.execute(sql, params)
        return list(cursor.fetchall())


def query_scalar(
    connection: pymysql.connections.Connection,
    sql: str,
    params: tuple[Any, ...] | list[Any] = (),
) -> Any:
    rows = query_rows(connection, sql, params)
    if not rows:
        return None
    return next(iter(rows[0].values()))


def execute(
    connection: pymysql.connections.Connection,
    sql: str,
    params: tuple[Any, ...] | list[Any] = (),
) -> int:
    assert_sql_allowed_for_connection(connection, sql)
    with connection.cursor() as cursor:
        cursor.execute(sql, params)
        return cursor.rowcount


def find_binary(name: str) -> Path:
    configured = ""
    if name == "mysql.exe":
        configured = os.environ.get("MYSQL_BIN", "").strip()
    elif name == "mysqldump.exe":
        configured = os.environ.get("MYSQLDUMP_BIN", "").strip()
    if configured:
        candidate = Path(configured)
        if candidate.is_file():
            return candidate.resolve()

    bare_name = name[:-4] if name.lower().endswith(".exe") else name
    discovered = shutil.which(name) or shutil.which(bare_name)
    if discovered:
        return Path(discovered).resolve()

    roots = [Path(r"D:\BtSoft\mysql"), Path(r"C:\Program Files\MySQL")]
    candidates: list[Path] = []
    for root in roots:
        if root.is_dir():
            candidates.extend(root.glob("*/bin/" + name))
    for candidate in sorted(candidates, reverse=True):
        if candidate.is_file():
            return candidate.resolve()
    raise MigrationFailure(f"未找到 {name}，请配置本机 MySQL 客户端")


def mysql_client_args(config: DbConfig, database: str) -> list[str]:
    executable = str(find_binary("mysql.exe"))
    args = [
        executable,
        "--default-character-set=utf8mb4",
        "--connect-timeout=10",
        "-u",
        config.username,
    ]
    if config.socket:
        args.append("--socket=" + config.socket)
    else:
        args.extend(["-h", config.host, "-P", str(config.port), "--protocol=TCP"])
    args.append(database)
    return args


def dump_database(
    config: DbConfig,
    output_path: Path,
    *,
    data_only: bool,
    exclude_tables: Iterable[str] = (),
) -> None:
    executable = str(find_binary("mysqldump.exe"))
    args = [
        executable,
        "--default-character-set=utf8mb4",
        "--single-transaction",
        "--skip-lock-tables",
        "--hex-blob",
        "--set-gtid-purged=OFF",
        "--no-tablespaces",
        "-u",
        config.username,
    ]
    if data_only:
        args.extend(
            [
                "--no-create-info",
                "--skip-triggers",
                "--skip-add-drop-table",
                "--skip-add-locks",
                "--skip-disable-keys",
            ]
        )
        for table in sorted(set(exclude_tables)):
            args.append("--ignore-table=" + TARGET_DATABASE + "." + table)
    else:
        args.extend(["--add-drop-table", "--routines", "--events", "--triggers"])
    if config.socket:
        args.append("--socket=" + config.socket)
    else:
        args.extend(["-h", config.host, "-P", str(config.port), "--protocol=TCP"])
    args.append(TARGET_DATABASE)
    output_path.parent.mkdir(parents=True, exist_ok=True)
    with output_path.open("wb") as output:
        completed = subprocess.run(
            args,
            stdout=output,
            stderr=subprocess.PIPE,
            env=mysql_environment(config),
            check=False,
        )
    if completed.returncode != 0 or output_path.stat().st_size == 0:
        detail = completed.stderr.decode("utf-8", errors="replace").strip()
        raise MigrationFailure(
            f"备份失败（{output_path.name}）：{detail or 'mysqldump 返回非零状态'}"
        )


def _restore_dump_with_mysql_client(
    config: DbConfig,
    dump_path: Path,
    database: str,
    *,
    context: str,
) -> None:
    with dump_path.open("rb") as source:
        completed = subprocess.run(
            mysql_client_args(config, database),
            stdin=source,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            env=mysql_environment(config),
            check=False,
        )
    if completed.returncode != 0:
        detail = completed.stderr.decode("utf-8", errors="replace").strip()
        raise MigrationFailure(context + "失败：" + (detail or "mysql 返回非零状态"))


def scan_sql_dump_for_disallowed_objects(path: Path) -> list[str]:
    patterns = {
        "CREATE DATABASE": rb"(?:^|\n)\s*CREATE\s+DATABASE\b",
        "DROP DATABASE": rb"(?:^|\n)\s*DROP\s+DATABASE\b",
        "USE database": rb"(?:^|\n)\s*USE\s+",
        "trigger": rb"(?:^|\n)\s*CREATE\s+TRIGGER\b",
        "procedure": rb"(?:^|\n)\s*CREATE\s+PROCEDURE\b",
        "function": rb"(?:^|\n)\s*CREATE\s+FUNCTION\b",
        "event": rb"(?:^|\n)\s*CREATE\s+EVENT\b",
        "GRANT": rb"(?:^|\n)\s*GRANT\b",
        "CREATE USER": rb"(?:^|\n)\s*CREATE\s+USER\b",
        "SET GLOBAL": rb"(?:^|\n)\s*SET\s+GLOBAL\b",
    }
    found: set[str] = set()
    tail = b""
    with path.open("rb") as handle:
        while True:
            block = handle.read(1024 * 1024)
            if not block:
                break
            payload = tail + block
            for name, pattern in patterns.items():
                if re.search(pattern, payload, re.IGNORECASE):
                    found.add(name)
            tail = payload[-1024:]
    return sorted(found)


def scan_sql_dump_for_target_database_access(path: Path) -> list[str]:
    """
    A staging restore runs through an administrative connection. Reject any
    statement line that could explicitly address the immutable target schema.
    The scan is anchored at statement starts so text values in INSERT rows are
    not treated as SQL object names.
    """
    statement = (
        rb"(?:^|\n)\s*(?:/\*![0-9]+\s+)?"
        rb"(?:CREATE|DROP|ALTER|TRUNCATE|RENAME|INSERT|REPLACE|UPDATE|DELETE|LOCK|UNLOCK)\b"
        rb"[^;\n]{0,512}(?:\x60idc\x60|\bidc\b)\s*\."
    )
    payload_tail = b""
    with path.open("rb") as handle:
        while True:
            block = handle.read(1024 * 1024)
            if not block:
                break
            payload = payload_tail + block
            if re.search(statement, payload, re.IGNORECASE):
                return ["qualified idc table reference"]
            payload_tail = payload[-1024:]
    return []


def sanitize_dump_for_staging(source_path: Path, output_path: Path) -> dict[str, Any]:
    """
    Exclude MySQL dump view sections while retaining every base-table DDL and
    INSERT.  Views are forbidden by the task and are incompatible here because
    the final schema uses base tables with the same product-group names.
    """
    start_pattern = re.compile(
        rb"^\s*--\s*(?:Temporary|Final)\s+(?:(?:table|view)\s+)?structure\s+for\s+view\b",
        re.IGNORECASE,
    )
    heading_pattern = re.compile(rb"^\s*--\s+\S")
    skipped_sections = 0
    skipping = False
    output_path.parent.mkdir(parents=True, exist_ok=True)
    with source_path.open("rb") as source, output_path.open("wb") as output:
        for line in source:
            if start_pattern.search(line):
                skipping = True
                skipped_sections += 1
                continue
            if skipping:
                if heading_pattern.search(line):
                    skipping = False
                    output.write(line)
                continue
            output.write(line)
    if skipping:
        raise MigrationFailure("源 dump 的视图段落未正常结束，拒绝还原。")
    if output_path.stat().st_size == 0:
        raise MigrationFailure("过滤视图后的中转 dump 为空。")
    return {
        "path": str(output_path),
        "skipped_view_sections": skipped_sections,
        "sha256": hash_file(output_path),
    }


def iter_mysql_dump_statements(path: Path) -> Iterable[bytes]:
    """Split a MySQL dump without treating quoted semicolons as separators."""
    payload = path.read_bytes()
    statement = bytearray()
    state = "normal"
    executable_prefix = False
    index = 0
    while index < len(payload):
        current = payload[index]
        following = payload[index + 1] if index + 1 < len(payload) else None
        if state == "normal":
            if current == ord("'"):
                statement.append(current)
                state = "single"
            elif current == ord('"'):
                statement.append(current)
                state = "double"
            elif current == ord("`"):
                statement.append(current)
                state = "backtick"
            elif (
                current == ord("-")
                and following == ord("-")
                and (
                    index + 2 >= len(payload)
                    or payload[index + 2] in b" \t\r\n"
                )
            ):
                statement.append(ord(" "))
                state = "line_comment"
                index += 1
            elif current == ord("#"):
                statement.append(ord(" "))
                state = "line_comment"
            elif current == ord("/") and following == ord("*"):
                statement.append(ord(" "))
                if index + 2 < len(payload) and payload[index + 2] == ord("!"):
                    state = "executable_comment"
                    executable_prefix = True
                    index += 2
                else:
                    state = "block_comment"
                    index += 1
            elif current == ord(";"):
                candidate = bytes(statement).strip()
                if candidate:
                    yield candidate
                statement.clear()
            else:
                statement.append(current)
        elif state in {"single", "double"}:
            statement.append(current)
            if current == ord("\\"):
                if following is not None:
                    statement.append(following)
                    index += 1
            elif current == (ord("'") if state == "single" else ord('"')):
                if following == current:
                    statement.append(following)
                    index += 1
                else:
                    state = "normal"
        elif state == "backtick":
            statement.append(current)
            if current == ord("`"):
                if following == current:
                    statement.append(following)
                    index += 1
                else:
                    state = "normal"
        elif state == "line_comment":
            if current in {ord("\r"), ord("\n")}:
                state = "normal"
        elif state in {"block_comment", "executable_comment"}:
            if current == ord("*") and following == ord("/"):
                statement.append(ord(" "))
                state = "normal"
                executable_prefix = False
                index += 1
            elif state == "executable_comment":
                if executable_prefix and (48 <= current <= 57 or current in b" \t\r\n"):
                    pass
                else:
                    executable_prefix = False
                    statement.append(current)
        index += 1
    if state in {"single", "double", "backtick", "block_comment", "executable_comment"}:
        raise MigrationFailure("SQL dump 存在未闭合的字符串、标识符或注释。")
    candidate = bytes(statement).strip()
    if candidate:
        yield candidate


def assert_dump_only_database_qualified_references(
    sql: str,
    database: str,
    context: str,
) -> None:
    code = _sql_code_without_string_literals(sql)
    qualified = re.compile(
        r"(?<![A-Za-z0-9_$`])(?P<schema>`(?:``|[^`])+`|[A-Za-z_][A-Za-z0-9_$]*)"
        r"\s*\.\s*(?:`(?:``|[^`])+`|[A-Za-z_][A-Za-z0-9_$]*)",
        re.IGNORECASE,
    )
    for match in qualified.finditer(code):
        schema = _unquote_identifier(match.group("schema"))
        if schema.casefold() != database.casefold():
            raise MigrationFailure(context + " 的限定库名不是当前允许数据库：" + schema)


def assert_no_unsafe_sql_tail(sql: str, context: str) -> None:
    code = _sql_code_without_string_literals(sql)
    tail = re.sub(r"^\s*[A-Za-z]+\b", "", code, count=1)
    if SQL_UNSAFE_KEYWORDS.search(tail):
        raise MigrationFailure(context + " 包含嵌入式 DDL 或管理语句。")
    if re.search(r"\b(?:OUTFILE|DUMPFILE)\b", tail, re.IGNORECASE):
        raise MigrationFailure(context + " 不允许读写服务器文件。")


def validate_staging_restore_dump(path: Path, staging_database: str) -> None:
    """Permit only data and table-create/drop statements bound to one staging DB."""
    assert_staging_database(staging_database)
    statement_count = 0
    for raw_statement in iter_mysql_dump_statements(path):
        statement = normalize_single_sql_statement(
            raw_statement.decode("utf-8", errors="replace"), "中转恢复 SQL"
        )
        keyword = sql_first_keyword(statement, "中转恢复 SQL")
        statement_count += 1
        if keyword == "SET":
            assert_session_set_statement(statement, "中转恢复 SET", target=False)
            continue
        if keyword == "CREATE":
            if not re.match(r"CREATE\s+(?:TEMPORARY\s+)?TABLE\b", statement, re.IGNORECASE):
                raise MigrationFailure("中转恢复只允许 CREATE TABLE。")
        elif keyword == "DROP":
            if not re.match(r"DROP\s+TABLE\b", statement, re.IGNORECASE):
                raise MigrationFailure("中转恢复只允许 DROP TABLE 清空中转表。")
        elif keyword == "INSERT":
            if not re.match(
                r"INSERT\s+(?:(?:LOW_PRIORITY|DELAYED|HIGH_PRIORITY)\s+)?(?:IGNORE\s+)?INTO\b",
                statement,
                re.IGNORECASE,
            ):
                raise MigrationFailure("中转恢复 INSERT 语法不在白名单中。")
        elif keyword == "ALTER":
            identifier = r"(?:`(?:``|[^`])+`|[A-Za-z_][A-Za-z0-9_$]*)"
            if not re.fullmatch(
                r"ALTER\s+TABLE\s+(?:" + identifier + r"\s*\.\s*)?"
                + identifier
                + r"\s+(?:DISABLE|ENABLE)\s+KEYS",
                statement,
                re.IGNORECASE,
            ):
                raise MigrationFailure(
                    "中转恢复仅允许 ALTER TABLE ... DISABLE/ENABLE KEYS。"
                )
        elif keyword == "LOCK":
            if not re.fullmatch(r"LOCK\s+TABLES\s+.+", statement, re.IGNORECASE | re.DOTALL):
                raise MigrationFailure("中转恢复仅允许 LOCK TABLES。")
        elif keyword == "UNLOCK":
            if not re.fullmatch(r"UNLOCK\s+TABLES", statement, re.IGNORECASE):
                raise MigrationFailure("中转恢复仅允许 UNLOCK TABLES。")
        else:
            raise MigrationFailure("中转恢复出现未批准 SQL 动词：" + keyword)
        assert_no_unsafe_sql_tail(statement, "中转恢复 SQL")
        assert_dump_only_database_qualified_references(
            statement, staging_database, "中转恢复 SQL"
        )
    if statement_count == 0:
        raise MigrationFailure("中转恢复 dump 不包含可执行 SQL。")


def restore_staging_dump(
    config: DbConfig,
    dump_path: Path,
    staging_database: str,
) -> None:
    assert_staging_database(staging_database)
    validate_staging_restore_dump(dump_path, staging_database)
    _restore_dump_with_mysql_client(
        config,
        dump_path,
        staging_database,
        context="中转库完整还原",
    )


def hash_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        while True:
            block = handle.read(1024 * 1024)
            if not block:
                break
            digest.update(block)
    return digest.hexdigest()


def list_base_tables(connection: pymysql.connections.Connection, database: str) -> list[str]:
    rows = query_rows(
        connection,
        """
        SELECT TABLE_NAME
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = %s AND TABLE_TYPE = 'BASE TABLE'
        ORDER BY TABLE_NAME
        """,
        (database,),
    )
    return [str(row["TABLE_NAME"]) for row in rows]


def fetch_schema_metadata(
    connection: pymysql.connections.Connection,
    database: str,
) -> dict[str, Any]:
    tables = list_base_tables(connection, database)
    raw_columns = query_rows(
        connection,
        """
        SELECT
            TABLE_NAME,
            COLUMN_NAME,
            ORDINAL_POSITION,
            COLUMN_DEFAULT,
            IS_NULLABLE,
            DATA_TYPE,
            COLUMN_TYPE,
            CHARACTER_MAXIMUM_LENGTH,
            CHARACTER_OCTET_LENGTH,
            NUMERIC_PRECISION,
            NUMERIC_SCALE,
            DATETIME_PRECISION,
            CHARACTER_SET_NAME,
            COLLATION_NAME,
            COLUMN_KEY,
            EXTRA
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = %s
        ORDER BY TABLE_NAME, ORDINAL_POSITION
        """,
        (database,),
    )
    columns: dict[str, list[dict[str, Any]]] = {table: [] for table in tables}
    for row in raw_columns:
        copied = {key.lower(): value for key, value in row.items()}
        copied["column_default"] = (
            None if copied["column_default"] is None else str(copied["column_default"])
        )
        copied["is_nullable"] = copied["is_nullable"] == "YES"
        columns.setdefault(str(copied["table_name"]), []).append(copied)

    raw_indexes = query_rows(
        connection,
        """
        SELECT
            TABLE_NAME,
            INDEX_NAME,
            NON_UNIQUE,
            SEQ_IN_INDEX,
            COLUMN_NAME
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = %s
        ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX
        """,
        (database,),
    )
    unique_indexes: dict[str, dict[str, list[str]]] = {table: {} for table in tables}
    for row in raw_indexes:
        if int(row["NON_UNIQUE"]) != 0:
            continue
        table = str(row["TABLE_NAME"])
        index = str(row["INDEX_NAME"])
        unique_indexes.setdefault(table, {}).setdefault(index, []).append(
            str(row["COLUMN_NAME"])
        )

    raw_foreign_keys = query_rows(
        connection,
        """
        SELECT
            TABLE_NAME,
            CONSTRAINT_NAME,
            COLUMN_NAME,
            REFERENCED_TABLE_NAME,
            REFERENCED_COLUMN_NAME,
            ORDINAL_POSITION
        FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = %s
          AND REFERENCED_TABLE_NAME IS NOT NULL
        ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION
        """,
        (database,),
    )
    foreign_keys: dict[str, dict[str, dict[str, Any]]] = {}
    for row in raw_foreign_keys:
        table = str(row["TABLE_NAME"])
        name = str(row["CONSTRAINT_NAME"])
        item = foreign_keys.setdefault(table, {}).setdefault(
            name,
            {
                "table": table,
                "name": name,
                "referenced_table": str(row["REFERENCED_TABLE_NAME"]),
                "pairs": [],
            },
        )
        item["pairs"].append(
            {
                "column": str(row["COLUMN_NAME"]),
                "referenced_column": str(row["REFERENCED_COLUMN_NAME"]),
            }
        )

    return {
        "tables": tables,
        "columns": columns,
        "unique_indexes": unique_indexes,
        "foreign_keys": [
            item
            for per_table in foreign_keys.values()
            for item in per_table.values()
        ],
    }


def schema_snapshot(
    connection: pymysql.connections.Connection,
    database: str,
    output_path: Path,
) -> dict[str, Any]:
    tables = list_base_tables(connection, database)
    raw_creates: dict[str, str] = {}
    normalized_creates: dict[str, str] = {}
    chunks = [
        f"-- SHOW CREATE TABLE snapshot for {database}",
        f"-- generated {now_text()}",
        "",
    ]
    for table in tables:
        row = query_rows(connection, "SHOW CREATE TABLE " + quote_identifier(table))[0]
        create_sql = str(next(value for key, value in row.items() if key != "Table"))
        raw_creates[table] = create_sql
        normalized = re.sub(
            r"\sAUTO_INCREMENT\s*=\s*\d+\b",
            "",
            create_sql,
            flags=re.IGNORECASE,
        )
        normalized_creates[table] = normalized
        chunks.extend([create_sql + ";", ""])
    output_path.write_text("\n".join(chunks), encoding="utf-8")
    normalized_hashes = {
        table: hashlib.sha256(sql.encode("utf-8")).hexdigest()
        for table, sql in normalized_creates.items()
    }
    return {
        "table_count": len(tables),
        "tables": tables,
        "normalized_hashes": normalized_hashes,
        "raw_creates": raw_creates,
        "normalized_creates": normalized_creates,
    }


def compare_schema_snapshots(before: dict[str, Any], after: dict[str, Any]) -> list[str]:
    problems: list[str] = []
    if before["tables"] != after["tables"]:
        missing = sorted(set(before["tables"]) - set(after["tables"]))
        unexpected = sorted(set(after["tables"]) - set(before["tables"]))
        if missing:
            problems.append("迁移后缺少表：" + ", ".join(missing))
        if unexpected:
            problems.append("迁移后出现新表：" + ", ".join(unexpected))
    for table in sorted(set(before["normalized_hashes"]) & set(after["normalized_hashes"])):
        if before["normalized_hashes"][table] != after["normalized_hashes"][table]:
            problems.append(table + " 的字段、索引或约束定义发生变化")
    return problems


def write_schema_diff_report(
    path: Path,
    before: dict[str, Any],
    after: dict[str, Any],
) -> list[str]:
    problems = compare_schema_snapshots(before, after)
    lines = [
        "# idc 结构前后对比",
        "",
        f"- 迁移前表数：{before['table_count']}",
        f"- 迁移后表数：{after['table_count']}",
        "- 对比方式：SHOW CREATE TABLE，忽略仅由写入数据自然推进的表级 AUTO_INCREMENT 数值。",
        "",
    ]
    if problems:
        lines.append("## 不一致项")
        lines.append("")
        lines.extend("- " + item for item in problems)
    else:
        lines.extend(["## 结果", "", "结构一致：表、字段、索引和约束均无差异。"])
    path.write_text("\n".join(lines) + "\n", encoding="utf-8")
    return problems


def column_map(columns: list[dict[str, Any]]) -> dict[str, dict[str, Any]]:
    return {str(column["column_name"]): column for column in columns}


def type_name(column: dict[str, Any]) -> str:
    return str(column["column_type"]).lower()


def data_type(column: dict[str, Any]) -> str:
    return str(column["data_type"]).lower()


def is_textual(column: dict[str, Any]) -> bool:
    return data_type(column) in TEXT_TYPES


def is_numeric(column: dict[str, Any]) -> bool:
    return data_type(column) in NUMERIC_TYPES


def is_temporal(column: dict[str, Any]) -> bool:
    return data_type(column) in TEMPORAL_TYPES


def is_auto_increment(column: dict[str, Any]) -> bool:
    return "auto_increment" in str(column["extra"]).lower()


def type_mismatch(source: dict[str, Any], target: dict[str, Any]) -> bool:
    if data_type(source) != data_type(target):
        return True
    if is_textual(target):
        return (
            str(source["character_set_name"] or "").lower()
            != str(target["character_set_name"] or "").lower()
            or source["character_maximum_length"] != target["character_maximum_length"]
        )
    if is_numeric(target):
        return (
            source["numeric_precision"] != target["numeric_precision"]
            or source["numeric_scale"] != target["numeric_scale"]
            or ("unsigned" in type_name(source)) != ("unsigned" in type_name(target))
        )
    return type_name(source) != type_name(target)


def default_expression(column: dict[str, Any]) -> str | None:
    value = column["column_default"]
    if value is None:
        return None
    upper = str(value).upper()
    if upper in {"CURRENT_TIMESTAMP", "CURRENT_TIMESTAMP()", "CURRENT_DATE", "CURRENT_TIME"}:
        return upper
    if is_numeric(column) or data_type(column) == "bit":
        return str(value)
    return sql_literal(value)


def fallback_expression(column: dict[str, Any]) -> str:
    kind = data_type(column)
    if is_numeric(column):
        return "0"
    if kind == "date":
        return "CURRENT_DATE"
    if kind in {"datetime", "timestamp"}:
        return "CURRENT_TIMESTAMP"
    if kind == "time":
        return "CURRENT_TIME"
    if kind == "json":
        return "JSON_OBJECT()"
    if kind == "set":
        return sql_literal("")
    return sql_literal("")


def numeric_cast_expression(source_sql: str, target: dict[str, Any]) -> str:
    kind = data_type(target)
    if kind in {"decimal", "numeric"}:
        precision = int(target["numeric_precision"] or 30)
        scale = int(target["numeric_scale"] or 0)
        return f"CAST({source_sql} AS DECIMAL({precision},{scale}))"
    if kind in {"float", "double", "real"}:
        return f"CAST({source_sql} AS DOUBLE)"
    signedness = "UNSIGNED" if "unsigned" in type_name(target) else "SIGNED"
    return f"CAST({source_sql} AS {signedness})"


def temporal_cast_expression(source_sql: str, target: dict[str, Any]) -> str:
    kind = data_type(target)
    cast_kind = {
        "date": "DATE",
        "datetime": "DATETIME",
        "timestamp": "DATETIME",
        "time": "TIME",
        "year": "UNSIGNED",
    }[kind]
    invalid = (
        f"CAST({source_sql} AS CHAR) IN "
        "('0000-00-00', '0000-00-00 00:00:00', '00:00:00')"
    )
    converted = f"CAST({source_sql} AS {cast_kind})"
    if bool(target["is_nullable"]):
        replacement = "NULL"
    elif kind == "date":
        replacement = "CURRENT_DATE"
    elif kind == "time":
        replacement = "CURRENT_TIME"
    else:
        replacement = "CURRENT_TIMESTAMP"
    return (
        f"CASE WHEN {source_sql} IS NULL OR {invalid} THEN {replacement} "
        f"ELSE {converted} END"
    )


def textual_expression(
    source_sql: str,
    source: dict[str, Any],
    target: dict[str, Any],
) -> tuple[str, list[str], list[dict[str, Any]]]:
    transforms: list[str] = []
    warnings: list[dict[str, Any]] = []
    source_charset = str(source["character_set_name"] or "").lower()
    target_charset = str(target["character_set_name"] or "").lower()
    expression = source_sql
    if source_charset == "latin1" and target_charset == "utf8mb4":
        expression = f"CONVERT({source_sql} USING utf8mb4)"
        transforms.append("latin1_to_utf8mb4_convert")
    elif source_charset and target_charset and source_charset != target_charset:
        expression = f"CONVERT({source_sql} USING utf8mb4)"
        transforms.append("charset_convert_to_utf8mb4")

    maximum_bytes = target.get("character_octet_length")
    maximum_chars = target.get("character_maximum_length")
    if maximum_bytes:
        max_bytes = int(maximum_bytes)
        safe_char_limit = int(maximum_chars or max_bytes)
        if target_charset == "utf8mb4":
            safe_char_limit = min(safe_char_limit, max(1, max_bytes // 4))
        converted = f"CONVERT({expression} USING utf8mb4)"
        expression = (
            f"CASE WHEN OCTET_LENGTH({converted}) > {max_bytes} "
            f"THEN LEFT({converted}, {safe_char_limit}) ELSE {converted} END"
        )
        transforms.append(f"truncate_to_{max_bytes}_bytes_if_needed")
        warnings.append(
            {
                "kind": "text_overflow_truncated",
                "maximum_bytes": max_bytes,
                "source_sql": source_sql,
            }
        )
    return expression, transforms, warnings


def source_expression(
    source_sql: str,
    source: dict[str, Any],
    target: dict[str, Any],
) -> tuple[str, list[str], list[dict[str, Any]]]:
    transforms: list[str] = []
    warnings: list[dict[str, Any]] = []
    if is_temporal(target):
        expression = temporal_cast_expression(source_sql, target)
        transforms.append("zero_date_to_null_or_current_timestamp")
        if type_mismatch(source, target):
            transforms.append("explicit_temporal_cast")
        warnings.append(
            {
                "kind": "invalid_zero_date_repaired",
                "source_sql": source_sql,
            }
        )
        return expression, transforms, warnings
    if is_numeric(target) and type_mismatch(source, target):
        return numeric_cast_expression(source_sql, target), ["explicit_numeric_cast"], warnings
    if data_type(target) == "json":
        fallback = "NULL" if bool(target["is_nullable"]) else "JSON_OBJECT()"
        expression = (
            f"CASE WHEN {source_sql} IS NULL OR JSON_VALID(CAST({source_sql} AS CHAR)) "
            f"THEN {source_sql} ELSE {fallback} END"
        )
        transforms.append("invalid_json_to_null_or_empty_object")
        return expression, transforms, warnings
    if is_textual(target):
        expression, transforms, warnings = textual_expression(source_sql, source, target)
        if type_mismatch(source, target):
            transforms.append("explicit_convert_or_truncate")
        return expression, transforms, warnings
    if type_mismatch(source, target):
        return f"CAST({source_sql} AS CHAR)", ["explicit_cast_as_char"], warnings
    return source_sql, transforms, warnings


def source_column_for_target(
    target_table: str,
    target_column: str,
    source_columns: dict[str, dict[str, Any]],
) -> str | None:
    aliases: dict[tuple[str, str], list[str]] = {
        ("orders", "product_spec_snapshot"): [
            "product_spec_snapshot",
            "product_name_snapshot",
        ],
        ("invoices", "product_spec_snapshot"): [
            "product_spec_snapshot",
            "product_name_snapshot",
        ],
        ("products", "upstream_product_name"): [
            "upstream_product_name",
            "supplier_product_name",
        ],
    }
    for candidate in aliases.get((target_table, target_column), [target_column]):
        if candidate in source_columns:
            return candidate
    return None


def render_template(sql: str, target_database: str, staging_database: str) -> str:
    return sql.replace("{{TARGET_DB}}", quote_identifier(target_database)).replace(
        "{{STAGING_DB}}", quote_identifier(staging_database)
    )


def source_reference(source_table: str) -> str:
    return "{{STAGING_DB}}." + quote_identifier(source_table)


def target_reference(target_table: str) -> str:
    return "{{TARGET_DB}}." + quote_identifier(target_table)


def build_select_with_deduplication(
    target_columns: list[str],
    projections: list[tuple[str, str]],
    from_sql: str,
    where_sql: str,
    unique_indexes: dict[str, list[str]],
    source_backed_columns: set[str],
) -> tuple[str, list[dict[str, Any]]]:
    projected_sql = ", ".join(
        f"{expression} AS {quote_identifier(name)}" for name, expression in projections
    )
    inner = "SELECT " + projected_sql + " FROM " + from_sql
    if where_sql:
        inner += " WHERE " + where_sql

    applicable_indexes: list[dict[str, Any]] = []
    available = set(target_columns)
    for name, columns in unique_indexes.items():
        if columns and all(column in available for column in columns):
            applicable_indexes.append({"name": name, "columns": columns})
    if not applicable_indexes:
        select_sql = (
            "SELECT "
            + ", ".join(quote_identifier(column) for column in target_columns)
            + " FROM ("
            + inner
            + ") AS mapped"
        )
        return select_sql, []

    order_candidates = [
        column
        for column in ("updated_at", "created_at", "id")
        if column in source_backed_columns
    ]
    if not order_candidates:
        order_candidates = [
            column for column in target_columns if column in source_backed_columns
        ][:1]
    if not order_candidates:
        order_candidates = target_columns[:1]
    order_sql = ", ".join(
        "mapped." + quote_identifier(column) + " DESC"
        for column in order_candidates
    )

    window_columns: list[str] = []
    conditions: list[str] = []
    for ordinal, item in enumerate(applicable_indexes, start=1):
        key_columns = item["columns"]
        partition = ", ".join(
            "mapped." + quote_identifier(column) for column in key_columns
        )
        alias = "__dedupe_" + str(ordinal)
        window_columns.append(
            f"ROW_NUMBER() OVER (PARTITION BY {partition} ORDER BY {order_sql}) "
            f"AS {quote_identifier(alias)}"
        )
        null_condition = " OR ".join(
            "ranked." + quote_identifier(column) + " IS NULL"
            for column in key_columns
        )
        conditions.append(
            "(" + null_condition + " OR ranked." + quote_identifier(alias) + " = 1)"
        )
        item["row_number_column"] = alias

    ranked = (
        "SELECT mapped.*, "
        + ", ".join(window_columns)
        + " FROM ("
        + inner
        + ") AS mapped"
    )
    select_sql = (
        "SELECT "
        + ", ".join("ranked." + quote_identifier(column) for column in target_columns)
        + " FROM ("
        + ranked
        + ") AS ranked WHERE "
        + " AND ".join(conditions)
    )
    return select_sql, applicable_indexes


def build_mapping_plan(
    *,
    target_table: str,
    source_table: str,
    target_columns: list[dict[str, Any]],
    source_columns: list[dict[str, Any]],
    unique_indexes: dict[str, list[str]],
    source_filter: str = "",
    source_filter_is_data_quality: bool = False,
    source_filter_reason: str = "",
    custom_expressions: dict[str, str] | None = None,
    custom_transform_labels: dict[str, str] | None = None,
    custom_sources: dict[str, str] | None = None,
    extra_joins: str = "",
    plan_kind: str = "same_name",
) -> dict[str, Any]:
    custom_expressions = custom_expressions or {}
    custom_transform_labels = custom_transform_labels or {}
    custom_sources = custom_sources or {}
    source_by_name = column_map(source_columns)
    projections: list[tuple[str, str]] = []
    fields: list[dict[str, Any]] = []
    source_backed_columns: set[str] = set()

    for target in target_columns:
        target_name = str(target["column_name"])
        field: dict[str, Any] = {
            "target_column": target_name,
            "target_type": type_name(target),
            "source_column": None,
            "source_type": None,
            "classification": "",
            "transforms": [],
        }
        if target_name in custom_expressions:
            expression = custom_expressions[target_name]
            field["classification"] = "custom_transform"
            field["transforms"] = [
                custom_transform_labels.get(target_name, "custom_mapping_rule")
            ]
            projections.append((target_name, expression))
            source_backed_columns.add(target_name)
        elif (
            target_table in {"orders", "invoices"}
            and target_name == "product_spec_snapshot"
            and (
                "product_spec_snapshot" in source_by_name
                or "product_name_snapshot" in source_by_name
            )
        ):
            candidates = [
                "NULLIF(s." + quote_identifier(name) + ", '')"
                for name in ("product_spec_snapshot", "product_name_snapshot")
                if name in source_by_name
            ]
            source_name = next(
                name
                for name in ("product_spec_snapshot", "product_name_snapshot")
                if name in source_by_name
            )
            expression, transforms, warnings = source_expression(
                "COALESCE(" + ", ".join(candidates) + ")",
                source_by_name[source_name],
                target,
            )
            field["source_column"] = " | ".join(
                name
                for name in ("product_spec_snapshot", "product_name_snapshot")
                if name in source_by_name
            )
            field["source_type"] = type_name(source_by_name[source_name])
            field["classification"] = "custom_transform"
            field["transforms"] = ["coalesce_nonempty_product_snapshot"] + transforms
            if warnings:
                field["warning_rules"] = warnings
            projections.append((target_name, expression))
            source_backed_columns.add(target_name)
        else:
            source_name = custom_sources.get(
                target_name,
                source_column_for_target(target_table, target_name, source_by_name),
            )
            source = source_by_name.get(source_name) if source_name else None
            if source is not None:
                source_sql = "s." + quote_identifier(source_name)
                expression, transforms, warnings = source_expression(
                    source_sql, source, target
                )
                field["source_column"] = source_name
                field["source_type"] = type_name(source)
                field["classification"] = (
                    "type_mismatch" if type_mismatch(source, target) else "common_direct"
                )
                field["transforms"] = transforms
                if warnings:
                    field["warning_rules"] = warnings
                projections.append((target_name, expression))
                source_backed_columns.add(target_name)
            elif is_auto_increment(target):
                field["classification"] = "target_only_auto_increment_omitted"
                fields.append(field)
                continue
            else:
                default = default_expression(target)
                if default is not None:
                    expression = default
                    field["classification"] = "target_only_default"
                elif bool(target["is_nullable"]):
                    expression = "NULL"
                    field["classification"] = "target_only_null"
                else:
                    field["classification"] = "target_only_required_no_default_blocked"
                    field["transforms"] = ["manual_mapping_required"]
                    fields.append(field)
                    continue
                projections.append((target_name, expression))
        fields.append(field)

    insert_columns = [name for name, _ in projections]
    select_sql, dedupe_indexes = build_select_with_deduplication(
        insert_columns,
        projections,
        source_reference(source_table) + " AS s" + extra_joins,
        source_filter,
        unique_indexes,
        source_backed_columns,
    )
    insert_sql = (
        "INSERT INTO "
        + target_reference(target_table)
        + " ("
        + ", ".join(quote_identifier(column) for column in insert_columns)
        + ") "
        + select_sql
        + ";"
    )
    raw_count_sql = "SELECT COUNT(*) FROM " + source_reference(source_table) + " AS s"
    if source_filter and not source_filter_is_data_quality:
        raw_count_sql += " WHERE " + source_filter
    filtered_source_count_sql = (
        "SELECT COUNT(*) FROM "
        + source_reference(source_table)
        + " AS s WHERE "
        + source_filter
        if source_filter and source_filter_is_data_quality
        else raw_count_sql
    )
    expected_count_sql = "SELECT COUNT(*) FROM (" + select_sql + ") AS expected_rows"

    return {
        "action": "map",
        "kind": plan_kind,
        "target_table": target_table,
        "source_table": source_table,
        "source_filter": source_filter,
        "source_filter_is_data_quality": source_filter_is_data_quality,
        "source_filter_reason": source_filter_reason,
        "insert_columns": insert_columns,
        "fields": fields,
        "unique_key_deduplication": dedupe_indexes,
        "raw_count_sql_template": raw_count_sql,
        "filtered_source_count_sql_template": filtered_source_count_sql,
        "expected_count_sql_template": expected_count_sql,
        "select_sql_template": select_sql,
        "insert_sql_template": insert_sql,
    }


def resolve_product_group_levels(
    connection: pymysql.connections.Connection,
    staging_database: str,
    source_metadata: dict[str, Any],
) -> dict[int, int]:
    columns = column_map(source_metadata["columns"].get(PRODUCT_GROUP_SOURCE, []))
    if not {"id", "parent_id", "level"}.issubset(columns):
        return {}
    rows = query_rows(
        connection,
        "SELECT "
        + quote_identifier("id")
        + ", "
        + quote_identifier("parent_id")
        + ", "
        + quote_identifier("level")
        + " FROM "
        + quote_identifier(staging_database)
        + "."
        + quote_identifier(PRODUCT_GROUP_SOURCE),
    )
    parents: dict[int, int | None] = {}
    declared_levels: dict[int, int] = {}
    for row in rows:
        item_id = int(row["id"])
        raw_parent = row["parent_id"]
        parents[item_id] = (
            None
            if raw_parent is None or str(raw_parent).strip().upper() in {"", "0", "NULL"}
            else int(raw_parent)
        )
        if row["level"] is None:
            raise MigrationFailure(f"中转库 product_groups.level 为空：{item_id}")
        declared_levels[item_id] = int(row["level"])

    resolved: dict[int, int] = {}
    active: set[int] = set()

    def level(item_id: int) -> int:
        if item_id in resolved:
            return resolved[item_id]
        if item_id in active:
            raise MigrationFailure(f"中转库 product_groups 存在循环父级：{item_id}")
        active.add(item_id)
        parent = parents.get(item_id)
        if parent is not None and parent not in parents:
            raise MigrationFailure(
                f"中转库 product_groups 的父级不存在：{item_id} -> {parent}"
            )
        current = 1 if parent is None else level(parent) + 1
        active.remove(item_id)
        if current > 3:
            raise MigrationFailure(
                f"中转库 product_groups 层级超过当前版本支持的 3 层：{item_id}"
            )
        resolved[item_id] = current
        return current

    for item_id in parents:
        level(item_id)
    inconsistent = [
        str(item_id)
        for item_id, computed in sorted(resolved.items())
        if declared_levels[item_id] != computed
    ]
    if inconsistent:
        raise MigrationFailure(
            "中转库 product_groups.level 与父级关系不一致：" + ", ".join(inconsistent)
        )
    return resolved


def list_promoted_product_groups(
    connection: pymysql.connections.Connection,
    staging_database: str,
    source_metadata: dict[str, Any],
    levels: dict[int, int],
) -> dict[int, list[int]]:
    product_columns = column_map(source_metadata["columns"].get("products", []))
    if "product_group_id" not in product_columns or not levels:
        return {1: [], 2: []}
    rows = query_rows(
        connection,
        "SELECT DISTINCT "
        + quote_identifier("product_group_id")
        + " AS group_id FROM "
        + quote_identifier(staging_database)
        + "."
        + quote_identifier("products")
        + " WHERE "
        + quote_identifier("product_group_id")
        + " IS NOT NULL",
    )
    result = {1: [], 2: []}
    for row in rows:
        group_id = int(row["group_id"])
        item_level = levels.get(group_id)
        if item_level in result:
            result[item_level].append(group_id)
    return {key: sorted(set(values)) for key, values in result.items()}


def list_uncategorized_product_root_ids(
    connection: pymysql.connections.Connection,
    staging_database: str,
    source_metadata: dict[str, Any],
    levels: dict[int, int],
) -> set[int]:
    product_columns = column_map(source_metadata["columns"].get("products", []))
    group_columns = column_map(source_metadata["columns"].get(PRODUCT_GROUP_SOURCE, []))
    if "product_group_id" not in product_columns:
        return set()
    if not {"id", "code"}.issubset(group_columns):
        raise MigrationFailure(
            "product_groups 缺少 id 或 code，无法为未分类商品建立三级分类承接路径"
        )

    group_rows = query_rows(
        connection,
        "SELECT "
        + quote_identifier("id")
        + ", "
        + quote_identifier("code")
        + " FROM "
        + quote_identifier(staging_database)
        + "."
        + quote_identifier(PRODUCT_GROUP_SOURCE),
    )
    root_codes: dict[str, int] = {}
    for row in group_rows:
        group_id = int(row["id"])
        if levels.get(group_id) != 1:
            continue
        code = str(row["code"] or "").strip().casefold()
        if not code:
            continue
        if code in root_codes and root_codes[code] != group_id:
            raise MigrationFailure("product_groups 存在重复的一级分类编码：" + code)
        root_codes[code] = group_id
    if not root_codes:
        raise MigrationFailure("product_groups 不存在可用的一级分类编码")

    selected_fields = []
    for name in ("service_type_code", "product_type"):
        if name in product_columns:
            selected_fields.append("s." + quote_identifier(name) + " AS " + quote_identifier(name))
        else:
            selected_fields.append("NULL AS " + quote_identifier(name))
    unclassified_rows = query_rows(
        connection,
        "SELECT "
        + ", ".join(selected_fields)
        + " FROM "
        + quote_identifier(staging_database)
        + "."
        + quote_identifier("products")
        + " AS s WHERE s."
        + quote_identifier("product_group_id")
        + " IS NULL OR s."
        + quote_identifier("product_group_id")
        + " = 0",
    )
    fallback_other = root_codes.get("other")
    resolved: set[int] = set()
    for row in unclassified_rows:
        candidates = [
            str(row[name] or "").strip().casefold()
            for name in ("service_type_code", "product_type")
        ]
        root_id = next(
            (root_codes[code] for code in candidates if code and code in root_codes),
            fallback_other,
        )
        if root_id is None:
            raise MigrationFailure(
                "存在 product_group_id 为空且无法匹配一级分类的商品；"
                "请在源 product_groups 中提供 other 一级分类"
            )
        resolved.add(root_id)
    return resolved


def sql_id_filter(column_sql: str, ids: Iterable[int]) -> str:
    collected = sorted(set(int(value) for value in ids))
    if not collected:
        return "1 = 0"
    return column_sql + " IN (" + ", ".join(str(value) for value in collected) + ")"


def build_product_group_plans(
    connection: pymysql.connections.Connection,
    staging_database: str,
    target_metadata: dict[str, Any],
    source_metadata: dict[str, Any],
) -> list[dict[str, Any]]:
    if PRODUCT_GROUP_SOURCE not in source_metadata["tables"]:
        return []
    group_source_columns = source_metadata["columns"][PRODUCT_GROUP_SOURCE]
    group_source_map = column_map(group_source_columns)
    required_source = {"id", "parent_id", "level", "code"}
    if not required_source.issubset(group_source_map):
        raise MigrationFailure(
            "product_groups 缺少分类映射所需字段："
            + ", ".join(sorted(required_source - set(group_source_map)))
        )
    required_target_columns = {
        "first_product_groups": {"id", "code", "name"},
        "second_product_groups": {"id", "first_product_group_id", "name"},
        "third_product_groups": {"id", "second_product_group_id", "name"},
    }
    missing_target = [
        table
        for table in PRODUCT_GROUP_TARGET_LEVELS
        if table not in target_metadata["tables"]
    ]
    missing_columns = [
        table + "." + column
        for table, required in required_target_columns.items()
        if table in target_metadata["tables"]
        for column in sorted(
            required
            - {
                str(item["column_name"])
                for item in target_metadata["columns"][table]
            }
        )
    ]
    if missing_target or missing_columns:
        details = []
        if missing_target:
            details.append("缺少目标分类表：" + ", ".join(missing_target))
        if missing_columns:
            details.append("缺少目标分类字段：" + ", ".join(missing_columns))
        raise MigrationFailure("商品分类结构无法迁移：" + "；".join(details))

    levels = resolve_product_group_levels(connection, staging_database, source_metadata)
    promoted = list_promoted_product_groups(
        connection, staging_database, source_metadata, levels
    )
    uncategorized_root_ids = list_uncategorized_product_root_ids(
        connection, staging_database, source_metadata, levels
    )
    plans: list[dict[str, Any]] = []

    for target_table, source_level in PRODUCT_GROUP_TARGET_LEVELS.items():
        if target_table not in target_metadata["tables"]:
            continue
        custom_expressions: dict[str, str] = {}
        if target_table == "second_product_groups":
            custom_expressions["first_product_group_id"] = (
                "NULLIF(s." + quote_identifier("parent_id") + ", 0)"
            )
        elif target_table == "third_product_groups":
            custom_expressions["second_product_group_id"] = (
                "NULLIF(s." + quote_identifier("parent_id") + ", 0)"
            )
        level_ids = [
            item_id
            for item_id, item_level in sorted(levels.items())
            if item_level == source_level
        ]
        plans.append(
            build_mapping_plan(
                target_table=target_table,
                source_table=PRODUCT_GROUP_SOURCE,
                target_columns=target_metadata["columns"][target_table],
                source_columns=group_source_columns,
                unique_indexes=target_metadata["unique_indexes"].get(target_table, {}),
                source_filter=sql_id_filter(
                    "s." + quote_identifier("id"), level_ids
                ),
                custom_expressions=custom_expressions,
                plan_kind="product_group_level_" + str(source_level),
            )
        )

    root_ids = sorted(set(promoted[1]) | uncategorized_root_ids)
    level_two_ids = promoted[2]
    if root_ids and "second_product_groups" in target_metadata["tables"]:
        custom = {
            "first_product_group_id": "s." + quote_identifier("id"),
            "legacy_product_group_id": "s." + quote_identifier("id"),
            "slug": "CONCAT('legacy-product-', s." + quote_identifier("id") + ")",
        }
        plans.append(
            build_mapping_plan(
                target_table="second_product_groups",
                source_table=PRODUCT_GROUP_SOURCE,
                target_columns=target_metadata["columns"]["second_product_groups"],
                source_columns=group_source_columns,
                unique_indexes=target_metadata["unique_indexes"].get(
                    "second_product_groups", {}
                ),
                source_filter=sql_id_filter("s." + quote_identifier("id"), root_ids),
                custom_expressions=custom,
                plan_kind="product_group_virtual_second",
            )
        )
    if root_ids and "third_product_groups" in target_metadata["tables"]:
        custom = {
            "second_product_group_id": "s." + quote_identifier("id"),
            "legacy_product_group_id": "s." + quote_identifier("id"),
            "slug": "CONCAT('legacy-product-', s." + quote_identifier("id") + ")",
        }
        plans.append(
            build_mapping_plan(
                target_table="third_product_groups",
                source_table=PRODUCT_GROUP_SOURCE,
                target_columns=target_metadata["columns"]["third_product_groups"],
                source_columns=group_source_columns,
                unique_indexes=target_metadata["unique_indexes"].get(
                    "third_product_groups", {}
                ),
                source_filter=sql_id_filter("s." + quote_identifier("id"), root_ids),
                custom_expressions=custom,
                plan_kind="product_group_virtual_third_from_root",
            )
        )
    if level_two_ids and "third_product_groups" in target_metadata["tables"]:
        custom = {
            "second_product_group_id": "s." + quote_identifier("id"),
            "legacy_product_group_id": "s." + quote_identifier("id"),
            "slug": "CONCAT('legacy-product-', s." + quote_identifier("id") + ")",
        }
        plans.append(
            build_mapping_plan(
                target_table="third_product_groups",
                source_table=PRODUCT_GROUP_SOURCE,
                target_columns=target_metadata["columns"]["third_product_groups"],
                source_columns=group_source_columns,
                unique_indexes=target_metadata["unique_indexes"].get(
                    "third_product_groups", {}
                ),
                source_filter=sql_id_filter(
                    "s." + quote_identifier("id"), level_two_ids
                ),
                custom_expressions=custom,
                plan_kind="product_group_virtual_third_from_second",
            )
        )
    return plans


def build_product_plan(
    target_metadata: dict[str, Any],
    source_metadata: dict[str, Any],
) -> dict[str, Any] | None:
    if (
        "products" not in target_metadata["tables"]
        or "products" not in source_metadata["tables"]
    ):
        return None
    source_map = column_map(source_metadata["columns"]["products"])
    group_map = column_map(source_metadata["columns"].get(PRODUCT_GROUP_SOURCE, []))
    custom_expressions: dict[str, str] = {}
    extra_joins = ""
    if "product_group_id" in source_map and {"id", "parent_id", "level"}.issubset(group_map):
        source_groups = source_reference(PRODUCT_GROUP_SOURCE)
        extra_joins = (
            " LEFT JOIN "
            + source_groups
            + " AS pg ON pg."
            + quote_identifier("id")
            + " = s."
            + quote_identifier("product_group_id")
            + " LEFT JOIN "
            + source_groups
            + " AS pg_parent ON pg_parent."
            + quote_identifier("id")
            + " = pg."
            + quote_identifier("parent_id")
            + " LEFT JOIN "
            + source_groups
            + " AS pg_grandparent ON pg_grandparent."
            + quote_identifier("id")
            + " = pg_parent."
            + quote_identifier("parent_id")
        )
        target_names = {
            str(column["column_name"])
            for column in target_metadata["columns"]["products"]
        }
        fallback_candidates: list[str] = []
        for source_column, alias in (
            ("service_type_code", "service_type_root"),
            ("product_type", "product_type_root"),
        ):
            if source_column not in source_map:
                continue
            fallback_candidates.append(
                "(SELECT "
                + alias
                + "."
                + quote_identifier("id")
                + " FROM "
                + source_groups
                + " AS "
                + alias
                + " WHERE "
                + alias
                + "."
                + quote_identifier("code")
                + " = NULLIF(s."
                + quote_identifier(source_column)
                + ", '') AND ("
                + alias
                + "."
                + quote_identifier("parent_id")
                + " IS NULL OR "
                + alias
                + "."
                + quote_identifier("parent_id")
                + " = 0) LIMIT 1)"
            )
        fallback_candidates.append(
            "(SELECT other_root."
            + quote_identifier("id")
            + " FROM "
            + source_groups
            + " AS other_root WHERE other_root."
            + quote_identifier("code")
            + " = 'other' AND (other_root."
            + quote_identifier("parent_id")
            + " IS NULL OR other_root."
            + quote_identifier("parent_id")
            + " = 0) LIMIT 1)"
        )
        if "product_group_id" in target_names:
            custom_expressions["product_group_id"] = (
                "CASE WHEN s."
                + quote_identifier("product_group_id")
                + " IS NULL OR s."
                + quote_identifier("product_group_id")
                + " = 0 THEN COALESCE("
                + ", ".join(fallback_candidates)
                + ") ELSE s."
                + quote_identifier("product_group_id")
                + " END"
            )
        if "first_product_group_id" in target_names:
            custom_expressions["first_product_group_id"] = (
                "CASE WHEN s."
                + quote_identifier("product_group_id")
                + " IS NULL THEN NULL "
                + "WHEN pg."
                + quote_identifier("level")
                + " = 1 THEN pg."
                + quote_identifier("id")
                + " WHEN pg."
                + quote_identifier("level")
                + " = 2 THEN pg_parent."
                + quote_identifier("id")
                + " WHEN pg."
                + quote_identifier("level")
                + " = 3 THEN pg_grandparent."
                + quote_identifier("id")
                + " ELSE NULL END"
            )
        if "second_product_group_id" in target_names:
            custom_expressions["second_product_group_id"] = (
                "CASE WHEN s."
                + quote_identifier("product_group_id")
                + " IS NULL THEN NULL "
                + "WHEN pg."
                + quote_identifier("level")
                + " IN (1, 2) THEN pg."
                + quote_identifier("id")
                + " WHEN pg."
                + quote_identifier("level")
                + " = 3 THEN pg_parent."
                + quote_identifier("id")
                + " ELSE NULL END"
            )
        if "third_product_group_id" in target_names:
            custom_expressions["third_product_group_id"] = (
                "s." + quote_identifier("product_group_id")
            )
    return build_mapping_plan(
        target_table="products",
        source_table="products",
        target_columns=target_metadata["columns"]["products"],
        source_columns=source_metadata["columns"]["products"],
        unique_indexes=target_metadata["unique_indexes"].get("products", {}),
        custom_expressions=custom_expressions,
        custom_transform_labels={
            "product_group_id": (
                "uncategorized_product_to_service_type_or_product_type_or_other_virtual_third"
            )
        },
        extra_joins=extra_joins,
        plan_kind="products_with_group_hierarchy",
    )


def build_mapping_configuration(
    connection: pymysql.connections.Connection,
    staging_database: str,
    target_metadata: dict[str, Any],
    source_metadata: dict[str, Any],
    source_filters: dict[str, dict[str, Any]] | None = None,
) -> tuple[list[dict[str, Any]], list[str]]:
    plans: list[dict[str, Any]] = []
    source_consumed: set[str] = set()
    source_filters = source_filters or {}

    product_group_plans = build_product_group_plans(
        connection, staging_database, target_metadata, source_metadata
    )
    if product_group_plans:
        plans.extend(product_group_plans)
        source_consumed.add(PRODUCT_GROUP_SOURCE)

    for target_table in target_metadata["tables"]:
        if target_table in PRESERVE_TARGET_TABLES:
            plans.append(
                {
                    "action": "preserve",
                    "target_table": target_table,
                    "reason": "current_schema_migration_history",
                    "fields": [],
                }
            )
            if target_table in source_metadata["tables"]:
                source_consumed.add(target_table)
            continue
        if target_table in PRODUCT_GROUP_TARGET_LEVELS and product_group_plans:
            continue
        if target_table == "products":
            plan = build_product_plan(target_metadata, source_metadata)
            if plan is not None:
                plans.append(plan)
                source_consumed.add("products")
                continue
        if target_table not in source_metadata["tables"]:
            plans.append(
                {
                    "action": (
                        "clear_only"
                        if target_table in ALLOW_CLEAR_TARGET_WITHOUT_SOURCE
                        else "blocked"
                    ),
                    "target_table": target_table,
                    "reason": (
                        "source_table_missing_explicitly_allowed"
                        if target_table in ALLOW_CLEAR_TARGET_WITHOUT_SOURCE
                        else "source_table_missing_without_approved_policy"
                    ),
                    "fields": [],
                }
            )
            continue
        filter_config = source_filters.get(target_table, {})
        plans.append(
            build_mapping_plan(
                target_table=target_table,
                source_table=target_table,
                target_columns=target_metadata["columns"][target_table],
                source_columns=source_metadata["columns"][target_table],
                unique_indexes=target_metadata["unique_indexes"].get(target_table, {}),
                source_filter=str(filter_config.get("sql", "")),
                source_filter_is_data_quality=bool(filter_config),
                source_filter_reason=str(filter_config.get("reason", "")),
            )
        )
        source_consumed.add(target_table)

    source_only = sorted(set(source_metadata["tables"]) - source_consumed)
    return plans, source_only


def matrix_markdown(
    config: dict[str, Any],
    target_metadata: dict[str, Any],
    source_metadata: dict[str, Any],
) -> str:
    lines = [
        "# 字段差异矩阵表",
        "",
        f"- 目标库：{TARGET_DATABASE}",
        f"- 中转库：{config['staging_database']}",
        "- 说明：类型不一致的共有字段使用显式 CAST 或 CONVERT；文本字段按目标最大容量截断并记录预检告警。",
        "",
    ]
    plans_by_target: dict[str, list[dict[str, Any]]] = {}
    for plan in config["plans"]:
        plans_by_target.setdefault(plan["target_table"], []).append(plan)

    for table in target_metadata["tables"]:
        lines.extend(["## " + table, ""])
        plans = plans_by_target.get(table, [])
        if not plans:
            lines.extend(["- 未生成映射计划。", ""])
            continue
        for plan in plans:
            if plan["action"] == "preserve":
                lines.extend(["- 保留本地 migrations 元数据，不使用历史 dump 覆盖。", ""])
                continue
            if plan["action"] == "clear_only":
                lines.extend(["- 本地有、源无：清空后保持空表。", ""])
                continue
            if plan["action"] == "blocked":
                lines.extend(
                    [
                        "- 本地有、源无：未在批准的清空策略中，迁移将在清空数据前中止。",
                        "",
                    ]
                )
                continue
            source_label = str(plan["source_table"])
            if plan.get("kind") != "same_name":
                lines.append("- 映射来源：" + source_label + "；规则：" + str(plan.get("kind")))
                lines.append("")
            if plan.get("source_filter_is_data_quality"):
                lines.append(
                    "- 数据质量过滤："
                    + str(plan.get("source_filter_reason") or "仅保留存在父级的有效子记录")
                )
                lines.append("")
            lines.extend(
                [
                    "| 目标字段 | 源字段 | 目标类型 | 源类型 | 分类 | 转换或填充值 |",
                    "| --- | --- | --- | --- | --- | --- |",
                ]
            )
            for field in plan["fields"]:
                transforms = ", ".join(field.get("transforms", [])) or "直接映射"
                lines.append(
                    "| "
                    + str(field["target_column"])
                    + " | "
                    + str(field.get("source_column") or "—")
                    + " | "
                    + str(field.get("target_type") or "—")
                    + " | "
                    + str(field.get("source_type") or "—")
                    + " | "
                    + str(field["classification"])
                    + " | "
                    + transforms
                    + " |"
                )
            lines.append("")

        source_columns = source_metadata["columns"].get(table, [])
        target_names = {
            str(column["column_name"])
            for column in target_metadata["columns"].get(table, [])
        }
        source_only = [
            str(column["column_name"])
            for column in source_columns
            if str(column["column_name"]) not in target_names
        ]
        if source_only:
            lines.append("- 源有、本地无（丢弃）： " + ", ".join(source_only))
            lines.append("")

    if config["source_only_tables"]:
        lines.extend(
            [
                "## 源表在本地不存在",
                "",
                "- 以下源表按任务边界跳过，不创建本地表： "
                + ", ".join(config["source_only_tables"]),
                "",
            ]
        )
    foreign_key_rules = config.get("post_migration_foreign_key_rules", [])
    if foreign_key_rules:
        lines.extend(
            [
                "## 后置外键修复规则",
                "",
                "| 约束 | 子表字段 | 父表 | 中转库候选孤儿数 | 修复动作 |",
                "| --- | --- | --- | ---: | --- |",
            ]
        )
        for rule in foreign_key_rules:
            lines.append(
                "| "
                + str(rule["constraint"])
                + " | "
                + ", ".join(rule["columns"])
                + " | "
                + str(rule["referenced_table"])
                + " | "
                + str(rule["source_orphan_candidates"])
                + " | "
                + str(rule["action"])
                + " |"
            )
        lines.append("")
    return "\n".join(lines) + "\n"


def mapping_sql_text(config: dict[str, Any]) -> str:
    lines = [
        "-- Generated INSERT INTO SELECT mapping script.",
        "-- Run through the migration tool so target clearing, timing, rollback",
        "-- and structural checks remain active.",
        "SET FOREIGN_KEY_CHECKS=0;",
        "",
    ]
    for plan in config["plans"]:
        if plan["action"] != "map":
            continue
        lines.append("-- " + plan["target_table"] + " <- " + plan["source_table"])
        lines.append(
            plan["insert_sql_template"]
            .replace("{{TARGET_DB}}", TARGET_DATABASE)
            .replace("{{STAGING_DB}}", config["staging_database"])
        )
        lines.append("")
    lines.append("SET FOREIGN_KEY_CHECKS=1;")
    return "\n".join(lines) + "\n"


def state_paths(output_dir: Path, run_id: str) -> dict[str, Path]:
    run_id = validate_run_id(run_id)
    prefix = "idc_" + run_id
    return {
        "state": output_dir / (prefix + "_heterogeneous_migration_state.json"),
        "log": output_dir / (prefix + "_完整操作日志及排错记录.md"),
        "backup_full": output_dir / ("idc_backup_rollback_" + run_id + ".sql"),
        "backup_data": output_dir / ("idc_backup_rollback_" + run_id + "_data_only.sql"),
        "schema_before": output_dir / (prefix + "_schema_before.sql"),
        "schema_after": output_dir / (prefix + "_schema_after.sql"),
        "schema_diff": output_dir / (prefix + "_schema_diff.md"),
        "matrix": output_dir / (prefix + "_字段差异矩阵表.md"),
        "mapping": output_dir / (prefix + "_字段映射配置.json"),
        "mapping_sql": output_dir / (prefix + "_映射_INSERT_SELECT.sql"),
        "failure": output_dir / (prefix + "_失败分析报告.md"),
        # It contains source data, so keep the filtered staging dump outside
        # the repository and remove it as soon as the restore completes.
        "staging_dump": Path(tempfile.gettempdir()) / (prefix + "_staging.sql"),
    }


def save_state(path: Path, state: dict[str, Any]) -> None:
    path.write_text(
        json.dumps(state, ensure_ascii=False, indent=2, default=json_default) + "\n",
        encoding="utf-8",
    )


def assert_managed_artifact_path(value: Any, expected: Path, context: str) -> None:
    candidate = Path(str(value))
    if candidate.is_symlink():
        raise MigrationFailure(context + " 不允许是符号链接。")
    if candidate.resolve(strict=False) != expected.resolve(strict=False):
        raise MigrationFailure(context + " 超出当前批次的受管产物路径。")


def validate_state_identity(state: dict[str, Any], paths: dict[str, Path]) -> None:
    if not isinstance(state, dict):
        raise MigrationFailure("批次状态必须是 JSON 对象。")
    if state.get("format") != "idc-heterogeneous-migration-state/v1":
        raise MigrationFailure("批次状态格式不受支持。")
    run_id = validate_run_id(str(state.get("run_id", "")))
    expected_state = paths["state"]
    if expected_state.name != ("idc_" + run_id + "_heterogeneous_migration_state.json"):
        raise MigrationFailure("批次状态的 run_id 与当前产物路径不匹配。")
    if str(state.get("target_database", "")) != TARGET_DATABASE:
        raise MigrationFailure("批次状态的目标库不是 idc，拒绝继续执行。")
    staging_database = str(state.get("staging_database", ""))
    assert_staging_database(staging_database, run_id=run_id)
    artifact_paths = state.get("paths")
    if not isinstance(artifact_paths, dict):
        raise MigrationFailure("批次状态缺少受管产物路径。")
    for name, expected in paths.items():
        if name not in artifact_paths:
            raise MigrationFailure("批次状态缺少受管产物：" + name)
        assert_managed_artifact_path(artifact_paths[name], expected, "批次状态产物 " + name)
    if "mapping_config" in state:
        assert_managed_artifact_path(
            state["mapping_config"], paths["mapping"], "字段映射配置路径"
        )


def load_state(path: Path, paths: dict[str, Path]) -> dict[str, Any]:
    assert_managed_artifact_path(path, paths["state"], "批次状态文件")
    if not path.is_file():
        raise MigrationFailure(f"未找到批次状态文件：{path}")
    state = json.loads(path.read_text(encoding="utf-8"))
    validate_state_identity(state, paths)
    return state


def prepare(
    config: DbConfig,
    args: argparse.Namespace,
    paths: dict[str, Path],
    reporter: Reporter,
) -> dict[str, Any]:
    if paths["state"].exists():
        state = load_state(paths["state"], paths)
        staging_database = str(state["staging_database"])
        with managed_connection(config, None, autocommit=True) as server_connection:
            charset_rows = query_rows(
                server_connection,
                """
                SELECT DEFAULT_CHARACTER_SET_NAME
                FROM information_schema.SCHEMATA
                WHERE SCHEMA_NAME = %s
                """,
                (staging_database,),
            )
        with managed_connection(
            config, staging_database, autocommit=True
        ) as staging_connection:
            table_count = len(list_base_tables(staging_connection, staging_database))
        if (
            len(charset_rows) != 1
            or str(charset_rows[0]["DEFAULT_CHARACTER_SET_NAME"]).lower() != "utf8mb4"
            or table_count != 58
        ):
            raise MigrationFailure("已有批次的中转库缺失、字符集异常或基础表数量异常。")
        reporter.line(
            "已存在该批次状态文件；已复核中转库 utf8mb4 和 58 张基础表，跳过重复阶段一准备。"
        )
        reporter.bullet(
            "已核验完整回滚备份 SHA-256："
            + str(state.get("backup_full_sha256", "未记录"))
        )
        reporter.bullet(
            "已核验 DML 回滚备份 SHA-256："
            + str(state.get("backup_data_sha256", "未记录"))
        )
        return state

    dump_path = assert_path_outside_repository(Path(args.dump), "源 SQL dump")
    if not dump_path.is_file() or dump_path.stat().st_size == 0:
        raise MigrationFailure(f"源 SQL dump 不存在或为空：{dump_path}")
    source_dump_sha256 = hash_file(dump_path)
    prohibited = scan_sql_dump_for_disallowed_objects(dump_path)
    if prohibited:
        raise MigrationFailure(
            "源 dump 包含禁止还原的对象或数据库切换语句：" + ", ".join(prohibited)
        )
    target_access = scan_sql_dump_for_target_database_access(dump_path)
    if target_access:
        raise MigrationFailure(
            "源 dump 试图显式访问不可变目标库 idc：" + ", ".join(target_access)
        )
    sanitized = sanitize_dump_for_staging(dump_path, paths["staging_dump"])
    if sanitized["skipped_view_sections"] != 6:
        raise MigrationFailure(
            "源 dump 的视图区段数量异常：预期过滤 6 段，实际 "
            + str(sanitized["skipped_view_sections"])
            + " 段；拒绝恢复。"
        )

    reporter.section("阶段一：环境准备与备份")
    reporter.line("开始完整备份当前 idc（结构和数据）。")
    dump_database(config, paths["backup_full"], data_only=False)
    dump_database(
        config,
        paths["backup_data"],
        data_only=True,
        exclude_tables=PRESERVE_TARGET_TABLES,
    )
    backup_full_sha256 = hash_file(paths["backup_full"])
    backup_data_sha256 = hash_file(paths["backup_data"])
    reporter.bullet("完整备份：" + str(paths["backup_full"]))
    reporter.bullet(
        "DML 回滚伴随备份（不含保留的 migrations 元数据）："
        + str(paths["backup_data"])
    )
    reporter.bullet("源 dump SHA-256：" + source_dump_sha256)
    reporter.bullet("完整备份 SHA-256：" + backup_full_sha256)
    reporter.bullet("DML 回滚备份 SHA-256：" + backup_data_sha256)

    with managed_connection(config, TARGET_DATABASE, autocommit=True) as target_connection:
        before = schema_snapshot(
            target_connection, TARGET_DATABASE, paths["schema_before"]
        )
        target_tables = list_base_tables(target_connection, TARGET_DATABASE)
    if not target_tables:
        raise MigrationFailure("本地 idc 没有数据表，无法执行迁移")
    reporter.line(f"已记录迁移前结构指纹：{len(target_tables)} 张表。")

    expected_staging_database = staging_database_for_run(args.run_id)
    requested_staging_database = args.staging_db.strip()
    if requested_staging_database and requested_staging_database != expected_staging_database:
        raise MigrationFailure(
            "--staging-db 只能使用当前批次的受管中转库："
            + expected_staging_database
        )
    staging_database = assert_staging_database(
        expected_staging_database, run_id=args.run_id
    )

    with managed_connection(config, None, autocommit=True) as server_connection:
        existing = query_scalar(
            server_connection,
            "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = %s",
            (staging_database,),
        )
        if int(existing or 0) != 0:
            raise MigrationFailure(
                f"中转库 {staging_database} 已存在；为避免影响已有库，本批次拒绝覆盖。"
            )
        execute(
            server_connection,
            "CREATE DATABASE "
            + quote_identifier(staging_database)
            + " CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
        )
        staging_charset = query_rows(
            server_connection,
            """
            SELECT DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME
            FROM information_schema.SCHEMATA
            WHERE SCHEMA_NAME = %s
            """,
            (staging_database,),
        )
    if (
        len(staging_charset) != 1
        or str(staging_charset[0]["DEFAULT_CHARACTER_SET_NAME"]).lower() != "utf8mb4"
    ):
        raise MigrationFailure("中转库字符集不是 utf8mb4，拒绝继续。")
    reporter.line("已创建独立 utf8mb4 中转库：" + staging_database)
    reporter.line(
        "已过滤 6 个禁止的源视图区段，开始将全部基础表结构和数据还原至中转库。"
    )
    try:
        restore_staging_dump(config, paths["staging_dump"], staging_database)
    finally:
        if paths["staging_dump"].exists():
            paths["staging_dump"].unlink()

    with managed_connection(config, staging_database, autocommit=True) as staging_connection:
        staging_tables = list_base_tables(staging_connection, staging_database)
    if not staging_tables:
        raise MigrationFailure("源 dump 还原后中转库没有数据表")
    if len(staging_tables) != 58:
        raise MigrationFailure(
            "中转库基础表数量异常：预期 58，实际 " + str(len(staging_tables))
        )
    reporter.line(f"中转库完整还原完成：{len(staging_tables)} 张基础表。")

    state = {
        "format": "idc-heterogeneous-migration-state/v1",
        "run_id": args.run_id,
        "created_at": now_text(),
        "target_database": TARGET_DATABASE,
        "staging_database": staging_database,
        "source_dump": str(dump_path),
        "source_dump_sha256": source_dump_sha256,
        "backup_full_sha256": backup_full_sha256,
        "backup_data_sha256": backup_data_sha256,
        "staging_restore": {
            "filtered_view_sections": sanitized["skipped_view_sections"],
            "filtered_dump_sha256": sanitized["sha256"],
            "temporary_dump_removed": True,
        },
        "paths": {name: str(path) for name, path in paths.items()},
        "schema_before": before,
        "prepared": True,
        "analyzed": False,
        "migration_started": False,
        "migration_complete": False,
        "rolled_back": False,
    }
    save_state(paths["state"], state)
    return state


def analyze(
    config: DbConfig,
    state: dict[str, Any],
    paths: dict[str, Path],
    reporter: Reporter,
) -> dict[str, Any]:
    reporter.section("阶段二：结构差异分析与映射配置")
    staging_database = str(state["staging_database"])
    with managed_connection(config, TARGET_DATABASE, autocommit=True) as target_connection:
        target_metadata = fetch_schema_metadata(target_connection, TARGET_DATABASE)
    with managed_connection(config, staging_database, autocommit=True) as staging_connection:
        source_metadata = fetch_schema_metadata(staging_connection, staging_database)
        foreign_key_repair_rules = detect_source_foreign_key_repair_rules(
            staging_connection,
            staging_database,
            target_metadata,
            source_metadata,
        )
        plans, source_only = build_mapping_configuration(
            staging_connection,
            staging_database,
            target_metadata,
            source_metadata,
            build_stale_orphan_source_filters(foreign_key_repair_rules),
        )

    mapping_config = {
        "format": "idc-heterogeneous-field-mapping/v1",
        "generated_at": now_text(),
        "target_database": TARGET_DATABASE,
        "staging_database": staging_database,
        "source_dump": state["source_dump"],
        "source_dump_sha256": state["source_dump_sha256"],
        "target_schema_fingerprint_before": state["schema_before"]["normalized_hashes"],
        "policies": {
            "target_ddl": "forbidden",
            "zero_dates": "NULL for nullable temporal fields; current temporal value for required fields",
            "source_latin1": "CONVERT using utf8mb4",
            "text_overflow": "safe truncation plus warning count",
            "unique_duplicates": "ROW_NUMBER by available target unique keys; latest timestamp retained",
            "foreign_key_orphans": (
                "nullable child FK is set to NULL when its parent row is absent; "
                "non-nullable orphan blocks migration"
            ),
            "migrations_table": "preserved as current final-schema metadata",
        },
        "plans": plans,
        "source_only_tables": source_only,
        "post_migration_foreign_key_rules": foreign_key_repair_rules,
    }
    paths["mapping"].write_text(
        json.dumps(mapping_config, ensure_ascii=False, indent=2, default=json_default)
        + "\n",
        encoding="utf-8",
    )
    paths["matrix"].write_text(
        matrix_markdown(mapping_config, target_metadata, source_metadata),
        encoding="utf-8",
    )
    paths["mapping_sql"].write_text(
        mapping_sql_text(mapping_config),
        encoding="utf-8",
    )
    state["analyzed"] = True
    state["mapping_config"] = str(paths["mapping"])
    state["mapping_sha256"] = hash_file(paths["mapping"])
    backup_full = Path(str(state["paths"]["backup_full"]))
    backup_data = Path(str(state["paths"]["backup_data"]))
    if not backup_full.is_file() or not backup_data.is_file():
        raise MigrationFailure("阶段一备份文件缺失，拒绝生成可执行映射。")
    state["backup_full_sha256"] = hash_file(backup_full)
    state["backup_data_sha256"] = hash_file(backup_data)
    state["matrix"] = str(paths["matrix"])
    state["mapping_sql"] = str(paths["mapping_sql"])
    save_state(paths["state"], state)

    mapped = sum(1 for item in plans if item["action"] == "map")
    clear_only = sum(1 for item in plans if item["action"] == "clear_only")
    reporter.line(
        f"字段矩阵与映射配置已生成：{mapped} 个 INSERT SELECT 计划，"
        f"{clear_only} 个本地有源无的清空计划。"
    )
    reporter.bullet("字段差异矩阵：" + str(paths["matrix"]))
    reporter.bullet("可复用 JSON 配置：" + str(paths["mapping"]))
    reporter.bullet("INSERT SELECT 映射脚本：" + str(paths["mapping_sql"]))
    if source_only:
        reporter.line("已按边界跳过本地不存在的源表：" + ", ".join(source_only))
    if foreign_key_repair_rules:
        reporter.line(
            "已固化 "
            + str(len(foreign_key_repair_rules))
            + " 条中转库外键孤儿修复规则。"
        )
    blocked = [plan["target_table"] for plan in plans if plan["action"] == "blocked"]
    blocked_fields = [
        plan["target_table"] + "." + field["target_column"]
        for plan in plans
        if plan["action"] == "map"
        for field in plan["fields"]
        if field["classification"] == "target_only_required_no_default_blocked"
    ]
    if blocked:
        raise MigrationFailure(
            "以下本地表在源中缺失且未配置安全清空策略，已在阶段二中止："
            + ", ".join(blocked)
        )
    if blocked_fields:
        raise MigrationFailure(
            "以下目标必填字段缺少源字段、默认值和显式映射，已在阶段二中止："
            + ", ".join(blocked_fields)
        )
    blocked_foreign_keys = [
        rule
        for rule in foreign_key_repair_rules
        if rule["action"] == "blocked_nonnullable_orphan"
    ]
    if blocked_foreign_keys:
        raise MigrationFailure(
            "以下中转库非空外键孤儿无法安全修复或过滤，已在阶段二中止："
            + ", ".join(
                str(rule["table"]) + "." + str(rule["constraint"])
                for rule in blocked_foreign_keys
            )
        )
    return state


def load_mapping_from_state(
    state: dict[str, Any], paths: dict[str, Path]
) -> dict[str, Any]:
    path = paths["mapping"]
    assert_managed_artifact_path(
        state.get("mapping_config", ""), path, "字段映射配置路径"
    )
    if path.is_symlink():
        raise MigrationFailure("字段映射配置不允许是符号链接。")
    if not path.is_file():
        raise MigrationFailure("尚未生成映射配置；请先执行阶段二")
    expected_hash = str(state.get("mapping_sha256", ""))
    if not expected_hash or hash_file(path) != expected_hash:
        raise MigrationFailure("字段映射配置完整性校验失败，拒绝执行。")
    mapping = json.loads(path.read_text(encoding="utf-8"))
    validate_mapping_configuration(mapping, state)
    return mapping


def assert_safe_sql_template(
    sql: str,
    *,
    expected_prefix: str,
    context: str,
    allow_trailing_semicolon: bool,
) -> None:
    raw = str(sql).strip()
    code = _sql_code_without_string_literals(raw)
    if allow_trailing_semicolon:
        if not code.rstrip().endswith(";") or code.count(";") != 1:
            raise MigrationFailure(context + " 不是单条 SQL 语句。")
    elif ";" in code:
        raise MigrationFailure(context + " 不允许包含分号。")
    normalized = normalize_single_sql_statement(raw, context)
    if not normalized.upper().startswith(expected_prefix.upper()):
        raise MigrationFailure(context + " 的 SQL 动词或目标表不在白名单中。")
    if len(normalized) > len(expected_prefix) and normalized[len(expected_prefix)] not in " \t\r\n(":
        raise MigrationFailure(context + " 的 SQL 前缀边界非法。")
    assert_no_unsafe_sql(normalized, context)
    if re.search(
        r"\b(?:DELETE|LOCK|REPLACE|SET|UNLOCK|UPDATE)\b",
        _sql_code_without_string_literals(normalized),
        re.IGNORECASE,
    ):
        raise MigrationFailure(context + " 包含禁止的 SQL 动词。")
    assert_schema_references(
        normalized,
        {TARGET_DATABASE, "information_schema"},
        context,
        allow_managed_staging=True,
    )


def validate_mapping_configuration(
    mapping: dict[str, Any],
    state: dict[str, Any],
) -> None:
    if str(mapping.get("target_database", "")) != TARGET_DATABASE:
        raise MigrationFailure("映射配置的目标库不是 idc。")
    staging_database = str(mapping.get("staging_database", ""))
    if staging_database != str(state["staging_database"]):
        raise MigrationFailure("映射配置与批次状态的中转库不一致。")
    allowed_tables = set(state.get("schema_before", {}).get("tables", []))
    allowed_actions = {"map", "preserve", "clear_only", "blocked"}
    plans = mapping.get("plans")
    if not isinstance(plans, list):
        raise MigrationFailure("映射配置缺少 plans 数组。")
    for plan in plans:
        if not isinstance(plan, dict):
            raise MigrationFailure("映射配置包含非法计划对象。")
        target_table = str(plan.get("target_table", ""))
        action = str(plan.get("action", ""))
        if target_table not in allowed_tables or action not in allowed_actions:
            raise MigrationFailure("映射配置包含未批准的目标表或动作。")
        if action != "map":
            continue
        source_table = str(plan.get("source_table", ""))
        if not re.fullmatch(r"[A-Za-z0-9_]{1,64}", source_table):
            raise MigrationFailure("映射配置包含非法源表名。")
        insert_sql = render_template(
            str(plan.get("insert_sql_template", "")),
            TARGET_DATABASE,
            staging_database,
        )
        expected_insert_prefix = (
            "INSERT INTO "
            + quote_identifier(TARGET_DATABASE)
            + "."
            + quote_identifier(target_table)
        )
        assert_safe_sql_template(
            insert_sql,
            expected_prefix=expected_insert_prefix,
            context=target_table + " INSERT 映射",
            allow_trailing_semicolon=True,
        )
        source_reference_sql = (
            quote_identifier(staging_database) + "." + quote_identifier(source_table)
        )
        if source_reference_sql not in insert_sql:
            raise MigrationFailure(target_table + " INSERT 映射未引用批准的中转源表。")
        for name in (
            "raw_count_sql_template",
            "filtered_source_count_sql_template",
            "expected_count_sql_template",
        ):
            sql = render_template(str(plan.get(name, "")), TARGET_DATABASE, staging_database)
            assert_safe_sql_template(
                sql,
                expected_prefix="SELECT",
                context=target_table + " " + name,
                allow_trailing_semicolon=False,
            )


def preflight_mapping(
    config: DbConfig,
    mapping: dict[str, Any],
    reporter: Reporter,
) -> dict[str, dict[str, Any]]:
    reporter.section("阶段三预检：INSERT SELECT、截断和唯一键")
    results: dict[str, dict[str, Any]] = {}
    staging_database = str(mapping["staging_database"])
    with managed_connection(config, staging_database, autocommit=True) as connection:
        for plan in mapping["plans"]:
            if plan["action"] != "map":
                continue
            table_key = plan["target_table"] + ":" + plan.get("kind", "same_name")
            raw_sql = render_template(
                plan["raw_count_sql_template"], TARGET_DATABASE, staging_database
            )
            filtered_sql = render_template(
                plan["filtered_source_count_sql_template"],
                TARGET_DATABASE,
                staging_database,
            )
            expected_sql = render_template(
                plan["expected_count_sql_template"], TARGET_DATABASE, staging_database
            )
            try:
                raw_count = int(query_scalar(connection, raw_sql) or 0)
                filtered_count = int(query_scalar(connection, filtered_sql) or 0)
                expected_count = int(query_scalar(connection, expected_sql) or 0)
            except Exception as exc:
                raise MigrationFailure(
                    f"预检映射失败：{plan['target_table']} / {plan.get('kind', '')}：{exc}"
                ) from exc

            warnings: list[dict[str, Any]] = []
            for field in plan["fields"]:
                for rule in field.get("warning_rules", []):
                    source_sql = str(rule["source_sql"])
                    if rule["kind"] == "text_overflow_truncated":
                        warning_sql = (
                            "SELECT COUNT(*) FROM "
                            + source_reference(plan["source_table"])
                            + " AS s WHERE "
                            + source_sql
                            + " IS NOT NULL AND OCTET_LENGTH(CONVERT("
                            + source_sql
                            + " USING utf8mb4)) > "
                            + str(rule["maximum_bytes"])
                        )
                    elif rule["kind"] == "invalid_zero_date_repaired":
                        warning_sql = (
                            "SELECT COUNT(*) FROM "
                            + source_reference(plan["source_table"])
                            + " AS s WHERE "
                            + source_sql
                            + " IS NOT NULL AND CAST("
                            + source_sql
                            + " AS CHAR) IN "
                            + "('0000-00-00', '0000-00-00 00:00:00', '00:00:00')"
                        )
                    else:
                        continue
                    if plan.get("source_filter"):
                        warning_sql += " AND (" + plan["source_filter"] + ")"
                    count = int(
                        query_scalar(
                            connection,
                            render_template(
                                warning_sql, TARGET_DATABASE, staging_database
                            ),
                        )
                        or 0
                    )
                    if count:
                        warnings.append(
                            {
                                "field": field["target_column"],
                                "kind": rule["kind"],
                                "count": count,
                            }
                        )
            results[table_key] = {
                "raw_source_rows": raw_count,
                "filtered_source_rows": filtered_count,
                "expected_insert_rows": expected_count,
                "data_quality_filtered_rows": raw_count - filtered_count,
                "deduplicated_rows": filtered_count - expected_count,
                "warnings": warnings,
            }
            note = (
                f"{plan['target_table']} ({plan.get('kind', 'same_name')})："
                f"源 {raw_count}，计划写入 {expected_count}"
            )
            if raw_count != filtered_count:
                note += f"，数据质量过滤 {raw_count - filtered_count}"
            if filtered_count != expected_count:
                note += f"，唯一键去重 {filtered_count - expected_count}"
            reporter.line(note)
            for warning in warnings:
                reporter.line(
                    "警告："
                    + plan["target_table"]
                    + "."
                    + warning["field"]
                    + " "
                    + warning["kind"]
                    + "，影响 "
                    + str(warning["count"])
                    + " 行。"
                )
    return results


def inbound_foreign_key_tables(target_metadata: dict[str, Any]) -> set[str]:
    return {str(item["referenced_table"]) for item in target_metadata["foreign_keys"]}


def clear_target_data(
    connection: pymysql.connections.Connection,
    mapping: dict[str, Any],
    target_metadata: dict[str, Any],
    reporter: Reporter,
) -> dict[str, int]:
    reporter.section("阶段三：清空目标数据")
    inbound = inbound_foreign_key_tables(target_metadata)
    cleared: dict[str, int] = {}
    execute(connection, "SET FOREIGN_KEY_CHECKS=0")
    connection.commit()
    for table in target_metadata["tables"]:
        plan_items = [
            plan for plan in mapping["plans"] if plan["target_table"] == table
        ]
        if plan_items and all(plan["action"] == "preserve" for plan in plan_items):
            reporter.line(table + "：保留当前迁移元数据。")
            continue
        affected = int(
            query_scalar(
                connection, "SELECT COUNT(*) FROM " + quote_identifier(table)
            )
            or 0
        )
        # TRUNCATE is DDL in MySQL.  The task's stronger target-schema redline
        # wins, so every target table is cleared with recoverable DML DELETE.
        execute(connection, "DELETE FROM " + quote_identifier(table))
        connection.commit()
        method = (
            "DELETE（存在入站外键）"
            if table in inbound
            else "DELETE（目标结构保护：不使用 TRUNCATE DDL）"
        )
        cleared[table] = affected
        reporter.line(f"{table}：{method}，影响 {affected} 行。")
    return cleared


def execute_mapping_plans(
    connection: pymysql.connections.Connection,
    mapping: dict[str, Any],
    preflight: dict[str, dict[str, Any]],
    reporter: Reporter,
) -> tuple[dict[str, dict[str, Any]], list[dict[str, Any]]]:
    reporter.section("阶段三：逐表 INSERT INTO SELECT 执行")
    staging_database = str(mapping["staging_database"])
    results: dict[str, dict[str, Any]] = {}
    errors: list[dict[str, Any]] = []
    consecutive_mapping_errors = 0
    for plan in mapping["plans"]:
        if plan["action"] != "map":
            continue
        table_key = plan["target_table"] + ":" + plan.get("kind", "same_name")
        sql = render_template(
            plan["insert_sql_template"], TARGET_DATABASE, staging_database
        )
        expected = int(preflight[table_key]["expected_insert_rows"])
        started = time.perf_counter()
        try:
            connection.begin()
            affected = execute(connection, sql)
            if affected != expected:
                raise MigrationFailure(
                    f"{plan['target_table']} INSERT 影响行数不一致："
                    f"预期 {expected}，实际 {affected}"
                )
            connection.commit()
            duration = time.perf_counter() - started
            target_count = int(
                query_scalar(
                    connection,
                    "SELECT COUNT(*) FROM " + quote_identifier(plan["target_table"]),
                )
                or 0
            )
            result = {
                "status": "ok",
                "affected_rows": affected,
                "duration_seconds": round(duration, 3),
                "expected_rows": expected,
                "target_table_rows": target_count,
            }
            results[table_key] = result
            reporter.line(
                f"{plan['target_table']} ({plan.get('kind', 'same_name')})："
                f"INSERT 影响 {affected} 行，耗时 {duration:.3f}s，"
                f"目标表当前 {target_count} 行。"
            )
            consecutive_mapping_errors = 0
        except Exception as exc:
            connection.rollback()
            duration = time.perf_counter() - started
            consecutive_mapping_errors += 1
            error = {
                "target_table": plan["target_table"],
                "kind": plan.get("kind", "same_name"),
                "error": str(exc),
                "duration_seconds": round(duration, 3),
                "consecutive_mapping_errors": consecutive_mapping_errors,
            }
            errors.append(error)
            results[table_key] = {"status": "failed", **error}
            reporter.line(
                f"异常：{plan['target_table']} 映射已中止并回滚该表事务；"
                f"连续映射异常 {consecutive_mapping_errors} 张。错误：{exc}"
            )
            if consecutive_mapping_errors >= 3:
                raise MigrationFailure(
                    "连续 3 张表出现未预期字段映射错误，按规则立即中止并触发回滚。"
                ) from exc
    return results, errors


def auto_increment_checks(
    connection: pymysql.connections.Connection,
    target_metadata: dict[str, Any],
) -> list[dict[str, Any]]:
    checks: list[dict[str, Any]] = []
    for table in target_metadata["tables"]:
        for column in target_metadata["columns"][table]:
            if not is_auto_increment(column):
                continue
            name = str(column["column_name"])
            maximum = query_scalar(
                connection,
                "SELECT MAX(" + quote_identifier(name) + ") FROM " + quote_identifier(table),
            )
            next_value = query_scalar(
                connection,
                """
                SELECT AUTO_INCREMENT
                FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s
                """,
                (TARGET_DATABASE, table),
            )
            max_int = int(maximum) if maximum is not None else 0
            next_int = int(next_value) if next_value is not None else 1
            checks.append(
                {
                    "table": table,
                    "column": name,
                    "max_id": max_int,
                    "next_auto_increment": next_int,
                    "aligned": next_int > max_int,
                }
            )
    return checks


def foreign_key_health(
    connection: pymysql.connections.Connection,
    target_metadata: dict[str, Any],
) -> list[dict[str, Any]]:
    results: list[dict[str, Any]] = []
    for foreign_key in target_metadata["foreign_keys"]:
        child = str(foreign_key["table"])
        parent = str(foreign_key["referenced_table"])
        pairs = foreign_key["pairs"]
        joins = " AND ".join(
            "c."
            + quote_identifier(pair["column"])
            + " = p."
            + quote_identifier(pair["referenced_column"])
            for pair in pairs
        )
        child_present = " AND ".join(
            "c." + quote_identifier(pair["column"]) + " IS NOT NULL"
            for pair in pairs
        )
        parent_missing = "p." + quote_identifier(pairs[0]["referenced_column"]) + " IS NULL"
        sql = (
            "SELECT COUNT(*) FROM "
            + quote_identifier(child)
            + " AS c LEFT JOIN "
            + quote_identifier(parent)
            + " AS p ON "
            + joins
            + " WHERE "
            + child_present
            + " AND "
            + parent_missing
        )
        count = int(query_scalar(connection, sql) or 0)
        results.append(
            {
                "constraint": foreign_key["name"],
                "table": child,
                "referenced_table": parent,
                "orphan_rows": count,
            }
        )
    return results


def detect_source_foreign_key_repair_rules(
    connection: pymysql.connections.Connection,
    staging_database: str,
    target_metadata: dict[str, Any],
    source_metadata: dict[str, Any],
) -> list[dict[str, Any]]:
    rules: list[dict[str, Any]] = []
    source_tables = set(source_metadata["tables"])
    for foreign_key in target_metadata["foreign_keys"]:
        child = str(foreign_key["table"])
        parent = str(foreign_key["referenced_table"])
        pairs = foreign_key["pairs"]
        if child not in source_tables or parent not in source_tables:
            continue
        source_child_columns = column_map(source_metadata["columns"][child])
        source_parent_columns = column_map(source_metadata["columns"][parent])
        if any(
            str(pair["column"]) not in source_child_columns
            or str(pair["referenced_column"]) not in source_parent_columns
            for pair in pairs
        ):
            continue
        joins = " AND ".join(
            "c."
            + quote_identifier(pair["column"])
            + " = p."
            + quote_identifier(pair["referenced_column"])
            for pair in pairs
        )
        child_present = " AND ".join(
            "c." + quote_identifier(pair["column"]) + " IS NOT NULL"
            for pair in pairs
        )
        parent_missing = "p." + quote_identifier(pairs[0]["referenced_column"]) + " IS NULL"
        candidate_count = int(
            query_scalar(
                connection,
                "SELECT COUNT(*) FROM "
                + quote_identifier(staging_database)
                + "."
                + quote_identifier(child)
                + " AS c LEFT JOIN "
                + quote_identifier(staging_database)
                + "."
                + quote_identifier(parent)
                + " AS p ON "
                + joins
                + " WHERE "
                + child_present
                + " AND "
                + parent_missing,
            )
            or 0
        )
        if candidate_count == 0:
            continue
        target_child_columns = column_map(target_metadata["columns"][child])
        nullable = all(
            bool(target_child_columns[str(pair["column"])]["is_nullable"])
            for pair in pairs
        )
        rule = {
            "constraint": foreign_key["name"],
            "table": child,
            "referenced_table": parent,
            "columns": [str(pair["column"]) for pair in pairs],
            "referenced_columns": [
                str(pair["referenced_column"]) for pair in pairs
            ],
            "source_orphan_candidates": candidate_count,
            "action": (
                "set_null_when_parent_missing"
                if nullable
                else (
                    "exclude_stale_child_row"
                    if str(foreign_key["name"])
                    in ALLOW_EXCLUDE_STALE_NONNULLABLE_FK_ROWS
                    else "blocked_nonnullable_orphan"
                )
            ),
        }
        if rule["action"] == "exclude_stale_child_row":
            rule["reason"] = ALLOW_EXCLUDE_STALE_NONNULLABLE_FK_ROWS[
                str(foreign_key["name"])
            ]
        rules.append(rule)
    return rules


def build_stale_orphan_source_filters(
    rules: list[dict[str, Any]],
) -> dict[str, dict[str, Any]]:
    grouped: dict[str, list[dict[str, Any]]] = {}
    for rule in rules:
        if rule["action"] == "exclude_stale_child_row":
            grouped.setdefault(str(rule["table"]), []).append(rule)
    filters: dict[str, dict[str, Any]] = {}
    for table, table_rules in grouped.items():
        predicates: list[str] = []
        reasons: list[str] = []
        for ordinal, rule in enumerate(table_rules, start=1):
            alias = "valid_parent_" + str(ordinal)
            join = " AND ".join(
                alias
                + "."
                + quote_identifier(parent_column)
                + " = s."
                + quote_identifier(child_column)
                for child_column, parent_column in zip(
                    rule["columns"], rule["referenced_columns"]
                )
            )
            predicates.append(
                "EXISTS (SELECT 1 FROM "
                + source_reference(str(rule["referenced_table"]))
                + " AS "
                + alias
                + " WHERE "
                + join
                + ")"
            )
            reasons.append(str(rule["reason"]))
        filters[table] = {
            "sql": " AND ".join(predicates),
            "reason": "；".join(reasons),
            "rules": table_rules,
        }
    return filters


def repair_nullable_foreign_key_orphans(
    connection: pymysql.connections.Connection,
    target_metadata: dict[str, Any],
    reporter: Reporter,
) -> list[dict[str, Any]]:
    """
    Source dumps can contain rows inserted with historical FK checks disabled.
    Preserve the child row but clear a nullable reference when its parent was
    not migrated. Non-nullable orphan references are a blocking data error.
    """
    reporter.section("阶段四：外键孤儿引用修复")
    repairs: list[dict[str, Any]] = []
    for foreign_key in target_metadata["foreign_keys"]:
        child = str(foreign_key["table"])
        parent = str(foreign_key["referenced_table"])
        pairs = foreign_key["pairs"]
        joins = " AND ".join(
            "c."
            + quote_identifier(pair["column"])
            + " = p."
            + quote_identifier(pair["referenced_column"])
            for pair in pairs
        )
        child_present = " AND ".join(
            "c." + quote_identifier(pair["column"]) + " IS NOT NULL"
            for pair in pairs
        )
        parent_missing = "p." + quote_identifier(pairs[0]["referenced_column"]) + " IS NULL"
        condition = child_present + " AND " + parent_missing
        count_sql = (
            "SELECT COUNT(*) FROM "
            + quote_identifier(child)
            + " AS c LEFT JOIN "
            + quote_identifier(parent)
            + " AS p ON "
            + joins
            + " WHERE "
            + condition
        )
        before = int(query_scalar(connection, count_sql) or 0)
        if before == 0:
            continue
        child_columns = column_map(target_metadata["columns"][child])
        nonnullable = [
            str(pair["column"])
            for pair in pairs
            if not bool(child_columns[str(pair["column"])]["is_nullable"])
        ]
        if nonnullable:
            raise MigrationFailure(
                "发现无法安全置空的外键孤儿引用："
                + child
                + "."
                + str(foreign_key["name"])
                + "（非空字段 "
                + ", ".join(nonnullable)
                + "）"
            )
        assignments = ", ".join(
            "c." + quote_identifier(pair["column"]) + " = NULL" for pair in pairs
        )
        update_sql = (
            "UPDATE "
            + quote_identifier(child)
            + " AS c LEFT JOIN "
            + quote_identifier(parent)
            + " AS p ON "
            + joins
            + " SET "
            + assignments
            + " WHERE "
            + condition
        )
        affected = execute(connection, update_sql)
        connection.commit()
        after = int(query_scalar(connection, count_sql) or 0)
        if after != 0:
            raise MigrationFailure(
                "外键孤儿引用置空后仍存在残留："
                + child
                + "."
                + str(foreign_key["name"])
            )
        repair = {
            "constraint": foreign_key["name"],
            "table": child,
            "referenced_table": parent,
            "before_orphans": before,
            "affected_rows": affected,
            "after_orphans": after,
            "rule": "nullable_fk_set_null_when_parent_missing",
        }
        repairs.append(repair)
        reporter.line(
            child
            + "."
            + str(foreign_key["name"])
            + "：发现 "
            + str(before)
            + " 条孤儿引用，已置空 "
            + str(affected)
            + " 条。"
        )
    if not repairs:
        reporter.line("未发现需要修复的外键孤儿引用。")
    return repairs


def product_group_health(
    connection: pymysql.connections.Connection,
    target_metadata: dict[str, Any],
) -> list[dict[str, Any]]:
    required_tables = {
        "products",
        "first_product_groups",
        "second_product_groups",
        "third_product_groups",
    }
    if not required_tables.issubset(set(target_metadata["tables"])):
        return []
    required_columns = {
        "products": {"product_group_id"},
        "second_product_groups": {"first_product_group_id"},
        "third_product_groups": {"second_product_group_id"},
    }
    if any(
        not columns.issubset(
            {
                str(item["column_name"])
                for item in target_metadata["columns"][table]
            }
        )
        for table, columns in required_columns.items()
    ):
        return []
    checks = [
        (
            "second_product_group_missing_first",
            "SELECT COUNT(*) FROM "
            + quote_identifier("second_product_groups")
            + " AS s LEFT JOIN "
            + quote_identifier("first_product_groups")
            + " AS f ON f."
            + quote_identifier("id")
            + " = s."
            + quote_identifier("first_product_group_id")
            + " WHERE s."
            + quote_identifier("first_product_group_id")
            + " IS NOT NULL AND f."
            + quote_identifier("id")
            + " IS NULL",
        ),
        (
            "third_product_group_missing_second",
            "SELECT COUNT(*) FROM "
            + quote_identifier("third_product_groups")
            + " AS t LEFT JOIN "
            + quote_identifier("second_product_groups")
            + " AS s ON s."
            + quote_identifier("id")
            + " = t."
            + quote_identifier("second_product_group_id")
            + " WHERE t."
            + quote_identifier("second_product_group_id")
            + " IS NOT NULL AND s."
            + quote_identifier("id")
            + " IS NULL",
        ),
        (
            "product_missing_valid_third_group",
            "SELECT COUNT(*) FROM "
            + quote_identifier("products")
            + " AS p LEFT JOIN "
            + quote_identifier("third_product_groups")
            + " AS t ON t."
            + quote_identifier("id")
            + " = p."
            + quote_identifier("product_group_id")
            + " WHERE p."
            + quote_identifier("product_group_id")
            + " IS NULL OR t."
            + quote_identifier("id")
            + " IS NULL",
        ),
    ]
    return [
        {"check": name, "invalid_rows": int(query_scalar(connection, sql) or 0)}
        for name, sql in checks
    ]


def canonical_row_bytes(row: dict[str, Any], fields: list[str]) -> bytes:
    values: list[str] = []
    for field in fields:
        value = row.get(field)
        if value is None:
            values.append("<NULL>")
        elif isinstance(value, bytes):
            values.append(value.hex())
        else:
            values.append(str(value))
    return ("\x1f".join(values) + "\n").encode("utf-8", errors="replace")


def stream_md5(
    connection: pymysql.connections.Connection,
    sql: str,
    fields: list[str],
) -> tuple[int, str]:
    digest = hashlib.md5()
    rows_seen = 0
    assert_sql_allowed_for_connection(connection, sql)
    with connection.cursor() as cursor:
        cursor.execute(sql)
        while True:
            rows = cursor.fetchmany(1000)
            if not rows:
                break
            for row in rows:
                digest.update(canonical_row_bytes(row, fields))
                rows_seen += 1
    return rows_seen, digest.hexdigest()


def core_hash_checks(
    config: DbConfig,
    mapping: dict[str, Any],
    target_metadata: dict[str, Any],
    source_metadata: dict[str, Any],
) -> list[dict[str, Any]]:
    preferred = ["orders", "invoices", "payments", "payment_callbacks", "services"]
    plans = {
        plan["target_table"]: plan
        for plan in mapping["plans"]
        if plan["action"] == "map" and plan["source_table"] == plan["target_table"]
    }
    results: list[dict[str, Any]] = []
    with managed_connection(config, TARGET_DATABASE, autocommit=True) as target_connection:
        with managed_connection(
            config, str(mapping["staging_database"]), autocommit=True
        ) as source_connection:
            for table in preferred:
                if len(results) >= 3:
                    break
                plan = plans.get(table)
                if plan is None:
                    continue
                source_columns = column_map(source_metadata["columns"].get(table, []))
                target_columns = column_map(target_metadata["columns"].get(table, []))
                candidates = [
                    "id",
                    "order_no",
                    "order_number",
                    "order_sn",
                    "invoice_no",
                    "invoice_number",
                    "invoice_sn",
                    "payment_no",
                    "transaction_id",
                    "amount",
                    "total_amount",
                    "pay_amount",
                    "status",
                ]
                direct_fields = [
                    field["target_column"]
                    for field in plan["fields"]
                    if field.get("source_column") == field["target_column"]
                    and field["target_column"] in source_columns
                    and field["target_column"] in target_columns
                ]
                fields = [name for name in candidates if name in direct_fields]
                if "id" not in fields and "id" in direct_fields:
                    fields.insert(0, "id")
                if len(fields) < 2:
                    continue
                fields = fields[:6]
                sort_fields = ["id"] if "id" in fields else fields
                projection = ", ".join(
                    quote_identifier(field) + " AS " + quote_identifier(field)
                    for field in fields
                )
                order = ", ".join(quote_identifier(field) for field in sort_fields)
                source_sql = (
                    "SELECT "
                    + projection
                    + " FROM "
                    + quote_identifier(str(mapping["staging_database"]))
                    + "."
                    + quote_identifier(table)
                    + " ORDER BY "
                    + order
                )
                target_sql = (
                    "SELECT "
                    + projection
                    + " FROM "
                    + quote_identifier(table)
                    + " ORDER BY "
                    + order
                )
                source_count, source_hash = stream_md5(
                    source_connection, source_sql, fields
                )
                target_count, target_hash = stream_md5(
                    target_connection, target_sql, fields
                )
                results.append(
                    {
                        "table": table,
                        "fields": fields,
                        "source_rows": source_count,
                        "target_rows": target_count,
                        "source_md5": source_hash,
                        "target_md5": target_hash,
                        "matched": source_count == target_count
                        and source_hash == target_hash,
                    }
                )
    return results


def application_smoke_check() -> dict[str, Any]:
    backend_dir = Path(__file__).resolve().parents[1]
    about = subprocess.run(
        ["php", "artisan", "about", "--no-ansi"],
        cwd=backend_dir,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        text=True,
        encoding="utf-8",
        errors="replace",
        check=False,
    )
    result: dict[str, Any] = {
        "artisan_about_exit_code": about.returncode,
        "artisan_about_output": (about.stdout or about.stderr).strip()[:2000],
    }
    if about.returncode != 0:
        return result
    tinker = subprocess.run(
        [
            "php",
            "artisan",
            "tinker",
            "--execute=echo (string) \\Illuminate\\Support\\Facades\\DB::table('invoices')->count();",
        ],
        cwd=backend_dir,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        text=True,
        encoding="utf-8",
        errors="replace",
        check=False,
    )
    result["finance_query_exit_code"] = tinker.returncode
    result["finance_query_output"] = (tinker.stdout or tinker.stderr).strip()[:2000]
    hierarchy = subprocess.run(
        ["php", "artisan", "product-catalog:check-product-group-hierarchy", "--json"],
        cwd=backend_dir,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        text=True,
        encoding="utf-8",
        errors="replace",
        check=False,
    )
    result["product_group_hierarchy_exit_code"] = hierarchy.returncode
    result["product_group_hierarchy_output"] = (
        hierarchy.stdout or hierarchy.stderr
    ).strip()[:2000]
    return result


def verify(
    config: DbConfig,
    state: dict[str, Any],
    mapping: dict[str, Any],
    preflight: dict[str, dict[str, Any]],
    paths: dict[str, Path],
    reporter: Reporter,
) -> dict[str, Any]:
    reporter.section("阶段四和验收：结构、行数、自增、外键和应用")
    with managed_connection(config, TARGET_DATABASE, autocommit=True) as target_connection:
        target_metadata = fetch_schema_metadata(target_connection, TARGET_DATABASE)
        after = schema_snapshot(target_connection, TARGET_DATABASE, paths["schema_after"])
        structure_errors = write_schema_diff_report(
            paths["schema_diff"], state["schema_before"], after
        )
        if structure_errors:
            raise MigrationFailure("结构安全性校验失败：" + "；".join(structure_errors))
        reporter.line("结构校验通过：SHOW CREATE TABLE 的表、字段、索引和约束无差异。")

        expected_by_table: dict[str, int] = {}
        for plan in mapping["plans"]:
            if plan["action"] == "map":
                key = plan["target_table"] + ":" + plan.get("kind", "same_name")
                expected_by_table[plan["target_table"]] = (
                    expected_by_table.get(plan["target_table"], 0)
                    + int(preflight[key]["expected_insert_rows"])
                )
            elif plan["action"] == "clear_only":
                expected_by_table.setdefault(plan["target_table"], 0)

        count_results: list[dict[str, Any]] = []
        for table, expected in sorted(expected_by_table.items()):
            target_count = int(
                query_scalar(
                    target_connection,
                    "SELECT COUNT(*) FROM " + quote_identifier(table),
                )
                or 0
            )
            count_results.append(
                {
                    "target_table": table,
                    "expected_rows": expected,
                    "target_rows": target_count,
                    "passed": target_count == expected,
                }
            )
        failed_counts = [item for item in count_results if not item["passed"]]
        if failed_counts:
            raise MigrationFailure(
                "数据行数校验失败："
                + "; ".join(
                    f"{item['target_table']} expected {item['expected_rows']} actual {item['target_rows']}"
                    for item in failed_counts
                )
            )
        reporter.line("逐表数据行数校验通过。")

        auto_increment = auto_increment_checks(target_connection, target_metadata)
        auto_failures = [item for item in auto_increment if not item["aligned"]]
        if auto_failures:
            raise MigrationFailure(
                "自增计数器未自然对齐："
                + "; ".join(
                    item["table"] + "." + item["column"] for item in auto_failures
                )
            )
        reporter.line(
            "自增计数器校验通过（未使用 ALTER TABLE，下一值均大于当前最大 ID）。"
        )

        foreign_keys = foreign_key_health(target_connection, target_metadata)
        orphaned = [item for item in foreign_keys if item["orphan_rows"] != 0]
        if orphaned:
            raise MigrationFailure(
                "外键完整性校验失败："
                + "; ".join(
                    item["table"]
                    + "."
                    + item["constraint"]
                    + "="
                    + str(item["orphan_rows"])
                    for item in orphaned
                )
            )
        reporter.line("外键反向 NOT EXISTS 校验通过，无孤儿数据。")

        product_groups = product_group_health(target_connection, target_metadata)
        product_group_failures = [
            item for item in product_groups if item["invalid_rows"] != 0
        ]
        if product_group_failures:
            raise MigrationFailure(
                "商品三级分类完整性校验失败："
                + "; ".join(
                    item["check"] + "=" + str(item["invalid_rows"])
                    for item in product_group_failures
                )
            )
        if product_groups:
            reporter.line("商品三级分类链与商品分类引用校验通过。")

    with managed_connection(config, str(mapping["staging_database"]), autocommit=True) as source_connection:
        source_metadata = fetch_schema_metadata(
            source_connection, str(mapping["staging_database"])
        )
    hashes = core_hash_checks(config, mapping, target_metadata, source_metadata)
    if len(hashes) < 3:
        raise MigrationFailure("无法构成 3 张核心业务表的 MD5 抽检")
    failed_hashes = [item for item in hashes if not item["matched"]]
    if failed_hashes:
        raise MigrationFailure(
            "核心业务字段 MD5 校验失败："
            + ", ".join(item["table"] for item in failed_hashes)
        )
    reporter.line("3 张核心表的关键字段 MD5 校验通过。")

    application = application_smoke_check()
    if application.get("artisan_about_exit_code") != 0 or application.get(
        "finance_query_exit_code"
    ) != 0 or application.get("product_group_hierarchy_exit_code") != 0:
        raise MigrationFailure(
            "Laravel 应用启动或财务查询冒烟失败："
            + json.dumps(application, ensure_ascii=False)
        )
    reporter.line("后端 Artisan 启动与 invoices 财务数据查询冒烟通过。")

    return {
        "verified_at": now_text(),
        "schema_diff": str(paths["schema_diff"]),
        "row_counts": count_results,
        "auto_increment": auto_increment,
        "foreign_keys": foreign_keys,
        "product_group_health": product_groups,
        "core_md5": hashes,
        "application_smoke": application,
    }


def validate_data_only_rollback_dump(path: Path) -> None:
    statement_count = 0
    for raw_statement in iter_mysql_dump_statements(path):
        statement = normalize_single_sql_statement(
            raw_statement.decode("utf-8", errors="replace"), "回滚恢复 SQL"
        )
        keyword = sql_first_keyword(statement, "回滚恢复 SQL")
        statement_count += 1
        if keyword == "SET":
            assert_session_set_statement(statement, "回滚恢复 SET", target=False)
            continue
        if keyword == "INSERT":
            if not re.match(
                r"INSERT\s+(?:(?:LOW_PRIORITY|DELAYED|HIGH_PRIORITY)\s+)?(?:IGNORE\s+)?INTO\b",
                statement,
                re.IGNORECASE,
            ):
                raise MigrationFailure("回滚恢复 INSERT 语法不在白名单中。")
        elif keyword == "DELETE":
            if not re.match(r"DELETE\s+FROM\b", statement, re.IGNORECASE):
                raise MigrationFailure("回滚恢复 DELETE 语法不在白名单中。")
        elif keyword == "LOCK":
            if not re.fullmatch(r"LOCK\s+TABLES\s+.+", statement, re.IGNORECASE | re.DOTALL):
                raise MigrationFailure("回滚恢复仅允许 LOCK TABLES。")
        elif keyword == "UNLOCK":
            if not re.fullmatch(r"UNLOCK\s+TABLES", statement, re.IGNORECASE):
                raise MigrationFailure("回滚恢复仅允许 UNLOCK TABLES。")
        else:
            raise MigrationFailure("回滚数据文件包含未批准 SQL 动词：" + keyword)
        assert_no_unsafe_sql_tail(statement, "回滚恢复 SQL")
        assert_dump_only_database_qualified_references(
            statement, TARGET_DATABASE, "回滚恢复 SQL"
        )
    if statement_count == 0:
        raise MigrationFailure("回滚数据文件不包含可执行数据 SQL。")


def restore_data_only_rollback_dump(config: DbConfig, dump_path: Path) -> None:
    validate_data_only_rollback_dump(dump_path)
    _restore_dump_with_mysql_client(
        config,
        dump_path,
        TARGET_DATABASE,
        context="目标库 DML 回滚恢复",
    )


def rollback(
    config: DbConfig,
    state: dict[str, Any],
    paths: dict[str, Path],
    reporter: Reporter,
    reason: str,
) -> None:
    reporter.section("失败回滚")
    reporter.line("开始仅数据 DML 回滚，原因：" + reason)
    assert_managed_artifact_path(
        state.get("paths", {}).get("backup_data", ""),
        paths["backup_data"],
        "DML 回滚备份路径",
    )
    data_backup = paths["backup_data"]
    if not data_backup.is_file():
        raise MigrationFailure("缺少数据回滚备份，无法回滚：" + str(data_backup))
    expected_backup_hash = str(state.get("backup_data_sha256", ""))
    if (
        not re.fullmatch(r"[0-9a-f]{64}", expected_backup_hash)
        or hash_file(data_backup) != expected_backup_hash
    ):
        raise MigrationFailure("数据回滚备份完整性校验失败，拒绝执行。")
    validate_data_only_rollback_dump(data_backup)
    with managed_connection(config, TARGET_DATABASE, autocommit=False) as connection:
        target_metadata = fetch_schema_metadata(connection, TARGET_DATABASE)
        execute(connection, "SET FOREIGN_KEY_CHECKS=0")
        connection.commit()
        for table in target_metadata["tables"]:
            if table in PRESERVE_TARGET_TABLES:
                continue
            execute(connection, "DELETE FROM " + quote_identifier(table))
            connection.commit()
        execute(connection, "SET FOREIGN_KEY_CHECKS=1")
        connection.commit()
    restore_data_only_rollback_dump(config, data_backup)
    state["rolled_back"] = True
    state["migration_complete"] = False
    save_state(paths["state"], state)
    reporter.line("数据 DML 回滚完成；目标库结构未执行任何恢复 DDL。")


def write_failure_report(
    path: Path,
    state: dict[str, Any],
    reason: str,
    rolled_back: bool,
) -> None:
    lines = [
        "# 失败分析报告",
        "",
        f"- 批次：{state.get('run_id', 'unknown')}",
        f"- 时间：{now_text()}",
        "- 目标库：idc",
        "- 失败原因：",
        "",
        "~~~text",
        reason,
        "~~~",
        "",
        "- 已执行 DML 数据回滚：" + ("是" if rolled_back else "否"),
        "- 结构策略：未对 idc 执行 CREATE、ALTER 或 DROP。",
        "",
    ]
    path.write_text("\n".join(lines), encoding="utf-8")


def run_migration_phase(
    config: DbConfig,
    state: dict[str, Any],
    paths: dict[str, Path],
    reporter: Reporter,
) -> tuple[dict[str, Any], dict[str, Any]]:
    mapping = load_mapping_from_state(state, paths)
    preflight = preflight_mapping(config, mapping, reporter)
    pre_path = paths["schema_after"].with_name(
        paths["schema_after"].stem + "_pre_migration.sql"
    )
    with managed_connection(config, TARGET_DATABASE, autocommit=False) as target_connection:
        target_metadata = fetch_schema_metadata(target_connection, TARGET_DATABASE)
        current = schema_snapshot(target_connection, TARGET_DATABASE, pre_path)
        mismatch = compare_schema_snapshots(state["schema_before"], current)
        if mismatch:
            raise MigrationFailure(
                "迁移前目标结构指纹已变化，拒绝清空数据：" + "；".join(mismatch)
            )
        state["migration_started"] = True
        save_state(paths["state"], state)
        cleared = clear_target_data(target_connection, mapping, target_metadata, reporter)
        state["cleared_target_rows"] = cleared
        save_state(paths["state"], state)
        execution, errors = execute_mapping_plans(
            target_connection, mapping, preflight, reporter
        )
        foreign_key_repairs: list[dict[str, Any]] = []
        if not errors:
            foreign_key_repairs = repair_nullable_foreign_key_orphans(
                target_connection, target_metadata, reporter
            )
        execute(target_connection, "SET FOREIGN_KEY_CHECKS=1")
        target_connection.commit()
    if errors:
        raise MigrationFailure(
            "存在表映射错误，迁移不能通过验收："
            + "; ".join(
                item["target_table"] + ": " + item["error"] for item in errors
            )
        )
    state["execution"] = execution
    state["foreign_key_repairs"] = foreign_key_repairs
    state["preflight"] = preflight
    save_state(paths["state"], state)
    return mapping, preflight


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="在独立中转库下执行 idc 异构表结构 DML 映射迁移。"
    )
    parser.add_argument(
        "--dump",
        required=True,
        help="源 MySQL dump 路径；必须显式指定，禁止依赖仓库内默认文件。",
    )
    parser.add_argument("--env", default=str(DEFAULT_ENV_FILE), help="后端 .env 路径。")
    parser.add_argument("--target-db", default=TARGET_DATABASE, help="只能为 idc。")
    parser.add_argument(
        "--staging-db",
        default="",
        help="仅接受当前 run-id 派生的受管中转库名称。",
    )
    parser.add_argument(
        "--output-dir",
        required=True,
        help="仓库外的受控产物目录；必须显式指定。",
    )
    parser.add_argument("--run-id", default=run_id_default(), help="批次标识。")
    parser.add_argument(
        "--phase",
        choices=["prepare", "analyze", "preflight", "migrate", "verify", "all", "rollback"],
        default="prepare",
        help="执行阶段。",
    )
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    args.run_id = validate_run_id(args.run_id)
    output_dir = assert_path_outside_repository(Path(args.output_dir), "产物目录")
    output_dir.mkdir(parents=True, exist_ok=True)
    paths = state_paths(output_dir, args.run_id)
    reporter = Reporter(paths["log"], args.run_id)
    state: dict[str, Any] | None = None
    config: DbConfig | None = None
    try:
        application_config = load_db_config(Path(args.env).resolve(), args.target_db)
        config = load_local_admin_config(application_config)
        reporter.line(
            "已验证本地受限应用账号；仅为创建和读取独立中转库使用本机管理连接。"
        )
        if args.phase == "prepare":
            prepare(config, args, paths, reporter)
            return 0

        state = load_state(paths["state"], paths)
        if args.phase == "analyze":
            analyze(config, state, paths, reporter)
            return 0

        if args.phase == "preflight":
            mapping = load_mapping_from_state(state, paths)
            state["preflight"] = preflight_mapping(config, mapping, reporter)
            save_state(paths["state"], state)
            return 0

        if args.phase == "rollback":
            rollback(config, state, paths, reporter, "手动请求回滚")
            return 0

        if args.phase == "migrate":
            run_migration_phase(config, state, paths, reporter)
            return 0

        if args.phase == "verify":
            mapping = load_mapping_from_state(state, paths)
            preflight = state.get("preflight")
            if not isinstance(preflight, dict):
                preflight = preflight_mapping(config, mapping, reporter)
            verification = verify(config, state, mapping, preflight, paths, reporter)
            state["verification"] = verification
            state["migration_complete"] = True
            save_state(paths["state"], state)
            return 0

        if args.phase == "all":
            state = prepare(config, args, paths, reporter)
            state = analyze(config, state, paths, reporter)
            mapping, preflight = run_migration_phase(config, state, paths, reporter)
            verification = verify(config, state, mapping, preflight, paths, reporter)
            state["verification"] = verification
            state["migration_complete"] = True
            save_state(paths["state"], state)
            reporter.section("完成")
            reporter.line("全部验收通过。")
            return 0
        raise MigrationFailure("未知执行阶段")
    except Exception as exc:
        reason = "".join(traceback.format_exception_only(type(exc), exc)).strip()
        reporter.line("任务失败：" + reason)
        rolled_back = False
        if (
            config is not None
            and state is not None
            and state.get("migration_started")
            and not state.get("rolled_back")
        ):
            try:
                rollback(config, state, paths, reporter, reason)
                rolled_back = True
            except Exception as rollback_exc:
                reporter.line("自动回滚失败：" + str(rollback_exc))
        if state is None and paths["state"].is_file():
            try:
                state = load_state(paths["state"], paths)
            except Exception:
                state = {"run_id": args.run_id}
        write_failure_report(
            paths["failure"],
            state or {"run_id": args.run_id},
            reason,
            rolled_back,
        )
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
