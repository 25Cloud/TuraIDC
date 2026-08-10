<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Services\Auth\Contracts\LegacyPasswordVerifier as LegacyPasswordVerifierContract;

final class LegacyPasswordVerifier
{
    /**
     * @param  iterable<int,LegacyPasswordVerifierContract>  $verifiers
     */
    public function __construct(
        private readonly iterable $verifiers = [],
    ) {}

    public function verify(string $plaintext, string $stored, bool &$needsPasswordRehash = false): ?bool
    {
        foreach ($this->verifiers as $verifier) {
            $matched = $verifier->verify($plaintext, $stored, $needsPasswordRehash);
            if ($matched !== null) {
                return $matched;
            }
        }

        return null;
    }
}
