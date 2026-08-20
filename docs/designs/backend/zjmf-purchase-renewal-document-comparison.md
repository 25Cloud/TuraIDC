---
status: needs-review
updated: 2026-08-11
owner: backend-platform
---

# Zjmf 与 TuraIDC 新购/续费单据创建对比

- 文档性质：对比智简魔方 ZJMF-CBAP 10.4.6 与 turaidc 的新购、续费流程，重点核对“账单 + 订单是否同步创建”，并记录 turaidc 上游对接版本的行为差异。
- 参照真源：`ZJMF-CBAP-10.4.6/10.4.6/app/home/model/CartModel.php`、`app/common/model/OrderModel.php`、`OrderTmpModel.php`；`zjmf-manger-decoded-main/app/home/controller/CartController.php`、`app/common/logic/Renew.php`、`app/openapi/controller/HostController.php`、`data/route/home.php`。
- turaidc 现状依据：`backend/app/Services/Finance/CheckoutService.php`、`backend/app/Services/Provisioning/ServiceRenewService.php`、`backend/app/Services/Finance/PaymentService.php`、`backend/app/Services/Order/PaidOrderBusinessFlowDispatcher.php`、`backend/plugins/servers/zjmf_finance/lib/ZjmfProvisionService.php`、`ZjmfRenewService.php`。
- 当前状态：静态审查完成；未变更任何代码与数据。

## 概述

三个系统的单据模型不同：

| 系统                                | 主财务单据                               | 订单与账单关系                                    | 账单创建时机                                 |
| ----------------------------------- | ---------------------------------------- | ------------------------------------------------- | -------------------------------------------- |
| ZJMF-CBAP 10.4.6                    | `order` + `order_item`（订单中心）       | 订单为主，`shd_invoices` 由财务插件补充           | 支付时由财务插件创建，核心包内无账单创建代码 |
| zjmf-manger（turaidc 上游对接架构） | `invoices` + `invoice_items`（账单中心） | `orders.invoiceid` 关联账单；续费只建账单不建订单 | 新购结算与续费提交时同步创建                 |
| turaidc                             | `invoices`（账单中心，财务入口）         | `invoices.order_id` 关联订单；新购/续费均建 Order | 下单时与订单在同一事务同步创建               |

turaidc 对接的 ZJMF 上游接口行为与 `zjmf-manger` 一致（`zjmf_api_login`、`/cart/settle`、`/apply_credit`、`/check_order`、`/host/renew` 等路由均存在于 `zjmf-manger-decoded-main/data/route/home.php`，而 CBAP 10.4.6 的 `route/api.php` 仅暴露商品接口）。本文同时列出两者作为对照。

## 1. 新购流程对比

### 1.1 ZJMF-CBAP 10.4.6：`CartModel::settle()`

同一事务内依次创建：

1. `order`（`type=new`，金额为 0 时直接 `Paid`）
2. `host`（产品实例，`status=Unpaid`，关联 `order_id`）
3. `order_item`（订单子项，`type=host`）

金额为 0 时直接调用 `processPaidOrder()` 开通。返回 `order_id`、`amount`、`host_ids`，**不返回账单 ID**；账单由财务插件在支付时创建。

### 1.2 zjmf-manger：`CartController::settle()`

同一事务内依次创建：

1. `invoices`（`type=product`，`status=Unpaid`）→ 获得 `invoiceid`
2. `orders`（`status=Pending`，`orders.invoiceid` 关联账单）
3. `host`（产品实例，`orderid` 关联订单）
4. `invoice_items`（账单子项：`setup`/`host`/`discount`/`promo`）

金额为 0 时账单直接标记 `Paid` 并 `processPaidInvoice()`。返回 `invoiceid`（下游场景附 `hostid`）。

### 1.3 turaidc：`CheckoutService::create()`

同一事务内依次创建：

1. `Invoice`（`type=new_purchase`，`status=UNPAID`，含报价、优惠券、配置快照）
2. shadow `Order`（`type=new`，仅内部履约使用，不向用户展示）
3. 扣减商品库存

支付完成后 `PaymentService::handlePaidInvoice()` → `PaidOrderBusinessFlowDispatcher`（`new`/`renew` 同步履约）→ `ZjmfProvisionService::provisionOrder()` 在上游同步创建：

登录上游 → 清空购物车 → 读取商品配置 → 加入购物车 → `POST /cart/settle`（上游创建订单 + 账单，解析 `invoiceid`）→ `POST /apply_credit` 用供应商余额支付 → 回查 `host` 详情并落库。

### 1.4 小结

| 步骤            | CBAP 10.4.6        | zjmf-manger | turaidc                 |
| --------------- | ------------------ | ----------- | ----------------------- |
| 创建账单        | 支付时（财务插件） | 结算时同步  | 下单时同步              |
| 创建订单        | 结算时同步         | 结算时同步  | 下单时同步              |
| 账单/订单同事务 | 否（跨插件）       | 是          | 是                      |
| 上游同步创建    | -                  | -           | 结算时同步创建订单+账单 |

## 2. 续费流程对比

### 2.1 ZJMF-CBAP 10.4.6

`OrderModel::createOrder(type=renew)` 分发到续费插件 `idcsmart_renew`（插件代码不在核心包），插件内部创建续费订单与账单并返回 `invoiceid`。

### 2.2 zjmf-manger：`Renew::renew()`（`/host/renew`）

1. 校验产品状态（`Active`/`Suspended`）与周期
2. 计算续费金额（支持换周期、比例续费）
3. **只创建 `invoices`（`type=renew`）+ `invoice_items` + `renew_cycle`，不创建订单**
4. 已有未支付续费账单时：删除旧账单重建；存在部分支付时差额退回客户余额
5. 金额为 0 时直接延长到期时间（不建账单）
6. 返回 `invoiceid` + `payment`

### 2.3 turaidc：`ServiceRenewService`

- `createRenewInvoiceForUser()` / `createRenewOrderForUser()`：同一事务内创建 `Invoice`（`type=renew`）与 shadow `Order`（`type=renew`），双向绑定（`invoices.order_id`）。
- 自动续费（`BillingAutomationService::createRenewOrders()`）同样走 `createRenewInvoiceForUser()`。
- 重复创建保护：已存在同周期未支付账单时复用；金额或优惠变化时先取消旧账单再重建（`renew_invoice_replaced`）。

支付完成后同步履约 `ZjmfRenewService::renewServiceInvoice()`：

`POST /host/renew`（上游创建续费账单，解析 `invoiceid`）→ `POST /pay?action=billing&pay=true` 用供应商余额支付 → `POST /check_order` 确认支付完成 → 回写 `provision_data`（`upstream_invoice_id`、`renew_invoice_id`、`last_renew_*`）。支付失败时支持 `recoverRenewInvoiceWithContext()` 恢复。

### 2.4 小结

| 步骤           | CBAP 10.4.6 | zjmf-manger           | turaidc                              |
| -------------- | ----------- | --------------------- | ------------------------------------ |
| 创建续费订单   | 插件创建    | 不创建                | 同步创建                             |
| 创建续费账单   | 插件创建    | 同步创建              | 同步创建                             |
| 未支付账单重建 | -           | 删除重建 + 差额退余额 | 取消重建（`renew_invoice_replaced`） |
| 上游同步创建   | -           | -                     | 提交续费时同步创建账单并余额支付     |

## 3. “同步创建账单和订单”验证结论

逐一核对 turaidc 的所有新购/续费入口，账单与订单均在提交时同步创建：

- [x] 新购：`CheckoutService::create()` 同事务创建 `Invoice` + `Order`。
- [x] 手动续费：`createRenewInvoiceForUser()` / `createRenewOrderForUser()` 同事务创建 `Invoice` + `Order` 并双向绑定。
- [x] 自动续费：`BillingAutomationService` → `createRenewInvoiceForUser()`。
- [x] 支付后同步履约：`PaidOrderBusinessFlowDispatcher::SYNCHRONOUS_FULFILLMENT_TYPES = ['new', 'renew']`，支付回调内同步调用上游创建订单/账单并余额支付；同步失败回退 `provision` 队列。
- [x] 上游新购：`/cart/settle` 同步创建订单 + 账单，`/apply_credit` 余额支付。
- [x] 上游续费：`/host/renew` 同步创建续费账单，`/pay` 余额支付，`/check_order` 确认。
- [x] 支付路径全覆盖：支付宝回调、主动查询、余额支付、管理员标记已支付均走 `handlePaidInvoice()` → 同步履约。

结论：turaidc 的新购与续费已实现“账单 + 订单同步创建”（本地同事务），且支付后在上游同步创建订单/账单并完成供应商余额支付。无需新增代码。

## 4. 差异与注意事项

| 差异                         | 说明                                                                                   | 处理                                                                                                                        |
| ---------------------------- | -------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------- |
| CBAP 核心包新购不创建账单    | 账单由财务插件在支付时创建，与 turaidc 的“账单为财务入口”不同                          | turaidc 直接创建 `Invoice`，符合[财务单据生成规则](../../references/backend/financial-document-generation.md)；上游无需改动 |
| 上游续费只建账单不建订单     | zjmf-manger 的 `/host/renew` 不创建 `orders`                                           | turaidc 本地独立创建 `Order(type=renew)`，不依赖上游订单                                                                    |
| 上游结算必须返回 `invoiceid` | turaidc 依赖财务插件版本返回账单 ID，否则拒绝继续（“上游已接受结算，但未返回账单 ID”） | 已明确对接财务版本；对接纯 CBAP 核心包时无法余额支付上游账单                                                                |
| 0 元单处理                   | CBAP 允许 0 元订单直接开通；turaidc `CheckoutService` 拒绝 `amount <= 0`               | 0 元场景在 turaidc 走人工开通，不属于新购单据流程                                                                           |
| 上游账单重建语义             | zjmf-manger 删除旧账单重建并退差额；turaidc 取消旧账单重建                             | 行为对齐，均保证同一产品同一周期仅一张有效待支付续费账单                                                                    |
