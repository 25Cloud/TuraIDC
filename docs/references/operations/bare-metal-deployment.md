# 裸机源码部署指南

面向不使用宝塔面板、不使用 Docker 的服务器：以系统原生服务（nginx + PHP-FPM + systemd + cron）从源码部署 TuraIDC 的完整流程。

- 对齐时间：`2026-08-28`（在 Debian 13 (trixie) 上实际部署并验证通过）
- 适用场景：内网/自管服务器、无法或不想安装面板与容器运行时的环境
- 配套文档：
  - [部署与调度指南](deployment-and-scheduling.md)（现网运维口径）
  - [宝塔部署项目指南](bt-panel-deployment.md)（面板版，拓扑一致）
  - [通用部署指南](deployment.md)（从零部署的通用参考）
  - [Docker 与 1Panel 部署指南](docker-and-1panel-deployment.md)（容器化）

---

## 一、拓扑与宝塔版的对应关系

运行拓扑与[宝塔部署](bt-panel-deployment.md)完全一致，只是管理载体不同：

```
系统 nginx（sites-available 四站点）
  ├── 官网/用户入口 -> /opt/turaidc/frontend-user-v3-www/dist 静态资源
  ├── 用户控制台   -> /opt/turaidc/frontend-user-v4-console/dist 静态资源
  ├── 管理端       -> /opt/turaidc/frontend-admin-v3/dist 静态资源
  └── 后端 API     -> /opt/turaidc/backend/public (PHP-FPM)
                        ├── MySQL 8（本机）
                        ├── Redis（本机，仅 127.0.0.1）
                        ├── /etc/cron.d 每分钟 schedule:run
                        └── systemd 常驻 php artisan vnc:relay
```

| 宝塔版载体             | 裸机版载体                                                 |
| ---------------------- | ---------------------------------------------------------- |
| 宝塔站点（Nginx 配置） | `/etc/nginx/sites-available/*.conf` + `sites-enabled` 软链 |
| 宝塔计划任务           | `/etc/cron.d/turaidc`                                      |
| 宝塔进程守护           | systemd 服务单元 `turaidc-vnc-relay.service`               |
| 宝塔 PHP 设置          | `/etc/php/8.4/fpm/`（发行版默认即可用）                    |

## 二、前置环境

实测基线：Debian 13 (trixie)、2 核 / 2G 内存 / 20G 磁盘。Ubuntu 22.04+、Debian 12 同理，包名基本一致。

| 软件                  | 来源                     | 说明                                                                 |
| --------------------- | ------------------------ | -------------------------------------------------------------------- |
| nginx                 | 发行版仓库               | 1.26+                                                                |
| PHP 8.4-FPM + CLI     | 发行版仓库               | Debian 13 自带 8.4，满足后端 `composer.json` 的 `^8.3`；无需第三方源 |
| MySQL 8.4 LTS         | MySQL 官方 APT 源        | 官方源已支持 trixie；trixie 无 MySQL 8.0 系列官方包，选 8.4 LTS      |
| Redis                 | 发行版仓库               | 8.x，仅监听 127.0.0.1，默认无密码即可                                |
| Composer              | 发行版仓库 `composer` 包 | 2.8+                                                                 |
| python3               | 系统自带                 | `install_db.py` 依赖                                                 |
| Node.js 20.19+ / pnpm | **仅构建机需要**         | 见第六节：小内存服务器不要原地构建前端                               |

内存口径：2G 可运行（PHP-FPM 用 Debian 默认小进程池即可）；若要在服务器上原地构建前端，必须另加 swap，且 LXC/VE 类容器通常 `swapon` 被内核禁止（`Operation not permitted`），此时应改为本机构建后上传 dist。

### 域名解析

按服务器形态二选一，四个公开地址（`api` / `www` / `console` / `admin`）都要能解析到部署服务器：

**内网 / 无公网部署（本指南默认做法）**：在访问端机器的 hosts 中添加四条记录（如 `192.168.1.170 api.tura.cloud` 等）；更换服务器 IP 或域名下线时，同理同步增删。

**有独立公网 IP 的云服务器**：到域名所在 DNS 服务商控制台配置解析，四条 A 记录指向服务器公网 IP：

| 记录类型 | 主机记录 | 记录值            |
| -------- | -------- | ----------------- |
| A        | api      | `<服务器公网 IP>` |
| A        | www      | `<服务器公网 IP>` |
| A        | console  | `<服务器公网 IP>` |
| A        | admin    | `<服务器公网 IP>` |

- 更换服务器 IP 或弃用旧域名时同理增删解析记录，避免残留指向失效地址。
- 云服务器生产环境建议统一 HTTPS：解析生效后为四个子域申请证书（如 Let's Encrypt），四个公开地址改为 `https://` 并设 `SESSION_SECURE_COOKIE=true`。
- 配置完成后等待 TTL 生效，用 `dig` / `ping` 确认四个子域均已指向服务器。

## 三、安装系统依赖

```bash
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y wget gnupg ca-certificates curl lsb-release
apt-get install -y nginx redis-server composer \
  php8.4-fpm php8.4-cli php8.4-mysql php8.4-gd php8.4-zip php8.4-intl \
  php8.4-bcmath php8.4-mbstring php8.4-curl php8.4-xml php8.4-redis

# MySQL 官方 APT 源（非交互模式使用默认选择：mysql-8.4-lts）
wget -q https://dev.mysql.com/get/mysql-apt-config_0.8.36-1_all.deb -O /tmp/mysql-apt-config.deb
apt-get install -y /tmp/mysql-apt-config.deb
apt-get update -y
apt-get install -y mysql-server
```

说明：

- `mysql-apt-config` 的 debconf 脚本可能打印 `lsb_release: command not found` 警告，只要 `/etc/apt/sources.list.d/mysql.list` 生成即无影响。
- 四个服务（nginx、php8.4-fpm、redis-server、mysql）安装后默认自启，`systemctl is-active ...` 逐个确认。
- PHP 扩展确认：`php -m | grep -iE "pdo_mysql|redis|gd|zip|intl|bcmath|mbstring|opcache|fileinfo"`。Debian 的 PHP 没有宝塔式禁用函数问题，`putenv`/`proc_open`/`symlink` 默认可用。

### 时区对齐

项目口径为 PHP `Asia/Shanghai`、数据库连接 `+08:00`（`config/app.php`、`config/database.php`），裸机需自行对齐：

```bash
timedatectl set-timezone Asia/Shanghai
echo -e "[mysqld]\ndefault-time-zone=+08:00" > /etc/mysql/mysql.conf.d/99-turaidc-timezone.cnf
systemctl restart mysql
```

## 四、上传代码

构建机（有 Node/pnpm 环境）打包，服务器只接收源码与构建产物：

```bash
# 构建机：从干净提交打包（自动排除 .env、node_modules、dist 等未跟踪内容）
git archive --format=tar -o code.tar HEAD
# 前端 dist 构建完成后追加进同一个包（见第六节）
tar -rf code.tar frontend-admin-v3/dist frontend-user-v3-www/dist frontend-user-v4-console/dist

# 上传。scp 在部分环境下会静默失败，用 ssh 管道传输并以 md5 校验更可靠：
cat code.tar | ssh root@<服务器> 'cat > /tmp/turaidc-code.tar && md5sum /tmp/turaidc-code.tar'

# 服务器：解压到统一部署目录
mkdir -p /opt/turaidc
tar -xf /tmp/turaidc-code.tar -C /opt/turaidc
```

## 五、后端部署

### 5.1 建库授权

Debian 的 MySQL root 默认走 auth_socket，root 登录下直接执行：

```sql
CREATE DATABASE IF NOT EXISTS idc CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'idc'@'127.0.0.1' IDENTIFIED BY '<强密码>';
GRANT ALL PRIVILEGES ON idc.* TO 'idc'@'127.0.0.1';
FLUSH PRIVILEGES;
```

> 若把 SQL 写进 shell heredoc，注意未加引号的定界符会做命令替换：`CREATE DATABASE IF NOT EXISTS \`idc\``的反引号会被 bash 当作命令执行（实测报`idc: command not found`）。`idc` 不是保留字，直接去掉反引号即可。

### 5.2 生成 backend/.env

以 `backend/.env.example` 为底，**sed 原地替换**，不要靠文件尾追加覆盖同名键——Laravel Dotenv 是不可变模式，同名键第一次出现生效，追加无效：

```bash
cd /opt/turaidc/backend
cp .env.example .env
sed -i \
  -e "s|^APP_ENV=.*|APP_ENV=production|" \
  -e "s|^APP_DEBUG=.*|APP_DEBUG=false|" \
  -e "s|^APP_URL=.*|APP_URL=http://api.tura.cloud|" \
  -e "s|^FRONTEND_URL=.*|FRONTEND_URL=http://www.tura.cloud|" \
  -e "s|^CLIENT_CONSOLE_URL=.*|CLIENT_CONSOLE_URL=http://console.tura.cloud|" \
  -e "s|^ADMIN_URL=.*|ADMIN_URL=http://admin.tura.cloud|" \
  -e "s|^CLIENT_SESSION_COOKIE_DOMAIN=.*|CLIENT_SESSION_COOKIE_DOMAIN=.tura.cloud|" \
  -e "s|^DB_PASSWORD=.*|DB_PASSWORD=<强密码>|" \
  .env
cat >> .env <<'EOF'

# example 中不存在的键才允许追加
INSTALL_ADMIN_PASSWORD=<至少12位强密码>
SEO_SITE_URL=http://www.tura.cloud
SEO_FRONTEND_SHELL_URL=file:///opt/turaidc/frontend-user-v3-www/dist/index.html
EOF
chown www-data:www-data .env && chmod 600 .env
```

要点：

- 四个公开地址同协议；内网 hosts 方案用 HTTP，`SESSION_SECURE_COOKIE` 保持 example 默认 `false`。
- `TURAIDC_BUSINESS_QUEUES` 保持 example 默认即可（`provision` 会被运行时自动并入正确分组）。
- `APP_KEY` 留空，`install_db.py` 检测为空时自动生成并写回。
- `INSTALL_ADMIN_PASSWORD` 是首次初始化管理员 `cerbo` 的临时密码，仅空库首次生效；不要写入文档、Git 或计划任务。

### 5.3 安装依赖并初始化数据库

```bash
cd /opt/turaidc/backend
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist

# 关键：先预建 Laravel 运行目录，再初始化（否则 optimize:clear 报 View path not found）
mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views \
         storage/logs storage/app/public bootstrap/cache public/uploads public/media

python3 scripts/install_db.py
```

`install_db.py` 依次完成：校验数据库连接、生成 `APP_KEY`、导入 `database/schema/mysql-schema.sql`、执行增量迁移、写入默认配置与通知模板、创建管理员 `cerbo`。空库可重复执行；不要对承载业务数据的库使用 `--reset`。

### 5.4 权限

```bash
cd /opt/turaidc/backend
chown -R www-data:www-data storage bootstrap/cache public/uploads public/media
chmod -R 775 storage bootstrap/cache public/uploads public/media
```

### 5.5 生产缓存

```bash
cd /opt/turaidc/backend
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
```

实测本仓库 `route:cache` 可以成功执行（[容器文档](docker-and-1panel-deployment.md)中"路由含闭包导致 route:cache 失败"的口径在源码部署下不成立，以此处实测为准）；若未来路由引入闭包导致失败，跳过该步不影响运行。

## 六、前端构建与产物

小内存服务器不要原地构建。在构建机（Node.js 20.19+、pnpm）上执行：

```bash
CI=true \
APP_URL=http://api.tura.cloud \
FRONTEND_URL=http://www.tura.cloud \
CLIENT_CONSOLE_URL=http://console.tura.cloud \
ADMIN_URL=http://admin.tura.cloud \
CLIENT_SESSION_COOKIE_DOMAIN=.tura.cloud \
pnpm run build:frontends
```

- 构建脚本从**进程环境变量优先**读取四个公开地址（其次才是 `backend/.env`），因此无需改动构建机上的 `backend/.env`。
- 四个地址必须互不相同且同协议，构建前脚本会强校验。
- 构建产物在三个前端目录各自的 `dist/`，不会写入 `backend/public`。
- pnpm 11 在 CI/沙箱环境下可能在 `run` 前自动触发依赖重装并失败（`ERR_SQLITE_ERROR: unable to open database file`）；`npm_config_verify_deps_before_run=false` 环境变量在 pnpm 11 不生效，用 CLI 形式或包装器注入：`pnpm --config.verify_deps_before_run=false run build:frontends`（嵌套 spawn 的子 pnpm 需要通过 PATH 包装器统一注入）。

构建完成后把三个 `dist` 追加进第四节的上传包（`tar -rf`），或单独打包上传，解压后落在 `/opt/turaidc/<前端目录>/dist`。

## 七、Nginx 四站点

配置文件放 `/etc/nginx/sites-available/`，软链到 `sites-enabled`，并移除发行版默认站点：

```bash
for h in api www console admin; do
  ln -sf /etc/nginx/sites-available/$h.tura.cloud.conf /etc/nginx/sites-enabled/
done
rm -f /etc/nginx/sites-enabled/default
nginx -t && systemctl reload nginx
```

### 7.1 api.tura.cloud（后端 PHP-FPM）

```nginx
server {
    listen 80;
    server_name api.tura.cloud;
    root /opt/turaidc/backend/public;
    index index.php index.html;
    client_max_body_size 100m;

    # VNC WebSocket 仅由 API 站点转发到内部 Relay
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

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
    }

    location ~ /\.(?!well-known) {
        deny all;
    }
}
```

### 7.2 www.tura.cloud（官网：静态回退 + SEO 动态渲染转发）

公开路径转发到 API 站点由 Laravel 读库渲染完整 HTML（规则依据[前端 Nginx 规则](frontend-nginx-rules.md)与宝塔指南 4.2 节）：

```nginx
server {
    listen 80;
    server_name www.tura.cloud;
    root /opt/turaidc/frontend-user-v3-www/dist;
    index index.html;

    location = / {
        proxy_pass http://127.0.0.1/seo/www;
        proxy_set_header Host              api.tura.cloud;
        proxy_set_header X-Real-IP         $remote_addr;
        proxy_set_header X-Forwarded-For   $remote_addr;
        proxy_set_header X-Forwarded-Proto $scheme;
    }

    location ~ ^/(robots\.txt|sitemap\.xml)$ {
        proxy_pass http://127.0.0.1;
        proxy_set_header Host              api.tura.cloud;
        proxy_set_header X-Real-IP         $remote_addr;
        proxy_set_header X-Forwarded-For   $remote_addr;
        proxy_set_header X-Forwarded-Proto $scheme;
    }

    location ~ ^/(cloud-server|hong-kong-server|us-server|high-defense-server|cloud-pc|about|terms|privacy|products)$ {
        proxy_pass http://127.0.0.1/seo/www$request_uri;
        proxy_set_header Host              api.tura.cloud;
        proxy_set_header X-Real-IP         $remote_addr;
        proxy_set_header X-Forwarded-For   $remote_addr;
        proxy_set_header X-Forwarded-Proto $scheme;
    }

    location ~ ^/(notices|help)(/[0-9]+)?$ {
        proxy_pass http://127.0.0.1/seo/www$request_uri;
        proxy_set_header Host              api.tura.cloud;
        proxy_set_header X-Real-IP         $remote_addr;
        proxy_set_header X-Forwarded-For   $remote_addr;
        proxy_set_header X-Forwarded-Proto $scheme;
    }

    location ~ ^/products/[0-9]+$ {
        proxy_pass http://127.0.0.1/seo/www$request_uri;
        proxy_set_header Host              api.tura.cloud;
        proxy_set_header X-Real-IP         $remote_addr;
        proxy_set_header X-Forwarded-For   $remote_addr;
        proxy_set_header X-Forwarded-Proto $scheme;
    }

    location / {
        try_files $uri $uri/ /index.html;
    }
}
```

纯 HTTP 内部转发不存在强制 HTTPS 301 拦截问题；若日后启用 HTTPS，需遵守宝塔指南 4.2 节的回环放行说明。

### 7.3 console / admin（SPA 静态回退）

```nginx
server {
    listen 80;
    server_name console.tura.cloud;   # admin 站点同理，替换 server_name 与 root
    root /opt/turaidc/frontend-user-v4-console/dist;
    index index.html;

    location / {
        try_files $uri $uri/ /index.html;
    }
}
```

## 八、调度与守护进程

### 8.1 每分钟调度（cron）

`/etc/cron.d/turaidc`（文件名不能含点号，属主 root，权限 644）：

```cron
* * * * * www-data cd /opt/turaidc/backend && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

以 `www-data` 运行，与 PHP-FPM 同用户，保证 storage 写权限。这条计划每分钟并行消费业务队列与 `automation` 队列，**不要再配置覆盖同一队列的常驻 `queue:work`**。

### 8.2 VNC Relay 常驻（systemd）

`/etc/systemd/system/turaidc-vnc-relay.service`：

```ini
[Unit]
Description=TuraIDC VNC Relay (php artisan vnc:relay)
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/opt/turaidc/backend
ExecStart=/usr/bin/php artisan vnc:relay
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
```

```bash
systemctl daemon-reload
systemctl enable --now turaidc-vnc-relay
ss -tlnp | grep 8100   # 确认 127.0.0.1:8100 已监听
```

## 九、部署验证

```bash
curl -s -H "Host: api.tura.cloud" http://127.0.0.1/api/health   # {"status":"alive"}
curl -s -H "Host: api.tura.cloud" http://127.0.0.1/api/ready    # status: ready
```

`/api/ready` 中 `scheduler` 首次为 `false` 是正常的——cron 尚未到达触发点；装好 cron 等待 1-2 分钟后复查应为全绿（database/cache/storage/scheduler/scheduler_liveness/task_readiness/queue 均 true）。

浏览器/构建机侧（配置 hosts 后）：

- [ ] 四端 `http://api|www|console|admin.tura.cloud` 均返回 200
- [ ] 官网首页 HTML title 为站点名（SEO 动态渲染生效），`/products`、`/notices`、`/sitemap.xml`、`/robots.txt` 返回 200
- [ ] 控制台/管理端刷新任意 SPA 路由不 404
- [ ] 管理端可用 `cerbo` + `INSTALL_ADMIN_PASSWORD` 登录，登录后立即改密
- [ ] `APP_DEBUG=false`、`.env` 600 且属主 www-data

## 十、升级

```bash
# 构建机：新提交重新打包（含重新构建的 dist），上传并解压覆盖 /opt/turaidc
# 服务器：
cd /opt/turaidc/backend
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader
php artisan migrate --force
chown -R www-data:www-data storage bootstrap/cache
php artisan optimize:clear && php artisan config:cache && php artisan route:cache
systemctl restart php8.4-fpm
systemctl status turaidc-vnc-relay   # relay 由 systemd 自动守护，一般无需处理
```

升级前先备份数据库；回滚口径见[部署与调度指南](deployment-and-scheduling.md)。

## 十一、实测踩坑记录（2026-08-28，Debian 13 / LXC 容器）

| 现象                                           | 原因与处理                                                                                                                                                                                 |
| ---------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `swapon: /swapfile: Operation not permitted`   | LXC/VE 容器内核禁止 swap。本机构建方案下服务器无需 swap，跳过即可                                                                                                                          |
| heredoc 中建库 SQL 报 `idc: command not found` | 未加引号的 heredoc 会做命令替换，反引号被当作命令执行；去掉标识符反引号                                                                                                                    |
| `.env` 尾部追加键不生效                        | Laravel Dotenv 不可变模式，同名键首个生效；必须 sed 原地替换                                                                                                                               |
| `optimize:clear` 报 `View path not found`      | `storage/framework/views` 不存在；**必须在 `install_db.py` 之前**预建 storage 目录树                                                                                                       |
| scp 显示成功但文件未到达                       | 部分环境 scp 静默失败；改用 `cat file \| ssh 'cat > dest'` 管道并 md5 校验                                                                                                                 |
| pnpm 自动重装依赖报 `ERR_SQLITE_ERROR`         | pnpm 11 的 `verify-deps-before-run` 在 CI/沙箱下自动触发安装且 store 不可写；`npm_config_verify_deps_before_run` 环境变量无效，需 `--config.verify_deps_before_run=false` 并覆盖嵌套 spawn |
| `/api/ready` 的 scheduler 为 false             | cron 刚安装尚未到触发点；等 1-2 分钟自动转绿                                                                                                                                               |
