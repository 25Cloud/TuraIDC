# 支付渠道接入

用户付钱进来的两个第三方通道是支付宝当面付与易支付，都以「集成插件」形态安装启用；余额支付是系统内置能力，不需要插件。本文说明入口、字段、回调地址要求与「付了钱没到账」的排查路径。

## 1. 后台入口

| 页面           | 路径                                    | 权限                                  |
| -------------- | --------------------------------------- | ------------------------------------- |
| 支付渠道       | 系统设置 → 集成插件 → 支付渠道          | 列表 `integration_plugin.view`；安装/改配置/启停 `integration_plugin.manage`；测试下单 `integration_plugin.test` |
| 订单管理       | 财务管理 → 订单管理 → 订单              | 订单相关权限                          |
| 支付网关日志   | 日志中心 → 日志管理 → 网关日志          | `log.list`                            |

插件列表页会扫描 `backend/plugins/gateways/` 下的目录，本地新增插件文件后点「扫描插件」才会出现，再点「安装」→「启用」。测试类接口（含「创建 0.01 元测试订单」）限流 10 次/分钟。

## 2. 可用渠道与系统编码

系统内部按网关编码记账（常量定义在 `PaymentGatewayCode`）：

| 编码      | 展示名     | 说明                                                     |
| --------- | ---------- | -------------------------------------------------------- |
| `balance` | 余额支付   | 内置，用账户余额支付，不涉及第三方                      |
| `alipay`  | 支付宝支付 | 需要 `ali_pay` 插件（slug 匹配 `alipay` / `ali_pay`）    |
| `epay`    | 易支付     | 需要 `epay` 插件；历史编码 `yipay`、`yi_pay` 会归一到这里 |
| `wechat`  | 微信支付   | 系统有编码与展示名，但 `backend/plugins/gateways/` 下没有对应插件目录 |
| `stripe`  | Stripe 支付 | 同上，没有对应插件目录                                   |
| `manual` / `free` | 管理员手动 / 免费开通 | 内部记账用，不是第三方通道          |

`wechat` 与 `stripe` 目前只存在于常量表里，`backend/plugins/gateways/` 下没有对应插件目录（未在代码中确认是否有计划补齐），因此它们不会出现在前台。

网关与插件的对应关系：前台「可用支付方式」取自**已启用插件**注册进运行时的网关，逐个调用 `isEnabled()`，只有为真才展示；而回调路由反查插件时按状态优先、id 升序取第一条，不强制要求启用。所以停用插件后前台不再展示该渠道，但已下发给渠道的旧回调地址仍能走到同一网关，不会 404。

## 3. 支付宝当面付（`ali_pay`）

字段（`backend/plugins/gateways/ali_pay/config.php`）：

| 字段                    | 默认值 | 留空后果                                             |
| ----------------------- | ------ | ---------------------------------------------------- |
| `alipay_enabled` 启用   | 开     | 关闭后该渠道不作为可用支付方式                       |
| `app_id` App ID         | 空     | `isEnabled()` 为假，渠道不上架                       |
| `private_key` 应用私钥  | 空     | 同上；必须填**应用私钥**，不是支付宝公钥             |
| `alipay_public_key` 支付宝公钥 | 空 | `isEnabled()` 只看 app_id 与私钥，但回调验签必须用公钥，缺了会一直验签失败 |

`private_key` 与 `alipay_public_key` 属于敏感键，落库前用 `APP_KEY` 加密（`enc:` 前缀），后台只回显 `******`，留空保存表示保留旧值而不是清空。要用「明文查看」需要 `settings.secret_reveal` 权限，且每次调用限流 10 次/分钟并写操作日志。

密钥本身也可以走环境变量 `ALIPAY_APP_ID`、`ALIPAY_PRIVATE_KEY`、`ALIPAY_PUBLIC_KEY`（`config/alipay.php`），插件配置优先于环境变量。生产上建议二者只留一处，避免排查时看错来源。

**回调地址**（决定钱能不能到账）：

- 路由：`POST /api/v2/client/payment/alipay/notify`，另有通用入口 `/api/v2/client/payment/notify/{gateway}`（GET/POST 都收）。旧编码 `yipay` 的历史回调也会被归一到当前编码，不用改渠道侧配置。
- 口径优先级：`ALIPAY_NOTIFY_URL` → 后端基址 `APP_URL` + `/api/v2/client/payment/alipay/notify` → `FRONTEND_URL` 同路径。
- 支付时只在该地址通过校验的情况下才把它带给支付宝：协议必须是 http/https，主机不能是 `localhost`、`127.0.0.1`、`::1`，也不能是内网或保留 IP。不通过时**不会**带 `notify_url` 参数——支付宝侧显示成功、本地永不入账，这是最隐蔽的一类故障。
- 回退到 `FRONTEND_URL` 是不可靠的兜底：四个前端站点不反代 API（见[部署与调度](../deploy/scheduling.md)），发到官网或控制台域名会静默丢回调。生产请显式配置 `ALIPAY_NOTIFY_URL` 指向 API 域名。

回调到达后先由 `verify.alipay.callback` 中间件验签，再进业务处理；金额与本地订单不一致会被拒绝。两条回调路由都限流 60 次/分钟/IP，限制放在验签之前——频率异常时网关收到的是 429，回调会被判定为失败重试。网关地址默认 `https://openapi.alipay.com/gateway.do`，沙箱是 `https://openapi-sandbox.dl.alipaydev.com/gateway.do`，用 `ALIPAY_GATEWAY` 切换。

## 4. 易支付（`epay`）

字段（`backend/plugins/gateways/epay/config.php`）：

| 字段                        | 默认值 | 留空后果                                             |
| --------------------------- | ------ | ---------------------------------------------------- |
| `enabled` 启用              | 开     | 关闭后该渠道不作为可用支付方式                       |
| `merchant_id` 商户 ID       | 空     | `isEnabled()` 为假；回调验签也会因商户号为空一律拒绝 |
| `api_endpoint` 接口地址     | 空     | 只接受 http/https 且带主机名的地址，不合法即视为未配置 |
| `sign_type` 签名方式        | `MD5`  | 非法值静默回退 `MD5`                                 |
| `merchant_key` MD5 商户密钥 | 空     | MD5 模式下必填；查询/退款接口若服务商仍要求 KEY 也要填 |
| `merchant_private_key` RSA 商户私钥 | 空 | RSA 模式下必填，仅在选择 RSA 时显示                |
| `platform_public_key` RSA 平台公钥  | 空 | RSA 模式下必填，用于验签回调，仅在选择 RSA 时显示  |
| `payment_types` 支付方式    | 仅勾选支付宝 | 未勾选时该渠道不展示给用户；可同时勾选支付宝与微信 |

`api_endpoint` 可以直接填平台根地址；如果填了 `mapi.php` 这类具体接口地址，系统会按同目录解析出其他接口（`mapi.php`、`api.php` 等）。

**上架条件比较严**：`isEnabled()` 要求启用、商户 ID 非空、签名材料齐全、接口地址可解析、`notify_url` 与 `return_url` 都非空、`payment_types` 非空，任何一条不满足前台都不显示这个渠道。

**回调地址**：固定取 `APP_URL` 拼 `/api/v2/client/payment/notify/epay`，`return_url` 取「用户控制台地址 `CLIENT_CONSOLE_URL` → 官网 `FRONTEND_URL` → `APP_URL`」回退到 `/client/recharge`。这两个地址都**不能在后台改**，只能通过环境变量调整——换域名时必须同步改 `.env` 并重启 PHP-FPM。

签名方式是硬约束：MD5 模式下回调必须带 `sign` 且与商户密钥算出的签名一致；RSA 模式下平台公钥为空则直接验签失败。空密钥不会降级成「不校验」。

## 5. 验证

先做只读检测，再走真实小额：

1. 插件详情页点「检测」确认插件清单与配置齐全、入口类能实例化（只读，不请求支付宝）。
2. 支付插件详情页的「创建 0.01 元测试订单」：真实创建一笔 0.01 元测试订单，**测试结果只返回不保存**。能拿到支付链接说明 App ID、私钥、签名链路都通了；拿不到就先看报错里的原因。
3. 用一笔真实小额充值走完整链路：用户下单 → 扫码支付 → 回调入账。
4. 到「网关日志」与订单详情确认回调记录，`out_trade_no`、`trade_no`、金额都对得上。

回调路径已在入站 WAF 的豁免名单里（`api/v2/client/payment/alipay/notify`、`api/v2/client/payment/notify/*`），不需要再动 WAF；反过来，如果你为了「让回调过去」去放宽 WAF 规则，那是改错了地方。

```bash
# 回调必须是公网可达的地址，本机验证时不要用 APP_URL=127.0.0.1 的配置去测真实回调
grep -E '^(APP_URL|FRONTEND_URL|CLIENT_CONSOLE_URL|ALIPAY_NOTIFY_URL)=' /你的项目/backend/.env
```

## 6. 排障

- **用户付款成功、本地订单一直待支付**：九成是回调地址问题。先确认 `.env` 里 `APP_URL` / `ALIPAY_NOTIFY_URL` 是公网 API 域名，再确认 Nginx 与 WAF 放行，最后看网关日志里有没有收到请求。
- **回调收到了但验签失败**：支付宝公钥填成了应用公钥；或易支付选了 RSA 却没填平台公钥；或易支付的商户密钥与服务商侧不一致。
- **渠道侧显示「通知失败」**：回调被限流挡下（60 次/分钟/IP）或返回了非 `success` 文本；支付宝会按递增间隔最多重试 8 次，短时限流通常能自愈，持续失败要看网关日志。
- **前台没有这个支付方式**：插件没启用，或 `isEnabled()` 的前置条件有一项为空（易支付最常见的是 `payment_types` 没勾、`api_endpoint` 不是合法 URL）。
- **改完配置没生效**：支付宝配置有 60 秒进程内缓存，最迟一分钟生效；改网关或换插件后建议重启 PHP-FPM。
- **换域名后渠道全挂**：`APP_URL`、`CLIENT_CONSOLE_URL` 没跟着改；这两项不在后台，改的是 `.env`。

## 关联文档

- [初始配置](index.md)：四步配置的顺序与上线检查清单。
- [上游供应商对接](upstream.md)：钱进来之后的另一半，机器从哪里开出来。
- [部署与调度](../deploy/scheduling.md)：四端域名、反代与队列的部署事实，回调可达性依赖它。
- [工程踩坑记录](../../develop/pitfalls.md)：改配置与增量部署时的实测坑。
