# MySQL 版本兼容基线（5.7.44 / 8.x 双版本）

本文是数据库与 SQL 改动的兼容性真源。**任何涉及数据库的改动（迁移、原生 SQL、查询构造器写法、模型写法）都必须同时在 MySQL 5.7.44 与 8.x 上成立。**

## 一、为什么必须双版本兼容

- 系统最低支持 MySQL **5.7.8**，推荐 8.0+，5.7 属兼容支持档（5.7 已 EOL）。存量用户里有相当比例仍在 5.7 上运行。
- 从智简魔方财务（ZJMF）搬迁过来的老站，其原有环境普遍是 5.7。搬迁工具链依赖表结构稳定。
- 只在 8.0 上验证的写法，在 5.7 上往往不是"降级运行"而是**直接抛 SQL 语法错误**，且通常只在特定页面触发，线上极难排查。

验证环境：`5.7.44`（当前 5.7 末版）与 `8.0.x`。以这两个版本为准，不以"理论上 5.7 支持"推断。

## 二、硬性禁令

### 2.1 禁止 8.0 专属语法

| 特性                                  | 5.7 | 替代写法                                                        |
| ------------------------------------- | :-: | --------------------------------------------------------------- |
| 窗口函数 `ROW_NUMBER()/RANK()/OVER()` | ❌  | 两级聚合：先 `MAX()` 分组求极值，再 join 回原表；并列用主键打破 |
| CTE `WITH ... AS`、递归 CTE           | ❌  | 子查询 / `joinSub()`                                            |
| 每组 top-N（借窗口函数实现）          | ❌  | 每组一条带 `LIMIT` 的子查询 `UNION ALL`，必要时在 PHP 侧再排序  |
| `JSON_TABLE()`                        | ❌  | 应用层展开                                                      |
| 降序索引（`INDEX (col DESC)`）        | ⚠️  | 5.7 会静默忽略方向，不报错但不生效；不要依赖其排序效果          |

> 现网案例：管理端工单上游投递日志曾用 `ROW_NUMBER() OVER (PARTITION BY ...)` 取每工单最新一条，在 5.7 上直接 `ERROR 1064`，整个页面不可用。已改写为两级 `MAX` + `joinSub`。

### 2.2 5.7 的隐式 `ON UPDATE CURRENT_TIMESTAMP` 陷阱

MySQL 5.7 默认 `explicit_defaults_for_timestamp=OFF`（8.0 默认 ON）。此时**表内第一顺位、且没有显式 `DEFAULT` 的 `timestamp NOT NULL` 列**，会被 MySQL 自动附加 `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`。后果是：任何不包含该列的 `UPDATE`，都会把它悄悄改写成当前时间。

因此：

- **新增表**：第一个 `timestamp` 列必须显式给 `DEFAULT`，或声明为 `nullable`（`$table->timestamp('x')->nullable()`），不要留裸的 `timestamp NOT NULL`。
- **更新既有行**：若目标表的首个 `timestamp` 列属上述形态，`UPDATE` 语句必须**显式带上该列的原值**来覆盖隐式行为（`Model::save()` 只写脏字段，不足以防护）。
- **部署要求**：5.7 部署应在 `my.cnf` 的 `[mysqld]` 设 `explicit_defaults_for_timestamp=ON`。这是纵深防御，不能替代上面两条代码层要求——不能假设用户改过配置。

> 现网案例：`schedule_ticks.slot_started_at` 正属此形态。同一 15 分钟槽内第二次心跳更新 `triggered_at` 时，5.7 把槽起点改成了当前时间，槽唯一键语义崩坏，调度器从该槽第 ~3 分钟起持续撞 `global_number` 唯一键。修复方式是在 `UPDATE` 中显式回写 `slot_started_at` 原值（零 DDL）。

### 2.3 版本下限相关

- `json` 列类型、虚拟生成列上的唯一索引：**5.7.8 起**才支持，这是最低版本 5.7.8 的由来。基线含 58 个 `json` 列，不可退到 5.6。
- 索引键长度：5.7 若未开 `innodb_large_prefix`（默认值随小版本变化），`utf8mb4` 下单列索引超过 191 字符可能失败。新增索引列尽量控制在 `varchar(191)` 以内，超长字段用前缀索引或哈希列。

## 三、数据库只增不删（铁律）

**绝不删除任何表、列或索引。** 只允许新增。

原因：我们不是原始开发者，存量用户会从更老的版本升级上来，还有用户正从魔方财务搬迁过来——迁移工具链（`backend/scripts/mofang_to_turaidc_migrator.py` 等）依赖表结构稳定。任何删除操作都可能让存量升级路径在毫无征兆处崩掉，且难以回滚。

- 发现冗余表 / 冗余列：**只记录、不动手**，把处置权留给上游维护者。
- 迁移只新增，且必须幂等（先 `Schema::hasTable` / `hasColumn` 再动手），不补跑历史激进迁移。
- 需要改变约束语义时（如放宽 `NOT NULL`、替换唯一键），走"新增/放宽/替换"路径，不走"先删后建"的净删除。

## 四、框架层已自适应的部分（无需额外处理）

Laravel 12 内部已按服务端版本分支处理以下差异，**不要为它们手写版本判断**：

- `sql_mode` 设置：`MySqlConnector` 以 8.0.11 为界分支。
- 队列 `SKIP LOCKED`：`DatabaseQueue` 以 8.0.1 为界，5.7 自动降级。
- `upsert` 的 `VALUES()` 语法：按版本选择写法。

## 五、改动前后的自检清单

1. 新增/修改的 SQL 是否用到窗口函数、CTE、`JSON_TABLE`？→ 有则必须改写。
2. 新增表是否有裸 `timestamp NOT NULL` 作为首个时间列？→ 有则加显式默认值或改可空。
3. 是否新增了对既有表的 `UPDATE` 路径？→ 检查该表首个 `timestamp` 列形态，必要时显式回写。
4. 迁移里有没有 `dropColumn` / `dropIndex` / `dropIfExists` 出现在 `up()`？→ 有则必须去掉（`down()` 中的回滚逻辑不受此限）。
5. 是否在 5.7 与 8.0 两套库上都跑过受影响的测试？→ 两边的失败集合应当一致。

## 六、如何在本地/服务器验证 5.7

不要动生产库。用独立 datadir 的 5.7 沙箱：

```bash
# 关键：mysqld / mysql 的每次调用都必须把 --no-defaults 放在第一个参数，
# 否则会读取系统 my.cnf 里的 datadir，导致沙箱进程去锁生产数据目录。
mysqld --no-defaults --initialize-insecure --user=root \
  --basedir=<解压目录> --datadir=<沙箱目录>/data \
  --character-set-server=utf8mb4 --collation-server=utf8mb4_unicode_ci

mysqld --no-defaults --user=root --basedir=<解压目录> --datadir=<沙箱目录>/data \
  --port=3307 --bind-address=127.0.0.1 --socket=<沙箱目录>/m57.sock \
  --pid-file=<沙箱目录>/m57.pid --performance-schema=OFF --skip-name-resolve
```

随后以 `DB_PORT=3307` 覆盖环境变量跑测试套件，与 8.0 的结果逐条比对：**5.7 的失败集合必须与 8.0 完全一致**，多出任何一条都要按真实缺陷对待，而不是"5.7 就这样"。
