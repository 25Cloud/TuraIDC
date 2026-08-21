# 支付宝身份认证插件（alipay_certify）

对接支付宝开放平台「身份认证」产品（`alipay.user.certify.open.*`）的实名认证插件。

## 目录

```text
backend/plugins/certification/alipay_certify/
├── AlipayCertifyPlugin.php        # 入口类（execute 分发）
├── config.php                     # 插件清单与配置 schema
├── logic/
│   ├── AlipayCertify.php          # action 分发、回调与计费配置
│   └── AlipayCertifyClient.php    # 支付宝网关客户端（自包含）
└── README.md
```

## 为什么 slug 是 `alipay_certify` 而不是 `alipay`

支付域已有 `key` 为 `alipay` 的收款插件。`integration_plugins` 的唯一约束是
`(domain, slug)` 与 `(domain, plugin_key)`，因此 `verification/alipay` 技术上不冲突；
但 `provider_key` 会出现在资金流水、审计日志与 `ProviderErrorMapper` 里，那些位置不带
domain，同名会难以分辨。故取 `alipay_certify`。

## 配置项

| 字段                 | 类型     | 必填 | 说明                                                        |
| -------------------- | -------- | ---- | ----------------------------------------------------------- |
| `app_id`             | text     | 是   | 开放平台为该应用分配的 AppID                                |
| `private_key`        | textarea | 是   | 应用私钥，用于请求签名（加密保存，不明文回显）              |
| `alipay_public_key`  | textarea | 是   | **支付宝公钥**（不是应用公钥），用于验签                     |
| `biz_code`           | select   | 是   | 认证场景码，必须与开放平台已签约的场景一致，默认 `FACE`      |
| `gateway_url`        | url      | 否   | 网关地址，默认 `https://openapi.alipay.com/gateway.do`      |
| `return_url`         | url      | 否   | 认证完成回跳地址；留空由系统传入控制台实名页                |
| `request_timeout`    | number   | 否   | 网关请求超时秒数，默认 15，取值钳制在 5~60                  |
| `charge_enabled`     | switch   | 否   | 是否对发起认证收费，默认关闭                                |
| `amount`             | number   | 否   | 收费金额（元），仅在开启收费时生效                          |
| `free_times`         | number   | 否   | 每用户免费认证次数                                          |

私钥与公钥都支持两种粘贴形态：带 `-----BEGIN ...-----` 头尾的完整 PEM，或只粘中间的
base64 内容。后者会被自动补齐头尾，PKCS8 与 PKCS1 均会尝试。

按项目硬规则，本插件**不提供** `ssl_verify` / `ca_bundle` 配置，统一依赖系统 CA。

## 认证流程

1. **初始化**（`certification.initialize`）
   调用 `alipay.user.certify.open.initialize`，提交 `outer_order_no`、`biz_code` 与
   `identity_param`（`CERT_INFO` / `IDENTITY_CARD` / 姓名 / 证件号），换取 `certify_id`。
   `outer_order_no` 为 32 位内字母数字组合，由 `RN + 时间 + 随机` 生成。

2. **开始认证**（`certification.scan_url`）
   `alipay.user.certify.open.certify` 是**页面接口**，不发服务端请求——把签名后的公共参数
   拼成 GET URL 返回，由平台生成二维码供用户扫码，在支付宝内完成人脸核身。

3. **查询结果**（`certification.query_status`）
   调用 `alipay.user.certify.open.query`，`passed` 为 `T` 判定通过、`F` 判定未通过。

4. **异步通知**（`certification.verify_callback`）
   支付宝以表单编码 POST 回调，插件剔除 `sign` / `sign_type` 后按 key 升序拼串验签，
   并返回 `replay_key` 交由平台做重放拦截。

### 状态码映射

平台对 `initialize` / `scan_url` 与 `query_status` 使用两套状态码，插件按其口径返回：

| action         | 返回值                                                        |
| -------------- | ------------------------------------------------------------- |
| `initialize`   | `200` 成功 / `400` 业务失败 / `500` 网络异常                  |
| `scan_url`     | 同上（页面接口无网络调用，只会是 200 / 400）                  |
| `query_status` | `1` 通过 / `2` 未通过 / `3` 网络异常 / `4` 处理中             |

**认证未完成不判失败**：支付宝在用户尚未走完流程时返回顶层 `code=40004`，语义是「处理中」。
插件识别 `CERTIFY_NOT_FINISH` / `CERTIFY_NOT_EXIST` / `CERTIFY_IN_PROCESS` 这类 `sub_code`
并映射为 `4`。若判成 `2`，用户会看到「认证失败」并被要求重新发起，而 `certify_id` 在未刷脸
时有 23 小时有效期，本可继续使用。

## 签名实现要点

与支付网关插件（`plugins/gateways/ali_pay`）同源，但收紧了两处：

- **待签串**按 key 升序、跳过 `sign` 与空值，用**未编码的原始值**以 `k=v&k=v` 拼接。
  支付网关用的 `urldecode(http_build_query())` 在值本身含 `&` 或 `=` 时会拼错。
- **同步响应验签**的对象是「响应节点在原始报文中的紧凑 JSON 原文」，插件用括号配对从
  报文里截取该片段。`json_decode` 再 `json_encode` 会因转义方式与键序变化而验签失败。

另外 `charset` 同时放进查询串：支付宝网关否则按 GBK 解码 POST body，姓名含中文时验签必然
不一致（这是支付网关插件已经踩过并留有注释的坑）。

## 启用

管理端「集成插件 → 人机验证/实名认证」中安装并启用本插件，填写上述配置后，
在实名认证驱动绑定处选择「支付宝身份认证」。同一时间只有一个实名驱动生效。
