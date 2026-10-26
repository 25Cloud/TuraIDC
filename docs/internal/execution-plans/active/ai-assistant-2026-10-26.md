---
status: active
updated: 2026-10-26
owner: backend-platform
---

# AI 助手与 AI 导购

## 背景

系统目前没有任何模型接入能力，客服、导购、实例运维全靠人工。目标是把「模型 + 工具调用」接进来，让模型只能通过受控工具读写业务：导购找货报价、助手查实例账单、执行电源/续费/重装并在用户确认后建单。

产品形态硬要求：

- 三个前端应用的业务页面都能打开**全局浮窗**（含商品选购/结算、控制台各页）。
- 控制台另外提供**独立完整页**对话（会话列表 + 完整对话）。
- 浮窗与独立页共享同一后端会话与同一前端状态，可互相切换不丢上下文。

设计与决策见 [AI 助手与 AI 导购设计文档](../../designs/architecture/2026-10-26-ai-assistant.md)。本计划只记实施步骤、验收与回滚。

## 范围与验收

- [ ] 后端新增 4 张表（会话/消息/工具调用/用量），迁移只新增，MySQL 5.7.44 与 8.x 均可执行。
- [ ] `settings` 新增 `ai` 组，`api_key` 走 `enc:` 加密，管理端接口只回 `has_value`。
- [ ] OpenAI 兼容与 Anthropic 两个驱动可切换，`FakeAiProvider` 支撑无需真实密钥的测试。
- [ ] 16 个工具按 `read` / `write` / `dangerous` 分级注册，未注册工具名一律拒绝。
- [ ] 写操作经确认协议执行：伪造/过期/篡改参数/重复确认全部被拒或幂等返回。
- [ ] 控制台浮窗可收发消息并渲染确认卡片；独立页 `/ai` 可列历史会话并继续对话。
- [ ] 官网浮窗在商品选购与详情页可用，未登录引导登录。
- [ ] 管理端可配置模型与密钥、开关各端入口、查看用量与会话审计，权限码 `ai.view` / `ai.manage` 生效。
- [ ] 额度与熔断生效：超日额度返回中文提示且不再消耗 token。
- [ ] 三端 `pnpm run build` 通过；`cd backend && php artisan test` 无新增失败。

## 实施步骤

### P0 后端骨架（无 UI，默认关闭）

1. 迁移：`ai_conversations`、`ai_messages`、`ai_tool_invocations`、`ai_usage_daily`（JSON 用 `text`，金额用 int 分，时间列可空）。
2. 模型：`AiConversation`、`AiMessage`、`AiToolInvocation`、`AiUsageDaily`；`$fillable` 精确白名单。
3. 配置：`backend/config/ai_gateway.php`（静态默认值）；`AiConfig`（`group=ai` 读取 + 默认值，样板 `OpenApiConfig`）；`Setting::SENSITIVE_KEYS` 加入 `ai_api_key`。
4. 能力域：`app/Contracts/Integrations/Ai/AiProviderInterface.php`、DTO（`AiChatRequest`、`AiMessage`、`AiToolCall`、`AiChatResult`、`AiUsage`）、`AiProviderManager`、`Drivers/OpenAiCompatibleDriver`、`Drivers/AnthropicDriver`、`tests/Fakes/Integrations/FakeAiProvider`。
5. 编排：`AiChatService`（工具循环、轮次与超时、上下文裁剪）、`AiErrorMapper`、`AiRedactor`。
6. 命令：`ai:doctor`（配置完整性 + 连通性 + 工具注册表自检）。
7. 单测：fake provider 下的工具循环、轮次上限、错误映射、脱敏。

### P1 只读对话 + 控制台浮窗

8. `AiToolRegistry` + `AiToolExecutor` + 只读工具（`search_products`、`get_product_detail`、`quote_price`、`list_my_services`、`get_service_detail`、`get_renew_quote`、`list_my_invoices`、`get_balance`、`list_my_tickets`、`get_ticket`）。一律调 `*QueryService` 的纯读方法，不调控制器——`GET /invoices`、`GET /invoices/summary`、`ReferralService::overview`、`ProductTypeService::list` 这类「读接口会写库」的方法不进工具面。
9. 接口：`/api/v2/client/ai/bootstrap`、`/ai/messages`、`/ai/conversations*`（薄层控制器 + FormRequest + 命名限流）。
10. `AiConversationService`：上下文装配、会话归属校验（非本人 404）。
11. `shared/runtime/ai/`：`types.ts`、`transport.ts`、`useAiChat.ts`、`context.ts`，并并入 `shared/runtime/index.ts` 导出面（不改 `shared/package.json` exports）。
12. console：`layouts/index.vue` 挂 `AiAssistantLauncher` + `AiChatDrawer`（TDesign），`App.vue` 兜底游客页，`store/modules/ai` 持久化。
13. 前端 e2e：浮窗开关、发消息、渲染回复（fake 后端），`playwright.config.ts` 钉 `locale: 'zh-CN'`。

### P2 确认协议 + 写工具 + 控制台独立页

14. `ai_tool_invocations` 确认协议：令牌签发与校验、参数指纹、TTL、幂等键唯一索引。
15. 写工具：`create_draft_invoice`、`renew_invoice`、`power_action`、`reset_service_password`、`create_ticket`；危险工具：`reinstall_service`、`pay_invoice_by_balance`（`allow_balance_pay=0` 时不注册）。设计文档 5.5 的「严禁注册」清单写进 `AiToolRegistry` 注释：`PaymentService::adjustBalance`、`refundInvoiceToBalance`、`refundOrder`、`ReferralService::processWithdrawal`/`confirmWithdrawalPayment`、`OrderService::updateManualPaymentStatus`、`InvoiceService::markPaidManually`、`ServiceSuspensionService::tryTerminateUpstream`、`ProductAdminService::forceDelete*`、`ProductCategoryService::forceDeleteCategory`、`HandlesAdminUserServices::deleteService`、`AccountService::updateAccount`/`setCashBalance`（自身不校验负数）。
16. 接口：`/ai/messages/stream`（SSE）、`/ai/actions/{id}/confirm|reject`。
17. console 独立页 `src/pages/client/ai/index.vue` + `router/modules/client.ts` children 注册 + 菜单项：会话列表、重命名、删除、引用卡片、工具调用折叠块。
18. Feature 测试：越权、未确认不执行、重复确认幂等、`pay_invoice_by_balance` 关闭时不注册。

### P3 流式 + 官网浮窗 + 管理端浮窗

19. `shared/runtime/ai/sseClient.ts`（原生 fetch + ReadableStream，绕开各端 axios 的信封、超时与重放）接入 `useAiChat`，断流自动回退非流式。
20. www：挂 **`src/App.vue`**（`WebsiteLayout.vue` 无 slot，layout 层不可挂），Element Plus 外观；商品选购/详情页注入 `aiContextBus`。
21. admin：`layouts/index.vue` 挂浮窗，受 `ai.view` 控制。
22. 部署配套：`api.25y.cn` 站点伪静态补 `fastcgi_buffering off;`；PHP `max_execution_time` ≥ 120s；nginx/php-fpm 超时核对。

### P4 管理端治理

23. 管理端页面：`pages/ai/settings.vue`（提供方、模型、密钥空值保留、开关、额度、工具白名单、连通性测试）、`pages/ai/usage.vue`、`pages/ai/conversations.vue`。
24. 权限码 `ai.view` / `ai.manage` 写入 `AdminPermissions`，补 `adminDefaultPermissions` 与菜单。
25. `ai:prune-conversations` 命令接入调度；摘要压缩在会话超过阈值时触发。
26. 文档：更新 `docs/guide/` 面向站长的 AI 配置说明；`docs/develop/api/format.md` 无需改动（沿用统一外层）。

### 上线顺序与回滚

27. 每阶段独立上线：`enabled=0` 时后端休眠、前端入口隐藏，回滚只需关开关。
28. 阶段上线前备份数据库，迁移只新增表，回滚不删表（保留数据，仅停用入口）。
29. 生产部署沿用既有增量流程（备份 → 拉取 → 迁移 → 清缓存 → 构建 → 重启 PHP-FPM → `app:doctor` + 四域名健康检查）。

## 风险与回滚

- 资金风险：`pay_invoice_by_balance` 一期不注册；所有写操作必须确认卡片。回滚条件为出现未确认执行或重复扣款迹象，立即置 `ai.enabled=0`。
- 成本风险：全站日额度 + 成本熔断；触发熔断只降级不报错。
- 隐私风险：默认 `redact_pii=1`；工具只返回必要字段。
- 兼容风险：迁移只新增、JSON 存 `text`、不用 5.7 不支持的语法；若迁移在 5.7 环境失败，停止后续阶段并回滚该迁移（表未写入业务数据）。
- 前端风险：浮窗挂在布局根节点，若与既有弹层层级冲突，先降级为独立页入口。

## 进度

- [x] 设计文档与执行计划落库（2026-10-26）。
- [x] P0 后端骨架：4 张表迁移、4 个模型、`config/ai_gateway.php`、`AiConfig`、能力域契约 + DTO、三个协议驱动（`/v1/chat/completions`、`/v1/responses`、`/v1/messages`）、`AiProviderManager`、`FakeAiProvider`、`ai:doctor`。
- [x] P1 只读对话：16 个工具按 read/write/dangerous 注册（含 `search_products`、`quote_price`、`list_my_services`、`get_balance` 等）、`/api/v2/client/ai/*` 十个端点、`AiConversationService` 上下文装配与归属校验、`shared/runtime/ai/` 共享层。
- [x] P2 确认协议 + 写工具 + 控制台独立页：HMAC 确定性确认令牌、参数指纹、TTL 5 分钟、幂等键、`/ai/actions/{id}/confirm|reject`、`create_draft_invoice`/`renew_invoice`/`power_action`/`reset_service_password`/`create_ticket`/`reinstall_service`。
- [x] P3 流式 + 官网与 admin 浮窗：`/ai/messages/stream`（SSE：meta/delta/tool/pending_action/done/error）、`shared/runtime/ai/sse.ts` 原生 fetch 流式、官网 Element Plus 浮窗与商品上下文注入、管理端浮窗。
- [x] P4 管理端治理：`/api/v2/admin/ai/settings|usage|conversations`、权限码 `ai.view`/`ai.manage`、`ai:prune-conversations` 已接入每日调度、用量页数据已供审计页消费。
- [x] 后端验证：`php artisan test --filter=AiAssistantTest` **14 passed（62 assertions）**。
- [ ] 三端构建与后端全量测试回归（进行中）。
- [ ] 提交、推送与生产增量部署。

## 遗留与偏差（如实记录）

- `pay_invoice_by_balance`（AI 直接扣余额）**本期未实现**：`PaymentService::payByBalance` 还需要一次性 `payment_session_token`，且服务层只有状态机与缓存锁、没有独立幂等键。设计文档把它列为危险工具，注册表里没有它，`allow_balance_pay` 开关也保持默认 0。要放开必须先补"会话令牌 + 幂等键"两条链路。
- 官网浮窗按设计**要求登录**（未登录点浮球引导登录），不做匿名 AI 端点。
- 前端 e2e（浮窗→发消息→确认卡）尚未补，当前用后端 Feature 测试 + 三端构建覆盖；补 e2e 需要 Playwright 与 mock 网关，留待后续。
- 管理端 `GET /ai/usage` 目前由审计页顶部一行消费，未做独立用量页。

## 决策日志

| 日期 | 决策 | 原因 |
| --- | --- | --- |
| 2026-10-26 | 模型接入走核心 `Services/Integrations/Ai/`，不进插件运行时 | AI 供应商无回调、无商户绑定、无签名协议；插件注册成本换不来收益，插件化触发条件已写入设计第 12 节 |
| 2026-10-26 | 工具直接调既有 Service，不 HTTP 自调用 Open API | Open 控制器已验证为薄壳转发同一批 Service，复用服务避免二次实现 |
| 2026-10-26 | 写操作一律走确认协议，余额支付默认关闭 | 模型幻觉与提示注入不能自行扣款；建单不等于扣钱 |
| 2026-10-26 | 浮窗挂布局根节点 + 控制台独立页共享同一 store 与后端会话 | 满足「全局可开、控制台有完整页」且不重复维护两套对话状态 |
| 2026-10-26 | 官网浮窗要求登录，不新增匿名 AI 端点 | 避免匿名消耗 token，会话可审计到用户 |
| 2026-10-26 | 三端共享 `shared/ai/` 无框架状态机，外观各端自实现 | 三端 UI 框架不可混用，但对话状态机必须只有一份 |
| 2026-10-26 | 三种上游协议各一个驱动，`protocol` 设置项切换（`chat_completions` / `responses` / `anthropic_messages`） | 站长手上网关形态不一致，逼他们改代码不如给一个开关 |
| 2026-10-26 | 权限三档：`read_only` / `confirm` / `auto_approve`（beta），站点可设上限并收敛用户请求 | 与 harness 一致的心智模型；`read_only` 下写工具不进模型可见列表，省 token 也省心 |
| 2026-10-26 | 高危工具在任何档位都必须确认 | 自动审批用来省普通写操作的点击，不是用来放开重装与动钱 |
| 2026-10-26 | 确认令牌用确定性 HMAC（调用编号+用户+参数指纹），库内只存 sha256 | 刷新页面后卡片仍可点，参数被改立刻失效，明文不落地 |
| 2026-10-26 | AI 密钥存储键命名 `provider_api_key` | 避免与供应商等既有 `api_key` 设置项共用加密白名单，接口层仍叫 `api_key` |
| 2026-10-26 | 额度数值 0 表示不限 | 站长更容易理解「0 = 不限制」，暂停入口统一用 `enabled=0` |
| 2026-10-26 | 一期不实现 `pay_invoice_by_balance` | 需要 `payment_session_token` 且服务层无幂等键，夜间无人值守时上线动钱工具不值当 |
