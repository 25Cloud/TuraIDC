# Docker / 1Panel 部署指南

面向全新服务器、使用容器化方式部署 TuraIDC 的完整指南。使用 Docker Compose 一键拉起全部服务，配合 GitHub Actions 自动构建推送镜像，服务器只负责拉取运行，不再逐端手工部署；也可直接用 1Panel 的编排与反代能力托管同一套 Compose 文件。

- 对齐时间：`2026-08-22`
- 传统宝塔部署见 [宝塔部署项目指南](bt-panel-deployment.md)，现网运维口径见 [部署与调度指南](deployment-and-scheduling.md)
- 部署文件位于仓库根 `deploy/docker/`，唯一需要改的配置是 `deploy/docker/.env`
- CI 构建推送见 `.github/workflows/docker-image.yml`
- 🧭 **从智简魔方财务系统迁移**：部署前若需导入智简魔方财务（ZJMF）老站数据，先阅读 [从智简魔方财务系统迁移](../database/migrate-from-zjmf-finance.md)（产品/用户/订单/上游/实名全流程与踩坑记录）

## 1. 容器拓扑

```
deploy/docker/docker-compose.yml  (project: turaidc)
├── mysql (mysql:8.0, 命名卷 mysql-data)      [可选：DB_HOST 留空时才拉取并启动]
├── redis (redis:7-alpine, 命名卷 redis-data) [可选：REDIS_HOST 留空时才拉取并启动]
├── app   (后端镜像, :80)
│     ├── php-fpm   (PHP 8.3, 含 pdo_mysql/redis/pcntl/opcache/gd/zip/intl/bcmath)
│     ├── nginx     (API + /ws/vnc WebSocket -> 127.0.0.1:8100)
│     ├── cron      (每分钟 php artisan schedule:run，驱动心跳与队列消费)
│     └── vnc:relay (VNC WebSocket 中继, 127.0.0.1:8100)
│     命名卷: app-storage / app-uploads / app-media
├── frontends (nginx:alpine, 三端口: 8081=官网 / 8082=控制台 / 8083=管理端)
│     └── 官网公开路径转发到 app 的 /seo/www/*（SEO 动态渲染）
```

官网 SEO：`frontends` 容器内 Nginx 将官网公开路径（首页、产品、落地页、公告/帮助及其详情）`proxy_pass` 到 `app` 容器的 `/seo/www/{path}`；Laravel 读数据库生成完整 HTML（站名/Logo/meta/正文），并以 `http://frontends:8081/index.html` 为模板（容器内部网络直连静态文件，无循环）。`/sitemap.xml`、`/robots.txt` 由 Laravel 动态生成。

`DB_HOST` / `REDIS_HOST` 留空（默认）时本地 mysql / redis 容器随编排启动；填写远程地址则对应本地容器不创建、镜像也不被 `docker compose pull` 拉取，app 直连远程服务，两者互相独立可单独切换。

镜像流向：GitHub Actions 构建并推送 `ghcr.io/<owner>/turaidc-{app,frontends}` → 服务器 `docker compose pull`。

与宝塔生产拓扑一一对应：前端三端合一（一个容器三端口分别托管 www/console/admin 静态站点）、后端走 PHP-FPM、无常驻 `queue:work`（由每分钟 `schedule:run` 并行消费业务队列与 `automation` 队列）、VNC Relay 独立常驻。

## 2. 前置条件

- 服务器：Linux（Ubuntu 20.04+/Debian 11+/CentOS 7+），内存建议 4G+，磁盘 20G+
- Docker Engine 24+ 与 Compose v2（`docker compose version` 可查）
- 四个域名解析到本机（`api`、`www`、`console`、`admin`），生产环境必须同一协议（HTTPS）
- GitHub 仓库（当前 `github.com/25Cloud/TuraIDC`），用于 CI 构建与 GHCR 镜像托管

## 3. 配置说明（deploy/docker/.env）

先复制模板并编辑：

```bash
cd deploy/docker
cp .env.example .env
vim .env
```

| 配置项                                                             | 必填 | 说明                                                                                                                                                                |
| ------------------------------------------------------------------ | ---- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `APP_URL` / `FRONTEND_URL` / `CLIENT_CONSOLE_URL` / `ADMIN_URL`    | 是   | 四个公开地址，互不相同、同一协议、无路径；`https://api.example.com` 形式                                                                                            |
| `CLIENT_SESSION_COOKIE_DOMAIN`                                     | 否   | 官网/控制台跨子域共享登录态时填父域如 `.example.com`；单域留空                                                                                                      |
| `REGISTRY` / `IMAGE_NAMESPACE` / `IMAGE_TAG`                       | 否   | 镜像仓库地址，默认 `ghcr.io` / `25cloud` / `latest`，与 CI 推送目标一致                                                                                             |
| `APP_KEY`                                                          | 否   | **不要在此文件设置**。容器启动时自动生成并写入 `backend/.env`。若在此设空值，Docker env_file 会注入容器环境变量，Dotenv 不覆盖已存在环境变量，导致生成的 key 被忽略 |
| `INSTALL_ADMIN_PASSWORD`                                           | 是   | 首次初始化默认管理员 `cerbo` 的密码，至少 12 位强密码，仅空库首次生效                                                                                               |
| `SESSION_SECURE_COOKIE`                                            | 是   | HTTPS 环境 `true`，纯 HTTP 环境 `false`                                                                                                                             |
| `DB_HOST` / `DB_PORT`                                              | 否   | 数据库来源：留空使用编排内 mysql 容器（默认）；填写远程地址则本地 mysql 不拉取不启动，app 直连远程库，端口默认 3306                                                 |
| `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` / `DB_ROOT_PASSWORD` | 是   | 数据库名与账号；同时用于 mysql 容器和 app 容器（远程模式仍需填写供 app 连接）                                                                                       |
| `REDIS_HOST` / `REDIS_PORT`                                        | 否   | Redis 来源：留空使用编排内 redis 容器（默认）；填写远程地址则本地 redis 不拉取不启动，app 直连远程，端口默认 6379                                                   |
| `REDIS_PASSWORD`                                                   | 否   | Redis 密码；默认空。公网切勿放行 6379；远程模式必须与远程实例一致                                                                                                   |
| `API_PORT` / `WWW_PORT` / `CONSOLE_PORT` / `ADMIN_PORT`            | 否   | 宿主机映射端口，默认 8080/8081/8082/8083                                                                                                                            |
| `CACHE_STORE` / `QUEUE_CONNECTION` / `SESSION_DRIVER`              | 否   | 与现网口径一致：redis / database / file，一般不用改                                                                                                                 |
| `SENTRY_LARAVEL_DSN`                                               | 否   | Sentry DSN，留空关闭                                                                                                                                                |
| `MAIL_FROM_ADDRESS`                                                | 否   | 默认发件人；SMTP 凭据由管理端"邮件插件"配置                                                                                                                         |
| `SEO_SITE_URL`                                                     | 否   | 官网公开地址，用于 canonical / sitemap / JSON-LD；默认取 `APP_URL`，多域名部署时建议显式填官网域名                                                                  |
| `SEO_FRONTEND_SHELL_URL`                                           | 否   | 官网 index.html 模板地址，默认 `http://frontends:8081/index.html`（compose 内部网络，一般无需配置）                                                                 |
| `SEO_SHELL_CACHE_TTL` / `SEO_CACHE_TTL`                            | 否   | shell 模板 / 页面渲染结果缓存秒数，默认 600 / 300；前端重新构建后最长延迟对应时长才生效                                                                             |

> 约束：密码与地址值不要包含双引号 `"` 和反斜杠 `\`，避免破坏 `.env` 解析。`.env` 已被 `.dockerignore` 排除，不会进镜像、不会入库。
>
> 远程数据库 / Redis 模式：`DB_HOST` 或 `REDIS_HOST` 填写远程地址后，对应本地容器不会被创建，`docker compose pull` 也不拉取其镜像。要求服务器 Compose v2.20+（依赖跳过用 `required: false`）；远程库需自行建库、对服务器 IP 授权；数据库结构与初始化仍由 app 容器 entrypoint 在启动时执行。

## 4. CI 自动打包推送

`.github/workflows/docker-image.yml` 在以下时机触发构建：

| 触发                                | 说明                                            |
| ----------------------------------- | ----------------------------------------------- |
| push 到 `main` / `master`           | 常规发版，推 `latest` + `main` + `sha-<短哈希>` |
| push 任意 tag（如 `v1.2.0`）        | 推 `1.2.0`、`1.2`、`latest` + `sha-<短哈希>`    |
| 手动触发（Actions 页 Run workflow） | 需要时手动出一版                                |

构建产物（GHCR，包名全小写）：

```
ghcr.io/<owner>/turaidc-app
ghcr.io/<owner>/turaidc-frontends
```

### 4.1 首次使用需要配置

1. 仓库 **Settings → Secrets and variables → Actions → New repository secret**，新增：

   | Secret                         | 示例                          | 说明                                           |
   | ------------------------------ | ----------------------------- | ---------------------------------------------- |
   | `APP_URL`                      | `https://api.example.com`     | 必填，前端构建注入 API 地址                    |
   | `FRONTEND_URL`                 | `https://www.example.com`     | 必填                                           |
   | `CLIENT_CONSOLE_URL`           | `https://console.example.com` | 必填                                           |
   | `ADMIN_URL`                    | `https://admin.example.com`   | 必填                                           |
   | `CLIENT_SESSION_COOKIE_DOMAIN` | `.example.com`                | 选填，跨子域共享登录态时配置；不配置则构建传空 |

   四个公开地址未配置时前端构建会直接失败（`build_frontends.mjs` 校验），这是有意的，提醒你补全。

2. 首次推送后，打开 GitHub → 头像 → Your packages（或仓库右侧 Packages），把 2 个 `turaidc-*` 包设为 **public**，服务器才能匿名拉取；私有包需要在服务器 `docker login ghcr.io`。

### 4.2 切换镜像仓库（如腾讯云 TCR / 阿里云 ACR）

国内服务器拉 GHCR 较慢时可切到国内镜像仓库：

1. 在对应云厂商开通个人版容器镜像服务，创建 `turaidc-app` / `turaidc-frontends` 两个仓库。
2. 在仓库增加 `REGISTRY` 与 `IMAGE_NAMESPACE` 的 Secrets/变量（如 `REGISTRY=ccr.ccs.tencentyun.com`），并在 workflow 的 `env.REGISTRY` 处改用对应值。
3. 在 workflow 中把 GHCR 登录步骤换成目标仓库的用户名/密码（或云厂商的长期凭证 Secret），并给对应 `login-action` 配置。
4. 服务器 `.env` 中同步修改 `REGISTRY` / `IMAGE_NAMESPACE`。

## 5. Docker Compose 部署

### 5.1 上传代码

```bash
git clone <你的仓库地址> /opt/turaidc && cd /opt/turaidc
```

### 5.2 配置并启动

```bash
cd deploy/docker
cp .env.example .env
vim .env            # 填写四个地址、密码等

# 拉取 CI 推送的镜像并启动（推荐）
docker compose pull && docker compose up -d
# 或本地直接构建（无镜像仓库时）
docker compose up -d --build
```

首次启动（空库）自动执行 `install_db.py`：导入 schema baseline、执行增量迁移、写入默认配置、创建管理员 `cerbo`。

### 5.3 验证

```bash
docker compose ps                      # 全部 running
curl http://127.0.0.1:8080/api/health  # {"status":"ok"} 类响应
curl http://127.0.0.1:8080/api/ready   # DB/Cache/Storage/Scheduler 就绪检查
```

浏览器访问 `http://服务器IP:8081`（官网）、`:8082`（控制台）、`:8083`（管理端），用 `cerbo` + `INSTALL_ADMIN_PASSWORD` 登录管理端。

## 6. 1Panel 部署

1Panel（https://1panel.cn）自带 Docker 与 Compose 编排，推荐流程：

### 6.1 上传代码

面板 → 文件，将仓库上传/解压到 `/opt/turaidc`，或 SSH 里 `git clone`。

### 6.2 准备 .env

SSH 执行：

```bash
cd /opt/turaidc/deploy/docker
cp .env.example .env
vim .env
```

### 6.3 创建编排

面板 → 容器 → 编排 → 创建编排：

| 配置项 | 值                                              |
| ------ | ----------------------------------------------- |
| 名称   | `turaidc`                                       |
| 来源   | 本机路径                                        |
| 路径   | `/opt/turaidc/deploy/docker/docker-compose.yml` |

创建后点击"构建并启动"（1Panel 会自动执行 `pull`/`build`）。查看"容器"页确认 `turaidc-mysql`、`turaidc-app`、`turaidc-frontends` 等 4 个容器均运行；点 `turaidc-app` 的日志确认 entrypoint 完成数据库初始化。

> 编排内变量来自同目录 `.env`，与命令行 `docker compose` 行为一致。

### 6.4 反向代理与 HTTPS

面板 → 网站 → 创建网站 → 反向代理，为四个域名各建一条：

| 域名               | 目标地址                |
| ------------------ | ----------------------- |
| `api.你的域名`     | `http://127.0.0.1:8080` |
| `www.你的域名`     | `http://127.0.0.1:8081` |
| `console.你的域名` | `http://127.0.0.1:8082` |
| `admin.你的域名`   | `http://127.0.0.1:8083` |

面板 → 证书 → 申请证书（Let's Encrypt，四个域名可合并一张泛域名证书），绑定到对应网站并开启强制 HTTPS。1Panel 反代默认支持 WebSocket，`/ws/vnc` 可直接穿透；若控制台 VNC 连不上，检查该反代是否保留了 `Upgrade`/`Connection` 头。

### 6.5 安全收口

反代生效后，把 `.env` 中四个端口改成仅本机监听（避免公网直连 8080 等端口），然后重建容器：

```bash
cd /opt/turaidc/deploy/docker
# 例如 API_PORT=127.0.0.1:8080 其余同理
docker compose up -d
```

面板 → 防火墙只放行 `80`/`443`（与面板端口）。

## 7. 纯 Docker 环境的 HTTPS

不使用 1Panel 时，可用宿主机 Nginx/Caddy 或 Cloudflare 反代到四个端口。宿主机 Nginx 示例（每个域名一个 server）：

```nginx
# WebSocket 升级映射放在 http 块（例如 /etc/nginx/conf.d/ 下的公共片段）
map $http_upgrade $connection_upgrade {
    default upgrade;
    ''      close;
}

# 80 端口统一跳转，避免用户直接访问 http 得到 404
server {
    listen 80;
    server_name api.example.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl;
    http2 on;                       # nginx < 1.25.1 写作 listen 443 ssl http2;
    server_name api.example.com;
    ssl_certificate     /etc/nginx/certs/api/fullchain.pem;
    ssl_certificate_key /etc/nginx/certs/api/privkey.pem;

    client_max_body_size 100m;

    # 关键：转发头必须放在 server 级。nginx 的 proxy_set_header 不跨 location 继承，
    # 若只写在 location / 内，下面的 /ws/vnc 因为自己声明了 proxy_set_header，
    # 会完全拿不到 Host / X-Forwarded-* —— Host 退化为 $proxy_host（127.0.0.1:8080），
    # 导致 VNC token 的 Origin 校验与 wss 地址推导失败。
    proxy_set_header Host              $host;
    proxy_set_header X-Real-IP         $remote_addr;
    proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;

    location / {
        proxy_pass http://127.0.0.1:8080;
    }

    # VNC WebSocket 需升级头
    # 注意：location 里一旦出现 proxy_set_header，就不再继承 server 级的同类指令，
    # 因此 Host / X-Real-IP / X-Forwarded-For / X-Forwarded-Proto 必须在这里重复声明，
    # 否则 VNC 通道会丢失真实客户端 IP 与 HTTPS 标识（后端据此判定 isSecure()）。
    location /ws/vnc {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Host              $host;
        proxy_set_header X-Real-IP         $remote_addr;
        proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header Upgrade    $http_upgrade;
        proxy_set_header Connection $connection_upgrade;
        proxy_buffering off;
        proxy_read_timeout 3600s;
        proxy_send_timeout 3600s;
    }
}
```

前端站点去掉 WebSocket 段即可。要求：四个地址协议统一（全 HTTPS）、`.env` 中 `SESSION_SECURE_COOKIE=true`。

`X-Forwarded-Proto: https` 是后端识别 HTTPS 的唯一依据：容器内 `deploy/docker/backend/nginx.conf` 用 `map` 把它转成 `fastcgi_param HTTPS on`，Laravel 的 `isSecure()` 才会返回 true。反代若不传该头，HTTPS 环境下后端仍按 HTTP 处理。

> **来源 IP 契约**：后端 `trustProxies` 只信任回环与 RFC1918/ULA 私网段并采信 `X-Forwarded-For`，`request()->ip()` 与 `/upload_image` 白名单/限流都依赖它。上面的 `$proxy_add_x_forwarded_for` 会保留客户端自带 XFF，公网客户端可借此伪造来源。单层受信反代应把该头重置为 `$remote_addr`（多层受信代理且逐级清洗时才可追加），并把 API 端口改为仅本机绑定（`127.0.0.1:8080`），不要向公网暴露容器端口。完整契约见 [部署与调度指南](deployment-and-scheduling.md) 的“受信代理与来源 IP 契约”。

压缩已由容器内部负责：`frontends` 容器对静态资源配置了 gzip 与 `gzip_static`（优先返回构建期生成的 `.gz`），`backend/nginx.conf` 也已为 API 的 JSON 响应开启 gzip。宿主机 Nginx **不需要**为这两类响应再配一遍——代理响应带 `Content-Encoding` 时 Nginx 不会二次压缩。

若宿主机上还有其他不经这两个容器的响应需要压缩（例如宿主机直接提供的静态文件或第三方上游），可在 http 块按需加入。注意 Nginx 默认 `gzip off`，不存在“自动生效的全局压缩”：

```nginx
gzip            on;
gzip_vary       on;
gzip_min_length 1024;
gzip_proxied    any;               # 必须，否则代理响应不会被压缩
gzip_types      text/plain text/css application/javascript application/json
                application/xml image/svg+xml;
```

## 8. 升级与回滚

### 8.1 升级（拉取 CI 新镜像）

```bash
cd /opt/turaidc
git pull                      # 更新 compose 与 .env 模板（若有变化）
cd deploy/docker
docker compose pull           # 拉取 CI 推送的最新镜像
docker compose up -d          # 增量迁移由 app 容器启动时自动执行（migrate --force）
docker compose ps
```

> 升级前先备份数据库（见下节）。迁移失败时查看 `docker compose logs app`。

### 8.2 回滚

指定旧镜像 tag 重新拉起：

```bash
cd deploy/docker
sed -i 's/^IMAGE_TAG=.*/IMAGE_TAG=<上一版本或 sha-xxx>/' .env
docker compose pull && docker compose up -d
```

数据库迁移原则上不可逆；需回滚数据库时用备份还原，禁止对生产库执行 `migrate:rollback` 不可逆迁移。

## 9. 备份与恢复

### 9.1 定时备份（宿主机 crontab）

```bash
0 3 * * * cd /opt/turaidc/deploy/docker && ./backup.sh >> /var/log/turaidc-backup.log 2>&1
```

备份文件位于 `deploy/docker/backups/turaidc-<时间戳>.sql.gz`，默认保留 14 天（`./backup.sh 30` 可改）。

### 9.2 手动备份

```bash
cd /opt/turaidc/deploy/docker && ./backup.sh
```

### 9.3 恢复

```bash
gunzip -c backups/turaidc-xxx.sql.gz | docker compose exec -T mysql \
  sh -c 'exec mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"'
```

## 10. 运维命令速查

```bash
docker compose ps                        # 状态
docker compose logs -f app               # 后端日志（php-fpm/nginx/cron/relay 均走 stdout）
docker compose logs -f frontends          # 前端日志
docker compose exec app bash             # 进入后端容器
docker compose exec app php artisan schedule:run   # 手动触发一次调度
docker compose exec app php artisan queue:retry all # 重试失败队列
docker compose exec mysql sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"'
docker compose restart app               # 重启后端
```

后端日志文件 `storage/logs/laravel.log` 在命名卷 `app-storage` 中；健康检查：`/api/health`（存活）、`/api/ready`（就绪）。

## 11. 常见问题

### 11.1 首次启动后管理端登录不上

确认空库首次启动完成了 `install_db.py`（`docker compose logs app` 中有 "执行 install_db.py" 且无报错）；管理员为 `cerbo` + `.env` 的 `INSTALL_ADMIN_PASSWORD`（仅首次生效，之后请立即在管理端改密）。

### 11.2 502 / 504

- 查看 `docker compose logs app`：php-fpm 未随 supervisor 拉起、或 MySQL 未就绪导致 entrypoint 卡住时表现为 502
- 确认 `mysql` 容器 healthcheck 通过（`docker compose ps` 中 healthy）
- `APP_KEY` 缺失会导致 Laravel 500：确认 `docker compose exec app sh -c 'grep ^APP_KEY= .env'` 有值

### 11.3 拉取镜像 401 / 拉不下来

- CI 首次推送后 GHCR 包默认 private：去 Packages 页把 2 个 `turaidc-*` 包设为 public
- 或服务器执行 `docker login ghcr.io` 用 PAT 登录
- 国内拉取慢可切腾讯云/阿里云镜像仓库（见 4.2）

### 11.4 CI 构建失败

- 检查仓库 Actions secrets 是否配齐四个公开地址（失败信息里会提示"必须是 HTTP(S) 根地址"或缺失）
- 检查 `ghcr.io` 登录：首次构建需 GITHUB_TOKEN 具备 `packages: write`（workflow 已声明 permissions）

### 11.5 页面刷新 404

前端 nginx 已内置 `try_files $uri $uri/ /index.html;`；若仍 404，确认访问的是前端容器（8081/8082/8083）而非 API 端口。

### 11.6 时区 / 时间不对

app 容器 `TZ=Asia/Shanghai` 已在编排中设置；MySQL 通过 `--default-time-zone=+08:00` 设置；`.env` 中 `DB_TIMEZONE=+08:00`。

### 11.7 上传文件过大

默认 `client_max_body_size 100m`、`upload_max_filesize=100M`、`post_max_size=120M`；需要更大时同时改 `deploy/docker/backend/nginx.conf` 与 `backend/php.ini` 后重建镜像。

### 11.8 改了 .env 不生效

容器启动时由 entrypoint 重新生成 `backend/.env` 并 `config:cache`，因此修改 `deploy/docker/.env` 后必须重启容器：

```bash
docker compose up -d --force-recreate app
```

四个公开地址变更需要重新构建/拉取前端镜像（前端是构建期注入）。

### 11.9 VNC 控制台连不上

- 检查 `/ws/vnc` 是否被反代丢弃了 Upgrade 头（见第 6 节）
- 容器内 `docker compose exec app supervisorctl status` 确认 `vnc-relay` 为 RUNNING
- 直连测试：`docker compose exec app sh -c 'curl -s http://127.0.0.1:8100 || true'`（Relay 对非 WebSocket 请求返回 400 属正常）

### 11.10 Docker Desktop Windows 下 fpm 报 "Operation not permitted"

Docker Desktop WSL2 后端的 overlayfs 存在已知 bug：php-fpm fork 的 worker 读镜像层文件时 `open()` 返回 EPERM。compose 已通过 `tmpfs: /var/www/backend` 规避——entrypoint 启动时把代码从镜像层 `/opt/backend-src` 拷贝到 tmpfs（内存文件系统），fpm 只读 tmpfs 文件。Linux 生产环境无此 bug，tmpfs 拷贝 <2s 可忽略。

### 11.11 迁移/初始化失败后重试

数据未写入时（仍为空库）可重建数据库卷后重来：

```bash
docker compose down -v   # 注意：-v 会删除全部命名卷（含数据库数据），仅限全新环境
docker compose up -d
```

## 12. 与宝塔部署的差异备忘

- 相同口径：队列不常驻、`schedule:run` 每分钟驱动、`CACHE_STORE=redis`、`QUEUE_CONNECTION=database`、`SESSION_DRIVER=file`、VNC Relay 常驻 8100
- 容器内不做 `route:cache`（路由含闭包，会失败）；只做 `config:cache`
- 代码、PHP 扩展、Nginx 配置均随镜像发布；`storage`、`public/uploads`、`public/media` 走命名卷持久化
- 生产 HTTPS 一律在容器外（1Panel/宿主机 Nginx/Cloudflare）终止

## 关联文档

- [部署指南](deployment.md)：通用部署步骤。
- [四端 Nginx 伪静态配置](frontend-nginx-rules.md)：站点伪静态规则。
- [部署与调度指南](deployment-and-scheduling.md)：调度与队列配置。
