---
status: completed
updated: 2026-09-12
owner: backend-platform
---

# TuraIDC 开放接口上游驱动插件（tura_open_api）

## 背景

开放 API（`/api/v2/open`，被对接侧）已落地并加固，但「无限级复合对接」还缺一环：中间实例作为**下游**调用上游 TuraIDC 时，没有把上游开放 API 封装成本地供应商能力的驱动插件——目前 `plugins/servers/` 只有 demo_servers、kanghostx、zjmf_finance，后者只能走 ZJMF 财务兼容协议。本计划新增 `tura_open_api` 上游驱动插件，让纯自有协议的 TuraIDC↔TuraIDC 链路闭环。

## 设计决策

1. **协议映射**：驱动实现 `ProvidesOrderProvisioning`（报价 → 下单 → 余额支付 → 轮询开通）、`ProvidesInvoiceRenewal` + `ProvidesRenewalRecovery`（同步续费 + 账单状态恢复）、`ProvidesBatchStatusSync`（分页拉取服务列表做状态同步）、`ProvidesSupplierBalance`、`ProvidesConsoleCatalog`（商品目录 + `hydrateSelectedPricing` 按周期报价补价格）。
2. **开通轮询的幂等闭环**：上游开通是异步的（下游侧视角）。驱动用 `tura-open-provision-{orderId}` 作为上游幂等键，队列重试重放 `POST /orders` 会命中上游的幂等缓存/DB 兜底返回同一账单，不重复扣上游余额；轮询超时则让 Job 自然重试继续轮询。
3. **checkpoint 策略**：`provisionOrder` 返回前不落 `upstream_invoice_id`（`assertNoUnresolvedUpstreamProvisionInvoice` 会拦截「有账单无实例」的中间态），只在完整拿到上游 service_id 后一次性返回。
4. **控制台最小可用**：实现 `ProvidesConsoleRuntime` 的 `getHostDetail/powerAction/getReinstallOptions/reinstall`（开放 API 已支持），VNC/监控/自定义模块等不实现（is_callable 探测自动隐藏）；`get/post/put` 通用回退抛明确中文异常。
5. **开放 API 增量**：账单投影补 `service_id`（nullable）——开通轮询需要从上游账单映射到上游服务实例；这是唯一的服务端改动，向后兼容。
6. **已知限制**（写入 README）：开放 API 不暴露配置项，导入商品无自定义配置；目录导入价格按月付报价推导，非月付周期的上游折扣会丢失；无环检测，互为上下游的配置错误靠余额耗尽自然终止。

## 范围与验收

- [ ] 开放 API `presentInvoice` 补 `service_id`。
- [ ] 插件四件套：`config.php` / `TuraOpenApiPlugin.php` / `lib/TuraOpenApiClient.php` / `logic/TuraOpenApi.php`。
- [ ] 供应商表单：api_url + api_key（Bearer）；空密钥前置拒服务。
- [ ] 测试：Http::fake 模拟上游开放 API，覆盖目录导入、开通全流程（含队列重试幂等重放）、续费、续费恢复、余额、状态同步。
- [ ] 全量回归零新增失败；文档同步（开放 API 设计文档、插件 README、执行计划索引、API 清单重生成）。

## 进度

全部子项已完成并验证通过：

- [x] 开放 API `presentInvoice` 补 `service_id`（履约后回填，开通轮询与续费恢复依赖该映射）。
- [x] 插件四件套：`config.php` / `TuraOpenApiPlugin.php` / `lib/TuraOpenApiClient.php` / `logic/TuraOpenApi.php` + README。
- [x] 供应商表单：api_url + api_key（Bearer）；空密钥前置拒服务。
- [x] 测试：`TuraOpenApiDriverTest` 6 用例（Http 闭包 fake 模拟上游）——开通全流程（含幂等键稳定性）、续费（到期取上游 nextduedate）、余额、状态同步、目录导入 + 报价补价、空密钥拒服务。
- [x] 全量回归零新增失败；文档同步（执行计划索引、开放 API 设计文档、catalog.json）。

## 决策日志

- 2026-09-12：不复用 zjmf_finance 兼容链路做自有对接——兼容端点是给魔方财务的，自有协议链路应走开放 API，二者并存。
- 2026-09-12：状态同步用服务列表分页拉取而非逐台详情（详情接口是控制台投影，形状复杂且带缓存/远程同步副作用）。
- 2026-09-12：**manifest capabilities 必须包含基契约**（ProvidesProvisioning/ProvidesRenewal/ProvidesStatusSync）——`PluginUpstreamDriver` 按清单判定 supports()，编排层以基契约作能力门槛；只列子接口会让门控判否、静默落入本地兜底路径（排查中实测踩坑）。
- 2026-09-12：上游商品 ID 真源是 `product_upstream_bindings`（商品表无该列），驱动经 `PluginBindingResolver::upstreamProductIdForProduct` 读取。
