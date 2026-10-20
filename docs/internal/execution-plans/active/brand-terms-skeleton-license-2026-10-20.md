---
status: 进行中
updated: 2026-10-20
owner: lead
---

# 官网体验收口、术语统一与双许可落地

## 背景

本轮用户反馈集中在六件事，外加一条必须先修的生产故障：

1. **生产 P0**：管理端实例详情点「同步信息」返回 `{"code":42200,"message":"电源状态失败，请稍后重试"}`（实例 `3783` / 用户 `1086`）。用户明确要求：同步到期时间、商品信息、IP 不能被电源状态读取失败阻断。
2. **术语口径**：卖的是「商品」，开通后的是「实例」；不允许产品 / 商品混用、服务 / 实例混用。
3. **官网加载动画**：不要遮罩转圈，全部改成一致的骨架动画（首屏、顶栏、所有 DOM 加载后的请求）。
4. **卡片式配置页**：只有一个选项的配置组现在渲染成灰色按钮，应为主题色激活态，表示已选中。
5. **侧栏商品目录开关**：用户仍看不到平滑动画，要求补上（含一级分组折叠）。
6. **一级分组图标**：必须是正规图标库（TDesign），不是媒体库上传。
7. **CLA 与双许可**：AGPL 免费 + 商业许可证（200 元/年，可闭源），官网加仓库入口，并让用户能获取开源版本。

前几轮已完成的不再列入：品牌首屏防闪、目录加载性能、区块式选购页、商品名「区域 + 规格」、描述规范化、失效商品收口、一级分组图标字段与生产回填。

## 前置事实（已核实，2026-10-20）

- **P0 根因链**：管理端 `ConsolePanel` 的「同步信息」→ `useConsoleDetail.refreshHostStatus()`：先请求 `clientApi.serviceModuleStatus({ type: 'host' })`（后端 `ServicePowerService::getModuleStatusForUser`，动作标签 `电源状态`），上游读电源失败时 `ServiceDetailService::assertSuccess()` 抛 `BusinessException`，经 `ProviderErrorMapper` 变成 `电源状态失败，请稍后重试`（42200）；异常直接中断，后面的 `loadDetailBase(true)` 根本没执行——到期时间、商品、IP 自然都没同步。该读状态接口没有降级分支（`ServiceStatusSyncService` / `ZjmfStatusService` 里已有的「电源状态不可用降级」逻辑没有复用到控制台读状态）。
- **侧栏动画实测**：生产 `ProductCatalogNav.vue` 的 `<Transition name="shop-nav">` 是生效的——收起时 60ms 内宽度 216→128、带 `shop-nav-leave-active shop-nav-leave-to`，200ms 过渡；但展开/收起伴随目录切换、一级分组栏折叠，视觉上是「跳变」；且过渡只用 width/opacity，flex 布局在离场时立刻回填，看起来仍是瞬切。需要复测三条路径：右上「收起/展开目录」、点二三级分组时一级分组栏自动收起、手机端抽屉。
- **术语规模**：`zh_CN` 语言包中「产品」合计 138 处、「服务」281 处（含「服务条款/客户服务/服务器」等保留词）；`zh_TW` / `zh_HK` / `en_US` 当前没有中文「产品/服务」命中，说明这两个中文语言包尚未覆盖或结构不同，需按 key 对齐。非语言包的硬编码中文另有 产品 83 行、服务 52 行，需逐条判断。
- **图标**：生产 `first_product_groups.icon_text` 已回填 kebab 图标名（vps→server、轻量云→cloud、isp→internet、dedicated/游戏云→control-platform、面板游戏云→component-grid、domain/云电脑→desktop、hosting/虚拟主机→hard-disk-storage、cdn→link、nat→lock-on、bare_metal→building、other→tools、invalid_products→shop）；`icon_image` 全空。工作区仍有两处未提交改动：`backend/app/Support/ProductGroupIcon.php`（优先级改为 icon→icon_text→abbr、别名归并、不再渲染 icon_image）与 `frontend-user-v3-www/src/utils/productGroupIcon.js`（TDesign kebab 名 → Element Plus 图标映射）。
- **许可现状**：根目录只有 `LICENSE`（AGPL-3.0）与 `README.md`（许可章节仅一句 AGPL 说明），没有 `CLA.md`、商业许可文件、`CODE_OF_CONDUCT.md`、`SECURITY.md`；`docs/develop/contributing.md` 仅 46 行且未提 CLA；官网页脚没有仓库入口。

## 范围与总体验收

- [ ] 生产「同步信息」在电源状态不可用时返回成功（或部分成功），到期时间 / 商品信息 / IP / 实例状态照常刷新；电源状态显示「暂时不可用」而不是报错阻断。
- [ ] 三端所有面向用户/站长的可见文案统一：销售对象一律「商品」，已开通对象一律「实例」；保留「服务条款、客户服务、服务器、服务支持」等固定搭配。
- [ ] 官网不再出现遮罩转圈：首屏、顶栏、路由切换、各页面异步区块统一骨架；骨架与真实布局同尺寸，无白屏闪烁。
- [ ] 卡片式配置页中单选项配置组渲染为主题色选中态（不是灰色禁用态），且不可取消。
- [ ] 侧栏商品目录展开/收起、一级分组栏折叠、手机端目录抽屉都有可见的平滑过渡（尊重 `prefers-reduced-motion`）。
- [ ] 一级分组图标只用内置图标库：管理端只能选图标，不再有图片上传 / 图片地址；三端渲染同一套图标名。
- [ ] 新增 `CLA.md`、`LICENSE-COMMERCIAL.md`、贡献/行为准则/安全披露规范，README 与文档站说明双许可与 AGPL 义务；官网页脚有仓库入口，并提供「获取开源版本」页面。
- [ ] 全部改动通过 `pnpm run docs:check`、受影响端构建与 e2e；后端改动通过 `php -l` + 相关测试；生产部署后逐项复验。

## 非目标

- 不改变 AGPL 免费路径的既有权利（已开源的代码不会回收成闭源）。
- 不做多语言整体翻译补齐（`zh_TW` / `zh_HK` 只保证本轮术语 key 对齐，不重译全量）。
- 不重构管理端实例详情信息架构（P0 只做同步解耦与降级）。
- 不引入新的图标库依赖（前端已有 TDesign / Element Plus 图标集，够用）。
- 不为骨架动画引入新组件库或全局过渡框架。

## TDD Route

- 模式 `off`，决定 `light`：仓库要求后端改动跑相关测试、前端改动跑构建与 e2e；本轮以「修 P0 + 补回归 + 补 e2e」为测试姿态，不做 RED 前置。P0 后端补失败降级单测；动画与单选项补 www e2e；图标管理端补 e2e。

## 兼容边界

- 后端：`ProductGroupIcon` 继续兼容历史 `icon_text` 值（`platform`、`gamepad`、`Grid` 等别名）；`icon_image` 列保留不删（迁移只新增），只是不再渲染。
- 接口：`/v2/admin/users/{user}/services/{service}/module-status` 对 `type=host` 失败改为返回可用性标记，不改变成功响应结构；三端按同一字段读取。
- 语言包：只做同 key 改文案与新增 key，不删 key、不改 key 名，避免线上缺失回退英文/空串。
- 版权：新增 CLA 只约束此后贡献；历史贡献按 AGPL 既有授权处理，商业许可证只对取得授权的部署生效。
- 部署：前端改动按端构建；后端无破坏性迁移，无需回滚 DB。

## 工作流与责任划分

| 工作流 | 内容                               | 负责                      | 写入范围                                                                                                                                                                                                                                       |
| ------ | ---------------------------------- | ------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| P0     | 同步信息解耦 + 电源状态降级 + 回归 | lead                      | `backend/app/Services/ClientServiceConsole/ServicePowerService.php`、`ServiceDetailService.php`、`frontend-admin-v3/src/pages/services/detail/console/composables/useConsoleDetail.ts`、admin 语言包新增 key、相关测试                         |
| P1     | 术语统一 商品/实例                 | teammate `terminology`    | 三端 `src/locales/lang/*.json`、菜单/路由配置；硬编码清单交回 lead 汇总                                                                                                                                                                        |
| P2     | 官网骨架加载统一                   | teammate `www-skeleton`   | `frontend-user-v3-www/index.html`、`src/App.vue`、`src/layout/**`、`src/views/website/Home/**`、`src/views/website/content/**`、`src/router/**`；**不含** `views/website/products/**` 与 `ProductDetail/index.vue`                             |
| P3     | 侧栏目录动画 + 单选项激活态        | teammate `www-product-ux` | `src/views/website/products/**`、`src/views/website/ProductDetail/index.vue`                                                                                                                                                                   |
| P4     | 一级分组图标库收口                 | teammate `group-icons`    | `backend/app/Support/ProductGroupIcon.php`、`frontend-admin-v3/src/pages/**/ProductCatalog.vue` 与其 e2e、`frontend-user-v3-www/src/utils/productGroupIcon.js`、console 图标映射                                                               |
| P5     | CLA + 双许可 + 官网仓库入口        | teammate `licensing-cla`  | 根 `CLA.md`、`LICENSE-COMMERCIAL.md`、`CODE_OF_CONDUCT.md`、`SECURITY.md`、`README.md`、`docs/guide/**`、`docs/develop/contributing.md`、`docs/catalog.json`、www 页脚 + 新增开源版本页（`views/website/OpenSource/**`、路由、语言包自身 key） |
| P6     | 收尾上线                           | lead                      | 数据库快照、changelog、全量验证、生产部署与复验                                                                                                                                                                                                |

约定：

- 写入范围互斥；跨范围改动先在群里说明，由 lead 裁决。
- `**/src/locales/lang/*.json` 只有 `terminology` 与 `licensing-cla`（仅新增自己的 key）可动，其余人不得改语言包。
- 每个 teammate 完成后提交前先给 lead 摘要（改了哪些文件、验证命令与结果），由 lead 复核后统一提交 / 部署，避免互相踩脏工作区。
- 工作区已有两处未提交图标改动，`group-icons` 必须在其基础上继续，不得丢弃。

## 实施步骤

### P0 生产「同步信息」42200（最高优先级）

1. 后端 `ServicePowerService::getModuleStatusForUser()`：`type=host` 时把上游读电源失败（含 `assertSuccess` 抛出的业务异常、超时、空响应）降级为「电源状态暂不可用」结果——返回 `available=false`、保留 `error` 文案、不抛 42200；异常写 warning 日志（含 service_id、上游 message）。
2. 后端 `ServiceDetailService`：抽出/复用电源不可用判定（`不能执行该操作`、能力不支持、主机已终止等），避免第三份重复逻辑；`getDetailForUser(forceRefresh=true)` 确认 runtime 失败不阻断 host 详情落库。
3. 管理端 `useConsoleDetail.refreshHostStatus()`：`serviceModuleStatus` 调用改为 best-effort；无论电源状态成功与否都执行 `loadDetailBase(true)`；成功提示区分「已同步」与「实例信息已同步，电源状态暂不可用」；失败提示只针对详情接口。
4. 管理端语言包补部分成功提示（4 个语言文件各自 key，`zh_CN` 为主）。
5. 回归：新增后端单测覆盖「上游电源读失败仍保存到期/商品/IP 并返回降级状态」；管理端构建；生产上复现服务 3783 验证不再 42200。

验收：`https://admin.25y.cn/admin/services/3783?user=1086` 点「同步信息」→ 提示成功或部分成功；刷新后到期时间、商品名、IP、状态均为最新；电源状态区域显示不可用而非错误弹窗。

### P1 术语统一（商品 / 实例）

1. 建立术语表：销售单元 = 商品（Product）；已开通对象 = 实例（Instance / Service 实体）；保留：服务条款、客户服务、客服、服务器、服务支持、售后服务、DNS 服务等固定搭配。
2. 三端语言包逐 key 改文案：`admin`（产品 50 处 / 服务 91 处）、`www`（44 / 133）、`console`（44 / 53）；`en_US` 用 Product / Instance，`zh_TW` / `zh_HK` 用「商品 / 執行個體（或執行個體？按现有用词）」统一。
3. 菜单、路由 meta、页面标题、按钮、空状态、提示语、表格列名、导出文件名同步。
4. 硬编码中文逐条判断（三端合计 产品 83 行 / 服务 52 行），区分销售对象与固定搭配；无法机械替换的交给 lead 复核。
5. 管理端/控制台财务与订单相关文案只改称谓，不动金额、状态枚举值。

验收：三端中文界面不再出现「产品」指代销售对象；「服务」只出现在固定搭配与「实例」语境；英文界面统一 Product / Instance；`pnpm typecheck` 与三端构建通过。

### P2 官网骨架加载统一

1. 梳理现有加载实现：`#app-splash`（当前为扫描条）、路由 fallback、`v-loading` / `el-loading`、各页面 `loading` 分支、图片懒加载占位。
2. 定义统一骨架规范：`SkeletonBlock`（圆角、渐变扫光、尺寸变量）、页面级骨架（首屏/列表/详情）、区块级骨架（顶栏用户区、页脚上方异步块）；统一 1.2s 扫光、`prefers-reduced-motion` 下静止。
3. 首屏：`index.html` splash 改为与首页真实结构同形的骨架（顶栏 + 标题 + 卡片格），品牌配置解析完成后平滑替换，不出现整屏白闪。
4. 顶栏：登录态 / 用户信息 / 导航异步加载用骨架占位，避免「登录/注册」闪成「用户名」。
5. 逐页替换：首页各 section、内容列表/详情（帮助中心、公告、关于、联系、SEO 落地页）、移动端抽屉、页脚异步块；移除所有整页遮罩 spinner。
6. 路由切换保留既有淡入，叠加页面骨架；慢请求下不出现空白内容区。
7. 补 e2e：拦截接口延迟，断言骨架出现、内容替换后骨架消失、无遮罩 spinner。

验收：Chrome 网络限速 Fast 3G 下逐页检查，无遮罩转圈、无白屏；骨架尺寸与真实内容一致；`prefers-reduced-motion` 下无动画。

### P3 侧栏目录动画 + 单选项激活态

1. 复测三条路径并录屏/截图留证：右上目录开关、点二三级分组触发一级分组栏折叠、手机端目录抽屉。
2. 桌面侧栏：左右两栏的展开/收起改为同一套过渡（宽度 + 透明度 + `transform`，240–280ms `cubic-bezier(.4,0,.2,1)`）；离场元素用 `position: absolute` 或 grid 列宽过渡避免布局瞬跳；目录开关按钮图标旋转过渡。
3. 一级分组折叠：折叠高度用 `grid-template-rows: 0fr/1fr` 或 `max-height` + `overflow: hidden` 过渡，子项从可见到收起不跳。
4. 移动端抽屉：保留遮罩淡入 + 面板位移，关闭时先动画再卸载（Transition 的 `v-if` 由动画钩子控制）。
5. 卡片式配置页单选项：`cfg.options.length === 1` 时渲染正常主题色选中态（与多选项同一套 class，只去掉切换行为），不可取消；同步修正配置摘要与价格展示。
6. 补 e2e：开关点击后 200ms 内宽度变化、折叠类名出现；单选项断言 `aria-pressed=true` 且背景为主题色。

验收：三处动画肉眼可见、无跳变；单选项显示主题色选中；`prefers-reduced-motion` 下过渡降为瞬时。

### P4 一级分组图标库收口

1. 完成未提交的 `ProductGroupIcon.php`：优先级 `icon`(TDesign 名) → `icon_text` → `abbr`，`icon_image` 停止渲染但列保留；别名表覆盖 `platform/gamepad/Grid/Node/Server/Network/Desktop/Monitor` 等历史值。
2. 管理端 `ProductCatalog.vue`：删除媒体库图片上传 / 图片地址 UI 与对应逻辑，只保留图标选择器；预览用规范化后的图标名；保存只写 `icon`（必要时兼容写 `icon_text`），不再写 `icon_image`。
3. 重写 `product-group-icon-config.spec.ts`：删除图片上传用例，改为「选择图标 → 保存 → 列表/预览渲染」与「历史 icon_text 别名可解析」。
4. 官网侧栏、卡片、面包屑与面包屑图标统一走 `productGroupIcon` 映射；控制台分组 Tab 图标同样走规范名。
5. 生产复验：三端页面抽查已回填分组的图标（vps / 轻量云 / isp / 游戏云 / 域名 / 虚拟主机 / CDN / NAT / 裸金属 / 其他）；别名值（`platform`、`gamepad`）不出现空图标。

验收：管理端无图片上传入口；三端同一分组显示同一图标；历史数据不出现空图标；相关构建与 e2e 通过。

### P5 CLA + 双许可 + 官网仓库入口

1. `CLA.md`：个人与组织两份贡献者许可协议，授予项目方版权与专利许可，并**明确允许项目方以 AGPL 与商业双许可分发**（这是双许可模式成立的前提）。
2. `LICENSE-COMMERCIAL.md`：商业许可证条款——面向敏感企业，200 元/年（按部署/组织），可闭源使用、免除 AGPL 第 13 条网络开源义务；不含商标授权、不含转售白牌；明确不提供担保与责任限制，并注明条款需法务复核。
3. `docs/guide/` 新增「开源与商业许可」说明页（站长/企业读者）：AGPL 免费使用的强制义务（改了就得上线开源、不得闭源再分发、保留署名）、商业许可适用场景、如何获取源码、如何取得商业授权；`docs/develop/contributing.md` 增加 CLA 流程（PR 评论签署 / 邮件签署）与提交流程。
4. `CODE_OF_CONDUCT.md`、`SECURITY.md`：行为准则（Contributor Covenant 中文版）与安全漏洞披露渠道（私有邮件 / GitHub Security Advisory），明确禁止公开提交漏洞细节。
5. `README.md`：许可章节改成双许可，补 AGPL 义务、商业授权入口、CLA 链接、仓库地址；页脚同款。
6. 官网：页脚新增「开源仓库」（`https://github.com/25Cloud/TuraIDC`）与「开源版本」入口；新增 `/open-source` 页面，说明 AGPL 权利、如何获取源码（GitHub 仓库 / clone / Release 源码包）、部署文档入口、商业授权联系方式；四语言 key 补齐；AGPL §13 要求向网络用户提供对应源码，部署未修改版本直接指向官方仓库即可。
7. `docs/catalog.json` 登记新增文档；`pnpm run docs:check` 通过。

验收：README 与官网都能看到 AGPL/商业双许可与仓库入口；`/open-source` 可访问且四语言无缺失；CLA 与规范文件齐全；docs:check 通过。

### P6 收尾上线

1. `backend/database/schema/mysql-schema.sql` 重新生成（有 DB 环境时执行 `php backend/scripts/export_schema_baseline.php`，无环境则记录原因）。
2. 新增 changelog 条目（本轮功能与 P0 修复、术语口径、许可变更）。
3. 全量验证：`pnpm run docs:check`、`pnpm typecheck:frontends`、三端构建、`pnpm test:shared`、www e2e、admin e2e、后端相关测试。
4. 生产部署：`git pull --ff-only` → `pnpm run build:frontends`；后端无迁移；清理配置缓存；确认服务端 `index-*.js` 哈希更新。
5. 生产逐项复验：P0 同步信息、术语抽查、骨架首屏、侧栏动画、单选项、图标、许可页面与页脚链接。
6. 保留 `pnpm-lock.yaml` 既有脏状态不提交；检查无遗留临时账号/测试数据。

## 验证矩阵

| 范围    | 命令                                                                                                  |
| ------- | ----------------------------------------------------------------------------------------------------- |
| 文档    | `pnpm run docs:check`                                                                                 |
| 后端    | `cd backend && e:\php83\php.exe -l <改动文件>`；相关 `php artisan test --filter=...`                  |
| admin   | `cd frontend-admin-v3 && pnpm run build`；e2e `pnpm test:admin-v3 -- <spec>`                          |
| www     | `cd frontend-user-v3-www && pnpm run build`；e2e `pnpm test:e2e:www -- product-card-display shop-nav` |
| console | `cd frontend-user-v4-console && pnpm run build`                                                       |
| shared  | `pnpm run typecheck:shared && pnpm run test:shared`                                                   |
| 生产    | 三端地址逐项手测 + Playwright 截图留档                                                                |

## 风险与回滚

- **P0 降级过度**：电源状态全失败被吞会掩盖上游真故障。缓解：降级只作用于 `type=host` 读状态，写 warning 日志并保留 `error` 字段；电源操作（开机/关机）仍走严格校验。
- **术语批量替换误伤**：可能改到「服务器」「服务条款」。缓解：按 key 精确替换 + 保留词白名单 + 逐端搜索复核，不做全文字符串替换。
- **骨架与真实布局不一致**：骨架换内容时跳动。缓解：骨架复用真实容器的尺寸类；限速下逐页对比截图。
- **动画影响性能/可访问性**：使用 width/opacity/transform 合成层属性，尊重 `prefers-reduced-motion`；移动端关闭复杂动画。
- **CLA/商业许可法律风险**：文案为工程侧草案，需法务或专业人员复核后发布；不承诺收益、不写绝对化表述。
- **回滚**：前端按端回滚上一版本构建即可；后端改动无迁移，`git revert` 对应提交；文档/许可回滚直接 revert。

## 进度

- [x] P0 同步信息解耦：后端 `type=host` 电源状态失败降级为 `available=false`；管理端同步按钮改为先刷新详情、电源失败只提示部分成功；补 `loadDetailBase(forceRefresh)` 真正带 `refresh=1`（此前参数被忽略，详情命中缓存）。已提交 `d760cbf0`、`aef8324b` 并部署生产，服务 3783 后端实测返回降级结果，管理端 e2e 3 例通过。
- [x] P1 术语统一：三端 12 个语言包 842 处 value、242 个 key，菜单/路由 20 处；保留服务条款/客服/服务器等固定搭配；硬编码补齐 5 处 SEO CTA、内容关键词。
- [ ] P2 官网骨架统一（进行中）
- [x] P3 侧栏动画与单选项激活态：侧栏内层定宽裁切 + 260ms 位移/淡出，一级折叠文字区定宽、图标居中，手机抽屉 keyed 面板过渡；单选项主题色 `aria-pressed=true` 且不可取消；35 项自定义断言 + 既有 30 例 e2e 通过。
- [ ] P4 图标库收口（进行中，后端测试 33 例已过）
- [x] P5 CLA 与双许可：根 CLA / 商业许可 / 行为准则 / 安全策略、`docs/guide/licensing.md`、README 双许可章节、官网 `/open-source` 与页脚仓库入口，docs:check 通过。
- [ ] P6 收尾上线

## 决策日志

| 日期       | 决策                                                        | 原因                                                            |
| ---------- | ----------------------------------------------------------- | --------------------------------------------------------------- |
| 2026-10-20 | 先修生产 P0，再做体验与许可                                 | 用户明确要求先解决同步信息报错；P0 影响站长日常运维             |
| 2026-10-20 | P0 采用「电源状态可降级、实例信息必须同步」                 | 电源状态是辅助展示信息，不应阻断到期/商品/IP 同步；写操作仍严格 |
| 2026-10-20 | 术语口径定为「商品 / 实例」，保留固定搭配                   | 用户明确要求统一口径；避免生硬替换破坏既有表达                  |
| 2026-10-20 | 骨架统一为「骨架 + 扫光」，移除遮罩 spinner                 | 用户明确不要遮罩转圈，要求首屏、顶栏、DOM 加载后请求一致化      |
| 2026-10-20 | 侧栏动画保留 Transition、重做过渡属性并覆盖三条路径         | 实测显示 width 过渡存在但布局瞬跳，观感仍是硬切                 |
| 2026-10-20 | 图标只保留内置图标库，`icon_image` 列保留但停止渲染         | 用户要求正规图标库；仓库规则禁止删列                            |
| 2026-10-20 | 双许可：AGPL-3.0 免费 + 商业许可 200 元/年，CLA 允许再许可  | 用户指定的商业模式；无 CLA 再许可权则商业许可不成立             |
| 2026-10-20 | 官网 `/open-source` 页面 + 页脚仓库入口承载「获取开源版本」 | AGPL 第 13 条要求向网络用户提供对应源码，需显式入口             |
