<?php

declare(strict_types=1);

namespace App\Services\Integrations\Plugins\Adapters;

use App\Services\Integrations\Plugins\PluginManifest;
use App\Services\Integrations\Plugins\PluginRuntimeRegistry;
use App\Services\Verification\Contracts\ProvidesVerificationFeeConfig;
use App\Services\Verification\Contracts\VerificationDriver;
use App\Services\Verification\Contracts\VerifiesVerificationCallbacks;
use App\Services\Verification\Data\VerificationCallbackRequest;
use App\Services\Verification\Data\VerificationCallbackVerificationResult;
use App\Services\Verification\Data\VerificationFeeConfig;
use App\Services\Verification\Data\VerificationInitializeRequest;
use App\Services\Verification\Data\VerificationInitializeResult;
use App\Services\Verification\Data\VerificationScanUrlResult;
use App\Services\Verification\Data\VerificationStatusResult;

final readonly class PluginVerificationDriver implements ProvidesVerificationFeeConfig, VerificationDriver, VerifiesVerificationCallbacks
{
    public function __construct(
        private PluginRuntimeRegistry $runtime,
        private PluginManifest $manifest,
    ) {}

    public function key(): string
    {
        return $this->manifest->key;
    }

    public function label(): string
    {
        return $this->manifest->name;
    }

    public function initialize(VerificationInitializeRequest $request): VerificationInitializeResult
    {
        $data = $this->runtime->execute($this->manifest->domain, $this->manifest->slug, 'certification.initialize', [
            'real_name' => $request->realName,
            'id_card' => $request->idCard,
            'cert_type' => $request->certType,
            'return_url' => $request->returnUrl,
        ])['data'] ?? [];

        return new VerificationInitializeResult(
            status: (int) ($data['status'] ?? 0),
            message: (string) ($data['msg'] ?? $data['message'] ?? ''),
            certifyId: (string) ($data['certify_id'] ?? ''),
            raw: is_array($data['raw'] ?? null) ? $data['raw'] : $data,
        );
    }

    public function generateScanUrl(string $certifyId): VerificationScanUrlResult
    {
        $data = $this->runtime->execute($this->manifest->domain, $this->manifest->slug, 'certification.scan_url', [
            'certify_id' => $certifyId,
        ])['data'] ?? [];

        return new VerificationScanUrlResult(
            status: (int) ($data['status'] ?? 0),
            message: (string) ($data['msg'] ?? $data['message'] ?? ''),
            url: (string) ($data['url'] ?? ''),
            raw: is_array($data['raw'] ?? null) ? $data['raw'] : $data,
        );
    }

    public function queryStatus(string $certifyId): VerificationStatusResult
    {
        $data = $this->runtime->execute($this->manifest->domain, $this->manifest->slug, 'certification.query_status', [
            'certify_id' => $certifyId,
        ])['data'] ?? [];

        return new VerificationStatusResult(
            status: (int) ($data['status'] ?? 0),
            message: (string) ($data['msg'] ?? $data['message'] ?? ''),
            raw: is_array($data['raw'] ?? null) ? $data['raw'] : $data,
        );
    }

    public function verifyCallback(VerificationCallbackRequest $request): VerificationCallbackVerificationResult
    {
        $result = $this->runtime->execute($this->manifest->domain, $this->manifest->slug, 'certification.verify_callback', [
            'payload' => $request->payload,
            'headers' => $request->headers,
            'method' => $request->method,
            'path' => $request->path,
            'raw_body' => $request->rawBody,
        ]);
        $data = is_array($result['data'] ?? null) ? $result['data'] : [];

        return new VerificationCallbackVerificationResult(
            passed: (bool) ($data['passed'] ?? false),
            message: (string) ($data['message'] ?? $result['message'] ?? '签名验证失败'),
            code: (int) ($data['code'] ?? 40001),
            httpStatus: (int) ($data['http_status'] ?? 401),
            replayKey: trim((string) ($data['replay_key'] ?? '')) ?: null,
        );
    }

    public function feeConfig(): VerificationFeeConfig
    {
        $result = $this->runtime->execute($this->manifest->domain, $this->manifest->slug, 'certification.fee_config');
        $data = is_array($result['data'] ?? null) ? $result['data'] : [];

        return new VerificationFeeConfig(
            freeAttempts: max(0, (int) ($data['free_attempts'] ?? $data['free_times'] ?? 0)),
            retryFee: max(0.0, (float) ($data['retry_fee'] ?? $data['amount'] ?? 0)),
            chargeEnabled: (bool) ($data['charge_enabled'] ?? false),
            amount: max(0.0, (float) ($data['amount'] ?? $data['retry_fee'] ?? 0)),
        );
    }
}
