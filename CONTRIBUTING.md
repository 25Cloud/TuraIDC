# 贡献指南

感谢你对二五云IDC财务系统的关注与贡献。请阅读以下规范后再提交代码。

## 开发环境

参考 [README.md](./README.md)「快速开始」章节搭建本地开发环境：

- 后端：PHP 8.2+ / MySQL 8 / Redis / Composer
- 前端：Node.js 20+（npm workspaces 管理三端 + 共享包）

## 代码规范

- 后端遵循 [Laravel](https://laravel.com) 官方风格，使用 Pint 统一格式：
  ```bash
  cd backend
  composer run format      # 格式化
  composer run format:check
  composer run analyse     # PHPStan 静态分析（level 5）
  ```
- 前端使用 ESLint + Prettier，提交前运行：
  ```bash
  npm run lint:frontends
  npm run typecheck:frontends
  ```
- 数据库变更一律新增 `backend/database/migrations/` 增量迁移，禁止修改 `database/schema/mysql-schema.sql` 基线（重大结构变更后重新导出基线）。

## 分支与提交

- 主分支为 `main`（或 `master`），新功能从主分支切出 `feat/xxx` 分支，修复切出 `fix/xxx`。
- 提交信息遵循 [Conventional Commits](https://www.conventionalcommits.org/zh-hans/v1.0.0/) 规范，仓库已配置 commitlint 强制校验：
  ```
  feat: 新增按需付费计费周期
  fix: 修复发票金额精度问题
  docs: 更新部署指南
  refactor: 重构订单状态机
  ```
- 仓库配置了 husky 钩子，提交时会自动执行 lint-staged（ESLint + Prettier）。

## 提交 Pull Request

1. Fork 仓库并在本地创建分支
2. 提交前确保 `format:check`、`lint:frontends`、`typecheck:frontends` 全部通过
3. 在 PR 描述中说明改动目的、影响范围与验证方式
4. 保持 PR 聚焦单一改动，避免夹带无关修改

## 安全问题

如发现安全漏洞，请勿公开提交 issue，通过 [SECURITY.md](./SECURITY.md) 中列出的方式私下报告。

## 许可

参与贡献即表示你同意你的贡献将遵循 [Apache License 2.0](./LICENSE) 发布。
