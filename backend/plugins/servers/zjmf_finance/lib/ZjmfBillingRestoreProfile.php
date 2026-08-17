<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Servers\ZjmfFinance\Lib;

use App\Services\Upstream\Contracts\UpstreamBillingRestoreProfile;

final class ZjmfBillingRestoreProfile implements UpstreamBillingRestoreProfile
{
    public function confirmationPhrases(): array
    {
        return [
            $this->defaultConfirmationPhrase(),
        ];
    }

    public function defaultConfirmationPhrase(): string
    {
        return 'RESTORE_ZJMF_BILLING';
    }

    public function source(): string
    {
        return 'zjmf_sql_restore';
    }

    public function invoiceNoPrefix(): string
    {
        return 'ZJMF';
    }

    public function paymentNoPrefix(): string
    {
        return 'PAYZJMF';
    }

    public function tradeNoPrefix(): string
    {
        return 'ZJMF-';
    }
}
