<?php

declare(strict_types=1);

namespace App\Services\Upstream\Contracts;

/**
 * Declares that new purchases for this upstream must finish fulfillment
 * synchronously after payment succeeds.
 */
interface ProvidesSynchronousNewPurchaseFulfillment {}
