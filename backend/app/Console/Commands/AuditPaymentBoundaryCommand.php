<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Finance\PaymentBoundaryAuditService;
use Illuminate\Console\Command;

class AuditPaymentBoundaryCommand extends Command
{
    protected $signature = 'payment:audit-third-party-boundary
        {--json : 以 JSON 输出结果}
        {--strict : 历史非第三方 Payment 数量超过基线时返回失败}
        {--baseline-non-third-party=66 : 历史非第三方 Payment 数量基线}
        {--sample=20 : 输出历史非第三方 Payment 样本数量}';

    protected $description = '审计 Payment 是否只承载第三方真实资金流入，历史非第三方记录只保留审计口径';

    public function handle(PaymentBoundaryAuditService $service): int
    {
        $baseline = max(0, (int) $this->option('baseline-non-third-party'));
        $sampleLimit = max(1, (int) $this->option('sample'));
        $result = $service->inspect($baseline, $sampleLimit);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        } else {
            $summary = $result['summary'];
            $this->info('Payment 第三方边界审计');
            $this->line('- third_party_gateways: '.implode(',', $result['third_party_gateways']));
            $this->line('- third_party_payment_count: '.$summary['third_party_payment_count']);
            $this->line('- historical_non_third_party_payment_count: '.$summary['historical_non_third_party_payment_count']);
            $this->line('- historical_non_third_party_baseline: '.$summary['historical_non_third_party_baseline']);
            $this->line('- historical_non_third_party_exceeded_baseline: '.($summary['historical_non_third_party_exceeded_baseline'] ? 'true' : 'false'));
        }

        if ((bool) $this->option('strict') && (bool) $result['summary']['historical_non_third_party_exceeded_baseline']) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
