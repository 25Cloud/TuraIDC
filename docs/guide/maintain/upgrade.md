---
status: current
updated: 2026-10-16
owner: ops
---

# 版本升级

本文面向负责生产服务器的站长与运维，说明把已运行的 TuraIDC 升级到新版本的完整操作：升级前检查、代码与依赖更新、迁移、前端重建、缓存与队列重载、验证与失败回滚。部署方式差异见[部署与调度指南](../deploy/scheduling.md)、[裸机源码部署指南](../deploy/bare-metal.md)与 [Docker / 1Panel 部署指南](../deploy/docker-1panel.md)。

## 1. 升级前检查

按顺序做完，任何一项没过就不要开始。

| 检查项       | 命令 / 位置                                                        | 通过标准                          |
| ------------ | ------------------------------------------------------------------ | --------------------------------- |
| 数据库备份   | 见[备份与恢复](backup.md)                                          | 备份文件非空且能解压              |
| 前端产物备份 | 见下方命令                                                         | 三个 `dist` 各留一份              |
| 当前版本留痕 | `git log --oneline -1`、`php artisan migrate:status \| tail -5`    | 记下提交号与最后一条迁移          |
| 磁盘余量     | `df -h`                                                            | 至少留出一次全量构建 + 备份的空间 |
| 生产配置     | `node backend/scripts/production_preflight.mjs --env=backend/.env` | 全部 `[PASS]`，无 `[FAIL]`        |

```bash
TS=$(date +%Y%m%d-%H%M%S)
tar -czf /tmp/backup/dist-$TS.tar.gz \
  frontend-user-v3-www/dist frontend-user-v4-console/dist frontend-admin-v3/dist

cd backend
composer check-platform-reqs --no-dev   # 只校验版本与扩展，不装包
php artisan app:doctor                  # PHP 版本、必需扩展、禁用函数
```

`app:doctor` 的检查项真源是 `backend/app/Support/EnvironmentRequirements.php`。三条兼容性注意：

- 最低 PHP 版本由代码约束（`composer.json` 的 `php` 约束与 `EnvironmentRequirements::MIN_PHP_VERSION`）；PHP 大版本升级必须与代码升级分开做、分别验证。
- 计划任务里的 `php` 必须与 API 站点同一个 PHP CLI。宝塔默认 CLI 版本常与站点不一致，必要时改成绝对路径（如 `/www/server/php/83/bin/php`），否则新代码会在旧解释器上执行。
- `production_preflight.mjs --network` 会真实探测四个公开地址的 TLS、`/api/ready` 与 CORS，可在升级后复跑（可选 `--min-cert-days=`、`--json`）。

## 2. 更新代码

```bash
cd /www/wwwroot/你的项目      # 换成你的实际部署目录
git status --short            # 先看清工作区有什么
git pull --ff-only origin main
git log --oneline -1          # 确认 HEAD 已推进到目标提交
```

两条必须避开的做法：

- **不要用 `git checkout origin/main -- <file>` 精确同步**。它只改工作区内容、不推进 HEAD；长期这么做会让生产 HEAD 停在很早的提交，等下一次完整 pull 时把中间所有他人提交一次性带进生产。
- **不要为了让 pull 通过而删掉工作区文件**。`git pull` 会被未跟踪的同名文件阻塞（`error: Untracked working tree files ...`）。先比对内容，一致才删：

```bash
sha1sum backend/public/media/console-panel/jquery.min.js
git show origin/main:backend/public/media/console-panel/jquery.min.js | sha1sum   # 两行一致才继续
rm -rf backend/public/media/console-panel && git pull --ff-only origin main
```

先在预发或本地跑一次同一提交，确认这次会带上线哪些提交，再决定发布窗口。

## 3. 安装依赖

```bash
cd backend
composer install --no-dev --optimize-autoloader
```

`composer.lock` 有变化时必须重新执行，否则新代码调用的依赖版本仍是旧的。以 root 运行时前面加 `COMPOSER_ALLOW_SUPERUSER=1`。之后不需要手工清 `bootstrap/cache`，第 6 节的缓存重建会覆盖。**不要执行 `php artisan key:generate`**：`APP_KEY` 用于解密存量密文（身份证、密钥类字段），换掉会让已加密数据永久无法读取；`install_db.py` 只在键为空时生成一次。

## 4. 执行数据库迁移

迁移文件是**只新增**：`up()` 只建表、加列、加索引，不删表、不删列、不删索引。少数迁移的 `down()` 里确有 `dropColumn` / `dropIndex`，那是给本地测试库用的，生产不执行（例：`2026_08_29_000001_add_secret_hash_index_to_api_keys.php` 明确不在 `down()` 里 drop 索引）。

```bash
cd backend
php artisan migrate:status        # 只读，看哪些还没跑
php artisan migrate --force       # 生产必须带 --force
php artisan migrate:status | grep -i pending || echo "无待执行迁移"
```

规则：

- **先完成第 1 节的备份再迁移**。迁移一旦落库，回退手段只有备份还原。
- 不要在生产库执行 `migrate:fresh`、`migrate:reset`、`migrate:rollback`，也不要对承载业务数据的库执行 `python3 scripts/install_db.py --reset`。
- `database/schema/mysql-schema.sql` 只用于空库初始化，禁止手工编辑。
- 迁移失败时不要反复重试。先看 `backend/storage/logs/laravel.log` 里的报错语句与表名，判断是结构冲突还是数据冲突，必要时走第 8 节的回滚路径。

## 5. 重建前端产物

三个前端是构建期注入地址的纯静态站点，升级必须重新构建，且**只能走统一入口**：

```bash
cd /www/wwwroot/你的项目
pnpm install --frozen-lockfile --shamefully-hoist
CI=true pnpm run build:frontends
```

- `pnpm run build:frontends` 从 `backend/.env` 的四个公开地址解析出 `VITE_*` 注入构建，是站点地址的唯一真源。
- 逐个 `pnpm run build` 会改读各前端目录自己的 `.env`，两处一旦不同步，产物里的 canonical、`og:url`、结算回跳地址会全部写成前端目录里的旧值——**站点 HTTP 全 200，但地址全是错的**。
- 生产机 PATH 里没有 `pnpm` 时先执行 `corepack enable pnpm`，否则构建脚本以 `spawn pnpm ENOENT` 失败。
- 远程命令有超时上限，构建几十秒会被中断。用后台方式跑再轮询日志：

```bash
nohup bash -c 'cd /www/wwwroot/你的项目 && CI=true pnpm run build:frontends > /tmp/build.log 2>&1; echo "exit=$?" >> /tmp/build.log' > /dev/null 2>&1 &
sleep 100 && tail -10 /tmp/build.log     # 轮询直到出现 exit=
```

产物落在各前端目录的 `dist/`，不会写入 `backend/public`。发布时替换对应宝塔站点根目录下的内容即可；宝塔站点的「网站目录」必须是应用目录（如 `frontend-admin-v3`），「运行目录」填 `/dist`，否则构建会撞上不可删除的 `.user.ini`。

## 6. 重载缓存与队列

```bash
cd backend
php artisan optimize:clear      # 清配置、路由、视图、事件、编译缓存
php artisan config:cache
php artisan route:cache
```

- 队列与调度不需要额外重启：`schedule:run` 每分钟触发 `queue:drain` 新建 Worker，新代码在下一次触发时生效。
- 只有改了 `php.ini`、PHP 扩展或 `bootstrap/cache` 才需要重启 PHP-FPM（宝塔面板 → PHP → 重启）。
- 运行了 VNC Relay 的环境，重启一次常驻进程即可（宝塔进程守护 / `systemctl restart`）。
- **不要配置覆盖同一队列的常驻 `queue:work`**，那会与 `queue:drain` 形成双消费者。

## 7. 验证

```bash
curl -s -H "Host: api.example.com" http://127.0.0.1/api/health   # 存活
curl -s -H "Host: api.example.com" http://127.0.0.1/api/ready    # 就绪
php artisan app:doctor
```

`/api/ready` 会分别报告 `database`、`cache`、`storage`、`scheduler`、`scheduler_liveness`、`task_readiness`、`queue`。调度刚重载或 cron 尚未到触发点时 `scheduler` 为 `false` 属正常，等 1–2 分钟复查。

```bash
# 产物地址必须与后台真源一致
grep -ro "<你的官网域名>" frontend-user-v3-www/dist/ | wc -l   # 应 > 0
grep -ro "<旧域名或其它域名>" frontend-user-v3-www/dist/ | wc -l # 应为 0
grep -ro "<本次改动的新关键字>" frontend-admin-v3/dist/ | wc -l
```

人工核对清单：

- [ ] 官网、控制台、管理端三个站点首页正常，任意 SPA 路由刷新不 404
- [ ] 官网首页 HTML 的 title 是站点名（SEO 动态渲染生效），`/sitemap.xml`、`/robots.txt` 返回 200
- [ ] 管理端能登录，「日志中心 → 运行日志」无新增 error，「自动任务日志 → 定时任务」有新的成功记录
- [ ] 心跳活着：`php artisan scheduler:liveness` 输出「心跳正常」
- [ ] 队列未堆积：`php artisan queue:monitor provision,referral,notification,coupon,default,automation`
- [ ] 用一笔真实小额订单走通「下单 → 支付 → 开通 → 通知」再放量

有 TTL 的响应缓存（如 `console/capabilities` 300 秒、settings 分组 600 秒）不会因为重新部署而立即失效，验证前先清对应缓存键，不要误判为「改了没生效」。

## 8. 失败回滚

先冻结自动动作，再动数据：

```bash
# 注释掉计划任务中的 schedule:run 行（宝塔计划任务页 / /etc/cron.d/turaidc）
```

回滚分三条路径，互相独立，出问题先定级再选：

- **前端**：解包升级前的 dist 备份覆盖对应目录，无需重启任何服务。新产物引用的接口若已被旧后端撤回，前端也要一起回退。
- **后端代码**：切回升级前的提交（`git log --oneline` 找到提交号），再 `composer install --no-dev --optimize-autoloader`，然后 `php artisan optimize:clear && php artisan config:cache && php artisan route:cache`。
- **数据库**：迁移只新增，通常**不需要**回滚数据库；只有在迁移本身出错或数据被写坏时才用备份还原，流程见[备份与恢复](backup.md)。禁止在生产直接 `migrate:rollback` 不可逆迁移。

回滚收尾：确认 `php artisan scheduler:liveness` 正常、`/api/ready` 全绿后再恢复计划任务；恢复前把回滚原因和影响范围记进执行计划，避免同样的升级路线再走一遍。

## 关联文档

- [备份与恢复](backup.md)：备份对象、现成脚本与恢复演练。
- [部署与调度指南](../deploy/scheduling.md)：增量更新的实测坑与运维口径。
- [故障排查](../troubleshooting.md)：升级后出现异常时的症状定位。
- [日志归档与 MySQL 日志维护](log-archive.md)：归档与数据库日志轮转。
