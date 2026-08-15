# www 端性能深度审查报告（专家团）

> 生成时间：2026-08-15 · 审查方式：6 维度专家并行 + 综合评审（7 个智能体、568 次工具调用）

# www 端性能深度审查报告

> 报告说明：本报告基于 6 个维度专家的原始发现做去重合并（同一问题的多条发现合并为一条，保留最完整证据与建议），并按业务影响划分 P0/P1/P2/P3；同时根据项目基线（dist JS 约 823KB、CSS 约 275KB）与大文件清单补充了 1 条未被覆盖的查漏发现（P2-11）。文中路径相对于项目根目录 `E:\TuraIDC\frontend-user-v3-www`。已实施的优化（路由懒加载、manualChunks、预压缩、fetchpriority、preconnect、config 预热、hero 视频 preload auto）不重复报告。

## 一、结论概览

**健康度评级：B（良）**

**一句话结论**：整体架构健康——路由全懒加载、manualChunks 划分合理（vendor-vue/vendor-axios/vendor-content 均按依赖分离）、Element Plus 样式按组件拆分（cssCodeSplit 生效，未发现 EP 全量样式打入）、构建期预压缩 .gz/.br、preconnect/dns-prefetch 已配置——但首页 LCP 关键路径存在「JS 链 → 两跳动态 chunk → 跨域 API」的三级串行且 API 无任何预热、入口渲染阻塞 CSS 含约 17KB 默认主题死字节、branding SVG（74KB/43KB）严重超规格且作为 favicon 每页必载，三大核心瓶颈直接拖住首屏指标；products 网络链路另有深链重复拉目录、分页无界翻页、详情预取风暴等明显缺陷。

**当前指标估算（4G / 中端机）**：

| 指标           | 估算值                               | 主要构成                                                                                                                                         |
| -------------- | ------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------ |
| 首页首屏 JS 链 | 约 433KB raw / 138KB br              | entry 66.7KB + vendor-vue 162.9KB + vendor-axios 46.4KB + WebsiteLayout 37.7KB + popper 52.8KB + button 19.6KB + home 27.4KB + base(dayjs) 7.1KB |
| 首页首屏 CSS   | 约 112KB raw / 17.5KB br             | 入口 index-Dmyntsrs 28.2KB（渲染阻塞，约 17KB 为默认主题死字节）+ WebsiteLayout 27.4KB + home 24KB + button 20.2KB + base 8.3KB + popper 3.9KB   |
| LCP            | 约 2.5-4s                            | 137KB br JS 关键链 + 两跳动态 chunk + 一次串行跨域 API RTT（150-400ms 可避免）+ hero 入场动画 200-420ms                                          |
| FCP            | 被 28KB 渲染阻塞 CSS 推迟约 75-150ms | head 入口 CSS 对 splash 首绘完全无用                                                                                                             |
| 全站 JS / CSS  | 823KB raw / 275KB raw                | 各路由懒加载 JS/CSS 之和                                                                                                                         |

## 二、P0 高优先级

### P0-1 首页 LCP 完全被 /v2/site/home API 往返阻塞，且首页关键路由 chunk 无预热（串行瀑布）

（合并：CWV critical「LCP 被 API 阻塞」+ 打包 medium「首页关键路由 chunk 未预加载」）

**问题**：首页 LCP 元素（hero 标题/视频）要等整条 JS 关键链执行完、在 onMounted 才发起 `siteApi.home()`，返回后才 `v-if` 渲染 HomeHeroCarousel；而构建产物只 preconnect + prefetch `/v2/site/config`，没有任何对 `/v2/site/home` 的预热；懒加载的 WebsiteLayout / home chunk / popper 都等 entry 执行、路由解析后才通过 `__vite__mapDeps` 动态拉取，形成「entry → 布局+popper → 首页 → API」四级串行等待。

**证据**：

- `src/pages/website/home/index.vue` 第 3 行 `<HomeHeroCarousel v-if="homeHeroReady">`；`homeHeroReady`（62-68 行）依赖 `homeContentReady = homeLoaded && homeLoadSucceeded`，后者只在 `loadHomePage` 中 `await siteApi.home()`（99 行）返回后置真；请求在 `onMounted`（124-125 行）才发起。
- `dist/index.html` 第 23-24 行仅 preconnect + prefetch config；第 32-34 行仅 modulepreload vendor-vue/vendor-axios；WebsiteLayout / home / popper 均不在 head 预热列表。
- 实测首页关键 JS 链合计 raw 433,813B / br 138,460B；WebsiteLayout 静态依赖 base-DxEogiJW.js（dayjs 7KB）与 vendor-axios。

**影响**：LCP ≈ 137KB br JS 关键链 + 两跳动态 chunk + 一次跨域 API RTT（含服务端渲染时间）。4G 中端机估算 2.5-4s，其中 API 往返约 150-400ms 属可避免部分；且没有任何手段在 JS 执行前启动该请求。

**修复建议**（按成本排序）：

1. `index.html` head 内联极小的 module script 提前 `fetch('/v2/site/home')` 并把 Promise 存入 `window.__HOME_FETCH__`，home 页 `loadHomePage` 直接复用——砍掉 1 个 RTT。注意与 axios 请求的 CORS/credentials 完全一致才可命中缓存。
2. 扩展现有 `client-fetch-priority` 构建插件：把首页路由链静态依赖（WebsiteLayout + home chunk + popper + button）注入 `<link rel="modulepreload">`，与 entry 并行下载，压缩首屏瀑布。
3. 长期：对首页做 SSR/prerender 把 hero 文案预渲染进 HTML（现有 `scripts/prerender-www.mjs` 只改写 title/meta/canonical/结构化数据，未渲染正文）。

**涉及文件**：`index.html`、`vite.config.js`、`src/pages/website/home/index.vue`

### P0-2 hero 标题/描述入场动画把 LCP 文字绘制推迟最多约 420ms

**问题**：h1/p 因 `:key` 每次（含首帧）重新挂载，`hero-body-rise` 动画从 `opacity:0` 重放，LCP 候选文字在 DOM 就绪后仍需从透明动画到不透明，绘制时刻被推迟约 200-420ms（每次切 slide 也重复）。

**证据**：`src/views/website/Home/components/HomeHeroCarousel.vue` 第 85-86 行 `:key="activeSlide.key"`；第 1136/1146 行 `.hero-title/.hero-desc { animation: hero-body-rise 0.42s cubic-bezier(0.22,1,0.36,1) }`；第 1215-1225 行 keyframes 起点 `opacity:0; transform: translate3d(0,14px,0)`。

**影响**：LCP 文字绘制被推迟约 200-420ms，与 API 延迟叠加，是纯加法延迟。

**修复建议**：首帧渲染禁用该动画（复用已有 `instantVideoReveal` 模式：初始置类去掉 animation，`nextTick` 后再恢复），只在轮播切换时保留 0.42s 淡入。

**涉及文件**：`src/views/website/Home/components/HomeHeroCarousel.vue`

### P0-3 桌面端 hero 视频可能成为 LCP：无 poster、无体积约束、多视频轮播双缓冲预取会同时下载两支视频

（合并：CWV high「视频可能成为 LCP」+ 图片 low「视频元素无 poster/体积约束」）

**问题**：video 元素无 poster、无 `<source type>`、无 fetchpriority；`src` 由 `resolvedVideoSrc(slide.video)` 动态生成，HTML 无法预加载；`switchToSlide` 把新视频 src 赋给非活跃 slot 后立即置 `activeVideoSlot`，使该 video 的 `preload` 变 `auto` 开始整段下载，而旧 slot 仍在播放。

**证据**：`HomeHeroCarousel.vue` 第 9-48 行两个 `<video>` 仅 `:src`，无 poster；第 17/22 行 `:preload="videoSlotA && activeVideoSlot==='a' ? 'auto' : 'none'"`；第 651-706 行 switchToSlide 双缓冲逻辑（694-703 行赋新 src + 置 activeVideoSlot）。默认 5 个 slide（201-272 行）video 均为空串，故默认/后端未配置时不产生视频请求；一旦管理端给多 slide 配置视频，首页停留期间会按轮播依次预取多支视频，且前端无码率/时长上限。

**影响**：桌面端（≥769px）如首屏 slide 带视频，LCP 从「文字绘制」变为「视频下载+解码+首帧」，在已约 2.5-4s 基础上再叠加数百 KB~MB 下载时间，LCP 可能突破 5s+；多视频轮播在弱网下与首屏关键资源竞争带宽。

**修复建议**：

1. 为 `<video>` 补 poster 静态首帧图（来自 API、可被缓存/preload），把 LCP 锚定在快速绘制的 poster/h1 上；后端将 hero 视频首帧抽成小尺寸封面图。
2. 后端约束视频码率/时长（建议 ≤5MB、≤20s），MP4(H.264)+WebM 双格式并带正确 MIME。
3. 首屏仅需氛围视频时优先使用单支循环视频而非多支轮播预取；保持现有 `shouldEnableHeroVideo` 的慢网拦截。

**涉及文件**：`src/views/website/Home/components/HomeHeroCarousel.vue`、后端 hero 数据约定

### P0-4 入口渲染阻塞 CSS：默认主题 base.css 死块 + 28KB head CSS 阻塞 splash/FCP

（合并：打包 high「默认主题 :root 死块」+ 运行时 medium「message 预编译 CSS」+ CWV high「head 28KB CSS 阻塞 FCP」）

**问题**：`src/app/bootstrap.ts` 第 5 行 `import 'element-plus/es/components/message/style/css'` 走 EP 预编译 CSS，经 `base/style/css.mjs` 引入 `element-plus/theme-chalk/base.css`（默认主题，不含 additionalData 注入的主题变量）；其余组件都经 `ElementPlusResolver(importStyle:'sass')` 编译为主题化 CSS。产物入口 CSS `index-Dmyntsrs.css`（28KB）同时含默认主题 `:root` 块（`--el-color-primary-rgb:64,158,255`，约 4.8KB 纯死字节）与后续主题化 `:root`（`--el-color-primary:#165dff`），且 `--el-color-primary-rgb` 未被主题覆盖，任何 `rgba(var(--el-color-primary-rgb),…)` 用法在 base css 加载前渲染成默认蓝；该 28KB 入口 CSS 由 `dist/index.html` 第 35 行以 `<link rel=stylesheet>` 渲染阻塞加载，而 splash 首绘完全不需要它。

**证据**：

- `dist/assets/css/index-Dmyntsrs.css`：offset 67 起默认 `:root{...--el-color-primary-rgb:64, 158, 255...}`（长 4,823 字符），全文件仅 1 处 `--el-color-primary-rgb` 且为默认值；主题化值 `22, 93, 255` 只存在于 sass 产物 `base-C20APSpZ.css`。
- 运行时顺序脆弱：ElMessage 等弹层主题依赖「默认 :root 先出现、主题 :root 后出现」的 CSS 顺序，一旦构建产物顺序变化会回退到默认蓝 #409eff。

**影响**：FCP（splash 首绘）被这张 render-blocking 样式表阻塞约 1 RTT + 解析（4G 约 +75-150ms，慢网更大）；入口 CSS 约 17KB 为默认主题重复内容（gz 约 3.7KB）属无效字节，拖慢首字节 CSS 解析。

**修复建议**：

1. bootstrap.ts 第 5 行改为 sass 路径 `import 'element-plus/es/components/message/style/index'`，走 Vite scss 管线被 additionalData 注入的主题变量编译，彻底移除默认主题 base.css（预期入口 CSS 由 28KB/6.3KB gz 降到约 11KB/3KB gz），并消除顺序依赖。
2. 在 `global.scss` 的 `:root` 补上 `--el-color-primary-rgb` 及 `primary-light-3/5/7/8/9`、`primary-dark-2` 派生变量。
3. 首屏真正关键的最小 CSS（splash + 应用壳）内联进 index.html，head 的入口 CSS 改异步加载（`<link rel="preload" as="style" onload="this.rel='stylesheet'">` 或 media="print" 切换），FCP 不再等它。

**涉及文件**：`src/app/bootstrap.ts`、`src/assets/styles/global.scss`、`index.html`

### P0-5 branding SVG 严重超规格：logo1.svg 74KB（favicon+splash）、logo.svg 43KB（页头/页脚）

（合并：图片 high + CWV medium）

**问题**：两个 SVG 体积巨大且被用在极小的渲染尺寸（favicon 48×48、splash 48×48、页头 logo ≤148×32、页脚 148×40），属明显缺陷；favicon 每个页面都会请求一次。

**证据**：`public/branding/logo1.svg` 共 73,952B（1352×940 整幅自动描边，1 个 path 的 d 占 73,796B，数值精度压到 2 位小数仅降到 66,521B，证明体积来自描边段数量而非精度）；`public/branding/logo.svg` 43,258B（d 占 42,757B）。压缩后 logo1.svg.br 仍 25,192B、logo.svg.br 15,286B。使用位置：favicon（index.html:20）、splash（index.html:83）、页头/页脚 logo（`WebsiteLayout.vue` 668 行 `logoSrc='/branding/logo.svg'`）。

**影响**：首屏需下载约 116KB 原始图片（压缩后仍约 48KB），与 entry JS 争抢关键带宽窗口（4G 约 +50-90ms，慢网更明显）；favicon 用 73KB（br 25KB）的 SVG 极不合理，浏览器每页都会请求一次，直接占用首屏关键请求带宽。

**修复建议**：用 Illustrator 简化/SVGO + 降低点数精度 + multipass 将两文件压到几 KB；为 favicon 单独提供 ≤2KB 小图标（或复用简化后的 logo1），splash 与 favicon 复用同一份小图。当前构建期已生成 .gz/.br，但原始体积才是根因，应从源文件根治。

**涉及文件**：`public/branding/logo1.svg`、`public/branding/logo.svg`

## 三、P1 中等

### P1-1 products 目录加载链路网络低效：深链重复拉目录、productsInit 兜底绕过缓存、children/根产品串行、switchType 重复请求

（合并：网络 high「深链重复拉取」+ medium「productsInit 兜底绕过缓存」+ medium「children 串行」+ low「switchType 重复请求」）

**问题/证据**：

- **深链重复拉取**：`useWebsiteProductsCatalog.js` `initWithAggregatedApi` 的 `hasRouteTarget` 分支（559-562 行）直接 `applyRouteSelection`，此时 `rootGroups` 仍为空（564-577 行才赋值，且仅非路由分支执行 `setCachedCatalog`），必走 479-487 行 `loadRootGroups → siteApi.productGroups` 重拉根分组 + `loadGroupPayload` 重拉目标组完整目录 → 首组目录被 productsInit 内拉一次 + loadGroupPayload 再拉一次。
- **兜底绕过缓存**：`src/api/site.js` 141-144 行当 `firstGroupId>0` 且响应无 catalog 时直接 `fetchV2SiteProductGroupCatalog(firstGroupId)`，未先查 `catalogCache`；且每次进 /products 都重新 GET purchase-context（无 TTL、无本地缓存），3 分钟目录缓存对首组形同虚设。
- **串行**：site.js 93-117 行 `await children` 完成才执行根产品，两者无数据依赖（仅 items_by_group 拼装依赖），本可并行。
- **switchType**：`useWebsiteProductsCatalog.js` 344-349 行每次切类型都打 `productGroups` 接口，而 init 564-577 行已能本地 `filterGroupsByType`，接口无任何缓存。

**影响**：深链首屏最少 3 次串行请求（purchase-context → product-groups → 目标组目录），若后端 purchase-context 不含 catalog 还会先全量拉首组目录造成 2 倍目录数据下载；最坏串行链 5-20+ 个请求；频繁切类型请求量线性增长。首页到购买页跳转变慢。

**修复建议**：路由分支先 `setCachedCatalog` 再 `applyRouteSelection`，并让 `loadGroupPayload` 优先从 catalogCache 命中；把目录缓存查询下沉到 site.js（同一模块级缓存 Map），productsInit 兜底前先查缓存；children 与根产品用 `Promise.all` 并行；switchType 改为本地按 `first_product_group_code` 过滤，仅本地无该类型数据时才请求。

**涉及文件**：`src/views/website/products/useWebsiteProductsCatalog.js`、`src/api/site.js`

### P1-2 入口 chunk 死代码瘦身：EP en 语言包 + seoLandingPages 全量文案

（合并：打包 medium「en 语言包死代码」+ 打包 medium「seoLandingPages 全量数据」）

**问题/证据**：

- **en locale**：入口 chunk `index-KfwYUMUb.js`（66,660B raw / 19,495B br）同时打包 `en` 与 `zh-cn` 两个 EP locale（实测含 `name:"en"` 与 `name:"zh-cn"` 两个对象，en 约 8,007 字符）。来源是 element-plus use-locale hook 静态 import en_default（`node_modules/element-plus/es/hooks/use-locale/index.mjs` 第 1 行），即使 bootstrap 始终 `provideGlobalConfig({locale:zhCn})`。
- **seoLandingPages**：`src/app/router/routes.ts` 第 2 行静态 import 全量数据（`src/data/seoLandingPages.js` 390 行/19KB，含 9 个落地页 hero/features/scenarios/visual 全部文案，入口 chunk 实测含 5 个 slug）。落地页组件本身懒加载（`index-EFRw9dcT.js` 仅 8.5KB），路由注册只需要 path/title/description/keywords。

**影响**：en locale 为纯死代码，约占入口 chunk raw 14%（约 2.4KB br/页）；seo 数据 9 个落地页正文从未访问也照常加载（约 8.9KB minified）。两项合计约 11KB br/页，全站每页首屏背负。

**修复建议**：vite.config.js `resolve.alias` 将 `element-plus/es/locale/lang/en.mjs` 指向最小 stub（或直接指向 zh-cn）；seoLandingPages 拆成两部分——entry 只保留 path/title/description/keywords 等路由 meta 必需字段的轻量表，hero/features/scenarios 等正文数据单独模块随落地页懒加载（defineAsyncComponent 或组件内 dynamic import）引入。

**涉及文件**：`vite.config.js`、`src/app/router/routes.ts`、`src/data/seoLandingPages.js`

### P1-3 header el-dropdown 连带 element-plus popper 机制 53KB 进入每页首屏

**问题**：`WebsiteLayout` 顶栏用户菜单使用 `el-dropdown/el-dropdown-menu`（第 183、225 行等），连带 element-plus popper/tooltip/scrollbar/use-form-item 全链 chunk `popper-BG62XCjp.js`（52,752B raw / 16,925B br，实测由 WebsiteLayout 动态引用）进入所有页面首屏。

**影响**：仅为一个头部下拉菜单，每页首屏多载约 53KB raw / 17KB br 的 element-plus popper 机制。

**修复建议**：将 header 用户菜单改为轻量自定义下拉（CSS + 原生事件，约 2-3KB），或改由点击后动态 import 该 el-dropdown 组件。

**涉及文件**：`src/layout/WebsiteLayout.vue`

### P1-4 fetchAll 分页 do-while 无空页/最大页数终止保护，total 与返回数不一致时无限翻页

**问题**：`src/api/site.js` 43-67/69-91 行 `do { … list.push(...data.list); total = Number(data.total || list.length); page += 1 } while (list.length < total)`；退出条件仅 `list.length >= total`。若后端 total 含 level 过滤/下架等未随页返回的条目（total 恒定且任意页返回空/不足），list.length 永远小于 total，page 无限递增，循环永不退出。

**影响**：一旦触发即为无界请求风暴（每页一个 GET，页数无上限），与失败重试叠加放大；当前代码把安全收敛完全寄托于后端分页语义正确。

**修复建议**：增加终止保护——记录「本页返回空 list」或「连续空页」即 break，并加最大页数硬上限（如 page<=100）；或改为后端一次性返回（调大 page_size / 增加 total 一致性校验）。

**涉及文件**：`src/api/site.js`

### P1-5 选中商品对当前分类全部商品逐个预取详情（可达 50 个请求），选中后后台 worker 不中止

**问题**：`prefetchProductDetails`（`useWebsiteProductCheckout.js` 491-527 行）对 `visibleProducts` 中全部 id（排除当前商品）发起 `fetchProductDetail → GET /v2/site/products/{id}`，仅 2 个并发 worker；`loadSelectedProduct`（456-483 行）abort 了 detail/stock/quote 三个 controller 但未调 `cancelProductDetailPrefetch`，选中商品后剩余产品仍继续被拉；每次分类切换（`useWebsiteProductsPage.js` 12-18 行 watch）都启动新一轮预取。

**影响**：大分类（page_size=50 全量分页）首看即触发几十个详情请求，与选中商品的 detail/stock/quote 争抢连接；分类来回切换时预取请求总量为「产品数×切分类次数」，消耗带宽并挤占关键请求。

**修复建议**：限制单分类预取上限（如仅预取前 8-10 个 + 视口内产品）；`loadSelectedProduct` 里调用 `cancelProductDetailPrefetch` 停掉后台预取；或改为懒加载（用户 hover/进入视口才拉详情）。

**涉及文件**：`src/views/website/products/useWebsiteProductCheckout.js`、`src/views/website/products/useWebsiteProductsPage.js`

### P1-6 机器规格正则解析重复执行：products 选中产品全 SKU 双份解析 + 详情页同级 Tab 每次渲染重跑

（合并：运行时 medium「desktop/mobile 双份解析」+ 运行时 medium「详情页同级每次渲染重跑」）

**问题/证据**：

- **products 列表**：`src/views/website/products/index.vue` 1517-1596 行 `desktopMachineSpecRows` 与 `mobileProductSpecRows` 各自对全部 `visibleProducts` 逐条执行 `resolveMachineSpecSelection / parseMachineSpecFromText / normalizeMemorySpecText / buildMachineSpecDisplayName` 等多次正则解析（`machineSpecResolver.js` 16-153）。两条 computed 都读取 `selectedProduct.value?.id === product.id`（1523/1286 行），每次点击表行/切换产品两份列表全部重算、同一 SKU 解析两遍；重算还级联刷新 1619-1759 行一串 computed，且 desktop 行对象重建导致整表 v-for 全量重渲染。
- **详情页同级 Tab**：`ProductDetail/index.vue` 第 26 行 `{{ resolveMachineSpecPresentation(sib).displayName }}` 直接写在 `v-for="sib in siblings"` 内，组件在每次配置变更、quoteLoading 翻转（executeQuote 636/674 行 true/false）、库存同步时整体重渲染，对所有同级产品重复正则解析。

**影响**：目录含 50-150 个 SKU 时每次产品选择触发约 2×N 次完整正则解析与整表行对象重建，低端机/移动端点击行后明显掉帧；详情页同级 5-30 个产品每次重渲染累计 CPU 开销明显。

**修复建议**：按 `product.id` 建立规格展示缓存 Map，desktop 与 mobile 两套行构建共用（仅 visibleProducts 变化或该商品 detail 变化时失效对应条目）；详情页抽 `siblingPresentations` computed（Map&lt;id, displayName&gt;），模板改为从 Map 读取。

**涉及文件**：`src/views/website/products/index.vue`、`src/views/website/ProductDetail/index.vue`

## 四、P2 / P3

### P2（一般收益）

- **P2-1 products 目录 children 与根产品串行**（`src/api/site.js` 93-117）：children 列表与根产品用 `Promise.all` 并行拉取，再并行子组产品，整体目录首屏延迟可省约一个 RTT 及更多分页时间。
- **P2-2 ContentListPage 侧边栏冗余请求**（合并 网络 medium + 运行时 low，`ContentListPage.vue`）：首屏 `loadList`（page_size=10）与 `loadSidebarContent`（page_size=20）并发拉同一接口第 1 页，第 1-10 条数据完全重叠；且每次分类/翻页/搜索同步（syncPage 296-300 行）都重新请求并按 view_count/时间排序后整体替换 hotArticles/recentArticles。建议合并为单次 page_size=20 拉取（主列表用前 10 条，sidebar 从同一 20 条排序取 5+5），侧边栏独立于列表同步、仅首次进入该内容类型时加载一次或模块级缓存复用。
- **P2-3 index.html prefetch /v2/site/config 死重**（合并 网络 medium + CWV low）：首页从不请求该接口（config 内嵌于 /v2/site/home 响应并 hydrate，bootstrap.ts 64-67 行对首页直接 return）；非首页浏览器级 prefetch 与 bootstrap `fetchSiteConfig()` 各打一次。建议移除该 prefetch，config 获取统一交给 bootstrap 的 fetchPromise 单例；真正缺预热的是 /v2/site/home（见 P0-1）。
- **P2-4 商品详情缓存为 composable 实例级**（`useWebsiteProductCheckout.js` 32-33/141-150）：`productDetailCache` 定义在 composable 内部，离开 /products 页即随实例销毁，与模块级 catalogCache 生命周期不一致，往返时同一批详情反复下载。建议提升为模块级并加 TTL。
- **P2-5 normalizer 全量透传原始字段**（`productCatalogNormalizer.js` 88-111/135-150、`contentNormalizer.js`）：`...item / ...payload` 展开保留全部原始字段，大目录（pricing_entries/config_options）在 catalog/productsByGroup/detail 多处完整驻留内存。建议 pick 白名单字段，非活跃分类 products 浅表化/丢弃，重复字段复用同一份数据。
- **P2-6 模板内未缓存 find/格式化**（`products/index.vue` 206-380/1010-1030）：`cpuConfig.options.find`、`selectedOptionLabel`、`formatFrequencyPair`、`normalizeInstanceSpecNote`（同一参数 1010/1027 行调两次）、`formatProductListPrice` 每次重渲染对每行/每配置重复执行。建议提为 computed，同一参数先在局部变量计算再复用。
- **P2-7 HomeHeroCarousel resolvedVideoSrc 每次渲染调用 + watch 冗余重入**（17/37/764-773 行）：`:src="resolvedVideoSrc(videoSlotA)"` 为模板函数调用每次渲染都做字符串归一化与正则；`watch(activeSlide)` 在 772 行重入 `switchToSlide`（auto=false 提前返回条件不成立，重走完整 slot 判断）。建议提为两个 computed；watch 分支当前 slot 已是目标视频时直接 `queueActiveVideoPlayback`。
- **P2-8 hero 骨架屏高度与真实 hero 不一致**（`HomeSectionSkeleton.vue` 169-173 行 vs `HomeHeroCarousel.vue` 849-851/952-962/1227-1239 行）：API 返回瞬间 LCP 元素出现的同时发生约 30-80px 垂直位移，估算 CLS 约 0.02-0.08（视口顶部），不同断点差值不同。建议对齐 min-height/padding 或固定占位高度。
- **P2-9 汉堡菜单打开触发 3 个并发 API + 大模板重渲染、touch 非被动监听**（`WebsiteLayout.vue` 725-735 行、`HomeHeroCarousel.vue` 57-58 行）：菜单打开同步调用 navProductInit + navNoticesMenu.init + navHelpMenu.init 三个请求并渲染双列多级菜单树，慢网下交互响应可能达 200ms+（INP 风险点）；touchstart/touchend 在 Vue3 下注册为非被动监听。建议菜单打开只请求当前展开的一层（或三份数据合并/缓存/复用 home 响应 root_groups），touch 事件加 .passive。优先级低于上述 LCP/FCP 项。
- **P2-10 splash 在 app.mount 瞬间被硬清除、首屏 3 种连续加载态**（`index.html` 36-85 行在 #app 内 + `bootstrap.ts` 61-62 行）：Vue3 mount 清空容器 innerHTML，fade-out 作用在已脱离文档的节点上不生效；挂载后主区经历「骨架 → 空白 → 另一骨架 → 内容」3 种加载态且衔接生硬。建议把 #app-splash 移出 #app 作兄弟节点，在首页骨架/hero 挂载完成后再淡出移除；去掉 App 层与 Layout 层重复骨架，保留一层统一品牌加载态。（不产生 CLS，主要是体验层）
- **P2-11 [查漏补充] products 页（核心购买页）首屏 JS 链全站最重**：实测主 chunk `index-qkf6aL8G.js` 112KB raw（全站最大页面 chunk），连带动态依赖 select 36KB / popper 53KB / websiteCheckout 15KB / button 20KB / input 15KB / dayjs 7KB 等，估算首屏 JS 约 575KB raw / 约 180KB br（entry+vendor-vue+vendor-axios 另计）。各维度均未覆盖其 bundle 体积。建议评估将结算（quote/checkout）、OS 图标映射等非首看逻辑拆出或懒加载；同时 `public/img/os/` 下 XenServer.svg(15.7KB)/Debian.svg(14.5KB) 等 OS 图标可 SVGO 压缩至 1-2KB。
- **P2-12 文章正文图片无宽高占位**（`ContentDetailPage.vue` 458-461 行）：正文仅 `:deep(img){max-width:100%;height:auto}`，无 aspect-ratio；shared/htmlSanitizer.js 155-159 会补 loading="lazy" decoding="async"，但 width/height 只保留源文自带值（绝大多数不带），懒加载图片没有预留空间，加载完成瞬间高度从 0 跳变。建议渲染管线为图片注入 aspect-ratio 占位或按元数据补宽高。

### P3（低优先）

- **P3-1 dayjs 冗余**（合并 打包 low + 运行时 low）：`package.json` 第 21 行声明 dayjs 但全项目（src/、@turaidc/shared）无直接使用（日期格式化用 `String(value).slice(0,10)` 或 new Date）；作为 EP 内部传递依赖经 WebsiteLayout 静态引用进入每页首屏（`base-DxEogiJW.js` 7,084B raw / 2,799B br）。建议确认布局链哪个 EP 组件传递引入；从 dependencies 移除 dayjs；若仅 date 类组件预留可对 dayjs 做 sideEffects 标记避免进布局链。影响小，可暂缓。
- **P3-2 页头/页脚 logo `<img>` 缺 width/height**（`WebsiteLayout.vue` 7-13/456-462 行）：CSS 已固定双轴尺寸（148×32/148×40），实际不发生 CLS，仅规范缺口；补 `width="148" height="32"`/`width="148" height="40"` 即可。
- **P3-3 死资源**：`public/img/os/` 下 cart.svg、slider.svg、no-upgrade.svg 三个 SVG 全仓库无引用（OS 图标映射只覆盖 Windows/Ubuntu/Debian 等 13 个），约 2.3KB 随 public 进入 dist/，确认无历史引用后删除。
- **P3-4 axios GET 去重 key 不含 Authorization**（`@turaidc/shared/runtime/http/core.ts` 39-49 行）：`buildSafeRequestKey` 仅取 method/baseURL/url/params/data/responseType/timeout/silentError，不含 headers；request.ts 114-117 行在去重挂载前注入 Authorization，同 URL 同 params 的 GET 若一个在 token 就绪前、一个在就绪后并发会共享先发起者的（可能未鉴权）响应。建议增加 header 维度或对带 token 的 GET 关闭去重。
- **P3-5 catalogPendingMap 取消后静默空载**（`useWebsiteProductsCatalog.js` 380-394/413-418 行）：切组 abort 后 pending map 中未 settle 的已取消 promise 被再次命中，await 抛 CanceledError 被 catch 静默吞掉且不重试，组 A→B→A 连点（尤其移动端抽屉）时组 A 可能停在空态无自愈。pending 命中时先判断是否已因取消而拒绝，catch 到 CanceledError 且 token 仍匹配时重新发起请求。

## 五、预期收益

| 优化项                     | 当前                                  | 优化后           | 说明                                                               |
| -------------------------- | ------------------------------------- | ---------------- | ------------------------------------------------------------------ |
| 入口 CSS（去默认主题死块） | 28KB raw / 6.3KB gz                   | 约 11KB / 3KB gz | bootstrap 改 sass 路径，移除约 17KB 默认主题重复内容               |
| 入口 CSS（异步化）         | 渲染阻塞 FCP +75-150ms                | 不阻塞首绘       | splash + 应用壳内联，head CSS 改 preload/onload 或 media 切换      |
| en locale 死代码           | 约 8KB raw / 2.4KB br/页              | 0                | alias 指向 zh-cn/stub                                              |
| seoLandingPages 正文       | 约 8.9KB minified/页                  | 路由 meta 轻量表 | 正文随落地页懒加载                                                 |
| popper 机制                | 53KB raw / 17KB br/页                 | 约 2-3KB         | header 下拉改轻量实现                                              |
| branding SVG               | 116KB raw / 48KB br（首屏含 favicon） | 数 KB            | SVGO 重生成 + 独立 favicon                                         |
| 首页 LCP                   | 约 2.5-4s                             | 约 1.8-2.5s      | API 预热（-1 RTT）+ 动画首帧禁用（-200-420ms）+ modulepreload 并行 |
| 首页首屏 JS br             | 约 138KB                              | 约 110KB         | en + seo + popper 三项合计约省 28KB br/页                          |
| products 目录网络          | 深链 3+ 次串行、首组拉两遍            | 1 次目录         | 缓存复用 + 并行化                                                  |
| products 详情预取          | 每分类 50 请求                        | 8-10 个          | 上限 + worker 中止                                                 |

说明：en locale + seo 数据 + popper 三项合计约省 28KB br/页首屏；配合 P0-1 的 modulepreload 与 API 预热，首页首屏关键路径可从「JS 链 → 两跳 chunk → API」三级串行收敛为「JS/CSS 并行 + 数据并行」两级，LCP 收益约 1-1.5s，FCP 收益约 75-150ms。

## 六、验证建议

1. **体积验证**（每项修复后执行 `npm run build`，比对 `dist/assets` 与 `dist/index.html`）：
   - 入口 CSS `index-*.css` 是否仍含默认主题块（`--el-color-primary-rgb:64, 158, 255`，应为 0 处）；入口 JS 是否仍含 `name:"en"` locale 对象与 5 个落地页 slug。
   - 用 `du -b dist/assets/js/*.js | sort -n` 比对 en/seo/popper 对应 chunk 体积变化。
2. **行为验证**：
   - fetchAll 终止保护：用 mock 接口返回 `total` 恒定且第 N 页空 list，确认循环在空页/上限处退出，不再无限翻页。
   - 深链目录去重：进入 `/products?typeId=..&groupId=..` 观察 Network 面板，确认 purchase-context 之后不再重复拉 product-groups + 目标组目录。
   - 详情预取中止：选中商品后确认剩余详情请求在 Network 面板出现 canceled。
3. **指标验证**（Lighthouse / PageSpeed Insights，4G + Moto G 配置，对比改动前后）：
   - LCP：确认 LCP 候选元素类型从 video 变为 h1/poster；记录 LCP 数值变化（预期 -1 RTT 与 -200-420ms）。
   - FCP：确认 splash 首绘不再等 28KB 入口 CSS。
   - CLS：hero 骨架替换瞬间是否还有 30-80px 位移；partner 跑马灯图片加载后是否跳变。
   - 用 PerformanceObserver 确认 LCP 候选元素与绘制时间戳。
4. **主题正确性**：修复 bootstrap sass 路径后，确认 ElMessage 弹层颜色仍为主题蓝 #165dff（而非默认 #409eff），且 global.scss 补齐 primary-rgb 后 `rgba(var(--el-color-primary-rgb),…)` 渲染正确。
5. **真机弱网**：DevTools Network 节流（Slow 4G）抓首页首屏请求瀑布，期望 modulepreload 与 /v2/site/home 并行，不再出现四级串行。
