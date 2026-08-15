<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\BusinessException;
use App\Services\Sms\Contracts\SmsDriver;
use App\Services\Sms\Data\SmsMessageRequest;
use App\Services\Sms\Data\SmsSendRequest;
use App\Services\Sms\Data\SmsSendResult;
use App\Services\Sms\SmsDriverManager;
use App\Services\Verification\Contracts\VerificationDriver;
use App\Services\Verification\Data\VerificationInitializeRequest;
use App\Services\Verification\Data\VerificationInitializeResult;
use App\Services\Verification\Data\VerificationScanUrlResult;
use App\Services\Verification\Data\VerificationStatusResult;
use App\Services\Verification\VerificationDriverManager;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class IntegrationDriverManagerTest extends TestCase
{
    public function test_verification_manager_resolves_registered_driver(): void
    {
        $driver = new FakeVerificationDriver('fake_identity');
        $manager = new VerificationDriverManager([$driver]);

        $this->assertSame($driver, $manager->resolve('fake_identity'));
        $this->assertSame([
            ['value' => 'fake_identity', 'label' => '测试实名'],
        ], $manager->options());
    }

    public function test_verification_manager_rejects_duplicate_key(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('重复注册');

        new VerificationDriverManager([
            new FakeVerificationDriver('fake_identity'),
            new FakeVerificationDriver('fake_identity'),
        ]);
    }

    public function test_verification_manager_reports_missing_driver_in_chinese(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('实名认证驱动 [missing] 未注册');

        (new VerificationDriverManager)->resolve('missing');
    }

    public function test_sms_manager_resolves_registered_driver(): void
    {
        $driver = new FakeSmsDriver('fake_sms');
        $manager = new SmsDriverManager([$driver]);

        $this->assertSame($driver, $manager->resolve('fake_sms'));
        $this->assertSame([
            ['value' => 'fake_sms', 'label' => '测试短信'],
        ], $manager->options());
    }

    public function test_sms_manager_rejects_duplicate_key(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('重复注册');

        new SmsDriverManager([
            new FakeSmsDriver('fake_sms'),
            new FakeSmsDriver('fake_sms'),
        ]);
    }

    public function test_sms_manager_reports_missing_driver_in_chinese(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('短信驱动 [missing] 未注册');

        (new SmsDriverManager)->resolve('missing');
    }
}

final readonly class FakeVerificationDriver implements VerificationDriver
{
    public function __construct(
        private string $key,
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return '测试实名';
    }

    public function initialize(VerificationInitializeRequest $request): VerificationInitializeResult
    {
        return new VerificationInitializeResult(200, '请求成功', 'CERT123');
    }

    public function generateScanUrl(string $certifyId): VerificationScanUrlResult
    {
        return new VerificationScanUrlResult(200, '请继续认证', 'https://example.test/verify');
    }

    public function queryStatus(string $certifyId): VerificationStatusResult
    {
        return new VerificationStatusResult(1, '审核通过');
    }
}

final readonly class FakeSmsDriver implements SmsDriver
{
    public function __construct(
        private string $key,
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return '测试短信';
    }

    public function sendMessage(SmsMessageRequest $request): SmsSendResult
    {
        return new SmsSendResult('success');
    }

    public function sendVerifyCode(SmsSendRequest $request): SmsSendResult
    {
        return new SmsSendResult('success');
    }
}
