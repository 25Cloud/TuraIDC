---
status: current
updated: 2026-10-21
owner: lead
---

# CLA 签署记录与历史贡献覆盖清单

> 本文是 [`CLA.md`](../../../CLA.md)（v1.0，2026-10-21 生效）与 [CLA 可行性核对](cla-feasibility-2026-10-21.md) 承诺的「贡献者签署记录 + 历史贡献覆盖清单」的落地件。
>
> 数据来源：仓库 git 全量历史（截至 `679c145b`，1171 次提交，2892 个跟踪文件）。
> 核心口径：**未签署的第三方历史贡献不进入商业许可版本**；商业授权每次交付前按 §4 逐条核对。

## 1. 怎么用这份清单

| 场景                    | 处理                                                                                                |
| ----------------------- | --------------------------------------------------------------------------------------------------- |
| 2026-10-21 之后的新贡献 | 合并前必须签署 `CLA.md`（PR 评论声明或邮件），签署后登记到 §3                                       |
| 生效日前的历史贡献      | 默认按原 AGPL 授权，不自动适用 CLA；贡献者自愿补签的，登记到 §3 并从 §4 排除清单移除                |
| 商业版本交付            | 交付前逐条核对 §4：未签署且仍在排除清单里的文件，要么先补签，要么用项目方自有实现替换后再进商业版本 |
| 身份存疑                | 来源无法确认的提交按「不包含」处理（对应 `CLA.md` 开头第 4 条）                                     |

## 2. 历史贡献者与身份归并

同一自然人在 git 历史里可能有多个身份（换邮箱、GitHub noreply 邮箱、本机导入账号），下面按身份组归并；「触及文件数」指该身份组写过、且当前仍在仓库中的文件数。

| 身份组                       | git 身份                                                           | 提交数 | 触及文件 | 判定                               | 签署状态                           |
| ---------------------------- | ------------------------------------------------------------------ | -----: | -------: | ---------------------------------- | ---------------------------------- |
| 项目方 A                     | `2908990438@qq.com`                                                |    509 |     2099 | 项目方自有贡献，可直接用于商业版本 | 不适用（待项目方书面确认身份归属） |
| 项目方 B                     | `wujifan01@163.com`, `wujiyan@wujiyan.cc`                          |    362 |     2731 | 项目方自有贡献，可直接用于商业版本 | 不适用（待项目方书面确认身份归属） |
| 导入历史本机账号             | `admin@5ixqubh8rc21q4z.cn-hangzhou-198-13-1.wuying.local`          |      1 |      339 | 项目方自有贡献，可直接用于商业版本 | 不适用（待项目方书面确认身份归属） |
| yealqp / 仙林loy             | `yealqp@126.com`, `114379504+yealqp@users.noreply.github.com`      |    115 |      279 | 第三方历史贡献                     | 未签署                             |
| Shanshui 山重水复            | `shanshui@shanshui.site`, `axtbot@shanshui.site`                   |     46 |      197 | 第三方历史贡献                     | 未签署                             |
| YeHuaiJing 叶淮璟            | `136069688+yehuaijing@users.noreply.github.com`, `furtime@163.com` |     50 |      134 | 第三方历史贡献                     | 未签署                             |
| PigFully / PingFully         | `linkserhu@gmail.com`, `bigcatcat1@foxmail.com`                    |     41 |      308 | 第三方历史贡献                     | 未签署                             |
| 咕咕摆烂真君 / Gugubailan520 | `241669272@qq.com`, `3840032310@qq.com`                            |      4 |        7 | 第三方历史贡献                     | 未签署                             |
| xiobb                        | `68597070+yellowface233@users.noreply.github.com`                  |     18 |        4 | 第三方历史贡献                     | 未签署                             |
| xioiox                       | `hi@586866.xyz`                                                    |     14 |       42 | 第三方历史贡献                     | 未签署                             |
| lan134342                    | `13695020650@163.com`                                              |      8 |       29 | 第三方历史贡献                     | 未签署                             |
| FurLLCN                      | `github@furll.cn`                                                  |      2 |        3 | 第三方历史贡献                     | 未签署                             |
| Cascade（来源待核实）        | `cascade@example.local`                                            |      1 |        1 | 来源待核实，暂按第三方处理         | 未签署                             |

**需要项目方书面确认的三件事**：

1. 「项目方 A」「项目方 B」「导入历史本机账号」三个身份组是否都属于项目方（仓库所有者及其维护团队）。确认前，商业版本的授权链条按「未确认即不包含」处理。
2. `cascade@example.local` 的来源（1 次提交、1 个文件），核实后决定归入项目方还是第三方。
3. 外部贡献者中是否存在雇佣/委托关系（职务作品），需要组织 CLA 衔接——见 `CLA.md` 第四部分第 2 条。

## 3. 签署记录

签署一次即覆盖此后全部贡献（`CLA.md` 第 9 条）。补签历史贡献的，需在声明中追加「并适用于我在 2026-10-21 前已提交的全部贡献」。

| 身份组 | GitHub 用户名 | 签署范围 | 签署时间 | 声明原文出处 | 登记人 |
| ------ | ------------- | -------- | -------- | ------------ | ------ |
| —      | —             | —        | —        | —            | —      |

> 当前为空：`CLA.md` 于 2026-10-21 生效，尚未产生需要签署的新贡献；历史补签进行中后逐条登记。
> 声明原文发往 `contact@turaidc.com`（主题「CLA 签署 - GitHub 用户名」），公开名单只展示 GitHub 用户名。

## 4. 历史贡献覆盖清单（未签署 → 商业版本默认排除）

下列 156 个文件在 git 历史中**只被外部（未签署）贡献者写过**，项目方自有提交没有碰过，因此在对方补签前不进入商业许可版本。

| 区域                              | 文件数 |
| --------------------------------- | -----: |
| 后端核心运行时代码 `backend/app/` |     45 |
| 后端测试 `backend/tests/`         |     31 |
| 后端插件 `backend/plugins/`       |     25 |
| 前端 `frontend-*/`                |     13 |
| 数据库迁移 `backend/database/`    |     11 |
| 部署脚本 `deploy/`                |     10 |
| 文档 `docs*/`                     |      6 |
| 共享包 `shared/`                  |      6 |
| 后端配置 `backend/config/`        |      4 |
| 仓库级配置（`.github/` 等）       |      3 |
| 后端其他 `backend/`               |      1 |
| 仓库其他                          |      1 |

### 4.1 按贡献者

- **PigFully / PingFully**：75 个文件
- **YeHuaiJing 叶淮璟**：41 个文件
- **yealqp / 仙林loy**：40 个文件
- **Shanshui 山重水复**：32 个文件
- **xioiox**：6 个文件
- **lan134342**：5 个文件
- **咕咕摆烂真君 / Gugubailan520**：4 个文件
- **xiobb**：1 个文件

### 4.2 文件清单

**仓库级配置（`.github/` 等）**

- `.dockerignore`
- `.github/CODE_OF_CONDUCT.md`
- `.github/workflows/docker-image.yml`

**后端核心运行时代码 `backend/app/`**

- `backend/app/Console/Commands/CaptchaSceneCommand.php`
- `backend/app/Console/Commands/CleanupUnusedUpstreamUploadsCommand.php`
- `backend/app/Console/Commands/InstallCommand.php`
- `backend/app/Constants/BillingCycle.php`
- `backend/app/Http/Controllers/Admin/V2/TicketDeliveryController.php`
- `backend/app/Http/Controllers/Admin/V2/TicketPreReplyController.php`
- `backend/app/Http/Controllers/Client/V2/TicketUpstreamCallbackController.php`
- `backend/app/Http/Controllers/Client/V2/TicketUpstreamUploadController.php`
- `backend/app/Http/Controllers/InstallController.php`
- `backend/app/Http/Middleware/TicketUpstreamUploadThrottle.php`
- `backend/app/Http/Middleware/VerifyTicketUpstreamUploadToken.php`
- `backend/app/Http/Middleware/WebApplicationFirewall.php`
- `backend/app/Http/Requests/Admin/V2/Product/ProductSortOrderRequest.php`
- `backend/app/Http/Requests/Admin/V2/Ticket/ListTicketUpstreamDeliveryLogsRequest.php`
- `backend/app/Http/Requests/Admin/V2/Ticket/SaveTicketPreReplyRequest.php`
- `backend/app/Http/Requests/Admin/V2/Ticket/SaveTicketUpstreamUploadGuardRequest.php`
- `backend/app/Http/Requests/Admin/V2/Ticket/UpsertTicketDeliveryRuleRequest.php`
- `backend/app/Http/Resources/Admin/V2/TicketDeliveryRuleResource.php`
- `backend/app/Http/Resources/Admin/V2/TicketUpstreamDeliveryLogResource.php`
- `backend/app/Jobs/DeliverTicketReplyToUpstreamJob.php`
- `backend/app/Jobs/DeliverTicketToUpstreamJob.php`
- `backend/app/Jobs/ResyncMemberLevelsJob.php`
- `backend/app/Jobs/SyncSupplierBalanceJob.php`
- `backend/app/Models/SupplierBalance.php`
- `backend/app/Models/SupplierBalanceLog.php`
- `backend/app/Models/TicketDeliveryRule.php`
- `backend/app/Models/TicketReplyDelivery.php`
- `backend/app/Models/TicketUpstreamBinding.php`
- `backend/app/Models/TicketUpstreamDeliveryLog.php`
- `backend/app/Rules/KnownApiScopeDomains.php`
- `backend/app/Services/Auth/CaptchaPolicyService.php`
- `backend/app/Services/Automation/Heartbeat/QueuedRunOutcome.php`
- `backend/app/Services/Install/InstallException.php`
- `backend/app/Services/ProductCatalog/SiteCatalogCacheInvalidator.php`
- `backend/app/Services/Supplier/AdminSupplierBalanceNotifier.php`
- `backend/app/Services/Ticket/TicketDeliveryService.php`
- `backend/app/Services/Ticket/TicketPreReplyService.php`
- `backend/app/Services/Ticket/TicketUpstreamCallbackToken.php`
- `backend/app/Support/DeferredJoinPaginator.php`
- `backend/app/Support/PanelAccessExtractor.php`
- `backend/app/Support/RichHtmlSanitizer.php`
- `backend/app/Support/SmsNotificationTemplateDefaults.php`
- `backend/app/Support/SqlIdentifier.php`
- `backend/app/Support/Waf/KeySanitizer.php`
- `backend/app/Support/Waf/PayloadScanner.php`

**后端配置 `backend/config/`**

- `backend/config/install.php`
- `backend/config/ticket_pre_reply.php`
- `backend/config/ticket_upstream.php`
- `backend/config/waf.php`

**数据库迁移 `backend/database/`**

- `backend/database/migrations/2026_08_20_120000_create_ticket_upstream_delivery_tables.php`
- `backend/database/migrations/2026_08_20_130000_add_ticket_delivery_settings.php`
- `backend/database/migrations/2026_08_20_140000_add_upstream_reply_metadata.php`
- `backend/database/migrations/2026_08_20_150000_add_ticket_delivery_rule_uniqueness.php`
- `backend/database/migrations/2026_08_20_160000_create_ticket_upstream_delivery_logs.php`
- `backend/database/migrations/2026_08_21_000001_make_referral_rewards_order_id_nullable.php`
- `backend/database/migrations/2026_08_23_120000_backfill_ticket_delivery_manage_permission.php`
- `backend/database/migrations/2026_08_23_130000_backfill_ticket_pre_reply_manage_permission.php`
- `backend/database/migrations/2026_08_23_140000_add_is_pre_reply_to_ticket_replies.php`
- `backend/database/migrations/2026_08_26_090000_add_stock_synced_at_to_products_table.php`
- `backend/database/migrations/2026_08_29_000001_add_secret_hash_index_to_api_keys.php`

**后端插件 `backend/plugins/`**

- `backend/plugins/addons/zjmf_bridge/tests/Feature/ZjmfBridgeTokenForgeryTest.php`
- `backend/plugins/captcha/cap/CapPlugin.php`
- `backend/plugins/captcha/cap/config.php`
- `backend/plugins/captcha/cap/lib/CapCaptchaService.php`
- `backend/plugins/captcha/scene-switches.php`
- `backend/plugins/captcha/turnstile/TurnstilePlugin.php`
- `backend/plugins/captcha/turnstile/config.php`
- `backend/plugins/captcha/turnstile/lib/TurnstileCaptchaService.php`
- `backend/plugins/certification/alipay_certify/AlipayCertifyPlugin.php`
- `backend/plugins/certification/alipay_certify/README.md`
- `backend/plugins/certification/alipay_certify/config.php`
- `backend/plugins/certification/alipay_certify/logic/AlipayCertify.php`
- `backend/plugins/certification/alipay_certify/logic/AlipayCertifyClient.php`
- `backend/plugins/certification/leaf_face/LeafFacePlugin.php`
- `backend/plugins/certification/leaf_face/config.php`
- `backend/plugins/certification/leaf_face/logic/LeafFace.php`
- `backend/plugins/certification/smapi/README.md`
- `backend/plugins/certification/smapi/SmapiPlugin.php`
- `backend/plugins/certification/smapi/config.php`
- `backend/plugins/certification/smapi/logic/Smapi.php`
- `backend/plugins/certification/smapi/logic/SmapiClient.php`
- `backend/plugins/sms/aliyun_sms/AliyunSmsPlugin.php`
- `backend/plugins/sms/aliyun_sms/config.php`
- `backend/plugins/sms/aliyun_sms/lib/AliyunSmsApiClient.php`
- `backend/plugins/sms/aliyun_sms/lib/AliyunSmsApiService.php`

**后端其他 `backend/`**

- `backend/resources/views/install/index.blade.php`

**后端测试 `backend/tests/`**

- `backend/tests/Feature/AdminSettingsSanitizationTest.php`
- `backend/tests/Feature/AdminUpstreamDeliveryLogQueryTest.php`
- `backend/tests/Feature/AdminUserSessionRevocationTest.php`
- `backend/tests/Feature/AlipayCertifyPluginTest.php`
- `backend/tests/Feature/BackfillTicketDeliveryManagePermissionMigrationTest.php`
- `backend/tests/Feature/BackfillTicketPreReplyManagePermissionMigrationTest.php`
- `backend/tests/Feature/CaptchaCapPluginTest.php`
- `backend/tests/Feature/CaptchaMandatorySceneTest.php`
- `backend/tests/Feature/HotPathQueryPerformanceTest.php`
- `backend/tests/Feature/InstallDatabaseVersionGateTest.php`
- `backend/tests/Feature/IntegrationPluginSystemTest.php`
- `backend/tests/Feature/LeafFacePluginTest.php`
- `backend/tests/Feature/MixPaymentReservedBalanceTest.php`
- `backend/tests/Feature/PasswordChangeSessionRaceTest.php`
- `backend/tests/Feature/RenewAmountAndExpiredUnsuspendTest.php`
- `backend/tests/Feature/ScheduleTickSlotImmutabilityTest.php`
- `backend/tests/Feature/ServiceProvisionDataTokenSanitizationTest.php`
- `backend/tests/Feature/SmapiPluginTest.php`
- `backend/tests/Feature/SqlLikeEscapeTest.php`
- `backend/tests/Feature/TicketPreReplyTest.php`
- `backend/tests/Feature/TicketUpstreamCallbackAttachmentTest.php`
- `backend/tests/Feature/UpstreamStockSyncPacingTest.php`
- `backend/tests/Feature/VncRelayPrivateIpGuardTest.php`
- `backend/tests/Feature/WafMiddlewareTest.php`
- `backend/tests/Support/Concerns/UpstreamDeliveryWhitelist.php`
- `backend/tests/Unit/BillingCycleTest.php`
- `backend/tests/Unit/RichHtmlSanitizerTest.php`
- `backend/tests/Unit/TextSanitizerTest.php`
- `backend/tests/Unit/TicketUpstreamCallbackTokenTest.php`
- `backend/tests/Unit/WafKeySanitizerTest.php`
- `backend/tests/Unit/WafPayloadScannerTest.php`

**部署脚本 `deploy/`**

- `deploy/docker/backend/Dockerfile`
- `deploy/docker/backend/entrypoint.sh`
- `deploy/docker/backend/nginx.conf`
- `deploy/docker/backend/php.ini`
- `deploy/docker/backend/supervisord.conf`
- `deploy/docker/backup.sh`
- `deploy/docker/ci/Dockerfile.php`
- `deploy/docker/ci/docker-compose.ci.yml`
- `deploy/docker/ci/pint-fix-in-container.sh`
- `deploy/docker/ci/run-ci-local.sh`

**文档 `docs*/`**

- `docs-web/.vitepress/theme/Layout.vue`
- `docs-web/.vitepress/theme/index.ts`
- `docs-web/package-lock.json`
- `docs-web/public/branding/favicon.png`
- `docs-web/public/branding/turaidc-logo.png`
- `docs/images/qrcode_1787147945469.jpg`

**前端 `frontend-*/`**

- `frontend-admin-v3/src/pages/agent-discounts/index.less`
- `frontend-admin-v3/src/utils/route/adminHome.ts`
- `frontend-admin-v3/tests/e2e/agent-discounts.spec.ts`
- `frontend-admin-v3/tests/e2e/login-remember-account.spec.ts`
- `frontend-admin-v3/tests/e2e/plugin-config-collapsible.spec.ts`
- `frontend-admin-v3/tests/e2e/status-theme-regression.spec.ts`
- `frontend-admin-v3/tests/e2e/users-create-password.spec.ts`
- `frontend-user-v3-www/public/img/os/TencentOS.png`
- `frontend-user-v4-console/public/img/os/TencentOS.png`
- `frontend-user-v4-console/src/pages/client/account/index.ts`
- `frontend-user-v4-console/src/utils/publicSite.ts`
- `frontend-user-v4-console/tests/e2e/auth-shell-dark.spec.ts`
- `frontend-user-v4-console/tests/e2e/profile-change-password.spec.ts`

**仓库其他**

- `pnpm-workspace.yaml`

**共享包 `shared/`**

- `shared/billingCycle.d.ts`
- `shared/billingCycle.js`
- `shared/captcha.ts`
- `shared/captcha.worker.ts`
- `shared/components/CapCaptchaCard.vue`
- `shared/tests/billing-cycle.test.mjs`

## 5. 商业交付前核对清单

- [ ] §2 中三个项目方身份组已完成书面确认，未确认的按「不包含」处理
- [ ] §3 签署记录与实际收到的声明原文一致，覆盖范围写明（仅新贡献 / 含历史贡献）
- [ ] §4 排除清单已逐条核对：已补签的移出；未补签且要进商业版本的文件已用项目方自有实现替换
- [ ] 商业版本不含任何来源无法确认的代码片段
- [ ] 交付物仍保留 `LICENSE`（AGPL-3.0-or-later）与版权声明，AGPL 免费路径的公众权利未被削弱
- [ ] 商业客户收到的授权链条与 `LICENSE-COMMERCIAL.md` 条款一致

## 6. 待办

1. 项目方确认 §2 的三个身份组归属，并补一份书面确认存档。
2. 按 §4 的贡献者分组逐一联系外部贡献者（8 组），询问是否愿意补签历史贡献；对方不签或联系不上的，按 §4 排除处理。
3. 商业版本若要包含 §4 中的核心运行时代码（WAF、工单上游传递、安装器、验证码/实名/短信插件等），需先为这些功能准备项目方自有实现；其余（测试、迁移、部署脚本、文档）可随商业构建一并排除。
4. 每次商业授权交付前，把本清单的快照随交付记录归档（本文即快照模板）。

## 关联文件

- [`CLA.md`](../../../CLA.md)：贡献者许可协议 v1.0（2026-10-21 生效）。
- [`LICENSE-COMMERCIAL.md`](../../../LICENSE-COMMERCIAL.md)：商业许可草案。
- [`cla-feasibility-2026-10-21.md`](cla-feasibility-2026-10-21.md)：双许可链条的工程侧核对。
- [`docs/guide/licensing.md`](../../guide/licensing.md)：站长/企业视角的双许可说明。
