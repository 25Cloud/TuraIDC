# TuraIDC Docker 一键部署

`docker compose` 一键拉起：MySQL 8 + Redis 7 + 后端（PHP-FPM/Nginx/Cron/VNC Relay）+ 前端三端合一（Nginx 三端口）。

## 快速开始

```bash
# 1. 进入本目录，准备环境变量（唯一需要配置的文件）
cd deploy/docker
cp .env.example .env
# 编辑 .env：填四个公开地址、APP_KEY、数据库密码、管理员初始密码、镜像仓库等
# 没有宿主机 PHP 时，先 docker compose pull app，再用 app 镜像执行：
# docker compose run --rm --no-deps --entrypoint php app -r 'echo "base64:", base64_encode(random_bytes(32)), PHP_EOL;'
# 生产拉取模式必须启用仓库标签不可变策略，或每次部署核对实际镜像 digest；不要使用 latest。

# 2a. 拉取模式（推荐，CI 已推送镜像到 GHCR）
docker compose pull && docker compose up -d

# 2b. 本地构建模式（无镜像仓库时）
docker compose up -d --build

# 3. 查看状态与日志
docker compose ps
docker compose logs -f app

# 4. 验证
curl http://127.0.0.1:8080/api/health   # API 存活
curl http://127.0.0.1:8080/api/ready    # 就绪检查（DB/Cache/Storage/Scheduler）
```

启动后访问：

| 服务       | 默认地址（端口可在 .env 修改） |
| ---------- | ------------------------------ |
| API        | http://127.0.0.1:8080          |
| 官网       | http://127.0.0.1:8081          |
| 用户控制台 | http://127.0.0.1:8082          |
| 管理端     | http://127.0.0.1:8083          |

生产部署必须在 `.env` 中设置持久化的 `APP_KEY`；容器重建时不会随机生成新密钥，缺失会直接退出。非生产环境的空库首次安装缺少 `APP_KEY` 时，入口脚本只生成一次并写入 `app-storage` 持久化卷。首次启动（空库）会自动执行 `install_db.py` 初始化，创建默认管理员 `cerbo`（密码为 `.env` 中 `INSTALL_ADMIN_PASSWORD`，仅首次生效）。

## 目录结构

```
deploy/docker/
├── .env.example        # 环境变量模板（复制为 .env 后编辑）
├── docker-compose.yml  # 服务编排（镜像地址由 .env 的 REGISTRY/IMAGE_NAMESPACE/IMAGE_TAG 控制）
├── backup.sh           # 数据库备份脚本
├── backend/            # 后端镜像：Dockerfile / entrypoint.sh / nginx.conf / php.ini / supervisord.conf
└── frontends/          # 前端镜像：Dockerfile / nginx-default.conf
```

## CI 自动打包推送

`.github/workflows/docker-image.yml` 在推送到 `main`、打 `v*` tag 或手动触发时，
用 buildx 构建并推送 2 个镜像到镜像仓库（默认 `ghcr.io/<owner>/turaidc-*`）。

使用前需在仓库 **Settings → Secrets and variables → Actions** 配置：

| Secret                         | 说明                                               |
| ------------------------------ | -------------------------------------------------- |
| `APP_URL`                      | API 公开地址，如 `https://api.example.com`         |
| `FRONTEND_URL`                 | 官网地址                                           |
| `CLIENT_CONSOLE_URL`           | 控制台地址                                         |
| `ADMIN_URL`                    | 管理端地址                                         |
| `CLIENT_SESSION_COOKIE_DOMAIN` | 跨子域共享登录态父域，不需要可留空（未配置则不传） |

首次推送后需在 GHCR 页面把 2 个 `turaidc-*` 包设为 **public**（或服务器 `docker login ghcr.io`）。

## 生产注意

- 生产 HTTPS 由 1Panel / 宿主机 Nginx / Cloudflare 反代终止，容器内部保持 HTTP；`.env` 中四个地址仍填 `https://` 域名。
- 官网 SEO：公开页面（首页、产品、落地页、公告/帮助等）由后端 Laravel 动态渲染完整 HTML，`frontends` 容器内 Nginx 已把公开路径转发到 `app` 容器的 `/seo/www/{path}`，无需额外配置。站点名/Logo/meta 实时读数据库；sitemap.xml 与 robots.txt 由后端动态生成（`/sitemap.xml`、`/robots.txt`）。
- 如需限制端口仅本机访问（配合反代），将 `.env` 中端口改为 `127.0.0.1:8080` 形式。
- 升级服务器：`git pull`（更新 .env 与 compose 文件）→ `docker compose pull && docker compose up -d`，后端增量迁移由容器启动时自动执行。
- 详细部署与运维指南见 `docs/guide/deploy/docker-1panel.md`。

## 从智简魔方财务系统迁移

正在使用智简魔方财务（ZJMF，`shd_` 前缀）？TuraIDC 支持将老站产品、用户、订单、上游供应商与实名数据完整迁移过来：

```bash
# 进入后端工作目录后执行（详见官方教程）
python3 backend/scripts/mofang_to_turaidc_migrator.py --dry-run   # 预检
python3 backend/scripts/mofang_to_turaidc_migrator.py --truncate  # 干净库迁移
php artisan app:reorganize-product-groups                          # 重建三级分组
php artisan app:sync-upstreams --api-keys-file /tmp/keys.json      # 上游同步+解密
php artisan services:backfill-upstream-bindings                    # 服务控制台恢复
php artisan optimize:clear && php artisan app:warmup-site-cache    # 预热缓存
```

> 📖 完整分步教程（含生产踩坑记录）：[从智简魔方财务系统迁移](../../docs/guide/migrate/zjmf-finance.md)
