# Demo 邮件插件

这是给开发者参考的邮件插件代码包。

要点：

- `config.php` 声明发件配置。
- `DemoMailPlugin.php` 是入口类。
- `lib/DemoMailService.php` 实现 `MailDriver`。
- 当前项目不需要 `.tpl` 模板文件。

真实开发时，`sendHtml` 中调用 SMTP、API 邮件服务或企业邮接口。模板渲染、验证码脱敏、邮件日志由平台处理。
