---
status: active
updated: 2026-08-21
owner: YeHuaiJing
---

# 工单上游传递 CodeRabbit 安全修复

## 背景

PR [#20](https://github.com/25Cloud/TuraIDC/pull/20)（feat: 增加对 zjmf_finance 的工单上游传递）收到 CodeRabbit 审查，共 13 条行级意见（2 条 Critical、11 条 Major）。本计划在 `fix/coderabbit-ticket-upstream-security` 分支逐条修复，并同步上游 zjmfv376 参考源码（`mf/zjmfv376-main/`）的配套改动。

## 范围与验收

- [ ] 附件上传路径遍历（CWE-22）与文件句柄泄漏、SSL 校验缺口已修复。
- [ ] `/upload_image` 匿名上传已加轻量凭证校验，无凭证/错误凭证被拒绝。
- [ ] legacy 回调验签在服务不存在或 token 为空时 fail-closed。
- [ ] 供应商切换非 ZJMF 提供商时强制关闭工单投递；投递规则部门取值限制为本地部门枚举。
- [ ] 日志中心嵌套事件有数量上限；状态摘要按每工单最新事件统计。
- [ ] 工单创建与回复下发稳定 `request_id`，上游配套实现幂等去重。
- [ ] `ticket_delivery_rules` 空供应商范围规则受唯一约束（NULL 归一化）。
- [ ] 投递日志迁移不再“条件创建 + 无条件删除”。
- [ ] 工单详情 `upstream_delivery` 嵌套字段白名单测试，拒绝 `callback_token`。
- [ ] 管理端规则编辑不再因首屏分页丢失已保存产品绑定。
- [ ] 后端定向/全量测试、管理端构建通过。

## 实施步骤

1. 逐条核对 CodeRabbit 行级评论与对应代码。
2. 修复 `ZjmfFinanceTransport::uploadTicketAttachment`（路径遍历、句柄、SSL）。
3. 新增上传凭证中间件并应用到 `/upload_image`，同步修改上游 `pushTicketReply` 上传调用。
4. legacy 回调验签 fail-closed；供应商与规则请求校验收紧。
5. 日志中心嵌套日志上限与摘要按最新事件统计。
6. 工单创建/回复下发 `request_id`，上游 `createTicket`/`replyTicket` 幂等去重并配套 SQL。
7. 迁移归一化唯一约束与回滚语义修正；补测试断言与前端保护。
8. 运行后端全量测试、管理端构建与文档校验。

## 上游 zjmfv376 配套改动（需同步部署）

以下改动在 `mf/zjmfv376-main/` 参考源码中完成，不在本仓库提交范围，上线时需同步到上游系统：

- `app/common.php`：`curlUpload()` 增加 `$extra` 参数，允许附带额外表单字段。
- `app/zjmf.php`：`pushTicketReply()` 上传附件时携带 `id`（downstream_id）与 `token`（downstream_token），供下游 `/upload_image` 校验。
- `app/openapi/controller/TicketController.php`：`createTicket()` 与 `replyTicket()` 支持 `request_id` 幂等——命中已存在 `request_id` 时直接返回既有结果，不重复创建/回复。
- `data/upgrade_ticket_request_id.sql`：为 `ticket`、`ticket_reply` 增加 `request_id` 列与唯一索引（执行前先备份并清理潜在重复）。

若上游暂不部署配套改动：`/upload_image` 会上传失败（回调附件为空），`request_id` 参数被上游忽略（行为与现状一致）。部署顺序建议上游先行。

## 风险与回滚

- 上传凭证校验为 fail-closed：上游未同步部署前，工单回调附件无法上传；以“上游先行部署”为上线顺序，必要时可回退本分支路由中间件。
- 两个迁移（150000/160000）为未发布 PR 的追加迁移，直接修正文件；已有漂移表的环境需先走独立修复流程（160000 会在表已存在时明确报错）。
- 日志摘要语义从“历史事件求和”改为“每工单最新事件”，前端无需改动（字段与文案不变）。
- 规则编辑页仅移除首屏自动过滤，供应商切换仍清空产品选择。

## 进度

- [x] 修复附件上传路径遍历、句柄泄漏与 SSL 校验（Critical）。
- [x] `/upload_image` 凭证中间件与本仓库路由接入，上游配套调用修改。
- [x] legacy 回调验签 fail-closed。
- [x] 供应商/规则请求校验收紧。
- [x] 日志中心嵌套上限与摘要语义修正。
- [x] `request_id` 下发与上游幂等配套（含 SQL）。
- [x] 迁移归一化唯一约束与回滚语义修正。
- [x] 测试断言与前端分页保护。
- [ ] 后端全量测试与前端构建收尾。
- [ ] 文档校验（`npm run docs:check`）。

## 决策日志

| 日期       | 决策                                               | 原因                                                                                                                                    |
| ---------- | -------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------- |
| 2026-08-21 | `/upload_image` 采用 per-service legacy token 校验 | 上游 `pushTicketReply` 已持有 `downstream_id`/`downstream_token`，与回调验签同源，无需引入新的共享密钥；凭证缺失即拒绝（fail-closed）。 |
| 2026-08-21 | 幂等采用 `request_id` 下发 + 上游幂等去重          | 上游不支持幂等参数且无可按业务键回查的接口；`request_id` 为稳定业务键（绑定/回复 ID），上游命中后返回既有结果，重试不会重复创建。       |
| 2026-08-21 | 日志嵌套事件上限 20 条/工单                        | 列表接口返回最新事件与总数（`log_count`），展开详情走分页接口；避免历史长工单的大查询与大响应。                                         |
| 2026-08-21 | 迁移直接修正未发布文件                             | 150000/160000 均为 PR #20 未合并的追加迁移，直接修正文件符合“迁移只新增”与 fail-fast 原则；漂移表环境显式报错而非静默跳过。             |
