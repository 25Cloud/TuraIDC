# 从智简魔方财务系统迁移（实验）

> ⚠️ **本文档已归档为实验性记录**，完整迁移教程请见正式指南：[从智简魔方财务系统迁移](./migrate-from-zjmf-finance.md)（已整合生产环境全流程与踩坑记录，包括 pricing 扁平格式、config_options 组装、上游解密、服务绑定回填等）。

本文记录将智简魔方财务（魔方财务 / ZJMF）业务数据迁移至图拉云 `turaidc` 数据库的实验性流程。该迁移器未集成进 `install_db.py`，仅作为一次性导入工具存在；正式生产迁移仍以 `migrate_legacy_dump.py` 为准。

## 1. 适用场景

- 源系统：智简魔方财务（数据库表前缀 `shd_`，旧版本可能使用 `mccloud_`、`ewytemplate_` 等前缀）。
- 目标系统：图拉云 `turaidc` 当前 schema。
- 数据规模：约 2.4 万行业务数据（用户 1279、商品 1337、订单 3369、账单 7814、服务 2970、工单 1130）。
- 迁移方式：从 `mysqldump` 文件流式解析 + 字段映射 + 批量写入，不要求源库在线。

## 2. 脚本与依赖

| 脚本                                                                                    | 作用                                                        |
| --------------------------------------------------------------------------------------- | ----------------------------------------------------------- |
| [mofang_to_turaidc_migrator.py](../../../backend/scripts/mofang_to_turaidc_migrator.py) | 主迁移器，解析 dump、字段映射、批量写入、pricing JSON 转换  |
| [mofang_migrate.conf.example](../../../backend/scripts/mofang_migrate.conf.example)     | 迁移器配置文件模板（复制为 `mofang_migrate.conf` 免输参数） |
| [normalize_mofang_dump.py](../../../backend/scripts/normalize_mofang_dump.py)           | 预处理 dump，剥离 `shd_` 等前缀并重命名冲突表               |
| [count_mofang_rows.py](../../../backend/scripts/count_mofang_rows.py)                   | 统计源表行数，评估迁移规模                                  |
| [peek_mofang_rows.py](../../../backend/scripts/peek_mofang_rows.py)                     | 抽样源数据，验证字段格式（如 `###md5` 密码）                |
| [dryrun_migration_analysis.py](../../../backend/scripts/dryrun_migration_analysis.py)   | 纯 Python dry-run，分析源/目标列交集和必填字段缺失          |
| [extract_mofang_core_tables.py](../../../backend/scripts/extract_mofang_core_tables.py) | 从大 dump 中抽取核心表，便于离线分析                        |

依赖：`pymysql`（已在 backend 虚拟环境中安装）。

## 3. 连接配置（三种方式，按优先级合并）

> 命令行参数 > 环境变量 `MOFANG_MIGRATE_<KEY>` > 配置文件。

**方式一：配置文件（推荐，零参数运行）**

复制 `mofang_migrate.conf.example` 为 `mofang_migrate.conf`（脚本同目录），填写真实值：

```ini
[db]
host = 43.240.220.81
port = 3306
user = turaidc
password = <PASSWORD>
database = turaidc

[source]
dump = e:\TuraIDC\25y_2026-08-17_20-39-46_mysql_data_HSoN5.sql
```

> `mofang_migrate.conf` 含数据库密码，已加入 `.gitignore`，**不会提交到仓库**；`dump` 留空时会自动发现项目根 / `backend/scripts` 下的 `25y_*.sql`。

**方式二：环境变量**

```
MOFANG_MIGRATE_HOST / MOFANG_MIGRATE_PORT / MOFANG_MIGRATE_USER /
MOFANG_MIGRATE_PASSWORD / MOFANG_MIGRATE_DATABASE / MOFANG_MIGRATE_DUMP
```

**方式三：命令行参数**（优先级最高，见下方执行流程）。

## 4. 执行流程

### 1. 预检：源数据评估

```bat
python backend\scripts\count_mofang_rows.py --dump path\to\shd_dump.sql
python backend\scripts\peek_mofang_rows.py --dump path\to\shd_dump.sql
python backend\scripts\dryrun_migration_analysis.py --dump path\to\shd_dump.sql
```

输出包含源表行数、关键字段格式（密码、pricing 列）、列交集和必填字段缺失清单。

### 2. 预检：dry-run 全量预演

```bat
:: 配置文件就绪后，零参数直接预演（自动发现 dump）
python backend\scripts\mofang_to_turaidc_migrator.py --dry-run

:: 或全部参数手动指定
python backend\scripts\mofang_to_turaidc_migrator.py ^
    --dump path\to\shd_dump.sql ^
    --host 43.240.220.81 --port 3306 ^
    --user turaidc --password <PASSWORD> ^
    --database turaidc --dry-run
```

dry-run 不写库，仅解析、映射、计数。期望输出 `合计: 源行 24595  入库 24595  失败 0`。

### 3. 实际迁移（含清空目标表）

```bat
python backend\scripts\mofang_to_turaidc_migrator.py --truncate
```

`--truncate` 会在迁移前清空 19 张目标表（TRUNCATE，DDL，4 秒完成）。**仅在干净库使用**。

### 4. 按白名单迁移指定表（修复失败表时使用）

```bat
python backend\scripts\mofang_to_turaidc_migrator.py ^
    --tables shd_configuration,shd_products,shd_invoices,shd_admin_log,shd_pricing
```

### 5. 迁移后校验

见下文「数据校验」一节。

## 5. 表映射规则

源表 `shd_*` 剥离前缀后，按以下对应关系写入目标表：

| 源表                              | 目标表                          | 备注                                                  |
| --------------------------------- | ------------------------------- | ----------------------------------------------------- |
| `shd_configuration`               | `settings`                      | 补 `group_key='general'`                              |
| `shd_product_first_groups`        | `first_product_groups`          | 一级商品组                                            |
| `shd_product_groups`              | `second_product_groups`         | 二级商品组，唯一键冲突由单行降级跳过                  |
| `shd_upper_reaches`               | `suppliers`                     | 上游供应商（源数据通常为空）                          |
| `shd_clients`                     | `users` + `user_accounts`       | 同源数据双写，密码保留 `###md5`                       |
| `shd_products`                    | `products`                      | `description → remark` 截断 255；`pricing` 后处理填入 |
| `shd_orders`                      | `orders`                        | `ordernum → order_no`，空值生成 `ORD-{id:08d}`        |
| `shd_invoices`                    | `invoices`                      | `invoice_num → invoice_no`，无效值生成 `INV-{id:08d}` |
| `shd_host`                        | `services`                      | 用户开通的服务实例                                    |
| `shd_ticket` / `shd_ticket_reply` | `tickets` / `ticket_replies`    | 工单与回复                                            |
| `shd_promo_code`                  | `coupons`                       | 优惠码                                                |
| `shd_activity_log`                | `activity_logs`                 | 用户活动日志                                          |
| `shd_admin_log`                   | `operation_logs`                | 管理员操作日志，不迁移源 id                           |
| `shd_message_log`                 | `message_logs`                  | 消息日志                                              |
| `shd_pricing`                     | (后处理写入 `products.pricing`) | 不直接入库，作为 pricing JSON 源                      |
| `shd_product_groups`              | `third_product_groups`          | 后处理同步二级组到三级组，满足外键                    |

## 6. 关键兼容性处理

### 密码：`###md5` 格式

魔方财务密码格式为 `###md5` 前缀 + 32 位 md5，与图拉云 bcrypt 不兼容。迁移时**原样保留**，由 [ZjmfLegacyPasswordVerifier.php](../../../backend/plugins/servers/zjmf_finance/lib/ZjmfLegacyPasswordVerifier.php) 在登录时识别并验证，用户登录后可平滑升级到 bcrypt。

### pricing：多列周期价格 → JSON

源 `shd_pricing` 表为每个商品、每个币种、每个周期（monthly/quarterly/semiannually/annually 等）各一列。迁移器将其聚合为目标 `products.pricing` JSON 字段：

```json
{
  "monthly": { "price": 25.0 },
  "quarterly": { "price": 75.0 },
  "semiannually": { "price": 150.0 },
  "annually": { "price": 300.0 }
}
```

初装费 `setup_fee` 从对应周期的 `*setupfee` 列提取。同一产品多币种记录时，优先取 `currency=0`（人民币）。

### invoice_no 唯一性兜底

源数据中约 42% 的 `invoice_num` 为无效值（3280 条字符串 `'NULL'`、10 条空字符串、部分 `'INV-0'`）。迁移器在 `_post_map_fix` 中检测无效值集合 `{"", "INV-0", "0", "NULL", "NONE", "N/A", "-"}`（大小写不敏感），命中时用源 id 生成唯一值 `INV-{id:08d}`，保证 `invoice_no` NOT NULL UNIQUE 约束满足。

### operation_logs 主键冲突

目标库 `operation_logs` 在应用运行期间持续写入，主键 `id` 由 auto_increment 分配。迁移源 `shd_admin_log` 时**不迁移源 id**，避免与应用日志主键冲突。

### remark 字段截断

目标库所有 `remark` 字段为 `varchar(255)`，源数据 `description`/`notes` 字段可能超长。所有 remark 映射统一使用 `lambda v: to_str(v)[:255] if v else None` 截断。

### 外键依赖：三级商品组

目标库 `products.product_group_id` 外键指向 `third_product_groups.id`，源库只有两级组。迁移器在主流程结束后调用 `_sync_third_product_groups`，将 `shd_product_groups` 数据复制到 `third_product_groups`，满足外键约束。

## 7. 数据校验

### 行数对账

```python
import pymysql
from pymysql.cursors import DictCursor

conn = pymysql.connect(host='...', user='turaidc', password='...', database='turaidc',
                       charset='utf8mb4', cursorclass=DictCursor)
expected = {
    "users": 1279, "products": 1337, "orders": 3369, "invoices": 7814,
    "services": 2970, "tickets": 1130, "ticket_replies": 2814,
    "first_product_groups": 36, "second_product_groups": 207,
    "settings": 331, "coupons": 19, "message_logs": 1826,
}
for t, exp in expected.items():
    with conn.cursor() as cur:
        cur.execute(f"SELECT COUNT(*) AS c FROM `{t}`")
        actual = cur.fetchone()['c']
    status = "✓" if actual == exp else "✗"
    print(f"  {t:<28} {actual:>8}/{exp:<8} {status}")
```

### 关键字段抽样

- `users.password` 应为 `###md5` 前缀格式。
- `products.pricing` 应为非空 JSON（含 `monthly`/`annually` 等周期键）。
- `invoices.invoice_no` 全部唯一（`GROUP BY ... HAVING COUNT(*) > 1` 应返回空）。

## 8. 已知限制

- **不迁移的内容**：知识库（`shd_knowledge_base`）、邮件模板、支付网关配置、上游服务器配置等非核心业务表保持空表。
- **源数据脏数据**：源 `shd_orders` 存在 2 条重复 `ordernum`，由单行降级插入自动跳过；最终入库 3367/3369。
- **应用并发写入**：迁移期间目标库 NestJS 应用若持续运行，会与迁移器争用 `operation_logs` 等表，建议迁移时停应用或选择低峰期。
- **元数据锁风险**：远程 MySQL 对 DDL（TRUNCATE）敏感，若目标库存在长事务会阻塞 TRUNCATE。迁移前应 `SHOW PROCESSLIST` 检查并 KILL 遗留空闲连接。
- **实验性**：该迁移器未纳入 `install_db.py` 自动流程，schema 演进时需手动同步映射规则。

## 9. 故障排查

### TRUNCATE 超时（元数据锁）

```
(2013, 'Lost connection to MySQL server during query (The read operation timed out)')
```

排查步骤：

1. `SHOW PROCESSLIST` 查找 `Waiting for table metadata lock` 的会话。
2. 定位锁源头（通常是 localhost 上 NestJS 应用的长事务）。
3. `KILL <id>` 终止阻塞进程，再重试 TRUNCATE。

### 字段长度超限

```
DataError: (1406, "Data too long for column 'remark' at row 1")
```

所有 `remark` 映射已统一截断到 255 字符；若其他 varchar 字段报错，在对应 `FieldMap` 中添加 `[:N]` 截断。

### 主键/唯一键冲突

```
IntegrityError: (1062, "Duplicate entry '...' for key '...'")
```

迁移器内置单行降级插入：批量失败后逐行重试，冲突行跳过并计入 `failed`。若失败行数异常高，检查 `_post_map_fix` 是否覆盖该表的无效值场景。

## 10. 相关文档

- [本地 IDC 数据迁移流程](local-idc-data-migration.md) — 正式迁移流程
- [ZJMF 统一迁移方案](../../execution-plans/completed/zjmf-unified-migration.md) — ZJMF 兼容性整体方案
