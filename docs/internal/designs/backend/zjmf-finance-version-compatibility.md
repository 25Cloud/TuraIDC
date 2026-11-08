---
status: active
updated: 2026-11-08
owner: backend-platform
---

# 魔方财务上游的版本兼容层

状态：已实现（v0.4.6 未发布）
范围：`backend/plugins/servers/zjmf_finance/`
触发事件：2026-11-07 供应商 17 升级魔方财务后，本系统整批商品无法自动开通

## 背景

本系统的魔方财务（ZJMF）上游对接一直以 **3.7.6** 为基准写的。3.8.0 起上游有两处破坏性变化：

1. **openapi 模块被整体删除**，也就是 `/v1/user`、`/v1/invoices/{id}`、`/v1/invoices/{id}/fund`、`/v1/hosts/{id}/actions/upgrade*` 这一整套接口不存在了。
2. **`cart/get_product_config` 加了 `UpstreamApiCheck` 中间件**：只允许 `/zjmf_api_login`（下游财务）或 `/resource_login_supplier`（资源池 usertype=3）换到机机的账号访问，校验不通过返回 405/400；部分商家升级后直接删掉了这条路由，访问得到站点的 404 页面。

于是同一个插件要同时面对两类站点：还在 3.7.6 的一大片（升级要付费，很多商家不动），和已经上了 3.8.0 的。

## 现象与证据

2026-11-07 供应商 17（`www.cnmrnmb.online`）实测：

| 接口                                                              | 结果                                                                   |
| ----------------------------------------------------------------- | ---------------------------------------------------------------------- |
| `POST /zjmf_api_login`                                            | 200（鉴权失败属正常，说明路由在）                                      |
| `GET /cart/all`                                                   | 200，返回 49 个分组 / 318 个商品                                       |
| `GET /cart/credit`                                                | 200，返回余额                                                          |
| `POST /cart/clear`、`POST /cart/add_to_shop`、`POST /cart/settle` | 正常                                                                   |
| `POST /apply_credit`                                              | 正常（购物车结算页那条）                                               |
| `GET /cart/get_product_config`                                    | **404 页面**（带 JWT、带会话 cookie 都一样；换任意现役商品 id 也 404） |
| `GET /api/product/prodetail`、`GET /api/product/proinfo`          | **200**，返回完整商品行 + 配置项 + 价格                                |
| `GET /v1/*`（user / hosts / invoices）                            | **全部 404**                                                           |

即：商删掉的是 `/v1` 与 `cart/get_product_config`，而 `cart/*` 的其余路由、`/apply_credit`、`api/product/*` 都还在。

由此确定两个可移植的替代：

- 商品配置 → `api/product/prodetail`（3.7.6 → 3.8.0 都注册着）
- 账单余额支付 → `POST /apply_credit`（购物车结算页那条，全版本都在）

## 方案

不做「按版本号到处 if」，而是收敛成两条能力，再靠候选顺序 + 失败回退兜住填错的情况。

### 两个配置项

都写在供应商绑定的 `provider_config`（`supplier_plugin_bindings.config_json`）里，老记录缺省即 `auto`，不需要迁移：

| 键                | 取值                                        | 作用                                                                                   |
| ----------------- | ------------------------------------------- | -------------------------------------------------------------------------------------- |
| `version_profile` | `auto`（默认）/ `legacy_376` / `modern_380` | 只影响候选顺序：老版本先试 `cart/get_product_config`，新版先试 `api/product/prodetail` |
| `use_v1_api`      | `auto`（默认）/ `always` / `never`          | `never` 时任何 `/v1/*` 都不再发出，直接给可操作提示；`auto` 不预先封死，靠 404 回退    |

### 候选顺序

- 商品配置：`auto` / `modern_380` → `api/product/prodetail` → `cart/get_product_config`
- 商品配置：`legacy_376` → `cart/get_product_config` → `api/product/prodetail`
- 余额支付：`legacy_376` + 允许 `/v1` → `/v1/invoices/{id}/fund` → `/apply_credit`
- 余额支付：其余情况 → `/apply_credit` →（允许 `/v1` 时）`/v1/invoices/{id}/fund`

选错档位只会多一次探测请求，不会直接失败——这是「一个逻辑全兼容」的兜底。

### 实现位置

| 文件                               | 职责                                                                       |
| ---------------------------------- | -------------------------------------------------------------------------- |
| `lib/ZjmfCompatibilityProfile.php` | 从 `provider_config` 解析档位，产出候选顺序                                |
| `lib/ZjmfCapabilityMemory.php`     | 按供应商记住实测走通的接口（缓存 6 小时）                                  |
| `lib/ZjmfProductConfigGateway.php` | 商品配置唯一出口；把 `prodetail` 归一成 `cart/get_product_config` 的老结构 |
| `lib/ZjmfFinanceTransport.php`     | `payInvoiceWithCredit()` / `fetchInvoice()` / `/v1` 守门                   |

`prodetail` 的返回是 `{data:{detail:{pid:{…商品行, product_pricings, config_groups, customfields}}}}`，网关把它摊平成 `{data:{products, product_pricings, config_groups, customfields, config_links}}`，与 `cart/get_product_config` 同形，所以 `ZjmfCatalogService`、`ZjmfProvisionService` 与 `app/Services/Provisioning/ProvisionService` 的解析逻辑一行都不用改。

## 反方向：本系统作为上游被魔方财务对接

魔方财务在另一头把 TuraIDC 当「财务类上游」时，走的是 `{本站}/api/v2/zjmf/*`（见 `routes/v2-zjmf-upstream.php`）：`zjmf_api_login` 换 JWT，之后 `cart/*`、`host/*`、`apply_credit` 等。这一面 3.7.6 已经跑了很多年，3.8.0 的差异集中在**同步推送的签名**上。

### 签名两代

| 版本         | 算法                                             | 参与字段                                                                  |
| ------------ | ------------------------------------------------ | ------------------------------------------------------------------------- |
| 3.7.6 及更早 | `upper(md5(json(ksort([id, token, rand_str]))))` | 只有这三个                                                                |
| 3.8.0 起     | `upper(hmac_sha256(规范化载荷, token))`          | 全部业务字段 + `signature_version=2` + `timestamp` + `nonce` + `rand_str` |

规范化载荷 = 去掉 `signature` 与 `token` 后，递归按键名 `SORT_STRING` 排序、`null` 归一成空串、标量统一转字符串，再用 `JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES` 编码。`timestamp` 容差 ±300 秒，`nonce` 必须是 32 位十六进制，且一次性。

3.8.0 的接收端默认**拒绝 legacy**（除非它自己开了 `ALLOW_LEGACY_SYNC_SIGNATURES=1`），所以两边都按「v2 优先、被明确拒绝才回退 legacy」实现：

- 出站 `App\Services\ZjmfUpstream\ZjmfDownstreamPushService`：先发 v2；下游回 `400 + 签名验证失败` 时用 legacy 重发一次
- 入站 `App\Http\Middleware\VerifyTicketUpstreamCallbackSignature`：按请求声明的 `signature_version` 分支，v2 校验 timestamp/nonce/HMAC 并做 nonce 重放拦截，legacy 原样保留

算法只写在 `App\Services\ZjmfUpstream\ZjmfSyncSignature` 一处，两侧共用，避免「发 v2、收 legacy」的口径漂移。

### 上游账号前提

3.8.0 给 `api/product/proinfo`、`api/product/prodetail`、`cart/get_product_config` 加了 `UpstreamApiCheck`：下游财务的账号必须开着「供应商 API 功能」（`clients.api_open=1`）或者是资源池账号（`usertype=3`），否则回 405/400。本系统作为下游时如果撞上这个，报错会原样带出来，需要上游管理员把账号的 API 开关打开。

## 流量包：两个方向都要打通

流量包不走上游代理折扣，是两个方向的独立计费项，不打通就等于「卖不了、也买不到」：

- **我们买上游**：`ServiceTrafficPackageService` 已有完整链路——按商品分类匹配后台配置的档位，再按上游能力选 `upgradeconfig`（配置项升级）或 `flowpacket`（HTML 页面 + `dcim/buy_flow_packet`）两种模式，最后用供应商余额付掉上游账单。
- **魔方财务买我们**：魔方不认配置项，只认 `dcim.flowpacket` 列表 + `dcim/buy_flow_packet`。

### 上游侧契约（对魔方财务）

| 环节          | 接口                                            | 说明                                                                                                                  |
| ------------- | ----------------------------------------------- | --------------------------------------------------------------------------------------------------------------------- |
| 档位列表      | `GET /host/header` → `data.dcim.flowpacket`     | `[{id,name,capacity,price,leave,billing_mode,long_term_valid}]`，`id` 直接复用流量值（GB）                            |
| 下单          | `POST /dcim/buy_flow_packet`                    | 入参 `id`（= Service id）+ `fid`/`flow_packet_id`/`selected_capacity` + 可选 `idempotency_key`，出参 `data.invoiceid` |
| 付款          | `POST /apply_credit`                            | 已有通道，余额付掉上面那张账单                                                                                        |
| 履约          | 支付后队列                                      | 复用 `ServiceTrafficPackageService::processPaidTrafficPackageOrder` 向我们的供应商买流量包                            |
| 状态（3.8.0） | `GET /dcim/flow_packet_order_status?invoiceid=` | `code` = `Unpaid` / `Pending` / `Paid`；非流量包账单回 `409 + Unknown`（魔方按「未知」处理）                          |

三个刻意的选择：

- 档位 `id` 用流量值而不是主键——魔方 `fid` 只能是整数，而流量值在同一商品分类内唯一，档位调整时也不会串到别的包上。
- `/host/header` 只读本地目录、绝不访问上游——主机页是下游首屏，一次慢上游会把整页拖死。
- `idempotency_key` 落缓存 24 小时——魔方超时重试很常见，重复提交必须回同一张账单，否则用户被重复扣钱。

### 目录与加价

可售档位存在设置组 `traffic_package_catalog` 的 `items`：键是「商品分类 + 商品类型 + 商品 ID 列表」，逐条带 `cost_price`（上游价即成本）与 `markup_type`（`none`/`percent`/`fixed`）+ `markup_value`，`price` 永远由成本 + 加价算出。所以「一键对接」拉一次就是可直接卖的价，不用手工再改一遍。

### 一键对接与存量数据

`POST /api/v2/admin/products/traffic-package-pulls` 先建任务再返回 `task_id`，任务体 `PullTrafficPackageCatalogJob` 落在 `default` 队列：`queue:drain` 只消费 provision / business / automation 三组，另开队列名在存量部署上压根没人消费，前端会一直 pending 到超时。`GET /products/traffic-package-pulls/{task}` 轮询进度；`QUEUE_CONNECTION=sync` 时直接就地执行并回 `done`，顶层继续平铺 `source`/`traffic`/`packages` 兼容老前端。

存量站点常见两种坏数据：目录是空的，匹配配置被存成空串（`option_field`/`option_keyword` 为空且两种模式都关）——功能即使打开也永远匹配不到档位。`php artisan traffic:backfill-catalog` 按分类跑一遍与一键对接相同的拉取逻辑写入目录（成本价 + 加价，上游没报价的档位写 `enabled=0`），`--repair-config` 只补空值与「两种模式都关」的坏配置，**不动总开关**。

## 取舍与已知限制

- **升级类操作（升级配置 / 升级套餐）暂时没有可移植替代**：3.8.0 的入口是 `/upgrade/*` 系列（会话态），参数结构与 `/v1/hosts/{id}/actions/upgrade*` 不同，需要在真机上调通再改，先做的是「明确报错 + 指路」。关闭 `/v1` 或在 3.8.0 站点上实测 404 后，提示为「请在供应商后台手工操作，或把该供应商的接口版本设为 3.7.6 及更早」。
- **`api/product/proinfo` / `prodetail` 在 3.8.0 也挂了 `UpstreamApiCheck`**：本系统的供应商账号必须开着「供应商 API 功能」（`clients.api_open=1`）或是资源池账号（`usertype=3`），否则上游会返回 405/400。这属于上游账号配置，不是代码问题，报错信息会原样带出来。
- **档位只对「能读到 `provider_config` 的供应商对象」生效**：调用链上都是 `supplierWithRuntimeCredentials()` 注入的，未注入时按 `auto` 处理，仍然能跑通。
- **没做版本自动探测接口**：上游没有可靠的版本查询端点，只能靠打一次看结果再记住；`auto` 档位第一次访问会有一次额外请求。
- **控制台「规格升级」的选项形状靠容错**：`ServiceUpgradeService::previewForUser()` 把上游 `/v1/hosts/{id}/actions/upgrade` 的 `data` 原样透传，仓库里没有该响应的权威样例，前端只能按别名容错解析（容器 `options/products/upgrade_products/list/items`，id 别名 `product_id/pid/id`）。3.8.0 上游已删掉 `/v1`，这条链路只在 3.7.6 站点可用，等拿到真实响应再在后端归一。
- **降级（负差价）直接拒绝**：`ServiceUpgradeService::createInvoiceForUser()` 在 `amount_total < 0` 时回业务错误。负金额账单会被支付链路算成「无需支付」并直接置为已付，等于白送一次降配；降级要走人工。
- **产品到产品的升级仍是 400 占位**：3.8.0 的 `upgradeProductCommon()` 对上下游商品直接拒绝，上游自己就禁了这条链路；本系统保留接口形状返回明确原因。

## 验证

- `backend/tests/Unit/ZjmfCompatibilityProfileTest.php`：档位解析与候选顺序
- `backend/tests/Unit/ZjmfProductConfigGatewayTest.php`：`prodetail` 归一化、双通道回退、命中后记住、批量单请求
- `backend/tests/Unit/ZjmfInvoicePayFallbackTest.php`：余额支付通道、`already_paid` 归一、`/v1` 守门与 404 记忆
- `backend/tests/Feature/ZjmfUpstreamFlowPacketTest.php`：上游侧档位下发、按幂等键只建一张账单、`Unpaid/Pending/Paid` 三态
- `backend/tests/Feature/AdminTrafficPackagePullTaskTest.php`：一键对接的同步降级、异步轮询、失败态
- `backend/tests/Feature/BackfillTrafficPackageCatalogTest.php`：存量目录回填与坏配置修复
- 真机：供应商 17（3.8.0）上重跑一次开通，确认 `product_config_ms` 不再报 404 且能走到 `add_cart`
