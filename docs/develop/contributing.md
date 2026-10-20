# 参与贡献

面向给 TuraIDC 提代码、改文档或报问题的贡献者。本文只写提交前必须知道的事；仓库根目录的 `AGENTS.md` 是给编码代理用的导航图，完整约束以仓库内的工作规则为准（见文末链接）。

## 1. 动手之前

1. 先读与任务相关的文档和现有代码，不要猜接口、业务或目录结构。
2. 保持最小改动，不做无关重构；无调用方的废弃代码不保留兼容层。
3. 工作区可能是脏的：绝不回滚、覆盖或格式化不是本次任务造成的改动。
4. 任何密钥、密码、Token、私钥与生产数据都不得进入代码、文档、提交信息或日志。

## 2. 改动落在哪里

| 改动类型                 | 落点                                                                                             |
| ------------------------ | ------------------------------------------------------------------------------------------------ |
| 后端业务与接口           | `backend/app/`，控制器薄层，第三方调用进专用 Service、Driver 或插件                              |
| 插件                     | `backend/plugins/{domain}/{slug}/`，特有逻辑不污染系统级扩展点                                   |
| 三端前端                 | `frontend-admin-v3/`、`frontend-user-v3-www/`、`frontend-user-v4-console/`，跨端能力进 `shared/` |
| 使用者文档               | `docs/guide/`                                                                                    |
| 开发者文档               | `docs/develop/`                                                                                  |
| 执行计划、设计、修复记录 | `docs/internal/`，并登记 `docs/catalog.json`                                                     |

## 3. 提交前验证

| 影响范围   | 最小验证                                                            |
| ---------- | ------------------------------------------------------------------- |
| 文档       | `pnpm run docs:check`                                               |
| `backend/` | `cd backend && php artisan test`（可先缩小到受影响文件）            |
| 前端各端   | 对应目录 `pnpm run build`；重构追加该应用的 `verify:refactor`       |
| `shared/`  | `pnpm run typecheck:shared && pnpm run test:shared`，再按影响端构建 |

前端界面改动按仓库要求补对应 e2e 用例。跑不了验证时，在说明里写清原因与未覆盖的风险。

## 4. CLA 签署与许可授权

本项目采用 **AGPL-3.0-or-later 与商业许可双许可**。为了让项目方能同时按两种许可分发你的贡献，**第一次提交 PR 前需要签署[贡献者许可协议（CLA）](../../CLA.md)**：

- **个人贡献者**：在任意一个 PR 评论中回复 `I have read the CLA Document and I hereby sign the CLA（我已阅读并同意 CLA.md）`，维护者登记后生效；也可按 CLA 文件中的签署邮箱发邮件签署。
- **组织贡献**（员工/外包人员在履职范围内提交）：由组织授权代表按 [CLA.md 第二部分](../../CLA.md)发邮件签署，并附指定贡献者的 GitHub 用户名清单。
- 签署一次即覆盖此后向本项目提交的全部贡献，不需要每个 PR 重复签；未签署的 PR 会在评审时被提醒，无法取得再许可授权的贡献可能不予合并。
- 贡献一经合并，即同时按 AGPL-3.0-or-later 向公众提供，并授权项目方以商业许可再分发；**已经按 AGPL 获得的公众权利不会减少**，你仍是自己贡献的版权人。
- 不同意上述授权时请不要提交贡献；你仍可以按 AGPL 自由使用本项目。行为规范见[行为准则](../../CODE_OF_CONDUCT.md)。

安全漏洞不要通过公开 Issue 或 PR 提交，请按[安全策略](../../SECURITY.md)私下报告。

## 5. 提交与协作

- 一个提交只做一件事，信息用中文：`Fix:中文描述`、`Feat:中文描述`、`Refactor:中文描述`；仓库钩子要求冒号后带一个空格。
- 改动通过 Pull Request 合入 `main`，描述里写清改动范围、验证命令与结果。
- 涉及路由、数据库结构、部署方式、接口契约或用户流程的改动，必须同步复核对应文档；确实无需文档变更时，在 PR 里说明理由。
- 自动生成物（数据库快照与后端 API 清单）只能通过脚本重刷，禁止手工编辑。

## 关联文档

- [工作规则](https://github.com/25Cloud/TuraIDC/blob/main/docs/internal/governance/WORKING_RULES.md)：协作、修改、验证与提交的完整强制规则。
- [文档治理策略](https://github.com/25Cloud/TuraIDC/blob/main/docs/internal/governance/DOCUMENTATION_POLICY.md)：文档放置、结构与生命周期。
- [工程踩坑记录](pitfalls.md)：提交、测试与增量部署环节的实测坑。
- [测试指南](testing.md)：测试环境、命令与范围。
- [开源与商业许可](../guide/licensing.md)：AGPL 强制义务、商业授权适用场景与获取方式。
- [贡献者许可协议 CLA](../../CLA.md)、[商业许可草案](../../LICENSE-COMMERCIAL.md)。
