# 从智简魔方财务系统迁移到 TuraIDC

本文档是**权威迁移教程**，整合了「智简魔方财务（ZJMF，`shd_` 前缀）」老站迁移到 TuraIDC 的全流程、工具与生产环境踩坑记录。适用于已有魔方财务业务数据、希望切换到 TuraIDC 的部署场景。

> 旧实验版文档见 [从智简魔方财务系统迁移（实验）](./migrate-from-zjmf-finance-experimental.md)，本文档已涵盖其全部内容并按生产经验修订（如 pricing 扁平格式）。

---

## 一、迁移总览

| 项       | 说明                                                                            |
| -------- | ------------------------------------------------------------------------------- |
| 源系统   | 智简魔方财务，数据库表前缀 `shd_`（旧版本可能为 `mccloud_`、`ewytemplate_` 等） |
| 目标系统 | TuraIDC（Laravel 12 + MySQL 8）                                                 |
| 迁移方式 | `mysqldump` 文件流式解析（无需源库在线）+ 字段映射 + 批量写入                   |
| 参考规模 | 用户 1279、商品 1337、订单 3369、账单 7814、服务 2970、工单 1130                |
| 数据安全 | 老站密码 `###md5` 原样保留，登录时验证后平滑升级 bcrypt                         |

迁移分 10 步：

```text
Step 1  环境准备（核对 .env 数据库指向）
Step 2  产品目录三级结构重建
Step 3  产品 / 用户 / 订单 / 工单迁移
Step 4  配置项迁移（products.config_options）与 OS version 修复
Step 5  service_type_code 派生
Step 6  上游同步（suppliers + 绑定 + API 密钥解密）
Step 7  实名认证迁移
Step 8  老用户密码策略
Step 9  缓存预热与验证
Step 10 服务实例上游绑定回填（恢复控制台管理能力）
```

---

## 二、工具清单

### 迁移脚本（Python，`backend/scripts/`）

| 脚本                                           | 作用                                                                                                   |
| ---------------------------------------------- | ------------------------------------------------------------------------------------------------------ |
| `mofang_to_turaidc_migrator.py`                | 主迁移器：产品 / 用户 / 订单 / 分组 / 主机等（支持 `--dry-run`、`--truncate`、`--tables` 白名单）      |
| `mofang_config_options_migrator.py`            | 解析 dump 的 `shd_product_config_links / options / sub / pricing`，组装 `products.config_options` JSON |
| `normalize_mofang_dump.py`                     | 预处理 dump，剥离 `shd_` 等前缀并重命名冲突表                                                          |
| `count_mofang_rows.py` / `peek_mofang_rows.py` | 统计源表行数 / 抽样源数据                                                                              |
| `dryrun_migration_analysis.py`                 | 纯 Python dry-run，分析源/目标列交集与必填字段缺失                                                     |
| `extract_mofang_core_tables.py`                | 从大 dump 抽取核心表，便于离线分析                                                                     |

### Artisan 命令（`backend/app/Console/Commands/`）

| 命令                                  | 作用                                                                                                    |
| ------------------------------------- | ------------------------------------------------------------------------------------------------------- |
| `app:reorganize-product-groups`       | 重建三级分组：一级组规范化（ProductType 内置 5 类）、归属重映射、`service_type_code` 派生、隐藏空二级组 |
| `app:sync-upstreams`                  | 从旧库同步上游供应商，解密 API 密钥写入 `supplier_plugin_bindings`，并建立产品级绑定                    |
| `app:migrate-real-name`               | 实名认证迁移（`shd_certifi_person / company` → `users` + `verification_histories`）                     |
| `app:fix-os-versions`                 | 修复 OS 配置项 `sub[].version`（`大类^版本`）并清理多余 `^`                                             |
| `app:backfill-config-options`         | 跨库补齐缺失的 `products.config_options`（从备份库回填）                                                |
| `services:backfill-upstream-bindings` | 回填 `service_upstream_bindings`（notes `ID：` + 上游 `/host/list` 按域名匹配）                         |

所有命令均支持 `--dry-run` 预检。

---

## 三、Step 1：环境准备

- 核对 `backend/.env`：`DB_DATABASE` **必须是当前活跃库**（历史教训：重新 clone 后默认可能指向备份库，导致"配置丢失 / 分组错乱"）。
- Redis 密码、队列 worker、crontab 按 [部署与调度指南](../operations/deployment-and-scheduling.md) 核对。
- 迁移器连接配置：命令行参数 > 环境变量 `MOFANG_MIGRATE_*` > `mofang_migrate.conf`（复制 `backend/scripts/mofang_migrate.conf.example`，含密码，已被 `.gitignore` 忽略）。

---

## 四、Step 2：产品目录三级结构重建

数据约定：

- 一级 `first_product_groups`：5 个规范类型（与 `ProductType` 内置一致）

| internal_id | code        | product_type    | 名称     |
| ----------- | ----------- | --------------- | -------- |
| 1           | `vps`       | `cloud_server`  | 云服务器 |
| 2           | `dedicated` | `game_cloud`    | 游戏云   |
| 3           | `hosting`   | `web_hosting`   | 虚拟主机 |
| 4           | `domain`    | `cloud_desktop` | 云电脑   |
| 5           | `other`     | `cdn`           | 其他     |

- 二级 `second_product_groups` = 老站一级组（36 个，`id = 旧id + 100`）
- 三级 `third_product_groups` = 老站二级组（207 个，`id = 旧id + 1000`）
- `products.product_group_id` = 三级组 id（`旧 gid + 1000`）

```bash
cd backend
php artisan app:reorganize-product-groups --dry-run   # 先预检
php artisan app:reorganize-product-groups             # 实际重建
```

> 迁移器可能把老站一级组直接当作 TuraIDC 类型（code 错乱 / visible 被过滤 → 商品列表为空），必须执行本命令规范化。

---

## 五、Step 3：产品 / 用户 / 订单迁移

```bash
# dump 已用 normalize_mofang_dump.py 规范化后，执行主迁移器
python3 backend/scripts/mofang_to_turaidc_migrator.py --dry-run   # 预演
python3 backend/scripts/mofang_to_turaidc_migrator.py --truncate  # 干净库实际迁移
```

产出：

- `products` 1337+ 个（id 与老站 `shd_products.id` 一致）
- 用户 1279+（id 与 `shd_clients.id` 一致）
- 订单 / 账单 / 工单 / 服务等关联数据

关键兼容性处理：

- **密码**：`###md5` 前缀 + 32 位 MD5，由 `ZjmfLegacyPasswordVerifier` 验证后升级 bcrypt。
- **pricing（重要）**：目标 `products.pricing` 是**扁平格式** `{"monthly":"25.00"}`（当前模型与结算链路均按扁平解析）。切勿使用旧的嵌套格式 `{"monthly":{"price":25}}`，否则价格为 0、报价报"无效计费周期"。
- **invoice_no 唯一性**：源数据约 42% 为无效值（`'NULL'` / `'INV-0'` 等），迁移器自动生成 `INV-{id:08d}`。
- **operation_logs 主键**：不迁移源 id，避免与应用日志主键冲突。
- **remark 截断**：全部 `remark` 映射截断到 255 字符。
- **外键依赖**：主流程结束后自动把二级组复制到 `third_product_groups`，满足 `products.product_group_id` 外键。

---

## 六、Step 4：配置项迁移（config_options）与 OS version 修复

### 1. 组装 config_options

```bash
python3 backend/scripts/mofang_config_options_migrator.py --dump 25y_*.sql --dry-run
python3 backend/scripts/mofang_config_options_migrator.py --dump 25y_*.sql --output /tmp/config_options.sql
```

来源表：`shd_product_config_links`（按 `link.pid` 关联产品）+ `shd_product_config_options` + `shd_product_config_options_sub` + `shd_pricing`。

**格式硬性要求（否则报价接口 500）**：

- `config_options` 必须是**配置项数组**，每项含 `field / name / option_type / hidden / sub / qty_minimum / qty_maximum / parameter / unit`。
- **严禁**把对接元数据（`mofang_host` / `zjmf_api_id` / `upstream_pid`）放进 `config_options`（`parseField(array)` 会类型错误）。对接字段放 `product_upstream_bindings`。
- 配置项类型映射（与后端 `TYPE_FIELD_MAP` 一致）：`6/7/16→cpu`、`8/9/17→memory`、`12→area`、`5→os`、`13/14/19→system_disk_size`、`10/11/18→bw`、`4→ip_num`。

### 2. OS 配置分组修复（用户可见的"系统选择"）

前端 `buildOsGroups` 按 `sub[].version` 的 `大类^版本` 格式分组。dump 中 os 的 sub 常为混乱格式（`12|CentOS^CentOS-7.6.1810-x64`、`Windows^Windows^Windows7`、`Windows2022|Windows`），必须修复：

```bash
php artisan app:fix-os-versions --dry-run
php artisan app:fix-os-versions
```

期望结果：`os_items=439、missing_version=0`；分组 CentOS:8、Ubuntu:5、Debian:3、Windows:5、Rocky:2、Tencentos:5。

> **引用陷阱**：`foreach (($o["sub"] ?? []) as &$s)` 中 `??` 产生副本、引用修改丢失（affected=0）。必须先 `isset($o['sub'])` 再 `&$s` 循环。

### 3. 跨库补齐（可选）

若活跃库 `with_config` 少于备份库（如 477 vs 1251）：

```bash
php artisan app:backfill-config-options --dry-run
php artisan app:backfill-config-options
```

---

## 七、Step 5：service_type_code 派生

在 `app:reorganize-product-groups` 中完成：

```sql
UPDATE products p
JOIN third_product_groups t ON t.id = p.product_group_id
JOIN second_product_groups s ON s.id = t.second_product_group_id
JOIN first_product_groups f ON f.id = s.first_product_group_id
SET p.service_type_code = f.code
WHERE p.service_type_code IS NULL OR p.service_type_code = '';
```

迁移器 `FieldMap(src="server_type")` 会失败（dump 无 `server_type` 列），必须由分组命令回填。

---

## 八、Step 6：上游同步（suppliers + 绑定 + 密钥解密）

```bash
php artisan app:sync-upstreams --dry-run
php artisan app:sync-upstreams --api-keys-file /tmp/keys.json   # 推荐提供预解密明文密钥
```

1. **解析上游主数据**：旧库 `shd_zjmf_finance_api`（常见 19 个上游）。落表 `suppliers`（`code = zjmf_api_<api_id>`）+ `supplier_plugin_bindings`（base_url / account_name + 加密 `secret_json`）。
2. **安装启用插件**：命令自动 `PluginInstaller` 安装并启用 `upstream/zjmf_finance`（provider_key 解析依赖）。
3. **产品级绑定**：映射在旧库 `shd_products` 的 `zjmf_api_id` / `upstream_pid` 字段（产品 id 与老站一致），写入 `product_upstream_bindings`。
4. **API 密钥解密**（认证必做，否则全部"接口认证失败"）：
   - 算法：`DES-CBC`，key = `md5("shundai")` 前 8 字节 = `3491f0a7`，IV 同值，PKCS#7 填充 + base64。
   - **PHP 8.3 / OpenSSL 3 已移除单 DES**。命令自动调用 openssl CLI（`-provider legacy -provider default`）解密；无 CLI 时用 `--api-keys-file` 传入预解密 JSON（`{"api_id":"明文"}`）。
   - 手动参考：`printf %s 密文 | openssl enc -d -des-cbc -provider legacy -provider default -K 3334393166306137 -iv 3334393166306137`
5. **验证**：`curl -X POST {base_url}/zjmf_api_login -d "username=..&password=明文"` 返回 jwt + "鉴权成功"。

> **坑位**：`shd_products` 的 dump INSERT 是 **107 字段**（v10 新增列）与 CREATE TABLE 83 列**顺序不同**，`zjmf_api_id`/`upstream_pid` 实际索引为 **[74]/[75]**（不要用 CREATE 列序 50/51）。从旧库**表**读取时无此问题。

---

## 九、Step 7：实名认证迁移

老站实名在 `shd_certifi_person`（约 584 条）与 `shd_certifi_company`（约 15 条）；`shd_certification` 空表忽略。

```bash
php artisan app:migrate-real-name --dry-run
php artisan app:migrate-real-name
```

状态映射（老 → 新）：

| 老 status | 含义      | 新 `is_verified` | 新 `verification_status` |
| --------- | --------- | ---------------- | ------------------------ |
| 1         | 通过      | 1                | 2（已认证）              |
| 2         | 待审      | 0                | 1（待认证）              |
| 3/4       | 驳回/失败 | 0                | 3（认证失败）            |

处理规则：

- `auth_user_id` 与新系统 `users.id` 一致（老站客户端 id）。
- 同一 uid 在 person/company 重叠（约 6 个）：**取 `update_time` 最新**写 `users`，其余写 `verification_histories`。
- `id_card` 经 `LegacyEncrypted` cast 自动加密存储。

---

## 十、Step 8：老用户密码策略

- 老站密码 `###` + 32 位 MD5 原样保留，由 `ZjmfLegacyPasswordVerifier` 验证后升级 bcrypt。
- 若密码迁移未做 / 失败：删除该逻辑，强制老用户走"忘记密码"流程重置（避免遗留安全隐患）。

---

## 十一、Step 9：缓存预热与验证

```bash
cd backend
php artisan optimize:clear
php artisan app:warmup-site-cache   # 改动分组 / 商品后必跑
```

验证清单：

- `GET https://api.25y.cn/api/v2/site/home` → 200
- 产品详情 API 的 `config_options`：OS 项 `sub[].version` 为 `大类^版本`
- 报价接口：`POST /api/v2/site/products/{id}/quote` → 返回 `total_amount`，不 500
- 官网产品列表"系统选择"按大类分组显示
- 供应商管理"测试连接"全部通过（jwt 鉴权成功）
- 后台用户列表可见 `real_name` / 实名状态

---

## 十二、Step 10：服务实例上游绑定回填

**现象**：已售出开通的服务在控制台显示"未接入完整控制能力，只读展示基础信息"。

**根因**：`service_upstream_bindings` 为空 → `upstream.host_id=0` → 前端 `canManageConsole` 不通过。

```bash
php artisan services:backfill-upstream-bindings --dry-run
php artisan services:backfill-upstream-bindings --service-ids 1,2,3   # 可定向
```

匹配逻辑：

1. **notes ID 优先**：从旧库 `shd_host.notes` 的 `ID：xxx` 提取（全角/半角冒号都兼容，用 `mb_strpos` + 子串正则）。
2. **上游 `/host/list` 兜底**：对无 notes 的服务，按供应商分页拉取全部主机（`page` + `limit=100`，注意 `limit` 生效、`pageSize/pagesize` 无效），按 `domain`（主机名）精确匹配。
3. 写入 `service_upstream_bindings`：`service_id`、`product_upstream_binding_id`、`supplier_plugin_binding_id`、`plugin_id`、`provider_key`、`upstream_service_id`。

参考结果：写入 1564 条绑定、0 失败；Active 309/388、Suspended 226/244 已接入控制。

> **供应商 base_url 坑**：`http://` 会被生产环境 HTTPS 校验拒绝（`assertTrustedBaseUrl`），先确认上游支持 HTTPS 再改。
> **边界**：上游 `/host/list` 中不存在的主机（上游已删除/历史数据）与自营产品（无 `product_upstream_bindings`）无法绑定，属正常。

验证：`GET /api/v2/client/services/{id}` 返回 `upstream.host_id > 0`、`actions.power=true`、`actions.module_status=true`。

---

## 十三、生产部署必踩的坑

1. **两库差异**：`tura_25y`（活跃）vs `turaidc`（备份）。修复脚本要在活跃库上重跑，别切库。
2. **`.user.ini`**：宝塔会在 `dist/` 和 `backend/public/` 生成带 immutable 属性的 `.user.ini`，前端重建前 `chattr -i` 删除，否则 vite 报 `ENOTDIR`。
3. **`config_options` 必须是数组**，否则报价接口 500。
4. **OS version 格式** `大类^版本`，前端 `buildOsGroups` 依赖。
5. **INSERT 107 字段 ≠ CREATE 83 列**：`zjmf_api_id` / `upstream_pid` 在 [74]/[75]。
6. **PHP 8.3 无单 DES**：DES-CBC 用 openssl legacy provider 或 Python pycryptodome。
7. **`??` 副本引用陷阱**：foreach 引用修改前先 `isset`。
8. **Nginx `error_page 404`**：api 站点必须注释，否则 Laravel JSON 404 被替换成 HTML。
9. **`perPage` 签名**：`ListTicketsRequest` / `ListLedgerRequest` / `ListNotificationsRequest` 子类必须与 `ClientFormRequest` 基类签名一致，否则 PHP Fatal（列表接口 500）。
10. **前端 `.env.production`**：迁移后易丢，官网 / 控制台 / 后台三个都要建（`VITE_API_BASE_URL=https://api.25y.cn/api`，Cookie 域 `.25y.cn`）。
11. **服务控制台只读**：`service_upstream_bindings` 必须回填（见 Step 10）。
12. **上游 base_url 强制 HTTPS**：`http://` 被拒。
13. **`/host/list` 分页参数是 `limit`**：`pageSize` / `pagesize` 无效。
14. **notes ID 提取**：全角冒号正则可能失效，用 `mb_strpos("ID：")` + 子串正则。

---

## 十四、迁移后数据校验

### 行数对账

参考脚本（源数据不同则调整期望值）：

```python
import pymysql
from pymysql.cursors import DictCursor

conn = pymysql.connect(host='...', user='...', password='...', database='...',
                       charset='utf8mb4', cursorclass=DictCursor)
expected = {
    "users": 1279, "products": 1337, "orders": 3369, "invoices": 7814,
    "services": 2970, "tickets": 1130, "ticket_replies": 2814,
    "first_product_groups": 36, "second_product_groups": 207,
}
for t, exp in expected.items():
    with conn.cursor() as cur:
        cur.execute(f"SELECT COUNT(*) AS c FROM `{t}`")
        actual = cur.fetchone()['c']
    print(f"  {t:<28} {actual:>8}/{exp:<8} {'✓' if actual == exp else '✗'}")
```

### 关键字段抽样

- `users.password` 应为 `###md5` 前缀格式。
- `products.pricing` 应为扁平 JSON（`{"monthly":"25.00"}`）。
- `products.config_options` 每项是数组，OS 项 `sub[].version` 为 `大类^版本`。
- `products.service_type_code` 全部回填（`vps/dedicated/hosting/domain/other` 之一）。
- `invoices.invoice_no` 全部唯一。

---

## 十五、常见故障排查

| 现象                                | 原因与处理                                                                      |
| ----------------------------------- | ------------------------------------------------------------------------------- |
| `TRUNCATE` 超时 / 元数据锁          | `SHOW PROCESSLIST` 查找 `Waiting for table metadata lock` 会话并 `KILL`，再重试 |
| `Data too long for column 'remark'` | 在对应 `FieldMap` 添加 `[:255]` 截断                                            |
| 报价接口 500 `parseField(array)`    | `config_options` 里混入了对接元数据对象，必须改为配置项数组并重跑 Step 4        |
| 商品列表为空 / 分组乱               | 一级组 code 错乱，重跑 `app:reorganize-product-groups`                          |
| 上游全部"接口认证失败"              | API 密钥未解密，重跑 `app:sync-upstreams --api-keys-file`                       |
| 服务控制台只读                      | 未回填 `service_upstream_bindings`，执行 Step 10                                |
| 前端首页标题 / favicon 不变         | 品牌配置未预热，`optimize:clear` + `app:warmup-site-cache`                      |

---

## 相关文档

- [本地 IDC 数据迁移流程](./local-idc-data-migration.md)
- [宝塔部署项目指南](../operations/bt-panel-deployment.md)
- [Docker 与 1Panel 部署指南](../operations/docker-and-1panel-deployment.md)
- [部署与调度指南](../operations/deployment-and-scheduling.md)
- [ZJMF 统一迁移方案](../../execution-plans/completed/zjmf-unified-migration.md)
