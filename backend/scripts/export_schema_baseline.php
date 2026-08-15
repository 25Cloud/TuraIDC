<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

define('LARAVEL_START', microtime(true));

$basePath = dirname(__DIR__);

require $basePath.'/vendor/autoload.php';

$app = require $basePath.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$options = getopt('', ['dry-run', 'output:']);
$dryRun = array_key_exists('dry-run', $options);
$output = isset($options['output']) && trim((string) $options['output']) !== ''
    ? (string) $options['output']
    : $basePath.'/database/schema/mysql-schema.sql';

$connection = DB::connection((string) config('database.default', 'mysql'));
$database = (string) $connection->getDatabaseName();

$tables = $connection->select(<<<'SQL'
    SELECT TABLE_NAME AS table_name
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_TYPE = 'BASE TABLE'
    ORDER BY TABLE_NAME
SQL);

$lines = [
    '/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;',
    "/*!40103 SET TIME_ZONE='+00:00' */;",
    '/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;',
    '/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;',
    "/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;",
    '/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;',
    '',
];

$tableNames = [];

foreach ($tables as $table) {
    $tableName = (string) $table->table_name;
    $tableNames[] = $tableName;
    $escapedTable = str_replace('`', '``', $tableName);
    $createRow = $connection->selectOne("SHOW CREATE TABLE `{$escapedTable}`");
    $createSql = showCreateTableSql($createRow);

    if ($createSql === '') {
        throw new RuntimeException("Unable to read SHOW CREATE TABLE for {$tableName}");
    }

    $lines[] = "DROP TABLE IF EXISTS `{$escapedTable}`;";
    $lines[] = '/*!40101 SET @saved_cs_client     = @@character_set_client */;';
    $lines[] = '/*!50503 SET character_set_client = utf8mb4 */;';
    $lines[] = rtrim($createSql, ';').';';
    $lines[] = '/*!40101 SET character_set_client = @saved_cs_client */;';
    $lines[] = '';
}

if (in_array('migrations', $tableNames, true)) {
    $migrationRows = $connection->table('migrations')
        ->select(['id', 'migration', 'batch'])
        ->orderBy('id')
        ->get();

    foreach ($migrationRows as $migration) {
        $lines[] = sprintf(
            'INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (%d,%s,%d);',
            (int) $migration->id,
            quoteSqlString((string) $migration->migration),
            (int) $migration->batch
        );
    }

    if ($migrationRows->isNotEmpty()) {
        $lines[] = '';
    }
}

$lines[] = '/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;';
$lines[] = '/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;';
$lines[] = '/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;';
$lines[] = '/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;';
$lines[] = '/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;';

$payload = implode("\n", $lines)."\n";

if (! $dryRun) {
    $directory = dirname($output);
    if (! is_dir($directory)) {
        mkdir($directory, 0775, true);
    }

    file_put_contents($output, $payload);
}

fwrite(STDOUT, sprintf(
    '%s schema baseline: %s, database: %s, tables: %d, migrations: %d%s',
    $dryRun ? 'Dry-run' : 'Generated',
    $output,
    $database,
    count($tableNames),
    in_array('migrations', $tableNames, true) ? (int) ($migrationRows ?? collect())->count() : 0,
    PHP_EOL
));

function showCreateTableSql(?object $row): string
{
    if (! $row) {
        return '';
    }

    foreach ((array) $row as $key => $value) {
        if (strtolower((string) $key) === 'create table') {
            return (string) $value;
        }
    }

    return '';
}

function quoteSqlString(string $value): string
{
    return "'".str_replace(['\\', "'"], ['\\\\', "''"], $value)."'";
}
