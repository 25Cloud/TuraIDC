<?php

declare(strict_types=1);

namespace App\Services\Auth\Contracts;

interface LegacyPasswordVerifier
{
    public function verify(string $plaintext, string $stored, bool &$needsPasswordRehash = false): ?bool;
}
