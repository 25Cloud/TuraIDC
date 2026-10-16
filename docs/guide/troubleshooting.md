---
status: current
updated: 2026-10-16
owner: ops
---

# 故障排查

本文面向负责生产服务器的站长与运维，按「症状 → 先查什么 → 常见根因 → 处理 → 如何确认恢复」组织常见故障的定位路径。本文只覆盖排障；日常升级与备份操作见[版本升级](maintain/upgrade.md)与[备份与恢复](maintain/backup.md)。

> 排障前先记住三个前提：生产站点是**四端独立**部署（官网 / 控制台 / 管理端静态站点 + API 站点），浏览器直连 API 域名；队列与定时任务由每分钟一次的 `schedule:run` 驱动，没有常驻 Worker；命令中的 `php` 必须是与 API 站点相同的 PHP CLI。以运行代码、日志和 `/api/ready` 输出为准，不要凭印象判断。

## 1. 站点打不开或白屏

```bash
curl -I https://你的官网域名/          # 看 HTTP 状态码，而不是只看浏览器
curl -s -o /dev/null -w '%{http_code}\n' https://你的API域名/api/health
tail -50 /www/wwwroot/你的项目/backend/storage/logs/laravel.log
```

浏览器按 F12 看 Console 与 Network：静态资源 404、接口 CORS/404、`pageerror` 三条线索指向完全不同的问题。

| 现象                                                                        | 根因                                                                                                    | 处理                                                                                       |
| --------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------ |
| 页面空白，Console 有 `ReferenceError: t is not defined` 之类 setup 阶段报错 | 前端发布产物有问题（历史上出现过 SFC 里游离 import 导致整个组件挂载失败）。构建、类型检查都不会报这种错 | 用上一次发布前的 `dist` 备份回滚，重新构建并复测；参考[版本升级](maintain/upgrade.md)      |
| 只有刷新某个路由才 404                                                      | 该 SPA 站点没配 History 回退                                                                            | 补 `try_files $uri $uri/ /index.html;`，见[四端 Nginx 伪静态配置](maintain/nginx-rules.md) |
| 页面能开但数据全是空的、接口 404/403                                        | 产物里的 API 地址与后端真源不一致，或 CORS 未放行                                                       | 核对 `backend/.env` 的四个公开地址，用 `grep -ro` 校验产物                                 |
| 官网首页没有标题/正文、meta 是空壳                                          | 官网 SEO 动态渲染的转发规则或模板地址失效                                                               | 见[四端 Nginx 伪静态配置](maintain/nginx-rules.md)与[部署与调度指南](deploy/scheduling.md) |
| 官网 `index.html` 正常但样式/JS 全 404                                      | 站点根目录或「运行目录」配置错误                                                                        | 宝塔站点「网站目录」填应用目录、「运行目录」填 `/dist`                                     |

**如何确认恢复**：四个公开地址各自返回 200；控制台与管理端刷新任意路由都不 404；官网首页 HTML 的 `title` 是站点名（不是默认壳）。

## 2. HTTP 502 与 PHP 版本、禁用函数

```bash
# 站点错误日志（宝塔：网站 → 站点 → 日志；裸机：/var/log/nginx/）
tail -50 /www/wwwlogs/你的API站点.error.log
php -v && php -m | grep -iE 'pdo_mysql|redis|openssl|mbstring|fileinfo|json'
```

- **PHP-FPM 未运行，或 API 站点没选中可用的 PHP 版本**（宝塔 → 软件商店 → PHP → 状态）：在站点「PHP 版本」里重新选择并重启对应 PHP 服务。不要手工改宝塔生成的 `fastcgi_pass` socket 路径。
- **PHP 版本低于最低要求**：最低版本的真源是 `backend/app/Support/EnvironmentRequirements.php`（PHP 8.3.0）。装 8.2 会在环境检测环节被直接拦下。
- **`putenv`、`proc_open`、`symlink` 被 `disable_functions` 禁用**：这是最高频的部署故障，宝塔默认禁用这三个。表现为 Composer 报 `Call to undefined function putenv()`，或应用读取 `.env`、调用外部进程时失败。处理：宝塔 → 软件商店 → PHP → 禁用函数，删掉这三个后重启 PHP。
- **PHP-FPM 进程池被打满或超时**：`Request Timeout` / `upstream timed out` 说明是慢请求而不是进程挂掉，去 `laravel.log` 与慢查询日志找具体请求。
- **`open_basedir` 限制了站点目录**：容器化部署下代码路径变化会让全部文件操作被拒（`deploy/docker/backend/entrypoint.sh` 会在容器启动时重写 `public/.user.ini`）。

**如何确认恢复**：`curl -I` 返回 200 或业务状态码（不再是 502/504）；`php artisan app:doctor` 通过；站点错误日志不再出现 `connect() to unix:/... failed`。

## 3. app:doctor 报错

先执行 `cd /www/wwwroot/你的项目/backend && php artisan app:doctor`（`--all` 附带列出建议安装但不阻断的扩展）。命令返回非零退出码，表示**必选项**未通过，输出里会逐条列出未通过项与当前 `disable_functions`。

| 未通过项                                                            | 处理                                                                                                                       |
| ------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------- |
| `PHP 版本 >= 8.3.0`                                                 | 升级 PHP，或切换站点与 CLI 到已装的 8.3+                                                                                   |
| `PHP 扩展 pdo_mysql / redis / openssl / mbstring / fileinfo / json` | 在面板安装对应扩展并重启 PHP（必需清单以 `EnvironmentRequirements::REQUIRED_EXTENSIONS` 为准）                             |
| `可调用函数 putenv / proc_open / symlink`                           | 从 `disable_functions` 中删除后重启 PHP；被禁用时 `function_exists()` 仍返回 true，只有 `disable_functions` 列表是可靠信号 |

注意 `app:doctor` 检测的是**当前进程**的 PHP：CLI 通过不代表 FPM 通过，两者配置可能不同。

**如何确认恢复**：命令输出「环境检测通过」且退出码为 0。

## 4. 队列与心跳不跑（定时任务没配）

**症状**：已付款订单一直不开通；自动续费、到期停服、账单逾期都不执行；通知不入队；管理端「自动任务日志 → 定时任务」长时间没有新记录。

```bash
cd /www/wwwroot/你的项目/backend
php artisan scheduler:liveness      # 心跳活着吗
php artisan schedule:run            # 手动触发一次，看有没有报错
php artisan queue:monitor provision,referral,notification,coupon,default,automation
```

管理端：`/admin/logs/schedules`（定时任务）、`/admin/logs/tasks`（自动任务日志）。平台层的 `/api/ready` 会分别报告 `scheduler`、`scheduler_liveness`、`task_readiness`、`queue`。

1. **计划任务根本没配或没真正执行**。唯一需要的是每分钟一次：

   ```cron
   * * * * * cd /www/wwwroot/你的项目/backend && php artisan schedule:run >> /dev/null 2>&1
   ```

   若 `cd` 路径不对、或 CLI PHP 不在 cron 的 PATH 里，命令会静默失败（错误被 `>> /dev/null 2>&1` 吞掉）。宝塔计划任务默认 PATH 与站点不同，`php` 可能指向另一个版本甚至不存在，先把输出改到日志文件排查。

2. **误配了常驻 `queue:work`**：`schedule:run` 已每分钟后台驱动 `queue:drain` 消费全部队列组，再挂常驻 Worker 会形成双消费者。不要在保留 `queue:drain` 的同时覆盖同一队列。
3. **`QUEUE_CONNECTION` 不是 `database`**：`queue:drain` 会直接跳过（返回 `queue_driver_not_database`），表现为队列静默不执行。核对 `backend/.env` 的 `QUEUE_CONNECTION`、`TURAIDC_PROVISION_QUEUES`、`TURAIDC_BUSINESS_QUEUES`、`TURAIDC_SCHEDULE_QUEUE`。
4. **`jobs` 表不存在**（drain 返回 `jobs_table_missing`）或 **Worker 被 OOM/强杀留下残锁**：前者确认迁移已执行；后者 drain 会按存活心跳判定残锁并强制释放，`laravel.log` 会出现「检测到队列 Worker 残锁」，反复出现要先查内存与系统日志。
5. **任务堆积**：按队列边界手动消费一次，不要起常驻 Worker。

   ```bash
   php artisan queue:work --once --queue=provision
   php artisan queue:work --once --queue=referral,notification,coupon,default
   php artisan queue:work --once --queue=automation
   php artisan queue:failed            # 列出失败任务
   php artisan queue:retry <id>        # 重试指定失败任务
   ```

**如何确认恢复**：`scheduler:liveness` 输出「心跳正常」；`/api/ready` 四个相关项为 true；手动跑一次 `schedule:run` 后「定时任务」页出现新的成功记录；`queue:monitor` 的待处理数为 0 或持续下降。

## 5. 邮件发不出去

- 管理端「日志中心 → 邮件日志」（`/admin/logs/email`），看是根本没生成记录、还是生成了但状态失败。记录里有 `plugin_id`、`driver_key`、`trace_id`。
- 「插件管理 → 邮件发送」（`/admin/integration-plugins/mail`）的插件是否已安装、配置并绑定。

**关键区分**：后台的**测试发信走驱动直发，不受总开关约束**。所以「测试邮件成功、用户却收不到验证码」几乎一定是总开关没开，而不是 SMTP 坏了。对外是否真的会发邮件的唯一判定源是 `notification.email_enabled`（`NotificationService::isEmailEnabled()`），在「站点设置 → 通知总开关」里开。

其他常见根因：`MAIL_MAILER=log`（默认值之一，邮件只写日志不真发，生产必须由邮件插件接管驱动配置）；邮件模板缺失或未启用（模板种子由 `SettingsSeeder` 幂等写入 `notification_templates`，模板数据不在 schema baseline 里）；发件人被收件方拒收（核对发件域名的 SPF/DKIM，属邮件服务商侧配置，本系统内未在代码中确认有发信域校验能力）。

**如何确认恢复**：管理端触发一次真实的注册/找回密码流程，邮件日志出现 `status` 成功的记录，收件箱收到邮件。

## 6. 上游开通失败

**症状**：用户已付款，服务停在「开通中」，`provision_data` 里带 `provision_error`；或控制台/管理端操作提示上游失败。

```bash
cd /www/wwwroot/你的项目/backend
php artisan provision:retry-failed                     # 默认 dry-run，只扫描并告警
php artisan provision:retry-failed --limit=10 --max-retries=3
```

命令会列出孤儿单的 `service_id`、`order_id`、`retry_count` 与截断后的 `provision_error`。

- 上游插件未安装或未配置：管理端「插件管理 → 上游开通」（`/admin/integration-plugins/upstream`）核对绑定与凭据。
- 上游地址/凭据变更，或上游侧服务异常：先用 `provision_error` 里的原文定位到具体接口。上游可能是纯 HTTP 或证书链不完整，**不要**因为证书问题就去改校验逻辑。
- 任务堆积在 `provision` 队列：见第 4 节，先确认队列在消费再谈重试。
- 重试本身有风险：开通链路是「清空购物车 → 加购 → checkout → 取 host_id」，缺少上游幂等键回查。若上次失败发生在 checkout 成功之后，自动重试可能造成**重复开通**。因此 `provision:retry-failed` 默认 dry-run，`--execute` 由管理员在核实上游实际开通状态后决定。

**如何确认恢复**：`php artisan provision:retry-failed` 输出「未发现上游开通失败孤儿单」；管理端「工单推送日志」/服务详情显示开通成功；数据库内该服务状态不再停留 `PENDING`。

## 7. 磁盘与日志暴涨

```bash
df -h
du -sh /www/wwwroot/你的项目/backend/storage/* 2>/dev/null | sort -h | tail
ls -lhS /var/lib/mysql/ | head    # 含 binlog
```

| 占用源                                                   | 处理                                                                                                                                                                                            |
| -------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| MySQL 备份目录                                           | 脚本默认保留 14 天；用 `BACKUP_RETENTION_DAYS` 调整或把月备份移到别的盘                                                                                                                         |
| Binlog                                                   | 生产 MySQL 8：`SET PERSIST binlog_expire_logs_seconds = 2592000;`（MySQL 5.7 用 `expire_logs_days`），见[日志归档与 MySQL 日志维护](maintain/log-archive.md)                                    |
| MySQL 错误日志/慢查询日志                                | 配置 logrotate，见[日志归档与 MySQL 日志维护](maintain/log-archive.md)                                                                                                                          |
| 高频业务日志表（`operation_logs` 等 7 张 InnoDB 日志表） | 先 `php artisan db:archive-logs --dry-run` 看影响行数，再 `--execute`；归档数据落在 `storage/app/private/log-archives/`，超 180 天的 `*.log` 会自动删除，归档报告在 `storage/logs/log-archive/` |
| 应用日志堆积                                             | `LOG_DAILY_DAYS`（默认 14）与 `LOG_LEVEL` 决定保留量；生产不要长期开 `debug`                                                                                                                    |
| 工单上游临时上传                                         | 已注册每分钟清理命令，可查「日志中心 → 上传/清理日志」（`/admin/logs/upstream-uploads`）                                                                                                        |
| `failed_jobs` 累积                                       | `php artisan queue:failed` 查看，`queue:retry <id>` 重试，确认无价值后再 `php artisan queue:flush --hours=168`                                                                                  |
| 日志归档目录本身                                         | 归档数据不通过 `.env` 配置路径，固定写在 `storage/app/private/log-archives`，不支持 NAS 或外部目录                                                                                              |

**如何确认恢复**：`df -h` 使用率回落并保持稳定；连续两天观察增速，确认增长来自业务量而不是泄漏。物理删除不会立即缩小 InnoDB 的独立 `.ibd` 文件，归还空间要单独安排维护窗口。

## 8. 权限 403 的常见原因

```bash
tail -30 /www/wwwlogs/你的站点.error.log          # 是 Nginx 拒绝还是应用返回
ls -ld /www/wwwroot/你的项目/backend /www/wwwroot/你的项目/backend/public
ls -l /www/wwwroot/你的项目/backend/.env
```

- **站点根目录指向错误**：请求落到了不该暴露的目录，或目录本身不可读。宝塔站点「网站目录」应是应用目录，不是 `dist`。
- **`.env` 权限不当**：必须严格限制（`chmod 600 .env`），属主要为站点运行用户；过宽是安全问题，过窄会让应用读不到配置。
- **`storage/`、`bootstrap/cache` 或上传目录（`public/uploads`、`public/media`）不可写**：写入失败在部分场景表现为 403/500。
- **`disable_functions` 里含 `symlink`**：`php artisan storage:link` 之类的符号链接操作失败，静态资源路径取不到。
- **`open_basedir` 不包含站点目录**：宝塔的「防跨站攻击」会在站点根目录写 `.user.ini`（`chattr +i`）；容器化部署需重写该文件指向真实代码路径。
- **Nginx `deny all` 规则命中**：配置里常见的 `location ~ /\.(?!well-known)` 会拒掉点目录访问，这是预期行为，不要为绕过它放开隐藏文件。

```bash
cd /www/wwwroot/你的项目/backend
sudo chown -R www:www storage bootstrap/cache public/uploads public/media
sudo chmod -R 775 storage bootstrap/cache public/uploads public/media && sudo chmod 600 .env
```

**如何确认恢复**：`/api/ready` 的 `storage` 为 true；管理端上传一张图片或提交一次带附件的工单成功；`laravel.log` 不再出现 `failed to open stream: Permission denied`。

## 关联文档

- [版本升级](maintain/upgrade.md)：升级流程、验证与回滚路径。
- [备份与恢复](maintain/backup.md)：备份对象、恢复演练与有效性自证。
- [部署与调度指南](deploy/scheduling.md)：调度、队列与受信代理契约。
- [四端 Nginx 伪静态配置](maintain/nginx-rules.md)：四端站点规则与 404 回退。
- [日志归档与 MySQL 日志维护](maintain/log-archive.md)：日志与 Binlog 清理。
- [部署指南](deploy/generic.md)：环境要求与从零部署步骤，排查后重新上线时对照。
