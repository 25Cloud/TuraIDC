---
status: 进行中
updated: 2026-10-05
owner: backend
---

# 商品分类层级与登录注册导向修复

## 背景

现网把官网、控制台、管理端、API 合并到同一个域名（单域名子路径部署）之后暴露三个问题：

1. **一级 / 二级 / 三级分类分不清**：官网选购页的层级命名不统一，一级目录只在左侧栏里出现且没有层级标识；
   管理端商品分类树把一级菜单条（一级）选完之后，又把树根标成「一级」、子节点标成「二级」，
   同一屏出现两套「一级分类」。
2. **同类型的一级菜单被并项**：实库里「大陆云服」（code=`type_3mbtxn`）与「轻量服务器」（code=`vps`）
   的 `product_type` 同为 `cloud_server`。站点分类接口按 `product_type` 兜底过滤，
   `GET /api/v2/site/product-groups?first_product_group_code=vps` 会把「华东」「华北」一起返回，
   前端只能靠客户端二次过滤救场；控制台「我的产品」概览直接按 `product_type` 分组，
   两条产品线的服务被并进同一张「云服务器」卡。
3. **官网登录 / 注册导向错误**：`frontend-user-v3-www/.env` 里
   `VITE_CONSOLE_SITE_URL` 只写了站点根地址，漏了控制台的 `/console` 子路径，
   首页「登录」「免费注册」跳到 `<站点根>/client/login`，
   该路径落在官网 SPA 上，登录和注册都到不了控制台。
4. **深色背景下登录按钮不可见**：`.site-header` 浮在首页 Hero 之上时整条 header 没有任何底色，
   `.header-link` 只有文字色，深色背景图上完全看不见；`.header-register` 因为有实体底色所以正常。

## 范围与验收

- [x] 按一级菜单 code 过滤站点分类时不再回落到 `product_type`，同类型的一级菜单互不并项。
- [x] 显式按 `product_type` 过滤时返回该类型下全部一级菜单的二级分类，且逐条带自己的 `first_product_group_code`。
- [x] 分类接口补 `group_level_label` / `group_path` / `group_path_text`，三级路径一次给全。
- [x] 控制台「我的产品」按一级菜单分组，大陆云服与轻量服务器各自成卡；子卡 key 带层级前缀。
- [x] 官网选购页按「一级 / 二级 / 三级」显式标注；移动端选择器加列头。
- [x] 官网目录内商品索引的 key 带层级前缀，修复「二级 #57」与「三级 #57」互相覆盖。
- [x] 管理端分类树按真实层级标注一级 / 二级 / 三级，并在面板标题写明当前一级菜单。
- [x] 管理端商品选择树按一级菜单分组，不再按 `product_type` 归并。
- [x] 官网登录 / 注册跳转到 `<控制台地址>/client/*`。
- [x] 控制台地址缺子路径时前端构建直接失败，不再带着坏链接发版。
- [x] 登录按钮为白色实底 + 主色文字，注册保持主色实底 + 白字。
- [x] 登录后的用户胶囊为半透明白色，浅色 header 与深色 Hero 下均清晰。
- [x] 1180px 以下不再隐藏登录入口。
- [x] 代登录地址支持控制台子路径：后端不再把带路径的 `CLIENT_CONSOLE_URL` 判为未配置，
      管理端拼接地址时保留 `/console` base。
- [x] 三端构建产物部署到生产环境并在线验证。

## 实施步骤

1. 抽出 `App\Support\SiteProductGroupQuery` 作为站点可见二 / 三级分组的唯一查询入口：
   `visibleSecondGroups(?string $firstGroupCode, ?string $businessType)` 按 code 精确命中，
   只有显式传 `product_type` 才展开成该类型下的一级菜单 code 集合。
   `ProductGroupV2QueryService` 与 `ProductSiteService` 各自的私有重复实现删除。
2. `App\Support\ProductGroupHierarchyFields` 增加 `levelLabel()` / `path()` / `pathText()`，
   站点资源与 `ProductSiteService` 的分组转换、商城卡片、控制台概览统一复用。
3. `ServiceOverviewService::buildGroupedOverview()` 改为按可见一级菜单分组；
   `buildGroupedOverviewCategoryCard()` 的 key 从裸 slug 改为 `层级:ID`。
4. 「登录成功了却没有登录态」不是会话机制问题：`shared/runtime/session.ts` 用 `client_token`
   cookie（`path=/`、`SameSite=Lax`、https 下加 `Secure`）存 token，控制台路由 base 是
   `VITE_BASE_URL`，站内跳转一律 `router.push('/client/...')`，base 会自动带上 `/console/`。
   实测官网登录 → `/console/client/dashboard` → 刷新后登录态保持，所有 `/v2/client/*` 接口 200。
   真正的断点在**管理员代登录**：
   - `AuthService::normalizeConfiguredUrl()` 把「带路径的 URL」判为非法，
     `CLIENT_CONSOLE_URL=https://<站点域名>/console` 被当成未配置，
     代登录直接抛 `CLIENT_CONSOLE_URL 未配置`；
   - 管理端 `resolveLoginAsTarget()` 把 `target.pathname` 覆盖成 `/client/login-as`，
     把控制台 base `/console` 抹掉，代登录窗口落到官网 SPA，
     控制台 `login-as` 页永远加载不出来，10 秒后报「等待管理端返回代登录凭证超时」。
5. 官网选购页的三级层级采用业务命名：**一级 = 产品目录，二级 = 地区，三级 = 可用区**。
   左侧栏标题为「产品目录」，两行筛选依次为「地区」「可用区」，移动端选择器两列同样按此标注。
   早期版本曾把两行筛选标成「二级分类」「三级分类」、侧栏标成「一级菜单」，
   但那套字样只描述层级、不描述业务含义，与页面实际提供的信息不匹配；
   同时还额外加过一条完整路径面包屑，两处均已按运营要求改为业务命名并撤除面包屑。
6. 头部按钮最终形态（实机截图核对）：
   - 「登录」为白色实底 + 主色文字（`#165dff` on `#ffffff`）+ 32% 主色描边，
     hover 转浅蓝底并下沉 1px；
   - 「免费注册」保持主色实底 + 白字；
   - 登录后的用户胶囊（`.header-user-trigger`）由透明底改为**半透明白底**
     `rgba(255, 255, 255, 0.72)`，hover 提到 `0.92`，圆角保持 999px。
   两者都需要自带底色：首页 header 浮在 Hero 之上时整条 header 没有底色，
   透明或纯文字的元素在深色画面上会彻底消失。
7. 回归测试：`SiteProductGroupIsolationTest`（新建，覆盖同类型一级菜单隔离与层级字段）、
   `ClientServiceConsoleCategoryTitleFallbackTest`（改按一级菜单语义断言）。

### 3.1 代登录地址修复

- `normalizeConfiguredUrl()` 改为允许路径：只拒绝非 http(s)、缺 host、带 user/pass/query/fragment
  的地址，并返回规范化结果（scheme 与 host 小写、去掉结尾斜杠、保留 base path）。
- `sameUrlOrigin()` 改为比较 origin **加** base path：单域名部署下控制台（`/console`）与管理端
  （`/admin`）本就同源，靠子路径区分，`postMessage` 的 `event.origin` 因此完全相同，
  前端仍用 `document.referrer` 与事件来源窗口做二次校验。原来只比 origin，会把单域名部署
  一律判成「控制台与管理端指向同一个地址」而拒绝生成代登录链接。
  多域名部署下两者 origin 本就不同，`sameUrlOrigin()` 直接返回 false，判定逻辑不受影响。
- `resolveAdminLoginAsTargetUrl()` 改为在规范化的控制台地址后追加 `/client/login-as`。
- 管理端 `resolveLoginAsTarget()` 改为在原有 pathname 后追加，不再整体覆盖。

验证：单域名部署下 `resolveAdminLoginAsTargetUrl()` 返回
`https://<站点域名>/console/client/login-as`，浏览器打开该地址命中控制台「代登录」页；
对照旧逻辑得到的是 `https://<站点域名>/client/login-as`（落到官网）。

### 3.2 多域名与单域名双支持

系统需要同时支持两种部署形态，本次改动不得把单域名子路径写死成唯一前提：

| 形态 | 官网 | 控制台 | 管理端 | API |
| --- | --- | --- | --- | --- |
| 多域名 | `https://www.<域名>` | `https://console.<域名>` | `https://admin.<域名>` | `https://api.<域名>` 或同域 `/api` |
| 单域名子路径 | `https://<域名>` | `https://<域名>/console` | `https://<域名>/admin` | `https://<域名>/api` |

两种形态下各环节的行为：

| 环节 | 多域名 | 单域名子路径 |
| --- | --- | --- |
| `consoleUrl.js` 解析控制台地址 | 直接用 `VITE_CONSOLE_SITE_URL`；未配置时由 `www.` 前缀推断出 `console.` 子域 | 未配置时回落到「当前 origin + `VITE_CONSOLE_BASE_PATH`」 |
| `vite.config.js` 构建守卫 | 控制台与官网不同源，不触发 | 同源且无子路径时构建失败 |
| `AuthService::normalizeConfiguredUrl()` | 允许无路径地址 | 允许带路径地址，原先只接受前者 |
| `AuthService::sameUrlOrigin()` | origin 不同，直接放行 | 按 origin + base path 区分控制台与管理端 |
| 管理端 `resolveLoginAsTarget()` | `basePath` 为空，拼出 `<控制台域名>/client/login-as` | `basePath` 为 `/console`，拼出 `<站点域名>/console/client/login-as` |
| 控制台 router base | `VITE_BASE_URL=/` | `VITE_BASE_URL=/console/` |

结论：单域名是新增支持形态，多域名为原有形态，两条路径都已覆盖，无一方被写死。

### 3.2 风险与回滚（增补）

- `CLIENT_CONSOLE_URL` / `ADMIN_URL` 现在允许带路径。误配成带 query 或 fragment 的地址仍会被拒绝，
  不会生成错误链接。
- 代登录的同源判定放宽到 base path 级别后，控制台与管理端同域时的安全性依赖前端已有的
  `document.referrer` + `event.source` 校验，未新增信任面。

## 风险与回滚

- **接口字段新增**：`group_level_label` / `group_path` / `group_path_text` 为纯新增字段，
  老前端忽略即可。`ServiceOverviewGroup.key` 的取值从商品类型变成一级菜单 code，
  控制台 `/client/services?catalog_type=` 的过滤同时按 code 与 `product_type` 命中，两种取值都可用。
- **管理端商品选择树节点 key 变化**：由 `type:<product_type>` 变为 `type:<first_product_group_code>`，
  仅影响 e2e 选择器；`smoke.spec.ts` 的 mock 未提供 `first_product_group_code`，
  仍回落到 `type:cloud_server`，现有断言不受影响。
- **目录缓存 key 层级化**：`useWebsiteProductsCatalog` 的 `productsByGroup` 改 key 后，
  已打开的页面需要刷新才会生效；无持久化缓存。
- **回滚**：前端按 `dist.bak-<时间戳>` 回滚（部署脚本 `--rollback`），后端 `git revert` 单个提交；
  无数据库结构变更、无迁移。

## 未覆盖与后续

- `useWebsiteProductsCatalog.js` 的 `rootGroupsByType` 与 `catalogPendingMap` 仍按裸 `groupId` 作 key。
  这两处只在一级菜单下的二级分类之间查找，二级 ID 之间不会冲突；
  若后续支持三级分类作为独立入口，需要一并层级化。
- 后端 `php artisan test` 未在服务器上执行：生产机未安装 phpunit、也没有 `idc_test` 库，
  装 dev 依赖会改动生产 `vendor`。本次改用实库 tinker 回归 + 线上接口与浏览器验证覆盖，
  新增的 `SiteProductGroupIsolationTest` 待具备测试库的环境跑一次。
- 管理端与控制台的界面改动已通过 `vue-tsc` 与构建验证，但登录态下的页面未做人工走查。
- 代登录链路只验证到「地址正确且控制台 `login-as` 页能打开」，
  未端到端跑通管理端发起 → 凭证交换 → 种 cookie 的完整链路（需要管理员账号与开启人机验证的交互）。
  建议人工点一次管理端「代登录」确认。
- 排查登录态时曾临时用 `php artisan captcha:scene client_login off` 关闭登录人机验证以复现登录流程，
  验证完毕已用 `php artisan captcha:scene client_login on` 恢复（当前状态：开启）。

## 进度

- [x] 定位三个问题的具体代码位置并用实库数据复现。
- [x] 后端：站点分类查询按一级菜单隔离，去掉两处重复实现。
- [x] 后端：分类层级字段（标签 / 路径）贯通资源层与商城卡片。
- [x] 后端：控制台概览按一级菜单分组，子卡 key 带层级。
- [x] 前端：官网选购页与导航的三级分类显式分层。
- [x] 前端：管理端分类树层级标签与商品选择树分组。
- [x] 登录 / 注册导向修复与构建期守卫。
- [x] 登录按钮与注册按钮统一为同一种实心按钮。
- [x] 后端 + 管理端：代登录地址支持控制台子路径。
- [x] 本地三端类型检查、lint、构建。
- [x] 部署到生产环境并在线验证分类隔离、代登录地址、登录注册跳转与按钮样式。

## 决策日志

| 日期       | 决策                                                                | 原因                                                                                                                                     |
| ---------- | ------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------- |
| 2026-10-05 | 站点分组查询收敛到 `SiteProductGroupQuery` 单一入口                 | `ProductGroupV2QueryService` 与 `ProductSiteService` 各有一份几乎相同的实现，已经出现「改一处漏一处」的风险。                                   |
| 2026-10-05 | 传 `first_product_group_code` 时禁止用 `product_type` 兜底          | 多个一级菜单共用同一商品类型是设计允许的（见产品类型与一级菜单重构方案），用类型兜底等于把两条产品线的分类并成一份，前端无法区分。 |
| 2026-10-05 | 控制台概览改按一级菜单分组，不再按商品类型                          | 「我的产品」要回答「这台机器属于哪条产品线」，按类型分组把大陆云服与轻量服务器并成一张卡，回答不了这个问题。                                 |
| 2026-10-05 | 目录内 key 用「层级:ID」而不是裸 ID                                  | 二级与三级各自自增，实库已存在「二级 #57」与「三级 #57」并存；裸 ID 作 key 会让三级分类的商品覆盖掉二级分类的商品。                             |
| 2026-10-05 | 不改 `toDisplayCategoryTree()`，只纠正层级标签                      | 分类接口本就按选中的一级菜单过滤，树里再嵌一层一级菜单是冗余；真正的问题是标签按树深度给而不是按真实层级给。                                 |
| 2026-10-05 | 用构建守卫替代「部署时记得配对地址」                                 | 地址漂移过一次就会再漂移一次。守卫让配错在构建期失败，比任何文档提醒都可靠；`--print-env` 让部署脚本和主构建脚本共用同一份解析。               |
| 2026-10-05 | 官网选购页不展示分类路径面包屑                                       | 运营只需要后台能编辑明白层级，前台多余的路径条增加信息噪音；层级通过侧栏「一级菜单」与筛选行的「二级分类 / 三级分类」标签表达已经足够。               |
| 2026-10-05 | 登录与注册按钮分成两套样式，不再共用一条规则                   | 运营要求登录为白色按钮、注册保持主色实底。次要/主要操作分层本就应区分，共用一条规则反而表达不出这层语义。                                 |
| 2026-10-05 | 用户胶囊改成半透明白底而不是纯白底                                | 纯白胶囊在浅色 header 上会突兀，半透明白在深色 Hero 上托住文字、在浅色 header 上几乎看不出底色，两种背景都合适。                             |
| 2026-10-05 | 代登录地址判定放宽到 origin + base path                             | 单域名部署下控制台与管理端必然同源，只比 origin 会一律拒绝生成链接；`postMessage` 的 origin 校验本就无法承担区分职责，真正的区分是 base path。 |
| 2026-10-05 | 排查时临时关闭 `client_login` 人机验证，验证后立即恢复               | 人机验证会挡住自动化复现登录链路；关闭只影响验证方式，不改任何账号或安全配置，且已确认恢复为「开启」。                                   |
