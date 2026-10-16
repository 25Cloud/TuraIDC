---
status: current
updated: 2026-10-16
owner: ops
---

# 备份与恢复

本文面向负责生产服务器的站长与运维，说明 TuraIDC 需要备份的对象、仓库里现成的备份脚本与保留策略、恢复流程与演练要点，以及如何证明一份备份真的可用。备份是升级与排障的前置条件，升级操作见[版本升级](upgrade.md)。

## 1. 备份对象清单

四类对象缺任何一类都无法完整恢复一个站点。外部集成（支付渠道、上游供应商）的配置与插件密钥存放在数据库里，随数据库备份一起保全，不需要单独导出。

| 对象           | 位置                                               | 丢失后果                                                              |
| -------------- | -------------------------------------------------- | --------------------------------------------------------------------- |
| 数据库         | MySQL 库（库名取 `backend/.env` 的 `DB_DATABASE`） | 订单、服务、资金、用户全部丢失                                        |
| 上传与私有文件 | `backend/storage/app/private/`                     | 工单上游附件、日志归档文件、插件私有文件丢失                          |
| 站点媒体与上传 | `backend/public/uploads/`、`backend/public/media/` | 工单附件、Logo、首页 Banner 图与视频丢失                              |
| 配置           | `backend/.env`                                     | 全部集成凭据与 `APP_KEY` 丢失；`APP_KEY` 丢失等于已加密字段永久读不出 |
| 前端产物       | 三个前端目录的 `dist/`                             | 站点打不开；可重建，但依赖构建机与正确的 `backend/.env`               |

`.env` 属于凭据文件，备份后必须按凭据管理：加密或离线存放、权限 `600`、不进入任何 Git 仓库。备份的**存放位置**不能与站点同盘同机：仓库脚本落在站点目录下的 `storage/backups/`，整机故障时会与业务数据一起丢失，需要自行同步到异地。

## 2. 数据库备份脚本

仓库提供两个现成脚本，按部署方式二选一。

### 2.1 源码 / 宝塔部署

```bash
cd /www/wwwroot/你的项目/backend
bash scripts/backup_mysql.sh
```

`backend/scripts/backup_mysql.sh` 的行为（以源码为准）：从 `backend/.env` 读取 `DB_HOST`、`DB_PORT`、`DB_DATABASE`、`DB_USERNAME`、`DB_PASSWORD`；用临时 `defaults-extra-file` 传递凭据（密码不出现在进程列表），执行完立即删除；调用 `mysqldump --single-transaction --routines --triggers --set-gtid-purged=OFF` 并 `gzip` 落盘；校验文件非空，空文件会被删掉并以非零码退出；最后清理过期文件并打印路径。

| 环境变量                | 默认值                          | 说明                                   |
| ----------------------- | ------------------------------- | -------------------------------------- |
| `BACKUP_DIR`            | `backend/storage/backups/mysql` | 备份目录                               |
| `BACKUP_RETENTION_DAYS` | 14                              | 保留天数，按文件 mtime 清理 `*.sql.gz` |

文件名形如 `<库名>_20261016_030000.sql.gz`。两个变量在调用前用环境变量覆盖，不必改脚本。定时执行：宝塔计划任务 → Shell 脚本，每天凌晨 3:00，脚本内容同上。

### 2.2 Docker / 1Panel 部署

```bash
cd /opt/turaidc/deploy/docker
./backup.sh            # 默认保留 14 天
./backup.sh 30         # 保留 30 天
```

该脚本在 mysql 容器内执行 `mysqldump`（复用容器环境的 root 口令，宿主机不存口令），用 `pipefail` 保证 dump 失败不会产生截断文件，并用 `gzip -t` 校验完整性：**校验失败会保留文件并退出**，且不清理旧备份。文件落在 `deploy/docker/backups/turaidc-<时间戳>.sql.gz`。宿主机 crontab：

```cron
0 3 * * * cd /opt/turaidc/deploy/docker && ./backup.sh >> /var/log/turaidc-backup.log 2>&1
```

Docker 命名卷里的上传与媒体数据不在脚本范围内，需要单独备份（见第 3 节）。

## 3. 文件类备份

仓库**没有**现成的文件备份脚本，以下为手工命令（未在代码中确认存在自动化替代）：

```bash
cd /www/wwwroot/你的项目
TS=$(date +%Y%m%d-%H%M%S)
mkdir -p /backup/turaidc

# 私有文件：工单上游附件、日志归档数据
tar -czf /backup/turaidc/storage-app-$TS.tar.gz -C backend/storage app

# 站点媒体与上传
tar -czf /backup/turaidc/public-files-$TS.tar.gz -C backend/public uploads media

# 配置：单独加密存放，不要与站点数据同盘
cp backend/.env /backup/turaidc/env-$TS.bak && chmod 600 /backup/turaidc/env-$TS.bak

# 前端产物：发布前打一次即可，产物可重建
tar -czf /backup/turaidc/dist-$TS.tar.gz \
  frontend-user-v3-www/dist frontend-user-v4-console/dist frontend-admin-v3/dist
```

`backend/storage/logs/` 与应用日志不需要长期备份；日志归档数据在 `backend/storage/app/private/log-archives/`，随 `storage/app` 一起打包，归档策略见[日志归档与 MySQL 日志维护](log-archive.md)。Docker 部署对应三个命名卷（`app-storage`、`app-uploads`、`app-media`），可用容器导出，卷名以 `docker volume ls` 实际输出为准（compose 项目名会作为前缀）：

```bash
docker run --rm -v turaidc_app-storage:/data -v /backup/turaidc:/out alpine \
  tar -czf /out/app-storage-$TS.tar.gz -C /data .
```

## 4. 保留策略

| 对象           | 建议保留                                               | 依据                      |
| -------------- | ------------------------------------------------------ | ------------------------- |
| 数据库每日备份 | 最近 14 天（脚本默认），关键发布窗口额外留一份手动快照 | `BACKUP_RETENTION_DAYS`   |
| 数据库月备份   | 保留 6–12 个月，单独目录，不受脚本自动清理影响         | 需要对账、审计回溯时用    |
| 上传与媒体文件 | 每周全量 + 变更前一次                                  | 附件只能从备份找回        |
| `.env`         | 每次变更后留一份，长期保留最近 5 份                    | 配置回滚与 `APP_KEY` 追溯 |
| 前端 `dist`    | 每次发布前一份，保留最近 5 份                          | 发布回滚                  |

脚本的自动清理只按 mtime 删除自身目录里的文件，不会碰其他目录。把月备份放进独立目录，就不会被日常清理带走。

## 5. 恢复流程与演练

### 5.1 隔离库演练

恢复的正确顺序是**先在隔离库演练，再动生产**。不要反复向同一临时库导入；先建新库再导入，失败就直接换一个库名重来。

```bash
# 1. 建一个与生产无关的复原库
mysql -u root -p -e "CREATE DATABASE idc_restore_drill_20261016 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 2. 导入
gunzip -c /backup/turaidc/idc_20261016_030000.sql.gz | mysql -u root -p idc_restore_drill_20261016

# 3. 比对表数量与关键财务行数（表清单以当前库为准）
mysql -u root -p -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='idc_restore_drill_20261016';"
mysql -u root -p -N -e "SELECT COUNT(*) FROM idc_restore_drill_20261016.migrations;"
```

导入失败时优先看报错行号与表名，而不是立刻换备份。曾出现过导入在第 715 行失败的记录：同一份 dump 换成原生 `mysql` 客户端即可完整导入，说明失败出在导入路径/客户端而非 dump 本身（原始记录属仓库维护者资料，不在本文链接）。

演练频率至少每季度一次，或在每次升级前做一次最小演练（只验证导入与表数量）。记录写清备份文件、时间戳、校验值、导入结果与差异清单，**差异为零才算通过**；没有做过恢复演练的备份只能算「文件存在」，不算「可恢复」。

### 5.2 生产还原

只有在确认备份可用、明确接受数据截止时刻的前提下才执行。还原前先停掉会写入的动作：

```bash
# 1. 冻结调度：注释掉计划任务中的 schedule:run 行（宝塔计划任务页 / /etc/cron.d/turaidc）
# 2. 对当前（故障）库再备份一次，保留现场，不要直接覆盖
bash scripts/backup_mysql.sh

# 3. 还原
gunzip -c /backup/turaidc/idc_<确认过的时间戳>.sql.gz | mysql -u <用户> -p <库名>

# 4. 恢复调度：取消第 1 步的注释，等 1-2 分钟让心跳与队列重新跑起来
```

还原范围限于数据库。文件类还原在站点目录内解包即可（先备份当前目录再解包）：

```bash
tar -xzf /backup/turaidc/storage-app-$TS.tar.gz -C backend/storage
tar -xzf /backup/turaidc/public-files-$TS.tar.gz -C backend/public
```

`.env` 的还原可能改变 `APP_KEY`。若与故障前不同，已加密字段（身份证等）将无法解密——这也是 `.env` 必须与数据库备份**同批次**保存的原因。修改 `.env` 后需要 `php artisan config:clear && php artisan config:cache`。演练要跑完整链路：建库 → 导入 → 核对表/行数 → 指向应用（用隔离环境，不要改生产 `.env`）→ 登录管理端看订单与资金页。

## 6. 备份有效性如何自证

按四层递进判断，越往后越可信：**文件非空**（脚本已自动做，空文件会被删除并非零退出）；**压缩包完整**（源码部署可自行补 `gzip -t`，Docker 脚本已内置；能发现截断，发现不了内容缺失）；**内容可比对**；**能真还原**（第 5 节的隔离库导入加行数比对，这是唯一算数的证据）。

```bash
gunzip -c idc_20261016_030000.sql.gz | grep -cE '^CREATE TABLE'
gunzip -c idc_20261016_030000.sql.gz | grep -oE 'INSERT INTO `(orders|services|users)`' | sort | uniq -c

# 每次备份后记录校验值与大小，形成可追溯台账
cd backend/storage/backups/mysql && sha256sum *.sql.gz > SHA256SUMS-$(date +%Y%m%d).txt && ls -lh --time-style=long-iso
```

监控与告警：把备份脚本的退出码接入现有告警渠道（如宝塔计划任务的日志/邮件），失败必须有人知道；`backend/scripts/production_preflight.mjs` 只检查配置与站点可达性，**不检查备份**（未在代码中确认有备份巡检任务）。

## 7. 常见错误

| 现象                      | 原因                                                                                        | 处理                                                                                 |
| ------------------------- | ------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------ |
| 脚本报 `.env 文件不存在`  | 不在 `backend` 目录执行或 `.env` 被移动                                                     | 确认 `backend/.env` 存在且属主可读                                                   |
| 备份文件是 0 字节         | `mysqldump` 失败（凭据、权限、磁盘满）                                                      | 看脚本 stderr；确认数据库账号有 `SELECT`、`LOCK TABLES`、`SHOW VIEW`、`TRIGGER` 权限 |
| 备份后目录没有新文件      | 计划任务里的 `cd` 路径不对，或 CLI PHP 不可用                                               | 用绝对路径，并把 stderr 落到日志文件而不是 `/dev/null`                               |
| 旧备份没被清理            | `BACKUP_DIR` 被环境变量指到别处                                                             | 打印实际路径核对；脚本只清理 `*.sql.gz`                                              |
| 还原后系统报解密失败      | `APP_KEY` 与备份数据不是同一批次                                                            | 从与数据库同批次的 `.env` 备份恢复                                                   |
| 导入到一半报语法/编码错误 | 客户端版本或字符集不匹配（用 MySQL 8.0 的 mysqldump 备份 5.7 需加 `--column-statistics=0`） | 换原生客户端重试，保留报错行号作为证据                                               |

## 关联文档

- [版本升级](upgrade.md)：升级前的备份要求与失败回滚路径。
- [故障排查](../troubleshooting.md)：磁盘与日志暴涨、数据库无法连接等症状定位。
- [部署与调度指南](../deploy/scheduling.md)：脚本调用方式与调度配置。
- [日志归档与 MySQL 日志维护](log-archive.md)：日志归档目录与 Binlog 过期策略。
