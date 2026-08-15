# 后端工程规范

本文档定义 TuraIDC Laravel 12 后端后续人工开发、审查、重构和第三方集成治理的长期标准。目标是让后端代码可读、可测、可替换、可追踪，并降低后续人工维护成本。

重要约束：当前开发文档、开发规范和其他约束性文档不具备完全真实性，不保证完全真实。后续治理必须以当前代码检查、真实路由、可观察运行行为和必要的受控验证为主，发现文档与当前代码冲突时，应先记录冲突，再按检查结论更新补充文档。

治理执行原则：审计要覆盖关键目录和风险域，但修改只落在有价值文件上；不得为了“覆盖”机械加注释、机械格式化或制造无意义改动。自动生成的 `docs/自动生成/接口/后端API清单.md` 不手工编辑。

## 1. 治理目标

1. Controller 保持薄层，业务逻辑收敛到 Service、Action、Domain Service 或专用集成客户端。
2. 参数验证、响应转换、业务流程、第三方协议、日志审计各自有清晰边界。
3. API 请求和响应工程化，避免参数归一化、响应字段转换、错误映射散落在 Controller 或第三方驱动里。
4. 支付、实名认证、ZJMF 财务/ZJMF 云、短信、邮件、对象存储等第三方能力以插件/驱动方式接入，业务层依赖项目内部接口而不是具体供应商。
5. 所有高风险业务具备可测试入口，包括支付回调、余额扣减、服务开通、上游失败、权限校验。
6. 必要注释解释业务原因、边界条件、第三方协议差异和历史兼容，不写无意义的逐行翻译。
7. 后端注释、API 错误 `message`、字段校验错误和前端用户可见提示必须使用简体中文。
8. 建立审查闭环，防止错审、漏审和审完不报。
9. 规则变化必须同步项目文档和 TuraIDC 相关 skills，避免后续代理和人工开发继续沿用旧约定。

## 2. 非目标

1. 不做一次性大重写。
2. 不为了抽象破坏当前稳定支付、账单、服务实例、回调链路。
3. 不未经确认执行数据库初始化、迁移或历史迁移补跑。
4. 不手工编辑自动生成的 `docs/自动生成/接口/后端API清单.md`。
5. 不把所有第三方能力塞进一个万能接口。

## 3. 后端分层标准

### Controller

- 只负责接收请求、鉴权上下文、调用应用服务、返回响应。
- 不直接拼装第三方请求。
- 不直接调用 `Http::*`。
- 不写复杂业务分支。
- 不在 Controller 中散落事务。

### FormRequest

- 请求参数、类型、范围、枚举值、业务前置约束优先放入 FormRequest。
- 复杂校验可以委托 Rule 或 Service，但 Controller 不应手写 validate。

### Resource

- 负责 API 输出结构转换。
- 不承载业务判断。
- 不直接访问第三方或触发写操作。

### Service / Action

- Service 按业务领域聚合，例如账单、支付、余额、服务实例、上游集成。
- 单次清晰业务动作可抽为 Action，避免一个 Service 无限膨胀。
- 涉及财务、审计、状态流转的 Service 必须考虑事务、幂等、审计字段和并发锁。

### Model

- Model 保持数据模型、关联、scope、cast、轻量领域状态判断。
- 不放跨表复杂流程。
- `$fillable` 必须精确，不开放敏感字段批量赋值。

### Job / Listener / Command

- 只做异步调度和流程编排，具体业务逻辑委托 Service。
- Job 必须明确 `$tries`、`$timeout`、`$backoff` 或说明不需要的原因。
- 调度任务涉及并发时使用 `withoutOverlapping()`。

## 4. 第三方插件化标准

第三方能力按“能力域接口 + Provider 驱动 + DTO + 统一异常 + 配置注册 + fake provider 测试替身”治理。

### 推荐目录

```text
backend/app/Contracts/Integrations/
backend/app/Services/Integrations/
backend/app/Services/Integrations/Payments/
backend/app/Services/Integrations/Identity/
backend/app/Services/Integrations/Upstream/
backend/app/Services/Integrations/Notifications/
backend/app/Services/Integrations/Storage/
backend/app/Services/Integrations/Drivers/
backend/config/integrations.php
backend/tests/Fakes/Integrations/
```

### 推荐接口

- `PaymentGatewayInterface`：支付创建、查询、关闭、回调验签、回调解析。
- `RefundGatewayInterface`：退款申请、退款查询、退款回调解析。
- `IdentityVerificationProviderInterface`：实名二要素、三要素或供应商支持的实名能力。
- `UpstreamProviderInterface`：ZJMF 财务/ZJMF 云等上游开通、续费、暂停、重启、同步。
- `SmsProviderInterface`：短信发送、模板校验、发送结果查询。
- `ObjectStorageProviderInterface`：上传、下载、临时链接、删除。

### 强制要求

1. 业务 Service 依赖项目内部接口，不依赖具体 SDK 或供应商字段。
2. Provider 原始响应必须转换为项目内部 DTO。
3. Provider 异常必须映射为统一业务异常。
4. 回调必须先通过统一入口处理签名、日志、幂等，再分发到具体 provider。
5. 日志必须包含 trace_id、provider、能力类型、资源 ID、结果状态，并对敏感字段脱敏。
6. 每个 Provider 必须声明能力，例如是否支持退款、实名三要素、服务暂停、续费、重试。
7. 必须提供 fake provider 或 mock gateway，用于自动化测试和本地开发。
8. 允许旧 Provider 和新 Provider 并存，历史记录按原 provider 解释，新请求可按配置切换。

### 当前落地进度

- 插件运行时已落地 `IntegrationPluginService`、`PluginInstaller`、`PluginScanner`、`PluginRuntimeRegistry`、`PluginConfigRepository` 和能力域 adapter。
- 插件文件位于 `backend/plugins/{domain}/{slug}/`，当前能力域包含支付网关、实名认证、短信、邮件、上游服务器。
- 插件配置由 `integration_plugins`、`integration_plugin_configs` 管理；敏感配置必须加密保存，API 只返回是否已配置和脱敏预览。
- 支付网关已通过 `PaymentGatewayInterface`、`PaymentGatewayManager` 和插件支付适配器接入；`payments.gateway` 仍保留历史业务值，网关插件 key 与业务 gateway 值不得混用。
- 后续新增支付方式必须新增插件或 driver，并通过插件运行时注册，不得在 `PaymentService` 或 Controller 中直接注入具体 provider。
- 实名认证能力通过插件实名适配器接入，初始化、扫码链接、查询结果、回调验签、收费配置必须通过内部 DTO/接口表达，业务层不得依赖供应商原始数组。
- 短信、邮件能力通过插件适配器接入，供应商英文或技术类错误必须映射为简体中文业务提示。
- 上游 provider 已通过能力对象声明服务开通、续费、控制台、网络、安全组、状态同步等能力；`zjmf_finance_api` 必须继续解析到ZJMF插件 adapter，不得折叠为共享主机面板 transport。
- ZJMF 财务/ZJMF 云和主机面板缓存 key 必须 provider-aware；是否保留旧 key 读取只能由当前已批准的执行计划明确，禁止把 `zjmf_finance_api` 归一化为 `hosting_panel_api`。
- 对象存储等尚未插件化的能力域接入前，必须先补内部 contract、DTO、错误映射、fake provider 或插件测试替身。

### 禁止做法

- Controller 直接选择支付宝、微信、ZJMF 财务等 provider。
- 业务表直接保存不可解释的第三方原始响应作为唯一状态来源。
- 一个万能 `PluginInterface` 覆盖支付、实名、上游、短信、存储所有能力。
- 没有回调幂等和回归测试就切换真实供应商。

## 5. API 请求与响应工程化

API 治理必须同时覆盖请求入口和响应出口。新增或重构接口时，以 `docs/参考资料/接口/API格式规范.md` 为当前 API 规范文档，并以当前代码检查结果反向补充该文档。

### 请求侧

- 复杂接口必须使用 FormRequest，Controller 不应手写大量参数读取和类型转换。
- 请求字段统一 `snake_case`。
- 分页统一 `page`、`page_size`。
- 排序、筛选、布尔、枚举、金额、日期范围必须在 FormRequest 或 Request DTO 中归一化。
- 金额必须明确单位，支付/余额相关不得隐式使用 float。
- 跨 Service 传递的请求参数推荐转为 Command/DTO。

### 响应侧

- Controller 使用统一响应方法或抛出统一业务异常。
- Service 不直接返回 HTTP Response。
- 面向前端字段由 Resource 或 Response DTO 控制。
- 第三方原始响应不得直接穿透到 Controller 或前端。
- 敏感字段默认不返回。
- 错误响应必须统一业务码、HTTP 状态码和 `message`。
- 兼容字段必须说明保留原因和移除条件。

### 文档同步

- API 请求/响应规则变化时，优先更新 `docs/参考资料/接口/API格式规范.md`。
- 路由清单变化时按脚本重刷 `docs/自动生成/接口/后端API清单.md`，禁止手工编辑该文件。
- 影响前端字段时，必须同步前端调用和测试建议。

## 6. 注释标准

### 必须注释

- 第三方协议差异和非显然字段映射。
- 财务、账单、支付、余额、服务实例状态流转中的关键业务约束。
- 幂等键、锁、事务边界的设计原因。
- 为兼容历史数据而保留的特殊逻辑。
- public interface、DTO、关键 Service public 方法、Job 的业务目的和副作用。
- 所有新增注释优先使用简体中文，便于后续人工维护。

### 不建议注释

- “给变量赋值”“调用某方法”这类代码本身已经表达清楚的内容。
- 与代码不同步的长段背景说明。
- 包含密钥、token、真实账号、供应商私密参数的内容。
- 面向维护者的中文注释中夹杂未解释的英文业务缩写。

### 推荐注释形式

- 接口和 DTO 用 DocBlock 说明输入输出语义。
- 复杂业务分支前用 1 到 3 行说明“为什么这样做”。
- 历史兼容逻辑标注触发条件和移除前提。

## 7. 错误、日志与审计

- API 响应使用统一错误结构。
- 成功响应固定 `code = 0`。
- API 返回的 `message` 和 `data.errors` 中用户可见错误必须使用简体中文。
- 不把第三方原始错误直接暴露给用户，必须映射为项目内部简体中文提示。
- 关键业务日志必须可串联 trace_id、actor、resource、provider、operation。
- 日志不得输出 secret、token、password、私钥、完整证件号。
- 财务和审计相关写入必须记录操作者、来源、IP、trace_id 或等价字段。
- 设置、供应商、支付、实名、上游等配置和凭据输出必须使用白名单 Resource/DTO；`api_key`、secret、token、私钥不得出现在 API 响应。
- 敏感配置更新采用空值保留、非空替换，不能用掩码值或空字符串覆盖真实配置。
- 插件配置中的敏感项必须走加密存储和脱敏预览；更新时空值表示保留旧值，不得覆盖为掩码或空字符串。
- `payments` 表为财务审计边界，任何维护命令、清理命令和测试不得物理删除 Payment 记录；只能输出只读报告、标记异常或跳过处理。
- `balance_logs` 等财务流水也属于审计链路，不得用物理删除掩盖撤销、恢复或退款，必须使用反向流水、状态投影或明确补偿状态。
- 支付宝原路退款、余额退款、混合支付余额恢复必须同步账单退款投影字段，收入统计必须排除或抵扣已退款账单。
- 支付和实名回调必须在路由层先过签名中间件，Service 层保留二次验签、金额/状态校验、幂等和事务锁。
- VNC、支付、实名、上游等日志必须使用统一脱敏工具或专用 `mask*` 方法处理 token、sign、password、secret。
- 公开 VNC token 必须一次性消费、短 TTL、绑定必要上下文，不得在公开解析响应中返回 VNC 密码、原始 token 或完整 URL。

## 8. 审查闭环与防漏审

后端体检、重构审查和第三方集成审查必须输出明确报告，不允许只做内部检查后不报告。

强制要求：

- 输出审查范围矩阵，列出目录、模块、接口、风险域、是否已覆盖。
- 每个 Blocker / High 必须有文件路径、方法名、路由或命令证据。
- 每个审查维度必须标注“已检查 / 未覆盖 / 需进一步验证”。
- 未覆盖项必须说明原因、风险和后续补查建议。
- 审查发现必须按严重级别排序并给修复优先级。
- 发现无问题的维度也必须写“未发现问题”和检查依据。
- 报告末尾必须包含完整性自检表。

完整性自检表至少检查：

- 是否读取 `AGENTS.md`。
- 是否以当前代码和真实路由为主。
- 是否检查 HARD-GATE。
- 是否检查 API 请求/响应工程化。
- 是否检查第三方插件化。
- 是否检查简体中文体验。
- 是否列出未覆盖项。
- 是否给出修复优先级。
- 是否给出文档/skills 同步建议。

## 9. 测试标准

必须优先补齐：

- 支付创建、回调、重复回调、失败回调。
- 余额扣减、并发扣减、退款或撤销。
- 实名认证成功、失败、供应商异常、字段异常。
- ZJMF 财务/ZJMF 云上游开通、续费、暂停、重启、同步失败。
- 权限码缺失、水平越权、用户资源归属。
- Provider fake 驱动下的本地可重复测试。
- 敏感配置读取/编辑不泄露原值。
- 供应商列表、详情、创建、更新响应不包含 `api_key` 原值。
- 公开 VNC token 不返回密码、重复消费失效、过期失效、日志脱敏。
- 用户端账单详情不返回管理端内部字段和第三方交易号。
- 维护命令不会删除 `payments`。
- 余额恢复和财务反转不会删除原始 `balance_logs`，保留反向流水或补偿审计记录。
- 支付原路退款覆盖成功、失败、重复退款、金额不一致和事务回滚。
- 收入统计不把已退款账单计入正向收入。
- 关键 Job 失败不吞异常，能够进入重试、failed_jobs 或明确补偿状态。

后端改动默认执行：

```cmd
cd backend
php artisan test
```

如全量测试存在历史基线失败，必须补充 targeted test，并明确本次改动是否新增失败。

运行后端测试前，必须确认测试库隔离生效：`APP_ENV=testing`，测试连接不得指向真实业务库，非 sqlite 测试库名应包含 `test`。未完成隔离前不得运行全量测试。

## 10. 文档与 skills 同步

触发以下变化时，必须同步项目文档和 TuraIDC 相关 skills：

- 新增或调整后端分层规则。
- 新增第三方 provider 或调整 provider 接入方式。
- 修改支付、实名、ZJMF 财务/ZJMF 云、短信、对象存储等集成边界。
- 修改 API 响应格式、错误码、分页结构、权限码。
- 修改 API 请求参数、响应字段、Resource、FormRequest、Request DTO、Response DTO。
- 修改 API 错误 `message`、前端用户可见提示、字段校验错误等简体中文体验规则。
- 修改审查闭环、防漏审、防审完不报规则。
- 修改队列、调度、回调、部署假设。
- 新增必须遵守的注释、日志、测试规则。
- 当前代码检查结果证明既有开发文档、开发规范或约束文档已经失真。

同步位置：

- 项目规则：`AGENTS.md`，仅在规则升级且确认为仓库级硬约束时更新。
- 后端文档：`docs/BACKEND.md`。
- API 格式：`docs/参考资料/接口/API格式规范.md`。
- API 分组导航：`docs/参考资料/接口/API清单导航.md`。
- TuraIDC 主技能：`C:/Users/USER125536/.agents/skills/turaidc/SKILL.md`。
- TuraIDC 后端审查技能：`C:/Users/USER125536/.agents/skills/turaidc/code-review/SKILL.md`。
- TuraIDC reference：`C:/Users/USER125536/.agents/skills/turaidc/references/11-backend-engineering-governance.md`。

禁止手工编辑：

- `docs/自动生成/接口/后端API清单.md`，该文件由脚本生成。

同步原则：

1. 当前代码检查结论优先于旧文档描述。
2. 文档更新必须标明新的真实约束、适用范围和不再可信的旧口径。
3. 不确定的内容标注“需进一步验证”，不要为了补齐文档而编造规则。
4. 对规则类文档的更新必须尽量小而准，避免把临时实现误写成长期规范。
