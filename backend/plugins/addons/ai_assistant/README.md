# AI 助手插件（addons / ai_assistant）

站内 AI 助手：对话、工具调用与权限分级。能力域 `addons`，插件标识 `ai_assistant`。

本插件是把**原本散在核心 `app/` 与 `routes/` 里的整套 AI 能力搬进插件**的结果：
控制器、请求校验、网关与三协议驱动、工具注册表、模型、运维命令、运行时配置默认值
现在全部收敛在 `backend/plugins/addons/ai_assistant/`，**API 路径、方法与中间件逐条保持不变**，
三端前端（管理端 / 用户控制台 / 官网）没有任何改动。

## 1. 目录结构

```text
backend/plugins/addons/ai_assistant/
├── AiAssistantPlugin.php                     # 入口类，execute() 提供 ai.status / ai.tools
├── config.php                                # 插件清单（manifest_version=2）+ 配置 schema 说明
├── README.md
├── config/ai.php                             # 运行时默认值，合并进配置键 ai_gateway
├── logic/AiAssistantStatus.php               # 插件级只读自检（状态、工具清单）
├── routes/client.php                         # /ai/*（客户端）
├── routes/admin.php                          # /ai/*（管理端，含 permission 分组）
└── src/
    ├── AiAssistantLifecycle.php              # 生命周期五方法（幂等）
    ├── Providers/AiAssistantServiceProvider.php
    ├── Console/{AiDoctorCommand,AiPruneConversationsCommand}.php
    ├── Contracts/AiProviderInterface.php
    ├── Http/Controllers/{Client,Admin}/
    ├── Http/Requests/{Client,Admin}/
    ├── Models/{AiConversation,AiMessage,AiToolInvocation,AiUsageDaily}.php
    └── Services/                             # AiConfig、聊天编排、额度、脱敏、工具、三协议驱动
```

## 2. 路由（与拆分前完全一致）

外层前缀与中间件由 `AiAssistantServiceProvider` 挂载：
客户端 `api/v2/client` + `['api','auth:sanctum','ensure.client']`；
管理端 `api/v2/admin` + `['api','auth:sanctum','ensure.admin']`。

| 方法 | 路径 | 追加中间件 |
| --- | --- | --- |
| GET | `/api/v2/client/ai/bootstrap` | — |
| GET | `/api/v2/client/ai/conversations` | — |
| POST | `/api/v2/client/ai/conversations` | `throttle:20,1,client-ai-conversation-store` |
| GET | `/api/v2/client/ai/conversations/{conversation}` | — |
| PATCH | `/api/v2/client/ai/conversations/{conversation}` | — |
| DELETE | `/api/v2/client/ai/conversations/{conversation}` | — |
| POST | `/api/v2/client/ai/messages` | `throttle:20,1,client-ai-message` |
| POST | `/api/v2/client/ai/messages/stream` | `throttle:10,1,client-ai-stream` |
| POST | `/api/v2/client/ai/actions/{action}/confirm` | `throttle:30,1,client-ai-confirm` |
| POST | `/api/v2/client/ai/actions/{action}/reject` | `throttle:30,1,client-ai-reject` |
| GET | `/api/v2/admin/ai/usage` | `permission:ai.view` |
| GET | `/api/v2/admin/ai/conversations` | `permission:ai.view` |
| GET | `/api/v2/admin/ai/conversations/{conversation}` | `permission:ai.view` |
| GET | `/api/v2/admin/ai/settings` | `permission:ai.view` |
| PUT | `/api/v2/admin/ai/settings` | `permission:ai.manage` |
| POST | `/api/v2/admin/ai/settings/test` | `permission:ai.manage` |

前端只认这些路径，**不要为了“插件化”去改前缀**；需要新路径时同样挂在 `ai/` 之下。

## 3. 配置

两层，职责不同，不互相复制：

1. **运行时默认值**：`config/ai.php`，由 Provider `mergeConfigFrom(..., 'ai_gateway')` 合并进
   `ai_gateway` 配置键。沿用旧键名是为了让站长 `.env` 里既有的 `AI_GATEWAY_*`
   （协议、超时、工具轮数、上下文条数、确认令牌有效期）继续生效。
2. **业务配置**：`settings` 表 `group=ai`，由管理端「AI 设置」页读写（`AI 设置` 是唯一编辑入口）。
   包含 `enabled`、`protocol`、`base_url`、`model`、`provider_api_key`（加密）、
   三端入口开关、额度与熔断、工具白名单、权限档位、保留天数等。

本插件**不在 `config.php` 的 `config` 里重复声明这批字段**：插件配置表
（`integration_plugin_configs`）与 `settings` 组 `ai` 同时存在会造成两份真源，
而前端与管理端接口读的是后者。

插件入口 `execute()` 支持两个只读动作，供插件管理页试运行与运维排查：

- `ai.status`：是否已配置、协议、模型、是否有密钥、三端开关、额度与权限档位、工具数量。
- `ai.tools`：按 `payload.permission_mode` 返回当前档位可用工具（名称 / 风险 / 说明）。

## 4. 数据表：在核心，不在插件

四张表由**核心迁移**创建，插件只读写、不建表、不改表：

| 表 | 说明 |
| --- | --- |
| `ai_conversations` | 会话（所属用户、渠道、权限档位、消息数与 token 汇总） |
| `ai_messages` | 消息（含 `reasoning` 思考过程） |
| `ai_tool_invocations` | 工具调用记录（确认协议载体、审计凭据） |
| `ai_usage_daily` | 按天用量与成本，额度熔断依据 |

原因：这些表在老实例里已经有生产数据，且 `database/schema/mysql-schema.sql`
与运维审计命令都按核心表名读取。插件标准 v2 允许“复用核心表”，
搬表只会带来迁移风险，没有任何收益。

清理策略走命令 `ai:prune-conversations`（`--dry-run` 只统计不删除），
由核心 `routes/console.php` 每天 04:40 调度；命令实现随插件一起搬走，命令名不变。

## 5. 安装、启用与升级

**新装 / 老实例升级**：核心迁移 `2026_10_27_000001_register_ai_assistant_plugin`
会在 `php artisan migrate` 时自动完成内置登记：`install()` 写/更新 `integration_plugins`
登记行 → 首次登记调生命周期 `install()` → 未启用则 `enable()`（同时激活 Provider 注册路由）。
整个过程幂等，重复执行不会产生第二行登记。

之所以必须自动启用：路由已经在插件 Provider 里，插件未启用时
`/api/v2/{client,admin}/ai/*` 会 404，而前端约定路径不能变。
`addons` 域不是“单启用域”（`PluginDomain::singleEnabledDomains()` 不含 `addons`），
因此与 `zjmf_bridge` 等其它 addon 可以同时启用。

**后续版本升级**：清单 `version` 升高时，登记迁移会调用
`PluginLifecycleRunner::upgrade()` → `AiAssistantLifecycle::upgrade()`；
平台侧通用生命周期调用（安装/启用/停用/卸载走 `PluginInstaller`）属于插件标准 v2 的
平台运行时改造，本插件已按接口实现，等平台接上即可自动生效。

**生命周期五方法**（`src/AiAssistantLifecycle.php`，全部幂等）：

| 方法 | 行为 |
| --- | --- |
| `install` | 自检 `ai_*` 四张表是否存在，写一条安装日志 |
| `enable` | 自检表 + 未配置网关时告警，写启用日志 |
| `disable` | 只写日志，不清理会话与用量 |
| `uninstall` | 只写日志，**不删表**（需要清理时由站长手动执行 SQL） |
| `upgrade` | 自检表 + 记录 `from/to` 版本 |

缺表只告警不抛异常：插件安装不该因为核心迁移没跑而整体失败，补跑 `migrate` 后重新启用即可。

## 6. 边界（改动前先看）

- 不注册全局中间件、不注册调度任务、不改核心路由文件；路由只挂在自己的 `ai/` 前缀下。
- 不把 API Key 写进 `config.php` 或插件配置明文；密钥只在 `settings` 组 `ai`（加密列）。
- 不搬表、不改表名、不删列。
- 不新增第二套 AI 配置真源（新增配置项请加在 `settings` 组 `ai` + 管理端设置页）。
- `ai:*` 命令名保持稳定：外部调度与文档按命令名引用。
