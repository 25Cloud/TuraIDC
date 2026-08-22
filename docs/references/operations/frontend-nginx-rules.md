# 四端 Nginx 伪静态配置

四个站点独立部署、浏览器直连 API。三个前端站点不配置 API、上传资源或 WebSocket 反代。宝塔已经管理站点根目录、SSL 和 PHP-FPM 时，只编辑“设置 → 伪静态”，不要手工替换站点的完整 Nginx `server {}` 配置。

| 站点       | 域名示例              | 运行目录                        |
| ---------- | --------------------- | ------------------------------- |
| 官网       | `www.example.com`     | `frontend-user-v3-www/dist`     |
| 用户控制台 | `console.example.com` | `frontend-user-v4-console/dist` |
| 管理端     | `admin.example.com`   | `frontend-admin-v3/dist`        |
| API        | `api.example.com`     | `backend/public`                |

## 宝塔面板设置

先创建四个站点并按上表设置根目录。API 站点选择 PHP 8.3；三个前端站点保持静态站点。HTTPS 证书、80 到 443 跳转和域名由宝塔“SSL”页面配置，不能在伪静态框中填写 `listen`、`server_name`、`root`、`ssl_certificate` 或 PHP-FPM 配置。

纯 HTTP 环境关闭宝塔强制 HTTPS，并将 `SESSION_SECURE_COOKIE=false`；HTTPS 环境则让四个站点都使用 HTTPS。无论哪种环境，四个公开地址必须统一协议。

## 官网：www

官网公开路径（首页、产品、落地页、公告/帮助及其详情）由 API 站点（Laravel）读数据库动态渲染完整 HTML；其余路径保持 SPA 静态回退。完整伪静态：

```nginx
# SEO 动态渲染：公开路径转发到 API 站点（Laravel 读库渲染 title/meta/正文，
# 站名与 Logo 实时取自数据库）。将 api.example.com 换成实际 API 域名。
location = / {
    proxy_pass http://127.0.0.1/seo/www;
    proxy_set_header Host              api.example.com;
    proxy_set_header X-Real-IP         $remote_addr;
    proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
}

location ~ ^/(robots\.txt|sitemap\.xml)$ {
    proxy_pass http://127.0.0.1;
    proxy_set_header Host              api.example.com;
    proxy_set_header X-Real-IP         $remote_addr;
    proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
}

# 落地页 / 关于 / 条款 / 隐私 / 产品列表（单段路径）
location ~ ^/(cloud-server|hong-kong-server|us-server|high-defense-server|cloud-pc|about|terms|privacy|products)$ {
    proxy_pass http://127.0.0.1/seo/www$request_uri;
    proxy_set_header Host              api.example.com;
    proxy_set_header X-Real-IP         $remote_addr;
    proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
}

# 公告/帮助列表与详情（/notices、/notices/123、/help、/help/123）
location ~ ^/(notices|help)(/[0-9]+)?$ {
    proxy_pass http://127.0.0.1/seo/www$request_uri;
    proxy_set_header Host              api.example.com;
    proxy_set_header X-Real-IP         $remote_addr;
    proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
}

# 产品详情（/products/123；多段购买页路径保持 SPA 静态回退）
location ~ ^/products/[0-9]+$ {
    proxy_pass http://127.0.0.1/seo/www$request_uri;
    proxy_set_header Host              api.example.com;
    proxy_set_header X-Real-IP         $remote_addr;
    proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
}

location / {
    try_files $uri $uri/ /index.html;
}
```

> 内部转发走 `127.0.0.1:80`（HTTP）。若 API 站点开启强制 HTTPS 跳转会拦截该转发，需对 `127.0.0.1` 来源放行或改用 `https://api.example.com` 形式；后端已信任回环/私有网段代理（`bootstrap/app.php`），`X-Forwarded-Proto` 可正确传递 https。容器部署（Docker/1Panel 编排）时上述转发已内置在 `deploy/docker/frontends/nginx-default.conf`，无需重复配置。

## 用户控制台：console

```nginx
location / {
    try_files $uri $uri/ /index.html;
}
```

`/vnc/vnc.html` 是控制台构建产物的一部分，会被 `$uri` 直接命中。

## 管理端：admin

```nginx
location / {
    try_files $uri $uri/ /index.html;
}
```

## API：api

在 API 站点的“伪静态”完整填入：

```nginx
location ^~ /ws/vnc {
    proxy_http_version 1.1;
    proxy_set_header Host              $host;
    proxy_set_header X-Real-IP         $remote_addr;
    proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_set_header Upgrade           $http_upgrade;
    proxy_set_header Connection        "upgrade";
    proxy_buffering off;
    proxy_read_timeout 3600s;
    proxy_send_timeout 3600s;
    proxy_pass http://127.0.0.1:8100;
}

location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

项目未启用 VNC 远程控制时，可以删除 `/ws/vnc` 的整个 `location` 块，保留 Laravel 回退规则。宝塔生成的 PHP-FPM 配置保持不变。`/api`、`/uploads`、`/media` 都由 API 站点的 Laravel/PHP-FPM 处理或直接提供静态文件。

> 注：API 请求路径为 PHP-FPM 直连，Laravel 以 `REMOTE_ADDR` 为准，上方 `X-Forwarded-*` 仅用于 VNC Relay。若改为反向代理 API 端口，请遵守[受信代理与来源 IP 契约](deployment-and-scheduling.md)（单层受信代理把 `X-Forwarded-For` 重置为 `$remote_addr`，应用端口仅本机监听，不得把公网代理加入 `trustProxies`）。

## HTTP 与 HTTPS

- HTTP 环境在宝塔关闭强制 HTTPS，并将 `SESSION_SECURE_COOKIE=false`。
- HTTPS 环境在宝塔 SSL 页面开启证书和 80 到 443 跳转；前端 API 基址填写 `https://api.example.com/api`，VNC 自动使用 `wss`。
- 同一环境四个公开域名统一使用 HTTP 或 HTTPS，避免浏览器混合内容。

## 缓存建议

- `index.html` 使用 `Cache-Control: no-cache`（官方 SEO 页面 HTML 由 Laravel 动态生成，动态响应的缓存由后端 `SEO_CACHE_TTL` 控制）。
- 带 hash 的 JS、CSS、字体和图片可长期缓存；压缩由宝塔 Nginx 的全局能力处理，不需要在伪静态中添加模块指令。
- 不要在用户控制台、管理端站点重新添加 `/api`、`/uploads`、`/media`、`/ws/vnc` 的 `proxy_pass`（官网的 SEO 转发只针对上述公开路径）。
