#!/usr/bin/env python3
"""魔方财务 → 图拉云：组装 products.config_options 配置项 JSON

来源表（老站 dump，shd_ 前缀）：
- shd_product_config_links   产品配置关联（按 pid 关联产品）
- shd_product_config_options 配置项定义
- shd_product_config_options_sub 配置项子选项
- shd_pricing                （可选）子选项价格，仅用于参考

目标：products.config_options 必须是配置项数组，每项含：
  field / name / option_type / hidden / sub / qty_minimum / qty_maximum / parameter / unit

硬性要求（防止报价接口 500）：
- 严禁把对接元数据（mofang_host / zjmf_api_id / upstream_pid）写进 config_options；
  对接字段应放 product_upstream_bindings。
- OS 配置项（option_type=5）的每个 sub 必须填充 version 字段，格式 `大类^版本`，
  前端 buildOsGroups 依赖该格式分组。

用法：
  python3 mofang_config_options_migrator.py --dump 25y_*.sql [--output update.sql] [--dry-run]
"""

from __future__ import annotations

import argparse
import json
import logging
import sys
from pathlib import Path
from typing import Any, Iterable

logging.basicConfig(level=logging.INFO, format="[%(levelname)s] %(message)s")
logger = logging.getLogger("config-options-migrator")

# 与后端 HandlesOrderCalculation::TYPE_FIELD_MAP 保持一致
TYPE_FIELD_MAP = {
    4: "ip_num",
    5: "os",
    6: "cpu",
    7: "cpu",
    8: "memory",
    9: "memory",
    10: "bw",
    11: "bw",
    12: "area",
    13: "system_disk_size",
    14: "system_disk_size",
    16: "cpu",
    17: "memory",
    18: "bw",
    19: "system_disk_size",
}

OS_OPTION_TYPE = 5


def _import_scanner():
    """复用主迁移器的 DumpScanner（同目录）。"""
    sys.path.insert(0, str(Path(__file__).resolve().parent))
    from mofang_to_turaidc_migrator import DumpScanner  # type: ignore

    return DumpScanner


def _row_to_dict(columns: list[str], row: tuple) -> dict[str, Any]:
    return dict(zip(columns, row))


def _opt(d: dict[str, Any], *names: str, default: Any = None) -> Any:
    for name in names:
        if name in d and d[name] is not None:
            return d[name]
    return default


def _to_int(v: Any, default: int = 0) -> int:
    try:
        return int(float(v))
    except (TypeError, ValueError):
        return default


def _to_str(v: Any, default: str = "") -> str:
    if v is None:
        return default
    return str(v).strip()


def _clean_os_version(raw: str) -> str:
    """把 sub.option_name 解析成 `大类^版本` 格式。

    源数据常见混乱格式：
      - `12|CentOS^CentOS-7.6.1810-x64`（id|大类^版本）
      - `Windows^Windows^Windows7`（多余 ^）
      - `Windows2022|Windows`（名称|大类，无版本）
    """
    text = _to_str(raw)
    if not text:
        return ""

    # 去掉 `数字|` 前缀（老站 id 前缀）
    text = text.split("|", 1)[-1].strip()

    # 合并连续的 ^
    while "^^" in text:
        text = text.replace("^^", "^")

    parts = [p.strip() for p in text.split("^") if p.strip()]
    if not parts:
        return ""

    if len(parts) == 1:
        # 无版本：大类即唯一段，版本留空由后端兜底
        return f"{parts[0]}^"

    # 大类 = 第一个段；版本 = 其余段合并（保留 Windows7-2008R2 等）
    family = parts[0]
    version = "^".join(parts[1:])

    return f"{family}^{version}"


def _field_for_option(option: dict[str, Any]) -> str:
    explicit = _to_str(_opt(option, "field"), "")
    if explicit:
        return explicit
    option_type = _to_int(_opt(option, "option_type", "type"), -1)
    return TYPE_FIELD_MAP.get(option_type, "")


def _build_config_options(
    links: list[dict[str, Any]],
    options_by_id: dict[int, dict[str, Any]],
    subs_by_option: dict[int, list[dict[str, Any]]],
) -> dict[int, list[dict[str, Any]]]:
    """按产品 pid 聚合 config_options。"""
    result: dict[int, list[dict[str, Any]]] = {}

    for link in links:
        pid = _to_int(_opt(link, "pid", "product_id"))
        option_id = _to_int(_opt(link, "config_id", "option_id", "cid"))
        if pid <= 0 or option_id <= 0:
            continue

        option = options_by_id.get(option_id)
        if option is None:
            logger.warning("[skip] 配置项 %d 无定义，产品 %d 忽略该关联", option_id, pid)
            continue

        item: dict[str, Any] = {
            "field": _field_for_option(option),
            "name": _to_str(_opt(option, "option_name", "name"), f"配置项{option_id}"),
            "option_type": _to_int(_opt(option, "option_type", "type"), 0),
            "hidden": 1 if _to_int(_opt(option, "hidden"), 0) == 1 else 0,
            "qty_minimum": _to_int(_opt(link, "qty_minimum", "qtymin"), 0),
            "qty_maximum": _to_int(_opt(link, "qty_maximum", "qtymax"), 0),
            "parameter": _to_str(_opt(option, "parameter", "param"), ""),
            "unit": _to_str(_opt(option, "unit"), ""),
        }

        subs = subs_by_option.get(option_id, [])
        normalized_subs: list[dict[str, Any]] = []
        for sub in subs:
            option_name = _to_str(_opt(sub, "option_name", "name"), "")
            normalized: dict[str, Any] = {
                "id": _to_int(_opt(sub, "id", "sub_id")),
                "option_name": option_name,
            }
            # OS 子项必须带 version（大类^版本）
            if item["option_type"] == OS_OPTION_TYPE:
                normalized["version"] = _clean_os_version(
                    _opt(sub, "version", default=option_name)  # type: ignore[arg-type]
                )
            normalized_subs.append(normalized)

        if normalized_subs:
            item["sub"] = normalized_subs

        result.setdefault(pid, []).append(item)

    return result


def _emit_sql(products: dict[int, list[dict[str, Any]]], output: Path | None) -> tuple[int, int]:
    lines: list[str] = []
    total_os_items = 0
    missing_version = 0

    for pid in sorted(products):
        payload = json.dumps(products[pid], ensure_ascii=False)
        escaped = payload.replace("\\", "\\\\").replace("'", "\\'")
        lines.append(
            "UPDATE `products` SET `config_options` = '%s' WHERE `id` = %d;"
            % (escaped, pid)
        )
        for item in products[pid]:
            if item["option_type"] == OS_OPTION_TYPE:
                total_os_items += 1
                for sub in item.get("sub", []):
                    version = _to_str(sub.get("version", ""))
                    if not version or version.endswith("^"):
                        missing_version += 1

    sql = "\n".join(lines)
    if output is None:
        print(sql)
    else:
        output.write_text(sql, encoding="utf-8")
        logger.info("SQL 已写入 %s（%d 行）", output, len(lines))

    return total_os_items, missing_version


def main() -> int:
    parser = argparse.ArgumentParser(description="组装 products.config_options 配置项 JSON")
    parser.add_argument("--dump", required=True, help="魔方财务 dump SQL 文件（25y_*.sql）")
    parser.add_argument("--output", default=None, help="输出 UPDATE SQL 文件（默认 stdout）")
    parser.add_argument("--dry-run", action="store_true", help="只统计，不输出 SQL")
    args = parser.parse_args()

    dump_path = Path(args.dump)
    if not dump_path.is_file():
        logger.error("dump 文件不存在：%s", dump_path)
        return 1

    scanner = _import_scanner()(dump_path)
    scanner.pre_scan_create_tables()

    links: list[dict[str, Any]] = []
    options_by_id: dict[int, dict[str, Any]] = {}
    subs_by_option: dict[int, list[dict[str, Any]]] = {}

    for table, columns, rows in scanner.scan():
        if not columns:
            logger.warning("[skip] 表 %s 无列信息", table)
            continue
        for row in rows:
            d = _row_to_dict(columns, row)
            if table.endswith("product_config_links"):
                links.append(d)
            elif table.endswith("product_config_options_sub"):
                owner = _to_int(_opt(d, "pid", "config_id", "gid"))
                if owner > 0:
                    subs_by_option.setdefault(owner, []).append(d)
            elif table.endswith("product_config_options"):
                oid = _to_int(_opt(d, "id"))
                if oid > 0:
                    options_by_id[oid] = d

    logger.info(
        "解析完成：links=%d options=%d subs=%d",
        len(links),
        len(options_by_id),
        sum(len(v) for v in subs_by_option.values()),
    )

    products = _build_config_options(links, options_by_id, subs_by_option)
    logger.info("涉及产品数：%d", len(products))

    os_items, missing_version = _emit_sql(products, None if args.dry_run else Path(args.output) if args.output else None)
    logger.info("os_items=%d missing_version=%d", os_items, missing_version)

    if args.dry_run:
        logger.info("--dry-run：未输出 SQL")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
