# Demo 上游服务插件

这是给开发者参考的 servers/upstream 插件代码包，会被后台插件管理扫描到。

要点：

- `config.php` 声明 `domain=upstream`，目录名和 `slug=demo_servers` 一致。
- `DemoServersPlugin.php` 是入口类。
- `logic/DemoServers.php` 实现 `UpstreamDriver` 和上游能力 marker interface。
- 插件只返回模拟商品、开通、续费、状态同步和控制台数据，不请求真实上游。
- 当前项目不需要 `.tpl` 模板文件，也不允许插件自行注册 Laravel 路由。

真实开发时，把 `DemoServers` 中的模拟返回替换成供应商 SDK/API 调用，并保持订单、账单、支付、余额、服务实例状态机、权限和审计仍由平台服务层负责。
