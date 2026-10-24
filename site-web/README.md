# site-web · TuraIDC 项目官网首页

TuraIDC 这个项目本身的产品首页（不是站长各自部署的那套官网门户）。单页营销站，
Astro 构建成纯静态产物，四种语言各一份 HTML，部署在 EdgeOne Pages 的独立项目上，
域名为 `www.turaidc.com`。

```cmd
npm install
npm run dev      :: 本地预览，默认 http://localhost:4321
npm run build    :: 输出到 dist
npm run verify   :: 产物自检（4 语言正文、SEO、hreflang、结构化数据、sitemap、零客户端 JS）
```

约定：

- 所有访客可见文案在 `src/i18n/*.json`，键结构按模块嵌套，语言代码沿用
  `shared/locale` 的 `zh_CN` / `zh_TW` / `zh_HK` / `en_US`。
- 地址、锚点、图标 key、素材路径在 `src/data/site.ts`；文案与常量不互相包含。
- 事实口径以仓库根 `README.md` 与 `docs/` 为准，站点只做面向访客的重写，不新增能力声明。
- 图标只用 `tdesign-icons-vue-next`，不手绘 SVG、不用 emoji 当功能图标。
- 视觉 token 沿用 `docs/develop/design.md`（主色 `#165DFF`），深色是其镜像，不引入第二套品牌色。
- 组件一律不加 `client:*` 指令：页面零客户端运行时，唯一的脚本是主题切换与滚动进场。

部署、翻译流程、主题与排错见
`docs/internal/governance/site-web-maintenance.md`。
