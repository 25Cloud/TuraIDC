#!/usr/bin/env python3
"""源 MySQL dump ↔ 目标 Laravel idc/turatest 库 dry-run 匹配分析。

不写入，只报告：
- 目标表在源里是否有同名表
- 源表与目标表的列名交集/差集（必选列缺失会红）
- INSERT 行数估算
- 产品组分裂（product_groups→3 张物理表）是否可执行

用法:
    python backend/scripts/dryrun_migration_analysis.py \
        --dump D:\\turaidc_migration\\mofang_normalized_20260817.sql \
        --target-db turatest
"""
from __future__ import annotations

import argparse
import os
import re
import sys
from dataclasses import dataclass, field
from pathlib import Path

import pymysql
from pymysql.cursors import DictCursor

TEXT_TYPES = {"char", "varchar", "tinytext", "text", "mediumtext", "longtext", "enum", "set"}
NUMERIC_TYPES = {"tinyint", "smallint", "mediumint", "int", "integer", "bigint", "decimal", "numeric", "float", "double", "real", "bit"}
TEMPORAL_TYPES = {"date", "datetime", "timestamp", "time", "year"}

TARGET_CORE_NONNULL_TABLES = [
    "users", "products", "orders", "invoices", "services", "tickets",
    "suppliers", "settings", "first_product_groups", "second_product_groups",
    "third_product_groups",
]


@dataclass
class DbConfig:
    host: str
    port: int
    username: str
    password: str
    database: str


@dataclass
class ColumnInfo:
    name: str
    ctype: str
    nullable: bool
    default: str | None
    extra: str

    @property
    def can_omit(self) -> bool:
        return self.nullable or self.default is not None or "auto_increment" in self.extra.lower()


@dataclass
class SourceTable:
    name: str
    columns: list[str] = field(default_factory=list)
    row_estimate: int = 0


def read_env(env_file: Path) -> dict[str, str]:
    values: dict[str, str] = {}
    for raw in env_file.read_text(encoding="utf-8", errors="replace").splitlines():
        line = raw.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        k, v = line.split("=", 1)
        k, v = k.strip(), v.strip()
        if len(v) >= 2 and v[0] == v[-1] and v[0] in {"'", '"'}:
            v = v[1:-1]
        values[k] = v
    return values


def load_db_config(env_file: Path, target_db: str) -> DbConfig:
    env = read_env(env_file)
    if env.get("DB_CONNECTION", "mysql") != "mysql":
        raise RuntimeError(f"仅支持 mysql；实际 DB_CONNECTION={env.get('DB_CONNECTION')!r}")
    return DbConfig(
        host=env.get("DB_HOST", "127.0.0.1").strip() or "127.0.0.1",
        port=int(env.get("DB_PORT", "3306") or "3306"),
        username=env.get("DB_USERNAME", "").strip(),
        password=env.get("DB_PASSWORD", ""),
        database=(target_db or env.get("DB_DATABASE", "")).strip(),
    )


def parse_normalized_dump(dump_path: Path) -> dict[str, SourceTable]:
    text = dump_path.read_text(encoding="utf-8", errors="replace")
    tables: dict[str, SourceTable] = {}

    schema_pattern = re.compile(r"CREATE TABLE `([^`]+)` \((.*?)\n\) ENGINE=", re.S)
    for m in schema_pattern.finditer(text):
        tname = m.group(1)
        cols: list[str] = []
        for raw in m.group(2).splitlines():
            line = raw.strip().rstrip(",")
            cm = re.match(r"`([^`]+)`\s+", line)
            if cm:
                cols.append(cm.group(1))
        tables[tname] = SourceTable(name=tname, columns=cols)

    insert_pattern = re.compile(r"INSERT INTO `([^`]+)`\s+(?:VALUES)?\s*\(", re.IGNORECASE)
    for m in insert_pattern.finditer(text):
        t = m.group(1)
        if t in tables:
            tables[t].row_estimate += 1

    # 修正：估算 INSERT INTO rows 可能是 VALUES(...),(...),(...)
    values_pattern = re.compile(
        r"INSERT INTO `([^`]+)`\s+VALUES\s*(.+?);",
        re.IGNORECASE | re.S,
    )
    for tname in list(tables.keys()):
        tables[tname].row_estimate = 0
    for m in values_pattern.finditer(text):
        t = m.group(1)
        if t not in tables:
            continue
        segment = m.group(2)
        cnt = 0
        in_s = False
        in_d = False
        depth = 0
        esc = False
        for ch in segment:
            if esc:
                esc = False
                continue
            if ch == "\\":
                esc = True
                continue
            if ch == "'" and not in_d:
                in_s = not in_s
                continue
            if ch == '"' and not in_s:
                in_d = not in_d
                continue
            if in_s or in_d:
                continue
            if ch == "(":
                depth += 1
                if depth == 1:
                    cnt += 1
            elif ch == ")":
                depth -= 1
        tables[t].row_estimate += cnt
    return tables


def fetch_target_schema(cfg: DbConfig) -> dict[str, dict[str, ColumnInfo]]:
    result: dict[str, dict[str, ColumnInfo]] = {}
    with pymysql.connect(
        host=cfg.host, port=cfg.port, user=cfg.username, password=cfg.password,
        database=cfg.database, charset="utf8mb4", cursorclass=DictCursor,
        connect_timeout=10,
    ) as c:
        with c.cursor() as cur:
            cur.execute(f"SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s ORDER BY TABLE_NAME, ORDINAL_POSITION", (cfg.database,))
            for row in cur.fetchall():
                t = row["TABLE_NAME"]
                info = ColumnInfo(
                    name=row["COLUMN_NAME"],
                    ctype=row["DATA_TYPE"],
                    nullable=(row["IS_NULLABLE"] == "YES"),
                    default=row["COLUMN_DEFAULT"],
                    extra=(row.get("EXTRA") or ""),
                )
                result.setdefault(t, {})[info.name] = info
    return result


def main() -> int:
    p = argparse.ArgumentParser(description="dry-run 迁移兼容性分析")
    p.add_argument("--dump", required=True, help="标准化后的 dump 路径")
    p.add_argument("--env", default="backend/.env", help="后端 .env 路径")
    p.add_argument("--target-db", default="", help="目标库（覆盖 .env 的 DB_DATABASE）")
    args = p.parse_args()

    dump_path = Path(args.dump)
    if not dump_path.is_file():
        print(f"[dryrun] dump 不存在: {dump_path}", file=sys.stderr)
        return 2

    cfg = load_db_config(Path(args.env), args.target_db)
    print(f"[dryrun] 目标库: {cfg.host}:{cfg.port}/{cfg.database} (user={cfg.username})")

    print("[dryrun] 解析源 dump 表结构 ...")
    source = parse_normalized_dump(dump_path)
    print(f"[dryrun] 源表数: {len(source)}")

    print("[dryrun] 读取目标库 schema ...")
    target = fetch_target_schema(cfg)
    print(f"[dryrun] 目标表数: {len(target)}")

    print("\n=== 目标表在源中的匹配情况 ===")
    matches = 0
    for tname in sorted(target.keys()):
        if tname in ("migrations",):
            continue
        if tname in source:
            src_cols = set(source[tname].columns)
            tgt_cols = set(target[tname].keys())
            inter = tgt_cols & src_cols
            missing_req = [
                c for c in (tgt_cols - src_cols)
                if not target[tname][c].can_omit
            ]
            miss_all = sorted(tgt_cols - src_cols)
            total_rows = source[tname].row_estimate
            tag = ""
            if missing_req:
                tag = f"  ⚠ 缺必选列: {missing_req}"
            else:
                matches += 1
            print(f"  ✓ {tname:40s}  交集{len(inter):>3}/{len(tgt_cols):>3}  源估算行{total_rows:>7}{tag}")
            if miss_all and not missing_req:
                print(f"      可省略目标缺列: {sorted(miss_all)}")
        else:
            # 检查 product_groups 特例
            if tname in ("first_product_groups", "second_product_groups", "third_product_groups") and "product_groups" in source:
                total_rows = source["product_groups"].row_estimate
                print(f"  ◇ {tname:40s}  ← product_groups 分裂（源共 {total_rows} 行，按 level 拆分）")
                matches += 1
            elif tname == "gateway_logs" and "gateway_logs" in source:
                total_rows = source["gateway_logs"].row_estimate
                print(f"  ✓ {tname:40s}  ← pay_log 重命名（行 {total_rows}）")
                matches += 1
            else:
                print(f"  ✗ {tname:40s}  源中无对应表（空表）")

    print(f"\n[dryrun] 目标 62 表中，可匹配: {matches}；保留空: {max(0, len(target) - 1 - matches)}")

    print("\n=== 关键源表（目标端不存在的旧核心表/将被丢弃）===")
    old_core = [
        t for t in sorted(source.keys())
        if (t.endswith("_legacy") or t in ("base_info", "clients_oauth", "auth_access", "auth_rule",
                                            "host", "host_category", "host_config_options",
                                            "plugin", "payment_gateways", "email_templates",
                                            "knowledge_base_links", "knowledge_base_tags"))
        and source[t].row_estimate > 0
    ]
    if old_core:
        for t in old_core:
            print(f"  - {t:45s}  约 {source[t].row_estimate:>6} 行  {source[t].columns[:8]}")
    else:
        print("  (无大于 0 行的旧核心遗留表被丢弃)")

    print("\n=== 行估计 TOP 20 ===")
    ranked = sorted(source.values(), key=lambda s: s.row_estimate, reverse=True)[:20]
    for s in ranked:
        if s.row_estimate > 0:
            tag = "（匹配目标）" if s.name in target else "（旧库表，将被忽略）"
            print(f"  {s.row_estimate:>7}  {s.name:45s} {tag}")

    print("\n[dryrun] 分析完成。")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
