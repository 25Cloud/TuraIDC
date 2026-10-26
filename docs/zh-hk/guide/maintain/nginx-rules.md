---
source: guide/maintain/nginx-rules.md
sourceHash: 77945cd6d06fe5a78b41d7aa8cb1b8047b19ca17240f0490b215d0fef2366839
generated: zh-hk
---

<!-- 本檔案由 docs-i18n.mjs trad zh-hk 從 docs/guide/maintain/nginx-rules.md 產生，請勿手動編輯。 -->
# 四端 Nginx 偽靜態配置

四個站點獨立部署、瀏覽器直連 API。三個前端站點不配置 API、上傳資源或 WebSocket 反代。寶塔已經管理站點根目錄、SSL 和 PHP-FPM 時，只編輯“設置 → 偽靜態”，不要手工替換站點的完整 Nginx `server {}` 配置。

| 站點       | 域名示例              | 運行目錄                        |
| ---------- | --------------------- | ------------------------------- |
| 官網       | `www.example.com`     | `frontend-user-v3-www/dist`     |
| 用戶控制台 | `console.example.com` | `frontend-user-v4-console/dist` |
| 管理端     | `admin.example.com`   | `frontend-admin-v3/dist`        |
| API        | `api.example.com`     | `backend/public`                |

## 1. 寶塔面板設置

先創建四個站點並按上表設置根目錄。API 站點選擇 PHP 8.3；三個前端站點保持靜態站點。HTTPS 證書、80 到 443 跳轉和域名由寶塔“SSL”頁面配置，不能在偽靜態框中填寫 `listen`、`server_name`、`root`、`ssl_certificate` 或 PHP-FPM 配置。

純 HTTP 環境關閉寶塔強制 HTTPS，並將 `SESSION_SECURE_COOKIE=false`；HTTPS 環境則讓四個站點都使用 HTTPS。無論哪種環境，四個公開地址必須統一協議。

## 2. 官網：www

官網公開路徑（首頁、產品、落地頁、公告/幫助及其詳情）由 API 站點（Laravel）讀數據庫動態渲染完整 HTML；其餘路徑保持 SPA 靜態回退。完整偽靜態：

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

> 內部轉發走 `127.0.0.1:80`（HTTP）。若 API 站點開啟強制 HTTPS 跳轉會攔截該轉發，需對 `127.0.0.1` 來源放行或改用 `https://api.example.com` 形式；後端已信任迴環/私有網段代理（`bootstrap/app.php`），`X-Forwarded-Proto` 可正確傳遞 https。容器部署（Docker/1Panel 編排）時上述轉發已內置在 `deploy/docker/frontends/nginx-default.conf`，無需重複配置。

## 3. 用戶控制台：console

```nginx
location / {
    try_files $uri $uri/ /index.html;
}
```

`/vnc/vnc.html` 是控制台構建產物的一部分，會被 `$uri` 直接命中。

## 4. 管理端：admin

```nginx
location / {
    try_files $uri $uri/ /index.html;
}
```

## 5. API：api

在 API 站點的“偽靜態”完整填入：

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

項目未啟用 VNC 遠程控制時，可以刪除 `/ws/vnc` 的整個 `location` 塊，保留 Laravel 回退規則。寶塔生成的 PHP-FPM 配置保持不變。`/api`、`/uploads`、`/media` 都由 API 站點的 Laravel/PHP-FPM 處理或直接提供靜態文件。

> 注：API 請求路徑為 PHP-FPM 直連，Laravel 以 `REMOTE_ADDR` 為準，上方 `X-Forwarded-*` 僅用於 VNC Relay。若改為反向代理 API 端口，請遵守[受信代理與來源 IP 契約](../deploy/scheduling.md)（單層受信代理把 `X-Forwarded-For` 重置為 `$remote_addr`，應用端口僅本機監聽，不得把公網代理加入 `trustProxies`）。

## 6. HTTP 與 HTTPS

- HTTP 環境在寶塔關閉強制 HTTPS，並將 `SESSION_SECURE_COOKIE=false`。
- HTTPS 環境在寶塔 SSL 頁面開啟證書和 80 到 443 跳轉；前端 API 基址填寫 `https://api.example.com/api`，VNC 自動使用 `wss`。
- 同一環境四個公開域名統一使用 HTTP 或 HTTPS，避免瀏覽器混合內容。

## 7. 快取建議

- `index.html` 使用 `Cache-Control: no-cache`（官方 SEO 頁面 HTML 由 Laravel 動態生成，動態響應的快取由後端 `SEO_CACHE_TTL` 控制）。
- 帶 hash 的 JS、CSS、字體和圖片可長期快取；壓縮由寶塔 Nginx 的全局能力處理，不需要在偽靜態中添加模塊指令。
- 不要在用戶控制台、管理端站點重新添加 `/api`、`/uploads`、`/media`、`/ws/vnc` 的 `proxy_pass`（官網的 SEO 轉發只針對上述公開路徑）。

## 8. SEO 排查

官網是 SPA，裸殼 `index.html` 帶 `noindex, nofollow`，正文由 API 站點動態渲染後注入。因此下面幾種情況會讓搜尋引擎「完全抓不到」：

| 現象                                  | 原因                                                                                                                                        | 處理                                                                                                 |
| ------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------- |
| 爬蟲抓到空殼 HTML，源碼裡有 `noindex` | 官網偽靜態沒按第 2 節配置，全部請求落進 `try_files ... /index.html`                                                                         | 按第 2 節補齊轉發，`/robots.txt`、`/sitemap.xml` 與公開路徑轉到 API 站點                             |
| 訪問 `/robots.txt` 返回 HTML 而非文本 | 同上，靜態回退把該路徑也吞了                                                                                                                | 同上                                                                                                 |
| `robots.txt` 正常但頁面 500           | 後端取前端殼失敗：源碼部署未設 `SEO_FRONTEND_SHELL_URL`，默認值 `http://frontends:8081/index.html` 是 Docker 服務名，源碼部署解析不了該主機 | 在 API 站點 `.env` 設 `SEO_FRONTEND_SHELL_URL=file:///绝对路径/frontend-user-v3-www/dist/index.html` |
| 頁面能打開但沒有 canonical / JSON-LD  | 路徑未登記在 `SeoRenderService::resolvePage`，後端返回 404 殼                                                                               | 確認路徑屬於公開路徑；購買等長路徑本就不渲染                                                         |

自查命令：

```bash
# 爬虫 UA 取首页，看是否拿到服务端渲染的 head
curl -s -A 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)' https://www.example.com/ | grep -E 'canonical|ld\+json|noindex'
# robots 与 sitemap 必须是真实内容，不是 SPA 壳
curl -s https://www.example.com/robots.txt | head -3
curl -s https://www.example.com/sitemap.xml | head -c 200
```

## 關聯文檔

- [部署指南](../deploy/generic.md)：Nginx 配置的完整上下文。
- [部署與調度指南](../deploy/scheduling.md)：調度、隊列與受信代理契約。
