# frontend-user-v3-www 迁移台账

## 范围

`frontend-user-v3-www` 承载公开官网与 SEO 页面，不读取 `client_token`，不承载 `/client/*` 用户控制台。

## 路由迁移

| 旧路由 | v3 路由 | 页面 | SEO | 状态 |
|---|---|---|---|---|
| `/` | `/` | 首页 | 是 | 已迁移 |
| `/products` | `/products` | 产品与服务 | 是 | 已迁移 |
| `/products/:typeId/:groupId/:productId` | 同旧路由 | 产品购买深链兼容 | 否 | 已迁移，不进 sitemap |
| `/products/:typeId/:groupId/:childGroupId/:productId` | 同旧路由 | 产品购买深链兼容 | 否 | 已迁移，不进 sitemap |
| `/products/:id` | `/products/:id` | 商品详情 | 是 | 已迁移 |
| `/about` | `/about` | 关于我们 | 是 | 已迁移 |
| `/terms` | `/terms` | 服务条款 | 是 | 已迁移 |
| `/privacy` | `/privacy` | 隐私政策 | 是 | 已迁移 |
| `/notices` | `/notices` | 公告列表 | 是 | 已迁移 |
| `/notices/:id` | `/notices/:id` | 公告详情 | 是 | 已迁移 |
| `/help` | `/help` | 帮助列表 | 是 | 已迁移 |
| `/help/:id` | `/help/:id` | 帮助详情 | 是 | 已迁移 |
| `/:pathMatch(.*)*` | `/:pathMatch(.*)*` | 404 | 否 | 已迁移 |

## 构建产物要求

- `dist/index.html`
- `dist/sitemap.xml`
- `dist/robots.txt`
- 静态公开路由 HTML：`products/`、`about/`、`terms/`、`privacy/`、`notices/`、`help/`
- `.gz` / `.br` 预压缩文件
- 不发布 `vnc/`、`novnc/` 等控制台专用静态资源

## 验收命令

```bat
cmd /c "cd frontend-user-v3-www && npm run check:source-health"
cmd /c "cd frontend-user-v3-www && npm run build:type"
cmd /c "cd frontend-user-v3-www && npm run build"
```
