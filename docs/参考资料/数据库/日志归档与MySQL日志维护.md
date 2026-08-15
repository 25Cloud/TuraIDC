# 日志归档与 MySQL 日志维护

本文是生产环境日志归档、MySQL 日志轮转和 Binlog 过期策略的操作真源。

## 一、数据库日志归档

### 1.1 处理范围

`db:archive-logs` 固定处理以下 8 张 InnoDB 日志表：

| 表 | 内容 |
| --- | --- |
| `operation_logs` | API、后台操作和管理员登录日志 |
| `activity_logs` | 系统与业务活动日志 |
| `message_logs` | 短信、邮件统一消息日志 |
| `automation_logs` | 自动化业务任务日志 |
| `schedule_run_logs` | Laravel 调度运行日志 |
| `schedule_task_runs` | 平台自动任务运行日志 |
| `integration_plugin_runtime_logs` | 插件运行日志 |
| `gateway_logs` | 支付网关交互日志 |

归档条件固定为：

```sql
created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
```

财务流水、账单、支付、归档审计和业务快照不在此命令的处理范围内。

### 1.2 文件位置与格式

归档目录由后端固定管理：

```text
backend/storage/app/private/log-archives/
└── operation_logs/
    └── operation_logs_20260721.log
```

归档数据文件格式为：

```text
backend/storage/app/private/log-archives/{表名}/{表名}_YYYYMMDD.log
```

文件内容是带表头的 CSV。同一张表同一天重跑时，pt-archiver 追加到原文件且不重复写表头。执行报告和机器可读运行日志固定存放在 `backend/storage/logs/log-archive/`，不与归档数据混放。

超过 180 天的归档数据 `*.log` 会在正式归档结束后自动删除。dry-run 不创建归档数据文件，也不执行历史文件清理。

### 1.3 安装与凭据

目标 Linux 服务器必须安装 Percona Toolkit，并先验证：

```bash
/usr/bin/pt-archiver --version
```

创建 `/etc/turaidc/pt-archiver.cnf`：

```ini
[client]
host=127.0.0.1
port=3306
user=turaidc_archiver
password=替换为强密码
default-character-set=utf8mb4
```

```bash
sudo chown root:root /etc/turaidc/pt-archiver.cnf
sudo chmod 600 /etc/turaidc/pt-archiver.cnf
```

归档账号只授予目标库上述 8 张表的 `SELECT`、`DELETE` 权限。不要把密码写入 Crontab、命令参数、项目代码或执行日志。

归档目录不通过 `.env` 或管理员后台配置：归档数据固定写入 `storage_path('app/private/log-archives')`，报告固定写入 `storage_path('logs/log-archive')`。不支持 NAS 挂载路径或外部目录。

管理员后台的“日志归档”设置仅用于保留天数、pt-archiver 可执行文件与凭据文件路径、并发数、批量大小和批次间隔；归档数据目录与报告目录不可编辑。

### 1.4 执行与断点续传

先预检：

```bash
cd /www/wwwroot/turaidc/backend
/usr/bin/php artisan db:archive-logs --dry-run
```

小表试运行：

```bash
/usr/bin/php artisan db:archive-logs --execute --table=gateway_logs
```

正式执行全部表：

```bash
/usr/bin/php artisan db:archive-logs --execute
```

临时调整限流：

```bash
/usr/bin/php artisan db:archive-logs --execute --concurrency=2 --batch-size=500 --sleep-seconds=2
```

程序使用 pt-archiver 的 `--limit`、`--commit-each`、`--sleep`、递增主键扫描和 `--purge`。已提交批次已经写入文件并从原表删除，中断后再次执行会继续处理剩余记录。极端中断可能让最后一个未提交批次在文件中重复，但不会遗漏已经删除的记录；恢复或分析归档时按主键 `id` 去重。

每张表的成功、失败、影响行数、文件大小、SHA-256 和工具退出码会写入：

- `archive_audit_logs`：数据库内的归档审计；
- `backend/storage/logs/log-archive/run_*.json`：单次完整执行报告；
- `backend/storage/logs/log-archive/archive-YYYY-MM-DD.log`：逐事件 JSON 日志。

### 1.5 平台定时任务

日志归档注册为平台核心定时任务 `log-archive`，Cron 表达式为 `0 2 * * *`。Laravel 的唯一调度入口仍是每分钟执行的 `schedule:run`，它触发 `scheduler:heartbeat`；心跳把到期归档任务投递到默认队列，并由既有任务运行记录保存结果。

任务固定以 `--execute --json` 调用 `db:archive-logs`，最长运行 1 小时，任务锁为 61 分钟，以满足现有数据库队列的可见性超时约束。归档服务自身仍保留全局文件锁和每表 PID 文件，避免重叠归档同一张表。该任务不可从管理端手动触发；人工操作必须使用本节的命令并遵循预检流程。

生产只保留每分钟一次的 `schedule:run`。切换后必须移除原来直接执行 `db:archive-logs --execute` 的每日 02:00 Crontab，不能让两种触发方式同时运行。

## 二、MySQL 错误日志与慢查询日志轮转

先确认实际日志路径：

```sql
SHOW VARIABLES WHERE Variable_name IN ('log_error', 'slow_query_log', 'slow_query_log_file');
```

将查询出的错误日志和慢查询日志绝对路径填入 `/etc/logrotate.d/turaidc-mysql`：

```text
/实际路径/mysql-error.log /实际路径/mysql-slow.log {
    su mysql mysql
    daily
    rotate 30
    compress
    dateext
    missingok
    notifempty
    create 0640 mysql mysql
    sharedscripts
    postrotate
        /usr/bin/mysqladmin --defaults-extra-file=/root/.my.cnf flush-logs > /dev/null 2>&1 || true
    endscript
}
```

`/root/.my.cnf` 必须由 root 持有、权限为 `0600`，并使用具备 `RELOAD` 权限的运维账号；不要复用低权限归档账号。

验证配置：

```bash
sudo logrotate -d /etc/logrotate.d/turaidc-mysql
# 确认调试输出无错误后，在维护窗口验证一次实际轮转
sudo logrotate -f /etc/logrotate.d/turaidc-mysql
```

## 三、Binlog 过期策略

当前实库为 MySQL 8.0，执行：

```sql
SET PERSIST binlog_expire_logs_seconds = 2592000;
SHOW GLOBAL VARIABLES LIKE 'binlog_expire_logs_seconds';
```

MySQL 5.7 使用以下配置，不要与 MySQL 8.0 参数同时配置：

```ini
[mysqld]
expire_logs_days=30
```

也可以先在线执行 `SET GLOBAL expire_logs_days = 30`，但仍需写入 `my.cnf` 并在重启后再次验证。

## 四、空间口径与上线检查

物理删除会让 InnoDB 表空间内部可复用，但独立 `.ibd` 文件不保证立即缩小。本任务不自动执行 `OPTIMIZE TABLE`；确需向操作系统归还空间时，使用管理端现有的智能优化能力并安排独立维护窗口。

上线前依次确认：

1. pt-archiver 版本、凭据文件权限和归档目录可写；
2. 全表 dry-run 的影响行数符合预期；
3. `gateway_logs` 小表试运行后，CSV 表头、行数、数据库剩余记录和审计日志一致；
4. 确认每分钟 `schedule:run`、默认队列消费和 `log-archive` 定时任务均正常，并观察首轮完整报告；
5. `logrotate -d` 无错误，Binlog 过期变量为 2592000 秒。
