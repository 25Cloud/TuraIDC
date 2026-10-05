# TuraIDC 手动部署说明（192.168.5.100）

主站 `cloud.shanshui.site` 的源站是 **192.168.5.100**，纯净安装、**没有 Docker**，
后端 `php-fpm`/`artisan` + nginx 直接指 `/opt/turaidc/*/dist`。所以每次上线都是
**本地构建 → 推送产物 → 服务器原子替换**，不走 CI/CD。

GitHub Actions（`docker-image.yml`）只推 GHCR 镜像，**与本机无关，不要依赖它上线**。

---

## 1. 连接方式

```bash
# Git Bash（Windows 自带 plink，支持 -pw 非交互登录）
PLINK="/c/Program Files/PuTTY/plink"
PW='<SSH 密码>'

"$PLINK" -ssh -pw "$PW" shanshui@192.168.5.100 '命令'
```

- 登录用户：`shanshui`；`root` **不能直接 SSH 登录**，需要提权时走 `sudo`：
  ```bash
  "$PLINK" -ssh -pw "$PW" shanshui@192.168.5.100 'echo "<SSH 密码>" | sudo -S -p "" 命令'
  ```
- 本机没有 `sshpass`，别再尝试；`ssh`/`scp` 可用但密码交互没法喂。
- **不要碰 103.79.186.32 / panel.shanshui.site**，那两台不是主站。

## 2. 服务器布局

| 路径 | 说明 |
| --- | --- |
| `/opt/turaidc/` | git 仓库（`main` 分支，**存在大量未提交改动，别 reset/checkout**） |
| `/opt/turaidc/frontend-user-v3-www/dist` | 官网（`base=/`），nginx `root` 指向这里 |
| `/opt/turaidc/frontend-user-v4-console/dist` | 用户控制台（`base=/console/`） |
| `/opt/turaidc/frontend-admin-v3/dist` | 管理后台（`base=/admin/`） |
| `/opt/turaidc/backend/public` | Laravel，含 `media/`、`uploads/` 直出目录 |
| `/etc/nginx/sites-enabled/turaidc.conf` | 主站 vhost（API 反代 `127.0.0.1:8080`） |

`dist/` 在 `.gitignore` 里，属主 `www-data`。服务器工具链：node v20.19.5 / pnpm 10.34.6。
本机节点版本不同，**一律本地构建**，不要在服务器上 build。

## 3. 部署脚本

```bash
bash deploy/deploy-www.sh            # 部署官网 www
bash deploy/deploy-www.sh --dry-run  # 只看会推什么，不落盘
```

脚本做的事：

1. 本地 `vite build --mode production`（输出到临时目录 `dist-deploy`）
2. `tar` 打包后 `plink` 推到服务器 `/tmp/www-dist-<时间戳>.tar.gz`
3. 服务器解到 `dist.new`，`chown -R www-data:www-data`
4. 原子替换：旧目录改名 `.bak-<时间戳>` → `mv dist.new dist`
5. 保留最近 3 份 `.bak-*`，`nginx -t` 校验，**不 reload**（纯静态文件无需 reload）
6. 失败自动回滚到 `.bak-*`

回滚：

```bash
bash deploy/deploy-www.sh --rollback   # 回到最近一份 .bak-*
```

## 4. 验证

```bash
# 首页 HTML 引用的是新 hash 的资源
curl -s https://cloud.shanshui.site/ | grep -o 'assets/js/index-[^"]*\.js'
```

构建后 `components.d.ts` 会被自动组件扫描改动，属副作用，**提交前 `git checkout --` 回滚**，
不要混进源码提交。

## 5. 踩过的坑

- `.bin/vite` 在 node 下会报 `SyntaxError: missing )`；直接跑
  `node node_modules/vite/bin/vite.js build` 才正常。
- `vite build` 默认 `emptyOutDir` 会删 `dist/`，触发本地安全删除拦截（>50 文件）；
  构建到全新目录，或加 `--emptyOutDir false`。
- Playwright 在 `frontend-admin-v3/node_modules/playwright`，需用
  `createRequire` + 绝对路径 `require` 引入，裸 `import` 会 `ERR_MODULE_NOT_FOUND`。
- 本地 preview 没有后端 API，`/v2/site/home` 拿不到数据 → Hero 轮播不挂载只渲染骨架屏。
  浏览器里验证 Hero 必须 `page.route('**/api/v2/**')` mock；
  **Playwright 后注册的路由优先级更高**，兜底路由要先注册，否则会把具体端点吃掉。
