---
source: develop/plugins/package-spec.md
sourceHash: 91594c221cedd64cc2acdb157a1a3142807e34248c0d82dd5919ecac51f86f03
translatedAt: 2026-10-26
---

# Plugin Package Specification (Standalone Repository Distribution and Plugin Marketplace)

> Supplementary document: the runtime contract (config.php / entry execute / config schema / scheduled task / security boundary) is covered in
> [plugins/README.md](index.md). This document focuses on how third-party plugins are developed, packaged, and distributed independently, and lays the groundwork for the plugin marketplace.

## 1. Background

The main repository is an AGPL open-source project, and plugins have long been coupled to it under `backend/plugins/`: third-party developers can only fork the main repository, add the plugin, and get it merged through a PR, so the iteration and maintenance cost becomes unsustainable as the number of plugins grows.

The target form is a **plugin marketplace**: developers create standalone plugin repositories in a uniform format, and users install/upgrade them from the admin side. It is delivered in three steps:

| Phase                           | Content                                                                                           | Status    |
| ------------------------------- | ------------------------------------------------------------------------------------------------- | --------- |
| M1 Template and scaffolding     | `php artisan plugin:make` generates the skeleton + standalone template repository + this document | Delivered |
| M2 Plugin marketplace           | GitHub index + tag/sha review-locked download + `plugin:market:install` + manual zip loading      | Delivered |
| M3 Marketplace page and signing | One-click install/upgrade on the admin marketplace page, package signature verification           | Planned   |

Whatever the step, the **runtime contract stays unchanged**: a plugin is still a set of files under `backend/plugins/{domain-dir}/{slug}/`, `config.php` declares the manifest, and the entry class provides `execute()`. Only the "how the code gets onto the server" layer changes.

## 2. Quick Start: Scaffolding

The main repository provides a skeleton generation command:

```bash
cd backend
php artisan plugin:make sms my_sms --name="我的短信" --provider --task
```

Arguments:

| Argument/Option | Description                                                                                    |
| --------------- | ---------------------------------------------------------------------------------------------- |
| `domain`        | Plugin domain: `payment` / `verification` / `captcha` / `mail` / `sms` / `upstream` / `addons` |
| `slug`          | Plugin identifier, snake_case (for example `my_sms`), identical to the directory name          |
| `--name`        | Display name; defaults to a readable form of the slug                                          |
| `--provider`    | Generates `src/Providers/{Studly}ServiceProvider.php` and declares it in the manifest          |
| `--task`        | Generates the heartbeat scheduled task and declares it in the manifest                         |
| `--force`       | Overwrites the directory when it already exists                                                |

Generated result:

```text
backend/plugins/sms/my_sms/
├── config.php            # manifest（info + config schema）
├── MySmsPlugin.php       # 统一执行入口（execute）
├── lib/MySmsScheduledTask.php   # 仅 --task
├── src/Providers/MySmsServiceProvider.php  # 仅 --provider
└── README.md
```

The generated skeleton is usable immediately: it passes `php -l`, passes pint, and can be installed and enabled by `PluginInstaller`.

## 3. Standalone Plugin Repository Specification

Third-party plugins are distributed as a **standalone Git repository**. The repository layout matches the generated skeleton, with these additional conventions:

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

Conventions:

- **Namespace** is fixed to `TuraIDC\Plugins\{StudlyDomain}\{StudlySlug}`, and the directory name = slug (snake_case).
- **slug is globally unique**: check whether it is already taken before registering it in the plugin marketplace; a slug that conflicts with a plugin built into the main repository cannot be installed.
- **Do not commit** `vendor/` or runtime artifacts; when shipping bundled dependencies (such as an SDK) with the package, provide `composer.json` or a lock file.
- **Version numbers** follow semantic versioning `MAJOR.MINOR.PATCH`, and the manifest `info.version` stays consistent with the Git tag.
- **License**: plugins are licensed independently. Built-in plugins distributed with the main repository follow the main repository license; standalone repositories may choose their own license, but an implementation derived from main-repository code must still fulfil the main repository's license obligations (see Section 7).

## 4. Plugin Package Format

Marketplace distribution uses a zip package (both the GitHub archive and a manually supplied zip take this form), with this layout:

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

Validation order at install time (identical to the existing checks in `PluginInstaller`):

1. Path traversal protection on extraction: every file must land inside the `{slug}/` prefix.
2. Required manifest fields: `domain` / `slug` / `key` / `name` / `version` / `entry`, with `slug` matching the directory name and `domain` being valid.
3. The entry class exists and has `execute()`, and every declared class file resides inside the plugin directory.
4. Each `scheduled_tasks` / `schedule_hooks` declaration is instantiated one by one to validate its contract.
5. The target slug does not conflict with a built-in plugin or an already installed plugin.

**Signing reserved**: in M3 the installer package is signature-verified (publisher key pair + package manifest hash), and a failed verification rejects the installation. Tamper detection of the plugin manifest through `manifest_hash` already exists (`PluginScanner::assertManifestHash`); signing builds on it to establish source trust.

## 5. Plugin Marketplace: Index and Distribution (delivered in M2)

The marketplace **adds no logic of its own**: the index and distribution depend entirely on GitHub, used together with an acceleration mirror for mainland China.

### 5.1 Index Repository

The official index repository [25Cloud/turaidc-plugin-index](https://github.com/25Cloud/turaidc-plugin-index) maintains a `plugins.json`:

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

Entry fields: `slug` (globally unique), `domain` (a valid plugin domain), `name`, `description`, `developer`, `repo` (`owner/repo`), `tag` (Git tag, such as `v1.2.0`), `sha` (the commit locked for that version, optional), `released_at`, `license`, `homepage`.

### 5.2 Developer Release Flow (PR Review)

1. Create the standalone plugin repository from the template repository (or with `plugin:make`), then develop, self-test, and complete `README.md` and `LICENSE`.
2. Cut a release: tag `vMAJOR.MINOR.PATCH` and publish a GitHub Release (the tag matches `info.version` in `config.php`).
3. Open a PR against [25Cloud/turaidc-plugin-index](https://github.com/25Cloud/turaidc-plugin-index), append an entry to `plugins.json`, and include the tag (including the commit sha of that tag is recommended).
4. The platform reviews the PR: after checking package contents, license, and security boundaries it merges, and the plugin goes live.

**Review-lock principle**: the installer downloads only the version locked by the index entry:

- `sha` mode: downloads `https://github.com/{repo}/archive/{sha}.zip` and, after extraction, strictly verifies that the top-level directory name is `{repo-basename}-{sha}` (the GitHub archive naming rule), which guarantees the download is exactly the reviewed commit, so **changing repository contents after review cannot affect a released version**;
- `tag` mode: downloads `{repo}/archive/refs/tags/{tag}.zip`. If the tag is force-pushed to a new commit, the installed plugin does not automatically upgrade to the new content (only a reinstall updates it); a new version must go through a new PR and be reviewed again.

### 5.3 Install Commands in the Main Repository

```bash
cd backend
php artisan plugin:market:list              # 同步索引并列出可安装插件（缓存 5 分钟）
php artisan plugin:market:list --force      # 强制重新拉取索引
php artisan plugin:market:install my_sms    # 按索引条目下载审核锁定版本并安装
php artisan plugin:market:install my_sms --zip=./my_sms-v1.2.0.zip  # 手动加载本地插件包
php artisan plugin:market:install my_sms --force  # 目标目录已存在时覆盖
```

With `--zip`, the slug is read from the manifest inside the package and compared against the argument; extraction has path traversal protection and a 100MB size limit.

### 5.4 Access from Mainland China

Both the index and the code download URLs accept an acceleration mirror prefix, see `plugins.market` in `config/plugins.php` (`PLUGIN_MARKET_RAW_MIRROR` / `PLUGIN_MARKET_DOWNLOAD_MIRROR`, default `https://ghfast.top/`; leave empty for a direct connection).

## 6. Relationship Between Scaffolding and the Template

- What `php artisan plugin:make` generates is precisely the minimal skeleton that "can be published as a standalone repository": once development is done, remove the empty stubs other than the `--provider`/`--task` ones, fill in the business logic, and push.
- **Standalone template repository**: <https://github.com/25Cloud/turaidc-plugin-template> (the Template flag is enabled). Developers click **Use this template** to copy it in one step, then run `php scripts/rename.php <slug> [<显示名>]` to replace names globally (class name/namespace/action); it includes CI (pint / php -l / manifest validation) and an MIT license template.
- The template and `plugin:make` are interchangeable, and the resulting contract is identical: the template uses addons/my_plugin as a placeholder, while the command generates directly from the actual domain/slug.

## 7. License and Security Boundaries

- **AGPL boundary**: the main repository is AGPL-3.0. If a plugin is distributed from a standalone repository under its own license, it must avoid copying main-repository code directly; integrate through the manifest / `execute()` contract interface (the interface itself is a public integration point of the main repository). Whether a plugin is deemed a combined work with the main repository and therefore bound by the AGPL is a legal determination; third-party plugins should assess this themselves and obtain any necessary authorization. The official plugin marketplace can state two tiers explicitly: "licensed together with the main repository" and "independently licensed".
- **Security boundaries** follow Section 9 of [plugins/README.md](index.md): a plugin cannot register admin menus, permissions, migrations, or arbitrary routes on its own; callbacks, accounting, provisioning, and the rest remain controlled by the platform service layer; plugin code executes on the server, so installation is treated as trust, and the marketplace sets the bar through manual PR review (delivered in M2) and package signing later (M3).

## 8. Verification Commands

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
