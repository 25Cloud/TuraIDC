# Demo 短信插件

这是给开发者参考的短信插件代码包。

要点：

- `config.php` 声明短信供应商参数。
- `DemoSmsPlugin.php` 是入口类。
- `lib/DemoSmsService.php` 实现 `SmsDriver`。
- 当前项目不需要 `.tpl` 模板文件。

真实开发时，`sendVerifyCode` 中调用第三方短信接口。验证码生成、频控、日志脱敏由平台处理。
