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

## 取舍与已知限制

- **升级类操作（升级配置 / 升级套餐）暂时没有可移植替代**：3.8.0 的入口是 `/upgrade/*` 系列（会话态），参数结构与 `/v1/hosts/{id}/actions/upgrade*` 不同，需要在真机上调通再改，先做的是「明确报错 + 指路」。关闭 `/v1` 或在 3.8.0 站点上实测 404 后，提示为「请在供应商后台手工操作，或把该供应商的接口版本设为 3.7.6 及更早」。
- **`api/product/proinfo` / `prodetail` 在 3.8.0 也挂了 `UpstreamApiCheck`**：本系统的供应商账号必须开着「供应商 API 功能」（`clients.api_open=1`）或是资源池账号（`usertype=3`），否则上游会返回 405/400。这属于上游账号配置，不是代码问题，报错信息会原样带出来。
- **档位只对「能读到 `provider_config` 的供应商对象」生效**：调用链上都是 `supplierWithRuntimeCredentials()` 注入的，未注入时按 `auto` 处理，仍然能跑通。
- **没做版本自动探测接口**：上游没有可靠的版本查询端点，只能靠打一次看结果再记住；`auto` 档位第一次访问会有一次额外请求。

## 验证

- `backend/tests/Unit/ZjmfCompatibilityProfileTest.php`：档位解析与候选顺序
- `backend/tests/Unit/ZjmfProductConfigGatewayTest.php`：`prodetail` 归一化、双通道回退、命中后记住、批量单请求
- `backend/tests/Unit/ZjmfInvoicePayFallbackTest.php`：余额支付通道、`already_paid` 归一、`/v1` 守门与 404 记忆
- 真机：供应商 17（3.8.0）上重跑一次开通，确认 `product_config_ms` 不再报 404 且能走到 `add_cart`
