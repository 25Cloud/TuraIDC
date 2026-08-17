#!/usr/bin/env python3
"""提取魔方 dump 核心表的 CREATE TABLE 定义，输出为文本供映射参考。"""
from __future__ import annotations

import re
import sys
from pathlib import Path

# 需要迁移的核心表（只挑目标库有对应物的）
CORE_TABLES = [
    "shd_user", "shd_clients", "shd_accounts", "shd_products", "shd_product_groups",
    "shd_product_first_groups", "shd_orders", "shd_invoices", "shd_invoice_items",
    "shd_user_products", "shd_host", "shd_configuration", "shd_upper_reaches",
    "shd_servers", "shd_pricing", "shd_ticket", "shd_ticket_reply",
    "shd_email_templates", "shd_message_template", "shd_news", "shd_base_info",
    "shd_plugin", "shd_payment_gateways", "shd_pay_log", "shd_currencies",
    "shd_promo_code", "shd_voucher", "shd_role", "shd_auth_rule", "shd_auth_access",
    "shd_admin_log", "shd_activity_log", "shd_knowledge_base", "shd_knowledge_base_cats",
    "shd_email_log", "shd_message_log", "shd_customfields", "shd_customfieldsvalues",
    "shd_contacts", "shd_sms_country", "shd_withdraw", "shd_credit",
    "shd_certifi_person", "shd_certifi_company", "shd_certification",
]

def main() -> int:
    dump = Path(sys.argv[1] if len(sys.argv) > 1 else r"e:\TuraIDC\25y_2026-08-17_20-39-46_mysql_data_HSoN5.sql")
    out = Path(sys.argv[2] if len(sys.argv) > 2 else r"D:\turaidc_migration\core_tables_ddl.txt")
    text = dump.read_text(encoding="utf-8", errors="replace")

    pattern = re.compile(r"CREATE TABLE `(shd_[^`]+)` \((.*?)\n\) ENGINE=", re.S)
    found: dict[str, str] = {}
    for m in pattern.finditer(text):
        found[m.group(1)] = m.group(2)

    out.parent.mkdir(parents=True, exist_ok=True)
    lines: list[str] = []
    for tname in CORE_TABLES:
        if tname not in found:
            lines.append(f"\n### {tname}  (源中不存在)\n")
            continue
        ddl = found[tname]
        # 只保留列定义行，去掉 KEY/UNIQUE/CONSTRAINT
        cols = []
        for raw in ddl.splitlines():
            line = raw.strip().rstrip(",")
            if not line or line.startswith("PRIMARY") or line.startswith("KEY") or line.startswith("UNIQUE") or line.startswith("CONSTRAINT"):
                continue
            cols.append(line)
        lines.append(f"\n### {tname}\n")
        lines.extend("  " + c for c in cols)
        lines.append("")

    out.write_text("\n".join(lines), encoding="utf-8")
    print(f"写入: {out}  ({len(found)} 张表匹配)")
    return 0

if __name__ == "__main__":
    raise SystemExit(main())
