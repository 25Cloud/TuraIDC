#!/usr/bin/env python3
"""精确统计魔方 dump 各表 INSERT 行数（流式解析，不载入全部）。"""
from __future__ import annotations

import re
import sys
from pathlib import Path


def count_rows(dump_path: Path) -> dict[str, int]:
    counts: dict[str, int] = {}
    current_table: str | None = None
    insert_re = re.compile(r"INSERT INTO `([^`]+)`")
    # 兼容多行 VALUES：统计 '(' 在 VALUES 段的数量需要状态机。简化：
    # 逐行匹配 "INSERT INTO `t` VALUES" 或 "INSERT INTO `t` (`a`,`b`) VALUES"
    # 行数统计：每个元组以行首 ',' 或 VALUES 后的 '(' 开始（粗略：统计 '),(' 出现次数 +1）
    values_pending: str | None = None

    with dump_path.open("r", encoding="utf-8", errors="replace") as f:
        for raw in f:
            line = raw.rstrip("\r\n")
            if current_table and values_pending is not None:
                # 上一行 VALUES 未完，继续累计
                counts[current_table] = counts.get(current_table, 0) + line.count("),(") + (1 if line.startswith("(") and not values_pending_done else 0)
                if line.endswith(";"):
                    values_pending = None
                continue
            m = insert_re.search(line)
            if m:
                current_table = m.group(1)
                counts.setdefault(current_table, 0)
                # 单行 INSERT: VALUES (...);
                if line.count("),(") > 0:
                    counts[current_table] += line.count("),(") + 1
                    current_table = None
                elif "VALUES" in line.upper() and line.rstrip().endswith(";"):
                    counts[current_table] += 1
                    current_table = None
                elif "VALUES" in line.upper():
                    counts[current_table] += 1
                    current_table = None
                else:
                    current_table = None
    return counts


def main() -> int:
    dump = Path(sys.argv[1] if len(sys.argv) > 1 else r"e:\TuraIDC\25y_2026-08-17_20-39-46_mysql_data_HSoN5.sql")
    counts = count_rows(dump)
    interesting = [
        "shd_clients", "shd_user", "shd_products", "shd_product_groups", "shd_product_first_groups",
        "shd_orders", "shd_invoices", "shd_invoice_items", "shd_host", "shd_user_products",
        "shd_configuration", "shd_upper_reaches", "shd_servers", "shd_pricing", "shd_ticket",
        "shd_ticket_reply", "shd_knowledge_base", "shd_knowledge_base_cats", "shd_pay_log",
        "shd_email_templates", "shd_message_template", "shd_email_log", "shd_message_log",
        "shd_activity_log", "shd_admin_log", "shd_promo_code", "shd_voucher", "shd_credit",
        "shd_accounts", "shd_currencies", "shd_news", "shd_base_info", "shd_plugin",
        "shd_payment_gateways", "shd_customfields", "shd_customfieldsvalues", "shd_contacts",
        "shd_role", "shd_auth_rule", "shd_auth_access", "shd_certifi_person", "shd_certifi_company",
        "shd_certification", "shd_sms_country", "shd_withdraw", "shd_contract",
        "shd_product_config_groups", "shd_product_config_options", "shd_product_config_links",
    ]
    print("=== 关键表行数 ===")
    for t in interesting:
        print(f"  {t:45s} {counts.get(t, 0):>8}")

    print("\n=== 全部非零行表 TOP 60 ===")
    for t, n in sorted(counts.items(), key=lambda kv: kv[1], reverse=True)[:60]:
        if n > 0:
            print(f"  {n:>8}  {t}")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
