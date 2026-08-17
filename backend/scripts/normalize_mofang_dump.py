#!/usr/bin/env python3
"""标准化魔方财务/二五云 MySQL dump：去掉表前缀、修正少量表名。

用法:
    python backend/scripts/normalize_mofang_dump.py \
        --src 25y_2026-08-17_18-47-55_mysql_data_ji0VZ.sql \
        --out mofang_normalized.sql
"""
from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

# 遇到这些前缀就剥离，让表名成为"标准"无前缀表
PREFIX_STRIP_ORDER: tuple[str, ...] = (
    "shd_",
    "mccloud_",
    "ewytemplate_",
    "auth_",
    "25y_",
)

# 额外的显式重命名（剥离前缀之后应用）
TABLE_RENAMES_AFTER_STRIP: dict[str, str] = {
    # 魔方单数/不同名 -> 目标 Laravel 复数/新名
    "user": "users",
    "ticket": "tickets",
    "configuration": "settings",
    "clients": "clients_legacy",  # 目标端无 clients，先改名避免污染
    "host": "hosts_legacy",
    "servers": "upstream_hosts_legacy",
    "currencies": "currencies_legacy",
    "role": "roles_legacy",
    "admin_log": "operation_logs",   # 操作日志：旧 admin_log 映射到目标 operation_logs（字段需后续调整）
    "email_log": "message_logs",     # 邮件日志（仅在列兼容时才会被导入）
    "message_log": "message_logs_srcdup",  # 已有 message_logs，做去重留源
    "base_info": "base_info_legacy",
    "activity_log": "activity_logs",
    "system_log": "system_logs_legacy",
    "customfields": "customfields_legacy",
    "customfieldsvalues": "customfieldsvalues_legacy",
    "contacts": "contacts_legacy",
    "knowledge_base": "content_articles",
    "knowledge_base_cats": "content_categories",
    "payment_gateways": "payment_gateways_legacy",
    "pricing": "pricing_legacy",
    "coupon": "coupon_campaigns",
    "promo_code": "coupons_src",
    "voucher": "voucher_legacy",
    "upper_reaches": "suppliers",  # 旧上游 -> 新 suppliers
    "user_product_bates": "user_product_bates_legacy",
    "user_product_groups": "user_product_groups_legacy",
    "user_products": "services",   # 用户产品 -> 新 services 表
    "pay_log": "gateway_logs",     # 支付流水 -> gateway_logs
}

# dump 里出现的 SQL 上下文正则
CREATE_TABLE_RE = re.compile(r"(CREATE TABLE\s+)`([^`]+)`(\s*\()")
DROP_TABLE_RE = re.compile(r"(DROP TABLE\s+(?:IF EXISTS\s+)?)`([^`]+)`(\s*;)")
ALTER_TABLE_RE = re.compile(r"(ALTER TABLE\s+)`([^`]+)`(\s+)")
LOCK_TABLES_RE = re.compile(r"(LOCK TABLES\s+)`([^`]+)`(\s+)")
UNLOCK_TABLES_RE = re.compile(r"(UNLOCK TABLES\s*;)")
INSERT_INTO_RE = re.compile(r"(INSERT INTO\s+)`([^`]+)`(\s+VALUES|\s*\()")
ALTER_TABLE_ADD_RE = re.compile(r"(ALTER TABLE\s+)`([^`]+)`(\s+)")
REFERENCES_RE = re.compile(r"(REFERENCES\s+)`([^`]+)`(\s*\()")
FOREIGN_KEY_RE = re.compile(r"(CONSTRAINT\s+`[^`]+`\s+FOREIGN KEY\s*\([^)]+\)\s+REFERENCES\s+)`([^`]+)`(\s*\([^)]+\))")


def strip_prefix(name: str) -> str:
    for prefix in PREFIX_STRIP_ORDER:
        if name.startswith(prefix):
            return name[len(prefix):]
    return name


def rename_table(stripped: str) -> str:
    return TABLE_RENAMES_AFTER_STRIP.get(stripped, stripped)


def normalize_table_name(name: str) -> str:
    stripped = strip_prefix(name)
    return rename_table(stripped)


def transform_line(line: str) -> str:
    # 按优先级处理最常见的 SQL
    if "CREATE TABLE" in line:
        line = CREATE_TABLE_RE.sub(
            lambda m: f"{m.group(1)}`{normalize_table_name(m.group(2))}`{m.group(3)}",
            line,
        )
    if "DROP TABLE" in line:
        line = DROP_TABLE_RE.sub(
            lambda m: f"{m.group(1)}`{normalize_table_name(m.group(2))}`{m.group(3)}",
            line,
        )
    if "LOCK TABLES" in line:
        line = LOCK_TABLES_RE.sub(
            lambda m: f"{m.group(1)}`{normalize_table_name(m.group(2))}`{m.group(3)}",
            line,
        )
    if "INSERT INTO" in line:
        line = INSERT_INTO_RE.sub(
            lambda m: f"{m.group(1)}`{normalize_table_name(m.group(2))}`{m.group(3)}",
            line,
        )
    if "ALTER TABLE" in line:
        line = ALTER_TABLE_RE.sub(
            lambda m: f"{m.group(1)}`{normalize_table_name(m.group(2))}`{m.group(3)}",
            line,
        )
    if "REFERENCES" in line:
        line = REFERENCES_RE.sub(
            lambda m: f"{m.group(1)}`{normalize_table_name(m.group(2))}`{m.group(3)}",
            line,
        )
    if "FOREIGN KEY" in line:
        line = FOREIGN_KEY_RE.sub(
            lambda m: f"{m.group(1)}`{normalize_table_name(m.group(2))}`{m.group(3)}",
            line,
        )
    return line


def main() -> int:
    parser = argparse.ArgumentParser(description="魔方财务 dump 标准化（去表前缀 + 关键表重命名）")
    parser.add_argument("--src", required=True, help="原始 dump 路径")
    parser.add_argument("--out", required=True, help="标准化后的 dump 路径")
    parser.add_argument("--encoding", default="utf-8", help="读入编码，默认 utf-8（错误用 replace）")
    args = parser.parse_args()

    src = Path(args.src)
    out = Path(args.out)
    if not src.is_file():
        print(f"[normalize] 源文件不存在: {src}", file=sys.stderr)
        return 2
    out.parent.mkdir(parents=True, exist_ok=True)

    rename_report: dict[tuple[str, str], int] = {}
    total_lines = 0
    changed_lines = 0

    with src.open("r", encoding=args.encoding, errors="replace", newline="") as fin, \
            out.open("w", encoding="utf-8", newline="\n") as fout:
        for raw in fin:
            total_lines += 1
            new = transform_line(raw)
            if new != raw:
                changed_lines += 1
                # 记录改名（从 CREATE TABLE 语句里抓）
                m = CREATE_TABLE_RE.search(raw) or DROP_TABLE_RE.search(raw) or LOCK_TABLES_RE.search(raw) or INSERT_INTO_RE.search(raw)
                if m:
                    old_name = m.group(2)
                    new_name = normalize_table_name(old_name)
                    if old_name != new_name:
                        rename_report[(old_name, new_name)] = rename_report.get((old_name, new_name), 0) + 1
            fout.write(new)

    print(f"[normalize] 总行数: {total_lines}")
    print(f"[normalize] 修改行数: {changed_lines}")
    print(f"[normalize] 表名映射报告 ({len(rename_report)} 种):")
    for (old, new), cnt in sorted(rename_report.items()):
        print(f"  {old:50s} -> {new:40s}  x{cnt}")
    print(f"[normalize] 输出: {out.resolve()}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
