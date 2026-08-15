# frontend-user-v3-www 最终验收报告

## 当前状态

公开官网路由当前由 `frontend-user-v3-www` 承载，构建后生成 sitemap、robots 和静态公开路由 HTML。旧 `frontend-client` 仅作为历史拆分背景，不再是当前目录。

## 验收记录

| 项目 | 结果 | 说明 |
|---|---|---|
| 独立工程 | 通过 | `npm run build` 通过 |
| 公开路由 | 已迁移 | 仅包含官网和公开内容 |
| `/client/*` | 不承载 | 控制台路由已由 `frontend-user-v4-console` 承载 |
| SEO meta | 通过 | 构建产物 canonical / OG URL 使用 `https://www.coyjs.cn` |
| sitemap | 通过 | `dist/sitemap.xml` 只包含公开路由，域名为 `https://www.coyjs.cn` |
| robots | 通过 | `dist/robots.txt` 禁止 `/client/`、`/console/`，sitemap 指向真实域名 |
| 控制台外链 | 通过 | 登录、注册、购买续接统一指向 `https://console.coyjs.cn/client/*` |
| 控制台静态资源隔离 | 通过 | `www` 不发布 `vnc/`、`novnc/` 静态目录 |
| 构建 | 通过 | 2026-06-09 已完成生产构建 |

## 待生产配置

- `VITE_PUBLIC_SITE_URL=https://www.coyjs.cn`。
- `VITE_CONSOLE_SITE_URL=https://console.coyjs.cn`。
- `VITE_API_BASE_URL=https://api.coyjs.cn/api`（HTTP 环境使用对应的 `http://` API 域名）。
- 如使用 CDN，设置 `VITE_WWW_ASSET_BASE_URL` 或既有 CDN 变量。

## 最终验证

```bat
cmd /c "cd frontend-user-v3-www && npm run check:source-health"
cmd /c "cd frontend-user-v3-www && npm run build:type"
cmd /c "cd frontend-user-v3-www && npm run build"
```

补充产物检查：

- `dist/sitemap.xml`、`dist/robots.txt` 已使用 `https://www.coyjs.cn`。
- `dist` 未匹配到 `www.example.com`、`www.xxx.com`、`client_token`、`ClientDashboard`、`ClientServices`、`/client/services`、`/client/invoices`。
