<?php

declare(strict_types=1);

namespace App\Services\System;

use App\Support\VersionedJson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class JsonSchemaVersionBackfillService
{
    private const TARGETS = [
        ['table' => 'orders', 'column' => 'config_snapshot', 'type' => 'order.config_snapshot', 'kind' => 'trade'],
        ['table' => 'orders', 'column' => 'config_pricing_snapshot', 'type' => 'order.config_pricing_snapshot', 'kind' => 'trade'],
        ['table' => 'orders', 'column' => 'coupon_snapshot', 'type' => 'order.coupon_snapshot', 'kind' => 'trade'],
        ['table' => 'invoices', 'column' => 'config_snapshot', 'type' => 'invoice.config_snapshot', 'kind' => 'trade'],
        ['table' => 'invoices', 'column' => 'config_pricing_snapshot', 'type' => 'invoice.config_pricing_snapshot', 'kind' => 'trade'],
        ['table' => 'invoices', 'column' => 'coupon_snapshot', 'type' => 'invoice.coupon_snapshot', 'kind' => 'trade'],
        ['table' => 'payment_callbacks', 'column' => 'payload_json', 'type' => 'payment_callback.payment', 'kind' => 'payment_callback'],
    ];

    /**
     * @return array<string, mixed>
     */
    public function inspect(int $sampleLimit = 20): array
    {
        $changes = $this->plannedChanges();
        $summary = $this->summarize($changes);
        $samples = [];

        foreach ($changes as $target => $rows) {
            $samples[$target] = array_slice($rows, 0, max(0, $sampleLimit));
        }

        return [
            'dry_run' => true,
            'database' => (string) DB::getDatabaseName(),
            'checked_at' => now()->format('Y-m-d H:i:s'),
            'summary' => $summary,
            'total_missing' => array_sum($summary),
            'samples' => $samples,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(int $chunkSize = 500): array
    {
        $chunkSize = max(1, $chunkSize);
        $changes = $this->plannedChanges();
        $summary = $this->summarize($changes);
        $backupPath = $this->writeBackup($changes, $summary);
        $updated = array_fill_keys(array_keys($summary), 0);

        DB::transaction(function () use ($changes, $chunkSize, &$updated): void {
            foreach ($changes as $targetKey => $rows) {
                [$table, $column] = explode('.', $targetKey, 2);

                foreach (array_chunk($rows, $chunkSize) as $chunk) {
                    foreach ($chunk as $row) {
                        $affected = DB::table($table)
                            ->where('id', (int) $row['id'])
                            ->update([
                                $column => json_encode($row['new_payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                                'updated_at' => now(),
                            ]);

                        $updated[$targetKey] += (int) $affected;
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
            'remaining_missing' => $this->summarize($this->plannedChanges(0)),
        ];
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function plannedChanges(?int $limitPerTarget = null): array
    {
        $changes = [];

        foreach (self::TARGETS as $target) {
            $table = (string) $target['table'];
            $column = (string) $target['column'];
            $targetKey = "{$table}.{$column}";
            $changes[$targetKey] = [];

            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            $query = DB::table($table)
                ->select(['id', $column])
                ->whereNotNull($column)
                ->orderBy('id');

            if ($limitPerTarget !== null && $limitPerTarget > 0) {
                $query->limit($limitPerTarget);
            }

            foreach ($query->get() as $row) {
                $payload = VersionedJson::decodeToArray($row->{$column});
                if ($payload === null || VersionedJson::isVersioned($payload)) {
                    continue;
                }

                $callbackType = (string) ($payload['callback_type'] ?? '');
                $schemaType = (string) $target['type'];
                $newPayload = $target['kind'] === 'payment_callback'
                    ? VersionedJson::paymentCallback($payload, $callbackType !== '' ? $callbackType : $this->resolvePaymentCallbackType($table, (int) $row->id))
                    : VersionedJson::tradeSnapshot($payload, $schemaType);

                $changes[$targetKey][] = [
                    'table' => $table,
                    'column' => $column,
                    'id' => (int) $row->id,
                    'old_payload' => $payload,
                    'new_payload' => $newPayload,
                ];
            }
        }

        return $changes;
    }

    private function resolvePaymentCallbackType(string $table, int $id): string
    {
        if ($table !== 'payment_callbacks' || ! Schema::hasColumn('payment_callbacks', 'callback_type')) {
            return 'payment';
        }

        $callbackType = DB::table('payment_callbacks')->where('id', $id)->value('callback_type');

        return trim((string) $callbackType) !== '' ? trim((string) $callbackType) : 'payment';
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $changes
     * @return array<string, int>
     */
    private function summarize(array $changes): array
    {
        $summary = [];

        foreach ($changes as $target => $rows) {
            $summary[$target] = count($rows);
        }

        return $summary;
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $changes
     * @param  array<string, int>  $summary
     */
    private function writeBackup(array $changes, array $summary): string
    {
        $directory = storage_path('app/json-schema-version-backfills');
        File::ensureDirectoryExists($directory);

        $path = $directory.'/json_schema_version_backfill_'.now()->format('Ymd_His').'.json';
        File::put($path, json_encode([
            'database' => (string) DB::getDatabaseName(),
            'created_at' => now()->format('Y-m-d H:i:s'),
            'summary' => $summary,
            'changes' => $changes,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        return $path;
    }
}
