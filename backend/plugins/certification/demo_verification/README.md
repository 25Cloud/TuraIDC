# Demo 实名认证插件

这是给开发者参考的实名认证插件代码包。

要点：

- `config.php` 声明配置项和能力。
- `DemoVerificationPlugin.php` 是入口类。
- `logic/DemoVerification.php` 实现 `VerificationDriver`。
- 当前项目不需要 `.tpl` 模板文件。

真实开发时，把 `initialize`、`generateScanUrl`、`queryStatus` 替换为第三方实名认证接口调用。用户实名状态、收费账单、记录落库仍由平台控制。
