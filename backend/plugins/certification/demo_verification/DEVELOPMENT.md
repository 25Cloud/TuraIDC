# 实名认证插件开发文档

## 目标边界

实名认证域采用“系统流程编排 + 插件认证调用”的边界：

- 系统层负责用户状态、认证历史、收费规则落地、二维码缓存、回调日志和幂等处理。
- 实名认证插件负责读取自身配置、调用第三方认证 API、生成认证链接、查询认证状态、验签回调、返回标准化结果。
- 插件不要直接修改用户表、认证历史表或账单表，也不要把第三方调用委托回 `app/Services/Verification/Drivers/*`。

`demo_verification` 只模拟认证流程，用来演示实名认证插件契约；真实插件应在 `logic/` 内完成第三方 API 调用。

## 目录职责

- `config.php`：声明 `domain=verification`、`slug=demo_verification`、入口类、能力和配置项。
- `DemoVerificationPlugin.php`：插件入口类，负责把 runtime action 分发到逻辑类。
- `logic/DemoVerification.php`：实现认证初始化、认证链接、状态查询、回调验签和收费配置。
- `DEVELOPMENT.md`：当前实名认证域插件开发说明。

## 必须实现的动作

### `certification.initialize`

系统提交实名信息后调用。

请求 `payload`：

- `real_name`：真实姓名。
- `id_card`：证件号码。
- `cert_type`：认证类型。
- `return_url`：认证完成后的返回地址。

返回数据：

- `status`：第三方接口成功必须返回 `200`。
- `msg`：提示信息。
- `certify_id`：第三方认证会话 ID。

### `certification.scan_url`

系统需要认证二维码或跳转链接时调用。

请求 `payload`：

- `certify_id`：认证会话 ID。

返回数据：

- `status`：成功必须返回 `200`。
- `msg`：提示信息。
- `url`：第三方认证链接。

### `certification.query_status`

系统主动查询认证状态时调用。

返回 `status` 使用系统内部结果码：

- `1`：认证成功。
- `2`：认证失败。
- `3`：网络或上游异常。
- `4`：认证待完成。

### `certification.verify_callback`

系统处理实名认证回调前调用。

请求 `payload`：

- `payload`：回调请求体解析结果。
- `headers`：回调请求头。
- `method`：请求方法。
- `path`：请求路径。
- `raw_body`：原始请求体。

返回数据：

- `passed`：验签是否通过。
- `message`：验签结果说明。
- `code`：标准化结果码，成功为 `0`。
- `http_status`：失败时建议为 `401` 或第三方要求的状态码。
- `replay_key`：幂等键，建议使用 `certify_id + nonce`。

### `certification.fee_config`

系统读取插件收费配置时调用。

返回数据：

- `free_attempts`：免费次数。
- `retry_fee`：重试收费金额。
- `charge_enabled`：是否启用收费。
- `amount`：配置金额。

## 回调要求

- 插件只负责验签和生成幂等键，不直接推进用户认证状态。
- 系统负责回调幂等、日志、认证历史和用户状态同步。
- 验签失败时不要抛出原始第三方错误，返回标准化失败结果。

## 配置约定

配置项在 `config.php` 中声明，敏感字段设置 `secret => true`。系统会负责加密保存和展示脱敏预览，插件通过 runtime 请求中的 `config` 读取已合并配置。

常见配置：

- `api_url`：第三方认证接口地址。
- `app_id`：第三方应用 ID。
- `app_secret`：第三方密钥，必须标记为敏感字段。
- `charge_enabled`、`amount`、`free_times`：认证收费配置。

## 开发真实实名认证插件

1. 复制 `demo_verification` 目录并修改 `slug`、`key`、命名空间和入口类。
2. 在 `config.php` 中声明第三方认证服务需要的配置。
3. 在 `logic/` 内实现第三方 API 客户端、签名、验签、状态映射和错误映射。
4. 保持五个 `certification.*` 动作返回结构稳定。
5. 第三方返回的姓名、证件号和原始响应必须脱敏后再进入日志或 `raw` 字段。

## 验证

```bash
cd backend
php artisan test tests\Feature\PluginSimulationTest.php --filter=demo_verification
```
