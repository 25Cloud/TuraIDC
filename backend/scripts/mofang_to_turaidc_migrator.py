#!/usr/bin/env python3
"""魔方财务 → 图拉云 turaidc 全量定制迁移器

特性：
- 流式解析源 MySQL dump（不载入全部，内存友好）
- 自定义 INSERT 语句解析器（正确处理引号/转义/二进制 hex）
- 每张核心表配置字段映射器（列名映射 + 值转换）
- pricing 字段：shd_pricing 多列周期价格 → products.pricing JSON
- 密码保留 `###md5` 格式，由 ZjmfLegacyPasswordVerifier 验证
- 时间戳(Unix) → MySQL datetime 字符串；0 视为 NULL
- 批量插入 + 每表事务；失败回滚并报告
- 支持 dry-run 模式：仅解析+映射+计数，不写库
- 支持指定表清单（白名单）

连接配置按优先级合并：命令行参数 > 环境变量 MOFANG_MIGRATE_* > 配置文件
（默认读取脚本同目录 mofang_migrate.conf，可用 --config 指定）。
未提供 --dump 时会自动发现项目根/脚本目录下的 25y_*.sql dump 文件。

用法:
    # 零参数：从 mofang_migrate.conf 读取配置，自动发现 dump
    python backend/scripts/mofang_to_turaidc_migrator.py --dry-run

    # dry-run 全量预演（全部参数手动指定，优先级最高）
    python backend/scripts/mofang_to_turaidc_migrator.py \\
        --dump e:\\TuraIDC\\25y_2026-08-17_20-39-46_mysql_data_HSoN5.sql \\
        --host 43.240.220.81 --port 3306 \\
        --user turaidc --password <PASSWORD> \\
        --database turaidc --dry-run

    # 实际迁移（按白名单表）
    python backend/scripts/mofang_to_turaidc_migrator.py \\
        --dump e:\\TuraIDC\\25y_2026-08-17_20-39-46_mysql_data_HSoN5.sql \\
        --host 43.240.220.81 --port 3306 \\
        --user turaidc --password <PASSWORD> \\
        --database turaidc \\
        --tables shd_clients,shd_products,shd_orders,shd_invoices,shd_host,shd_ticket

    # 全量迁移
    python backend/scripts/mofang_to_turaidc_migrator.py \\
        --dump ... --host ... --database turaidc

配置示例见脚本同目录 mofang_migrate.conf.example（复制为 mofang_migrate.conf
即可免输参数；该文件含密码，已加入 .gitignore 不会提交）。
"""
from __future__ import annotations

import argparse
import json
import logging
import os
import re
import sys
from configparser import RawConfigParser
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any, Callable, Iterable

import pymysql
from pymysql.cursors import DictCursor

logger = logging.getLogger("migrator")

# =============================================================================
# 一、SQL INSERT 流式解析器
# =============================================================================

INSERT_RE = re.compile(
    r"INSERT\s+INTO\s+`([^`]+)`\s*"
    r"(?:\(([^)]*)\)\s*)?"
    r"VALUES\s*",
    re.IGNORECASE,
)


def _parse_column_list(cols_text: str) -> list[str]:
    """解析 INSERT 语句中的列名列表 `(\`a\`,\`b\`,\`c\`)`"""
    if not cols_text:
        return []
    cols: list[str] = []
    for part in cols_text.split(","):
        part = part.strip().strip("`")
        if part:
            cols.append(part)
    return cols


def _split_top_level(text: str, sep: str = ",") -> list[str]:
    """按顶层分隔符切分（忽略引号内、转义符后的分隔符）"""
    parts: list[str] = []
    buf: list[str] = []
    in_s = False  # 单引号字符串
    in_d = False  # 双引号字符串（MySQL ANSI 模式较少，仍兼容）
    esc = False
    for ch in text:
        if esc:
            buf.append(ch)
            esc = False
            continue
        if ch == "\\":
            buf.append(ch)
            esc = True
            continue
        if ch == "'" and not in_d:
            in_s = not in_s
            buf.append(ch)
            continue
        if ch == '"' and not in_s:
            in_d = not in_d
            buf.append(ch)
            continue
        if ch == sep and not in_s and not in_d:
            parts.append("".join(buf))
            buf = []
            continue
        buf.append(ch)
    if buf:
        parts.append("".join(buf))
    return parts


def _split_tuples(values_text: str) -> list[str]:
    """切分 VALUES 段中的多个元组 `(a,b),(c,d),(e,f)` → ['a,b','c,d','e,f']"""
    tuples: list[str] = []
    buf: list[str] = []
    depth = 0
    in_s = False
    in_d = False
    esc = False
    for ch in values_text:
        if esc:
            buf.append(ch)
            esc = False
            continue
        if ch == "\\":
            buf.append(ch)
            esc = True
            continue
        if ch == "'" and not in_d:
            in_s = not in_s
            buf.append(ch)
            continue
        if ch == '"' and not in_s:
            in_d = not in_d
            buf.append(ch)
            continue
        if ch == "(" and not in_s and not in_d:
            depth += 1
            if depth == 1:
                # 元组开始，清空缓冲（应该是空或仅空白/逗号）
                buf = []
                continue
        if ch == ")" and not in_s and not in_d:
            depth -= 1
            if depth == 0:
                tuples.append("".join(buf))
                buf = []
                continue
        buf.append(ch)
    return tuples


def _parse_sql_value(raw: str) -> Any:
    """解析单个 SQL 值字面量 → Python 对象

    - NULL → None
    - 数字 → int/float
    - 字符串 'xxx' → str（去引号、反转义）
    - 二进制 _binary 'xxx' 或 0x... → str
    """
    s = raw.strip()
    if s.upper() == "NULL":
        return None
    # _binary '...' 处理
    m = re.match(r"^_binary\s*'(.*)'$", s, re.S)
    if m:
        return _unescape_sql_string(m.group(1))
    # 0xHEX
    if re.match(r"^0x[0-9a-fA-F]+$", s):
        return s  # 保留为字符串
    if len(s) >= 2 and s[0] == "'" and s[-1] == "'":
        return _unescape_sql_string(s[1:-1])
    if len(s) >= 2 and s[0] == '"' and s[-1] == '"':
        return _unescape_sql_string(s[1:-1])
    # 数字
    try:
        if "." in s:
            return float(s)
        return int(s)
    except ValueError:
        return s


def _unescape_sql_string(s: str) -> str:
    """反转义 MySQL 字符串字面量"""
    out: list[str] = []
    i = 0
    while i < len(s):
        ch = s[i]
        if ch == "\\" and i + 1 < len(s):
            nxt = s[i + 1]
            mapping = {
                "n": "\n",
                "r": "\r",
                "t": "\t",
                "0": "\x00",
                "\\": "\\",
                "'": "'",
                '"': '"',
                "Z": "\x1a",
            }
            out.append(mapping.get(nxt, nxt))
            i += 2
            continue
        out.append(ch)
        i += 1
    return "".join(out)


class InsertBuffer:
    """跨行的 INSERT 语句累积缓冲"""

    def __init__(self, table: str, columns: list[str]):
        self.table = table
        self.columns = columns
        self.text_parts: list[str] = []

    def append(self, line: str) -> None:
        self.text_parts.append(line)

    def get_values_text(self) -> str:
        """获取 VALUES 之后的部分（包含末尾 ; 会被去除）"""
        full = "".join(self.text_parts)
        # 定位 VALUES 关键字
        # 用状态机找 VALUES 的位置（不区分大小写）
        # 简化：正则匹配
        m = re.search(r"\bVALUES\s*", full, re.IGNORECASE)
        if not m:
            return ""
        rest = full[m.end():]
        # 去掉末尾分号
        rest = rest.rstrip()
        if rest.endswith(";"):
            rest = rest[:-1]
        return rest

    def is_complete(self) -> bool:
        full = "".join(self.text_parts)
        # 完整的 INSERT 以分号结尾（且不在字符串内）
        # 简化判断：最后一个非空白字符是 ;
        stripped = full.rstrip()
        return stripped.endswith(";")


class DumpScanner:
    """流式扫描 dump 文件，按表分组产出 (table, columns, list[tuple])

    支持两种 INSERT 格式：
    1. 带列名：INSERT INTO `t` (`a`,`b`) VALUES (1,2);
    2. 不带列名：INSERT INTO `t` VALUES (1,2);
       此种情况需先扫描 CREATE TABLE 获取列顺序，按位置映射。
    """

    def __init__(self, dump_path: Path):
        self.dump_path = dump_path
        # 表名 -> 列名列表（按 CREATE TABLE 中出现顺序）
        self._create_table_columns: dict[str, list[str]] = {}

    def pre_scan_create_tables(self) -> dict[str, list[str]]:
        """预扫描 dump 文件中的 CREATE TABLE 语句，提取表名和列顺序

        CREATE TABLE 语法示例：
            CREATE TABLE `tbl` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `name` varchar(100) DEFAULT NULL,
              PRIMARY KEY (`id`),
              KEY `idx_name` (`name`)
            ) ENGINE=InnoDB ...;
        """
        cols_map: dict[str, list[str]] = {}
        # 正则：匹配 CREATE TABLE 表名
        create_re = re.compile(r"CREATE TABLE\s+(?:IF NOT EXISTS\s+)?`([^`]+)`\s*\(", re.IGNORECASE)
        # 列定义正则：行首是 `字段名` 类型
        col_re = re.compile(r"^\s*`([^`]+)`\s+")

        with self.dump_path.open("r", encoding="utf-8", errors="replace") as f:
            in_create = False
            current_table = ""
            current_cols: list[str] = []
            paren_depth = 0
            for raw in f:
                line = raw.rstrip("\r\n")
                if not in_create:
                    m = create_re.search(line)
                    if m:
                        current_table = m.group(1)
                        current_cols = []
                        in_create = True
                        paren_depth = line.count("(") - line.count(")")
                        # 当行可能已有列定义
                        # 处理 CREATE TABLE `tbl` ( `id` int ...
                        after_paren = line[line.find("(", m.end() - 1) + 1:] if "(" in line[m.start():] else ""
                        if after_paren:
                            cm = col_re.match(after_paren)
                            if cm:
                                current_cols.append(cm.group(1))
                    continue
                # 在 CREATE TABLE 内部
                # 检测结束：括号平衡且包含 ENGINE= 或 ;
                paren_depth += line.count("(") - line.count(")")
                # 提取列定义（仅识别行首 `xxx`，不识别 PRIMARY KEY/KEY/CONSTRAINT 等）
                cm = col_re.match(line)
                if cm and not any(line.strip().upper().startswith(kw) for kw in
                                  ("PRIMARY", "KEY ", "KEY`", "UNIQUE", "CONSTRAINT", "FOREIGN", "INDEX", "FULLTEXT", "CHECK")):
                    current_cols.append(cm.group(1))
                if paren_depth <= 0 or ";" in line:
                    if current_table and current_cols:
                        cols_map[current_table] = current_cols
                    in_create = False
                    current_table = ""
                    current_cols = []
                    paren_depth = 0

        self._create_table_columns = cols_map
        return cols_map

    def scan(self) -> Iterable[tuple[str, list[str], list[tuple[Any, ...]]]]:
        """yield (table_name, columns, rows)"""
        current_buf: InsertBuffer | None = None
        with self.dump_path.open("r", encoding="utf-8", errors="replace") as f:
            for raw in f:
                line = raw.rstrip("\r\n")
                if current_buf is not None:
                    current_buf.append(line)
                    if current_buf.is_complete():
                        yield from self._emit(current_buf)
                        current_buf = None
                    continue
                # 尝试匹配 INSERT
                m = INSERT_RE.search(line)
                if m:
                    table = m.group(1)
                    cols = _parse_column_list(m.group(2) or "")
                    # 如果 INSERT 没带列名，从 CREATE TABLE 预扫描中获取
                    if not cols and table in self._create_table_columns:
                        cols = self._create_table_columns[table]
                    # 判断这一行是否已经完整
                    if line.rstrip().endswith(";"):
                        # 单行 INSERT
                        buf = InsertBuffer(table, cols)
                        buf.append(line)
                        yield from self._emit(buf)
                    else:
                        current_buf = InsertBuffer(table, cols)
                        current_buf.append(line)

    def _emit(self, buf: InsertBuffer) -> Iterable[tuple[str, list[str], list[tuple[Any, ...]]]]:
        values_text = buf.get_values_text()
        if not values_text:
            return
        tuples = _split_tuples(values_text)
        rows: list[tuple[Any, ...]] = []
        for tup in tuples:
            fields = _split_top_level(tup, ",")
            row = tuple(_parse_sql_value(f) for f in fields)
            rows.append(row)
        yield (buf.table, buf.columns, rows)


# =============================================================================
# 二、列映射器（每张核心表一份）
# =============================================================================

def ts_to_datetime(ts: Any) -> str | None:
    """Unix 时间戳 → 'YYYY-MM-DD HH:MM:SS'；0/None → None"""
    if ts is None or ts == 0 or ts == "":
        return None
    try:
        import time
        t = int(ts)
        if t <= 0:
            return None
        return time.strftime("%Y-%m-%d %H:%M:%S", time.localtime(t))
    except (ValueError, TypeError, OSError):
        return None


def ts_to_date(ts: Any) -> str | None:
    """Unix 时间戳 → 'YYYY-MM-DD'"""
    dt = ts_to_datetime(ts)
    return dt[:10] if dt else None


def to_int(v: Any, default: int = 0) -> int:
    if v is None or v == "":
        return default
    try:
        return int(v)
    except (ValueError, TypeError):
        try:
            return int(float(v))
        except (ValueError, TypeError):
            return default


def to_float(v: Any, default: float = 0.0) -> float:
    if v is None or v == "":
        return default
    try:
        return float(v)
    except (ValueError, TypeError):
        return default


def to_str(v: Any, default: str = "") -> str:
    if v is None:
        return default
    return str(v)


def nullable_str(v: Any) -> str | None:
    """空字符串 → None，其他保留"""
    if v is None:
        return None
    s = str(v).strip()
    return s if s else None


def status_to_int(v: Any, mapping: dict[str, int], default: int = 0) -> int:
    """字符串状态 → int"""
    if v is None:
        return default
    return mapping.get(str(v).strip(), default)


def invert_bool(v: Any) -> int:
    """0→1, 1→0 (hidden=1 → is_visible=0)"""
    return 0 if to_int(v) else 1


@dataclass
class FieldMap:
    """字段映射规则"""
    src: str | None  # 源列名；None 表示静态字段
    tgt: str
    fn: Callable[[Any], Any] | None = None
    static: Any = None  # 当 src=None 时使用


@dataclass
class TableMapper:
    """表映射器"""
    source_table: str
    target_table: str
    fields: list[FieldMap]
    order: int = 0  # 迁移顺序

    def map_row(self, source_row: dict[str, Any]) -> dict[str, Any]:
        result: dict[str, Any] = {}
        for fm in self.fields:
            if fm.src is None:
                if fm.static is not None or fm.tgt not in result:
                    result[fm.tgt] = fm.static
                continue
            v = source_row.get(fm.src)
            if fm.fn:
                v = fm.fn(v)
            if v is not None:
                result[fm.tgt] = v
        return result


# =============================================================================
# 三、各表映射器配置
# =============================================================================

# 时间戳字段转换
def _ts(v): return ts_to_datetime(v)
def _ts_date(v): return ts_to_date(v)


MAPPERS: list[TableMapper] = [
    # ---------- settings (shd_configuration) ----------
    TableMapper(
        source_table="shd_configuration",
        target_table="settings",
        order=10,
        fields=[
            FieldMap(src=None, tgt="id", static=None),  # auto_increment
            FieldMap(src=None, tgt="group_key", static="general"),
            FieldMap(src="setting", tgt="item_key"),
            FieldMap(src="value", tgt="item_value", fn=to_str),
        ],
    ),

    # ---------- first_product_groups (shd_product_first_groups) ----------
    TableMapper(
        source_table="shd_product_first_groups",
        target_table="first_product_groups",
        order=20,
        fields=[
            FieldMap(src="id", tgt="id"),
            FieldMap(src="name", tgt="name", fn=to_str),
            FieldMap(src=None, tgt="code", static=None),
            FieldMap(src=None, tgt="product_type", static=None),
            FieldMap(src="name", tgt="slug"),  # 用 name 当 slug 简易处理
            FieldMap(src=None, tgt="description", static=None),
            FieldMap(src=None, tgt="icon", static=None),
            FieldMap(src=None, tgt="banner_image", static=None),
            FieldMap(src="order", tgt="sort_order", fn=lambda v: to_int(v)),
            FieldMap(src="hidden", tgt="is_visible", fn=invert_bool),
            FieldMap(src=None, tgt="is_system", static=0),
            FieldMap(src="create_time", tgt="created_at", fn=_ts),
            FieldMap(src="update_time", tgt="updated_at", fn=_ts),
        ],
    ),

    # ---------- second_product_groups (shd_product_groups, gid=0 即二级) ----------
    # 注：源库 shd_product_groups.gid 指向 first_groups.id，因此均为二级组
    TableMapper(
        source_table="shd_product_groups",
        target_table="second_product_groups",
        order=30,
        fields=[
            FieldMap(src="id", tgt="id"),
            FieldMap(src="gid", tgt="first_product_group_id", fn=lambda v: to_int(v) or None),
            FieldMap(src="name", tgt="name", fn=to_str),
            FieldMap(src="name", tgt="slug"),
            FieldMap(src="headline", tgt="description", fn=nullable_str),
            FieldMap(src=None, tgt="banner_image", static=None),
            FieldMap(src="order", tgt="sort_order", fn=lambda v: to_int(v)),
            FieldMap(src="hidden", tgt="is_visible", fn=invert_bool),
            FieldMap(src="create_time", tgt="created_at", fn=_ts),
            FieldMap(src="update_time", tgt="updated_at", fn=_ts),
        ],
    ),

    # ---------- suppliers (shd_upper_reaches) ----------
    TableMapper(
        source_table="shd_upper_reaches",
        target_table="suppliers",
        order=40,
        fields=[
            FieldMap(src="id", tgt="id"),
            FieldMap(src="name", tgt="name", fn=to_str),
            FieldMap(src="name", tgt="code"),  # 简易 code
            FieldMap(src=None, tgt="contact_name", static=None),
            FieldMap(src="phone", tgt="contact_phone", fn=nullable_str),
            FieldMap(src=None, tgt="contact_email", static=None),
            FieldMap(src=None, tgt="website", static=None),
            FieldMap(src=None, tgt="status", static=1),
            FieldMap(src=None, tgt="sort_order", static=0),
            FieldMap(src="bz", tgt="notes", fn=nullable_str),
            FieldMap(src="create_time", tgt="created_at", fn=_ts),
            FieldMap(src=None, tgt="updated_at", static=None),
        ],
    ),

    # ---------- users (shd_clients) ----------
    TableMapper(
        source_table="shd_clients",
        target_table="users",
        order=50,
        fields=[
            FieldMap(src="id", tgt="id"),
            FieldMap(src="email", tgt="email", fn=nullable_str),
            FieldMap(src="password", tgt="password", fn=to_str),  # 保留 ###md5 原样
            FieldMap(src="username", tgt="nickname", fn=lambda v: to_str(v) or "user"),
            FieldMap(src="phonenumber", tgt="phone", fn=nullable_str),
            FieldMap(src="companyname", tgt="company", fn=to_str),
            FieldMap(src="qq", tgt="qq", fn=to_str),
            FieldMap(src=None, tgt="alipay_real_name", static=""),
            FieldMap(src=None, tgt="alipay_account", static=""),
            FieldMap(src=None, tgt="referral_code", static=None),
            FieldMap(src=None, tgt="referrer_user_id", static=None),
            FieldMap(src=None, tgt="member_level_id", static=None),
            FieldMap(src=None, tgt="total_sales_amount", static=0),
            FieldMap(src=None, tgt="referred_at", static=None),
            # status: 1激活→1正常, 0未激活→0禁用, 2关闭→0禁用
            FieldMap(src="status", tgt="status", fn=lambda v: 1 if to_int(v) == 1 else 0),
            FieldMap(src=None, tgt="login_email_alert", static=1),
            FieldMap(src=None, tgt="login_notify", static=1),
            FieldMap(src=None, tgt="login_location_alert", static=1),
            FieldMap(src=None, tgt="password_change_alert", static=1),
            FieldMap(src=None, tgt="phone_change_alert", static=1),
            FieldMap(src=None, tgt="email_change_alert", static=1),
            FieldMap(src="marketing_emails_opt_in", tgt="marketing_alert", fn=lambda v: to_int(v)),
            FieldMap(src="email_verified", tgt="is_verified", fn=lambda v: to_int(v)),
            FieldMap(src=None, tgt="real_name", static=""),
            FieldMap(src=None, tgt="id_card", static=""),
            FieldMap(src=None, tgt="verification_status", static=0),
            FieldMap(src=None, tgt="verification_message", static=""),
            FieldMap(src=None, tgt="verification_certify_id", static=None),
            FieldMap(src=None, tgt="verified_at", static=None),
            FieldMap(src="lastloginip", tgt="last_login_ip", fn=nullable_str),
            FieldMap(src="lastlogin", tgt="last_login_at", fn=_ts),
            FieldMap(src="notes", tgt="admin_note", fn=nullable_str),
            FieldMap(src="create_time", tgt="created_at", fn=_ts),
            FieldMap(src="update_time", tgt="updated_at", fn=_ts),
            FieldMap(src=None, tgt="deleted_at", static=None),
        ],
    ),

    # ---------- user_accounts (shd_clients.balance → cash_balance) ----------
    TableMapper(
        source_table="shd_clients",
        target_table="user_accounts",
        order=51,
        fields=[
            FieldMap(src="id", tgt="user_id"),
            FieldMap(src="credit", tgt="cash_balance", fn=to_float),
            FieldMap(src="credit_limit", tgt="credit_limit", fn=to_float),
            FieldMap(src=None, tgt="referral_frozen_balance", static=0),
            FieldMap(src=None, tgt="referral_available_balance", static=0),
            FieldMap(src=None, tgt="referral_pending_withdrawal_balance", static=0),
            FieldMap(src=None, tgt="referral_withdrawn_balance", static=0),
            FieldMap(src=None, tgt="version", static=0),
            FieldMap(src="create_time", tgt="created_at", fn=_ts),
            FieldMap(src="update_time", tgt="updated_at", fn=_ts),
        ],
    ),

    # ---------- products (shd_products) ----------
    TableMapper(
        source_table="shd_products",
        target_table="products",
        order=60,
        fields=[
            FieldMap(src="id", tgt="id"),
            FieldMap(src="gid", tgt="product_group_id", fn=lambda v: to_int(v) or None),
            FieldMap(src="server_type", tgt="service_type_code", fn=nullable_str),
            FieldMap(src="type", tgt="product_type", fn=lambda v: to_str(v) or "other"),
            FieldMap(src=None, tgt="console_template", static="compute"),
            FieldMap(src="name", tgt="custom_display_name", fn=nullable_str),
            FieldMap(src="description", tgt="remark", fn=lambda v: to_str(v)[:255] if v else None),
            # pricing 由后处理填入（先设空 JSON 占位，避免 NOT NULL 约束失败）
            FieldMap(src=None, tgt="pricing", static="{}"),
            FieldMap(src=None, tgt="setup_fee", static=0),
            FieldMap(src=None, tgt="config_options", static=None),
            FieldMap(src=None, tgt="purchase_requires", static=None),
            FieldMap(src="qty", tgt="stock", fn=lambda v: to_int(v, -1) if to_int(v) != 0 else -1),
            FieldMap(src="retired", tgt="status", fn=invert_bool),
            FieldMap(src="order", tgt="sort_order", fn=lambda v: to_int(v)),
            FieldMap(src="auto_setup", tgt="auto_setup", fn=lambda v: 1 if to_str(v) in ("on", "payment", "order") else 0),
            FieldMap(src="create_time", tgt="created_at", fn=_ts),
            FieldMap(src="update_time", tgt="updated_at", fn=_ts),
            FieldMap(src=None, tgt="deleted_at", static=None),
        ],
    ),

    # ---------- orders (shd_orders) ----------
    # 状态映射：Pending→0, Active→1, Completed→3, Cancelled→4, Suspend→2, Fraud→4
    TableMapper(
        source_table="shd_orders",
        target_table="orders",
        order=70,
        fields=[
            FieldMap(src="id", tgt="id"),
            FieldMap(src="uid", tgt="user_id", fn=lambda v: to_int(v)),
            FieldMap(src="ordernum", tgt="order_no", fn=to_str),
            FieldMap(src=None, tgt="product_id", static=None),
            FieldMap(src=None, tgt="product_spec_snapshot", static=None),
            FieldMap(src=None, tgt="product_type_snapshot", static=None),
            FieldMap(src=None, tgt="service_id", static=None),
            FieldMap(src=None, tgt="type", static="new"),
            FieldMap(src=None, tgt="coupon_id", static=None),
            FieldMap(src=None, tgt="user_coupon_id", static=None),
            FieldMap(src="promo_code", tgt="coupon_code", fn=nullable_str),
            FieldMap(src="amount", tgt="amount", fn=to_float),
            FieldMap(src=None, tgt="currency", static="CNY"),
            FieldMap(src="promo_value", tgt="discount", fn=to_float),
            FieldMap(src="amount", tgt="paid_amount", fn=to_float),  # 简化：订单总额作为已付
            FieldMap(src=None, tgt="billing_cycle", static=None),
            FieldMap(src=None, tgt="quantity", static=1),
            FieldMap(src=None, tgt="config_snapshot", static=None),
            FieldMap(src=None, tgt="config_pricing_snapshot", static=None),
            FieldMap(src=None, tgt="coupon_snapshot", static=None),
            FieldMap(src=None, tgt="service_snapshot", static=None),
            FieldMap(
                src="status",
                tgt="status",
                fn=lambda v: {"Pending": 0, "Active": 1, "Suspend": 2, "Completed": 3, "Cancelled": 4, "Fraud": 4, "Terminated": 4}.get(str(v).strip(), 0),
            ),
            FieldMap(src="pay_time", tgt="paid_at", fn=_ts),
            FieldMap(src=None, tgt="deleted_at", static=None),
            FieldMap(src="create_time", tgt="created_at", fn=_ts),
            FieldMap(src="update_time", tgt="updated_at", fn=_ts),
            FieldMap(src="notes", tgt="remark", fn=lambda v: to_str(v)[:255] if v else None),
            FieldMap(src=None, tgt="operator", static=None),
            FieldMap(src=None, tgt="trace_id", static=None),
            FieldMap(src=None, tgt="projection_type", static="provisioning"),
        ],
    ),

    # ---------- invoices (shd_invoices) ----------
    TableMapper(
        source_table="shd_invoices",
        target_table="invoices",
        order=80,
        fields=[
            FieldMap(src="id", tgt="id"),
            FieldMap(src="uid", tgt="user_id", fn=lambda v: to_int(v)),
            FieldMap(src="invoice_num", tgt="invoice_no", fn=nullable_str),
            FieldMap(src=None, tgt="order_id", static=None),
            FieldMap(src=None, tgt="origin_invoice_id", static=None),
            FieldMap(src=None, tgt="product_id", static=None),
            FieldMap(src=None, tgt="product_spec_snapshot", static=None),
            FieldMap(src=None, tgt="product_type_snapshot", static=None),
            FieldMap(src=None, tgt="service_id", static=None),
            FieldMap(src=None, tgt="coupon_id", static=None),
            FieldMap(src=None, tgt="user_coupon_id", static=None),
            FieldMap(src=None, tgt="coupon_code", static=None),
            FieldMap(src="type", tgt="type", fn=lambda v: {"recharge": "recharge", "product": "new", "renew": "renew"}.get(str(v).strip(), "normal")),
            FieldMap(src="total", tgt="amount", fn=to_float),
            FieldMap(src=None, tgt="currency", static="CNY"),
            FieldMap(src="credit", tgt="discount", fn=to_float),
            FieldMap(src=None, tgt="billing_cycle", static=None),
            FieldMap(src=None, tgt="quantity", static=1),
            FieldMap(src=None, tgt="config_snapshot", static=None),
            FieldMap(src=None, tgt="config_pricing_snapshot", static=None),
            FieldMap(src=None, tgt="coupon_snapshot", static=None),
            FieldMap(src="subtotal", tgt="paid_amount", fn=to_float),
            FieldMap(
                src="status",
                tgt="status",
                fn=lambda v: {"Paid": 1, "Unpaid": 0, "Draft": 0, "Overdue": 3, "Cancelled": 2, "Refunded": 5, "Collections": 0}.get(str(v).strip(), 0),
            ),
            FieldMap(src="due_time", tgt="due_date", fn=_ts_date),
            FieldMap(src="paid_time", tgt="paid_at", fn=_ts),
            FieldMap(src=None, tgt="deleted_at", static=None),
            FieldMap(src=None, tgt="refund_trace_id", static=None),
            FieldMap(src=None, tgt="refund_method", static=None),
            FieldMap(src=None, tgt="refund_amount", static=None),
            FieldMap(src=None, tgt="refunded_at", static=None),
            FieldMap(src="create_time", tgt="created_at", fn=_ts),
            FieldMap(src="update_time", tgt="updated_at", fn=_ts),
            FieldMap(src="notes", tgt="remark", fn=lambda v: to_str(v)[:255] if v else None),
            FieldMap(src=None, tgt="operator", static=None),
            FieldMap(src=None, tgt="trace_id", static=None),
        ],
    ),

    # ---------- services (shd_host) ----------
    # 状态：Pending→0, Active→1, Suspended→2, Cancelled→4, Fraud→0, Completed→3, Deleted→4
    TableMapper(
        source_table="shd_host",
        target_table="services",
        order=90,
        fields=[
            FieldMap(src="id", tgt="id"),
            FieldMap(src="uid", tgt="user_id", fn=lambda v: to_int(v)),
            FieldMap(src="productid", tgt="product_id", fn=lambda v: to_int(v)),
            FieldMap(src="orderid", tgt="order_id", fn=lambda v: to_int(v) or None),
            FieldMap(src=None, tgt="invoice_id", static=None),
            FieldMap(src="domain", tgt="name", fn=lambda v: to_str(v) or "service"),
            FieldMap(src="domain", tgt="domain", fn=to_str),
            FieldMap(src="billingcycle", tgt="billing_cycle", fn=lambda v: to_str(v) or "monthly"),
            FieldMap(src="amount", tgt="amount", fn=to_float),
            FieldMap(src=None, tgt="locked_pricing", static=None),
            FieldMap(
                src="domainstatus",
                tgt="status",
                fn=lambda v: {"Active": 1, "Pending": 0, "Suspended": 2, "Cancelled": 4, "Fraud": 0, "Completed": 3, "Deleted": 4, "Verifiy_Active": 1, "Overdue_Active": 1, "Issue_Active": 1}.get(str(v).strip(), 0),
            ),
            FieldMap(src=None, tgt="provision_data", static=None),
            FieldMap(src="nextduedate", tgt="expires_at", fn=_ts),
            FieldMap(src="initiative_renew", tgt="auto_renew", fn=lambda v: to_int(v)),
            FieldMap(src="suspendreason", tgt="suspended_reason", fn=nullable_str),
            FieldMap(src="create_time", tgt="created_at", fn=_ts),
            FieldMap(src="update_time", tgt="updated_at", fn=_ts),
            FieldMap(src=None, tgt="deleted_at", static=None),
            FieldMap(src="remark", tgt="remark", fn=lambda v: to_str(v)[:255] if v else None),
            FieldMap(src=None, tgt="operator", static=None),
            FieldMap(src=None, tgt="trace_id", static=None),
        ],
    ),

    # ---------- tickets (shd_ticket) ----------
    TableMapper(
        source_table="shd_ticket",
        target_table="tickets",
        order=100,
        fields=[
            FieldMap(src="id", tgt="id"),
            FieldMap(src="uid", tgt="user_id", fn=lambda v: to_int(v)),
            FieldMap(src="dptid", tgt="department", fn=lambda v: "support" if to_int(v) == 0 else f"dpt_{to_int(v)}"),
            FieldMap(src="title", tgt="subject", fn=lambda v: to_str(v) or "(无主题)"),
            FieldMap(
                src="priority",
                tgt="priority",
                fn=lambda v: {"low": 1, "中": 2, "medium": 2, "high": 3, "紧急": 4, "urgent": 4}.get(str(v).strip().lower(), 2),
            ),
            FieldMap(
                src="status",
                tgt="status",
                fn=lambda v: {"1": 0, "2": 1, "3": 2, "4": 3}.get(str(v).strip(), 0),
            ),
            FieldMap(src="host_id", tgt="service_id", fn=lambda v: to_int(v) or None),
            FieldMap(src="admin_id", tgt="assignee_id", fn=lambda v: to_int(v) or None),
            FieldMap(src="create_time", tgt="created_at", fn=_ts),
            FieldMap(src="update_time", tgt="updated_at", fn=_ts),
            FieldMap(src=None, tgt="close_reason", static=None),
        ],
    ),

    # ---------- ticket_replies (shd_ticket_reply) ----------
    TableMapper(
        source_table="shd_ticket_reply",
        target_table="ticket_replies",
        order=110,
        fields=[
            FieldMap(src="id", tgt="id"),
            FieldMap(src="tid", tgt="ticket_id", fn=lambda v: to_int(v)),
            FieldMap(src="uid", tgt="user_id", fn=lambda v: to_int(v)),
            FieldMap(src="content", tgt="content", fn=lambda v: to_str(v) or ""),
            FieldMap(src="admin_id", tgt="is_staff", fn=lambda v: 1 if to_int(v) else 0),
            FieldMap(src=None, tgt="attachments", static=None),
            FieldMap(src=None, tgt="quote_reply_id", static=None),
            FieldMap(src=None, tgt="recalled_at", static=None),
            FieldMap(src="create_time", tgt="created_at", fn=_ts),
        ],
    ),

    # ---------- content_articles (shd_knowledge_base) ----------
    TableMapper(
        source_table="shd_knowledge_base",
        target_table="content_articles",
        order=120,
        fields=[
            FieldMap(src="id", tgt="id"),
            FieldMap(src=None, tgt="content_type", static="help"),
            FieldMap(src=None, tgt="category_id", static=None),
            FieldMap(src="title", tgt="title", fn=lambda v: to_str(v) or "(无标题)"),
            FieldMap(src="title", tgt="slug"),
            FieldMap(src=None, tgt="summary", static=None),
            FieldMap(src="article", tgt="content", fn=lambda v: to_str(v) or ""),
            FieldMap(src=None, tgt="category_name", static=None),
            FieldMap(src=None, tgt="keywords", static=None),
            FieldMap(src=None, tgt="cover_image", static=None),
            FieldMap(src="hidden", tgt="status", fn=lambda v: 1 if to_int(v) == 0 else 0),  # hidden=0 → published
            FieldMap(src=None, tgt="is_pinned", static=0),
            FieldMap(src=None, tgt="is_recommended", static=0),
            FieldMap(src="order", tgt="sort_order", fn=lambda v: to_int(v)),
            FieldMap(src="views", tgt="view_count", fn=lambda v: to_int(v)),
            FieldMap(src=None, tgt="require_reread_at", static=None),
            FieldMap(src=None, tgt="publish_at", static=None),
            FieldMap(src=None, tgt="last_published_at", static=None),
            FieldMap(src="create_by", tgt="created_by", fn=lambda v: to_int(v) or None),
            FieldMap(src=None, tgt="updated_by", static=None),
            FieldMap(src=None, tgt="operator", static=None),
            FieldMap(src=None, tgt="remark", static=None),
            FieldMap(src=None, tgt="trace_id", static=None),
            FieldMap(src="create_time", tgt="created_at", fn=_ts),
            FieldMap(src=None, tgt="updated_at", static=None),
            FieldMap(src=None, tgt="deleted_at", static=None),
        ],
    ),

    # ---------- content_categories (shd_knowledge_base_cats) ----------
    TableMapper(
        source_table="shd_knowledge_base_cats",
        target_table="content_categories",
        order=121,
        fields=[
            FieldMap(src="id", tgt="id"),
            FieldMap(src=None, tgt="content_type", static="help"),
            FieldMap(src="name", tgt="name", fn=lambda v: to_str(v) or "category"),
            FieldMap(src="name", tgt="slug"),
            FieldMap(src="description", tgt="description", fn=nullable_str),
            FieldMap(src="hidden", tgt="status", fn=invert_bool),
            FieldMap(src=None, tgt="sort_order", static=0),
            FieldMap(src=None, tgt="created_by", static=None),
            FieldMap(src=None, tgt="updated_by", static=None),
            FieldMap(src=None, tgt="created_at", static=None),
            FieldMap(src=None, tgt="updated_at", static=None),
        ],
    ),

    # ---------- coupons (shd_promo_code) ----------
    TableMapper(
        source_table="shd_promo_code",
        target_table="coupons",
        order=130,
        fields=[
            FieldMap(src="id", tgt="id"),
            FieldMap(src=None, tgt="coupon_campaign_id", static=None),
            FieldMap(src="code", tgt="name", fn=lambda v: to_str(v) or "promo"),
            FieldMap(src="code", tgt="code", fn=lambda v: to_str(v) or "PROMO"),
            FieldMap(src=None, tgt="description", static=None),
            FieldMap(src=None, tgt="distribution_type", static="public"),
            FieldMap(src=None, tgt="discount_scope", static="first_month"),
            FieldMap(src="type", tgt="discount_type", fn=lambda v: to_str(v) or "percent"),
            FieldMap(src="value", tgt="discount_value", fn=to_float),
            FieldMap(src=None, tgt="min_amount", static=0),
            FieldMap(src=None, tgt="max_discount_amount", static=None),
            FieldMap(src=None, tgt="billing_cycles", static=None),
            FieldMap(src=None, tgt="product_ids", static=None),
            FieldMap(src="one_time", tgt="first_order_only", fn=lambda v: to_int(v)),
            FieldMap(src="max_times", tgt="total_usage_limit", fn=lambda v: to_int(v) or None),
            FieldMap(src="once_per_client", tgt="per_user_limit", fn=lambda v: 1 if to_int(v) else None),
            FieldMap(src="used", tgt="used_count", fn=lambda v: to_int(v)),
            FieldMap(src=None, tgt="status", static=1),
            FieldMap(src=None, tgt="sort_order", static=0),
            FieldMap(src="start_time", tgt="starts_at", fn=_ts),
            FieldMap(src="expiration_time", tgt="expires_at", fn=_ts),
            FieldMap(src="notes", tgt="remark", fn=lambda v: to_str(v)[:255] if v else None),
            FieldMap(src=None, tgt="operator", static=None),
            FieldMap(src=None, tgt="trace_id", static=None),
            FieldMap(src="start_time", tgt="created_at", fn=_ts),
            FieldMap(src="expiration_time", tgt="updated_at", fn=_ts),
        ],
    ),

    # ---------- activity_logs (shd_activity_log) ----------
    TableMapper(
        source_table="shd_activity_log",
        target_table="activity_logs",
        order=140,
        fields=[
            FieldMap(src="id", tgt="id"),
            FieldMap(src=None, tgt="actor_type", static="system"),
            FieldMap(src="activeid", tgt="actor_id", fn=lambda v: to_int(v) or None),
            FieldMap(src="user", tgt="actor_name", fn=lambda v: to_str(v) or "system"),
            FieldMap(src=None, tgt="module", static="system"),
            FieldMap(src="usertype", tgt="action", fn=lambda v: to_str(v) or "unknown"),
            FieldMap(src="description", tgt="description", fn=lambda v: to_str(v) or ""),
            FieldMap(src=None, tgt="subject_type", static=None),
            FieldMap(src="type_data_id", tgt="subject_id", fn=lambda v: to_int(v) or None),
            FieldMap(src=None, tgt="context", static=None),
            FieldMap(src="ipaddr", tgt="ip_address", fn=nullable_str),
            FieldMap(src="create_time", tgt="created_at", fn=_ts),
            FieldMap(src=None, tgt="updated_at", static=None),
        ],
    ),

    # ---------- operation_logs (shd_admin_log) ----------
    TableMapper(
        source_table="shd_admin_log",
        target_table="operation_logs",
        order=141,
        fields=[
            FieldMap(src="id", tgt="id"),
            FieldMap(src="activeid", tgt="user_id", fn=lambda v: to_int(v) or None),
            FieldMap(src=None, tgt="user_type", static="admin"),
            FieldMap(src="usertype", tgt="action", fn=lambda v: to_str(v) or "login"),
            FieldMap(src=None, tgt="module", static="auth"),
            FieldMap(src="type_data_id", tgt="subject_id", fn=lambda v: to_int(v) or None),
            FieldMap(src=None, tgt="context", static=None),
            FieldMap(src="ipaddress", tgt="ip_address", fn=nullable_str),
            FieldMap(src="logintime", tgt="created_at", fn=_ts),
        ],
    ),

    # ---------- message_logs from shd_email_log ----------
    TableMapper(
        source_table="shd_email_log",
        target_table="message_logs",
        order=150,
        fields=[
            FieldMap(src="id", tgt="id"),
            FieldMap(src=None, tgt="plugin_id", static=None),
            FieldMap(src=None, tgt="driver_key", static=None),
            FieldMap(src=None, tgt="trace_id", static=None),
            FieldMap(src=None, tgt="channel", static="email"),
            FieldMap(src="to", tgt="recipient", fn=lambda v: to_str(v) or ""),
            FieldMap(src=None, tgt="template_code", static=None),
            FieldMap(src="subject", tgt="subject", fn=nullable_str),
            FieldMap(src="message", tgt="content", fn=lambda v: to_str(v) or ""),
            FieldMap(src=None, tgt="params_json", static=None),
            FieldMap(src=None, tgt="provider", static=None),
            FieldMap(src=None, tgt="request_id", static=None),
            FieldMap(src="status", tgt="status", fn=lambda v: "sent" if to_int(v) == 1 else "failed"),
            FieldMap(src="fail_reason", tgt="error_msg", fn=nullable_str),
            FieldMap(src=None, tgt="sent_at", static=None),
            FieldMap(src=None, tgt="origin_type", static=None),
            FieldMap(src="uid", tgt="origin_id", fn=lambda v: to_int(v) or None),
            FieldMap(src="create_time", tgt="created_at", fn=_ts),
            FieldMap(src=None, tgt="updated_at", static=None),
        ],
    ),

    # ---------- message_logs from shd_message_log ----------
    TableMapper(
        source_table="shd_message_log",
        target_table="message_logs",
        order=151,
        fields=[
            FieldMap(src="id", tgt="id"),
            FieldMap(src=None, tgt="plugin_id", static=None),
            FieldMap(src=None, tgt="driver_key", static=None),
            FieldMap(src=None, tgt="trace_id", static=None),
            FieldMap(src=None, tgt="channel", static="sms"),
            FieldMap(src="phone", tgt="recipient", fn=lambda v: to_str(v) or ""),
            FieldMap(src="template_code", tgt="template_code", fn=nullable_str),
            FieldMap(src=None, tgt="subject", static=None),
            FieldMap(src="content", tgt="content", fn=lambda v: to_str(v) or ""),
            FieldMap(src=None, tgt="params_json", static=None),
            FieldMap(src=None, tgt="provider", static=None),
            FieldMap(src=None, tgt="request_id", static=None),
            FieldMap(src="status", tgt="status", fn=lambda v: "sent" if to_int(v) == 1 else "failed"),
            FieldMap(src="fail_reason", tgt="error_msg", fn=nullable_str),
            FieldMap(src=None, tgt="sent_at", static=None),
            FieldMap(src=None, tgt="origin_type", static=None),
            FieldMap(src="uid", tgt="origin_id", fn=lambda v: to_int(v) or None),
            FieldMap(src="create_time", tgt="created_at", fn=_ts),
            FieldMap(src=None, tgt="updated_at", static=None),
        ],
    ),

    # ---------- gateway_logs (shd_pay_log) ----------
    TableMapper(
        source_table="shd_pay_log",
        target_table="gateway_logs",
        order=160,
        fields=[
            FieldMap(src="id", tgt="id"),
            FieldMap(src=None, tgt="plugin_id", static=None),
            FieldMap(src=None, tgt="gateway_key", static=None),
            FieldMap(src="payment", tgt="gateway", fn=lambda v: to_str(v) or "unknown"),
            FieldMap(src=None, tgt="action", static="precreate"),
            FieldMap(src="trans_id", tgt="out_trade_no", fn=lambda v: str(v) if v is not None else None),
            FieldMap(src=None, tgt="trade_no", static=None),
            FieldMap(src="invoice_id", tgt="invoice_id", fn=lambda v: to_int(v) or None),
            FieldMap(src=None, tgt="trace_id", static=None),
            FieldMap(src=None, tgt="request_data", static=None),
            FieldMap(src=None, tgt="response_data", static=None),
            FieldMap(src="status", tgt="result_status", fn=lambda v: "success" if to_str(v) in ("Success", "success", "1") else "pending"),
            FieldMap(src="description", tgt="error_msg", fn=nullable_str),
            FieldMap(src=None, tgt="ip_address", static=None),
            FieldMap(src="create_time", tgt="created_at", fn=_ts),
            FieldMap(src=None, tgt="updated_at", static=None),
        ],
    ),
]


# =============================================================================
# 四、迁移器主类
# =============================================================================

@dataclass
class DbConfig:
    host: str = "127.0.0.1"
    port: int = 3306
    user: str = ""
    password: str = ""
    database: str = ""


@dataclass
class MigrationStats:
    table: str
    target: str
    source_rows: int = 0
    inserted: int = 0
    failed: int = 0
    skipped: int = 0
    errors: list[str] = field(default_factory=list)

    def __str__(self) -> str:
        return (f"  {self.table:32s} → {self.target:30s} "
                f"源行 {self.source_rows:>6}  入库 {self.inserted:>6}  "
                f"失败 {self.failed:>4}  跳过 {self.skipped:>4}")


class Migrator:
    def __init__(
        self,
        dump_path: Path,
        db_config: DbConfig,
        mappers: list[TableMapper] | None = None,
        only_tables: set[str] | None = None,
        batch_size: int = 500,
        dry_run: bool = False,
        truncate_first: bool = False,
    ):
        self.dump_path = dump_path
        self.cfg = db_config
        self.mappers = mappers if mappers is not None else MAPPERS
        self.only_tables = only_tables
        self.batch_size = batch_size
        self.dry_run = dry_run
        self.truncate_first = truncate_first
        self.conn: pymysql.Connection | None = None

        # 按源表分组映射器（同一源表可能映射到多个目标表，如 shd_email_log+shd_message_log→message_logs）
        self._source_to_mappers: dict[str, list[TableMapper]] = {}
        for m in self.mappers:
            self._source_to_mappers.setdefault(m.source_table, []).append(m)

        # 按迁移顺序排序
        self._ordered_mappers = sorted(self.mappers, key=lambda m: m.order)

        self.stats: list[MigrationStats] = []
        self._stats_by_key: dict[tuple[str, str], MigrationStats] = {}

        # 辅助表：用于后处理（不直接迁移，但需扫描保留数据）
        # 例如 shd_pricing 用于 products.pricing JSON 转换
        self.auxiliary_tables: set[str] = {"shd_pricing"}

    def _stats_for(self, source: str, target: str) -> MigrationStats:
        key = (source, target)
        if key not in self._stats_by_key:
            s = MigrationStats(table=source, target=target)
            self._stats_by_key[key] = s
            self.stats.append(s)
        return self._stats_by_key[key]

    def _connect(self) -> pymysql.Connection:
        return pymysql.connect(
            host=self.cfg.host,
            port=self.cfg.port,
            user=self.cfg.user,
            password=self.cfg.password,
            database=self.cfg.database,
            charset="utf8mb4",
            cursorclass=DictCursor,
            autocommit=False,
            connect_timeout=15,
            read_timeout=300,
            write_timeout=300,
        )

    def run(self) -> int:
        logger.info("=== 魔方财务 → 图拉云 turaidc 全量迁移器启动 ===")
        logger.info("源 dump: %s", self.dump_path)
        logger.info("目标库: %s:%s/%s", self.cfg.host, self.cfg.port, self.cfg.database)
        logger.info("模式: %s", "DRY-RUN" if self.dry_run else "EXECUTE")
        logger.info("白名单表: %s", self.only_tables or "(全部)")

        if not self.dump_path.is_file():
            logger.error("dump 文件不存在: %s", self.dump_path)
            return 2

        if not self.dry_run:
            self.conn = self._connect()
            logger.info("已连接目标库")
            # 禁用外键检查：源库与目标库分组结构不同，外键约束会阻止部分 INSERT
            # 迁移完成后在 finally 中重新启用
            with self.conn.cursor() as cur:
                cur.execute("SET FOREIGN_KEY_CHECKS=0")
            self.conn.commit()
            logger.info("已禁用外键检查 (SET FOREIGN_KEY_CHECKS=0)")
            if self.truncate_first:
                self._truncate_targets()

        # 先扫描全部 INSERT 语句，按源表分组缓存
        # 由于同一源表的多条 INSERT 可能分散，先收集
        source_rows: dict[str, list[tuple[list[str], list[tuple]]]] = {}
        logger.info("开始流式扫描源 dump ...")
        scanner = DumpScanner(self.dump_path)
        # 第 1 遍：预扫描 CREATE TABLE 语句，建立表名→列名列表映射
        # （mysqldump 默认 INSERT 不带列名，必须按 CREATE TABLE 顺序映射）
        logger.info("第 1 遍：预扫描 CREATE TABLE 提取列顺序 ...")
        create_cols = scanner.pre_scan_create_tables()
        logger.info("预扫描完成，识别 %d 张表的列结构", len(create_cols))
        # 打印核心表的列数
        for t in ("shd_clients", "shd_products", "shd_orders", "shd_invoices", "shd_host", "shd_ticket"):
            if t in create_cols:
                logger.info("  %s: %d 列", t, len(create_cols[t]))

        # 第 2 遍：流式扫描 INSERT 语句
        logger.info("第 2 遍：扫描 INSERT 语句 ...")
        for table, columns, rows in scanner.scan():
            if self.only_tables and table not in self.only_tables:
                continue
            # 收集映射表或辅助表（如 shd_pricing 用于后处理）
            if table not in self._source_to_mappers and table not in self.auxiliary_tables:
                continue
            source_rows.setdefault(table, []).append((columns, rows))

        logger.info("扫描完成，命中 %d 张源表", len(source_rows))
        for t, batches in source_rows.items():
            total = sum(len(r) for _, r in batches)
            logger.info("  %s: %d 行（%d 批次）", t, total, len(batches))

        # 按迁移顺序处理
        for mapper in self._ordered_mappers:
            if mapper.source_table not in source_rows:
                continue
            self._migrate_table(mapper, source_rows[mapper.source_table])

        # 后处理：products.pricing JSON 转换
        if not self.dry_run and self.conn is not None:
            self._post_process_pricing(source_rows.get("shd_pricing", []))
            # 将 third_product_groups 补齐为 second_product_groups 的副本
            # （源库只有两级组，目标库 products.product_group_id 外键指向 third_product_groups）
            self._sync_third_product_groups(source_rows.get("shd_product_groups", []))

        # 打印汇总
        self._print_summary()

        if self.conn is not None and not self.dry_run:
            # 重新启用外键检查
            with self.conn.cursor() as cur:
                cur.execute("SET FOREIGN_KEY_CHECKS=1")
            self.conn.commit()
            logger.info("已重新启用外键检查")
            self.conn.close()
            logger.info("已关闭数据库连接")
        return 0

    def _truncate_targets(self) -> None:
        """迁移前清空目标表

        使用 TRUNCATE（DDL，快；实测 19 张表 4 秒完成）。
        连接断开时自动重连并继续。
        """
        assert self.conn is not None
        targets = sorted({m.target_table for m in self._ordered_mappers})
        logger.info("清空目标表（%d 张，TRUNCATE）...", len(targets))
        # 外键检查已在 run() 中禁用，保持禁用直到迁移结束
        for t in targets:
            for attempt in range(3):
                try:
                    with self.conn.cursor() as cur:
                        cur.execute(f"TRUNCATE TABLE `{t}`")
                    self.conn.commit()
                    logger.info("  TRUNCATE %s", t)
                    break
                except Exception as e:
                    if attempt == 2:
                        logger.warning("  TRUNCATE %s 失败（3 次重试）: %s", t, e)
                        break
                    logger.warning("  TRUNCATE %s 失败，重连重试: %s", t, e)
                    self._ensure_conn()
        self.conn.commit()

    def _ensure_conn(self) -> None:
        """确保数据库连接可用；连接失效时自动重建，同步禁用外键检查"""
        try:
            # ping 无 reconnect 参数，失败则走新建连接
            if self.conn is None:
                raise RuntimeError("conn is None")
            self.conn.ping()
        except Exception:
            try:
                self.conn = self._connect()
                with self.conn.cursor() as cur:
                    cur.execute("SET FOREIGN_KEY_CHECKS=0")
                self.conn.commit()
            except Exception as err:
                logger.warning("重建连接失败: %s", err)

    def _post_map_fix(self, mapper: "TableMapper", mapped: dict) -> None:
        """行级后处理：修复特定表的数据兼容性问题"""
        tgt = mapper.target_table

        if tgt == "invoices":
            # invoice_no 为无效值时（空/None/字符串"NULL"/INV-0/纯0），用 id 生成唯一值
            inv_no = to_str(mapped.get("invoice_no"))
            norm = inv_no.strip().upper() if inv_no else ""
            INVALID_NO = {"", "INV-0", "0", "NULL", "NONE", "N/A", "-"}
            if not inv_no or norm in INVALID_NO:
                inv_id = to_int(mapped.get("id")) or 0
                mapped["invoice_no"] = f"INV-{inv_id:08d}"

        elif tgt == "orders":
            # order_no 为空时用 id 生成唯一后缀；源数据存在少量重复 order_no，由单行降级过滤
            ono = to_str(mapped.get("order_no"))
            if not ono or ono.upper() in ("NULL", "0", ""):
                oid = to_int(mapped.get("id")) or 0
                mapped["order_no"] = f"ORD-{oid:08d}"

        elif tgt == "operation_logs":
            # 不迁移源 id，避免与应用运行期间写入的日志主键冲突
            mapped.pop("id", None)

    def _migrate_table(
        self,
        mapper: TableMapper,
        batches: list[tuple[list[str], list[tuple]]],
    ) -> None:
        stats = self._stats_for(mapper.source_table, mapper.target_table)
        logger.info("[%s → %s] 开始迁移", mapper.source_table, mapper.target_table)

        for columns, rows in batches:
            for row in rows:
                stats.source_rows += 1
                # 将行打包为 dict（按列名）
                source_dict: dict[str, Any] = {}
                if columns:
                    for i, col in enumerate(columns):
                        source_dict[col] = row[i] if i < len(row) else None
                else:
                    # 无列名：按位置拿不到字段名，跳过
                    stats.skipped += 1
                    stats.errors.append(f"无列名 INSERT 跳过，row_count={len(row)}")
                    continue

                mapped = mapper.map_row(source_dict)
                # 简单校验
                if not mapped:
                    stats.skipped += 1
                    continue

                # 行级后处理：修复特定表的数据兼容性
                self._post_map_fix(mapper, mapped)

                if self.dry_run:
                    stats.inserted += 1
                    continue

                # 实际写入由批量缓冲处理
                self._buffer_insert(mapper, mapped, stats)

        # flush 剩余
        if not self.dry_run:
            self._flush_buffer(mapper, stats)

        logger.info("[%s → %s] 完成", mapper.source_table, mapper.target_table)

    # 批量插入缓冲（per-mapper）
    _buffers: dict[str, list[dict]] = {}

    def _buffer_insert(self, mapper: TableMapper, row: dict, stats: MigrationStats) -> None:
        key = f"{mapper.source_table}::{mapper.target_table}"
        buf = self._buffers.setdefault(key, [])
        buf.append(row)
        if len(buf) >= self.batch_size:
            self._flush_buffer(mapper, stats)

    def _flush_buffer(self, mapper: TableMapper, stats: MigrationStats) -> None:
        key = f"{mapper.source_table}::{mapper.target_table}"
        buf = self._buffers.pop(key, [])
        if not buf:
            return
        assert self.conn is not None
        try:
            self._batch_insert(mapper.target_table, buf)
            stats.inserted += len(buf)
        except Exception as e:
            stats.failed += len(buf)
            err_msg = f"批量插入失败（{len(buf)} 行）: {type(e).__name__}: {e}"
            stats.errors.append(err_msg)
            logger.error("[%s] %s", mapper.target_table, err_msg)
            # 尝试重连后重试一次批量
            self._ensure_conn()
            try:
                self._batch_insert(mapper.target_table, buf)
                stats.inserted += len(buf)
                stats.failed -= len(buf)
                logger.info("[%s] 重连后批量插入成功 %d 行", mapper.target_table, len(buf))
                return
            except Exception as e3:
                logger.warning("[%s] 重连后仍失败: %s", mapper.target_table, e3)
            # 尝试单行降级
            logger.info("[%s] 尝试单行降级插入 ...", mapper.target_table)
            ok = 0
            for r in buf:
                try:
                    self._batch_insert(mapper.target_table, [r])
                    ok += 1
                except Exception as e2:
                    stats.failed += 0  # 已计
                    if len(stats.errors) < 50:
                        stats.errors.append(f"单行失败 id={r.get('id')}: {e2}")
            stats.inserted += ok
            stats.failed -= ok  # 调整：单行成功的从失败中剔除
            logger.info("[%s] 单行降级完成，成功 %d/%d", mapper.target_table, ok, len(buf))

    def _batch_insert(self, table: str, rows: list[dict]) -> None:
        if not rows:
            return
        assert self.conn is not None
        # 连接保活：远程数据库可能因超时断开
        self._ensure_conn()

        cols = list(rows[0].keys())
        # 统一所有行的列（处理可选字段差异）
        all_cols = set(cols)
        for r in rows[1:]:
            all_cols.update(r.keys())
        cols = sorted(all_cols)
        placeholders = ",".join(["%s"] * len(cols))
        col_names = ",".join(f"`{c}`" for c in cols)
        sql = f"INSERT INTO `{table}` ({col_names}) VALUES ({placeholders})"
        values: list[tuple] = []
        for r in rows:
            row = tuple(self._serialize_value(r.get(c)) for c in cols)
            values.append(row)
        with self.conn.cursor() as cur:
            cur.executemany(sql, values)
            affected = cur.rowcount
        self.conn.commit()
        # 调试：如果 affected 与 len(rows) 不匹配，打印警告
        if affected != len(rows) and affected != -1:
            logger.warning("[%s] executemany 影响 %d 行，但预期 %d 行", table, affected, len(rows))

    def _sync_third_product_groups(self, product_groups_batches: list[tuple[list[str], list[tuple]]]) -> None:
        """把 shd_product_groups 的数据复制到 third_product_groups

        源库只有两级组（first_groups + product_groups）。
        目标库 products.product_group_id 外键指向 third_product_groups.id。
        为使外键约束满足，把二级组数据也写入 third_product_groups。
        """
        if not product_groups_batches:
            logger.info("[third_groups] 源 shd_product_groups 无数据，跳过")
            return
        assert self.conn is not None
        logger.info("[third_groups] 同步二级组数据到 third_product_groups")

        # 清空 third_product_groups（外键检查已禁用）
        with self.conn.cursor() as cur:
            cur.execute("TRUNCATE TABLE third_product_groups")

        # 从已迁移的 second_product_groups 表读取数据
        with self.conn.cursor() as cur:
            cur.execute("""
                SELECT id, first_product_group_id, name, slug, description,
                       sort_order, is_visible, created_at, updated_at
                FROM second_product_groups
            """)
            rows = cur.fetchall()

        if not rows:
            logger.info("[third_groups] second_product_groups 表为空，跳过")
            return

        # 写入 third_product_groups
        cols = ["id", "second_product_group_id", "name", "slug", "description",
                "sort_order", "is_visible", "created_at", "updated_at"]
        placeholders = ",".join(["%s"] * len(cols))
        col_names = ",".join(f"`{c}`" for c in cols)
        sql = f"INSERT INTO `third_product_groups` ({col_names}) VALUES ({placeholders})"
        values = [
            (r["id"], r["first_product_group_id"], r["name"], r["slug"],
             r["description"], r["sort_order"], r["is_visible"],
             r["created_at"], r["updated_at"])
            for r in rows
        ]
        with self.conn.cursor() as cur:
            cur.executemany(sql, values)
        self.conn.commit()
        logger.info("[third_groups] 完成，写入 %d 行", len(values))

    @staticmethod
    def _serialize_value(v: Any) -> Any:
        """将 Python 对象转换为 pymysql 可识别的值"""
        if v is None:
            return None
        if isinstance(v, (dict, list)):
            return json.dumps(v, ensure_ascii=False)
        if isinstance(v, bool):
            return 1 if v else 0
        return v

    def _post_process_pricing(self, pricing_batches: list[tuple[list[str], list[tuple]]]) -> None:
        """从 shd_pricing 表合并周期价格到 products.pricing 字段

        源 shd_pricing 结构：
        - type: 'product' 表示产品定价
        - relid: 关联 products.id
        - currency: 货币 ID（默认 0）
        - 多个周期列：monthly/quarterly/semiannually/annually/biennially/triennially/hour/day/ontrial/onetime

        目标 products.pricing 为扁平格式 {周期: 金额}；一次性初装费写入 products.setup_fee。
        """
        if not pricing_batches:
            logger.info("[pricing] 源 shd_pricing 表无数据，跳过 JSON 转换")
            return
        assert self.conn is not None
        logger.info("[pricing] 开始处理 products.pricing JSON 转换")

        # 周期字段 → JSON key 映射
        cycle_fields = {
            "monthly": "monthly",
            "quarterly": "quarterly",
            "semiannually": "semiannually",
            "annually": "annually",
            "biennially": "biennially",
            "triennially": "triennially",
            "hour": "hour",
            "day": "day",
            "ontrial": "ontrial",
            "onetime": "onetime",
        }

        # 按 relid 聚合
        pricing_by_product: dict[int, list[dict]] = {}
        for columns, rows in pricing_batches:
            for row in rows:
                d = dict(zip(columns, row))
                if to_str(d.get("type")) != "product":
                    continue
                relid = to_int(d.get("relid"))
                if relid <= 0:
                    continue
                pricing_by_product.setdefault(relid, []).append(d)

        logger.info("[pricing] 涉及 %d 个产品的定价记录", len(pricing_by_product))

        # 先构建全部 UPDATE 参数，再分批 executemany（每批 100，避免大事务+连接超时）
        updates: list[tuple] = []
        for product_id, rows in pricing_by_product.items():
            # 合并同一产品的多条定价记录（不同 currency），取 currency=0 或第一条
            row = rows[0]
            if len(rows) > 1:
                for r in rows:
                    if to_int(r.get("currency")) == 0:
                        row = r
                        break

            pricing_json: dict[str, Any] = {}
            setup_fee = to_float(row.get("osetupfee", 0))

            # 目标 products.pricing 为扁平格式 {周期: 金额}（当前模型与结算链路均按扁平解析）
            for src_col, json_key in cycle_fields.items():
                val = to_float(row.get(src_col), -1)
                if val > 0:
                    pricing_json[json_key] = val

            if not pricing_json:
                continue
            updates.append((json.dumps(pricing_json, ensure_ascii=False), setup_fee, product_id))

        if not updates:
            logger.info("[pricing] 没有可写入的定价，跳过")
            return

        sql = "UPDATE `products` SET `pricing` = %s, `setup_fee` = %s WHERE `id` = %s"
        updated = 0
        batch = 100
        for i in range(0, len(updates), batch):
            chunk = updates[i:i + batch]
            # 连接保活
            self._ensure_conn()
            with self.conn.cursor() as cur:
                cur.executemany(sql, chunk)
                updated += cur.rowcount
            self.conn.commit()
            if (i // batch) % 5 == 0:
                logger.info("[pricing] 进度 %d/%d", i + len(chunk), len(updates))

        logger.info("[pricing] 完成，更新 %d 个产品的 pricing JSON", updated)

    def _print_summary(self) -> None:
        print("\n" + "=" * 90)
        print("迁移汇总")
        print("=" * 90)
        total_src = total_ins = total_fail = total_skip = 0
        for s in self.stats:
            print(s)
            total_src += s.source_rows
            total_ins += s.inserted
            total_fail += s.failed
            total_skip += s.skipped
        print("-" * 90)
        print(f"  合计:  源行 {total_src}  入库 {total_ins}  失败 {total_fail}  跳过 {total_skip}")

        # 错误打印前 10 条
        all_errors = [e for s in self.stats for e in s.errors]
        if all_errors:
            print("\n错误样例（前 10）:")
            for e in all_errors[:10]:
                print(f"  - {e}")


# =============================================================================
# 五、入口
# =============================================================================

CONFIG_ENV_PREFIX = "MOFANG_MIGRATE_"
DEFAULT_CONFIG_PATH = Path(__file__).resolve().parent / "mofang_migrate.conf"


def _env_value(key: str) -> str | None:
    """读取环境变量 MOFANG_MIGRATE_<KEY>（key 大写），未设置返回 None"""
    return os.environ.get(CONFIG_ENV_PREFIX + key.upper()) or None


def _load_config_file(config_path: Path) -> dict[str, str]:
    """解析 mofang_migrate.conf（INI 格式，[db] / [source] 两节）

    示例：
        [db]
        host = 43.240.220.81
        port = 3306
        user = turaidc
        password = xxx
        database = turaidc

        [source]
        dump = e:\\TuraIDC\\25y_xxx_mysql_data.sql
    """
    if not config_path.is_file():
        return {}
    parser = RawConfigParser()
    try:
        parser.read(config_path, encoding="utf-8")
    except Exception as e:
        logger.warning("配置文件 %s 解析失败，忽略: %s", config_path, e)
        return {}

    result: dict[str, str] = {}
    for section in ("db", "source"):
        if parser.has_section(section):
            for key in parser.options(section):
                value = parser.get(section, key).strip()
                if value:
                    result[f"{section}.{key}"] = value
    return result


def _discover_dump_files() -> list[Path]:
    """自动发现项目根 / 脚本目录下的魔方财务 dump 文件（25y_*.sql）"""
    script_dir = Path(__file__).resolve().parent
    project_root = script_dir.parent.parent
    candidates: dict[str, Path] = {}
    for base in (project_root, script_dir):
        for pattern in ("25y_*.sql", "*_mysql_data_*.sql"):
            for p in sorted(base.glob(pattern)):
                candidates[str(p.resolve())] = p.resolve()
    return sorted(candidates.values(), key=lambda p: p.stat().st_mtime, reverse=True)


def _pick_from_list(items: list[str], prompt: str, default_index: int = 0) -> str:
    print(prompt)
    for i, item in enumerate(items, start=1):
        marker = " (默认)" if i - 1 == default_index else ""
        print(f"  {i}. {item}{marker}")
    while True:
        try:
            raw = input(f"请输入序号 [1-{len(items)}]（回车选默认）: ").strip()
        except EOFError:
            print("  无输入，使用默认项。")
            return items[default_index]
        if raw == "":
            return items[default_index]
        try:
            idx = int(raw)
        except ValueError:
            print("  输入无效，请重新输入。")
            continue
        if 1 <= idx <= len(items):
            return items[idx - 1]
        print("  序号超出范围，请重新输入。")


def main() -> int:
    logging.basicConfig(
        level=logging.INFO,
        format="[%(asctime)s] %(levelname)s %(message)s",
        datefmt="%H:%M:%S",
    )

    p = argparse.ArgumentParser(description="魔方财务 → 图拉云 turaidc 全量定制迁移器")
    p.add_argument("--dump", help="源 MySQL dump 路径（缺省时自动发现 25y_*.sql 或读取配置）")
    p.add_argument("--config", default=str(DEFAULT_CONFIG_PATH), help="配置文件路径（INI，默认脚本同目录 mofang_migrate.conf）")
    p.add_argument("--host", help="目标库主机")
    p.add_argument("--port", type=int, help="目标库端口")
    p.add_argument("--user", help="目标库用户")
    p.add_argument("--password", help="目标库密码（也可用环境变量 MOFANG_MIGRATE_PASSWORD，避免明文入命令行）")
    p.add_argument("--database", help="目标库名")
    p.add_argument("--tables", default="", help="逗号分隔的白名单表名（默认全部）")
    p.add_argument("--batch-size", type=int, default=500)
    p.add_argument("--dry-run", action="store_true", help="仅预演，不写库")
    p.add_argument("--truncate", action="store_true", help="迁移前清空目标表（危险！仅干净库使用）")
    args = p.parse_args()

    # 合并配置：命令行 > 环境变量 > 配置文件
    file_cfg = _load_config_file(Path(args.config))

    def resolve(cli_value, file_key: str) -> str | None:
        env_key = file_key.split(".", 1)[1].upper()
        if cli_value not in (None, ""):
            return str(cli_value)
        env = _env_value(env_key)
        if env:
            return env
        return file_cfg.get(file_key)

    host = resolve(args.host, "db.host") or "127.0.0.1"
    port_raw = resolve(args.port if args.port else None, "db.port") or "3306"
    try:
        port = int(port_raw)
    except ValueError:
        logger.warning("端口值无效: %s，使用默认 3306", port_raw)
        port = 3306
    user = resolve(args.user, "db.user")
    password = resolve(args.password, "db.password")
    database = resolve(args.database, "db.database") or "turaidc"

    # dump 路径：命令行 > 环境变量 > 配置文件 > 自动发现
    dump = resolve(args.dump, "source.dump")
    if dump:
        dump_path = Path(dump)
    else:
        discovered = _discover_dump_files()
        if not discovered:
            logger.error(
                "未找到 dump 文件。请用 --dump 指定源 dump 路径，"
                "或在配置文件中配置 [source] dump，"
                "或把 25y_*.sql 放到项目根目录/backend/scripts 下。"
            )
            return 2
        if len(discovered) == 1:
            dump_path = discovered[0]
            logger.info("自动发现 dump: %s", dump_path)
        elif sys.stdin.isatty():
            chosen = _pick_from_list(
                [str(p) for p in discovered],
                "发现多个 dump 文件，请选择:",
            )
            dump_path = Path(chosen)
        else:
            dump_path = discovered[0]
            logger.warning(
                "检测到 %d 个 dump 文件，非交互模式默认选用最新的: %s",
                len(discovered),
                dump_path,
            )

    # 缺少连接凭据时给出清晰指引（凭据来自命令行/环境变量/配置文件）
    if not user or not password:
        logger.error(
            "缺少目标库连接凭据（user/password）。请用 --user/--password 指定，"
            "或设置环境变量 %sUSER / %sPASSWORD，"
            "或在配置文件 %s 的 [db] 节填写。",
            CONFIG_ENV_PREFIX,
            CONFIG_ENV_PREFIX,
            DEFAULT_CONFIG_PATH,
        )
        return 2

    only_tables: set[str] | None = None
    if args.tables:
        only_tables = {t.strip() for t in args.tables.split(",") if t.strip()}

    cfg = DbConfig(
        host=host,
        port=port,
        user=user,
        password=password,
        database=database,
    )

    migrator = Migrator(
        dump_path=dump_path,
        db_config=cfg,
        only_tables=only_tables,
        batch_size=args.batch_size,
        dry_run=args.dry_run,
        truncate_first=args.truncate,
    )
    return migrator.run()


if __name__ == "__main__":
    raise SystemExit(main())
