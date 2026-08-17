# 本地 IDC 数据迁移流程

本文记录本地数据库从 MySQL dump 重置、初始化、迁移数据的固定流程。

> 若源系统是智简魔方财务（表前缀 `shd_`），请改用实验性迁移器：[从智简魔方财务系统迁移（实验）](从智简魔方财务系统迁移（实验）.md)。本文档针对当前结构内的 dump 迁移，不剥离表前缀。

## 一键脚本

将 dump 的业务数据迁移到**已是当前结构**的本地 `idc` 库（不改表结构）：

```bat
python backend\scripts\migrate_legacy_dump.py --dump C:\path\to\idc_dump.sql
```

迁移前执行完整只读演练（解析全部 INSERT、核验字段映射和分类层级，不写库）：

```bat
python backend\scripts\migrate_legacy_dump.py --dump C:\path\to\idc_dump.sql --dry-run
```

对已经执行的迁移做独立核验（逐表行数、核心引用和三级商品分类）：

```bat
python backend\scripts\migrate_legacy_dump.py --dump C:\path\to\idc_dump.sql --verify
```

如需连同当前结构初始化一起重建本地库，再迁移指定 dump：

```bat
python backend\scripts\reset_init_and_migrate_idc_dump.py --dump C:\path\to\idc_dump.sql
```

所有迁移脚本都要求显式传入 `--dump`，避免误用已清理的历史备份。

仅初始化当前库结构，不导入旧 dump：

```bat
python backend\scripts\install_db.py --reset
```

只检查不写库：

```bat
python backend\scripts\reset_init_and_migrate_idc_dump.py --dump C:\path\to\idc_dump.sql --dry-run
```

迁移记录默认写入：

```text
控制台输出或脚本指定的本地日志位置
```

一次性迁移日志不进入长期文档树；如需保留结论，应提炼为本文件中的兼容规则。

## 执行顺序

1. `backend/scripts/install_db.py --reset`
   - 删除并重建 `.env` 指向的本地数据库。
   - 使用当前项目 schema 初始化表结构。
   - 执行当前迁移。
   - 初始化默认 `settings`、通知模板和默认管理员。
   - schema baseline 只承载表结构和 `migrations` 状态，不承载业务行数据；默认运行数据由本脚本显式补齐。

2. `backend/scripts/migrate_legacy_dump.py`
   - 直接解析 dump 的 INSERT，并在单一事务中按当前目标表字段映射复制数据；不会恢复旧 dump 的表结构，也不需要创建临时库。
   - 迁移会清空并重建目标业务数据，且保留当前 `migrations`，避免以旧迁移状态覆盖当前代码结构。
   - 旧单表 `product_groups` 按层级映射到当前的 `first_product_groups`、`second_product_groups`、`third_product_groups`，保留 ID 并回填商品三级分类字段；旧二级分类直挂商品或未挂分类商品会自动创建历史承接三级分类，确保当前结构中的每个商品都有有效三级分类。
   - 备份中缺少的当前表（如新增的 `recharge_records`、`refunds`）会置空；备份存在而脚本未定义映射的表会直接失败，禁止静默丢失。
   - 清空运行态表 `jobs`、`password_reset_tokens`、`personal_access_tokens`、`sessions`，不迁移旧运行态数据。
   - 保留当前库 `settings` 中的 `codex_runtime`、`codex_service` 临时配置，不用 dump 覆盖。
   - 自动发现 PATH 或 BaoTa 安装目录中的 `mysql.exe`；也可通过 `MYSQL_BIN` 指定客户端路径。
   - 迁移完成后校验逐表行数、关键外键引用、运行态表为空及三层商品分类完整性。

## 已知兼容规则

- 旧 dump 缺少当前新增日志表 `activity_logs`、`gateway_logs`、`schedule_run_logs` 时，初始化后的目标表保持空表。
- `products.name` 等旧字段不会被恢复。
- `orders`、`invoices` 的商品规格快照会优先映射 `product_spec_snapshot`，缺失时兼容旧的 `product_name_snapshot`。

## 迁移后检查

- 核心表行数符合预期：`users`、`products`、`invoices`、`services`、`payments`、`operation_logs`。
- 运行态表保持清空：`jobs`、`sessions`、`password_reset_tokens`、`personal_access_tokens`。
- 当前结构与 `docs/DATABASE.md` 一致。
- 前后端能使用迁移后的管理员、用户、商品和服务数据完成最小冒烟。

## 刷新当前结构基线

当前库结构或数据迁移完成后，需要同步刷新空库初始化 baseline 和数据库结构快照：

```bat
php backend\scripts\export_schema_baseline.php
php backend\scripts\export_database_structure.php
```

`export_schema_baseline.php` 只导出表结构和 `migrations` 记录；`install_db.py` 会在导入 baseline 后补默认配置、通知模板和管理员。
