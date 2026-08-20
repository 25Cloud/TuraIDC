# 本地对接说明

## 文档用途

- 只写**项目实际对接**的第三方上游，不写官方参考文档里的大而全列表
- 对齐时间：`2026-07-02`
- 配套参考：
  - 历史官方 ZJMF 财务 API 参考不再随仓库保存；需要时从受控上游来源获取
  - `docs/ARCHITECTURE.md` - 架构位置与入口

## 一、上游供应商

当前实库 `suppliers` 表中已启用的上游：

| 供应商 | `code`       | `interface_type`    | 对接协议         |
| ------ | ------------ | ------------------- | ---------------- |
| 极点云 | `supplier_1` | `hosting_panel_api` | 主机面板 OpenAPI |
| 美得云 | `supplier_2` | `hosting_panel_api` | 主机面板 OpenAPI |

说明：

- 两家已启用上游底层是同一套主机面板 API 协议，本地走 provider 驱动体系适配；`hosting_panel_api` 使用 `HostingPanelApiDriver`
- ZJMF 财务上游统一通过 `backend/plugins/servers/zjmf_finance/` 插件承载；`zjmf_finance_api` 使用插件内 `Lib\ZjmfFinanceDriver` + `Lib\ZjmfFinanceAdapter`
- `zjmf_finance_api` 是独立 provider key，禁止在解析、开通、同步或服务绑定时归一化为 `hosting_panel_api`
- 新增同类上游只需在后台添加 `suppliers` 记录并维护 API 地址、账号、密钥

## 1.1 中间层边界

| 层                  | 职责                                                                | ZJMF落点                                                                                                                         |
| ------------------- | ------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------- |
| Provider 解析       | 按服务绑定、商品绑定、供应商配置解析真实 provider key               | `ProviderResolver`、`ProviderRegistry`                                                                                           |
| Plugin              | 声明 `domain=upstream`、`slug=zjmf_finance`、`key=zjmf_finance_api` | `backend/plugins/servers/zjmf_finance/config.php`、`ZjmfFinancePlugin.php`                                                       |
| Driver              | 暴露供应商 key、能力清单和能力对象                                  | `backend/plugins/servers/zjmf_finance/lib/ZjmfFinanceDriver.php`                                                                 |
| Adapter             | 显式声明平台业务 Service 可调用的ZJMF能力方法                       | `backend/plugins/servers/zjmf_finance/lib/ZjmfFinanceAdapter.php`                                                                |
| Plugin Services     | 登录、商品、开通、续费、状态、控制台、网络、安全组等协议动作        | `backend/plugins/servers/zjmf_finance/lib/Zjmf*Service.php`                                                                      |
| Plugin Transport    | ZJMF 财务请求入口、JWT 解析、401 失效处理和文本/JSON 请求封装       | `backend/plugins/servers/zjmf_finance/lib/ZjmfFinanceTransport.php`、`ZjmfAuthManager.php`                                       |
| Shared Transport    | 仅作为底层 HTTP、DNS/TLS 安全、超时、日志脱敏等共享传输辅助         | `HostingPanelApiTransport.php`                                                                                                   |
| Legacy Zjmf Support | 旧密码兼容、账单恢复等非数据面兼容能力                              | `backend/plugins/servers/zjmf_finance/lib/ZjmfLegacyPasswordVerifier.php`、`ZjmfBillingRestoreService.php`，由插件 Provider 注册 |

扩展规则：

- 业务 Service 不直接拼ZJMF请求，也不直接读取官方字段差异，新增差异先落到插件 service/adapter。
- 能复用主机面板底层传输能力时只能经由 `ZjmfFinanceTransport` 包装；provider key 和能力对象必须保持ZJMF独立身份。
- ZJMF 云商品配置项模板优先在插件内 `ZjmfCloudConfigTemplate` 扩展，通用 fallback 才放到 `Services/Upstream/Support/CloudConfigTemplate`。
- 旧ZJMF密码兼容走 `LegacyPasswordVerifier` 聚合器，具体格式判断放在 `ZjmfLegacyPasswordVerifier`。
- 新增 provider 或能力时同时补最小测试：provider 解析、adapter 显式方法、关键字段映射、失败降级。
- 不允许新增或依赖 `ZjmfFinanceAdapter::__call()` 动态转发；新增能力应先扩展插件内部 service，再在 adapter 上补明确方法。

## 二、入口与关键文件

| 职责                     | 入口                                                                                               |
| ------------------------ | -------------------------------------------------------------------------------------------------- |
| 上游解析入口             | `backend/app/Services/Upstream/ProviderResolver.php`                                               |
| 驱动注册表               | `backend/app/Services/Upstream/ProviderRegistry.php`                                               |
| 主机面板驱动             | `backend/app/Services/Upstream/Drivers/HostingPanelApi/HostingPanelApiDriver.php`                  |
| 主机面板传输层           | `backend/app/Services/Upstream/Drivers/HostingPanelApi/HostingPanelApiTransport.php`               |
| ZJMF 财务插件            | `backend/plugins/servers/zjmf_finance/`                                                            |
| ZJMF 财务驱动            | `backend/plugins/servers/zjmf_finance/lib/ZjmfFinanceDriver.php`                                   |
| ZJMF 财务适配器          | `backend/plugins/servers/zjmf_finance/lib/ZjmfFinanceAdapter.php`                                  |
| ZJMF 财务传输入口        | `backend/plugins/servers/zjmf_finance/lib/ZjmfFinanceTransport.php`                                |
| ZJMF 财务登录与 JWT 管理 | `backend/plugins/servers/zjmf_finance/lib/ZjmfAuthManager.php`                                     |
| ZJMF 云配置项模板        | `backend/plugins/servers/zjmf_finance/lib/ZjmfCloudConfigTemplate.php`                             |
| ZJMF商品类型映射         | `backend/plugins/servers/zjmf_finance/lib/ZjmfProductTypeMapper.php`                               |
| 旧ZJMF密码兼容           | `backend/plugins/servers/zjmf_finance/lib/ZjmfLegacyPasswordVerifier.php`                          |
| 旧密码兼容聚合器         | `backend/app/Services/Auth/LegacyPasswordVerifier.php`                                             |
| 上游协议辅助             | `backend/app/Services/Upstream/Drivers/HostingPanelApi/Concerns/`                                  |
| 商品配置项归一           | `backend/plugins/servers/zjmf_finance/lib/ZjmfCatalogService.php`、`ZjmfCloudConfigTemplate.php`   |
| ZJMF账单恢复工具         | `backend/plugins/servers/zjmf_finance/lib/ZjmfBillingRestoreService.php`                           |
| 支付宝 F2F 插件          | `backend/plugins/gateways/ali_pay/`                                                                |
| 支付宝 F2F 客户端        | `backend/plugins/gateways/ali_pay/lib/AlipayClient.php`                                            |
| 支付宝 F2F 能力服务      | `backend/plugins/gateways/ali_pay/lib/AlipayService.php`                                           |
| 支付宝回调               | `backend/app/Http/Controllers/Client/PaymentCallbackController.php`                                |
| 实名回调                 | `backend/app/Http/Controllers/Client/VerificationController@callback`，走 `verify.callback` 中间件 |
| 回调签名中间件           | `backend/app/Http/Middleware/VerifyCallbackSignature.php`                                          |

## 三、对接的上游能力清单

只列项目真实调用的，不代表官方全量：

| 能力                           | 方法         | 路径                                                          | 本地调用入口                                                                      |
| ------------------------------ | ------------ | ------------------------------------------------------------- | --------------------------------------------------------------------------------- |
| ZJMF登录并换 JWT               | `POST`       | `/zjmf_api_login`                                             | `ZjmfAuthManager::login()` / `ZjmfFinanceTransport::login()`                      |
| ZJMF获取用户资料               | `GET`        | `/v1/user`                                                    | `ZjmfFinanceTransport::getUserProfile()`                                          |
| ZJMF获取账户余额               | `GET`        | `/cart/credit`                                                | `ZjmfFinanceTransport::getBalance()`                                              |
| ZJMF商品目录                   | `GET`        | `/cart/all`                                                   | `ZjmfCatalogService::getProductCatalog()`                                         |
| ZJMF商品配置与库存             | `GET`        | `/cart/get_product_config?pid={productId}`                    | `ZjmfCatalogService`                                                              |
| ZJMF同系统主机详情             | `GET`        | `/host/header?host_id={hostId}&source=API`                    | `ZjmfFinanceTransport::getHostDetail()`；将 `data.host_data` 归一为 `data.host`。 |
| ZJMF开通                       | 多个官方接口 | 购物车、结算、上游支付、host 回查                             | `ZjmfProvisionService::provisionOrder()`                                          |
| ZJMF续费                       | `POST`       | `/host/renew`；`/pay?action=billing&pay=true`；`/check_order` | `ZjmfRenewService`；自动续费仅由本站账单与调度执行，不同步上游开关。              |
| ZJMF状态同步                   | `GET`        | `/host/header?host_id={hostId}&source=API`，以及运行状态接口  | `ZjmfStatusService`                                                               |
| ZJMF VNC、电源、重装、重置密码 | 多个官方接口 | host action / console 接口                                    | `ZjmfConsoleService`                                                              |
| ZJMF流量包、升降级             | 多个官方接口 | upgrade / flowpacket 接口                                     | `ZjmfNetworkService`                                                              |
| ZJMF NAT、安全组、自定义模块   | `GET/POST`   | servicedetail 自定义模块页面和提交接口                        | `ZjmfSecurityService`、`ZjmfConsoleService`                                       |

插件 service 内部通过 `ZjmfFinanceTransport::get/post/put/delete/parallelGet` 调用上游。平台业务 Service 只调用 capability 暴露的高层方法，不直接拼ZJMF请求路径、请求参数或官方响应字段。

## 四、签名与认证约束

- ZJMF JWT 按 `supplier_id + provider_key` 隔离缓存，`provider_key` 必须保持 `zjmf_finance_api`
- ZJMF插件调用前由 `ZjmfAuthManager` 自动 `login`，失败或 401 会清理缓存并触发刷新/重登
- 普通 ZJMF 数据面在获取 JWT 后使用 `Authorization: Bearer <token>`；同系统自定义模块页面和动作使用 `Authorization: JWT <token>`，其中页面请求还必须传入 `id`、`key` 和 `jwt` 查询参数；`/zjmf_api_login` 与官方参考的 `/v1/login_api` 不能混用
- 上游请求失败由插件传输入口和共享底层传输记录脱敏日志，日志必须能区分 `zjmf_finance_api` 与 `hosting_panel_api`
- 上游回调（供应商主动推我们）当前**未**暴露标准 webhook；业务主要靠轮询

## 五、支付对接：支付宝 F2F

### 5.1 能力

| 能力                 | 阿里方法名               | 本地入口                                       |
| -------------------- | ------------------------ | ---------------------------------------------- |
| 预下单生成付款二维码 | `alipay.trade.precreate` | `AlipayService` action `payment.precreate`     |
| 主动查询订单状态     | `alipay.trade.query`     | `AlipayService` action `payment.query`         |
| 交易退款             | `alipay.trade.refund`    | `AlipayService` action `payment.refund`        |
| 验证异步通知签名     | -                        | `AlipayService` action `payment.verify_notify` |

### 5.2 配置入口

通过管理端"通知配置/支付配置"设置以下 `settings` 项：

- `alipay_app_id`
- `alipay_private_key`
- 其余支付宝相关 `alipay_*` 项

### 5.3 回调路径

- 支付宝异步通知：`POST /api/v2/client/payment/alipay/notify`
- 本地回调 URL 约定：`{APP_URL}/api/v2/client/payment/alipay/notify`
- 回调里通过支付插件运行时执行 `payment.verify_notify` 做签名校验，`PaymentService` 做幂等 + 状态落库 + 后续开通/续费派发

## 六、实名认证对接：支付宝实名

### 6.1 客户端接口（面向用户端 SPA）

- `GET /api/v2/client/verification/fee-config` - 获取实名费用配置
- `POST /api/v2/client/verification/init` - 初始化实名（拿 `certify_id`）
- `POST /api/v2/client/verification/qrcode` - 获取扫码二维码
- `GET /api/v2/client/verification/status` - 查询当前状态
- `GET /api/v2/client/verification/scan` - 扫码跳转中转

### 6.2 实名异步回调

- 路径：`GET|POST /api/v2/client/verification/callback`
- 中间件：`verify.callback`（对应 `VerifyCallbackSignature`）
- 控制器：`Client\V2\VerificationController::callback`
- 成功后会更新 `users.verification_*`、`user_accounts` 对应字段，并落 `verification_histories`

### 6.3 管理端可见性

- `GET /api/v2/admin/verifications` - 实名列表
- `GET /api/v2/admin/verifications/{user}/history` - 历史
- `POST /api/v2/admin/verifications/{user}/unbindings` - 后台解绑（按权限）

## 七、与官方文档的映射

| 官方文档                      | 对应本地文件                                                                                                        |
| ----------------------------- | ------------------------------------------------------------------------------------------------------------------- |
| 受控上游的 ZJMF 财务 API 参考 | `backend/plugins/servers/zjmf_finance/lib/ZjmfFinanceAdapter.php` + `Zjmf*Service.php` + `ZjmfFinanceTransport.php` |

建议阅读顺序：

1. 本文（项目对接范围）
2. 对应的官方参考文档（字段语义）
3. 对应的本地 Service 实现

## 八、新增对接时的基线清单

新增上游或支付渠道时，至少满足：

- 在 `suppliers` 表建立记录（或接入相应配置表）
- 新增或复用 `Service` 客户端（不要直接在 Controller 里 `Http::post`）
- 入参、出参、异常落日志（`trace_id` + `supplier_id` + 调用上下文）
- JWT / Token 做缓存 + 过期回退机制
- 调用失败有可观测性（管理端日志页能查）
- 回调必须过 `verify.callback` 或等价签名中间件
- 回调处理必须幂等
- 回调路径、公钥/密钥、商户号等敏感配置走 `settings` 或 `.env`，**不要硬编码**

## 九、尚未接入的官方能力

以下在官方文档里存在，但当前项目**未**主动调用：

- 通用 webhook 推送接收（项目主要依赖轮询）
- 订单维度回调（订单状态靠查询 + 内部状态机自愈）
- 产品库一键全量同步（当前只做 `batch-sync` 与按需拉取）

需要接入时，先在本目录追加章节，再同步 `Upstream` 驱动与传输层。
