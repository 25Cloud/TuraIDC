# 插件标准 v2（清单字段、生命周期与升级）

- 状态：草案（本次按此实施）
- 更新：2026-10-27
- 适用：`backend/plugins/**` 全部插件，以及 `25Cloud/turaidc-plugin-template`、`25Cloud/turaidc-plugin-index` 两个仓库

## 1. 为什么要有这一版

现有插件只需要 `{Name}Plugin.php` + `config.php`，能装能配，但缺三件事：

1. **没有版本契约**：`info.version` 只是字符串，平台不会因为"插件版本高于已安装版本"而跑升级逻辑。
2. **没有生命周期**：安装/启用/停用/卸载/升级都没有钩子，插件只能在 `boot()` 里靠 `if` 猜自己是不是第一次跑。
3. **没有数据边界**：插件自建表没有命名约束，卸载也没法安全处理。

v2 在**不破坏 v1** 的前提下补齐这三件事。所有新增字段与接口都是可选的：
不带新字段的旧插件照常安装运行。

## 2. 清单（`config.php` 的 `info`）

```php
return [
    'info' => [
        // —— v1 必填，保持不变 ——
        'domain' => 'addons',              // 能力域，对应 backend/plugins 下的目录
        'slug' => 'ai_assistant',          // 插件标识，目录名一致
        'key' => 'ai_assistant',           // 平台内唯一键，与 slug 一致
        'name' => 'AI 助手',
        'version' => '1.0.0',              // 语义化版本，升级判断的唯一依据
        'entry' => AiAssistantPlugin::class,
        'capabilities' => ['ai.chat', 'ai.tools'],

        // —— v2 新增，全部可选 ——
        'manifest_version' => 2,
        'provider' => AiAssistantServiceProvider::class,   // 需要注册路由/中间件时必填
        'min_platform_version' => '1.0.0',                 // 平台低于此版本时拒绝启用
        'description' => '站内 AI 助手：对话、工具调用与权限分级',
        'author' => '25Cloud',
        'homepage' => 'https://github.com/25Cloud/TuraIDC',
        'license' => 'AGPL-3.0-or-later',
        'lifecycle' => AiAssistantLifecycle::class,        // 实现 PluginLifecycleInterface
        'upgrade' => [
            'from' => ['1.0.0'],          // 支持从这些版本直接升级到当前版本
            'migrations' => true,         // 升级时执行 database/migrations
        ],
    ],
    'config' => [
        // v1 结构不变；v2 允许两项可选标记，用于"换代而不删旧值"
        'base_url' => ['title' => 'API 地址', 'type' => 'text', 'required' => true],
        'model' => ['title' => '模型', 'type' => 'text', 'value' => 'deepseek-v4.1-flash', 'since' => '1.0.0'],
        'legacy_mode' => ['title' => '旧模式', 'type' => 'switch', 'deprecated' => '1.1.0'],
    ],
];
```

规则：

- `version` 必须语义化（`MAJOR.MINOR.PATCH`）；平台用它判断"已安装 < 清单"时才触发升级。
- `capabilities` 必须是点号命名空间串（`ai.chat`），用于权限与依赖判断，不要写自然语言。
- `manifest_version` 缺省视为 1；写 2 才启用新字段语义。
- `config.*.deprecated` 表示"保留读、不再展示新增"，`since` 表示从哪个版本开始存在。
  两者只影响管理端展示与升级提示，不影响读取，避免升级后配置读不到。

## 3. 生命周期

新增可选接口 `App\Contracts\Integrations\Plugins\PluginLifecycleInterface`：

```php
interface PluginLifecycleInterface
{
    public function install(array $context): void;   // 首次安装：建表、写默认值
    public function enable(array $context): void;    // 每次启用
    public function disable(array $context): void;   // 每次停用
    public function uninstall(array $context): void; // 卸载：默认只清配置，不删业务数据
    public function upgrade(string $fromVersion, string $toVersion, array $context): void;
}
```

约定：

- 全部方法必须**幂等**，平台在升级重试时会重复调用。
- `$context` 至少含 `plugin_id`、`version`、`platform_version`。
- 升级顺序固定：`升级前迁移 → upgrade() → 升级后迁移 → 写回 version`；
  任一步失败保留旧版本号，下一次安装检测会重试。
- `uninstall()` 默认不删业务表；要删必须在插件 README 里写明并提供独立命令。

## 4. 数据边界

- 插件自建表统一前缀 `plugin_{domain}_{slug}_`，例如 `plugin_addons_ai_assistant_conversations`。
- 迁移只新增：不加列默认值破坏既有数据、不改列类型、不删列；MySQL 5.7 与 8.x 都要能跑。
- 复用核心表时只读不写，写操作必须走核心 Service，不直接 `DB::table()->update()`。

## 5. 路由与中间件

- 插件只能注册**自己前缀**下的路由：`api/v2/client/{slug}/*`、`api/v2/admin/{slug}/*` 或
  `{slug}/v1/*`（对外协议），不得占用核心 `/api/v2/client/*` 之外的公共路径。
- 鉴权必须复用核心中间件（`auth:sanctum`、`ensure.client`、`ensure.admin`、`permission:*`），
  不得自建鉴权入口、不得注册全局中间件、不得注册调度任务（调度走 `ScheduledTask` 声明）。
- 需要"平滑迁移"的插件（例如从一个已存在的核心功能搬进插件），允许在一段时间内保留旧路径作为别名，
  但必须在 `upgrade()` 里登记，并在下一个 MAJOR 版本删除。

## 6. 插件索引（`turaidc-plugin-index/plugins.json`）

```json
{
  "schema_version": 2,
  "updated_at": "2026-10-27",
  "plugins": [
    {
      "key": "ai_assistant",
      "name": "AI 助手",
      "domain": "addons",
      "slug": "ai_assistant",
      "repo": "25Cloud/TuraIDC",
      "path": "backend/plugins/addons/ai_assistant",
      "ref": "main",
      "version": "1.0.0",
      "min_platform_version": "1.0.0",
      "manifest_version": 2,
      "summary": "站内 AI 助手：对话、工具调用与权限分级",
      "capabilities": ["ai.chat", "ai.tools"],
      "official": true,
      "license": "AGPL-3.0-or-later"
    }
  ]
}
```

- `schema_version` 增量加字段不升版；删字段/改语义才升。
- 平台侧解析必须容忍未知字段（忽略而不是报错），否则索引一改就锁死旧实例。
- 条目里的 `version` 必须与插件 `config.php` 的 `info.version` 一致，模板仓库的校验脚本要检查这一点。

## 7. 模板仓库（`turaidc-plugin-template`）要求

必须包含并且持续校验：

1. `config.php`：v2 清单示例（含 `manifest_version`、`lifecycle`、`upgrade`）。
2. `src/Providers/*ServiceProvider.php`：只注册自己前缀的路由 + 中间件别名。
3. `src/*Lifecycle.php`：五个生命周期方法的幂等空实现示例。
4. `scripts/validate-manifest.php`：校验必填字段、语义化版本、`capabilities` 命名、
   `lifecycle` 类是否存在且实现接口、`min_platform_version` 格式。
5. `docs/SPEC.md`：本文件的中性表述版（不依赖本仓库内部路径）。
6. `.github/workflows/validate.yml`：PR 与 push 都跑 `validate-manifest.php`。
7. `README.md`：三分钟上手 + 目录说明 + 升级怎么做 + 不要做什么。

## 8. 已知取舍

- 不引入插件沙箱与独立依赖版本（PHP 单进程共享 autoload），依赖冲突靠 `vendor/` 自带与
  `composer.json` 声明，不做运行时隔离。
- 不做插件热更新：升级需要重新扫描 + 平台写回版本号，避免半升级状态被当成已升级。
- 生命周期钩子只在平台动作里触发，插件不得自己调用别人的钩子。
