# 聚合实名认证插件（smapi）

对接小沐实名 API（https://smapi.x1m1.cn）的实名认证插件。

## 目录

```text
backend/plugins/certification/smapi/
├── SmapiPlugin.php       # 入口类（execute 分发）
├── config.php            # 插件清单与配置 schema
├── logic/
│   ├── Smapi.php         # action 分发、回调与计费配置
│   └── SmapiClient.php   # 上游 HTTP 客户端（自包含）
└── README.md
```

## 配置项

| 字段           | 类型     | 必填 | 说明                                             |
| -------------- | -------- | ---- | ------------------------------------------------ |
| `api_url`      | url      | 否   | 平台地址，默认 `https://smapi.x1m1.cn`           |
| `app_key`      | text     | 是   | 用户中心 API 密钥 AppKey                         |
| `secret_key`   | password | 是   | 用户中心 API 密钥 AppSecret（加密保存）          |
| `product_code` | textarea | 是   | 产品标识；多产品按 `code,名称\|code,名称` 填写     |
| `ssl_verify`   | switch   | 否   | 是否校验服务商 HTTPS 证书，默认开启              |
| `ca_bundle`    | text     | 否   | 本地 CA bundle 文件路径                          |
| `charge_enabled` | switch | 否 | 开启后用户发起认证按配置金额扣费                 |
| `amount`       | number   | 否   | 收费金额（元）                                   |
| `free_times`   | number   | 否   | 每个用户免费认证次数                             |

## 接口行为

- `certification.initialize`：调用 `POST /api/realname/initialize` 创建认证记录，
  请求体为 `product_code`（取配置中第一个有效产品标识）、`cert_name`、`cert_no`、`return_url`，
  成功后返回上游记录 `id` 作为 `certify_id`。
- `certification.scan_url`：调用查询接口，从返回数据中提取认证页面链接
  （`certify_page_url` / `certify_url` / `url` / `qrcode_url` / `qr_code_url`）。
- `certification.query_status`：状态映射为项目内部约定：
  `passed` → 1（通过）、`failed` / `updated` → 2（不通过，带失败原因）、
  `initialized` / `processing` → 4（处理中）、网络异常 → 3（保留原状态）。
- `certification.verify_callback`：平台不提供服务端签名回调，固定返回不支持（501），
  认证结果一律通过轮询查询获得。
- `certification.fee_config`：按 `charge_enabled` / `amount` / `free_times` 输出计费配置。

## 注意事项

- 当前用户端实名流程不收集手机号（`initialize` 不传 `mobile`），
  如配置的产品强制要求手机号三要素，请与服务商确认接口行为或选择不强制手机号的产品。
- 多产品配置时客户前台的“认证接口”下拉选择在当前用户端未实现，
  当前始终使用配置中第一个产品标识发起认证。
- 密钥通过 `secret=true` 加密保存且不明文回显。
