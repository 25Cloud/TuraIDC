# KangHostx 开发说明

## 文件结构

```text
backend/plugins/servers/kanghostx/
├── config.php
├── KangHostxPlugin.php
├── lib/KangHostxClient.php
└── logic/KangHostx.php
```

- `config.php`：声明 `domain=upstream`、`slug=key=kanghostx`、能力和后台插件配置。
- `KangHostxPlugin.php`：入口类，继承逻辑类。
- `lib/KangHostxClient.php`：康乐 WHM API 签名、URL、HTTP、响应校验。
- `logic/KangHostx.php`：实现 TuraIDC 上游契约方法和字段归一。

## 字段映射

原模块配置字段兼容了 `parameter*`、`kl_*` 和新语义字段。新商品应把这些字段配置在产品 `config_options` 中，供应商只保存康乐面板地址和 accesshash：

| 康乐字段 | 来源优先级 |
| --- | --- |
| `web_quota` | `kl_site` / `parameter6` / `web_quota_mb` |
| `db_quota` | `kl_sql` / `parameter7` / `db_quota_mb` |
| `domain` | `kl_domain` / `parameter3` / `domain_limit` |
| `max_subdir` | `kl_zi` / `parameter4` / `max_subdir` |
| `flow_limit` | `kl_flow` / `parameter8` / `flow_limit_gb` |
| `speed_limit` | `kl_speed` / `parameter9` / `speed_limit_mbps`，写入前乘以 128 |
| `subdir` | `parameter5` / `default_subdir` |
| `port` | `parameter16` / `site_port` |

取值优先级：订单 `config_snapshot` > 商品 `config_options` 默认值 > 旧供应商 `provider_config`。最后一级仅用于兼容旧数据，不应继续在供应商配置中维护套餐规格。

## 验证

```bash
cd backend
php artisan test tests/Feature/KangHostxPluginTest.php
```

语法检查：

```bash
php -l plugins/servers/kanghostx/config.php
php -l plugins/servers/kanghostx/KangHostxPlugin.php
php -l plugins/servers/kanghostx/lib/KangHostxClient.php
php -l plugins/servers/kanghostx/logic/KangHostx.php
```
