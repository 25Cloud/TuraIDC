---
status: completed
updated: 2026-11-25
owner: Codex
---

# 本机后端测试环境与回归

## 背景

用户明确授权扫描本机可用测试环境、下载缺失组件并运行测试。本次继续验证前端清理后的项目，采用已有数据库可执行文件和独立本地数据目录，不接入现有业务数据库。

## 范围与验收

- [x] 检查 PATH、常见安装目录、集成环境、WSL 与相关服务。
- [x] 配置与 CI 一致的 PHP 8.3、Composer 与锁定后端依赖。
- [x] 在独立目录和本机端口启动 MySQL，初始化专用测试库、导入 schema baseline 并执行增量迁移。
- [x] 运行插件、单元和功能测试及启动检查，记录失败、跳过和未覆盖范围。
- [x] 记录可复用运行方式，整理临时文件与文档索引。

## 测试环境

- PHP 8.3.37 位于 D:/CodexDbTools/php-8.3.37，独立 php.ini 已启用项目及测试所需扩展，包含 MySQL、SQLite、mbstring、OpenSSL、curl、GD 和 zip。启动器显式设置 OPENSSL_CONF 指向该 PHP 自带的 extras/ssl/openssl.cnf，以支持 Windows 下 RSA 密钥生成。
- Composer 2.11.3 及缓存位于 D:/CodexDbTools/turaidc-testing，按 composer.lock 安装 121 个包，没有更新锁文件；PHP 平台检查与包发现通过。
- 复用 MySQL 8.0.39 程序，在 D:/CodexDbTools/turaidc-testing/mysql80-data 初始化新数据目录，绑定 127.0.0.1:33380，仅使用 idc_test 测试库。buffer pool 为 512M，测试进程 PATH 显式包含 mysql 客户端。
- 应用测试配置放在被 Git 忽略的 backend/.env.testing。随机应用密钥与数据库凭据仅保留在本机配置，文档不记录取值。
- Redis 7.2.16 使用 redis-windows/redis-windows 的 MSYS2 便携构建，发行包 SHA-256 与发布摘要一致，绑定 127.0.0.1:36379，设置随机认证口令、禁用持久化，仅用于本机测试。使用项目已有 Predis 客户端，没有引入新的项目依赖。Redis 7.2 为 BSD-3-Clause，本机工具不随项目分发。
- 默认缓存与会话仍按 PHPUnit 配置使用 array、邮件使用 array、队列使用 sync；需要直接访问 Redis 的插件分支连接上述独立实例。

## 验证结果

| 检查 | 结果 |
| --- | --- |
| 依赖 | composer install 按锁文件完成，121 个包；check-platform-reqs、post-autoload-dump 通过。 |
| 数据库 | 独立 MySQL 8.0.39 导入 schema baseline，增量迁移通过。 |
| 管理端权限审计 | 298 条路由：293 条挂权限、5 条既有自服务白名单；缺失、重复和未知权限码均为 0。 |
| Plugins | 111 项通过，667 个断言。 |
| Unit | 672 项通过、2 项既有跳过，8,091,892 个断言。 |
| Feature | 1,822 项通过、4 项既有跳过，104,244 个断言。 |
| 全量合计 | 2,611 项：2,605 通过、6 项既有跳过，零失败与错误；8,196,803 个断言，完整命令退出码为 0，约 492 秒。 |
| 环境复用 | 独立 MySQL 与 Redis 停止、重新启动后，HealthEndpointTest 的 3 项测试与 11 个断言通过；验证结束已停止两个独立实例。 |
| HTTP 启动烟测 | 本机 38080 端口启动成功；GET /up 返回 200，GET /api/health 返回 200 / code=0 / alive，未登录 GET /api/v2/admin/auth/info 返回 401 / code=40100。 |

单元测试的 2 项跳过来自 ProductionMigrationSafetyTest：仓库未包含它要检查的两个历史迁移文件，测试代码原本即如此处理。本轮没有添加跳过、调整业务代码或放宽断言。首次单元测试的 10 项 Redis 连接错误在补齐服务后全部消失；环境排查阶段的功能测试主动中止，以最终完整运行的结果为准。支付宝认证用例曾因 Windows OpenSSL 缺少配置路径而无法生成测试 RSA 密钥，补齐 OPENSSL_CONF 后重新完整运行功能套件。

功能测试的 4 项既有跳过：HotPathQueryPerformanceTest 与 ReferralRewardLifecycleRegressionTest 需要当前基线不存在的 user_referrals 表；IdcRefactorSchemaClosureTest 已在源码中固定跳过；ZjmfProviderKeyMigrationTest 依赖未随仓库提供的历史迁移。

邮件队列测试曾受到前面中断运行留下的非探针任务影响，断言全 jobs 表数量时失败；重建独立测试库后，两项定向回归通过。完整复用命令已加入测试库重建步骤，从干净数据库顺序运行三套回归并全部完成，最终 JUnit 报告均无失败或错误，邮件重试两项在完整套件中通过。

## 本机复用

在 CMD 中运行以下命令；启动器仅使用上文的独立数据目录和端口，发现端口被其他认证实例占用时会拒绝继续。无参数时先校验 testing 环境及解析后的 MySQL 连接、地址、端口、库名和测试用户，再重建专用测试库并按 CI 顺序分别运行 Plugins、Unit、Feature；带筛选参数时直接运行指定测试。

```cmd
D:\CodexDbTools\turaidc-testing\test-backend.cmd
D:\CodexDbTools\turaidc-testing\test-backend.cmd --filter=HealthEndpointTest
D:\CodexDbTools\turaidc-testing\stop-backend-env.cmd
```

- 测试前端口未启动时，启动器会隐藏启动 MySQL 与 Redis；测试结束可用关闭命令释放进程。没有注册 Windows 服务或修改系统 PATH。
- 便携 Redis 从 Windows 程序直接启动时使用相对配置名 redis-testing.conf，并把工作目录设为其所在目录；Windows 绝对配置路径在该构建中无法识别。
- CMD 启动器使用本次 Codex 捆绑 Node 的绝对路径；桌面运行时升级后如旧路径被移除，需更新启动器中的 Node 路径。
- 随机口令保存在被忽略的 backend/.env.testing 与运行目录的专用配置中；不要复制到提交、文档或命令行。

## 未覆盖范围

本轮为 Windows / PHP 8.3 / MySQL 8.0 / Redis 7.2 与 Predis 的本机验证，没有替代 Linux 原生 phpredis、MySQL 5.7 全套回归、常驻队列及调度进程的验证，也未调用真实支付、邮件、短信或收费上游。前端首批的原版对照证据和既有失败仍见[清理记录](frontend-build-cleanup-2026-11-25.md)。

## 风险与回滚

已有 MySQL 与 PHP 安装仅复用可执行文件。新的配置、数据、日志与凭据独立保存；应用只使用带 test 名称的测试库。禁止连接现有业务数据目录或调用真实支付、短信和上游写操作。缺失运行时使用官方发行包，并校验下载摘要。源码改动与此前待提交清理差异分别核对。

## 进度

- [x] 在 D:/CodexDbTools 找到 PHP 8.5.13、MySQL 8.0.39、MySQL 5.7.44 等现有环境，但它们未加入 PATH；Composer 未发现。
- [x] 阅读测试配置和 CI，确认使用 schema baseline、增量迁移和独立 MySQL 测试库。
- [x] 补齐 PHP 8.3 与依赖。
- [x] 初始化 schema baseline 和增量迁移；管理端路由权限审计通过，HTTP 健康与未认证访问烟测通过。
- [x] 完成全量功能测试，记录最终统计。

## 决策日志

| 日期 | 决策 | 原因 |
| --- | --- | --- |
| 2026-11-25 | 保留已有 PHP 安装，另行配置 PHP 8.3 | 当前可用版本为 8.5，测试优先与项目 CI 的 8.3 对齐，降低版本差异干扰。 |
| 2026-11-25 | 复用 MySQL 8.0.39 程序，独立数据目录与端口 | 不需要重复下载数据库，且不会影响现有环境。 |
| 2026-11-25 | 补齐独立 Redis 后重跑全套 | 初次单元测试 10 项因插件直接访问 Redis 而连接失败；不修改业务或放宽断言规避环境缺口。 |
| 2026-11-25 | 在测试启动器显式设置 OPENSSL_CONF | PHP 包自带配置文件，Windows 默认路径不存在会令 RSA 测试密钥生成失败；只修正本机进程环境。 |
| 2026-11-25 | 全量运行前重建独立测试库 | 中断运行可能留下队列任务，先清理测试数据可避免全表数量断言受前次运行污染；重建脚本校验环境、连接及目标库。 |
