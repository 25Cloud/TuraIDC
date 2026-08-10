# 邮件插件开发文档

## 目标边界

邮件域采用“系统编排 + 插件发送”的边界：

- 系统层负责模板解析、变量替换、发送频控、发送日志和业务流程。
- 邮件插件负责读取自身配置、调用第三方邮件服务或 SMTP 传输、把发送结果标准化返回。
- 插件不要直接写业务表，不要绕过系统日志，也不要把真实第三方调用委托回 `app/Services/Mail/Drivers/*`。

`demo_mail` 只模拟发送并写日志，用来演示插件契约；真实插件应把模拟逻辑替换为第三方 API 或 SMTP 调用。

## 目录职责

- `config.php`：声明 `domain=mail`、`slug=demo_mail`、入口类、能力和配置项。
- `DemoMailPlugin.php`：插件入口类，继承具体服务实现。
- `lib/DemoMailService.php`：实现邮件动作分发和发送逻辑。
- `DEVELOPMENT.md`：当前邮件域插件开发说明。

## 必须实现的动作

### `mail.send_html`

系统在完成模板解析后调用该动作。

请求 `payload`：

- `to`：收件邮箱。
- `subject`：邮件标题。
- `html`：已经渲染好的 HTML 正文。
- `context`：系统透传的上下文，只用于第三方请求附加参数或调试，不应作为模板再渲染。

返回：

```php
[
    'success' => true,
    'action' => 'mail.send_html',
    'data' => [
        'sent' => true,
    ],
]
```

### `mail.test_smtp`

后台插件配置页测试邮件时调用该动作。即使真实插件不是 SMTP，也应支持这个测试动作，保证后台体验一致。

请求 `payload`：

- `account_index`：后台选择的账号序号，单账号插件可忽略但要原样返回。
- `to`：测试收件邮箱。
- `subject`：测试标题。
- `body`：测试正文。

返回至少包含：

- `sent`：是否发送成功。
- `to`：测试收件邮箱。
- `subject`：测试标题。

## 配置约定

配置项在 `config.php` 中声明，敏感字段设置 `secret => true`。系统会负责加密保存和展示脱敏预览，插件通过 runtime 请求中的 `config` 读取已合并配置。

常见配置：

- `from_address`：发件邮箱。
- `from_name`：发件名称。
- `api_token` / `password`：第三方密钥或 SMTP 密码，必须标记为敏感字段。

## 开发真实邮件插件

1. 复制 `demo_mail` 目录并修改 `slug`、`key`、命名空间和入口类。
2. 在 `config.php` 中声明第三方邮件服务需要的配置。
3. 在 `lib/` 内实现第三方客户端或 SMTP 调用。
4. 保持 `mail.send_html` 和 `mail.test_smtp` 返回结构稳定。
5. 第三方异常要转换为插件失败结果或业务异常，不要暴露原始密钥、完整请求体或第三方敏感响应。

## 验证

```bash
cd backend
php artisan test tests\Feature\PluginSimulationTest.php --filter=demo_mail
```
