# 变更记录

本项目采用 [Keep a Changelog](https://keepachangelog.com/zh-CN/1.1.0/) 格式，并遵循 [语义化版本](https://semver.org/lang/zh-CN/)。

## [v0.3.2] - 2026-08-20

### 修复

- 短信找回密码报"短信服务暂不可用"：
  - 阿里云短信错误码（`biz.FREQUENCY` 等）映射为用户可读文案并透传，不再被两层通用化吞掉
  - 新增"每分钟/目标号码"限流（默认 1 条），提前拦截避免打到服务商触发阿里云频繁限制
  - `sendPhoneCode` 对插件层 `BusinessException` 直接透传用户可读原因
- 人机验证（Corptcha）内嵌组件预先完成被重置回退：
  - 无挂起动作时保留验证结果（`verifiedResult` 一次性消费），按钮触发时取出并重置组件，保证 token 单次使用
  - 未完成验证时点击操作先提示"请先完成人机验证"再唤起组件（登录/注册/找回密码/换绑手机邮箱）

## [v0.3.1] - 2026-08-20

首个开源发布版本，欢迎试用与反馈。开发交流：QQ 群 1105174267。

### 开源初始化

- 项目自有代码许可证由 Apache-2.0 调整为 AGPL-3.0-or-later，TDesign 原始代码保留 MIT 许可
- 重构仓库结构为 monorepo（backend / frontend-admin-v3 / frontend-user-v3-www / frontend-user-v4-console / shared）
- 移除测试文件、AI 协作中间态文档与内部工具脚本，适配开源发布
- 新增开源许可证与部署、贡献文档
- 源码中硬编码的生产域名替换为占位符 `example.com`

### 新增

- 人机验证插件 Corptcha（与 GeeTest / Vaptcha 同接口，`initGeetest4` 兼容适配，登录/注册/找回密码页可见容器渲染）
- Docker / 1Panel 一键部署（`deploy/docker/`，GitHub Actions 自动构建推送镜像）
- 三个前端页面合一与混合部署模式
- 智简魔方财务（ZJMF）迁移工具集：
  - `app:reorganize-product-groups` 三级分组重建与 `service_type_code` 派生
  - `app:sync-upstreams` 上游同步与 API 密钥解密（DES-CBC / openssl legacy）
  - `app:migrate-real-name` 实名认证迁移
  - `app:fix-os-versions` OS 配置项 version 修复
  - `app:backfill-config-options` 跨库补齐配置项
  - `services:backfill-upstream-bindings` 服务实例上游绑定回填
  - `mofang_config_options_migrator.py` 配置项迁移脚本
- 完整迁移教程：[从智简魔方财务系统迁移](docs/参考资料/数据库/从智简魔方财务系统迁移.md)

### 修复

- 官网品牌配置不同步：Logo / Favicon / 网页标题 / QQ 群链接
- 接口 500：`perPage` 签名与基类不一致（工单/账单/通知列表）
- PR #13 审查的 4 个 Critical 安全风险
- 邮件模板明暗色适配完善（各模板独立 accent 与暗色亮色映射）
- CORS 支持 `CORS_ALLOWED_ORIGINS` 额外来源（官网备用域名）
- 迁移器 pricing 扁平格式 `{"monthly":"25.00"}`（修复价格为零与"无效计费周期"）
- 补充 tencentos 系统图标

### 文档

- README：Logo、动态徽章、架构图、生态合作、Star History（加密嵌入）、贡献者、开发交流群
- README 对比板块「我们没有 / 我们有」与 Roadmap
- 部署指南（宝塔 / Docker 与 1Panel / 部署与调度）、迁移教程、AGPL 许可说明

## [1.0.0] - 初始开源版本

### 功能

- 管理后台：商品 / 订单 / 财务 / 用户 / 工单 / 插件 / 内容管理
- 官网门户：产品展示、下单、内容与帮助中心
- 用户控制台：服务管理、续费、充值、工单、分销
- 后端：Laravel 12 API、Sanctum 鉴权、调度任务、队列、日志归档
