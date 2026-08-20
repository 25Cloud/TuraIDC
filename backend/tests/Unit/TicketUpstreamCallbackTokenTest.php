<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ticket\TicketUpstreamCallbackToken;
use PHPUnit\Framework\TestCase;

final class TicketUpstreamCallbackTokenTest extends TestCase
{
    public function test_service_token_is_deterministic_and_legacy_signature_matches_reference_algorithm(): void
    {
        $applicationKey = 'base64:unit-test-key';
        $token = TicketUpstreamCallbackToken::forServiceId(42, $applicationKey);
        $payload = ['id' => '42', 'rand_str' => 'abc123'];
        $signed = ['id' => '42', 'token' => $token, 'rand_str' => 'abc123'];
        ksort($signed, SORT_STRING);

        $signature = strtoupper(md5((string) json_encode($signed)));

        self::assertSame($token, TicketUpstreamCallbackToken::forServiceId(42, $applicationKey));
        self::assertNotSame($token, TicketUpstreamCallbackToken::forServiceId(43, $applicationKey));
        $expectedPayload = [
            'id' => $payload['id'],
            'rand_str' => $payload['rand_str'],
            'token' => $token,
        ];
        ksort($expectedPayload, SORT_STRING);
        self::assertSame($signature, strtoupper(md5((string) json_encode($expectedPayload))));
    }
}
