# www 性能优化落地记录（P0 + P1）

> 日期：2026-08-15 · 范围：性能审查报告的 P0（5 项）+ P1（6 项）共 11 项改动
> 审查报告见同目录 `www-performance-audit.md` / `.html`

## 落地结果摘要

| 指标 | 优化前 | 优化后 |
|---|---|---|
| 首页首屏 JS 链 | ~433KB raw / ~138KB br | **~335KB raw / ~115KB br**（-23%） |
| 首页入口 chunk | 66.7KB raw / 19.5KB br | **53.0KB raw / 16.1KB br**（en stub + seo meta） |
| WebsiteLayout chunk | 37.7KB raw | **25.2KB raw**（自定义下拉替代 popper） |
| popper 机制 | 53KB raw / 17KB br 进每页首屏 | **0**（仅懒加载页按需引入） |
| 全站 JS | 823.6KB raw | **811.9KB raw** |
| favicon | 74KB SVG | **0.8KB PNG（32×32）** |
| splash logo | 74KB SVG | **2.1KB PNG（96×96）** |
| 入口 CSS 默认主题死块 | ~17KB（含 `--el-color-primary-rgb:64,158,255`） | **0（rgb 正确为 22,93,255）** |

## 各改动详情

### P0-1 首页 API 预热 + 路由链
- `index.html`：head 内联脚本在 JS 下载前 `fetch('/v2/site/home')` 存入 `window.__HOME_FETCH__`（仅根路径触发，非阻塞，失败静默回退）
- `src/pages/website/home/index.vue`：`resolveHome()` 优先消费预热响应，无效则回退 `siteApi.home()`
- `src/api/site.js`：新增 `homeFromRaw(raw)` 复用与 `home()` 一致的 normalizer 管线
- 收益：LCP 关键路径砍掉 1 个串行 API RTT（约 150–400ms 可避免）

### P0-2 hero 标题/描述首帧禁用入场动画
- `HomeHeroCarousel.vue`：新增 `instantBodyReveal`，首帧禁用 `hero-body-rise` 动画（复用 instantVideoReveal 模式），onMounted + nextTick 后恢复轮播切换淡入
- 收益：LCP 文字绘制提前约 200–420ms

### P0-3 hero 视频 poster
- `HomeHeroCarousel.vue`：`normalizeSlide` 支持 `video_poster/poster` 字段；两个 `<video>` 补 `:poster`（当前 slide 封面），LCP 锚定在快速绘制的占位图上
- 体积/码率约束需后端配合（≤5MB、≤20s、MP4+WebM）；慢网/移动端/reduced-motion 已由 `shouldEnableHeroVideo` 拦截

### P0-4 入口 CSS 去默认主题死块
- `src/app/bootstrap.ts`：message 样式改 sass 路径 `import 'element-plus/es/components/message/style/index'`，走 additionalData 主题变量编译，移除 EP 默认主题 base.css
- `src/assets/styles/global.scss`：`:root` 补齐 `--el-color-primary-rgb:22,93,255` 及 light-3/5/7/8/9、dark-2 派生变量（消除 CSS 顺序脆弱性）
- 收益：入口 CSS 死块归零，FCP 不再被默认主题重复内容拖累

### P0-5 branding SVG 瘦身 + 独立 favicon
- `public/branding/logo1.svg`（74KB → **39KB**）、`logo.svg`（43KB → **22KB**）：SVGO precision 2 + multipass 无损优化（渲染像素对比 avgDiff≈0.02，肉眼不可见）
- 新增 `favicon-16/32.png`（0.4/0.8KB）、`splash-96.png`（2.1KB），由品牌图形栅格化（方形画布居中）
- `index.html`：favicon 改用 PNG、splash 用 splash-96.png；`siteBranding.ts` 默认 favicon 同步更新
- 遗留：SVG 根因（自动描边复杂路径）仍需设计资源重制到数 KB，脚本已做到无损压缩极限

### P1-1 products 目录缓存复用 + children 并行 + switchType 本地过滤
- `src/api/site.js`：目录缓存下沉为模块级 `getCachedCatalog/setCachedCatalog/invalidateCatalogCache`（TTL 3min），`fetchV2SiteProductGroupCatalog` 命中缓存直接返回、写缓存；children 与根产品 `Promise.all` 并行；`fetchV2SiteProductPurchaseContext` 兜底先查缓存
- `useWebsiteProductsCatalog.js`：复用 site.js 缓存（删本地 Map）；深链分支用 productsInit 响应的 `root_groups`/`catalog` 填充本地缓存，避免重复拉目录；`rootGroupsByType` 类型分组缓存，switchType 回访已加载类型零请求

### P1-2 入口死代码瘦身
- 新增 `src/shims/en-locale.mjs` 最小 stub（`{ name:'en', el:{} }`），`vite.config.js` 自定义 `resolveId` 插件（`enforce:'pre'`）拦截 EP use-locale 静态引入的 en 语言包（Vite alias 对相对路径 import 会路径拼接损坏，故用 resolveId 钩子）
- 新增 `src/data/seoLandingMeta.js` 轻量路由 meta（~1KB）；`routes.ts`/`WebsiteLayout.vue`/构建脚本改引用 meta；`seoLandingPages.js` 仅保留全量内容数据 + `getSeoLandingPageByPath`（随懒加载落地页 chunk 加载）
- `tests/seoLandingPages.test.mjs` 同步更新（内容断言用 seoLandingPages.js，路由断言用 seoLandingMeta.js）

### P1-3 header 下拉轻量化
- `WebsiteLayout.vue`：桌面/移动用户菜单从 `el-dropdown` 改为轻量自定义下拉（`user-menu-panel`，CSS + 原生事件），共享菜单项；外部点击 / Escape 关闭；移除 `.el-dropdown__popper` 样式
- 收益：element-plus popper 机制（53KB raw / 17KB br）不再进入每页首屏

### P1-4 fetchAll 分页终止保护
- `src/api/site.js` 两个 `do-while`：本页返回空 list 或页数 > 100 即 break，避免 total 与返回数不一致时无限翻页请求风暴

### P1-5 详情预取上限 + 选中后中止
- `useWebsiteProductCheckout.js`：新增 `PRODUCT_DETAIL_PREFETCH_LIMIT = 8`，`prefetchProductDetails` 只预取列表前 8 个（多在首屏视口）；`loadSelectedProduct` 中止后台预取 worker

### P1-6 机器规格解析缓存
- `products/index.vue`：抽取 `resolveDesktopSpecRowBase`/`resolveMobileSpecCached`，按 `product.id` + product/detail 引用缓存解析结果，切选中仅重算选中行（原全 SKU 双份正则解析）；`family` 上下文回退取实时值
- `ProductDetail/index.vue`：新增 `siblingDisplayNames` computed（Map<id, displayName>），同级 Tab 从 Map 读取，不再每次渲染重复解析

## 验证

- ✅ `npm run lint`：通过
- ✅ `npx vue-tsc --noEmit`：通过
- ✅ `npm test`：5 个单测全部通过（含更新后的 seoLandingPages 测试）
- ✅ `npm run build`（vite + sitemap + prerender）：成功，`Prerendered 12 public routes`
- ✅ 产物抽查：入口 CSS `--el-color-primary-rgb:22,93,255`（0 处默认值）；入口 JS en locale 为 stub（`el:{}`）；popper chunk 归零；favicon/splash PNG 落地

## Lighthouse 实测对比（本地 preview + 移动端 4G 模拟）

> 方法：`scripts/lighthouse-audit.mjs` 起 vite preview 服务 dist，Lighthouse 12 移动端（390×844, 4G 节流）审计首页。
> baseline = git HEAD（优化前），optimized = 当前工作区。页面数据请求真实生产 API（跨域）。

| 指标 | baseline | optimized | 变化 |
|---|---|---|---|
| Performance | 76/100 | 82/100 | +6 |
| LCP | 5207ms | 4299ms | **-908ms（-17%）** |
| FCP | 1372ms | 1394ms | ~持平 |
| Speed Index | 4178ms | 3358ms | **-820ms** |
| TTI | 4620ms | 3744ms | **-876ms** |
| TBT | 175ms | 169ms | -6ms |
| CLS | 0.000 | 0.000 | 持平 |
| 传输字节 | 473.8KB | 306.9KB | **-166.9KB（-35%）** |
| 其中 script | 165.1KB | 123.4KB | -41.7KB |
| 其中 image | 47.4KB | 11.4KB | -36.0KB |
| 其中 stylesheet | 22.2KB | 15.0KB | -7.2KB |

报告：`docs/perf-audit/lighthouse/baseline.html` / `optimized.html`（可浏览器打开看明细）。

说明：本地 preview 下数据请求跨域访问生产 API，节流后 API 往返计入 LCP；生产环境（同源 + HTTP 缓存）指标预计更优。LCP 仍有约 4.3s，主要来自 JS 关键链 + API 往返，若需进一步压缩可考虑 SSR/prerender hero 文案（见报告 P0-1 方案 c）。

## 遗留建议（未在本轮实施）

- P2-3：index.html 中 `/v2/site/config` prefetch 与 bootstrap 重复，可移除
- P2-4：商品详情缓存提升为模块级（当前 composable 实例级）
- P2-7：`resolvedVideoSrc`/`activePoster` 模板每次渲染调用，可提为 computed
- P3-1：dayjs 冗余（EP 传递依赖进布局链，7KB raw）
- SVG 根因重制需设计资源（Illustrator 重新描摹 logo）
- hero 视频体积/码率约束需后端配合
