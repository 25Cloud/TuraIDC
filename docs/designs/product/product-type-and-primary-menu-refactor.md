# 产品类型与一级菜单重构方案

## 1. 背景

当前产品中心里，“商品种类 / 产品类型”和“一级菜单 / 一级分类”耦合在一起。后端 `ProductType` 会通过 `ProductGroupHierarchyService::syncProductTypes()` 同步生成 `first_product_groups`，导致系统把类型当成导航菜单使用。

后续要根据产品类型设计专门的选购页、控制台页、服务动作、状态映射和插件能力，因此必须把两个概念拆开：

- **产品类型**：固定业务枚举，用来决定购买页、控制台、开通、续费、状态、操作能力。
- **一级菜单**：运营侧产品导航入口，用来组织官网产品中心、后台产品目录和用户服务分组。

## 2. 目标

- 每个一级菜单必须绑定一个产品类型。
- 产品类型只能从固定 8 类中选择。
- 多个一级菜单可以绑定同一个产品类型。
- 产品、订单、账单、服务链路都能得到稳定的 `product_type`。
- 官网选购页和用户控制台按 `product_type` 选择专门模板。
- `other` 类型由上游开通插件声明购买页、控制台页和动作能力。
- 移除“动态新增商品种类等于新增一级菜单”的旧耦合。

## 3. 概念模型

```text
产品类型 product_type
  固定 8 类，用于决定业务能力和页面形态。

一级菜单 first_product_groups
  运营导航层。每个一级菜单绑定一个 product_type。

二级/三级分类
  归属于一级菜单，用于进一步组织商品。

产品 products
  归属于一级/二级/三级分类。
  有效 product_type 来自所属一级菜单。

订单/账单/服务
  下单或开通时保存 product_type_snapshot，后续控制台和履约按快照或服务绑定类型处理。
```

## 4. 固定产品类型

| 中文名称 | value              | 页面来源           |
| -------- | ------------------ | ------------------ |
| 云服务器 | `cloud_server`     | 系统内置           |
| 游戏云   | `game_cloud`       | 系统内置           |
| 云电脑   | `cloud_desktop`    | 系统内置           |
| 裸金属   | `bare_metal`       | 系统内置           |
| CDN      | `cdn`              | 系统内置           |
| 其他     | `other`            | 上游插件提供       |
| 物理机   | `physical_machine` | 系统内置           |
| 虚拟主机 | `web_hosting`      | 系统内置或插件增强 |

## 5. 现有数据迁移映射

当前本地数据里一级菜单的 `code` 被当作产品类型使用。迁移后，`code` 继续作为一级菜单标识，新增 `product_type` 作为业务类型。

| 当前一级菜单 code | 当前名称 | 新 product_type    |
| ----------------- | -------- | ------------------ |
| `vps`             | 云服务器 | `cloud_server`     |
| `dedicated`       | 游戏云   | `game_cloud`       |
| `domain`          | 云电脑   | `cloud_desktop`    |
| `type_iwjqnj`     | 裸金属   | `bare_metal`       |
| `other`           | CDN      | `cdn`              |
| `type_ipragu`     | 其他     | `other`            |
| `type_tgynng`     | 物理机   | `physical_machine` |
| `type_1`          | 虚拟主机 | `web_hosting`      |

注意：当前 `code=other` 的菜单名称是 CDN，迁移后它的 `product_type` 应是 `cdn`。真正的插件型“其他”由 `product_type=other` 表示。

## 6. 数据结构调整

### 6.1 first_product_groups

新增字段：

```text
product_type varchar(50) not null default 'other'
```

建议索引：

```text
first_product_groups_product_type_index(product_type)
first_product_groups_visible_product_type_sort_index(is_visible, product_type, sort_order, id)
```

字段职责：

| 字段                  | 职责                                 |
| --------------------- | ------------------------------------ |
| `code`                | 一级菜单自身标识，不再等于产品类型   |
| `name`                | 一级菜单展示名称                     |
| `product_type`        | 固定 8 类之一                        |
| `legacy_product_type` | 历史映射字段，仅用于旧数据追溯和迁移 |

### 6.2 products

当前保留：

| 字段                     | 用法                                                     |
| ------------------------ | -------------------------------------------------------- |
| `product_type`           | 产品有效类型，写入所属一级菜单的 `product_type`          |
| `service_type_code`      | 与 `product_type` 同步写固定 8 类，不能再写一级菜单 code |
| `first_product_group_id` | 一级菜单归属                                             |

产品创建、批量绑定、供应商商品导入时，不允许前端随意传入产品类型覆盖一级菜单类型。后端应从 `first_product_group_id` 或 `first_product_group_code` 解析出 `product_type`。

### 6.3 快照字段

订单、账单、服务相关快照继续保存产品类型：

```text
product_type_snapshot
```

新数据写固定 8 类之一。历史旧值通过迁移回填为固定 8 类，读取路径仍保留最小归一化能力，避免未清理数据继续暴露旧菜单 code。

## 7. 后端服务调整

### 7.1 ProductType

`App\Constants\ProductType` 同时承担两类职责：

- `businessAllowedValues()` 返回固定 8 类。
- `businessLabelOf()`、`businessIconOf()` 从固定目录解析。
- `allowedValues()` 保留为一级菜单 code 列表，用于 `settings.product/product_types` 和 `first_product_groups.code`。
- `normalizeBusinessValueFromMenuCode()` 只用于旧菜单 code 到业务类型的迁移和修复。

### 7.2 ProductTypeService

当前 `ProductTypeService` 继续负责一级菜单管理，但资源必须同时返回菜单 code 和业务类型：

```text
list()
create(label, icon, product_type)
update(value, label, icon, product_type)
delete(value)
```

职责边界：

- 管理一级菜单。
- 创建一级菜单时必须填写 `label/product_type`，`value/code` 由后端生成或沿用既有菜单 code。
- 更新一级菜单时允许修改 `product_type`，但必须同步影响产品链路。
- 删除一级菜单前检查二级/三级分类和商品占用。
- 统计菜单下商品数、分组数。

### 7.3 ProductGroupHierarchyService

调整重点：

- `syncProductTypes()` 只同步一级菜单，不再把固定业务产品类型当菜单 code。
- 历史分类同步时，先找到或创建一级菜单，再写入一级菜单的 `product_type`。
- `ensureFirstProductGroup()` 不应把产品类型当一级菜单 code。
- `buildProductHierarchyPayload()` 返回的 `service_type_code` 应来自一级菜单的 `product_type`。

### 7.4 ProductExperienceResolver

新增体验解析服务：

```text
resolvePurchaseExperience(Product $product): array
resolveConsoleExperience(Service $service): array
resolveCapabilities(Product|Service $target): array
```

解析顺序：

```text
产品显式模板配置
-> product_type 内置模板
-> product_type=other 时走上游插件能力
-> 无能力时禁止在线购买或展示基础控制台
```

## 8. 接口调整

### 8.1 管理端接口

新增只读产品类型接口：

```text
GET /api/v2/admin/product-type-options
```

响应示例：

```json
{
  "code": 0,
  "data": {
    "list": [
      {
        "value": "cloud_server",
        "label": "云服务器",
        "is_plugin_driven": false
      }
    ]
  }
}
```

一级菜单管理接口：

```text
GET    /api/v2/admin/product-menus
POST   /api/v2/admin/product-menus
PUT    /api/v2/admin/product-menus/{menu}
DELETE /api/v2/admin/product-menus/{menu}
POST   /api/v2/admin/product-menus/reorders
```

一级菜单资源字段：

```json
{
  "id": 1,
  "code": "vps",
  "name": "云服务器",
  "product_type": "cloud_server",
  "product_type_label": "云服务器",
  "is_visible": 1,
  "sort_order": 1,
  "product_count": 12,
  "group_count": 4
}
```

### 8.2 官网接口

新增或替换：

```text
GET /api/v2/site/product-menus
GET /api/v2/site/product-groups?first_product_group_id=...
GET /api/v2/site/products
GET /api/v2/site/products/{product}
```

产品详情应返回：

```json
{
  "id": 1,
  "product_type": "cloud_server",
  "product_type_label": "云服务器",
  "purchase_template": "compute",
  "provider_key": "zjmf_finance_api",
  "plugin_key": "zjmf_finance",
  "purchase_capabilities": ["quote", "checkout", "config_schema"]
}
```

### 8.3 控制台接口

服务列表和服务详情应返回：

```json
{
  "product_type": "cloud_server",
  "product_type_label": "云服务器",
  "console_template": "compute",
  "provider_key": "zjmf_finance_api",
  "plugin_key": "zjmf_finance",
  "capabilities": ["runtime", "network", "security", "vnc"]
}
```

前端按 `console_template` 和 `capabilities` 渲染，不再用一级菜单 code 或中文名称猜页面。

## 9. 官网选购页分流

新增前端 resolver：

```text
purchaseExperienceResolver(product)
```

推荐模板：

| product_type       | purchase_template  | 说明                         |
| ------------------ | ------------------ | ---------------------------- |
| `cloud_server`     | `compute`          | 当前机器配置页演进           |
| `game_cloud`       | `compute_game`     | 游戏云专用文案和规格         |
| `cloud_desktop`    | `cloud_desktop`    | 云电脑账号、系统、桌面规格   |
| `bare_metal`       | `bare_metal`       | 裸金属规格、带宽、机房       |
| `cdn`              | `cdn`              | 域名、流量包、带宽、区域     |
| `physical_machine` | `physical_machine` | 物理机硬件、托管、带宽       |
| `web_hosting`      | `web_hosting`      | 空间、数据库、流量、绑定域名 |
| `other`            | `plugin_schema`    | 插件提供 schema，平台渲染    |

`other` 规则：

- 必须绑定有效上游插件。
- 插件必须提供购买 schema 或明确声明不可在线购买。
- 无 schema 时展示“暂不支持在线购买 / 联系销售”，禁止进入伪配置页。

## 10. 用户控制台分流

新增前端 resolver：

```text
consoleExperienceResolver(service)
```

推荐模板：

| product_type       | console_template   | 说明                            |
| ------------------ | ------------------ | ------------------------------- |
| `cloud_server`     | `compute`          | 开关机、重装、VNC、网络、安全组 |
| `game_cloud`       | `game_compute`     | 游戏云运行状态、网络、防护      |
| `cloud_desktop`    | `cloud_desktop`    | 桌面入口、账号、远程连接        |
| `bare_metal`       | `bare_metal`       | 物理资源、带宽、远程管理        |
| `cdn`              | `cdn`              | 域名、流量、证书、刷新/预热     |
| `physical_machine` | `physical_machine` | 物理机运维信息                  |
| `web_hosting`      | `web_hosting`      | FTP、数据库、域名绑定、空间流量 |
| `other`            | `plugin_schema`    | 插件提供控制台 schema/actions   |

控制台动作必须由后端能力控制，前端只根据后端返回的 capability 展示按钮。

## 11. 插件型 other 设计

`other` 代表插件驱动产品，不代表通用杂项页面。

### 11.1 规则

- `product_type=other` 的产品必须绑定有效上游插件。
- 插件不注册系统级路由、系统级定时任务或全局中间件。
- 平台统一负责鉴权、审计、订单、账单、支付、服务状态机。
- 插件只提供能力：配置 schema、报价参数、开通参数、控制台动作、状态映射。

### 11.2 建议能力接口

```text
ProvidesPurchaseExperience
ProvidesConsoleExperience
ProvidesProductStatusMapping
```

示例能力返回：

```json
{
  "purchase_schema": {
    "template": "plugin_schema",
    "fields": []
  },
  "console_schema": {
    "template": "plugin_schema",
    "sections": [],
    "actions": []
  },
  "status_mapping": {
    "active": "运行中",
    "suspended": "已暂停"
  }
}
```

## 12. 实施计划

### Phase 1：后端基础模型

目标：

- 给 `first_product_groups` 增加 `product_type`。
- 固定 8 类 `ProductType`。
- 增加旧值到新值的 mapper。
- 完成现有一级菜单回填。

验收：

- 所有一级菜单都有合法 `product_type`。
- 产品类型资源返回一级菜单 code，并附带固定 8 类 `product_type`。
- `php artisan test` 中产品类型相关测试通过。

### Phase 2：产品链路改造

目标：

- 产品创建、编辑、批量绑定、供应商导入统一从一级菜单解析产品类型。
- `products.product_type` 和 `service_type_code` 写固定 8 类。
- 订单、账单、服务快照写固定类型，历史快照由迁移回填。

验收：

- 保存商品后 `products.product_type` 是固定 8 类之一。
- 批量绑定供应商商品不再把一级菜单 code 当产品类型。
- 受影响 Feature 测试通过。

### Phase 3：管理端产品中心

目标：

- 把“管理一级分类”调整为“一级菜单管理”。
- 表单增加产品类型下拉。
- 移除动态新增产品类型能力。
- 列表展示一级菜单名称、产品类型、商品数、分组数。

验收：

- `frontend-admin-v3` 执行 `pnpm.cmd run build`。
- 后台可以创建/编辑一级菜单并绑定产品类型。

### Phase 4：官网选购页

目标：

- 官网导航按一级菜单展示。
- 产品详情按 `purchase_template` 渲染。
- 先把当前机器购买页收敛为 `compute` 模板。
- `other` 使用插件 schema，缺失 schema 时禁止在线购买。

验收：

- `frontend-user-v3-www` 执行 `pnpm.cmd run build`。
- 云服务器类产品选购链路可用。
- `other` 无插件能力时不能进入伪购买流程。

### Phase 5：用户控制台

目标：

- 服务列表和详情返回 `console_template` 与 capabilities。
- 控制台按模板渲染。
- 动作按钮只按 capability 展示。

验收：

- `frontend-user-v4-console` 执行 `pnpm.cmd run build`。
- 现有云服务器控制台能力不回退。
- 非对应类型不展示错误动作按钮。

### Phase 6：插件型 other 闭环

目标：

- 给 `demo_servers` 或实际插件补充购买/控制台 schema。
- 打通一个 `product_type=other` 的产品链路。
- 平台统一渲染插件 schema。

验收：

- 插件 simulation test 通过。
- `other` 产品购买页、开通、控制台详情有完整最小链路。

### Phase 7：清理旧耦合和文档

目标：

- 清理把 `settings.product_types.value` 当业务产品类型的旧逻辑。
- 前端变量从 `productTypes` 逐步改为 `productMenus` / `productTypeOptions`。
- 更新 API 文档和架构文档。

验收：

- API 清单重新生成。
- 三个前端构建通过。
- 后端完整测试通过。

## 13. 风险与处理

| 风险                              | 影响                   | 处理                                                                                    |
| --------------------------------- | ---------------------- | --------------------------------------------------------------------------------------- |
| 旧 `product_type` 值混杂菜单 code | 购买页和控制台分流错误 | 用 mapper 统一旧值，新增数据只写固定 8 类                                               |
| `other` 无插件能力却允许购买      | 产生无法开通订单       | 后端校验：`other` 必须有有效插件能力或禁止上线购买                                      |
| 前端继续用中文名/code 猜模板      | 页面错配               | 后端返回 `purchase_template`、`console_template`、capabilities                          |
| 历史订单/账单被批量改坏           | 财务追溯风险           | 迁移只改 `product_type_snapshot` 的旧枚举值，不改金额、状态、支付流水；异常值保留并记录 |
| 插件绕过平台状态机                | 审计和财务风险         | 插件只提供 schema/actions，平台统一执行订单、账单、服务状态                             |

## 14. 最终验收清单

- [ ] 一级菜单和产品类型已解耦。
- [ ] 产品类型只能是固定 8 类。
- [ ] 每个一级菜单都有合法 `product_type`。
- [ ] 产品创建、编辑、导入、批量绑定都按一级菜单解析产品类型。
- [ ] 官网购买页按 `purchase_template` 分流。
- [ ] 用户控制台按 `console_template` 和 capabilities 分流。
- [ ] `other` 产品必须由插件提供购买/控制台能力。
- [ ] 后端执行 `php artisan test`。
- [ ] 管理端执行 `pnpm.cmd run build`。
- [ ] 官网执行 `pnpm.cmd run build`。
- [ ] 用户控制台执行 `pnpm.cmd run build`。
