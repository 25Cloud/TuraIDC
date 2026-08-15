<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\BusinessException;
use App\Services\Auth\VerificationService;
use App\Services\Verification\Contracts\VerificationDriver;
use App\Services\Verification\Data\VerificationInitializeRequest;
use App\Services\Verification\Data\VerificationInitializeResult;
use App\Services\Verification\Data\VerificationScanUrlResult;
use App\Services\Verification\Data\VerificationStatusResult;
use App\Services\Verification\VerificationDriverManager;
use Tests\TestCase;

class VerificationQrSessionTest extends TestCase
{
    public function test_qr_session_uses_proxy_url_for_five_minutes_and_can_be_closed(): void
    {
        config(['app.url' => 'https://api.example.test']);

        $driver = new FakeQrSessionVerificationDriver;
        $service = new VerificationService(new VerificationDriverManager([$driver]));
        $startedAt = time();

        $payload = $service->generateQrCode('CERT-5MIN');

        $this->assertSame(300, $payload['expires_in_seconds']);
        $this->assertStringStartsWith('https://api.example.test/api/v2/client/verification/scan?', $payload['url']);
        $this->assertStringContainsString('certify_id=CERT-5MIN', $payload['url']);
        $this->assertSame($payload['url'], $payload['qrcode_url']);
        $this->assertGreaterThanOrEqual($startedAt + 295, strtotime((string) $payload['expires_at']));
        $this->assertSame('https://provider.example.test/verify?cert=CERT-5MIN', $service->resolveQrCodeRedirectUrl('CERT-5MIN'));
        $this->assertSame(1, $driver->scanUrlCalls);

        $service->closeQrCodeSession('CERT-5MIN');

        try {
            $service->resolveQrCodeRedirectUrl('CERT-5MIN');
            $this->fail('Closed QR session should not resolve a redirect URL.');
        } catch (BusinessException) {
            $this->assertTrue(true);
        }

        $this->assertSame(1, $driver->scanUrlCalls);
    }
}

final class FakeQrSessionVerificationDriver implements VerificationDriver
{
    public int $scanUrlCalls = 0;

    public function key(): string
    {
        return 'stay33';
    }

    public function label(): string
    {
        return '测试实名';
    }

    public function initialize(VerificationInitializeRequest $request): VerificationInitializeResult
    {
        return new VerificationInitializeResult(200, '请求成功', 'CERT-5MIN');
    }

    public function generateScanUrl(string $certifyId): VerificationScanUrlResult
    {
        $this->scanUrlCalls++;

        return new VerificationScanUrlResult(200, '请继续认证', 'https://provider.example.test/verify?cert='.$certifyId);
    }

    public function queryStatus(string $certifyId): VerificationStatusResult
    {
        return new VerificationStatusResult(4, '等待认证');
    }
}
