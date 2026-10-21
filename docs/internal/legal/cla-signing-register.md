---
status: current
updated: 2026-10-21
owner: lead
---

# CLA 签署记录与历史贡献覆盖清单

> 本文是 [`CLA.md`](../../../CLA.md)（v1.0，2026-10-21 生效）与 [CLA 可行性核对](cla-feasibility-2026-10-21.md) 承诺的「贡献者签署记录 + 历史贡献覆盖清单」的落地件，并按 2026-10-21 法务审查结论修订过方法论。
>
> 数据来源：仓库 git 全量历史（截至 `50914089`，1175 次提交，2895 个跟踪文件）。
>
> **公开仓库内的公示口径**：本文只包含公开 git 数据与聚合统计，**不出现逐人邮箱与逐人签署状态**。个人化签署记录（姓名、邮箱、声明原文、时间）保存在项目方私有记录中，仅在商业授权交付的核对环节由授权代表调取；对外公示只列已取得再许可授权的贡献者与聚合统计。

## 1. 总体结论（法务审查）

**当前状态不能对外提供任何声称免除 AGPL 第 13 条的闭源版本。** 三个原因：

1. **导入历史的权属已有依据，但凭证待归档**：仓库 2895 个跟踪文件中有 **2093 个（72.3%）** 来自两次「导入型」提交，git blame 对这批代码无法反映真实作者。项目方确认：该批代码系**向第三方购买取得**，卖方曾明确表示可自由开源、二次开发与售卖，并已转让著作权，项目方按自有代码处理。仍需归档的只有凭证（购买合同与卖方书面声明），见 §3。
2. **文件级排除在法律上不成立**：AGPL 第 0 条把「修改」定义为对全部或部分作品的复制或改编，第 5(c) 条要求按 AGPL 授权整体作品；因此从同一代码树里删掉若干文件，剩下的仍然是受 AGPL 覆盖的「修改版」，闭源仍会触发第 13 条。**只有补签（取得单独的再许可授权）能切断这条链。**
3. **混合文件被漏判**：550 个文件同时被项目方与外部贡献者写过（占当前跟踪文件 19.0%），先前按「项目方写过即项目方自有」处理会高估可用范围（见 §4）。

## 2. 商业版本构建规则（写进文件，门禁执行）

| 路径                       | 内容                                                                               | 成本                                                | 备注                                                                 |
| -------------------------- | ---------------------------------------------------------------------------------- | --------------------------------------------------- | -------------------------------------------------------------------- |
| **R1 全量补签**            | 全部外部贡献者补签并授权双许可（含闭源）                                           | 9 组、299 次提交，逐一发函                          | **首选**：这是 AGPL 第 5(c) 条下唯一能合法让「修改后闭源」成立的机制 |
| **R2 分离构建 + 独立实现** | 商业版从不含未签署贡献的代码树构建；相关功能由项目方独立实现（clean room，需留痕） | 取决于混合文件规模（550 个文件，核心运行时 178 个） | 成本高一个数量级，且需法务确认独立实现的举证标准                     |
| **R3 放弃闭源**            | 不再提供闭源授权，改为按 AGPL 运营 + 订阅/托管/私有化交付服务                      | 低                                                  | 零授权风险                                                           |

**门禁（未满足即不得对外提供闭源版本）**：

- R1 或 R2 未完成前，**不得**对外提供任何声称免除 AGPL 第 13 条的版本；
- 商业版构建产物需逐文件比对 §4/§5 清单，留存比对记录；
- 官方 SaaS / 演示站必须运行 AGPL 代码树，并履行第 13 条源码提供义务；
- AGPL 分支不得延迟发布（对外宣称的开源版本必须真实可得）；
- 若 R1 未能覆盖全部外部贡献者，落到 R2 或 R3，而不是靠「排除文件」了事。

## 3. 导入历史的权属取证

仓库历史里有两次「一次性导入」式提交，git 无法反映其中代码的真实作者：

| 提交       | 说明                        | 覆盖的当前文件 |
| ---------- | --------------------------- | -------------: |
| `c124e4cc` | 开源初始化二五云IDC财务系统 |           1651 |
| `b83eef91` | 全量合并 caiwu_update       |            760 |

合计 **2093 个当前文件（72.3%）** 属于这批导入代码。另有远端分支 `remotes/caiwu/main`（起点 2026-04-07，早于开源初始化）不是 HEAD 的祖先，存在未合并的私有历史。

**项目方确认（2026-10-21）**：该批导入代码系**向第三方购买取得**；卖方曾明确表示可自由开源、二次开发与售卖，并已转让著作权，项目方按自有代码处理。

**仍需归档的凭证（不影响结论，但要留痕）**：

1. 购买合同（或订单、发票、付款凭证）与卖方关于「可自由开源/二开/售卖 + 著作权转让」的书面声明，存入项目方私有记录；
2. 确认合同里的权利范围是**著作权转让**，而不是仅「使用权」——若只是买断使用权，卖方需另出一份著作权转让或可再许可（含商业许可）的授权声明。这一步是唯一还可能出问题的地方，且成本很低；
3. 购买方与被许可主体一致：合同主体应与 CLA/商业许可里的「项目方」是同一主体或已完成承接。
4. 确认 `remotes/caiwu/main` 的处置方式（合并、归档为私有、还是删除远端），避免授权链存在未审计的分支。
5. 上述确认完成前，商业授权只按草案沟通，不签署。

## 4. 历史贡献者身份归并

同一自然人在 git 历史里可能有多个身份（换邮箱、GitHub noreply 邮箱、本机导入账号），下表按身份组归并。「触及文件」指该身份组写过、且当前仍在仓库中的文件数。

| 身份组                       | GitHub 用户名                                       | 提交数 | 触及文件 | 类别                     |
| ---------------------------- | --------------------------------------------------- | -----: | -------: | ------------------------ |
| 项目方 A                     | `HaoSehu`、`cerbo`                                  |    509 |     2099 | 项目方（待书面权属确认） |
| 项目方 B                     | `吴基岩`                                            |    366 |     2734 | 项目方（待书面权属确认） |
| 导入历史本机账号             | （无公开账号）                                      |      1 |      339 | 项目方（待书面权属确认） |
| yealqp / 仙林loy             | `yealqp`、`仙林loy`                                 |    115 |      279 | 外部贡献者               |
| Shanshui 山重水复            | `Shanshui-山重水复`、`Shanshui2024`、`Shanshui2023` |     46 |      197 | 外部贡献者               |
| YeHuaiJing 叶淮璟            | `YeHuaiJing`、`叶淮璟`                              |     50 |      134 | 外部贡献者               |
| PigFully / PingFully         | `PigFully`、`PingFully`                             |     41 |      308 | 外部贡献者               |
| 咕咕摆烂真君 / Gugubailan520 | `咕咕摆烂真君`、`Gugubailan520`                     |      4 |        7 | 外部贡献者               |
| xiobb / xioiox               | `xiobb`、`xioiox`、`yellowface233`                  |     32 |       46 | 外部贡献者               |
| lan134342                    | `lan134342`                                         |      8 |       29 | 外部贡献者               |
| FurLLCN                      | `FurLLCN`                                           |      2 |        3 | 外部贡献者               |
| Cascade（来源待核实）        | `Cascade`                                           |      1 |        1 | 来源待核实               |

> 本表只做身份归并：列 GitHub 用户名，**不公示邮箱**，也不标注逐人签署状态。联系邮箱请从 git 历史作者信息或 GitHub 公开资料取用，不要写回仓库；逐人签署状态与声明原文存项目方私有记录。

## 5. 文件分类（当前跟踪文件 2895 个）

| 类别                                              | 数量 |  占比 | 商业版本可用性                                        |
| ------------------------------------------------- | ---: | ----: | ----------------------------------------------------- |
| 仅项目方写过                                      | 2189 | 75.6% | 权属确认后可商用                                      |
| 外部贡献者写过（其中双方都写过的混合文件 550 个） |  706 | 24.4% | 未取得再许可授权前不可商用；混合文件需按行/块判定归属 |
| 合计                                              | 2895 |  100% | —                                                     |

### 5.1 混合文件（项目方与外部都写过）分布

| 区域                           | 文件数 |
| ------------------------------ | -----: |
| 前端 `frontend-*/`             |    203 |
| 后端核心运行时 `backend/app/`  |    178 |
| 后端测试 `backend/tests/`      |     58 |
| 后端插件 `backend/plugins/`    |     52 |
| 后端其他 `backend/`            |     17 |
| 共享包 `shared/`               |      9 |
| 文档 `docs*/`                  |      7 |
| 仓库其他                       |      7 |
| 部署脚本 `deploy/`             |      5 |
| 后端配置 `backend/config/`     |      5 |
| 仓库级配置（`.github/` 等）    |      5 |
| 数据库迁移 `backend/database/` |      4 |

### 5.2 仅外部贡献者写过的文件分布

| 区域                           | 文件数 |
| ------------------------------ | -----: |
| 后端核心运行时 `backend/app/`  |     45 |
| 后端测试 `backend/tests/`      |     31 |
| 后端插件 `backend/plugins/`    |     25 |
| 前端 `frontend-*/`             |     13 |
| 数据库迁移 `backend/database/` |     11 |
| 部署脚本 `deploy/`             |     10 |
| 共享包 `shared/`               |      6 |
| 文档 `docs*/`                  |      6 |
| 后端配置 `backend/config/`     |      4 |
| 仓库级配置（`.github/` 等）    |      3 |
| 后端其他 `backend/`            |      1 |
| 仓库其他                       |      1 |

### 5.3 混合文件清单

> 混合文件不是「项目方自有」：其中的外部贡献行同样受 AGPL 约束。R1 完成后本清单整体放行；R2 路径下需对清单内文件做独立实现替换。

**仓库级配置（`.github/` 等）**

- `.coderabbit.yaml`
- `.github/CONTRIBUTING.md`
- `.github/SECURITY.md`
- `.github/workflows/ci.yml`
- `.gitignore`

**仓库其他**

- `AGENTS.md`
- `README.md`

**后端其他 `backend/`**

- `backend/.env.example`

**后端核心运行时 `backend/app/`**

- `backend/app/Console/Commands/BackfillConfigOptionsCommand.php`
- `backend/app/Console/Commands/BackfillServiceUpstreamBindingsCommand.php`
- `backend/app/Console/Commands/DatabaseNormalizeCoreRelationsCommand.php`
- `backend/app/Console/Commands/LocalProductSimulateCommand.php`
- `backend/app/Console/Commands/SchedulerLivenessCommand.php`
- `backend/app/Console/Commands/SeedNotificationEmailTemplatesCommand.php`
- `backend/app/Console/Commands/SyncUpstreamsCommand.php`
- `backend/app/Console/Commands/VncRelayCommand.php`
- `backend/app/Constants/ProductType.php`
- `backend/app/Http/Controllers/Admin/V2/AgentGroupController.php`
- `backend/app/Http/Controllers/Admin/V2/AgentGroupDiscountController.php`
- `backend/app/Http/Controllers/Admin/V2/AuthController.php`
- `backend/app/Http/Controllers/Admin/V2/OpenApiAdminController.php`
- `backend/app/Http/Controllers/Admin/V2/ProductController.php`
- `backend/app/Http/Controllers/Admin/V2/ProductDiscountGroupController.php`
- `backend/app/Http/Controllers/Admin/V2/SupplierController.php`
- `backend/app/Http/Controllers/Admin/V2/TicketController.php`
- `backend/app/Http/Controllers/Client/V2/AuthController.php`
- `backend/app/Http/Controllers/Client/V2/ReferralController.php`
- `backend/app/Http/Controllers/Client/V2/TicketController.php`
- `backend/app/Http/Controllers/Client/V2/TicketWorkflowController.php`
- `backend/app/Http/Controllers/Client/V2/VerificationController.php`
- `backend/app/Http/Controllers/SecureAssetController.php`
- `backend/app/Http/Middleware/VerifyTicketUpstreamCallbackSignature.php`
- `backend/app/Http/Requests/Admin/V2/HomeHero/UpdateHomeHeroRequest.php`
- `backend/app/Http/Requests/Admin/V2/IntegrationPlugin/RunIntegrationPluginTaskRequest.php`
- `backend/app/Http/Requests/Admin/V2/Invoice/ListInvoicesRequest.php`
- `backend/app/Http/Requests/Admin/V2/Log/CleanupLogActionRequest.php`
- `backend/app/Http/Requests/Admin/V2/Log/ListLogsRequest.php`
- `backend/app/Http/Requests/Admin/V2/Log/SummarizeLogsRequest.php`
- `backend/app/Http/Requests/Admin/V2/MediaFile/StoreMediaFileRequest.php`
- `backend/app/Http/Requests/Admin/V2/Product/ListProductsRequest.php`
- `backend/app/Http/Requests/Admin/V2/Product/ProductBatchUpdateRequest.php`
- `backend/app/Http/Requests/Admin/V2/Product/StoreProductRequest.php`
- `backend/app/Http/Requests/Admin/V2/ProductGroup/BatchUpdateGroupProductsRequest.php`
- `backend/app/Http/Requests/Admin/V2/Setting/UpdateSettingsRequest.php`
- `backend/app/Http/Requests/Admin/V2/Supplier/UpsertSupplierRequest.php`
- `backend/app/Http/Requests/Client/V2/ApiKey/StoreApiKeyRequest.php`
- `backend/app/Http/Requests/Client/V2/ApiKey/UpdateApiKeyRequest.php`
- `backend/app/Http/Requests/Client/V2/Auth/UpdateProfileRequest.php`
- `backend/app/Http/Requests/Client/V2/Invoice/ListInvoicesRequest.php`
- `backend/app/Http/Requests/Client/V2/Service/CreateNatForwardingRequest.php`
- `backend/app/Http/Requests/Client/V2/Ticket/ListTicketsRequest.php`
- `backend/app/Http/Resources/Admin/V2/AdminHomeHeroResource.php`
- `backend/app/Http/Resources/Admin/V2/AdminIntegrationPluginSchemaFieldResource.php`
- `backend/app/Http/Resources/Admin/V2/AdminLogDetailResource.php`
- `backend/app/Http/Resources/Admin/V2/AdminLogSummaryResource.php`
- `backend/app/Http/Resources/Admin/V2/AdminProductDetailResource.php`
- `backend/app/Http/Resources/Admin/V2/AdminProductGroupListResource.php`
- `backend/app/Http/Resources/Admin/V2/AdminSupplierResource.php`
- `backend/app/Http/Resources/Client/V2/ClientInvoiceDetailResource.php`
- `backend/app/Http/Resources/Site/V2/SiteHomeHeroResource.php`
- `backend/app/Http/Resources/Site/V2/SiteProductCardResource.php`
- `backend/app/Http/Resources/Site/V2/SiteProductDetailResource.php`
- `backend/app/Http/Resources/Site/V2/SiteProductGroupResource.php`
- `backend/app/Http/Resources/Ticket/V2/TicketDetailResource.php`
- `backend/app/Jobs/SyncInvoiceCouponUsageJob.php`
- `backend/app/Jobs/SyncPaidInvoiceCouponUsageJob.php`
- `backend/app/Models/AgentGroup.php`
- `backend/app/Models/Concerns/HandlesProductSnapshot.php`
- `backend/app/Models/IntegrationPlugin.php`
- `backend/app/Models/Product.php`
- `backend/app/Models/Service.php`
- `backend/app/Models/ServiceInstance.php`
- `backend/app/Models/Supplier.php`
- `backend/app/Models/SupplierPluginBinding.php`
- `backend/app/Models/Ticket.php`
- `backend/app/Models/TicketReply.php`
- `backend/app/Models/User.php`
- `backend/app/Providers/AppServiceProvider.php`
- `backend/app/Services/Admin/Rbac/AdminRoleService.php`
- `backend/app/Services/Admin/Rbac/AdminStaffService.php`
- `backend/app/Services/Admin/Rbac/PermissionCatalogService.php`
- `backend/app/Services/Admin/V2/AdminCatalogActionV2Service.php`
- `backend/app/Services/Admin/V2/AdminConfigurationV2QueryService.php`
- `backend/app/Services/Auth/AuthService.php`
- `backend/app/Services/Auth/GeeTestService.php`
- `backend/app/Services/Auth/LoginRiskControlService.php`
- `backend/app/Services/Auth/VerificationService.php`
- `backend/app/Services/Automation/BillingAutomationService.php`
- `backend/app/Services/Automation/Heartbeat/HeartbeatScheduler.php`
- `backend/app/Services/Automation/Heartbeat/Providers/CoreScheduledTaskProvider.php`
- `backend/app/Services/Automation/Heartbeat/QueueDrainService.php`
- `backend/app/Services/Automation/Heartbeat/ScheduleTaskRunRepository.php`
- `backend/app/Services/Automation/Heartbeat/ScheduleTickRepository.php`
- `backend/app/Services/Automation/InvoiceCleanupAutomationService.php`
- `backend/app/Services/Automation/ScheduleTaskService.php`
- `backend/app/Services/Automation/ServiceLifecycleAutomationService.php`
- `backend/app/Services/Automation/ServiceStatusSyncService.php`
- `backend/app/Services/ClientServiceConsole/ServiceConsoleAreaService.php`
- `backend/app/Services/ClientServiceConsole/ServiceDetailService.php`
- `backend/app/Services/ClientServiceConsole/ServiceNatService.php`
- `backend/app/Services/ClientServiceConsole/ServiceOverviewService.php`
- `backend/app/Services/ClientServiceConsole/ServicePowerService.php`
- `backend/app/Services/ClientServiceConsole/ServiceResolverService.php`
- `backend/app/Services/ClientServiceConsole/ServiceSecurityGroupService.php`
- `backend/app/Services/ClientServiceConsole/ServiceTrafficPackageService.php`
- `backend/app/Services/ClientServiceConsole/ServiceTransformService.php`
- `backend/app/Services/ClientServiceConsole/ServiceUpgradeService.php`
- `backend/app/Services/ClientServiceConsole/ServiceVncService.php`
- `backend/app/Services/Content/ContentArticleService.php`
- `backend/app/Services/Content/HomeHeroService.php`
- `backend/app/Services/Content/MediaFileService.php`
- `backend/app/Services/Finance/AdminAgentDiscountService.php`
- `backend/app/Services/Finance/AdminFinanceQueryService.php`
- `backend/app/Services/Finance/AdminOrderNotificationService.php`
- `backend/app/Services/Finance/CheckoutService.php`
- `backend/app/Services/Finance/ClientPaymentQueryService.php`
- `backend/app/Services/Finance/CouponCampaignService.php`
- `backend/app/Services/Finance/CouponService.php`
- `backend/app/Services/Finance/FinanceLedgerQueryService.php`
- `backend/app/Services/Finance/InvoiceService.php`
- `backend/app/Services/Finance/InvoiceV2QueryService.php`
- `backend/app/Services/Finance/OrderV2QueryService.php`
- `backend/app/Services/Finance/PaymentService.php`
- `backend/app/Services/Install/InstallService.php`
- `backend/app/Services/Integrations/Plugins/IntegrationPluginService.php`
- `backend/app/Services/Integrations/Plugins/MofangFinanceProviderKeyMigrationService.php`
- `backend/app/Services/Integrations/Plugins/PluginBindingResolver.php`
- `backend/app/Services/Integrations/Plugins/PluginScanner.php`
- `backend/app/Services/Integrations/Plugins/ServiceUpstreamBindingWriter.php`
- `backend/app/Services/Integrations/Plugins/UpstreamBindingWriter.php`
- `backend/app/Services/Mail/SmtpMailTransport.php`
- `backend/app/Services/OpenApi/ApiKeyService.php`
- `backend/app/Services/Order/Concerns/HandlesOrderCalculation.php`
- `backend/app/Services/Order/OrderService.php`
- `backend/app/Services/ProductCatalog/Concerns/HandlesProductCatalogHelpers.php`
- `backend/app/Services/ProductCatalog/CouponProductGroupQueryService.php`
- `backend/app/Services/ProductCatalog/CpuModelCatalogService.php`
- `backend/app/Services/ProductCatalog/InstanceSpecCatalogService.php`
- `backend/app/Services/ProductCatalog/ProductAdminService.php`
- `backend/app/Services/ProductCatalog/ProductCatalogService.php`
- `backend/app/Services/ProductCatalog/ProductCategoryService.php`
- `backend/app/Services/ProductCatalog/ProductDisplayNameResolver.php`
- `backend/app/Services/ProductCatalog/ProductGroupHierarchyService.php`
- `backend/app/Services/ProductCatalog/ProductGroupV2QueryService.php`
- `backend/app/Services/ProductCatalog/ProductSiteService.php`
- `backend/app/Services/ProductCatalog/ProductSyncService.php`
- `backend/app/Services/ProductCatalog/ProductTypeService.php`
- `backend/app/Services/Provisioning/AdminServiceListService.php`
- `backend/app/Services/Provisioning/ProvisionService.php`
- `backend/app/Services/Provisioning/ServiceRenewService.php`
- `backend/app/Services/Referral/MemberLevelService.php`
- `backend/app/Services/Referral/ReferralService.php`
- `backend/app/Services/Site/SeoRenderService.php`
- `backend/app/Services/Site/SiteHomeService.php`
- `backend/app/Services/Site/SiteProductQuoteService.php`
- `backend/app/Services/Supplier/SupplierBalanceService.php`
- `backend/app/Services/System/AdminLogService.php`
- `backend/app/Services/System/AdminLogV2QueryService.php`
- `backend/app/Services/System/Concerns/HandlesAdminLogCleanup.php`
- `backend/app/Services/System/DashboardService.php`
- `backend/app/Services/System/DatabaseEngineeringService.php`
- `backend/app/Services/System/NotificationService.php`
- `backend/app/Services/System/NotificationTemplateService.php`
- `backend/app/Services/System/ScheduleRunLogService.php`
- `backend/app/Services/System/SettingService.php`
- `backend/app/Services/System/SmsService.php`
- `backend/app/Services/Ticket/TicketService.php`
- `backend/app/Services/Ticket/TicketUpstreamCallbackService.php`
- `backend/app/Services/Upstream/Contracts/ProvidesUpstreamQuoting.php`
- `backend/app/Services/Upstream/Drivers/HostingPanelApi/HostingPanelApiTransport.php`
- `backend/app/Services/User/Concerns/HandlesAdminUserServices.php`
- `backend/app/Services/User/UserService.php`
- `backend/app/Services/ZjmfUpstream/UpstreamProvisionService.php`
- `backend/app/Support/AdminPermissions.php`
- `backend/app/Support/CacheKey.php`
- `backend/app/Support/DatabaseSchema.php`
- `backend/app/Support/EmailNotificationTemplateDefaults.php`
- `backend/app/Support/MediaAssetTypes.php`
- `backend/app/Support/Money.php`
- `backend/app/Support/NotificationTemplateContent.php`
- `backend/app/Support/ProductConfigOptionPresenter.php`
- `backend/app/Support/ProductGroupHierarchyFields.php`
- `backend/app/Support/PublicUrl.php`
- `backend/app/Support/SiteProductGroupQuery.php`
- `backend/app/Support/SqlLike.php`
- `backend/app/Support/TextSanitizer.php`

**后端其他 `backend/`**

- `backend/bootstrap/app.php`
- `backend/composer.json`
- `backend/composer.lock`

**后端配置 `backend/config/`**

- `backend/config/alipay.php`
- `backend/config/cors.php`
- `backend/config/idc.php`
- `backend/config/queue.php`
- `backend/config/schedule_hooks.php`

**数据库迁移 `backend/database/`**

- `backend/database/migrations/2026_08_25_170000_create_supplier_balances_table.php`
- `backend/database/migrations/2026_08_25_180000_create_supplier_balance_logs_table.php`
- `backend/database/schema/mysql-schema.sql`
- `backend/database/seeders/SettingsSeeder.php`

**后端其他 `backend/`**

- `backend/lang/zh_CN/validation.php`
- `backend/phpstan-baseline.neon`

**后端插件 `backend/plugins/`**

- `backend/plugins/addons/zjmf_bridge/config.php`
- `backend/plugins/addons/zjmf_bridge/logic/ZjmfBridge.php`
- `backend/plugins/addons/zjmf_bridge/routes/client.php`
- `backend/plugins/addons/zjmf_bridge/routes/zjmf.php`
- `backend/plugins/addons/zjmf_bridge/src/Http/Controllers/AgentController.php`
- `backend/plugins/addons/zjmf_bridge/src/Http/Controllers/ZjmfBridge/AddonController.php`
- `backend/plugins/addons/zjmf_bridge/src/Http/Middleware/AuthenticateZjmfClient.php`
- `backend/plugins/addons/zjmf_bridge/src/Http/Middleware/LogZjmfBridgeRequest.php`
- `backend/plugins/addons/zjmf_bridge/src/Http/Middleware/VerifyZjmfSignature.php`
- `backend/plugins/addons/zjmf_bridge/src/Http/Middleware/ZjmfBridgeEnabled.php`
- `backend/plugins/addons/zjmf_bridge/src/Providers/ZjmfBridgeServiceProvider.php`
- `backend/plugins/addons/zjmf_bridge/src/Services/ZjmfFinanceService.php`
- `backend/plugins/addons/zjmf_bridge/src/Services/ZjmfProductCatalogService.php`
- `backend/plugins/addons/zjmf_bridge/src/Services/ZjmfTokenService.php`
- `backend/plugins/addons/zjmf_bridge/tests/Feature/ZjmfBridgeFinanceTest.php`
- `backend/plugins/addons/zjmf_bridge/tests/Feature/ZjmfBridgeProductTest.php`
- `backend/plugins/addons/zjmf_bridge/tests/Feature/ZjmfBridgeServiceTest.php`
- `backend/plugins/addons/zjmf_bridge/tests/Feature/ZjmfBridgeTest.php`
- `backend/plugins/addons/zjmf_bridge/tests/Feature/ZjmfBridgeTicketTest.php`
- `backend/plugins/addons/zjmf_bridge/tests/Unit/LogZjmfBridgeRequestTest.php`
- `backend/plugins/captcha/corptcha/config.php`
- `backend/plugins/captcha/geetest/config.php`
- `backend/plugins/captcha/geetest/lib/GeetestCaptchaService.php`
- `backend/plugins/captcha/vaptcha/config.php`
- `backend/plugins/certification/baidu_face/logic/BaiduFaceClient.php`
- `backend/plugins/certification/leaf_face/logic/LeafFaceClient.php`
- `backend/plugins/certification/stay33/config.php`
- `backend/plugins/certification/stay33/logic/Stay33Client.php`
- `backend/plugins/gateways/ali_pay/controller/IndexController.php`
- `backend/plugins/gateways/ali_pay/lib/AlipayClient.php`
- `backend/plugins/gateways/demo_pay/controller/IndexController.php`
- `backend/plugins/mail/smtp/config.php`
- `backend/plugins/mail/smtp/lib/SmtpMailService.php`
- `backend/plugins/servers/kanghostx/lib/KangHostxClient.php`
- `backend/plugins/servers/kanghostx/logic/KangHostx.php`
- `backend/plugins/servers/tura_open_api/README.md`
- `backend/plugins/servers/tura_open_api/config.php`
- `backend/plugins/servers/tura_open_api/lib/TuraOpenApiClient.php`
- `backend/plugins/servers/tura_open_api/logic/TuraOpenApi.php`
- `backend/plugins/servers/zjmf_finance/lib/ZjmfBillingRestoreService.php`
- `backend/plugins/servers/zjmf_finance/lib/ZjmfCatalogService.php`
- `backend/plugins/servers/zjmf_finance/lib/ZjmfFinanceAdapter.php`
- `backend/plugins/servers/zjmf_finance/lib/ZjmfFinanceTransport.php`
- `backend/plugins/servers/zjmf_finance/lib/ZjmfProvisionService.php`
- `backend/plugins/servers/zjmf_finance/lib/ZjmfRenewService.php`
- `backend/plugins/servers/zjmf_finance/lib/ZjmfStatusService.php`
- `backend/plugins/servers/zjmf_finance/src/Commands/RestoreZjmfBillingCommand.php`
- `backend/plugins/servers/zjmf_finance/src/Providers/ZjmfFinanceServiceProvider.php`
- `backend/plugins/sms/aliyun/config.php`
- `backend/plugins/sms/aliyun/lib/AliyunSmsClient.php`
- `backend/plugins/sms/aliyun/lib/AliyunSmsService.php`
- `backend/plugins/sms/stay33/lib/Stay33SmsClient.php`

**后端其他 `backend/`**

- `backend/routes/api.php`
- `backend/routes/console.php`
- `backend/routes/v2-admin.php`
- `backend/routes/v2-client.php`
- `backend/routes/web.php`
- `backend/scripts/README.md`
- `backend/scripts/build_frontends.mjs`
- `backend/scripts/check_docs.mjs`
- `backend/scripts/export_api_inventory.php`
- `backend/scripts/install_db.py`
- `backend/scripts/sensitive_file_scan.mjs`

**后端测试 `backend/tests/`**

- `backend/tests/Feature/AdminIntegrationPluginControllerTest.php`
- `backend/tests/Feature/AdminLoginAsRedirectUrlTest.php`
- `backend/tests/Feature/AdminLoginFailureLockTest.php`
- `backend/tests/Feature/AdminPermissionCatalogTest.php`
- `backend/tests/Feature/BackendHealthFixRegressionTest.php`
- `backend/tests/Feature/BackendStructureBoundaryTest.php`
- `backend/tests/Feature/BaiduFacePluginTest.php`
- `backend/tests/Feature/ClientAuthEnumerationTest.php`
- `backend/tests/Feature/ClientInvoiceInvoiceOnlyRegressionTest.php`
- `backend/tests/Feature/ClientReferralRegressionTest.php`
- `backend/tests/Feature/ClientServiceConsoleCategoryTitleFallbackTest.php`
- `backend/tests/Feature/DatabaseEngineeringCommandTest.php`
- `backend/tests/Feature/KangHostxPluginTest.php`
- `backend/tests/Feature/LocalProductSimulationTest.php`
- `backend/tests/Feature/MultiSmtpRoundRobinPluginTest.php`
- `backend/tests/Feature/NotificationTemplateApiTest.php`
- `backend/tests/Feature/OpenApi/ApiKeyTest.php`
- `backend/tests/Feature/OrderQuantityCheckoutFlowTest.php`
- `backend/tests/Feature/PaymentCallbackGatewayBindingTest.php`
- `backend/tests/Feature/PluginRuntimeRegistryIntegrationTest.php`
- `backend/tests/Feature/PluginSimulationTest.php`
- `backend/tests/Feature/ProductPurchaseRequiresCheckoutTest.php`
- `backend/tests/Feature/RechargeGatewayFailureTest.php`
- `backend/tests/Feature/ReferralRewardLifecycleRegressionTest.php`
- `backend/tests/Feature/ScheduleTaskExecutionCoverageTest.php`
- `backend/tests/Feature/SeparatedOriginCorsTest.php`
- `backend/tests/Feature/ServiceRenewInvoiceOnlyIdempotencyTest.php`
- `backend/tests/Feature/SiteCatalogCacheInvalidationTest.php`
- `backend/tests/Feature/SiteProductGroupIsolationTest.php`
- `backend/tests/Feature/Stay33SmsPluginTest.php`
- `backend/tests/Feature/SupplierBalanceSyncTest.php`
- `backend/tests/Feature/SupplierBatchConnectRegressionTest.php`
- `backend/tests/Feature/TicketServiceRegressionTest.php`
- `backend/tests/Feature/UploadSecurityTest.php`
- `backend/tests/Feature/V2AdminConfigurationApiTest.php`
- `backend/tests/Feature/V2AdminDashboardApiTest.php`
- `backend/tests/Feature/V2AdminLogApiTest.php`
- `backend/tests/Feature/V2AdminSupplierWriteAndRemoteApiTest.php`
- `backend/tests/Feature/V2AdminTicketActionApiTest.php`
- `backend/tests/Feature/V2ProductApiTest.php`
- `backend/tests/Feature/V2TicketApiTest.php`
- `backend/tests/Feature/ZjmfBillingRestoreCommandTest.php`
- `backend/tests/Unit/IntegrationDtoTest.php`
- `backend/tests/Unit/LoginRiskControlServiceTest.php`
- `backend/tests/Unit/MoneyTest.php`
- `backend/tests/Unit/NotificationTemplateContentTest.php`
- `backend/tests/Unit/ProductionMigrationSafetyTest.php`
- `backend/tests/Unit/ProviderResolverTest.php`
- `backend/tests/Unit/PublicUrlTest.php`
- `backend/tests/Unit/Services/Finance/AgentDiscountCheckoutTest.php`
- `backend/tests/Unit/ZjmfCloudConfigTemplateTest.php`
- `backend/tests/Unit/ZjmfConsoleAndNetworkServiceTest.php`
- `backend/tests/Unit/ZjmfCredentialParserTest.php`
- `backend/tests/Unit/ZjmfFinanceTransportTest.php`
- `backend/tests/Unit/ZjmfLegacyPasswordVerifierTest.php`
- `backend/tests/Unit/ZjmfProvisionCartSessionTest.php`
- `backend/tests/Unit/ZjmfRenewAndStatusServiceTest.php`
- `backend/tests/Unit/ZjmfServiceProviderTest.php`

**部署脚本 `deploy/`**

- `deploy/docker/.env.example`
- `deploy/docker/README.md`
- `deploy/docker/docker-compose.yml`
- `deploy/docker/frontends/Dockerfile`
- `deploy/docker/frontends/nginx-default.conf`

**文档 `docs*/`**

- `docs-web/.gitignore`
- `docs-web/.vitepress/config.ts`
- `docs-web/.vitepress/theme/components/DeploymentSelector.vue`
- `docs-web/.vitepress/theme/components/HomePortal.vue`
- `docs-web/package.json`
- `docs/README.md`
- `docs/catalog.json`

**仓库其他**

- `eslint.config.js`

**前端 `frontend-*/`**

- `frontend-admin-v3/.gitignore`
- `frontend-admin-v3/CHANGELOG.md`
- `frontend-admin-v3/PUBLISH.md`
- `frontend-admin-v3/README.md`
- `frontend-admin-v3/eslint.config.js`
- `frontend-admin-v3/package.json`
- `frontend-admin-v3/playwright.config.ts`
- `frontend-admin-v3/src/api/admin/logs.ts`
- `frontend-admin-v3/src/api/admin/plugins.ts`
- `frontend-admin-v3/src/api/admin/ticket.ts`
- `frontend-admin-v3/src/api/admin/types.ts`
- `frontend-admin-v3/src/api/product.ts`
- `frontend-admin-v3/src/api/supplier.ts`
- `frontend-admin-v3/src/assets/assets-logo-full.svg`
- `frontend-admin-v3/src/components/finance-record-detail/InvoiceDetailDrawer.vue`
- `frontend-admin-v3/src/components/mobile-record-card/index.vue`
- `frontend-admin-v3/src/components/product-binding-tree-select/index.vue`
- `frontend-admin-v3/src/components/record-detail-page/index.vue`
- `frontend-admin-v3/src/components/status-tag/index.vue`
- `frontend-admin-v3/src/constants/permissions.ts`
- `frontend-admin-v3/src/hooks/useGeeTestCaptcha.ts`
- `frontend-admin-v3/src/hooks/useProductBindingTree.ts`
- `frontend-admin-v3/src/layouts/components/Content.vue`
- `frontend-admin-v3/src/layouts/components/Header.vue`
- `frontend-admin-v3/src/layouts/components/LayoutHeader.vue`
- `frontend-admin-v3/src/layouts/components/LayoutSideNav.vue`
- `frontend-admin-v3/src/layouts/components/MenuContent.vue`
- `frontend-admin-v3/src/layouts/components/SideNav.vue`
- `frontend-admin-v3/src/layouts/setting.vue`
- `frontend-admin-v3/src/locales/lang/zh_CN.json`
- `frontend-admin-v3/src/locales/useLocale.ts`
- `frontend-admin-v3/src/pages/admin/system/roles/index.vue`
- `frontend-admin-v3/src/pages/agent-discounts/index.vue`
- `frontend-admin-v3/src/pages/content/edit.vue`
- `frontend-admin-v3/src/pages/content/index.less`
- `frontend-admin-v3/src/pages/content/index.vue`
- `frontend-admin-v3/src/pages/dashboard/base/index.vue`
- `frontend-admin-v3/src/pages/finance/new-customers/index.vue`
- `frontend-admin-v3/src/pages/finance/orders/detail/index.vue`
- `frontend-admin-v3/src/pages/finance/orders/index.vue`
- `frontend-admin-v3/src/pages/finance/recharges/index.vue`
- `frontend-admin-v3/src/pages/integration-plugins/index.less`
- `frontend-admin-v3/src/pages/integration-plugins/index.vue`
- `frontend-admin-v3/src/pages/login/index.vue`
- `frontend-admin-v3/src/pages/logs/components/LogDetailDrawer.vue`
- `frontend-admin-v3/src/pages/logs/index.less`
- `frontend-admin-v3/src/pages/logs/index.vue`
- `frontend-admin-v3/src/pages/member-levels/index.vue`
- `frontend-admin-v3/src/pages/notifications/email-template-detail/index.vue`
- `frontend-admin-v3/src/pages/notifications/index.vue`
- `frontend-admin-v3/src/pages/open-api/index.vue`
- `frontend-admin-v3/src/pages/products/components/ProductCatalog.vue`
- `frontend-admin-v3/src/pages/products/components/Suppliers.vue`
- `frontend-admin-v3/src/pages/products/composables/useProductShared.ts`
- `frontend-admin-v3/src/pages/products/coupon-campaigns/index.vue`
- `frontend-admin-v3/src/pages/products/coupons/index.vue`
- `frontend-admin-v3/src/pages/products/cpu-models/index.vue`
- `frontend-admin-v3/src/pages/products/edit.vue`
- `frontend-admin-v3/src/pages/products/index.less`
- `frontend-admin-v3/src/pages/products/specs/index.vue`
- `frontend-admin-v3/src/pages/referral/index.vue`
- `frontend-admin-v3/src/pages/services/detail/cdn/CdnConsolePanel.vue`
- `frontend-admin-v3/src/pages/services/detail/cdn/registry.ts`
- `frontend-admin-v3/src/pages/services/detail/cdn/tabs/CdnOverviewTab.vue`
- `frontend-admin-v3/src/pages/services/detail/console/composables/useConsoleCore.ts`
- `frontend-admin-v3/src/pages/services/detail/console/registry.ts`
- `frontend-admin-v3/src/pages/services/detail/console/tabs/AreaTab.vue`
- `frontend-admin-v3/src/pages/services/detail/console/tabs/PanelTab.vue`
- `frontend-admin-v3/src/pages/services/detail/console/tabs/panelSpecs.ts`
- `frontend-admin-v3/src/pages/services/detail/console/types.ts`
- `frontend-admin-v3/src/pages/services/detail/index.vue`
- `frontend-admin-v3/src/pages/services/index.vue`
- `frontend-admin-v3/src/pages/settings/index.less`
- `frontend-admin-v3/src/pages/settings/index.vue`
- `frontend-admin-v3/src/pages/tickets/delivery-settings/index.less`
- `frontend-admin-v3/src/pages/tickets/delivery-settings/index.vue`
- `frontend-admin-v3/src/pages/tickets/detail/index.less`
- `frontend-admin-v3/src/pages/tickets/detail/index.vue`
- `frontend-admin-v3/src/pages/tickets/pre-reply-settings/index.less`
- `frontend-admin-v3/src/pages/tickets/pre-reply-settings/index.vue`
- `frontend-admin-v3/src/pages/users/components/VerificationPanel.vue`
- `frontend-admin-v3/src/pages/users/detail/index.vue`
- `frontend-admin-v3/src/pages/users/index.vue`
- `frontend-admin-v3/src/permission.ts`
- `frontend-admin-v3/src/router/index.ts`
- `frontend-admin-v3/src/router/modules/admin/system.ts`
- `frontend-admin-v3/src/router/modules/admin/tickets.ts`
- `frontend-admin-v3/src/store/modules/permission.ts`
- `frontend-admin-v3/src/store/modules/setting.ts`
- `frontend-admin-v3/src/store/modules/tabs-router.ts`
- `frontend-admin-v3/src/style/layout.less`
- `frontend-admin-v3/src/utils/request/index.ts`
- `frontend-admin-v3/stylelint.config.js`
- `frontend-admin-v3/tests/e2e/smoke.spec.ts`
- `frontend-admin-v3/tests/unit/apiAssetUrl.spec.ts`
- `frontend-admin-v3/tsconfig.json`
- `frontend-admin-v3/vite.config.ts`
- `frontend-user-v3-www/.env.example`
- `frontend-user-v3-www/README.md`
- `frontend-user-v3-www/auto-imports.d.ts`
- `frontend-user-v3-www/components.d.ts`
- `frontend-user-v3-www/eslint.config.js`
- `frontend-user-v3-www/index.html`
- `frontend-user-v3-www/package.json`
- `frontend-user-v3-www/src/api/productCatalogNormalizer.js`
- `frontend-user-v3-www/src/api/site.js`
- `frontend-user-v3-www/src/app/http/request.ts`
- `frontend-user-v3-www/src/app/router/guards.ts`
- `frontend-user-v3-www/src/app/stores/siteBranding.ts`
- `frontend-user-v3-www/src/assets/styles/global.scss`
- `frontend-user-v3-www/src/components/MobileRegionPicker.vue`
- `frontend-user-v3-www/src/data/supportContacts.js`
- `frontend-user-v3-www/src/layout/WebsiteLayout.vue`
- `frontend-user-v3-www/src/layout/useNavProductMenu.js`
- `frontend-user-v3-www/src/utils/consoleUrl.js`
- `frontend-user-v3-www/src/utils/websiteProductConfig.js`
- `frontend-user-v3-www/src/views/website/Home/components/HomeHeroCarousel.vue`
- `frontend-user-v3-www/src/views/website/Home/components/HomeSectionSkeleton.vue`
- `frontend-user-v3-www/src/views/website/ProductDetail/index.vue`
- `frontend-user-v3-www/src/views/website/content/ContentDetailPage.vue`
- `frontend-user-v3-www/src/views/website/products/index.vue`
- `frontend-user-v3-www/src/views/website/products/page.scss`
- `frontend-user-v3-www/src/views/website/products/useWebsiteProductCheckout.js`
- `frontend-user-v3-www/src/views/website/products/useWebsiteProductConfigurator.js`
- `frontend-user-v3-www/src/views/website/products/useWebsiteProductsCatalog.js`
- `frontend-user-v3-www/vite.config.js`
- `frontend-user-v4-console/.gitignore`
- `frontend-user-v4-console/CHANGELOG.md`
- `frontend-user-v4-console/PUBLISH.md`
- `frontend-user-v4-console/README.md`
- `frontend-user-v4-console/eslint.config.js`
- `frontend-user-v4-console/index.html`
- `frontend-user-v4-console/package.json`
- `frontend-user-v4-console/playwright.config.ts`
- `frontend-user-v4-console/playwright.local-reuse.config.ts`
- `frontend-user-v4-console/src/api/client.ts`
- `frontend-user-v4-console/src/app/stores/siteBranding.ts`
- `frontend-user-v4-console/src/components/auth/AuthShell.vue`
- `frontend-user-v4-console/src/composables/useGeeTestCaptcha.ts`
- `frontend-user-v4-console/src/data/supportContacts.ts`
- `frontend-user-v4-console/src/domains/account/profileState.ts`
- `frontend-user-v4-console/src/domains/account/useProfile.ts`
- `frontend-user-v4-console/src/domains/finance/useInvoices.ts`
- `frontend-user-v4-console/src/domains/finance/useOrders.ts`
- `frontend-user-v4-console/src/domains/finance/useRecharge.ts`
- `frontend-user-v4-console/src/domains/finance/useRecords.ts`
- `frontend-user-v4-console/src/domains/services/console/useConsoleAreaTicket.ts`
- `frontend-user-v4-console/src/domains/services/console/useConsoleCore.ts`
- `frontend-user-v4-console/src/domains/services/console/useConsolePower.ts`
- `frontend-user-v4-console/src/domains/services/console/useConsoleSecurity.ts`
- `frontend-user-v4-console/src/domains/services/console/useConsoleTabs.ts`
- `frontend-user-v4-console/src/domains/services/useServiceCenter.ts`
- `frontend-user-v4-console/src/layouts/components/Header.vue`
- `frontend-user-v4-console/src/layouts/components/LayoutHeader.vue`
- `frontend-user-v4-console/src/layouts/components/SideNav.vue`
- `frontend-user-v4-console/src/layouts/setting.vue`
- `frontend-user-v4-console/src/locales/lang/en_US.json`
- `frontend-user-v4-console/src/locales/lang/zh_CN.json`
- `frontend-user-v4-console/src/locales/useLocale.ts`
- `frontend-user-v4-console/src/main.ts`
- `frontend-user-v4-console/src/pages/client-auth/forgot-password.vue`
- `frontend-user-v4-console/src/pages/client-auth/login.vue`
- `frontend-user-v4-console/src/pages/client-auth/register.vue`
- `frontend-user-v4-console/src/pages/client-auth/shared-auth.less`
- `frontend-user-v4-console/src/pages/client/catalog/index.vue`
- `frontend-user-v4-console/src/pages/client/checkout-resume/index.vue`
- `frontend-user-v4-console/src/pages/client/content-detail/ContentDetailPage.vue`
- `frontend-user-v4-console/src/pages/client/content-list/ContentListPage.vue`
- `frontend-user-v4-console/src/pages/client/coupons/index.vue`
- `frontend-user-v4-console/src/pages/client/dashboard/index.vue`
- `frontend-user-v4-console/src/pages/client/invoice-detail-view/index.vue`
- `frontend-user-v4-console/src/pages/client/invoice-detail/index.vue`
- `frontend-user-v4-console/src/pages/client/invoices/index.vue`
- `frontend-user-v4-console/src/pages/client/order-create/index.vue`
- `frontend-user-v4-console/src/pages/client/order-detail/index.vue`
- `frontend-user-v4-console/src/pages/client/orders/index.vue`
- `frontend-user-v4-console/src/pages/client/payment-detail/index.vue`
- `frontend-user-v4-console/src/pages/client/profile/index.vue`
- `frontend-user-v4-console/src/pages/client/service-console/components/ConsoleHeader.vue`
- `frontend-user-v4-console/src/pages/client/service-console/components/cdn/registry.ts`
- `frontend-user-v4-console/src/pages/client/service-console/components/cdn/tabs/CdnOverviewTab.vue`
- `frontend-user-v4-console/src/pages/client/service-console/components/styles.less`
- `frontend-user-v4-console/src/pages/client/service-console/components/tabs/AreaTab.vue`
- `frontend-user-v4-console/src/pages/client/service-console/components/tabs/FinanceTab.vue`
- `frontend-user-v4-console/src/pages/client/service-console/components/virtualhost/registry.ts`
- `frontend-user-v4-console/src/pages/client/service-console/components/virtualhost/tabs/VirtualHostOverviewTab.vue`
- `frontend-user-v4-console/src/pages/client/service-console/index.vue`
- `frontend-user-v4-console/src/pages/client/services/index.vue`
- `frontend-user-v4-console/src/pages/client/tickets/index.vue`
- `frontend-user-v4-console/src/pages/result/500/index.vue`
- `frontend-user-v4-console/src/permission.ts`
- `frontend-user-v4-console/src/router/index.ts`
- `frontend-user-v4-console/src/router/modules/client.ts`
- `frontend-user-v4-console/src/store/modules/permission.ts`
- `frontend-user-v4-console/src/store/modules/setting.ts`
- `frontend-user-v4-console/src/store/modules/user.ts`
- `frontend-user-v4-console/src/style/index.less`
- `frontend-user-v4-console/src/style/layout.less`
- `frontend-user-v4-console/src/style/reset.less`
- `frontend-user-v4-console/src/types/client.ts`
- `frontend-user-v4-console/stylelint.config.js`
- `frontend-user-v4-console/tsconfig.json`
- `frontend-user-v4-console/vite.config.ts`

**仓库其他**

- `package.json`
- `pnpm-lock.yaml`

**共享包 `shared/`**

- `shared/components/TicketChatPanel.vue`
- `shared/content/createMarkdownRenderer.ts`
- `shared/eslint.config.js`
- `shared/package.json`
- `shared/runtime/branding.ts`
- `shared/runtime/http/core.ts`
- `shared/statusConfig.js`
- `shared/tests/branding-splash.test.mjs`
- `shared/tsconfig.json`

**仓库其他**

- `theme.css`
- `tsconfig.base.json`

## 6. 签署记录（字段与存放约定）

| 字段           | 说明                                              | 是否公开         |
| -------------- | ------------------------------------------------- | ---------------- |
| GitHub 用户名  | 贡献者在 GitHub 上的标识                          | 是（仅已授权者） |
| 身份组         | 对应 §4 的归并身份                                | 是（仅已授权者） |
| 签署范围       | 仅新贡献 / 含 2026-10-21 前历史贡献               | 否               |
| 签署时间       | 声明作出时间                                      | 否               |
| 声明原文与取证 | PR 评论 id 与原始 JSON / 邮件 Message-ID / 盖章件 | 否               |
| 留档位置       | 项目方私有记录（邮件归档）                        | 否               |

留存期限建议：至相关著作权保护期届满后 5 年；其中个人信息在签署核对目的实现后按最短必要期限处理。

更正通道：贡献者如认为本清单的身份归并或文件分类有误，可在 7 个工作日内向 `contact@turaidc.com` 反馈，项目方核实后更新本文并在变更记录中说明。

## 7. 商业交付前核对清单

- [ ] §3 的导入历史权属证据已留存，`remotes/caiwu/main` 已处置
- [ ] §4 中三个项目方身份组已书面确认归属
- [ ] 全部外部贡献者的再许可授权已取得（R1），或已按 R2 完成独立实现并留存比对记录
- [ ] 商业版构建产物已逐文件比对本文清单，比对记录随交付归档
- [ ] 官方 SaaS / 演示站运行的是 AGPL 代码树，并提供对应源码
- [ ] 交付物保留 `LICENSE`（AGPL-3.0-or-later）与版权声明，AGPL 公众权利未被削弱
- [ ] 授权链条与 `LICENSE-COMMERCIAL.md` 条款一致

## 8. 待办

1. **优先推 R1**：向 9 组外部贡献者发函补签（每人一次邮件 + 一句声明），成本远低于重写 550 个混合文件。
2. 取证导入历史（§3）与三个项目方身份组的权属证据。
3. 委托法务决定三件事：R2 独立实现的举证标准、AGPL 双许可模式在中国法下的效力、电子签署的证明力。
4. 逐人签署状态转入私有记录，仓库内只保留本文的公开安全内容。

## 关联文件

- [`CLA.md`](../../../CLA.md)：贡献者许可协议 v1.0（2026-10-21 生效）。
- [`LICENSE-COMMERCIAL.md`](../../../LICENSE-COMMERCIAL.md)：商业许可草案。
- [`cla-feasibility-2026-10-21.md`](cla-feasibility-2026-10-21.md)：双许可链条的工程侧核对。
- [`cla-outreach-template.md`](cla-outreach-template.md)：R1 补签执行包（联系函模板、声明原文、记录表）。
- [`docs/guide/licensing.md`](../../guide/licensing.md)：站长/企业视角的双许可说明。
