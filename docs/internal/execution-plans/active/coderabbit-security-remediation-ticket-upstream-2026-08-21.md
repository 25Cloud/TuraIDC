---
status: active
updated: 2026-08-22
owner: YeHuaiJing
---

# 工单上游传递 CodeRabbit 安全修复

## 背景

PR `#20`（feat: 增加对 zjmf_finance 的工单上游传递）收到 CodeRabbit 审查，共 13 条行级意见（2 条 Critical、11 条 Major）。本计划在 `fix/coderabbit-ticket-upstream-security` 分支逐条修复，并同步上游 zjmfv376 参考源码（`mf/zjmfv376-main/`）的配套改动。

## 范围与验收

- [x] 附件上传路径遍历（CWE-22）与文件句柄泄漏已修复；TLS 校验统一复用 `idc.hosting_panel_api` 的 `ssl_verify`/`ca_bundle` 口径（保持默认证书校验，不引入 `withoutVerifying()`）。
- [x] `/upload_image` 上传凭证校验：`upload_token_required` 默认 `false`，无凭证上传放行以兼容部署中的上游，带凭证上传强制匹配；设为 `true` 时拒绝无凭证/错误凭证（fail-closed）。
- [x] `/upload_image` 接口启用开关默认关闭；配置工单传递规则前必须先开启，且白名单外上传默认拒绝。
- [x] legacy 回调验签在服务不存在或 token 为空时 fail-closed。
- [x] 供应商切换非 ZJMF 提供商时强制关闭工单投递；投递规则部门取值限制为本地部门枚举。
- [x] 日志中心嵌套事件有数量上限；状态摘要按每工单最新事件统计。
- [x] 工单创建与回复下发稳定 `request_id`，上游配套实现幂等去重。
- [x] `ticket_delivery_rules` 空供应商范围规则受唯一约束（NULL 归一化）。
- [x] 投递日志迁移不再“条件创建 + 无条件删除”。
- [x] 工单详情 `upstream_delivery` 嵌套字段白名单测试，拒绝 `callback_token`。
- [x] 管理端规则编辑不再因首屏分页丢失已保存产品绑定。
- [x] 回调 token（`downstream_token`、`ticket_callback_token`）禁止写入 `services.provision_data`，统一由 `PluginBindingResolver::sanitizeServiceProvisionData()` 在合并/写回前剔除；token 仅保留在加密的 `service_connection_snapshots.secret_json`。
- [x] 孤儿上传清理不再逐文件执行前置通配 `LIKE`，改为初筛一次批量引用查询 + 锁内一次批量引用查询（固定次数）。
- [x] 上传限流测试在 `tearDown` 中清理 `RateLimiter` 计数，保证重复运行幂等。
- [x] ZJMF 附件上传新增 TLS 选项与失败路径单测；受信代理/XFF 契约写入运维文档。
- [ ] 后端全量测试与前端构建收尾。
- [ ] 文档校验（`pnpm run docs:check`）。

## 实施步骤

1. 逐条核对 CodeRabbit 行级评论与对应代码。
2. 修复 `ZjmfFinanceTransport::uploadTicketAttachment`（路径遍历、句柄、SSL）。
3. 新增上传凭证中间件并应用到 `/upload_image`，同步修改上游 `pushTicketReply` 上传调用。
4. legacy 回调验签 fail-closed；供应商与规则请求校验收紧。
5. 日志中心嵌套日志上限与摘要按最新事件统计。
6. 工单创建/回复下发 `request_id`，上游 `createTicket`/`replyTicket` 幂等去重并配套 SQL。
7. 迁移归一化唯一约束与回滚语义修正；补测试断言与前端保护。
8. 运行后端全量测试、管理端构建与文档校验。

## 上游 zjmfv376 配套改动（参考，不强制部署）

以下改动在 `mf/zjmfv376-main/` 参考源码中完成，不在本仓库提交范围，**不要求**部署到上游系统：

- `app/common.php`：`curlUpload()` 增加 `$extra` 参数，允许附带额外表单字段。
- `app/zjmf.php`：`pushTicketReply()` 上传附件时携带 `id`（downstream_id）与 `token`（downstream_token），供下游 `/upload_image` 校验。
- `app/openapi/controller/TicketController.php`：`createTicket()` 与 `replyTicket()` 支持 `request_id` 幂等——命中已存在 `request_id` 时直接返回既有结果，不重复创建/回复。
- `data/upgrade_ticket_request_id.sql`：为 `ticket`、`ticket_reply` 增加 `request_id` 列与唯一索引（执行前先备份并清理潜在重复）。

由于部署中的上游系统无法同步配套修改，本仓库 `/upload_image` 凭证校验默认放行无凭证上传（`upload_token_required=false`），以保证回调附件可用；带凭证的上传仍强制匹配。`/upload_image` 接口本身默认关闭（`upload_image_enabled=false`），必须在管理端开启后才能配置工单传递规则；开启后白名单外上传默认拒绝（`block_non_whitelisted=true`），生产环境应先填写可信来源 IP/CIDR。磁盘占用通过未使用上传文件清理缓解：超过保留期（`upload_unused_retention_minutes`，默认 5 分钟）仍未用于回复工单的文件由每分钟调度任务 `tickets:cleanup-unused-upstream-uploads` 删除。`request_id` 幂等在未部署上游前由上游忽略（行为与现状一致）。

## 风险与回滚

- 上游系统不可改，`/upload_image` 凭证校验默认放行无凭证上传（安全修复降级为缓解）：匿名上传依赖未使用文件每分钟清理与自定义限流中间件（白名单 IP/CIDR 不限速、非白名单按速率、可开启拒绝白名单外上传）控制，未来上游可携带凭证时应恢复 `upload_token_required=true`（fail-closed）。
- 两个迁移（150000/160000）为未发布 PR 的追加迁移，直接修正文件；已有漂移表的环境需先走独立修复流程（160000 会在表已存在时明确报错）。
- 日志摘要语义从“历史事件求和”改为“每工单最新事件”，前端无需改动（字段与文案不变）。
- 规则编辑页仅移除首屏自动过滤，供应商切换仍清空产品选择。

## 进度

- [x] 修复附件上传路径遍历、句柄泄漏（Critical）。
- [x] 附件上传 TLS 校验统一复用 `ssl_verify`/`ca_bundle` 口径，并新增上传单测（早期 `withoutVerifying()` 已在 73559c7 移除，保留默认证书校验）。
- [x] `/upload_image` 凭证中间件与本仓库路由接入，上游配套调用修改。
- [x] legacy 回调验签 fail-closed。
- [x] 供应商/规则请求校验收紧。
- [x] 日志中心嵌套上限与摘要语义修正。
- [x] `request_id` 下发与上游幂等配套（含 SQL）。
- [x] 迁移归一化唯一约束与回滚语义修正。
- [x] 测试断言与前端分页保护。
- [x] 上传未使用文件 5 分钟自动删除（每分钟清理任务）。
- [x] 上传限流白名单 IP 与速率管理端配置（含 API 与页面）。
- [x] 回调 token 禁止写入 `services.provision_data`：新增 `sanitizeServiceProvisionData()` 并在全部 `serviceProvisionData()` 合并路径与写回点统一剔除，token 只保留在加密 secret 快照；补回归测试。
- [x] 孤儿上传清理改为批量引用查询（初筛 + 锁内各一次），移除逐文件前置通配 `LIKE`；补查询次数与锁竞争测试。
- [x] 上传限流测试 `tearDown` 清理 `RateLimiter` 计数，测试幂等。
- [x] 受信代理/XFF 契约写入部署与运维文档（含容器端口仅本机绑定、单层代理重置 XFF）。
- [ ] 后端全量测试与前端构建收尾。
- [ ] 文档校验（`pnpm run docs:check`）。

## 决策日志

| 日期       | 决策                                                                        | 原因                                                                                                                                                                                                                                                            |
| ---------- | --------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 2026-08-21 | `/upload_image` 采用 per-service legacy token 校验                          | 上游 `pushTicketReply` 已持有 `downstream_id`/`downstream_token`，与回调验签同源，无需引入新的共享密钥；带凭证上传按该 token 强制匹配，`upload_token_required=true` 时凭证缺失/错误即拒绝（fail-closed，默认 `false` 放行无凭证上传以兼容部署中的上游）。       |
| 2026-08-21 | 幂等采用 `request_id` 下发 + 上游幂等去重                                   | 上游不支持幂等参数且无可按业务键回查的接口；`request_id` 为稳定业务键（绑定/回复 ID），上游命中后返回既有结果，重试不会重复创建。                                                                                                                               |
| 2026-08-21 | 日志嵌套事件上限 20 条/工单                                                 | 列表接口返回最新事件与总数（`log_count`），展开详情走分页接口；避免历史长工单的大查询与大响应。                                                                                                                                                                 |
| 2026-08-21 | 迁移直接修正未发布文件                                                      | 150000/160000 均为 PR #20 未合并的追加迁移，直接修正文件符合“迁移只新增”与 fail-fast 原则；漂移表环境显式报错而非静默跳过。                                                                                                                                     |
| 2026-08-21 | 上传凭证校验默认放行（`upload_token_required=false`）+ 每分钟未引用文件清理 | 部署上游系统无法同步配套修改，fail-closed 校验会拒绝回调附件上传（图片无法显示）；默认放行无凭证上传恢复功能，带凭证上传仍强制匹配；上传成功超过保留期（默认 5 分钟）仍未用于回复工单的文件由每分钟清理任务删除（`tickets:cleanup-unused-upstream-uploads`）。  |
| 2026-08-21 | 上传限流白名单与速率配置化（管理端「工单传递设置」页）                      | `/upload_image` 使用自定义限流中间件：白名单 IP/CIDR 不限速，非白名单按配置速率限制（0 为不限速）；配置存 `settings` 表 `ticket_upstream` 组，避免匿名上传被直接爆破。                                                                                          |
| 2026-08-22 | 上传 TLS 口径统一复用 `ssl_verify`/`ca_bundle`，不关闭证书校验              | CodeRabbit 曾建议 `withoutVerifying()`，但关闭 CA/证书校验会引入中间人风险；附件上传改为复用 `HostingPanelApiTransport::httpClientOptions()`（verify 布尔或有效 CA bundle、禁止重定向），行为与主机面板直传一致。                                               |
| 2026-08-22 | 回调 token 只允许进入加密 secret 快照，禁止写回 `services.provision_data`   | `serviceProvisionProjection(includeSecrets: true)` 的解密结果会被各 Service 合并并写回 legacy JSON，token 明文入库违背“凭据不入库”约束；新增统一 `sanitizeServiceProvisionData()` 在合并/写回前剔除，加密 `secret_json` 与 writer 的受控 runtime 流程不受影响。 |
| 2026-08-22 | 孤儿清理改为批量引用查询                                                    | 原实现锁内对每个待删文件执行一次前置通配 `LIKE`（全表扫描）；改为初筛一次批量查询 + 成功持锁集合一次批量查询，锁语义与 `receiveReply()` 排序一致，删除前仍以锁内批量结果为唯一依据。                                                                            |
