# 变更记录

本项目采用 [Keep a Changelog](https://keepachangelog.com/zh-CN/1.1.0/) 格式，并遵循 [语义化版本](https://semver.org/lang/zh-CN/)。

## [未发布]

### 开源初始化

- 项目自有代码许可证由 Apache-2.0 调整为 AGPL-3.0-or-later，TDesign 原始代码保留 MIT 许可
- 重构仓库结构为 monorepo（backend / frontend-admin-v3 / frontend-user-v3-www / frontend-user-v4-console / shared）
- 移除测试文件、AI 协作中间态文档与内部工具脚本，适配开源发布
- 新增开源许可证与部署、贡献文档
- 源码中硬编码的生产域名替换为占位符 `example.com`

## [1.0.0] - 初始开源版本

### 功能

- 管理后台：商品 / 订单 / 财务 / 用户 / 工单 / 插件 / 内容管理
- 官网门户：产品展示、下单、内容与帮助中心
- 用户控制台：服务管理、续费、充值、工单、分销
- 后端：Laravel 12 API、Sanctum 鉴权、调度任务、队列、日志归档
