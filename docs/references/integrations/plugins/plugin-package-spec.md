# 插件包规范（独立仓库分发与插件市场）

> 补充文档：运行期契约（config.php / 入口 execute / 配置 schema / 定时任务 / 安全边界）详见
> [plugins/README.md](./README.md)。本文聚焦「第三方插件如何独立开发、打包、分发」，并为插件市场打底。

## 1. 背景

主仓是 AGPL 开源项目，插件长期与主仓耦合在 `backend/plugins/` 下：第三方开发者只能 fork 主仓、加插件、PR 合入，迭代与维护成本随插件数量上升而不可持续。

目标形态是**插件市场**：开发者按统一格式创建独立插件仓库，用户通过管理端安装/升级。分三步走：

| 阶段              | 内容                                                | 状态   |
| ----------------- | --------------------------------------------------- | ------ |
| M1 模板与脚手架   | `php artisan plugin:make` 生成骨架 + 本文档         | 已落地 |
| M2 外部插件安装   | 扫描器支持第三方插件目录，管理端 zip 安装/卸载/升级 | 规划中 |
| M3 市场索引与签名 | 官方插件索引、包签名校验、市场页一键安装            | 规划中 |

无论哪一步，**运行期契约不变**：插件仍是 `backend/plugins/{domain-dir}/{slug}/` 下的一组文件，`config.php` 声明 manifest，入口类提供 `execute()`。变化的只是「代码如何进入服务器」这一层。

## 2. 快速开始：脚手架

主仓提供骨架生成命令：

```bash
cd backend
php artisan plugin:make sms my_sms --name="我的短信" --provider --task
```

参数：

| 参数/选项    | 说明                                                                                    |
| ------------ | --------------------------------------------------------------------------------------- |
| `domain`     | 插件域：`payment` / `verification` / `captcha` / `mail` / `sms` / `upstream` / `addons` |
| `slug`       | 插件标识，snake_case（如 `my_sms`），与目录名一致                                       |
| `--name`     | 显示名，缺省取 slug 的可读形式                                                          |
| `--provider` | 生成 `src/Providers/{Studly}ServiceProvider.php` 并在 manifest 声明                     |
| `--task`     | 生成心跳定时任务并在 manifest 声明                                                      |
| `--force`    | 目录已存在时覆盖                                                                        |

生成结果：

```text
backend/plugins/sms/my_sms/
├── config.php            # manifest（info + config schema）
├── MySmsPlugin.php       # 统一执行入口（execute）
├── lib/MySmsScheduledTask.php   # 仅 --task
├── src/Providers/MySmsServiceProvider.php  # 仅 --provider
└── README.md
```

生成的骨架立即可用：`php -l` 通过、pint 通过、可被 `PluginInstaller` 安装启用。

## 3. 独立插件仓库规范

第三方插件以**独立 Git 仓库**分发，仓库布局与生成骨架一致，额外约定：

```text
my-sms-plugin/
├── config.php
├── MySmsPlugin.php
├── lib/                 # 业务逻辑（运行时自动加载）
├── logic/               # 较重业务逻辑（可选）
├── src/Providers/       # ServiceProvider（可选）
├── tests/               # 插件自测（可选，建议）
├── README.md            # 插件说明、配置、动作表、许可
├── LICENSE              # 插件自己的许可文件
└── plugin.json          # （可选）与 config.php 等价的 JSON manifest，二选一
```

约定：

- **命名空间**固定 `TuraIDC\Plugins\{StudlyDomain}\{StudlySlug}`，目录名 = slug（snake_case）。
- **slug 全局唯一**：在插件市场注册前先检索是否已被占用；与主仓内置插件冲突的 slug 不允许安装。
- **不提交** `vendor/` 与运行时产物；自带依赖（如 SDK）随包分发时提供 `composer.json` 或锁文件。
- **版本号**遵循语义化版本 `MAJOR.MINOR.PATCH`，manifest `info.version` 与 Git tag 保持一致。
- **许可**：插件独立授权。随主仓分发的内置插件遵循主仓协议；独立仓库插件可自选许可，但不得使用主仓代码的派生实现而不履行主仓协议义务（见第 6 节）。

## 4. 插件包格式（M3 的打包基线）

市场分发采用 zip 安装包，布局：

```text
{slug}-{version}.zip
└── {slug}/                # 顶层即插件目录，目录名 = slug
    ├── config.php         # 或 plugin.json
    ├── {Studly}Plugin.php
    ├── lib/
    ├── logic/
    ├── src/               # 可选
    └── README.md
```

安装时校验顺序（与 `PluginInstaller` 现有校验一致）：

1. 解压路径穿越防护：所有文件必须落在 `{slug}/` 前缀内。
2. manifest 必填字段：`domain` / `slug` / `key` / `name` / `version` / `entry`，且 `slug` 与目录名一致、`domain` 合法。
3. 入口类存在且有 `execute()`，所有声明的类文件必须位于插件目录内。
4. `scheduled_tasks` / `schedule_hooks` 声明逐一实例化校验契约。
5. 目标 slug 未与内置插件及已安装插件冲突。

**签名预留**：M3 阶段对安装包做签名校验（发布者密钥对 + 包清单哈希），校验失败拒绝安装。插件清单的 `manifest_hash` 篡改检测已存在（`PluginScanner::assertManifestHash`），签名在此基础上补齐来源可信。

## 5. 脚手架与模板的关联

- `php artisan plugin:make` 生成的就是「可发布为独立仓库」的最小骨架：开发完成后删除 `--provider`/`--task` 之外的空壳，补齐业务逻辑即可推送。
- 计划中的独立模板仓库（`turaidc-plugin-template`）以生成为基准：作为 GitHub 模板仓库供「想手写不跑命令」的开发者一键复制，含 CI（pint / php -l / manifest 校验脚本）与许可模板。
- 模板与命令二选一即可，产物契约完全一致。

## 6. 协议与安全边界

- **AGPL 边界**：主仓 AGPL-3.0。插件若独立仓库分发、独立授权，必须避免直接复制主仓代码；通过 manifest / `execute()` 契约接口集成（接口本身是主仓公开的集成点）。插件是否被认定为与主仓构成联合作品、从而受 AGPL 约束，属于法律判断；第三方插件应自行评估并取得必要授权。官方插件市场可提供「随主仓同授权」与「独立授权」两种档位明示。
- **安全边界**沿用 [plugins/README.md](./README.md) 第 9 节：插件不能自行注册管理端菜单、权限、迁移和任意路由；回调、账务、开通等仍由平台服务层控制；插件代码在服务器执行，安装即视为信任——市场对第三方插件必须设置审核或签名门槛（M3）。

## 7. 验证命令

```bash
# 生成骨架并检查
cd backend
php artisan plugin:make sms demo_check --force
php -l plugins/sms/demo_check/DemoCheckPlugin.php
php vendor/bin/pint --test plugins/sms/demo_check
```
