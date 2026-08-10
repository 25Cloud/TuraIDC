<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Invoice;
use App\Services\Finance\CouponService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncInvoiceCouponUsageJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public int $backoff = 5;

    public function __construct(public int $invoiceId) {}

    public function handle(CouponService $service): void
    {
        $invoice = Invoice::query()->find($this->invoiceId);

        if ($invoice instanceof Invoice) {
            $service->syncInvoiceCouponUsage($invoice);
        }
    }
}
