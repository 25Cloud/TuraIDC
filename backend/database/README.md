# 数据库目录说明

## 目录结构

```
database/
├── schema/
│   └── mysql-schema.sql     # 生产库完整结构快照（新环境初始化用）
├── migrations/
│   └── *.php                # 增量迁移（仅新增变更）
├── seeders/                 # 数据填充
├── factories/               # 测试工厂
└── _archive/                # 历史遗留（不参与 migrate）
    ├── migrations/          # 已固化进 schema 的历史迁移
    ├── migrations_devin/    # 临时工程迁移
    ├── schema.old.sql       # 旧版 schema 快照
    └── import_legacy_data.sql
```

## 初始化方式

### 新环境
```bash
python backend/scripts/install_db.py
# 自动导入 schema/mysql-schema.sql（含 migrations 记录）
```

### 增量更新
```bash
php artisan migrate --force
# 仅执行 migrations/*.php 中的新迁移
```

### 更新 schema baseline
```bash
# 在生产库或同步后的本地库上导出
php backend/scripts/export_schema_baseline.php
```

## 注意事项

1. **禁止**把历史迁移从 `_archive/migrations/` 移回 `migrations/` 再执行
2. 新功能上线时，只新增 `migrations/*.php` 增量迁移
3. 重大结构变更后，重新导出 schema baseline 并归档旧迁移
4. `_archive/` 下文件仅供历史参考，不进入日常部署流程
