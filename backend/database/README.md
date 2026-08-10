# 数据库目录说明

## 目录结构

```
database/
├── schema/
│   └── mysql-schema.sql     # 生产库完整结构快照（新环境初始化用）
├── migrations/
│   └── *.php                # 增量迁移（仅新增变更）
└── seeders/                 # 数据填充
```

## 初始化方式

### 新环境
```bash
# 先导入完整结构快照，再执行增量迁移
mysql -u root -p finance < database/schema/mysql-schema.sql
php artisan migrate --force
```

### 增量更新
```bash
php artisan migrate --force
# 仅执行 migrations/*.php 中的新迁移
```

## 注意事项

1. `schema/mysql-schema.sql` 是完整结构基线，禁止手工编辑
2. 新功能上线时，只新增 `migrations/*.php` 增量迁移
3. 重大结构变更后，重新导出 schema baseline 并归档旧迁移
