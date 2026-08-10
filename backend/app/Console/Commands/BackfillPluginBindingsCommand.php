<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Integrations\Plugins\PluginDataBackfillService;
use Illuminate\Console\Command;
use RuntimeException;

class BackfillPluginBindingsCommand extends Command
{
    protected $signature = 'db:backfill-plugin-bindings
        {--dry-run : Inspect and report without writing}
        {--execute : Write plugin bindings and normalized foreign keys}
        {--batch= : Backfill batch id, defaults to plugin-bindings-v1}
        {--chunk=500 : Batch size hint for large deployments}
        {--json : Output JSON report}';

    protected $description = 'Backfill plugin binding, upstream runtime, payment, and notification plugin references';

    public function handle(PluginDataBackfillService $service): int
    {
        if ((bool) $this->option('dry-run') && (bool) $this->option('execute')) {
            $this->error('Do not pass --dry-run and --execute at the same time.');

            return self::FAILURE;
        }

        $execute = (bool) $this->option('execute');
        $batchId = trim((string) $this->option('batch')) ?: PluginDataBackfillService::DEFAULT_BATCH_ID;
        $chunkSize = max(1, (int) $this->option('chunk'));

        try {
            $report = $execute
                ? $service->execute($batchId, $chunkSize)
                : $service->inspect($batchId, $chunkSize);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

            return $report['has_blocking_unknowns'] ?? false ? self::FAILURE : self::SUCCESS;
        }

        $this->info($execute ? 'Plugin binding backfill executed.' : 'Plugin binding backfill dry-run complete.');
        $this->line('Batch: '.$report['batch_id']);
        $this->line('Database: '.$report['database']);

        foreach ((array) ($report['tables'] ?? []) as $table => $stats) {
            $this->line(sprintf(
                '- %s: total=%d success=%d skipped=%d failed=%d',
                $table,
                (int) ($stats['total'] ?? 0),
                (int) ($stats['success'] ?? 0),
                (int) ($stats['skipped'] ?? 0),
                (int) ($stats['failed'] ?? 0),
            ));
        }

        $unknownCounts = (array) ($report['unknown_counts'] ?? []);
        $this->line(sprintf(
            'Unknowns: providers=%d gateways=%d drivers=%d',
            (int) ($unknownCounts['providers'] ?? 0),
            (int) ($unknownCounts['gateways'] ?? 0),
            (int) ($unknownCounts['drivers'] ?? 0),
        ));

        if (($report['has_blocking_unknowns'] ?? false) === true) {
            $this->warn('Blocking unknown keys exist. Re-run with --json to inspect details.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
