<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

define('LARAVEL_START', microtime(true));

$basePath = dirname(__DIR__);

require $basePath.'/vendor/autoload.php';

$app = require $basePath.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

date_default_timezone_set((string) config('app.timezone', 'Asia/Shanghai'));

$connectionName = (string) config('database.default', 'mysql');
$connection = DB::connection($connectionName);
$database = (string) $connection->getDatabaseName();
$serverInfo = $connection->selectOne('SELECT VERSION() AS version, DATABASE() AS database_name');
$mysqlVersion = (string) ($serverInfo->version ?? 'unknown');

$tables = $connection->select(<<<'SQL'
    SELECT
        TABLE_NAME AS table_name,
        TABLE_TYPE AS table_type,
        ENGINE AS engine,
        TABLE_ROWS AS table_rows,
        DATA_LENGTH AS data_length,
        INDEX_LENGTH AS index_length,
        AUTO_INCREMENT AS auto_increment,
        TABLE_COLLATION AS table_collation,
        TABLE_COMMENT AS table_comment,
        CREATE_TIME AS create_time,
        UPDATE_TIME AS update_time
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
    ORDER BY TABLE_NAME
SQL);

$columnsByTable = groupBy($connection->select(<<<'SQL'
    SELECT
        TABLE_NAME AS table_name,
        ORDINAL_POSITION AS ordinal_position,
        COLUMN_NAME AS column_name,
        COLUMN_TYPE AS column_type,
        DATA_TYPE AS data_type,
        CHARACTER_SET_NAME AS character_set_name,
        COLLATION_NAME AS collation_name,
        IS_NULLABLE AS is_nullable,
        COLUMN_DEFAULT AS column_default,
        COLUMN_KEY AS column_key,
        EXTRA AS extra,
        COLUMN_COMMENT AS column_comment,
        GENERATION_EXPRESSION AS generation_expression
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    ORDER BY TABLE_NAME, ORDINAL_POSITION
SQL), 'table_name');

$rawIndexes = $connection->select(<<<'SQL'
    SELECT
        TABLE_NAME AS table_name,
        INDEX_NAME AS index_name,
        NON_UNIQUE AS non_unique,
        SEQ_IN_INDEX AS seq_in_index,
        COLUMN_NAME AS column_name,
        COLLATION AS collation,
        CARDINALITY AS cardinality,
        SUB_PART AS sub_part,
        NULLABLE AS nullable,
        INDEX_TYPE AS index_type,
        COMMENT AS comment,
        INDEX_COMMENT AS index_comment
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
    ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX
SQL);

$indexesByTable = [];
foreach ($rawIndexes as $index) {
    $tableName = (string) $index->table_name;
    $indexName = (string) $index->index_name;

    $indexesByTable[$tableName][$indexName] ??= [
        'index_name' => $indexName,
        'non_unique' => (int) $index->non_unique,
        'index_type' => (string) $index->index_type,
        'columns' => [],
        'cardinality' => [],
        'comment' => (string) ($index->comment ?? ''),
        'index_comment' => (string) ($index->index_comment ?? ''),
    ];

    $columnName = (string) $index->column_name;
    if ($index->sub_part !== null) {
        $columnName .= '('.(string) $index->sub_part.')';
    }
    if ((string) ($index->collation ?? '') === 'D') {
        $columnName .= ' DESC';
    }

    $indexesByTable[$tableName][$indexName]['columns'][] = $columnName;
    $indexesByTable[$tableName][$indexName]['cardinality'][] = $index->cardinality;
}

$foreignKeysByTable = [];
$rawForeignKeys = $connection->select(<<<'SQL'
    SELECT
        kcu.CONSTRAINT_NAME AS constraint_name,
        kcu.TABLE_NAME AS table_name,
        kcu.COLUMN_NAME AS column_name,
        kcu.REFERENCED_TABLE_NAME AS referenced_table_name,
        kcu.REFERENCED_COLUMN_NAME AS referenced_column_name,
        rc.UPDATE_RULE AS update_rule,
        rc.DELETE_RULE AS delete_rule,
        kcu.ORDINAL_POSITION AS ordinal_position
    FROM information_schema.KEY_COLUMN_USAGE kcu
    LEFT JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
        ON rc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA
        AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
        AND rc.TABLE_NAME = kcu.TABLE_NAME
    WHERE kcu.TABLE_SCHEMA = DATABASE()
        AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
    ORDER BY kcu.TABLE_NAME, kcu.CONSTRAINT_NAME, kcu.ORDINAL_POSITION
SQL);

foreach ($rawForeignKeys as $foreignKey) {
    $tableName = (string) $foreignKey->table_name;
    $constraintName = (string) $foreignKey->constraint_name;

    $foreignKeysByTable[$tableName][$constraintName] ??= [
        'constraint_name' => $constraintName,
        'columns' => [],
        'referenced_table_name' => (string) $foreignKey->referenced_table_name,
        'referenced_columns' => [],
        'update_rule' => (string) ($foreignKey->update_rule ?? ''),
        'delete_rule' => (string) ($foreignKey->delete_rule ?? ''),
    ];

    $foreignKeysByTable[$tableName][$constraintName]['columns'][] = (string) $foreignKey->column_name;
    $foreignKeysByTable[$tableName][$constraintName]['referenced_columns'][] = (string) $foreignKey->referenced_column_name;
}

$checksByTable = [];
try {
    $rawChecks = $connection->select(<<<'SQL'
        SELECT
            tc.TABLE_NAME AS table_name,
            tc.CONSTRAINT_NAME AS constraint_name,
            cc.CHECK_CLAUSE AS check_clause
        FROM information_schema.TABLE_CONSTRAINTS tc
        INNER JOIN information_schema.CHECK_CONSTRAINTS cc
            ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA
            AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME
        WHERE tc.TABLE_SCHEMA = DATABASE()
            AND tc.CONSTRAINT_TYPE = 'CHECK'
        ORDER BY tc.TABLE_NAME, tc.CONSTRAINT_NAME
    SQL);

    $checksByTable = groupBy($rawChecks, 'table_name');
} catch (Throwable) {
    $checksByTable = [];
}

$tableCount = count($tables);
$columnCount = array_sum(array_map('count', $columnsByTable));
$indexCount = array_sum(array_map('count', $indexesByTable));
$foreignKeyCount = array_sum(array_map('count', $foreignKeysByTable));
$checkCount = array_sum(array_map('count', $checksByTable));
$jsonColumns = [];
$dataTypeCounts = [];

foreach ($columnsByTable as $tableName => $columns) {
    foreach ($columns as $column) {
        $dataType = (string) $column->data_type;
        $dataTypeCounts[$dataType] = ($dataTypeCounts[$dataType] ?? 0) + 1;

        if ($dataType === 'json') {
            $jsonColumns[] = $tableName.'.'.(string) $column->column_name;
        }
    }
}

ksort($dataTypeCounts);

$lines = [
    '# 当前数据库结构说明',
    '',
    '- 文档性质：参考资料 / 实库结构快照',
    '- 生成时间：`'.date('Y-m-d H:i:s P').'`',
    '- 数据来源：Laravel 默认连接 `'.$connectionName.'` 直连 MySQL `information_schema` 与业务库 `'.$database.'`',
    '- 数据库：`'.$database.'`',
    '- MySQL 版本：`'.escapeInlineCode($mysqlVersion).'`',
    '- 当前表数量：`'.$tableCount.'`',
    '- 字段数量：`'.$columnCount.'`',
    '- 索引数量：`'.$indexCount.'`',
    '- 外键约束数量：`'.$foreignKeyCount.'`',
    '- CHECK 约束数量：`'.$checkCount.'`',
    '- 说明：',
    '  - 本文只导出表结构元数据，不包含任何业务行数据。',
    '  - 行数来自 `information_schema.TABLES.TABLE_ROWS`，InnoDB 下仅作估算。',
    '  - 字段、索引、外键与约束均来自当前实库，不以迁移文件或历史快照推断。',
    '  - 需要更新时在项目根目录执行：`php backend/scripts/export_database_structure.php`。',
    '',
    '> **自动生成**：请优先通过脚本重刷本文档，避免手工维护结构信息产生漂移。',
    '',
    '## 1. 结构概览',
    '',
    '### 1.1 表清单',
    '',
    '| 表名 | 类型 | 引擎 | 估算行数 | 数据大小 | 索引大小 | 自增值 | 排序规则 | 表注释 |',
    '| --- | --- | --- | ---: | ---: | ---: | ---: | --- | --- |',
];

foreach ($tables as $table) {
    $lines[] = sprintf(
        '| `%s` | %s | %s | %s | %s | %s | %s | %s | %s |',
        escapeTableCell((string) $table->table_name),
        escapeTableCell((string) $table->table_type),
        emptyCell($table->engine),
        numberCell($table->table_rows),
        formatBytes((int) ($table->data_length ?? 0)),
        formatBytes((int) ($table->index_length ?? 0)),
        numberCell($table->auto_increment),
        emptyCell($table->table_collation),
        emptyCell($table->table_comment)
    );
}

$lines[] = '';
$lines[] = '### 1.2 字段类型分布';
$lines[] = '';
$lines[] = '| 类型 | 字段数 |';
$lines[] = '| --- | ---: |';
foreach ($dataTypeCounts as $type => $count) {
    $lines[] = sprintf('| `%s` | %d |', escapeTableCell($type), $count);
}

$lines[] = '';
$lines[] = '### 1.3 JSON 字段';
$lines[] = '';
if ($jsonColumns === []) {
    $lines[] = '当前实库未发现 JSON 字段。';
} else {
    foreach ($jsonColumns as $jsonColumn) {
        $lines[] = '- `'.escapeInlineCode($jsonColumn).'`';
    }
}

$lines[] = '';
$lines[] = '## 2. 表结构明细';

foreach ($tables as $offset => $table) {
    $tableName = (string) $table->table_name;
    $tableColumns = $columnsByTable[$tableName] ?? [];
    $tableIndexes = $indexesByTable[$tableName] ?? [];
    $tableForeignKeys = $foreignKeysByTable[$tableName] ?? [];
    $tableChecks = $checksByTable[$tableName] ?? [];

    $lines[] = '';
    $lines[] = '### 2.'.($offset + 1).' `'.escapeInlineCode($tableName).'`';
    $lines[] = '';
    $lines[] = '- 类型：`'.escapeInlineCode((string) $table->table_type).'`';
    $lines[] = '- 引擎：'.inlineCodeOrDash($table->engine);
    $lines[] = '- 估算行数：`'.numberCell($table->table_rows).'`';
    $lines[] = '- 数据大小：`'.formatBytes((int) ($table->data_length ?? 0)).'`';
    $lines[] = '- 索引大小：`'.formatBytes((int) ($table->index_length ?? 0)).'`';
    $lines[] = '- 自增值：'.inlineCodeOrDash($table->auto_increment);
    $lines[] = '- 排序规则：'.inlineCodeOrDash($table->table_collation);
    $lines[] = '- 表注释：'.emptyCell($table->table_comment);
    $lines[] = '';
    $lines[] = '#### 字段';
    $lines[] = '';
    $lines[] = '| 序号 | 字段 | 类型 | 可空 | 默认值 | 键 | 额外 | 字符集 | 排序规则 | 注释 |';
    $lines[] = '| ---: | --- | --- | --- | --- | --- | --- | --- | --- | --- |';

    foreach ($tableColumns as $column) {
        $lines[] = sprintf(
            '| %d | `%s` | `%s` | %s | %s | %s | %s | %s | %s | %s |',
            (int) $column->ordinal_position,
            escapeTableCell((string) $column->column_name),
            escapeTableCell((string) $column->column_type),
            (string) $column->is_nullable === 'YES' ? '是' : '否',
            defaultCell($column->column_default, (string) $column->is_nullable),
            emptyCell($column->column_key),
            generatedExtraCell((string) ($column->extra ?? ''), (string) ($column->generation_expression ?? '')),
            emptyCell($column->character_set_name),
            emptyCell($column->collation_name),
            emptyCell($column->column_comment)
        );
    }

    $lines[] = '';
    $lines[] = '#### 索引';
    $lines[] = '';

    if ($tableIndexes === []) {
        $lines[] = '无数据库级索引。';
    } else {
        $lines[] = '| 索引名 | 唯一 | 类型 | 字段 | 基数 | 注释 |';
        $lines[] = '| --- | --- | --- | --- | ---: | --- |';

        foreach ($tableIndexes as $index) {
            $maxCardinality = max(array_filter(
                array_map(static fn ($value): ?int => $value === null ? null : (int) $value, $index['cardinality']),
                static fn (?int $value): bool => $value !== null
            ) ?: [0]);

            $lines[] = sprintf(
                '| `%s` | %s | `%s` | %s | %s | %s |',
                escapeTableCell($index['index_name']),
                $index['non_unique'] === 0 ? '是' : '否',
                escapeTableCell($index['index_type']),
                implode(', ', array_map(static fn (string $column): string => '`'.escapeInlineCode($column).'`', $index['columns'])),
                numberCell($maxCardinality),
                emptyCell(trim($index['comment'].' '.$index['index_comment']))
            );
        }
    }

    $lines[] = '';
    $lines[] = '#### 外键约束';
    $lines[] = '';

    if ($tableForeignKeys === []) {
        $lines[] = '无数据库级外键约束。';
    } else {
        $lines[] = '| 约束名 | 字段 | 引用表 | 引用字段 | 更新规则 | 删除规则 |';
        $lines[] = '| --- | --- | --- | --- | --- | --- |';

        foreach ($tableForeignKeys as $foreignKey) {
            $lines[] = sprintf(
                '| `%s` | %s | `%s` | %s | `%s` | `%s` |',
                escapeTableCell($foreignKey['constraint_name']),
                implode(', ', array_map(static fn (string $column): string => '`'.escapeInlineCode($column).'`', $foreignKey['columns'])),
                escapeTableCell($foreignKey['referenced_table_name']),
                implode(', ', array_map(static fn (string $column): string => '`'.escapeInlineCode($column).'`', $foreignKey['referenced_columns'])),
                escapeTableCell($foreignKey['update_rule']),
                escapeTableCell($foreignKey['delete_rule'])
            );
        }
    }

    if ($tableChecks !== []) {
        $lines[] = '';
        $lines[] = '#### CHECK 约束';
        $lines[] = '';
        $lines[] = '| 约束名 | 条件 |';
        $lines[] = '| --- | --- |';

        foreach ($tableChecks as $check) {
            $lines[] = sprintf(
                '| `%s` | `%s` |',
                escapeTableCell((string) $check->constraint_name),
                escapeTableCell((string) $check->check_clause)
            );
        }
    }
}

$target = dirname($basePath).'/docs/DATABASE.md';
file_put_contents($target, implode("\n", $lines)."\n");

fwrite(STDOUT, sprintf(
    '已生成数据库结构文档: %s，表数: %d，字段数: %d，索引数: %d%s',
    $target,
    $tableCount,
    $columnCount,
    $indexCount,
    PHP_EOL
));

/**
 * @param  array<int, object>  $rows
 * @return array<string, array<int, object>>
 */
function groupBy(array $rows, string $property): array
{
    $grouped = [];

    foreach ($rows as $row) {
        $key = (string) $row->{$property};
        $grouped[$key] ??= [];
        $grouped[$key][] = $row;
    }

    return $grouped;
}

function escapeTableCell(string $value): string
{
    return str_replace(
        ["\r\n", "\n", '|'],
        ['<br>', '<br>', '&#124;'],
        trim($value)
    );
}

function escapeInlineCode(string $value): string
{
    return str_replace('`', '&#96;', $value);
}

function emptyCell(mixed $value): string
{
    $text = trim((string) ($value ?? ''));

    if ($text === '') {
        return '—';
    }

    return escapeTableCell($text);
}

function inlineCodeOrDash(mixed $value): string
{
    $text = trim((string) ($value ?? ''));

    if ($text === '') {
        return '—';
    }

    return '`'.escapeInlineCode($text).'`';
}

function defaultCell(mixed $value, string $isNullable): string
{
    if ($value === null) {
        return $isNullable === 'YES' ? '`NULL`' : '—';
    }

    $text = (string) $value;

    if ($text === '') {
        return '空字符串';
    }

    return '`'.escapeInlineCode($text).'`';
}

function generatedExtraCell(string $extra, string $generationExpression): string
{
    $parts = [];

    if (trim($extra) !== '') {
        $parts[] = trim($extra);
    }

    if (trim($generationExpression) !== '') {
        $parts[] = 'generated: '.trim($generationExpression);
    }

    if ($parts === []) {
        return '—';
    }

    return escapeTableCell(implode('; ', $parts));
}

function numberCell(mixed $value): string
{
    if ($value === null || $value === '') {
        return '—';
    }

    return number_format((int) $value);
}

function formatBytes(int $bytes): string
{
    if ($bytes <= 0) {
        return '0 B';
    }

    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $power = min((int) floor(log($bytes, 1024)), count($units) - 1);
    $value = $bytes / (1024 ** $power);

    return rtrim(rtrim(number_format($value, 2), '0'), '.').' '.$units[$power];
}
