---
status: 进行中
updated: 2026-08-12
owner: backend-platform
---

# 定时任务体系重构方案（2026-08-09）

- 文档性质：参照 ZJMF v10.4.6 的定时任务设计，对 turaidc 现有 Laravel 12 心跳、队列、运行台账、钩子和后台管理能力做差距审计，并形成分阶段实施计划。
- 参照真源：[Zjmf 定时任务系统解析](../../designs/backend/zjmf-scheduler-analysis.md)。原始代码范围为 `ZJMF-CBAP-10.4.6/10.4.6/app/command/Cron.php`、`Task.php`、`TaskWaitModel.php`、`TaskModel.php` 与 `app/common.php`。
- turaidc 现状依据：`backend/routes/console.php`、`backend/app/Services/Automation/`、`backend/app/Jobs/RunHeartbeatTaskJob.php`、管理端调度路由及[部署与调度指南](../../references/operations/deployment-and-scheduling.md)。
- 本计划原始交付边界是方案与实施门禁，不包含生产调度或队列配置变更；后续经明确重构指令落地的第一批代码、迁移和验证结果仅在“进度”中登记，工作区其他未提交改动不属于本计划。

## 背景与基线

### ZJMF 参照模型

ZJMF v10.4.6 将“调度判断”和“耗时执行”分离：`Cron` 每分钟进入，使用 `configuration` 表中的时间戳和锁键分发分钟、五分钟和每日任务；`add_task` 同时写入长期 `task` 台账与短生命周期 `task_wait` 队列；`Task` 消费者通过文件锁、事务取数、`FOR UPDATE` 和条件更新（CAS）抢占任务，失败最多重试三次；`TaskController` 提供分页查询和失败任务人工重试；`hook`/`add_hook` 为插件提供周期、入队和消费扩展点。

参照文档还明确了几个行为边界：分钟任务每次调用都执行，五分钟和日任务按 `configuration` 时间戳防重；主机暂停/终止只扫描“到期日窗口”，错过窗口不会自动补处理；续费提醒为两次，逾期提醒为三次，邮件和短信均可入队。

### turaidc 当前运行模型

1. `backend/routes/console.php` 只注册每分钟一次的 `schedule:run -> scheduler:heartbeat`；生产由 `schedule:run` 驱动，心跳同时尝试排空数据库队列。
2. `HeartbeatScheduler::tick()` 使用 `TickSlot::floorToFifteenMinutes()` 将触发归一到 15 分钟槽位，并以 `Cache::lock('scheduler:heartbeat:{slot}')` 做全局互斥；`ScheduleTickRepository` 以槽位唯一性落库。
3. `HeartbeatTaskRegistry` 汇总 `CoreScheduledTaskProvider`、`LegacyScheduleHookTaskProvider`、`PluginScheduledTaskProvider`；`TriggerRuleMatcher` 评估 `CronRule`、`DailyTick` 和 `EveryTicks`。
4. 任务运行记录在 `schedule_task_runs`，当前状态包括 `queued`、`running`、`retrying`、`success`、`failed`、`dispatch_failed`。`ScheduleTaskRunRepository::markRunning()`、`markSucceeded()`、`markRetrying()` 和 `markTerminalFailed()` 使用条件更新保证状态转移的 CAS 语义。
5. `RunHeartbeatTaskJob` 使用 Laravel 队列，`tries=3`、退避 60 秒和 `WithoutOverlapping`；`QueueDrainService` 每分钟并行启动业务队列与 `automation` 队列 Worker，生产没有常驻 Queue Worker。
6. `ScheduleTaskService` 已提供调度总览、任务注册信息、环境快照、队列状态和最近 24 条运行记录；`ScheduleTaskTriggerService` 与 `POST /api/v2/admin/schedule-triggers` 支持权限控制的手动触发。
7. 现有租约回收会把超时的 `queued/running/retrying` 记录标为 `failed`，明确“不自动重跑”，原因是无法仅凭数据库判断业务副作用是否已经发生。

## 目标与范围

### 目标

1. 保留 Laravel 心跳与 Laravel Queue 作为唯一调度/消费基础设施，明确 15 分钟槽位是当前真实执行语义，避免插件把兼容名称误认为真实的 1 分钟或 5 分钟执行。
2. 建立可审计的 ZJMF 钩子兼容层：保留现有 Hook 隔离策略，同时为缺失的入队前后、消费分发和首次续费提醒扩展点定义明确的兼容映射与失败边界。
3. 让 `schedule_task_runs` 成为可检索、可解释的长期运行台账，区分自动重试、最终失败、派发失败、租约回收和人工重跑。
4. 在不自动重放未知业务副作用的前提下，提供受权限保护、带父运行关联和幂等检查的失败任务人工重跑能力。
5. 对续费提醒、账单逾期提醒、到期暂停/终止和补偿扫描规则做逐项产品确认；未确认前不改变现有业务阈值。
6. 将调度健康、队列消费、滞留运行和 Hook 失败纳入运维可观测性及回归测试。

### 非目标

- 不引入 ZJMF 的 `configuration.cron_lock*` 配置表锁键；现有 Cache 分布式锁、数据库唯一约束和队列互斥已经提供更清晰的原子边界。
- 不自建 `task/task_wait` 双表，不复制 ThinkPHP 的数据库消费者；`schedule_task_runs` 继续负责运行台账，Laravel `jobs`/`failed_jobs` 继续负责队列载荷和失败队列。
- 不把每分钟、每五分钟兼容 Hook 改造成真正的 1 分钟或 5 分钟独立 Worker；若产品需要更低延迟，另立容量、成本和调度入口方案。
- 不改变 Laravel Queue 的失败重试机制、队列连接或生产调度配置作为本计划的默认结果；任何配置变更必须在灰度阶段单独审批。
- 不自动重跑租约超时任务，不以“任务失败”推断“业务没有副作用”；人工重跑必须经过权限、状态和幂等检查。
- 不直接照搬 ZJMF 的短信模板刷新、对象存储告警、下游商品同步等业务动作；这些能力只有在 turaidc 已有服务/插件和产品责任人确认后才进入业务规则阶段。
- 不在本计划内执行生产数据修复、迁移回滚、旧队列清理或物理删除。

## ZJMF 机制与 turaidc 现状映射

| ZJMF 机制                                                                                                                                      | turaidc 现状                                                                                                                                                                                                                                      | 差距/结论                                                                                                                                                                                                                                                                                 | 是否需要动作                                                                                         |
| ---------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------- |
| 三层调度：`minuteCron`、`fiveMinuteCron`、`dayCron`                                                                                            | `routes/console.php` 每分钟进入心跳；`HeartbeatScheduler` 将时间归一到 15 分钟槽位；`TriggerRuleMatcher` 支持 Cron、每日序号和每 N 个槽位；`LegacyScheduleHookTaskProvider` 将 `tick.every_minute`、`tick.every_five_minutes` 注册为任务          | 架构上已有“入口、规则、任务注册、队列执行”分层，但真实最小粒度是 15 分钟。兼容名称可能造成插件时效性误判；`ScheduleTaskService` 的 `missed_slot_policy` 已明确为 `strict_current_slot`，错过槽位不补跑                                                                                    | 是。为任务元数据增加“声明频率/有效频率”区分，补充插件文档和告警；是否建设真实 1/5 分钟入口需另行立项 |
| `configuration` 锁键：`cron_lock`、`cron_lock_start_time`、`cron_lock_last_time`、五分钟/日任务时间戳                                          | `HeartbeatScheduler` 使用 `Cache::lock('scheduler:heartbeat:{slot}', 840)`；任务触发使用 `scheduler:task-trigger:{task}`；`QueueDrainService` 使用按 Worker 的队列锁；`schedule_ticks.slot_started_at`、`schedule_task_runs` 唯一键提供数据库幂等 | turaidc 没有配置表锁键，且分布式锁与唯一约束的边界更明确；复制配置锁会引入读改写非原子、锁过期和多时钟问题                                                                                                                                                                                | 否。不新增配置锁；只补充锁后端可用性、降级模式、租约和重复派发监控                                   |
| `task/task_wait` 双表：长期台账与短生命周期等待队列                                                                                            | `schedule_task_runs` 保存槽位、任务、来源、状态、耗时、摘要和错误；Laravel `jobs` 保存待消费载荷，`failed_jobs` 保存最终失败的队列 Job；`ScheduleTaskRunRepository` 负责状态机                                                                    | 分工已经由 Laravel Queue 承担，不需要再建一套 DB 队列。当前运行台账缺少分页过滤、尝试次数/父运行、人工重跑结果等可观测字段                                                                                                                                                                | 不建双表；是，增强现有台账查询和必要的追加字段/索引                                                  |
| CAS 消费：事务内 `FOR UPDATE` 取 10 条，提交后按 `status` 条件更新为 `Exec`                                                                    | Laravel Queue 负责 reserve/可见性；`RunHeartbeatTaskJob` 通过 `HeartbeatTaskRunner` 调用 `markRunning()`，仅 `queued/retrying` 可进入 `running`；`WithoutOverlapping` 做任务级互斥；派发失败为 `dispatch_failed`，后续同槽心跳可复用记录重派      | 消费语义已覆盖，且迟到/重复 Job 在状态 CAS 失败时不会再次执行；缺少高并发、重复投递、Worker 崩溃与租约边界的系统性回归和指标                                                                                                                                                              | 不新增自建消费者；是，补并发测试、迟到 Job 拒绝执行测试和租约/可见性告警                             |
| 失败重试：`task_wait` 失败回 `Wait/Failed`，最多三次；完成/超限记录清理                                                                        | `RunHeartbeatTaskJob::$tries=3`、`backoff=60`；运行台账显式记录 `retrying`、`failed`、`dispatch_failed`，`failed_jobs` 保留失败 Job；成功/失败台账不会自动删除；租约回收只标失败不自动重跑                                                        | turaidc 的审计留存优于 ZJMF，但总览没有展示尝试序号、最终失败原因分类、自动重试与人工重跑关系；没有失败运行手动重跑接口                                                                                                                                                                   | 是。增加运行详情/过滤、重试 lineage 和人工重跑，保持“不自动重跑未知副作用”的安全边界                 |
| Hook 扩展：`minute_cron`、`five_minute_cron`、`daily_cron`、`before_task_create`、`after_task_create`、`task_run`、`before_host_renewal_first` | `ScheduleHookService` 支持系统配置和插件清单监听器，逐监听器隔离异常；已有 `before_cron`、`after_cron`、`task.before`、`task.after`、`task.failed`、`tick.*`、旧 `*_cron` 兼容名称；Provider 只在存在监听器时注册任务                             | 已有的 Hook 命名和失败隔离不能直接等价于 ZJMF：缺少 `before_task_create`、`after_task_create`、`task_run`、`before_host_renewal_first`；`tick.every_minute/every_five_minutes` 实际均 15 分钟触发；`before_daily_cron/after_daily_cron` 是固定 03:00 兼容任务，不等于核心每日账单任务前后 | 是。先做兼容映射表和调用方盘点，再按失败边界新增扩展点；不改变现有 Hook 异常隔离语义                 |
| 后台任务管理：`GET /admin/v1/task` 分页筛选，`PUT /admin/v1/task/:id/retry` 对失败台账重试                                                     | `GET /api/v2/admin/schedules/overview` 返回任务注册、环境、队列和最近 24 条运行；`POST /api/v2/admin/schedule-triggers` 支持按任务手动触发；权限由 `SCHEDULE_VIEW/SCHEDULE_TRIGGER` 控制                                                          | 有总览和手动触发，但没有按任务/状态/时间分页查询 `schedule_task_runs` 的专用 API，也没有仅针对最终失败运行的人工重跑；“最近 24 条”无法支撑审计和故障处理                                                                                                                                  | 是。增加分页查询、运行详情、失败重跑、审计操作记录和权限细分；现有总览保持兼容                       |
| 行为边界：ZJMF 日任务窗口、续费/逾期提醒次数、暂停/终止规则                                                                                    | turaidc `SettingService` 固定续费提醒 `[7,3,1]`，账单逾期提醒默认 `[1,3,5]`；`ServiceLifecycleAutomationService` 默认到期当天暂停、暂停后按保留期终止，采用持续扫描/幂等日志；`BillingAutomationService` 以 `AutomationLog::recordOnce()` 防重复  | 时间点、对象范围、通知渠道和“错过窗口是否补偿”均不等价。turaidc 更偏持续扫描与补偿式处理，不能当作 ZJMF 的当天窗口行为                                                                                                                                                                    | 是。单列产品确认和回归验收，不在技术重构阶段擅自改业务阈值                                           |

## 真实差距与依据

以下差距均来自当前代码或已登记接口，不把“ZJMF 有而 turaidc 没有”直接当成必须复制的功能。

| 差距                                 | 依据                                                                                                                                                                                  | 影响                                                                       | 建议动作                                                                                                                             |
| ------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------ |
| 兼容 Hook 的名义频率与有效频率不一致 | `LegacyScheduleHookTaskProvider::HOOK_TASKS` 对 `tick.every_minute`、`tick.every_five_minutes` 的描述明确写着“当前按每 15 分钟心跳触发”；`TickSlot::floorToFifteenMinutes()` 固化槽位 | 插件可能把低延迟 Hook 用于必须分钟级完成的业务，造成 SLA 误判              | 在任务注册元数据中同时输出 `declared_cadence` 与 `effective_cadence=15m`，并在插件集成文档中声明严格当前槽位策略                     |
| ZJMF 入队生命周期 Hook 缺失          | `ScheduleHookService` 常量没有 `before_task_create`、`after_task_create`；现有 `ScheduleTaskTriggerService` 只负责后台手动触发，不能代表业务入队 Hook                                 | 插件无法在统一入队点补充审计或做通知拦截                                   | 盘点现有 `ScheduleHook` 与插件清单后新增 typed 事件/Hook，明确异常是否阻断入队；默认不阻断已存在的任务链路                           |
| ZJMF 消费分发 Hook 缺失              | `ScheduleHookService` 没有 `task_run`；`HeartbeatTaskRunner` 只按注册任务键执行                                                                                                       | 插件无法承接未注册的自定义消费类型；盲目添加会绕过任务注册、权限和幂等检查 | 先定义扩展任务契约和白名单，未知类型保持失败并进入台账，不使用任意字符串反射                                                         |
| 首次续费提醒前置 Hook 缺失           | ZJMF `Cron::hostDue()` 在第一次提醒入队前触发 `before_host_renewal_first`；turaidc `BillingAutomationService::sendRenewNotices()` 直接按提醒日扫描和记录幂等                          | 需要在首次提醒前做插件补充动作时无统一边界                                 | 若产品确认需要，增加领域事件并保留异常隔离；不把普通 `before_cron` 伪装成领域 Hook                                                   |
| 失败任务没有人工重跑闭环             | `ScheduleTaskRunRepository::reclaimStaleRunsForTask()` 明确“未自动重跑”；`routes/v2-admin.php` 只有 `GET /schedules/overview` 与 `POST /schedule-triggers`                            | 运营只能重新触发任务，无法针对某一次失败运行留痕、关联和复核               | 增加 `GET /schedule-runs`、`GET /schedule-runs/{id}`、`POST /schedule-runs/{id}/retry`，限制终态 `failed`/经确认的 `dispatch_failed` |
| 运行历史查询过窄且状态语义未充分暴露 | `ScheduleTaskService::recentLogs()` 调用 `recentRuns(24)`；序列化只返回时间、级别、任务、状态、耗时、摘要和错误                                                                       | 故障分析无法按任务、来源、状态、时间范围追踪，自动重试和人工重跑无法区分   | 增加过滤/分页、attempt/parent/source 语义、重跑操作日志和失败原因分类；保留现有总览字段兼容                                          |
| 业务提醒规则与 ZJMF 不同             | `SettingService::FIXED_RENEW_NOTICE_DAYS=[7,3,1]`；默认账单逾期 `[1,3,5]`；ZJMF 续费两次、逾期三次并支持邮件+短信                                                                     | 直接“对齐 ZJMF”会改变客户通知频率、渠道和成本                              | 产品确认后再调整；先补规则快照、幂等键和通知渠道测试                                                                                 |
| 到期处理与 ZJMF 窗口不同             | `ServiceLifecycleAutomationService` 以到期阈值和 `expired_suspended_at` 持续扫描，终止按暂停时间计算；ZJMF 只扫描到期日当天窗口                                                       | turaidc 会补处理错过的对象，行为更安全但与旧插件预期可能不同               | 将 `missed_slot_policy=strict_current_slot` 限定为调度槽位，不改变业务扫描的补偿语义；对外在任务摘要中明确                           |

## 目标架构与行为边界

### 运行链路

```text
系统 Cron（每分钟）
  -> Laravel schedule:run
  -> scheduler:heartbeat
  -> 15 分钟 TickSlot + Cache 锁 + schedule_ticks 唯一槽位
  -> Provider 注册任务 + TriggerRuleMatcher 匹配
  -> schedule_task_runs（queued）
  -> Laravel jobs / automation 队列
  -> RunHeartbeatTaskJob + WithoutOverlapping
  -> HeartbeatTaskRunner 状态 CAS
  -> success / retrying / failed / dispatch_failed
  -> 管理端查询、告警或经确认的人工重跑
```

### 状态和重跑原则

- `queued -> running` 只能由 `markRunning()` 的条件更新完成；迟到或重复 Job 发现状态不在 `queued/retrying` 时直接结束，不执行任务副作用。
- `running -> retrying` 仅用于仍在 Laravel Queue 自动尝试范围内的失败；最后一次尝试由 `RunHeartbeatTaskJob::failed()` 或 Runner 明确写入 `failed`。
- `dispatch_failed` 表示队列派发没有完成，不等于业务执行失败；同槽位重派前必须保留原始错误。
- 租约回收只关闭无法证明仍在执行的运行，默认不创建新 Job；人工重跑必须创建新的运行记录或明确的父子关系，不能复用已终态行覆盖历史。
- 任何人工重跑都要记录管理员、原因、原运行 ID、重跑运行 ID、幂等检查结果和最终状态；不允许通过 SQL 直接把 `failed` 改成 `running`。

### Hook 行为边界

- 现有 `ScheduleHookService::run()` 逐监听器捕获异常并返回结果，保持 Hook 失败隔离；新增 Hook 不得默认改变该行为。
- `before_task_create` 若未来采用“可阻断”语义，必须使用显式的 typed result（允许/拒绝/降级）和调用方清单；不能以任意异常在插件中隐式阻断所有任务。
- `task_run` 只能服务于经过注册和权限校验的扩展任务；未知任务类型仍应写入失败台账并告警。
- `tick.every_minute`、`tick.every_five_minutes` 的兼容名称继续保留，但 API 和插件清单必须输出实际 15 分钟频率。

## 分阶段实施计划

### Phase 0：基线、边界和产品确认

**目标**：冻结当前行为事实，确认哪些差距是必须兼容的产品能力，避免在实现阶段把 ZJMF 行为误当作 turaidc 需求。

**拟改动文件**：实现文件无；本计划文件和 `docs/execution-plans/README.md`、`docs/catalog.json` 只做文档登记。以下文件只读审计：`backend/routes/console.php`、`backend/app/Services/Automation/Heartbeat/*`、`backend/app/Services/Automation/*AutomationService.php`、`backend/app/Jobs/RunHeartbeatTaskJob.php`、`backend/routes/v2-admin.php`。

**工作项**：

1. 导出任务注册清单：任务键、Provider、规则描述、有效频率、队列、锁 TTL、超时、是否可手动触发、当前权限。
2. 统计最近 30 天 `schedule_ticks`、`schedule_task_runs`、`jobs`、`failed_jobs` 的状态分布、重复槽位、派发失败、租约回收和平均耗时；仅读数据库，不修改数据。
3. 盘点插件清单中所有 `schedule_hooks`、`ScheduleHook` 实现和直接调用 `ScheduleTaskTriggerService` 的入口，形成 Hook 消费者矩阵。
4. 产品确认续费提醒日、账单逾期提醒日、邮件/短信/站内渠道、到期暂停/终止补偿策略，以及失败任务人工重跑的角色和审批要求。
5. 运维确认生产每分钟调度、数据库队列 `retry_after`、Worker 最大超时和 Cache 锁后端的真实配置；不在此阶段更改配置。

**数据/迁移风险**：只读查询可能在高峰增加数据库负载；必须使用时间范围、索引列和限量查询，不对运行台账做回填或清理。当前活动迁移目录没有调度表迁移，不能把 `backend/database/_archive/migrations/` 当作新的迁移入口。

**回滚策略**：无实现变更；撤回本阶段只需删除本阶段产生的审计报告，不删除生产台账或队列记录。

**最小验证**：执行针对调度的只读查询和现有 `php artisan test tests/Feature/HeartbeatSchedulerTest.php tests/Feature/ScheduleTaskOverviewTest.php`；文档变更执行 `npm run docs:check`。

### Phase 0 审计基线（2026-08-11 登记）

以下为 Phase 0 工作项 1-3 的只读审计产物；统计来自本地 `idc` 库（截至 2026-08-11），生产基线待运维确认。

### 任务注册清单（17 个，运行时代码导出）

LegacyScheduleHookTaskProvider 的 `schedule-hook-*` 任务当前因没有任何监听器而未注册，不在清单中；`log-archive` 是唯一不可手动触发的任务。

| 任务键                                     | Provider | 规则             | 有效频率   | 队列       | 超时(s) | 锁TTL(s) | 手动触发 |
| ------------------------------------------ | -------- | ---------------- | ---------- | ---------- | ------- | -------- | -------- |
| `provision-retry-failed`                   | Core     | 每次心跳         | 15分钟     | automation | 900     | 960      | 是       |
| `refresh-hosting-panel-auth`               | Core     | 每次心跳         | 15分钟     | automation | 600     | 660      | 是       |
| `referral-release-rewards`                 | Core     | 每次心跳         | 15分钟     | automation | 600     | 1200     | 是       |
| `service-status-sync`                      | Core     | 每次心跳         | 15分钟     | automation | 1200    | 1800     | 是       |
| `service-lifecycle-maintenance`            | Core     | 每次心跳         | 15分钟     | automation | 900     | 960      | 是       |
| `site-cache-warmup`                        | Core     | 每次心跳         | 15分钟     | automation | 900     | 960      | 是       |
| `coupon-campaign-dispatch`                 | Core     | 每次心跳         | 15分钟     | automation | 900     | 960      | 是       |
| `order-cleanup`                            | Core     | 每次心跳         | 15分钟     | automation | 900     | 960      | 是       |
| `service-auto-renew`                       | Core     | 每 4 次心跳      | 60分钟     | automation | 900     | 960      | 是       |
| `reconcile-account-balance`                | Core     | 每 4 次心跳      | 60分钟     | automation | 1200    | 1800     | 是       |
| `compensate-recharge-invoices`             | Core     | 每 4 次心跳      | 60分钟     | automation | 1200    | 1800     | 是       |
| `billing-maintenance`                      | Core     | cron `0 * * * *` | 每小时     | automation | 1200    | 1800     | 是       |
| `product-upstream-config-sync`             | Core     | cron `0 0 * * *` | 每日 00:00 | automation | 3600    | 3660     | 是       |
| `ticket-auto-close`                        | Core     | cron `0 0 * * *` | 每日 00:00 | automation | 900     | 1200     | 是       |
| `log-archive`                              | Core     | cron `0 2 * * *` | 每日 02:00 | automation | 3600    | 3660     | **否**   |
| `refresh-zjmf-finance-auth`                | Plugin   | 每次心跳         | 15分钟     | automation | 600     | 660      | 是       |
| `sync-zjmf-finance-inventory-and-services` | Plugin   | 每次心跳         | 15分钟     | automation | 1200    | 1800     | 是       |

### 最近 30 天调度/队列统计（只读查询）

- `schedule_ticks`：311 个槽位，重复槽位 0。
- `schedule_task_runs`：3555 条；`success` 3401、`failed` 145、`queued` 9；`dispatch_failed` 0；租约回收 0；`manual_retry` 0；来源 `heartbeat` 3553、`manual_trigger` 2。
- 145 条 `failed` 全部来自旧 `mofang` 插件任务键（`refresh-mofang-finance-auth`、`sync-mofang-finance-inventory-and-services`），错误为“不支持的任务”，属插件卸载后的历史 Job 遗留，不是当前注册任务失败；对应 `failed_jobs` 中 `RunHeartbeatTaskJob` 145 条。
- `jobs`：9 条待消费，全部为 `RunHeartbeatTaskJob` 且位于 `automation` 队列，与台账 `queued` 9 条一致。
- `failed_jobs`：234 条（`RunHeartbeatTaskJob` 145、邮件类 87、`RunScheduleTaskJob` 1、`ProcessPaidOrderFulfillmentJob` 1）。
- 本地库 `2026_08_10_000001_add_retry_lineage_to_schedule_task_runs_table` 迁移已应用（2026-08-12），`attempt/parent_run_id/manual_retry_at/manual_retry_by` 列与 `parent_run_id+created_at` 索引已落库；测试使用独立库不受影响。

### Hook 消费者矩阵

- 已启用插件 12 个，仅 `upstream/zjmf_finance` 声明 `schedule_hooks`（`plugins.zjmf_finance.auth_refresh`、`plugins.zjmf_finance.inventory_service_sync`）。
- 上述两个自定义 Hook 由插件自有 `ScheduledTask` 直接调用 `ScheduleHookService::run()` 触发，不经 Legacy Provider。
- 所有标准 Hook 名称（`before_cron`、`after_cron`、`task.before`、`task.after`、`task.failed`、`tick.every_minute`、`tick.every_five_minutes`、`tick.hourly`、`tick.daily`、`*_daily_cron`、`*_five_minute_cron` 等）当前**均无监听器**；系统 `config/schedule_hooks.php` 全部为空。
- ZJMF 缺失扩展点 `before_task_create`、`after_task_create`、`task_run`、`before_host_renewal_first` 在系统配置与插件清单中均无声明。
- 直接调用 `ScheduleTaskTriggerService` 的生产入口仅 `AdminOperationalActionV2Service`（管理端 `POST /api/v2/admin/schedule-triggers`），其余为总览读取与测试。

### 配置基线（代码默认值，生产待运维确认）

- `QUEUE_CONNECTION=database`，DB 队列 `retry_after=3900`；调度队列 `automation`，业务队列 `provision,referral,notification,coupon,default`。
- `CACHE_STORE=redis`（默认），Cache 锁连接 `REDIS_CACHE_LOCK_CONNECTION`。
- `missed_slot_policy=strict_current_slot` 已固化在调度总览输出。

### Phase 1：调度频率与 Hook 兼容契约

**依赖**：Phase 0 的任务/插件矩阵和产品确认；未确认的 Hook 不进入实现。

**目标**：把“声明频率”和“实际频率”显式化，补齐有真实调用方的 ZJMF Hook 扩展点，同时保持当前 15 分钟心跳和异常隔离。

**拟改动文件清单**：

- `backend/app/Services/Automation/Heartbeat/Providers/LegacyScheduleHookTaskProvider.php`：任务描述、有效频率和错过槽位策略输出。
- `backend/app/Services/Automation/Heartbeat/HeartbeatTaskRegistry.php`、`CallbackScheduledTask.php`、相关数据对象：增加只读元数据，不改变任务键。
- `backend/app/Services/Automation/ScheduleHookService.php`：建立 ZJMF 名称到 turaidc Hook 的显式映射；按确认结果增加 `before_task_create`、`after_task_create`、`task_run`、`before_host_renewal_first` 的 typed 适配器或领域事件。
- `backend/app/Services/Automation/Heartbeat/HeartbeatTaskRunner.php`、`CoreScheduledTaskProvider.php`：在任务执行前后/失败处传递一致的上下文，禁止重复触发业务副作用。
- `backend/config/schedule_hooks.php`、插件清单声明及 `docs/references/integrations/plugins/README.md`：补充声明频率、有效频率、失败隔离和兼容映射。
- 测试：`backend/tests/Unit/ScheduleHookServiceTest.php`、`backend/tests/Feature/HeartbeatSchedulerTest.php`、`backend/tests/Feature/ScheduleTaskExecutionCoverageTest.php`，必要时新增 Hook 兼容测试。

**数据/迁移风险**：本阶段原则上无迁移；Hook 上下文只增加可选字段。若必须持久化 Hook 版本或任务声明，先确认是否已有配置/插件清单字段，优先追加 JSON 元数据，禁止改写历史运行记录。

**回滚策略**：以 Hook 映射开关和 Provider 元数据开关回滚；关闭新增适配器后旧 Hook 名称和现有任务键继续工作。不得删除已经写入的 Hook 结果摘要。

**最小验证**：

- 单元测试覆盖每个映射、无监听器不注册任务、单监听器异常不阻断其他监听器、Hook 上下文含 `task_key/source/tick_slot/task_run_id`。
- Feature 测试验证 15 分钟槽位下 `tick.every_minute` 和 `tick.every_five_minutes` 不会被误报为真实 1/5 分钟。
- `cd backend && php artisan test tests/Unit/ScheduleHookServiceTest.php tests/Feature/HeartbeatSchedulerTest.php tests/Feature/ScheduleTaskExecutionCoverageTest.php`。

### Phase 2：运行台账、重试与租约治理

**依赖**：Phase 1 的任务元数据稳定；Phase 0 已确认生产队列超时与重试参数。

**目标**：让运行台账完整表达自动重试、最终失败、派发失败、租约回收和人工重跑的关系，强化 CAS、队列可见性和重复投递边界。

**拟改动文件清单**：

- `backend/app/Services/Automation/Heartbeat/ScheduleTaskRunRepository.php`：统一状态转移、attempt/父运行关联、人工重跑创建和租约回收原因；保持条件更新。
- `backend/app/Models/ScheduleTaskRun.php`：补充状态常量、可审计字段和类型转换。
- `backend/app/Jobs/RunHeartbeatTaskJob.php`、`backend/app/Services/Automation/Heartbeat/HeartbeatTaskRunner.php`：写入尝试序号、最终失败边界、迟到 Job 拒绝原因和结构化摘要。
- `backend/app/Services/Automation/Heartbeat/HeartbeatScheduler.php`、`QueueDrainService.php`：补充 Worker/锁/队列可见性指标和滞留告警，不改变每分钟入口。
- 仅在实库字段确认且产品批准后新增 `backend/database/migrations/<timestamp>_add_schedule_task_run_retry_lineage.php`：采用追加字段/索引，候选为 `attempt`、`parent_run_id`、`manual_retry_at`、`manual_retry_by`；字段命名以 `information_schema` 和现有模型为准，不能在计划阶段假定。
- 测试：`backend/tests/Feature/HeartbeatSchedulerTest.php`、`backend/tests/Feature/ScheduleTaskExecutionCoverageTest.php`、`backend/tests/Feature/QueueDrainServiceTest.php`，新增 Repository/CAS/重复投递/租约边界测试。

**数据/迁移风险**：

- `schedule_task_runs` 可能已有大量历史记录，新增字段必须可空或有安全默认值，禁止重写历史状态。
- `parent_run_id` 只能建立同表自关联，需处理删除策略，不能因为槽位删除级联丢失审计。
- `attempt` 与 Laravel Job 的尝试次数必须定义为同一口径；队列重投、Worker 崩溃和手动重跑不能混在一个计数器中。
- 迁移只允许追加；生产回滚不执行 `down` 删除字段，采用代码开关停止写入并保留数据。

**回滚策略**：先关闭新增字段写入和告警读取，恢复旧序列化字段；保留已写入的新增列。若状态机变更导致派发异常，暂停心跳派发并由现有 `dispatch_failed` 重派逻辑收敛，不手工改状态。

**最小验证**：

1. 并发派发同一任务/同一槽位时只有一条运行进入 `running`，重复 Job 不执行 Handler。
2. 第 1、2 次失败进入 `retrying`，第 3 次进入 `failed`，`success` 不被 `failed()` 覆盖。
3. Worker 在 `retry_after` 前后退出、租约回收、重复投递和 `dispatch_failed` 重派均有定向测试。
4. `cd backend && php artisan test tests/Feature/HeartbeatSchedulerTest.php tests/Feature/QueueDrainServiceTest.php tests/Feature/ScheduleTaskExecutionCoverageTest.php`。

### Phase 3：后台运行台账与失败任务人工重跑

**依赖**：Phase 2 已确定状态机、attempt 和父运行语义；权限责任人已在 Phase 0 确认。

**目标**：补齐 ZJMF `TaskController` 的可检索和可重跑能力，但适配 turaidc 的队列与幂等边界，不直接复制 `task_wait`。

**拟改动文件清单**：

- `backend/app/Services/Automation/ScheduleTaskService.php`：把当前 `recentRuns(24)` 扩展为带任务键、来源、状态、时间范围、分页、排序和统计的查询服务；保留 `overview` 响应兼容。
- `backend/app/Services/Automation/ScheduleTaskTriggerService.php`：增加按运行 ID 的人工重跑前置检查，禁止直接复用终态行；复用同一任务注册、队列和 `WithoutOverlapping`。
- `backend/app/Http/Controllers/Admin/V2/ScheduleTaskController.php`、`backend/routes/v2-admin.php`：新增 `GET /api/v2/admin/schedule-runs`、`GET /api/v2/admin/schedule-runs/{id}`、`POST /api/v2/admin/schedule-runs/{id}/retry` 的契约；保留现有总览和按任务手动触发接口。
- `backend/app/Support/` 或现有操作日志服务：记录管理员、原因、原运行 ID、重跑运行 ID和结果；不把凭据、任务载荷中的敏感数据写入日志。
- 权限定义与 API 资源/请求类：新增“查看运行台账”和“重跑失败任务”最小权限，禁止复用过宽的系统管理权限。
- 测试：`backend/tests/Feature/ScheduleTaskOverviewTest.php`、`backend/tests/Feature/V2AdminOperationalActionApiTest.php`、`backend/tests/Feature/V2AdminSettingsScheduleApiTest.php`，新增分页、权限、状态和重复重跑测试。

**人工重跑规则**：

- 仅允许 `failed`；`dispatch_failed` 需先确认原 Job 未进入队列，不能把队列不可用误判为业务失败。
- `queued/running/retrying/success` 拒绝重跑；租约回收的 `failed` 必须显示“可能已有副作用”的警告并要求更高权限或二次确认。
- 新运行的 `source` 使用独立的 `manual_retry`，通过 `parent_run_id` 关联原记录；重跑失败不覆盖原失败原因。
- 重跑前重新读取任务注册和当前配置，任务已禁用、插件清单失效或任务键改变时拒绝入队。
- 触发接口返回运行 ID、状态和审计关联，不同步执行任务，不绕过队列。

**数据/迁移风险**：分页查询需要 `task_key/status/source/created_at` 组合索引评估；新增索引必须先用真实 `EXPLAIN` 验证。操作日志与运行台账写入应采用同一请求链路的可重试写入，不能因为日志失败而重复派发。

**回滚策略**：撤销新路由和权限，保留只读总览及现有按任务触发；已创建的 `manual_retry` 运行继续由队列状态机收敛，不删除审计记录。必要时把人工重跑按钮置为只读，不恢复 SQL 直改方案。

**最小验证**：

- 未授权管理员、非终态、插件卸载、任务禁用、重复点击和并发重跑均返回明确错误且不新增 Job。
- 合法失败运行生成新的运行 ID，父子关系、管理员、原因和最终状态可查询。
- API Feature 测试通过后再考虑管理端页面；若修改 `frontend-admin-v3`，必须追加对应 e2e 和 `npm run build`，本计划阶段不默认包含前端改动。

### Phase 4：业务规则差异确认与受控收敛

**依赖**：Phase 0 产品确认、Phase 1 频率契约和 Phase 3 失败可运维；没有书面确认的规则保持现状。

**目标**：逐项决定哪些 ZJMF 业务行为需要兼容，哪些继续采用 turaidc 的补偿式、幂等式实现；不把任务框架重构与通知策略变更绑定发布。

**拟改动文件清单**：

- `backend/app/Services/System/SettingService.php`：只在产品批准后调整提醒日、开关和渠道配置；优先取消固定常量，改为可校验配置。
- `backend/app/Services/Automation/BillingAutomationService.php`、`AutoRenewService.php`：实现确认后的续费/账单提醒规则、渠道和幂等键，保留通知失败的补偿语义。
- `backend/app/Services/Automation/ServiceLifecycleAutomationService.php`：确认到期暂停、暂停后终止、错过窗口补偿和通知规则；禁止按 ZJMF 窗口直接删除 turaidc 的持续扫描逻辑。
- `InvoiceCleanupAutomationService.php`、`OrderCleanupAutomationService.php`、`CoreScheduledTaskProvider.php`：仅调整任务边界、摘要和验收指标，不把 ZJMF 未使用的业务动作强行加入核心任务。
- 测试：续费提醒、逾期提醒、服务暂停/终止、幂等日志和通知渠道的 Feature/Unit 测试；涉及前端设置页面时追加端到端测试。
- 运维文档：`docs/references/operations/deployment-and-scheduling.md` 记录有效频率、补偿策略、人工重跑和告警处理。

**数据/迁移风险**：提醒规则变化可能重复发送或漏发，必须以规则快照和 `AutomationLog` 幂等键保护；不能通过批量删除历史日志来“重置”提醒。设置迁移只允许新增字段/默认值，旧设置需可读、可回退。

**回滚策略**：按功能开关回到现有 `[7,3,1]`、`[1,3,5]` 和当前生命周期阈值；保留已发送通知和幂等记录，禁止回滚脚本再次发送。任何数据修复另立迁移计划。

**最小验证**：以固定日期构造到期/逾期样本，验证每个提醒日只发一次、错过心跳后的补偿策略、暂停与终止的时间计算、邮件/站内/短信失败重试；执行受影响后端测试和完整后端回归。

### Phase 5：灰度、监控和文档收口

**依赖**：Phase 1 至 Phase 4 的验收项全部完成；生产变更单、回滚负责人和告警接收人明确。

**目标**：在不改变唯一调度入口的前提下，以低风险任务灰度，观察至少一个完整日周期和多个 15 分钟槽位，再决定是否扩大范围。

**拟改动文件清单**：

- `backend/app/Services/System/ProductionReadinessService.php` 或现有健康检查：增加调度心跳滞留、队列 Worker、运行台账终态比例、Hook 失败和失败重跑积压指标。
- `backend/app/Services/Automation/ScheduleTaskService.php`：在总览中输出有效频率、最近成功/失败、滞留运行和人工重跑统计，保持现有字段向后兼容。
- `docs/references/operations/deployment-and-scheduling.md`、`docs/references/integrations/plugins/README.md`：更新生产调度、队列、锁后端、人工重跑和 Hook 兼容边界。
- `docs/generated/api/backend-api-catalog.md`：若路由发生变化，必须通过项目生成脚本刷新，禁止手工编辑。

**数据/迁移风险**：监控查询不得扫描全表；历史运行台账增长需要单独的保留/归档策略，不能在本计划中删除。灰度期间不同时改变队列连接、Cron 频率和业务通知规则。

**回滚策略**：停止新增任务注册或关闭新 API/告警读取，保留 Laravel 每分钟 `schedule:run`、原有心跳和队列；已入队 Job 由原状态机完成，不能强杀后批量重跑。任何生产数据状态保持可追溯。

**最小验证**：

- `cd backend && php artisan test`。
- 若本阶段修改共享或前端契约，按影响范围执行 `npm run typecheck:shared && npm run test:shared`、对应前端 `npm run build` 和 e2e。
- `npm run docs:check`，并核对 API 自动生成物没有手工漂移。

## 阶段依赖与发布顺序

```text
Phase 0 基线/确认
    ├──> Phase 1 频率与 Hook 契约
    │       └──> Phase 2 台账/重试/租约
    │               └──> Phase 3 查询与人工重跑
    └──────────────────────────────> Phase 4 业务规则收敛
Phase 1 + Phase 2 + Phase 3 + Phase 4
    └──> Phase 5 灰度、监控与文档收口
```

- Phase 1 可以在 Phase 0 的产品确认完成后先做；Phase 2 不得在状态和队列可见性未测清前上线。
- Phase 3 依赖 Phase 2 的父运行/尝试口径，否则人工重跑会覆盖或混淆自动重试。
- Phase 4 与 Phase 2/3 技术上可并行设计，但生产发布必须在失败可运维能力完成后进行。
- Phase 5 是唯一允许灰度观察和运维切换的阶段；任何真实 Cron、队列 Worker 或缓存锁配置调整都必须在独立变更单中执行。

## 验证矩阵与验收门禁

| 范围           | 最小验证                                                                                | 通过标准                                                          |
| -------------- | --------------------------------------------------------------------------------------- | ----------------------------------------------------------------- |
| 调度槽位与互斥 | `HeartbeatSchedulerTest`、并发心跳、重复槽位、锁不可用降级                              | 每槽位最多一条有效运行；锁失败只跳过并产生可定位告警              |
| 规则匹配       | Cron、DailyTick、EveryTicks、时区、错过槽位                                             | 15 分钟对齐规则不误报；`strict_current_slot` 行为有测试           |
| 队列消费       | `RunHeartbeatTaskJob`、`QueueDrainServiceTest`、重复 Job、Worker 崩溃、retry_after 边界 | CAS 失败不执行副作用；自动重试最多三次；成功不被失败回写覆盖      |
| 运行台账       | 状态机、派发失败、租约回收、attempt/父运行                                              | 终态不可被迟到 Job 覆盖；回收原因和人工重跑可追溯                 |
| Hook           | 监听器映射、异常隔离、无监听器、插件加载失败                                            | 单个监听器失败不吞掉其他结果；缺失 Hook 不被静默冒充              |
| 后台 API       | 分页/过滤/排序、权限、详情、人工重跑、重复请求                                          | 仅合法终态可重跑；操作审计完整；不暴露任务载荷敏感信息            |
| 业务规则       | 到期提醒、逾期提醒、暂停/终止、幂等与补偿                                               | 产品确认的每个日期/渠道有固定时钟测试；同一规则只产生一次业务动作 |
| 运维           | 心跳、Worker、Cache、队列积压、失败运行和告警                                           | 生产只需每分钟调度入口；异常可定位到任务键、运行 ID 和阶段        |
| 文档与生成物   | `npm run docs:check`、路由生成脚本（如有）                                              | 链接、目录覆盖、计划结构和自动生成物均通过                        |

## 风险清单

| 风险                           | 触发条件                                    | 预防/监控                                     | 处置                                           |
| ------------------------------ | ------------------------------------------- | --------------------------------------------- | ---------------------------------------------- |
| 频率误解导致 SLA 失败          | 插件使用 `tick.every_minute` 期待分钟级执行 | 输出有效频率；插件安装/扫描时校验声明         | 禁用不满足 SLA 的 Hook，另立真实分钟调度方案   |
| 人工重跑产生重复副作用         | 原 Job 已执行但台账因进程崩溃仍为失败       | 默认不自动重跑；父运行、幂等键、二次确认      | 由业务负责人确认后重跑，保留原记录和结果       |
| 队列可见性与 Worker 超时不一致 | `retry_after <= timeout + 安全余量` 不成立  | 启动校验、总览显示、告警                      | 暂停自动化队列灰度，先修配置并核对重复 Job     |
| Cache 锁后端不可用             | Windows 文件 Cache 或共享部署锁不可靠       | `schedule_runtime.mutex` 健康状态、锁失败指标 | 降级为跳过当前槽位，不启动可能重复的 Worker    |
| 台账增长影响查询               | 每分钟心跳和手动触发长期累积                | 索引、时间范围、分页和保留策略                | 先限流查询；另立台账归档计划，不在线删除历史   |
| Hook 失败被错误吞掉或阻断      | 新增 Hook 复用旧异常语义                    | typed 结果、逐监听器结果、契约测试            | 回滚新适配器，保留失败摘要和插件标识           |
| 业务规则改变造成通知风暴/漏发  | 未完成产品确认就调整天数或渠道              | 规则快照、幂等日志、灰度日期样本              | 关闭新开关，保持已发送记录不重复补发           |
| 插件任务注册漂移               | 插件卸载、清单变更或键重命名                | 注册清单校验、任务键稳定性测试                | 拒绝人工重跑并提示插件状态，禁止按旧键盲目入队 |
| 文档与代码事实分叉             | 路由/任务键变更未更新生成文档               | `docs:check`、生成脚本、阶段验收              | 先更新代码事实和生成物，再继续发布             |

## 不确定项与产品确认项

1. 是否需要真实 1 分钟或 5 分钟执行语义？如果需要，确认允许新增独立调度入口、Worker 成本、锁后端要求和插件 SLA；否则接受现有 15 分钟有效频率。
2. `before_task_create` / `after_task_create` 是否为插件公共契约？确认异常是否阻断入队、是否需要通知偏好过滤和审计字段。
3. 是否需要 `task_run` 未注册类型扩展？确认扩展任务的白名单、返回状态、超时、权限和幂等责任人。
4. 是否需要 `before_host_renewal_first`？确认首次续费提醒前的业务动作、异常隔离和重复触发规则。
5. 失败任务人工重跑由哪些角色执行？是否要求二次确认、工单号/原因、时间窗和最高权限；租约回收任务是否一律禁止重跑。
6. 是否保留当前续费提醒 `[7,3,1]`，还是改为 ZJMF 两次提醒？账单逾期提醒 `[1,3,5]` 是否需要短信渠道；渠道失败是否允许补发。
7. 到期暂停默认当天、终止默认关闭及“按暂停时间计算保留期”是否为产品真源；错过心跳槽位后是否继续采用持续扫描补偿。
8. `schedule_task_runs` 的历史保留、归档和查询时间范围由谁负责；是否需要单独的台账归档计划。
9. 生产是否允许由 `QueueDrainService` 每分钟启动短 Worker，还是要改为常驻 `queue:work`；若改变，必须单独评估部署与回滚。

## 进度

- [x] 按顺序完成 AGENTS、工作规则、文档记录系统、执行计划规范、ZJMF 真源、turaidc 调度/队列/台账/Hook/业务自动化和运维指南的只读审计。
- [x] 形成 ZJMF 机制与 turaidc 现状逐项映射及真实差距证据。
- [x] 完成 Phase 0 工作项 1-3：导出任务注册清单（17 个）、最近 30 天调度/队列只读统计与 Hook 消费者矩阵，审计基线已登记于本计划；产品确认（工作项 4）和生产配置确认（工作项 5）待相关责任人。
- [ ] 完成 Phase 0 产品确认和生产基线；提醒规则、真实频率和生产调度配置仍保持现状。
- [x] 完成第一批低风险实现：运行台账追加 attempt/父子关联字段，提供分页/详情 API，并增加仅限终态 failed 的受权限人工重跑。
- [x] 完成第一批定向回归：运行台账 API、调度器、队列派发、权限目录和既有调度管理 API 测试通过。
- [x] 完成全量后端回归核对：`1112 passed, 2 skipped, 26 failed`；新增运行台账 API 三项测试在全量顺序下通过，26 项失败仍集中于通知、归档、插件、服务投影等既有基线范围，未作为本批调度回归的通过条件。
- [x] 完成审查问题修复：新增 `HeartbeatTaskTimedOutListener` 收敛超时运行状态，心跳槽位锁 TTL 对齐 900 秒，修正 VNC 命令过时注释；新增 5 项定向测试，调度相关 62 项回归全部通过。
- [x] 完成 Phase 1 频率契约第一部分（不依赖产品确认）：任务元数据输出 `declared_cadence`/`effective_cadence`，Legacy Hook 任务声明原始频率，插件集成文档补充声明/有效频率边界；调度相关 64 项测试、PHPStan、Pint、`docs:check` 全部通过。
- [x] 完成 Phase 2 台账可观测性部分：迟到/重复 Job 被 CAS 拒绝后把原因写入 `summary.late_job_rejections`（限量 5 条），心跳槽位在租约回收、派发失败或队列积压时输出结构化健康告警；新增 3 项定向测试，调度相关 67 项测试、PHPStan、Pint 通过。
- [x] 完成 Phase 3 分页索引真实 EXPLAIN 评估：`task_key/status/source/created_at` 与 `task_key+status` 组合过滤均走既有索引（ref/range、Using index），不需要新增索引；`retry_lineage` 迁移已在本地 dev 库应用。
- [x] 完成 Phase 5 总览统计部分：调度总览新增 `runs_summary`（active/stale/failed_24h/success_24h/manual_retry_24h，全走索引列），管理端 API 透出；新增统计计数测试，调度相关 62 项测试、PHPStan、Pint、`docs:check` 通过。
- [x] 完成 Phase 1 Hook 上下文契约测试补全：`ScheduleRunLogService::record` 传入的 tick_slot/tick_number/daily_tick_index/task_run_id/attempt 字段有定向断言；`config/schedule_hooks.php` 补充声明/有效频率注释。
- [x] 提交 `69d6295a`（定时任务体系重构：频率契约、运行台账查询与人工重跑，56 文件）与 `b6a831f2`（Hook 上下文测试与频率注释）；共享登记文档（catalog.json、API 清单、执行计划 README、设计文档 index、迁移记录清理）含其他计划登记，由各计划收口时统一提交。
- [x] 完成深度审查问题修复：`schedule_task_runs` 移出日志归档（禁止在线删除台账）；新增独立心跳存活探针 `scheduler:liveness`；心跳槽位锁占用时输出结构化告警；待处理统计收窄到 automation 队列；未注册任务 Job 直接收敛终态不再消耗重试；`dispatch_failed` 重派重置 attempt；JobTimedOut 反序列化收紧为白名单。调度相关 50 项测试、PHPStan、Pint 全部通过。
- [x] 完成剩余审查项：`schedule_task_runs.schedule_tick_id` 外键由级联删除改为 SET NULL（迁移 `2026_08_12_000001`，本地 dev 库已应用并验证）；运行台账分页 API 支持逗号分隔多状态筛选；部署指南登记 Windows 超时强杀限制。调度/归档/台账 API 共 54 项测试、PHPStan、Pint、`docs:check` 全部通过。
- [ ] 完成 Phase 1 其余部分（ZJMF 缺失 Hook 扩展点，依赖 Phase 0 产品确认）、Phase 2 剩余（Worker/锁可见性完整指标并入 Phase 5）、Phase 4 业务规则（依赖产品确认）与 Phase 5 剩余（灰度、告警接收人、文档收口）。

## 决策日志

| 日期       | 决策                                                                                                                                                                    | 原因                                                                                                                                                                                                                                               |
| ---------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 2026-08-12 | `schedule_task_runs.schedule_tick_id` 外键由 `cascadeOnDelete` 改为 `nullOnDelete`（迁移 `2026_08_12_000001`，幂等检查约束存在后变更；`down()` 不回退）                 | 未来若给 `schedule_ticks` 增加槽位清理/归档，级联删除会连带摧毁运行台账；台账是长期审计记录，槽位被删时关联置空即可，不影响唯一键（`schedule_tick_id` 可空）                                                                                       |
| 2026-08-12 | 运行台账分页 API 的 `status` 支持逗号分隔多状态（如 `queued,running`），无效状态返回 422                                                                                | 故障分析需要同时检索多状态（活跃、滞留等），Repository 已支持数组过滤，请求层此前反而更窄；白名单逐项校验保持契约严格                                                                                                                              |
| 2026-08-12 | `schedule_task_runs` 从 `log_archive.tables` 移入 `excluded_tables`，日志归档不再在线删除运行台账；归档测试表数由 8 更新为 7                                            | 深度审查发现 `db:archive-logs --execute` 每夜按 30 天保留期删除运行台账，`parent_run_id` 父子链和人工重跑审计随之断裂，与“长期可审计台账”目标直接冲突；台账的保留/归档策略另立计划，不在本计划删除历史                                             |
| 2026-08-12 | 新增独立心跳存活探针 `scheduler:liveness`（每分钟，与心跳并列注册），心跳停滞超阈值（默认 `health.scheduler_max_age_seconds`=180s）时输出结构化错误日志并返回失败退出码 | 心跳死亡时租约回收与健康告警全部失效，`queued` 运行与队列 Job 无限滞留；探针由系统 Cron 独立驱动，不依赖心跳命令存活；`schedule:list` 注册事件由 1 个更新为 2 个                                                                                   |
| 2026-08-12 | 调度总览新增 `runs_summary`（active/stale/failed_24h/success_24h/manual_retry_24h），全部走 `status_created_at`/`source_created_at` 等既有索引                          | 故障定位需要“当前活跃、滞留、24h 成败与人工重跑”的全局视角；只读统计不改变现有总览字段，管理端按 `compactValue` 限量透出                                                                                                                           |
| 2026-08-12 | 迟到/重复 Job 被 CAS 拒绝后，把拒绝痕迹追加到 `summary.late_job_rejections`（限量 5 条），不改变运行状态                                                                | 拒绝只返回 `skipped` 无法解释台账为何短暂停留在非终态；追加审计字段让运维能区分“被多次触碰”与“一次执行”，后续 `markSucceeded` 覆盖摘要时痕迹自然合并，不做额外的并发合并                                                                           |
| 2026-08-12 | 心跳槽位在租约回收、派发失败或 `jobs` 表积压时输出结构化健康告警，正常槽位保持静默                                                                                      | 每次心跳都输出汇总会刷屏且无信号价值；只在异常信号出现时告警，指标字段与 Phase 5 健康检查口径一致                                                                                                                                                  |
| 2026-08-11 | 频率契约采用可选接口 `ScheduledTaskCadence`，`effective_cadence` 由触发规则按 15 分钟槽位推断，不改任务键、不强制插件实现类                                             | 在 `ScheduledTask` 接口加方法会破坏全部插件任务类；兼容名称（every_minute 等）只作为 `declared_cadence` 输出，不作为真实频率推断依据；`EveryTicks`/`DailyTick` 增加只读访问器供序列化使用                                                          |
| 2026-08-11 | 注册 `JobTimedOut` 监听器把超时运行的台账状态收敛为 retrying/failed，不改变 markRunning CAS 语义                                                                        | 任务超时被 SIGKILL 时 Worker 在 kill 前同步派发 `JobTimedOut`，进程外被杀不经过 Runner 的 catch，运行记录会永久停在 running，队列自动重试被 CAS 永久拒绝、每日任务当日丢失；监听器在信号回调内同步写库，重试因此可真正执行，迟到 Job 仍被 CAS 拒绝 |
| 2026-08-11 | 心跳槽位锁 TTL 由 840 秒对齐为 900 秒；确认 worker 超时被杀不泄漏 drain 锁                                                                                              | 锁 TTL 应覆盖整个槽位周期，避免极端卡顿下的同槽重入窗口；`QueueDrainService` 的锁由父进程（心跳进程）轮询子进程退出后释放，worker 被 SIGKILL 时锁在 100ms 内正常释放，仅父进程自身崩溃才依赖 TTL 兜底                                              |
| 2026-08-10 | dispatch_failed 同槽重派前将旧错误追加到 summary.dispatch_failures，并限量保留最近五次                                                                                  | 重派应恢复可消费状态，但不能抹掉派发基础设施故障的审计线索；不新增另一张队列表                                                                                                                                                                     |
| 2026-08-09 | 以 Laravel 心跳、Laravel Queue 和 `schedule_task_runs` 为目标真源，不复制 ZJMF `task/task_wait`                                                                         | turaidc 已有队列保留、失败队列、状态 CAS 和任务级互斥；重复建设会产生双重消费和两套重试语义                                                                                                                                                        |
| 2026-08-09 | 不引入 `configuration.cron_lock*`                                                                                                                                       | 当前 `Cache::lock` + 槽位唯一约束具有原子边界，ZJMF 配置表锁存在读写非原子和过期恢复复杂度                                                                                                                                                         |
| 2026-08-09 | 将 15 分钟作为当前有效频率，兼容 Hook 名称不代表真实 1/5 分钟                                                                                                           | `TickSlot::floorToFifteenMinutes()`、`EveryTicks`、Legacy Provider 描述和调度总览均以 15 分钟槽位为事实                                                                                                                                            |
| 2026-08-09 | 租约回收不自动重跑                                                                                                                                                      | `ScheduleTaskRunRepository::reclaimStaleRunsForTask()` 已明确无法从数据库判断业务副作用；自动重跑可能造成重复扣款、重复通知或重复履约                                                                                                              |
| 2026-08-09 | 失败人工重跑创建新运行并保留父运行                                                                                                                                      | 需要同时满足 ZJMF 后台重试能力和 turaidc 长期审计，不能覆盖原失败行或直接改状态                                                                                                                                                                    |
| 2026-08-09 | 业务提醒阈值先不对齐 ZJMF                                                                                                                                               | turaidc 当前 `[7,3,1]`、`[1,3,5]` 和持续扫描补偿是代码事实；改变通知策略属于产品决策，不是调度框架修复                                                                                                                                             |
| 2026-08-09 | 计划 frontmatter 使用用户指定的 `status: 进行中`，目录登记使用校验器兼容的 `active`                                                                                     | 人类可读计划状态与 `docs/catalog.json` 的机器状态枚举需要同时满足；校验器需增加中文状态别名，避免牺牲用户要求或目录一致性                                                                                                                          |
| 2026-08-10 | 第一批先落地运行台账可观测性和失败人工重跑，不改变业务提醒规则、真实调度频率或生产调度配置                                                                              | 这些改动只追加审计字段和管理端能力，可沿用现有 Laravel Queue、Cache 锁和 CAS 状态机；业务规则差异仍需产品确认                                                                                                                                      |
| 2026-08-10 | 人工重跑仅允许终态 `failed`，每个失败运行最多发起一次，并创建 `manual_retry` 子运行                                                                                     | `dispatch_failed` 代表队列基础设施问题，不能误判为业务失败；父记录保留原始错误，子记录承载新的执行生命周期                                                                                                                                         |
| 2026-08-10 | 迁移只追加字段和索引，生产不执行 `down()`                                                                                                                               | 运行台账是审计真源，回滚通过撤销路由/权限和停止新入口实现，不删除历史数据                                                                                                                                                                          |
| 2026-08-10 | 运行台账 API 测试的列表夹具使用随机专属任务键                                                                                                                           | 共享 `idc_test` 会保留其他测试的历史运行记录；固定 `service-status-sync` 会使倒序分页首行不确定，不能用清理非本测试数据的方式解决隔离问题                                                                                                          |
