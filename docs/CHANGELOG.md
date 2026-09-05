# 变更记录

本项目采用 [Keep a Changelog](https://keepachangelog.com/zh-CN/1.1.0/) 格式，并遵循 [语义化版本](https://semver.org/lang/zh-CN/)。

## [v0.3.5] - 2026-09-05

### 修复

- **被魔方财务对接持续 405**：登录与鉴权的账号条件不一致——登录只校验 API 开关与密码，鉴权中间件额外要求账号启用，账号停用时形成「登录成功 → 每次请求 405 → 强制重登 → 再 405」死循环。现登录与鉴权条件对齐，停用/关闭 API 的账号登录直接拒绝；405 响应细分原因（JWT 失效/账号不可用/API 未开放）并记录日志便于定位
- **对接智简魔方财务续费假成功**：续费周期下拉此前由本地商品定价派生（仅配月付价也会派生出季/半年/年），用户选中上游不支持的周期后本地扣款成功、上游「换周期续费」被拒，履约失败且用户不可见。现续费周期按上游 `/host/renewpage` 官方可续周期收敛（本地定价与上游集合取交集），预览、建账单、建订单、自动续费共用；上游不可达时回退本地集合并告警

### 新增

- **代理组全局默认折扣率**（`agent_groups.default_discount_rate`）：折扣矩阵未覆盖的商品（未挂折扣组/折扣组停用/矩阵无记录）按默认折扣率兜底生效，矩阵命中优先；管理端代理组表单可配置，留空保持仅矩阵生效的既有行为
- 被对接补充 `/provision/button` 控制按钮接口（`id` + `func` 分发电源/VNC 操作）与硬关机、硬重启支持
- 官方文档站新增 [TuraIDC 作为上游被魔方财务对接参考](references/integrations/zjmf-upstream-api.md)（hostname 前缀、账号要求、状态码语义与 405 排查指引）

### 变更

- 后端测试提速与稳定性：`redis_volatile` 缓存 driver 可经 `CACHE_VOLATILE_DRIVER` 覆盖（测试环境切 array，无需真实 Redis）；测试基类适配本机 mysql CLI 路径；修复两个既有坏测试；测试文档新增 Windows 直跑 phpunit 的快速路径指引

### 升级提示

- 执行 `php artisan migrate`（`agent_groups` 新增 `default_discount_rate` 可空列，不配置不影响现有行为）
- 需要全局代理折扣的站点，到管理端「代理折扣 → 代理组」配置默认折扣率
- 对接魔方财务的站点，确认上游 hostname 为 `https://域名/api/v2/zjmf` 形式（详见对接参考文档）且对接账号处于启用状态

## [v0.3.4] - 2026-09-04

### 新增

- **代理折扣体系**：商品折扣分组、代理组与折扣矩阵，新购/续费计价接入代理折扣，含成本价保护与优惠券兼容；管理端新增折扣管理页面，官网商品列表展示代理折扣价
- **开放 API（Open API v2）**：API 密钥生成/校验、scope 与 IP 白名单、调用审计；开放商品目录与报价、订单、服务、余额接口；用户端新增「API 密钥」管理页，管理端新增开放接口配置与密钥管理
- **魔方财务上游协议**：TuraIDC 可作为上游服务商被魔方财务（ZJMF）对接；新增上游余额自动同步与商品库存定时刷新
- **工单上游传递**：工单按规则转发上游，含传递规则管理、上游附件上传接口启用开关（默认关闭）、回调签名校验与推送日志；传递设置权限独立化
- **Web 安装向导**：新增可视化安装向导与 `app:install` 安装命令，支持部署级令牌控制与失败锁清理
- **插件市场**：插件索引同步、审核锁定下载、安全解压与手动 zip 安装；新增 `plugin:make` 脚手架与插件包规范（独立插件模板仓库 [25Cloud/turaidc-plugin-template](https://github.com/25Cloud/turaidc-plugin-template)）
- **MySQL 5.7 支持**：兼容 MySQL 5.7.8+，移除窗口函数依赖、修复 5.7 隐式时间戳缺陷，并加装安装期数据库版本闸门
- **服务实例增强**：实例详情本地快照与异步同步，补齐上游控制操作（含 ZJMF 暂停/解除暂停）；后台服务实例独立管理页，全站实例名称可点击直达
- 用户控制台改动态 tab 导航，兼容魔方财务自定义区域
- 管理端支持编辑用户实名认证信息（状态/姓名/证件号/状态说明）；代理分组支持「无代理分组」
- 官网 SEO 动态渲染：Laravel 读库生成完整 HTML，利于搜索引擎收录
- 用户端充值支持小数金额（最多两位小数）
- 用户新建工单可指定管理员，自动以其名义预回复
- 产品目录批量操作与强制删除、拖拽排序、多标签页增强
- 选购页规格亮点展示，商品名移除规格 slug 后缀
- 官方文档站上线（[docs-web](https://github.com/25Cloud/TuraIDC/tree/main/docs-web)，VitePress 构建），主题支持深浅色切换动效与增强阅读面板

### 性能

- 拆除会员等级长事务、消除 schema 内省风暴、管理端日志分页改延迟关联
- CORS 允许浏览器缓存预检结果，减少约三分之一的跨源往返
- 心跳调度削减每分钟冗余查询；插件配置 60 秒缓存，去掉每次调用的 information_schema 检查
- 官网商品卡改标签缓存（商品/分组/折扣组变更 2 秒合并失效）；优惠券商品分组新增整树接口，替代递归逐层分页

### 修复

- **安全**：修复 zjmf_bridge 任意用户 JWT 伪造漏洞并补齐续费金额口径；修复官网 SEO 存储型 XSS；开放 API 密钥校验改等值命中并落实限流；收敛散落的 XSS 防护，撤回误伤邮件模板的净化方案
- 修复续费 500 与商品展示名 remark HTML 渗入；状态同步增加续费保护，避免续费成功后仍被上游暂停误杀
- 修复管理端编辑用户资料保存失败；用户写操作失败时补上错误提示，并分离写入与刷新的错误处理
- 启动屏改用管理员设置的站点名，不再固定显示「图拉云」
- 修复多标签页切换后页面状态重置、右键菜单瞬间消失、中键触发浏览器滚动
- 修复人机验证（Corptcha）内联渲染，未验证提交不再无限转圈
- 删除对接商品后释放上游商品，允许重新对接
- 修复 CPU 型号/睿频批量设置与代理折扣全链路
- 修复管理端会话与标签页缺陷、插件哈希静默丢弃与关系类型缺失
- 锁定 vue 运行时 3.5.41 修复循环 chunk 初始化崩溃；压平 VitePress 构建分块消除循环依赖

### 文档

- 官方文档站与 EdgeOne Pages 构建配置；新增裸机源码部署指南
- 统一文档结构基线（目录英文化、编号元信息与目录归属），整理仓库根目录分散文档
- 插件市场落地状态与开发者发布流程（PR 审核 + tag/sha 锁定）、插件包规范模板仓库链接

### 升级提示

- 新增 19 个迁移（工单上游传递、代理折扣、开放 API、上游余额等表），升级后执行 `php artisan migrate`
- 启用工单上游传递需在 `backend/.env` 设置 `TICKET_UPSTREAM_CALLBACK_SECRET`（生产环境必须为随机高强度值，留空则拒绝服务）
- 队列拆分为 provision 与业务两组（`TURAIDC_PROVISION_QUEUES` / `TURAIDC_BUSINESS_QUEUES`），未配置时代码默认值即生效，Docker 部署建议同步 `.env.example`
- 官网 SEO 动态渲染新增 `SEO_FRONTEND_SHELL_URL` 等配置，源码部署需按 `.env.example` 指向前端产物路径

## [v0.3.3] - 2026-08-20

### 修复

- ZJMF Bridge 插件启用失败（"Provider 初始化未完成"）：`PluginProviderRegistry` 的系统级组件检查误伤 addons 域插件——addons（如 zjmf_bridge）注册自己的路由命名空间与中间件别名属合法能力，现豁免该检查，但仍禁止注册系统级调度

### 文档

- README Trusted Partners 新增合作伙伴：7iNet、StarVM 星空云、二五云

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
- 完整迁移教程：[从智简魔方财务系统迁移](references/database/migrate-from-zjmf-finance.md)

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
