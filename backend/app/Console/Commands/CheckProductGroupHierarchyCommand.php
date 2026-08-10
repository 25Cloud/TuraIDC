<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ProductCatalog\ProductGroupHierarchyService;
use Illuminate\Console\Command;

class CheckProductGroupHierarchyCommand extends Command
{
    protected $signature = 'product-catalog:check-product-group-hierarchy {--json : 以 JSON 输出结果}';

    protected $description = '巡检三层商品分类表及商品三级分类挂载字段';

    public function handle(ProductGroupHierarchyService $service): int
    {
        $result = $service->checkHierarchy();
        $blockingErrors = (array) ($result['blocking_errors'] ?? []);
        $warnings = (array) ($result['warnings'] ?? []);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return $blockingErrors === [] ? self::SUCCESS : self::FAILURE;
        }

        foreach ((array) ($result['counts'] ?? []) as $key => $value) {
            $this->line($key.': '.$value);
        }

        foreach ($warnings as $warning) {
            $this->warn((string) $warning);
        }

        foreach ($blockingErrors as $error) {
            $this->error((string) $error);
        }

        return $blockingErrors === [] ? self::SUCCESS : self::FAILURE;
    }
}
