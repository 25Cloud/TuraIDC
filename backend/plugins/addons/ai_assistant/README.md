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

## 4. 商品类工具的返回口径（导购）

AI 被当成真人售前用，不是规格表朗读器。三个商品类工具
（`search_products` / `get_product_detail` / `quote_price`）共用
`src/Services/Tools/Concerns/DescribesProductForSale.php` 输出同一份「可售卖信息」：

| 字段 | 说明 |
| --- | --- |
| `title` | 给用户看的完整叫法：`区域 · 机房/线路 · 规格`（如 `华中 · 湖北襄阳 电信 · 2核2G20Mbps`） |
| `region` / `site` / `spec` | 二级分组名 / 三级分组名 / 规格摘要，模型据此回答「哪个区」 |
| `group_path` | 一级 / 二级 / 三级分组全路径 |
| `product_url` | 官网配置/购买页 `/products/{一级slug}/{二级id}/{三级id}/{商品id}` |
| `group_url` | 该三级分组目录页（用户想看同类时给这个） |
| `link` | 现成的 `[title](product_url)`，模型照抄不会写错地址 |

为什么必须这么返回：`products.custom_display_name` 只存规范规格摘要（`2核2G20Mbps`），
区域与机房信息全在分组名里。只把 `name` 交给模型，模型只能说「2核2G20M」，用户根本
不知道是哪台机器；不给链接，用户看完还得自己翻目录找回去。

地址口径必须与官网保持一致（`frontend-user-v3-www/src/utils/productRoute.js`）。
缺一级 slug（老数据）时退回官网仍兼容的 `/products/{商品id}`，不拼会 404 的地址；
`FRONTEND_URL` 未配置或格式非法时退回相对路径（`PublicUrl` 会抛 `LogicException`，
但站点地址没配不该让「查商品」整体失败）。

`search_products` 的 `keyword` 同时匹配**商品规格**与**分组名**——「有没有香港的」
这类问句里没有一个字出现在商品名上，只扫商品名会永远搜不到；命中分组名时改用
分组 id 过滤重新查一次（最多展开 3 个分组）。

## 5. 数据表：在核心，不在插件

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

清理走**插件清单声明的定时任务** `src/Automation/AiPruneConversationsTask.php`
（`info.extra.scheduled_tasks`，cron `30 4 * * *`，心跳 15 分钟槽位派发）：
超过保留期的活跃会话先软删，软删满 7 天的会话连消息与工具调用一并物理删除。
清理逻辑只有一份（`Services/AiConversationPruner`），运维命令
`ai:prune-conversations`（`--dry-run` 只统计不删除）与它共用。

之所以不放在核心 `routes/console.php`：核心 schedule 插件命令的话，插件停用/卸载后
那条 Schedule 还在，每天必然「命令不存在」；走清单声明只有已启用的插件会被
`PluginScheduledTaskProvider` 扫到，插件不在，任务自然消失。

## 6. 安装、启用与升级

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

## 7. 边界（改动前先看）

- 不注册全局中间件、不注册调度任务、不改核心路由文件；路由只挂在自己的 `ai/` 前缀下。
- **入口收口在核心，用 `App\Support\OptionalFeature` 判定**：管理端页面托管在核心
  （插件不能注册菜单），核心通过 `OptionalFeature::map()` 得到 `ai_assistant` 开关，
  由管理端登录接口下发 `features`，前端侧栏分组与 `/admin/ai/*` 路由的 `meta.requiresFeature`
  据此隐藏。停用或卸载后应当看到的现象是：侧栏没有「AI 助手」、`/admin/ai/*` 直接访问被
  退回首页、调度里没有本插件的任务——**不是一个点进去全是 404 的菜单**。
- **周期能力只走清单**：`info.extra.scheduled_tasks` 已声明 `AiPruneConversationsTask`。
  核心 `routes/console.php` 不再出现本插件的任何命令，新增周期任务时也照此办理，
  不要回头去核心加 `Schedule::command()`。
- 不把 API Key 写进 `config.php` 或插件配置明文；密钥只在 `settings` 组 `ai`（加密列）。
- 不搬表、不改表名、不删列。
- 不新增第二套 AI 配置真源（新增配置项请加在 `settings` 组 `ai` + 管理端设置页）。
- `ai:*` 命令名保持稳定：外部调度与文档按命令名引用。
