---
status: completed
updated: 2026-10-27
owner: Codex
---

# 上游开通去重与管理端一键开通

- 触发：对接魔方财务的客户反馈「一个新订单没成功，却自动向上游发出了 4 笔相同订单」，
  以及「手动开通和上游绑定功能重合，还要手填上游实例 ID」

## 背景

支付后履约链路是「同步跑一次 + `provision` 队列 `$tries=3`」。上游开通失败被当成普通异常上抛后，
队列会把同一次购买重新走一遍「清空购物车 → 加购 → 结算」，上游因此出现 4 笔一模一样的订单
（1 次同步 + 3 次重试）。真正的漏洞点在**结算成功之后**：`/cart/settle` 成功即代表上游已建单，
只要本地解析不出账单号或请求在返回途中断掉，`provision_data` 里就没有 `upstream_invoice_id`，
下一轮重试的闸门读到 0 便放行。

同一时期客户截图里还能看到两条服务指向同一个上游实例号——那是并发履约给同一个订单建了两条服务。

管理端的「手动开通」要求填写上游实例 ID，而后端根本不使用该字段（旧接口只接收 `remark`），
行为上完全被「上游绑定」覆盖，与「点一下自动下单并回填实例 ID」的预期相反。

## 目标

1. 一次购买最多在上游产生一笔订单；失败必须转人工，不允许自动重试。
2. 管理端一个按钮跑完「加购 → 结算 → 供应商余额支付 → 回填上游实例 ID」，不再手填实例 ID。
3. 「上游绑定」保留为人工兜底路径（机器已存在、或上游手工建单后补录）。

## 实施步骤

1. 新增 `App\Exceptions\ProvisionRetryNotAllowedException`（继承 `BusinessException`）：
   语义化「不允许自动重试」，管理端接口仍能正常渲染成业务错误。
2. `ProvisionService`：闸门增加 `upstream_provision_uncertain_at` 判定；新增
   `clearUpstreamProvisionUncertainty()` 作为唯一清除入口；`ensureLocalService` 按订单加锁并按
   `order_id + product_id` 复查；开通成功后清除账单 `requires_refund`；
   `adminInitiatedProvision` 已有上游实例时直接拒绝。
3. `ZjmfProvisionService`：结算前按主机名回查并复用上游实例（仅在上游有痕迹时执行，查不动则阻断）；
   结算成功却没有账单号时落未确认标记并抛不可重试异常；复用前过滤已被其它本地服务占用的实例。
4. `PaymentService::provisionPaidOrder` 上游开通失败改为抛不可重试异常；
   `ProcessPaidOrderFulfillmentJob::handle` 捕获后记日志 + 打 `requires_refund`，不重抛。
5. 接口：新增 `POST /api/v2/admin/users/{user}/services/{service}/upstream-provision`，
   删除已被取代的 `PUT .../manual-provision`；管理端三个页面把「手动开通」换成「一键上游开通」，
   弹窗只保留说明、强制确认与备注。
6. 同批交付三端 AI 助手的机器人头图标与自适应重做。

## 风险与回滚

- 逐单串行锁依赖缓存锁；缓存不可用时 `processPaidOrderFulfillmentById` 已有降级分支，不会因此拒绝开通。
- 未确认标记只能由管理员「一键上游开通」勾选强制确认后清除，不做自动清理；如需回滚仅撤前端按钮即可，
  后端闸门会继续保持安全侧。
- 已绑定上游实例的服务一律拒绝再次下单，回滚前不要指望旧「手动开通」路径。

## 进度

- [x] 定位重复下单根因（结算后无 checkpoint + 队列重试）
- [x] 不可重试异常与闸门落地，ZJMF 插件补主机名回查与未确认标记
- [x] 单订单单服务（加锁 + 复查）与 requires_refund 清理
- [x] 管理端一键上游开通接口与三处 UI 改造，旧接口下线
- [x] 后端回归（`Zjmf*` 181 + `Provision*` 等 26）、三端构建、API 清单重刷
- [x] 部署到二五云4 并绕过 CDN 直连源站验收

## 决策日志

- 2026-10-27：不采用「把队列 `$tries` 改成 1」的省事做法。重试本身对网络抖动仍然有价值，
  真正的问题是失败语义没区分——用专用异常终止自动重试，既保住瞬时故障的重试能力，
  又保证「已经可能下单」的失败不再重放。
- 2026-10-27：未确认标记与残留账单号只由管理员强制确认后清除，不做「重试 N 次自动放行」，
  因为放行一次就可能多买一台机器。
- 2026-10-27：删除 `PUT .../manual-provision` 而不是保留两套入口。它的入参 `upstream_host_id`
  后端从未使用，留在界面上只会继续误导管理员。
