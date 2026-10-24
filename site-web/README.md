# site-web · TuraIDC 项目官网首页

TuraIDC 这个项目本身的产品首页（不是站长各自部署的那套官网门户）。单页营销站，
Astro 构建成纯静态产物，部署在 EdgeOne Pages 的独立项目上，域名为
`www.turaidc.com`。

```cmd
npm install
npm run dev      :: 本地预览，默认 http://localhost:4321
npm run build    :: 输出到 dist
npm run verify   :: 产物自检（正文、SEO 头部、结构化数据、sitemap、零客户端 JS）
```

约定：

- 页面文案的唯一来源是 `src/data/content.ts`，组件里不写硬编码文案。
- 事实口径以仓库根 `README.md` 与 `docs/` 为准，站点只做面向访客的重写，不新增能力声明。
- 图标只用 `tdesign-icons-vue-next`，不手绘 SVG、不用 emoji 当功能图标。
- 视觉 token 沿用 `docs/develop/design.md`（主色 `#165DFF`），不引入第二套色板与圆角。
- 组件一律不加 `client:*` 指令：页面零客户端运行时，唯一的脚本是页面里那段原生滚动进场逻辑。

部署、构建配置、SEO 检查清单与排错见
`docs/internal/governance/site-web-maintenance.md`。
