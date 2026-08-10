# Demo 支付网关插件

这是给开发者参考的支付插件代码包，会被后台插件管理扫描到。

要点：

- `config.php` 声明 `domain=payment`，目录名和 `slug=demo_pay` 一致。
- `DemoPayPlugin.php` 是入口类。
- `lib/DemoPayService.php` 实现 `PaymentGatewayInterface`。
- `controller/IndexController.php` 只做回调适配，不自行注册 Laravel 路由。
- 当前项目不需要 `.tpl` 模板文件。

真实开发时，把 `DemoPayService` 中的模拟返回替换成第三方 SDK 调用，并保持账务、订单、回调幂等等逻辑在平台服务层。
