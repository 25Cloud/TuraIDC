# frontend-user-v3-www 迁移台账

## 范围

`frontend-user-v3-www` 承载公开官网与 SEO 页面，不读取 `client_token`，不承载 `/client/*` 用户控制台。

## 路由迁移

| 旧路由                                                | v3 路由                                                 | 页面                                                       | SEO | 状态                 |
| ----------------------------------------------------- | ------------------------------------------------------- | ---------------------------------------------------------- | --- | -------------------- |
| `/`                                                   | `/`                                                     | 首页                                                       | 是  | 已迁移               |
| `/products`                                           | `/products`                                             | 产品与服务                                                 | 是  | 已迁移               |
| `/products/:typeId`                                   | `/products/:typeSlug`                                   | 一级分组目录（卡片式保持地址层级；列表式自动补全到商品段） | 否  | 已迁移，不进 sitemap |
| `/products/:typeId/:groupId`                          | `/products/:typeSlug/:groupId`                          | 二级分组目录                                               | 否  | 已迁移，不进 sitemap |
| `/products/:typeId/:groupId/:productId`               | `/products/:typeSlug/:groupId/:thirdId`                 | 三级分组目录；第三段是旧商品 ID 时按旧深链兼容             | 否  | 已迁移，不进 sitemap |
| `/products/:typeId/:groupId/:childGroupId`            | 同上（第三段是三级分组 ID）                             | 三级分组目录（刷新/返回不丢）                              | 否  | 已迁移，不进 sitemap |
| `/products/:typeId/:groupId/:childGroupId/:productId` | `/products/:typeSlug/:groupId/:childGroupId/:productId` | 商品配置：列表式内联配置，卡片式渲染独立配置页（同一地址） | 否  | 已迁移，不进 sitemap |
| `/products/:id`                                       | `/products/:id`                                         | 旧商品 ID 地址兼容，加载后规范成四段商品地址               | 否  | 已迁移               |
| `/products/:typeId/...`（旧 code）                    | 兼容映射                                                | 运行时按 slug/code 双匹配后规范成 slug 地址                | 否  | 已迁移，不进 sitemap |
| `/about`                                              | `/about`                                                | 关于我们                                                   | 是  | 已迁移               |
| `/terms`                                              | `/terms`                                                | 服务条款                                                   | 是  | 已迁移               |
| `/privacy`                                            | `/privacy`                                              | 隐私政策                                                   | 是  | 已迁移               |
| `/notices`                                            | `/notices`                                              | 公告列表                                                   | 是  | 已迁移               |
| `/notices/:id`                                        | `/notices/:id`                                          | 公告详情                                                   | 是  | 已迁移               |
| `/help`                                               | `/help`                                                 | 帮助列表                                                   | 是  | 已迁移               |
| `/help/:id`                                           | `/help/:id`                                             | 帮助详情                                                   | 是  | 已迁移               |
| `/:pathMatch(.*)*`                                    | `/:pathMatch(.*)*`                                      | 404                                                        | 否  | 已迁移               |

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
