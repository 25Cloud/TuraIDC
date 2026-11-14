---
status: 进行中
updated: 2026-11-08
owner: platform-security
---

# 安全审查、依赖与 CI 修复计划（2026-11-08）

## 背景

根据 `Issue_idc.md` 的 TuraIDC 安全审查，集中修复上游 JWT、控制台回源、面板票据、host/sync 回调、插件市场、Docker 密钥、生产依赖和 CI 门禁问题。目标是让代码在受控测试环境中 fail-closed，并把未能在当前环境完成的生产密钥轮换、Docker 和完整后端回归保留为明确验收项。

## 范围与验收

- [x] 上游 JWT 拒绝空/短/示例 APP_KEY，强制 `iat/exp/is_api/iss/aud` 与 HS256 头校验。
- [x] 控制台静态资源代理关闭自动跳转，逐跳校验来源主机、端口、DNS 地址、大小和超时。
- [x] 控制台票据改为服务路径限定的 HttpOnly Cookie/Bearer，URL、Referer、缓存不再承载原票据。
- [x] 上游面板 HTML 净化并收紧 CSP；补充 host/sync 持久幂等与 VNC 启动 token 消费边界。
- [x] 插件市场启用 HTTPS/仓库 allowlist、固定 commit、纯数据 manifest 和 archive SHA-256。
- [x] Docker 生产缺失或短 APP_KEY 直接退出；CI 增加依赖审计、E2E 入口、SBOM/provenance 和本地测试对齐。
- [x] Node 生产依赖升级，`pnpm audit --prod` 无已知漏洞。
- [ ] 在 PHP 8.3 + MySQL + Redis + Docker runner 完成完整 PHPUnit、Pint、PHPStan、Docker CI 和 Playwright 浏览器套件。
- [ ] 生产/staging 注入持久化 APP_KEY，轮换已在空密钥环境签发的 JWT，并完成容器重建演练。

## 实施步骤

1. 先完成认证、回源和票据的 fail-closed 代码与定向回归。
2. 增加数据库事件幂等迁移并运行迁移/Feature 测试。
3. 升级前端依赖、补 CI 审计与 E2E 门禁，运行三端构建和共享测试。
4. 在具备 PHP 8.3、MySQL、Redis、Docker 和浏览器缓存的 runner 上执行完整验收。
5. 生产部署前备份配置与数据库，注入固定 APP_KEY，做登录、Crypt、供应商凭据、VNC 和队列重建前后对照。

## 风险与回滚

- 控制台面板脚本净化可能改变第三方页面行为；回滚前需保留 CSP、净化和票据测试，不能恢复 query bearer 传递。
- 新增 host/sync 事件表只增不删；异常时回滚应用代码前保留事件数据并确认旧协议兼容窗口。
- APP_KEY 变更会使旧会话和密文失效；生产必须先备份并按轮换迁移策略执行，禁止让入口脚本随机生成替代密钥。
- 当前环境缺少 MySQL PDO、Docker daemon 和 Playwright 浏览器时，失败只能标记为环境未覆盖，不得视作产品回归结论。

## 进度

- [x] 代码与定向测试完成（2026-11-08）。
- [x] 文档、依赖锁和 CI 配置同步完成（2026-11-08）。
- [ ] 完整后端/Docker/浏览器 CI 验收。
- [ ] staging/production 密钥轮换与重建演练。

## 决策日志

| 日期       | 决策                                                                 | 原因                                                                             |
| ---------- | -------------------------------------------------------------------- | -------------------------------------------------------------------------------- |
| 2026-11-08 | 控制台票据使用服务路径 HttpOnly Cookie，Bearer 作为非 iframe 回退。  | iframe URL 不能安全承载 bearer；路径隔离、no-store 和 no-referrer 可收敛泄漏面。 |
| 2026-11-08 | 插件市场远程包只接受 `plugin.json`、固定 commit 和 archive SHA-256。 | 安装阶段不能执行不可信 `config.php`，动态分支和无摘要包无法证明供应链完整性。    |
| 2026-11-08 | host/sync nonce 在数据库事务内持久消费，工单回调继续使用缓存 nonce。 | 主机状态写入需要跨实例、重启和业务失败重试都成立的原子幂等边界。                 |
