#!/usr/bin/env python3
"""提取魔方 dump 关键表的数据样本，验证映射前提。"""
from __future__ import annotations

import re
import sys
from pathlib import Path


def extract_inserts(dump: Path, table: str, limit: int = 2) -> list[str]:
    """提取指定表的 INSERT 语句片段（前 limit 条）。"""
    results: list[str] = []
    pattern = re.compile(r"INSERT INTO `" + table + r"`\s+(?:\([^)]*\)\s+)?VALUES\s*\(.*?\)\s*;", re.S | re.I)
    with dump.open("r", encoding="utf-8", errors="replace") as f:
        text = f.read()
    for m in pattern.finditer(text):
        results.append(m.group(0)[:800])
        if len(results) >= limit:
            break
    return results


def main() -> int:
    dump = Path(sys.argv[1] if len(sys.argv) > 1 else r"e:\TuraIDC\25y_2026-08-17_20-39-46_mysql_data_HSoN5.sql")
    tables = sys.argv[2].split(",") if len(sys.argv) > 2 else ["shd_clients"]
    limit = int(sys.argv[3]) if len(sys.argv) > 3 else 2

    for t in tables:
        print(f"\n===== {t} =====")
        for i, sql in enumerate(extract_inserts(dump, t, limit)):
            print(f"--- row {i + 1} ---")
            print(sql[:700])

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
