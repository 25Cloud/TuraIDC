# 插件包规范（独立仓库分发与插件市场）

> 补充文档：运行期契约（config.php / 入口 execute / 配置 schema / 定时任务 / 安全边界）详见
> [plugins/README.md](./README.md)。本文聚焦「第三方插件如何独立开发、打包、分发」，并为插件市场打底。

## 1. 背景

主仓是 AGPL 开源项目，插件长期与主仓耦合在 `backend/plugins/` 下：第三方开发者只能 fork 主仓、加插件、PR 合入，迭代与维护成本随插件数量上升而不可持续。

目标形态是**插件市场**：开发者按统一格式创建独立插件仓库，用户通过管理端安装/升级。分三步走：

| 阶段            | 内容                                                                         | 状态   |
| --------------- | ---------------------------------------------------------------------------- | ------ |
| M1 模板与脚手架 | `php artisan plugin:make` 生成骨架 + 独立模板仓库 + 本文档                   | 已落地 |
| M2 插件市场     | GitHub 索引 + tag/sha 审核锁定下载 + `plugin:market:install` + 手动 zip 加载 | 已落地 |
| M3 市场页与签名 | 管理端市场页一键安装/升级、包签名校验                                        | 规划中 |

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
- **许可**：插件独立授权。随主仓分发的内置插件遵循主仓协议；独立仓库插件可自选许可，但不得使用主仓代码的派生实现而不履行主仓协议义务（见第 7 节）。

## 4. 插件包格式

市场分发采用 zip 安装包（GitHub archive 与手动 zip 均为此形态），布局：

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

## 5. 插件市场：索引与分发（M2 已落地）

市场**不建任何自建逻辑**，索引与分发全部依赖 GitHub，配合国内加速镜像使用。

### 5.1 索引仓库

官方索引仓库 [25Cloud/turaidc-plugin-index](https://github.com/25Cloud/turaidc-plugin-index) 维护一个 `plugins.json`：

```json
{
  "schema": 1,
  "updated_at": "2026-08-29T00:00:00+08:00",
  "plugins": [
    {
      "slug": "my_sms",
      "domain": "sms",
      "name": "我的短信",
      "description": "对接 xx 短信服务商",
      "developer": "25Cloud",
      "repo": "25Cloud/my-sms-plugin",
      "tag": "v1.2.0",
      "sha": "67cd977fe6a714fe3f78f9a9f58785675ea0492c",
      "released_at": "2026-08-29",
      "license": "MIT",
      "homepage": "https://github.com/25Cloud/my-sms-plugin"
    }
  ]
}
```

条目字段：`slug`（全局唯一）、`domain`（合法插件域）、`name`、`description`、`developer`、`repo`（`owner/repo`）、`tag`（Git tag，如 `v1.2.0`）、`sha`（该版本锁定 commit，可选）、`released_at`、`license`、`homepage`。

### 5.2 开发者发布流程（PR 审核）

1. 用模板仓库（或 `plugin:make`）创建插件独立仓库，开发、自测、完成 `README.md` 与 `LICENSE`。
2. 发版本：打 `vMAJOR.MINOR.PATCH` tag 并发布 GitHub Release（tag 与 `config.php` 的 `info.version` 一致）。
3. 向 [25Cloud/turaidc-plugin-index](https://github.com/25Cloud/turaidc-plugin-index) 提 PR，在 `plugins.json` 追加条目，附上 tag（建议同时附该 tag 的 commit sha）。
4. 平台审核 PR：核对包内容、许可、安全边界后合入，插件即上架。

**审核锁定原理**：安装器只下载索引条目锁定的版本——

- `sha` 模式：下载 `https://github.com/{repo}/archive/{sha}.zip`，解压后强校验顶层目录名必须为 `{repo-basename}-{sha}`（GitHub archive 命名规则），确保下载的就是审核过的 commit，**审核后修改仓库内容无法影响已上架版本**；
- `tag` 模式：下载 `{repo}/archive/refs/tags/{tag}.zip`。若 tag 被 force-push 指向新 commit，不会自动升级到新内容（重装才更新）；新版本必须走新的 PR 重新审核。

### 5.3 主仓安装命令

```bash
cd backend
php artisan plugin:market:list              # 同步索引并列出可安装插件（缓存 5 分钟）
php artisan plugin:market:list --force      # 强制重新拉取索引
php artisan plugin:market:install my_sms    # 按索引条目下载审核锁定版本并安装
php artisan plugin:market:install my_sms --zip=./my_sms-v1.2.0.zip  # 手动加载本地插件包
php artisan plugin:market:install my_sms --force  # 目标目录已存在时覆盖
```

`--zip` 手动加载的 slug 取包内 manifest 并与参数比对；解压有路径穿越防护与 100MB 体积上限。

### 5.4 国内访问

索引与代码下载地址均可加加速镜像前缀，见 `config/plugins.php` 的 `plugins.market`（`PLUGIN_MARKET_RAW_MIRROR` / `PLUGIN_MARKET_DOWNLOAD_MIRROR`，默认 `https://ghfast.top/`，留空则直连）。

## 6. 脚手架与模板的关联

- `php artisan plugin:make` 生成的就是「可发布为独立仓库」的最小骨架：开发完成后删除 `--provider`/`--task` 之外的空壳，补齐业务逻辑即可推送。
- **独立模板仓库**：<https://github.com/25Cloud/turaidc-plugin-template>（已启用 Template 属性）。开发者点 **Use this template** 一键复制，再执行 `php scripts/rename.php <slug> [<显示名>]` 全局替换（类名/命名空间/动作），含 CI（pint / php -l / manifest 校验）与 MIT 许可模板。
- 模板与 `plugin:make` 二选一即可，产物契约完全一致：模板用 addons/my_plugin 作占位，命令按实际 domain/slug 直接生成。

## 7. 协议与安全边界

- **AGPL 边界**：主仓 AGPL-3.0。插件若独立仓库分发、独立授权，必须避免直接复制主仓代码；通过 manifest / `execute()` 契约接口集成（接口本身是主仓公开的集成点）。插件是否被认定为与主仓构成联合作品、从而受 AGPL 约束，属于法律判断；第三方插件应自行评估并取得必要授权。官方插件市场可提供「随主仓同授权」与「独立授权」两种档位明示。
- **安全边界**沿用 [plugins/README.md](./README.md) 第 9 节：插件不能自行注册管理端菜单、权限、迁移和任意路由；回调、账务、开通等仍由平台服务层控制；插件代码在服务器执行，安装即视为信任——市场通过 PR 人工审核（M2 已落地）与后续的包签名（M3）设置门槛。

## 8. 验证命令

```bash
# 生成骨架并检查
cd backend
php artisan plugin:make sms demo_check --force
php -l plugins/sms/demo_check/DemoCheckPlugin.php
php vendor/bin/pint --test plugins/sms/demo_check

# 插件市场
php artisan plugin:market:list --force
php artisan plugin:market:install <slug>
php artisan plugin:market:install <slug> --zip=<本地插件包.zip>
```
