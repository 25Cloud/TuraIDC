# backend/scripts

仓库内允许长期存在的脚本，必须满足**两条**：

1. 有明确的调用方——`package.json` 的 script、CI、`DEPLOYMENT.md` 等文档，或本文件登记的手工运维流程；
2. 不依赖任何开发者本机的私有配置、不硬编码某个环境的具体 ID / 路径 / dump 文件名。

**不要**把下面这些放进来（写完即删，或放到临时目录）：

- 只为看一眼数据而写的 `var_dump` / `echo` 排查脚本——用 `php artisan tinker`；
- 一次性数据订正——写成 migration 或 `artisan` 命令，跑完留痕；
- 造测试数据——正规位置是 `backend/database/seeders/` 与 `backend/database/factories/`；
- 结构快照 SQL——`backend/database/migrations/` 才是唯一真相，快照一定会漂移。

---

## 构建与文档（`package.json` 调用）

| 脚本 | 用途 | 调用 |
| --- | --- | --- |
| `build_frontends.mjs` | 构建三个前端并投放到 `backend/public` | `pnpm build:frontends` / `build:admin-v3` / `build:user-v3-www` / `build:user-v4-console` |
| `build_frontends.test.mjs` | 上一条的单测 | `node --test backend/scripts/build_frontends.test.mjs` |
| `check_docs.mjs` | 文档链接与新鲜度检查 | `pnpm docs:check` / `pnpm docs:freshness` |

## 运维

| 脚本 | 用途 | 调用 |
| --- | --- | --- |
| `backup_mysql.sh` | 每日备份 MySQL、保留 14 天 | 见 `DEPLOYMENT.md`「数据库备份」 |
| `install_db.py` / `install_db.sh` | 初始化项目数据库 | 见 `backend/database/README.md` |

## 质量门禁（当前未接入 `package.json`，手工执行）

| 脚本 | 用途 | 调用 |
| --- | --- | --- |
| `production_preflight.mjs` | 上线前检查 | `node backend/scripts/production_preflight.mjs` |
| `production_preflight.test.mjs` | 上一条的单测 | `node --test backend/scripts/production_preflight.test.mjs` |
| `sensitive_file_scan.mjs` | 扫描仓库内的敏感文件 | `node backend/scripts/sensitive_file_scan.mjs` |
| `sensitive_file_scan.test.mjs` | 上一条的单测 | `node --test backend/scripts/sensitive_file_scan.test.mjs` |
| `workspace_health_check.mjs` | 工作区健康检查 | `node backend/scripts/workspace_health_check.mjs` |

## 结构 / 接口导出

| 脚本 | 用途 |
| --- | --- |
| `export_api_inventory.php` | 导出路由与中间件清单 |
| `export_database_structure.php` | 导出当前库结构 |
| `export_schema_baseline.php` | 导出结构基线，供跨版本比对 |
| `generate_frontend_api_catalog.php` | 生成前端可用接口目录 |

## 魔方财务 / 异构 IDC 数据搬迁

整条链路的入口是 `mofang_to_turaidc_migrator.py`（零参数时读 `mofang_migrate.conf`，模板见
`mofang_migrate.conf.example`）。其余为其各阶段的工具，**搬迁完成前不要删**：

| 脚本 | 阶段 |
| --- | --- |
| `normalize_mofang_dump.py` | 标准化 dump：去表前缀、修正表名 |
| `extract_mofang_core_tables.py` | 提取核心表 `CREATE TABLE` 供映射参考 |
| `count_mofang_rows.py` | 流式统计各表 INSERT 行数 |
| `peek_mofang_rows.py` | 抽样关键表数据，验证映射前提 |
| `dryrun_migration_analysis.py` | 源 dump ↔ 目标库 dry-run 匹配分析 |
| `mofang_config_options_migrator.py` | 组装 `products.config_options` |
| `strip_dump_super_lines.py` | 过滤 dump 中的 SUPER 权限行（不连库） |
| `migrate_dump_strip.py` | 过滤 SUPER 权限行并直接导入 |
| `migrate_legacy_dump.py` | 旧版 dump 数据迁移 |
| `migrate_heterogeneous_idc_dump.py` | 异构 IDC 系统 dump 迁移 |
| `reset_init_and_migrate_idc_dump.py` | 重置本地库 → 初始化结构 → 导入 dump |

## 待归位

| 脚本 | 说明 |
| --- | --- |
| `seed_mock_data.php` | 演示数据灌入，应改写为 `database/seeders/` 下的 Seeder 类；在此之前**严禁在生产库执行** |
