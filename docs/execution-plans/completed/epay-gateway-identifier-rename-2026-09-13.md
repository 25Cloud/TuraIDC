---
status: completed
updated: 2026-09-13
owner: backend-platform
---

# 易支付网关标识统一为 epay

## 背景

易支付插件此前存在三套标识并存：插件目录与清单 slug 为 `yi_pay`、清单 key 与业务网关编码为 `yipay`，与其他网关插件「目录名 = slug = key」（如 `ali_pay`/`alipay` 特例由 normalize 承接）的单标识约定不一致，命名与读写两套字符串增加维护成本。本次全量统一为 `epay`。

## 范围与验收

- [x] 插件包目录 `backend/plugins/gateways/yi_pay` → `epay`，命名空间/类名 `YiPay*` → `Epay*`
- [x] 清单 slug/key 与网关编码常量 `PaymentGatewayCode::YIPAY('yipay')` → `EPAY('epay')`
- [x] `normalize()` 保留 `yipay`/`yi_pay` 历史别名归一
- [x] 回调入口归一化：旧回调 URL `/payment/notify/yipay` 继续可用（含回归测试）
- [x] 迁移改写存量 `integration_plugins` 注册行（保 id）与 `payments`/`payment_callbacks`/`gateway_logs` 的 `gateway_key`
- [x] 用户端支付记录筛选项与文档、CHANGELOG 同步
- [x] 回归测试通过（插件/回调/充值/日志边界等受影响用例）

## 实施步骤

1. `git mv` 重命名插件目录与类文件，改写命名空间、类名、config.php（slug/key/notice 键）。
2. 批量替换 `PaymentGatewayCode::YIPAY` → `EPAY`（36 处），重写常量本体与 normalize 别名。
3. 回调控制器与回填服务统一走 `normalize()`；字面量 match 臂补 `'epay'`。
4. 新增迁移 `2026_09_13_000001_rename_yipay_gateway_identifiers_to_epay`。
5. 更新测试类名与断言，保留历史别名回归用例。
6. 前端筛选项、插件 README 表格、CHANGELOG 同步。

## 风险与回滚

- **数据风险**：迁移只按主键与标识列改写，不动金额/状态；幂等可重复执行。down() 逆向改回仅在没有新 epay 数据写入时安全。
- **兼容性**：未执行迁移的部署在升级窗口内历史支付筛选会短暂漏数据，迁移随发版 `php artisan migrate` 一并执行。
- **网关侧**：无需配合改动，旧回调地址由 normalize 兼容。

## 进度

- [x] 已完成并验证。

## 决策日志

| 日期       | 决策                                     | 原因                                                                        |
| ---------- | ---------------------------------------- | --------------------------------------------------------------------------- |
| 2026-09-13 | 全量改运行标识（含落库值），而非仅改包名 | 查询走「归一化值 = 列值」等值匹配，保留 yipay 历史值会让财务筛选/审计漏数据 |
| 2026-09-13 | 回填服务 normalizeGatewayKey 委托常量类  | 消除两处重复的别名映射，避免口径漂移                                        |
