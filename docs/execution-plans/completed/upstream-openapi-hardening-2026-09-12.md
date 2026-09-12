---
status: completed
updated: 2026-09-12
owner: backend-platform
---

# 自有上下游对接链路加固（开放 API + 上游驱动体系）

## 背景

对系统自有的上下游对接逻辑（非 ZJMF 财务兼容协议）做了双向审查：

- 作为上游被下游调用：开放 API（`/api/v2/open`，API Key 鉴权）；
- 作为下游调用上游：`app/Services/Upstream/` 驱动体系 + `plugins/servers/`。

审查发现 1 个确认功能缺陷（开放 API 重装参数字段错位）、多项高危短板（写端点零测试、能力契约空接口 duck typing、开通失败资金悬挂、审计盲区等），本次全部修复。

## 进度

全部子项已完成并回归通过：

- [x] 开放 API 重装端点参数对齐控制台：`os_template_id`（nullable integer，从未被服务层读取）→ `os_id`（required string max:50）；补 `GET /services/{id}/reinstall-options` 供下游获取 `os_id`。
- [x] 开放 API 写端点（电源/续费/重装）补 Feature 测试，走 demo_servers 假上游完整 HTTP 链路（`OpenApiWriteEndpointsTest`，7 用例）。
- [x] 能力契约分层：新增 `ProvidesOrderProvisioning`、`ProvidesInvoiceRenewal`、`ProvidesRenewalRecovery`、`ProvidesContextualRenewalRecovery`、`ProvidesRenewableCycleFiltering`、`ProvidesBatchStatusSync`、`ProvidesSupplierBalance`、`ProvidesHostSuspension`；编排层 6 处 `method_exists`/`is_callable` 改为 instanceof；控制台可选功能探测保持原样（属逐驱动可选能力，非缺陷）。
- [x] 余额查询从 `ProvidesRenewal` 拆出为独立契约 `ProvidesSupplierBalance`。
- [x] demo_servers 驱动补齐 `suspendHost`/`unsuspendHost`（原对 demo 实例暂停会 fatal）。
- [x] hosting_panel_api transport 与 ZJMF 认证管理器空凭据前置拒服务（密钥缺省即拒绝）。
- [x] transport User-Agent 由 `ini_set`（进程级全局态，长驻 worker 竞态）改为 per-request stream context。
- [x] 开通队列重试耗尽后，已付未履约账单打 `requires_refund` 标记（`ProcessPaidOrderFulfillmentJob::failed` → `PaymentService::markOrderFulfillmentRequiresRefund`），对齐续费链路口径；仅标记不自动退款。
- [x] 开放 API 审计盲区：认证失败记 usage 日志（api_key_id=0 哨兵）；非 BusinessException（422/429/500）也进审计；`duration_ms` 运算优先级 bug 修复。
- [x] 写接口独立限流 `throttle:open-api-write`（默认 30/分钟，`open_api.write_rate_limit` 可调）。
- [x] 开放 API 列表分页：orders/services/products 支持 `page`/`page_size`（默认维持原 limit 行为，上限 200）。
- [x] IP 白名单支持 CIDR（`App\Support\IpAllowlistMatcher`，v4/v6 通吃，含非字节对齐前缀）。
- [x] 下单幂等 DB 兜底：`invoices.idempotency_key` + `(user_id, idempotency_key)` 唯一索引；CheckoutService 事务内先查既有账单再建单，缓存驱逐（volatile TTL 仅 15 分钟）后重放不再重复建单。
- [x] 电源/重装防重锁（`lock:service:console-action:{serviceId}`，fail-fast）+ 本地快照失败留系统级日志。
- [x] usage 日志归档：`open-api:prune-usage-logs` 命令 + 每周调度（保留 `open_api.usage_log_retention_days`，默认 90 天，分批删除）。
- [x] 零散修复：404 口径统一（BusinessException 替代裸 abort）、`paid` 字段改为按账单状态计算、状态常量替代硬编码 `disabled`、`ProviderKey::label()` 补全四个驱动、ProvisionService 死代码与重复注释清理、暂停编排测试（`ServiceSuspensionUpstreamTest` 3 用例）。

## 决策日志

1. **能力契约分两层而非整体替换空接口**：审查确认 `method_exists` 分发承载真实协议分层——hosting_panel_api transport 只讲通用 REST（购物车开通/续费 fund/逐台同步），ZJMF 等适配器讲高层会话协议。若强行删除守卫会打断主机面板产品全部开通。因此采用「基契约声明最小面 + 扩展子契约声明高层协议」的分层，instanceof 语义与原 method_exists 完全一致，静态可查。
2. **控制台 `is_callable` 探测保留**：电源/重装/NAT/监控等具名方法属逐驱动可选能力（transport 无 powerAction/reinstall，走通用 PUT 回退），与开通/续费/同步的协议分层性质不同，不纳入本次契约化。
3. **requires_refund 只标记不自动退款**：开通失败可能需人工先与上游核实（对账/重开/退款决策），自动退款会掩盖待处理事实；与续费链路 `autoRefundSupersededRenewInvoice` 失败分支同口径。
4. **transport `file_get_contents` 主通道暂不重写**：WAF 挑战重试、SSRF 防护（DNS 白名单）均围绕 stream context 实现，整体迁移 Laravel HTTP 客户端属结构性重构且风险高；本次仅修复其中 user_agent 进程级竞态。迁移工作留待后续专项（tech-debt）。
5. **写限流按 IP 计数**：限流在认证前执行（认证前拿不到密钥），与全局限流同机制；认证后按 key 限流需中间件重排，暂不做。

## 验证

- 新增测试：`IpAllowlistMatcherTest`（12）、`OpenApiWriteEndpointsTest`（7）、`ServiceSuspensionUpstreamTest`（3）、`ProvisionFulfillmentRefundFlagTest`（3）、`OpenApiPruneUsageLogsTest`（1）。
- 修复受契约影响的既有测试 fake：`FakeZjmfProvisioningCapability`、`FakeInvoiceRenewalCapability`、`UpstreamCycleFilterCapability`、`ServiceStatusSyncBindingTest` 匿名类。
- 全量后端回归见提交说明；迁移 `2026_09_12_000001_add_idempotency_key_to_invoices` 仅新增列与索引。

## 关联文档

- [开放 API 设计文档](../../designs/backend/2026-08-24-open-api-design.md)（本次同步修订：限流、密钥格式、审计哨兵、分页、幂等兜底、重装参数）
- [后端工程规范](../../BACKEND.md) §4 第三方插件化标准
