<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Auth\Contracts\LegacyPasswordVerifier as LegacyPasswordVerifierContract;
use App\Services\Auth\LegacyPasswordVerifier;
use PHPUnit\Framework\TestCase;

class LegacyPasswordVerifierTest extends TestCase
{
    public function test_it_returns_first_non_null_legacy_verifier_result(): void
    {
        $needsRehash = false;
        $verifier = new LegacyPasswordVerifier([
            new class implements LegacyPasswordVerifierContract
            {
                public function verify(string $plaintext, string $stored, bool &$needsPasswordRehash = false): ?bool
                {
                    return null;
                }
            },
            new class implements LegacyPasswordVerifierContract
            {
                public function verify(string $plaintext, string $stored, bool &$needsPasswordRehash = false): ?bool
                {
                    $needsPasswordRehash = true;

                    return true;
                }
            },
        ]);

        $this->assertTrue($verifier->verify('Secret123', 'legacy-hash', $needsRehash));
        $this->assertTrue($needsRehash);
    }

    public function test_it_ignores_unsupported_legacy_formats(): void
    {
        $needsRehash = false;
        $verifier = new LegacyPasswordVerifier([
            new class implements LegacyPasswordVerifierContract
            {
                public function verify(string $plaintext, string $stored, bool &$needsPasswordRehash = false): ?bool
                {
                    return null;
                }
            },
        ]);

        $this->assertNull($verifier->verify('Secret123', 'unknown-hash', $needsRehash));
        $this->assertFalse($needsRehash);
    }
}
