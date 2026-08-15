# ZJMF Bridge Addon

ZJMF Bridge 的配置、路由、控制器、中间件、日志和业务服务均收敛在本 addons 插件中。

插件启用时，通用插件 Provider 加载器会注册本插件的 `/zjmf/v1/*` 路由及受控中间件；所有请求再由 `PluginRuntimeRegistry::execute('addons', 'zjmf_bridge', 'zjmf.dispatch', ...)` 分发到插件入口。

入口配置位于 `config/zjmf_bridge.php`，插件迁移位于 `database/migrations/`。
