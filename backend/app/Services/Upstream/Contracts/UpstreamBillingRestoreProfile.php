<?php

declare(strict_types=1);

namespace App\Services\Upstream\Contracts;

interface UpstreamBillingRestoreProfile
{
    /**
     * @return array<int, string>
     */
    public function confirmationPhrases(): array;

    public function defaultConfirmationPhrase(): string;

    public function source(): string;

    public function invoiceNoPrefix(): string;

    public function paymentNoPrefix(): string;

    public function tradeNoPrefix(): string;
}
