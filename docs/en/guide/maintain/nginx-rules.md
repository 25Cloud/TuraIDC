---
source: guide/maintain/nginx-rules.md
sourceHash: 77945cd6d06fe5a78b41d7aa8cb1b8047b19ca17240f0490b215d0fef2366839
translatedAt: 2026-10-26
---

# Nginx Rewrite Rules for the Four Sites

The four sites are deployed independently, and browsers connect to the API directly. The three frontend sites do not configure proxying for the API, uploaded resources, or WebSocket. When BaoTa Panel already manages the site root directory, SSL, and PHP-FPM, edit only "Settings → Rewrite" and do not manually replace the site's complete Nginx `server {}` configuration.

| Site             | Example domain        | Running directory               |
| ---------------- | --------------------- | ------------------------------- |
| Official website | `www.example.com`     | `frontend-user-v3-www/dist`     |
| User console     | `console.example.com` | `frontend-user-v4-console/dist` |
| Admin            | `admin.example.com`   | `frontend-admin-v3/dist`        |
| API              | `api.example.com`     | `backend/public`                |

## 1. BaoTa Panel Settings

Create the four sites first and set the root directory of each as shown in the table above. Select PHP 8.3 for the API site; keep the three frontend sites as static sites. HTTPS certificates, the 80-to-443 redirect, and domains are configured on the BaoTa "SSL" page; `listen`, `server_name`, `root`, `ssl_certificate`, and PHP-FPM configuration must not be entered in the rewrite box.

In a plain HTTP environment, disable BaoTa forced HTTPS and set `SESSION_SECURE_COOKIE=false`; in an HTTPS environment, make all four sites use HTTPS. In either environment, the four public addresses must use the same protocol.

## 2. Official Website: www

The public paths of the official website (home page, products, landing pages, notices/help and their details) are rendered as complete HTML by the API site (Laravel) reading the database dynamically; all other paths keep the SPA static fallback. Complete rewrite rules:

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

# 落地页 / 关于 / 条款 / 隐私 / 产品列表（单段路径，含尾斜杠变体）
location ~ ^/(cloud-server|hong-kong-server|us-server|high-defense-server|cloud-pc|about|terms|privacy|products)/?$ {
    proxy_pass http://127.0.0.1/seo/www$request_uri;
    proxy_set_header Host              api.example.com;
    proxy_set_header X-Real-IP         $remote_addr;
    proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
}

# 公告/帮助列表与详情（/notices、/notices/123、/help、/help/123，含尾斜杠）
location ~ ^/(notices|help)(/[0-9]+)?/?$ {
    proxy_pass http://127.0.0.1/seo/www$request_uri;
    proxy_set_header Host              api.example.com;
    proxy_set_header X-Real-IP         $remote_addr;
    proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
}

# 产品详情（/products/123；多段购买页路径保持 SPA 静态回退）
location ~ ^/products/[0-9]+/?$ {
    proxy_pass http://127.0.0.1/seo/www$request_uri;
    proxy_set_header Host              api.example.com;
    proxy_set_header X-Real-IP         $remote_addr;
    proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
}

# 其余单段路径（无扩展名）转发后端：未登记的路径由 SEO 渲染返回品牌 404 壳 +
# HTTP 404 状态码，避免爬虫把 SPA 裸壳 200 当可索引页面。静态资源与多段路径不受影响。
location ~ ^/[^./]+/?$ {
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

> Internal forwarding goes to `127.0.0.1:80` (HTTP). If forced HTTPS redirect is enabled on the API site, it intercepts this forwarding; allow requests originating from `127.0.0.1` or switch to the `https://api.example.com` form instead. The backend already trusts loopback/private network proxies (`bootstrap/app.php`), so `X-Forwarded-Proto` passes https through correctly. For container deployments (Docker/1Panel orchestration), the forwarding above is already built into `deploy/docker/frontends/nginx-default.conf`; no need to configure it again.

## 3. User Console: console

```nginx
location / {
    try_files $uri $uri/ /index.html;
}
```

`/vnc/vnc.html` is part of the console build output and is matched directly by `$uri`.

## 4. Admin: admin

```nginx
location / {
    try_files $uri $uri/ /index.html;
}
```

## 5. API: api

Fill the following in full into the "Rewrite" box of the API site:

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

When VNC remote control is not enabled in the project, you can delete the entire `location` block for `/ws/vnc` and keep the Laravel fallback rule. The PHP-FPM configuration generated by BaoTa stays unchanged. `/api`, `/uploads`, and `/media` are all handled by Laravel/PHP-FPM on the API site, or the static files are served directly.

> Note: API request paths go directly through PHP-FPM, so Laravel relies on `REMOTE_ADDR`; the `X-Forwarded-*` headers above are used only by the VNC Relay. If you change to reverse proxying the API port, follow the [trusted proxy and source IP contract](../deploy/scheduling.md) (a single-layer trusted proxy resets `X-Forwarded-For` to `$remote_addr`; the application port listens on localhost only; public proxies must not be added to `trustProxies`).

## 6. HTTP and HTTPS

- In an HTTP environment, disable forced HTTPS in BaoTa and set `SESSION_SECURE_COOKIE=false`.
- In an HTTPS environment, enable the certificate and the 80-to-443 redirect on the BaoTa SSL page; set the frontend API base URL to `https://api.example.com/api`, and VNC automatically uses `wss`.
- Within the same environment, the four public domains must all use HTTP or all use HTTPS, to avoid browser mixed content.

## 7. Caching Recommendations

- Serve `index.html` with `Cache-Control: no-cache` (the HTML of the official SEO pages is generated dynamically by Laravel; caching of dynamic responses is controlled by the backend `SEO_CACHE_TTL`).
- Hashed JS, CSS, fonts, and images can be cached long term; compression is handled by BaoTa's global Nginx capability, so there is no need to add module directives to the rewrite rules.
- Do not add `proxy_pass` for `/api`, `/uploads`, `/media`, or `/ws/vnc` back into the user console or admin sites (the SEO forwarding of the official website targets only the public paths listed above).

## 8. SEO Troubleshooting

The official website is a SPA: the bare shell `index.html` carries `noindex, nofollow`, and the body is injected after dynamic rendering by the API site. The following situations therefore leave search engines completely unable to crawl the content:

| Symptom                                                                 | Cause                                                                                                                                                                                                                              | Action                                                                                                              |
| ----------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------- |
| The crawler gets the empty shell HTML and the source contains `noindex` | The official website rewrite rules were not configured per section 2, so every request falls into `try_files ... /index.html`                                                                                                      | Complete the forwarding per section 2 and route `/robots.txt`, `/sitemap.xml`, and the public paths to the API site |
| Requesting `/robots.txt` returns HTML instead of text                   | Same as above: the static fallback swallows that path as well                                                                                                                                                                      | Same as above                                                                                                       |
| `robots.txt` is fine but the page returns 500                           | The backend fails to fetch the frontend shell: a source deployment did not set `SEO_FRONTEND_SHELL_URL`, and the default value `http://frontends:8081/index.html` is a Docker service name that a source deployment cannot resolve | Set `SEO_FRONTEND_SHELL_URL=file:///绝对路径/frontend-user-v3-www/dist/index.html` in the `.env` of the API site    |
| The page opens but has no canonical / JSON-LD                           | The path is not registered in `SeoRenderService::resolvePage`, so the backend returns the 404 shell                                                                                                                                | Confirm that the path is a public path; long paths such as purchase pages are not rendered by design                |

Self-check commands:

```bash
# 爬虫 UA 取首页，看是否拿到服务端渲染的 head
curl -s -A 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)' https://www.example.com/ | grep -E 'canonical|ld\+json|noindex'
# robots 与 sitemap 必须是真实内容，不是 SPA 壳
curl -s https://www.example.com/robots.txt | head -3
curl -s https://www.example.com/sitemap.xml | head -c 200
```

## Related Documentation

- [Deployment Guide](../deploy/generic.md): full context for the Nginx configuration.
- [Deployment and Scheduling Guide](../deploy/scheduling.md): scheduling, queue, and trusted proxy contract.
