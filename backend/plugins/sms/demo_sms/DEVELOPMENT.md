# 短信插件开发文档

## 目标边界

短信域采用“系统生成验证码 + 插件发送”的边界：

- 系统层负责验证码生成、频控、风控、日志、脱敏和业务流程。
- 短信插件负责读取自身配置、调用第三方短信 API、把发送结果标准化返回。
- 插件不要直接写用户表、验证码表或短信日志，不要把第三方调用委托回 `app/Services/Sms/Drivers/*`。

`demo_sms` 只模拟发送结果，用来演示短信插件契约；真实插件应在 `lib/` 内完成第三方 API 调用。

## 目录职责

- `config.php`：声明 `domain=sms`、`slug=demo_sms`、入口类、能力和配置项。
- `DemoSmsPlugin.php`：插件入口类，继承具体服务实现。
- `lib/DemoSmsService.php`：实现短信动作分发和发送逻辑。
- `DEVELOPMENT.md`：当前短信域插件开发说明。

## 必须实现的动作

### `sms.send_verify_code`

系统发送验证码时调用该动作。

请求 `payload`：

- `phone`：手机号。
- `code`：系统生成的验证码。
- `options`：短信签名、模板编号等可选参数。

返回数据应对齐 `SmsSendResult::toArray()`：

- `status`：建议成功时为 `success`。
- `request_id`：第三方请求 ID，没有时可为空。
- `template_code`：实际使用的模板编号。
- `template_params`：实际提交给第三方的模板变量。
- `raw`：脱敏后的第三方原始响应摘要。

### `sms.test`

后台插件配置页测试短信时调用该动作。

请求 `payload`：

- `phone`：测试手机号。

实现建议：

- 使用系统测试入口传入的随机验证码；直接调用测试动作时由插件按 `100000` 到 `999999` 兜底生成。
- 复用 `sms.send_verify_code` 的发送逻辑。
- 返回 `action=sms.test`，方便后台识别测试动作。

## 配置约定

配置项在 `config.php` 中声明，敏感字段设置 `secret => true`。系统会负责加密保存和展示脱敏预览，插件通过 runtime 请求中的 `config` 读取已合并配置。

常见配置：

- `access_key`：第三方 Access Key。
- `secret_key`：第三方 Secret Key，必须标记为敏感字段。
- `sign_name`：短信签名。
- `template_code`：验证码模板编号。

## 开发真实短信插件

1. 复制 `demo_sms` 目录并修改 `slug`、`key`、命名空间和入口类。
2. 在 `config.php` 中声明第三方短信服务需要的配置。
3. 在 `lib/` 内实现签名、请求、响应解析和错误映射。
4. 保持 `sms.send_verify_code` 和 `sms.test` 返回结构稳定。
5. 日志只能记录脱敏后的手机号、模板编号、请求 ID 和错误摘要。

## 验证

```bash
cd backend
php artisan test tests\Feature\PluginSimulationTest.php --filter=demo_sms
```
