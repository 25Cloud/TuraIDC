---
status: 进行中
updated: 2026-10-18
owner: backend
---

# 购物车展示方式与商品描述

## 背景

选购页此前只有一种形态：商品规格表格与配置区同页铺开（`frontend-user-v3-www/src/views/website/products/index.vue`）。这个形态在商品多、同组配置差异大时可读性尚可，但运营侧缺少表达空间——商品没有可编辑的描述，展示方式也无法按站点偏好调整。

对照魔方 `zjmf376` 的实现（`public/themes/cart/default/product.tpl`），卡片式是「名称 + 描述 + 价格 + 立即选购」的网格，点击后进入独立配置页；切换靠后台全局设置 `order_page_style`，用户可用 `cookie("cart_theme")` 临时覆盖。魔方的 `products.description` 是 `varchar(1000)` 的纯文本散文，**没有**从配置项自动生成描述——CPU / 内存那套是独立的结构化规格系统，两者解耦。

本计划要做的是魔方没有的那部分：把描述做成富文本，并让它与 TuraIDC 已有的规格维度识别同源。

## 关键前置事实

- `ProductSpecHighlightService` 已有 21 个规格维度与四路来源合并（商品名 → `remark` → `config_options` → 后台覆盖），列表页的 `spec_highlights` 列就是它产出的。
- `products.remark` 是 `varchar(255)`，存的就是历史商品卡片 HTML，`fromRemark()` 已按 `<li>键:值</li>` 解析；同时它还是展示名兜底（`ProductDisplayNameResolver`）和回传魔方的 `description`（`ZjmfUpstream\ProductService`）。**不能直接拿来当描述用**，三职责会互相污染。
- 上游智简魔方允许 `field` 复用——虚拟主机与 CDN 的全部配置项 `field` 都是 `cpu`。识别必须按名称而非 field，否则「WEB空间 12T」会被算成内存 12T。
- `/v2/site/product-groups/{id}/products` 用的是 `SiteProductCardResource`，它是选购页分组浏览的主要数据源；`/v2/site/products` 用 `SiteProductSummaryResource`。描述两处都要下发。
- `/products/:id` 已是独立路由（`WwwProductDetail` → `views/website/ProductDetail/index.vue`），自带返回入口与完整配置区，不需要为卡片式另建页面。

## 范围与验收

- [x] 新增 `products.description`（`text`，可空），迁移只新增不删列。
- [x] `ProductDescriptionService` 三段口径：人工填写（过 `RichHtmlSanitizer` 白名单）/ 按规格维度自动生成 `<ul><li>键: 值</li></ul>` / 两者皆空。自动生成只读不写库。
- [x] 自动生成复用 `ProductSpecHighlightService`，不新增第二套 CPU / 内存判据。
- [x] `remark` 完全不动，魔方回传、展示名兜底、订单快照全部不受影响。
- [x] `SiteProductSummaryResource` 与 `SiteProductCardResource` 下发 `description_html` / `description_source`，两种形态共用同一份接口数据，不按展示方式裁剪字段。
- [x] 全站展示方式存 `settings` group `basic` / key `product_display_mode`，经 `SiteConfigPayload` 随既有 `/site/config` 下发，不新增接口。
- [x] 前端访客级覆盖用 localStorage `turaidc:shop-display-mode`（后台默认值兜底），不落 `users` 表、不影响其他访客。
- [x] 列表式保持现状不变；卡片式不自动选中第一个商品（选中态交给独立配置页承载）。
- [x] 卡片点「立即选购」跳 `/products/{id}`，该页同时展示商品描述。
- [x] 管理端描述编辑器双模式：结构化键值对增删改排序 + 高级 HTML 源码；高级切回结构化时解析不出的整行文本以 key 为空保留，不丢内容。
- [x] 四语言文案补齐。

## 非目标

- 不做展示方式的产品组级覆盖（魔方有 `product_groups.tpl_type`），YAGNI。
- 不把描述回传魔方。魔方字段是纯文本，双向同步会引入截断与格式降级问题。
- 不给首页商品卡片改版，只补字段让它能显示描述。
- 不改 `remark` 的任何既有职责。

## 进度

- 2026-10-15：后端迁移、描述服务、设置下发、三个站点资源、管理端读取接口与单测完成（`tests/Unit/ProductDescriptionServiceTest.php`，8 项）。
- 2026-10-15：官网卡片网格、切换控件、独立配置页描述块、四语言文案完成，`frontend-user-v3-www` 构建通过。
- 2026-10-15：管理端描述编辑器与展示方式开关完成。
- 2026-10-15：顺带修掉 v0.4.1 遗留的 `BusinessException` 回归——构造时查翻译表在未绑定 `translator` 的场景会抛 `BindingResolutionException`，把业务异常整个替换掉（`tests/Unit` 有 5 项因此失败）。改为取译文失败即放行中文原文，并补 `BusinessExceptionWithoutContainerTest` 锁住。
- 2026-10-18：按运营反馈恢复访客切换（右侧分段按钮，偏好存 localStorage），站点默认值只负责首屏；同时修掉「切到卡片后地址仍留商品 ID、刷新又回到配置页」的路由残留。管理端「商品展示」页仍是默认值的唯一维护入口。
- 2026-10-18（第二轮运营反馈）：卡片视觉重做（类型/库存徽标、规格标签、大号价格与 CTA）；卡片态地址改为 `/products/:typeId/:groupId/:childGroupId`，刷新与从配置页返回都回到原分类；顺带修掉路由视图外层未定义样式的 `page-fade` 过渡导致的「返回后整块空白」。
- 2026-10-18（第三轮运营反馈）：手机端恢复列表/卡片切换；宽屏增加访客级版心调整（固定宽度含档位 / 撑满屏幕，`max-width` 过渡动画，`localStorage` 记忆），独立配置页跟随同一版心。
- 2026-10-18（第四轮运营反馈）：三级分组改为路径参数（三段路由运行时按目录数据区分三级分组/旧商品深链）；独立配置页补展示方式切换；分组导航改纵向折叠并支持移到侧栏（手机端折叠展开）；修正二/三级分组标签。
- 2026-10-19（上游数据一致性核查）：上游商品描述改为导入 `products.description`（解码 + 白名单），描述空时回退 `remark` 中的上游描述；修复站点商品查询漏选 `description/remark`、`remark` 转义未解码、`qty_minimum=0` 吞下拉值、`4C` 写法漏 CPU、容量行误判内存、截断 remark 非法字节导致 JSON 失败等解析问题；线上执行配置全量同步（957 / 958 个商品更新）并回填 879 条描述。另发现 311 个绑定指向已不存在的上游商品（246 个仍在售）、FS 域名不可解析、QS 拉取超时，已记录待站长处理。
- 2026-10-19（失效商品收口）：把 254 个上游失效 / 供应商不可达但仍在上架的商品下架并移入隐藏分组「失效商品」；修复「云服务器/华中」因字节级 trim 裁坏中文导致的 500；选购页补手机端返回一级分组入口、卡片式隐藏无意义规格选择、去掉分组导航层级字样。
- 2026-10-19（第五轮运营反馈，卡片描述与交互）：卡片式描述只显示半句、与配置页不一致——分组商品查询漏选 `description`，回退到 255 字节的 `remark`；补齐查询列后两者同源。描述区规格列表改一行一项、去掉限高截断；自动生成描述剔除系统维度；整张卡片取消点击/键盘跳转，只有「立即选购」按钮进配置页。
- 2026-10-19（第六轮：统一路由 + 类型 slug）：展示方式不再与地址绑定，两种形态共用 `/products/:typeSlug`、`/:groupId`、`/:childGroupId`、`/:productId` 四层地址——目录地址下卡片式保持层级、列表式自动补全到首个商品；商品地址下列表式内联配置、卡片式渲染独立配置页；旧单段商品地址与三段商品深链仍兼容并规范到四段。一级分组新增可自定义 slug（后台「管理商品种类」可编辑、目录同步不再覆盖），并发起生产 slug 整理（cloud-server / game-cloud / web-hosting / cloud-desktop / panel-game-cloud / light-cloud 等），旧 code 链接运行时双匹配后自动规范。

- 2026-10-19（第七轮：目录加载性能）：新增聚合接口 `GET /v2/site/product-groups/{group}/catalog` 一次返回二/三级分组与目录内商品；前端目录缓存改「内存 3 分钟 + sessionStorage 60 秒（按登录态分区）」，三级分组商品按需加载，卡片式不再预取商品详情，首页去掉重复的 config 预取。此前一个 30 子分组的目录要发 30 多条商品请求，配合 8 个详情预取与 3 次 config，首屏三十多个请求、数秒才出内容。

## 决策日志

- **新增 `description` 列而不是复用 `remark`**：`remark` 同时是展示名兜底与回传魔方的 `description`，扩容到富文本会污染订单 / 账单快照文案（代码里已有注释警告过这点），且历史值是 `<li>` 卡片 HTML，语义不同。
- **自动生成只读不写库**：识别规则改进后历史商品立即跟着变，不必跑回填命令。代价是描述不是可导出数据，商家要固化某次结果在后台存一次即转为 `manual`。
- **2026-10-19 描述优先级改为「人工 > 上游描述 > 自动生成」**：上游商品描述才是用户在商品页看到的原文，用规格拼出来的描述与上游不一致。导入时把上游描述解码、消毒后写入 `description`；历史数据从上游目录回填，取不到时用 `remark` 里保存的上游描述兜底，最后才走自动生成。同步只补空描述，不覆盖后台人工修改。
- **展示方式只做全局默认值，不做商品级**：布局是页面级属性，商品级混排无法呈现；访客覆盖只作为「个人浏览偏好」存在本机，不写后端。
- **2026-10-18 再次放开访客切换**：此前一度以「运营无法确认配置效果」为由关闭用户级覆盖，实际运营反馈是「访客想按自己习惯看列表/卡片」。折中方案：站点默认值决定首屏形态，访客切换只存本机 localStorage，运营用无痕窗口验证默认值。
- **2026-10-18 三级分组放路径而不是查询参数**：`?child=` 地址不符合站点既有 `/products/:typeId/:groupId/...` 风格，改为三段路径；三段路径与旧商品深链形状相同（都是数字），因此在页面拿到目录 children / items_by_group 后按「命中三级分组 → 分组，否则 → 商品」判定，`syncSelectionRoute` 再把旧深链规范成四段商品路径。
- **不引入富文本编辑器**：管理端目前零富文本基础设施（无 wangeditor / tinymce / quill / tiptap，全仓 `v-html` 命中 0）。引入编辑器要连带做净化与预览一致性，成本远高于本次目标；结构化模式 + 高级源码 textarea 已经覆盖运营诉求。
- **`description_source` 随接口下发**：后台要能预览「留空时长什么样」，而商品列表接口不逐个跑识别（要过一遍商品名正则与全部配置项匹配），因此单独给 `GET /v2/admin/products/{id}/description`。该接口只读——描述是商品的普通字段，随商品表单提交，不开第二个写接口。
- **2026-10-19 目录改聚合接口而不是让前端少拉几个子分组**：慢的根因是「每个三级分组一次商品请求」的扇出模型，前端再优化也只能省部分请求；聚合接口把「该目录需要哪些商品」的判定放到服务端（按 `child_group_id` / 解析到的子分组 / 商品归属推断目标三级分组），一次查询 + 一次缓存命中就够。服务端实测热缓存 60-190ms，慢在请求数与网络往返，所以优先减请求而不是压服务端耗时。
- **聚合结果按 `site-products` 标签缓存 5 分钟**：复用既有模型事件失效（`invalidateCatalogCacheOnCatalogChanges`），目录、分组、商品任一变更都会清掉，不会出现「改了后台要等缓存过期」。
