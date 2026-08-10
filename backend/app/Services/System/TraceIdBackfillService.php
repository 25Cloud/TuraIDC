<?php

declare(strict_types=1);

namespace App\Services\System;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class TraceIdBackfillService
{
    private const TARGET_TABLES = [
        'invoices',
        'payments',
        'services',
        'account_transactions',
    ];

    /**
     * @return array<string, mixed>
     */
    public function inspect(int $sampleLimit = 20): array
    {
        $sampleLimit = max(0, $sampleLimit);
        $summary = [];
        $samples = [];
        $total = 0;

        foreach (self::TARGET_TABLES as $table) {
            $count = $this->missingQuery($table)?->count() ?? 0;
            $summary[$table] = (int) $count;
            $total += (int) $count;

            $samples[$table] = $sampleLimit > 0
                ? $this->plannedChanges($table, $sampleLimit)
                : [];
        }

        return [
            'dry_run' => true,
            'database' => (string) DB::getDatabaseName(),
            'checked_at' => now()->format('Y-m-d H:i:s'),
            'summary' => $summary,
            'total_missing' => $total,
            'samples' => $samples,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(int $chunkSize = 500): array
    {
        $chunkSize = max(1, $chunkSize);
        $changes = [];
        $summary = [];
        $updated = [];

        foreach (self::TARGET_TABLES as $table) {
            $changes[$table] = $this->plannedChanges($table);
            $summary[$table] = count($changes[$table]);
            $updated[$table] = 0;
        }

        $backupPath = $this->writeBackup($changes, $summary);

        DB::transaction(function () use ($changes, $chunkSize, &$updated): void {
            foreach ($changes as $table => $rows) {
                foreach (array_chunk($rows, $chunkSize) as $chunk) {
                    foreach ($chunk as $row) {
                        $affected = DB::table($table)
                            ->where('id', (int) $row['id'])
                            ->where(function ($query): void {
                                $query->whereNull('trace_id')
                                    ->orWhere('trace_id', '');
                            })
                            ->update(['trace_id' => (string) $row['new_trace_id']]);

                        $updated[$table] += (int) $affected;
                    }
                }
            }
        });

        return [
            'dry_run' => false,
            'database' => (string) DB::getDatabaseName(),
            'executed_at' => now()->format('Y-m-d H:i:s'),
            'backup_path' => $backupPath,
            'planned' => $summary,
            'updated' => $updated,
            'remaining_missing' => $this->remainingMissing(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function remainingMissing(): array
    {
        $summary = [];

        foreach (self::TARGET_TABLES as $table) {
            $summary[$table] = (int) ($this->missingQuery($table)?->count() ?? 0);
        }

        return $summary;
    }

    /**
     * @return list<array{table:string,id:int,old_trace_id:?string,new_trace_id:string}>
     */
    private function plannedChanges(string $table, ?int $limit = null): array
    {
        $query = $this->missingQuery($table);
        if ($query === null) {
            return [];
        }

        $query->select(['id', 'trace_id'])->orderBy('id');

        if ($limit !== null) {
            $query->limit(max(0, $limit));
        }

        return $query
            ->get()
            ->map(fn (object $row): array => [
                'table' => $table,
                'id' => (int) $row->id,
                'old_trace_id' => $row->trace_id === null ? null : (string) $row->trace_id,
                'new_trace_id' => $this->legacyTraceId($table, (int) $row->id),
            ])
            ->values()
            ->all();
    }

    private function missingQuery(string $table): ?Builder
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'trace_id')) {
            return null;
        }

        return DB::table($table)
            ->where(function ($query): void {
                $query->whereNull('trace_id')
                    ->orWhere('trace_id', '');
            });
    }

    private function legacyTraceId(string $table, int $id): string
    {
        return "legacy-{$table}-{$id}";
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $changes
     * @param  array<string, int>  $summary
     */
    private function writeBackup(array $changes, array $summary): string
    {
        $directory = storage_path('app/trace-id-backfills');
        File::ensureDirectoryExists($directory);

        $path = $directory.'/trace_id_backfill_'.now()->format('Ymd_His').'.json';
        File::put($path, json_encode([
            'database' => (string) DB::getDatabaseName(),
            'created_at' => now()->format('Y-m-d H:i:s'),
            'summary' => $summary,
            'changes' => $changes,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        return $path;
    }
}
