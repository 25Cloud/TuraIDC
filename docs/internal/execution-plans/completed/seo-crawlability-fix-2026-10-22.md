---
status: completed
updated: 2026-10-22
owner: backend
---

# 官网 SEO 可抓取性修复

## 背景

站点主入口 `idc.25y.cn` 是 Vue 3 SPA（`frontend-user-v3-www`），裸壳 `index.html` 故意带 `noindex, nofollow`，内容由 `SeoRenderService` 在服务端渲染后注入。设计链路是「官网 Nginx 把公开路径转发到 API 站点的 `/seo/www`」。

实际生产上这条链路完全断开，搜索引擎抓到的是裸壳并读到 `noindex`，表现为「完全抓不到」。IDC 这类业务获客几乎全部依赖自然搜索，首页、落地页、商品详情页进不了索引等于没有官网。

## 关键前置事实

- 后端渲染能力**已经存在且完整**：`GET /seo/www/{path}` 注入 title/description/canonical/robots/OG/Twitter/JSON-LD 与正文快照，`robots.txt`、`sitemap.xml` 同源；`resolvePage` 已覆盖首页、落地页、`/products`、`/products/{id}`、公告与帮助详情。
- `docs/guide/maintain/nginx-rules.md` 第 2 节**已经写好**官网站点的完整伪静态（`/robots.txt`、`/sitemap.xml`、公开路径 → `/seo/www`），生产宝塔却只配了 `try_files $uri $uri/ /index.html`，一条转发都没有。
- 生产渲染 500 的根因：`config('idc.seo.frontend_shell_url')` 默认值 `http://frontends:8081/index.html` 是 Docker Compose 服务名，源码部署（宝塔）解析不了该主机名，cURL error 6 → 渲染抛异常。`SeoRenderService::fetchShellTemplate()` **已支持 `file://` 本地路径**（读同机构建产物），所以源码部署只需配置正确，不需要改代码。
- `SeoRenderServiceTest` 用 `file://` 临时文件做 shell，证明了本地路径模式可用。
- `sitemap.xml` 当前只有 12 条：首页、`/products`、关于/条款/隐私/公告/帮助、5 个落地页、公告与帮助详情。**商品详情页一条都没有**——而商品详情页才是 IDC 真正的获客落地页。
- `/products`（浏览页）按设计 `noindex`，SEO 面在落地页与商品详情页；`/products/{id}` 的 `robots` 为空即允许索引。
- 官网与 API 同机（宝塔单机部署），API vhost 未强制 HTTPS 跳转，`127.0.0.1` + `Host: api.25y.cn` 的内部转发可用。

## 范围与验收

- [x] 源码部署默认能取到前端壳：`SEO_FRONTEND_SHELL_URL` 指向 `file:///www/wwwroot/TuraIDC/frontend-user-v3-www/dist/index.html`，生产渲染不再 500。
- [x] 官网站点按 `docs/guide/maintain/nginx-rules.md` 第 2 节配置转发：`/robots.txt`、`/sitemap.xml`、公开路径 → API 站点 `/seo/www`，其余路径保持 SPA 静态回退。
- [x] `sitemap.xml` 纳入在售商品详情页（`/products/{id}`），带 `lastmod`。
- [x] 爬虫 UA 访问 `https://idc.25y.cn/`、`/cloud-server`、`/products/{id}` 返回服务端渲染 HTML：含 canonical、不含 noindex、含 JSON-LD。
- [x] `https://idc.25y.cn/robots.txt` 返回真实 robots（不再是 SPA 壳），`/sitemap.xml` 返回合法 XML 且条目数 > 12。
- [x] 后端回归：`SeoRenderServiceTest` 全绿；新增商品页入 sitemap 的断言。
- [x] 文档：`nginx-rules.md` 增补「未配置伪静态导致爬虫吃到 noindex 裸壳」的排查条目；`docs/internal/changelog.md` 记修复。

## 不做

- 不做 SSR 框架迁移（Nuxt/Next）：现有后端渲染架构已覆盖需求，迁移代价与风险远大于收益。
- 不改 `/products` 浏览页的 `noindex` 策略：按设计由精选落地页与商品详情页承担索引，避免重复内容。
- 不动商品详情页的 `canonical` 与标题口径。

## 进度

- [x] 现状定位：三层断点（nginx 未转发 / shell 地址指向 Docker 主机名 / sitemap 缺商品页）。
- [x] 后端渲染能力核实：`robots.txt`、`sitemap.xml`、`/seo/www` 后端侧均正常，500 仅因 shell 地址。
- [x] 修 sitemap：纳入商品详情页（804 条）+ 回归用例（4/4 通过）。
- [x] 生产配置：`config('idc.seo.frontend_shell_url')` 按部署形态自动判定，源码部署走 `file://` 直读本机 dist；官网 nginx 补齐 SEO 转发伪静态。
- [x] 爬虫 UA 端到端验证：首页 / 落地页 / 商品详情页 / robots.txt / sitemap.xml 均返回服务端渲染内容，公网经 CDN 同样生效。
- [x] 补齐后端遗漏的公开页 `/open-source`（AGPL 源码提供入口，页脚有链接，此前会被判死链）。
- [x] 文档与收尾：nginx 排查条目、changelog、执行计划与 catalog 登记。

## 生产验收实测

| 检查项                                                | 结果                                                                                              |
| ----------------------------------------------------- | ------------------------------------------------------------------------------------------------- |
| `https://idc.25y.cn/` 爬虫 UA                         | 200，服务端渲染 HTML，含 canonical 与 Organization / WebPage / BreadcrumbList JSON-LD，无 noindex |
| `https://idc.25y.cn/cloud-server`                     | 200，独立 title 与 canonical                                                                      |
| `https://idc.25y.cn/products/{id}`                    | 200，含 `"@type":"Product"` 结构化数据                                                            |
| `https://idc.25y.cn/robots.txt`                       | 真实文本，指向 `https://idc.25y.cn/sitemap.xml`                                                   |
| `https://idc.25y.cn/sitemap.xml`                      | 816 条 URL（12 条栏目 + 804 个在售商品详情页）                                                    |
| 商品选购多段路径 `/products/{slug}/{gid}/{cid}/{pid}` | 仍走 SPA 静态回退，不受影响                                                                       |

## 决策日志

- **2026-10-22 修复路线选择**：走「接上既有链路」而不是「给搜索引擎单独做一套水合」。理由：后端已实现完整的服务端渲染与结构化数据，缺的只是 nginx 转发与一个配置项；再做一套爬虫专用 HTML 会形成两个真源，后续文案与商品改动要同步两处。
- **2026-10-22 shell 取用方式**：源码部署用 `file://` 读同机构建产物，不走 HTTP 回环。理由：零网络依赖、不依赖官网站点自身在线、前端重建后按 mtime 立即生效。
- **2026-10-22 nginx 转发范围**：按文档既有规则转发公开路径，未登记路径由后端返回 404 壳，避免爬虫把 SPA 裸壳 200 当可索引页面；静态资源与多段购买路径保持 SPA 回退。
- **2026-10-22 单段路径通配转发**：官网单段路由只有首页、`/products`、落地页、`/about`、`/terms`、`/privacy`、`/notices`、`/help`、`/open-source`，后端 `resolvePage` 已全部登记（本次补 `/open-source`），因此通配 `location ~ ^/[^./]+/?$` 转发是安全的：已登记路径返回可索引 HTML，未登记路径返回 404 壳。若后端未登记而直接照搬通配规则，官网的 `/login`、`/register`、`/cart`、`/console` 会被转成 404 壳把登录流程打死。
- **2026-10-22 首页标题**：改为取站点配置的 `browser_title`（与 SPA 首页同源），并把生产值从「二五云」改为「云服务器_独立服务器_IDC服务商-二五云」；品牌名保留，关键词前置。
