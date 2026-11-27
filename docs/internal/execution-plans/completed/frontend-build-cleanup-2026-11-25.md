---
status: completed
updated: 2026-11-25
owner: Codex
---

# 前端构建去重与废弃代码清理

## 背景

用户要求拉取并理解 TuraIDC，清理无用文件和难维护代码，并确保清理不影响现有功能。本批基于 `340f1327b60d39876ec41c9cd39c50a0471b7877`，经源码走读、引用检查和原版对照，处理三端构建重复、确定无调用方的遗留代码与官网回归脚本。工程规则沿用[前端规范](../../../develop/frontend.md)和[工作规则](../../governance/WORKING_RULES.md)。

## 范围与验收

- [x] 三端静态资源命名、gzip/Brotli 预压缩共用一个实现，验证相对与绝对输出目录。
- [x] 删除确定无调用方的遗留文件与路由函数，并记录删除依据。
- [x] 完成共享测试、三端类型检查和生产构建、现有浏览器回归、构建上下文检查；失败项与未修改原版对照。
- [x] 修复官网源码检查在 Windows 下空跑、多行 Vue 属性误报和回归脚本依赖外部 npm 的问题。
- [x] 记录入项口径、本机限制与回归证据，完成文档归档。

## 实施结果

### 构建去重

新增 `shared/vite/buildAssets.mjs` 与对应类型声明，供三端 Vite 配置使用。保持资源分类目录、1024 字节阈值、gzip level 9、Brotli quality 11、仅保留有压缩收益的副本及原文件回退行为。输出目录改为读取 Vite 最终配置，修复旧实现写死 `dist` 导致 `--outDir` 下预压缩失效的问题。

新增 5 个构建测试，覆盖资源分类、递归扫描、大小写扩展名、压缩阈值、解压一致性、不可压缩内容、目录不存在以及真实 Vite 自定义目录构建。

### 删除依据

| 文件或符号 | 核实结果 |
| --- | --- |
| `frontend-admin-v3/src/hooks/useFinanceDetailDrawer.ts` | Hook 及其状态类型只在定义处存在，页面已有实际使用的账单详情实现。 |
| `frontend-user-v3-www/src/router/lazyView.js` | 旧 re-export 无调用方，现有路由直接引用共享运行时。 |
| `frontend-user-v3-www/src/assets/styles/_card-header.scss` | mixin 名称无样式导入或调用。 |
| `shared/vite-plugin-version.ts` | 插件工厂无调用方，三端构建均未加载此文件。 |
| admin、console 的 `getRoutesExpanded` | 两端均明确标记废弃，仓库中只有函数定义，没有调用。 |

检查范围包含源码、测试、文档、样式与动态 glob。保留仍由 glob 加载的路由、类型声明、仍有导入的样式及静态演示站。控制台的 `isRecord` 仍参与路由转换，保留其实现。

### 回归脚本修复

官网 `check-source-health.mjs` 原先用 `new URL(WindowsPath, "file:")` 判断入口，Windows 下可能退出 0 却没有执行扫描；改用 `pathToFileURL`。Vue 属性语法交给已有 Vue 编译器解析，消除合法跨行绑定被逐行正则误报的问题。新增 4 个 CLI 测试，验证正常文件、损坏字符、多行属性和真正未闭合属性。

`verify-refactor.mjs` 使用当前 Node 可执行文件启动健康检查，再调用 Vite 构建 API，移除对 PATH 中 npm 的依赖与 Shell 字符串命令。健康检查在独立进程运行，避免 SFC 解析缓存影响后续生产编译。没有新增依赖，锁文件保持原样。

## 项目理解与后续开发入口

这是本次基线走读的结论；持续维护的架构与工程真源仍是[系统架构](../../../develop/architecture.md)、[后端规范](../../../develop/backend.md)、[前端规范](../../../develop/frontend.md)与[本地启动](../../../develop/local-development.md)。

| 部分 | 当前职责 |
| --- | --- |
| `backend/` | Laravel 12 / PHP 8.3 API、账务、履约、调度与插件。API 按 site/admin/client/open/zjmf 分层。 |
| `frontend-admin-v3/` | Vue / TypeScript / TDesign 管理端。 |
| `frontend-user-v3-www/` | Vue / Element Plus 商家官网与商品展示，认证入口导向控制台。 |
| `frontend-user-v4-console/` | Vue / TypeScript / TDesign 用户控制台。 |
| `shared/` | 跨端 HTTP、会话、状态、计费周期、组件、多语言与构建能力。 |
| `site-web/`、`docs-web/`、`demo/` | 分别是项目宣传官网、文档站与静态演示站，均有实际用途。 |

- 新购实际为 Invoice-first：`ClientInvoicePaymentWorkflowService` 调用 `CheckoutService` 创建账单和内部履约订单；报价、幂等、库存与锁是该链路的关键约束。用户侧以账单作为主要交易凭据。
- 余额与第三方支付分别通过账户流水和 Payment 记录管理。支付、退款与续费修改前，应先阅读现有 Service 和对应测试，保持事务、幂等与审计约束。
- 支付完成由 `PaidOrderBusinessFlowDispatcher` 调度履约；`ProcessPaidOrderFulfillmentJob` 包含唯一性、提交后派发和不可自动重试情形，不能用无条件重试代替现有分支。
- 上游能力由插件清单、启用状态和 ProviderRegistry 解析；商品只有三级分组，业务类型属于一级分组属性。
- 调度心跳、槽位去重与独立队列消费共同工作。空库需要先导入 schema baseline 再叠加增量迁移，不能仅凭 migrate 成功判断初始化完成。
- 文档与代码冲突时以代码和测试为准。例如旧 README 的密码迁移描述与当前登录服务对非 bcrypt 密码要求重置的实现存在差异。

## 验证结果

验证日期为 2026-11-25。使用仓库锁文件安装依赖，Node 为 v24.22.3。所有生产构建采用相同本地 API 与站点地址配置。

| 检查 | 结果 |
| --- | --- |
| shared 全量测试 | 29 项通过，包含真实 Vite 构建测试。 |
| 官网源码检查 CLI 测试 | 4 项通过；真实官网源码检查通过。 |
| shared 与三端类型检查 | 全部通过。 |
| 三端生产构建 | 全部通过；管理端原有动态/静态重复导入提示仍存在。 |
| 官网 `verify:refactor` | 通过，实际执行源码检查与生产构建。 |
| 受改源码 ESLint | 共享模块、声明、测试、三端 Vite 配置、两端路由与官网脚本通过。 |
| 镜像构建上下文 | 3 前端、21 处跨目录引用、19 条 COPY 校验通过。 |
| 压缩文件 | 315 个 gzip 与 315 个 Brotli 副本均小于原文件，解压后与原文件一致。 |
| 清理前后构建产物 | 三端全部 1,042 个文件相对路径、文件内容 SHA-256 均一致，包含 HTML、JS、CSS、静态文件和预压缩副本。 |
| 浏览器回归 | 管理端 133/138、控制台 52/53、官网 57/58 通过，使用生产产物和 Mock API。 |
| 文档检查 | 与修改前 stdout/stderr 和退出码完全一致，仅有既有译文问题。 |
| `git diff --check` | 通过。 |

### 原版对照与既有失败

从 HEAD 导出独立临时源码快照，使用同一份锁定依赖和构建环境。对每个失败场景在原版重跑，断言失败一致；没有将这些用例标记跳过或修改业务代码使其表面通过。

| 检查 | 既有失败 |
| --- | --- |
| admin `login-remember-account.spec.ts` | 3 项登录后仍停留登录页的断言失败；原版相同。 |
| admin `plugin-menu-gating.spec.ts` | 2 项插件菜单与直接访问重定向断言失败；原版相同。 |
| console `service-console-area-preload.spec.ts` | 显式关闭预热时 iframe 数量期望 1，实际 0；原版相同。 |
| www `product-detail-config.spec.ts` | 移动端地区选择被 Cookie 提示层拦截点击；原版相同。 |
| console `verify:refactor` | 12 个未改动文件含固定 px，违反该脚本规则；这些文件与 HEAD 无差异。 |
| `docs:check` | guide/index、guide/deploy/index、guide/deploy/generic 的英文与两种繁体译文存在过期或结构差异，共涉及 9 个页面。 |

### 本机验证限制

首批验证时，PHP、Composer、MySQL 和 Redis 尚未配置为项目可用的测试环境，后端 `.env`、vendor 也尚未准备，因此当时未运行 Laravel 测试、数据库迁移或真实后端联调。后续按用户授权扫描到 D:/CodexDbTools 中的独立工具并补齐环境，见[本机后端测试环境与回归](backend-local-test-environment-2026-11-25.md)。浏览器测试使用 Mock API，不能替代真实支付、邮件、上游开通和队列验证。本批没有修改后端、数据库结构、账务代码、API 契约或锁定依赖；产物完全一致的结论适用于本次使用的构建配置，不等于证明项目所有既有问题均已消除。

## 风险与回滚

共享构建模块会影响静态资源部署，因此以压缩解压一致性和清理前后完整产物对照作为主要验收证据。运行参数改为自定义 outDir 时，预压缩现在跟随最终输出目录，其行为由集成测试覆盖。回滚以本批文件差异为单位恢复，不覆盖其他工作区内容。本批按用户确认作为独立提交保存；发布与部署另按用户指令执行。

## 进度

- [x] 拉取完整仓库并检出跟踪 origin/main 的 main。
- [x] 走读架构、鉴权、交易、履约、上游插件、调度与前端入口。
- [x] 记录变更前共享测试与文档校验基线。
- [x] 构建共享化、无调用方代码清理与官网验证脚本修复。
- [x] 运行三端生产构建、类型检查与完整桌面浏览器套件。
- [x] 独立原版复现失败项，逐文件对照全部构建产物。
- [x] 归档实现、验证结果与本机运行限制。

## 决策日志

| 日期 | 决策 | 原因 |
| --- | --- | --- |
| 2026-11-25 | 首批选择构建去重和确定无调用方的遗留代码 | 具有直接引用证据，且可用实际构建和浏览器测试验证。 |
| 2026-11-25 | 保留 glob 路由、类型声明和被导入样式 | 文件名无显式引用不代表运行时无用途。 |
| 2026-11-25 | 修复官网回归脚本并补 CLI 用例 | 原先 Windows 下源码检查可能假通过，无法支撑不影响功能的验收。 |
| 2026-11-25 | 比较独立原版全部构建文件并复现失败项 | 比单纯构建成功更直接地验证本批清理保持运行时代码不变。 |
| 2026-11-25 | 本批保留资金和履约服务边界 | 当前本机后端环境不足以验证高风险业务重构，后续应按对应业务计划在可联调环境开展。 |
